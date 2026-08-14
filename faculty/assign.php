<?php
/**
 * Faculty - choose which students may attempt this paper.
 *
 * The roster is filtered by branch, year and semester, so allotting a paper to
 * "CSE, 3rd year, semester 5" is two clicks rather than fifty. This list is the
 * only thing that lets a student see the paper at all.
 */
require_once __DIR__ . '/../includes/auth.php';
$faculty   = require_faculty();
$collegeId = (int)$faculty['college_id'];

$examId = get_int('exam_id') ?: (int)post('exam_id');
$exam   = get_owned_exam($examId, (int)$faculty['id']);
if (!$exam) {
    set_flash('danger', 'That exam does not belong to you.');
    redirect('faculty/exams.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $selected = $_POST['students'] ?? [];
    if (!is_array($selected)) { $selected = []; }
    $selected = array_map('intval', $selected);

    // Never trust the posted ids: keep only students of this college.
    if ($selected) {
        $in = implode(',', array_fill(0, count($selected), '?'));
        $chk = db()->prepare("SELECT id FROM students
                               WHERE college_id = ? AND id IN ($in)");
        $chk->execute(array_merge([$collegeId], $selected));
        $selected = array_map('intval', $chk->fetchAll(PDO::FETCH_COLUMN));
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Students who already started the exam keep their allotment.
        $locked = $pdo->prepare('SELECT DISTINCT student_id FROM attempts WHERE exam_id = ?');
        $locked->execute([$examId]);
        $lockedIds = array_map('intval', $locked->fetchAll(PDO::FETCH_COLUMN));

        $keep = array_values(array_unique(array_merge($selected, $lockedIds)));

        if ($keep) {
            $in = implode(',', array_fill(0, count($keep), '?'));
            $pdo->prepare("DELETE FROM exam_assignments
                            WHERE exam_id = ? AND student_id NOT IN ($in)")
                ->execute(array_merge([$examId], $keep));
        } else {
            $pdo->prepare('DELETE FROM exam_assignments WHERE exam_id = ?')->execute([$examId]);
        }

        $ins = $pdo->prepare(
            'INSERT INTO exam_assignments (exam_id, student_id, assigned_by)
             VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE assigned_by = VALUES(assigned_by)'
        );
        foreach ($keep as $sid) {
            $ins->execute([$examId, $sid, $faculty['id']]);
        }

        $pdo->prepare("UPDATE exams SET access_mode = 'assigned' WHERE id = ?")->execute([$examId]);
        $pdo->commit();

        $skipped = count(array_diff($lockedIds, $selected));
        set_flash('success', count($keep) . ' student(s) allotted this paper'
            . ($skipped > 0 ? " ($skipped kept because they already started it)" : '') . '.');
    } catch (Throwable $ex) {
        $pdo->rollBack();
        set_flash('danger', 'Could not update the allotment. Please try again.');
    }
    redirect('faculty/assign.php?exam_id=' . $examId);
}

$filters  = class_filters_from_get();
$branches = get_branches($collegeId, false);

$students = get_college_students($collegeId, $filters,
    ',
     (SELECT COUNT(*) FROM exam_assignments a
       WHERE a.exam_id = ? AND a.student_id = s.id) AS assigned,
     (SELECT COUNT(*) FROM attempts t
       WHERE t.exam_id = ? AND t.student_id = s.id) AS attempted',
    [$examId, $examId]);

$assignedNow = count(array_filter($students, fn($s) => (int)$s['assigned'] > 0));

$pageTitle = 'Allot Students';
$activeNav = 'exams';
require_once __DIR__ . '/includes/faculty_header.php';
?>

<div class="card border-0 mb-3">
  <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div>
      <span class="eyebrow">Allot paper</span>
      <h5 class="mb-1"><?= e($exam['title']) ?></h5>
      <div class="small text-muted-2">
        <?= e($exam['subject']) ?> &middot;
        <?= (int)$exam['assigned_count'] ?> student(s) currently allotted
        &middot; intended for <strong><?= e(exam_audience_label($exam)) ?></strong>
      </div>
    </div>
    <div class="d-flex gap-2">
      <a href="<?= url('faculty/exam_form.php?id=' . $examId) ?>" class="btn btn-outline-secondary">
        <i class="bi bi-pencil me-1"></i>Edit paper
      </a>
      <a href="<?= url('faculty/questions.php?exam_id=' . $examId) ?>" class="btn btn-outline-secondary">
        <i class="bi bi-list-check me-1"></i>Questions
      </a>
    </div>
  </div>
</div>

<div class="alert alert-info d-flex align-items-start gap-2">
  <i class="bi bi-shield-lock fs-5"></i>
  <div>
    Only the students ticked below will see this paper on their dashboard. Filter by
    branch, year and semester, then use <strong>Select all shown</strong> to allot a whole
    class at once.
  </div>
</div>

<?php if ((int)$exam['question_count'] === 0): ?>
  <div class="alert alert-danger">
    <i class="bi bi-exclamation-triangle me-1"></i>
    This paper has no questions yet - students cannot start it.
    <a href="<?= url('faculty/import.php?exam_id=' . $examId) ?>" class="fw-semibold">Add questions</a>
  </div>
<?php endif; ?>

<?= render_class_filter(url('faculty/assign.php'), $branches, $filters, ['exam_id' => $examId]) ?>

<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="exam_id" value="<?= $examId ?>">

  <div class="card border-0">
    <div class="card-body d-flex flex-wrap gap-2 align-items-center border-bottom">
      <button type="button" class="btn btn-sm btn-outline-primary" id="selectAll">
        <i class="bi bi-check-all me-1"></i>Select all shown
      </button>
      <button type="button" class="btn btn-sm btn-outline-secondary" id="selectNone">
        Clear shown
      </button>
      <?php if ($exam['target_branch_id'] || $exam['target_year'] || $exam['target_semester']): ?>
        <button type="button" class="btn btn-sm btn-outline-info" id="selectTarget"
                data-branch="<?= (int)$exam['target_branch_id'] ?>"
                data-year="<?= (int)$exam['target_year'] ?>"
                data-sem="<?= (int)$exam['target_semester'] ?>">
          <i class="bi bi-bullseye me-1"></i>Select the paper's class
        </button>
      <?php endif; ?>
      <span class="small text-muted-2 ms-auto">
        <span id="selCount"><?= $assignedNow ?></span> selected of <?= count($students) ?> shown
      </span>
    </div>

    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead>
          <tr>
            <th style="width:60px">Allot</th><th>Student</th><th>Class</th>
            <th>Roll no</th><th>Status</th><th>This paper</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($students as $s): $sid = (int)$s['id']; ?>
            <tr>
              <td>
                <input class="form-check-input student-check" type="checkbox"
                       name="students[]" value="<?= $sid ?>" id="stu<?= $sid ?>"
                       data-branch="<?= (int)$s['branch_id'] ?>"
                       data-year="<?= (int)$s['study_year'] ?>"
                       data-sem="<?= (int)$s['semester'] ?>"
                       <?= (int)$s['assigned'] ? 'checked' : '' ?>>
              </td>
              <td>
                <label class="mb-0" for="stu<?= $sid ?>" style="cursor:pointer">
                  <div class="fw-semibold"><?= e($s['name']) ?></div>
                  <div class="small text-muted-2"><?= e($s['email']) ?></div>
                </label>
              </td>
              <td><?= class_chips($s) ?></td>
              <td class="small"><?= e($s['enrollment_no'] ?: '-') ?></td>
              <td>
                <span class="badge <?= (int)$s['is_active'] ? 'bg-success' : 'bg-secondary' ?>">
                  <?= (int)$s['is_active'] ? 'Active' : 'Blocked' ?>
                </span>
              </td>
              <td>
                <?php if ((int)$s['attempted'] > 0): ?>
                  <span class="badge bg-info-subtle text-info-emphasis">
                    Already attempted - cannot be removed
                  </span>
                <?php else: ?>
                  <span class="text-muted-2 small">Not started</span>
                <?php endif; ?>
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

    <div class="card-body border-top">
      <button class="btn btn-primary">
        <i class="bi bi-check2 me-1"></i>Save Allotment
      </button>
      <span class="small text-muted-2 ms-2">
        Students hidden by the filter keep whatever they already had.
      </span>
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

  var target = document.getElementById('selectTarget');
  if (target) {
    target.addEventListener('click', function () {
      var branch = target.dataset.branch, year = target.dataset.year, sem = target.dataset.sem;
      boxes.forEach(function (b) {
        var ok = (branch === '0' || b.dataset.branch === branch)
              && (year   === '0' || b.dataset.year   === year)
              && (sem    === '0' || b.dataset.sem    === sem);
        if (ok) { b.checked = true; }
      });
      refresh();
    });
  }
  refresh();
})();
</script>

<?php require_once __DIR__ . '/includes/faculty_footer.php'; ?>
