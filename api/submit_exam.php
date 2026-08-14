<?php
/**
 * POST /api/submit_exam.php   (JSON in, JSON out)
 *
 * Grades the attempt on the server and closes it. Calling it twice is
 * harmless: grade_attempt() returns the existing result instead of
 * creating a second one.
 */
require_once __DIR__ . '/../includes/auth.php';

$student = require_student(true);

$payload = json_decode(file_get_contents('php://input'), true) ?: [];
$_POST   = array_merge($_POST, $payload);
verify_csrf(true);

$attemptId = (int)($payload['attempt_id'] ?? 0);
$auto      = !empty($payload['auto']);

$attempt = get_owned_attempt($attemptId, (int)$student['id']);
if (!$attempt) {
    json_out(['ok' => false, 'error' => 'Examination not found.'], 404);
}

// Time over even if the client thinks otherwise -> record it as automatic.
if (!$auto && seconds_left($attempt) <= -TIMER_GRACE_SECONDS) {
    $auto = true;
}

try {
    $result = grade_attempt($attemptId, $auto ? 'auto_submitted' : 'submitted');
} catch (Throwable $ex) {
    json_out(['ok' => false, 'error' => 'Could not submit the examination.'], 500);
}

json_out([
    'ok'       => true,
    'redirect' => url('result.php?attempt=' . $attemptId),
    'summary'  => [
        'correct'    => (int)$result['correct_count'],
        'wrong'      => (int)$result['wrong_count'],
        'unanswered' => (int)$result['unanswered_count'],
        'percentage' => (float)$result['percentage'],
        'status'     => $result['status'],
    ],
]);
