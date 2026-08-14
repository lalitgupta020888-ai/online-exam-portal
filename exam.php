<?php
/**
 * The examination interface.
 *
 * This page deliberately does NOT use the site header - during an exam the
 * student gets a locked down layout with only the timer, the question area
 * and the navigation palette.
 *
 * Security notes:
 *   - options are rendered without their is_correct flag;
 *   - the remaining time comes from attempts.expires_at, never from the client;
 *   - a submitted attempt cannot be reopened.
 */
require_once __DIR__ . '/includes/auth.php';

$student   = require_student();
$attemptId = get_int('attempt');
$attempt   = get_owned_attempt($attemptId, (int)$student['id']);

if (!$attempt) {
    set_flash('danger', 'Examination not found.');
    redirect('dashboard.php');
}

// Already finished -> straight to the result. Prevents re-entry.
if ($attempt['status'] !== 'in_progress') {
    redirect('result.php?attempt=' . $attemptId);
}

// Time already over -> grade it now and show the result.
if (seconds_left($attempt) <= 0) {
    grade_attempt($attemptId, 'auto_submitted');
    set_flash('warning', 'Time was over, so your examination was submitted automatically.');
    redirect('result.php?attempt=' . $attemptId);
}

$exam      = get_exam((int)$attempt['exam_id']);
$questions = get_exam_questions((int)$attempt['exam_id']);
$remaining = seconds_left($attempt);

// Current per-question state (selected option + review flag + visited).
$st = db()->prepare('SELECT question_id, option_id, answer_text, marked_for_review, visited
                       FROM answers WHERE attempt_id = ?');
$st->execute([$attemptId]);
$state = [];
foreach ($st->fetchAll() as $row) {
    $state[(int)$row['question_id']] = $row;
}

// Compact state object handed to the JavaScript layer.
$jsState = [];
foreach ($questions as $i => $q) {
    $s = $state[$q['id']] ?? null;
    $jsState[] = [
        'id'         => (int)$q['id'],
        'subjective' => $q['type'] === 'subjective' ? 1 : 0,
        'selected'   => $s && $s['option_id'] !== null ? (int)$s['option_id'] : null,
        'text'       => $s ? (string)($s['answer_text'] ?? '') : '',
        'review'     => $s ? (int)$s['marked_for_review'] : 0,
        'visited'    => $s ? (int)$s['visited'] : 0,
    ];
}
$letters = ['A', 'B', 'C', 'D', 'E', 'F'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Exam in progress &middot; <?= e($exam['title']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= url('assets/css/style.css') ?>" rel="stylesheet">
</head>
<body>

<!-- ============================ TOP BAR ============================ -->
<header class="exam-topbar">
  <div class="container-fluid px-3 px-lg-4">
    <div class="row align-items-center g-2">
      <div class="col-lg-5 col-12">
        <div class="fw-bold text-truncate">
          <i class="bi bi-journal-text text-primary me-1"></i><?= e($exam['title']) ?>
        </div>
        <div class="small text-muted-2">
          <i class="bi bi-person-circle me-1"></i><?= e($student['name']) ?>
          &nbsp;|&nbsp; <?= e($exam['subject']) ?>
        </div>
      </div>

      <div class="col-lg-3 col-6">
        <div class="small text-muted-2">Question</div>
        <div class="fw-bold">
          <span id="qCounter">1</span> of <?= count($questions) ?>
        </div>
      </div>

      <div class="col-lg-4 col-6 text-end">
        <div class="timer-box" id="timerBox" role="timer" aria-live="off">
          <i class="bi bi-stopwatch"></i>
          <span>TIME LEFT:</span>
          <span id="timerText"><?= format_seconds($remaining) ?></span>
        </div>
      </div>
    </div>
  </div>
</header>

<div class="container-fluid px-3 px-lg-4 py-4">
  <div class="row g-4">

    <!-- ========================= QUESTION AREA ======================= -->
    <div class="col-lg-8">
      <div class="question-box">
        <?php foreach ($questions as $i => $q): ?>
          <div class="question-slide<?= $i === 0 ? '' : ' d-none' ?>"
               data-index="<?= $i ?>" data-question-id="<?= (int)$q['id'] ?>">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <span class="badge bg-primary-subtle text-primary-emphasis px-3 py-2">
                Question <?= $i + 1 ?> of <?= count($questions) ?>
              </span>
              <span class="small text-muted-2">
                <?php if ($q['type'] === 'subjective'): ?>
                  <span class="badge bg-warning-subtle text-warning-emphasis me-1">Written answer</span>
                <?php endif; ?>
                <?= num((float)$q['marks']) ?> mark<?= (float)$q['marks'] > 1 ? 's' : '' ?>
                <?php if ($q['type'] !== 'subjective' && (float)$exam['negative_marks'] > 0): ?>
                  &nbsp;|&nbsp; <span class="text-danger">-<?= num((float)$exam['negative_marks']) ?> if wrong</span>
                <?php endif; ?>
              </span>
            </div>

            <p class="question-text mb-4"><?= e($q['question_text']) ?></p>

            <?php if ($q['type'] === 'subjective'): ?>
              <div class="subjective-answer">
                <label class="form-label small text-muted-2" for="ans_<?= (int)$q['id'] ?>">
                  Write your answer
                  <?php if ((int)$q['max_words'] > 0): ?>
                    <span class="text-danger">(maximum <?= (int)$q['max_words'] ?> words)</span>
                  <?php endif; ?>
                </label>
                <textarea class="form-control subjective-input" id="ans_<?= (int)$q['id'] ?>"
                          rows="10" data-max-words="<?= (int)$q['max_words'] ?>"
                          placeholder="Type your answer here..."></textarea>
                <div class="d-flex justify-content-between small text-muted-2 mt-2">
                  <span class="word-count">0 words</span>
                  <span><i class="bi bi-info-circle me-1"></i>Saved automatically as you type</span>
                </div>
              </div>
            <?php else: ?>
              <div class="options">
                <?php foreach ($q['options'] as $k => $opt): ?>
                  <label class="option-item" data-option-id="<?= (int)$opt['id'] ?>">
                    <input type="radio" class="form-check-input"
                           name="q_<?= (int)$q['id'] ?>" value="<?= (int)$opt['id'] ?>">
                    <span class="option-key"><?= $letters[$k] ?? ($k + 1) ?>.</span>
                    <span><?= e($opt['option_text']) ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>

        <!-- ------------------------- Controls ------------------------- -->
        <hr class="my-4">
        <div class="d-flex flex-wrap gap-2">
          <button class="btn btn-outline-secondary" id="btnPrev">
            <i class="bi bi-chevron-left"></i> Previous
          </button>
          <button class="btn btn-outline-danger" id="btnClear">
            <i class="bi bi-eraser"></i> Clear Answer
          </button>
          <button class="btn btn-outline-primary" id="btnReview">
            <i class="bi bi-bookmark"></i> <span id="btnReviewText">Mark for Review</span>
          </button>
          <div class="ms-auto d-flex gap-2">
            <button class="btn btn-outline-secondary" id="btnNext">
              Next <i class="bi bi-chevron-right"></i>
            </button>
            <button class="btn btn-primary" id="btnSaveNext">
              <i class="bi bi-save"></i> Save &amp; Next
            </button>
          </div>
        </div>
        <div class="small mt-3" id="saveStatus" aria-live="polite">&nbsp;</div>
      </div>
    </div>

    <!-- ========================= SIDE PALETTE ======================== -->
    <div class="col-lg-4">
      <div class="card border-0 mb-3">
        <div class="card-body">
          <h6 class="mb-3"><i class="bi bi-grid-3x3-gap me-1"></i>Question Palette</h6>
          <div class="palette mb-3" id="palette"></div>

          <div class="small d-grid gap-2 mb-3">
            <span><span class="legend-dot legend-answered"></span>Answered
              (<span id="cntAnswered">0</span>)</span>
            <span><span class="legend-dot legend-not-answered"></span>Not Answered
              (<span id="cntNotAnswered">0</span>)</span>
            <span><span class="legend-dot legend-review"></span>Marked for Review
              (<span id="cntReview">0</span>)</span>
            <span><span class="legend-dot legend-not-visited"></span>Not Visited
              (<span id="cntNotVisited">0</span>)</span>
          </div>

          <button class="btn btn-success w-100 py-2" id="btnSubmit">
            <i class="bi bi-send-check me-1"></i>Submit Exam
          </button>
        </div>
      </div>

      <div class="alert alert-light border small mb-0">
        <i class="bi bi-shield-check text-success me-1"></i>
        Your answers are saved automatically. Refreshing the page will not
        reset the timer or lose your work.
      </div>
    </div>
  </div>
</div>

<!-- ===================== SUBMIT CONFIRM MODAL ====================== -->
<div class="modal fade" id="submitModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-send-check me-2 text-success"></i>Submit Examination</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p>Please confirm your submission. Once submitted the paper cannot be reopened.</p>
        <div class="row g-2 text-center">
          <div class="col-4"><div class="stat-card p-2">
            <div class="value fs-4 text-success" id="mAnswered">0</div>
            <div class="label">Answered</div></div></div>
          <div class="col-4"><div class="stat-card p-2">
            <div class="value fs-4 text-danger" id="mUnanswered">0</div>
            <div class="label">Unanswered</div></div></div>
          <div class="col-4"><div class="stat-card p-2">
            <div class="value fs-4 text-primary" id="mReview">0</div>
            <div class="label">For Review</div></div></div>
        </div>
        <div class="alert alert-warning small mt-3 mb-0" id="unansweredWarn" hidden>
          <i class="bi bi-exclamation-triangle me-1"></i>
          You still have unanswered questions.
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
          Continue Exam
        </button>
        <button type="button" class="btn btn-success" id="btnConfirmSubmit">
          <i class="bi bi-check2-circle me-1"></i>Yes, Submit
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ====================== TIME OVER MODAL ========================== -->
<div class="modal fade" id="timeUpModal" tabindex="-1" data-bs-backdrop="static"
     data-bs-keyboard="false" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 text-center">
      <div class="modal-body p-4">
        <i class="bi bi-alarm text-danger display-4"></i>
        <h4 class="mt-3 mb-2">Time is over!</h4>
        <p class="text-muted-2 mb-0">Your examination is being submitted automatically...</p>
        <div class="spinner-border text-primary mt-3" role="status"></div>
      </div>
    </div>
  </div>
</div>

<script>
/* Configuration handed from PHP to the exam engine. No answer key here. */
window.EXAM_CONFIG = {
  attemptId:   <?= (int)$attemptId ?>,
  secondsLeft: <?= (int)$remaining ?>,
  csrfToken:   <?= json_encode(csrf_token()) ?>,
  questions:   <?= json_encode($jsState) ?>,
  urls: {
    save:   <?= json_encode(url('api/save_answer.php')) ?>,
    submit: <?= json_encode(url('api/submit_exam.php')) ?>,
    sync:   <?= json_encode(url('api/time_left.php')) ?>,
    result: <?= json_encode(url('result.php?attempt=' . $attemptId)) ?>
  }
};
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= url('assets/js/exam.js') ?>"></script>
</body>
</html>
