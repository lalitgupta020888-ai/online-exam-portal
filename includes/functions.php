<?php
/**
 * Shared helper functions: escaping, redirects, flash messages, formatting
 * and the exam scoring engine.
 */
require_once __DIR__ . '/../config/db.php';

/* ------------------------------------------------------------------ */
/*  Output / navigation helpers                                        */
/* ------------------------------------------------------------------ */

/** Escape a value for safe HTML output. */
function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Build an absolute URL from a project-relative path. */
function url(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

/**
 * Redirect and stop execution.
 *
 * A path starting with "/" is already absolute (for example a stored
 * REQUEST_URI) and is used as it is - passing it through url() would
 * prefix BASE_URL a second time and produce /online-exam/online-exam/...
 * A protocol relative "//host" path is rejected so a crafted request
 * cannot turn the login redirect into an open redirect.
 */
function redirect(string $path): void
{
    if (preg_match('#^https?://#', $path)) {
        $target = $path;
    } elseif (str_starts_with($path, '//')) {
        $target = url('index.php');
    } elseif (str_starts_with($path, '/')) {
        $target = $path;
    } else {
        $target = url($path);
    }
    header('Location: ' . $target);
    exit;
}

/** Queue a one-shot message shown on the next page load. */
function set_flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/** Render and clear all queued flash messages. */
function render_flash(): string
{
    if (empty($_SESSION['flash'])) {
        return '';
    }
    $icons = ['success' => 'check-circle-fill', 'danger' => 'exclamation-triangle-fill',
              'warning' => 'exclamation-circle-fill', 'info' => 'info-circle-fill'];
    $html = '';
    foreach ($_SESSION['flash'] as $f) {
        $icon = $icons[$f['type']] ?? 'info-circle-fill';
        $html .= '<div class="alert alert-' . e($f['type'])
              . ' alert-dismissible fade show d-flex align-items-center" role="alert">'
              . '<i class="bi bi-' . $icon . ' me-2"></i><div>' . e($f['message']) . '</div>'
              . '<button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"'
              . ' aria-label="Close"></button></div>';
    }
    unset($_SESSION['flash']);
    return $html;
}

/** Read a POST field as a trimmed string. */
function post(string $key, string $default = ''): string
{
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
}

/** Read a GET field as an integer. */
function get_int(string $key, int $default = 0): int
{
    return isset($_GET[$key]) ? (int)$_GET[$key] : $default;
}

/** Send a JSON response and stop. */
function json_out(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

/* ------------------------------------------------------------------ */
/*  Passwords                                                          */
/* ------------------------------------------------------------------ */

/**
 * Shared rules for every "set a password" form in the project.
 *
 * @return array<string,string> field => message (empty when the password is fine)
 */
function password_errors(string $password, string $confirm, string $current = null): array
{
    $errors = [];

    if (mb_strlen($password) < MIN_PASSWORD_LENGTH) {
        $errors['new_password'] = 'Password must be at least ' . MIN_PASSWORD_LENGTH
                                . ' characters long.';
    } elseif (preg_match('/^\s|\s$/', $password)) {
        $errors['new_password'] = 'Password cannot start or end with a space.';
    }

    if ($password !== $confirm) {
        $errors['confirm_password'] = 'The two passwords do not match.';
    }

    if ($current !== null && $password !== '' && hash_equals($current, $password)) {
        $errors['new_password'] = 'The new password must be different from the current one.';
    }

    return $errors;
}

/**
 * Change the password of one account after checking the current one.
 * Works for students, faculty and admins - only the table name differs.
 *
 * @return array<string,string> field => message (empty on success)
 */
function change_account_password(string $table, int $id, string $current,
                                 string $new, string $confirm): array
{
    $allowed = ['students', 'faculty', 'admins'];
    if (!in_array($table, $allowed, true)) {
        return ['current_password' => 'Unknown account type.'];
    }

    $st = db()->prepare("SELECT password_hash FROM `$table` WHERE id = ?");
    $st->execute([$id]);
    $hash = $st->fetchColumn();

    if ($hash === false) {
        return ['current_password' => 'Account not found.'];
    }
    if ($current === '' || !password_verify($current, (string)$hash)) {
        return ['current_password' => 'Your current password is not correct.'];
    }

    $errors = password_errors($new, $confirm, $current);
    if ($errors) {
        return $errors;
    }

    $upd = db()->prepare("UPDATE `$table`
                             SET password_hash = ?, password_changed_at = ?
                           WHERE id = ?");
    $upd->execute([password_hash($new, PASSWORD_DEFAULT), date('Y-m-d H:i:s'), $id]);

    return [];
}

/** Initials for the avatar circle on a profile card: "Anita Verma" -> "AV". */
function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $parts = array_values(array_filter($parts));
    if (!$parts) {
        return '?';
    }
    $first = mb_substr($parts[0], 0, 1);
    $last  = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '';
    return mb_strtoupper($first . $last);
}

/* ------------------------------------------------------------------ */
/*  Formatting                                                         */
/* ------------------------------------------------------------------ */

/** 1845 -> "30:45" */
function format_seconds(int $seconds): string
{
    $seconds = max(0, $seconds);
    return sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60);
}

/** "2026-08-13 10:24:00" -> "13 Aug 2026, 10:24 AM" */
function format_datetime(?string $dt): string
{
    if (!$dt) {
        return '-';
    }
    $ts = strtotime($dt);
    return $ts ? date('d M Y, h:i A', $ts) : '-';
}

/** Trim trailing zeros: 2.00 -> "2", 0.25 -> "0.25" */
function num(float $n): string
{
    return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.') ?: '0';
}

/* ------------------------------------------------------------------ */
/*  Exam data helpers                                                  */
/* ------------------------------------------------------------------ */

/** The SELECT fragment that decorates an exam row with its derived counts. */
function exam_select_sql(): string
{
    return 'e.*,
            (SELECT b.name FROM branches b WHERE b.id = e.target_branch_id) AS target_branch_name,
            (SELECT COUNT(*) FROM questions q WHERE q.exam_id = e.id) AS question_count,
            (SELECT COALESCE(SUM(q.marks),0) FROM questions q WHERE q.exam_id = e.id) AS total_marks,
            (SELECT COUNT(*) FROM questions q WHERE q.exam_id = e.id AND q.type = \'subjective\')
              AS subjective_count,
            (SELECT COALESCE(SUM(q.marks),0) FROM questions q
              WHERE q.exam_id = e.id AND q.type = \'subjective\') AS subjective_total,
            (SELECT COUNT(*) FROM exam_assignments a WHERE a.exam_id = e.id) AS assigned_count';
}

/** Fetch one exam plus its question count and total marks. */
function get_exam(int $examId): ?array
{
    $stmt = db()->prepare('SELECT ' . exam_select_sql() . ' FROM exams e WHERE e.id = ?');
    $stmt->execute([$examId]);
    $exam = $stmt->fetch();
    return $exam ?: null;
}

/**
 * All exams, optionally only the active ones and only for one college.
 * Admin screens always pass the signed in college so tenants stay separate.
 */
function get_exams(bool $activeOnly = true, ?int $collegeId = null): array
{
    $where  = [];
    $params = [];
    if ($activeOnly)  { $where[] = 'e.is_active = 1'; }
    if ($collegeId)   { $where[] = 'e.college_id = ?'; $params[] = $collegeId; }

    $sql = 'SELECT ' . exam_select_sql() . ' FROM exams e';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $st = db()->prepare($sql . ' ORDER BY e.id');
    $st->execute($params);
    return $st->fetchAll();
}

/** Every exam created by one faculty member. */
function get_faculty_exams(int $facultyId): array
{
    $st = db()->prepare('SELECT ' . exam_select_sql() . '
                           FROM exams e WHERE e.faculty_id = ? ORDER BY e.id DESC');
    $st->execute([$facultyId]);
    return $st->fetchAll();
}

/**
 * The exams a student is allowed to see and attempt.
 *
 * A student only ever sees the papers that have been allotted to them. An exam
 * nobody assigned them is invisible everywhere: dashboard, instructions and
 * start_exam all go through this same rule.
 */
function get_student_exams(int $studentId): array
{
    // The college check is belt and braces on top of the allotment: a paper
    // from another college can never appear even if a stray row existed.
    $st = db()->prepare(
        'SELECT ' . exam_select_sql() . ', f.name AS faculty_name,
                asg.assigned_at
           FROM exams e
           JOIN exam_assignments asg ON asg.exam_id = e.id AND asg.student_id = ?
           JOIN students s ON s.id = asg.student_id
      LEFT JOIN faculty f ON f.id = e.faculty_id
          WHERE e.is_active = 1
            AND (e.college_id IS NULL OR s.college_id IS NULL OR e.college_id = s.college_id)
       ORDER BY e.subject, e.title'
    );
    $st->execute([$studentId]);
    return $st->fetchAll();
}

/* ------------------------------------------------------------------ */
/*  Students of a college, filtered by branch / year / semester        */
/* ------------------------------------------------------------------ */

/**
 * The roster a faculty member or administrator works with.
 *
 * @param array{branch_id?:int,study_year?:int,semester?:int,q?:string} $filters
 */
function get_college_students(?int $collegeId, array $filters = [], string $extraSelect = '',
                              array $extraParams = []): array
{
    $sql = 'SELECT s.*, b.name AS branch_name, b.code AS branch_code' . $extraSelect . '
              FROM students s
         LEFT JOIN branches b ON b.id = s.branch_id';

    $where  = [];
    $params = $extraParams;

    if ($collegeId) {
        $where[] = 's.college_id = ?';
        $params[] = $collegeId;
    }
    if (!empty($filters['branch_id'])) {
        $where[] = 's.branch_id = ?';
        $params[] = (int)$filters['branch_id'];
    }
    if (!empty($filters['study_year'])) {
        $where[] = 's.study_year = ?';
        $params[] = (int)$filters['study_year'];
    }
    if (!empty($filters['semester'])) {
        $where[] = 's.semester = ?';
        $params[] = (int)$filters['semester'];
    }
    if (!empty($filters['q'])) {
        $where[] = '(s.name LIKE ? OR s.email LIKE ? OR s.enrollment_no LIKE ?)';
        $like = '%' . $filters['q'] . '%';
        array_push($params, $like, $like, $like);
    }
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY b.name, s.study_year, s.semester, s.name';

    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/** Read branch / year / semester filters out of the query string. */
function class_filters_from_get(): array
{
    return [
        'branch_id'  => get_int('branch_id'),
        'study_year' => get_int('study_year'),
        'semester'   => get_int('semester'),
        'q'          => trim($_GET['q'] ?? ''),
    ];
}

/**
 * The same list grouped by subject, which is how the dashboard shows it:
 * one section per subject the student has been allotted.
 *
 * @return array<string, array<int, array<string,mixed>>>
 */
function get_student_exams_by_subject(int $studentId): array
{
    $grouped = [];
    foreach (get_student_exams($studentId) as $exam) {
        $grouped[$exam['subject']][] = $exam;
    }
    return $grouped;
}

/**
 * True when this student has been allotted this exam.
 *
 * This is the single authority for "can this student sit this paper" - the
 * dashboard, the instructions page and start_exam.php all call it, so there is
 * no route to a paper that was never allotted.
 */
function student_can_attempt(array $exam, int $studentId): bool
{
    if (!(int)$exam['is_active']) {
        return false;
    }
    $st = db()->prepare('SELECT 1 FROM exam_assignments WHERE exam_id = ? AND student_id = ?');
    $st->execute([$exam['id'], $studentId]);
    return (bool)$st->fetchColumn();
}

/**
 * Questions of an exam *without* the is_correct flag.
 * Used while an exam is running so the answer key never reaches the browser.
 */
function get_exam_questions(int $examId): array
{
    $qs = db()->prepare('SELECT id, question_text, type, marks, max_words, sort_order
                           FROM questions WHERE exam_id = ? ORDER BY sort_order, id');
    $qs->execute([$examId]);
    $questions = $qs->fetchAll();
    if (!$questions) {
        return [];
    }

    $ids = array_column($questions, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $os  = db()->prepare("SELECT id, question_id, option_text
                            FROM options WHERE question_id IN ($in)
                           ORDER BY option_order, id");
    $os->execute($ids);

    $byQuestion = [];
    foreach ($os->fetchAll() as $opt) {
        $byQuestion[$opt['question_id']][] = $opt;
    }
    foreach ($questions as &$q) {
        $q['options'] = $byQuestion[$q['id']] ?? [];
    }
    return $questions;
}

/* ------------------------------------------------------------------ */
/*  Scoring engine (server side only)                                  */
/* ------------------------------------------------------------------ */

/**
 * Grade an attempt, write the results row and close the attempt.
 *
 * Runs inside a transaction and relies on the UNIQUE key on
 * results.attempt_id, so a second concurrent submission can never
 * create a second result. Returns the results row.
 *
 * @param string $status 'submitted' or 'auto_submitted'
 */
function grade_attempt(int $attemptId, string $status = 'submitted'): array
{
    $pdo = db();

    // Already graded? Return the existing result - submissions are final.
    $existing = $pdo->prepare('SELECT * FROM results WHERE attempt_id = ?');
    $existing->execute([$attemptId]);
    if ($row = $existing->fetch()) {
        return $row;
    }

    $pdo->beginTransaction();
    try {
        // Lock the attempt row so two parallel submits serialise here.
        $st = $pdo->prepare('SELECT * FROM attempts WHERE id = ? FOR UPDATE');
        $st->execute([$attemptId]);
        $attempt = $st->fetch();
        if (!$attempt) {
            throw new RuntimeException('Attempt not found.');
        }

        // Another request graded it while we waited for the lock.
        $again = $pdo->prepare('SELECT * FROM results WHERE attempt_id = ?');
        $again->execute([$attemptId]);
        if ($row = $again->fetch()) {
            $pdo->commit();
            return $row;
        }

        $exam = get_exam((int)$attempt['exam_id']);
        $negative = (float)$exam['negative_marks'];

        // Pull every question with the student's answer joined in.
        $qs = $pdo->prepare(
            'SELECT q.id, q.marks, q.type,
                    a.id AS answer_id, a.option_id, a.answer_text,
                    o.is_correct
               FROM questions q
          LEFT JOIN answers a ON a.question_id = q.id AND a.attempt_id = ?
          LEFT JOIN options o ON o.id = a.option_id
              WHERE q.exam_id = ?'
        );
        $qs->execute([$attemptId, $attempt['exam_id']]);
        $rows = $qs->fetchAll();

        $total = count($rows);
        $correct = $wrong = $unanswered = 0;
        $objective = 0.0;
        $totalMarks = 0.0;
        $subjectiveTotal = 0.0;
        $awaiting = 0;                       // written answers still to be marked

        $markAnswer = $pdo->prepare(
            'UPDATE answers SET is_correct = ?, awarded_marks = ? WHERE id = ?'
        );

        foreach ($rows as $r) {
            $marks = (float)$r['marks'];
            $totalMarks += $marks;

            // ---------- Subjective: only the faculty can mark it ----------
            if ($r['type'] === 'subjective') {
                $subjectiveTotal += $marks;
                if (trim((string)$r['answer_text']) === '') {
                    $unanswered++;
                    if ($r['answer_id'] !== null) {
                        $markAnswer->execute([null, 0, $r['answer_id']]);
                    }
                } else {
                    $awaiting++;             // awarded_marks stays NULL until evaluated
                }
                continue;
            }

            // ---------- Objective: marked automatically ----------
            if ($r['option_id'] === null) {
                $unanswered++;
                if ($r['answer_id'] !== null) {
                    $markAnswer->execute([null, 0, $r['answer_id']]);
                }
            } elseif ((int)$r['is_correct'] === 1) {
                $correct++;
                $objective += $marks;
                $markAnswer->execute([1, $marks, $r['answer_id']]);
            } else {
                $wrong++;
                $objective -= $negative;
                $markAnswer->execute([0, -$negative, $r['answer_id']]);
            }
        }

        $objective  = round($objective, 2);
        $attempted  = $total - $unanswered;
        $obtained   = max(0, round($objective, 2));      // subjective adds to this later
        $percentage = $totalMarks > 0 ? round($obtained / $totalMarks * 100, 2) : 0.0;
        $passed     = $obtained >= (float)$exam['passing_marks'] ? 'PASS' : 'FAIL';

        $ins = $pdo->prepare(
            'INSERT INTO results
               (attempt_id, student_id, exam_id, total_questions, attempted,
                correct_count, wrong_count, unanswered_count, total_marks,
                obtained_marks, objective_marks, subjective_marks, subjective_total,
                pending_evaluation, percentage, status)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $ins->execute([
            $attemptId, $attempt['student_id'], $attempt['exam_id'], $total, $attempted,
            $correct, $wrong, $unanswered, $totalMarks, $obtained, $objective, 0,
            $subjectiveTotal, $awaiting > 0 ? 1 : 0, $percentage, $passed,
        ]);

        $close = $pdo->prepare(
            'UPDATE attempts SET status = ?, submitted_at = ?, evaluation_status = ?
              WHERE id = ?'
        );
        $close->execute([$status, date('Y-m-d H:i:s'),
                         $awaiting > 0 ? 'pending' : 'not_required', $attemptId]);

        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }

    $final = $pdo->prepare('SELECT * FROM results WHERE attempt_id = ?');
    $final->execute([$attemptId]);
    return $final->fetch();
}

/**
 * Recompute a result after the faculty has marked its written answers.
 *
 * Objective marks never change here - only the subjective part is folded in,
 * and the pass/fail verdict is recomputed from the new total.
 */
function recalc_result(int $attemptId): ?array
{
    $pdo = db();

    $st = $pdo->prepare('SELECT * FROM results WHERE attempt_id = ?');
    $st->execute([$attemptId]);
    $result = $st->fetch();
    if (!$result) {
        return null;
    }

    $exam = get_exam((int)$result['exam_id']);

    // Subjective marks awarded so far, and how many are still unmarked.
    $st = $pdo->prepare(
        "SELECT COALESCE(SUM(a.awarded_marks),0) AS awarded,
                SUM(CASE WHEN a.awarded_marks IS NULL
                          AND TRIM(COALESCE(a.answer_text,'')) <> '' THEN 1 ELSE 0 END) AS awaiting
           FROM answers a
           JOIN questions q ON q.id = a.question_id
          WHERE a.attempt_id = ? AND q.type = 'subjective'"
    );
    $st->execute([$attemptId]);
    $row = $st->fetch();

    $subjective = round((float)$row['awarded'], 2);
    $awaiting   = (int)$row['awaiting'];

    $obtained   = max(0, round((float)$result['objective_marks'] + $subjective, 2));
    $totalMarks = (float)$result['total_marks'];
    $percentage = $totalMarks > 0 ? round($obtained / $totalMarks * 100, 2) : 0.0;
    $passed     = $obtained >= (float)$exam['passing_marks'] ? 'PASS' : 'FAIL';

    $upd = $pdo->prepare(
        'UPDATE results
            SET subjective_marks = ?, obtained_marks = ?, percentage = ?,
                status = ?, pending_evaluation = ?
          WHERE attempt_id = ?'
    );
    $upd->execute([$subjective, $obtained, $percentage, $passed,
                   $awaiting > 0 ? 1 : 0, $attemptId]);

    $pdo->prepare('UPDATE attempts SET evaluation_status = ? WHERE id = ?')
        ->execute([$awaiting > 0 ? 'pending' : 'completed', $attemptId]);

    $st = $pdo->prepare('SELECT * FROM results WHERE attempt_id = ?');
    $st->execute([$attemptId]);
    return $st->fetch() ?: null;
}

/* ------------------------------------------------------------------ */
/*  Exam progress (used by the faculty dashboard)                      */
/* ------------------------------------------------------------------ */

/**
 * How far an exam has progressed through its assigned students.
 *
 * Returns assigned / completed / in progress / not started counts plus two
 * flags: all_completed (every assigned student has submitted) and
 * results_visible (that, or the faculty released the results early).
 */
function exam_progress(array $exam): array
{
    $pdo    = db();
    $examId = (int)$exam['id'];

    // The allotted roster is the denominator - "all students" means everyone
    // this paper was given to.
    $assigned = (int)$exam['assigned_count'];

    $st = $pdo->prepare(
        "SELECT COUNT(DISTINCT a.student_id) FROM attempts a
           JOIN exam_assignments x ON x.exam_id = a.exam_id AND x.student_id = a.student_id
          WHERE a.exam_id = ? AND a.status <> 'in_progress'"
    );
    $st->execute([$examId]);
    $completed = (int)$st->fetchColumn();

    $st = $pdo->prepare(
        "SELECT COUNT(DISTINCT a.student_id) FROM attempts a
           JOIN exam_assignments x ON x.exam_id = a.exam_id AND x.student_id = a.student_id
          WHERE a.exam_id = ? AND a.status = 'in_progress'"
    );
    $st->execute([$examId]);
    $running = (int)$st->fetchColumn();

    $st = $pdo->prepare('SELECT COUNT(*) FROM results
                          WHERE exam_id = ? AND pending_evaluation = 1');
    $st->execute([$examId]);
    $pending = (int)$st->fetchColumn();

    $allDone = $assigned > 0 && $completed >= $assigned;

    return [
        'assigned'        => $assigned,
        'completed'       => $completed,
        'in_progress'     => $running,
        'not_started'     => max(0, $assigned - $completed - $running),
        'pending_eval'    => $pending,
        'all_completed'   => $allDone,
        'percent'         => $assigned > 0 ? round($completed / $assigned * 100) : 0,
        'results_visible' => $allDone || (int)($exam['results_released'] ?? 0) === 1,
    ];
}

/* ------------------------------------------------------------------ */
/*  Faculty analytics (used by the administrator's oversight screens)  */
/* ------------------------------------------------------------------ */

/**
 * Everything the administrator needs to judge one faculty member's work:
 * what they set, how much of it, how they marked it and how their students
 * ended up doing.
 *
 * @return array<string,mixed>
 */
function faculty_analytics(int $facultyId): array
{
    $pdo = db();

    /* ---------------- What they set ---------------- */
    $st = $pdo->prepare(
        "SELECT COUNT(*) AS papers,
                SUM(e.is_active) AS active_papers,
                (SELECT COUNT(*) FROM questions q JOIN exams x ON x.id = q.exam_id
                  WHERE x.faculty_id = :f2) AS questions,
                (SELECT COUNT(*) FROM questions q JOIN exams x ON x.id = q.exam_id
                  WHERE x.faculty_id = :f3 AND q.type = 'subjective') AS subjective_questions,
                (SELECT COALESCE(SUM(q.marks),0) FROM questions q JOIN exams x ON x.id = q.exam_id
                  WHERE x.faculty_id = :f4) AS total_marks,
                (SELECT COUNT(DISTINCT a.student_id) FROM exam_assignments a
                   JOIN exams x ON x.id = a.exam_id WHERE x.faculty_id = :f5) AS students
           FROM exams e WHERE e.faculty_id = :f1"
    );
    $st->execute(['f1' => $facultyId, 'f2' => $facultyId, 'f3' => $facultyId,
                  'f4' => $facultyId, 'f5' => $facultyId]);
    $set = $st->fetch() ?: [];

    /* ---------------- Difficulty / type mix ---------------- */
    $st = $pdo->prepare(
        "SELECT q.difficulty, q.type, COUNT(*) AS n
           FROM questions q JOIN exams e ON e.id = q.exam_id
          WHERE e.faculty_id = ?
       GROUP BY q.difficulty, q.type"
    );
    $st->execute([$facultyId]);
    $mix = ['easy' => 0, 'medium' => 0, 'hard' => 0,
            'mcq' => 0, 'truefalse' => 0, 'subjective' => 0];
    foreach ($st->fetchAll() as $row) {
        $mix[$row['difficulty']] = ($mix[$row['difficulty']] ?? 0) + (int)$row['n'];
        $mix[$row['type']]       = ($mix[$row['type']] ?? 0) + (int)$row['n'];
    }

    /* ---------------- How they marked written answers ---------------- */
    $st = $pdo->prepare(
        "SELECT COUNT(*) AS evaluated,
                COALESCE(SUM(a.awarded_marks),0) AS awarded,
                COALESCE(SUM(q.marks),0) AS possible,
                SUM(CASE WHEN a.awarded_marks >= q.marks THEN 1 ELSE 0 END) AS full_marks,
                SUM(CASE WHEN a.awarded_marks = 0 THEN 1 ELSE 0 END) AS zero_marks,
                SUM(CASE WHEN TRIM(COALESCE(a.feedback,'')) <> '' THEN 1 ELSE 0 END) AS with_feedback,
                AVG(TIMESTAMPDIFF(HOUR, t.submitted_at, a.evaluated_at)) AS avg_turnaround
           FROM answers a
           JOIN questions q ON q.id = a.question_id
           JOIN attempts t  ON t.id = a.attempt_id
           JOIN exams e     ON e.id = t.exam_id
          WHERE e.faculty_id = ? AND q.type = 'subjective' AND a.evaluated_at IS NOT NULL"
    );
    $st->execute([$facultyId]);
    $eval = $st->fetch() ?: [];

    $st = $pdo->prepare(
        'SELECT COUNT(*) AS pending_papers,
                MIN(t.submitted_at) AS oldest_pending
           FROM results r JOIN attempts t ON t.id = r.attempt_id
           JOIN exams e ON e.id = r.exam_id
          WHERE e.faculty_id = ? AND r.pending_evaluation = 1'
    );
    $st->execute([$facultyId]);
    $pending = $st->fetch() ?: [];

    /* ---------------- How their students did ---------------- */
    $st = $pdo->prepare(
        'SELECT COUNT(*) AS submissions,
                COALESCE(AVG(r.percentage),0) AS avg_pct,
                SUM(CASE WHEN r.status = "PASS" THEN 1 ELSE 0 END) AS passed
           FROM results r JOIN exams e ON e.id = r.exam_id
          WHERE e.faculty_id = ?'
    );
    $st->execute([$facultyId]);
    $out = $st->fetch() ?: [];

    $evaluated = (int)($eval['evaluated'] ?? 0);
    $possible  = (float)($eval['possible'] ?? 0);
    $subs      = (int)($out['submissions'] ?? 0);

    return [
        'papers'              => (int)($set['papers'] ?? 0),
        'active_papers'       => (int)($set['active_papers'] ?? 0),
        'questions'           => (int)($set['questions'] ?? 0),
        'subjective_questions'=> (int)($set['subjective_questions'] ?? 0),
        'total_marks'         => (float)($set['total_marks'] ?? 0),
        'students'            => (int)($set['students'] ?? 0),
        'mix'                 => $mix,

        'evaluated'           => $evaluated,
        'pending_papers'      => (int)($pending['pending_papers'] ?? 0),
        'oldest_pending'      => $pending['oldest_pending'] ?? null,
        // Share of the available subjective marks actually awarded: a low
        // number means a strict marker, a very high one a lenient marker.
        'award_ratio'         => $possible > 0
                                 ? round((float)$eval['awarded'] / $possible * 100, 1) : null,
        'full_marks_pct'      => $evaluated > 0
                                 ? round((int)$eval['full_marks'] / $evaluated * 100, 1) : null,
        'zero_marks_pct'      => $evaluated > 0
                                 ? round((int)$eval['zero_marks'] / $evaluated * 100, 1) : null,
        'feedback_pct'        => $evaluated > 0
                                 ? round((int)$eval['with_feedback'] / $evaluated * 100, 1) : null,
        'avg_turnaround'      => $eval['avg_turnaround'] !== null
                                 ? round((float)$eval['avg_turnaround'], 1) : null,

        'submissions'         => $subs,
        'avg_pct'             => round((float)($out['avg_pct'] ?? 0), 2),
        'pass_rate'           => $subs > 0
                                 ? round((int)$out['passed'] / $subs * 100, 1) : null,
    ];
}

/**
 * Turn the raw evaluation numbers into short, readable observations for the
 * administrator - the things worth a second look.
 *
 * @return array<int,array{0:string,1:string}> [tone, message]
 */
function faculty_observations(array $a): array
{
    $notes = [];

    if ($a['pending_papers'] > 0) {
        $age = $a['oldest_pending']
             ? floor((time() - strtotime($a['oldest_pending'])) / 86400) : 0;
        $notes[] = [$age >= 3 ? 'danger' : 'warning',
            $a['pending_papers'] . ' paper(s) still unmarked'
            . ($age > 0 ? ", oldest waiting $age day(s)" : '')];
    }

    if ($a['award_ratio'] !== null) {
        if ($a['award_ratio'] >= 85) {
            $notes[] = ['warning', 'Marks written answers leniently - awards '
                                 . $a['award_ratio'] . '% of the available marks'];
        } elseif ($a['award_ratio'] <= 40) {
            $notes[] = ['warning', 'Marks written answers strictly - awards only '
                                 . $a['award_ratio'] . '% of the available marks'];
        } else {
            $notes[] = ['success', 'Balanced marking - awards ' . $a['award_ratio']
                                 . '% of the available marks'];
        }
    }

    if ($a['feedback_pct'] !== null) {
        if ($a['feedback_pct'] < 30) {
            $notes[] = ['warning', 'Gives written feedback on only '
                                 . $a['feedback_pct'] . '% of answers'];
        } elseif ($a['feedback_pct'] >= 80) {
            $notes[] = ['success', 'Gives feedback on ' . $a['feedback_pct'] . '% of answers'];
        }
    }

    if ($a['avg_turnaround'] !== null && $a['avg_turnaround'] > 72) {
        $notes[] = ['warning', 'Takes about ' . round($a['avg_turnaround'] / 24, 1)
                             . ' days to mark a paper'];
    }

    if ($a['pass_rate'] !== null && $a['submissions'] >= 3) {
        if ($a['pass_rate'] >= 95) {
            $notes[] = ['warning', 'Pass rate of ' . $a['pass_rate']
                                 . '% - the papers may be too easy'];
        } elseif ($a['pass_rate'] <= 30) {
            $notes[] = ['danger', 'Pass rate of only ' . $a['pass_rate']
                                 . '% - the papers may be too hard'];
        }
    }

    if ($a['papers'] > 0 && $a['students'] === 0) {
        $notes[] = ['warning', 'Has set papers but allotted them to nobody'];
    }
    if ($a['papers'] === 0) {
        $notes[] = ['muted', 'Has not set any paper yet'];
    }

    return $notes;
}

/**
 * Seconds remaining in an attempt, based purely on the stored expires_at.
 * Refreshing the browser cannot change this value.
 */
function seconds_left(array $attempt): int
{
    return max(0, strtotime($attempt['expires_at']) - time());
}

/**
 * Auto submit any of this student's attempts whose deadline has passed.
 * Called on dashboard/exam load so an abandoned exam still gets a result.
 */
function close_expired_attempts(int $studentId): void
{
    $st = db()->prepare(
        "SELECT id FROM attempts
          WHERE student_id = ? AND status = 'in_progress' AND expires_at <= ?"
    );
    $st->execute([$studentId, date('Y-m-d H:i:s')]);
    foreach ($st->fetchAll() as $row) {
        grade_attempt((int)$row['id'], 'auto_submitted');
    }
}
