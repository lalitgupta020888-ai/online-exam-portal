<?php
/**
 * Global configuration.
 * Edit the DB_* constants if your XAMPP MySQL uses a different user/password.
 */

// ---------- Database ----------
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'online_exam');
define('DB_USER', 'root');
define('DB_PASS', '');          // default XAMPP MySQL password is empty

// ---------- Application ----------
define('APP_NAME', 'Online Exam Portal');

/**
 * Base URL path of the project as seen by the browser.
 * If you copied the folder to htdocs/online-exam this must be '/online-exam'.
 * It is detected automatically, but you may hard-code it if needed.
 */
if (!defined('BASE_URL')) {
    $dir = str_replace('\\', '/', dirname(dirname(__FILE__)));
    $root = str_replace('\\', '/', rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/'));
    $base = ($root && strpos($dir, $root) === 0) ? substr($dir, strlen($root)) : '';
    define('BASE_URL', rtrim($base, '/'));
}

// Grace period (seconds) added to the server deadline so that a request
// sent right on the buzzer is still accepted instead of being rejected.
define('TIMER_GRACE_SECONDS', 5);

/**
 * Can a newly registered faculty member sign in straight away?
 *
 * false (recommended) - the account is created as "pending" and an
 *                       administrator approves it from Admin > Faculty.
 *                       A faculty account can set papers and read every
 *                       assigned student's marks, so it is worth a check.
 * true                - self registration is trusted and login works at once.
 */
define('FACULTY_SELF_APPROVE', false);

// Minimum password length used by every registration / change password form.
define('MIN_PASSWORD_LENGTH', 6);

// Show PHP errors while developing locally. Set to false for production.
define('DEBUG_MODE', true);

if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

// Use the server timezone consistently for every DATETIME we store.
date_default_timezone_set('Asia/Kolkata');
