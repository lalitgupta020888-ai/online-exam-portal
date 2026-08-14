<?php
/**
 * Admin - every submitted paper, filterable by exam, student and outcome.
 */
require_once __DIR__ . '/../includes/auth.php';
$admin     = require_admin();
$collegeId = (int)$admin['college_id'];

$examId    = get_int('exam_id');
$studentId = get_int('student_id');
$status    = $_GET['status'] ?? '';
$search    = trim($_GET['q'] ?? '');

$where  = ['e.college_id = ?'];
$params = [$collegeId];
if ($examId)    { $where[] = 'r.exam_id = ?';    $params[] = $examId; }
if ($studentId) { $where[] = 'r.student_id = ?'; $params[] = $studentId; }
if ($status === 'PASS' || $status === 'FAIL') {
    $where[] = 'r.status = ?';
    $params[] = $status;
}
if ($search !== '') {
    $where[] = '(s.name LIKE ? OR s.email LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$st = db()->prepare(
    "SELECT r.*, s.name AS student_name, s.email, e.title AS exam_title,
            e.subject, a.started_at, a.submitted_at, a.status AS attempt_status
       FROM results r
       JOIN students s ON s.id = r.student_id
       JOIN exams e    ON e.id = r.exam_id
       JOIN attempts a ON a.id = r.attempt_id
       $whereSql
   ORDER BY r.created_at DESC"
);
$st->execute($params);
$rows = $st->fetchAll();

$total  = count($rows);
$passed = count(array_filter($rows, fn($r) => $r['status'] === 'PASS'));
$avg    = $total ? array_sum(array_column($rows, 'percentage')) / $total : 0;

$exams = get_exams(false, $collegeId);

$pageTitle = 'Student Results';
$activeNav = 'results';
require_once __DIR__ . '/includes/admin_header.php';
?>

<div class="row g-3 mb-3">
  <?php foreach ([
    ['clipboard-data', 'text-primary', 'Papers', $total],
    ['patch-check',    'text-success', 'Passed', $passed],
    ['x-octagon',      'text-danger',  'Failed', $total - $passed],
    ['graph-up',       'text-info',    'Average', num((float)$avg) . '%'],
  ] as [$icon, $tone, $label, $value]): ?>
    <div class="col-6 col-lg-3">
      <div class="stat-card h-100">
        <i class="bi bi-<?= $icon ?> fs-4 <?= $tone ?>"></i>
        <div class="value mt-1"><?= e($value) ?></div>
        <div class="label"><?= e($label) ?></div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="card border-0 mb-3">
  <div class="card-body">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-md-4">
        <label class="form-label" for="exam_id">Examination</label>
        <select class="form-select" id="exam_id" name="exam_id">
          <option value="0">All examinations</option>
          <?php foreach ($exams as $ex): ?>
            <option value="<?= (int)$ex['id'] ?>" <?= $examId === (int)$ex['id'] ? 'selected' : '' ?>>
              <?= e($ex['title']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label" for="status">Outcome</label>
        <select class="form-select" id="status" name="status">
          <option value="">All</option>
          <option value="PASS" <?= $status === 'PASS' ? 'selected' : '' ?>>Pass</option>
          <option value="FAIL" <?= $status === 'FAIL' ? 'selected' : '' ?>>Fail</option>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label" for="q">Student</label>
        <input type="search" class="form-control" id="q" name="q" value="<?= e($search) ?>"
               placeholder="Name or email">
      </div>
      <div class="col-md-2 d-grid">
        <button class="btn btn-outline-primary"><i class="bi bi-funnel me-1"></i>Filter</button>
      </div>
    </form>
  </div>
</div>

<div class="card border-0">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead>
        <tr>
          <th>#</th><th>Student</th><th>Examination</th><th>Submitted</th>
          <th>Correct</th><th>Wrong</th><th>Skipped</th>
          <th>Marks</th><th>%</th><th>Result</th><th class="text-end">Answer sheet</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $i => $r): ?>
          <tr>
            <td class="text-muted-2"><?= $i + 1 ?></td>
            <td>
              <div class="fw-semibold"><?= e($r['student_name']) ?></div>
              <div class="small text-muted-2"><?= e($r['email']) ?></div>
            </td>
            <td>
              <div class="small fw-semibold"><?= e($r['exam_title']) ?></div>
              <div class="small text-muted-2"><?= e($r['subject']) ?></div>
            </td>
            <td class="small">
              <?= e(format_datetime($r['submitted_at'])) ?>
              <?php if ($r['attempt_status'] === 'auto_submitted'): ?>
                <span class="badge bg-warning text-dark">Auto</span>
              <?php endif; ?>
            </td>
            <td class="text-success fw-semibold"><?= (int)$r['correct_count'] ?></td>
            <td class="text-danger fw-semibold"><?= (int)$r['wrong_count'] ?></td>
            <td class="text-warning fw-semibold"><?= (int)$r['unanswered_count'] ?></td>
            <td><?= num((float)$r['obtained_marks']) ?> / <?= num((float)$r['total_marks']) ?></td>
            <td><?= num((float)$r['percentage']) ?>%</td>
            <td><span class="badge <?= $r['status'] === 'PASS' ? 'bg-success' : 'bg-danger' ?>">
                <?= e($r['status']) ?></span>
              <?php if ((int)$r['pending_evaluation']): ?>
                <div class="small text-warning">provisional</div>
              <?php endif; ?>
            </td>
            <td class="text-end">
              <a class="btn btn-sm btn-outline-primary"
                 href="<?= url('admin/attempt_view.php?attempt=' . (int)$r['attempt_id']) ?>"
                 title="View the answer sheet and how it was marked">
                <i class="bi bi-file-text"></i>
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
          <tr><td colspan="11" class="text-center text-muted-2 py-5">
            No results match this filter.
          </td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
