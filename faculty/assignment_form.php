<?php
/**
 * Faculty - create or edit an assignment and choose the class it goes to.
 */
require_once __DIR__ . '/../includes/auth.php';
$faculty   = require_faculty();
$collegeId = (int)$faculty['college_id'];
ensure_assignment_schema();

$id     = get_int('id');
$isEdit = $id > 0;
$errors = [];

$a = [
    'title' => '', 'subject' => $faculty['department'] ?? '', 'questions' => '',
    'branch_id' => $faculty['branch_id'] ?? '', 'study_year' => '', 'semester' => '',
    'due_at' => date('Y-m-d\T23:59', strtotime('+7 days')), 'max_marks' => 10, 'is_active' => 1,
];
if ($isEdit) {
    $found = get_owned_assignment($id, (int)$faculty['id']);
    if (!$found) {
        set_flash('danger', 'That assignment does not belong to you.');
        redirect('faculty/assignments.php');
    }
    $a = array_merge($a, $found);
    $a['due_at'] = date('Y-m-d\TH:i', strtotime($found['due_at']));
}

$branches = get_branches($collegeId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    foreach (['title', 'subject', 'questions', 'due_at'] as $k) {
        $a[$k] = post($k);
    }
    $a['branch_id']  = (int)post('branch_id') ?: null;
    $a['study_year'] = (int)post('study_year') ?: null;
    $a['semester']   = (int)post('semester') ?: null;
    if (!isset(study_years()[(int)$a['study_year']])) { $a['study_year'] = null; }
    if (!isset(semesters()[(int)$a['semester']]))     { $a['semester'] = null; }
    $a['max_marks']  = (float)post('max_marks');
    $a['is_active']  = post('is_active') === '1' ? 1 : 0;

    if (mb_strlen($a['title']) < 3)    { $errors['title'] = 'Give the assignment a title.'; }
    if ($a['subject'] === '')          { $errors['subject'] = 'Enter the subject.'; }
    if (mb_strlen($a['questions']) < 5) { $errors['questions'] = 'Write the assignment questions.'; }
    $due = strtotime($a['due_at']);
    if (!$due) { $errors['due_at'] = 'Choose the last date for submission.'; }
    if ($a['max_marks'] <= 0 || $a['max_marks'] > 1000) { $errors['max_marks'] = 'Marks must be between 1 and 1000.'; }
    if ($a['branch_id'] && !branch_belongs_to($a['branch_id'], $collegeId)) {
        $errors['branch_id'] = 'Choose a branch of your college.';
    }

    if (!$errors) {
        $vals = [mb_substr($a['title'], 0, 200), mb_substr($a['subject'], 0, 120), $a['questions'],
                 $a['branch_id'], $a['study_year'], $a['semester'], date('Y-m-d H:i:s', $due),
                 $a['max_marks'], $a['is_active']];
        if ($isEdit) {
            db()->prepare('UPDATE assignments SET title = ?, subject = ?, questions = ?, branch_id = ?,
                                  study_year = ?, semester = ?, due_at = ?, max_marks = ?, is_active = ?
                            WHERE id = ? AND faculty_id = ?')
                ->execute(array_merge($vals, [$id, $faculty['id']]));
            set_flash('success', 'Assignment updated.');
        } else {
            db()->prepare('INSERT INTO assignments (title, subject, questions, branch_id, study_year, semester,
                                  due_at, max_marks, is_active, college_id, faculty_id)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute(array_merge($vals, [$collegeId, $faculty['id']]));
            set_flash('success', 'Assignment sent to ' . assignment_audience_label($a) . '.');
        }
        redirect('faculty/assignments.php');
    }
}

$pageTitle = $isEdit ? 'Edit Assignment' : 'New Assignment';
$activeNav = 'assignments';
require_once __DIR__ . '/includes/faculty_header.php';

$err = fn($k) => isset($errors[$k]) ? 'is-invalid' : '';
$msg = fn($k) => isset($errors[$k]) ? '<div class="invalid-feedback">' . e($errors[$k]) . '</div>' : '';
?>

<form method="post" class="card border-0" novalidate>
  <?= csrf_field() ?>
  <div class="card-body p-4">
    <div class="row g-3">
      <div class="col-md-8">
        <label class="form-label" for="title">Assignment Title</label>
        <input type="text" class="form-control <?= $err('title') ?>" id="title" name="title"
               value="<?= e($a['title']) ?>" placeholder="e.g. Assignment 1 - Data Structures" required>
        <?= $msg('title') ?>
      </div>
      <div class="col-md-4">
        <label class="form-label" for="subject">Subject</label>
        <input type="text" class="form-control <?= $err('subject') ?>" id="subject" name="subject"
               value="<?= e($a['subject']) ?>" list="subjectList" required>
        <datalist id="subjectList">
          <?php foreach (faculty_subjects((int)$faculty['id']) as $sub): ?>
            <option value="<?= e($sub) ?>">
          <?php endforeach; ?>
        </datalist>
        <?= $msg('subject') ?>
      </div>

      <div class="col-12">
        <label class="form-label" for="questions">Assignment Questions</label>
        <textarea class="form-control <?= $err('questions') ?>" id="questions" name="questions" rows="10"
                  placeholder="Q1. Explain ...&#10;Q2. Write a program to ..."><?= e($a['questions']) ?></textarea>
        <?= $msg('questions') ?>
      </div>

      <div class="col-12"><h6 class="mb-0 mt-2"><i class="bi bi-people me-1"></i>Send to</h6>
        <div class="small text-muted-2">Every student of the chosen class gets it. Leave "All" to include everyone.</div></div>
      <div class="col-md-4">
        <label class="form-label" for="branch_id">Branch</label>
        <select class="form-select <?= $err('branch_id') ?>" id="branch_id" name="branch_id">
          <option value="0">All branches</option>
          <?php foreach ($branches as $b): ?>
            <option value="<?= (int)$b['id'] ?>" <?= (int)$a['branch_id'] === (int)$b['id'] ? 'selected' : '' ?>>
              <?= e($b['code']) ?> - <?= e($b['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <?= $msg('branch_id') ?>
      </div>
      <div class="col-6 col-md-4">
        <label class="form-label" for="study_year">Year</label>
        <select class="form-select" id="study_year" name="study_year">
          <option value="0">All years</option>
          <?php foreach (study_years() as $v => $label): ?>
            <option value="<?= $v ?>" <?= (int)$a['study_year'] === $v ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-4">
        <label class="form-label" for="semester">Semester</label>
        <select class="form-select" id="semester" name="semester">
          <option value="0">All semesters</option>
          <?php foreach (semesters() as $v => $label): ?>
            <option value="<?= $v ?>" <?= (int)$a['semester'] === $v ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-4">
        <label class="form-label" for="due_at">Last date of submission</label>
        <input type="datetime-local" class="form-control <?= $err('due_at') ?>" id="due_at" name="due_at"
               value="<?= e($a['due_at']) ?>" required>
        <?= $msg('due_at') ?>
      </div>
      <div class="col-6 col-md-4">
        <label class="form-label" for="max_marks">Maximum marks</label>
        <input type="number" step="0.5" min="1" class="form-control <?= $err('max_marks') ?>"
               id="max_marks" name="max_marks" value="<?= e(num((float)$a['max_marks'])) ?>">
        <?= $msg('max_marks') ?>
      </div>
      <div class="col-6 col-md-4 d-flex align-items-end">
        <div class="form-check form-switch mb-2">
          <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active"
                 value="1" <?= (int)$a['is_active'] ? 'checked' : '' ?>>
          <label class="form-check-label" for="is_active">Visible to students</label>
        </div>
      </div>
    </div>
  </div>
  <div class="card-footer bg-transparent d-flex justify-content-end gap-2 p-3">
    <a href="<?= url('faculty/assignments.php') ?>" class="btn btn-light">Cancel</a>
    <button class="btn btn-primary"><i class="bi bi-send me-1"></i><?= $isEdit ? 'Save Changes' : 'Send Assignment' ?></button>
  </div>
</form>

<?php require_once __DIR__ . '/includes/faculty_footer.php'; ?>
