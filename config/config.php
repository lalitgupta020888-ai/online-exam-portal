<?php
/**
 * Global configuration.
 * Edit the DB_* constants if your XAMPP MySQL uses a different user/password.
 */

// ---------- Database ----------
/**
 * Read from the environment when the host provides it (any cloud deploy),
 * otherwise fall back to the stock XAMPP values for local development.
 *
 * Several keys may be given: the first one the host has set wins. Railway
 * publishes its MySQL credentials as MYSQLHOST / MYSQLUSER / MYSQLPASSWORD,
 * so those are accepted as aliases of the plain DB_* names.
 */
function env_or($keys, string $fallback): string
{
    foreach ((array) $keys as $key) {
        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return $value;
        }
    }
    return $fallback;
}

define('DB_HOST', env_or(['DB_HOST', 'MYSQLHOST'], '127.0.0.1'));
define('DB_PORT', env_or(['DB_PORT', 'MYSQLPORT'], '3306'));
define('DB_USER', env_or(['DB_USER', 'MYSQLUSER'], 'root'));
define('DB_PASS', env_or(['DB_PASS', 'MYSQLPASSWORD'], ''));  // XAMPP default is empty

// Deliberately NOT aliased to Railway's MYSQLDATABASE (which is "railway"):
// database/schema.sql creates and populates a database called online_exam,
// so the app must look in that one or it would connect to an empty schema.
define('DB_NAME', env_or('DB_NAME', 'online_exam'));

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

// Show PHP errors while developing locally. Set APP_DEBUG=0 in production.
define('DEBUG_MODE', env_or('APP_DEBUG', '1') === '1');

if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

// Use the server timezone consistently for every DATETIME we store.
date_default_timezone_set('Asia/Kolkata');
