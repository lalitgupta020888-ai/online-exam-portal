<?php
/**
 * Attendance board shared by admin/attendance.php and faculty/attendance.php.
 *
 * Expects $collegeId (int) and $boardPage ('admin/attendance.php' ...).
 * Reads the date and the branch / year / semester filters from the query string.
 */

$date = (string)($_GET['date'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > date('Y-m-d')) {
    $date = date('Y-m-d');
}
$filters = [
    'branch_id'  => (int)($_GET['branch_id'] ?? 0),
    'study_year' => (int)($_GET['study_year'] ?? 0),
    'semester'   => (int)($_GET['semester'] ?? 0),
];
$show = in_array($_GET['show'] ?? '', ['present', 'absent'], true) ? $_GET['show'] : 'all';

$register  = attendance_register($collegeId, $date, $filters);
$board     = attendance_breakdown($register);
$branches  = get_branches($collegeId);
$pctAll    = attendance_pct($board['present'], $board['total']);
$isToday   = $date === date('Y-m-d');
$prevDay   = date('Y-m-d', strtotime($date . ' -1 day'));
$nextDay   = date('Y-m-d', strtotime($date . ' +1 day'));

/** Same page, with some query values changed. */
$boardLink = static function (array $change) use ($boardPage, $date, $filters, $show): string {
    $q = array_filter(array_merge(['date' => $date, 'show' => $show], $filters, $change),
                      static fn($v) => $v !== 0 && $v !== '' && $v !== 'all');
    return url($boardPage . ($q ? '?' . http_build_query($q) : ''));
};

$ringTone = static fn(int $pct): string => $pct >= 75 ? 'good' : ($pct >= 50 ? 'mid' : 'low');
?>

<!-- ------------------------------ Hero ------------------------------ -->
<section class="att-card att-board-hero mb-4" data-state="<?= $board['present'] ? 'done' : 'ready' ?>">
  <div class="att-glow"></div>
  <div class="att-body">
    <div class="att-info">
      <span class="att-eyebrow"><i class="bi bi-calendar2-check"></i>Attendance register</span>
      <h3 class="att-title"><?= e(date('l, d M Y', strtotime($date))) ?><?= $isToday ? ' · Today' : '' ?></h3>
      <p class="att-sub mb-3">
        <strong><?= $board['present'] ?></strong> of <strong><?= $board['total'] ?></strong> students
        marked present from the campus<?= array_filter($filters) ? ' in the selected class' : '' ?>.
        <?php if ($isToday): ?>This list updates as students check in.<?php endif; ?>
      </p>
      <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-sm btn-light" href="<?= $boardLink(['date' => $prevDay]) ?>">
          <i class="bi bi-chevron-left"></i> Previous day</a>
        <?php if (!$isToday): ?>
          <a class="btn btn-sm btn-light" href="<?= $boardLink(['date' => $nextDay]) ?>">
            Next day <i class="bi bi-chevron-right"></i></a>
          <a class="btn btn-sm btn-gold" href="<?= $boardLink(['date' => date('Y-m-d')]) ?>">Today</a>
        <?php endif; ?>
      </div>
    </div>
    <div class="att-action">
      <div class="att-ring <?= $ringTone($pctAll) ?>" style="--pct: <?= $pctAll ?>">
        <div><strong><?= $pctAll ?>%</strong><span>present</span></div>
      </div>
      <div class="att-stats justify-content-center">
        <div><strong class="text-success-emphasis"><?= $board['present'] ?></strong><span>present</span></div>
        <div><strong><?= $board['total'] - $board['present'] ?></strong><span>absent</span></div>
      </div>
    </div>
  </div>
</section>

<!-- ----------------------------- Filters ---------------------------- -->
<form method="get" class="card border-0 mb-4">
  <div class="card-body row g-2 align-items-end">
    <div class="col-6 col-md-3">
      <label class="form-label small mb-1" for="attDate">Date</label>
      <input type="date" class="form-control" id="attDate" name="date"
             value="<?= e($date) ?>" max="<?= e(date('Y-m-d')) ?>">
    </div>
    <div class="col-6 col-md-3">
      <label class="form-label small mb-1" for="attBranch">Branch</label>
      <select class="form-select" id="attBranch" name="branch_id">
        <option value="">All branches</option>
        <?php foreach ($branches as $b): ?>
          <option value="<?= (int)$b['id'] ?>" <?= $filters['branch_id'] === (int)$b['id'] ? 'selected' : '' ?>>
            <?= e($b['code']) ?> - <?= e($b['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small mb-1" for="attYear">Year</label>
      <select class="form-select" id="attYear" name="study_year">
        <option value="">All years</option>
        <?php foreach (study_years() as $v => $label): ?>
          <option value="<?= $v ?>" <?= $filters['study_year'] === $v ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small mb-1" for="attSem">Semester</label>
      <select class="form-select" id="attSem" name="semester">
        <option value="">All</option>
        <?php foreach (semesters() as $v => $label): ?>
          <option value="<?= $v ?>" <?= $filters['semester'] === $v ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <input type="hidden" name="show" value="<?= e($show) ?>">
    <div class="col-12 col-md-2 d-grid">
      <button class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Apply</button>
    </div>
  </div>
</form>

<?php if (!$board['total']): ?>
  <div class="card empty-state text-center">
    <div class="empty-state-icon"><i class="bi bi-people"></i></div>
    <h5 class="mb-2">No students in this selection</h5>
    <p class="text-muted-2 mb-0">Change the branch, year or semester filter to see a register.</p>
  </div>
<?php else: ?>

  <!-- --------------------- Branch-wise overview --------------------- -->
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><i class="bi bi-diagram-3 me-1"></i>Branch-wise</h5>
  </div>
  <div class="row g-3 mb-4">
    <?php foreach ($board['branches'] as $bid => $b): $p = attendance_pct($b['present'], $b['total']); ?>
      <div class="col-sm-6 col-xl-3">
        <a href="#branch-<?= (int)$bid ?>" class="att-branch-tile card border-0 h-100">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-start mb-2">
              <span class="att-code"><?= e($b['code']) ?></span>
              <span class="att-pct <?= $ringTone($p) ?>"><?= $p ?>%</span>
            </div>
            <div class="fw-semibold text-truncate mb-2" title="<?= e($b['name']) ?>"><?= e($b['name']) ?></div>
            <div class="att-bar <?= $ringTone($p) ?>"><span style="width: <?= $p ?>%"></span></div>
            <div class="small text-muted-2 mt-2">
              <?= $b['present'] ?> / <?= $b['total'] ?> present · <?= count($b['classes']) ?>
              class<?= count($b['classes']) > 1 ? 'es' : '' ?>
            </div>
          </div>
        </a>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- ---------------------- Class-wise register --------------------- -->
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h5 class="mb-0"><i class="bi bi-people me-1"></i>Class-wise register</h5>
    <div class="d-flex flex-wrap gap-2">
      <div class="btn-group btn-group-sm">
        <?php foreach (['all' => 'All', 'present' => 'Present', 'absent' => 'Absent'] as $k => $label): ?>
          <a href="<?= $boardLink(['show' => $k]) ?>"
             class="btn <?= $show === $k ? 'btn-primary' : 'btn-outline-primary' ?>"><?= $label ?></a>
        <?php endforeach; ?>
      </div>
      <input type="search" class="form-control form-control-sm" style="max-width: 220px"
             placeholder="Search student…" data-att-search>
    </div>
  </div>

  <?php foreach ($board['branches'] as $bid => $b): ?>
    <section class="card border-0 mb-4 att-branch" id="branch-<?= (int)$bid ?>">
      <div class="att-branch-head">
        <span class="att-code"><?= e($b['code']) ?></span>
        <div class="flex-grow-1" style="min-width: 0">
          <h6 class="mb-0 text-truncate"><?= e($b['name']) ?></h6>
          <span class="small text-muted-2"><?= $b['present'] ?> of <?= $b['total'] ?> present</span>
        </div>
        <span class="att-pct <?= $ringTone(attendance_pct($b['present'], $b['total'])) ?>">
          <?= attendance_pct($b['present'], $b['total']) ?>%</span>
      </div>

      <?php foreach ($b['classes'] as $c): $cp = attendance_pct($c['present'], $c['total']); ?>
        <details class="att-class" <?= count($b['classes']) === 1 || array_filter($filters) ? 'open' : '' ?>>
          <summary>
            <span class="class-chip year"><?= e($c['label']) ?></span>
            <div class="att-bar flex-grow-1 <?= $ringTone($cp) ?>"><span style="width: <?= $cp ?>%"></span></div>
            <span class="small fw-semibold text-nowrap"><?= $c['present'] ?>/<?= $c['total'] ?> · <?= $cp ?>%</span>
            <i class="bi bi-chevron-down att-caret"></i>
          </summary>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0 att-table">
              <thead><tr><th>Student</th><th>Enrolment</th><th>Status</th><th>Check-in</th><th>Distance</th></tr></thead>
              <tbody>
                <?php foreach ($c['students'] as $s):
                  $here = $s['marked_at'] !== null;
                  if (($show === 'present' && !$here) || ($show === 'absent' && $here)) { continue; } ?>
                  <tr data-att-row>
                    <td>
                      <div class="d-flex align-items-center gap-2">
                        <span class="att-avatar <?= $here ? 'in' : '' ?>"><?= e(initials($s['name'])) ?></span>
                        <span class="fw-medium"><?= e($s['name']) ?></span>
                      </div>
                    </td>
                    <td class="text-muted-2 small"><?= e($s['enrollment_no'] ?: '—') ?></td>
                    <td>
                      <?php if ($here): ?>
                        <span class="att-status in"><i class="bi bi-check-circle-fill"></i>Present</span>
                      <?php else: ?>
                        <span class="att-status out"><i class="bi bi-dash-circle"></i>Absent</span>
                      <?php endif; ?>
                    </td>
                    <td class="small"><?= $here ? e(date('h:i A', strtotime($s['marked_at']))) : '—' ?></td>
                    <td class="small text-muted-2"><?= $here ? e(num((float)$s['distance_m'])) . ' m' : '—' ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </details>
      <?php endforeach; ?>
    </section>
  <?php endforeach; ?>

  <script>
  (function () {
    var box = document.querySelector('[data-att-search]');
    box.addEventListener('input', function () {
      var needle = box.value.toLowerCase();
      document.querySelectorAll('[data-att-row]').forEach(function (row) {
        row.hidden = row.textContent.toLowerCase().indexOf(needle) === -1;
      });
      if (needle) document.querySelectorAll('.att-class').forEach(function (d) { d.open = true; });
    });
  })();
  </script>
<?php endif; ?>
