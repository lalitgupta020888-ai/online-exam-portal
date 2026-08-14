<?php
/**
 * Printable / downloadable result sheet.
 *
 * Opens a self contained page and triggers the browser print dialog, from
 * which the student can choose "Save as PDF". This keeps the project free
 * of external PDF libraries so it runs on a stock XAMPP installation.
 */
require_once __DIR__ . '/includes/auth.php';

$student   = require_student();
$attemptId = get_int('attempt');
$attempt   = get_owned_attempt($attemptId, (int)$student['id']);

if (!$attempt || $attempt['status'] === 'in_progress') {
    set_flash('danger', 'Result not available for download.');
    redirect('results.php');
}

$st = db()->prepare('SELECT * FROM results WHERE attempt_id = ?');
$st->execute([$attemptId]);
$result = $st->fetch();
if (!$result) {
    set_flash('danger', 'Result not found.');
    redirect('results.php');
}

$exam   = get_exam((int)$attempt['exam_id']);
$passed = $result['status'] === 'PASS';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Result - <?= e($exam['title']) ?> - <?= e($student['name']) ?></title>
<style>
  * { box-sizing: border-box; }
  body { font-family: "Segoe UI", Arial, sans-serif; color: #0f172a;
         max-width: 800px; margin: 0 auto; padding: 32px 24px; }
  .head { text-align: center; border-bottom: 3px solid #2563eb; padding-bottom: 16px;
          margin-bottom: 24px; }
  .head h1 { margin: 0 0 4px; font-size: 24px; color: #2563eb; }
  .head p { margin: 0; color: #64748b; font-size: 13px; }
  h2 { font-size: 16px; margin: 24px 0 10px; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 18px; font-size: 14px; }
  th, td { border: 1px solid #e2e8f0; padding: 9px 12px; text-align: left; }
  th { background: #f8fafc; width: 45%; font-weight: 600; color: #475569; }
  .verdict { text-align: center; padding: 18px; border-radius: 10px; margin: 22px 0;
             color: #fff; background: <?= $passed ? '#16a34a' : '#dc2626' ?>; }
  .verdict .big { font-size: 30px; font-weight: 800; letter-spacing: .08em; }
  .grid { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 18px; }
  .box { flex: 1 1 22%; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px;
         text-align: center; }
  .box .v { font-size: 20px; font-weight: 800; }
  .box .l { font-size: 11px; color: #64748b; text-transform: uppercase; }
  .foot { margin-top: 30px; border-top: 1px solid #e2e8f0; padding-top: 12px;
          font-size: 11px; color: #94a3b8; text-align: center; }
  .actions { text-align: center; margin-bottom: 20px; }
  .actions button, .actions a { padding: 9px 18px; border-radius: 8px; border: 0;
          background: #2563eb; color: #fff; font-weight: 600; cursor: pointer;
          text-decoration: none; display: inline-block; margin: 0 4px; font-size: 14px; }
  .actions a { background: #64748b; }
  @media print { .actions { display: none; } body { padding: 0; } }
</style>
</head>
<body>

<div class="actions">
  <button onclick="window.print()">Print / Save as PDF</button>
  <a href="<?= url('result.php?attempt=' . $attemptId) ?>">Back to Result</a>
</div>

<div class="head">
  <h1><?= e(APP_NAME) ?></h1>
  <p>Statement of Examination Result</p>
</div>

<h2>Candidate &amp; Examination</h2>
<table>
  <tr><th>Student Name</th><td><?= e($student['name']) ?></td></tr>
  <tr><th>Email</th><td><?= e($student['email']) ?></td></tr>
  <tr><th>Examination</th><td><?= e($exam['title']) ?></td></tr>
  <tr><th>Subject</th><td><?= e($exam['subject']) ?></td></tr>
  <tr><th>Date &amp; Time</th><td><?= e(format_datetime($attempt['submitted_at'])) ?></td></tr>
  <tr><th>Attempt ID</th><td>#<?= (int)$attemptId ?></td></tr>
</table>

<h2>Performance</h2>
<div class="grid">
  <div class="box"><div class="v"><?= (int)$result['total_questions'] ?></div><div class="l">Questions</div></div>
  <div class="box"><div class="v"><?= (int)$result['correct_count'] ?></div><div class="l">Correct</div></div>
  <div class="box"><div class="v"><?= (int)$result['wrong_count'] ?></div><div class="l">Incorrect</div></div>
  <div class="box"><div class="v"><?= (int)$result['unanswered_count'] ?></div><div class="l">Unanswered</div></div>
</div>

<table>
  <tr><th>Total Questions</th><td><?= (int)$result['total_questions'] ?></td></tr>
  <tr><th>Attempted Questions</th><td><?= (int)$result['attempted'] ?></td></tr>
  <tr><th>Total Marks</th><td><?= num((float)$result['total_marks']) ?></td></tr>
  <tr><th>Obtained Marks</th><td><?= num((float)$result['obtained_marks']) ?></td></tr>
  <tr><th>Passing Marks</th><td><?= num((float)$exam['passing_marks']) ?></td></tr>
  <tr><th>Negative Marking</th>
      <td><?= (float)$exam['negative_marks'] > 0
              ? '-' . num((float)$exam['negative_marks']) . ' per wrong answer' : 'Not applicable' ?></td></tr>
  <tr><th>Percentage</th><td><?= num((float)$result['percentage']) ?>%</td></tr>
</table>

<div class="verdict">
  <div class="big"><?= e($result['status']) ?></div>
  <div><?= num((float)$result['obtained_marks']) ?> out of <?= num((float)$result['total_marks']) ?>
       marks (<?= num((float)$result['percentage']) ?>%)</div>
</div>

<div class="foot">
  This is a computer generated statement and does not require a signature.<br>
  Generated on <?= e(date('d M Y, h:i A')) ?> by <?= e(APP_NAME) ?>.
</div>

<script>
  // Open the print dialog automatically the first time the page loads.
  window.addEventListener('load', function () { window.setTimeout(window.print, 400); });
</script>
</body>
</html>
