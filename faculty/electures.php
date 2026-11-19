<?php
/**
 * Faculty - my E-Lectures (YouTube videos): publish, hide or delete.
 */
require_once __DIR__ . '/../includes/auth.php';
$faculty = require_faculty();
ensure_elearning_schema();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $v = get_owned_elearning('lecture', (int)post('item_id'), (int)$faculty['id']);
    if (!$v) {
        set_flash('danger', 'That E-Lecture does not belong to you.');
    } elseif (post('action') === 'toggle') {
        db()->prepare('UPDATE e_lectures SET is_active = 1 - is_active WHERE id = ?')->execute([$v['id']]);
        set_flash('success', 'Visibility updated.');
    } elseif (post('action') === 'delete') {
        db()->prepare('DELETE FROM e_lectures WHERE id = ?')->execute([$v['id']]);
        set_flash('success', 'E-Lecture deleted.');
    }
    redirect('faculty/electures.php');
}

$items = get_faculty_elearning('lecture', (int)$faculty['id']);
$live  = count(array_filter($items, fn($v) => (int)$v['is_active']));
$views = array_sum(array_column($items, 'views'));

$pageTitle = 'E-Lecture';
$activeNav = 'electures';
require_once __DIR__ . '/includes/faculty_header.php';
?>

<div class="el-hero el-hero-lecture mb-4">
  <div>
    <span class="el-hero-eyebrow"><i class="bi bi-play-btn me-1"></i>E-Lecture Studio</span>
    <h3 class="mb-1">Video lectures for your classes</h3>
    <p class="mb-0">Add lectures with a YouTube link. Students watch them inside the portal.</p>
    <div class="el-hero-stats">
      <div><strong><?= count($items) ?></strong><span>Lectures</span></div>
      <div><strong><?= $live ?></strong><span>Published</span></div>
      <div><strong><?= number_format($views) ?></strong><span>Student views</span></div>
    </div>
  </div>
  <a href="<?= url('faculty/electure_form.php') ?>" class="btn btn-light btn-lg el-hero-cta">
    <i class="bi bi-plus-lg me-1"></i>New E-Lecture</a>
</div>

<?php if (!$items): ?>
  <div class="card border-0 p-5 text-center">
    <i class="bi bi-camera-video display-4 text-muted-2"></i>
    <h5 class="mt-3 mb-1">No E-Lectures yet</h5>
    <p class="text-muted-2 mb-4">Upload your video to YouTube, paste the link here and pick the class.</p>
    <div><a href="<?= url('faculty/electure_form.php') ?>" class="btn btn-primary">Add the first lecture</a></div>
  </div>
<?php else: ?>
  <input type="search" class="form-control mb-3" id="elSearch" style="max-width:320px" placeholder="Search lectures...">
  <div class="row g-3">
    <?php foreach ($items as $v): $id = (int)$v['id']; ?>
      <div class="col-sm-6 col-xl-4" data-el-item="<?= e(mb_strtolower($v['title'] . ' ' . $v['subject'] . ' ' . $v['unit'])) ?>">
        <div class="card border-0 h-100 el-card el-video-card">
          <button type="button" class="el-thumb" data-bs-toggle="modal" data-bs-target="#vp<?= $id ?>">
            <img src="<?= e(youtube_thumb($v['youtube_id'])) ?>" alt="" loading="lazy">
            <span class="el-play"><i class="bi bi-play-fill"></i></span>
            <span class="badge rounded-pill el-thumb-badge <?= (int)$v['is_active'] ? 'bg-success' : 'bg-secondary' ?>">
              <?= (int)$v['is_active'] ? 'Published' : 'Hidden' ?></span>
          </button>
          <div class="card-body">
            <span class="eyebrow mb-1"><?= e($v['subject']) ?><?= $v['unit'] ? ' &middot; ' . e($v['unit']) : '' ?></span>
            <h6 class="mb-2 el-title-2"><?= e($v['title']) ?></h6>
            <div class="el-meta">
              <span><i class="bi bi-people"></i><?= e(assignment_audience_label($v)) ?> (<?= (int)$v['student_count'] ?>)</span>
              <span><i class="bi bi-eye"></i><?= (int)$v['views'] ?> views</span>
            </div>
            <div class="d-flex flex-wrap gap-1">
              <a class="btn btn-sm btn-outline-secondary" href="<?= url('faculty/electure_form.php?id=' . $id) ?>">
                <i class="bi bi-pencil me-1"></i>Edit</a>
              <form method="post" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="item_id" value="<?= $id ?>">
                <input type="hidden" name="action" value="toggle">
                <button class="btn btn-sm btn-outline-warning">
                  <i class="bi bi-eye<?= (int)$v['is_active'] ? '-slash' : '' ?> me-1"></i><?= (int)$v['is_active'] ? 'Hide' : 'Show' ?></button>
              </form>
              <form method="post" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="item_id" value="<?= $id ?>">
                <input type="hidden" name="action" value="delete">
                <button class="btn btn-sm btn-outline-danger" data-confirm="Delete this E-Lecture?">
                  <i class="bi bi-trash"></i></button>
              </form>
            </div>
          </div>
        </div>
      </div>

      <div class="modal fade el-video-modal" id="vp<?= $id ?>" tabindex="-1" aria-hidden="true"
           data-src="<?= e(youtube_embed_url($v['youtube_id'])) ?>">
        <div class="modal-dialog modal-xl modal-dialog-centered">
          <div class="modal-content border-0 bg-black">
            <div class="el-video-frame rounded-0"></div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<script>
(function () {
  // Load the player only while the modal is open, so closing it stops the video.
  document.querySelectorAll('.el-video-modal').forEach(function (m) {
    var frame = m.querySelector('.el-video-frame');
    m.addEventListener('shown.bs.modal', function () {
      frame.innerHTML = '<iframe src="' + m.dataset.src + '&autoplay=1" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen></iframe>';
    });
    m.addEventListener('hidden.bs.modal', function () { frame.innerHTML = ''; });
  });
  var box = document.getElementById('elSearch');
  if (!box) return;
  box.addEventListener('input', function () {
    var q = box.value.trim().toLowerCase();
    document.querySelectorAll('[data-el-item]').forEach(function (el) {
      el.classList.toggle('d-none', q !== '' && el.dataset.elItem.indexOf(q) === -1);
    });
  });
})();
</script>

<?php require_once __DIR__ . '/includes/faculty_footer.php'; ?>
