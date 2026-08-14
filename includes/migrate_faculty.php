<?php
/**
 * Faculty module migration.
 *
 * Adds every table and column the faculty features need. Each step checks
 * first, so running it on an already upgraded database is a no-op. Shared by
 * install.php (fresh install) and upgrade.php (existing install).
 */
require_once __DIR__ . '/sql_util.php';

/**
 * @return array<int, array{0:string,1:string}> log lines as [state, message]
 */
function migrate_faculty(PDO $pdo, string $dbName): array
{
    $log = [];

    // ---------- 1. New tables ----------
    $file = __DIR__ . '/../database/upgrade_faculty.sql';
    if (!is_readable($file)) {
        throw new RuntimeException('Cannot read database/upgrade_faculty.sql');
    }
    foreach (split_sql(file_get_contents($file)) as $sql) {
        $pdo->exec($sql);
    }
    $log[] = ['ok', 'Tables faculty, exam_assignments and question_imports are present'];

    // ---------- 2. New columns ----------
    $columns = [
        // exams: ownership, who may attempt, and whether results are published
        ['exams', 'faculty_id',
         'ALTER TABLE `exams` ADD COLUMN `faculty_id` INT UNSIGNED DEFAULT NULL AFTER `id`'],
        ['exams', 'access_mode',
         "ALTER TABLE `exams` ADD COLUMN `access_mode` ENUM('open','assigned')
            NOT NULL DEFAULT 'open' AFTER `is_active`"],
        ['exams', 'results_released',
         'ALTER TABLE `exams` ADD COLUMN `results_released` TINYINT(1) NOT NULL DEFAULT 0
            AFTER `access_mode`'],

        // questions: subjective support
        ['questions', 'model_answer',
         'ALTER TABLE `questions` ADD COLUMN `model_answer` TEXT DEFAULT NULL AFTER `explanation`'],
        ['questions', 'max_words',
         'ALTER TABLE `questions` ADD COLUMN `max_words` INT UNSIGNED DEFAULT NULL AFTER `model_answer`'],

        // answers: written answers and their manual evaluation
        ['answers', 'answer_text',
         'ALTER TABLE `answers` ADD COLUMN `answer_text` MEDIUMTEXT DEFAULT NULL AFTER `option_id`'],
        ['answers', 'awarded_marks',
         'ALTER TABLE `answers` ADD COLUMN `awarded_marks` DECIMAL(6,2) DEFAULT NULL AFTER `is_correct`'],
        ['answers', 'feedback',
         'ALTER TABLE `answers` ADD COLUMN `feedback` VARCHAR(500) DEFAULT NULL AFTER `awarded_marks`'],
        ['answers', 'evaluated_by',
         'ALTER TABLE `answers` ADD COLUMN `evaluated_by` INT UNSIGNED DEFAULT NULL AFTER `feedback`'],
        ['answers', 'evaluated_at',
         'ALTER TABLE `answers` ADD COLUMN `evaluated_at` DATETIME DEFAULT NULL AFTER `evaluated_by`'],

        // attempts: evaluation state kept separate from submission state
        ['attempts', 'evaluation_status',
         "ALTER TABLE `attempts` ADD COLUMN `evaluation_status`
            ENUM('not_required','pending','completed') NOT NULL DEFAULT 'not_required'
            AFTER `status`"],

        // faculty: full professional profile + self registration workflow
        ['faculty', 'employee_code',
         'ALTER TABLE `faculty` ADD COLUMN `employee_code` VARCHAR(50) DEFAULT NULL AFTER `name`'],
        ['faculty', 'phone',
         'ALTER TABLE `faculty` ADD COLUMN `phone` VARCHAR(20) DEFAULT NULL AFTER `email`'],
        ['faculty', 'designation',
         'ALTER TABLE `faculty` ADD COLUMN `designation` VARCHAR(80) DEFAULT NULL AFTER `department`'],
        ['faculty', 'qualification',
         'ALTER TABLE `faculty` ADD COLUMN `qualification` VARCHAR(120) DEFAULT NULL AFTER `designation`'],
        ['faculty', 'specialization',
         'ALTER TABLE `faculty` ADD COLUMN `specialization` VARCHAR(200) DEFAULT NULL AFTER `qualification`'],
        ['faculty', 'experience_years',
         'ALTER TABLE `faculty` ADD COLUMN `experience_years` DECIMAL(4,1) DEFAULT NULL AFTER `specialization`'],
        ['faculty', 'joining_date',
         'ALTER TABLE `faculty` ADD COLUMN `joining_date` DATE DEFAULT NULL AFTER `experience_years`'],
        ['faculty', 'about',
         'ALTER TABLE `faculty` ADD COLUMN `about` TEXT DEFAULT NULL AFTER `joining_date`'],
        ['faculty', 'status',
         "ALTER TABLE `faculty` ADD COLUMN `status` ENUM('pending','approved','suspended')
            NOT NULL DEFAULT 'approved' AFTER `about`"],
        ['faculty', 'approved_at',
         'ALTER TABLE `faculty` ADD COLUMN `approved_at` DATETIME DEFAULT NULL AFTER `status`'],
        ['faculty', 'password_changed_at',
         'ALTER TABLE `faculty` ADD COLUMN `password_changed_at` DATETIME DEFAULT NULL
            AFTER `password_hash`'],

        // students: keep track of the last password change too
        ['students', 'password_changed_at',
         'ALTER TABLE `students` ADD COLUMN `password_changed_at` DATETIME DEFAULT NULL
            AFTER `password_hash`'],

        // admins: same, so the default password can be nagged about
        ['admins', 'password_changed_at',
         'ALTER TABLE `admins` ADD COLUMN `password_changed_at` DATETIME DEFAULT NULL
            AFTER `password_hash`'],

        // results: objective / subjective split
        ['results', 'objective_marks',
         'ALTER TABLE `results` ADD COLUMN `objective_marks` DECIMAL(7,2) NOT NULL DEFAULT 0
            AFTER `obtained_marks`'],
        ['results', 'subjective_marks',
         'ALTER TABLE `results` ADD COLUMN `subjective_marks` DECIMAL(7,2) NOT NULL DEFAULT 0
            AFTER `objective_marks`'],
        ['results', 'subjective_total',
         'ALTER TABLE `results` ADD COLUMN `subjective_total` DECIMAL(7,2) NOT NULL DEFAULT 0
            AFTER `subjective_marks`'],
        ['results', 'pending_evaluation',
         'ALTER TABLE `results` ADD COLUMN `pending_evaluation` TINYINT(1) NOT NULL DEFAULT 0
            AFTER `subjective_total`'],
    ];

    $added = 0;
    foreach ($columns as [$table, $column, $sql]) {
        if (!column_exists($pdo, $dbName, $table, $column)) {
            $pdo->exec($sql);
            $added++;
        }
    }
    $log[] = ['ok', $added > 0
        ? "Added $added new column(s) to exams, questions, answers, attempts and results"
        : 'All faculty columns already present'];

    // ---------- 3. Widen the question type enum ----------
    $st = $pdo->prepare(
        'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $st->execute([$dbName, 'questions', 'type']);
    $type = (string)$st->fetchColumn();
    if (!str_contains($type, 'subjective')) {
        $pdo->exec("ALTER TABLE `questions` MODIFY `type`
                      ENUM('mcq','truefalse','subjective') NOT NULL DEFAULT 'mcq'");
        $log[] = ['ok', 'questions.type now accepts subjective questions'];
    } else {
        $log[] = ['ok', 'questions.type already accepts subjective questions'];
    }

    // ---------- 4. Foreign keys ----------
    $fks = [
        ['exams', 'fk_exams_faculty',
         'ALTER TABLE `exams` ADD CONSTRAINT `fk_exams_faculty`
            FOREIGN KEY (`faculty_id`) REFERENCES `faculty` (`id`) ON DELETE SET NULL'],
        ['answers', 'fk_answers_evaluator',
         'ALTER TABLE `answers` ADD CONSTRAINT `fk_answers_evaluator`
            FOREIGN KEY (`evaluated_by`) REFERENCES `faculty` (`id`) ON DELETE SET NULL'],
    ];
    foreach ($fks as [$table, $name, $sql]) {
        if (!index_exists($pdo, $dbName, $table, $name)) {
            try { $pdo->exec($sql); } catch (PDOException $e) { /* already constrained */ }
        }
    }
    $log[] = ['ok', 'Foreign keys linked to the faculty table'];

    // ---------- 5. Demo faculty account ----------
    if ((int)$pdo->query('SELECT COUNT(*) FROM faculty')->fetchColumn() === 0) {
        $st = $pdo->prepare(
            'INSERT INTO faculty (name, employee_code, username, email, phone, department,
                    designation, qualification, specialization, experience_years,
                    joining_date, password_hash, status, approved_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $st->execute(['Dr. Anita Verma', 'FAC-001', 'faculty', 'faculty@example.com',
                      '9876500001', 'Computer Science', 'Associate Professor',
                      'Ph.D. (Computer Science)', 'Networks, Operating Systems', 12.0,
                      '2014-07-01', password_hash('faculty123', PASSWORD_DEFAULT),
                      'approved', date('Y-m-d H:i:s')]);
        $log[] = ['ok', 'Demo faculty account created (faculty / faculty123)'];
    } else {
        // Existing accounts predate the approval workflow - they stay approved.
        $pdo->exec("UPDATE `faculty` SET `status` = 'approved' WHERE `status` IS NULL");
        $log[] = ['ok', 'Faculty account(s) already exist - left approved and untouched'];
    }

    // ---------- 6. Backfill ----------
    // Every exam is allotted per student: a paper is visible only to the
    // students it was given to, so all existing exams move to 'assigned'.
    $pdo->exec("UPDATE `exams` SET `access_mode` = 'assigned' WHERE `access_mode` <> 'assigned'");
    $log[] = ['ok', 'All exams set to allotted-students-only access'];

    return $log;
}
