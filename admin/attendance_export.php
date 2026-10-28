<?php
/**
 * Admin - download attendance (day or month) as Excel or PDF, whole college.
 */
require_once __DIR__ . '/../includes/auth.php';
$admin = require_admin();

$collegeId  = (int)$admin['college_id'];
$attFaculty = null;
$boardPage  = 'admin/attendance.php';
require __DIR__ . '/../includes/attendance_export.php';
