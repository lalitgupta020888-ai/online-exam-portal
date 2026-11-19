<?php
/**
 * Faculty - my E-Content: publish, preview, hide or delete study material.
 */
require_once __DIR__ . '/../includes/auth.php';
$faculty = require_faculty();
ensure_elearning_schema();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $c = get_owned_elearning('content', (int)post('item_id'), (int)$faculty['id']);
    if (!$c) {
        set_flash('danger', 'That E-Content does not belong to you.');
    } elseif (post('action') === 'toggle') {
        db()->prepare('UPDATE e_contents SET is_active = 1 - is_active WHERE id = ?')->execute([$c['id']]);
        set_flash('success', 'Visibility updated.');
    } elseif (post('action') === 'delete') {
        db()->prepare('DELETE FROM e_contents WHERE id = ?')->execute([$c['id']]);
        set_flash('success', 'E-Content deleted.');
    }
    redirect('faculty/econtent.php');
}

$items = get_faculty_elearning('content', (int)$faculty['id']);
$live  = count(array_filter($items, fn($c) => (int)$c['is_active']));
$views = array_sum(array_column($items, 'views'));

$pageTitle = 'E-Content';
$activeNav = 'econtent';
require_once __DIR__ . '/includes/faculty_header.php';
?>

<div class="el-hero el-hero-content mb-4">
  <div>
    <span class="el-hero-eyebrow"><i class="bi bi-journal-richtext me-1"></i>E-Content Library</span>
    <h3 class="mb-1">Study material for your classes</h3>
    <p class="mb-0">Notes you type or PDFs you upload appear to students as protected on-screen text &mdash;
      no download, no print, no copy.</p>
    <div class="el-hero-stats">
      <div><strong><?= count($items) ?></strong><span>Total</span></div>
      <div><strong><?= $live ?></strong><span>Published</span></div>
      <div><strong><?= number_format($views) ?></strong><span>Student reads</span></div>
    </div>
  </div>
  <a href="<?= url('faculty/econtent_form.php') ?>" class="btn btn-light btn-lg el-hero-cta">
    <i class="bi bi-plus-lg me-1"></i>New E-Content</a>
</div>

<?php if (!$items): ?>
  <div class="card border-0 p-5 text-center">
    <i class="bi bi-journal-richtext display-4 text-muted-2"></i>
    <h5 class="mt-3 mb-1">No E-Content yet</h5>
    <p class="text-muted-2 mb-4">Type your notes or upload a PDF, choose the class, and students can start reading.</p>
    <div><a href="<?= url('faculty/econtent_form.php') ?>" class="btn btn-primary">Publish the first one</a></div>
  </div>
<?php else: ?>
  <input type="search" class="form-control mb-3" id="elSearch" style="max-width:320px" placeholder="Search E-Content...">
  <div class="row g-3">
    <?php foreach ($items as $c):
        $id = (int)$c['id'];
        $words = count(preg_split('/\s+/u', trim($c['body']), -1, PREG_SPLIT_NO_EMPTY));
    ?>
      <div class="col-xl-6" data-el-item="<?= e(mb_strtolower($c['title'] . ' ' . $c['subject'] . ' ' . $c['unit'])) ?>">
        <div class="card border-0 h-100 el-card">
          <div class="card-body d-flex gap-3">
            <div class="el-doc-icon <?= $c['source'] === 'pdf' ? 'is-pdf' : '' ?>">
              <i class="bi bi-<?= $c['source'] === 'pdf' ? 'file-earmark-pdf' : 'file-earmark-text' ?>"></i>
            </div>
            <div class="flex-grow-1 min-w-0">
              <div class="d-flex justify-content-between align-items-start gap-2">
                <div class="min-w-0">
                  <span class="eyebrow mb-1"><?= e($c['subject']) ?><?= $c['unit'] ? ' &middot; ' . e($c['unit']) : '' ?></span>
                  <h5 class="mb-1 text-truncate"><?= e($c['title']) ?></h5>
                </div>
                <span class="badge rounded-pill <?= (int)$c['is_active'] ? 'bg-success' : 'bg-secondary' ?>">
                  <?= (int)$c['is_active'] ? 'Published' : 'Hidden' ?></span>
              </div>
              <div class="el-meta">
                <span><i class="bi bi-people"></i><?= e(assignment_audience_label($c)) ?> (<?= (int)$c['student_count'] ?>)</span>
                <span><i class="bi bi-text-paragraph"></i><?= number_format($words) ?> words</span>
                <span><i class="bi bi-clock"></i><?= reading_minutes($c['body']) ?> min read</span>
                <span><i class="bi bi-eye"></i><?= (int)$c['views'] ?> reads</span>
              </div>
              <div class="small text-muted-2 mb-3"><i class="bi bi-calendar3 me-1"></i><?= e(format_datetime($c['created_at'])) ?>
                <?php if ($c['source'] === 'pdf'): ?>&middot; from PDF<?php endif; ?></div>
              <div class="d-flex flex-wrap gap-1">
                <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#pv<?= $id ?>">
                  <i class="bi bi-eye me-1"></i>Preview</button>
                <a class="btn btn-sm btn-outline-secondary" href="<?= url('faculty/econtent_form.php?id=' . $id) ?>">
                  <i class="bi bi-pencil me-1"></i>Edit</a>
                <form method="post" class="d-inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="item_id" value="<?= $id ?>">
                  <input type="hidden" name="action" value="toggle">
                  <button class="btn btn-sm btn-outline-warning">
                    <i class="bi bi-eye<?= (int)$c['is_active'] ? '-slash' : '' ?> me-1"></i><?= (int)$c['is_active'] ? 'Hide' : 'Show' ?></button>
                </form>
                <form method="post" class="d-inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="item_id" value="<?= $id ?>">
                  <input type="hidden" name="action" value="delete">
                  <button class="btn btn-sm btn-outline-danger" data-confirm="Delete this E-Content permanently?">
                    <i class="bi bi-trash"></i></button>
                </form>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="modal fade" id="pv<?= $id ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
          <div class="modal-content border-0">
            <div class="modal-header">
              <h5 class="modal-title"><?= e($c['title']) ?></h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body"><article class="el-reader-body"><?= econtent_html($c['body']) ?></article></div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<script>
(function () {
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
