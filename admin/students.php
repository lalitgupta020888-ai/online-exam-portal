<?php
/**
 * Admin - the students of this college, filtered by branch, year and semester.
 */
require_once __DIR__ . '/../includes/auth.php';
$admin     = require_admin();
$collegeId = (int)$admin['college_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $sid    = (int)post('student_id');
    $action = post('action');

    // Only students of this college can be touched.
    $st = db()->prepare('SELECT id FROM students WHERE id = ? AND college_id = ?');
    $st->execute([$sid, $collegeId]);

    if (!$st->fetchColumn()) {
        set_flash('danger', 'Student not found in your college.');
    } elseif ($action === 'toggle') {
        db()->prepare('UPDATE students SET is_active = 1 - is_active WHERE id = ?')->execute([$sid]);
        set_flash('success', 'Student account status updated.');
    } elseif ($action === 'delete') {
        db()->prepare('DELETE FROM students WHERE id = ?')->execute([$sid]);
        set_flash('success', 'Student deleted along with their attempts and results.');
    }
    redirect('admin/students.php');
}

$filters  = class_filters_from_get();
$branches = get_branches($collegeId, false);

$students = get_college_students($collegeId, $filters,
    ',
     (SELECT COUNT(*) FROM results r WHERE r.student_id = s.id) AS attempts,
     (SELECT COALESCE(AVG(r.percentage),0) FROM results r WHERE r.student_id = s.id) AS avg_pct,
     (SELECT COUNT(*) FROM results r WHERE r.student_id = s.id AND r.status = "PASS") AS passed,
     (SELECT COUNT(*) FROM exam_assignments a WHERE a.student_id = s.id) AS allotted');

// Headline counts for the whole college, not just the filtered view.
$st = db()->prepare(
    'SELECT COUNT(*) AS total,
            SUM(is_active) AS active,
            SUM(branch_id IS NULL OR study_year IS NULL) AS incomplete
       FROM students WHERE college_id = ?'
);
$st->execute([$collegeId]);
$totals = $st->fetch();

$pageTitle = 'Students';
$activeNav = 'students';
require_once __DIR__ . '/includes/admin_header.php';
?>

<div class="row g-3 mb-3">
  <?php foreach ([
    ['people',        'text-primary', 'Students',        (int)$totals['total']],
    ['person-check',  'text-success', 'Active',          (int)$totals['active']],
    ['funnel',        'text-info',    'Matching filter', count($students)],
    ['exclamation-triangle', 'text-warning', 'Class not set', (int)$totals['incomplete']],
  ] as [$icon, $tone, $label, $value]): ?>
    <div class="col-6 col-lg-3">
      <div class="stat-card h-100">
        <i class="bi bi-<?= $icon ?> fs-4 <?= $tone ?>"></i>
        <div class="value mt-1"><?= $value ?></div>
        <div class="label"><?= e($label) ?></div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php if ((int)$totals['incomplete'] > 0): ?>
  <div class="alert alert-warning d-flex align-items-center gap-2">
    <i class="bi bi-mortarboard fs-4"></i>
    <div>
      <strong><?= (int)$totals['incomplete'] ?> student(s)</strong> have not set their branch or
      year, so they will be missed when a paper is allotted by class. Ask them to update
      their account.
    </div>
  </div>
<?php endif; ?>

<?= render_class_filter(url('admin/students.php'), $branches, $filters) ?>

<div class="card border-0">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead>
        <tr>
          <th>#</th><th>Student</th><th>Class</th><th>Roll no</th><th>Registered</th>
          <th>Allotted</th><th>Taken</th><th>Passed</th><th>Average</th>
          <th>Status</th><th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($students as $i => $s): $sid = (int)$s['id']; ?>
          <tr>
            <td class="text-muted-2"><?= $i + 1 ?></td>
            <td>
              <div class="fw-semibold"><?= e($s['name']) ?></div>
              <div class="small text-muted-2"><?= e($s['email']) ?></div>
            </td>
            <td><?= class_chips($s) ?></td>
            <td class="small"><?= e($s['enrollment_no'] ?: '-') ?></td>
            <td class="small"><?= e(format_datetime($s['created_at'])) ?></td>
            <td><?= (int)$s['allotted'] ?></td>
            <td><?= (int)$s['attempts'] ?></td>
            <td><?= (int)$s['passed'] ?></td>
            <td style="min-width:120px">
              <div class="d-flex align-items-center gap-2">
                <div class="progress flex-grow-1" style="height:7px">
                  <div class="progress-bar" style="width:<?= (float)$s['avg_pct'] ?>%"></div>
                </div>
                <span class="small"><?= num((float)$s['avg_pct']) ?>%</span>
              </div>
            </td>
            <td>
              <span class="badge <?= (int)$s['is_active'] ? 'bg-success' : 'bg-secondary' ?>">
                <?= (int)$s['is_active'] ? 'Active' : 'Blocked' ?>
              </span>
            </td>
            <td class="text-end text-nowrap">
              <a class="btn btn-sm btn-outline-primary"
                 href="<?= url('admin/results.php?student_id=' . $sid) ?>" title="View results">
                <i class="bi bi-bar-chart"></i>
              </a>
              <form method="post" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="student_id" value="<?= $sid ?>">
                <input type="hidden" name="action" value="toggle">
                <button class="btn btn-sm btn-outline-warning" title="Block / unblock">
                  <i class="bi bi-<?= (int)$s['is_active'] ? 'lock' : 'unlock' ?>"></i>
                </button>
              </form>
              <form method="post" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="student_id" value="<?= $sid ?>">
                <input type="hidden" name="action" value="delete">
                <button class="btn btn-sm btn-outline-danger"
                        data-confirm="Delete this student? Their attempts and results will also be removed.">
                  <i class="bi bi-trash"></i>
                </button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$students): ?>
          <tr><td colspan="11" class="text-center text-muted-2 py-5">
            No students match this filter.
          </td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
