<?php
/**
 * Answer review - shown only after an attempt has been submitted, which is
 * the point at which it is safe to send the answer key to the browser.
 */
require_once __DIR__ . '/includes/auth.php';

$student   = require_student();
$attemptId = get_int('attempt');
$attempt   = get_owned_attempt($attemptId, (int)$student['id']);

if (!$attempt) {
    set_flash('danger', 'Result not found.');
    redirect('results.php');
}
if ($attempt['status'] === 'in_progress') {
    set_flash('warning', 'You can review your answers only after submitting the paper.');
    redirect('exam.php?attempt=' . $attemptId);
}

$exam = get_exam((int)$attempt['exam_id']);

// Questions + options + what the student chose.
$qs = db()->prepare(
    'SELECT q.id, q.question_text, q.type, q.marks, q.explanation, q.difficulty, q.category,
            a.option_id AS chosen, a.answer_text, a.awarded_marks, a.feedback, a.evaluated_at
       FROM questions q
  LEFT JOIN answers a ON a.question_id = q.id AND a.attempt_id = ?
      WHERE q.exam_id = ?
   ORDER BY q.sort_order, q.id'
);
$qs->execute([$attemptId, $attempt['exam_id']]);
$questions = $qs->fetchAll();

$optStmt = db()->prepare('SELECT id, question_id, option_text, is_correct
                            FROM options WHERE question_id = ? ORDER BY option_order, id');

$filter  = $_GET['filter'] ?? 'all';   // all | correct | incorrect | unanswered
$letters = ['A', 'B', 'C', 'D', 'E', 'F'];

$pageTitle = 'Answer Review';
$activeNav = 'results';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-4 py-lg-5">
  <div class="page-head d-flex flex-wrap justify-content-between align-items-end mb-4 gap-3">
    <div>
      <span class="eyebrow">Question by question</span>
      <h2 class="mb-1">Answer Review</h2>
      <p class="text-muted-2 mb-0">
        <?= e($exam['title']) ?> &middot; <?= e(format_datetime($attempt['submitted_at'])) ?>
      </p>
    </div>
    <div class="d-flex gap-2 no-print">
      <a href="<?= url('result.php?attempt=' . $attemptId) ?>" class="btn btn-outline-primary">
        <i class="bi bi-bar-chart me-1"></i>Back to Result
      </a>
      <a href="<?= url('dashboard.php') ?>" class="btn btn-outline-secondary">Dashboard</a>
    </div>
  </div>

  <!-- Filter buttons -->
  <div class="btn-group mb-4 no-print flex-wrap" role="group" aria-label="Filter questions">
    <?php foreach ([
        'all'        => 'All Questions',
        'correct'    => 'Correct',
        'incorrect'  => 'Incorrect',
        'unanswered' => 'Unanswered',
    ] as $key => $label): ?>
      <a class="btn btn-<?= $filter === $key ? '' : 'outline-' ?>primary"
         href="<?= url('review.php?attempt=' . $attemptId . '&filter=' . $key) ?>">
        <?= e($label) ?>
      </a>
    <?php endforeach; ?>
  </div>

  <?php foreach ($questions as $i => $q):
      $isSubjective = $q['type'] === 'subjective';
      $written      = trim((string)$q['answer_text']);
      $options      = [];
      $chosen       = null;
      $correctId    = null;

      if ($isSubjective) {
          if ($written === '')                  { $status = 'unanswered'; }
          elseif ($q['evaluated_at'] === null)  { $status = 'pending'; }
          elseif ((float)$q['awarded_marks'] >= (float)$q['marks']) { $status = 'correct'; }
          elseif ((float)$q['awarded_marks'] > 0) { $status = 'partial'; }
          else                                  { $status = 'incorrect'; }
      } else {
          $optStmt->execute([$q['id']]);
          $options = $optStmt->fetchAll();
          $chosen  = $q['chosen'] !== null ? (int)$q['chosen'] : null;
          foreach ($options as $o) {
              if ((int)$o['is_correct'] === 1) { $correctId = (int)$o['id']; }
          }

          if ($chosen === null)           { $status = 'unanswered'; }
          elseif ($chosen === $correctId) { $status = 'correct'; }
          else                            { $status = 'incorrect'; }
      }

      // The filter buttons only offer correct/incorrect/unanswered, so the two
      // extra subjective states ride along with the closest match.
      $filterKey = match ($status) {
          'partial' => 'incorrect',
          'pending' => 'unanswered',
          default   => $status,
      };
      if ($filter !== 'all' && $filter !== $filterKey) { continue; }

      $badge = [
        'correct'    => ['bg-success', 'check-circle-fill', 'Correct'],
        'partial'    => ['bg-warning text-dark', 'slash-circle-fill', 'Partly correct'],
        'incorrect'  => ['bg-danger',  'x-circle-fill',     'Incorrect'],
        'unanswered' => ['bg-warning text-dark', 'dash-circle-fill', 'Unanswered'],
        'pending'    => ['bg-info text-dark', 'hourglass-split', 'Awaiting evaluation'],
      ][$status];
      $itemClass = in_array($status, ['partial', 'pending'], true) ? 'unanswered' : $status;
  ?>
    <div class="review-item <?= $itemClass ?>">
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div class="fw-bold">Question <?= $i + 1 ?></div>
        <div class="d-flex gap-2 align-items-center">
          <?php if ($isSubjective): ?>
            <span class="badge bg-warning-subtle text-warning-emphasis">Written</span>
          <?php endif; ?>
          <span class="badge bg-light text-muted-2 border"><?= e(ucfirst($q['difficulty'])) ?></span>
          <?php if ($q['category']): ?>
            <span class="badge bg-light text-muted-2 border"><?= e($q['category']) ?></span>
          <?php endif; ?>
          <span class="badge <?= $badge[0] ?>">
            <i class="bi bi-<?= $badge[1] ?> me-1"></i><?= $badge[2] ?>
          </span>
        </div>
      </div>

      <p class="question-text mb-3"><?= e($q['question_text']) ?></p>

      <?php if ($isSubjective): ?>
        <div class="mb-3">
          <div class="small text-muted-2 mb-1">Your answer</div>
          <div class="border rounded p-3 bg-light" style="white-space:pre-wrap">
            <?= $written !== '' ? e($written)
                  : '<em class="text-danger">You did not answer this question.</em>' ?>
          </div>
        </div>

        <div class="d-flex flex-wrap gap-3 align-items-center mb-3">
          <span class="badge bg-dark-subtle text-dark-emphasis">
            Marks:
            <?php if ($q['evaluated_at'] === null && $written !== ''): ?>
              not marked yet
            <?php else: ?>
              <?= num((float)($q['awarded_marks'] ?? 0)) ?> / <?= num((float)$q['marks']) ?>
            <?php endif; ?>
          </span>
          <?php if ($q['evaluated_at']): ?>
            <span class="small text-muted-2">
              <i class="bi bi-check2 me-1"></i>Evaluated on <?= e(format_datetime($q['evaluated_at'])) ?>
            </span>
          <?php endif; ?>
        </div>

        <?php if (trim((string)$q['feedback']) !== ''): ?>
          <div class="explanation-box mb-3">
            <strong><i class="bi bi-chat-left-text text-primary me-1"></i>Faculty feedback:</strong>
            <?= e($q['feedback']) ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>

      <?php foreach ($options as $k => $o):
          $id        = (int)$o['id'];
          $isCorrect = (int)$o['is_correct'] === 1;
          $isChosen  = $chosen === $id;
          $cls = $isCorrect ? 'is-correct' : ($isChosen ? 'is-chosen-wrong' : '');
      ?>
        <div class="review-option <?= $cls ?>">
          <span class="option-key"><?= $letters[$k] ?? ($k + 1) ?>.</span>
          <span class="flex-grow-1"><?= e($o['option_text']) ?></span>
          <?php if ($isChosen): ?>
            <span class="badge <?= $isCorrect ? 'bg-success' : 'bg-danger' ?>">Your answer</span>
          <?php endif; ?>
          <?php if ($isCorrect): ?>
            <span class="badge bg-success-subtle text-success-emphasis">Correct answer</span>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>

      <?php if ($q['explanation']): ?>
        <div class="explanation-box mt-3">
          <strong><i class="bi bi-lightbulb text-warning me-1"></i>Explanation:</strong>
          <?= e($q['explanation']) ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <div class="text-center mt-4 no-print">
    <a href="<?= url('result.php?attempt=' . $attemptId) ?>" class="btn btn-primary">
      <i class="bi bi-arrow-left me-1"></i>Back to Result
    </a>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
