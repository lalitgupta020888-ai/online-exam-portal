<?php
/**
 * Faculty - every student who has been assigned any of my exams, with their
 * overall performance. Links through to one student's full analysis.
 */
require_once __DIR__ . '/../includes/auth.php';
$faculty   = require_faculty();
$collegeId = (int)$faculty['college_id'];

$search = trim($_GET['q'] ?? '');
$scope  = $_GET['scope'] ?? 'mine';      // mine = my students, all = every student

$sql = 'SELECT s.id, s.name, s.email, s.phone, s.is_active, s.created_at,
               s.branch_id, s.study_year, s.semester, s.enrollment_no,
               b.name AS branch_name, b.code AS branch_code,
               (SELECT COUNT(*) FROM results r JOIN exams e2 ON e2.id = r.exam_id
                 WHERE r.student_id = s.id AND e2.faculty_id = :fac1) AS my_exams,
               (SELECT COALESCE(AVG(r.percentage),0) FROM results r
                  JOIN exams e3 ON e3.id = r.exam_id
                 WHERE r.student_id = s.id AND e3.faculty_id = :fac2) AS my_avg,
               (SELECT COUNT(*) FROM results r JOIN exams e4 ON e4.id = r.exam_id
                 WHERE r.student_id = s.id AND e4.faculty_id = :fac3
                   AND r.status = "PASS") AS my_passed,
               (SELECT COUNT(*) FROM results r WHERE r.student_id = s.id) AS all_exams,
               (SELECT COALESCE(AVG(r.percentage),0) FROM results r
                 WHERE r.student_id = s.id) AS all_avg
          FROM students s
     LEFT JOIN branches b ON b.id = s.branch_id';

$params = ['fac1' => $faculty['id'], 'fac2' => $faculty['id'], 'fac3' => $faculty['id']];
$where  = ['s.college_id = :college'];
$params['college'] = $collegeId;

if ($scope === 'mine') {
    $where[] = 'EXISTS (SELECT 1 FROM exam_assignments a JOIN exams e ON e.id = a.exam_id
                         WHERE a.student_id = s.id AND e.faculty_id = :fac4)';
    $params['fac4'] = $faculty['id'];
}
if ($search !== '') {
    $where[] = '(s.name LIKE :like OR s.email LIKE :like2)';
    $params['like']  = '%' . $search . '%';
    $params['like2'] = '%' . $search . '%';
}
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY s.name';

$st = db()->prepare($sql);
$st->execute($params);
$students = $st->fetchAll();

$pageTitle = 'Student Analysis';
$activeNav = 'students';
require_once __DIR__ . '/includes/faculty_header.php';
?>

<form method="get" class="card border-0 mb-3">
  <div class="card-body row g-2 align-items-end">
    <div class="col-md-5">
      <label class="form-label" for="q">Search</label>
      <div class="input-group">
        <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
        <input type="search" class="form-control" id="q" name="q" value="<?= e($search) ?>"
               placeholder="Name or email">
      </div>
    </div>
    <div class="col-md-4">
      <label class="form-label" for="scope">Show</label>
      <select class="form-select" id="scope" name="scope">
        <option value="mine" <?= $scope === 'mine' ? 'selected' : '' ?>>
          Only students assigned to my exams</option>
        <option value="all" <?= $scope === 'all' ? 'selected' : '' ?>>
          All registered students</option>
      </select>
    </div>
    <div class="col-md-3"><button class="btn btn-outline-primary w-100">
      <i class="bi bi-funnel me-1"></i>Apply</button></div>
  </div>
</form>

<div class="card border-0">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead>
        <tr>
          <th>Student</th><th>Registered</th>
          <th>My exams taken</th><th>Passed</th><th>Average (my exams)</th>
          <th>All exams</th><th>Overall average</th><th class="text-end">Analysis</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($students as $s): ?>
          <tr>
            <td>
              <div class="fw-semibold"><?= e($s['name']) ?></div>
              <div class="small text-muted-2"><?= e($s['email']) ?></div>
            </td>
            <td class="small"><?= e(format_datetime($s['created_at'])) ?></td>
            <td><?= (int)$s['my_exams'] ?></td>
            <td><?= (int)$s['my_passed'] ?></td>
            <td style="min-width:130px">
              <div class="d-flex align-items-center gap-2">
                <div class="progress flex-grow-1" style="height:7px">
                  <div class="progress-bar" style="width:<?= (float)$s['my_avg'] ?>%"></div>
                </div>
                <span class="small"><?= num((float)$s['my_avg']) ?>%</span>
              </div>
            </td>
            <td><?= (int)$s['all_exams'] ?></td>
            <td class="small"><?= num((float)$s['all_avg']) ?>%</td>
            <td class="text-end">
              <a class="btn btn-sm btn-outline-primary"
                 href="<?= url('faculty/student_analysis.php?student_id=' . (int)$s['id']) ?>">
                <i class="bi bi-person-lines-fill me-1"></i>View
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$students): ?>
          <tr><td colspan="8" class="text-center text-muted-2 py-5">
            No students found. Assign an exam to your students first.
          </td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/includes/faculty_footer.php'; ?>
