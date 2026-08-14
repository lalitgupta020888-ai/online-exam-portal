<?php
/**
 * Forgot password - step 2: set a new password using the emailed token.
 */
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Reset Password';
$activeNav = 'login';
$errors    = [];

$token = $_REQUEST['token'] ?? '';
$st = db()->prepare('SELECT * FROM students WHERE reset_token = ? AND reset_expires > ?');
$st->execute([$token, date('Y-m-d H:i:s')]);
$student = $st->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $student) {
    verify_csrf();
    $password = post('password');
    $confirm  = post('confirm_password');

    if (strlen($password) < 6) {
        $errors['password'] = 'Password must be at least 6 characters long.';
    }
    if ($password !== $confirm) {
        $errors['confirm_password'] = 'The two passwords do not match.';
    }

    if (!$errors) {
        $upd = db()->prepare(
            'UPDATE students SET password_hash = ?, reset_token = NULL, reset_expires = NULL
              WHERE id = ?'
        );
        $upd->execute([password_hash($password, PASSWORD_DEFAULT), $student['id']]);
        set_flash('success', 'Your password has been updated. Please log in.');
        redirect('login.php');
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="auth-wrap">
  <div class="auth-card">
    <div class="text-center mb-4">
      <div class="feature-icon bg-soft-primary mx-auto"><i class="bi bi-shield-lock"></i></div>
      <h3 class="mb-1">Set a New Password</h3>
    </div>

    <?php if (!$student): ?>
      <div class="alert alert-danger">
        This reset link is invalid or has expired. Please request a new one.
      </div>
      <a href="<?= url('forgot_password.php') ?>" class="btn btn-primary w-100">
        Request New Link
      </a>
    <?php else: ?>
      <p class="text-muted-2 small text-center">
        Resetting the password for <strong><?= e($student['email']) ?></strong>
      </p>
      <form method="post" novalidate class="needs-validation">
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">

        <div class="mb-3">
          <label class="form-label" for="password">New Password</label>
          <input type="password" class="form-control <?= isset($errors['password']) ? 'is-invalid' : '' ?>"
                 id="password" name="password" required minlength="6">
          <div class="invalid-feedback"><?= e($errors['password'] ?? 'At least 6 characters.') ?></div>
        </div>

        <div class="mb-3">
          <label class="form-label" for="confirm_password">Confirm New Password</label>
          <input type="password"
                 class="form-control <?= isset($errors['confirm_password']) ? 'is-invalid' : '' ?>"
                 id="confirm_password" name="confirm_password" required minlength="6">
          <div class="invalid-feedback">
            <?= e($errors['confirm_password'] ?? 'Passwords must match.') ?>
          </div>
        </div>

        <button type="submit" class="btn btn-primary w-100 py-2">Update Password</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
