/**
 * Face scan for attendance.
 *
 * FaceScan.capture({title, hint}) opens a camera window, waits until one face
 * is held steady in front of the camera for a few frames and resolves with its
 * 128 number descriptor (averaged over the frames). It rejects with an Error
 * whose message can be shown to the student; a cancelled scan rejects with
 * err.cancelled = true.
 *
 * face-api.js and its models load from the CDN the first time a scan starts.
 */
(function () {
  var LIB = 'https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.13/dist/face-api.js',
      MODELS = 'https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.13/model/',
      SAMPLES = 5, ready = null;

  function load() {
    if (ready) return ready;
    ready = new Promise(function (resolve, reject) {
      var s = document.createElement('script');
      s.src = LIB;
      s.onload = resolve;
      s.onerror = function () { reject(new Error('The face scanner could not be loaded. Check your internet and try again.')); };
      document.head.appendChild(s);
    }).then(function () {
      var nets = faceapi.nets;
      return Promise.all([nets.tinyFaceDetector.loadFromUri(MODELS),
                          nets.faceLandmark68Net.loadFromUri(MODELS),
                          nets.faceRecognitionNet.loadFromUri(MODELS)]);
    });
    ready.catch(function () { ready = null; });
    return ready;
  }

  function modal(title, hint) {
    var el = document.createElement('div');
    el.className = 'face-modal';
    el.innerHTML =
      '<div class="face-box" role="dialog" aria-modal="true">' +
        '<div class="face-head"><strong></strong>' +
          '<button type="button" class="btn-close btn-close-white" aria-label="Cancel"></button></div>' +
        '<div class="face-frame"><video playsinline muted></video><span class="face-ring"></span></div>' +
        '<p class="face-hint"></p>' +
        '<div class="face-status"><span class="spinner-border spinner-border-sm me-2"></span>Starting camera…</div>' +
      '</div>';
    el.querySelector('strong').textContent = title;
    el.querySelector('.face-hint').textContent = hint;
    document.body.appendChild(el);
    return el;
  }

  function capture(opts) {
    opts = opts || {};
    var el = modal(opts.title || 'Face scan',
                   opts.hint || 'Look straight at the camera in good light. Remove mask or sunglasses.'),
        video = el.querySelector('video'), status = el.querySelector('.face-status'),
        ring = el.querySelector('.face-ring'), stream = null, stopped = false;

    function say(html) { status.innerHTML = html; }
    function close() {
      stopped = true;
      if (stream) stream.getTracks().forEach(function (t) { t.stop(); });
      el.remove();
    }

    return new Promise(function (resolve, reject) {
      el.querySelector('.btn-close').addEventListener('click', function () {
        close();
        var err = new Error('Face scan cancelled.'); err.cancelled = true; reject(err);
      });
      function fail(msg) { if (!stopped) { close(); reject(new Error(msg)); } }

      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.isSecureContext) {
        return fail('Camera is not available. Open the portal over https in an up to date browser.');
      }
      navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: { ideal: 640 } }, audio: false })
        .then(function (s) {
          stream = s;
          if (stopped) return close();
          video.srcObject = s;
          return video.play();
        }, function (e) {
          throw new Error(e && e.name === 'NotAllowedError'
            ? 'Camera permission was denied. Allow camera for this site in your browser settings and try again.'
            : 'The camera could not be started. Close other apps using it and try again.');
        })
        .then(function () {
          if (stopped) return;
          say('<span class="spinner-border spinner-border-sm me-2"></span>Loading face scanner…');
          return load();
        })
        .then(function () {
          if (stopped) return;
          var samples = [], started = Date.now(),
              options = new faceapi.TinyFaceDetectorOptions({ inputSize: 320, scoreThreshold: 0.5 });
          say('Looking for your face…');

          (function tick() {
            if (stopped) return;
            if (Date.now() - started > 30000) {
              return fail('No clear face was found. Sit facing the camera in good light and try again.');
            }
            faceapi.detectAllFaces(video, options).withFaceLandmarks().withFaceDescriptors().then(function (found) {
              if (stopped) return;
              if (found.length !== 1) {
                samples = []; ring.className = 'face-ring';
                say(found.length ? 'Only one person should be in front of the camera.' : 'Looking for your face…');
                return setTimeout(tick, 150);
              }
              var box = found[0].detection.box;
              if (box.width < video.videoWidth * 0.25) {
                samples = []; ring.className = 'face-ring';
                say('Come a little closer to the camera.');
                return setTimeout(tick, 150);
              }
              samples.push(found[0].descriptor);
              ring.className = 'face-ring is-locked';
              say('Hold still… ' + Math.round(samples.length / SAMPLES * 100) + '%');
              if (samples.length < SAMPLES) return setTimeout(tick, 120);

              var avg = [];
              for (var i = 0; i < 128; i++) {
                var sum = 0;
                samples.forEach(function (d) { sum += d[i]; });
                avg.push(Math.round(sum / samples.length * 1e6) / 1e6);
              }
              close();
              resolve(avg);
            }, function () { fail('The face scan failed. Please try again.'); });
          })();
        })
        .catch(function (e) { fail(e.message); });
    });
  }

  window.FaceScan = { capture: capture };
})();
