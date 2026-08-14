<?php
/**
 * Faculty - the exams this faculty member owns, with progress on each.
 */
require_once __DIR__ . '/../includes/auth.php';
$faculty = require_faculty();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $examId = (int)post('exam_id');
    $exam   = get_owned_exam($examId, (int)$faculty['id']);

    if (!$exam) {
        set_flash('danger', 'That exam does not belong to you.');
    } else {
        switch (post('action')) {
            case 'delete':
                db()->prepare('DELETE FROM exams WHERE id = ?')->execute([$examId]);
                set_flash('success', 'Exam deleted along with its questions and results.');
                break;
            case 'toggle':
                db()->prepare('UPDATE exams SET is_active = 1 - is_active WHERE id = ?')
                    ->execute([$examId]);
                set_flash('success', 'Exam visibility updated.');
                break;
            case 'release':
                db()->prepare('UPDATE exams SET results_released = 1 WHERE id = ?')
                    ->execute([$examId]);
                set_flash('success', 'Results released - you can now see them without waiting '
                                   . 'for the remaining students.');
                break;
            case 'unrelease':
                db()->prepare('UPDATE exams SET results_released = 0 WHERE id = ?')
                    ->execute([$examId]);
                set_flash('success', 'Results locked again.');
                break;
        }
    }
    redirect('faculty/exams.php');
}

$pageTitle = 'My Exams';
$activeNav = 'exams';
require_once __DIR__ . '/includes/faculty_header.php';

$exams = get_faculty_exams((int)$faculty['id']);
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
  <input type="search" class="form-control" id="tableSearch" style="max-width:320px"
         placeholder="Search my exams...">
  <a href="<?= url('faculty/exam_form.php') ?>" class="btn btn-primary">
    <i class="bi bi-plus-lg me-1"></i>Create New Exam
  </a>
</div>

<?php if (!$exams): ?>
  <div class="card border-0 p-5 text-center">
    <i class="bi bi-journal-plus display-4 text-muted-2"></i>
    <h5 class="mt-3 mb-1">You have not created any exam yet</h5>
    <p class="text-muted-2 mb-4">
      Create an exam, add questions by typing them or importing a PDF, then assign
      it to your students.
    </p>
    <div><a href="<?= url('faculty/exam_form.php') ?>" class="btn btn-primary">
      Create the first exam</a></div>
  </div>
<?php else: ?>
  <div class="row g-3" data-searchable>
    <?php foreach ($exams as $exam):
        $id = (int)$exam['id'];
        $p  = exam_progress($exam);
    ?>
      <div class="col-xl-6">
        <div class="card border-0 h-100">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
              <div>
                <h5 class="mb-1"><?= e($exam['title']) ?></h5>
                <div class="small text-muted-2">
                  <i class="bi bi-bookmark me-1"></i><?= e($exam['subject']) ?>
                  &middot; <?= (int)$exam['duration_minutes'] ?> min
                  &middot; <?= num((float)$exam['total_marks']) ?> marks
                </div>
              </div>
              <div class="text-end">
                <span class="badge <?= (int)$exam['is_active'] ? 'bg-success' : 'bg-secondary' ?>">
                  <?= (int)$exam['is_active'] ? 'Active' : 'Hidden' ?>
                </span>
                <?php if ($exam['access_mode'] === 'assigned'): ?>
                  <span class="badge bg-info-subtle text-info-emphasis">Assigned only</span>
                <?php else: ?>
                  <span class="badge bg-warning-subtle text-warning-emphasis">Open to all</span>
                <?php endif; ?>
              </div>
            </div>

            <div class="row g-2 mb-3">
              <div class="col-3"><div class="stat-card p-2">
                <div class="value fs-5"><?= (int)$exam['question_count'] ?></div>
                <div class="label">Questions</div></div></div>
              <div class="col-3"><div class="stat-card p-2">
                <div class="value fs-5"><?= (int)$exam['subjective_count'] ?></div>
                <div class="label">Written</div></div></div>
              <div class="col-3"><div class="stat-card p-2">
                <div class="value fs-5"><?= $p['assigned'] ?></div>
                <div class="label">Students</div></div></div>
              <div class="col-3"><div class="stat-card p-2">
                <div class="value fs-5"><?= $p['completed'] ?></div>
                <div class="label">Completed</div></div></div>
            </div>

            <div class="d-flex justify-content-between small mb-1">
              <span class="text-muted-2">Completion</span>
              <span class="fw-semibold"><?= $p['completed'] ?> / <?= $p['assigned'] ?></span>
            </div>
            <div class="progress mb-3">
              <div class="progress-bar <?= $p['all_completed'] ? 'bg-success' : 'bg-primary' ?>"
                   style="width:<?= (int)$p['percent'] ?>%"></div>
            </div>

            <?php if ($p['pending_eval'] > 0): ?>
              <div class="alert alert-warning py-2 small mb-3">
                <i class="bi bi-pencil-square me-1"></i>
                <?= $p['pending_eval'] ?> paper(s) waiting for evaluation of written answers.
                <a href="<?= url('faculty/evaluate.php?exam_id=' . $id) ?>" class="fw-semibold">
                  Evaluate now</a>
              </div>
            <?php elseif ($p['all_completed']): ?>
              <div class="alert alert-success py-2 small mb-3">
                <i class="bi bi-check2-circle me-1"></i>
                All students have completed this exam - results are ready.
              </div>
            <?php endif; ?>

            <div class="d-flex flex-wrap gap-1">
              <a class="btn btn-sm btn-outline-secondary"
                 href="<?= url('faculty/questions.php?exam_id=' . $id) ?>">
                <i class="bi bi-list-check me-1"></i>Questions
              </a>
              <a class="btn btn-sm btn-outline-secondary"
                 href="<?= url('faculty/import.php?exam_id=' . $id) ?>">
                <i class="bi bi-file-earmark-arrow-up me-1"></i>Import
              </a>
              <a class="btn btn-sm btn-outline-secondary"
                 href="<?= url('faculty/assign.php?exam_id=' . $id) ?>">
                <i class="bi bi-people me-1"></i>Assign
              </a>
              <a class="btn btn-sm btn-outline-primary"
                 href="<?= url('faculty/results.php?exam_id=' . $id) ?>">
                <i class="bi bi-bar-chart me-1"></i>Results
              </a>
              <a class="btn btn-sm btn-outline-secondary"
                 href="<?= url('faculty/exam_form.php?id=' . $id) ?>">
                <i class="bi bi-pencil"></i>
              </a>

              <form method="post" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="exam_id" value="<?= $id ?>">
                <input type="hidden" name="action" value="toggle">
                <button class="btn btn-sm btn-outline-warning" title="Show / hide">
                  <i class="bi bi-eye<?= (int)$exam['is_active'] ? '-slash' : '' ?>"></i>
                </button>
              </form>

              <?php if (!$p['all_completed']): ?>
                <form method="post" class="d-inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="exam_id" value="<?= $id ?>">
                  <input type="hidden" name="action"
                         value="<?= (int)$exam['results_released'] ? 'unrelease' : 'release' ?>">
                  <button class="btn btn-sm btn-outline-info"
                          title="<?= (int)$exam['results_released']
                                    ? 'Lock results again' : 'Release results early' ?>">
                    <i class="bi bi-<?= (int)$exam['results_released'] ? 'lock' : 'unlock' ?>"></i>
                  </button>
                </form>
              <?php endif; ?>

              <form method="post" class="d-inline ms-auto">
                <?= csrf_field() ?>
                <input type="hidden" name="exam_id" value="<?= $id ?>">
                <input type="hidden" name="action" value="delete">
                <button class="btn btn-sm btn-outline-danger"
                        data-confirm="Delete this exam? Its questions, attempts and results will be removed permanently.">
                  <i class="bi bi-trash"></i>
                </button>
              </form>
            </div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/faculty_footer.php'; ?>
