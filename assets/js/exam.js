/* =====================================================================
   Online Exam Portal - examination engine
   ---------------------------------------------------------------------
   Handles question navigation, the palette, autosaving of answers,
   the countdown timer and submission.

   The correct answers are never present in this file or in the DOM -
   evaluation happens entirely on the server.
   ===================================================================== */
(function () {
  'use strict';

  var CFG = window.EXAM_CONFIG;
  if (!CFG) { return; }

  var slides   = Array.prototype.slice.call(document.querySelectorAll('.question-slide'));
  var state    = CFG.questions;          // [{id, selected, review, visited}]
  var total    = state.length;
  var current  = 0;
  var submitted = false;

  var el = {
    palette:      document.getElementById('palette'),
    counter:      document.getElementById('qCounter'),
    timerBox:     document.getElementById('timerBox'),
    timerText:    document.getElementById('timerText'),
    saveStatus:   document.getElementById('saveStatus'),
    btnPrev:      document.getElementById('btnPrev'),
    btnNext:      document.getElementById('btnNext'),
    btnSaveNext:  document.getElementById('btnSaveNext'),
    btnClear:     document.getElementById('btnClear'),
    btnReview:    document.getElementById('btnReview'),
    btnReviewTxt: document.getElementById('btnReviewText'),
    btnSubmit:    document.getElementById('btnSubmit'),
    cntAnswered:  document.getElementById('cntAnswered'),
    cntNotAnswered: document.getElementById('cntNotAnswered'),
    cntReview:    document.getElementById('cntReview'),
    cntNotVisited: document.getElementById('cntNotVisited')
  };

  var submitModal = new bootstrap.Modal(document.getElementById('submitModal'));
  var timeUpModal = new bootstrap.Modal(document.getElementById('timeUpModal'));

  /* ---------------------------------------------------------------- */
  /*  Server communication                                             */
  /* ---------------------------------------------------------------- */

  function post(url, payload) {
    return fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': CFG.csrfToken
      },
      body: JSON.stringify(payload)
    }).then(function (res) {
      return res.json().catch(function () {
        return { ok: false, error: 'Unexpected server response.' };
      });
    });
  }

  function flashStatus(message, tone) {
    el.saveStatus.textContent = message;
    el.saveStatus.className = 'small mt-3 text-' + (tone || 'muted');
    if (tone !== 'danger') {
      window.setTimeout(function () { el.saveStatus.innerHTML = '&nbsp;'; }, 2000);
    }
  }

  /** Persist the state of one question. */
  function saveQuestion(index, opts) {
    var q = state[index];
    return post(CFG.urls.save, {
      attempt_id:  CFG.attemptId,
      question_id: q.id,
      option_id:   q.selected,
      answer_text: q.subjective ? q.text : null,
      review:      q.review ? 1 : 0
    }).then(function (res) {
      if (!res.ok) {
        if (res.expired) { autoSubmit(); return res; }
        flashStatus(res.error || 'Could not save your answer.', 'danger');
      } else if (opts && opts.notify) {
        flashStatus('Answer saved.', 'success');
      }
      return res;
    }).catch(function () {
      flashStatus('Network problem - your last change may not be saved.', 'danger');
    });
  }

  /* ---------------------------------------------------------------- */
  /*  Palette + rendering                                              */
  /* ---------------------------------------------------------------- */

  /** A question counts as answered if an option is picked or text is written. */
  function isAnswered(q) {
    return q.subjective ? q.text.trim() !== '' : q.selected !== null;
  }

  /** not-visited | answered | not-answered | review | review-answered */
  function stateClass(q) {
    if (q.review) { return isAnswered(q) ? 'review-answered' : 'review'; }
    if (isAnswered(q)) { return 'answered'; }
    return q.visited ? 'not-answered' : 'not-visited';
  }

  function buildPalette() {
    state.forEach(function (q, i) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'palette-btn';
      btn.textContent = i + 1;
      btn.setAttribute('aria-label', 'Go to question ' + (i + 1));
      btn.addEventListener('click', function () { goTo(i); });
      el.palette.appendChild(btn);
    });
  }

  function refreshPalette() {
    var counts = { answered: 0, notAnswered: 0, review: 0, notVisited: 0 };

    state.forEach(function (q, i) {
      var btn = el.palette.children[i];
      btn.className = 'palette-btn state-' + stateClass(q) + (i === current ? ' current' : '');

      if (q.review) { counts.review++; }
      else if (isAnswered(q)) { counts.answered++; }
      else if (q.visited) { counts.notAnswered++; }
      else { counts.notVisited++; }
    });

    el.cntAnswered.textContent    = counts.answered;
    el.cntNotAnswered.textContent = counts.notAnswered;
    el.cntReview.textContent      = counts.review;
    el.cntNotVisited.textContent  = counts.notVisited;
  }

  function refreshControls() {
    var q = state[current];
    el.btnPrev.disabled = current === 0;
    el.btnNext.disabled = current === total - 1;
    el.btnReview.classList.toggle('active', !!q.review);
    el.btnReviewTxt.textContent = q.review ? 'Unmark Review' : 'Mark for Review';
    el.counter.textContent = current + 1;
  }

  /** Show question `index`, marking it visited the first time. */
  function goTo(index) {
    if (index < 0 || index >= total) { return; }
    slides[current].classList.add('d-none');
    current = index;
    slides[current].classList.remove('d-none');

    var q = state[current];
    if (!q.visited) {
      q.visited = 1;
      saveQuestion(current);           // remember the visit server side
    }
    refreshPalette();
    refreshControls();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  /** Reflect the stored answers in the radio buttons / text boxes. */
  function paintSelections() {
    slides.forEach(function (slide, i) {
      var q = state[i];

      if (q.subjective) {
        var box = slide.querySelector('.subjective-input');
        if (box && box.value !== q.text) { box.value = q.text; }
        paintWordCount(slide);
        return;
      }

      slide.querySelectorAll('.option-item').forEach(function (item) {
        var isSel = q.selected !== null && Number(item.dataset.optionId) === q.selected;
        item.classList.toggle('selected', isSel);
        item.querySelector('input').checked = isSel;
      });
    });
  }

  /** Live word counter under a written answer, with the limit highlighted. */
  function paintWordCount(slide) {
    var box = slide.querySelector('.subjective-input');
    var out = slide.querySelector('.word-count');
    if (!box || !out) { return; }

    var words = box.value.trim() ? box.value.trim().split(/\s+/).length : 0;
    var max   = Number(box.dataset.maxWords || 0);
    out.textContent = words + ' word' + (words === 1 ? '' : 's')
                    + (max > 0 ? ' / ' + max : '');
    out.classList.toggle('text-danger', max > 0 && words > max);
  }

  /* ---------------------------------------------------------------- */
  /*  Answer selection                                                 */
  /* ---------------------------------------------------------------- */

  /* The listener sits on the radio's `change` event rather than on the
     surrounding label, so a click on the label (which forwards to the
     radio) still results in exactly one save request. */
  slides.forEach(function (slide, i) {
    slide.querySelectorAll('.option-item').forEach(function (item) {
      item.querySelector('input').addEventListener('change', function () {
        state[i].selected = Number(item.dataset.optionId);
        state[i].visited = 1;
        paintSelections();
        refreshPalette();
        saveQuestion(i);
      });
    });

    // ---- Written answers: autosave shortly after typing stops ----
    var box = slide.querySelector('.subjective-input');
    if (box) {
      var timer = null;
      box.addEventListener('input', function () {
        state[i].text = box.value;
        state[i].visited = 1;
        paintWordCount(slide);
        refreshPalette();

        window.clearTimeout(timer);
        timer = window.setTimeout(function () {
          saveQuestion(i, { notify: true });
        }, 900);
      });
      // Never lose the last keystrokes when moving away from the question.
      box.addEventListener('blur', function () {
        window.clearTimeout(timer);
        saveQuestion(i);
      });
    }
  });

  el.btnPrev.addEventListener('click', function () { goTo(current - 1); });
  el.btnNext.addEventListener('click', function () { goTo(current + 1); });

  el.btnSaveNext.addEventListener('click', function () {
    saveQuestion(current, { notify: true });
    if (current < total - 1) { goTo(current + 1); }
  });

  el.btnClear.addEventListener('click', function () {
    if (state[current].subjective) {
      if (!window.confirm('Clear everything you have written for this question?')) { return; }
      state[current].text = '';
    } else {
      state[current].selected = null;
    }
    paintSelections();
    refreshPalette();
    saveQuestion(current).then(function () { flashStatus('Answer cleared.', 'secondary'); });
  });

  el.btnReview.addEventListener('click', function () {
    var q = state[current];
    q.review = q.review ? 0 : 1;
    q.visited = 1;
    refreshPalette();
    refreshControls();
    saveQuestion(current);
  });

  /* ---------------------------------------------------------------- */
  /*  Countdown timer                                                  */
  /* ---------------------------------------------------------------- */

  var secondsLeft = CFG.secondsLeft;
  var warned = false;

  function pad(n) { return (n < 10 ? '0' : '') + n; }

  function paintTimer() {
    var s = Math.max(0, secondsLeft);
    var mm = Math.floor(s / 60), ss = s % 60;
    el.timerText.textContent = pad(mm) + ':' + pad(ss);

    el.timerBox.classList.toggle('warning', s <= 300 && s > 60);
    el.timerBox.classList.toggle('danger', s <= 60);

    // One-time five minute warning.
    if (!warned && s <= 300) {
      warned = true;
      flashStatus('Only 5 minutes remaining!', 'danger');
    }
    document.title = pad(mm) + ':' + pad(ss) + ' - Exam in progress';
  }

  function tick() {
    if (submitted) { return; }
    secondsLeft--;
    paintTimer();
    if (secondsLeft <= 0) {
      window.clearInterval(timerId);
      autoSubmit();
    }
  }

  paintTimer();
  var timerId = window.setInterval(tick, 1000);

  /* Re-sync with the server every 30s so a slow/throttled tab, a device
     sleep or a clock change cannot drift away from the real deadline. */
  window.setInterval(function () {
    if (submitted) { return; }
    fetch(CFG.urls.sync + '?attempt=' + CFG.attemptId, { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res.ok) { return; }
        if (res.status !== 'in_progress') { window.location.href = CFG.urls.result; return; }
        if (Math.abs(res.seconds_left - secondsLeft) > 2) {
          secondsLeft = res.seconds_left;
          paintTimer();
        }
        if (secondsLeft <= 0) { autoSubmit(); }
      })
      .catch(function () { /* offline - keep counting locally */ });
  }, 30000);

  /* ---------------------------------------------------------------- */
  /*  Submission                                                       */
  /* ---------------------------------------------------------------- */

  function doSubmit(auto) {
    if (submitted) { return; }
    submitted = true;
    window.clearInterval(timerId);

    post(CFG.urls.submit, { attempt_id: CFG.attemptId, auto: auto ? 1 : 0 })
      .then(function (res) {
        // Even on an error the attempt may already be closed server side,
        // so the result page is always the right destination.
        window.location.href = (res && res.redirect) || CFG.urls.result;
      })
      .catch(function () { window.location.href = CFG.urls.result; });
  }

  function autoSubmit() {
    if (submitted) { return; }
    submitModal.hide();
    timeUpModal.show();
    window.setTimeout(function () { doSubmit(true); }, 1200);
  }

  el.btnSubmit.addEventListener('click', function () {
    var answered = state.filter(isAnswered).length;
    var review   = state.filter(function (q) { return q.review; }).length;
    document.getElementById('mAnswered').textContent   = answered;
    document.getElementById('mUnanswered').textContent = total - answered;
    document.getElementById('mReview').textContent     = review;
    document.getElementById('unansweredWarn').hidden   = (total - answered) === 0;
    submitModal.show();
  });

  document.getElementById('btnConfirmSubmit').addEventListener('click', function () {
    submitModal.hide();
    doSubmit(false);
  });

  /*
   * Tab / window switch. A page cannot stop the switch itself, so the
   * question is asked the moment the student comes back: "Yes" submits the
   * paper, "No" keeps them on it.
   */
  var tabSwitchModal = new bootstrap.Modal(document.getElementById('tabSwitchModal'));
  var leftExam = false;

  document.addEventListener('visibilitychange', function () {
    if (submitted) { return; }
    if (document.hidden) {
      leftExam = true;
      return;
    }
    if (leftExam) {
      leftExam = false;
      submitModal.hide();
      tabSwitchModal.show();
    }
  });

  document.getElementById('btnTabStay').addEventListener('click', function () {
    tabSwitchModal.hide();
  });

  document.getElementById('btnTabSubmit').addEventListener('click', function () {
    tabSwitchModal.hide();
    // Flush the answer on screen first so nothing typed is lost.
    saveQuestion(current).then(function () { doSubmit(false); });
  });

  /*
   * Screenshot protection. A web page cannot stop the operating system from
   * capturing the screen, so the paper is blanked whenever a capture is
   * likely: the window loses focus (snipping tools take focus first), or
   * the Windows / Cmd or PrintScreen key goes down.
   */
  var shield = document.getElementById('examShield');
  var shieldTimer = null;

  function showShield(ms) {
    shield.hidden = false;
    window.clearTimeout(shieldTimer);
    if (ms) { shieldTimer = window.setTimeout(hideShield, ms); }
  }

  function hideShield() {
    window.clearTimeout(shieldTimer);
    shield.hidden = true;
  }

  window.addEventListener('blur', function () { showShield(); });
  window.addEventListener('focus', hideShield);
  shield.addEventListener('click', hideShield);

  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Meta' || ev.key === 'OS' || ev.key === 'PrintScreen') {
      showShield();
      return;
    }
    // Print, save, copy, cut and view-source shortcuts.
    var k = (ev.key || '').toLowerCase();
    if ((ev.ctrlKey || ev.metaKey) && ['p', 's', 'u'].indexOf(k) !== -1) {
      ev.preventDefault();
    }
  }, true);

  document.addEventListener('keyup', function (ev) {
    if (ev.key === 'PrintScreen') {
      // Overwrite whatever the key put on the clipboard.
      if (navigator.clipboard) { navigator.clipboard.writeText('').catch(function () {}); }
      showShield(1500);
    } else if (ev.key === 'Meta' || ev.key === 'OS') {
      showShield(800);
    }
  }, true);

  function isAnswerBox(node) {
    return node && (node.tagName === 'TEXTAREA' || node.tagName === 'INPUT');
  }

  ['copy', 'cut'].forEach(function (type) {
    document.addEventListener(type, function (ev) {
      if (!isAnswerBox(ev.target)) { ev.preventDefault(); }
    });
  });
  document.addEventListener('contextmenu', function (ev) { ev.preventDefault(); });
  document.addEventListener('dragstart', function (ev) { ev.preventDefault(); });

  (function paintWatermark() {
    var box = document.getElementById('examWatermark');
    var html = '';
    for (var i = 0; i < 60; i++) { html += '<span></span>'; }
    box.innerHTML = html;
    box.querySelectorAll('span').forEach(function (s) { s.textContent = CFG.watermark; });
  })();

  /* Warn before the student navigates away from an unfinished exam. */
  window.addEventListener('beforeunload', function (ev) {
    if (submitted) { return; }
    ev.preventDefault();
    ev.returnValue = '';
    return '';
  });

  /* Keyboard shortcuts: arrow keys move, 1-4 pick an option. */
  document.addEventListener('keydown', function (ev) {
    // Never hijack keys while a written answer is being typed.
    if (ev.target.tagName === 'TEXTAREA') { return; }
    if (ev.target.tagName === 'INPUT' && ev.target.type !== 'radio') { return; }
    if (ev.key === 'ArrowRight') { goTo(current + 1); }
    else if (ev.key === 'ArrowLeft') { goTo(current - 1); }
    else if (/^[1-6]$/.test(ev.key)) {
      var items = slides[current].querySelectorAll('.option-item');
      var pick = items[Number(ev.key) - 1];
      if (pick) { pick.click(); }
    }
  });

  /* ---------------------------------------------------------------- */
  /*  Boot                                                             */
  /* ---------------------------------------------------------------- */

  buildPalette();
  paintSelections();
  if (!state[0].visited) {
    state[0].visited = 1;
    saveQuestion(0);
  }
  refreshPalette();
  refreshControls();
})();
