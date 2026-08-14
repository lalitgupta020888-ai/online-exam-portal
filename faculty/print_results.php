<?php
/**
 * Faculty - printable result sheet.
 *
 *   print_results.php?exam_id=N     -> every student's result for one exam
 *   print_results.php?student_id=N  -> one student's complete record
 *
 * Opens the browser print dialog, from which "Save as PDF" produces the file.
 * No PDF library is needed, so this works on a stock XAMPP install.
 */
require_once __DIR__ . '/../includes/auth.php';
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

    $n      = count($rows);
    $marks  = array_map(fn($r) => (float)$r['obtained_marks'], $rows);
    $passed = count(array_filter($rows, fn($r) => $r['status'] === 'PASS'));
    $avg    = $n ? array_sum($marks) / $n : 0;
    $high   = $n ? max($marks) : 0;
    $low    = $n ? min($marks) : 0;
    $title  = $exam['title'];
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
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Results - <?= e($title) ?></title>
<style>
  * { box-sizing: border-box; }
  body { font-family: "Segoe UI", Arial, sans-serif; color: #0f172a;
         max-width: 1000px; margin: 0 auto; padding: 28px 22px; font-size: 13px; }
  .head { text-align: center; border-bottom: 3px solid #2563eb; padding-bottom: 14px;
          margin-bottom: 18px; }
  .head h1 { margin: 0 0 3px; font-size: 22px; color: #2563eb; }
  .head p { margin: 0; color: #64748b; font-size: 12px; }
  h2 { font-size: 15px; margin: 20px 0 8px; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
  th, td { border: 1px solid #e2e8f0; padding: 6px 8px; text-align: left; }
  thead th { background: #f1f5f9; font-size: 11px; text-transform: uppercase;
             letter-spacing: .03em; color: #475569; }
  tbody tr:nth-child(even) { background: #f8fafc; }
  .meta td:first-child { background: #f8fafc; font-weight: 600; color: #475569; width: 30%; }
  .grid { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; }
  .box { flex: 1 1 15%; border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px;
         text-align: center; }
  .box .v { font-size: 18px; font-weight: 800; }
  .box .l { font-size: 10px; color: #64748b; text-transform: uppercase; }
  .pass { color: #16a34a; font-weight: 700; }
  .fail { color: #dc2626; font-weight: 700; }
  .prov { color: #b45309; font-size: 10px; }
  .foot { margin-top: 24px; border-top: 1px solid #e2e8f0; padding-top: 10px;
          font-size: 10px; color: #94a3b8; text-align: center; }
  .actions { text-align: center; margin-bottom: 18px; }
  .actions button, .actions a { padding: 8px 16px; border-radius: 8px; border: 0;
          background: #2563eb; color: #fff; font-weight: 600; cursor: pointer;
          text-decoration: none; display: inline-block; margin: 0 4px; font-size: 13px; }
  .actions a { background: #64748b; }
  @media print { .actions { display: none; } body { padding: 0; } }
</style>
</head>
<body>

<div class="actions">
  <button onclick="window.print()">Print / Save as PDF</button>
  <a href="<?= $mode === 'exam'
              ? url('faculty/results.php?exam_id=' . $examId)
              : url('faculty/student_analysis.php?student_id=' . $studentId) ?>">Back</a>
</div>

<div class="head">
  <h1><?= e(APP_NAME) ?></h1>
  <p><?= $mode === 'exam' ? 'Consolidated Examination Result Sheet'
                          : 'Student Performance Record' ?></p>
</div>

<?php if ($mode === 'exam'): ?>
  <table class="meta">
    <tr><td>Examination</td><td><?= e($exam['title']) ?></td></tr>
    <tr><td>Subject</td><td><?= e($exam['subject']) ?></td></tr>
    <tr><td>Faculty</td><td><?= e($faculty['name']) ?>
        <?= $faculty['department'] ? ' (' . e($faculty['department']) . ')' : '' ?></td></tr>
    <tr><td>Total Marks / Passing Marks</td>
        <td><?= num((float)$exam['total_marks']) ?> / <?= num((float)$exam['passing_marks']) ?></td></tr>
    <tr><td>Questions</td>
        <td><?= (int)$exam['question_count'] ?>
            (<?= (int)$exam['question_count'] - (int)$exam['subjective_count'] ?> objective,
             <?= (int)$exam['subjective_count'] ?> written)</td></tr>
    <tr><td>Students Assigned / Completed</td>
        <td><?= $progress['assigned'] ?> / <?= $progress['completed'] ?></td></tr>
    <tr><td>Generated</td><td><?= e(date('d M Y, h:i A')) ?></td></tr>
  </table>

  <h2>Class Summary</h2>
  <div class="grid">
    <div class="box"><div class="v"><?= $n ?></div><div class="l">Papers</div></div>
    <div class="box"><div class="v"><?= $passed ?></div><div class="l">Passed</div></div>
    <div class="box"><div class="v"><?= $n - $passed ?></div><div class="l">Failed</div></div>
    <div class="box"><div class="v"><?= $n ? round($passed / $n * 100) : 0 ?>%</div>
        <div class="l">Pass Rate</div></div>
    <div class="box"><div class="v"><?= num((float)$avg) ?></div><div class="l">Average</div></div>
    <div class="box"><div class="v"><?= num((float)$high) ?></div><div class="l">Highest</div></div>
    <div class="box"><div class="v"><?= num((float)$low) ?></div><div class="l">Lowest</div></div>
  </div>

  <h2>Result Sheet</h2>
  <table>
    <thead>
      <tr>
        <th>Rank</th><th>Student</th><th>Email</th><th>Submitted</th>
        <th>Correct</th><th>Wrong</th><th>Skipped</th>
        <?php if ((float)$exam['subjective_total'] > 0): ?>
          <th>Objective</th><th>Written</th>
        <?php endif; ?>
        <th>Marks</th><th>%</th><th>Result</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $i => $r): ?>
        <tr>
          <td><?= $i + 1 ?></td>
          <td><?= e($r['student_name']) ?></td>
          <td><?= e($r['email']) ?></td>
          <td><?= e(format_datetime($r['submitted_at'])) ?></td>
          <td><?= (int)$r['correct_count'] ?></td>
          <td><?= (int)$r['wrong_count'] ?></td>
          <td><?= (int)$r['unanswered_count'] ?></td>
          <?php if ((float)$exam['subjective_total'] > 0): ?>
            <td><?= num((float)$r['objective_marks']) ?></td>
            <td><?= (int)$r['pending_evaluation'] ? 'pending'
                     : num((float)$r['subjective_marks']) . ' / '
                       . num((float)$r['subjective_total']) ?></td>
          <?php endif; ?>
          <td><?= num((float)$r['obtained_marks']) ?> / <?= num((float)$r['total_marks']) ?>
              <?php if ((int)$r['pending_evaluation']): ?>
                <div class="prov">provisional</div>
              <?php endif; ?></td>
          <td><?= num((float)$r['percentage']) ?>%</td>
          <td class="<?= $r['status'] === 'PASS' ? 'pass' : 'fail' ?>"><?= e($r['status']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?>
        <tr><td colspan="12" style="text-align:center">No papers submitted.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>

<?php else: ?>
  <table class="meta">
    <tr><td>Student Name</td><td><?= e($student['name']) ?></td></tr>
    <tr><td>Email</td><td><?= e($student['email']) ?></td></tr>
    <?php if ($student['phone']): ?>
      <tr><td>Phone</td><td><?= e($student['phone']) ?></td></tr>
    <?php endif; ?>
    <tr><td>Registered On</td><td><?= e(format_datetime($student['created_at'])) ?></td></tr>
    <tr><td>Prepared By</td><td><?= e($faculty['name']) ?></td></tr>
    <tr><td>Generated</td><td><?= e(date('d M Y, h:i A')) ?></td></tr>
  </table>

  <h2>Overall Performance</h2>
  <div class="grid">
    <div class="box"><div class="v"><?= $n ?></div><div class="l">Exams Taken</div></div>
    <div class="box"><div class="v"><?= $passed ?></div><div class="l">Passed</div></div>
    <div class="box"><div class="v"><?= $n - $passed ?></div><div class="l">Failed</div></div>
    <div class="box"><div class="v"><?= num((float)$avg) ?>%</div><div class="l">Average</div></div>
    <div class="box"><div class="v"><?= num((float)$high) ?>%</div><div class="l">Best</div></div>
    <div class="box"><div class="v"><?= num((float)$low) ?>%</div><div class="l">Lowest</div></div>
  </div>

  <h2>Examination History</h2>
  <table>
    <thead>
      <tr>
        <th>#</th><th>Exam</th><th>Subject</th><th>Date</th><th>Time</th>
        <th>Correct</th><th>Wrong</th><th>Skipped</th><th>Marks</th><th>%</th><th>Result</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $i => $r):
          $secs = strtotime($r['submitted_at']) - strtotime($r['started_at']); ?>
        <tr>
          <td><?= $i + 1 ?></td>
          <td><?= e($r['title']) ?></td>
          <td><?= e($r['subject']) ?></td>
          <td><?= e(format_datetime($r['submitted_at'])) ?></td>
          <td><?= format_seconds(max(0, (int)$secs)) ?></td>
          <td><?= (int)$r['correct_count'] ?></td>
          <td><?= (int)$r['wrong_count'] ?></td>
          <td><?= (int)$r['unanswered_count'] ?></td>
          <td><?= num((float)$r['obtained_marks']) ?> / <?= num((float)$r['total_marks']) ?></td>
          <td><?= num((float)$r['percentage']) ?>%</td>
          <td class="<?= $r['status'] === 'PASS' ? 'pass' : 'fail' ?>"><?= e($r['status']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?>
        <tr><td colspan="11" style="text-align:center">No exams completed.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
<?php endif; ?>

<div class="foot">
  This is a computer generated statement and does not require a signature.<br>
  Generated on <?= e(date('d M Y, h:i A')) ?> by <?= e(APP_NAME) ?>.
</div>

<script>
  window.addEventListener('load', function () { window.setTimeout(window.print, 400); });
</script>
</body>
</html>
