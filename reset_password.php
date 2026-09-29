<?php
/**
 * Old link-based reset. Passwords are now reset with an OTP on the forgot
 * password page, so any old link just leads there.
 */
require_once __DIR__ . '/includes/auth.php';
redirect('forgot_password.php');
