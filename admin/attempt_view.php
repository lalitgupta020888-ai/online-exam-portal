<?php
/**
 * Admin - read only answer sheet for one student's attempt.
 *
 * Shows what the student answered and, for written questions, exactly how the
 * faculty marked it: the marks awarded against the maximum, the feedback they
 * left, when they marked it and how it compares with their own model answer.
 */
require_once __DIR__ . '/../includes/auth.php';
$admin     = require_admin();
$collegeId = (int)$admin['college_id'];

$attemptId = get_int('attempt');

$st = db()->prepare(
    'SELECT t.*, s.name AS student_name, s.email, e.title AS exam_title, e.subject,
            e.passing_marks, e.negative_marks, e.faculty_id
       FROM attempts t
       JOIN students s ON s.id = t.student_id
       JOIN exams e    ON e.id = t.exam_id
      WHERE t.id = ? AND e.college_id = ?'
);
$st->execute([$attemptId, $collegeId]);
$attempt = $st->fetch();

if (!$attempt) {
    set_flash('danger', 'Answer sheet not found.');
    redirect('admin/results.php');
}

$st = db()->prepare('SELECT * FROM results WHERE attempt_id = ?');
$st->execute([$attemptId]);
$result = $st->fetch();

$owner = null;
if ($attempt['faculty_id']) {
    $st = db()->prepare('SELECT * FROM faculty WHERE id = ?');
    $st->execute([$attempt['faculty_id']]);
    $owner = $st->fetch() ?: null;
}

$st = db()->prepare(
    'SELECT q.*, a.option_id AS chosen, a.answer_text, a.awarded_marks, a.feedback,
            a.evaluated_at, a.is_correct,
            f.name AS evaluator_name
       FROM questions q
  LEFT JOIN answers a ON a.question_id = q.id AND a.attempt_id = ?
  LEFT JOIN faculty f ON f.id = a.evaluated_by
      WHERE q.exam_id = ?
   ORDER BY q.sort_order, q.id'
);
$st->execute([$attemptId, $attempt['exam_id']]);
$questions = $st->fetchAll();

$optStmt = db()->prepare('SELECT * FROM options WHERE question_id = ? ORDER BY option_order, id');
$letters  = ['A', 'B', 'C', 'D', 'E', 'F'];

$pageTitle = 'Answer Sheet - ' . $attempt['student_name'];
$activeNav = 'results';
require_once __DIR__ . '/includes/admin_header.php';
?>

<div class="card border-0 mb-3">
  <div class="card-body">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
      <div class="d-flex align-items-center gap-3">
        <span class="avatar-md"><?= e(initials($attempt['student_name'])) ?></span>
        <div>
          <span class="eyebrow">Answer sheet</span>
          <h4 class="mb-1"><?= e($attempt['student_name']) ?></h4>
          <div class="small text-muted-2">
            <?= e($attempt['email']) ?> &middot; <?= e($attempt['exam_title']) ?>
            (<?= e($attempt['subject']) ?>)
            &middot; submitted <?= e(format_datetime($attempt['submitted_at'])) ?>
          </div>
          <?php if ($owner): ?>
            <div class="small mt-1">
              Paper set by
              <a href="<?= url('admin/faculty_detail.php?id=' . (int)$owner['id']) ?>"
                 class="fw-semibold"><?= e($owner['name']) ?></a>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-secondary"
           href="<?= url('admin/results.php?exam_id=' . (int)$attempt['exam_id']) ?>">
          <i class="bi bi-arrow-left me-1"></i>Results
        </a>
        <a class="btn btn-outline-primary"
           href="<?= url('admin/paper_view.php?exam_id=' . (int)$attempt['exam_id']) ?>">
          <i class="bi bi-journal-text me-1"></i>Question paper
        </a>
      </div>
    </div>
  </div>
</div>

<?php if ($result): ?>
  <div class="row g-3 mb-4">
    <?php
    $cards = [
      ['check-circle',  'text-success', 'Correct',    (int)$result['correct_count']],
      ['x-circle',      'text-danger',  'Incorrect',  (int)$result['wrong_count']],
      ['dash-circle',   'text-warning', 'Unanswered', (int)$result['unanswered_count']],
      ['ui-checks',     'text-primary', 'Objective',  num((float)$result['objective_marks'])],
    ];
    if ((float)$result['subjective_total'] > 0) {
      $cards[] = ['pencil-square', 'text-warning', 'Written',
                  (int)$result['pending_evaluation']
                    ? 'pending'
                    : num((float)$result['subjective_marks']) . ' / '
                      . num((float)$result['subjective_total'])];
    }
    $cards[] = ['award', 'text-dark', 'Total',
                num((float)$result['obtained_marks']) . ' / ' . num((float)$result['total_marks'])];
    foreach ($cards as [$icon, $tone, $label, $value]): ?>
      <div class="col-6 col-lg-2">
        <div class="stat-card h-100">
          <i class="bi bi-<?= $icon ?> fs-4 <?= $tone ?>"></i>
          <div class="value mt-1 fs-5"><?= e($value) ?></div>
          <div class="label"><?= e($label) ?></div>
        </div>
      </div>
    <?php endforeach; ?>
    <div class="col-6 col-lg-2">
      <div class="stat-card h-100">
        <i class="bi bi-patch-check fs-4 <?= $result['status'] === 'PASS'
              ? 'text-success' : 'text-danger' ?>"></i>
        <div class="value mt-1 fs-5"><?= e($result['status']) ?></div>
        <div class="label"><?= num((float)$result['percentage']) ?>%</div>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php foreach ($questions as $i => $q):
    $isSub   = $q['type'] === 'subjective';
    $written = trim((string)$q['answer_text']);
    $chosen  = $q['chosen'] !== null ? (int)$q['chosen'] : null;
?>
  <div class="card border-0 mb-3">
    <div class="card-body">
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div class="d-flex align-items-center gap-2">
          <span class="q-number"><?= $i + 1 ?></span>
          <span class="badge <?= $isSub ? 'bg-warning-subtle text-warning-emphasis'
                : 'bg-primary-subtle text-primary-emphasis' ?>">
            <?= $isSub ? 'Written' : 'Objective' ?>
          </span>
          <span class="small text-muted-2"><?= num((float)$q['marks']) ?> marks</span>
        </div>

        <?php if ($isSub): ?>
          <?php if ($q['evaluated_at']): ?>
            <span class="badge bg-success">
              Marked <?= num((float)$q['awarded_marks']) ?> / <?= num((float)$q['marks']) ?>
            </span>
          <?php elseif ($written !== ''): ?>
            <span class="badge bg-danger">Not marked yet</span>
          <?php else: ?>
            <span class="badge bg-warning text-dark">Not answered</span>
          <?php endif; ?>
        <?php elseif ($chosen === null): ?>
          <span class="badge bg-warning text-dark">Not answered</span>
        <?php elseif ((int)$q['is_correct'] === 1): ?>
          <span class="badge bg-success">Correct</span>
        <?php else: ?>
          <span class="badge bg-danger">Incorrect</span>
        <?php endif; ?>
      </div>

      <p class="question-text mb-3"><?= e($q['question_text']) ?></p>

      <?php if ($isSub): ?>
        <div class="row g-3">
          <div class="col-lg-7">
            <div class="small text-muted-2 mb-1">Student's answer</div>
            <div class="border rounded p-3 bg-light" style="white-space:pre-wrap">
              <?= $written !== '' ? e($written)
                    : '<em class="text-danger">Not answered</em>' ?>
            </div>
          </div>
          <div class="col-lg-5">
            <?php if ($q['model_answer']): ?>
              <div class="small text-muted-2 mb-1">Faculty's model answer</div>
              <div class="explanation-box mb-3" style="white-space:pre-wrap">
                <?= e($q['model_answer']) ?>
              </div>
            <?php endif; ?>

            <?php if ($q['evaluated_at']): ?>
              <ul class="info-list small">
                <li><span>Marks awarded</span>
                  <strong><?= num((float)$q['awarded_marks']) ?> / <?= num((float)$q['marks']) ?></strong></li>
                <li><span>Marked by</span>
                  <strong><?= e($q['evaluator_name'] ?? 'Faculty') ?></strong></li>
                <li><span>Marked on</span>
                  <strong><?= e(format_datetime($q['evaluated_at'])) ?></strong></li>
              </ul>
              <?php if (trim((string)$q['feedback']) !== ''): ?>
                <div class="explanation-box mt-2">
                  <strong><i class="bi bi-chat-left-text text-primary me-1"></i>Feedback to the student:</strong>
                  <?= e($q['feedback']) ?>
                </div>
              <?php else: ?>
                <div class="alert alert-warning py-2 small mb-0 mt-2">
                  <i class="bi bi-exclamation-triangle me-1"></i>
                  No feedback was given to the student for this answer.
                </div>
              <?php endif; ?>
            <?php elseif ($written !== ''): ?>
              <div class="alert alert-danger py-2 small mb-0">
                <i class="bi bi-hourglass-split me-1"></i>
                This answer is still waiting for the faculty to mark it.
              </div>
            <?php endif; ?>
          </div>
        </div>

      <?php else:
        $optStmt->execute([$q['id']]);
        foreach ($optStmt->fetchAll() as $k => $o):
            $isCorrect = (int)$o['is_correct'] === 1;
            $isChosen  = $chosen === (int)$o['id'];
            $cls = $isCorrect ? 'is-correct' : ($isChosen ? 'is-chosen-wrong' : ''); ?>
          <div class="review-option <?= $cls ?>">
            <span class="option-key"><?= $letters[$k] ?? ($k + 1) ?>.</span>
            <span class="flex-grow-1"><?= e($o['option_text']) ?></span>
            <?php if ($isChosen): ?>
              <span class="badge <?= $isCorrect ? 'bg-success' : 'bg-danger' ?>">Student's answer</span>
            <?php endif; ?>
            <?php if ($isCorrect): ?>
              <span class="badge bg-success-subtle text-success-emphasis">Correct answer</span>
            <?php endif; ?>
          </div>
        <?php endforeach;
      endif; ?>

      <?php if ($q['explanation']): ?>
        <div class="explanation-box mt-3">
          <strong><i class="bi bi-lightbulb text-warning me-1"></i>Explanation:</strong>
          <?= e($q['explanation']) ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
