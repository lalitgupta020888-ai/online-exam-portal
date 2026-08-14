<?php
/**
 * PDO database connection (singleton).
 * Every query in the project uses prepared statements through this handle.
 */
require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            DB_HOST, DB_PORT, DB_NAME);
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            $msg = DEBUG_MODE ? htmlspecialchars($e->getMessage()) : '';
            exit('<h3 style="font-family:sans-serif">Database connection failed.</h3>'
               . '<p style="font-family:sans-serif">Start MySQL in XAMPP and run '
               . '<code>install.php</code> first.</p><pre>' . $msg . '</pre>');
        }
    }
    return $pdo;
}
