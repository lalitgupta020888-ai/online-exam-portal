<?php
/**
 * Faculty dashboard.
 *
 * The centre of this page is the completion tracker: for every exam it shows
 * how many of the assigned students have finished, and the class result set is
 * revealed once they all have (or once the faculty releases it early).
 */
require_once __DIR__ . '/../includes/auth.php';
$faculty = require_faculty();

$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
require_once __DIR__ . '/includes/faculty_header.php';

$exams = get_faculty_exams((int)$faculty['id']);

// Headline numbers across everything this faculty member owns.
$st = db()->prepare(
    'SELECT COUNT(*) AS papers,
            COALESCE(AVG(r.percentage),0) AS avg_pct,
            SUM(CASE WHEN r.status = "PASS" THEN 1 ELSE 0 END) AS passed,
            SUM(r.pending_evaluation) AS pending
       FROM results r JOIN exams e ON e.id = r.exam_id
      WHERE e.faculty_id = ?'
);
$st->execute([$faculty['id']]);
$totals = $st->fetch();

$st = db()->prepare(
    'SELECT COUNT(DISTINCT a.student_id) FROM exam_assignments a
       JOIN exams e ON e.id = a.exam_id WHERE e.faculty_id = ?'
);
$st->execute([$faculty['id']]);
$studentCount = (int)$st->fetchColumn();

$papers   = (int)$totals['papers'];
$passRate = $papers > 0 ? round((int)$totals['passed'] / $papers * 100, 1) : 0;

// Exams grouped by whether their results are ready to look at.
$ready = $waiting = [];
foreach ($exams as $exam) {
    $p = exam_progress($exam);
    if ($p['results_visible'] && $p['assigned'] > 0) { $ready[]   = [$exam, $p]; }
    else                                            { $waiting[] = [$exam, $p]; }
}
?>

<div class="row g-3 mb-4">
  <?php foreach ([
    ['journal-text',    'text-primary', 'My Exams',        count($exams)],
    ['people',          'text-info',    'Students Assigned', $studentCount],
    ['clipboard-data',  'text-success', 'Papers Submitted', $papers],
    ['graph-up',        'text-warning', 'Average Score',   num((float)$totals['avg_pct']) . '%'],
    ['patch-check',     'text-success', 'Pass Rate',       $passRate . '%'],
    ['hourglass-split', 'text-danger',  'Awaiting Evaluation', (int)$totals['pending']],
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

<?php if ((int)$totals['pending'] > 0): ?>
  <div class="alert alert-warning d-flex flex-wrap align-items-center gap-2">
    <i class="bi bi-pencil-square fs-4"></i>
    <div class="flex-grow-1">
      <strong><?= (int)$totals['pending'] ?> paper(s)</strong> have written answers waiting
      for your marks. Class results stay provisional until they are evaluated.
    </div>
    <a href="<?= url('faculty/evaluate.php') ?>" class="btn btn-warning btn-sm">Evaluate now</a>
  </div>
<?php endif; ?>

<?php if (!$exams): ?>
  <div class="card border-0 p-5 text-center">
    <i class="bi bi-journal-plus display-4 text-muted-2"></i>
    <h5 class="mt-3 mb-1">Welcome, <?= e($faculty['name']) ?></h5>
    <p class="text-muted-2 mb-4">
      Start by creating an exam. You can type the questions or import them from a
      PDF, then assign the exam to your students.
    </p>
    <div><a href="<?= url('faculty/exam_form.php') ?>" class="btn btn-primary">
      Create your first exam</a></div>
  </div>
<?php else: ?>

  <!-- ================== Results ready to view ================== -->
  <h5 class="mb-3">
    <i class="bi bi-check2-circle text-success me-2"></i>Results ready
    <span class="badge bg-success ms-1"><?= count($ready) ?></span>
  </h5>

  <?php if (!$ready): ?>
    <div class="card border-0 p-4 mb-4 text-center text-muted-2">
      No exam has been completed by all of its students yet. The full result set for an
      exam appears here the moment the last student submits - or you can release it
      early from <a href="<?= url('faculty/exams.php') ?>">My Exams</a>.
    </div>
  <?php else: ?>
    <div class="row g-3 mb-4">
      <?php foreach ($ready as [$exam, $p]):
          $st = db()->prepare(
              'SELECT COUNT(*) AS n, COALESCE(AVG(percentage),0) AS avg_pct,
                      COALESCE(MAX(percentage),0) AS top,
                      SUM(CASE WHEN status = "PASS" THEN 1 ELSE 0 END) AS passed
                 FROM results WHERE exam_id = ?'
          );
          $st->execute([$exam['id']]);
          $s = $st->fetch();
      ?>
        <div class="col-xl-6">
          <div class="card border-0 h-100 border-start border-4 border-success">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-start mb-2">
                <div>
                  <h6 class="mb-1"><?= e($exam['title']) ?></h6>
                  <div class="small text-muted-2"><?= e($exam['subject']) ?></div>
                </div>
                <?php if (!$p['all_completed']): ?>
                  <span class="badge bg-info">Released early</span>
                <?php else: ?>
                  <span class="badge bg-success">All completed</span>
                <?php endif; ?>
              </div>

              <div class="row g-2 mb-3">
                <div class="col-3"><div class="stat-card p-2">
                  <div class="value fs-6"><?= (int)$s['n'] ?></div>
                  <div class="label">Papers</div></div></div>
                <div class="col-3"><div class="stat-card p-2">
                  <div class="value fs-6"><?= num((float)$s['avg_pct']) ?>%</div>
                  <div class="label">Average</div></div></div>
                <div class="col-3"><div class="stat-card p-2">
                  <div class="value fs-6"><?= num((float)$s['top']) ?>%</div>
                  <div class="label">Highest</div></div></div>
                <div class="col-3"><div class="stat-card p-2">
                  <div class="value fs-6"><?= (int)$s['passed'] ?>/<?= (int)$s['n'] ?></div>
                  <div class="label">Passed</div></div></div>
              </div>

              <?php if ($p['pending_eval'] > 0): ?>
                <div class="small text-danger mb-2">
                  <i class="bi bi-exclamation-circle me-1"></i>
                  <?= $p['pending_eval'] ?> paper(s) still need evaluation - these figures
                  are provisional.
                </div>
              <?php endif; ?>

              <div class="d-flex flex-wrap gap-1">
                <a class="btn btn-sm btn-primary"
                   href="<?= url('faculty/results.php?exam_id=' . (int)$exam['id']) ?>">
                  <i class="bi bi-table me-1"></i>All Results
                </a>
                <a class="btn btn-sm btn-outline-primary"
                   href="<?= url('faculty/analysis.php?exam_id=' . (int)$exam['id']) ?>">
                  <i class="bi bi-graph-up me-1"></i>Analysis
                </a>
                <a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener"
                   href="<?= url('faculty/print_results.php?exam_id=' . (int)$exam['id']) ?>">
                  <i class="bi bi-printer me-1"></i>Print / PDF
                </a>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!-- ================== Still in progress ================== -->
  <h5 class="mb-3">
    <i class="bi bi-hourglass-split text-primary me-2"></i>Exams in progress
    <span class="badge bg-secondary ms-1"><?= count($waiting) ?></span>
  </h5>

  <div class="card border-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead>
          <tr>
            <th>Exam</th><th>Access</th><th>Questions</th>
            <th style="min-width:180px">Completion</th><th>Status</th><th class="text-end">Manage</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($waiting as [$exam, $p]): $id = (int)$exam['id']; ?>
            <tr>
              <td>
                <div class="fw-semibold"><?= e($exam['title']) ?></div>
                <div class="small text-muted-2"><?= e($exam['subject']) ?></div>
              </td>
              <td>
                <?php if ($exam['access_mode'] === 'assigned'): ?>
                  <span class="badge bg-info-subtle text-info-emphasis">Assigned</span>
                <?php else: ?>
                  <span class="badge bg-warning-subtle text-warning-emphasis">Open</span>
                <?php endif; ?>
              </td>
              <td>
                <?= (int)$exam['question_count'] ?>
                <?php if ((int)$exam['subjective_count'] > 0): ?>
                  <span class="small text-muted-2">(<?= (int)$exam['subjective_count'] ?> written)</span>
                <?php endif; ?>
              </td>
              <td>
                <div class="d-flex align-items-center gap-2">
                  <div class="progress flex-grow-1" style="height:8px">
                    <div class="progress-bar" style="width:<?= (int)$p['percent'] ?>%"></div>
                  </div>
                  <span class="small fw-semibold"><?= $p['completed'] ?>/<?= $p['assigned'] ?></span>
                </div>
                <?php if ($p['in_progress'] > 0): ?>
                  <div class="small text-muted-2"><?= $p['in_progress'] ?> writing now</div>
                <?php endif; ?>
              </td>
              <td class="small">
                <?php if ((int)$exam['question_count'] === 0): ?>
                  <span class="text-danger">No questions yet</span>
                <?php elseif ($p['assigned'] === 0): ?>
                  <span class="text-warning">No students assigned</span>
                <?php else: ?>
                  <span class="text-muted-2">
                    Waiting for <?= $p['assigned'] - $p['completed'] ?> student(s)
                  </span>
                <?php endif; ?>
              </td>
              <td class="text-end text-nowrap">
                <a class="btn btn-sm btn-outline-secondary"
                   href="<?= url('faculty/questions.php?exam_id=' . $id) ?>"
                   title="Questions"><i class="bi bi-list-check"></i></a>
                <a class="btn btn-sm btn-outline-secondary"
                   href="<?= url('faculty/assign.php?exam_id=' . $id) ?>"
                   title="Assign students"><i class="bi bi-people"></i></a>
                <a class="btn btn-sm btn-outline-primary"
                   href="<?= url('faculty/results.php?exam_id=' . $id) ?>"
                   title="Results"><i class="bi bi-bar-chart"></i></a>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$waiting): ?>
            <tr><td colspan="6" class="text-center text-muted-2 py-4">
              Every exam has been completed. Nothing pending.
            </td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card border-0 mt-3">
    <div class="card-body d-flex flex-wrap gap-2">
      <a href="<?= url('faculty/exam_form.php') ?>" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i>Create Exam
      </a>
      <a href="<?= url('faculty/students.php') ?>" class="btn btn-outline-primary">
        <i class="bi bi-people me-1"></i>Student Analysis
      </a>
      <a href="<?= url('faculty/results.php') ?>" class="btn btn-outline-secondary">
        <i class="bi bi-bar-chart me-1"></i>All Results
      </a>
    </div>
  </div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/faculty_footer.php'; ?>
