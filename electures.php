<?php
/**
 * Student - E-Lecture gallery: video lectures published to my class.
 */
require_once __DIR__ . '/includes/auth.php';
$student = require_student();

$items    = get_student_elearning('lecture', $student);
$subjects = array_values(array_unique(array_column($items, 'subject')));
sort($subjects);

$pageTitle = 'E-Lecture';
$activeNav = 'elearning';
require_once __DIR__ . '/includes/header.php';
?>

<section class="el-page-hero el-hero-lecture">
  <div class="container">
    <span class="el-hero-eyebrow"><i class="bi bi-play-btn me-1"></i>E-Lecture</span>
    <h2 class="mb-2">Watch your video lectures</h2>
    <p class="mb-0">Lectures shared by your faculty for your class &mdash; <?= count($items) ?> available.</p>
  </div>
</section>

<div class="container py-4 el-page-body">
  <?php if (!$items): ?>
    <div class="card border-0 p-5 text-center">
      <i class="bi bi-camera-video display-4 text-muted-2"></i>
      <h5 class="mt-3 mb-1">No lectures yet</h5>
      <p class="text-muted-2 mb-0">When your faculty adds a video lecture for your class, it appears here.</p>
    </div>
  <?php else: ?>
    <div class="el-filter-bar">
      <div class="el-search"><i class="bi bi-search"></i>
        <input type="search" id="elSearch" class="form-control" placeholder="Search lectures..."></div>
      <div class="el-chips">
        <button type="button" class="active" data-subject="">All</button>
        <?php foreach ($subjects as $s): ?>
          <button type="button" data-subject="<?= e(mb_strtolower($s)) ?>"><?= e($s) ?></button>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="row g-4">
      <?php foreach ($items as $v): $isNew = strtotime($v['created_at']) > time() - 7 * 86400; ?>
        <div class="col-sm-6 col-lg-4" data-el-item="<?= e(mb_strtolower($v['title'] . ' ' . $v['subject'] . ' ' . $v['unit'])) ?>"
             data-el-subject="<?= e(mb_strtolower($v['subject'])) ?>">
          <a href="<?= url('electure.php?id=' . (int)$v['id']) ?>" class="card border-0 h-100 el-card el-video-card text-reset text-decoration-none">
            <div class="el-thumb">
              <img src="<?= e(youtube_thumb($v['youtube_id'])) ?>" alt="" loading="lazy">
              <span class="el-play"><i class="bi bi-play-fill"></i></span>
              <?php if ($isNew): ?><span class="badge rounded-pill el-new el-thumb-badge">New</span><?php endif; ?>
            </div>
            <div class="card-body">
              <span class="eyebrow mb-1"><?= e($v['subject']) ?><?= $v['unit'] ? ' &middot; ' . e($v['unit']) : '' ?></span>
              <h6 class="mb-2 el-title-2"><?= e($v['title']) ?></h6>
              <div class="small text-muted-2"><i class="bi bi-person-video3 me-1"></i><?= e($v['faculty_name']) ?>
                &middot; <?= e(date('d M Y', strtotime($v['created_at']))) ?></div>
            </div>
          </a>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="text-center text-muted-2 py-5 d-none" id="elNone">No lecture matches your search.</div>
  <?php endif; ?>
</div>

<script src="<?= asset_url('assets/js/elearning.js') ?>"></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
