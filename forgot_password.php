<?php
/**
 * Forgot password - step 1: generate a one hour reset token.
 *
 * XAMPP has no mail server configured by default, so instead of emailing
 * the link we show it on screen when DEBUG_MODE is on. In production
 * replace that block with mail() / PHPMailer.
 */
require_once __DIR__ . '/includes/auth.php';

$pageTitle  = 'Forgot Password';
$activeNav  = 'login';
$error      = '';
$resetLink  = '';
$done       = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = post('email');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $st = db()->prepare('SELECT id FROM students WHERE email = ?');
        $st->execute([$email]);
        $student = $st->fetch();

        if ($student) {
            $token = bin2hex(random_bytes(32));
            $upd = db()->prepare(
                'UPDATE students SET reset_token = ?, reset_expires = ? WHERE id = ?'
            );
            $upd->execute([$token, date('Y-m-d H:i:s', time() + 3600), $student['id']]);

            $resetLink = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://'
                       . $_SERVER['HTTP_HOST']
                       . url('reset_password.php?token=' . $token);
        }
        // Always report success so the form cannot be used to discover
        // which email addresses are registered.
        $done = true;
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="auth-wrap">
  <div class="auth-card">
    <div class="text-center mb-4">
      <div class="feature-icon bg-soft-warning mx-auto"><i class="bi bi-key"></i></div>
      <h3 class="mb-1">Forgot Password</h3>
      <p class="text-muted-2 small mb-0">
        Enter your registered email and we will create a password reset link.
      </p>
    </div>

    <?php if ($error): ?>
      <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($done): ?>
      <div class="alert alert-success">
        <i class="bi bi-check-circle-fill me-1"></i>
        If that email address is registered, a reset link has been generated for it.
      </div>
      <?php if ($resetLink && DEBUG_MODE): ?>
        <div class="alert alert-info small">
          <strong>Local development:</strong> email sending is disabled, so use this link:<br>
          <a href="<?= e($resetLink) ?>" class="text-break"><?= e($resetLink) ?></a>
        </div>
      <?php endif; ?>
      <a href="<?= url('login.php') ?>" class="btn btn-primary w-100">Back to Login</a>
    <?php else: ?>
      <form method="post" novalidate class="needs-validation">
        <?= csrf_field() ?>
        <div class="mb-3">
          <label class="form-label" for="email">Registered Email</label>
          <div class="input-group">
            <span class="input-group-text bg-white"><i class="bi bi-envelope"></i></span>
            <input type="email" class="form-control" id="email" name="email"
                   placeholder="you@example.com" required autofocus>
            <div class="invalid-feedback">Enter a valid email address.</div>
          </div>
        </div>
        <button type="submit" class="btn btn-primary w-100 py-2">Send Reset Link</button>
      </form>
      <p class="text-center small text-muted-2 mt-4 mb-0">
        Remembered it? <a href="<?= url('login.php') ?>" class="fw-semibold">Back to login</a>
      </p>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
