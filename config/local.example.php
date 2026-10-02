<?php
/**
 * Database details for a server without environment variables (shared
 * hosting such as InfinityFree). Copy this file to config/local.php and fill
 * it in; config/local.php is ignored by git so the password stays private.
 *
 * InfinityFree shows these values under Control Panel > MySQL Databases.
 */
define('DB_HOST', 'sqlXXX.infinityfree.com');
define('DB_PORT', '3306');
define('DB_USER', 'if0_00000000');
define('DB_PASS', 'your-password');
define('DB_NAME', 'if0_00000000_exam');

// Email OTP for admin / faculty "forgot password" (Gmail SMTP).
// SMTP_PASS is a 16 letter Gmail App Password: Google Account > Security >
// 2-Step Verification > App passwords. Leave both empty to switch email OTP off.
define('SMTP_USER', 'yourname@gmail.com');
define('SMTP_PASS', 'abcdefghijklmnop');

// Phone OTP through Fast2SMS - leave empty to keep it switched off.
define('FAST2SMS_API_KEY', '');

// Keep PHP errors hidden from visitors on a live site.
define('DEBUG_MODE', false);
