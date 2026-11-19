<?php
/**
 * Student - protected reader for one E-Content.
 *
 * The text is shown on screen only: there is no file to download, and the
 * page blocks copy, select, right-click, save, print and the usual screenshot
 * keys, blurs itself when the window loses focus (snipping tools), and is
 * watermarked with the student's name so any photo of the screen is traceable.
 */
require_once __DIR__ . '/includes/auth.php';
$student = require_student();

$id = get_int('id');
$c  = get_student_elearning_item('content', $id, $student);
if (!$c) {
    set_flash('danger', 'That E-Content is not available to you.');
    redirect('econtent.php');
}
elearning_count_view('content', $id);

// Delivered encoded and drawn by script, so "view source" / "save page" has no readable text.
$payload = base64_encode(econtent_html($c['body']));
$mark    = $student['name'] . ' · ' . ($student['enrollment_no'] ?: 'ID ' . $student['id']) . ' · ' . date('d M Y');

header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');

$pageTitle = $c['title'];
$activeNav = 'elearning';
require_once __DIR__ . '/includes/header.php';
?>

<div class="el-progress"><span id="readProgress"></span></div>

<div class="el-reader-wrap" id="reader" data-theme="light">
  <div class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 el-noprint">
      <a href="<?= url('econtent.php') ?>" class="btn btn-sm btn-light"><i class="bi bi-arrow-left me-1"></i>Library</a>
      <div class="el-reader-tools">
        <button type="button" data-font="-1" title="Smaller text">A<sup>-</sup></button>
        <button type="button" data-font="1" title="Larger text">A<sup>+</sup></button>
        <span class="sep"></span>
        <button type="button" data-theme-set="light" title="Light"><i class="bi bi-sun"></i></button>
        <button type="button" data-theme-set="sepia" title="Sepia"><i class="bi bi-book"></i></button>
        <button type="button" data-theme-set="dark" title="Dark"><i class="bi bi-moon-stars"></i></button>
      </div>
    </div>

    <div class="row g-4">
      <div class="col-lg-3 d-none d-lg-block">
        <nav class="el-toc" id="toc"><div class="el-toc-title">On this page</div></nav>
      </div>
      <div class="col-lg-9">
        <article class="el-reader card border-0">
          <header class="el-reader-head">
            <span class="eyebrow"><?= e($c['subject']) ?><?= $c['unit'] ? ' &middot; ' . e($c['unit']) : '' ?></span>
            <h1><?= e($c['title']) ?></h1>
            <div class="el-reader-by">
              <span><i class="bi bi-person-video3"></i><?= e($c['faculty_name']) ?><?= $c['faculty_designation'] ? ', ' . e($c['faculty_designation']) : '' ?></span>
              <span><i class="bi bi-calendar3"></i><?= e(date('d M Y', strtotime($c['updated_at'] ?: $c['created_at']))) ?></span>
              <span><i class="bi bi-clock"></i><?= reading_minutes($c['body']) ?> min read</span>
              <span class="el-lock"><i class="bi bi-shield-lock-fill"></i>Protected &middot; read only</span>
            </div>
          </header>
          <div class="el-reader-stage">
            <div class="el-reader-body" id="readerBody">
              <noscript><p>Please enable JavaScript to read this content.</p></noscript>
            </div>
            <div class="el-watermark" id="watermark" aria-hidden="true"></div>
          </div>
        </article>
      </div>
    </div>
  </div>

  <div class="el-shield" id="shield">
    <i class="bi bi-shield-lock"></i>
    <strong>Content protected</strong>
    <span>Click here to continue reading. Screenshots, copying and printing are not allowed.</span>
  </div>
</div>

<div class="el-print-block">This content is protected and cannot be printed.</div>

<script>
window.EL_READER = { payload: <?= json_encode($payload) ?>, mark: <?= json_encode($mark) ?> };
</script>
<script src="<?= asset_url('assets/js/elearning.js') ?>"></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
