<?php
/**
 * Examination instructions.
 *
 *  - instructions.php               -> general rules + exam picker
 *  - instructions.php?exam_id=1     -> rules for that exam + "Start Exam"
 *
 * The Start button stays disabled until the acceptance checkbox is ticked.
 */
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Exam Instructions';
$activeNav = 'instructions';

$examId = get_int('exam_id');
$exam   = $examId ? get_exam($examId) : null;

if ($examId && (!$exam || !(int)$exam['is_active'])) {
    set_flash('danger', 'That examination is not available.');
    redirect('dashboard.php');
}

// An exam restricted to assigned students must not even be readable by others.
if ($exam && is_student_logged_in()
    && !student_can_attempt($exam, (int)$_SESSION['student_id'])) {
    set_flash('danger', 'You have not been assigned to that examination.');
    redirect('dashboard.php');
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-4 py-lg-5">
  <div class="row justify-content-center">
    <div class="col-lg-9">

      <?php if (!$exam): ?>
        <!-- ---------------- General instructions ---------------- -->
        <div class="section-head">
          <div class="feature-icon bg-soft-primary mx-auto"><i class="bi bi-info-circle"></i></div>
          <span class="eyebrow">Before you begin</span>
          <h2>General Examination Instructions</h2>
          <p class="text-muted-2 mb-0">Read these rules before attempting any paper.</p>
        </div>
      <?php else: ?>
        <div class="card border-0 mb-4 exam-card">
          <div class="exam-head">
            <div class="exam-head-top">
              <span class="chip chip-light">
                <i class="bi bi-bookmark-fill"></i><?= e($exam['subject']) ?>
              </span>
              <span class="chip chip-warning">
                <i class="bi bi-stopwatch"></i><?= (int)$exam['duration_minutes'] ?> min
              </span>
            </div>
            <h3 class="text-white mb-0"><?= e($exam['title']) ?></h3>
          </div>
          <div class="card-body">
            <p class="text-muted-2 mb-4"><?= e($exam['description']) ?></p>

            <div class="row g-3">
              <?php
              $summary = [
                ['list-ol',      'Total Questions', (int)$exam['question_count']],
                ['clock',        'Duration',        (int)$exam['duration_minutes'] . ' minutes'],
                ['award',        'Total Marks',     num((float)$exam['total_marks'])],
                ['trophy',       'Passing Marks',   num((float)$exam['passing_marks'])],
                ['plus-circle',  'Marks / Question', num((float)$exam['marks_per_question'])],
                ['dash-circle',  'Negative Marking',
                  (float)$exam['negative_marks'] > 0
                    ? '-' . num((float)$exam['negative_marks']) . ' per wrong answer'
                    : 'None'],
              ];
              if ((int)$exam['subjective_count'] > 0) {
                  $summary[] = ['pencil-square', 'Written Questions',
                                (int)$exam['subjective_count'] . ' of ' . (int)$exam['question_count']];
              }
              foreach ($summary as [$icon, $label, $value]): ?>
                <div class="col-6 col-lg-4">
                  <div class="stat-card h-100">
                    <i class="bi bi-<?= $icon ?> fs-4 text-primary"></i>
                    <div class="value fs-5 mt-1"><?= e($value) ?></div>
                    <div class="label"><?= e($label) ?></div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <!-- ---------------- Rules (always shown) ---------------- -->
      <div class="card border-0 mb-4">
        <div class="card-body p-4">
          <h5 class="mb-3"><i class="bi bi-1-circle text-primary me-2"></i>Before you begin</h5>
          <ul class="text-muted-2 mb-4">
            <li>Make sure you have a stable internet connection and enough battery.</li>
            <li>The timer starts the moment you press <strong>Start Exam</strong> and
                cannot be paused.</li>
            <li>Closing or refreshing the page does <strong>not</strong> reset the timer -
                the remaining time is tracked on the server.</li>
          </ul>

          <h5 class="mb-3"><i class="bi bi-2-circle text-primary me-2"></i>Answering questions</h5>
          <ul class="text-muted-2 mb-4">
            <li>One question is shown at a time and only one option may be selected.</li>
            <li>Your selection is saved automatically as soon as you choose it.</li>
            <?php if (!$exam || (int)$exam['subjective_count'] > 0): ?>
              <li><strong>Written (subjective) questions</strong> have a text box instead of
                  options. Your text is saved automatically a moment after you stop typing,
                  and these answers are marked by your faculty, so the final score for such a
                  paper appears only after evaluation.</li>
            <?php endif; ?>
            <li>Use <strong>Clear Answer</strong> to deselect the chosen option.</li>
            <li>Use <strong>Mark for Review</strong> to flag a question you want to revisit.
                A marked question is still evaluated if you have answered it.</li>
          </ul>

          <h5 class="mb-3"><i class="bi bi-3-circle text-primary me-2"></i>Navigation</h5>
          <ul class="text-muted-2 mb-4">
            <li><strong>Previous / Next</strong> move between questions.</li>
            <li><strong>Save &amp; Next</strong> stores the answer and moves ahead.</li>
            <li>The question palette on the right lets you jump straight to any question.</li>
          </ul>

          <h5 class="mb-3"><i class="bi bi-palette text-primary me-2"></i>Question palette colours</h5>
          <div class="d-flex flex-wrap gap-4 mb-4 small">
            <span><span class="legend-dot legend-not-visited"></span>Not Visited</span>
            <span><span class="legend-dot legend-answered"></span>Answered</span>
            <span><span class="legend-dot legend-not-answered"></span>Not Answered</span>
            <span><span class="legend-dot legend-review"></span>Marked for Review</span>
          </div>

          <h5 class="mb-3"><i class="bi bi-4-circle text-primary me-2"></i>Submission</h5>
          <ul class="text-muted-2 mb-0">
            <li>Press <strong>Submit Exam</strong> when you are done; a confirmation
                dialog will appear.</li>
            <li>When the timer reaches <strong>00:00</strong> the paper is submitted
                automatically.</li>
            <li>A submitted paper is final and cannot be reopened.</li>
            <li>Your score and detailed answer review appear immediately after submission.</li>
          </ul>
        </div>
      </div>

      <?php if ($exam): ?>
        <!-- ---------------- Accept + start ---------------- -->
        <div class="card border-0">
          <div class="card-body p-4">
            <?php if (!is_student_logged_in()): ?>
              <div class="alert alert-warning mb-3">
                <i class="bi bi-lock me-1"></i>
                Please <a href="<?= url('login.php') ?>" class="fw-semibold">log in</a>
                to start this examination.
              </div>
              <a href="<?= url('login.php') ?>" class="btn btn-primary w-100 py-2">Login to Continue</a>
            <?php else: ?>
              <form method="post" action="<?= url('start_exam.php') ?>" id="startExamForm">
                <?= csrf_field() ?>
                <input type="hidden" name="exam_id" value="<?= (int)$exam['id'] ?>">

                <div class="form-check mb-3 p-3 bg-light rounded border">
                  <input class="form-check-input ms-0 me-2" type="checkbox"
                         id="acceptTerms" name="accept" value="1">
                  <label class="form-check-label fw-semibold" for="acceptTerms">
                    I have read and understood all instructions.
                  </label>
                </div>

                <button type="submit" class="btn btn-primary w-100 py-2" id="startExamBtn" disabled>
                  <i class="bi bi-play-circle me-1"></i>Start Exam
                </button>
                <p class="small text-muted-2 text-center mt-3 mb-0">
                  The countdown begins as soon as you press this button.
                </p>
              </form>
            <?php endif; ?>
          </div>
        </div>
      <?php else: ?>
        <div class="text-center">
          <a href="<?= url('dashboard.php') ?>" class="btn btn-primary btn-hero">
            <i class="bi bi-journal-text me-1"></i>Choose an Examination
          </a>
        </div>
      <?php endif; ?>

    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
