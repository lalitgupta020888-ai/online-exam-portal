<?php
/**
 * Attendance download shared by admin/attendance_export.php and
 * faculty/attendance_export.php.
 *
 *   ?period=day&date=YYYY-MM-DD     one day's register
 *   ?period=month&month=YYYY-MM     the whole month as a P / A grid
 *   &format=xlsx | pdf              Excel file, or a print page ("Save as PDF")
 *   &branch_id= &study_year= &semester=   the same filters as the register
 *
 * Expects $collegeId, $attFaculty (null = whole college) and $boardPage.
 */
require_once __DIR__ . '/result_sheet.php';      // xlsx_build(), xlsx_col()

$period = ($_GET['period'] ?? '') === 'month' ? 'month' : 'day';
$format = ($_GET['format'] ?? '') === 'pdf' ? 'pdf' : 'xlsx';
$filters = [
    'branch_id'  => (int)($_GET['branch_id'] ?? 0),
    'study_year' => (int)($_GET['study_year'] ?? 0),
    'semester'   => (int)($_GET['semester'] ?? 0),
];

$date = (string)($_GET['date'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > date('Y-m-d')) {
    $date = date('Y-m-d');
}
$month = (string)($_GET['month'] ?? '');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) || $month > date('Y-m')) {
    $month = date('Y-m');
}

$college = get_college($collegeId);
$place   = trim(implode(', ', array_filter([$college['address'] ?? '', $college['city'] ?? '',
                                            $college['state'] ?? ''])));

// What the sheet covers, for its heading.
$scope = [];
if ($filters['branch_id'])  { $scope[] = branch_name($filters['branch_id']); }
if ($filters['study_year']) { $scope[] = year_label($filters['study_year']); }
if ($filters['semester'])   { $scope[] = 'Semester ' . $filters['semester']; }
$scopeLabel = $scope ? implode(' · ', $scope)
            : ($attFaculty !== null ? 'My students (' . $attFaculty['name'] . ')' : 'All branches');

if ($period === 'day') {
    $rows    = attendance_register($collegeId, $date, $filters, $attFaculty);
    $present = count(array_filter($rows, static fn($r) => $r['marked_at'] !== null));
    $title   = 'Daily Attendance - ' . date('d M Y', strtotime($date));
    $summary = ['Students' => count($rows), 'Present' => $present, 'Absent' => count($rows) - $present,
                'Attendance %' => attendance_pct($present, count($rows))];
} else {
    $m       = attendance_month($collegeId, $month, $filters, $attFaculty);
    $rows    = $m['students'];
    $nDays   = $m['days'];
    $counted = $m['counted'];
    $title   = 'Monthly Attendance - ' . date('F Y', strtotime($month . '-01'));
    $isSun   = static fn(int $d): bool => date('w', strtotime(sprintf('%s-%02d', $month, $d))) === '0';
    $isFut   = static fn(int $d): bool => sprintf('%s-%02d', $month, $d) > date('Y-m-d');
    // Before attendance began, or still to come: left blank, never "absent".
    $isOff   = static fn(int $d): bool => $isFut($d) || sprintf('%s-%02d', $month, $d) < $m['since'];

    $dayTotals = array_fill(1, $nDays, 0);
    foreach ($rows as &$r) {
        $mine = $m['present'][(int)$r['id']] ?? [];
        foreach ($mine as $d => $_) { $dayTotals[$d]++; }
        $r['_days']    = $mine;
        $r['_present'] = count($mine);
        $r['_pct']     = min(100, attendance_pct(count($mine), count($counted)));
    }
    unset($r);
    $avg = $rows ? (int)round(array_sum(array_column($rows, '_pct')) / count($rows)) : 0;
    $summary = ['Students' => count($rows), 'Working days' => count($counted),
                'Average attendance %' => $avg,
                'Below 75%' => count(array_filter($rows, static fn($r) => $r['_pct'] < 75))];
}

$fileBase = preg_replace('/[^A-Za-z0-9_-]+/', '_',
    ($college['code'] ?? 'College') . '_Attendance_' . ($period === 'day' ? $date : $month));

/* ===================================================================== */
/*  Excel                                                                 */
/* ===================================================================== */
if ($format === 'xlsx') {
    $head = ['#', 'Enrolment No.', 'Student Name', 'Branch', 'Class'];
    if ($period === 'day') {
        $head = array_merge($head, ['Status', 'Check-in', 'Distance (m)']);
    } else {
        for ($d = 1; $d <= $nDays; $d++) { $head[] = (string)$d; }
        $head = array_merge($head, ['Present', 'Working Days', '%']);
    }
    $last  = xlsx_col(count($head) - 1);
    $cells = []; $merges = []; $heights = [];
    $r = 1;

    $cells[$r][0] = [$college['name'] ?? APP_NAME, 1]; $merges[] = "A$r:$last$r"; $heights[$r] = 34; $r++;
    if ($place !== '') { $cells[$r][0] = [$place, 15]; $merges[] = "A$r:$last$r"; $r++; }
    $cells[$r][0] = [mb_strtoupper($title), 2]; $merges[] = "A$r:$last$r"; $heights[$r] = 24; $r++;
    $cells[$r][0] = [$scopeLabel, 15]; $merges[] = "A$r:$last$r"; $r += 2;

    // Summary boxes
    $c = 0;
    foreach ($summary as $label => $value) {
        $span = $period === 'day' ? 1 : 2;
        $cells[$r][$c]     = [$label, 12];
        $cells[$r + 1][$c] = [$value, 11];
        if ($span > 1) {
            $cells[$r][$c + 1] = ['', 12]; $cells[$r + 1][$c + 1] = ['', 11];
            $merges[] = xlsx_col($c) . "$r:" . xlsx_col($c + 1) . $r;
            $merges[] = xlsx_col($c) . ($r + 1) . ':' . xlsx_col($c + 1) . ($r + 1);
        }
        $c += $span;
    }
    $heights[$r + 1] = 24;
    $r += 3;

    foreach ($head as $i => $label) { $cells[$r][$i] = [$label, 3]; }
    $heights[$r] = 30; $r++;

    foreach ($rows as $i => $s) {
        $line = [[$i + 1, 5], [$s['enrollment_no'] ?: '—', 5], [$s['name'], 16],
                 [$s['branch_code'] ?? '—', 5], [attendance_class_label($s), 5]];
        if ($period === 'day') {
            $here = $s['marked_at'] !== null;
            $line[] = [$here ? 'PRESENT' : 'ABSENT', $here ? 6 : 7];
            $line[] = [$here ? date('h:i A', strtotime($s['marked_at'])) : '—', 5];
            $line[] = [$here ? (float)$s['distance_m'] : '—', 5];
        } else {
            for ($d = 1; $d <= $nDays; $d++) {
                if (isset($s['_days'][$d]))  { $line[] = ['P', 6]; }
                elseif ($isOff($d))          { $line[] = ['', 5]; }
                elseif ($isSun($d))          { $line[] = ['S', 13]; }
                else                         { $line[] = ['A', 7]; }
            }
            $line[] = [$s['_present'], 5];
            $line[] = [count($counted), 5];
            $line[] = [$s['_pct'], $s['_pct'] >= 75 ? 6 : 7];
        }
        foreach ($line as $k => $cell) { $cells[$r][$k] = $cell; }
        $r++;
    }

    if ($period === 'month' && $rows) {
        $cells[$r][0] = ['Present per day', 3];
        for ($k = 1; $k <= 4; $k++) { $cells[$r][$k] = ['', 3]; }
        $merges[] = "A$r:E$r";
        for ($d = 1; $d <= $nDays; $d++) { $cells[$r][4 + $d] = [$dayTotals[$d], 3]; }
        for ($k = 5 + $nDays; $k < count($head); $k++) { $cells[$r][$k] = ['', 3]; }
        $r++;
    }
    if (!$rows) { $cells[$r][0] = ['No students in this selection.', 9]; $r++; }

    $r++;
    if ($period === 'month') {
        $cells[$r][0] = ['P = Present  ·  A = Absent  ·  S = Sunday  ·  % is counted over working days up to today.', 9];
        $r++;
    }
    $cells[$r][0] = ['Attendance marked by students from within ' . ATTENDANCE_RADIUS_M
                     . ' m of the campus point. Generated on ' . date('d M Y, h:i A') . ' by ' . APP_NAME . '.', 9];

    $widths = [5, 16, 26, 9, 18];
    if ($period === 'day') {
        array_push($widths, 12, 11, 12);
    } else {
        for ($d = 1; $d <= $nDays; $d++) { $widths[] = 4.2; }
        array_push($widths, 9, 9, 7);
    }

    $data = xlsx_build($period === 'day' ? 'Daily' : 'Monthly', $cells, $merges, $widths, 0, $heights);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fileBase . '.xlsx"');
    header('Content-Length: ' . strlen($data));
    header('Cache-Control: private, max-age=0');
    echo $data;
    exit;
}

/* ===================================================================== */
/*  PDF (print page)                                                      */
/* ===================================================================== */
$logo   = college_logo_url($college);
$xlsUrl = url(str_replace('attendance.php', 'attendance_export.php', $boardPage) . '?'
              . http_build_query(array_filter(array_merge($_GET, ['format' => 'xlsx']))));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($fileBase) ?></title>
<style>
  * { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  @page { size: A4 <?= $period === 'month' ? 'landscape' : 'portrait' ?>; margin: 9mm; }
  body { font-family: "Segoe UI", Arial, sans-serif; color: #0f172a; background: #e2e8f0;
         margin: 0; padding: 24px 12px; font-size: 11.5px; }
  .sheet { max-width: <?= $period === 'month' ? 1180 : 900 ?>px; margin: 0 auto; background: #fff; border-radius: 14px;
           overflow: hidden; box-shadow: 0 10px 30px rgba(15,23,42,.15); }
  .banner { background: linear-gradient(120deg, #0b1020, #1e2748 55%, #4338ca); color: #fff;
            padding: 18px 24px; display: flex; align-items: center; gap: 16px; }
  .logo { width: 62px; height: 62px; border-radius: 50%; background: #fff; flex: none; display: flex;
          align-items: center; justify-content: center; overflow: hidden; border: 3px solid #c8a45c; }
  .logo img { width: 100%; height: 100%; object-fit: contain; }
  .logo span { color: #1e2748; font-weight: 800; font-size: 22px; }
  .banner h1 { margin: 0; font-size: 21px; }
  .banner .place { opacity: .8; font-size: 11px; margin-top: 2px; }
  .banner .doc { margin-left: auto; text-align: right; }
  .banner .doc b { display: block; font-size: 14px; letter-spacing: .08em; text-transform: uppercase; color: #e6cf9a; }
  .banner .doc span { font-size: 11px; opacity: .85; }
  .body { padding: 16px 22px 22px; }
  h2 { font-size: 12px; margin: 16px 0 8px; color: #1e2748; text-transform: uppercase;
       letter-spacing: .06em; border-left: 4px solid #c8a45c; padding-left: 8px; }
  .grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; }
  .box { border-radius: 10px; padding: 8px 6px; text-align: center; background: #eef0ff; border: 1px solid #dfe3ff; }
  .box .v { font-size: 18px; font-weight: 800; color: #312e81; }
  .box .l { font-size: 9px; color: #64748b; text-transform: uppercase; letter-spacing: .04em; }
  .box.g { background: #f0fdf4; border-color: #bbf7d0; } .box.g .v { color: #15803d; }
  .box.r { background: #fef2f2; border-color: #fecaca; } .box.r .v { color: #b91c1c; }
  table { width: 100%; border-collapse: collapse; }
  th, td { border: 1px solid #e2e8f0; padding: 4px 5px; text-align: center; }
  td.l, th.l { text-align: left; }
  thead th { background: #1e2748; color: #fff; font-size: 9px; text-transform: uppercase; letter-spacing: .03em; }
  tbody tr:nth-child(even) { background: #f8fafc; }
  thead { display: table-header-group; }
  tr { page-break-inside: avoid; }
  .pill { display: inline-block; padding: 1px 8px; border-radius: 99px; font-weight: 700; font-size: 9.5px; }
  .P, .PRESENT { background: #dcfce7; color: #15803d; }
  .A, .ABSENT { background: #fee2e2; color: #b91c1c; }
  .month td, .month th { padding: 3px 1px; font-size: 8.5px; }
  .month td.l { padding-left: 4px; font-size: 9.5px; white-space: nowrap; }
  .month td.P { font-weight: 700; } .month td.A { font-weight: 700; }
  .month td.S, .month th.S { background: #f1f5f9; color: #94a3b8; }
  .month tfoot td { background: #eef0ff; font-weight: 700; color: #312e81; }
  .low { color: #b91c1c; font-weight: 700; } .ok { color: #15803d; font-weight: 700; }
  .signs { display: flex; justify-content: space-between; margin-top: 40px; padding: 0 10px; }
  .signs div { width: 190px; border-top: 1px solid #334155; text-align: center; padding-top: 4px; font-size: 10.5px; color: #334155; }
  .foot { margin-top: 16px; border-top: 1px dashed #cbd5e1; padding-top: 8px; font-size: 9.5px; color: #94a3b8; text-align: center; }
  .actions { text-align: center; margin-bottom: 16px; }
  .actions button, .actions a { padding: 9px 18px; border-radius: 8px; border: 0; background: #4338ca; color: #fff;
          font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; margin: 0 4px; font-size: 13px; }
  .actions a.x { background: #16a34a; } .actions a.b { background: #64748b; }
  @media print {
    .actions { display: none; }
    body { background: #fff; padding: 0; }
    .sheet { box-shadow: none; border-radius: 0; max-width: none; }
  }
</style>
</head>
<body>

<div class="actions">
  <button onclick="window.print()">Download PDF / Print</button>
  <a class="x" href="<?= e($xlsUrl) ?>">Download Excel</a>
  <a class="b" href="<?= url($boardPage) ?>">Back</a>
</div>

<div class="sheet">
  <div class="banner">
    <div class="logo">
      <?php if ($logo): ?><img src="<?= e($logo) ?>" alt="">
      <?php else: ?><span><?= e(initials($college['name'] ?? APP_NAME)) ?></span><?php endif; ?>
    </div>
    <div>
      <h1><?= e($college['name'] ?? APP_NAME) ?></h1>
      <?php if ($place !== ''): ?><div class="place"><?= e($place) ?></div><?php endif; ?>
    </div>
    <div class="doc">
      <b><?= e($title) ?></b>
      <span><?= e($scopeLabel) ?></span>
    </div>
  </div>

  <div class="body">
    <h2>Summary</h2>
    <div class="grid">
      <?php foreach ($summary as $label => $value):
          $tone = in_array($label, ['Present'], true) ? 'g' : (in_array($label, ['Absent', 'Below 75%'], true) ? 'r' : ''); ?>
        <div class="box <?= $tone ?>"><div class="v"><?= e($value) ?><?= substr($label, -2) === ' %' ? '%' : '' ?></div>
          <div class="l"><?= e(str_replace(' %', '', $label)) ?></div></div>
      <?php endforeach; ?>
    </div>

<?php if ($period === 'day'): ?>
    <h2>Student-wise Attendance</h2>
    <table>
      <thead><tr><th>#</th><th>Enrolment</th><th class="l">Student Name</th><th>Branch</th><th>Class</th>
        <th>Status</th><th>Check-in</th><th>Distance</th></tr></thead>
      <tbody>
        <?php foreach ($rows as $i => $s): $here = $s['marked_at'] !== null; ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td><?= e($s['enrollment_no'] ?: '—') ?></td>
            <td class="l"><strong><?= e($s['name']) ?></strong></td>
            <td><?= e($s['branch_code'] ?? '—') ?></td>
            <td><?= e(attendance_class_label($s)) ?></td>
            <td><span class="pill <?= $here ? 'PRESENT' : 'ABSENT' ?>"><?= $here ? 'PRESENT' : 'ABSENT' ?></span></td>
            <td><?= $here ? e(date('h:i A', strtotime($s['marked_at']))) : '—' ?></td>
            <td><?= $here ? e(num((float)$s['distance_m'])) . ' m' : '—' ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="8">No students in this selection.</td></tr><?php endif; ?>
      </tbody>
    </table>
<?php else: ?>
    <h2>Day-wise Register</h2>
    <table class="month">
      <thead>
        <tr><th>#</th><th class="l">Student</th><th>Br.</th>
          <?php for ($d = 1; $d <= $nDays; $d++): ?><th class="<?= $isSun($d) ? 'S' : '' ?>"><?= $d ?></th><?php endfor; ?>
          <th>P</th><th>%</th></tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $i => $s): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td class="l"><strong><?= e($s['name']) ?></strong><?php if ($s['enrollment_no']): ?>
              <span style="color:#94a3b8"> · <?= e($s['enrollment_no']) ?></span><?php endif; ?></td>
            <td><?= e($s['branch_code'] ?? '—') ?></td>
            <?php for ($d = 1; $d <= $nDays; $d++):
                if (isset($s['_days'][$d]))  { $cls = 'P'; $txt = 'P'; }
                elseif ($isOff($d))          { $cls = '';  $txt = ''; }
                elseif ($isSun($d))          { $cls = 'S'; $txt = 'S'; }
                else                         { $cls = 'A'; $txt = 'A'; } ?>
              <td class="<?= $cls ?>"><?= $txt ?></td>
            <?php endfor; ?>
            <td><strong><?= $s['_present'] ?></strong></td>
            <td class="<?= $s['_pct'] >= 75 ? 'ok' : 'low' ?>"><?= $s['_pct'] ?>%</td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="<?= $nDays + 5 ?>">No students in this selection.</td></tr><?php endif; ?>
      </tbody>
      <?php if ($rows): ?>
        <tfoot><tr><td colspan="3" class="l">Present per day</td>
          <?php for ($d = 1; $d <= $nDays; $d++): ?><td><?= $isOff($d) ? '' : $dayTotals[$d] ?></td><?php endfor; ?>
          <td colspan="2"></td></tr></tfoot>
      <?php endif; ?>
    </table>
    <div class="foot" style="border:0;margin-top:6px">P = Present · A = Absent · S = Sunday ·
      % is counted over <?= count($counted) ?> working day(s) up to today · below 75% shown in red.</div>
<?php endif; ?>

    <div class="signs">
      <div><?= $attFaculty !== null ? 'Faculty: ' . e($attFaculty['name']) : 'Class Coordinator' ?></div>
      <div>Head of Department</div>
      <div>Principal</div>
    </div>

    <div class="foot">
      Attendance is marked by students from within <?= ATTENDANCE_RADIUS_M ?> m of the campus point.
      This is a computer generated statement. Generated on <?= e(date('d M Y, h:i A')) ?> by <?= e(APP_NAME) ?>.
    </div>
  </div>
</div>

<script>
  window.addEventListener('load', function () { window.setTimeout(window.print, 500); });
</script>
</body>
</html>
