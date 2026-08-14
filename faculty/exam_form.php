<?php
/**
 * Faculty - create or edit one of my exams.
 */
require_once __DIR__ . '/../includes/auth.php';
$faculty = require_faculty();

$id     = get_int('id');
$isEdit = $id > 0;
$errors = [];

$exam = [
    'title' => '', 'subject' => $faculty['department'] ?? '', 'description' => '',
    'duration_minutes' => 30, 'marks_per_question' => 1,
    'negative_marks' => 0, 'passing_marks' => 0, 'is_active' => 1,
    'access_mode' => 'assigned',
    'target_branch_id' => $faculty['branch_id'] ?? '', 'target_year' => '',
    'target_semester' => '',
];

$collegeId = (int)$faculty['college_id'];
$branches  = get_branches($collegeId);

if ($isEdit) {
    $found = get_owned_exam($id, (int)$faculty['id']);
    if (!$found) {
        set_flash('danger', 'That exam does not belong to you.');
        redirect('faculty/exams.php');
    }
    $exam = array_merge($exam, $found);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $exam['title']              = post('title');
    $exam['subject']            = post('subject');
    $exam['description']        = post('description');
    $exam['duration_minutes']   = (int)post('duration_minutes');
    $exam['marks_per_question'] = (float)post('marks_per_question');
    $exam['negative_marks']     = (float)post('negative_marks');
    $exam['passing_marks']      = (float)post('passing_marks');
    $exam['is_active']          = post('is_active') === '1' ? 1 : 0;
    $exam['access_mode']        = 'assigned';   // every paper is allotted per student
    $exam['target_branch_id']   = post('target_branch_id');
    $exam['target_year']        = post('target_year');
    $exam['target_semester']    = post('target_semester');

    $targetBranch = (int)$exam['target_branch_id'];
    if ($targetBranch && !branch_belongs_to($targetBranch, $collegeId)) {
        $errors['target_branch_id'] = 'Choose a branch offered by your college.';
    }
    $targetYear = (int)$exam['target_year'];
    $targetSem  = (int)$exam['target_semester'];

    if ($exam['title'] === '')   { $errors['title'] = 'Enter the exam title.'; }
    if ($exam['subject'] === '') { $errors['subject'] = 'Enter the subject.'; }
    if ($exam['duration_minutes'] < 1 || $exam['duration_minutes'] > 600) {
        $errors['duration_minutes'] = 'Duration must be between 1 and 600 minutes.';
    }
    if ($exam['marks_per_question'] <= 0) {
        $errors['marks_per_question'] = 'Marks per question must be greater than zero.';
    }
    if ($exam['negative_marks'] < 0) { $errors['negative_marks'] = 'Cannot be negative.'; }
    if ($exam['passing_marks'] < 0)  { $errors['passing_marks'] = 'Cannot be negative.'; }

    if (!$errors) {
        if ($isEdit) {
            db()->prepare(
                'UPDATE exams SET title=?, subject=?, description=?, duration_minutes=?,
                        marks_per_question=?, negative_marks=?, passing_marks=?,
                        is_active=?, access_mode=?, target_branch_id=?, target_year=?,
                        target_semester=?
                  WHERE id=? AND faculty_id=?'
            )->execute([
                $exam['title'], $exam['subject'], $exam['description'],
                $exam['duration_minutes'], $exam['marks_per_question'],
                $exam['negative_marks'], $exam['passing_marks'],
                $exam['is_active'], $exam['access_mode'],
                $targetBranch ?: null, $targetYear ?: null, $targetSem ?: null,
                $id, $faculty['id'],
            ]);
            set_flash('success', 'Exam updated.');
            redirect('faculty/exams.php');
        }

        db()->prepare(
            'INSERT INTO exams (college_id, faculty_id, title, subject, description,
                    duration_minutes, marks_per_question, negative_marks, passing_marks,
                    is_active, access_mode, target_branch_id, target_year, target_semester)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $collegeId, $faculty['id'], $exam['title'], $exam['subject'], $exam['description'],
            $exam['duration_minutes'], $exam['marks_per_question'],
            $exam['negative_marks'], $exam['passing_marks'],
            $exam['is_active'], $exam['access_mode'],
            $targetBranch ?: null, $targetYear ?: null, $targetSem ?: null,
        ]);
        $id = (int)db()->lastInsertId();
        set_flash('success', 'Exam created. Now add the questions.');
        redirect('faculty/questions.php?exam_id=' . $id);
    }
}

$pageTitle = $isEdit ? 'Edit Exam' : 'Create Exam';
$activeNav = 'exams';
require_once __DIR__ . '/includes/faculty_header.php';
?>

<div class="row justify-content-center">
  <div class="col-xl-9">
    <div class="card border-0">
      <div class="card-body p-4">
        <form method="post" novalidate class="needs-validation">
          <?= csrf_field() ?>

          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label" for="title">Exam Title</label>
              <input type="text" id="title" name="title" required
                     class="form-control <?= isset($errors['title']) ? 'is-invalid' : '' ?>"
                     value="<?= e($exam['title']) ?>" placeholder="e.g. Unit Test 1 - Data Structures">
              <div class="invalid-feedback"><?= e($errors['title'] ?? 'Enter a title.') ?></div>
            </div>

            <div class="col-md-4">
              <label class="form-label" for="subject">Subject</label>
              <input type="text" id="subject" name="subject" required
                     class="form-control <?= isset($errors['subject']) ? 'is-invalid' : '' ?>"
                     value="<?= e($exam['subject']) ?>" placeholder="e.g. Data Structures">
              <div class="invalid-feedback"><?= e($errors['subject'] ?? 'Enter a subject.') ?></div>
            </div>

            <div class="col-12">
              <label class="form-label" for="description">Description</label>
              <textarea id="description" name="description" rows="2" class="form-control"
                        placeholder="Shown to students on the exam card"><?= e($exam['description']) ?></textarea>
            </div>

            <div class="col-6 col-md-3">
              <label class="form-label" for="duration_minutes">Duration (minutes)</label>
              <input type="number" id="duration_minutes" name="duration_minutes"
                     min="1" max="600" required
                     class="form-control <?= isset($errors['duration_minutes']) ? 'is-invalid' : '' ?>"
                     value="<?= e($exam['duration_minutes']) ?>">
              <div class="invalid-feedback"><?= e($errors['duration_minutes'] ?? '1 - 600.') ?></div>
            </div>

            <div class="col-6 col-md-3">
              <label class="form-label" for="marks_per_question">Default Marks / Question</label>
              <input type="number" id="marks_per_question" name="marks_per_question"
                     step="0.25" min="0.25" required
                     class="form-control <?= isset($errors['marks_per_question']) ? 'is-invalid' : '' ?>"
                     value="<?= e($exam['marks_per_question']) ?>">
              <div class="invalid-feedback">
                <?= e($errors['marks_per_question'] ?? 'Greater than zero.') ?>
              </div>
            </div>

            <div class="col-6 col-md-3">
              <label class="form-label" for="negative_marks">Negative Marks / Wrong</label>
              <input type="number" id="negative_marks" name="negative_marks" step="0.25" min="0"
                     class="form-control" value="<?= e($exam['negative_marks']) ?>">
              <div class="form-text">Objective questions only</div>
            </div>

            <div class="col-6 col-md-3">
              <label class="form-label" for="passing_marks">Passing Marks</label>
              <input type="number" id="passing_marks" name="passing_marks" step="0.5" min="0"
                     class="form-control" value="<?= e($exam['passing_marks']) ?>">
              <?php if ($isEdit): ?>
                <div class="form-text">Total is <?= num((float)$exam['total_marks']) ?></div>
              <?php endif; ?>
            </div>

            <div class="col-12">
              <div class="form-section-head mb-3 mt-2">
                <span class="form-section-num"><i class="bi bi-people"></i></span>
                <div>
                  <h5 class="mb-0">Who is this paper for?</h5>
                  <span class="small text-muted-2">
                    Used to pre-select the class on the allotment screen. Leave any of
                    them blank to keep the paper open to all.
                  </span>
                </div>
              </div>
            </div>

            <div class="col-md-6">
              <label class="form-label" for="target_branch_id">Branch</label>
              <select class="form-select <?= isset($errors['target_branch_id']) ? 'is-invalid' : '' ?>"
                      id="target_branch_id" name="target_branch_id">
                <option value="">All branches</option>
                <?php foreach ($branches as $b): ?>
                  <option value="<?= (int)$b['id'] ?>"
                    <?= (int)$exam['target_branch_id'] === (int)$b['id'] ? 'selected' : '' ?>>
                    <?= e($b['name']) ?> (<?= e($b['code']) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="invalid-feedback"><?= e($errors['target_branch_id'] ?? '') ?></div>
            </div>

            <div class="col-6 col-md-3">
              <label class="form-label" for="target_year">Year</label>
              <select class="form-select" id="target_year" name="target_year">
                <option value="">All years</option>
                <?php foreach (study_years() as $v => $label): ?>
                  <option value="<?= $v ?>" <?= (int)$exam['target_year'] === $v ? 'selected' : '' ?>>
                    <?= e($label) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-6 col-md-3">
              <label class="form-label" for="target_semester">Semester</label>
              <select class="form-select" id="target_semester" name="target_semester">
                <option value="">All</option>
                <?php foreach (semesters() as $v => $label): ?>
                  <option value="<?= $v ?>" <?= (int)$exam['target_semester'] === $v ? 'selected' : '' ?>>
                    Semester <?= $v ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12">
              <div class="alert alert-info d-flex align-items-start gap-2 mb-0">
                <i class="bi bi-shield-lock fs-5"></i>
                <div>
                  <strong>This paper is visible only to the students you allot it to.</strong>
                  <span class="d-block small">
                    After saving, open <em>Assign Students</em> and tick the students who
                    may attempt it. No other student can see it on their dashboard or
                    start it.
                  </span>
                </div>
              </div>
            </div>

            <div class="col-12">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" id="is_active" name="is_active"
                       value="1" <?= (int)$exam['is_active'] ? 'checked' : '' ?>>
                <label class="form-check-label" for="is_active">
                  Published - students can start it
                </label>
              </div>
            </div>
          </div>

          <hr class="my-4">
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-check2 me-1"></i><?= $isEdit ? 'Save Changes' : 'Create Exam' ?>
            </button>
            <a href="<?= url('faculty/exams.php') ?>" class="btn btn-outline-secondary">Cancel</a>
            <?php if ($isEdit): ?>
              <a href="<?= url('faculty/questions.php?exam_id=' . $id) ?>"
                 class="btn btn-outline-primary ms-auto">
                <i class="bi bi-list-check me-1"></i>Manage Questions
              </a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/faculty_footer.php'; ?>
