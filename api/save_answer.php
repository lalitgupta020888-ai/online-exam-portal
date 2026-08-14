<?php
/**
 * POST /api/save_answer.php   (JSON in, JSON out)
 *
 * Stores one answer / review flag for the logged in student's attempt.
 * Rejects the write when the attempt is not theirs, already submitted,
 * or past its deadline.
 */
require_once __DIR__ . '/../includes/auth.php';

$student = require_student(true);

$raw     = file_get_contents('php://input');
$payload = json_decode($raw, true) ?: [];
$_POST   = array_merge($_POST, $payload);   // lets verify_csrf() see the token too
verify_csrf(true);

$attemptId  = (int)($payload['attempt_id'] ?? 0);
$questionId = (int)($payload['question_id'] ?? 0);
$optionId   = isset($payload['option_id']) && $payload['option_id'] !== null
              ? (int)$payload['option_id'] : null;
$answerText = isset($payload['answer_text']) && $payload['answer_text'] !== null
              ? (string)$payload['answer_text'] : null;
$review     = !empty($payload['review']) ? 1 : 0;

// A written answer is capped so a runaway paste cannot fill the database.
if ($answerText !== null && mb_strlen($answerText) > 20000) {
    $answerText = mb_substr($answerText, 0, 20000);
}

$attempt = get_owned_attempt($attemptId, (int)$student['id']);
if (!$attempt) {
    json_out(['ok' => false, 'error' => 'Examination not found.'], 404);
}
if ($attempt['status'] !== 'in_progress') {
    json_out(['ok' => false, 'error' => 'This examination has already been submitted.',
              'expired' => true], 409);
}
if (seconds_left($attempt) <= 0) {
    grade_attempt($attemptId, 'auto_submitted');
    json_out(['ok' => false, 'error' => 'Time is over.', 'expired' => true], 409);
}

// The question must belong to this exam.
$chk = db()->prepare('SELECT id, type FROM questions WHERE id = ? AND exam_id = ?');
$chk->execute([$questionId, $attempt['exam_id']]);
$question = $chk->fetch();
if (!$question) {
    json_out(['ok' => false, 'error' => 'Invalid question.'], 400);
}

if ($question['type'] === 'subjective') {
    // A written question has no options - ignore anything sent in that field.
    $optionId = null;
} else {
    $answerText = null;
    // The option, when given, must belong to that question.
    if ($optionId !== null) {
        $chk = db()->prepare('SELECT id FROM options WHERE id = ? AND question_id = ?');
        $chk->execute([$optionId, $questionId]);
        if (!$chk->fetch()) {
            json_out(['ok' => false, 'error' => 'Invalid option.'], 400);
        }
    }
}

$sql = 'INSERT INTO answers
            (attempt_id, question_id, option_id, answer_text, marked_for_review, visited)
        VALUES (?,?,?,?,?,1)
        ON DUPLICATE KEY UPDATE option_id = VALUES(option_id),
                                answer_text = VALUES(answer_text),
                                marked_for_review = VALUES(marked_for_review),
                                visited = 1';
db()->prepare($sql)->execute([$attemptId, $questionId, $optionId, $answerText, $review]);

json_out([
    'ok'           => true,
    'seconds_left' => seconds_left($attempt),
]);
