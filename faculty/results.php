<?php
/**
 * Faculty - the result sheet for one exam (or for all my exams).
 *
 * A single exam's result set stays hidden until every assigned student has
 * completed it, unless the faculty released it early. That is the
 * "show me everyone's result once all students are done" rule.
 */
require_once __DIR__ . '/../includes/auth.php';
$faculty = require_faculty();

$examId = get_int('exam_id');
$exam   = null;
$progress = null;

if ($examId) {
    $exam = get_owned_exam($examId, (int)$faculty['id']);
    if (!$exam) {
        set_flash('danger', 'That exam does not belong to you.');
        redirect('faculty/results.php');
    }
    $progress = exam_progress($exam);
}

$pageTitle = $exam ? 'Results - ' . $exam['title'] : 'Results';
$activeNav = 'results';
require_once __DIR__ . '/includes/faculty_header.php';

$exams = get_faculty_exams((int)$faculty['id']);
?>

<form method="get" class="card border-0 mb-3">
  <div class="card-body row g-2 align-items-end">
    <div class="col-md-6">
      <label class="form-label" for="exam_id">Exam</label>
      <select class="form-select" id="exam_id" name="exam_id" onchange="this.form.submit()">
        <option value="0">-- choose an exam --</option>
        <?php foreach ($exams as $ex): ?>
          <option value="<?= (int)$ex['id'] ?>" <?= $examId === (int)$ex['id'] ? 'selected' : '' ?>>
            <?= e($ex['title']) ?> (<?= e($ex['subject']) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if ($exam): ?>
      <div class="col-md-6 text-md-end">
        <a class="btn btn-outline-primary"
           href="<?= url('faculty/analysis.php?exam_id=' . $examId) ?>">
          <i class="bi bi-graph-up me-1"></i>Full Analysis
        </a>
        <a class="btn btn-outline-secondary" target="_blank" rel="noopener"
           href="<?= url('faculty/print_results.php?exam_id=' . $examId) ?>">
          <i class="bi bi-printer me-1"></i>Print / PDF
        </a>
      </div>
    <?php endif; ?>
  </div>
</form>

<?php if (!$exam): ?>
  <!-- ---------------- No exam chosen: overview of all ---------------- -->
  <div class="card border-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead>
          <tr><th>Exam</th><th>Completion</th><th>Papers</th><th>Average</th>
              <th>Passed</th><th>Results</th><th class="text-end"></th></tr>
        </thead>
        <tbody>
          <?php foreach ($exams as $ex):
              $p  = exam_progress($ex);
              $st = db()->prepare(
                  'SELECT COUNT(*) AS n, COALESCE(AVG(percentage),0) AS avg_pct,
                          SUM(CASE WHEN status = "PASS" THEN 1 ELSE 0 END) AS passed
                     FROM results WHERE exam_id = ?'
              );
              $st->execute([$ex['id']]);
              $s = $st->fetch();
          ?>
            <tr>
              <td>
                <div class="fw-semibold"><?= e($ex['title']) ?></div>
                <div class="small text-muted-2"><?= e($ex['subject']) ?></div>
              </td>
              <td style="min-width:150px">
                <div class="d-flex align-items-center gap-2">
                  <div class="progress flex-grow-1" style="height:7px">
                    <div class="progress-bar <?= $p['all_completed'] ? 'bg-success' : '' ?>"
                         style="width:<?= (int)$p['percent'] ?>%"></div>
                  </div>
                  <span class="small"><?= $p['completed'] ?>/<?= $p['assigned'] ?></span>
                </div>
              </td>
              <td><?= (int)$s['n'] ?></td>
              <td><?= num((float)$s['avg_pct']) ?>%</td>
              <td><?= (int)$s['passed'] ?></td>
              <td>
                <?php if ($p['results_visible']): ?>
                  <span class="badge bg-success">Ready</span>
                <?php else: ?>
                  <span class="badge bg-secondary">Locked</span>
                <?php endif; ?>
              </td>
              <td class="text-end">
                <a class="btn btn-sm btn-outline-primary"
                   href="<?= url('faculty/results.php?exam_id=' . (int)$ex['id']) ?>">Open</a>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$exams): ?>
            <tr><td colspan="7" class="text-center text-muted-2 py-5">
              You have not created any exam yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php elseif (!$progress['results_visible']): ?>
  <!-- ---------------- Locked until everyone finishes ---------------- -->
  <div class="card border-0 p-5 text-center">
    <i class="bi bi-lock display-4 text-muted-2"></i>
    <h4 class="mt-3 mb-2">Results are locked</h4>
    <p class="text-muted-2 mb-4">
      The full result sheet appears here once <strong>all
      <?= $progress['assigned'] ?> assigned students</strong> have completed
      <em><?= e($exam['title']) ?></em>.
    </p>

    <div class="mx-auto" style="max-width:520px">
      <div class="d-flex justify-content-between small mb-1">
        <span class="text-muted-2">Completed</span>
        <span class="fw-semibold"><?= $progress['completed'] ?> of <?= $progress['assigned'] ?></span>
      </div>
      <div class="progress mb-4" style="height:14px">
        <div class="progress-bar" style="width:<?= (int)$progress['percent'] ?>%"></div>
      </div>

      <div class="row g-2 mb-4">
        <div class="col-4"><div class="stat-card p-2">
          <div class="value fs-5 text-success"><?= $progress['completed'] ?></div>
          <div class="label">Completed</div></div></div>
        <div class="col-4"><div class="stat-card p-2">
          <div class="value fs-5 text-warning"><?= $progress['in_progress'] ?></div>
          <div class="label">Writing now</div></div></div>
        <div class="col-4"><div class="stat-card p-2">
          <div class="value fs-5 text-danger"><?= $progress['not_started'] ?></div>
          <div class="label">Not started</div></div></div>
      </div>

      <?php if ($progress['assigned'] === 0): ?>
        <div class="alert alert-warning">
          No student has been assigned to this exam yet.
          <a href="<?= url('faculty/assign.php?exam_id=' . $examId) ?>" class="fw-semibold">
            Assign students</a>
        </div>
      <?php endif; ?>

      <form method="post" action="<?= url('faculty/exams.php') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="exam_id" value="<?= $examId ?>">
        <input type="hidden" name="action" value="release">
        <button class="btn btn-outline-primary"
                data-confirm="Release the results now, without waiting for the remaining students?">
          <i class="bi bi-unlock me-1"></i>Release results now
        </button>
      </form>
    </div>
  </div>

  <?php
  // Who we are still waiting for - useful even while results are locked.
  $st = db()->prepare(
      "SELECT s.name, s.email,
              (SELECT t.status FROM attempts t
                WHERE t.exam_id = a.exam_id AND t.student_id = a.student_id
                ORDER BY t.id DESC LIMIT 1) AS attempt_status
         FROM exam_assignments a JOIN students s ON s.id = a.student_id
        WHERE a.exam_id = ?
     ORDER BY s.name"
  );
  $st->execute([$examId]);
  $roster = $st->fetchAll();
  ?>
  <?php if ($roster): ?>
    <div class="card border-0 mt-3">
      <div class="card-body">
        <h6 class="mb-3"><i class="bi bi-people me-1"></i>Assigned students</h6>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Student</th><th>Status</th></tr></thead>
            <tbody>
              <?php foreach ($roster as $r): ?>
                <tr>
                  <td>
                    <div class="fw-semibold"><?= e($r['name']) ?></div>
                    <div class="small text-muted-2"><?= e($r['email']) ?></div>
                  </td>
                  <td>
                    <?php if ($r['attempt_status'] === null): ?>
                      <span class="badge bg-danger">Not started</span>
                    <?php elseif ($r['attempt_status'] === 'in_progress'): ?>
                      <span class="badge bg-warning text-dark">In progress</span>
                    <?php else: ?>
                      <span class="badge bg-success">Completed</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  <?php endif; ?>

<?php else: ?>
  <!-- ---------------- The result sheet ---------------- -->
  <?php
  $st = db()->prepare(
      'SELECT r.*, s.name AS student_name, s.email, a.submitted_at, a.status AS attempt_status
         FROM results r
         JOIN students s ON s.id = r.student_id
         JOIN attempts a ON a.id = r.attempt_id
        WHERE r.exam_id = ?
     ORDER BY r.obtained_marks DESC, a.submitted_at ASC'
  );
  $st->execute([$examId]);
  $rows = $st->fetchAll();

  $n        = count($rows);
  $passed   = count(array_filter($rows, fn($r) => $r['status'] === 'PASS'));
  $pendings = count(array_filter($rows, fn($r) => (int)$r['pending_evaluation'] === 1));
  $marks    = array_map(fn($r) => (float)$r['obtained_marks'], $rows);
  $avg      = $n ? array_sum($marks) / $n : 0;
  $high     = $n ? max($marks) : 0;
  $low      = $n ? min($marks) : 0;
  ?>

  <?php if (!$progress['all_completed']): ?>
    <div class="alert alert-info">
      <i class="bi bi-unlock me-1"></i>
      These results were released early - <?= $progress['assigned'] - $progress['completed'] ?>
      assigned student(s) have not completed the exam yet.
    </div>
  <?php endif; ?>

  <?php if ($pendings > 0): ?>
    <div class="alert alert-warning d-flex flex-wrap align-items-center gap-2">
      <i class="bi bi-pencil-square"></i>
      <div class="flex-grow-1">
        <?= $pendings ?> paper(s) still have unmarked written answers, so their totals
        are provisional.
      </div>
      <a class="btn btn-sm btn-warning"
         href="<?= url('faculty/evaluate.php?exam_id=' . $examId) ?>">Evaluate</a>
    </div>
  <?php endif; ?>

  <div class="row g-3 mb-3">
    <?php foreach ([
      ['people',       'text-primary', 'Students',   $n],
      ['patch-check',  'text-success', 'Passed',     $passed],
      ['x-octagon',    'text-danger',  'Failed',     $n - $passed],
      ['graph-up',     'text-info',    'Average',    num((float)$avg)],
      ['trophy',       'text-warning', 'Highest',    num((float)$high)],
      ['arrow-down',   'text-secondary','Lowest',    num((float)$low)],
    ] as [$icon, $tone, $label, $value]): ?>
      <div class="col-6 col-lg-2">
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
            <th>Rank</th><th>Student</th><th>Submitted</th>
            <th>Correct</th><th>Wrong</th><th>Skipped</th>
            <?php if ((float)$exam['subjective_total'] > 0): ?>
              <th>Objective</th><th>Written</th>
            <?php endif; ?>
            <th>Marks</th><th>%</th><th>Result</th><th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $i => $r): ?>
            <tr>
              <td class="fw-bold text-muted-2"><?= $i + 1 ?></td>
              <td>
                <div class="fw-semibold"><?= e($r['student_name']) ?></div>
                <div class="small text-muted-2"><?= e($r['email']) ?></div>
              </td>
              <td class="small">
                <?= e(format_datetime($r['submitted_at'])) ?>
                <?php if ($r['attempt_status'] === 'auto_submitted'): ?>
                  <span class="badge bg-warning text-dark">Auto</span>
                <?php endif; ?>
              </td>
              <td class="text-success fw-semibold"><?= (int)$r['correct_count'] ?></td>
              <td class="text-danger fw-semibold"><?= (int)$r['wrong_count'] ?></td>
              <td class="text-warning fw-semibold"><?= (int)$r['unanswered_count'] ?></td>
              <?php if ((float)$exam['subjective_total'] > 0): ?>
                <td><?= num((float)$r['objective_marks']) ?></td>
                <td>
                  <?php if ((int)$r['pending_evaluation']): ?>
                    <span class="badge bg-warning text-dark">Pending</span>
                  <?php else: ?>
                    <?= num((float)$r['subjective_marks']) ?>
                    <span class="text-muted-2">/ <?= num((float)$r['subjective_total']) ?></span>
                  <?php endif; ?>
                </td>
              <?php endif; ?>
              <td class="fw-semibold">
                <?= num((float)$r['obtained_marks']) ?>
                <span class="text-muted-2">/ <?= num((float)$r['total_marks']) ?></span>
              </td>
              <td style="min-width:110px">
                <div class="d-flex align-items-center gap-2">
                  <div class="progress flex-grow-1" style="height:7px">
                    <div class="progress-bar <?= $r['status'] === 'PASS' ? 'bg-success' : 'bg-danger' ?>"
                         style="width:<?= e($r['percentage']) ?>%"></div>
                  </div>
                  <span class="small"><?= num((float)$r['percentage']) ?>%</span>
                </div>
              </td>
              <td>
                <span class="badge <?= $r['status'] === 'PASS' ? 'bg-success' : 'bg-danger' ?>">
                  <?= e($r['status']) ?>
                </span>
              </td>
              <td class="text-end text-nowrap">
                <a class="btn btn-sm btn-outline-primary"
                   href="<?= url('faculty/student_analysis.php?student_id=' . (int)$r['student_id']) ?>"
                   title="Full analysis of this student"><i class="bi bi-person-lines-fill"></i></a>
                <?php if ((float)$r['subjective_total'] > 0): ?>
                  <a class="btn btn-sm btn-outline-secondary"
                     href="<?= url('faculty/evaluate.php?attempt=' . (int)$r['attempt_id']) ?>"
                     title="Written answers"><i class="bi bi-pencil-square"></i></a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$rows): ?>
            <tr><td colspan="12" class="text-center text-muted-2 py-5">
              No papers have been submitted yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/faculty_footer.php'; ?>
