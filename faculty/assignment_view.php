<?php
/**
 * Faculty - read the submissions of one assignment and award marks.
 */
require_once __DIR__ . '/../includes/auth.php';
$faculty = require_faculty();
ensure_assignment_schema();

$id = get_int('id') ?: (int)post('id');
$assignment = get_owned_assignment($id, (int)$faculty['id']);
if (!$assignment) {
    set_flash('danger', 'That assignment does not belong to you.');
    redirect('faculty/assignments.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $marks = post('marks');
    $max   = (float)$assignment['max_marks'];
    if ($marks === '' || !is_numeric($marks) || (float)$marks < 0 || (float)$marks > $max) {
        set_flash('danger', 'Marks must be between 0 and ' . num($max) . '.');
    } else {
        $st = db()->prepare('UPDATE assignment_submissions SET marks = ?, feedback = ?, graded_at = NOW()
                              WHERE id = ? AND assignment_id = ?');
        $st->execute([(float)$marks, post('feedback') ?: null, (int)post('submission_id'), $id]);
        set_flash($st->rowCount() ? 'success' : 'danger',
                  $st->rowCount() ? 'Marks saved.' : 'Submission not found.');
    }
    redirect('faculty/assignment_view.php?id=' . $id . '#s' . (int)post('submission_id'));
}

$roster    = assignment_roster($assignment);
$submitted = count(array_filter($roster, fn($r) => $r['submission_id']));

$pageTitle = 'Assignment Submissions';
$activeNav = 'assignments';
require_once __DIR__ . '/includes/faculty_header.php';
?>

<div class="card border-0 mb-3">
  <div class="card-body">
    <div class="d-flex flex-wrap justify-content-between gap-2">
      <div>
        <span class="eyebrow"><?= e($assignment['subject']) ?></span>
        <h5 class="mb-1"><?= e($assignment['title']) ?></h5>
        <div class="small text-muted-2">
          <?= e(assignment_audience_label($assignment)) ?> &middot;
          Due <?= e(format_datetime($assignment['due_at'])) ?> &middot;
          <?= num((float)$assignment['max_marks']) ?> marks &middot;
          <strong><?= $submitted ?> / <?= count($roster) ?></strong> submitted
        </div>
      </div>
      <a href="<?= url('faculty/assignment_form.php?id=' . $id) ?>" class="btn btn-outline-secondary align-self-start">
        <i class="bi bi-pencil me-1"></i>Edit</a>
    </div>
    <details class="mt-3">
      <summary class="small fw-semibold">Questions</summary>
      <div class="assignment-questions mt-2"><?= nl2br(e($assignment['questions'])) ?></div>
    </details>
  </div>
</div>

<?php if (!$roster): ?>
  <div class="card border-0 p-5 text-center text-muted-2">No active student is in this class yet.</div>
<?php endif; ?>

<div class="d-flex flex-column gap-3">
  <?php foreach ($roster as $r): ?>
    <div class="card border-0" <?= $r['submission_id'] ? 'id="s' . (int)$r['submission_id'] . '"' : '' ?>>
      <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
          <div>
            <strong><?= e($r['name']) ?></strong>
            <span class="small text-muted-2">
              <?= e($r['enrollment_no'] ?: '') ?>
              <?= $r['branch_code'] ? '&middot; ' . e($r['branch_code']) : '' ?>
            </span>
          </div>
          <?php if (!$r['submission_id']): ?>
            <span class="badge bg-secondary">Not submitted</span>
          <?php else: ?>
            <div class="d-flex gap-1 align-items-center">
              <span class="small text-muted-2"><?= e(format_datetime($r['submitted_at'])) ?></span>
              <?php if ((int)$r['is_late']): ?><span class="badge bg-danger">Late</span><?php endif; ?>
              <?php if ($r['graded_at']): ?>
                <span class="badge bg-success"><?= num((float)$r['marks']) ?> / <?= num((float)$assignment['max_marks']) ?></span>
              <?php else: ?>
                <span class="badge bg-warning text-dark">To grade</span>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>

        <?php if ($r['submission_id']): ?>
          <?php if ($r['answer_text'] !== null && $r['answer_text'] !== ''): ?>
            <div class="assignment-answer mt-3"><?= nl2br(e($r['answer_text'])) ?></div>
          <?php endif; ?>
          <?php if ($r['pdf_file']): ?>
            <a class="btn btn-sm btn-outline-primary mt-3" target="_blank" rel="noopener"
               href="<?= url('assignment_file.php?id=' . (int)$r['submission_id']) ?>">
              <i class="bi bi-file-earmark-pdf me-1"></i><?= e($r['pdf_name'] ?: 'Handwritten answer.pdf') ?></a>
          <?php endif; ?>

          <form method="post" class="row g-2 align-items-end mt-2">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="submission_id" value="<?= (int)$r['submission_id'] ?>">
            <div class="col-4 col-md-2">
              <label class="form-label small mb-1">Marks / <?= num((float)$assignment['max_marks']) ?></label>
              <input type="number" name="marks" step="0.5" min="0" max="<?= e((float)$assignment['max_marks']) ?>"
                     class="form-control form-control-sm" value="<?= $r['marks'] !== null ? e(num((float)$r['marks'])) : '' ?>" required>
            </div>
            <div class="col-8 col-md-8">
              <label class="form-label small mb-1">Feedback (optional)</label>
              <input type="text" name="feedback" class="form-control form-control-sm" maxlength="2000"
                     value="<?= e($r['feedback'] ?? '') ?>">
            </div>
            <div class="col-md-2 d-grid">
              <button class="btn btn-sm btn-primary"><i class="bi bi-check2 me-1"></i><?= $r['graded_at'] ? 'Update' : 'Save' ?></button>
            </div>
          </form>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/includes/faculty_footer.php'; ?>
