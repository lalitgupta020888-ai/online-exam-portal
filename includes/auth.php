<?php
/**
 * Session handling, CSRF protection and page guards.
 * Every page in the project includes this file first.
 */
require_once __DIR__ . '/../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('OEP_SESSION');
    session_start();

    // Rotate the id periodically to limit session fixation.
    if (!isset($_SESSION['created_at'])) {
        $_SESSION['created_at'] = time();
    } elseif (time() - $_SESSION['created_at'] > 1800) {
        session_regenerate_id(true);
        $_SESSION['created_at'] = time();
    }
}

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/college.php';

/* ------------------------------------------------------------------ */
/*  CSRF                                                               */
/* ------------------------------------------------------------------ */

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Hidden input to drop inside every POST form. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/**
 * Verify the token on a POST request; aborts the request when invalid.
 *
 * 403 is used rather than the Laravel style 419 because Apache only passes
 * through status codes it knows and rewrites unregistered ones to 500.
 */
function verify_csrf(bool $json = false): void
{
    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!hash_equals(csrf_token(), (string)$token)) {
        if ($json) {
            json_out(['ok' => false, 'error' => 'Invalid security token. Reload the page.'], 403);
        }
        http_response_code(403);
        exit('Invalid security token. Please go back and reload the page.');
    }
}

/* ------------------------------------------------------------------ */
/*  Student session                                                    */
/* ------------------------------------------------------------------ */

function login_student(array $student): void
{
    session_regenerate_id(true);
    $_SESSION['student_id']   = (int)$student['id'];
    $_SESSION['student_name'] = $student['name'];
    $_SESSION['created_at']   = time();
}

function is_student_logged_in(): bool
{
    return !empty($_SESSION['student_id']);
}

function current_student(): ?array
{
    if (!is_student_logged_in()) {
        return null;
    }
    static $cache = null;
    if ($cache === null) {
        $st = db()->prepare('SELECT * FROM students WHERE id = ?');
        $st->execute([$_SESSION['student_id']]);
        $cache = $st->fetch() ?: null;
    }
    return $cache;
}

/**
 * Drop a session whose account no longer loads (deleted, suspended, blocked).
 *
 * Without this the login page - which only looks at the session variable -
 * would bounce such a user straight back to the guarded page, and the two
 * would redirect to each other forever.
 */
function abandon_stale_session(string $role): void
{
    unset($_SESSION[$role . '_id'], $_SESSION[$role . '_name']);
}

/** Guard: only a logged in student may continue. */
function require_student(bool $json = false): array
{
    $student = current_student();
    if (!$student) {
        if ($json) {
            json_out(['ok' => false, 'error' => 'Your session has expired. Please log in again.'], 401);
        }
        abandon_stale_session('student');
        set_flash('warning', 'Please log in to continue.');
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? '';
        redirect('login.php');
    }
    return $student;
}

/* ------------------------------------------------------------------ */
/*  Admin session                                                      */
/* ------------------------------------------------------------------ */

function login_admin(array $admin): void
{
    session_regenerate_id(true);
    $_SESSION['admin_id']   = (int)$admin['id'];
    $_SESSION['admin_name'] = $admin['name'];
    $_SESSION['created_at'] = time();
}

function is_admin_logged_in(): bool
{
    return !empty($_SESSION['admin_id']);
}

function current_admin(): ?array
{
    if (!is_admin_logged_in()) {
        return null;
    }
    $st = db()->prepare('SELECT * FROM admins WHERE id = ?');
    $st->execute([$_SESSION['admin_id']]);
    return $st->fetch() ?: null;
}

/** Guard: only a logged in administrator may continue. */
function require_admin(): array
{
    $admin = current_admin();
    if (!$admin) {
        abandon_stale_session('admin');
        set_flash('warning', 'Administrator login required.');
        redirect('admin/login.php');
    }
    return $admin;
}

/* ------------------------------------------------------------------ */
/*  Faculty session                                                    */
/* ------------------------------------------------------------------ */

function login_faculty(array $faculty): void
{
    session_regenerate_id(true);
    $_SESSION['faculty_id']   = (int)$faculty['id'];
    $_SESSION['faculty_name'] = $faculty['name'];
    $_SESSION['created_at']   = time();
}

function is_faculty_logged_in(): bool
{
    return !empty($_SESSION['faculty_id']);
}

function current_faculty(): ?array
{
    if (!is_faculty_logged_in()) {
        return null;
    }
    // An account that is suspended, or still waiting for approval, stops
    // working immediately - even if the session was opened before that.
    $st = db()->prepare("SELECT * FROM faculty
                          WHERE id = ? AND is_active = 1 AND status = 'approved'");
    $st->execute([$_SESSION['faculty_id']]);
    return $st->fetch() ?: null;
}

/** Guard: only a logged in, approved faculty member may continue. */
function require_faculty(): array
{
    $faculty = current_faculty();
    if (!$faculty) {
        // Covers a suspended or deleted account whose session is still open.
        $wasSignedIn = is_faculty_logged_in();
        abandon_stale_session('faculty');
        set_flash('warning', $wasSignedIn
            ? 'Your faculty account is no longer active. Please contact the administrator.'
            : 'Faculty login required.');
        redirect('faculty/login.php');
    }
    return $faculty;
}

/* ------------------------------------------------------------------ */
/*  Tenant boundary                                                    */
/* ------------------------------------------------------------------ */

/**
 * The college of whoever is signed in - the boundary every listing query
 * filters on, so one college can never see another's data.
 */
function current_college_id(): ?int
{
    foreach ([['faculty', 'current_faculty'],
              ['admin',   'current_admin'],
              ['student', 'current_student']] as [$role, $fn]) {
        if (!empty($_SESSION[$role . '_id'])) {
            $account = $fn();
            if ($account && !empty($account['college_id'])) {
                return (int)$account['college_id'];
            }
        }
    }
    return null;
}

/** The college record of whoever is signed in. */
function current_college(): ?array
{
    return get_college(current_college_id());
}

/**
 * Load an exam and make sure the logged in faculty member owns it.
 * Returns null when the exam does not exist or belongs to someone else.
 */
function get_owned_exam(int $examId, int $facultyId): ?array
{
    $exam = get_exam($examId);
    if (!$exam || (int)$exam['faculty_id'] !== $facultyId) {
        return null;
    }
    return $exam;
}

/**
 * Load an attempt and make sure it belongs to the logged in student.
 * Returns null when the attempt does not exist or is not theirs.
 */
function get_owned_attempt(int $attemptId, int $studentId): ?array
{
    $st = db()->prepare('SELECT * FROM attempts WHERE id = ? AND student_id = ?');
    $st->execute([$attemptId, $studentId]);
    return $st->fetch() ?: null;
}
