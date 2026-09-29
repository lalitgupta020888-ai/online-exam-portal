<?php
/**
 * Student - reset a forgotten password with an email / phone OTP.
 *
 * This used to show a reset link on screen, which let anyone who knew a
 * student's email change their password. It now shares the OTP flow used by
 * administrators and faculty.
 */
$otpRole = [
    'key'         => 'student',
    'table'       => 'students',
    'title'       => 'Student',
    'login'       => 'login.php',
    'body'        => 'auth-body-faculty',
    'match'       => ['email', 'enrollment_no'],
    'ident_label' => 'Email, Enrolment No. or Phone',
];
require __DIR__ . '/includes/forgot_otp.php';
