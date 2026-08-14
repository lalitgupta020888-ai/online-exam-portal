-- =====================================================================
--  Online Exam Portal - College (multi tenant) upgrade
--
--  Every account, paper and result now belongs to a college. A college is
--  created when somebody registers as its administrator, and the faculty and
--  students of that college register under it, branch and year/semester wise.
--
--  Applied by /upgrade.php - each step checks first, so it is safe to re-run.
-- =====================================================================

USE `online_exam`;

-- ---------------------------------------------------------------------
-- Colleges - one row per institution using the portal
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `colleges` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(150) NOT NULL,
  `code`       VARCHAR(30)  NOT NULL,
  `logo`       VARCHAR(255) DEFAULT NULL,     -- file name inside uploads/logos/
  `email`      VARCHAR(150) DEFAULT NULL,
  `phone`      VARCHAR(20)  DEFAULT NULL,
  `website`    VARCHAR(150) DEFAULT NULL,
  `address`    VARCHAR(255) DEFAULT NULL,
  `city`       VARCHAR(80)  DEFAULT NULL,
  `state`      VARCHAR(80)  DEFAULT NULL,
  `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_college_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Branches / departments offered by a college
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `branches` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `college_id` INT UNSIGNED NOT NULL,
  `name`       VARCHAR(120) NOT NULL,
  `code`       VARCHAR(20)  NOT NULL,
  `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_branch_code` (`college_id`,`code`),
  KEY `idx_branch_college` (`college_id`),
  CONSTRAINT `fk_branch_college` FOREIGN KEY (`college_id`)
    REFERENCES `colleges` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
