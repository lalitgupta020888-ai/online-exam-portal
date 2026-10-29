<?php
/**
 * Geo-fenced attendance.
 *
 * The college administrator stands inside the campus and saves its GPS
 * coordinates once. A student who has saved an Aadhaar number may then mark
 * attendance from the dashboard - once a day, and only from within
 * ATTENDANCE_RADIUS_M metres of that point. The distance is always worked out
 * on the server; the browser only reports where it is.
 *
 * The student also registers their face once in My Account. Each check-in
 * starts with a live camera scan; the browser turns the face into a 128 number
 * descriptor (face-api.js) and the server compares it with the registered one.
 */

define('ATTENDANCE_RADIUS_M', 15);
// A fix vaguer than this cannot prove the student is inside a 15 m circle.
define('ATTENDANCE_MAX_ACCURACY_M', 50);
// Euclidean distance between two face descriptors; below this = same person.
// face-api.js recommends 0.6, a stricter 0.5 keeps look-alikes out.
define('FACE_MATCH_THRESHOLD', 0.5);

/**
 * Add the location / Aadhaar columns and the attendance table on a site that
 * has not re-run upgrade.php. Runs the checks at most once per request.
 */
function ensure_attendance_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $columns = [
        ['colleges', 'latitude',        'DECIMAL(10,7) DEFAULT NULL'],
        ['colleges', 'longitude',       'DECIMAL(10,7) DEFAULT NULL'],
        ['colleges', 'geo_accuracy',    'DECIMAL(7,1) DEFAULT NULL'],
        ['colleges', 'geo_updated_at',  'DATETIME DEFAULT NULL'],
        ['students', 'aadhaar_no',      'CHAR(12) DEFAULT NULL'],
        ['students', 'face_descriptor', 'TEXT DEFAULT NULL'],
        ['students', 'face_updated_at', 'DATETIME DEFAULT NULL'],
    ];
    $st = db()->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS
                          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    foreach ($columns as [$table, $column, $type]) {
        $st->execute([$table, $column]);
        if (!(int)$st->fetchColumn()) {
            db()->exec("ALTER TABLE `$table` ADD COLUMN `$column` $type");
        }
    }

    db()->exec('CREATE TABLE IF NOT EXISTS `attendance` (
        `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `student_id` INT UNSIGNED NOT NULL,
        `college_id` INT UNSIGNED NOT NULL,
        `att_date`   DATE NOT NULL,
        `marked_at`  DATETIME NOT NULL,
        `latitude`   DECIMAL(10,7) NOT NULL,
        `longitude`  DECIMAL(10,7) NOT NULL,
        `accuracy`   DECIMAL(7,1) DEFAULT NULL,
        `distance_m` DECIMAL(7,1) NOT NULL,
        `face_distance` DECIMAL(5,3) DEFAULT NULL,
        `ip`         VARCHAR(45) DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_attendance_day` (`student_id`, `att_date`),
        KEY `idx_attendance_college` (`college_id`, `att_date`),
        CONSTRAINT `fk_attendance_student` FOREIGN KEY (`student_id`)
          REFERENCES `students` (`id`) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $st->execute(['attendance', 'face_distance']);
    if (!(int)$st->fetchColumn()) {
        db()->exec('ALTER TABLE `attendance` ADD COLUMN `face_distance` DECIMAL(5,3) DEFAULT NULL AFTER `distance_m`');
    }
    $done = true;
}

/** Great-circle distance in metres between two points (haversine). */
function geo_distance_m(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $r    = 6371000.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a    = sin($dLat / 2) ** 2
          + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return 2 * $r * asin(min(1.0, sqrt($a)));
}

/** True when lat / lng are real coordinates (and not the 0,0 "no fix" point). */
function valid_coordinates(float $lat, float $lng): bool
{
    return $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180
        && !($lat == 0.0 && $lng == 0.0);
}

/** Does the college have a saved campus location? */
function college_has_location(?array $college): bool
{
    return $college && $college['latitude'] !== null && $college['longitude'] !== null;
}

/**
 * Aadhaar numbers are 12 digits, never start with 0 or 1, and end in a
 * Verhoeff check digit - so most typing mistakes are caught here.
 */
function valid_aadhaar(string $number): bool
{
    if (!preg_match('/^[2-9][0-9]{11}$/', $number)) {
        return false;
    }
    static $d = [
        [0,1,2,3,4,5,6,7,8,9], [1,2,3,4,0,6,7,8,9,5], [2,3,4,0,1,7,8,9,5,6],
        [3,4,0,1,2,8,9,5,6,7], [4,0,1,2,3,9,5,6,7,8], [5,9,8,7,6,0,4,3,2,1],
        [6,5,9,8,7,1,0,4,3,2], [7,6,5,9,8,2,1,0,4,3], [8,7,6,5,9,3,2,1,0,4],
        [9,8,7,6,5,4,3,2,1,0],
    ];
    static $p = [
        [0,1,2,3,4,5,6,7,8,9], [1,5,7,6,2,8,3,0,9,4], [5,8,0,3,7,9,6,1,4,2],
        [8,9,1,6,0,4,3,5,2,7], [9,4,5,3,1,2,6,8,7,0], [4,2,8,6,5,7,3,9,0,1],
        [2,7,9,3,8,0,6,4,1,5], [7,0,4,6,9,1,3,2,5,8],
    ];
    $c = 0;
    foreach (array_reverse(str_split($number)) as $i => $digit) {
        $c = $d[$c][$p[$i % 8][(int)$digit]];
    }
    return $c === 0;
}

/** "XXXX XXXX 1234" - the full number is never shown back on screen. */
function mask_aadhaar(?string $number): string
{
    return $number ? 'XXXX XXXX ' . substr($number, -4) : '';
}

/**
 * A face descriptor sent by the browser (JSON array of 128 numbers), or null
 * when it is missing or malformed.
 *
 * @return float[]|null
 */
function parse_face_descriptor(string $json): ?array
{
    $v = json_decode($json, true);
    if (!is_array($v) || count($v) !== 128 || array_keys($v) !== range(0, 127)) {
        return null;
    }
    foreach ($v as $x) {
        if (!is_int($x) && !is_float($x) || !is_finite((float)$x) || abs($x) > 1) {
            return null;
        }
    }
    return array_map('floatval', $v);
}

/** Euclidean distance between two descriptors (0 = identical). */
function face_distance(array $a, array $b): float
{
    $sum = 0.0;
    foreach ($a as $i => $x) {
        $sum += ($x - $b[$i]) ** 2;
    }
    return sqrt($sum);
}

/** Does the student have a registered face? */
function student_has_face(array $student): bool
{
    return !empty($student['face_descriptor']);
}

/** Another student of the college whose registered face matches $face, or null. */
function face_owner_elsewhere(array $face, int $collegeId, int $exceptStudentId): ?array
{
    ensure_attendance_schema();
    $st = db()->prepare('SELECT id, name, face_descriptor FROM students
                          WHERE college_id = ? AND id <> ? AND face_descriptor IS NOT NULL');
    $st->execute([$collegeId, $exceptStudentId]);
    foreach ($st->fetchAll() as $r) {
        $other = parse_face_descriptor($r['face_descriptor']);
        if ($other && face_distance($face, $other) <= FACE_MATCH_THRESHOLD) {
            return $r;
        }
    }
    return null;
}

/** Today's attendance row for a student, or null. */
function attendance_today(int $studentId): ?array
{
    ensure_attendance_schema();
    $st = db()->prepare('SELECT * FROM attendance WHERE student_id = ? AND att_date = ?');
    $st->execute([$studentId, date('Y-m-d')]);
    return $st->fetch() ?: null;
}

/**
 * Every student of a college with their attendance on one day.
 * Rows carry branch_name / branch_code plus marked_at and distance_m (null = absent).
 *
 * A faculty member ($faculty given) sees only the students of their own branch
 * and the students on their own subject roster; the administrator sees everyone.
 *
 * @param array{branch_id?:int,study_year?:int,semester?:int} $filters
 */
function attendance_register(int $collegeId, string $date, array $filters = [],
                             ?array $faculty = null): array
{
    ensure_attendance_schema();
    $sql = 'SELECT s.id, s.name, s.enrollment_no, s.branch_id, s.study_year, s.semester,
                   b.name AS branch_name, b.code AS branch_code,
                   a.marked_at, a.distance_m
              FROM students s
         LEFT JOIN branches b   ON b.id = s.branch_id
         LEFT JOIN attendance a ON a.student_id = s.id AND a.att_date = ?
             WHERE s.college_id = ? AND s.is_active = 1';
    $params = [$date, $collegeId];
    if ($faculty !== null) {
        ensure_faculty_students_table();
        $sql .= ' AND (s.branch_id = ? OR s.id IN
                       (SELECT fs.student_id FROM faculty_students fs WHERE fs.faculty_id = ?))';
        array_push($params, (int)($faculty['branch_id'] ?? 0), (int)$faculty['id']);
    }
    foreach (['branch_id' => 's.branch_id', 'study_year' => 's.study_year',
              'semester' => 's.semester'] as $key => $col) {
        if (!empty($filters[$key])) {
            $sql .= " AND $col = ?";
            $params[] = (int)$filters[$key];
        }
    }
    $sql .= ' ORDER BY b.name IS NULL, b.name, s.study_year, s.semester, s.name';
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/**
 * Group a register branch -> class ("Year · Semester") with present / total
 * counts at every level.
 */
function attendance_breakdown(array $rows): array
{
    $out = ['present' => 0, 'total' => 0, 'branches' => []];
    foreach ($rows as $r) {
        $bKey = $r['branch_id'] ? (int)$r['branch_id'] : 0;
        $cKey = (int)$r['study_year'] . '-' . (int)$r['semester'];
        $here = $r['marked_at'] !== null ? 1 : 0;

        if (!isset($out['branches'][$bKey])) {
            $out['branches'][$bKey] = [
                'name' => $r['branch_name'] ?? 'No branch set', 'code' => $r['branch_code'] ?? '—',
                'present' => 0, 'total' => 0, 'classes' => [],
            ];
        }
        $b =& $out['branches'][$bKey];
        if (!isset($b['classes'][$cKey])) {
            $b['classes'][$cKey] = [
                'label' => ($r['study_year'] ? year_label((int)$r['study_year']) : 'Year not set')
                         . ($r['semester'] ? ' · Sem ' . (int)$r['semester'] : ''),
                'present' => 0, 'total' => 0, 'students' => [],
            ];
        }
        $b['classes'][$cKey]['students'][] = $r;
        $b['classes'][$cKey]['present'] += $here;
        $b['classes'][$cKey]['total']++;
        $b['present'] += $here;
        $b['total']++;
        $out['present'] += $here;
        $out['total']++;
        unset($b);
    }
    return $out;
}

/**
 * Month register: the students of attendance_register() plus, per student,
 * the days of $month ('YYYY-MM') they were present on.
 *
 * @return array{students: array, present: array<int, array<int, string>>, days: int,
 *               counted: int[]} counted = past or current days of the month that are not Sundays
 */
function attendance_month(int $collegeId, string $month, array $filters = [], ?array $faculty = null): array
{
    $first    = $month . '-01';
    $days     = (int)date('t', strtotime($first));
    $students = attendance_register($collegeId, $first, $filters, $faculty);

    $present = [];
    if ($students) {
        $ids = array_map('intval', array_column($students, 'id'));
        $st  = db()->prepare('SELECT student_id, att_date, marked_at FROM attendance
                               WHERE college_id = ? AND att_date BETWEEN ? AND ?
                                 AND student_id IN (' . implode(',', $ids) . ')');
        $st->execute([$collegeId, $first, $month . '-' . $days]);
        foreach ($st->fetchAll() as $r) {
            $present[(int)$r['student_id']][(int)substr($r['att_date'], 8, 2)] = $r['marked_at'];
        }
    }

    // Days before the college started taking attendance are not counted as absent.
    $since = attendance_start_date($collegeId) ?? date('Y-m-d');
    $counted = [];
    for ($d = 1; $d <= $days; $d++) {
        $ts  = strtotime(sprintf('%s-%02d', $month, $d));
        $day = date('Y-m-d', $ts);
        if ($day >= $since && $day <= date('Y-m-d') && date('w', $ts) !== '0') {
            $counted[] = $d;
        }
    }
    return ['students' => $students, 'present' => $present, 'days' => $days,
            'counted' => $counted, 'since' => $since];
}

/** Longest span a date range report may cover. */
define('ATTENDANCE_RANGE_MAX_DAYS', 366);

/**
 * Read ?from= / ?to= (YYYY-MM-DD) into a valid range: never in the future,
 * from <= to, and at most ATTENDANCE_RANGE_MAX_DAYS long. Null when not given.
 *
 * @return array{0:string,1:string}|null
 */
function attendance_range_from_get(): ?array
{
    $from = (string)($_GET['from'] ?? '');
    $to   = (string)($_GET['to'] ?? '');
    $ok   = static fn(string $d): bool => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d);
    if (!$ok($from) || !$ok($to)) {
        return null;
    }
    $today = date('Y-m-d');
    $to    = min($to, $today);
    $from  = min($from, $to);
    $span  = (int)((strtotime($to) - strtotime($from)) / 86400) + 1;
    if ($span > ATTENDANCE_RANGE_MAX_DAYS) {
        $from = date('Y-m-d', strtotime($to . ' -' . (ATTENDANCE_RANGE_MAX_DAYS - 1) . ' days'));
    }
    return [$from, $to];
}

/**
 * Totals for every student between two dates (inclusive).
 *
 * Working days are the days of the range that are not Sundays and fall on or
 * after the day the college started taking attendance. Each student row gets
 * _present (days present), _absent and _pct (present / working days).
 *
 * @return array{students: array, counted: int, since: string, avg: int, below: int}
 */
function attendance_range(int $collegeId, string $from, string $to, array $filters = [],
                          ?array $faculty = null): array
{
    $students = attendance_register($collegeId, $to, $filters, $faculty);
    $since    = attendance_start_date($collegeId) ?? date('Y-m-d');

    $counted = 0;
    for ($ts = strtotime(max($from, $since)); $ts <= strtotime($to); $ts = strtotime('+1 day', $ts)) {
        if (date('w', $ts) !== '0') {
            $counted++;
        }
    }

    $present = [];
    if ($students) {
        $ids = array_map('intval', array_column($students, 'id'));
        $st  = db()->prepare('SELECT student_id, COUNT(*) FROM attendance
                               WHERE college_id = ? AND att_date BETWEEN ? AND ?
                                 AND student_id IN (' . implode(',', $ids) . ')
                            GROUP BY student_id');
        $st->execute([$collegeId, $from, $to]);
        $present = array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
    }
    foreach ($students as &$s) {
        $s['_present'] = $present[(int)$s['id']] ?? 0;
        $s['_absent']  = max(0, $counted - $s['_present']);
        $s['_pct']     = min(100, attendance_pct($s['_present'], $counted));
    }
    unset($s);

    $avg = $students ? (int)round(array_sum(array_column($students, '_pct')) / count($students)) : 0;
    return ['students' => $students, 'counted' => $counted, 'since' => $since, 'avg' => $avg,
            'below' => count(array_filter($students, static fn($s) => $s['_pct'] < 75))];
}

/** First day this college took attendance: its first record or when the campus point was set. */
function attendance_start_date(int $collegeId): ?string
{
    ensure_attendance_schema();
    $st = db()->prepare('SELECT LEAST(
                COALESCE((SELECT MIN(att_date) FROM attendance WHERE college_id = ?), DATE(c.geo_updated_at)),
                COALESCE(DATE(c.geo_updated_at), (SELECT MIN(att_date) FROM attendance WHERE college_id = ?)))
            FROM colleges c WHERE c.id = ?');
    $st->execute([$collegeId, $collegeId, $collegeId]);
    return $st->fetchColumn() ?: null;
}

/** "CSE · 2nd Year · Sem 3" for a register row. */
function attendance_class_label(array $r): string
{
    return implode(' · ', array_filter([
        $r['study_year'] ? year_label((int)$r['study_year']) : '',
        $r['semester'] ? 'Sem ' . (int)$r['semester'] : '',
    ])) ?: '—';
}

/** Whole number percentage, 0 when there is nothing to divide by. */
function attendance_pct(int $present, int $total): int
{
    return $total ? (int)round($present / $total * 100) : 0;
}

/**
 * Days marked present in the current month and the current streak of
 * consecutive days (ending today or yesterday).
 *
 * @return array{month:int, streak:int, days:array<string,bool>}
 */
function attendance_summary(int $studentId): array
{
    ensure_attendance_schema();
    $st = db()->prepare('SELECT att_date FROM attendance
                          WHERE student_id = ? AND att_date >= ? ORDER BY att_date DESC');
    $st->execute([$studentId, date('Y-m-d', strtotime('-60 days'))]);
    $days = array_fill_keys($st->fetchAll(PDO::FETCH_COLUMN), true);

    $month = 0;
    foreach (array_keys($days) as $d) {
        if (substr($d, 0, 7) === date('Y-m')) {
            $month++;
        }
    }

    $streak = 0;
    $cursor = isset($days[date('Y-m-d')]) ? time() : strtotime('-1 day');
    while (isset($days[date('Y-m-d', $cursor)])) {
        $streak++;
        $cursor = strtotime('-1 day', $cursor);
    }
    return ['month' => $month, 'streak' => $streak, 'days' => $days];
}
