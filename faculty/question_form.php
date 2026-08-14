<?php
/**
 * Faculty - type or edit a single question.
 * Supports Multiple Choice, True/False and Subjective (written answer).
 */
require_once __DIR__ . '/../includes/auth.php';
$faculty = require_faculty();

$id     = get_int('id');
$isEdit = $id > 0;
$errors = [];

$q = [
    'exam_id'      => get_int('exam_id'),
    'question_text' => '',
    'type'         => 'mcq',
    'category'     => '',
    'difficulty'   => 'easy',
    'marks'        => 1,
    'explanation'  => '',
    'model_answer' => '',
    'max_words'    => '',
    'sort_order'   => 0,
];
$options = ['', '', '', ''];
$correct = 0;

if ($isEdit) {
    $st = db()->prepare('SELECT q.* FROM questions q JOIN exams e ON e.id = q.exam_id
                          WHERE q.id = ? AND e.faculty_id = ?');
    $st->execute([$id, $faculty['id']]);
    $found = $st->fetch();
    if (!$found) {
        set_flash('danger', 'That question does not belong to you.');
        redirect('faculty/exams.php');
    }
    $q = array_merge($q, $found);

    $os = db()->prepare('SELECT * FROM options WHERE question_id = ? ORDER BY option_order, id');
    $os->execute([$id]);
    $options = [];
    foreach ($os->fetchAll() as $i => $o) {
        $options[] = $o['option_text'];
        if ((int)$o['is_correct'] === 1) { $correct = $i; }
    }
    $options = array_pad($options, 4, '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $q['exam_id']       = (int)post('exam_id');
    $q['question_text'] = post('question_text');
    $q['type']          = in_array(post('type'), ['mcq', 'truefalse', 'subjective'], true)
                          ? post('type') : 'mcq';
    $q['category']      = post('category');
    $q['difficulty']    = in_array(post('difficulty'), ['easy', 'medium', 'hard'], true)
                          ? post('difficulty') : 'easy';
    $q['marks']         = (float)post('marks');
    $q['explanation']   = post('explanation');
    $q['model_answer']  = post('model_answer');
    $q['max_words']     = post('max_words');
    $q['sort_order']    = (int)post('sort_order');
    $correct            = (int)post('correct');

    if ($q['type'] === 'truefalse') {
        $options = ['True', 'False'];
        if ($correct > 1) { $correct = 0; }
    } elseif ($q['type'] === 'subjective') {
        $options = [];
    } else {
        $posted = $_POST['options'] ?? [];
        if (!is_array($posted)) { $posted = []; }
        $options = [];
        for ($i = 0; $i < 4; $i++) {
            $options[$i] = trim((string)($posted[$i] ?? ''));
        }
    }

    // The exam must be one of mine.
    if (!$q['exam_id'] || !get_owned_exam((int)$q['exam_id'], (int)$faculty['id'])) {
        $errors['exam_id'] = 'Choose one of your own exams.';
    }
    if ($q['question_text'] === '') {
        $errors['question_text'] = 'Enter the question text.';
    }
    if ($q['marks'] <= 0) {
        $errors['marks'] = 'Marks must be greater than zero.';
    }
    if ($q['type'] !== 'subjective') {
        foreach ($options as $opt) {
            if ($opt === '') { $errors['options'] = 'All answer options are required.'; }
        }
        if (!isset($options[$correct])) {
            $errors['correct'] = 'Select the correct answer.';
        }
    }

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $maxWords = $q['max_words'] !== '' ? (int)$q['max_words'] : null;

            if ($isEdit) {
                $pdo->prepare(
                    'UPDATE questions SET exam_id=?, question_text=?, type=?, category=?,
                            difficulty=?, marks=?, explanation=?, model_answer=?, max_words=?,
                            sort_order=? WHERE id=?'
                )->execute([
                    $q['exam_id'], $q['question_text'], $q['type'], $q['category'] ?: null,
                    $q['difficulty'], $q['marks'], $q['explanation'] ?: null,
                    $q['model_answer'] ?: null, $maxWords, $q['sort_order'], $id,
                ]);
                $pdo->prepare('DELETE FROM options WHERE question_id = ?')->execute([$id]);
            } else {
                if ($q['sort_order'] === 0) {
                    $next = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0)+1
                                             FROM questions WHERE exam_id = ?');
                    $next->execute([$q['exam_id']]);
                    $q['sort_order'] = (int)$next->fetchColumn();
                }
                $pdo->prepare(
                    'INSERT INTO questions (exam_id, question_text, type, category, difficulty,
                            marks, explanation, model_answer, max_words, sort_order)
                     VALUES (?,?,?,?,?,?,?,?,?,?)'
                )->execute([
                    $q['exam_id'], $q['question_text'], $q['type'], $q['category'] ?: null,
                    $q['difficulty'], $q['marks'], $q['explanation'] ?: null,
                    $q['model_answer'] ?: null, $maxWords, $q['sort_order'],
                ]);
                $id = (int)$pdo->lastInsertId();
            }

            if ($options) {
                $ins = $pdo->prepare(
                    'INSERT INTO options (question_id, option_text, is_correct, option_order)
                     VALUES (?,?,?,?)'
                );
                foreach ($options as $i => $text) {
                    $ins->execute([$id, $text, $i === $correct ? 1 : 0, $i + 1]);
                }
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            $errors['general'] = 'Could not save the question. Please try again.';
        }

        if (!$errors) {
            set_flash('success', $isEdit ? 'Question updated.' : 'Question added.');
            redirect('faculty/questions.php?exam_id=' . $q['exam_id']);
        }
    }
}

$exams = get_faculty_exams((int)$faculty['id']);
$pageTitle = $isEdit ? 'Edit Question' : 'Type a Question';
$activeNav = 'exams';
require_once __DIR__ . '/includes/faculty_header.php';
?>

<div class="row justify-content-center">
  <div class="col-xl-9">
    <div class="card border-0">
      <div class="card-body p-4">

        <?php if (isset($errors['general'])): ?>
          <div class="alert alert-danger"><?= e($errors['general']) ?></div>
        <?php endif; ?>

        <form method="post" novalidate class="needs-validation">
          <?= csrf_field() ?>

          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label" for="exam_id">Exam</label>
              <select class="form-select <?= isset($errors['exam_id']) ? 'is-invalid' : '' ?>"
                      id="exam_id" name="exam_id" required>
                <option value="">-- select --</option>
                <?php foreach ($exams as $ex): ?>
                  <option value="<?= (int)$ex['id'] ?>"
                    <?= (int)$q['exam_id'] === (int)$ex['id'] ? 'selected' : '' ?>>
                    <?= e($ex['title']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="invalid-feedback"><?= e($errors['exam_id'] ?? 'Choose an exam.') ?></div>
            </div>

            <div class="col-md-3">
              <label class="form-label" for="type">Question Type</label>
              <select class="form-select" id="type" name="type">
                <option value="mcq" <?= $q['type'] === 'mcq' ? 'selected' : '' ?>>
                  Objective - Multiple Choice</option>
                <option value="truefalse" <?= $q['type'] === 'truefalse' ? 'selected' : '' ?>>
                  Objective - True / False</option>
                <option value="subjective" <?= $q['type'] === 'subjective' ? 'selected' : '' ?>>
                  Subjective - Written Answer</option>
              </select>
            </div>

            <div class="col-md-3">
              <label class="form-label" for="difficulty">Difficulty</label>
              <select class="form-select" id="difficulty" name="difficulty">
                <?php foreach (['easy', 'medium', 'hard'] as $d): ?>
                  <option value="<?= $d ?>" <?= $q['difficulty'] === $d ? 'selected' : '' ?>>
                    <?= ucfirst($d) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12">
              <label class="form-label" for="question_text">Question Text</label>
              <textarea id="question_text" name="question_text" rows="3" required
                        class="form-control <?= isset($errors['question_text']) ? 'is-invalid' : '' ?>"
                        placeholder="Type the question here"><?= e($q['question_text']) ?></textarea>
              <div class="invalid-feedback">
                <?= e($errors['question_text'] ?? 'Enter the question.') ?>
              </div>
            </div>

            <!-- ------------------ Objective: options ------------------ -->
            <div class="col-12" id="optionsBlock">
              <label class="form-label d-block">
                Answer Options
                <span class="text-muted-2 fw-normal small">
                  - select the radio button next to the correct answer
                </span>
              </label>
              <?php if (isset($errors['options']) || isset($errors['correct'])): ?>
                <div class="alert alert-danger py-2 small">
                  <?= e($errors['options'] ?? $errors['correct']) ?>
                </div>
              <?php endif; ?>

              <div id="optionRows">
                <?php foreach (['A', 'B', 'C', 'D'] as $i => $letter): ?>
                  <div class="input-group mb-2 option-row" data-row="<?= $i ?>">
                    <div class="input-group-text">
                      <input class="form-check-input mt-0" type="radio" name="correct"
                             value="<?= $i ?>" <?= $correct === $i ? 'checked' : '' ?>
                             aria-label="Option <?= $letter ?> is correct">
                    </div>
                    <span class="input-group-text fw-bold"><?= $letter ?></span>
                    <input type="text" class="form-control" name="options[<?= $i ?>]"
                           value="<?= e($options[$i] ?? '') ?>" placeholder="Option <?= $letter ?>">
                  </div>
                <?php endforeach; ?>
              </div>
              <div class="form-text" id="tfHint" hidden>
                True/False questions always use the fixed options
                <strong>True</strong> and <strong>False</strong>.
              </div>
            </div>

            <!-- --------------- Subjective: model answer --------------- -->
            <div class="col-12" id="subjectiveBlock" hidden>
              <div class="alert alert-info py-2 small mb-3">
                <i class="bi bi-info-circle me-1"></i>
                A written answer cannot be marked automatically. After the students submit,
                you award the marks yourself from <strong>Evaluate Answers</strong>.
              </div>
              <label class="form-label" for="model_answer">
                Model Answer / Key Points
                <span class="text-muted-2 fw-normal small">(only you can see this)</span>
              </label>
              <textarea id="model_answer" name="model_answer" rows="4" class="form-control"
                        placeholder="The points you expect in a full marks answer"><?= e($q['model_answer'] ?? '') ?></textarea>
            </div>

            <div class="col-6 col-md-3">
              <label class="form-label" for="marks">Marks</label>
              <input type="number" step="0.25" min="0.25" id="marks" name="marks" required
                     class="form-control <?= isset($errors['marks']) ? 'is-invalid' : '' ?>"
                     value="<?= e($q['marks']) ?>">
              <div class="invalid-feedback"><?= e($errors['marks'] ?? 'Enter the marks.') ?></div>
            </div>

            <div class="col-6 col-md-3" id="maxWordsBlock" hidden>
              <label class="form-label" for="max_words">Word limit</label>
              <input type="number" min="0" id="max_words" name="max_words" class="form-control"
                     value="<?= e($q['max_words'] ?? '') ?>" placeholder="e.g. 150">
              <div class="form-text">Blank = no limit</div>
            </div>

            <div class="col-md-3">
              <label class="form-label" for="category">Category / Topic</label>
              <input type="text" id="category" name="category" class="form-control"
                     value="<?= e($q['category'] ?? '') ?>" placeholder="e.g. Arrays">
            </div>

            <div class="col-md-3">
              <label class="form-label" for="sort_order">Order in paper</label>
              <input type="number" min="0" id="sort_order" name="sort_order" class="form-control"
                     value="<?= e($q['sort_order']) ?>">
              <div class="form-text">0 = at the end</div>
            </div>

            <div class="col-12" id="explanationBlock">
              <label class="form-label" for="explanation">Explanation
                <span class="text-muted-2 fw-normal small">(shown to the student during review)</span>
              </label>
              <textarea id="explanation" name="explanation" rows="2" class="form-control"
                        placeholder="Why this answer is correct"><?= e($q['explanation'] ?? '') ?></textarea>
            </div>
          </div>

          <hr class="my-4">
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-check2 me-1"></i><?= $isEdit ? 'Save Changes' : 'Add Question' ?>
            </button>
            <a href="<?= url('faculty/questions.php?exam_id=' . (int)$q['exam_id']) ?>"
               class="btn btn-outline-secondary">Cancel</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
/* Show the right fields for the chosen question type. */
(function () {
  var type    = document.getElementById('type');
  var rows    = Array.prototype.slice.call(document.querySelectorAll('.option-row'));
  var hint    = document.getElementById('tfHint');
  var optBlk  = document.getElementById('optionsBlock');
  var subBlk  = document.getElementById('subjectiveBlock');
  var wordBlk = document.getElementById('maxWordsBlock');

  function apply() {
    var isSub = type.value === 'subjective';
    var isTF  = type.value === 'truefalse';

    optBlk.hidden  = isSub;
    subBlk.hidden  = !isSub;
    wordBlk.hidden = !isSub;
    hint.hidden    = !isTF;

    rows.forEach(function (row, i) {
      var text  = row.querySelector('input[type="text"]');
      var radio = row.querySelector('input[type="radio"]');
      var hide  = isSub || (isTF && i > 1);

      row.hidden = hide;
      text.disabled = hide;
      radio.disabled = hide;

      if (isTF && i < 2) {
        text.value = i === 0 ? 'True' : 'False';
        text.readOnly = true;
      } else if (!isSub) {
        text.readOnly = false;
      }
      if (hide && radio.checked && !isSub) {
        rows[0].querySelector('input[type="radio"]').checked = true;
      }
    });
  }

  type.addEventListener('change', apply);
  apply();
})();
</script>

<?php require_once __DIR__ . '/includes/faculty_footer.php'; ?>
