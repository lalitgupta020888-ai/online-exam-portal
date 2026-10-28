<?php
/**
 * Admin - student attendance register, branch-wise and class-wise.
 */
$pageTitle = 'Student Attendance';
$activeNav = 'attendance';
require_once __DIR__ . '/includes/admin_header.php';

$collegeId = (int)$admin['college_id'];
$boardPage = 'admin/attendance.php';
$attFaculty = null;                // the whole college
require __DIR__ . '/../includes/attendance_board.php';

require_once __DIR__ . '/includes/admin_footer.php';
