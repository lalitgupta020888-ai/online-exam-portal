<?php
/**
 * Admin - read only view of a complete question paper.
 *
 * Shows exactly what the faculty set: every question with its options, the
 * correct answer, the explanation and the model answer, plus how the class
 * actually performed on each question. Nothing here can be edited - the
 * administrator supervises papers, the faculty writes them.
 */
require_once __DIR__ . '/../includes/auth.php';
$admin     = require_admin();
$collegeId = (int)$admin['college_id'];

$examId = get_int('exam_id');
$exam   = get_exam($examId);
if ($exam && (int)$exam['college_id'] !== $collegeId) { $exam = null; }
if (!$exam) {
    set_flash('danger', 'Question paper not found.');
    redirect('admin/papers.php');
}

$owner = null;
if ($exam['faculty_id']) {
    $st = db()->prepare('SELECT * FROM faculty WHERE id = ?');
    $st->execute([$exam['faculty_id']]);
    $owner = $st->fetch() ?: null;
}

/* Questions with how the class did on each of them. */
$st = db()->prepare(
    "SELECT q.*,
            SUM(CASE WHEN a.is_correct = 1 THEN 1 ELSE 0 END) AS correct,
            SUM(CASE WHEN a.is_correct = 0 THEN 1 ELSE 0 END) AS wrong,
            SUM(CASE WHEN a.option_id IS NULL
                      AND TRIM(COALESCE(a.answer_text,'')) = '' THEN 1 ELSE 0 END) AS skipped,
            AVG(a.awarded_marks) AS avg_awarded,
            COUNT(a.id) AS responses
       FROM questions q
  LEFT JOIN answers a  ON a.question_id = q.id
  LEFT JOIN attempts t ON t.id = a.attempt_id AND t.status <> 'in_progress'
      WHERE q.exam_id = ?
   GROUP BY q.id
   ORDER BY q.sort_order, q.id"
);
$st->execute([$examId]);
$questions = $st->fetchAll();

$optStmt = db()->prepare('SELECT * FROM options WHERE question_id = ? ORDER BY option_order, id');

$progress = exam_progress($exam);
$letters  = ['A', 'B', 'C', 'D', 'E', 'F'];

$pageTitle = 'Question Paper - ' . $exam['title'];
$activeNav = 'papers';
require_once __DIR__ . '/includes/admin_header.php';
?>

<div class="card border-0 mb-3">
  <div class="card-body">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
      <div>
        <span class="eyebrow">Question paper</span>
        <h4 class="mb-1"><?= e($exam['title']) ?></h4>
        <div class="small text-muted-2">
          <i class="bi bi-bookmark me-1"></i><?= e($exam['subject']) ?>
          &middot; <?= (int)$exam['duration_minutes'] ?> min
          &middot; <?= num((float)$exam['total_marks']) ?> marks
          &middot; pass <?= num((float)$exam['passing_marks']) ?>
          <?php if ((float)$exam['negative_marks'] > 0): ?>
            &middot; -<?= num((float)$exam['negative_marks']) ?> per wrong answer
          <?php endif; ?>
        </div>
        <?php if ($owner): ?>
          <div class="mt-2 d-flex align-items-center gap-2">
            <span class="avatar-sm"><?= e(initials($owner['name'])) ?></span>
            <div class="small">
              Set by
              <a href="<?= url('admin/faculty_detail.php?id=' . (int)$owner['id']) ?>"
                 class="fw-semibold"><?= e($owner['name']) ?></a>
              <span class="text-muted-2">
                &middot; <?= e($owner['designation'] ?: 'Faculty') ?>
                <?php if ($owner['department']): ?>, <?= e($owner['department']) ?><?php endif; ?>
              </span>
            </div>
          </div>
        <?php else: ?>
          <div class="mt-2">
            <span class="badge bg-warning text-dark">No faculty owner</span>
            <span class="small text-muted-2">
              Transfer it to a faculty member so they can maintain it.
            </span>
          </div>
        <?php endif; ?>
      </div>

      <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-secondary" href="<?= url('admin/papers.php') ?>">
          <i class="bi bi-arrow-left me-1"></i>All papers
        </a>
        <a class="btn btn-outline-primary" href="<?= url('admin/results.php?exam_id=' . $examId) ?>">
          <i class="bi bi-bar-chart me-1"></i>Results
        </a>
        <button class="btn btn-outline-secondary no-print" onclick="window.print()">
          <i class="bi bi-printer me-1"></i>Print
        </button>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <?php foreach ([
    ['list-ol',        'text-primary', 'Questions',   (int)$exam['question_count']],
    ['ui-checks',      'text-info',    'Objective',
      (int)$exam['question_count'] - (int)$exam['subjective_count']],
    ['pencil-square',  'text-warning', 'Written',     (int)$exam['subjective_count']],
    ['people',         'text-secondary','Allotted',   $progress['assigned']],
    ['check2-circle',  'text-success', 'Completed',   $progress['completed']],
    ['hourglass-split','text-danger',  'Unmarked',    $progress['pending_eval']],
  ] as [$icon, $tone, $label, $value]): ?>
    <div class="col-6 col-lg-2">
      <div class="stat-card h-100">
        <i class="bi bi-<?= $icon ?> fs-4 <?= $tone ?>"></i>
        <div class="value mt-1"><?= $value ?></div>
        <div class="label"><?= e($label) ?></div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="alert alert-info d-flex align-items-start gap-2 no-print">
  <i class="bi bi-eye fs-5"></i>
  <div>
    This is a <strong>read only</strong> view. Questions are written and edited by the
    faculty who owns the paper.
  </div>
</div>

<?php foreach ($questions as $i => $q):
    $isSub = $q['type'] === 'subjective';
    $responses = (int)$q['responses'];
    if ($isSub) {
        $rate = (float)$q['marks'] > 0 && $q['avg_awarded'] !== null
                ? (float)$q['avg_awarded'] / (float)$q['marks'] * 100 : null;
    } else {
        $rate = $responses > 0 ? (int)$q['correct'] / $responses * 100 : null;
    }
?>
  <div class="card border-0 mb-3">
    <div class="card-body">
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div class="d-flex align-items-center gap-2">
          <span class="q-number"><?= $i + 1 ?></span>
          <div>
            <span class="badge <?= $isSub ? 'bg-warning-subtle text-warning-emphasis'
                  : ($q['type'] === 'mcq' ? 'bg-primary-subtle text-primary-emphasis'
                                          : 'bg-info-subtle text-info-emphasis') ?>">
              <?= $isSub ? 'Written' : ($q['type'] === 'mcq' ? 'Multiple choice' : 'True / False') ?>
            </span>
            <span class="badge bg-light text-muted-2 border"><?= e(ucfirst($q['difficulty'])) ?></span>
            <?php if ($q['category']): ?>
              <span class="badge bg-light text-muted-2 border"><?= e($q['category']) ?></span>
            <?php endif; ?>
            <span class="small text-muted-2 ms-1"><?= num((float)$q['marks']) ?> marks</span>
          </div>
        </div>

        <?php if ($rate !== null): ?>
          <div style="min-width:170px">
            <div class="d-flex justify-content-between small mb-1">
              <span class="text-muted-2">Class success</span>
              <span class="fw-semibold"><?= round($rate) ?>%</span>
            </div>
            <div class="progress" style="height:6px">
              <div class="progress-bar <?= $rate >= 70 ? 'bg-success'
                    : ($rate >= 40 ? 'bg-warning' : 'bg-danger') ?>"
                   style="width:<?= round($rate) ?>%"></div>
            </div>
            <?php if (!$isSub): ?>
              <div class="small text-muted-2 mt-1">
                <?= (int)$q['correct'] ?> right &middot; <?= (int)$q['wrong'] ?> wrong
                &middot; <?= (int)$q['skipped'] ?> skipped
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>

      <p class="question-text mb-3"><?= e($q['question_text']) ?></p>

      <?php if ($isSub): ?>
        <?php if ($q['model_answer']): ?>
          <div class="explanation-box mb-2">
            <strong><i class="bi bi-key text-primary me-1"></i>Model answer set by the faculty:</strong>
            <div style="white-space:pre-wrap"><?= e($q['model_answer']) ?></div>
          </div>
        <?php else: ?>
          <div class="alert alert-warning py-2 small mb-2">
            <i class="bi bi-exclamation-triangle me-1"></i>
            No model answer was set for this written question - marking is entirely
            at the faculty's discretion.
          </div>
        <?php endif; ?>
        <?php if ((int)$q['max_words'] > 0): ?>
          <div class="small text-muted-2">Word limit: <?= (int)$q['max_words'] ?></div>
        <?php endif; ?>

      <?php else:
        $optStmt->execute([$q['id']]);
        foreach ($optStmt->fetchAll() as $k => $o): ?>
          <div class="review-option <?= (int)$o['is_correct'] === 1 ? 'is-correct' : '' ?>">
            <span class="option-key"><?= $letters[$k] ?? ($k + 1) ?>.</span>
            <span class="flex-grow-1"><?= e($o['option_text']) ?></span>
            <?php if ((int)$o['is_correct'] === 1): ?>
              <span class="badge bg-success">Correct answer</span>
            <?php endif; ?>
          </div>
        <?php endforeach;
      endif; ?>

      <?php if ($q['explanation']): ?>
        <div class="explanation-box mt-3">
          <strong><i class="bi bi-lightbulb text-warning me-1"></i>Explanation shown to students:</strong>
          <?= e($q['explanation']) ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>

<?php if (!$questions): ?>
  <div class="card border-0 empty-state text-center">
    <div class="empty-state-icon"><i class="bi bi-journal-x"></i></div>
    <h5 class="mb-1">This paper has no questions yet</h5>
    <p class="text-muted-2 mb-0">The faculty who owns it has not added any question.</p>
  </div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
