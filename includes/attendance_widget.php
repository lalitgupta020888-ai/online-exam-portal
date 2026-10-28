<?php
/**
 * "Today's attendance" card for the admin and faculty dashboards.
 * Expects $collegeId (int) and $boardPage (link target of the full register).
 */
$attBoard = attendance_breakdown(attendance_register($collegeId, date('Y-m-d')));
$attPct   = attendance_pct($attBoard['present'], $attBoard['total']);
$attTone  = $attPct >= 75 ? 'good' : ($attPct >= 50 ? 'mid' : 'low');

$st = db()->prepare('SELECT a.marked_at, s.name FROM attendance a JOIN students s ON s.id = a.student_id
                      WHERE a.college_id = ? AND a.att_date = ? ORDER BY a.marked_at DESC LIMIT 5');
$st->execute([$collegeId, date('Y-m-d')]);
$attLatest = $st->fetchAll();
?>
<a href="<?= url($boardPage) ?>" class="att-card att-widget d-block mb-4" data-state="<?= $attBoard['present'] ? 'done' : 'ready' ?>">
  <div class="att-glow"></div>
  <div class="att-body">
    <div class="att-ring sm <?= $attTone ?>" style="--pct: <?= $attPct ?>">
      <div><strong><?= $attPct ?>%</strong><span>today</span></div>
    </div>
    <div class="att-info">
      <span class="att-eyebrow"><i class="bi bi-geo-alt-fill"></i>Student Attendance · <?= e(date('d M Y')) ?></span>
      <h4 class="att-title mb-1"><?= $attBoard['present'] ?> of <?= $attBoard['total'] ?> students present</h4>
      <div class="att-widget-branches">
        <?php foreach (array_slice($attBoard['branches'], 0, 6) as $b): ?>
          <span><b><?= e($b['code']) ?></b> <?= $b['present'] ?>/<?= $b['total'] ?></span>
        <?php endforeach; ?>
      </div>
      <?php if ($attLatest): ?>
        <div class="att-sub small mb-0 mt-2">
          Latest: <?= e(implode(', ', array_map(static fn($r) => $r['name'] . ' ' . date('h:i A', strtotime($r['marked_at'])), $attLatest))) ?>
        </div>
      <?php endif; ?>
    </div>
    <span class="btn btn-gold att-btn">
      <i class="bi bi-list-check me-1"></i>View attendance <i class="bi bi-arrow-right ms-1"></i>
    </span>
  </div>
</a>
