<?php
/**
 * Admin - deep analysis of one faculty member: the papers they set, the
 * question bank they authored, how they marked the written answers, and how
 * their students ended up doing.
 */
require_once __DIR__ . '/../includes/auth.php';
$admin     = require_admin();
$collegeId = (int)$admin['college_id'];

$id = get_int('id');
$st = db()->prepare('SELECT * FROM faculty WHERE id = ? AND college_id = ?');
$st->execute([$id, $collegeId]);
$f = $st->fetch();

if (!$f) {
    set_flash('danger', 'Faculty member not found in your college.');
    redirect('admin/faculty_analysis.php');
}

$a     = faculty_analytics($id);
$notes = faculty_observations($a);

/* ---------------- Papers they set ---------------- */
$st = db()->prepare(
    'SELECT ' . exam_select_sql() . ',
            (SELECT COUNT(*) FROM results r WHERE r.exam_id = e.id) AS submissions,
            (SELECT COALESCE(AVG(r.percentage),0) FROM results r WHERE r.exam_id = e.id) AS avg_pct,
            (SELECT SUM(CASE WHEN r.status = "PASS" THEN 1 ELSE 0 END) FROM results r
              WHERE r.exam_id = e.id) AS passed,
            (SELECT COUNT(*) FROM results r WHERE r.exam_id = e.id AND r.pending_evaluation = 1)
              AS pending
       FROM exams e WHERE e.faculty_id = ? ORDER BY e.id DESC'
);
$st->execute([$id]);
$papers = $st->fetchAll();

/* ---------------- Categories they cover ---------------- */
$st = db()->prepare(
    "SELECT COALESCE(NULLIF(q.category,''),'Uncategorised') AS topic, COUNT(*) AS n
       FROM questions q JOIN exams e ON e.id = q.exam_id
      WHERE e.faculty_id = ?
   GROUP BY topic ORDER BY n DESC LIMIT 14"
);
$st->execute([$id]);
$topics = $st->fetchAll();

/* ---------------- Their most recent evaluations ---------------- */
$st = db()->prepare(
    "SELECT a.awarded_marks, a.feedback, a.evaluated_at, q.marks, q.question_text,
            s.name AS student_name, e.title AS exam_title, t.id AS attempt_id,
            TIMESTAMPDIFF(HOUR, t.submitted_at, a.evaluated_at) AS turnaround
       FROM answers a
       JOIN questions q ON q.id = a.question_id
       JOIN attempts t  ON t.id = a.attempt_id
       JOIN exams e     ON e.id = t.exam_id
       JOIN students s  ON s.id = t.student_id
      WHERE e.faculty_id = ? AND q.type = 'subjective' AND a.evaluated_at IS NOT NULL
   ORDER BY a.evaluated_at DESC LIMIT 25"
);
$st->execute([$id]);
$evaluations = $st->fetchAll();

/* ---------------- Papers still waiting ---------------- */
$st = db()->prepare(
    'SELECT r.attempt_id, s.name AS student_name, e.title AS exam_title, t.submitted_at
       FROM results r
       JOIN attempts t ON t.id = r.attempt_id
       JOIN students s ON s.id = r.student_id
       JOIN exams e    ON e.id = r.exam_id
      WHERE e.faculty_id = ? AND r.pending_evaluation = 1
   ORDER BY t.submitted_at ASC'
);
$st->execute([$id]);
$pendingList = $st->fetchAll();

$pageTitle = 'Analysis - ' . $f['name'];
$activeNav = 'analysis';
require_once __DIR__ . '/includes/admin_header.php';
?>

<!-- ---------------------- Profile ---------------------- -->
<div class="card border-0 profile-header mb-4">
  <div class="profile-header-bg"></div>
  <div class="card-body position-relative">
    <div class="d-flex flex-wrap align-items-start gap-3">
      <div class="avatar-xl"><?= e(initials($f['name'])) ?></div>
      <div class="flex-grow-1">
        <h3 class="mb-1"><?= e($f['name']) ?></h3>
        <div class="text-muted-2">
          <?= e($f['designation'] ?: 'Faculty') ?>
          <?php if ($f['department']): ?> &middot; <?= e($f['department']) ?><?php endif; ?>
        </div>
        <div class="d-flex flex-wrap gap-2 mt-2">
          <?php if ($f['employee_code']): ?>
            <span class="chip chip-soft"><i class="bi bi-person-vcard"></i><?= e($f['employee_code']) ?></span>
          <?php endif; ?>
          <span class="chip chip-soft"><i class="bi bi-envelope"></i><?= e($f['email']) ?></span>
          <?php if ($f['qualification']): ?>
            <span class="chip chip-soft"><i class="bi bi-mortarboard"></i><?= e($f['qualification']) ?></span>
          <?php endif; ?>
          <?php if ($f['experience_years'] !== null): ?>
            <span class="chip chip-soft"><i class="bi bi-clock-history"></i>
              <?= num((float)$f['experience_years']) ?> yrs experience</span>
          <?php endif; ?>
          <span class="badge <?= $f['status'] === 'approved' ? 'bg-success' : 'bg-secondary' ?>">
            <?= e(ucfirst($f['status'])) ?>
          </span>
        </div>
      </div>
      <div class="d-flex flex-column gap-2">
        <a class="btn btn-outline-primary btn-sm" href="<?= url('admin/faculty.php') ?>">
          <i class="bi bi-gear me-1"></i>Manage account
        </a>
        <a class="btn btn-outline-secondary btn-sm" href="<?= url('admin/faculty_analysis.php') ?>">
          <i class="bi bi-arrow-left me-1"></i>All faculty
        </a>
      </div>
    </div>
    <?php if ($f['specialization'] || $f['about']): ?>
      <div class="mt-3 small text-muted-2">
        <?php if ($f['specialization']): ?>
          <strong>Teaches:</strong> <?= e($f['specialization']) ?><br>
        <?php endif; ?>
        <?= e((string)$f['about']) ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- ---------------------- Headline metrics ---------------------- -->
<div class="row g-3 mb-4">
  <?php foreach ([
    ['journal-text',   'text-primary', 'Papers Set',     $a['papers']],
    ['patch-question', 'text-info',    'Questions',      $a['questions']],
    ['pencil-square',  'text-warning', 'Written Qs',     $a['subjective_questions']],
    ['people',         'text-secondary','Students',      $a['students']],
    ['clipboard-data', 'text-success', 'Papers Received', $a['submissions']],
    ['hourglass-split','text-danger',  'Unmarked',       $a['pending_papers']],
  ] as [$icon, $tone, $label, $value]): ?>
    <div class="col-6 col-lg-2">
      <div class="stat-card h-100">
        <i class="bi bi-<?= $icon ?> fs-4 <?= $tone ?>"></i>
        <div class="value mt-1"><?= $value ?></div>
        <div class="label"><?= e($label) ?></div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php if ($notes): ?>
  <div class="card border-0 mb-4">
    <div class="card-body">
      <h6 class="mb-3"><i class="bi bi-clipboard-check me-1"></i>Assessment</h6>
      <ul class="list-unstyled mb-0">
        <?php foreach ($notes as [$tone, $text]):
            $icon = ['success' => 'check-circle-fill text-success',
                     'warning' => 'exclamation-triangle-fill text-warning',
                     'danger'  => 'exclamation-octagon-fill text-danger',
                     'muted'   => 'dash-circle text-muted-2'][$tone]; ?>
          <li class="d-flex gap-2 py-1"><i class="bi bi-<?= $icon ?>"></i><span><?= e($text) ?></span></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
<?php endif; ?>

<div class="row g-4 mb-4">
  <!-- ------------- Evaluation quality ------------- -->
  <div class="col-lg-7">
    <div class="card border-0 h-100">
      <div class="card-body">
        <h6 class="mb-1"><i class="bi bi-pencil-square me-1"></i>How this faculty marks written answers</h6>
        <p class="small text-muted-2">
          Based on <?= $a['evaluated'] ?> marked answer(s).
        </p>

        <?php if ($a['evaluated'] === 0): ?>
          <div class="alert alert-light border small">
            This faculty has not marked any written answer yet, so there is no marking
            pattern to analyse.
          </div>
        <?php else: ?>
          <div class="mb-3">
            <div class="d-flex justify-content-between small mb-1">
              <span class="fw-semibold">Marks awarded out of the marks available</span>
              <span><?= $a['award_ratio'] ?>%</span>
            </div>
            <div class="progress" style="height:14px">
              <div class="progress-bar <?= $a['award_ratio'] >= 85 ? 'bg-warning'
                    : ($a['award_ratio'] <= 40 ? 'bg-danger' : 'bg-success') ?>"
                   style="width:<?= (float)$a['award_ratio'] ?>%"></div>
            </div>
            <div class="small text-muted-2 mt-1">
              Below 40% reads as a strict marker, above 85% as a lenient one.
            </div>
          </div>

          <div class="row g-2 mb-3">
            <div class="col-6 col-md-3"><div class="stat-card p-2">
              <div class="value fs-5"><?= $a['full_marks_pct'] ?>%</div>
              <div class="label">Full marks</div></div></div>
            <div class="col-6 col-md-3"><div class="stat-card p-2">
              <div class="value fs-5"><?= $a['zero_marks_pct'] ?>%</div>
              <div class="label">Zero marks</div></div></div>
            <div class="col-6 col-md-3"><div class="stat-card p-2">
              <div class="value fs-5"><?= $a['feedback_pct'] ?>%</div>
              <div class="label">With feedback</div></div></div>
            <div class="col-6 col-md-3"><div class="stat-card p-2">
              <div class="value fs-5">
                <?= $a['avg_turnaround'] === null ? '-'
                     : ($a['avg_turnaround'] < 24 ? num((float)$a['avg_turnaround']) . 'h'
                                                  : num($a['avg_turnaround'] / 24) . 'd') ?>
              </div>
              <div class="label">Turnaround</div></div></div>
          </div>

        <?php endif; ?>

        <?php /* The backlog matters most for a faculty who has not started
                 marking, so it is shown regardless of their marking history. */ ?>
        <?php if ($pendingList): ?>
          <div class="alert alert-warning small mb-0">
            <strong><?= count($pendingList) ?> paper(s) still unmarked:</strong>
            <ul class="mb-0 mt-1">
              <?php foreach (array_slice($pendingList, 0, 8) as $p): ?>
                <li><?= e($p['student_name']) ?> - <?= e($p['exam_title']) ?>,
                    submitted <?= e(format_datetime($p['submitted_at'])) ?></li>
              <?php endforeach; ?>
              <?php if (count($pendingList) > 8): ?>
                <li class="text-muted-2">and <?= count($pendingList) - 8 ?> more</li>
              <?php endif; ?>
            </ul>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ------------- Question bank shape ------------- -->
  <div class="col-lg-5">
    <div class="card border-0 h-100">
      <div class="card-body">
        <h6 class="mb-3"><i class="bi bi-diagram-3 me-1"></i>Question bank they authored</h6>

        <div class="small fw-semibold mb-1">By type</div>
        <?php
        $total = max(1, $a['questions']);
        foreach ([['mcq', 'Multiple choice', 'bg-primary'],
                  ['truefalse', 'True / False', 'bg-info'],
                  ['subjective', 'Written', 'bg-warning']] as [$k, $label, $tone]):
            $n = $a['mix'][$k] ?? 0; ?>
          <div class="d-flex justify-content-between small"><span><?= $label ?></span>
            <span class="text-muted-2"><?= $n ?></span></div>
          <div class="progress mb-2" style="height:7px">
            <div class="progress-bar <?= $tone ?>" style="width:<?= $n / $total * 100 ?>%"></div>
          </div>
        <?php endforeach; ?>

        <div class="small fw-semibold mt-3 mb-1">By difficulty</div>
        <?php foreach ([['easy', 'Easy', 'bg-success'],
                        ['medium', 'Medium', 'bg-warning'],
                        ['hard', 'Hard', 'bg-danger']] as [$k, $label, $tone]):
            $n = $a['mix'][$k] ?? 0; ?>
          <div class="d-flex justify-content-between small"><span><?= $label ?></span>
            <span class="text-muted-2"><?= $n ?></span></div>
          <div class="progress mb-2" style="height:7px">
            <div class="progress-bar <?= $tone ?>" style="width:<?= $n / $total * 100 ?>%"></div>
          </div>
        <?php endforeach; ?>

        <?php if ($topics): ?>
          <div class="small fw-semibold mt-3 mb-2">Topics covered</div>
          <div class="d-flex flex-wrap gap-1">
            <?php foreach ($topics as $t): ?>
              <span class="chip chip-soft"><?= e($t['topic']) ?> &middot; <?= (int)$t['n'] ?></span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- ---------------------- Papers set ---------------------- -->
<div class="card border-0 mb-4">
  <div class="card-body">
    <h6 class="mb-3"><i class="bi bi-journal-text me-1"></i>Papers set by this faculty</h6>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead>
          <tr>
            <th>Paper</th><th>Questions</th><th>Marks</th><th>Allotted</th>
            <th>Submitted</th><th>Average</th><th>Passed</th><th>Status</th>
            <th class="text-end">Question paper</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($papers as $p): ?>
            <tr>
              <td>
                <div class="fw-semibold"><?= e($p['title']) ?></div>
                <div class="small text-muted-2"><?= e($p['subject']) ?></div>
              </td>
              <td>
                <?= (int)$p['question_count'] ?>
                <?php if ((int)$p['subjective_count'] > 0): ?>
                  <div class="small text-muted-2"><?= (int)$p['subjective_count'] ?> written</div>
                <?php endif; ?>
              </td>
              <td><?= num((float)$p['total_marks']) ?>
                <div class="small text-muted-2">pass <?= num((float)$p['passing_marks']) ?></div></td>
              <td><?= (int)$p['assigned_count'] ?></td>
              <td><?= (int)$p['submissions'] ?></td>
              <td><?= (int)$p['submissions'] > 0 ? num((float)$p['avg_pct']) . '%' : '-' ?></td>
              <td><?= (int)$p['passed'] ?></td>
              <td>
                <?php if ((int)$p['pending'] > 0): ?>
                  <span class="badge bg-danger"><?= (int)$p['pending'] ?> unmarked</span>
                <?php elseif (!(int)$p['is_active']): ?>
                  <span class="badge bg-secondary">Hidden</span>
                <?php else: ?>
                  <span class="badge bg-success">Active</span>
                <?php endif; ?>
              </td>
              <td class="text-end text-nowrap">
                <a class="btn btn-sm btn-outline-primary"
                   href="<?= url('admin/paper_view.php?exam_id=' . (int)$p['id']) ?>">
                  <i class="bi bi-eye me-1"></i>View questions
                </a>
                <a class="btn btn-sm btn-outline-secondary"
                   href="<?= url('admin/results.php?exam_id=' . (int)$p['id']) ?>"
                   title="Results"><i class="bi bi-bar-chart"></i></a>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$papers): ?>
            <tr><td colspan="9" class="text-center text-muted-2 py-4">
              This faculty has not set any paper yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ---------------------- Recent evaluations ---------------------- -->
<div class="card border-0">
  <div class="card-body">
    <h6 class="mb-1"><i class="bi bi-clock-history me-1"></i>Recent marking by this faculty</h6>
    <p class="small text-muted-2">
      The last <?= count($evaluations) ?> written answers they marked, with the marks and
      feedback they gave.
    </p>

    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead>
          <tr>
            <th>Student</th><th>Paper</th><th>Question</th>
            <th>Awarded</th><th>Feedback given</th><th>Marked</th><th>Took</th>
            <th class="text-end">Answer sheet</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($evaluations as $ev):
              $ratio = (float)$ev['marks'] > 0
                       ? (float)$ev['awarded_marks'] / (float)$ev['marks'] * 100 : 0; ?>
            <tr>
              <td class="small fw-semibold"><?= e($ev['student_name']) ?></td>
              <td class="small text-muted-2"><?= e($ev['exam_title']) ?></td>
              <td class="small"><?= e(mb_strimwidth($ev['question_text'], 0, 52, '...')) ?></td>
              <td style="min-width:120px">
                <div class="d-flex align-items-center gap-2">
                  <div class="progress flex-grow-1" style="height:6px">
                    <div class="progress-bar <?= $ratio >= 70 ? 'bg-success'
                          : ($ratio > 0 ? 'bg-warning' : 'bg-danger') ?>"
                         style="width:<?= $ratio ?>%"></div>
                  </div>
                  <span class="small fw-semibold">
                    <?= num((float)$ev['awarded_marks']) ?>/<?= num((float)$ev['marks']) ?>
                  </span>
                </div>
              </td>
              <td>
                <?php if (trim((string)$ev['feedback']) !== ''): ?>
                  <span class="small" title="<?= e($ev['feedback']) ?>">
                    <i class="bi bi-chat-left-text text-success me-1"></i>
                    <?= e(mb_strimwidth($ev['feedback'], 0, 40, '...')) ?>
                  </span>
                <?php else: ?>
                  <span class="badge bg-warning text-dark">none</span>
                <?php endif; ?>
              </td>
              <td class="small"><?= e(format_datetime($ev['evaluated_at'])) ?></td>
              <td class="small">
                <?= $ev['turnaround'] === null ? '-'
                     : ((int)$ev['turnaround'] < 24 ? (int)$ev['turnaround'] . ' h'
                                                    : round((int)$ev['turnaround'] / 24) . ' d') ?>
              </td>
              <td class="text-end">
                <a class="btn btn-sm btn-outline-primary"
                   href="<?= url('admin/attempt_view.php?attempt=' . (int)$ev['attempt_id']) ?>">
                  <i class="bi bi-file-text"></i>
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$evaluations): ?>
            <tr><td colspan="8" class="text-center text-muted-2 py-4">
              No written answers marked yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
