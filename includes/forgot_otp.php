<?php
/**
 * Shared "forgot password" flow for administrators and faculty, using a one
 * time password sent by email or SMS. The calling page sets $otpRole first:
 *
 *   $otpRole = ['key' => 'admin', 'table' => 'admins', 'title' => 'Administrator',
 *               'login' => 'admin/login.php', 'body' => 'auth-body-admin'];
 *
 * Optional: 'match' (columns the typed identifier is compared with, default
 * username + email; phone is always tried) and 'ident_label' (field label).
 *
 * Steps (kept in the session): 1 find the account and send a code,
 * 2 enter the code, 3 choose a new password.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/otp.php';

$key   = $otpRole['key'];
$table = $otpRole['table'];   // fixed by the calling page, never user input
$state = $_SESSION['pw_otp'][$key] ?? ['step' => 1];
$error = '';
$info  = '';
$identLabel = $otpRole['ident_label'] ?? 'Username, Email or Phone';

$saveState = static function (array $s) use ($key): void {
    $_SESSION['pw_otp'][$key] = $s;
};

/** Send a code over the chosen channel; returns an error message or ''. */
$sendCode = static function (array $acc, string $channel) use ($key): string {
    $wait = otp_wait_seconds($key, (int)$acc['id']);
    if ($wait > 0) {
        return "Please wait $wait seconds before asking for another code.";
    }
    $code = otp_issue($key, (int)$acc['id']);
    if ($channel === 'phone') {
        return sms_send_otp($acc['phone'], $code);
    }
    $body = "Hello {$acc['name']},\n\n"
          . "Your password reset code for " . APP_NAME . " is: $code\n\n"
          . "It is valid for " . (OTP_TTL_SECONDS / 60) . " minutes. "
          . "If you did not ask for this, ignore this email - your password stays the same.\n";
    return smtp_send($acc['email'], 'Password reset code: ' . $code, $body);
};

$loadAccount = static function (int $id) use ($table) {
    $st = db()->prepare("SELECT id, name, email, phone FROM `$table` WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch();
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post('action');

    if ($action === 'restart') {
        $state = ['step' => 1];
        $saveState($state);
    } elseif ($action === 'send') {
        $ident   = post('identifier');
        $channel = post('channel') === 'phone' ? 'phone' : 'email';

        if ($ident === '') {
            $error = 'Enter your ' . strtolower($identLabel) . '.';
        } elseif ($channel === 'email' && !email_otp_enabled()) {
            $error = 'Email OTP is not available right now. Please contact the administrator.';
        } elseif ($channel === 'phone' && !sms_otp_enabled()) {
            $error = 'Phone OTP is not available yet. Please use email OTP.';
        } else {
            $phone = normalize_phone($ident);
            $cols = $otpRole['match'] ?? ['username', 'email'];
            $st = db()->prepare("SELECT id, name, email, phone FROM `$table` WHERE "
                              . implode(' OR ', array_map(static fn($c) => "`$c` = ?", $cols)));
            $st->execute(array_fill(0, count($cols), $ident));
            $acc = $st->fetch();
            if (!$acc && strlen($phone) === 10) {
                // Phone numbers are stored in whatever format was typed, so
                // compare on the last 10 digits.
                foreach (db()->query("SELECT id, name, email, phone FROM `$table`
                                       WHERE phone IS NOT NULL AND phone <> ''") as $row) {
                    if (normalize_phone($row['phone']) === $phone) {
                        $acc = $row;
                        break;
                    }
                }
            }

            if (!$acc) {
                $error = 'No ' . strtolower($otpRole['title']) . ' account matches that '
                       . strtolower($identLabel) . '.';
            } elseif ($channel === 'email' && !filter_var($acc['email'], FILTER_VALIDATE_EMAIL)) {
                $error = 'This account has no email address on record. Try phone OTP.';
            } elseif ($channel === 'phone' && strlen(normalize_phone($acc['phone'])) !== 10) {
                $error = 'This account has no mobile number on record. Try email OTP.';
            } else {
                $error = $sendCode($acc, $channel);
                if ($error === '') {
                    $state = ['step' => 2, 'id' => (int)$acc['id'], 'channel' => $channel];
                    $saveState($state);
                }
            }
        }
    } elseif ($action === 'resend' && ($state['step'] ?? 1) === 2) {
        $acc = $loadAccount((int)$state['id']);
        $error = $acc ? $sendCode($acc, $state['channel']) : 'Account not found.';
        if ($error === '') {
            $info = 'A new code has been sent.';
        }
    } elseif ($action === 'verify' && ($state['step'] ?? 1) === 2) {
        $code = preg_replace('/\D/', '', post('otp'));
        if (strlen($code) !== 6) {
            $error = 'Enter the 6 digit code.';
        } elseif (!otp_verify($key, (int)$state['id'], $code)) {
            $error = 'That code is wrong or has expired. Check it or ask for a new one.';
        } else {
            $state['step'] = 3;
            $saveState($state);
        }
    } elseif ($action === 'reset' && ($state['step'] ?? 1) === 3) {
        $password = post('password');
        $errs = password_errors($password, post('confirm_password'));
        if ($errs) {
            $error = implode(' ', $errs);
        } else {
            db()->prepare("UPDATE `$table` SET password_hash = ?, password_changed_at = ?
                            WHERE id = ?")
                ->execute([password_hash($password, PASSWORD_DEFAULT), date('Y-m-d H:i:s'),
                           (int)$state['id']]);
            unset($_SESSION['pw_otp'][$key]);
            set_flash('success', 'Your password has been reset. Please log in with the new password.');
            redirect($otpRole['login']);
        }
    }
}

$step = (int)($state['step'] ?? 1);
$dest = '';
if ($step === 2) {
    $acc = $loadAccount((int)$state['id']);
    if ($acc) {
        $dest = $state['channel'] === 'phone' ? mask_phone($acc['phone']) : mask_email($acc['email']);
    }
}
$emailOn = email_otp_enabled();
$smsOn   = sms_otp_enabled();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Forgot Password &middot; <?= e(APP_NAME) ?></title>
<meta name="theme-color" content="#0f766e">
<link rel="icon" type="image/svg+xml" href="<?= asset_url('assets/images/favicon.svg') ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= url('assets/css/style.css') ?>" rel="stylesheet">
</head>
<body class="<?= e($otpRole['body']) ?>">

<div class="auth-wrap">
  <?= brand_lockup() ?>
  <div class="auth-card">
    <div class="text-center mb-4">
      <div class="feature-icon bg-soft-warning mx-auto"><i class="bi bi-key"></i></div>
      <h3 class="mb-1"><?= e($otpRole['title']) ?> - Forgot Password</h3>
      <p class="text-muted-2 small mb-0">
        <?= ['1' => 'We will send a one time code to your registered email or phone.',
             '2' => 'Enter the code we sent you.',
             '3' => 'Choose a new password.'][(string)$step] ?>
      </p>
    </div>

    <?php if ($error): ?>
      <div class="alert alert-danger d-flex align-items-center">
        <i class="bi bi-exclamation-triangle-fill me-2"></i><?= e($error) ?>
      </div>
    <?php elseif ($info): ?>
      <div class="alert alert-success"><?= e($info) ?></div>
    <?php endif; ?>

    <?php if ($step === 1): ?>
      <form method="post" novalidate class="needs-validation">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="send">
        <div class="mb-3">
          <label class="form-label" for="identifier"><?= e($identLabel) ?></label>
          <div class="input-group">
            <span class="input-group-text bg-white"><i class="bi bi-person-badge"></i></span>
            <input type="text" class="form-control" id="identifier" name="identifier"
                   value="<?= e(post('identifier')) ?>" required autofocus>
            <div class="invalid-feedback">Enter your <?= e(strtolower($identLabel)) ?>.</div>
          </div>
        </div>
        <label class="form-label">Send the code by</label>
        <div class="d-flex gap-3 mb-4">
          <div class="form-check">
            <input class="form-check-input" type="radio" name="channel" id="ch_email" value="email"
                   <?= post('channel') !== 'phone' ? 'checked' : '' ?> <?= $emailOn ? '' : 'disabled' ?>>
            <label class="form-check-label" for="ch_email"><i class="bi bi-envelope me-1"></i>Email OTP</label>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="radio" name="channel" id="ch_phone" value="phone"
                   <?= post('channel') === 'phone' ? 'checked' : '' ?> <?= $smsOn ? '' : 'disabled' ?>>
            <label class="form-check-label" for="ch_phone">
              <i class="bi bi-phone me-1"></i>Phone OTP
              <?php if (!$smsOn): ?><span class="small text-muted-2">(coming soon)</span><?php endif; ?>
            </label>
          </div>
        </div>
        <button type="submit" class="btn btn-primary w-100 py-2">
          <i class="bi bi-send me-1"></i>Send OTP
        </button>
      </form>

    <?php elseif ($step === 2): ?>
      <p class="small">Code sent to <strong><?= e($dest) ?></strong>. It is valid for
        <?= OTP_TTL_SECONDS / 60 ?> minutes.</p>
      <form method="post" novalidate class="needs-validation">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="verify">
        <div class="mb-3">
          <label class="form-label" for="otp">6 digit code</label>
          <input type="text" class="form-control form-control-lg text-center" id="otp" name="otp"
                 inputmode="numeric" autocomplete="one-time-code" maxlength="6"
                 pattern="[0-9]{6}" required autofocus>
          <div class="invalid-feedback">Enter the 6 digit code.</div>
        </div>
        <button type="submit" class="btn btn-primary w-100 py-2">Verify Code</button>
      </form>
      <div class="d-flex justify-content-between mt-3">
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="resend">
          <button class="btn btn-link btn-sm p-0">Resend code</button></form>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="restart">
          <button class="btn btn-link btn-sm p-0">Use another account or method</button></form>
      </div>

    <?php else: ?>
      <form method="post" novalidate class="needs-validation">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="reset">
        <div class="mb-3">
          <label class="form-label" for="password">New Password</label>
          <div class="input-group">
            <span class="input-group-text bg-white"><i class="bi bi-lock"></i></span>
            <input type="password" class="form-control" id="password" name="password"
                   minlength="<?= MIN_PASSWORD_LENGTH ?>" required autofocus>
            <button class="btn btn-outline-secondary" type="button"
                    data-toggle-password="#password" aria-label="Show password">
              <i class="bi bi-eye"></i>
            </button>
            <div class="invalid-feedback">At least <?= MIN_PASSWORD_LENGTH ?> characters.</div>
          </div>
        </div>
        <div class="mb-4">
          <label class="form-label" for="confirm_password">Confirm New Password</label>
          <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
          <div class="invalid-feedback">The two passwords must match.</div>
        </div>
        <button type="submit" class="btn btn-primary w-100 py-2">Reset Password</button>
      </form>
    <?php endif; ?>

    <p class="text-center small text-muted-2 mt-4 mb-0">
      Remembered it? <a href="<?= url($otpRole['login']) ?>" class="fw-semibold">Back to login</a>
    </p>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= asset_url('assets/js/main.js') ?>"></script>
</body>
</html>
