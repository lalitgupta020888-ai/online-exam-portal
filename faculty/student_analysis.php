<?php
/**
 * Faculty - the complete record of one student: every exam attempted, the
 * marks trend, subject wise strength and topic wise accuracy.
 */
require_once __DIR__ . '/../includes/auth.php';
$faculty = require_faculty();

$studentId = get_int('student_id');
$st = db()->prepare('SELECT * FROM students WHERE id = ?');
$st->execute([$studentId]);
$student = $st->fetch();

if (!$student) {
    set_flash('danger', 'Student not found.');
    redirect('faculty/students.php');
}

/* ---------------- Every result of this student ---------------- */
$st = db()->prepare(
    'SELECT r.*, e.title, e.subject, e.faculty_id, e.passing_marks,
            a.started_at, a.submitted_at, a.status AS attempt_status,
            f.name AS faculty_name
       FROM results r
       JOIN exams e    ON e.id = r.exam_id
       JOIN attempts a ON a.id = r.attempt_id
  LEFT JOIN faculty f  ON f.id = e.faculty_id
      WHERE r.student_id = ?
   ORDER BY a.submitted_at ASC'
);
$st->execute([$studentId]);
$results = $st->fetchAll();

$n         = count($results);
$pcts      = array_map(fn($r) => (float)$r['percentage'], $results);
$passed    = count(array_filter($results, fn($r) => $r['status'] === 'PASS'));
$avg       = $n ? array_sum($pcts) / $n : 0;
$best      = $n ? max($pcts) : 0;
$worst     = $n ? min($pcts) : 0;
$totalQ    = array_sum(array_map(fn($r) => (int)$r['total_questions'], $results));
$totalC    = array_sum(array_map(fn($r) => (int)$r['correct_count'], $results));
$totalW    = array_sum(array_map(fn($r) => (int)$r['wrong_count'], $results));
$totalS    = array_sum(array_map(fn($r) => (int)$r['unanswered_count'], $results));
$accuracy  = ($totalC + $totalW) > 0 ? $totalC / ($totalC + $totalW) * 100 : 0;

// Improving or slipping? Compare the first half with the second half.
$trend = 'steady';
if ($n >= 4) {
    $half   = intdiv($n, 2);
    $first  = array_sum(array_slice($pcts, 0, $half)) / $half;
    $second = array_sum(array_slice($pcts, $half)) / ($n - $half);
    if ($second - $first > 5)      { $trend = 'improving'; }
    elseif ($first - $second > 5)  { $trend = 'declining'; }
}

/* ---------------- Subject wise ---------------- */
$bySubject = [];
foreach ($results as $r) {
    $key = $r['subject'];
    $bySubject[$key] ??= ['n' => 0, 'sum' => 0.0, 'passed' => 0];
    $bySubject[$key]['n']++;
    $bySubject[$key]['sum'] += (float)$r['percentage'];
    if ($r['status'] === 'PASS') { $bySubject[$key]['passed']++; }
}

/* ---------------- Topic wise accuracy (objective answers) ---------------- */
$st = db()->prepare(
    "SELECT COALESCE(NULLIF(q.category,''),'Uncategorised') AS topic,
            COUNT(*) AS attempted,
            SUM(CASE WHEN a.is_correct = 1 THEN 1 ELSE 0 END) AS correct
       FROM answers a
       JOIN questions q ON q.id = a.question_id
       JOIN attempts t  ON t.id = a.attempt_id
      WHERE t.student_id = ? AND t.status <> 'in_progress'
        AND q.type <> 'subjective' AND a.option_id IS NOT NULL
   GROUP BY topic
   ORDER BY attempted DESC"
);
$st->execute([$studentId]);
$topics = $st->fetchAll();

$pageTitle = 'Analysis - ' . $student['name'];
$activeNav = 'students';
require_once __DIR__ . '/includes/faculty_header.php';
?>

<div class="card border-0 mb-3">
  <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
    <div class="d-flex align-items-center gap-3">
      <div class="feature-icon bg-soft-primary mb-0"><i class="bi bi-person"></i></div>
      <div>
        <h5 class="mb-1"><?= e($student['name']) ?></h5>
        <div class="small text-muted-2">
          <?= e($student['email']) ?>
          <?php if ($student['phone']): ?> &middot; <?= e($student['phone']) ?><?php endif; ?>
          &middot; registered <?= e(format_datetime($student['created_at'])) ?>
        </div>
      </div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary" target="_blank" rel="noopener"
         href="<?= url('faculty/print_results.php?student_id=' . $studentId) ?>">
        <i class="bi bi-printer me-1"></i>Print / PDF
      </a>
      <a class="btn btn-outline-primary" href="<?= url('faculty/students.php') ?>">
        <i class="bi bi-arrow-left me-1"></i>Back
      </a>
    </div>
  </div>
</div>

<?php if (!$n): ?>
  <div class="card border-0 p-5 text-center">
    <i class="bi bi-clipboard-x display-4 text-muted-2"></i>
    <h5 class="mt-3 mb-1">This student has not completed any exam yet</h5>
  </div>
<?php else: ?>

<div class="row g-3 mb-4">
  <?php
  $trendIcon = ['improving' => ['arrow-up-right', 'text-success', 'Improving'],
                'declining' => ['arrow-down-right', 'text-danger', 'Declining'],
                'steady'    => ['arrow-right', 'text-secondary', 'Steady']][$trend];
  foreach ([
    ['journal-check', 'text-primary', 'Exams Taken',   $n],
    ['patch-check',   'text-success', 'Passed',        $passed . ' / ' . $n],
    ['graph-up',      'text-info',    'Average',       num((float)$avg) . '%'],
    ['trophy',        'text-warning', 'Best',          num((float)$best) . '%'],
    ['arrow-down',    'text-danger',  'Lowest',        num((float)$worst) . '%'],
    ['bullseye',      'text-dark',    'Accuracy',      num((float)$accuracy) . '%'],
    [$trendIcon[0],   $trendIcon[1],  'Trend',         $trendIcon[2]],
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
  <!-- ---------------- Marks trend ---------------- -->
  <div class="col-lg-7">
    <div class="card border-0 h-100">
      <div class="card-body">
        <h6 class="mb-3"><i class="bi bi-activity me-1"></i>Performance over time</h6>
        <?php
        // A simple inline SVG line chart - no chart library needed.
        $w = 640; $h = 200; $pad = 30;
        $count = max(1, $n - 1);
        $points = [];
        foreach ($pcts as $i => $p) {
            $x = $pad + ($n > 1 ? ($i / $count) * ($w - 2 * $pad) : ($w - 2 * $pad) / 2);
            $y = $h - $pad - ($p / 100) * ($h - 2 * $pad);
            $points[] = [round($x, 1), round($y, 1)];
        }
        $path = implode(' ', array_map(fn($pt) => $pt[0] . ',' . $pt[1], $points));
        ?>
        <div style="overflow-x:auto">
          <svg viewBox="0 0 <?= $w ?> <?= $h ?>" width="100%" height="200"
               role="img" aria-label="Percentage scored in each exam over time">
            <?php foreach ([0, 25, 50, 75, 100] as $g):
                $gy = $h - $pad - ($g / 100) * ($h - 2 * $pad); ?>
              <line x1="<?= $pad ?>" y1="<?= $gy ?>" x2="<?= $w - $pad ?>" y2="<?= $gy ?>"
                    stroke="#e2e8f0" stroke-width="1"></line>
              <text x="2" y="<?= $gy + 4 ?>" font-size="10" fill="#94a3b8"><?= $g ?>%</text>
            <?php endforeach; ?>

            <?php if ($n > 1): ?>
              <polyline points="<?= e($path) ?>" fill="none" stroke="#2563eb" stroke-width="2.5"
                        stroke-linejoin="round" stroke-linecap="round"></polyline>
            <?php endif; ?>

            <?php foreach ($points as $i => $pt): ?>
              <circle cx="<?= $pt[0] ?>" cy="<?= $pt[1] ?>" r="4"
                      fill="<?= $results[$i]['status'] === 'PASS' ? '#16a34a' : '#dc2626' ?>">
                <title><?= e($results[$i]['title'] . ': ' . num($pcts[$i]) . '%') ?></title>
              </circle>
            <?php endforeach; ?>
          </svg>
        </div>
        <div class="small text-muted-2 text-center">
          Each point is one exam, oldest on the left.
          <span class="text-success">Green = pass</span>,
          <span class="text-danger">red = fail</span>.
        </div>
      </div>
    </div>
  </div>

  <!-- ---------------- Subject wise ---------------- -->
  <div class="col-lg-5">
    <div class="card border-0 h-100">
      <div class="card-body">
        <h6 class="mb-3"><i class="bi bi-bookmarks me-1"></i>Subject wise</h6>
        <?php foreach ($bySubject as $subject => $d):
            $sAvg = $d['sum'] / $d['n'];
            $tone = $sAvg >= 60 ? 'bg-success' : ($sAvg >= 40 ? 'bg-warning' : 'bg-danger');
        ?>
          <div class="mb-3">
            <div class="d-flex justify-content-between small mb-1">
              <span class="fw-semibold"><?= e($subject) ?></span>
              <span class="text-muted-2">
                <?= $d['n'] ?> exam(s) &middot; <?= $d['passed'] ?> passed &middot;
                <?= num((float)$sAvg) ?>%
              </span>
            </div>
            <div class="progress" style="height:10px">
              <div class="progress-bar <?= $tone ?>" style="width:<?= (float)$sAvg ?>%"></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<!-- ---------------- Answer split + topics ---------------- -->
<div class="row g-4 mb-4">
  <div class="col-lg-5">
    <div class="card border-0 h-100">
      <div class="card-body">
        <h6 class="mb-3"><i class="bi bi-pie-chart me-1"></i>Answers across all exams</h6>
        <div class="row g-2 text-center mb-3">
          <div class="col-3"><div class="stat-card p-2">
            <div class="value fs-5"><?= $totalQ ?></div><div class="label">Questions</div></div></div>
          <div class="col-3"><div class="stat-card p-2">
            <div class="value fs-5 text-success"><?= $totalC ?></div><div class="label">Correct</div></div></div>
          <div class="col-3"><div class="stat-card p-2">
            <div class="value fs-5 text-danger"><?= $totalW ?></div><div class="label">Wrong</div></div></div>
          <div class="col-3"><div class="stat-card p-2">
            <div class="value fs-5 text-warning"><?= $totalS ?></div><div class="label">Skipped</div></div></div>
        </div>
        <div class="progress" style="height:20px">
          <?php $den = max(1, $totalQ); ?>
          <div class="progress-bar bg-success" style="width:<?= $totalC / $den * 100 ?>%">
            <?= $totalC ?></div>
          <div class="progress-bar bg-danger" style="width:<?= $totalW / $den * 100 ?>%">
            <?= $totalW ?></div>
          <div class="progress-bar bg-warning" style="width:<?= $totalS / $den * 100 ?>%">
            <?= $totalS ?></div>
        </div>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card border-0 h-100">
      <div class="card-body">
        <h6 class="mb-3"><i class="bi bi-tags me-1"></i>Topic wise accuracy</h6>
        <?php if (!$topics): ?>
          <p class="text-muted-2 small mb-0">
            No topic data yet - add a category to your questions to see this breakdown.
          </p>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead><tr><th>Topic</th><th>Attempted</th><th>Correct</th>
                  <th style="min-width:140px">Accuracy</th></tr></thead>
              <tbody>
                <?php foreach ($topics as $t):
                    $acc = (int)$t['attempted'] > 0
                           ? (int)$t['correct'] / (int)$t['attempted'] * 100 : 0;
                    $tone = $acc >= 70 ? 'bg-success' : ($acc >= 40 ? 'bg-warning' : 'bg-danger');
                ?>
                  <tr>
                    <td class="small fw-semibold"><?= e($t['topic']) ?></td>
                    <td class="small"><?= (int)$t['attempted'] ?></td>
                    <td class="small"><?= (int)$t['correct'] ?></td>
                    <td>
                      <div class="d-flex align-items-center gap-2">
                        <div class="progress flex-grow-1" style="height:7px">
                          <div class="progress-bar <?= $tone ?>" style="width:<?= $acc ?>%"></div>
                        </div>
                        <span class="small"><?= round($acc) ?>%</span>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- ---------------- Every exam ---------------- -->
<div class="card border-0">
  <div class="card-body">
    <h6 class="mb-3"><i class="bi bi-list-ol me-1"></i>All exams taken (<?= $n ?>)</h6>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead>
          <tr>
            <th>#</th><th>Exam</th><th>Set by</th><th>Date</th><th>Time taken</th>
            <th>Correct</th><th>Wrong</th><th>Skipped</th><th>Marks</th><th>%</th><th>Result</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($results as $i => $r):
              $secs = strtotime($r['submitted_at']) - strtotime($r['started_at']);
          ?>
            <tr>
              <td class="text-muted-2"><?= $i + 1 ?></td>
              <td>
                <div class="fw-semibold"><?= e($r['title']) ?></div>
                <div class="small text-muted-2"><?= e($r['subject']) ?></div>
              </td>
              <td class="small text-muted-2"><?= e($r['faculty_name'] ?? 'Administrator') ?></td>
              <td class="small"><?= e(format_datetime($r['submitted_at'])) ?>
                <?php if ($r['attempt_status'] === 'auto_submitted'): ?>
                  <span class="badge bg-warning text-dark">Auto</span>
                <?php endif; ?>
              </td>
              <td class="small"><?= format_seconds(max(0, (int)$secs)) ?></td>
              <td class="text-success fw-semibold"><?= (int)$r['correct_count'] ?></td>
              <td class="text-danger fw-semibold"><?= (int)$r['wrong_count'] ?></td>
              <td class="text-warning fw-semibold"><?= (int)$r['unanswered_count'] ?></td>
              <td class="fw-semibold">
                <?= num((float)$r['obtained_marks']) ?>
                <span class="text-muted-2">/ <?= num((float)$r['total_marks']) ?></span>
                <?php if ((int)$r['pending_evaluation']): ?>
                  <span class="badge bg-warning text-dark">Provisional</span>
                <?php endif; ?>
              </td>
              <td><?= num((float)$r['percentage']) ?>%</td>
              <td>
                <span class="badge <?= $r['status'] === 'PASS' ? 'bg-success' : 'bg-danger' ?>">
                  <?= e($r['status']) ?>
                </span>
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
