<?php
/**
 * Faculty - import questions from a PDF / Word / text file, or from pasted text.
 *
 * Three stages, all on this one page:
 *   1. form    - upload a file or paste the paper
 *   2. preview - the parsed questions shown as editable fields to check and fix
 *   3. save    - write the confirmed questions into the exam
 *
 * Nothing is written to the database until the faculty confirms stage 2, so a
 * badly formatted PDF can never quietly corrupt a question bank.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/doc_text.php';
require_once __DIR__ . '/../includes/question_parser.php';

$faculty = require_faculty();

$examId = get_int('exam_id') ?: (int)post('exam_id');
$exam   = get_owned_exam($examId, (int)$faculty['id']);
if (!$exam) {
    set_flash('danger', 'Choose one of your own exams to import into.');
    redirect('faculty/exams.php');
}

$stage    = 'form';
$parsed   = [];
$warning  = '';
$error    = '';
$rawText  = '';
$sourceName = '';
$sourceType = 'typed';

const MAX_UPLOAD_BYTES = 8 * 1024 * 1024;   // 8 MB is plenty for a question paper

/* ------------------------------------------------------------------ */
/*  Stage 2 - parse the upload or the pasted text                      */
/* ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('stage') === 'parse') {
    verify_csrf();

    $rawText = post('raw_text');

    if (!empty($_FILES['paper']['name'])) {
        $file = $_FILES['paper'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $error = match ($file['error']) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'That file is too large.',
                UPLOAD_ERR_PARTIAL   => 'The upload did not finish. Please try again.',
                UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'The server cannot store the upload.',
                default => 'The file could not be uploaded.',
            };
        } elseif (!is_uploaded_file($file['tmp_name'])) {
            $error = 'Invalid upload.';
        } elseif ($file['size'] > MAX_UPLOAD_BYTES) {
            $error = 'That file is larger than 8 MB.';
        } else {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['pdf', 'docx', 'txt', 'text', 'md'], true)) {
                $error = 'Only PDF, DOCX and TXT files can be imported.';
            } else {
                $extracted  = extract_document_text($file['tmp_name'], $file['name']);
                $rawText    = $extracted['text'];
                $warning    = $extracted['warning'];
                $sourceName = $file['name'];
                $sourceType = $ext === 'text' || $ext === 'md' ? 'txt' : $ext;
            }
        }
    }

    if (!$error && trim($rawText) === '') {
        $error = $error ?: 'Nothing to import - upload a file or paste the questions.';
    }

    if (!$error) {
        $parsed = parse_questions($rawText);
        if (!$parsed) {
            $error = 'No questions could be recognised. Check the format shown below, '
                   . 'then paste the text and try again.';
        } else {
            $stage = 'preview';
        }
    }
}

/* ------------------------------------------------------------------ */
/*  Stage 3 - save the confirmed questions                             */
/* ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('stage') === 'save') {
    verify_csrf();

    $rows       = $_POST['q'] ?? [];
    $sourceName = post('source_name');
    $sourceType = post('source_type') ?: 'typed';

    if (!is_array($rows)) { $rows = []; }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $next = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0) FROM questions WHERE exam_id = ?');
        $next->execute([$examId]);
        $order = (int)$next->fetchColumn();

        $insQ = $pdo->prepare(
            'INSERT INTO questions (exam_id, question_text, type, category, difficulty,
                    marks, explanation, model_answer, sort_order)
             VALUES (?,?,?,?,?,?,?,?,?)'
        );
        $insO = $pdo->prepare(
            'INSERT INTO options (question_id, option_text, is_correct, option_order)
             VALUES (?,?,?,?)'
        );

        $imported = 0;
        $skipped  = 0;

        foreach ($rows as $row) {
            if (!empty($row['skip'])) { $skipped++; continue; }

            $text = trim((string)($row['text'] ?? ''));
            if ($text === '') { $skipped++; continue; }

            $type = in_array($row['type'] ?? '', ['mcq', 'truefalse', 'subjective'], true)
                    ? $row['type'] : 'mcq';
            $marks = (float)($row['marks'] ?? 1);
            if ($marks <= 0) { $marks = 1; }

            $options = [];
            $correct = (int)($row['correct'] ?? 0);

            if ($type === 'truefalse') {
                $options = ['True', 'False'];
                if ($correct > 1) { $correct = 0; }
            } elseif ($type === 'mcq') {
                foreach ((array)($row['options'] ?? []) as $opt) {
                    $opt = trim((string)$opt);
                    if ($opt !== '') { $options[] = $opt; }
                }
                // An objective question with too few options, or with no answer
                // key chosen, cannot be graded - skip it rather than store it broken.
                if (count($options) < 2 || !isset($options[$correct])) {
                    $skipped++;
                    continue;
                }
            }

            $insQ->execute([
                $examId, $text, $type,
                trim((string)($row['category'] ?? '')) ?: null,
                in_array($row['difficulty'] ?? '', ['easy', 'medium', 'hard'], true)
                    ? $row['difficulty'] : 'easy',
                $marks,
                trim((string)($row['explanation'] ?? '')) ?: null,
                trim((string)($row['model_answer'] ?? '')) ?: null,
                ++$order,
            ]);
            $qid = (int)$pdo->lastInsertId();

            foreach ($options as $i => $opt) {
                $insO->execute([$qid, $opt, $i === $correct ? 1 : 0, $i + 1]);
            }
            $imported++;
        }

        $pdo->prepare(
            'INSERT INTO question_imports (exam_id, faculty_id, source_name, source_type, imported)
             VALUES (?,?,?,?,?)'
        )->execute([$examId, $faculty['id'], $sourceName ?: 'Pasted text', $sourceType, $imported]);

        $pdo->commit();

        set_flash($imported > 0 ? 'success' : 'warning',
            $imported . ' question(s) imported'
            . ($skipped > 0 ? ', ' . $skipped . ' skipped' : '') . '.');
        redirect('faculty/questions.php?exam_id=' . $examId);

    } catch (Throwable $ex) {
        $pdo->rollBack();
        $error = 'The import failed and nothing was saved. Please try again.';
    }
}

$pageTitle = 'Import Questions';
$activeNav = 'exams';
require_once __DIR__ . '/includes/faculty_header.php';
?>

<div class="card border-0 mb-3">
  <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div>
      <h5 class="mb-1">Importing into: <?= e($exam['title']) ?></h5>
      <div class="small text-muted-2">
        <?= e($exam['subject']) ?> &middot;
        currently <?= (int)$exam['question_count'] ?> question(s)
      </div>
    </div>
    <a href="<?= url('faculty/questions.php?exam_id=' . $examId) ?>" class="btn btn-outline-secondary">
      <i class="bi bi-arrow-left me-1"></i>Back to questions
    </a>
  </div>
</div>

<?php if ($error): ?>
  <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i><?= e($error) ?></div>
<?php endif; ?>

<?php if ($stage === 'form'): ?>
  <!-- =================== STAGE 1: upload or paste =================== -->
  <div class="row g-4">
    <div class="col-lg-7">
      <form method="post" enctype="multipart/form-data" class="card border-0 h-100">
        <div class="card-body p-4">
          <?= csrf_field() ?>
          <input type="hidden" name="stage" value="parse">
          <input type="hidden" name="exam_id" value="<?= $examId ?>">

          <h5 class="mb-3"><i class="bi bi-file-earmark-arrow-up me-2 text-primary"></i>
            Upload a question paper</h5>
          <div class="mb-3">
            <input type="file" class="form-control" name="paper" id="paper"
                   accept=".pdf,.docx,.txt,.md">
            <div class="form-text">PDF, Word (.docx) or plain text, up to 8 MB.</div>
          </div>

          <div class="text-center text-muted-2 my-3 small">- OR -</div>

          <h5 class="mb-3"><i class="bi bi-keyboard me-2 text-primary"></i>Paste the questions</h5>
          <textarea name="raw_text" rows="12" class="form-control font-monospace"
                    style="font-size:.85rem"
                    placeholder="Paste or type the question paper here..."><?= e($rawText) ?></textarea>

          <button class="btn btn-primary w-100 mt-3 py-2">
            <i class="bi bi-magic me-1"></i>Read Questions
          </button>
          <p class="small text-muted-2 text-center mt-2 mb-0">
            You will be able to check and edit everything before it is saved.
          </p>
        </div>
      </form>
    </div>

    <div class="col-lg-5">
      <div class="card border-0 h-100">
        <div class="card-body p-4">
          <h5 class="mb-3"><i class="bi bi-info-circle me-2 text-primary"></i>Accepted format</h5>
          <p class="small text-muted-2">
            Keep each question on its own block. Numbering may be
            <code>Q1.</code>, <code>1.</code> or <code>1)</code>; options may be
            <code>A)</code>, <code>A.</code> or <code>(A)</code>.
          </p>
          <pre class="bg-light border rounded p-3 small mb-3"
               style="max-height:340px;overflow:auto"><?= e(sample_question_format()) ?></pre>
          <ul class="small text-muted-2 mb-0">
            <li>A question with <strong>no options</strong> becomes a written
                (subjective) question automatically.</li>
            <li>Tag one explicitly with <code>[Subjective]</code> if you prefer.</li>
            <li><code>Answer:</code>, <code>Marks:</code>, <code>Explanation:</code>,
                <code>Category:</code> and <code>Difficulty:</code> are all optional.</li>
            <li>A scanned PDF (a photo of a page) has no text in it - paste the
                questions instead.</li>
          </ul>
        </div>
      </div>
    </div>
  </div>

<?php else: ?>
  <!-- =================== STAGE 2: preview and edit =================== -->
  <?php
  $withWarnings = count(array_filter($parsed, fn($q) => !empty($q['warnings'])));
  $objective    = count(array_filter($parsed, fn($q) => $q['type'] !== 'subjective'));
  ?>

  <?php if ($warning): ?>
    <div class="alert alert-warning">
      <i class="bi bi-exclamation-triangle me-1"></i><?= e($warning) ?>
    </div>
  <?php endif; ?>

  <div class="alert alert-info d-flex flex-wrap gap-3 align-items-center">
    <i class="bi bi-eyeglasses fs-4"></i>
    <div class="flex-grow-1">
      <strong><?= count($parsed) ?> question(s) read</strong>
      (<?= $objective ?> objective, <?= count($parsed) - $objective ?> written)
      <?php if ($sourceName): ?>from <em><?= e($sourceName) ?></em><?php endif; ?>.
      Nothing has been saved yet - check each one below and press
      <strong>Import</strong> at the bottom.
    </div>
    <?php if ($withWarnings > 0): ?>
      <span class="badge bg-warning text-dark"><?= $withWarnings ?> need attention</span>
    <?php endif; ?>
  </div>

  <form method="post" class="needs-validation" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="stage" value="save">
    <input type="hidden" name="exam_id" value="<?= $examId ?>">
    <input type="hidden" name="source_name" value="<?= e($sourceName) ?>">
    <input type="hidden" name="source_type" value="<?= e($sourceType) ?>">

    <?php foreach ($parsed as $i => $q): ?>
      <div class="card border-0 mb-3 import-question <?= $q['warnings'] ? 'border-start border-4 border-warning' : '' ?>">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
            <h6 class="mb-0">Question <?= $i + 1 ?></h6>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="skip<?= $i ?>"
                     name="q[<?= $i ?>][skip]" value="1">
              <label class="form-check-label small text-danger" for="skip<?= $i ?>">
                Do not import this one
              </label>
            </div>
          </div>

          <?php foreach ($q['warnings'] as $w): ?>
            <div class="alert alert-warning py-2 small">
              <i class="bi bi-exclamation-triangle me-1"></i><?= e($w) ?>
            </div>
          <?php endforeach; ?>

          <div class="row g-3">
            <div class="col-12">
              <label class="form-label small">Question text</label>
              <textarea name="q[<?= $i ?>][text]" rows="2" class="form-control"><?= e($q['text']) ?></textarea>
            </div>

            <div class="col-md-3">
              <label class="form-label small">Type</label>
              <select name="q[<?= $i ?>][type]" class="form-select import-type">
                <option value="mcq" <?= $q['type'] === 'mcq' ? 'selected' : '' ?>>Multiple Choice</option>
                <option value="truefalse" <?= $q['type'] === 'truefalse' ? 'selected' : '' ?>>True / False</option>
                <option value="subjective" <?= $q['type'] === 'subjective' ? 'selected' : '' ?>>Written</option>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label small">Marks</label>
              <input type="number" step="0.25" min="0.25" name="q[<?= $i ?>][marks]"
                     class="form-control" value="<?= e(num((float)$q['marks'])) ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label small">Category</label>
              <input type="text" name="q[<?= $i ?>][category]" class="form-control"
                     value="<?= e($q['category']) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label small">Difficulty</label>
              <select name="q[<?= $i ?>][difficulty]" class="form-select">
                <?php foreach (['easy', 'medium', 'hard'] as $d): ?>
                  <option value="<?= $d ?>" <?= $q['difficulty'] === $d ? 'selected' : '' ?>>
                    <?= ucfirst($d) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12 import-options">
              <label class="form-label small">Options - pick the correct one</label>
              <?php
              $opts = $q['type'] === 'truefalse' ? ['True', 'False'] : $q['options'];
              $opts = array_pad($opts, $q['type'] === 'truefalse' ? 2 : 4, '');
              foreach ($opts as $k => $opt):
                  $letter = chr(65 + $k);
              ?>
                <div class="input-group mb-2">
                  <div class="input-group-text">
                    <input class="form-check-input mt-0" type="radio"
                           name="q[<?= $i ?>][correct]" value="<?= $k ?>"
                           <?= $q['correct'] === $k ? 'checked' : '' ?>
                           aria-label="Option <?= $letter ?> is correct">
                  </div>
                  <span class="input-group-text fw-bold"><?= $letter ?></span>
                  <input type="text" class="form-control" name="q[<?= $i ?>][options][<?= $k ?>]"
                         value="<?= e($opt) ?>" placeholder="Option <?= $letter ?>">
                </div>
              <?php endforeach; ?>
            </div>

            <div class="col-12 import-model" <?= $q['type'] === 'subjective' ? '' : 'hidden' ?>>
              <label class="form-label small">Model answer / key points (only you see this)</label>
              <textarea name="q[<?= $i ?>][model_answer]" rows="2"
                        class="form-control"><?= e($q['model_answer']) ?></textarea>
            </div>

            <div class="col-12">
              <label class="form-label small">Explanation (shown to students during review)</label>
              <textarea name="q[<?= $i ?>][explanation]" rows="2"
                        class="form-control"><?= e($q['explanation']) ?></textarea>
            </div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>

    <div class="card border-0 sticky-bottom">
      <div class="card-body d-flex flex-wrap gap-2 align-items-center">
        <button class="btn btn-primary">
          <i class="bi bi-download me-1"></i>Import <?= count($parsed) ?> Question(s)
        </button>
        <a href="<?= url('faculty/import.php?exam_id=' . $examId) ?>"
           class="btn btn-outline-secondary">Start over</a>
        <span class="small text-muted-2 ms-auto">
          Objective questions with no correct option selected are skipped automatically.
        </span>
      </div>
    </div>
  </form>

  <script>
  /* Show options or the model answer box depending on the chosen type. */
  document.querySelectorAll('.import-question').forEach(function (card) {
    var type  = card.querySelector('.import-type');
    var opts  = card.querySelector('.import-options');
    var model = card.querySelector('.import-model');
    function apply() {
      var isSub = type.value === 'subjective';
      opts.hidden  = isSub;
      model.hidden = !isSub;
      card.querySelectorAll('.import-options input').forEach(function (el, idx) {
        // True/False keeps only the first two rows usable.
        var row = Math.floor(idx / 2);
        var hide = isSub || (type.value === 'truefalse' && row > 1);
        el.disabled = hide;
        if (el.type === 'text' && type.value === 'truefalse' && row < 2) {
          el.value = row === 0 ? 'True' : 'False';
        }
      });
    }
    type.addEventListener('change', apply);
    apply();
  });
  </script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/faculty_footer.php'; ?>
