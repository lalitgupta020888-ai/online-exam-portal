<?php
/**
 * Faculty - my students, subject wise.
 *
 * Every student who registers with this college shows up under "Registered
 * students", filtered branch / year / semester wise. The faculty member matches
 * them against their own class list and adds them to "My list" for a subject,
 * and can take them off again. This never touches the student account itself -
 * only an administrator can delete a student.
 */
require_once __DIR__ . '/../includes/auth.php';
$faculty   = require_faculty();
$facultyId = (int)$faculty['id'];
$collegeId = (int)$faculty['college_id'];

ensure_faculty_students_table();

$view = ($_GET['view'] ?? 'all') === 'mine' ? 'mine' : 'all';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action  = post('action');
    $subject = trim(post('subject'));
    $back    = post('back') ?: 'faculty/my_students.php';
    if (!str_starts_with($back, 'faculty/my_students.php')) {
        $back = 'faculty/my_students.php';
    }

    $selected = $_POST['students'] ?? [];
    if (!is_array($selected)) { $selected = []; }
    $selected = array_values(array_unique(array_filter(array_map('intval', $selected))));

    // Never trust the posted ids: keep only students of this college.
    if ($selected) {
        $in  = implode(',', array_fill(0, count($selected), '?'));
        $chk = db()->prepare("SELECT id FROM students WHERE college_id = ? AND id IN ($in)");
        $chk->execute(array_merge([$collegeId], $selected));
        $selected = array_map('intval', $chk->fetchAll(PDO::FETCH_COLUMN));
    }

    if (!$selected) {
        set_flash('warning', 'Tick at least one student first.');
    } elseif ($subject === '' || mb_strlen($subject) > 120) {
        set_flash('danger', 'Choose or type the subject (up to 120 characters).');
    } elseif ($action === 'add') {
        $ins = db()->prepare('INSERT IGNORE INTO faculty_students (faculty_id, student_id, subject)
                              VALUES (?,?,?)');
        $added = 0;
        foreach ($selected as $sid) {
            $ins->execute([$facultyId, $sid, $subject]);
            $added += $ins->rowCount();
        }
        $already = count($selected) - $added;
        set_flash('success', "$added student(s) added to your list for $subject"
            . ($already > 0 ? " ($already were already on it)" : '') . '.');
    } elseif ($action === 'remove') {
        $in = implode(',', array_fill(0, count($selected), '?'));
        $del = db()->prepare("DELETE FROM faculty_students
                               WHERE faculty_id = ? AND subject = ? AND student_id IN ($in)");
        $del->execute(array_merge([$facultyId, $subject], $selected));
        set_flash('success', $del->rowCount() . " student(s) removed from your list for $subject. "
            . 'Their account and results are not affected.');
    }
    redirect($back);
}

$filters  = class_filters_from_get();
$branches = get_branches($collegeId, false);
$subjects = faculty_subjects($facultyId);

$subjectFilter = trim($_GET['subject'] ?? '');
if ($view === 'mine' && $subjectFilter === '' && $subjects) {
    $subjectFilter = $subjects[0];
}

if ($view === 'all') {
    // Every registered student, with the subjects I have already added them for.
    $students = get_college_students($collegeId, $filters,
        ',
         (SELECT GROUP_CONCAT(fs.subject ORDER BY fs.subject SEPARATOR "||")
            FROM faculty_students fs
           WHERE fs.student_id = s.id AND fs.faculty_id = ?) AS my_subjects',
        [$facultyId]);
} else {
    $students = $subjectFilter === '' ? [] : get_college_students($collegeId, $filters,
        ',
         (SELECT fs.added_at FROM faculty_students fs
           WHERE fs.student_id = s.id AND fs.faculty_id = ? AND fs.subject = ?) AS added_at',
        [$facultyId, $subjectFilter]);
    $students = array_values(array_filter($students, fn($s) => $s['added_at'] !== null));
}

// Head counts per subject for the tabs.
$st = db()->prepare('SELECT subject, COUNT(*) AS n FROM faculty_students
                      WHERE faculty_id = ? GROUP BY subject');
$st->execute([$facultyId]);
$subjectCounts = array_column($st->fetchAll(), 'n', 'subject');

// One button per class (branch + year + semester) with its head count, so a
// single click shows just that class. "My list" counts only my subject list.
$classSql = 'SELECT s.branch_id, b.name AS branch_name, s.study_year, s.semester, COUNT(*) AS n
               FROM students s
               JOIN branches b ON b.id = s.branch_id';
$classParams = [];
if ($view === 'mine') {
    $classSql .= ' JOIN faculty_students fs ON fs.student_id = s.id
                    AND fs.faculty_id = ? AND fs.subject = ?';
    $classParams = [$facultyId, $subjectFilter];
}
$classSql .= ' WHERE s.college_id = ?
             GROUP BY s.branch_id, b.name, s.study_year, s.semester
             ORDER BY b.name, s.study_year, s.semester';
$classParams[] = $collegeId;
$st = db()->prepare($classSql);
$st->execute($classParams);
$classes = $st->fetchAll();

$query = http_build_query(array_filter([
    'view'       => $view,
    'subject'    => $view === 'mine' ? $subjectFilter : '',
    'branch_id'  => $filters['branch_id'],
    'study_year' => $filters['study_year'],
    'semester'   => $filters['semester'],
    'q'          => $filters['q'],
]));
$backUrl = 'faculty/my_students.php' . ($query !== '' ? '?' . $query : '');

$pageTitle = 'My Students';
$activeNav = 'mystudents';
require_once __DIR__ . '/includes/faculty_header.php';
?>

<ul class="nav nav-pills-premium mb-3">
  <li><a class="nav-pill <?= $view === 'all' ? 'active' : '' ?>"
         href="<?= url('faculty/my_students.php?view=all') ?>">
    <i class="bi bi-people me-1"></i>Registered students</a></li>
  <li><a class="nav-pill <?= $view === 'mine' ? 'active' : '' ?>"
         href="<?= url('faculty/my_students.php?view=mine') ?>">
    <i class="bi bi-person-check me-1"></i>My list
    <span class="badge bg-primary-subtle text-primary-emphasis ms-1"><?= array_sum($subjectCounts) ?></span></a></li>
</ul>

<div class="alert alert-info d-flex align-items-start gap-2">
  <i class="bi bi-info-circle fs-5"></i>
  <div>
    <?php if ($view === 'all'): ?>
      Every student who has registered with your college is listed here. Filter by branch,
      year and semester, match them against your class list, tick them and add them to
      your list for a subject.
    <?php else: ?>
      Your students for one subject. Removing a student only takes them off your list -
      their account, papers and results stay. Only the administrator can delete a student.
    <?php endif; ?>
  </div>
</div>

<?php if ($view === 'mine'): ?>
  <?php if (!$subjects): ?>
    <div class="card border-0"><div class="card-body text-center text-muted-2 py-5">
      Your list is empty. Go to <a href="<?= url('faculty/my_students.php?view=all') ?>">Registered
      students</a> and add students for a subject.
    </div></div>
    <?php require_once __DIR__ . '/includes/faculty_footer.php'; return; ?>
  <?php endif; ?>
  <div class="d-flex flex-wrap gap-2 mb-3">
    <?php foreach ($subjects as $sub): ?>
      <a class="btn btn-sm <?= $sub === $subjectFilter ? 'btn-primary' : 'btn-outline-primary' ?>"
         href="<?= url('faculty/my_students.php?' . http_build_query(['view' => 'mine', 'subject' => $sub])) ?>">
        <?= e($sub) ?> <span class="badge bg-light text-dark ms-1"><?= (int)($subjectCounts[$sub] ?? 0) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($classes): ?>
  <div class="card border-0 mb-3">
    <div class="card-body">
      <div class="small text-muted-2 mb-2"><i class="bi bi-diagram-3 me-1"></i>Choose a class</div>
      <div class="d-flex flex-wrap gap-2">
        <?php
          $base = ['view' => $view] + ($view === 'mine' ? ['subject' => $subjectFilter] : []);
          $anyClass = $filters['branch_id'] || $filters['study_year'] || $filters['semester'];
        ?>
        <a class="btn btn-sm <?= $anyClass ? 'btn-outline-secondary' : 'btn-secondary' ?>"
           href="<?= url('faculty/my_students.php?' . http_build_query($base)) ?>">
          All classes <span class="badge bg-light text-dark ms-1"><?= array_sum(array_column($classes, 'n')) ?></span>
        </a>
        <?php foreach ($classes as $c):
            $active = (int)$filters['branch_id'] === (int)$c['branch_id']
                   && (int)$filters['study_year'] === (int)$c['study_year']
                   && (int)$filters['semester'] === (int)$c['semester'];
            $label = $c['branch_name']
                   . ($c['study_year'] ? ' - ' . str_replace('Year', 'Yr.', year_label((int)$c['study_year'])) : '')
                   . ($c['semester'] ? ' / ' . (int)$c['semester'] . ' Sem' : ''); ?>
          <a class="btn btn-sm <?= $active ? 'btn-primary' : 'btn-outline-primary' ?>"
             href="<?= url('faculty/my_students.php?' . http_build_query($base + [
                 'branch_id' => (int)$c['branch_id'],
                 'study_year' => (int)$c['study_year'],
                 'semester' => (int)$c['semester'],
             ])) ?>">
            <?= e($label) ?> <span class="badge bg-light text-dark ms-1"><?= (int)$c['n'] ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
<?php endif; ?>

<?= render_class_filter(url('faculty/my_students.php'), $branches, $filters,
    array_filter(['view' => $view, 'subject' => $view === 'mine' ? $subjectFilter : ''])) ?>

<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="back" value="<?= e($backUrl) ?>">

  <div class="card border-0">
    <div class="card-body d-flex flex-wrap gap-2 align-items-center border-bottom">
      <button type="button" class="btn btn-sm btn-outline-primary" id="selectAll">
        <i class="bi bi-check-all me-1"></i>Select all shown
      </button>
      <button type="button" class="btn btn-sm btn-outline-secondary" id="selectNone">Clear</button>
      <span class="small text-muted-2"><span id="selCount">0</span> selected of <?= count($students) ?></span>

      <div class="ms-auto d-flex flex-wrap gap-2 align-items-center">
        <?php if ($view === 'all'): ?>
          <input type="text" name="subject" class="form-control form-control-sm" style="width:220px"
                 list="subjectList" maxlength="120" required placeholder="Subject, e.g. Data Structures">
          <datalist id="subjectList">
            <?php foreach ($subjects as $sub): ?><option value="<?= e($sub) ?>"><?php endforeach; ?>
          </datalist>
          <button class="btn btn-sm btn-primary" name="action" value="add">
            <i class="bi bi-person-plus me-1"></i>Add to my list
          </button>
        <?php else: ?>
          <input type="hidden" name="subject" value="<?= e($subjectFilter) ?>">
          <button class="btn btn-sm btn-outline-danger" name="action" value="remove"
                  data-confirm="Remove the selected students from your <?= e($subjectFilter) ?> list?">
            <i class="bi bi-person-dash me-1"></i>Remove from <?= e($subjectFilter) ?>
          </button>
        <?php endif; ?>
      </div>
    </div>

    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead>
          <tr>
            <th style="width:50px"></th><th>Student</th><th>Class</th><th>Roll no</th>
            <th><?= $view === 'all' ? 'On my list for' : 'Added' ?></th>
            <th class="text-end">Analysis</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($students as $s): $sid = (int)$s['id']; ?>
            <tr>
              <td><input class="form-check-input student-check" type="checkbox"
                         name="students[]" value="<?= $sid ?>" id="stu<?= $sid ?>"></td>
              <td>
                <label class="mb-0" for="stu<?= $sid ?>" style="cursor:pointer">
                  <div class="fw-semibold"><?= e($s['name']) ?></div>
                  <div class="small text-muted-2"><?= e($s['email']) ?></div>
                </label>
              </td>
              <td><?= class_chips($s) ?></td>
              <td class="small"><?= e($s['enrollment_no'] ?: '-') ?></td>
              <td class="small">
                <?php if ($view === 'all'): ?>
                  <?php if ($s['my_subjects']): ?>
                    <?php foreach (explode('||', $s['my_subjects']) as $sub): ?>
                      <span class="badge bg-success-subtle text-success-emphasis"><?= e($sub) ?></span>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <span class="text-muted-2">-</span>
                  <?php endif; ?>
                <?php else: ?>
                  <?= e(format_datetime($s['added_at'])) ?>
                <?php endif; ?>
              </td>
              <td class="text-end">
                <a class="btn btn-sm btn-outline-primary"
                   href="<?= url('faculty/student_analysis.php?student_id=' . $sid) ?>">
                  <i class="bi bi-person-lines-fill"></i>
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$students): ?>
            <tr><td colspan="6" class="text-center text-muted-2 py-5">
              No students match this filter.
            </td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</form>

<script>
(function () {
  var boxes = Array.prototype.slice.call(document.querySelectorAll('.student-check'));
  var count = document.getElementById('selCount');
  function refresh() {
    count.textContent = boxes.filter(function (b) { return b.checked; }).length;
  }
  boxes.forEach(function (b) { b.addEventListener('change', refresh); });
  document.getElementById('selectAll').addEventListener('click', function () {
    boxes.forEach(function (b) { b.checked = true; }); refresh();
  });
  document.getElementById('selectNone').addEventListener('click', function () {
    boxes.forEach(function (b) { b.checked = false; }); refresh();
  });
})();
</script>

<?php require_once __DIR__ . '/includes/faculty_footer.php'; ?>
