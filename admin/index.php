<?php
/**
 * Admin dashboard - a supervisory view of the whole portal.
 *
 * The administrator does not set papers or questions; this page answers
 * "is anything stuck, and is anyone marking oddly?" and links straight into
 * the analysis screens.
 */
$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
require_once __DIR__ . '/includes/admin_header.php';

$pdo = db();
$collegeId = (int)$admin['college_id'];
$college   = get_college($collegeId);

/** Every figure below is for this administrator's college only. */
$one = static function (string $sql) use ($pdo, $collegeId) {
    $st = $pdo->prepare($sql);
    $st->execute(['c' => $collegeId]);
    return $st->fetchColumn();
};

$stats = [
    'students'  => (int)$one('SELECT COUNT(*) FROM students WHERE college_id = :c'),
    'faculty'   => (int)$one("SELECT COUNT(*) FROM faculty WHERE college_id = :c AND status='approved'"),
    'exams'     => (int)$one('SELECT COUNT(*) FROM exams WHERE college_id = :c'),
    'questions' => (int)$one('SELECT COUNT(*) FROM questions q JOIN exams e ON e.id = q.exam_id
                               WHERE e.college_id = :c'),
    'papers'    => (int)$one('SELECT COUNT(*) FROM results r JOIN exams e ON e.id = r.exam_id
                               WHERE e.college_id = :c'),
];
$passed   = (int)$one('SELECT COUNT(*) FROM results r JOIN exams e ON e.id = r.exam_id
                        WHERE e.college_id = :c AND r.status = "PASS"');
$avg      = (float)$one('SELECT COALESCE(AVG(r.percentage),0) FROM results r
                          JOIN exams e ON e.id = r.exam_id WHERE e.college_id = :c');
$running  = (int)$one("SELECT COUNT(*) FROM attempts t JOIN exams e ON e.id = t.exam_id
                        WHERE e.college_id = :c AND t.status = 'in_progress'");
$pendEval = (int)$one('SELECT COUNT(*) FROM results r JOIN exams e ON e.id = r.exam_id
                        WHERE e.college_id = :c AND r.pending_evaluation = 1');
$pendFac  = (int)$one("SELECT COUNT(*) FROM faculty WHERE college_id = :c AND status='pending'");
$orphans  = (int)$one('SELECT COUNT(*) FROM exams WHERE college_id = :c AND faculty_id IS NULL');
$unallot  = (int)$one('SELECT COUNT(*) FROM exams e WHERE e.college_id = :c AND NOT EXISTS
                        (SELECT 1 FROM exam_assignments a WHERE a.exam_id = e.id)');
$noClass  = (int)$one('SELECT COUNT(*) FROM students
                        WHERE college_id = :c AND (branch_id IS NULL OR study_year IS NULL)');
$passRate = $stats['papers'] ? round($passed / $stats['papers'] * 100, 1) : 0;

// Per faculty snapshot for the summary table.
$st = $pdo->prepare("SELECT * FROM faculty
                      WHERE college_id = ? AND status = 'approved' ORDER BY name");
$st->execute([$collegeId]);
$faculty = $st->fetchAll();
$rows = [];
foreach ($faculty as $f) {
    $rows[] = ['f' => $f, 'a' => faculty_analytics((int)$f['id'])];
}
usort($rows, fn($x, $y) => $y['a']['pending_papers'] <=> $x['a']['pending_papers']);

// Ten most recent submitted papers.
$st = $pdo->prepare(
    'SELECT r.*, s.name AS student_name, e.title AS exam_title, a.submitted_at,
            f.name AS faculty_name, b.code AS branch_code, s.study_year, s.semester
       FROM results r
       JOIN students s ON s.id = r.student_id
       JOIN exams e    ON e.id = r.exam_id
       JOIN attempts a ON a.id = r.attempt_id
  LEFT JOIN faculty f  ON f.id = e.faculty_id
  LEFT JOIN branches b ON b.id = s.branch_id
      WHERE e.college_id = ?
   ORDER BY r.created_at DESC LIMIT 10'
);
$st->execute([$collegeId]);
$recent = $st->fetchAll();
?>

<?php if ($college): ?>
  <div class="card border-0 mb-4">
    <div class="card-body d-flex flex-wrap align-items-center gap-3">
      <?= college_badge($college, 56) ?>
      <div class="flex-grow-1">
        <span class="eyebrow">Institution</span>
        <h4 class="mb-1"><?= e($college['name']) ?></h4>
        <div class="small text-muted-2">
          <span class="class-chip"><?= e($college['code']) ?></span>
          <?php if ($college['city']): ?>
            <span class="ms-2"><i class="bi bi-geo-alt me-1"></i><?= e($college['city']) ?><?php
              if ($college['state']): ?>, <?= e($college['state']) ?><?php endif; ?></span>
          <?php endif; ?>
          <?php if ($college['email']): ?>
            <span class="ms-2"><i class="bi bi-envelope me-1"></i><?= e($college['email']) ?></span>
          <?php endif; ?>
        </div>
      </div>
      <div class="d-flex gap-2">
        <a class="btn btn-sm btn-outline-primary" href="<?= url('admin/branches.php') ?>">
          <i class="bi bi-diagram-3 me-1"></i>Branches
        </a>
        <a class="btn btn-sm btn-outline-secondary" href="<?= url('admin/account.php?tab=college') ?>">
          <i class="bi bi-pencil me-1"></i>Edit college
        </a>
      </div>
    </div>
  </div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <?php foreach ([
    ['people',         'text-primary',  'Students',        $stats['students']],
    ['person-video3',  'text-info',     'Faculty',         $stats['faculty']],
    ['journal-text',   'text-secondary','Question Papers', $stats['exams']],
    ['patch-question', 'text-secondary','Questions',       $stats['questions']],
    ['clipboard-data', 'text-success',  'Papers Submitted', $stats['papers']],
    ['graph-up',       'text-warning',  'Average Score',   num($avg) . '%'],
    ['patch-check',    'text-success',  'Pass Rate',       $passRate . '%'],
    ['hourglass-split','text-danger',   'Exams Running',   $running],
  ] as [$icon, $tone, $label, $value]): ?>
    <div class="col-6 col-lg-3 col-xl-3">
      <div class="stat-card h-100">
        <i class="bi bi-<?= $icon ?> fs-4 <?= $tone ?>"></i>
        <div class="value mt-1"><?= e($value) ?></div>
        <div class="label"><?= e($label) ?></div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<!-- ------------------------ Needs attention ------------------------ -->
<?php
$alerts = [];
if ($pendFac > 0) {
    $alerts[] = ['danger', 'person-exclamation',
        $pendFac . ' faculty registration(s) waiting for your approval',
        url('admin/faculty.php?status=pending'), 'Review'];
}
if ($pendEval > 0) {
    $alerts[] = ['warning', 'pencil-square',
        $pendEval . ' submitted paper(s) still have unmarked written answers',
        url('admin/faculty_analysis.php?sort=pending'), 'See who'];
}
if ($orphans > 0) {
    $alerts[] = ['warning', 'person-dash',
        $orphans . ' question paper(s) have no faculty owner',
        url('admin/papers.php?faculty_id=-1'), 'Transfer'];
}
if ($unallot > 0) {
    $alerts[] = ['info', 'people',
        $unallot . ' paper(s) have not been allotted to any student',
        url('admin/papers.php'), 'Allot'];
}
if ($noClass > 0) {
    $alerts[] = ['warning', 'mortarboard',
        $noClass . ' student(s) have no branch or year set - they cannot be allotted by class',
        url('admin/students.php'), 'Review'];
}
?>
<?php if ($alerts): ?>
  <h5 class="mb-3"><i class="bi bi-bell text-warning me-2"></i>Needs attention</h5>
  <div class="row g-3 mb-4">
    <?php foreach ($alerts as [$tone, $icon, $text, $link, $cta]): ?>
      <div class="col-lg-6">
        <div class="alert alert-<?= $tone ?> d-flex align-items-center gap-2 mb-0 h-100">
          <i class="bi bi-<?= $icon ?> fs-4"></i>
          <div class="flex-grow-1"><?= e($text) ?></div>
          <a class="btn btn-sm btn-<?= $tone === 'info' ? 'primary' : $tone ?>"
             href="<?= $link ?>"><?= e($cta) ?></a>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php else: ?>
  <div class="alert alert-success d-flex align-items-center gap-2">
    <i class="bi bi-check2-circle fs-4"></i>
    <div>Everything is up to date - no pending approvals, no marking backlog and every
      paper is owned and allotted.</div>
  </div>
<?php endif; ?>

<div class="row g-4">
  <!-- --------------------- Faculty at a glance --------------------- -->
  <div class="col-xl-7">
    <div class="card border-0 h-100">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h6 class="mb-0"><i class="bi bi-person-video3 me-1"></i>Faculty at a glance</h6>
          <a class="small" href="<?= url('admin/faculty_analysis.php') ?>">Full analysis</a>
        </div>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead>
              <tr><th>Faculty</th><th>Papers</th><th>Questions</th>
                  <th>Unmarked</th><th>Marking</th><th></th></tr>
            </thead>
            <tbody>
              <?php foreach ($rows as ['f' => $f, 'a' => $a]): ?>
                <tr>
                  <td>
                    <div class="d-flex align-items-center gap-2">
                      <span class="avatar-sm"><?= e(initials($f['name'])) ?></span>
                      <div>
                        <div class="small fw-semibold"><?= e($f['name']) ?></div>
                        <div class="small text-muted-2"><?= e($f['department'] ?: '-') ?></div>
                      </div>
                    </div>
                  </td>
                  <td><?= $a['papers'] ?></td>
                  <td><?= $a['questions'] ?></td>
                  <td>
                    <?php if ($a['pending_papers'] > 0): ?>
                      <span class="badge bg-danger"><?= $a['pending_papers'] ?></span>
                    <?php else: ?>
                      <span class="text-muted-2">-</span>
                    <?php endif; ?>
                  </td>
                  <td class="small">
                    <?php if ($a['award_ratio'] === null): ?>
                      <span class="text-muted-2">-</span>
                    <?php else: ?>
                      <span class="badge <?= $a['award_ratio'] >= 85 ? 'bg-warning text-dark'
                            : ($a['award_ratio'] <= 40 ? 'bg-danger' : 'bg-success') ?>">
                        <?= $a['award_ratio'] >= 85 ? 'lenient'
                             : ($a['award_ratio'] <= 40 ? 'strict' : 'balanced') ?>
                      </span>
                    <?php endif; ?>
                  </td>
                  <td class="text-end">
                    <a class="btn btn-sm btn-outline-primary"
                       href="<?= url('admin/faculty_detail.php?id=' . (int)$f['id']) ?>">
                      <i class="bi bi-graph-up"></i>
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$rows): ?>
                <tr><td colspan="6" class="text-center text-muted-2 py-4">
                  No approved faculty yet.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- ------------------------ Recent papers ------------------------ -->
  <div class="col-xl-5">
    <div class="card border-0 h-100">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h6 class="mb-0"><i class="bi bi-clock-history me-1"></i>Recently submitted</h6>
          <a class="small" href="<?= url('admin/results.php') ?>">All results</a>
        </div>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Student</th><th>Paper</th><th>Score</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($recent as $r): ?>
                <tr>
                  <td class="small fw-semibold"><?= e($r['student_name']) ?></td>
                  <td class="small text-muted-2">
                    <?= e($r['exam_title']) ?>
                    <?php if ($r['faculty_name']): ?>
                      <div class="small">by <?= e($r['faculty_name']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span class="badge <?= $r['status'] === 'PASS' ? 'bg-success' : 'bg-danger' ?>">
                      <?= num((float)$r['percentage']) ?>%
                    </span>
                    <?php if ((int)$r['pending_evaluation']): ?>
                      <div class="small text-warning">provisional</div>
                    <?php endif; ?>
                  </td>
                  <td class="text-end">
                    <a class="btn btn-sm btn-outline-secondary"
                       href="<?= url('admin/attempt_view.php?attempt=' . (int)$r['attempt_id']) ?>"
                       title="Answer sheet"><i class="bi bi-file-text"></i></a>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$recent): ?>
                <tr><td colspan="4" class="text-center text-muted-2 py-4">
                  No papers submitted yet.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="card border-0 mt-4">
  <div class="card-body d-flex flex-wrap gap-2">
    <a href="<?= url('admin/faculty_analysis.php') ?>" class="btn btn-primary">
      <i class="bi bi-graph-up-arrow me-1"></i>Analyse Faculty
    </a>
    <a href="<?= url('admin/papers.php') ?>" class="btn btn-outline-primary">
      <i class="bi bi-journal-text me-1"></i>Inspect Question Papers
    </a>
    <a href="<?= url('admin/faculty.php') ?>" class="btn btn-outline-secondary">
      <i class="bi bi-person-video3 me-1"></i>Faculty Accounts
    </a>
    <a href="<?= url('admin/students.php') ?>" class="btn btn-outline-secondary">
      <i class="bi bi-people me-1"></i>Students
    </a>
    <a href="<?= url('admin/results.php') ?>" class="btn btn-outline-secondary">
      <i class="bi bi-bar-chart me-1"></i>All Results
    </a>
  </div>
</div>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
