<?php
/**
 * Faculty - award marks for written (subjective) answers.
 *
 *   evaluate.php                -> every paper of mine waiting for evaluation
 *   evaluate.php?exam_id=N      -> the same list, filtered to one exam
 *   evaluate.php?attempt=N      -> mark one student's paper
 *
 * As soon as a paper is fully marked, recalc_result() folds the awarded marks
 * into the result and recomputes pass / fail.
 */
require_once __DIR__ . '/../includes/auth.php';
$faculty = require_faculty();

$attemptId = get_int('attempt');

/* ------------------------------------------------------------------ */
/*  Save the marks                                                     */
/* ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $attemptId = (int)post('attempt_id');

    // The attempt must belong to an exam this faculty member owns.
    $st = db()->prepare(
        'SELECT a.*, e.faculty_id FROM attempts a
           JOIN exams e ON e.id = a.exam_id
          WHERE a.id = ? AND e.faculty_id = ?'
    );
    $st->execute([$attemptId, $faculty['id']]);
    $attempt = $st->fetch();

    if (!$attempt) {
        set_flash('danger', 'That paper does not belong to your exam.');
        redirect('faculty/evaluate.php');
    }
    if ($attempt['status'] === 'in_progress') {
        set_flash('warning', 'That student has not submitted yet.');
        redirect('faculty/evaluate.php');
    }

    $marks    = $_POST['marks'] ?? [];
    $feedback = $_POST['feedback'] ?? [];
    if (!is_array($marks))    { $marks = []; }
    if (!is_array($feedback)) { $feedback = []; }

    // Only questions that really belong to this attempt, with their maximum.
    $qs = db()->prepare(
        "SELECT a.id AS answer_id, q.id AS question_id, q.marks
           FROM answers a JOIN questions q ON q.id = a.question_id
          WHERE a.attempt_id = ? AND q.type = 'subjective'"
    );
    $qs->execute([$attemptId]);

    $upd = db()->prepare(
        'UPDATE answers SET awarded_marks = ?, feedback = ?, evaluated_by = ?, evaluated_at = ?
          WHERE id = ?'
    );
    $now = date('Y-m-d H:i:s');
    $saved = 0;

    foreach ($qs->fetchAll() as $row) {
        $qid = (int)$row['question_id'];
        if (!array_key_exists($qid, $marks) || trim((string)$marks[$qid]) === '') {
            continue;                                   // left blank = not marked yet
        }
        $awarded = (float)$marks[$qid];
        $awarded = max(0, min($awarded, (float)$row['marks']));   // clamp to 0..max
        $note    = mb_substr(trim((string)($feedback[$qid] ?? '')), 0, 500);

        $upd->execute([$awarded, $note ?: null, $faculty['id'], $now, $row['answer_id']]);
        $saved++;
    }

    $result = recalc_result($attemptId);

    set_flash('success', $saved . ' answer(s) marked.'
        . ($result && !(int)$result['pending_evaluation']
            ? ' This paper is fully evaluated - final score '
              . num((float)$result['obtained_marks']) . '/' . num((float)$result['total_marks'])
              . ' (' . $result['status'] . ').'
            : ' Some answers are still unmarked.'));

    redirect(post('next') === 'list'
        ? 'faculty/evaluate.php' . (post('exam_id') ? '?exam_id=' . (int)post('exam_id') : '')
        : 'faculty/evaluate.php?attempt=' . $attemptId);
}

/* ------------------------------------------------------------------ */
/*  Single paper                                                       */
/* ------------------------------------------------------------------ */
if ($attemptId > 0) {
    $st = db()->prepare(
        'SELECT a.*, s.name AS student_name, s.email, e.title AS exam_title,
                e.subject, e.id AS exam_id, e.passing_marks
           FROM attempts a
           JOIN students s ON s.id = a.student_id
           JOIN exams e ON e.id = a.exam_id
          WHERE a.id = ? AND e.faculty_id = ?'
    );
    $st->execute([$attemptId, $faculty['id']]);
    $attempt = $st->fetch();

    if (!$attempt) {
        set_flash('danger', 'That paper does not belong to your exam.');
        redirect('faculty/evaluate.php');
    }

    $qs = db()->prepare(
        "SELECT q.id, q.question_text, q.marks, q.model_answer, q.max_words, q.category,
                a.answer_text, a.awarded_marks, a.feedback, a.evaluated_at
           FROM questions q
      LEFT JOIN answers a ON a.question_id = q.id AND a.attempt_id = ?
          WHERE q.exam_id = ? AND q.type = 'subjective'
       ORDER BY q.sort_order, q.id"
    );
    $qs->execute([$attemptId, $attempt['exam_id']]);
    $questions = $qs->fetchAll();

    $rs = db()->prepare('SELECT * FROM results WHERE attempt_id = ?');
    $rs->execute([$attemptId]);
    $result = $rs->fetch();

    $pageTitle = 'Evaluate - ' . $attempt['student_name'];
    $activeNav = 'evaluate';
    require_once __DIR__ . '/includes/faculty_header.php';
    ?>

    <div class="card border-0 mb-3">
      <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
          <h5 class="mb-1"><?= e($attempt['student_name']) ?></h5>
          <div class="small text-muted-2">
            <?= e($attempt['email']) ?> &middot; <?= e($attempt['exam_title']) ?>
            &middot; submitted <?= e(format_datetime($attempt['submitted_at'])) ?>
          </div>
        </div>
        <?php if ($result): ?>
          <div class="text-end">
            <div class="small text-muted-2">Objective marks already scored</div>
            <div class="fs-5 fw-bold">
              <?= num((float)$result['objective_marks']) ?>
              <span class="text-muted-2 fs-6">
                / <?= num((float)$result['total_marks'] - (float)$result['subjective_total']) ?>
              </span>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <form method="post" class="needs-validation" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="attempt_id" value="<?= $attemptId ?>">
      <input type="hidden" name="exam_id" value="<?= (int)$attempt['exam_id'] ?>">

      <?php foreach ($questions as $i => $q): ?>
        <div class="card border-0 mb-3">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
              <h6 class="mb-0">Question <?= $i + 1 ?>
                <?php if ($q['category']): ?>
                  <span class="badge bg-light text-muted-2 border ms-1"><?= e($q['category']) ?></span>
                <?php endif; ?>
              </h6>
              <span class="badge <?= $q['evaluated_at'] ? 'bg-success' : 'bg-warning text-dark' ?>">
                <?= $q['evaluated_at'] ? 'Marked' : 'Not marked' ?>
              </span>
            </div>

            <p class="question-text mb-3"><?= e($q['question_text']) ?></p>

            <div class="row g-3">
              <div class="col-lg-7">
                <label class="form-label small text-muted-2">Student's answer</label>
                <div class="border rounded p-3 bg-light"
                     style="white-space:pre-wrap;max-height:340px;overflow:auto">
                  <?php $ans = trim((string)$q['answer_text']); ?>
                  <?= $ans !== '' ? e($ans)
                        : '<em class="text-danger">Not answered</em>' ?>
                </div>
                <div class="small text-muted-2 mt-1">
                  <?= $ans === '' ? 0 : str_word_count($ans) ?> words
                  <?php if ((int)$q['max_words'] > 0): ?>
                    / limit <?= (int)$q['max_words'] ?>
                  <?php endif; ?>
                </div>
              </div>

              <div class="col-lg-5">
                <?php if ($q['model_answer']): ?>
                  <label class="form-label small text-muted-2">Your model answer</label>
                  <div class="explanation-box mb-3" style="white-space:pre-wrap">
                    <?= e($q['model_answer']) ?>
                  </div>
                <?php endif; ?>

                <label class="form-label small" for="m<?= (int)$q['id'] ?>">
                  Marks awarded (out of <?= num((float)$q['marks']) ?>)
                </label>
                <div class="input-group mb-2">
                  <input type="number" step="0.25" min="0" max="<?= e($q['marks']) ?>"
                         class="form-control" id="m<?= (int)$q['id'] ?>"
                         name="marks[<?= (int)$q['id'] ?>]"
                         value="<?= $q['awarded_marks'] !== null ? e(num((float)$q['awarded_marks'])) : '' ?>"
                         placeholder="0 - <?= num((float)$q['marks']) ?>">
                  <span class="input-group-text">/ <?= num((float)$q['marks']) ?></span>
                </div>
                <div class="btn-group btn-group-sm mb-2" role="group">
                  <button type="button" class="btn btn-outline-secondary quick-mark"
                          data-target="m<?= (int)$q['id'] ?>" data-value="0">0</button>
                  <button type="button" class="btn btn-outline-secondary quick-mark"
                          data-target="m<?= (int)$q['id'] ?>"
                          data-value="<?= num((float)$q['marks'] / 2) ?>">Half</button>
                  <button type="button" class="btn btn-outline-secondary quick-mark"
                          data-target="m<?= (int)$q['id'] ?>"
                          data-value="<?= num((float)$q['marks']) ?>">Full</button>
                </div>

                <label class="form-label small" for="f<?= (int)$q['id'] ?>">
                  Feedback <span class="text-muted-2 fw-normal">(the student sees this)</span>
                </label>
                <textarea class="form-control" id="f<?= (int)$q['id'] ?>" rows="2"
                          maxlength="500"
                          name="feedback[<?= (int)$q['id'] ?>]"><?= e($q['feedback'] ?? '') ?></textarea>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>

      <?php if (!$questions): ?>
        <div class="alert alert-info">This paper has no written questions to mark.</div>
      <?php endif; ?>

      <div class="card border-0 sticky-bottom">
        <div class="card-body d-flex flex-wrap gap-2 align-items-center">
          <button class="btn btn-primary" name="next" value="stay">
            <i class="bi bi-check2 me-1"></i>Save Marks
          </button>
          <button class="btn btn-success" name="next" value="list">
            <i class="bi bi-check2-all me-1"></i>Save &amp; Back to List
          </button>
          <a href="<?= url('faculty/evaluate.php') ?>" class="btn btn-outline-secondary">Cancel</a>
          <span class="small text-muted-2 ms-auto">
            Leave a box blank to come back to it later.
          </span>
        </div>
      </div>
    </form>

    <script>
    document.querySelectorAll('.quick-mark').forEach(function (btn) {
      btn.addEventListener('click', function () {
        document.getElementById(btn.dataset.target).value = btn.dataset.value;
      });
    });
    </script>

    <?php
    require_once __DIR__ . '/includes/faculty_footer.php';
    return;
}

/* ------------------------------------------------------------------ */
/*  List of papers waiting for evaluation                              */
/* ------------------------------------------------------------------ */
$examId = get_int('exam_id');

$sql = "SELECT r.*, a.submitted_at, a.evaluation_status, s.name AS student_name, s.email,
               e.title AS exam_title, e.subject
          FROM results r
          JOIN attempts a ON a.id = r.attempt_id
          JOIN students s ON s.id = r.student_id
          JOIN exams e    ON e.id = r.exam_id
         WHERE e.faculty_id = ?";
$params = [$faculty['id']];
if ($examId) {
    $sql .= ' AND e.id = ?';
    $params[] = $examId;
}
$sql .= ' ORDER BY r.pending_evaluation DESC, a.submitted_at ASC';

$st = db()->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

$pending = array_values(array_filter($rows, fn($r) => (int)$r['pending_evaluation'] === 1));
$done    = array_values(array_filter($rows, fn($r) => (int)$r['pending_evaluation'] === 0
                                                   && (float)$r['subjective_total'] > 0));

$exams = get_faculty_exams((int)$faculty['id']);

$pageTitle = 'Evaluate Written Answers';
$activeNav = 'evaluate';
require_once __DIR__ . '/includes/faculty_header.php';
?>

<div class="row g-3 mb-3">
  <?php foreach ([
    ['hourglass-split', 'text-danger',  'Waiting for you', count($pending)],
    ['check2-all',      'text-success', 'Already marked',  count($done)],
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

<form method="get" class="card border-0 mb-3">
  <div class="card-body row g-2 align-items-end">
    <div class="col-md-6">
      <label class="form-label" for="exam_id">Exam</label>
      <select class="form-select" id="exam_id" name="exam_id" onchange="this.form.submit()">
        <option value="0">All my exams</option>
        <?php foreach ($exams as $ex): ?>
          <option value="<?= (int)$ex['id'] ?>" <?= $examId === (int)$ex['id'] ? 'selected' : '' ?>>
            <?= e($ex['title']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
</form>

<?php if (!$pending && !$done): ?>
  <div class="card border-0 p-5 text-center">
    <i class="bi bi-check2-circle display-4 text-success"></i>
    <h5 class="mt-3 mb-1">Nothing to evaluate</h5>
    <p class="text-muted-2 mb-0">
      Papers appear here as soon as students submit an exam that has written questions.
    </p>
  </div>
<?php else: ?>
  <div class="card border-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead>
          <tr>
            <th>Student</th><th>Exam</th><th>Submitted</th>
            <th>Objective</th><th>Written</th><th>Status</th><th class="text-end">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach (array_merge($pending, $done) as $r):
              $isPending = (int)$r['pending_evaluation'] === 1;
              if (!$isPending && (float)$r['subjective_total'] <= 0) { continue; }
          ?>
            <tr class="<?= $isPending ? 'table-warning' : '' ?>">
              <td>
                <div class="fw-semibold"><?= e($r['student_name']) ?></div>
                <div class="small text-muted-2"><?= e($r['email']) ?></div>
              </td>
              <td class="small"><?= e($r['exam_title']) ?></td>
              <td class="small"><?= e(format_datetime($r['submitted_at'])) ?></td>
              <td><?= num((float)$r['objective_marks']) ?></td>
              <td>
                <?= $isPending ? '-' : num((float)$r['subjective_marks']) ?>
                <span class="text-muted-2">/ <?= num((float)$r['subjective_total']) ?></span>
              </td>
              <td>
                <span class="badge <?= $isPending ? 'bg-danger' : 'bg-success' ?>">
                  <?= $isPending ? 'Pending' : 'Evaluated' ?>
                </span>
              </td>
              <td class="text-end">
                <a class="btn btn-sm <?= $isPending ? 'btn-primary' : 'btn-outline-primary' ?>"
                   href="<?= url('faculty/evaluate.php?attempt=' . (int)$r['attempt_id']) ?>">
                  <i class="bi bi-pencil-square me-1"></i><?= $isPending ? 'Evaluate' : 'Review' ?>
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/faculty_footer.php'; ?>
