<?php
/**
 * Consolidated result sheet of one exam, shared by the printable PDF page
 * (faculty/print_results.php) and the Excel download (faculty/export_results.php),
 * so both always show exactly the same students, columns and numbers.
 *
 * Every student the paper was allotted to is listed - those who never sat it
 * appear as ABSENT, so the sheet doubles as the class list.
 */

/** Letter grade for a percentage; a failed paper is always F. */
function result_grade(float $pct, string $status): string
{
    if ($status !== 'PASS') {
        return 'F';
    }
    foreach ([90 => 'A+', 80 => 'A', 70 => 'B+', 60 => 'B', 50 => 'C', 40 => 'D'] as $min => $g) {
        if ($pct >= $min) {
            return $g;
        }
    }
    return 'E';
}

/**
 * @return array{columns: array, rows: array, stats: array, college: ?array}
 *   columns: [key, label, numeric, show in pdf]
 *   rows:    one array per student keyed by column key, plus '_state'
 *            (PASS | FAIL | ABSENT) and '_provisional'
 */
function exam_result_sheet(array $exam, array $faculty): array
{
    $examId = (int)$exam['id'];
    $st = db()->prepare(
        'SELECT s.id, s.name, s.email, s.phone, s.enrollment_no, s.study_year, s.semester,
                b.name AS branch_name, b.code AS branch_code,
                r.id AS result_id, r.obtained_marks, r.total_marks, r.percentage, r.status,
                r.correct_count, r.wrong_count, r.unanswered_count, r.objective_marks,
                r.subjective_marks, r.subjective_total, r.pending_evaluation,
                a.started_at, a.submitted_at
           FROM students s
      LEFT JOIN branches b ON b.id = s.branch_id
      LEFT JOIN results r  ON r.exam_id = :e1 AND r.student_id = s.id
      LEFT JOIN attempts a ON a.id = r.attempt_id
          WHERE EXISTS (SELECT 1 FROM exam_assignments x WHERE x.exam_id = :e2 AND x.student_id = s.id)
             OR r.id IS NOT NULL
       ORDER BY (r.id IS NULL), r.obtained_marks DESC, a.submitted_at ASC, s.name'
    );
    $st->execute(['e1' => $examId, 'e2' => $examId]);
    $students = $st->fetchAll();

    $written = (float)$exam['subjective_total'] > 0;
    $columns = [
        ['rank',      'Rank',           true,  true],
        ['roll',      'Roll / Enrolment No.', false, true],
        ['name',      'Student Name',   false, true],
        ['branch',    'Branch',         false, true],
        ['class',     'Year / Sem',     false, true],
        ['email',     'Email',          false, false],
        ['phone',     'Phone',          false, false],
        ['attend',    'Attendance',     false, true],
        ['submitted', 'Submitted On',   false, true],
        ['time',      'Time Taken',     false, true],
        ['correct',   'Correct',        true,  true],
        ['wrong',     'Wrong',          true,  true],
        ['skipped',   'Skipped',        true,  true],
    ];
    if ($written) {
        $columns[] = ['objective', 'Objective Marks', true, true];
        $columns[] = ['written',   'Written Marks',   true, true];
    }
    array_push($columns,
        ['marks', 'Marks Obtained', true,  true],
        ['outof', 'Out of',         true,  true],
        ['pct',   'Percentage',     true,  true],
        ['grade', 'Grade',          false, true],
        ['result','Result',         false, true]
    );

    $rows = [];
    $rank = 0; $prevMarks = null; $pos = 0;
    $marks = [];
    foreach ($students as $s) {
        $present = $s['result_id'] !== null;
        $row = [
            'roll'   => $s['enrollment_no'] ?: '-',
            'name'   => $s['name'],
            'branch' => $s['branch_code'] ?: '-',
            'class'  => trim(($s['study_year'] ? year_label((int)$s['study_year']) : '')
                       . ($s['semester'] ? ', Sem ' . (int)$s['semester'] : ''), ', ') ?: '-',
            'email'  => $s['email'],
            'phone'  => $s['phone'] ?: '-',
            '_provisional' => false,
        ];
        if ($present) {
            $pos++;
            $obt = (float)$s['obtained_marks'];
            if ($prevMarks === null || $obt < $prevMarks) {
                $rank = $pos;
                $prevMarks = $obt;
            }
            $marks[] = $obt;
            $secs = ($s['submitted_at'] && $s['started_at'])
                  ? max(0, strtotime($s['submitted_at']) - strtotime($s['started_at'])) : 0;
            $row += [
                'rank'      => $rank,
                'attend'    => 'Present',
                'submitted' => format_datetime($s['submitted_at']),
                'time'      => format_seconds((int)$secs),
                'correct'   => (int)$s['correct_count'],
                'wrong'     => (int)$s['wrong_count'],
                'skipped'   => (int)$s['unanswered_count'],
                'objective' => round((float)$s['objective_marks'], 2),
                'written'   => (int)$s['pending_evaluation'] ? 'Pending'
                               : round((float)$s['subjective_marks'], 2),
                'marks'     => round($obt, 2),
                'outof'     => round((float)$s['total_marks'], 2),
                'pct'       => round((float)$s['percentage'], 2),
                'grade'     => result_grade((float)$s['percentage'], $s['status']),
                'result'    => $s['status'],
                '_state'    => $s['status'] === 'PASS' ? 'PASS' : 'FAIL',
                '_provisional' => (bool)(int)$s['pending_evaluation'],
            ];
        } else {
            $row += [
                'rank' => '-', 'attend' => 'Absent', 'submitted' => '-', 'time' => '-',
                'correct' => '-', 'wrong' => '-', 'skipped' => '-', 'objective' => '-',
                'written' => '-', 'marks' => '-', 'outof' => round((float)$exam['total_marks'], 2),
                'pct' => '-', 'grade' => '-', 'result' => 'ABSENT', '_state' => 'ABSENT',
            ];
        }
        $rows[] = $row;
    }

    $appeared = count($marks);
    $passed   = count(array_filter($rows, fn($r) => $r['_state'] === 'PASS'));
    $stats = [
        'Students'   => count($rows),
        'Appeared'   => $appeared,
        'Absent'     => count($rows) - $appeared,
        'Passed'     => $passed,
        'Failed'     => $appeared - $passed,
        'Pass Rate'  => ($appeared ? round($passed / $appeared * 100) : 0) . '%',
        'Average'    => num($appeared ? array_sum($marks) / $appeared : 0),
        'Highest'    => num($appeared ? max($marks) : 0),
        'Lowest'     => num($appeared ? min($marks) : 0),
    ];

    return [
        'columns' => $columns,
        'rows'    => $rows,
        'stats'   => $stats,
        'college' => get_college((int)($exam['college_id'] ?: $faculty['college_id'])),
    ];
}

/** The "about this paper" lines printed above the table, label => value. */
function exam_sheet_details(array $exam, array $faculty, array $progress): array
{
    $obj = (int)$exam['question_count'] - (int)$exam['subjective_count'];
    return [
        'Examination'        => $exam['title'],
        'Subject'            => $exam['subject'],
        'Class'              => exam_audience_label($exam),
        'Faculty'            => $faculty['name']
                                . (!empty($faculty['designation']) ? ', ' . $faculty['designation'] : '')
                                . (!empty($faculty['department']) ? ' (' . $faculty['department'] . ')' : ''),
        'Total / Passing Marks' => num((float)$exam['total_marks']) . ' / ' . num((float)$exam['passing_marks']),
        'Duration'           => (int)$exam['duration_minutes'] . ' minutes',
        'Questions'          => (int)$exam['question_count'] . " ($obj objective, "
                                . (int)$exam['subjective_count'] . ' written)',
        'Allotted / Completed' => $progress['assigned'] . ' / ' . $progress['completed'],
        'Generated On'       => date('d M Y, h:i A'),
    ];
}

/* ------------------------------------------------------------------ */
/*  Minimal .xlsx writer - no ZipArchive or Composer library needed    */
/* ------------------------------------------------------------------ */

function xlsx_col(int $i): string
{
    $s = '';
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
        $s = chr(65 + ($i - 1) % 26) . $s;
    }
    return $s;
}

/** Uncompressed (stored) zip archive of name => contents. */
function zip_stored(array $files): string
{
    $out = ''; $central = '';
    $t = getdate();
    $time = ($t['hours'] << 11) | ($t['minutes'] << 5) | intdiv($t['seconds'], 2);
    $date = (($t['year'] - 1980) << 9) | ($t['mon'] << 5) | $t['mday'];
    foreach ($files as $name => $data) {
        $crc = crc32($data); $len = strlen($data); $offset = strlen($out);
        $out .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, $time, $date, $crc, $len, $len,
                     strlen($name), 0) . $name . $data;
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 0, $time, $date, $crc,
                         $len, $len, strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
    }
    return $out . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files),
                                  strlen($central), strlen($out), 0);
}

/**
 * Build a one-sheet workbook.
 *
 * @param array $cells   [rowNo => [colNo => [value, styleId]]] (0-based columns, 1-based rows)
 * @param array $merges  ranges like "A1:H1"
 * @param array $widths  column widths, 0-based
 */
function xlsx_build(string $sheetName, array $cells, array $merges, array $widths,
                    int $freezeRow = 0, array $heights = []): string
{
    $x = fn($v) => htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8');

    $cols = '';
    foreach ($widths as $i => $w) {
        $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
    }
    ksort($cells);
    $data = '';
    foreach ($cells as $r => $row) {
        ksort($row);
        $ht = isset($heights[$r]) ? ' ht="' . $heights[$r] . '" customHeight="1"' : '';
        $data .= '<row r="' . $r . '"' . $ht . '>';
        foreach ($row as $c => [$v, $style]) {
            $ref = xlsx_col($c) . $r;
            if (is_int($v) || is_float($v)) {
                $data .= '<c r="' . $ref . '" s="' . $style . '"><v>' . $v . '</v></c>';
            } elseif ($v === null || $v === '') {
                $data .= '<c r="' . $ref . '" s="' . $style . '"/>';
            } else {
                $data .= '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">'
                       . $x($v) . '</t></is></c>';
            }
        }
        $data .= '</row>';
    }
    $mergeXml = $merges ? '<mergeCells count="' . count($merges) . '">'
        . implode('', array_map(fn($m) => '<mergeCell ref="' . $m . '"/>', $merges)) . '</mergeCells>' : '';
    $pane = $freezeRow ? '<pane ySplit="' . $freezeRow . '" topLeftCell="A' . ($freezeRow + 1)
        . '" activePane="bottomLeft" state="frozen"/>' : '';

    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
        . '<sheetViews><sheetView workbookViewId="0" showGridLines="0">' . $pane . '</sheetView></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="18"/>'
        . '<cols>' . $cols . '</cols><sheetData>' . $data . '</sheetData>' . $mergeXml
        . '<pageMargins left="0.4" right="0.4" top="0.5" bottom="0.5" header="0.3" footer="0.3"/>'
        . '<pageSetup orientation="landscape" paperSize="9" fitToWidth="1" fitToHeight="0"/>'
        . '</worksheet>';

    $font = fn($sz, $color = '0F172A', $b = false, $i = false) =>
        '<font>' . ($b ? '<b/>' : '') . ($i ? '<i/>' : '') . '<sz val="' . $sz . '"/><color rgb="FF'
        . $color . '"/><name val="Calibri"/></font>';
    $fill = fn($rgb) => '<fill><patternFill patternType="solid"><fgColor rgb="FF' . $rgb
        . '"/><bgColor indexed="64"/></patternFill></fill>';
    $border = '<border><left style="thin"><color rgb="FFCBD5E1"/></left><right style="thin"><color rgb="FFCBD5E1"/></right>'
        . '<top style="thin"><color rgb="FFCBD5E1"/></top><bottom style="thin"><color rgb="FFCBD5E1"/></bottom><diagonal/></border>';
    // xf: [font, fill, border, horizontal, wrap, numFmt]
    $xfs = [
        [0, 0, 0, '',       false, 0],   // 0 default
        [1, 2, 0, 'center', false, 0],   // 1 title (college name)
        [2, 3, 0, 'center', false, 0],   // 2 subtitle band
        [3, 2, 1, 'center', true,  0],   // 3 table header
        [0, 0, 1, '',       false, 0],   // 4 cell
        [0, 0, 1, 'center', false, 0],   // 5 cell centred
        [5, 6, 1, 'center', false, 0],   // 6 PASS
        [6, 7, 1, 'center', false, 0],   // 7 FAIL
        [4, 4, 1, '',       false, 0],   // 8 detail label
        [7, 0, 0, '',       false, 0],   // 9 footnote
        [8, 0, 0, '',       false, 0],   // 10 section heading
        [8, 8, 1, 'center', false, 0],   // 11 stat value
        [4, 4, 1, 'center', true,  0],   // 12 stat label
        [7, 5, 1, 'center', false, 0],   // 13 ABSENT
        [0, 0, 1, 'center', false, 2],   // 14 number 0.00
        [9, 0, 0, 'center', false, 0],   // 15 sub-heading under title
        [4, 0, 1, '',       false, 0],   // 16 bold cell (student name)
    ];
    $xfXml = '';
    foreach ($xfs as [$f, $fi, $b, $h, $wrap, $nf]) {
        $xfXml .= '<xf numFmtId="' . $nf . '" fontId="' . $f . '" fillId="' . $fi . '" borderId="' . $b
            . '" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"'
            . ($nf ? ' applyNumberFormat="1"' : '') . '><alignment vertical="center"'
            . ($h ? ' horizontal="' . $h . '"' : '') . ($wrap ? ' wrapText="1"' : '') . '/></xf>';
    }
    $fonts = [
        $font(11), $font(18, 'FFFFFF', true), $font(12, 'FFFFFF', true), $font(10, 'FFFFFF', true),
        $font(11, '0F172A', true), $font(11, '15803D', true), $font(11, 'B91C1C', true),
        $font(10, '64748B', false, true), $font(12, '1E3A8A', true), $font(11, '334155', false, true),
    ];
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="' . count($fonts) . '">' . implode('', $fonts) . '</fonts>'
        . '<fills count="9"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
        . $fill('1E3A8A') . $fill('2563EB') . $fill('F1F5F9') . $fill('F8FAFC') . $fill('DCFCE7')
        . $fill('FEE2E2') . $fill('EFF6FF') . '</fills>'
        . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>' . $border . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="' . count($xfs) . '">' . $xfXml . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';

    $sheetName = $x(mb_substr(preg_replace('#[\\\\/?*\[\]:]#', ' ', $sheetName), 0, 31));
    return zip_stored([
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . $sheetName . '" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>',
        'xl/styles.xml'            => $styles,
        'xl/worksheets/sheet1.xml' => $sheet,
    ]);
}
