<?php
/**
 * Student - watch one E-Lecture, with the rest of the class's lectures
 * listed alongside as "Up next".
 */
require_once __DIR__ . '/includes/auth.php';
$student = require_student();

$id = get_int('id');
$v  = get_student_elearning_item('lecture', $id, $student);
if (!$v) {
    set_flash('danger', 'That lecture is not available to you.');
    redirect('electures.php');
}
elearning_count_view('lecture', $id);

$others = array_values(array_filter(get_student_elearning('lecture', $student), fn($o) => (int)$o['id'] !== $id));
// Same subject first.
usort($others, fn($x, $y) => ($y['subject'] === $v['subject']) <=> ($x['subject'] === $v['subject']) ?: $y['id'] <=> $x['id']);
$others = array_slice($others, 0, 12);

$pageTitle = $v['title'];
$activeNav = 'elearning';
require_once __DIR__ . '/includes/header.php';
?>

<div class="el-watch">
  <div class="container py-4">
    <a href="<?= url('electures.php') ?>" class="btn btn-sm btn-light mb-3"><i class="bi bi-arrow-left me-1"></i>All lectures</a>
    <div class="row g-4">
      <div class="col-lg-8">
        <div class="el-video-frame el-player shadow-lg">
          <iframe src="<?= e(youtube_embed_url($v['youtube_id'])) ?>" title="<?= e($v['title']) ?>"
                  allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
        </div>
        <div class="card border-0 mt-3">
          <div class="card-body p-4">
            <span class="eyebrow"><?= e($v['subject']) ?><?= $v['unit'] ? ' &middot; ' . e($v['unit']) : '' ?></span>
            <h3 class="mb-2"><?= e($v['title']) ?></h3>
            <div class="el-reader-by mb-0">
              <span><i class="bi bi-person-video3"></i><?= e($v['faculty_name']) ?><?= $v['faculty_designation'] ? ', ' . e($v['faculty_designation']) : '' ?></span>
              <span><i class="bi bi-calendar3"></i><?= e(date('d M Y', strtotime($v['created_at']))) ?></span>
            </div>
            <?php if ($v['description']): ?>
              <hr>
              <div class="el-desc"><?= nl2br(e($v['description'])) ?></div>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div class="col-lg-4">
        <div class="el-upnext">
          <h6 class="mb-3"><i class="bi bi-collection-play me-1"></i>Up next</h6>
          <?php if (!$others): ?>
            <div class="small text-muted-2">No other lectures yet.</div>
          <?php endif; ?>
          <?php foreach ($others as $o): ?>
            <a href="<?= url('electure.php?id=' . (int)$o['id']) ?>" class="el-upnext-item">
              <span class="el-thumb el-thumb-sm"><img src="<?= e(youtube_thumb($o['youtube_id'], 'mqdefault')) ?>" alt="" loading="lazy">
                <span class="el-play"><i class="bi bi-play-fill"></i></span></span>
              <span class="min-w-0">
                <strong class="el-title-2"><?= e($o['title']) ?></strong>
                <small><?= e($o['subject']) ?> &middot; <?= e($o['faculty_name']) ?></small>
              </span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
