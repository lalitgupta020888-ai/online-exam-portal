<?php
/**
 * Faculty module upgrade for an existing installation.
 *
 * Run once from the browser (http://localhost/online-exam/upgrade.php).
 * Unlike install.php this NEVER drops anything - it only adds the tables and
 * columns the faculty features need, and it is safe to run again.
 *
 * Delete this file once the upgrade is done.
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/migrate_faculty.php';
require_once __DIR__ . '/includes/migrate_college.php';

$run    = ($_SERVER['REQUEST_METHOD'] === 'POST');
$log    = [];
$failed = false;

if ($run) {
    try {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME),
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $log[] = ['ok', 'Connected to database ' . DB_NAME];
        $log = array_merge($log, migrate_faculty($pdo, DB_NAME));
        $log = array_merge($log, migrate_college($pdo, DB_NAME));
    } catch (Throwable $ex) {
        $failed = true;
        $log[] = ['fail', $ex->getMessage()];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Upgrade &middot; <?= htmlspecialchars(APP_NAME) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body>
<div class="container py-5" style="max-width:820px">

  <div class="text-center mb-4">
    <div class="feature-icon bg-soft-purple mx-auto"><i class="bi bi-person-video3"></i></div>
    <h2 class="mb-1">Faculty Module Upgrade</h2>
    <p class="text-muted-2">Adds faculty logins, exam assignment, subjective questions and colleges.</p>
  </div>

  <?php if (!$run): ?>
    <div class="card border-0">
      <div class="card-body p-4">
        <h5 class="mb-3">What this does</h5>
        <ul class="text-muted-2">
          <li>Creates the <code>faculty</code>, <code>exam_assignments</code> and
              <code>question_imports</code> tables.</li>
          <li>Adds the columns needed for subjective questions and manual evaluation.</li>
          <li>Creates a demo faculty account if none exists.</li>
          <li><strong>No data is deleted</strong> - existing exams keep working exactly
              as they do now.</li>
        </ul>
        <form method="post">
          <button class="btn btn-primary w-100 py-2">
            <i class="bi bi-arrow-up-circle me-1"></i>Run Upgrade
          </button>
        </form>
      </div>
    </div>
  <?php else: ?>
    <div class="card border-0 mb-3">
      <div class="card-body p-4">
        <?php foreach ($log as [$state, $message]): ?>
          <div class="d-flex align-items-start gap-2 mb-2">
            <i class="bi bi-<?= $state === 'ok' ? 'check-circle-fill text-success'
                                                : 'x-circle-fill text-danger' ?>"></i>
            <div class="small"><?= htmlspecialchars($message) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <?php if ($failed): ?>
      <div class="alert alert-danger">Upgrade failed - fix the error above and run it again.</div>
    <?php else: ?>
      <div class="alert alert-success">
        <h5 class="alert-heading"><i class="bi bi-check2-circle me-1"></i>Upgrade complete</h5>
        <p class="mb-2">Faculty sign in:</p>
        <table class="table table-sm mb-0 bg-white">
          <thead><tr><th>Username</th><th>Password</th><th>Login page</th></tr></thead>
          <tbody>
            <tr><td><code>faculty</code></td><td><code>faculty123</code></td>
                <td><a href="<?= BASE_URL ?>/faculty/login.php">Faculty login</a></td></tr>
          </tbody>
        </table>
      </div>
      <div class="alert alert-warning small">
        <i class="bi bi-shield-exclamation me-1"></i>Delete <code>upgrade.php</code> now.
      </div>
      <a href="<?= BASE_URL ?>/faculty/login.php" class="btn btn-primary">Open the faculty panel</a>
    <?php endif; ?>
  <?php endif; ?>
</div>
</body>
</html>
