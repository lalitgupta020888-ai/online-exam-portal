<?php
/**
 * Creates an attempt and starts the server side countdown.
 *
 * The deadline (expires_at) is computed here, once, and stored in the
 * database - which is why refreshing the exam page cannot buy extra time.
 */
require_once __DIR__ . '/includes/auth.php';

$student = require_student();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('dashboard.php');
}
verify_csrf();

$examId = (int)post('exam_id');
$exam   = get_exam($examId);

if (!$exam || !(int)$exam['is_active']) {
    set_flash('danger', 'That examination is not available.');
    redirect('dashboard.php');
}
if ((int)$exam['question_count'] === 0) {
    set_flash('danger', 'This examination has no questions yet.');
    redirect('dashboard.php');
}
// The authoritative check: an 'assigned' exam may only be started by the
// students its faculty listed.
if (!student_can_attempt($exam, (int)$student['id'])) {
    set_flash('danger', 'You have not been assigned to this examination.');
    redirect('dashboard.php');
}
if (post('accept') !== '1') {
    set_flash('warning', 'You must accept the instructions before starting.');
    redirect('instructions.php?exam_id=' . $examId);
}

// Close anything that has already run out of time.
close_expired_attempts((int)$student['id']);

// Resume instead of starting a second live attempt for the same exam.
$st = db()->prepare(
    "SELECT id FROM attempts
      WHERE student_id = ? AND exam_id = ? AND status = 'in_progress'
      ORDER BY id DESC LIMIT 1"
);
$st->execute([$student['id'], $examId]);
if ($row = $st->fetch()) {
    set_flash('info', 'Resuming your examination - the timer never stopped.');
    redirect('exam.php?attempt=' . (int)$row['id']);
}

$now     = time();
$expires = $now + ((int)$exam['duration_minutes'] * 60);

$pdo = db();
$pdo->beginTransaction();
try {
    $ins = $pdo->prepare(
        "INSERT INTO attempts (student_id, exam_id, started_at, expires_at, status)
         VALUES (?,?,?,?,'in_progress')"
    );
    $ins->execute([
        $student['id'], $examId,
        date('Y-m-d H:i:s', $now),
        date('Y-m-d H:i:s', $expires),
    ]);
    $attemptId = (int)$pdo->lastInsertId();

    // Pre-create one answer row per question so the palette has a state
    // for every question from the very first paint.
    $qs = $pdo->prepare('SELECT id FROM questions WHERE exam_id = ? ORDER BY sort_order, id');
    $qs->execute([$examId]);
    $blank = $pdo->prepare(
        'INSERT INTO answers (attempt_id, question_id, option_id, marked_for_review, visited)
         VALUES (?,?,NULL,0,0)'
    );
    foreach ($qs->fetchAll() as $q) {
        $blank->execute([$attemptId, $q['id']]);
    }

    $pdo->commit();
} catch (Throwable $ex) {
    $pdo->rollBack();
    set_flash('danger', 'Could not start the examination. Please try again.');
    redirect('dashboard.php');
}

redirect('exam.php?attempt=' . $attemptId);
