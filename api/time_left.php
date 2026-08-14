<?php
/**
 * GET /api/time_left.php?attempt=ID
 *
 * The browser polls this every 30 seconds so the displayed countdown can
 * never drift away from the authoritative server deadline.
 */
require_once __DIR__ . '/../includes/auth.php';

$student   = require_student(true);
$attemptId = get_int('attempt');
$attempt   = get_owned_attempt($attemptId, (int)$student['id']);

if (!$attempt) {
    json_out(['ok' => false, 'error' => 'Examination not found.'], 404);
}

$left = seconds_left($attempt);

// Deadline passed while the tab was idle - grade it right here.
if ($attempt['status'] === 'in_progress' && $left <= 0) {
    grade_attempt($attemptId, 'auto_submitted');
    $attempt['status'] = 'auto_submitted';
}

json_out([
    'ok'           => true,
    'status'       => $attempt['status'],
    'seconds_left' => $left,
]);
