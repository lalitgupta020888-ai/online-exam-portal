<?php
/**
 * Faculty - full analysis of one exam across the whole class.
 *
 * Score distribution, topper list, pass/fail split and a per question
 * breakdown showing which questions the class actually struggled with.
 */
require_once __DIR__ . '/../includes/auth.php';
$faculty = require_faculty();

$examId = get_int('exam_id');
$exam   = get_owned_exam($examId, (int)$faculty['id']);
if (!$exam) {
    set_flash('danger', 'That exam does not belong to you.');
    redirect('faculty/results.php');
}

$progress = exam_progress($exam);
if (!$progress['results_visible']) {
    set_flash('warning', 'The analysis unlocks once all assigned students have completed the exam.');
    redirect('faculty/results.php?exam_id=' . $examId);
}

/* ---------------- Class level figures ---------------- */
$st = db()->prepare(
    'SELECT r.*, s.name AS student_name, s.email, a.submitted_at, a.started_at
       FROM results r
       JOIN students s ON s.id = r.student_id
       JOIN attempts a ON a.id = r.attempt_id
      WHERE r.exam_id = ?
   ORDER BY r.obtained_marks DESC'
);
$st->execute([$examId]);
$rows = $st->fetchAll();

$n      = count($rows);
$marks  = array_map(fn($r) => (float)$r['obtained_marks'], $rows);
$pcts   = array_map(fn($r) => (float)$r['percentage'], $rows);
$passed = count(array_filter($rows, fn($r) => $r['status'] === 'PASS'));
$total  = (float)$exam['total_marks'];

$avg = $n ? array_sum($marks) / $n : 0;
$high = $n ? max($marks) : 0;
$low  = $n ? min($marks) : 0;

// Median and standard deviation.
$sorted = $marks;
sort($sorted);
$median = 0.0;
if ($n) {
    $mid = intdiv($n, 2);
    $median = $n % 2 ? $sorted[$mid] : ($sorted[$mid - 1] + $sorted[$mid]) / 2;
}
$variance = 0.0;
foreach ($marks as $m) { $variance += ($m - $avg) ** 2; }
$stdDev = $n ? sqrt($variance / $n) : 0.0;

// Grade bands.
$bands = [
    ['90 - 100%', 90, 100.01, 'bg-success'],
    ['75 - 89%',  75, 90,     'bg-primary'],
    ['60 - 74%',  60, 75,     'bg-info'],
    ['40 - 59%',  40, 60,     'bg-warning'],
    ['Below 40%',  0, 40,     'bg-danger'],
];
$distribution = [];
foreach ($bands as [$label, $from, $to, $colour]) {
    $count = count(array_filter($pcts, fn($p) => $p >= $from && $p < $to));
    $distribution[] = [$label, $count, $colour, $n ? round($count / $n * 100) : 0];
}

/* ---------------- Per question breakdown ---------------- */
$qs = db()->prepare(
    "SELECT q.id, q.question_text, q.type, q.marks, q.difficulty, q.category,
            SUM(CASE WHEN a.is_correct = 1 THEN 1 ELSE 0 END) AS correct,
            SUM(CASE WHEN a.is_correct = 0 THEN 1 ELSE 0 END) AS wrong,
            SUM(CASE WHEN a.option_id IS NULL
                      AND TRIM(COALESCE(a.answer_text,'')) = '' THEN 1 ELSE 0 END) AS skipped,
            AVG(a.awarded_marks) AS avg_awarded,
            COUNT(a.id) AS responses
       FROM questions q
  LEFT JOIN answers a ON a.question_id = q.id
  LEFT JOIN attempts t ON t.id = a.attempt_id AND t.status <> 'in_progress'
      WHERE q.exam_id = ?
   GROUP BY q.id, q.question_text, q.type, q.marks, q.difficulty, q.category
   ORDER BY q.sort_order, q.id"
);
$qs->execute([$examId]);
$questions = $qs->fetchAll();

$pageTitle = 'Analysis - ' . $exam['title'];
$activeNav = 'results';
require_once __DIR__ . '/includes/faculty_header.php';
?>

<div class="card border-0 mb-3">
  <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div>
      <h5 class="mb-1"><?= e($exam['title']) ?></h5>
      <div class="small text-muted-2">
        <?= e($exam['subject']) ?> &middot; <?= (int)$exam['question_count'] ?> questions
        &middot; <?= num($total) ?> marks &middot; passing <?= num((float)$exam['passing_marks']) ?>
      </div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-primary" href="<?= url('faculty/results.php?exam_id=' . $examId) ?>">
        <i class="bi bi-table me-1"></i>Result Sheet
      </a>
      <a class="btn btn-outline-secondary" target="_blank" rel="noopener"
         href="<?= url('faculty/print_results.php?exam_id=' . $examId) ?>">
        <i class="bi bi-printer me-1"></i>Print / PDF
      </a>
    </div>
  </div>
</div>

<?php if (!$n): ?>
  <div class="card border-0 p-5 text-center">
    <i class="bi bi-clipboard-x display-4 text-muted-2"></i>
    <h5 class="mt-3">No papers submitted yet</h5>
  </div>
<?php else: ?>

<div class="row g-3 mb-4">
  <?php foreach ([
    ['people',        'text-primary',  'Students',     $n],
    ['patch-check',   'text-success',  'Pass Rate',    ($n ? round($passed / $n * 100, 1) : 0) . '%'],
    ['graph-up',      'text-info',     'Average',      num((float)$avg) . ' / ' . num($total)],
    ['align-middle',  'text-secondary','Median',       num((float)$median)],
    ['trophy',        'text-warning',  'Highest',      num((float)$high)],
    ['arrow-down',    'text-danger',   'Lowest',       num((float)$low)],
    ['distribute-vertical', 'text-dark', 'Std Deviation', num((float)$stdDev)],
  ] as [$icon, $tone, $label, $value]): ?>
    <div class="col-6 col-lg-3 col-xl-2">
      <div class="stat-card h-100">
        <i class="bi bi-<?= $icon ?> fs-4 <?= $tone ?>"></i>
        <div class="value mt-1 fs-5"><?= e($value) ?></div>
        <div class="label"><?= e($label) ?></div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="row g-4 mb-4">
  <!-- --------------- Score distribution --------------- -->
  <div class="col-lg-7">
    <div class="card border-0 h-100">
      <div class="card-body">
        <h6 class="mb-3"><i class="bi bi-bar-chart me-1"></i>Score Distribution</h6>
        <?php foreach ($distribution as [$label, $count, $colour, $pct]): ?>
          <div class="mb-3">
            <div class="d-flex justify-content-between small mb-1">
              <span class="fw-semibold"><?= e($label) ?></span>
              <span class="text-muted-2"><?= $count ?> student(s) &middot; <?= $pct ?>%</span>
            </div>
            <div class="progress" style="height:18px">
              <div class="progress-bar <?= $colour ?>" style="width:<?= max(2, $pct) ?>%">
                <?= $count > 0 ? $count : '' ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- --------------- Pass / fail + toppers --------------- -->
  <div class="col-lg-5">
    <div class="card border-0 h-100">
      <div class="card-body">
        <h6 class="mb-3"><i class="bi bi-trophy me-1"></i>Pass / Fail and Toppers</h6>

        <div class="score-ring mb-4" style="--pct:<?= $n ? round($passed / $n * 100, 2) : 0 ?>;--ring-color:#16a34a">
          <div class="inner">
            <div>
              <div class="pct"><?= $n ? round($passed / $n * 100) : 0 ?>%</div>
              <div class="small text-muted-2 mt-1"><?= $passed ?> of <?= $n ?> passed</div>
            </div>
          </div>
        </div>

        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead><tr><th>#</th><th>Student</th><th>Marks</th><th>%</th></tr></thead>
            <tbody>
              <?php foreach (array_slice($rows, 0, 5) as $i => $r): ?>
                <tr>
                  <td>
                    <?php if ($i === 0): ?><i class="bi bi-trophy-fill text-warning"></i>
                    <?php else: ?><?= $i + 1 ?><?php endif; ?>
                  </td>
                  <td class="small fw-semibold"><?= e($r['student_name']) ?></td>
                  <td class="small"><?= num((float)$r['obtained_marks']) ?></td>
                  <td class="small"><?= num((float)$r['percentage']) ?>%</td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- --------------- Per question analysis --------------- -->
<div class="card border-0">
  <div class="card-body">
    <h6 class="mb-1"><i class="bi bi-list-check me-1"></i>Question by Question</h6>
    <p class="small text-muted-2">
      The success rate shows how much of the available marks the class earned on each
      question - the low ones are the topics worth revisiting.
    </p>

    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead>
          <tr>
            <th>#</th><th>Question</th><th>Type</th><th>Correct</th>
            <th>Wrong</th><th>Skipped</th><th style="min-width:160px">Success rate</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($questions as $i => $q):
              $responses = (int)$q['responses'];
              // For objective questions the rate is correct/responses; for written
              // ones it is the average awarded out of the maximum.
              if ($q['type'] === 'subjective') {
                  $rate = (float)$q['marks'] > 0 && $q['avg_awarded'] !== null
                          ? (float)$q['avg_awarded'] / (float)$q['marks'] * 100 : 0;
              } else {
                  $rate = $responses > 0 ? (int)$q['correct'] / $responses * 100 : 0;
              }
              $tone = $rate >= 70 ? 'bg-success' : ($rate >= 40 ? 'bg-warning' : 'bg-danger');
          ?>
            <tr>
              <td class="text-muted-2"><?= $i + 1 ?></td>
              <td style="min-width:260px">
                <div class="small fw-semibold">
                  <?= e(mb_strimwidth($q['question_text'], 0, 80, '...')) ?>
                </div>
                <?php if ($q['category']): ?>
                  <span class="badge bg-light text-muted-2 border"><?= e($q['category']) ?></span>
                <?php endif; ?>
                <span class="badge bg-light text-muted-2 border"><?= e(ucfirst($q['difficulty'])) ?></span>
              </td>
              <td>
                <span class="badge bg-secondary-subtle text-secondary-emphasis">
                  <?= $q['type'] === 'subjective' ? 'Written'
                       : ($q['type'] === 'mcq' ? 'MCQ' : 'T/F') ?>
                </span>
              </td>
              <td class="text-success fw-semibold">
                <?= $q['type'] === 'subjective' ? '-' : (int)$q['correct'] ?>
              </td>
              <td class="text-danger fw-semibold">
                <?= $q['type'] === 'subjective' ? '-' : (int)$q['wrong'] ?>
              </td>
              <td class="text-warning fw-semibold"><?= (int)$q['skipped'] ?></td>
              <td>
                <div class="d-flex align-items-center gap-2">
                  <div class="progress flex-grow-1" style="height:8px">
                    <div class="progress-bar <?= $tone ?>" style="width:<?= round($rate) ?>%"></div>
                  </div>
                  <span class="small fw-semibold"><?= round($rate) ?>%</span>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/faculty_footer.php'; ?>
