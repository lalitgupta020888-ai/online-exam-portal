<?php
/**
 * Student - E-Content library: study material published to my class.
 */
require_once __DIR__ . '/includes/auth.php';
$student = require_student();

$items    = get_student_elearning('content', $student);
$subjects = array_values(array_unique(array_column($items, 'subject')));
sort($subjects);

$pageTitle = 'E-Content';
$activeNav = 'elearning';
require_once __DIR__ . '/includes/header.php';
?>

<section class="el-page-hero el-hero-content">
  <div class="container">
    <span class="el-hero-eyebrow"><i class="bi bi-journal-richtext me-1"></i>E-Content Library</span>
    <h2 class="mb-2">Read your study material</h2>
    <p class="mb-0">Notes published by your faculty for your class &mdash; <?= count($items) ?> available.</p>
  </div>
</section>

<div class="container py-4 el-page-body">
  <?php if (!$items): ?>
    <div class="card border-0 p-5 text-center">
      <i class="bi bi-journal-richtext display-4 text-muted-2"></i>
      <h5 class="mt-3 mb-1">No E-Content yet</h5>
      <p class="text-muted-2 mb-0">When your faculty publishes study material for your class, it appears here.</p>
    </div>
  <?php else: ?>
    <div class="el-filter-bar">
      <div class="el-search"><i class="bi bi-search"></i>
        <input type="search" id="elSearch" class="form-control" placeholder="Search by title, subject or unit..."></div>
      <div class="el-chips">
        <button type="button" class="active" data-subject="">All</button>
        <?php foreach ($subjects as $s): ?>
          <button type="button" data-subject="<?= e(mb_strtolower($s)) ?>"><?= e($s) ?></button>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="row g-3">
      <?php foreach ($items as $c):
          $isNew = strtotime($c['created_at']) > time() - 7 * 86400;
          $snippet = mb_substr(trim(preg_replace('/[#*_\-•]+|\s+/u', ' ', $c['body'])), 0, 150);
      ?>
        <div class="col-md-6 col-xl-4" data-el-item="<?= e(mb_strtolower($c['title'] . ' ' . $c['subject'] . ' ' . $c['unit'])) ?>"
             data-el-subject="<?= e(mb_strtolower($c['subject'])) ?>">
          <a href="<?= url('econtent_view.php?id=' . (int)$c['id']) ?>" class="card border-0 h-100 el-card el-read-card text-reset text-decoration-none">
            <div class="card-body d-flex flex-column">
              <div class="d-flex justify-content-between align-items-start mb-3">
                <div class="el-doc-icon"><i class="bi bi-journal-text"></i></div>
                <?php if ($isNew): ?><span class="badge rounded-pill el-new">New</span><?php endif; ?>
              </div>
              <span class="eyebrow mb-1"><?= e($c['subject']) ?><?= $c['unit'] ? ' &middot; ' . e($c['unit']) : '' ?></span>
              <h5 class="mb-2"><?= e($c['title']) ?></h5>
              <p class="small text-muted-2 mb-3 el-snippet"><?= e($snippet) ?>&hellip;</p>
              <div class="mt-auto d-flex justify-content-between align-items-center small text-muted-2">
                <span><i class="bi bi-person-video3 me-1"></i><?= e($c['faculty_name']) ?></span>
                <span><i class="bi bi-clock me-1"></i><?= reading_minutes($c['body']) ?> min</span>
              </div>
            </div>
            <div class="el-read-cta">Start reading <i class="bi bi-arrow-right"></i></div>
          </a>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="text-center text-muted-2 py-5 d-none" id="elNone">No material matches your search.</div>
  <?php endif; ?>
</div>

<script src="<?= asset_url('assets/js/elearning.js') ?>"></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
