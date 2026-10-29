<?php
/**
 * Student - read one assignment and submit a typed answer and / or a PDF of
 * the handwritten pages. Re-submitting replaces the earlier work, until the
 * faculty member has graded it.
 */
require_once __DIR__ . '/includes/auth.php';
$student = require_student();

$id = get_int('id') ?: (int)post('id');
$assignment = get_student_assignment($id, $student);
if (!$assignment) {
    set_flash('danger', 'That assignment is not available to you.');
    redirect('assignments.php');
}
$sub   = get_submission($id, (int)$student['id']);
$error = '';
$text  = $sub['answer_text'] ?? '';

// A file over post_max_size makes PHP drop the whole form, CSRF token included.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    set_flash('danger', 'The upload was too large. Keep the PDF under ' . (ASSIGNMENT_PDF_MAX_BYTES >> 20) . ' MB.');
    redirect('assignment.php?id=' . $id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $text = post('answer_text');
    $removePdf = post('remove_pdf') === '1';

    if ($sub && $sub['graded_at']) {
        $error = 'This assignment has already been graded and can no longer be changed.';
    } elseif (mb_strlen($text) > 50000) {
        $error = 'The typed answer is too long (50,000 characters at most).';
    } else {
        [$pdf, $error] = store_assignment_pdf($_FILES['pdf'] ?? []);
        $keepOld = $sub && $sub['pdf_file'] && !$pdf && !$removePdf;
        if ($error === '' && $text === '' && !$pdf && !$keepOld) {
            $error = 'Type your answer or upload a PDF of your handwritten work (or both).';
            delete_assignment_pdf($pdf);
        }
        if ($error === '') {
            $file = $pdf ?: ($keepOld ? $sub['pdf_file'] : null);
            $name = $pdf ? mb_substr(basename((string)$_FILES['pdf']['name']), 0, 200)
                         : ($keepOld ? $sub['pdf_name'] : null);
            $late = strtotime($assignment['due_at']) < time() ? 1 : 0;
            db()->prepare('INSERT INTO assignment_submissions
                               (assignment_id, student_id, answer_text, pdf_file, pdf_name, submitted_at, is_late)
                           VALUES (?, ?, ?, ?, ?, NOW(), ?)
                           ON DUPLICATE KEY UPDATE answer_text = VALUES(answer_text), pdf_file = VALUES(pdf_file),
                               pdf_name = VALUES(pdf_name), submitted_at = VALUES(submitted_at), is_late = VALUES(is_late)')
                ->execute([$id, $student['id'], $text !== '' ? $text : null, $file, $name, $late]);
            if ($sub && $sub['pdf_file'] && $sub['pdf_file'] !== $file) {
                delete_assignment_pdf($sub['pdf_file']);
            }
            set_flash('success', $sub ? 'Your assignment has been updated.' : 'Assignment submitted.');
            redirect('assignment.php?id=' . $id);
        }
    }
}

$locked  = $sub && $sub['graded_at'];
$overdue = strtotime($assignment['due_at']) < time();

$pageTitle = $assignment['title'];
$activeNav = 'assignments';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-4" style="max-width: 900px">
  <a href="<?= url('assignments.php') ?>" class="small text-decoration-none"><i class="bi bi-arrow-left me-1"></i>My Assignments</a>

  <div class="card border-0 mt-2 mb-3">
    <div class="card-body p-4">
      <span class="eyebrow"><?= e($assignment['subject']) ?></span>
      <h4 class="mb-1"><?= e($assignment['title']) ?></h4>
      <div class="small text-muted-2 mb-3">
        <?= e($assignment['faculty_name']) ?> &middot; <?= num((float)$assignment['max_marks']) ?> marks &middot;
        <span class="<?= $overdue && !$sub ? 'text-danger fw-semibold' : '' ?>">
          Last date <?= e(format_datetime($assignment['due_at'])) ?> (<?= e(due_label($assignment['due_at'])) ?>)</span>
      </div>
      <div class="assignment-questions"><?= nl2br(e($assignment['questions'])) ?></div>
    </div>
  </div>

  <?php if ($locked): ?>
    <div class="card border-0 mb-3 border-start border-success border-4">
      <div class="card-body p-4">
        <h6 class="mb-1"><i class="bi bi-patch-check-fill text-success me-1"></i>Graded</h6>
        <div class="display-6 fw-bold"><?= num((float)$sub['marks']) ?> <small class="fs-5 text-muted-2">/ <?= num((float)$assignment['max_marks']) ?></small></div>
        <?php if ($sub['feedback']): ?>
          <p class="mb-0 mt-2"><strong>Feedback:</strong> <?= nl2br(e($sub['feedback'])) ?></p>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data" class="card border-0">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $id ?>">
    <div class="card-body p-4">
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h5 class="mb-0"><i class="bi bi-pencil-square me-1"></i>My Answer</h5>
        <?php if ($sub): ?>
          <span class="small text-muted-2">Submitted <?= e(format_datetime($sub['submitted_at'])) ?>
            <?= (int)$sub['is_late'] ? '<span class="badge bg-danger ms-1">Late</span>' : '' ?></span>
        <?php endif; ?>
      </div>

      <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

      <label class="form-label" for="answer_text">Type your answer</label>
      <textarea class="form-control mb-3" id="answer_text" name="answer_text" rows="12"
                placeholder="Write your answers here..." <?= $locked ? 'readonly' : '' ?>><?= e($text) ?></textarea>

      <label class="form-label" for="pdf">Handwritten answer (PDF)</label>
      <?php if ($sub && $sub['pdf_file']): ?>
        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
          <a class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener"
             href="<?= url('assignment_file.php?id=' . (int)$sub['id']) ?>">
            <i class="bi bi-file-earmark-pdf me-1"></i><?= e($sub['pdf_name'] ?: 'My answer.pdf') ?></a>
          <?php if (!$locked): ?>
            <div class="form-check small mb-0">
              <input class="form-check-input" type="checkbox" name="remove_pdf" value="1" id="remove_pdf">
              <label class="form-check-label" for="remove_pdf">Remove this PDF</label>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>
      <?php if (!$locked): ?>
        <input type="file" class="form-control" id="pdf" name="pdf" accept="application/pdf,.pdf">
        <div class="form-text">
          Write on paper, scan the pages into one PDF (e.g. with Google Drive or Adobe Scan), max
          <?= ASSIGNMENT_PDF_MAX_BYTES >> 20 ?> MB.<?= $sub && $sub['pdf_file'] ? ' A new file replaces the old one.' : '' ?>
          You can type, upload a PDF, or both.
        </div>
      <?php endif; ?>
    </div>
    <?php if (!$locked): ?>
      <div class="card-footer bg-transparent p-3 d-flex justify-content-end">
        <button class="btn btn-primary"><i class="bi bi-upload me-1"></i><?= $sub ? 'Update Submission' : 'Submit Assignment' ?></button>
      </div>
    <?php endif; ?>
  </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
