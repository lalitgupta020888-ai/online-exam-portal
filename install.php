<?php
/**
 * One click installer.
 *
 * Run this once from the browser (http://localhost/online-exam/install.php).
 * It executes database/schema.sql and the migrations. It creates no login
 * accounts - the first administrator registers the college from
 * admin/register.php.
 *
 * Delete this file once the project is installed.
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/sql_util.php';

$run     = ($_SERVER['REQUEST_METHOD'] === 'POST');
$log     = [];
$failed  = false;

if ($run) {
    try {
        // Connect to the server itself - schema.sql creates the database.
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%s;charset=utf8mb4', DB_HOST, DB_PORT),
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $log[] = ['ok', 'Connected to MySQL at ' . DB_HOST . ':' . DB_PORT];

        // Shared hosts forbid CREATE DATABASE and hand out a pre-made
        // database under their own name, so the tables go into DB_NAME.
        try {
            $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '`
                        DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        } catch (PDOException $e) {
            // No permission - the host must have created DB_NAME already.
        }
        $pdo->exec('USE `' . DB_NAME . '`');

        $statements = sql_file_statements(__DIR__ . '/database/schema.sql');
        foreach ($statements as $sql) {
            $pdo->exec($sql);
        }
        $log[] = ['ok', 'Executed ' . count($statements)
                      . ' SQL statements - tables and demo exams created'];

        $pdo->exec('USE `' . DB_NAME . '`');

        // ---------------- Faculty module ----------------
        require_once __DIR__ . '/includes/migrate_faculty.php';
        require_once __DIR__ . '/includes/migrate_college.php';
        $log = array_merge($log, migrate_faculty($pdo, DB_NAME));
        $log = array_merge($log, migrate_college($pdo, DB_NAME));

        // No accounts are created here: the first administrator registers the
        // college from admin/register.php, and faculty / students sign up under it.

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
<title>Install &middot; <?= htmlspecialchars(APP_NAME) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body>
<div class="container py-5" style="max-width:820px">

  <div class="text-center mb-4">
    <div class="feature-icon bg-soft-primary mx-auto"><i class="bi bi-database-gear"></i></div>
    <h2 class="mb-1"><?= htmlspecialchars(APP_NAME) ?> - Installer</h2>
    <p class="text-muted-2">Creates the database, the tables and the demo data.</p>
  </div>

  <?php if (!$run): ?>
    <div class="card border-0">
      <div class="card-body p-4">
        <h5 class="mb-3">Before you continue</h5>
        <ol class="text-muted-2">
          <li>Start <strong>Apache</strong> and <strong>MySQL</strong> from the XAMPP control panel.</li>
          <li>Check the credentials in <code>config/config.php</code>
              (defaults: user <code>root</code>, empty password).</li>
          <li>Running the installer will <strong>drop and recreate</strong> the
              <code><?= htmlspecialchars(DB_NAME) ?></code> tables - any existing data is lost.</li>
        </ol>
        <form method="post">
          <button class="btn btn-primary w-100 py-2"
                  onclick="return confirm('This will reset the database. Continue?')">
            <i class="bi bi-play-circle me-1"></i>Run Installation
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
      <div class="alert alert-danger">
        Installation failed. Fix the error above (usually MySQL is not running or the
        credentials in <code>config/config.php</code> are wrong) and run the installer again.
      </div>
    <?php else: ?>
      <div class="alert alert-success">
        <h5 class="alert-heading"><i class="bi bi-check2-circle me-1"></i>Installation complete</h5>
        <p class="mb-0">No login accounts exist yet. Register your college to create the
          first administrator account - faculty and students then sign up under it.</p>
      </div>
      <div class="alert alert-warning small">
        <i class="bi bi-shield-exclamation me-1"></i>
        Delete <code>install.php</code> now.
      </div>
      <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/admin/register.php" class="btn btn-primary">Register your college</a>
        <a href="<?= BASE_URL ?>/index.php" class="btn btn-outline-primary">Open the website</a>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>
</body>
</html>
