<?php
/**
 * Faculty - download attendance (day or month) as Excel or PDF, own students only.
 */
require_once __DIR__ . '/../includes/auth.php';
$faculty = require_faculty();

$collegeId  = (int)$faculty['college_id'];
$attFaculty = $faculty;
$boardPage  = 'faculty/attendance.php';
require __DIR__ . '/../includes/attendance_export.php';
