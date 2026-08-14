</main>

<footer class="footer-oep">
  <div class="container">
    <div class="row gy-4">
      <div class="col-lg-5">
        <h5 class="d-flex align-items-center gap-2 mb-3">
          <span class="brand-badge" style="width:34px;height:34px;border-radius:11px;
                display:grid;place-items:center;background:var(--oep-grad-brand);color:#fff">
            <i class="bi bi-mortarboard-fill"></i>
          </span>
          <?= e(APP_NAME) ?>
        </h5>
        <p class="mb-3 small" style="max-width:26rem">
          A complete examination suite: faculty set and allot the papers, students
          attempt them on a server controlled timer, and results with full analysis
          follow automatically.
        </p>
        <div class="d-flex gap-2 small">
          <span class="chip" style="background:rgba(255,255,255,.08);color:rgba(255,255,255,.75)">
            <i class="bi bi-shield-check"></i>Secure
          </span>
          <span class="chip" style="background:rgba(255,255,255,.08);color:rgba(255,255,255,.75)">
            <i class="bi bi-stopwatch"></i>Timed
          </span>
          <span class="chip" style="background:rgba(255,255,255,.08);color:rgba(255,255,255,.75)">
            <i class="bi bi-graph-up"></i>Analysed
          </span>
        </div>
      </div>
      <div class="col-6 col-lg-3">
        <h6 class="text-white mb-3">Quick Links</h6>
        <ul class="list-unstyled small mb-0 d-grid gap-2">
          <li><a href="<?= url('index.php') ?>">Home</a></li>
          <li><a href="<?= url('dashboard.php') ?>">Exams</a></li>
          <li><a href="<?= url('instructions.php') ?>">Instructions</a></li>
          <li><a href="<?= url('results.php') ?>">Results</a></li>
        </ul>
      </div>
      <div class="col-6 col-lg-4">
        <h6 class="text-white mb-3">Account</h6>
        <ul class="list-unstyled small mb-0 d-grid gap-2">
          <li><a href="<?= url('login.php') ?>">Student Login</a></li>
          <li><a href="<?= url('register.php') ?>">Student Registration</a></li>
          <li><a href="<?= url('faculty/login.php') ?>">Faculty Login</a></li>
          <li><a href="<?= url('faculty/register.php') ?>">Faculty Registration</a></li>
          <li><a href="<?= url('admin/login.php') ?>">Administrator Login</a></li>
          <li><a href="<?= url('admin/register.php') ?>">Register your College</a></li>
        </ul>
      </div>
    </div>
    <hr class="border-secondary my-4">
    <div class="small text-center mb-0">
      &copy; <?= date('Y') ?> <?= e(APP_NAME) ?>. All rights reserved.
    </div>
  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= url('assets/js/main.js') ?>"></script>
</body>
</html>
