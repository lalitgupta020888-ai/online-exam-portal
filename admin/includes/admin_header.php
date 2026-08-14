<?php
/**
 * Admin layout header. Every admin page (except login) includes this,
 * which also enforces the administrator guard.
 */
require_once __DIR__ . '/../../includes/auth.php';

$admin     = require_admin();
$pageTitle = $pageTitle ?? 'Admin';
$activeNav = $activeNav ?? '';

/**
 * The administrator supervises the portal - they do not author papers.
 * Setting exams and questions belongs to the faculty panel, so this menu
 * carries only oversight, analysis and account administration.
 */
$navItems = [
    'dashboard' => ['index.php',           'speedometer2',    'Dashboard'],
    'faculty'   => ['faculty.php',         'person-video3',   'Faculty Accounts'],
    'analysis'  => ['faculty_analysis.php','graph-up-arrow',  'Faculty Analysis'],
    'papers'    => ['papers.php',          'journal-text',    'Question Papers'],
    'students'  => ['students.php',        'people',          'Students'],
    'results'   => ['results.php',         'bar-chart-line',  'Results'],
    'account'   => ['account.php',         'shield-lock',     'My Account'],
];

$navItems['branches'] = ['branches.php', 'diagram-3', 'Branches'];

// Badge on the Faculty item for registrations waiting to be approved - only
// registrations for this administrator's own college.
$pf = db()->prepare("SELECT COUNT(*) FROM faculty WHERE status = 'pending' AND college_id = ?");
$pf->execute([(int)$admin['college_id']]);
$pendingFaculty = (int)$pf->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> &middot; Admin &middot; <?= e(APP_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= url('assets/css/style.css') ?>" rel="stylesheet">
</head>
<body>

<div class="admin-shell">
  <aside class="admin-sidebar">
    <div class="brand">
      <i class="bi bi-shield-lock-fill"></i> Admin Panel
    </div>
    <?php $adminCollege = get_college((int)$admin['college_id']); ?>
    <?php if ($adminCollege): ?>
      <div class="college-strip">
        <?= college_badge($adminCollege, 38) ?>
        <div class="text-truncate">
          <div class="name text-truncate"><?= e($adminCollege['name']) ?></div>
          <div class="meta"><?= e($adminCollege['code']) ?></div>
        </div>
      </div>
    <?php endif; ?>
    <nav>
      <?php foreach ($navItems as $key => [$file, $icon, $label]): ?>
        <a href="<?= url('admin/' . $file) ?>" class="<?= $activeNav === $key ? 'active' : '' ?>">
          <i class="bi bi-<?= $icon ?>"></i><?= e($label) ?>
          <?php if ($key === 'faculty' && $pendingFaculty > 0): ?>
            <span class="badge bg-danger ms-auto"><?= $pendingFaculty ?></span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
      <a href="<?= url('index.php') ?>" target="_blank" rel="noopener">
        <i class="bi bi-box-arrow-up-right"></i>View Website
      </a>
      <a href="<?= url('admin/logout.php') ?>" class="text-danger">
        <i class="bi bi-box-arrow-right"></i>Logout
      </a>
    </nav>
  </aside>

  <div class="admin-main">
    <div class="admin-topbar">
      <h5 class="mb-0"><?= e($pageTitle) ?></h5>
      <div class="small text-muted-2">
        <i class="bi bi-person-circle me-1"></i>
        Signed in as <strong><?= e($admin['name']) ?></strong>
      </div>
    </div>

    <div class="p-3 p-lg-4">
      <?= render_flash() ?>
