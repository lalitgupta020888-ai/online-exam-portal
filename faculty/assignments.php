<?php
/**
 * Faculty - my assignments: how many students have submitted and been graded.
 */
require_once __DIR__ . '/../includes/auth.php';
$faculty = require_faculty();
ensure_assignment_schema();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $a = get_owned_assignment((int)post('assignment_id'), (int)$faculty['id']);
    if (!$a) {
        set_flash('danger', 'That assignment does not belong to you.');
    } elseif (post('action') === 'toggle') {
        db()->prepare('UPDATE assignments SET is_active = 1 - is_active WHERE id = ?')->execute([$a['id']]);
        set_flash('success', 'Assignment visibility updated.');
    } elseif (post('action') === 'delete') {
        $st = db()->prepare('SELECT pdf_file FROM assignment_submissions WHERE assignment_id = ?');
        $st->execute([$a['id']]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $file) {
            delete_assignment_pdf($file);
        }
        db()->prepare('DELETE FROM assignments WHERE id = ?')->execute([$a['id']]);
        set_flash('success', 'Assignment deleted along with its submissions.');
    }
    redirect('faculty/assignments.php');
}

$assignments = get_faculty_assignments((int)$faculty['id']);

$pageTitle = 'Assignments';
$activeNav = 'assignments';
require_once __DIR__ . '/includes/faculty_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
  <input type="search" class="form-control" id="tableSearch" style="max-width:320px"
         placeholder="Search my assignments...">
  <a href="<?= url('faculty/assignment_form.php') ?>" class="btn btn-primary">
    <i class="bi bi-plus-lg me-1"></i>New Assignment
  </a>
</div>

<?php if (!$assignments): ?>
  <div class="card border-0 p-5 text-center">
    <i class="bi bi-file-earmark-text display-4 text-muted-2"></i>
    <h5 class="mt-3 mb-1">No assignments yet</h5>
    <p class="text-muted-2 mb-4">
      Write the questions, pick the class, and every student in it can submit a typed
      answer or a PDF of their handwritten work.
    </p>
    <div><a href="<?= url('faculty/assignment_form.php') ?>" class="btn btn-primary">Create the first assignment</a></div>
  </div>
<?php else: ?>
  <div class="row g-3" data-searchable>
    <?php foreach ($assignments as $a):
        $id = (int)$a['id'];
        $total = (int)$a['student_count'];
        $pct = $total ? min(100, round($a['submitted'] / $total * 100)) : 0;
        $overdue = strtotime($a['due_at']) < time();
    ?>
      <div class="col-xl-6">
        <div class="card border-0 h-100">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
              <div>
                <h5 class="mb-1"><?= e($a['title']) ?></h5>
                <div class="small text-muted-2">
                  <i class="bi bi-bookmark me-1"></i><?= e($a['subject']) ?>
                  &middot; <?= e(assignment_audience_label($a)) ?>
                  &middot; <?= num((float)$a['max_marks']) ?> marks
                </div>
              </div>
              <span class="badge <?= (int)$a['is_active'] ? 'bg-success' : 'bg-secondary' ?>">
                <?= (int)$a['is_active'] ? 'Active' : 'Hidden' ?></span>
            </div>
            <div class="small mb-3 <?= $overdue ? 'text-danger' : 'text-muted-2' ?>">
              <i class="bi bi-calendar-event me-1"></i><?= e(format_datetime($a['due_at'])) ?>
              &middot; <?= e(due_label($a['due_at'])) ?>
            </div>

            <div class="row g-2 mb-3">
              <div class="col-4"><div class="stat-card p-2">
                <div class="value fs-5"><?= $total ?></div><div class="label">Students</div></div></div>
              <div class="col-4"><div class="stat-card p-2">
                <div class="value fs-5"><?= (int)$a['submitted'] ?></div><div class="label">Submitted</div></div></div>
              <div class="col-4"><div class="stat-card p-2">
                <div class="value fs-5"><?= (int)$a['graded'] ?></div><div class="label">Graded</div></div></div>
            </div>
            <div class="progress mb-3"><div class="progress-bar" style="width:<?= $pct ?>%"></div></div>

            <div class="d-flex flex-wrap gap-1">
              <a class="btn btn-sm btn-primary" href="<?= url('faculty/assignment_view.php?id=' . $id) ?>">
                <i class="bi bi-inbox me-1"></i>Submissions</a>
              <a class="btn btn-sm btn-outline-secondary" href="<?= url('faculty/assignment_form.php?id=' . $id) ?>">
                <i class="bi bi-pencil me-1"></i>Edit</a>
              <form method="post" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="assignment_id" value="<?= $id ?>">
                <input type="hidden" name="action" value="toggle">
                <button class="btn btn-sm btn-outline-warning">
                  <i class="bi bi-eye<?= (int)$a['is_active'] ? '-slash' : '' ?> me-1"></i><?= (int)$a['is_active'] ? 'Hide' : 'Show' ?></button>
              </form>
              <form method="post" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="assignment_id" value="<?= $id ?>">
                <input type="hidden" name="action" value="delete">
                <button class="btn btn-sm btn-outline-danger"
                        data-confirm="Delete this assignment? All student submissions and PDFs will be removed.">
                  <i class="bi bi-trash"></i></button>
              </form>
            </div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/faculty_footer.php'; ?>
