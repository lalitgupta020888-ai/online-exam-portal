<?php
/**
 * Student exam dashboard.
 *
 * Shows ONLY the papers a faculty member (or the administrator) has allotted to
 * this student, grouped subject by subject. Nothing else is visible here.
 */
require_once __DIR__ . '/includes/auth.php';

$student = require_student();

// Any abandoned attempt whose time is over gets graded before we draw the page.
close_expired_attempts((int)$student['id']);

$pageTitle = 'Exam Dashboard';
$activeNav = 'exams';

$bySubject = get_student_exams_by_subject((int)$student['id']);
$examCount = array_sum(array_map('count', $bySubject));

// Live attempt (if the student walked away mid exam) per exam.
$live = db()->prepare(
    "SELECT exam_id, id FROM attempts
      WHERE student_id = ? AND status = 'in_progress'"
);
$live->execute([$student['id']]);
$inProgress = array_column($live->fetchAll(), 'id', 'exam_id');

// Best previous result per exam.
$prev = db()->prepare(
    'SELECT exam_id, MAX(percentage) AS best, COUNT(*) AS attempts
       FROM results WHERE student_id = ? GROUP BY exam_id'
);
$prev->execute([$student['id']]);
$history = [];
foreach ($prev->fetchAll() as $r) {
    $history[$r['exam_id']] = $r;
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-4 py-lg-5">

  <div class="page-head d-flex flex-wrap justify-content-between align-items-end mb-4 gap-3">
    <div>
      <span class="eyebrow">My Dashboard</span>
      <h2 class="mb-1">Hello, <?= e($student['name']) ?></h2>
      <p class="text-muted-2 mb-0">
        <?php if ($examCount): ?>
          You have <strong><?= $examCount ?></strong> paper<?= $examCount > 1 ? 's' : '' ?>
          allotted across <strong><?= count($bySubject) ?></strong>
          subject<?= count($bySubject) > 1 ? 's' : '' ?>.
        <?php else: ?>
          Your allotted examinations will appear here.
        <?php endif; ?>
      </p>
    </div>
    <a href="<?= url('results.php') ?>" class="btn btn-outline-primary">
      <i class="bi bi-bar-chart me-1"></i>My Results
    </a>
  </div>

  <?php if ($inProgress): ?>
    <div class="alert alert-warning d-flex align-items-center">
      <i class="bi bi-hourglass-split me-2 fs-5"></i>
      <div>You have an examination in progress. The timer is still running -
        resume it from its card below before the time runs out.</div>
    </div>
  <?php endif; ?>

  <?php if (!$examCount): ?>
    <div class="card empty-state text-center">
      <div class="empty-state-icon"><i class="bi bi-journal-bookmark"></i></div>
      <h5 class="mb-2">No examination has been allotted to you yet</h5>
      <p class="text-muted-2 mb-0 mx-auto" style="max-width:32rem">
        Your faculty decides which subject's paper you may attempt. As soon as a paper
        is allotted to you it will appear here, grouped by subject. You cannot see or
        start any other examination.
      </p>
    </div>
  <?php endif; ?>

  <?php foreach ($bySubject as $subject => $exams): ?>
    <section class="subject-block">
      <div class="subject-head">
        <div class="subject-mark"><?= e(mb_strtoupper(mb_substr($subject, 0, 1))) ?></div>
        <div>
          <h4 class="mb-0"><?= e($subject) ?></h4>
          <span class="small text-muted-2">
            <?= count($exams) ?> paper<?= count($exams) > 1 ? 's' : '' ?> allotted
          </span>
        </div>
        <span class="subject-rule"></span>
      </div>

      <div class="row g-4">
        <?php foreach ($exams as $exam):
          $eid     = (int)$exam['id'];
          $qCount  = (int)$exam['question_count'];
          $running = $inProgress[$eid] ?? null;
          $past    = $history[$eid] ?? null;
        ?>
          <div class="col-md-6 col-xl-4">
            <article class="card card-hover exam-card h-100">
              <div class="exam-head">
                <div class="exam-head-top">
                  <span class="chip chip-light">
                    <i class="bi bi-bookmark-fill"></i><?= e($exam['subject']) ?>
                  </span>
                  <?php if ($running): ?>
                    <span class="chip chip-warning">In progress</span>
                  <?php elseif ($past): ?>
                    <span class="chip chip-success">Attempted</span>
                  <?php endif; ?>
                </div>
                <h5 class="exam-title"><?= e($exam['title']) ?></h5>
                <?php if (!empty($exam['faculty_name'])): ?>
                  <div class="exam-faculty">
                    <i class="bi bi-person-video3"></i>Allotted by <?= e($exam['faculty_name']) ?>
                  </div>
                <?php endif; ?>
              </div>

              <div class="card-body d-flex flex-column">
                <?php if (trim((string)$exam['description']) !== ''): ?>
                  <p class="text-muted-2 small"><?= e($exam['description']) ?></p>
                <?php endif; ?>

                <div class="exam-meta mb-3">
                  <div class="meta-item"><span>Questions</span><strong><?= $qCount ?></strong></div>
                  <div class="meta-item"><span>Duration</span>
                    <strong><?= (int)$exam['duration_minutes'] ?> min</strong></div>
                  <div class="meta-item"><span>Total Marks</span>
                    <strong><?= num((float)$exam['total_marks']) ?></strong></div>
                  <div class="meta-item"><span>Passing Marks</span>
                    <strong><?= num((float)$exam['passing_marks']) ?></strong></div>
                </div>

                <ul class="exam-notes">
                  <?php if ((int)$exam['subjective_count'] > 0): ?>
                    <li><i class="bi bi-pencil-square text-warning"></i>
                      <?= (int)$exam['subjective_count'] ?> written question(s) -
                      marked by your faculty</li>
                  <?php endif; ?>
                  <li>
                    <?php if ((float)$exam['negative_marks'] > 0): ?>
                      <i class="bi bi-dash-circle text-danger"></i>
                      Negative marking: <?= num((float)$exam['negative_marks']) ?> per wrong answer
                    <?php else: ?>
                      <i class="bi bi-check-circle text-success"></i>No negative marking
                    <?php endif; ?>
                  </li>
                  <?php if ($past): ?>
                    <li><i class="bi bi-trophy text-primary"></i>
                      Best score <strong><?= num((float)$past['best']) ?>%</strong>
                      in <?= (int)$past['attempts'] ?> attempt<?= (int)$past['attempts'] > 1 ? 's' : '' ?></li>
                  <?php endif; ?>
                </ul>

                <div class="mt-auto pt-3">
                  <?php if ($qCount === 0): ?>
                    <button class="btn btn-secondary w-100" disabled>No questions added yet</button>
                  <?php elseif ($running): ?>
                    <a href="<?= url('exam.php?attempt=' . (int)$running) ?>"
                       class="btn btn-warning w-100 fw-bold">
                      <i class="bi bi-arrow-right-circle me-1"></i>Resume Exam
                    </a>
                  <?php else: ?>
                    <a href="<?= url('instructions.php?exam_id=' . $eid) ?>" class="btn btn-primary w-100">
                      <i class="bi bi-play-circle me-1"></i>Start Exam
                    </a>
                  <?php endif; ?>
                </div>
              </div>
            </article>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
