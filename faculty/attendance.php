<?php
/**
 * Faculty - attendance of their own students (own branch + subject roster),
 * branch-wise and class-wise.
 */
$pageTitle = 'Student Attendance';
$activeNav = 'attendance';
require_once __DIR__ . '/includes/faculty_header.php';

$collegeId = (int)$faculty['college_id'];
$boardPage = 'faculty/attendance.php';
$attFaculty = $faculty;           // own branch + own roster only
require __DIR__ . '/../includes/attendance_board.php';

require_once __DIR__ . '/includes/faculty_footer.php';
