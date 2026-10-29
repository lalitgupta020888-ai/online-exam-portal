<?php
/**
 * GET /assignment_file.php?id=<submission id>
 *
 * Streams a submitted assignment PDF. Only the student who submitted it, the
 * faculty member who set the assignment, or an administrator of that college
 * may open it - the files themselves are not reachable from the web.
 */
require_once __DIR__ . '/includes/auth.php';
ensure_assignment_schema();

$st = db()->prepare('SELECT x.student_id, x.pdf_file, x.pdf_name, a.faculty_id, a.college_id
                       FROM assignment_submissions x JOIN assignments a ON a.id = x.assignment_id
                      WHERE x.id = ?');
$st->execute([get_int('id')]);
$row = $st->fetch();

$student = current_student();
$faculty = current_faculty();
$admin   = current_admin();
$allowed = $row && (
    ($student && (int)$student['id'] === (int)$row['student_id'])
    || ($faculty && (int)$faculty['id'] === (int)$row['faculty_id'])
    || ($admin && (int)$admin['college_id'] === (int)$row['college_id'])
);
$path = $row && $row['pdf_file'] ? ASSIGNMENT_DIR . '/' . basename($row['pdf_file']) : '';

if (!$allowed || !is_file($path)) {
    http_response_code(404);
    exit('File not found.');
}

$name = preg_replace('/[^\w\-. ]+/', '_', $row['pdf_name'] ?: 'assignment.pdf');
header('Content-Type: application/pdf');
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . $name . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
