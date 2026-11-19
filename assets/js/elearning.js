/* E-Content / E-Lecture pages: library filters and the protected reader. */
(function () {
  'use strict';

  /* ---------- Library search + subject chips ---------- */
  var search = document.getElementById('elSearch');
  var chips = document.querySelectorAll('.el-chips button');
  var subject = '';
  function filter() {
    var q = search ? search.value.trim().toLowerCase() : '';
    var shown = 0;
    document.querySelectorAll('[data-el-item]').forEach(function (el) {
      var ok = (q === '' || el.dataset.elItem.indexOf(q) !== -1)
            && (subject === '' || el.dataset.elSubject === subject);
      el.classList.toggle('d-none', !ok);
      if (ok) shown++;
    });
    var none = document.getElementById('elNone');
    if (none) none.classList.toggle('d-none', shown > 0);
  }
  if (search) search.addEventListener('input', filter);
  chips.forEach(function (c) {
    c.addEventListener('click', function () {
      chips.forEach(function (x) { x.classList.remove('active'); });
      c.classList.add('active');
      subject = c.dataset.subject;
      filter();
    });
  });

  /* ---------- Protected reader ---------- */
  var cfg = window.EL_READER;
  var body = document.getElementById('readerBody');
  if (!cfg || !body) return;

  var reader = document.getElementById('reader');
  var shield = document.getElementById('shield');

  // Decode the UTF-8 HTML and draw it.
  try {
    var bin = atob(cfg.payload), bytes = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
    body.innerHTML = new TextDecoder('utf-8').decode(bytes);
  } catch (e) {
    body.textContent = 'This content could not be displayed in this browser.';
  }
  cfg.payload = null;

  // Watermark: the student's name tiled across the text.
  var mark = cfg.mark.replace(/[<>&"]/g, '');
  var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="380" height="210">' +
    '<text x="190" y="105" text-anchor="middle" transform="rotate(-24 190 105)" ' +
    'font-family="Arial, sans-serif" font-size="15" font-weight="700" fill="rgba(67,56,202,0.10)">' +
    mark + '</text></svg>';
  document.getElementById('watermark').style.backgroundImage =
    'url("data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg) + '")';

  // Table of contents from the headings.
  var toc = document.getElementById('toc');
  var heads = body.querySelectorAll('h2, h3, h4');
  if (toc) {
    if (heads.length < 2) {
      toc.classList.add('d-none');
    } else {
      heads.forEach(function (h, n) {
        h.id = 'sec-' + n;
        var a = document.createElement('a');
        a.href = '#' + h.id;
        a.textContent = h.textContent;
        a.className = 'lvl-' + h.tagName.toLowerCase();
        toc.appendChild(a);
      });
    }
  }

  // Reading progress.
  var bar = document.getElementById('readProgress');
  function progress() {
    var r = body.getBoundingClientRect();
    var total = r.height - window.innerHeight + 160;
    var done = Math.min(1, Math.max(0, (160 - r.top) / Math.max(1, total)));
    bar.style.width = (done * 100) + '%';
  }
  window.addEventListener('scroll', progress, { passive: true });
  progress();

  // Font size and theme (remembered per browser).
  function store(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
  function load(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
  var size = parseInt(load('el_font') || '18', 10);
  function applySize() { body.style.fontSize = size + 'px'; }
  applySize();
  document.querySelectorAll('[data-font]').forEach(function (b) {
    b.addEventListener('click', function () {
      size = Math.min(26, Math.max(14, size + parseInt(b.dataset.font, 10) * 2));
      applySize(); store('el_font', size);
    });
  });
  function setTheme(t) {
    reader.dataset.theme = t;
    document.querySelectorAll('[data-theme-set]').forEach(function (b) {
      b.classList.toggle('active', b.dataset.themeSet === t);
    });
    store('el_theme', t);
  }
  setTheme(load('el_theme') || 'light');
  document.querySelectorAll('[data-theme-set]').forEach(function (b) {
    b.addEventListener('click', function () { setTheme(b.dataset.themeSet); });
  });

  /* Guards: no select / copy / right click / drag / save / print / screenshot keys. */
  function stop(e) { e.preventDefault(); e.stopPropagation(); return false; }
  ['copy', 'cut', 'contextmenu', 'dragstart', 'selectstart'].forEach(function (ev) {
    document.addEventListener(ev, function (e) {
      if (e.target.closest && e.target.closest('input, textarea')) return;
      return stop(e);
    });
  });

  function shieldOn() { reader.classList.add('is-shielded'); }
  function shieldOff() { reader.classList.remove('is-shielded'); }
  function wipeClipboard() {
    try { if (navigator.clipboard) navigator.clipboard.writeText(''); } catch (e) {}
  }

  document.addEventListener('keydown', function (e) {
    var k = (e.key || '').toLowerCase();
    var mod = e.ctrlKey || e.metaKey;
    if (k === 'printscreen') { wipeClipboard(); shieldOn(); return stop(e); }
    if (k === 'f12') return stop(e);
    if (mod && ['c', 'x', 'a', 's', 'p', 'u'].indexOf(k) !== -1) return stop(e);
    if (mod && e.shiftKey && ['i', 'j', 'c', 's', 'k'].indexOf(k) !== -1) { shieldOn(); return stop(e); }
    // Windows snipping (Win+Shift+S) and macOS screenshots (Cmd+Shift+3/4/5).
    if ((e.metaKey && e.shiftKey) || (e.key === 'Meta' && e.shiftKey) || (e.key === 'Shift' && e.metaKey)) shieldOn();
  }, true);
  document.addEventListener('keyup', function (e) {
    if ((e.key || '').toLowerCase() === 'printscreen') { wipeClipboard(); shieldOn(); }
  }, true);

  // Snipping tools and screen recorders take focus away from the page.
  window.addEventListener('blur', shieldOn);
  document.addEventListener('visibilitychange', function () { if (document.hidden) shieldOn(); });
  shield.addEventListener('click', shieldOff);
  window.addEventListener('beforeprint', function () { body.style.visibility = 'hidden'; });
  window.addEventListener('afterprint', function () { body.style.visibility = ''; });
})();
