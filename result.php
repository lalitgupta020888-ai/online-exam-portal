<?php
/**
 * Result dashboard for a single attempt.
 */
require_once __DIR__ . '/includes/auth.php';

$student   = require_student();
$attemptId = get_int('attempt');
$attempt   = get_owned_attempt($attemptId, (int)$student['id']);

if (!$attempt) {
    set_flash('danger', 'Result not found.');
    redirect('results.php');
}

// Still running? Send the student back to finish it.
if ($attempt['status'] === 'in_progress') {
    if (seconds_left($attempt) > 0) {
        redirect('exam.php?attempt=' . $attemptId);
    }
    grade_attempt($attemptId, 'auto_submitted');
}

$st = db()->prepare('SELECT * FROM results WHERE attempt_id = ?');
$st->execute([$attemptId]);
$result = $st->fetch();

if (!$result) {
    $result = grade_attempt($attemptId, 'submitted');
}

$exam      = get_exam((int)$attempt['exam_id']);
$pending   = (int)($result['pending_evaluation'] ?? 0) === 1;
$passed    = $result['status'] === 'PASS';
$pct       = (float)$result['percentage'];
$ringColor = $pending ? '#f59e0b' : ($passed ? '#16a34a' : '#dc2626');

// Time actually used.
$used = strtotime($attempt['submitted_at']) - strtotime($attempt['started_at']);

$pageTitle = 'Result';
$activeNav = 'results';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-4 py-lg-5">

  <?php if ($pending): ?>
    <div class="alert alert-warning d-flex align-items-start gap-2">
      <i class="bi bi-hourglass-split fs-4"></i>
      <div>
        <strong>Your written answers are still being evaluated.</strong>
        The marks shown below cover the objective questions only
        (<?= num((float)$result['objective_marks']) ?> of
        <?= num((float)$result['total_marks'] - (float)$result['subjective_total']) ?>).
        Your final score and pass/fail result will appear here once your faculty
        has marked the written answers.
      </div>
    </div>
  <?php endif; ?>

  <!-- ---------------------- Headline banner ---------------------- -->
  <div class="card border-0 mb-4 overflow-hidden">
    <div class="result-banner"
         style="background:linear-gradient(135deg,<?= $pending ? '#5c3406,#d97706'
                                                    : ($passed ? '#064e3b,#15803d' : '#7f1d1d,#b91c1c') ?>)">
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
          <div class="small opacity-75 mb-1">
            <i class="bi bi-bookmark-fill me-1"></i><?= e($exam['subject']) ?>
          </div>
          <h3 class="mb-1 text-white"><?= e($exam['title']) ?></h3>
          <div class="small opacity-75">
            <i class="bi bi-person-circle me-1"></i><?= e($student['name']) ?>
            &nbsp;|&nbsp;
            <i class="bi bi-calendar-event me-1"></i><?= e(format_datetime($attempt['submitted_at'])) ?>
          </div>
        </div>
        <div class="text-center">
          <span class="result-badge bg-white <?= $pending ? 'text-warning'
                                                : ($passed ? 'text-success' : 'text-danger') ?>">
            <?= $pending ? 'AWAITING EVALUATION' : ($passed ? 'PASS' : 'FAIL') ?>
          </span>
          <?php if ($attempt['status'] === 'auto_submitted'): ?>
            <div class="small mt-2 opacity-75">
              <i class="bi bi-alarm me-1"></i>Auto submitted (time over)
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-4">
    <!-- ------------------------ Score ring ------------------------ -->
    <div class="col-lg-4">
      <div class="card border-0 h-100">
        <div class="card-body text-center p-4">
          <h6 class="text-muted-2 mb-4">Overall Score</h6>
          <div class="score-ring" style="--pct:<?= e($pct) ?>;--ring-color:<?= $ringColor ?>">
            <div class="inner">
              <div>
                <div class="pct"><?= num($pct) ?>%</div>
                <div class="small text-muted-2 mt-1">
                  <?= num((float)$result['obtained_marks']) ?> / <?= num((float)$result['total_marks']) ?> marks
                </div>
              </div>
            </div>
          </div>

          <div class="mt-4 text-start">
            <div class="d-flex justify-content-between small mb-1">
              <span class="text-muted-2">Marks obtained</span>
              <span class="fw-semibold"><?= num($pct) ?>%</span>
            </div>
            <div class="progress">
              <div class="progress-bar <?= $passed ? 'bg-success' : 'bg-danger' ?>"
                   role="progressbar" style="width:<?= e($pct) ?>%"
                   aria-valuenow="<?= e($pct) ?>" aria-valuemin="0" aria-valuemax="100"></div>
            </div>
            <div class="small text-muted-2 mt-2">
              Passing marks: <strong><?= num((float)$exam['passing_marks']) ?></strong>
              (<?= num((float)$exam['total_marks'] > 0
                    ? (float)$exam['passing_marks'] / (float)$exam['total_marks'] * 100 : 0) ?>%)
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ------------------------ Summary cards --------------------- -->
    <div class="col-lg-8">
      <div class="row g-3">
        <?php
        $cards = [
          ['list-ol',        'text-primary', 'Total Questions', (int)$result['total_questions']],
          ['pencil-square',  'text-info',    'Attempted',       (int)$result['attempted']],
          ['check-circle',   'text-success', 'Correct',         (int)$result['correct_count']],
          ['x-circle',       'text-danger',  'Incorrect',       (int)$result['wrong_count']],
          ['dash-circle',    'text-warning', 'Unanswered',      (int)$result['unanswered_count']],
          ['award',          'text-primary', 'Obtained Marks',  num((float)$result['obtained_marks'])],
          ['trophy',         'text-secondary','Total Marks',    num((float)$result['total_marks'])],
          ['stopwatch',      'text-dark',    'Time Taken',      format_seconds(max(0, (int)$used))],
        ];
        // Only worth showing the split when the paper actually had written questions.
        if ((float)$result['subjective_total'] > 0) {
          $cards[] = ['ui-checks', 'text-primary', 'Objective Marks',
                      num((float)$result['objective_marks'])];
          $cards[] = ['pen', 'text-warning', 'Written Marks',
                      $pending ? 'Pending'
                               : num((float)$result['subjective_marks']) . ' / '
                                 . num((float)$result['subjective_total'])];
        }
        foreach ($cards as [$icon, $tone, $label, $value]): ?>
          <div class="col-6 col-md-3">
            <div class="stat-card h-100">
              <i class="bi bi-<?= $icon ?> fs-4 <?= $tone ?>"></i>
              <div class="value mt-1"><?= e($value) ?></div>
              <div class="label"><?= e($label) ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- ---------------------- Detail table ---------------------- -->
      <div class="card border-0 mt-4">
        <div class="card-body">
          <h6 class="mb-3"><i class="bi bi-clipboard-data me-1"></i>Result Details</h6>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <tbody>
                <tr><th class="text-muted-2 fw-normal">Student Name</th>
                    <td class="fw-semibold"><?= e($student['name']) ?></td></tr>
                <tr><th class="text-muted-2 fw-normal">Examination</th>
                    <td class="fw-semibold"><?= e($exam['title']) ?></td></tr>
                <tr><th class="text-muted-2 fw-normal">Subject</th>
                    <td><?= e($exam['subject']) ?></td></tr>
                <tr><th class="text-muted-2 fw-normal">Started At</th>
                    <td><?= e(format_datetime($attempt['started_at'])) ?></td></tr>
                <tr><th class="text-muted-2 fw-normal">Submitted At</th>
                    <td><?= e(format_datetime($attempt['submitted_at'])) ?></td></tr>
                <tr><th class="text-muted-2 fw-normal">Negative Marking</th>
                    <td><?= (float)$exam['negative_marks'] > 0
                            ? '-' . num((float)$exam['negative_marks']) . ' per wrong answer'
                            : 'Not applicable' ?></td></tr>
                <tr><th class="text-muted-2 fw-normal">Percentage</th>
                    <td class="fw-semibold"><?= num($pct) ?>%</td></tr>
                <tr><th class="text-muted-2 fw-normal">Result</th>
                    <td><span class="badge <?= $passed ? 'bg-success' : 'bg-danger' ?>">
                        <?= $passed ? 'PASS' : 'FAIL' ?></span></td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- --------------------------- Actions ------------------------- -->
  <div class="d-flex flex-wrap gap-2 justify-content-center mt-4 no-print">
    <a href="<?= url('review.php?attempt=' . $attemptId) ?>" class="btn btn-primary">
      <i class="bi bi-search me-1"></i>Review Answers
    </a>
    <a href="<?= url('instructions.php?exam_id=' . (int)$exam['id']) ?>" class="btn btn-outline-primary">
      <i class="bi bi-arrow-repeat me-1"></i>Retake Exam
    </a>
    <a href="<?= url('download_result.php?attempt=' . $attemptId) ?>"
       class="btn btn-outline-secondary" target="_blank" rel="noopener">
      <i class="bi bi-download me-1"></i>Download Result
    </a>
    <a href="<?= url('dashboard.php') ?>" class="btn btn-outline-secondary">
      <i class="bi bi-grid me-1"></i>Back to Dashboard
    </a>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
