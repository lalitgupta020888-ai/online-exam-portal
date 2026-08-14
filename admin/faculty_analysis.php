<?php
/**
 * Admin - comparative analysis of every faculty member.
 *
 * One row per faculty with what they set, how they marked it and how their
 * students did, so the administrator can spot a backlog, an unusually strict
 * or lenient marker, or a paper set that is too easy.
 */
require_once __DIR__ . '/../includes/auth.php';
$admin     = require_admin();
$collegeId = (int)$admin['college_id'];

$sort = $_GET['sort'] ?? 'papers';

$st = db()->prepare(
    "SELECT * FROM faculty
      WHERE college_id = ? AND status <> 'pending' ORDER BY name"
);
$st->execute([$collegeId]);
$faculty = $st->fetchAll();

$rows = [];
foreach ($faculty as $f) {
    $rows[] = ['faculty' => $f, 'a' => faculty_analytics((int)$f['id'])];
}

// Sorting is done in PHP because the metrics are derived, not stored.
usort($rows, static function ($x, $y) use ($sort) {
    return match ($sort) {
        'pending'   => $y['a']['pending_papers'] <=> $x['a']['pending_papers'],
        'questions' => $y['a']['questions'] <=> $x['a']['questions'],
        'students'  => $y['a']['students'] <=> $x['a']['students'],
        'strict'    => ($x['a']['award_ratio'] ?? 999) <=> ($y['a']['award_ratio'] ?? 999),
        'lenient'   => ($y['a']['award_ratio'] ?? -1) <=> ($x['a']['award_ratio'] ?? -1),
        'passrate'  => ($y['a']['pass_rate'] ?? -1) <=> ($x['a']['pass_rate'] ?? -1),
        'name'      => strcasecmp($x['faculty']['name'], $y['faculty']['name']),
        default     => $y['a']['papers'] <=> $x['a']['papers'],
    };
});

// Portal wide totals for the tiles at the top.
$totals = [
    'faculty'    => count($rows),
    'papers'     => array_sum(array_column(array_column($rows, 'a'), 'papers')),
    'questions'  => array_sum(array_column(array_column($rows, 'a'), 'questions')),
    'evaluated'  => array_sum(array_column(array_column($rows, 'a'), 'evaluated')),
    'pending'    => array_sum(array_column(array_column($rows, 'a'), 'pending_papers')),
    'submissions'=> array_sum(array_column(array_column($rows, 'a'), 'submissions')),
];

$pageTitle = 'Faculty Analysis';
$activeNav = 'analysis';
require_once __DIR__ . '/includes/admin_header.php';
?>

<div class="row g-3 mb-3">
  <?php foreach ([
    ['person-video3',   'text-primary', 'Faculty',        $totals['faculty']],
    ['journal-text',    'text-info',    'Papers Set',     $totals['papers']],
    ['patch-question',  'text-secondary','Questions Authored', $totals['questions']],
    ['clipboard-data',  'text-success', 'Papers Submitted', $totals['submissions']],
    ['check2-all',      'text-success', 'Answers Marked', $totals['evaluated']],
    ['hourglass-split', 'text-danger',  'Awaiting Marking', $totals['pending']],
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

<?php if ($totals['pending'] > 0): ?>
  <div class="alert alert-warning d-flex align-items-center gap-2">
    <i class="bi bi-exclamation-triangle-fill fs-4"></i>
    <div>
      <strong><?= $totals['pending'] ?> submitted paper(s)</strong> are still waiting for a
      faculty member to mark the written answers. Those students are seeing a provisional
      score only.
    </div>
  </div>
<?php endif; ?>

<div class="card border-0 mb-3">
  <div class="card-body d-flex flex-wrap align-items-center gap-2">
    <span class="small fw-semibold text-muted-2 me-1">Sort by</span>
    <?php foreach ([
      'papers'    => 'Papers set',
      'questions' => 'Questions authored',
      'students'  => 'Students reached',
      'pending'   => 'Marking backlog',
      'strict'    => 'Strictest marker',
      'lenient'   => 'Most lenient',
      'passrate'  => 'Pass rate',
      'name'      => 'Name',
    ] as $key => $label): ?>
      <a class="btn btn-sm <?= $sort === $key ? 'btn-primary' : 'btn-outline-secondary' ?>"
         href="<?= url('admin/faculty_analysis.php?sort=' . $key) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<div class="card border-0 mb-4">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead>
        <tr>
          <th>Faculty</th>
          <th>Papers</th><th>Questions</th><th>Students</th>
          <th>Marked</th><th>Pending</th>
          <th style="min-width:150px">Marking generosity</th>
          <th>Feedback</th><th>Turnaround</th>
          <th style="min-width:130px">Pass rate</th>
          <th class="text-end">Analysis</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as ['faculty' => $f, 'a' => $a]): ?>
          <tr>
            <td>
              <div class="d-flex align-items-center gap-2">
                <span class="avatar-sm"><?= e(initials($f['name'])) ?></span>
                <div>
                  <div class="fw-semibold"><?= e($f['name']) ?></div>
                  <div class="small text-muted-2">
                    <?= e($f['designation'] ?: 'Faculty') ?>
                    <?php if ($f['department']): ?> &middot; <?= e($f['department']) ?><?php endif; ?>
                  </div>
                </div>
              </div>
            </td>
            <td><?= $a['papers'] ?></td>
            <td>
              <?= $a['questions'] ?>
              <?php if ($a['subjective_questions'] > 0): ?>
                <div class="small text-muted-2"><?= $a['subjective_questions'] ?> written</div>
              <?php endif; ?>
            </td>
            <td><?= $a['students'] ?></td>
            <td><?= $a['evaluated'] ?></td>
            <td>
              <?php if ($a['pending_papers'] > 0): ?>
                <span class="badge bg-danger"><?= $a['pending_papers'] ?></span>
              <?php else: ?>
                <span class="text-muted-2">-</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($a['award_ratio'] === null): ?>
                <span class="text-muted-2 small">no data</span>
              <?php else:
                  $tone = $a['award_ratio'] >= 85 ? 'bg-warning'
                        : ($a['award_ratio'] <= 40 ? 'bg-danger' : 'bg-success'); ?>
                <div class="d-flex align-items-center gap-2">
                  <div class="progress flex-grow-1" style="height:7px">
                    <div class="progress-bar <?= $tone ?>"
                         style="width:<?= (float)$a['award_ratio'] ?>%"></div>
                  </div>
                  <span class="small fw-semibold"><?= $a['award_ratio'] ?>%</span>
                </div>
                <div class="small text-muted-2">
                  <?= $a['award_ratio'] >= 85 ? 'lenient'
                       : ($a['award_ratio'] <= 40 ? 'strict' : 'balanced') ?>
                </div>
              <?php endif; ?>
            </td>
            <td>
              <?= $a['feedback_pct'] === null ? '<span class="text-muted-2">-</span>'
                   : $a['feedback_pct'] . '%' ?>
            </td>
            <td class="small">
              <?php if ($a['avg_turnaround'] === null): ?>
                <span class="text-muted-2">-</span>
              <?php elseif ($a['avg_turnaround'] < 24): ?>
                <?= num((float)$a['avg_turnaround']) ?> h
              <?php else: ?>
                <?= num($a['avg_turnaround'] / 24) ?> d
              <?php endif; ?>
            </td>
            <td>
              <?php if ($a['pass_rate'] === null): ?>
                <span class="text-muted-2 small">no papers yet</span>
              <?php else: ?>
                <div class="d-flex align-items-center gap-2">
                  <div class="progress flex-grow-1" style="height:7px">
                    <div class="progress-bar bg-success"
                         style="width:<?= (float)$a['pass_rate'] ?>%"></div>
                  </div>
                  <span class="small"><?= $a['pass_rate'] ?>%</span>
                </div>
                <div class="small text-muted-2">avg <?= num((float)$a['avg_pct']) ?>%</div>
              <?php endif; ?>
            </td>
            <td class="text-end">
              <a class="btn btn-sm btn-primary"
                 href="<?= url('admin/faculty_detail.php?id=' . (int)$f['id']) ?>">
                <i class="bi bi-graph-up me-1"></i>Analyse
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
          <tr><td colspan="11" class="text-center text-muted-2 py-5">
            No approved faculty accounts yet.
          </td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ------------------- Observations worth a look ------------------- -->
<h5 class="mb-3"><i class="bi bi-lightbulb text-warning me-2"></i>Things worth a look</h5>
<div class="row g-3">
  <?php
  $anyNotes = false;
  foreach ($rows as ['faculty' => $f, 'a' => $a]):
      $notes = array_filter(faculty_observations($a), fn($n) => $n[0] !== 'success' && $n[0] !== 'muted');
      if (!$notes) { continue; }
      $anyNotes = true;
  ?>
    <div class="col-lg-6">
      <div class="card border-0 h-100">
        <div class="card-body">
          <div class="d-flex align-items-center gap-2 mb-2">
            <span class="avatar-sm"><?= e(initials($f['name'])) ?></span>
            <div class="fw-semibold"><?= e($f['name']) ?></div>
            <a class="btn btn-sm btn-outline-primary ms-auto"
               href="<?= url('admin/faculty_detail.php?id=' . (int)$f['id']) ?>">Open</a>
          </div>
          <ul class="list-unstyled mb-0 small">
            <?php foreach ($notes as [$tone, $text]): ?>
              <li class="d-flex gap-2 py-1">
                <i class="bi bi-<?= $tone === 'danger' ? 'exclamation-octagon-fill text-danger'
                                    : 'exclamation-triangle-fill text-warning' ?>"></i>
                <span><?= e($text) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>
    </div>
  <?php endforeach; ?>

  <?php if (!$anyNotes): ?>
    <div class="col-12">
      <div class="card border-0 p-4 text-center text-muted-2">
        <i class="bi bi-check2-circle fs-3 text-success mb-2"></i>
        Nothing stands out - no marking backlog and no unusual marking patterns.
      </div>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
