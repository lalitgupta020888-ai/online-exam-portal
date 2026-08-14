<?php
/**
 * Faculty layout header. Every faculty page (except login) includes this,
 * which also enforces the faculty guard.
 */
require_once __DIR__ . '/../../includes/auth.php';

$faculty   = require_faculty();
$pageTitle = $pageTitle ?? 'Faculty';
$activeNav = $activeNav ?? '';

$navItems = [
    'dashboard' => ['index.php',   'speedometer2',    'Dashboard'],
    'exams'     => ['exams.php',   'journal-text',    'My Exams'],
    'evaluate'  => ['evaluate.php','pencil-square',   'Evaluate Answers'],
    'results'   => ['results.php', 'bar-chart-line',  'Results'],
    'students'  => ['students.php','people',          'Student Analysis'],
    'profile'   => ['profile.php', 'person-gear',     'My Profile'],
];

// Badge with the number of papers waiting for manual evaluation.
$pendingCount = 0;
$st = db()->prepare(
    'SELECT COUNT(*) FROM results r JOIN exams e ON e.id = r.exam_id
      WHERE e.faculty_id = ? AND r.pending_evaluation = 1'
);
$st->execute([$faculty['id']]);
$pendingCount = (int)$st->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> &middot; Faculty &middot; <?= e(APP_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= url('assets/css/style.css') ?>" rel="stylesheet">
</head>
<body>

<div class="admin-shell">
  <aside class="admin-sidebar faculty-sidebar">
    <div class="brand">
      <i class="bi bi-person-video3"></i> Faculty Panel
    </div>
    <?php $facultyCollege = get_college((int)$faculty['college_id']); ?>
    <?php if ($facultyCollege): ?>
      <div class="college-strip">
        <?= college_badge($facultyCollege, 38) ?>
        <div class="text-truncate">
          <div class="name text-truncate"><?= e($facultyCollege['name']) ?></div>
          <div class="meta"><?= e($facultyCollege['code']) ?></div>
        </div>
      </div>
    <?php endif; ?>
    <nav>
      <?php foreach ($navItems as $key => [$file, $icon, $label]): ?>
        <a href="<?= url('faculty/' . $file) ?>" class="<?= $activeNav === $key ? 'active' : '' ?>">
          <i class="bi bi-<?= $icon ?>"></i><?= e($label) ?>
          <?php if ($key === 'evaluate' && $pendingCount > 0): ?>
            <span class="badge bg-danger ms-auto"><?= $pendingCount ?></span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
      <a href="<?= url('index.php') ?>" target="_blank" rel="noopener">
        <i class="bi bi-box-arrow-up-right"></i>View Website
      </a>
      <a href="<?= url('faculty/logout.php') ?>" class="text-danger">
        <i class="bi bi-box-arrow-right"></i>Logout
      </a>
    </nav>
  </aside>

  <div class="admin-main">
    <div class="admin-topbar">
      <h5 class="mb-0"><?= e($pageTitle) ?></h5>
      <a href="<?= url('faculty/profile.php') ?>"
         class="d-flex align-items-center gap-2 text-decoration-none topbar-user">
        <span class="avatar-sm"><?= e(initials($faculty['name'])) ?></span>
        <span class="small lh-sm">
          <strong class="d-block text-dark"><?= e($faculty['name']) ?></strong>
          <span class="text-muted-2">
            <?= e($faculty['designation'] ?: 'Faculty') ?>
            <?php if ($faculty['department']): ?>
              &middot; <?= e($faculty['department']) ?>
            <?php endif; ?>
          </span>
        </span>
      </a>
    </div>

    <div class="p-3 p-lg-4">
      <?= render_flash() ?>
