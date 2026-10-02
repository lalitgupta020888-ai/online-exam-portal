<?php
/**
 * Faculty - printable result sheet.
 *
 *   print_results.php?exam_id=N     -> every allotted student's result for one exam
 *   print_results.php?student_id=N  -> one student's complete record
 *
 * Opens the browser print dialog, from which "Save as PDF" produces the file.
 * No PDF library is needed, so this works on a stock XAMPP install.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/result_sheet.php';
$faculty = require_faculty();

$examId    = get_int('exam_id');
$studentId = get_int('student_id');

$mode = $examId ? 'exam' : ($studentId ? 'student' : '');
if (!$mode) {
    set_flash('danger', 'Choose an exam or a student to print.');
    redirect('faculty/results.php');
}

if ($mode === 'exam') {
    $exam = get_owned_exam($examId, (int)$faculty['id']);
    if (!$exam) {
        set_flash('danger', 'That exam does not belong to you.');
        redirect('faculty/results.php');
    }
    $progress = exam_progress($exam);
    if (!$progress['results_visible']) {
        set_flash('warning', 'Results unlock once all assigned students have completed the exam.');
        redirect('faculty/results.php?exam_id=' . $examId);
    }

    $sheet   = exam_result_sheet($exam, $faculty);
    $college = $sheet['college'];
    $details = exam_sheet_details($exam, $faculty, $progress);
    $columns = array_values(array_filter($sheet['columns'], fn($c) => $c[3]));
    $title   = $exam['title'];
} else {
    $st = db()->prepare('SELECT * FROM students WHERE id = ?');
    $st->execute([$studentId]);
    $student = $st->fetch();
    if (!$student) {
        set_flash('danger', 'Student not found.');
        redirect('faculty/students.php');
    }

    $st = db()->prepare(
        'SELECT r.*, e.title, e.subject, a.submitted_at, a.started_at,
                a.status AS attempt_status
           FROM results r
           JOIN exams e    ON e.id = r.exam_id
           JOIN attempts a ON a.id = r.attempt_id
          WHERE r.student_id = ?
       ORDER BY a.submitted_at ASC'
    );
    $st->execute([$studentId]);
    $rows = $st->fetchAll();

    $n      = count($rows);
    $pcts   = array_map(fn($r) => (float)$r['percentage'], $rows);
    $passed = count(array_filter($rows, fn($r) => $r['status'] === 'PASS'));
    $avg    = $n ? array_sum($pcts) / $n : 0;
    $high   = $n ? max($pcts) : 0;
    $low    = $n ? min($pcts) : 0;
    $title  = $student['name'];
    $college = get_college((int)($student['college_id'] ?: $faculty['college_id']));
}
$logo  = college_logo_url($college);
$place = trim(implode(', ', array_filter([$college['address'] ?? '', $college['city'] ?? '',
                                          $college['state'] ?? ''])));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> - Result</title>
<style>
  * { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  @page { size: A4 landscape; margin: 10mm; }
  body { font-family: "Segoe UI", Arial, sans-serif; color: #0f172a; background: #e2e8f0;
         margin: 0; padding: 24px 12px; font-size: 12px; }
  .sheet { max-width: 1120px; margin: 0 auto; background: #fff; border-radius: 14px;
           overflow: hidden; box-shadow: 0 10px 30px rgba(15,23,42,.15); }
  .banner { background: linear-gradient(120deg, #1e3a8a, #2563eb 60%, #4f46e5); color: #fff;
            padding: 20px 26px; display: flex; align-items: center; gap: 18px; }
  .logo { width: 70px; height: 70px; border-radius: 50%; background: #fff; flex: none;
          display: flex; align-items: center; justify-content: center; overflow: hidden;
          border: 3px solid rgba(255,255,255,.6); }
  .logo img { width: 100%; height: 100%; object-fit: contain; }
  .logo span { color: #1e3a8a; font-weight: 800; font-size: 24px; }
  .banner h1 { margin: 0; font-size: 24px; letter-spacing: .02em; }
  .banner .place { opacity: .85; font-size: 12px; margin-top: 2px; }
  .banner .doc { margin-left: auto; text-align: right; }
  .banner .doc b { display: block; font-size: 15px; letter-spacing: .08em; text-transform: uppercase; }
  .banner .doc span { font-size: 11px; opacity: .85; }
  .body { padding: 20px 26px 24px; }
  h2 { font-size: 13px; margin: 18px 0 8px; color: #1e3a8a; text-transform: uppercase;
       letter-spacing: .06em; border-left: 4px solid #2563eb; padding-left: 8px; }
  .details { display: grid; grid-template-columns: repeat(3, 1fr); border: 1px solid #e2e8f0;
             border-radius: 10px; overflow: hidden; }
  .details div { padding: 7px 12px; border-bottom: 1px solid #e2e8f0; border-right: 1px solid #e2e8f0; }
  .details small { display: block; color: #64748b; font-size: 10px; text-transform: uppercase;
                   letter-spacing: .04em; }
  .details strong { font-size: 12.5px; }
  .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(95px, 1fr)); gap: 8px; }
  .box { border-radius: 10px; padding: 9px 6px; text-align: center; background: #eff6ff;
         border: 1px solid #dbeafe; }
  .box .v { font-size: 19px; font-weight: 800; color: #1e3a8a; }
  .box .l { font-size: 9.5px; color: #64748b; text-transform: uppercase; letter-spacing: .04em; }
  .box.g { background: #f0fdf4; border-color: #bbf7d0; } .box.g .v { color: #15803d; }
  .box.r { background: #fef2f2; border-color: #fecaca; } .box.r .v { color: #b91c1c; }
  .box.a { background: #fffbeb; border-color: #fde68a; } .box.a .v { color: #b45309; }
  table { width: 100%; border-collapse: collapse; }
  th, td { border: 1px solid #e2e8f0; padding: 5px 6px; text-align: center; }
  td.l, th.l { text-align: left; }
  thead th { background: #1e3a8a; color: #fff; font-size: 9.5px; text-transform: uppercase;
             letter-spacing: .03em; }
  tbody tr:nth-child(even) { background: #f8fafc; }
  tr.absent td { color: #94a3b8; }
  thead { display: table-header-group; }
  tr { page-break-inside: avoid; }
  .pill { display: inline-block; padding: 2px 9px; border-radius: 99px; font-weight: 700; font-size: 10.5px; }
  .PASS { background: #dcfce7; color: #15803d; }
  .FAIL { background: #fee2e2; color: #b91c1c; }
  .ABSENT { background: #f1f5f9; color: #64748b; }
  .prov { color: #b45309; font-size: 9px; }
  .signs { display: flex; justify-content: space-between; margin-top: 46px; padding: 0 10px; }
  .signs div { width: 200px; border-top: 1px solid #334155; text-align: center; padding-top: 4px;
               font-size: 11px; color: #334155; }
  .foot { margin-top: 18px; border-top: 1px dashed #cbd5e1; padding-top: 8px; font-size: 10px;
          color: #94a3b8; text-align: center; }
  .actions { text-align: center; margin-bottom: 16px; }
  .actions button, .actions a { padding: 9px 18px; border-radius: 8px; border: 0;
          background: #2563eb; color: #fff; font-weight: 600; cursor: pointer;
          text-decoration: none; display: inline-block; margin: 0 4px; font-size: 13px; }
  .actions a.x { background: #16a34a; }
  .actions a.b { background: #64748b; }
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
  <?php if ($mode === 'exam'): ?>
    <a class="x" href="<?= url('faculty/export_results.php?exam_id=' . $examId) ?>">Download Excel</a>
  <?php endif; ?>
  <a class="b" href="<?= $mode === 'exam'
              ? url('faculty/results.php?exam_id=' . $examId)
              : url('faculty/student_analysis.php?student_id=' . $studentId) ?>">Back</a>
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
      <b><?= $mode === 'exam' ? 'Result Sheet' : 'Performance Record' ?></b>
      <span><?= $mode === 'exam' ? 'Consolidated Examination Result' : 'Student Examination History' ?></span>
    </div>
  </div>

  <div class="body">
<?php if ($mode === 'exam'): ?>
    <h2>Examination Details</h2>
    <div class="details">
      <?php foreach ($details as $label => $value): ?>
        <div><small><?= e($label) ?></small><strong><?= e($value) ?></strong></div>
      <?php endforeach; ?>
    </div>

    <h2>Class Summary</h2>
    <div class="grid">
      <?php foreach ($sheet['stats'] as $label => $value):
          $tone = ['Passed' => 'g', 'Failed' => 'r', 'Absent' => 'a'][$label] ?? ''; ?>
        <div class="box <?= $tone ?>"><div class="v"><?= e($value) ?></div><div class="l"><?= e($label) ?></div></div>
      <?php endforeach; ?>
    </div>

    <h2>Student-wise Result</h2>
    <table>
      <thead>
        <tr>
          <?php foreach ($columns as [$key, $label]): ?>
            <th class="<?= $key === 'name' ? 'l' : '' ?>"><?= e($label) ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($sheet['rows'] as $row): ?>
          <tr class="<?= $row['_state'] === 'ABSENT' ? 'absent' : '' ?>">
            <?php foreach ($columns as [$key]): $v = $row[$key]; ?>
              <?php if ($key === 'result'): ?>
                <td><span class="pill <?= $row['_state'] ?>"><?= e($v) ?></span></td>
              <?php elseif ($key === 'name'): ?>
                <td class="l"><strong><?= e($v) ?></strong></td>
              <?php elseif ($key === 'marks'): ?>
                <td><strong><?= e(is_numeric($v) ? num((float)$v) : $v) ?></strong>
                  <?php if ($row['_provisional']): ?><div class="prov">provisional</div><?php endif; ?></td>
              <?php elseif ($key === 'pct'): ?>
                <td><?= e(is_numeric($v) ? num((float)$v) . '%' : $v) ?></td>
              <?php else: ?>
                <td><?= e(is_float($v) ? num($v) : (string)$v) ?></td>
              <?php endif; ?>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        <?php if (!$sheet['rows']): ?>
          <tr><td colspan="<?= count($columns) ?>">No students allotted to this paper.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>

    <div class="signs">
      <div>Faculty: <?= e($faculty['name']) ?></div>
      <div>Head of Department</div>
      <div>Principal / Controller of Exams</div>
    </div>

<?php else: ?>
    <h2>Student Details</h2>
    <div class="details">
      <div><small>Student Name</small><strong><?= e($student['name']) ?></strong></div>
      <div><small>Roll / Enrolment No.</small><strong><?= e($student['enrollment_no'] ?: '-') ?></strong></div>
      <div><small>Class</small><strong><?= e(student_class_label($student) ?: '-') ?></strong></div>
      <div><small>Email</small><strong><?= e($student['email']) ?></strong></div>
      <div><small>Phone</small><strong><?= e($student['phone'] ?: '-') ?></strong></div>
      <div><small>Prepared By</small><strong><?= e($faculty['name']) ?></strong></div>
    </div>

    <h2>Overall Performance</h2>
    <div class="grid">
      <div class="box"><div class="v"><?= $n ?></div><div class="l">Exams Taken</div></div>
      <div class="box g"><div class="v"><?= $passed ?></div><div class="l">Passed</div></div>
      <div class="box r"><div class="v"><?= $n - $passed ?></div><div class="l">Failed</div></div>
      <div class="box"><div class="v"><?= num((float)$avg) ?>%</div><div class="l">Average</div></div>
      <div class="box"><div class="v"><?= num((float)$high) ?>%</div><div class="l">Best</div></div>
      <div class="box"><div class="v"><?= num((float)$low) ?>%</div><div class="l">Lowest</div></div>
    </div>

    <h2>Examination History</h2>
    <table>
      <thead>
        <tr>
          <th>#</th><th class="l">Exam</th><th>Subject</th><th>Date</th><th>Time</th>
          <th>Correct</th><th>Wrong</th><th>Skipped</th><th>Marks</th><th>%</th><th>Grade</th><th>Result</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $i => $r):
            $secs = strtotime($r['submitted_at']) - strtotime($r['started_at']); ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td class="l"><strong><?= e($r['title']) ?></strong></td>
            <td><?= e($r['subject']) ?></td>
            <td><?= e(format_datetime($r['submitted_at'])) ?></td>
            <td><?= format_seconds(max(0, (int)$secs)) ?></td>
            <td><?= (int)$r['correct_count'] ?></td>
            <td><?= (int)$r['wrong_count'] ?></td>
            <td><?= (int)$r['unanswered_count'] ?></td>
            <td><strong><?= num((float)$r['obtained_marks']) ?></strong> / <?= num((float)$r['total_marks']) ?></td>
            <td><?= num((float)$r['percentage']) ?>%</td>
            <td><?= e(result_grade((float)$r['percentage'], $r['status'])) ?></td>
            <td><span class="pill <?= $r['status'] === 'PASS' ? 'PASS' : 'FAIL' ?>"><?= e($r['status']) ?></span></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
          <tr><td colspan="12">No exams completed.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
<?php endif; ?>

    <div class="foot">
      Grades: A+ &ge; 90% &middot; A &ge; 80% &middot; B+ &ge; 70% &middot; B &ge; 60% &middot;
      C &ge; 50% &middot; D &ge; 40% &middot; E &lt; 40% (passed) &middot; F = Fail.<br>
      This is a computer generated statement and does not require a signature.
      Generated on <?= e(date('d M Y, h:i A')) ?> by <?= e(APP_NAME) ?>.
    </div>
  </div>
</div>

<script>
  window.addEventListener('load', function () { window.setTimeout(window.print, 500); });
</script>
</body>
</html>
