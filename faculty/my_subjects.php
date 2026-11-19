<?php
/**
 * Faculty - My Subject: the subjects I teach, session wise.
 * A session (e.g. 2026-27) has an odd term (July-December, Sem 1/3/5) and an
 * even term (January-June, Sem 2/4/6); each session keeps its own record.
 */
require_once __DIR__ . '/../includes/auth.php';
$faculty   = require_faculty();
$facultyId = (int)$faculty['id'];
$collegeId = (int)$faculty['college_id'];
ensure_my_subjects_schema();

$current  = current_session();
$session  = (string)($_GET['session'] ?? $_POST['session'] ?? $current);
if (!session_start_year($session)) {
    $session = $current;
}
$branches = get_branches($collegeId);
$branchIds = array_map('intval', array_column($branches, 'id'));
$errors   = [];

$form = ['id' => 0, 'semester' => '', 'branch_id' => (int)($faculty['branch_id'] ?? 0),
         'subject_name' => '', 'subject_code' => '', 'subject_type' => 'Theory', 'periods' => ''];

$editId = get_int('edit');
if ($editId) {
    $st = db()->prepare('SELECT * FROM faculty_teaching WHERE id = ? AND faculty_id = ?');
    $st->execute([$editId, $facultyId]);
    if ($row = $st->fetch()) {
        $form = array_merge($form, $row);
        $session = $row['session'];
    }
}

$back = 'faculty/my_subjects.php?session=' . urlencode($session);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post('action');

    if ($action === 'delete') {
        db()->prepare('DELETE FROM faculty_teaching WHERE id = ? AND faculty_id = ?')
            ->execute([(int)post('id'), $facultyId]);
        set_flash('success', 'Subject removed from session ' . $session . '.');
        redirect($back);
    }

    if ($action === 'copy') {
        $from = post('from');
        if (session_start_year($from) && $from !== $session) {
            $st = db()->prepare('INSERT IGNORE INTO faculty_teaching
                       (faculty_id, college_id, session, semester, study_year, branch_id,
                        subject_name, subject_code, subject_type, periods)
                SELECT faculty_id, college_id, ?, semester, study_year, branch_id,
                       subject_name, subject_code, subject_type, periods
                  FROM faculty_teaching WHERE faculty_id = ? AND session = ?');
            $st->execute([$session, $facultyId, $from]);
            set_flash('success', $st->rowCount() . ' subject(s) copied from ' . $from . ' to ' . $session . '.');
        }
        redirect($back);
    }

    if ($action === 'save') {
        $form['id']           = (int)post('id');
        $form['semester']     = (int)post('semester');
        $form['branch_id']    = (int)post('branch_id');
        $form['subject_name'] = preg_replace('/\s+/u', ' ', post('subject_name'));
        $form['subject_code'] = strtoupper(post('subject_code'));
        $form['subject_type'] = post('subject_type');
        $form['periods']      = post('periods');

        if (!isset(semesters()[$form['semester']]))       { $errors['semester'] = 'Choose the semester.'; }
        if ($form['branch_id'] && !in_array($form['branch_id'], $branchIds, true)) {
            $errors['branch_id'] = 'Choose a branch of your college.';
        }
        if (mb_strlen($form['subject_name']) < 2)          { $errors['subject_name'] = 'Enter the subject name.'; }
        if (mb_strlen($form['subject_code']) > 30)         { $errors['subject_code'] = 'Keep the code under 30 characters.'; }
        if (!isset(subject_types()[$form['subject_type']])) { $form['subject_type'] = 'Theory'; }
        $periods = $form['periods'] === '' ? null : (int)$form['periods'];
        if ($periods !== null && ($periods < 1 || $periods > 40)) { $errors['periods'] = '1 to 40 periods a week.'; }

        if (!$errors) {
            $vals = [$form['semester'], semester_year($form['semester']), $form['branch_id'],
                     mb_substr($form['subject_name'], 0, 120),
                     $form['subject_code'] !== '' ? $form['subject_code'] : null,
                     $form['subject_type'], $periods];
            try {
                if ($form['id']) {
                    db()->prepare('UPDATE faculty_teaching SET semester = ?, study_year = ?, branch_id = ?,
                                          subject_name = ?, subject_code = ?, subject_type = ?, periods = ?
                                    WHERE id = ? AND faculty_id = ?')
                        ->execute(array_merge($vals, [$form['id'], $facultyId]));
                    set_flash('success', 'Subject saved.');
                } else {
                    db()->prepare('INSERT INTO faculty_teaching (semester, study_year, branch_id, subject_name,
                                          subject_code, subject_type, periods, faculty_id, college_id, session)
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                        ->execute(array_merge($vals, [$facultyId, $collegeId, $session]));
                    set_flash('success', $form['subject_name'] . ' added to Semester ' . $form['semester']
                        . ' for session ' . $session . '.');
                }
                redirect($back);
            } catch (PDOException $ex) {
                if ($ex->getCode() !== '23000') {
                    throw $ex;
                }
                $errors['subject_name'] = 'This subject is already set for that semester and branch.';
            }
        }
    }
}

$rows     = get_teaching($facultyId, $session);
$sessions = teaching_sessions($facultyId);
// Always offer the current and the next session, plus every one on record.
$start = session_start_year($current);
$sessionList = array_unique(array_merge([session_from_start($start + 1), $current], array_keys($sessions), [$session]));
rsort($sessionList);
$copySources = array_values(array_filter(array_keys($sessions), fn($s) => $s !== $session));

$bySem = [];
foreach ($rows as $r) {
    $bySem[(int)$r['semester']][] = $r;
}
$termCount = ['odd' => 0, 'even' => 0];
foreach ($rows as $r) {
    $termCount[semester_term((int)$r['semester'])]++;
}
$periodsTotal = array_sum(array_map(fn($r) => (int)$r['periods'], $rows));
$classes = count(array_unique(array_map(fn($r) => $r['semester'] . '-' . $r['branch_id'], $rows)));
$activeTerm = $session === $current ? current_term() : null;

$pageTitle = 'My Subject';
$activeNav = 'mysubjects';
require_once __DIR__ . '/includes/faculty_header.php';

$err = fn($k) => isset($errors[$k]) ? 'is-invalid' : '';
$msg = fn($k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . e($errors[$k]) . '</div>' : '';
$typeClass = ['Theory' => 'ms-type-theory', 'Practical' => 'ms-type-practical', 'Both' => 'ms-type-both'];
?>

<div class="el-hero ms-hero mb-4">
  <div>
    <span class="el-hero-eyebrow"><i class="bi bi-book-half me-1"></i>My Subject &middot; Academic Session</span>
    <h3 class="mb-1">Session <?= e($session) ?></h3>
    <p class="mb-0">Odd term <?= e(term_info($session, 'odd')['months']) ?> &nbsp;&middot;&nbsp;
      Even term <?= e(term_info($session, 'even')['months']) ?></p>
    <div class="el-hero-stats">
      <div><strong><?= count($rows) ?></strong><span>Subjects</span></div>
      <div><strong><?= $termCount['odd'] ?></strong><span>Odd sem</span></div>
      <div><strong><?= $termCount['even'] ?></strong><span>Even sem</span></div>
      <div><strong><?= $classes ?></strong><span>Classes</span></div>
      <div><strong><?= $periodsTotal ?: '–' ?></strong><span>Periods / week</span></div>
    </div>
  </div>
  <i class="bi bi-calendar2-range el-hero-icon"></i>
</div>

<div class="ms-session-bar mb-4">
  <span class="small fw-semibold text-muted-2 me-1"><i class="bi bi-clock-history me-1"></i>Session</span>
  <?php foreach ($sessionList as $s): ?>
    <a href="<?= url('faculty/my_subjects.php?session=' . urlencode($s)) ?>"
       class="ms-session-chip <?= $s === $session ? 'active' : '' ?>">
      <?= e($s) ?>
      <?php if ($s === $current): ?><span class="ms-now">Current</span><?php endif; ?>
      <?php if (!empty($sessions[$s])): ?><span class="ms-count"><?= (int)$sessions[$s] ?></span><?php endif; ?>
    </a>
  <?php endforeach; ?>
  <?php if ($copySources): ?>
    <form method="post" class="ms-copy ms-auto">
      <?= csrf_field() ?>
      <input type="hidden" name="session" value="<?= e($session) ?>">
      <input type="hidden" name="action" value="copy">
      <select name="from" class="form-select form-select-sm">
        <?php foreach ($copySources as $s): ?><option value="<?= e($s) ?>"><?= e($s) ?></option><?php endforeach; ?>
      </select>
      <button class="btn btn-sm btn-outline-primary text-nowrap"
              data-confirm="Copy all subjects of the chosen session into <?= e($session) ?>? Subjects already set are kept.">
        <i class="bi bi-copy me-1"></i>Copy into <?= e($session) ?></button>
    </form>
  <?php endif; ?>
</div>

<div class="row g-4">
  <div class="col-xl-4">
    <form method="post" class="card border-0 el-form ms-form" id="subjectForm" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="session" value="<?= e($session) ?>">
      <input type="hidden" name="id" value="<?= (int)$form['id'] ?>">
      <div class="card-body p-4">
        <h5 class="mb-1"><i class="bi bi-<?= $form['id'] ? 'pencil-square' : 'plus-circle' ?> me-2 text-primary"></i><?= $form['id'] ? 'Edit subject' : 'Add a subject' ?></h5>
        <p class="small text-muted-2 mb-3">For session <strong><?= e($session) ?></strong></p>

        <label class="form-label" for="semester">Semester</label>
        <select class="form-select mb-1 <?= $err('semester') ?>" id="semester" name="semester">
          <option value="">-- Select semester --</option>
          <?php foreach (['odd', 'even'] as $t): $ti = term_info($session, $t); ?>
            <optgroup label="<?= e($ti['label'] . ' · ' . $ti['months']) ?>">
              <?php foreach ($ti['sems'] as $s): ?>
                <option value="<?= $s ?>" <?= (int)$form['semester'] === $s ? 'selected' : '' ?>>
                  Semester <?= $s ?> &middot; <?= e(year_label(semester_year($s))) ?></option>
              <?php endforeach; ?>
            </optgroup>
          <?php endforeach; ?>
        </select>
        <div class="small text-muted-2 mb-3" id="termHint"></div>
        <?= $msg('semester') ?>

        <label class="form-label" for="branch_id">Branch</label>
        <select class="form-select mb-3 <?= $err('branch_id') ?>" id="branch_id" name="branch_id">
          <option value="0">All branches</option>
          <?php foreach ($branches as $b): ?>
            <option value="<?= (int)$b['id'] ?>" <?= (int)$form['branch_id'] === (int)$b['id'] ? 'selected' : '' ?>>
              <?= e($b['code']) ?> - <?= e($b['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <?= $msg('branch_id') ?>

        <label class="form-label" for="subject_name">Subject name</label>
        <input type="text" class="form-control mb-1 <?= $err('subject_name') ?>" id="subject_name" name="subject_name"
               maxlength="120" value="<?= e($form['subject_name']) ?>" placeholder="e.g. Digital Electronics">
        <?= $msg('subject_name') ?>

        <div class="row g-2 mt-2">
          <div class="col-6">
            <label class="form-label" for="subject_code">Code <span class="text-muted-2 small">(optional)</span></label>
            <input type="text" class="form-control <?= $err('subject_code') ?>" id="subject_code" name="subject_code"
                   maxlength="30" value="<?= e((string)$form['subject_code']) ?>" placeholder="e.g. EC-301">
          </div>
          <div class="col-6">
            <label class="form-label" for="periods">Periods / week</label>
            <input type="number" min="1" max="40" class="form-control <?= $err('periods') ?>" id="periods" name="periods"
                   value="<?= e((string)$form['periods']) ?>" placeholder="e.g. 4">
          </div>
        </div>
        <?= $msg('subject_code') ?><?= $msg('periods') ?>

        <label class="form-label mt-3">Type</label>
        <div class="ms-type-pick">
          <?php foreach (subject_types() as $t => $icon): ?>
            <input type="radio" class="btn-check" name="subject_type" id="type<?= $t ?>" value="<?= $t ?>"
                   <?= $form['subject_type'] === $t ? 'checked' : '' ?>>
            <label for="type<?= $t ?>"><i class="bi bi-<?= $icon ?>"></i><?= $t === 'Both' ? 'Theory + Practical' : $t ?></label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="card-footer bg-transparent d-flex gap-2 p-3">
        <?php if ($form['id']): ?>
          <a href="<?= url($back) ?>" class="btn btn-light">Cancel</a>
        <?php endif; ?>
        <button class="btn btn-primary flex-grow-1"><i class="bi bi-check2-circle me-1"></i><?= $form['id'] ? 'Save Changes' : 'Add Subject' ?></button>
      </div>
    </form>
  </div>

  <div class="col-xl-8">
    <div class="row g-4">
      <?php foreach (['odd', 'even'] as $t): $ti = term_info($session, $t); ?>
        <div class="col-lg-6">
          <div class="ms-term ms-term-<?= $t ?> <?= $activeTerm === $t ? 'is-running' : '' ?>">
            <div class="ms-term-head">
              <div>
                <div class="ms-term-label"><?= e($ti['label']) ?>
                  <?php if ($activeTerm === $t): ?><span class="ms-live"><span></span>Running now</span><?php endif; ?></div>
                <div class="ms-term-months"><i class="bi bi-calendar3 me-1"></i><?= e($ti['months']) ?></div>
              </div>
              <div class="ms-term-count"><?= $termCount[$t] ?><small>subjects</small></div>
            </div>

            <?php foreach ($ti['sems'] as $sem): ?>
              <div class="ms-sem">
                <div class="ms-sem-title">
                  <span class="ms-sem-badge"><?= $sem ?></span>
                  Semester <?= $sem ?> <span class="text-muted-2 fw-normal">&middot; <?= e(year_label(semester_year($sem))) ?></span>
                </div>
                <?php if (empty($bySem[$sem])): ?>
                  <div class="ms-empty">No subject set</div>
                <?php else: foreach ($bySem[$sem] as $r): ?>
                  <div class="ms-subject <?= (int)$form['id'] === (int)$r['id'] ? 'is-editing' : '' ?>">
                    <span class="ms-type-dot <?= $typeClass[$r['subject_type']] ?? '' ?>" title="<?= e($r['subject_type']) ?>">
                      <i class="bi bi-<?= subject_types()[$r['subject_type']] ?? 'journal-text' ?>"></i></span>
                    <div class="min-w-0 flex-grow-1">
                      <div class="ms-subject-name text-truncate"><?= e($r['subject_name']) ?></div>
                      <div class="ms-subject-meta">
                        <?php if ($r['subject_code']): ?><span class="ms-code"><?= e($r['subject_code']) ?></span><?php endif; ?>
                        <span><?= e($r['branch_id'] ? ($r['branch_code'] ?: 'Branch') : 'All branches') ?></span>
                        <span><?= e($r['subject_type'] === 'Both' ? 'Theory + Practical' : $r['subject_type']) ?></span>
                        <?php if ($r['periods']): ?><span><?= (int)$r['periods'] ?> periods/wk</span><?php endif; ?>
                      </div>
                    </div>
                    <div class="ms-actions">
                      <a href="<?= url('faculty/my_subjects.php?edit=' . (int)$r['id']) ?>" class="btn btn-sm btn-light" title="Edit">
                        <i class="bi bi-pencil"></i></a>
                      <form method="post" class="d-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="session" value="<?= e($session) ?>">
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <button class="btn btn-sm btn-light text-danger" title="Remove"
                                data-confirm="Remove <?= e($r['subject_name']) ?> from session <?= e($session) ?>?">
                          <i class="bi bi-trash"></i></button>
                      </form>
                    </div>
                  </div>
                <?php endforeach; endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<script>
(function () {
  var sem = document.getElementById('semester'), hint = document.getElementById('termHint');
  var terms = <?= json_encode(['odd' => term_info($session, 'odd'), 'even' => term_info($session, 'even')]) ?>;
  function show() {
    var v = parseInt(sem.value, 10);
    if (!v) { hint.textContent = ''; return; }
    var t = terms[v % 2 ? 'odd' : 'even'];
    hint.innerHTML = '<i class="bi bi-info-circle me-1"></i>' + t.label + ' term · ' + t.months;
  }
  sem.addEventListener('change', show); show();
})();
</script>

<?php require_once __DIR__ . '/includes/faculty_footer.php'; ?>
