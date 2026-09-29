<?php
/**
 * Administrator - reset a forgotten password with an email / phone OTP.
 */
$otpRole = [
    'key'   => 'admin',
    'table' => 'admins',
    'title' => 'Administrator',
    'login' => 'admin/login.php',
    'body'  => 'auth-body-admin',
];
require __DIR__ . '/../includes/forgot_otp.php';
