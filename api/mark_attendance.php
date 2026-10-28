<?php
/**
 * POST /api/mark_attendance.php  (lat, lng, accuracy)
 *
 * Marks today's attendance for the signed in student when their reported
 * position lies within ATTENDANCE_RADIUS_M metres of the campus point the
 * college administrator saved.
 */
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'error' => 'Method not allowed.'], 405);
}
$student = require_student(true);
verify_csrf(true);
ensure_attendance_schema();

if (empty($student['aadhaar_no'])) {
    json_out(['ok' => false, 'code' => 'aadhaar',
              'error' => 'Add your Aadhaar number in My Account before marking attendance.'], 422);
}

$college = get_college((int)$student['college_id']);
if (!college_has_location($college)) {
    json_out(['ok' => false, 'code' => 'campus',
              'error' => 'Your college has not set its campus location yet. Please inform the administrator.'], 422);
}

if ($row = attendance_today((int)$student['id'])) {
    json_out(['ok' => true, 'already' => true,
              'time' => date('h:i A', strtotime($row['marked_at'])),
              'distance' => (float)$row['distance_m']]);
}

$lat = (float)post('lat');
$lng = (float)post('lng');
$acc = (float)post('accuracy');
if (!valid_coordinates($lat, $lng)) {
    json_out(['ok' => false, 'error' => 'Your location could not be read. Please try again.'], 422);
}
if ($acc <= 0 || $acc > ATTENDANCE_MAX_ACCURACY_M) {
    json_out(['ok' => false, 'code' => 'accuracy', 'accuracy' => round($acc),
              'error' => 'GPS signal is too weak (±' . round($acc) . ' m). Step near a window or into the open and try again.'], 422);
}

$distance = geo_distance_m($lat, $lng, (float)$college['latitude'], (float)$college['longitude']);
if ($distance > ATTENDANCE_RADIUS_M) {
    json_out(['ok' => false, 'code' => 'outside', 'distance' => round($distance, 1),
              'error' => 'You are ' . num(round($distance)) . ' m from the campus point. Attendance can only be marked within '
                       . ATTENDANCE_RADIUS_M . ' m of it.'], 422);
}

$now = date('Y-m-d H:i:s');
// INSERT IGNORE: a double tap must not fail on the one-per-day key.
db()->prepare(
    'INSERT IGNORE INTO attendance
        (student_id, college_id, att_date, marked_at, latitude, longitude, accuracy, distance_m, ip)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([$student['id'], $college['id'], date('Y-m-d'), $now,
            $lat, $lng, $acc, round($distance, 1), client_ip()]);

json_out(['ok' => true, 'already' => false,
          'time' => date('h:i A', strtotime($now)), 'distance' => round($distance, 1)]);
