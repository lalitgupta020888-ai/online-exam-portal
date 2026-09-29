<?php
/**
 * Faculty - reset a forgotten password with an email / phone OTP.
 */
$otpRole = [
    'key'   => 'faculty',
    'table' => 'faculty',
    'title' => 'Faculty',
    'login' => 'faculty/login.php',
    'body'  => 'auth-body-faculty',
];
require __DIR__ . '/../includes/forgot_otp.php';
