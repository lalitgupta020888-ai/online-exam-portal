-- =====================================================================
--  Online Exam Portal - Faculty module upgrade
--
--  Adds: faculty accounts, exam ownership, per student exam assignment,
--        subjective questions and manual evaluation.
--
--  Applied by /upgrade.php, which skips any step that is already present,
--  so it is safe to run more than once.
-- =====================================================================

USE `online_exam`;

-- ---------------------------------------------------------------------
-- Faculty accounts
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `faculty` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`          VARCHAR(100) NOT NULL,
  `username`      VARCHAR(50)  NOT NULL,
  `email`         VARCHAR(150) NOT NULL,
  `department`    VARCHAR(100) DEFAULT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_faculty_username` (`username`),
  UNIQUE KEY `uq_faculty_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Which students are allowed to attempt which exam.
--
-- exams.access_mode decides how this table is used:
--   'open'     -> any logged in student may attempt (the original behaviour)
--   'assigned' -> ONLY the students listed here may attempt
-- Exams created by a faculty member default to 'assigned'.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `exam_assignments` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `exam_id`     INT UNSIGNED NOT NULL,
  `student_id`  INT UNSIGNED NOT NULL,
  `assigned_by` INT UNSIGNED DEFAULT NULL,     -- faculty.id
  `assigned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_assignment` (`exam_id`,`student_id`),
  KEY `idx_assignment_student` (`student_id`),
  CONSTRAINT `fk_assign_exam` FOREIGN KEY (`exam_id`)
    REFERENCES `exams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_assign_student` FOREIGN KEY (`student_id`)
    REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_assign_faculty` FOREIGN KEY (`assigned_by`)
    REFERENCES `faculty` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Import batches - lets a faculty member undo a bad PDF import.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `question_imports` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `exam_id`     INT UNSIGNED NOT NULL,
  `faculty_id`  INT UNSIGNED DEFAULT NULL,
  `source_name` VARCHAR(255) DEFAULT NULL,
  `source_type` VARCHAR(20)  DEFAULT NULL,     -- pdf | docx | txt | typed
  `imported`    INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_import_exam` (`exam_id`),
  CONSTRAINT `fk_import_exam` FOREIGN KEY (`exam_id`)
    REFERENCES `exams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_import_faculty` FOREIGN KEY (`faculty_id`)
    REFERENCES `faculty` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
