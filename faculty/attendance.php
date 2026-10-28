<?php
/**
 * Faculty - student attendance register of the college, branch-wise and class-wise.
 */
$pageTitle = 'Student Attendance';
$activeNav = 'attendance';
require_once __DIR__ . '/includes/faculty_header.php';

$collegeId = (int)$faculty['college_id'];
$boardPage = 'faculty/attendance.php';
require __DIR__ . '/../includes/attendance_board.php';

require_once __DIR__ . '/includes/faculty_footer.php';
