<?php
/**
 * Public / student layout header.
 * Set $pageTitle and $activeNav before including this file.
 */
require_once __DIR__ . '/auth.php';

$pageTitle = $pageTitle ?? APP_NAME;
$activeNav = $activeNav ?? '';
$student   = current_student();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> &middot; <?= e(APP_NAME) ?></title>
<meta name="description" content="<?= e(APP_NAME) ?> - <?= e(APP_TAGLINE) ?>. Timed online examinations with instant results, e-content to read and e-lectures to watch.">
<meta name="theme-color" content="#0f766e">
<link rel="icon" type="image/svg+xml" href="<?= asset_url('assets/images/favicon.svg') ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= asset_url('assets/css/style.css') ?>" rel="stylesheet">
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-oep sticky-top">
  <div class="container">
    <a class="navbar-brand" href="<?= url('index.php') ?>">
      <span class="brand-badge"><i class="bi bi-mortarboard-fill"></i></span>
      <span>
        <?= e(APP_NAME) ?>
        <span class="brand-sub"><?= e(APP_TAGLINE) ?></span>
      </span>
    </a>
    <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse"
            data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false"
            aria-label="Toggle navigation">
      <i class="bi bi-list fs-3"></i>
    </button>

    <div class="collapse navbar-collapse" id="mainNav">
      <ul class="navbar-nav ms-auto align-items-lg-center gap-1">
        <li class="nav-item">
          <a class="nav-link <?= $activeNav === 'home' ? 'active' : '' ?>"
             href="<?= url('index.php') ?>"><i class="bi bi-house-door me-1"></i>Home</a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= $activeNav === 'exams' ? 'active' : '' ?>"
             href="<?= url('dashboard.php') ?>"><i class="bi bi-journal-text me-1"></i>Exams</a>
        </li>
        <?php if ($student): ?>
        <li class="nav-item">
          <a class="nav-link <?= $activeNav === 'assignments' ? 'active' : '' ?>"
             href="<?= url('assignments.php') ?>"><i class="bi bi-file-earmark-text me-1"></i>Assignments</a>
        </li>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle <?= $activeNav === 'elearning' ? 'active' : '' ?>"
             href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-mortarboard me-1"></i>E-Learning</a>
          <ul class="dropdown-menu shadow border-0">
            <li><a class="dropdown-item" href="<?= url('econtent.php') ?>">
              <i class="bi bi-journal-richtext me-2"></i>E-Content</a></li>
            <li><a class="dropdown-item" href="<?= url('electures.php') ?>">
              <i class="bi bi-play-btn me-2"></i>E-Lecture</a></li>
          </ul>
        </li>
        <?php endif; ?>
        <li class="nav-item">
          <a class="nav-link <?= $activeNav === 'instructions' ? 'active' : '' ?>"
             href="<?= url('instructions.php') ?>"><i class="bi bi-info-circle me-1"></i>Instructions</a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= $activeNav === 'results' ? 'active' : '' ?>"
             href="<?= url('results.php') ?>"><i class="bi bi-bar-chart me-1"></i>Results</a>
        </li>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle <?= $activeNav === 'department' ? 'active' : '' ?>"
             href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-building me-1"></i>Department</a>
          <ul class="dropdown-menu shadow border-0">
            <li><a class="dropdown-item" href="<?= url('department_electronics.php') ?>">
              <i class="bi bi-cpu me-2"></i>Electronics</a></li>
          </ul>
        </li>

        <?php if ($student): ?>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" role="button"
               data-bs-toggle="dropdown" aria-expanded="false">
              <?= avatar_html($student, 'avatar-sm me-2') ?><?= e($student['name']) ?>
            </a>
            <ul class="dropdown-menu dropdown-menu-end shadow border-0">
              <li><a class="dropdown-item" href="<?= url('dashboard.php') ?>">
                <i class="bi bi-grid me-2"></i>My Dashboard</a></li>
              <li><a class="dropdown-item" href="<?= url('results.php') ?>">
                <i class="bi bi-clipboard-data me-2"></i>My Results</a></li>
              <li><a class="dropdown-item" href="<?= url('profile.php') ?>">
                <i class="bi bi-person-gear me-2"></i>My Account</a></li>
              <li><a class="dropdown-item" href="<?= url('profile.php?tab=password') ?>">
                <i class="bi bi-shield-lock me-2"></i>Change Password</a></li>
              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item text-danger" href="<?= url('logout.php') ?>">
                <i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
            </ul>
          </li>
        <?php else: ?>
          <li class="nav-item">
            <a class="nav-link <?= $activeNav === 'login' ? 'active' : '' ?>"
               href="<?= url('login.php') ?>"><i class="bi bi-box-arrow-in-right me-1"></i>Login</a>
          </li>
          <li class="nav-item ms-lg-2">
            <a class="btn btn-primary px-3" href="<?= url('register.php') ?>">Register</a>
          </li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</nav>

<main>
<?php if ($flash = render_flash()): ?>
  <div class="container mt-3"><?= $flash ?></div>
<?php endif; ?>
