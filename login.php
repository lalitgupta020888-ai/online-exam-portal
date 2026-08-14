<?php
/**
 * Student login.
 */
require_once __DIR__ . '/includes/auth.php';

if (is_student_logged_in()) {
    redirect('dashboard.php');
}

$pageTitle = 'Student Login';
$activeNav = 'login';
$error     = '';
$email     = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $email    = post('email');
    $password = post('password');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        $error = 'Please enter your email address and password.';
    } else {
        $st = db()->prepare('SELECT * FROM students WHERE email = ?');
        $st->execute([$email]);
        $student = $st->fetch();

        // Same message for "no such user" and "wrong password" on purpose.
        if (!$student || !password_verify($password, $student['password_hash'])) {
            $error = 'Invalid email address or password.';
        } elseif (!(int)$student['is_active']) {
            $error = 'This account has been deactivated. Contact the administrator.';
        } else {
            login_student($student);
            $target = $_SESSION['redirect_after_login'] ?? '';
            unset($_SESSION['redirect_after_login']);
            set_flash('success', 'Welcome back, ' . $student['name'] . '!');
            redirect($target ?: 'dashboard.php');
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="auth-wrap">
  <div class="auth-card">
    <div class="text-center mb-4">
      <div class="feature-icon bg-soft-primary mx-auto"><i class="bi bi-box-arrow-in-right"></i></div>
      <h3 class="mb-1">Student Login</h3>
      <p class="text-muted-2 small mb-0">Log in to view and attempt your examinations.</p>
    </div>

    <?php if ($error): ?>
      <div class="alert alert-danger d-flex align-items-center">
        <i class="bi bi-exclamation-triangle-fill me-2"></i><?= e($error) ?>
      </div>
    <?php endif; ?>

    <form method="post" novalidate class="needs-validation">
      <?= csrf_field() ?>

      <div class="mb-3">
        <label class="form-label" for="email">Email Address</label>
        <div class="input-group">
          <span class="input-group-text bg-white"><i class="bi bi-envelope"></i></span>
          <input type="email" class="form-control" id="email" name="email"
                 value="<?= e($email) ?>" placeholder="you@example.com" required autofocus>
          <div class="invalid-feedback">Enter a valid email address.</div>
        </div>
      </div>

      <div class="mb-2">
        <label class="form-label" for="password">Password</label>
        <div class="input-group">
          <span class="input-group-text bg-white"><i class="bi bi-lock"></i></span>
          <input type="password" class="form-control" id="password" name="password"
                 placeholder="Your password" required>
          <button class="btn btn-outline-secondary" type="button"
                  data-toggle-password="#password" aria-label="Show password">
            <i class="bi bi-eye"></i>
          </button>
          <div class="invalid-feedback">Enter your password.</div>
        </div>
      </div>

      <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="remember" name="remember">
          <label class="form-check-label small" for="remember">Remember me</label>
        </div>
        <a href="<?= url('forgot_password.php') ?>" class="small fw-semibold">Forgot password?</a>
      </div>

      <button type="submit" class="btn btn-primary w-100 py-2">
        <i class="bi bi-box-arrow-in-right me-1"></i>Login
      </button>
    </form>

    <div class="auth-divider"><span>New here?</span></div>

    <a href="<?= url('register.php') ?>" class="btn btn-outline-primary w-100">
      <i class="bi bi-person-plus me-1"></i>Create a Student Account
    </a>

    <p class="text-center small text-muted-2 mt-4 mb-0">
      <a href="<?= url('faculty/login.php') ?>"><i class="bi bi-person-video3 me-1"></i>Faculty</a>
      &nbsp;&middot;&nbsp;
      <a href="<?= url('admin/login.php') ?>"><i class="bi bi-shield-lock me-1"></i>Administrator</a>
    </p>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
