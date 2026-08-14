<?php
/**
 * Faculty login. Accepts either the username or the registered email.
 */
require_once __DIR__ . '/../includes/auth.php';

if (is_faculty_logged_in()) {
    redirect('faculty/index.php');
}

$error    = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username = post('username');
    $password = post('password');

    if ($username === '' || $password === '') {
        $error = 'Please enter your username and password.';
    } else {
        $st = db()->prepare('SELECT * FROM faculty WHERE username = ? OR email = ?');
        $st->execute([$username, $username]);
        $faculty = $st->fetch();

        if (!$faculty || !password_verify($password, $faculty['password_hash'])) {
            $error = 'Invalid username or password.';
        } elseif ($faculty['status'] === 'pending') {
            $error = 'Your registration is still waiting for administrator approval. '
                   . 'You will be able to sign in once it is approved.';
        } elseif ($faculty['status'] === 'suspended' || !(int)$faculty['is_active']) {
            $error = 'This faculty account has been deactivated. Please contact the administrator.';
        } else {
            login_faculty($faculty);
            set_flash('success', 'Welcome back, ' . $faculty['name'] . '.');
            redirect('faculty/index.php');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Faculty Login &middot; <?= e(APP_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= url('assets/css/style.css') ?>" rel="stylesheet">
</head>
<body class="auth-body-faculty">

<div class="auth-wrap">
  <div class="auth-card">
    <div class="text-center mb-4">
      <div class="feature-icon bg-soft-purple mx-auto"><i class="bi bi-person-video3"></i></div>
      <h3 class="mb-1">Faculty Login</h3>
      <p class="text-muted-2 small mb-0">Set papers, evaluate answers and review results.</p>
    </div>

    <?= render_flash() ?>

    <?php if ($error): ?>
      <div class="alert alert-danger d-flex align-items-center">
        <i class="bi bi-exclamation-triangle-fill me-2"></i><?= e($error) ?>
      </div>
    <?php endif; ?>

    <form method="post" novalidate class="needs-validation">
      <?= csrf_field() ?>

      <div class="mb-3">
        <label class="form-label" for="username">Username or Email</label>
        <div class="input-group">
          <span class="input-group-text bg-white"><i class="bi bi-person-badge"></i></span>
          <input type="text" class="form-control" id="username" name="username"
                 value="<?= e($username) ?>" required autofocus>
          <div class="invalid-feedback">Enter your username or email.</div>
        </div>
      </div>

      <div class="mb-4">
        <label class="form-label" for="password">Password</label>
        <div class="input-group">
          <span class="input-group-text bg-white"><i class="bi bi-lock"></i></span>
          <input type="password" class="form-control" id="password" name="password" required>
          <button class="btn btn-outline-secondary" type="button"
                  data-toggle-password="#password" aria-label="Show password">
            <i class="bi bi-eye"></i>
          </button>
          <div class="invalid-feedback">Enter your password.</div>
        </div>
      </div>

      <button type="submit" class="btn btn-primary w-100 py-2">
        <i class="bi bi-box-arrow-in-right me-1"></i>Login
      </button>
    </form>

    <div class="auth-divider"><span>New here?</span></div>

    <a href="<?= url('faculty/register.php') ?>" class="btn btn-outline-primary w-100">
      <i class="bi bi-person-plus me-1"></i>Register as Faculty
    </a>

    <p class="text-center small text-muted-2 mt-4 mb-0">
      <a href="<?= url('index.php') ?>"><i class="bi bi-arrow-left me-1"></i>Back to website</a>
    </p>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= url('assets/js/main.js') ?>"></script>
</body>
</html>
