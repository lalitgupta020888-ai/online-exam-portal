<?php
/**
 * College (multi tenant) migration.
 *
 * Adds the colleges and branches tables, links every account, paper and result
 * to a college, and records the branch / year / semester of each student.
 * Every step checks first, so running it twice changes nothing.
 */
require_once __DIR__ . '/sql_util.php';

/**
 * @return array<int, array{0:string,1:string}> log lines as [state, message]
 */
function migrate_college(PDO $pdo, string $dbName): array
{
    $log = [];

    /* ---------------- 1. New tables ---------------- */
    foreach (sql_file_statements(__DIR__ . '/../database/upgrade_college.sql') as $sql) {
        $pdo->exec($sql);
    }
    $log[] = ['ok', 'Tables colleges and branches are present'];

    /* ---------------- 2. New columns ---------------- */
    $columns = [
        // Who each account belongs to
        ['admins',   'college_id', 'ALTER TABLE `admins` ADD COLUMN `college_id` INT UNSIGNED
                                     DEFAULT NULL AFTER `id`'],
        ['admins',   'email',      'ALTER TABLE `admins` ADD COLUMN `email` VARCHAR(150)
                                     DEFAULT NULL AFTER `name`'],
        ['admins',   'phone',      'ALTER TABLE `admins` ADD COLUMN `phone` VARCHAR(20)
                                     DEFAULT NULL AFTER `email`'],
        ['admins',   'designation','ALTER TABLE `admins` ADD COLUMN `designation` VARCHAR(80)
                                     DEFAULT NULL AFTER `phone`'],

        ['faculty',  'college_id', 'ALTER TABLE `faculty` ADD COLUMN `college_id` INT UNSIGNED
                                     DEFAULT NULL AFTER `id`'],
        ['faculty',  'branch_id',  'ALTER TABLE `faculty` ADD COLUMN `branch_id` INT UNSIGNED
                                     DEFAULT NULL AFTER `department`'],

        ['students', 'college_id',    'ALTER TABLE `students` ADD COLUMN `college_id` INT UNSIGNED
                                        DEFAULT NULL AFTER `id`'],
        ['students', 'branch_id',     'ALTER TABLE `students` ADD COLUMN `branch_id` INT UNSIGNED
                                        DEFAULT NULL AFTER `phone`'],
        ['students', 'study_year',    'ALTER TABLE `students` ADD COLUMN `study_year` TINYINT UNSIGNED
                                        DEFAULT NULL AFTER `branch_id`'],
        ['students', 'semester',      'ALTER TABLE `students` ADD COLUMN `semester` TINYINT UNSIGNED
                                        DEFAULT NULL AFTER `study_year`'],
        ['students', 'enrollment_no', 'ALTER TABLE `students` ADD COLUMN `enrollment_no` VARCHAR(40)
                                        DEFAULT NULL AFTER `semester`'],
        ['students', 'admission_year','ALTER TABLE `students` ADD COLUMN `admission_year` SMALLINT UNSIGNED
                                        DEFAULT NULL AFTER `enrollment_no`'],

        // Which audience a paper is meant for
        ['exams', 'college_id',       'ALTER TABLE `exams` ADD COLUMN `college_id` INT UNSIGNED
                                        DEFAULT NULL AFTER `id`'],
        ['exams', 'target_branch_id', 'ALTER TABLE `exams` ADD COLUMN `target_branch_id` INT UNSIGNED
                                        DEFAULT NULL AFTER `subject`'],
        ['exams', 'target_year',      'ALTER TABLE `exams` ADD COLUMN `target_year` TINYINT UNSIGNED
                                        DEFAULT NULL AFTER `target_branch_id`'],
        ['exams', 'target_semester',  'ALTER TABLE `exams` ADD COLUMN `target_semester` TINYINT UNSIGNED
                                        DEFAULT NULL AFTER `target_year`'],
    ];

    $added = 0;
    foreach ($columns as [$table, $column, $sql]) {
        if (!column_exists($pdo, $dbName, $table, $column)) {
            $pdo->exec($sql);
            $added++;
        }
    }
    $log[] = ['ok', $added > 0
        ? "Added $added college / branch column(s) to admins, faculty, students and exams"
        : 'All college columns already present'];

    /* ---------------- 3. A college for the data that already exists ---------------- */
    $collegeId = (int)$pdo->query('SELECT id FROM colleges ORDER BY id LIMIT 1')->fetchColumn();

    if (!$collegeId) {
        $st = $pdo->prepare(
            'INSERT INTO colleges (name, code, email, city, state)
             VALUES (?,?,?,?,?)'
        );
        $st->execute(['Demo Institute of Technology', 'DEMO',
                      'office@demo-institute.edu', 'Noida', 'Uttar Pradesh']);
        $collegeId = (int)$pdo->lastInsertId();
        $log[] = ['ok', 'Created the default college for the existing data'];
    } else {
        $log[] = ['ok', 'College record already present'];
    }

    /* ---------------- 4. Default branches ---------------- */
    $st = $pdo->prepare('SELECT COUNT(*) FROM branches WHERE college_id = ?');
    $st->execute([$collegeId]);
    if ((int)$st->fetchColumn() === 0) {
        $ins = $pdo->prepare('INSERT INTO branches (college_id, name, code) VALUES (?,?,?)');
        foreach (default_branches() as [$name, $code]) {
            $ins->execute([$collegeId, $name, $code]);
        }
        $log[] = ['ok', count(default_branches()) . ' default branches created'];
    } else {
        $log[] = ['ok', 'Branches already defined for this college'];
    }

    /* ---------------- 5. Backfill ---------------- */
    foreach (['admins', 'faculty', 'students', 'exams'] as $table) {
        $pdo->prepare("UPDATE `$table` SET college_id = ? WHERE college_id IS NULL")
            ->execute([$collegeId]);
    }
    $log[] = ['ok', 'Existing admins, faculty, students and papers linked to that college'];

    /* ---------------- 6. Foreign keys ---------------- */
    $fks = [
        ['admins',   'fk_admins_college',   'ALTER TABLE `admins` ADD CONSTRAINT `fk_admins_college`
            FOREIGN KEY (`college_id`) REFERENCES `colleges` (`id`) ON DELETE CASCADE'],
        ['faculty',  'fk_faculty_college',  'ALTER TABLE `faculty` ADD CONSTRAINT `fk_faculty_college`
            FOREIGN KEY (`college_id`) REFERENCES `colleges` (`id`) ON DELETE CASCADE'],
        ['faculty',  'fk_faculty_branch',   'ALTER TABLE `faculty` ADD CONSTRAINT `fk_faculty_branch`
            FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL'],
        ['students', 'fk_students_college', 'ALTER TABLE `students` ADD CONSTRAINT `fk_students_college`
            FOREIGN KEY (`college_id`) REFERENCES `colleges` (`id`) ON DELETE CASCADE'],
        ['students', 'fk_students_branch',  'ALTER TABLE `students` ADD CONSTRAINT `fk_students_branch`
            FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL'],
        ['exams',    'fk_exams_college',    'ALTER TABLE `exams` ADD CONSTRAINT `fk_exams_college`
            FOREIGN KEY (`college_id`) REFERENCES `colleges` (`id`) ON DELETE CASCADE'],
        ['exams',    'fk_exams_branch',     'ALTER TABLE `exams` ADD CONSTRAINT `fk_exams_branch`
            FOREIGN KEY (`target_branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL'],
    ];
    foreach ($fks as [$table, $name, $sql]) {
        if (!index_exists($pdo, $dbName, $table, $name)) {
            try { $pdo->exec($sql); } catch (PDOException $e) { /* already constrained */ }
        }
    }
    $log[] = ['ok', 'Foreign keys linked to colleges and branches'];

    /* ---------------- 7. Upload folder ---------------- */
    $dir = __DIR__ . '/../uploads/logos';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $log[] = ['ok', is_dir($dir)
        ? 'Logo upload folder ready (uploads/logos)'
        : 'Could not create uploads/logos - create it by hand and allow writing'];

    return $log;
}

/** The branch list every new college starts with; the admin can edit it. */
function default_branches(): array
{
    return [
        ['Computer Science & Engineering', 'CSE'],
        ['Information Technology',         'IT'],
        ['Electronics & Communication',    'ECE'],
        ['Electrical Engineering',         'EE'],
        ['Mechanical Engineering',         'ME'],
        ['Civil Engineering',              'CE'],
        ['Bachelor of Computer Application','BCA'],
        ['Master of Computer Application', 'MCA'],
        ['Bachelor of Business Administration', 'BBA'],
    ];
}
