<?php
/**
 * Assignments.
 *
 * A faculty member writes the assignment questions and sends them to a class
 * (branch / year / semester - any of them may be "all"). Every active student
 * of the college in that class sees it and submits a typed answer, a PDF of
 * their handwritten pages, or both. The faculty member then awards marks and
 * feedback. A student can re-submit until the work has been graded.
 */

define('ASSIGNMENT_DIR', __DIR__ . '/../uploads/assignments');
define('ASSIGNMENT_PDF_MAX_BYTES', 8 * 1024 * 1024);

/** Create the two assignment tables on first use. */
function ensure_assignment_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    db()->exec('CREATE TABLE IF NOT EXISTS `assignments` (
        `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `college_id`  INT UNSIGNED NOT NULL,
        `faculty_id`  INT UNSIGNED NOT NULL,
        `title`       VARCHAR(200) NOT NULL,
        `subject`     VARCHAR(120) NOT NULL,
        `questions`   MEDIUMTEXT NOT NULL,
        `branch_id`   INT UNSIGNED DEFAULT NULL,
        `study_year`  TINYINT UNSIGNED DEFAULT NULL,
        `semester`    TINYINT UNSIGNED DEFAULT NULL,
        `due_at`      DATETIME NOT NULL,
        `max_marks`   DECIMAL(6,2) NOT NULL DEFAULT 10,
        `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
        `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_assignment_college` (`college_id`, `is_active`),
        KEY `idx_assignment_faculty` (`faculty_id`),
        CONSTRAINT `fk_assignment_faculty` FOREIGN KEY (`faculty_id`)
          REFERENCES `faculty` (`id`) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    db()->exec('CREATE TABLE IF NOT EXISTS `assignment_submissions` (
        `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `assignment_id` INT UNSIGNED NOT NULL,
        `student_id`    INT UNSIGNED NOT NULL,
        `answer_text`   MEDIUMTEXT DEFAULT NULL,
        `pdf_file`      VARCHAR(80) DEFAULT NULL,
        `pdf_name`      VARCHAR(200) DEFAULT NULL,
        `submitted_at`  DATETIME NOT NULL,
        `is_late`       TINYINT(1) NOT NULL DEFAULT 0,
        `marks`         DECIMAL(6,2) DEFAULT NULL,
        `feedback`      TEXT DEFAULT NULL,
        `graded_at`     DATETIME DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_submission` (`assignment_id`, `student_id`),
        KEY `idx_submission_student` (`student_id`),
        CONSTRAINT `fk_submission_assignment` FOREIGN KEY (`assignment_id`)
          REFERENCES `assignments` (`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_submission_student` FOREIGN KEY (`student_id`)
          REFERENCES `students` (`id`) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $done = true;
}

/** SQL that is true when student alias `s` belongs to the class of assignment alias `a`. */
function assignment_match_sql(): string
{
    return 's.college_id = a.college_id AND s.is_active = 1
        AND (a.branch_id IS NULL OR a.branch_id = s.branch_id)
        AND (a.study_year IS NULL OR a.study_year = s.study_year)
        AND (a.semester IS NULL OR a.semester = s.semester)';
}

/** "CSE · 2nd Year · Sem 3" - who an assignment was sent to. */
function assignment_audience_label(array $a): string
{
    $bits = [$a['branch_id'] ? branch_name((int)$a['branch_id']) : 'All branches'];
    if ($a['study_year']) { $bits[] = year_label((int)$a['study_year']); }
    if ($a['semester'])   { $bits[] = 'Sem ' . (int)$a['semester']; }
    return implode(' · ', $bits);
}

/** A faculty member's assignments with submitted / graded / class-size counts. */
function get_faculty_assignments(int $facultyId): array
{
    ensure_assignment_schema();
    $st = db()->prepare('SELECT a.*,
            (SELECT COUNT(*) FROM students s WHERE ' . assignment_match_sql() . ') AS student_count,
            (SELECT COUNT(*) FROM assignment_submissions x WHERE x.assignment_id = a.id) AS submitted,
            (SELECT COUNT(*) FROM assignment_submissions x
              WHERE x.assignment_id = a.id AND x.graded_at IS NOT NULL) AS graded
          FROM assignments a WHERE a.faculty_id = ? ORDER BY a.due_at DESC, a.id DESC');
    $st->execute([$facultyId]);
    return $st->fetchAll();
}

/** One of this faculty member's assignments, or null. */
function get_owned_assignment(int $id, int $facultyId): ?array
{
    ensure_assignment_schema();
    $st = db()->prepare('SELECT * FROM assignments WHERE id = ? AND faculty_id = ?');
    $st->execute([$id, $facultyId]);
    return $st->fetch() ?: null;
}

/**
 * Every student in the assignment's class with their submission (null columns
 * when they have not submitted).
 */
function assignment_roster(array $assignment): array
{
    $st = db()->prepare('SELECT s.id, s.name, s.enrollment_no, s.study_year, s.semester,
                                b.code AS branch_code,
                                x.id AS submission_id, x.answer_text, x.pdf_file, x.pdf_name,
                                x.submitted_at, x.is_late, x.marks, x.feedback, x.graded_at
                           FROM assignments a
                           JOIN students s ON ' . assignment_match_sql() . '
                      LEFT JOIN branches b ON b.id = s.branch_id
                      LEFT JOIN assignment_submissions x
                             ON x.assignment_id = a.id AND x.student_id = s.id
                          WHERE a.id = ?
                       ORDER BY x.submitted_at IS NULL, s.name');
    $st->execute([$assignment['id']]);
    return $st->fetchAll();
}

/** Active assignments sent to this student's class, with their submission. */
function get_student_assignments(array $student): array
{
    ensure_assignment_schema();
    $st = db()->prepare('SELECT a.*, f.name AS faculty_name,
                                x.id AS submission_id, x.submitted_at, x.is_late, x.marks, x.graded_at
                           FROM students s
                           JOIN assignments a ON a.is_active = 1 AND ' . assignment_match_sql() . '
                           JOIN faculty f ON f.id = a.faculty_id
                      LEFT JOIN assignment_submissions x
                             ON x.assignment_id = a.id AND x.student_id = s.id
                          WHERE s.id = ?
                       ORDER BY x.id IS NOT NULL, a.due_at');
    $st->execute([$student['id']]);
    return $st->fetchAll();
}

/** One active assignment this student may see, with faculty name, or null. */
function get_student_assignment(int $id, array $student): ?array
{
    ensure_assignment_schema();
    $st = db()->prepare('SELECT a.*, f.name AS faculty_name
                           FROM students s
                           JOIN assignments a ON a.is_active = 1 AND ' . assignment_match_sql() . '
                           JOIN faculty f ON f.id = a.faculty_id
                          WHERE s.id = ? AND a.id = ?');
    $st->execute([$student['id'], $id]);
    return $st->fetch() ?: null;
}

/** A student's submission for an assignment, or null. */
function get_submission(int $assignmentId, int $studentId): ?array
{
    $st = db()->prepare('SELECT * FROM assignment_submissions WHERE assignment_id = ? AND student_id = ?');
    $st->execute([$assignmentId, $studentId]);
    return $st->fetch() ?: null;
}

/**
 * Validate an uploaded PDF and move it into ASSIGNMENT_DIR under a random name.
 *
 * @return array{0:?string,1:string} [stored file name, error message]
 */
function store_assignment_pdf(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [null, ''];
    }
    if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
        || ($file['error'] === UPLOAD_ERR_OK && $file['size'] > ASSIGNMENT_PDF_MAX_BYTES)) {
        return [null, 'The PDF must be ' . (ASSIGNMENT_PDF_MAX_BYTES >> 20) . ' MB or smaller.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        return [null, 'The PDF could not be uploaded. Please try again.'];
    }
    // Trust the file header, not the sent name or MIME type.
    $fh = fopen($file['tmp_name'], 'rb');
    $head = $fh ? fread($fh, 5) : '';
    if ($fh) { fclose($fh); }
    if ($head !== '%PDF-') {
        return [null, 'That file is not a PDF. Scan or save your handwritten pages as a PDF.'];
    }
    if (!is_dir(ASSIGNMENT_DIR) && !@mkdir(ASSIGNMENT_DIR, 0777, true)) {
        return [null, 'The server cannot write to uploads/assignments.'];
    }
    // Student work is private: only assignment_file.php may hand it out.
    if (!is_file(ASSIGNMENT_DIR . '/.htaccess')) {
        @file_put_contents(ASSIGNMENT_DIR . '/.htaccess',
            "<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
"
          . "<IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
</IfModule>
");
    }
    $name = 'asg_' . bin2hex(random_bytes(12)) . '.pdf';
    if (!move_uploaded_file($file['tmp_name'], ASSIGNMENT_DIR . '/' . $name)) {
        return [null, 'The PDF could not be saved.'];
    }
    return [$name, ''];
}

/** Remove a stored PDF (ignores names that are not ours). */
function delete_assignment_pdf(?string $name): void
{
    if ($name && preg_match('/^asg_[0-9a-f]{24}\.pdf$/', $name)) {
        @unlink(ASSIGNMENT_DIR . '/' . $name);
    }
}

/** "Due in 3 days" / "Overdue by 2 hours" style label. */
function due_label(string $dueAt): string
{
    $diff = strtotime($dueAt) - time();
    $abs  = abs($diff);
    $span = $abs >= 86400 ? floor($abs / 86400) . ' day' . ($abs >= 172800 ? 's' : '')
          : ($abs >= 3600 ? floor($abs / 3600) . ' hour' . ($abs >= 7200 ? 's' : '')
          : max(1, floor($abs / 60)) . ' min');
    return $diff >= 0 ? "Due in $span" : "Overdue by $span";
}
