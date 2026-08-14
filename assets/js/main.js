/* =====================================================================
   Online Exam Portal - site wide behaviour
   (Bootstrap form validation, password toggles, confirm dialogs, etc.)
   ===================================================================== */
(function () {
  'use strict';

  /* ---------------- Bootstrap style client side validation ---------- */
  document.querySelectorAll('form.needs-validation').forEach(function (form) {
    form.addEventListener('submit', function (ev) {
      // Confirm password fields must match before the form is allowed through.
      // Registration forms use #password, change-password forms use #new_password.
      var pwd = form.querySelector('#new_password') || form.querySelector('#password');
      var cnf = form.querySelector('#confirm_password');
      if (pwd && cnf) {
        cnf.setCustomValidity(pwd.value === cnf.value ? '' : 'mismatch');
      }
      if (!form.checkValidity()) {
        ev.preventDefault();
        ev.stopPropagation();
      }
      form.classList.add('was-validated');
    });
  });

  /* -------------------------- Show / hide password ------------------ */
  document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var input = document.querySelector(btn.dataset.togglePassword);
      if (!input) { return; }
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.innerHTML = '<i class="bi bi-eye' + (show ? '-slash' : '') + '"></i>';
    });
  });

  /* --------------------- Password strength meter -------------------- */
  document.querySelectorAll('[data-strength]').forEach(function (input) {
    var meter = document.querySelector(input.dataset.strength);
    if (!meter) { return; }
    var label = meter.querySelector('.pw-meter-label');
    var words = ['Password strength', 'Very weak', 'Weak', 'Good', 'Strong'];

    function score(value) {
      if (!value) { return 0; }
      var points = 0;
      if (value.length >= 6) { points++; }
      if (value.length >= 10) { points++; }
      if (/[A-Z]/.test(value) && /[a-z]/.test(value)) { points++; }
      if (/[0-9]/.test(value)) { points++; }
      if (/[^A-Za-z0-9]/.test(value)) { points++; }
      // Collapse to a 1-4 scale.
      return Math.max(1, Math.min(4, points - 1));
    }

    input.addEventListener('input', function () {
      var s = score(input.value);
      meter.dataset.score = String(s);
      if (label) { label.textContent = words[s]; }
    });
  });

  /* ------------------- Live preview of a picked logo ---------------- */
  var logoInput = document.getElementById('logo');
  var logoPreview = document.getElementById('logoPreview');
  if (logoInput && logoPreview) {
    logoInput.addEventListener('change', function () {
      var file = logoInput.files && logoInput.files[0];
      if (!file || !/^image\//.test(file.type)) { return; }
      var reader = new FileReader();
      reader.onload = function (ev) {
        logoPreview.innerHTML = '';
        var img = document.createElement('img');
        img.src = ev.target.result;
        img.alt = '';
        logoPreview.appendChild(img);
      };
      reader.readAsDataURL(file);
    });
  }

  /* --------- Branch list follows the chosen college on sign-up ------- */
  var collegeSelect = document.getElementById('college_id');
  var branchSelect  = document.getElementById('branch_id');
  if (collegeSelect && branchSelect && window.OEP_BRANCHES) {
    var applyBranches = function (keep) {
      var list = window.OEP_BRANCHES[collegeSelect.value] || [];
      branchSelect.innerHTML = '';
      var blank = document.createElement('option');
      blank.value = '';
      blank.textContent = list.length ? '-- select your branch --' : 'Choose a college first';
      branchSelect.appendChild(blank);
      list.forEach(function (b) {
        var o = document.createElement('option');
        o.value = b.id;
        o.textContent = b.name;
        if (String(b.id) === String(keep)) { o.selected = true; }
        branchSelect.appendChild(o);
      });
      branchSelect.disabled = list.length === 0;
    };
    collegeSelect.addEventListener('change', function () { applyBranches(null); });
    applyBranches(branchSelect.dataset.selected || null);
  }

  /* ------------- Instructions page: enable Start after accept ------- */
  var accept = document.getElementById('acceptTerms');
  var startBtn = document.getElementById('startExamBtn');
  if (accept && startBtn) {
    var syncStart = function () { startBtn.disabled = !accept.checked; };
    accept.addEventListener('change', syncStart);
    syncStart();   // the browser may restore a ticked checkbox on back/refresh
  }

  /* ------------------ Generic confirmation for links/forms ---------- */
  document.querySelectorAll('[data-confirm]').forEach(function (node) {
    node.addEventListener('click', function (ev) {
      if (!window.confirm(node.dataset.confirm)) {
        ev.preventDefault();
      }
    });
  });

  /* --------------------- Auto dismiss flash messages ---------------- */
  document.querySelectorAll('.alert-dismissible').forEach(function (alert) {
    window.setTimeout(function () {
      if (window.bootstrap && bootstrap.Alert.getOrCreateInstance(alert)) {
        bootstrap.Alert.getOrCreateInstance(alert).close();
      }
    }, 6000);
  });

  /* --------------- Live client side search for admin tables --------- */
  var searchBox = document.getElementById('tableSearch');
  if (searchBox) {
    searchBox.addEventListener('input', function () {
      var needle = searchBox.value.toLowerCase();
      document.querySelectorAll('[data-searchable] tbody tr').forEach(function (row) {
        row.hidden = row.textContent.toLowerCase().indexOf(needle) === -1;
      });
    });
  }
})();
