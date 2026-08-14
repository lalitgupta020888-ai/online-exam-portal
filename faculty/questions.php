<?php
/**
 * Faculty - questions of one of my exams.
 */
require_once __DIR__ . '/../includes/auth.php';
$faculty = require_faculty();

$examId = get_int('exam_id') ?: (int)post('exam_id');
$exam   = get_owned_exam($examId, (int)$faculty['id']);
if (!$exam) {
    set_flash('danger', 'That exam does not belong to you.');
    redirect('faculty/exams.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post('action');

    if ($action === 'delete' && ($qid = (int)post('question_id'))) {
        // The exam_id check keeps one faculty out of another's question bank.
        db()->prepare('DELETE q FROM questions q JOIN exams e ON e.id = q.exam_id
                        WHERE q.id = ? AND e.faculty_id = ?')
            ->execute([$qid, $faculty['id']]);
        set_flash('success', 'Question deleted.');
    } elseif ($action === 'delete_import' && ($batch = (int)post('import_id'))) {
        db()->prepare('DELETE FROM question_imports WHERE id = ? AND exam_id = ?')
            ->execute([$batch, $examId]);
        set_flash('success', 'Import record removed.');
    }
    redirect('faculty/questions.php?exam_id=' . $examId);
}

$st = db()->prepare(
    "SELECT q.*,
            (SELECT o.option_text FROM options o
              WHERE o.question_id = q.id AND o.is_correct = 1
              ORDER BY o.option_order LIMIT 1) AS correct_option,
            (SELECT COUNT(*) FROM options o WHERE o.question_id = q.id) AS option_count
       FROM questions q WHERE q.exam_id = ?
   ORDER BY q.sort_order, q.id"
);
$st->execute([$examId]);
$questions = $st->fetchAll();

$objective  = count(array_filter($questions, fn($q) => $q['type'] !== 'subjective'));
$subjective = count($questions) - $objective;

$pageTitle = 'Questions - ' . $exam['title'];
$activeNav = 'exams';
require_once __DIR__ . '/includes/faculty_header.php';
?>

<div class="card border-0 mb-3">
  <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
    <div>
      <h5 class="mb-1"><?= e($exam['title']) ?></h5>
      <div class="small text-muted-2">
        <?= e($exam['subject']) ?> &middot;
        <?= count($questions) ?> questions
        (<?= $objective ?> objective, <?= $subjective ?> written) &middot;
        total <?= num((float)$exam['total_marks']) ?> marks &middot;
        passing <?= num((float)$exam['passing_marks']) ?>
      </div>
    </div>
    <div class="d-flex flex-wrap gap-2">
      <a href="<?= url('faculty/question_form.php?exam_id=' . $examId) ?>" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i>Type a Question
      </a>
      <a href="<?= url('faculty/import.php?exam_id=' . $examId) ?>" class="btn btn-outline-primary">
        <i class="bi bi-file-earmark-arrow-up me-1"></i>Import from PDF / Word / Text
      </a>
      <a href="<?= url('faculty/assign.php?exam_id=' . $examId) ?>" class="btn btn-outline-secondary">
        <i class="bi bi-people me-1"></i>Assign Students
      </a>
    </div>
  </div>
</div>

<?php if ((float)$exam['passing_marks'] > (float)$exam['total_marks'] && $questions): ?>
  <div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle me-1"></i>
    Passing marks (<?= num((float)$exam['passing_marks']) ?>) are higher than the total
    marks of the paper (<?= num((float)$exam['total_marks']) ?>) - nobody can pass.
    <a href="<?= url('faculty/exam_form.php?id=' . $examId) ?>" class="fw-semibold">Fix it</a>
  </div>
<?php endif; ?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
  <input type="search" class="form-control" id="tableSearch" style="max-width:320px"
         placeholder="Search question text...">
</div>

<div class="card border-0">
  <div class="table-responsive" data-searchable>
    <table class="table table-hover align-middle mb-0">
      <thead>
        <tr>
          <th>#</th><th>Question</th><th>Type</th><th>Answer key</th>
          <th>Marks</th><th>Difficulty</th><th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($questions as $i => $q): ?>
          <tr>
            <td class="text-muted-2"><?= $i + 1 ?></td>
            <td style="min-width:260px">
              <div class="fw-semibold"><?= e(mb_strimwidth($q['question_text'], 0, 90, '...')) ?></div>
              <?php if ($q['category']): ?>
                <span class="badge bg-light text-muted-2 border"><?= e($q['category']) ?></span>
              <?php endif; ?>
              <?php if ($q['type'] !== 'subjective' && (int)$q['option_count'] < 2): ?>
                <span class="badge bg-danger">Needs options</span>
              <?php endif; ?>
            </td>
            <td>
              <?php
              $badge = ['mcq' => ['bg-primary-subtle text-primary-emphasis', 'MCQ'],
                        'truefalse' => ['bg-info-subtle text-info-emphasis', 'True/False'],
                        'subjective' => ['bg-warning-subtle text-warning-emphasis', 'Written']][$q['type']];
              ?>
              <span class="badge <?= $badge[0] ?>"><?= $badge[1] ?></span>
            </td>
            <td class="small">
              <?php if ($q['type'] === 'subjective'): ?>
                <span class="text-muted-2">
                  <i class="bi bi-pencil me-1"></i>Marked by you
                </span>
              <?php else: ?>
                <span class="text-success fw-semibold"><?= e($q['correct_option'] ?? '-') ?></span>
              <?php endif; ?>
            </td>
            <td><?= num((float)$q['marks']) ?></td>
            <td><span class="badge bg-light text-muted-2 border"><?= e(ucfirst($q['difficulty'])) ?></span></td>
            <td class="text-end text-nowrap">
              <a class="btn btn-sm btn-outline-primary"
                 href="<?= url('faculty/question_form.php?id=' . (int)$q['id']) ?>">
                <i class="bi bi-pencil"></i>
              </a>
              <form method="post" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="question_id" value="<?= (int)$q['id'] ?>">
                <input type="hidden" name="exam_id" value="<?= $examId ?>">
                <button class="btn btn-sm btn-outline-danger"
                        data-confirm="Delete this question permanently?">
                  <i class="bi bi-trash"></i>
                </button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$questions): ?>
          <tr><td colspan="7" class="text-center text-muted-2 py-5">
            No questions yet. Type one, or import a question paper.
          </td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/includes/faculty_footer.php'; ?>
