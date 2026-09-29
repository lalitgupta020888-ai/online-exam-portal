<?php
/**
 * One time passwords for "forgot password" on the admin and faculty sides,
 * plus the tiny mail / SMS senders they need.
 *
 * Shared hosting has no working mail(), so email goes straight to an SMTP
 * server (Gmail by default, see SMTP_* in config/config.php). SMS goes
 * through Fast2SMS when FAST2SMS_API_KEY is set.
 */
require_once __DIR__ . '/../config/db.php';

const OTP_TTL_SECONDS    = 600;  // a code is valid for 10 minutes
const OTP_MAX_ATTEMPTS   = 5;    // wrong guesses before the code is burnt
const OTP_RESEND_SECONDS = 60;   // minimum gap between two codes

function email_otp_enabled(): bool
{
    return SMTP_USER !== '' && SMTP_PASS !== '';
}

function sms_otp_enabled(): bool
{
    return FAST2SMS_API_KEY !== '';
}

/** Created on first use so the live site needs no separate upgrade run. */
function ensure_otp_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    db()->exec("CREATE TABLE IF NOT EXISTS `password_otps` (
        `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `account_type` VARCHAR(10)  NOT NULL,
        `account_id`   INT UNSIGNED NOT NULL,
        `code_hash`    VARCHAR(255) NOT NULL,
        `attempts`     TINYINT UNSIGNED NOT NULL DEFAULT 0,
        `expires_at`   DATETIME     NOT NULL,
        `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_otp_account` (`account_type`, `account_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $done = true;
}

/** Seconds the account must still wait before another code may be sent. */
function otp_wait_seconds(string $type, int $id): int
{
    ensure_otp_table();
    $st = db()->prepare('SELECT MAX(created_at) FROM password_otps
                          WHERE account_type = ? AND account_id = ?');
    $st->execute([$type, $id]);
    $last = $st->fetchColumn();
    return $last ? max(0, OTP_RESEND_SECONDS - (time() - strtotime($last))) : 0;
}

/** Create a fresh 6 digit code, replacing any earlier one, and return it. */
function otp_issue(string $type, int $id): string
{
    ensure_otp_table();
    $code = (string)random_int(100000, 999999);
    db()->prepare('DELETE FROM password_otps WHERE account_type = ? AND account_id = ?')
        ->execute([$type, $id]);
    db()->prepare('INSERT INTO password_otps (account_type, account_id, code_hash, expires_at)
                   VALUES (?,?,?,?)')
        ->execute([$type, $id, password_hash($code, PASSWORD_DEFAULT),
                   date('Y-m-d H:i:s', time() + OTP_TTL_SECONDS)]);
    return $code;
}

/** Check a code. A correct one is used up; wrong ones count toward the limit. */
function otp_verify(string $type, int $id, string $code): bool
{
    ensure_otp_table();
    $st = db()->prepare('SELECT * FROM password_otps WHERE account_type = ? AND account_id = ?');
    $st->execute([$type, $id]);
    $row = $st->fetch();
    if (!$row || strtotime($row['expires_at']) < time()
        || (int)$row['attempts'] >= OTP_MAX_ATTEMPTS) {
        return false;
    }
    if (!password_verify($code, $row['code_hash'])) {
        db()->prepare('UPDATE password_otps SET attempts = attempts + 1 WHERE id = ?')
            ->execute([$row['id']]);
        return false;
    }
    db()->prepare('DELETE FROM password_otps WHERE id = ?')->execute([$row['id']]);
    return true;
}

/** Keep only the last 10 digits, so "+91 98765-43210" matches "9876543210". */
function normalize_phone(?string $phone): string
{
    $digits = preg_replace('/\D+/', '', (string)$phone);
    return strlen($digits) > 10 ? substr($digits, -10) : $digits;
}

function mask_email(string $email): string
{
    [$user, $domain] = array_pad(explode('@', $email, 2), 2, '');
    return substr($user, 0, 2) . str_repeat('*', max(1, strlen($user) - 2)) . '@' . $domain;
}

function mask_phone(string $phone): string
{
    $d = normalize_phone($phone);
    return str_repeat('*', max(0, strlen($d) - 4)) . substr($d, -4);
}

/**
 * Send a plain text email over SMTP with SSL (port 465) or STARTTLS (587).
 * Returns '' on success or a short error description.
 */
function smtp_send(string $to, string $subject, string $body): string
{
    if (!email_otp_enabled()) {
        return 'Email sending is not configured.';
    }
    $ssl  = SMTP_PORT === 465;
    $sock = @stream_socket_client(($ssl ? 'ssl://' : 'tcp://') . SMTP_HOST . ':' . SMTP_PORT,
                                  $errno, $errstr, 20);
    if (!$sock) {
        return "Could not connect to the mail server ($errstr).";
    }
    stream_set_timeout($sock, 20);

    $read = static function () use ($sock): string {
        $out = '';
        while (($line = fgets($sock, 515)) !== false) {
            $out .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        return $out;
    };
    $cmd = static function (string $line, string $expect) use ($sock, $read): void {
        fwrite($sock, $line . "\r\n");
        $reply = $read();
        if (strpos($reply, $expect) !== 0) {
            throw new RuntimeException(trim($reply) ?: 'No reply from mail server');
        }
    };

    try {
        if (strpos($read(), '220') !== 0) {
            throw new RuntimeException('Mail server did not greet us');
        }
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $cmd('EHLO ' . $host, '250');
        if (!$ssl) {
            $cmd('STARTTLS', '220');
            if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('TLS negotiation failed');
            }
            $cmd('EHLO ' . $host, '250');
        }
        $cmd('AUTH LOGIN', '334');
        $cmd(base64_encode(SMTP_USER), '334');
        $cmd(base64_encode(SMTP_PASS), '235');
        $cmd('MAIL FROM:<' . SMTP_USER . '>', '250');
        $cmd('RCPT TO:<' . $to . '>', '250');
        $cmd('DATA', '354');

        $headers = [
            'Date: ' . date('r'),
            'From: =?UTF-8?B?' . base64_encode(SMTP_FROM_NAME) . '?= <' . SMTP_USER . '>',
            'To: <' . $to . '>',
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];
        // Dot-stuff lines that start with "." as SMTP requires.
        $text = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $body));
        $cmd(implode("\r\n", $headers) . "\r\n\r\n" . str_replace("\n", "\r\n", $text) . "\r\n.", '250');
        fwrite($sock, "QUIT\r\n");
        fclose($sock);
        return '';
    } catch (RuntimeException $ex) {
        fclose($sock);
        return $ex->getMessage();
    }
}

/** Send an OTP by SMS through Fast2SMS. Returns '' on success or an error. */
function sms_send_otp(string $phone, string $code): string
{
    if (!sms_otp_enabled()) {
        return 'SMS sending is not configured.';
    }
    $number = normalize_phone($phone);
    if (strlen($number) !== 10) {
        return 'The registered phone number is not a valid 10 digit mobile number.';
    }
    $ch = curl_init('https://www.fast2sms.com/dev/bulkV2');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'route' => 'otp', 'variables_values' => $code, 'numbers' => $number,
        ]),
        CURLOPT_HTTPHEADER     => ['authorization: ' . FAST2SMS_API_KEY],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) {
        return 'SMS request failed (' . $err . ').';
    }
    $json = json_decode($res, true);
    $msg  = $json['message'] ?? 'unknown error';
    return !empty($json['return']) ? '' : ('SMS was not sent: ' . implode(' ', (array)$msg));
}
