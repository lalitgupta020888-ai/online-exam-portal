<?php
/**
 * History of every result belonging to the logged in student.
 */
require_once __DIR__ . '/includes/auth.php';

$student = require_student();
close_expired_attempts((int)$student['id']);

$pageTitle = 'My Results';
$activeNav = 'results';

$st = db()->prepare(
    'SELECT r.*, e.title, e.subject, a.submitted_at, a.status AS attempt_status
       FROM results r
       JOIN exams e ON e.id = r.exam_id
       JOIN attempts a ON a.id = r.attempt_id
      WHERE r.student_id = ?
   ORDER BY r.created_at DESC'
);
$st->execute([$student['id']]);
$rows = $st->fetchAll();

$totalExams = count($rows);
$passed     = count(array_filter($rows, fn($r) => $r['status'] === 'PASS'));
$avg        = $totalExams ? array_sum(array_column($rows, 'percentage')) / $totalExams : 0;
$best       = $totalExams ? max(array_column($rows, 'percentage')) : 0;

require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-4 py-lg-5">
  <div class="page-head d-flex flex-wrap justify-content-between align-items-end mb-4 gap-3">
    <div>
      <span class="eyebrow">Performance</span>
      <h2 class="mb-1">My Results</h2>
      <p class="text-muted-2 mb-0">Every examination you have submitted.</p>
    </div>
    <a href="<?= url('dashboard.php') ?>" class="btn btn-outline-primary">
      <i class="bi bi-journal-text me-1"></i>Take Another Exam
    </a>
  </div>

  <?php if (!$rows): ?>
    <div class="card p-5 text-center border-0">
      <i class="bi bi-clipboard-x display-4 text-muted-2"></i>
      <h5 class="mt-3 mb-1">No results yet</h5>
      <p class="text-muted-2 mb-4">Attempt an examination and your result will appear here.</p>
      <div><a href="<?= url('dashboard.php') ?>" class="btn btn-primary">Browse Exams</a></div>
    </div>
  <?php else: ?>

    <div class="row g-3 mb-4">
      <?php foreach ([
        ['journal-check', 'text-primary', 'Exams Taken', $totalExams],
        ['patch-check',   'text-success', 'Passed',      $passed],
        ['graph-up',      'text-info',    'Average',     num((float)$avg) . '%'],
        ['trophy',        'text-warning', 'Best Score',  num((float)$best) . '%'],
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

    <div class="card border-0">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead>
            <tr>
              <th>#</th><th>Examination</th><th>Date</th><th>Score</th>
              <th>Percentage</th><th>Result</th><th class="text-end">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $i => $r): $pass = $r['status'] === 'PASS'; ?>
              <tr>
                <td class="text-muted-2"><?= $i + 1 ?></td>
                <td>
                  <div class="fw-semibold"><?= e($r['title']) ?></div>
                  <div class="small text-muted-2"><?= e($r['subject']) ?></div>
                </td>
                <td class="small"><?= e(format_datetime($r['submitted_at'])) ?>
                  <?php if ($r['attempt_status'] === 'auto_submitted'): ?>
                    <span class="badge bg-warning text-dark ms-1">Auto</span>
                  <?php endif; ?>
                </td>
                <td><?= num((float)$r['obtained_marks']) ?> / <?= num((float)$r['total_marks']) ?></td>
                <td style="min-width:130px">
                  <div class="d-flex align-items-center gap-2">
                    <div class="progress flex-grow-1" style="height:8px">
                      <div class="progress-bar <?= $pass ? 'bg-success' : 'bg-danger' ?>"
                           style="width:<?= e($r['percentage']) ?>%"></div>
                    </div>
                    <span class="small fw-semibold"><?= num((float)$r['percentage']) ?>%</span>
                  </div>
                </td>
                <td><span class="badge <?= $pass ? 'bg-success' : 'bg-danger' ?>"><?= e($r['status']) ?></span></td>
                <td class="text-end">
                  <a class="btn btn-sm btn-outline-primary"
                     href="<?= url('result.php?attempt=' . (int)$r['attempt_id']) ?>">View</a>
                  <a class="btn btn-sm btn-outline-secondary"
                     href="<?= url('review.php?attempt=' . (int)$r['attempt_id']) ?>">Review</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
