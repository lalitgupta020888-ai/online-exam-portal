<?php
/**
 * Faculty - download the consolidated result sheet of one exam as Excel (.xlsx).
 * Same students, columns and numbers as the printable PDF sheet.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/result_sheet.php';
$faculty = require_faculty();

$examId = get_int('exam_id');
$exam   = get_owned_exam($examId, (int)$faculty['id']);
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
$columns = $sheet['columns'];
$last    = xlsx_col(count($columns) - 1);

$cells = []; $merges = []; $heights = [];
$r = 1;

// College banner
$cells[$r][0] = [$college['name'] ?? APP_NAME, 1];
$merges[] = "A$r:$last$r"; $heights[$r] = 34; $r++;
$place = trim(implode(', ', array_filter([$college['address'] ?? '', $college['city'] ?? '',
                                          $college['state'] ?? ''])));
if ($place !== '') {
    $cells[$r][0] = [$place, 15];
    $merges[] = "A$r:$last$r"; $r++;
}
$cells[$r][0] = ['CONSOLIDATED EXAMINATION RESULT SHEET', 2];
$merges[] = "A$r:$last$r"; $heights[$r] = 24; $r += 2;

// Paper details, two label/value pairs per row
$details = array_chunk(exam_sheet_details($exam, $faculty, $progress), 2, true);
$half = intdiv(count($columns), 2);
foreach ($details as $pair) {
    $c = 0;
    foreach ($pair as $label => $value) {
        $cells[$r][$c]     = [$label, 8];
        $cells[$r][$c + 1] = ['', 8];
        $merges[] = xlsx_col($c) . "$r:" . xlsx_col($c + 1) . $r;
        $end = $c === 0 ? $half - 1 : count($columns) - 1;
        for ($k = $c + 2; $k <= $end; $k++) { $cells[$r][$k] = ['', 4]; }
        $cells[$r][$c + 2] = [$value, 4];
        $merges[] = xlsx_col($c + 2) . "$r:" . xlsx_col($end) . $r;
        $c = $half;
    }
    $r++;
}
$r++;

// Class summary
$cells[$r][0] = ['Class Summary', 10]; $r++;
$c = 0;
foreach ($sheet['stats'] as $label => $value) {
    $cells[$r][$c]     = [$label, 12];
    $cells[$r + 1][$c] = [is_numeric($value) ? $value + 0 : $value, 11];
    $c++;
}
$heights[$r + 1] = 24;
$r += 3;

// Result table
$cells[$r][0] = ['Student-wise Result', 10]; $r++;
foreach ($columns as $i => [$key, $label]) {
    $cells[$r][$i] = [$label, 3];
}
$heights[$r] = 32; $r++;

foreach ($sheet['rows'] as $row) {
    foreach ($columns as $i => [$key, , $numeric]) {
        $v = $row[$key];
        if ($key === 'result') {
            $style = ['PASS' => 6, 'FAIL' => 7, 'ABSENT' => 13][$row['_state']];
        } elseif ($key === 'attend' && $row['_state'] === 'ABSENT') {
            $style = 13;
        } elseif ($key === 'name') {
            $style = 16;
        } elseif (in_array($key, ['objective', 'written', 'marks', 'outof', 'pct'], true) && is_numeric($v)) {
            $style = 14;
        } else {
            $style = $numeric || in_array($key, ['grade', 'attend', 'class', 'branch', 'time'], true) ? 5 : 4;
        }
        $cells[$r][$i] = [is_numeric($v) && $numeric ? $v + 0 : $v, $style];
    }
    $r++;
}

$r++;
if (array_filter($sheet['rows'], fn($x) => $x['_provisional'])) {
    $cells[$r][0] = ['Written answers of some students are still being evaluated - their marks are provisional.', 9];
    $r++;
}
$cells[$r][0] = ['This is a computer generated statement and does not require a signature. Generated on '
                 . date('d M Y, h:i A') . ' by ' . APP_NAME . '.', 9];

$widths = [];
foreach ($columns as [$key]) {
    $widths[] = ['rank' => 7, 'roll' => 18, 'name' => 26, 'branch' => 10, 'class' => 15, 'email' => 28,
                 'phone' => 14, 'attend' => 12, 'submitted' => 20, 'time' => 11, 'grade' => 8,
                 'result' => 10][$key] ?? 11;
}

$file = preg_replace('/[^A-Za-z0-9_-]+/', '_', $exam['title']) . '_Result.xlsx';
$data = xlsx_build('Result', $cells, $merges, $widths, 0, $heights);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $file . '"');
header('Content-Length: ' . strlen($data));
header('Cache-Control: private, max-age=0');
echo $data;
