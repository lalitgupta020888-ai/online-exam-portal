-- =====================================================================
--  Online Exam Portal - Database Schema
--  MySQL / MariaDB (XAMPP)
--
--  This file creates the database, all tables and the demo exam data.
--  User accounts (admin + demo student) are created by /install.php
--  because their passwords must be hashed by PHP (password_hash()).
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `online_exam`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `online_exam`;

-- Drop in reverse dependency order so the script can be re-run safely.
DROP TABLE IF EXISTS `results`;
DROP TABLE IF EXISTS `answers`;
DROP TABLE IF EXISTS `attempts`;
DROP TABLE IF EXISTS `options`;
DROP TABLE IF EXISTS `questions`;
DROP TABLE IF EXISTS `exams`;
DROP TABLE IF EXISTS `admins`;
DROP TABLE IF EXISTS `students`;

-- ---------------------------------------------------------------------
-- Students
-- ---------------------------------------------------------------------
CREATE TABLE `students` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`          VARCHAR(100) NOT NULL,
  `email`         VARCHAR(150) NOT NULL,
  `phone`         VARCHAR(20)  DEFAULT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `reset_token`   VARCHAR(64)  DEFAULT NULL,
  `reset_expires` DATETIME     DEFAULT NULL,
  `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_students_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Administrators
-- ---------------------------------------------------------------------
CREATE TABLE `admins` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(50)  NOT NULL,
  `name`          VARCHAR(100) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admins_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Exams
-- ---------------------------------------------------------------------
CREATE TABLE `exams` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`            VARCHAR(150) NOT NULL,
  `subject`          VARCHAR(100) NOT NULL,
  `description`      TEXT         DEFAULT NULL,
  `duration_minutes` INT UNSIGNED NOT NULL DEFAULT 30,
  `marks_per_question` DECIMAL(5,2) NOT NULL DEFAULT 1.00,
  `negative_marks`   DECIMAL(5,2) NOT NULL DEFAULT 0.00, -- deducted per wrong answer
  `passing_marks`    DECIMAL(6,2) NOT NULL DEFAULT 0.00,
  `is_active`        TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_exams_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Questions (belong to one exam)
-- ---------------------------------------------------------------------
CREATE TABLE `questions` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `exam_id`       INT UNSIGNED NOT NULL,
  `question_text` TEXT NOT NULL,
  `type`          ENUM('mcq','truefalse') NOT NULL DEFAULT 'mcq',
  `category`      VARCHAR(80) DEFAULT NULL,
  `difficulty`    ENUM('easy','medium','hard') NOT NULL DEFAULT 'easy',
  `marks`         DECIMAL(5,2) NOT NULL DEFAULT 1.00,
  `explanation`   TEXT DEFAULT NULL,
  `sort_order`    INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_questions_exam` (`exam_id`),
  CONSTRAINT `fk_questions_exam` FOREIGN KEY (`exam_id`)
    REFERENCES `exams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Options (answer choices). `is_correct` never leaves the server
-- while an exam is in progress.
-- ---------------------------------------------------------------------
CREATE TABLE `options` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `question_id`  INT UNSIGNED NOT NULL,
  `option_text`  VARCHAR(500) NOT NULL,
  `is_correct`   TINYINT(1) NOT NULL DEFAULT 0,
  `option_order` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_options_question` (`question_id`),
  CONSTRAINT `fk_options_question` FOREIGN KEY (`question_id`)
    REFERENCES `questions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Exam attempts. `expires_at` is the server-side authority for the
-- countdown timer - refreshing the page cannot extend it.
-- ---------------------------------------------------------------------
CREATE TABLE `attempts` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `student_id`   INT UNSIGNED NOT NULL,
  `exam_id`      INT UNSIGNED NOT NULL,
  `started_at`   DATETIME NOT NULL,
  `expires_at`   DATETIME NOT NULL,
  `submitted_at` DATETIME DEFAULT NULL,
  `status`       ENUM('in_progress','submitted','auto_submitted')
                 NOT NULL DEFAULT 'in_progress',
  PRIMARY KEY (`id`),
  KEY `idx_attempts_student` (`student_id`),
  KEY `idx_attempts_exam` (`exam_id`),
  KEY `idx_attempts_status` (`status`),
  CONSTRAINT `fk_attempts_student` FOREIGN KEY (`student_id`)
    REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_attempts_exam` FOREIGN KEY (`exam_id`)
    REFERENCES `exams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Student answers - one row per (attempt, question)
-- ---------------------------------------------------------------------
CREATE TABLE `answers` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `attempt_id`        INT UNSIGNED NOT NULL,
  `question_id`       INT UNSIGNED NOT NULL,
  `option_id`         INT UNSIGNED DEFAULT NULL,   -- NULL = not answered
  `marked_for_review` TINYINT(1) NOT NULL DEFAULT 0,
  `visited`           TINYINT(1) NOT NULL DEFAULT 0,
  `is_correct`        TINYINT(1) DEFAULT NULL,     -- filled at submission
  `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                      ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_answers_attempt_question` (`attempt_id`,`question_id`),
  KEY `idx_answers_question` (`question_id`),
  CONSTRAINT `fk_answers_attempt` FOREIGN KEY (`attempt_id`)
    REFERENCES `attempts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_answers_question` FOREIGN KEY (`question_id`)
    REFERENCES `questions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_answers_option` FOREIGN KEY (`option_id`)
    REFERENCES `options` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Results - one immutable row written when an attempt is submitted.
-- The UNIQUE key on attempt_id is what makes double submission
-- impossible even under concurrent requests.
-- ---------------------------------------------------------------------
CREATE TABLE `results` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `attempt_id`       INT UNSIGNED NOT NULL,
  `student_id`       INT UNSIGNED NOT NULL,
  `exam_id`          INT UNSIGNED NOT NULL,
  `total_questions`  INT UNSIGNED NOT NULL,
  `attempted`        INT UNSIGNED NOT NULL,
  `correct_count`    INT UNSIGNED NOT NULL,
  `wrong_count`      INT UNSIGNED NOT NULL,
  `unanswered_count` INT UNSIGNED NOT NULL,
  `total_marks`      DECIMAL(7,2) NOT NULL,
  `obtained_marks`   DECIMAL(7,2) NOT NULL,
  `percentage`       DECIMAL(5,2) NOT NULL,
  `status`           ENUM('PASS','FAIL') NOT NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_results_attempt` (`attempt_id`),
  KEY `idx_results_student` (`student_id`),
  KEY `idx_results_exam` (`exam_id`),
  CONSTRAINT `fk_results_attempt` FOREIGN KEY (`attempt_id`)
    REFERENCES `attempts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_results_student` FOREIGN KEY (`student_id`)
    REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_results_exam` FOREIGN KEY (`exam_id`)
    REFERENCES `exams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  DEMO DATA
-- =====================================================================

INSERT INTO `exams`
  (`id`,`title`,`subject`,`description`,`duration_minutes`,
   `marks_per_question`,`negative_marks`,`passing_marks`,`is_active`) VALUES
 (1,'Web Development Fundamentals','Computer Science',
  'Covers HTML, CSS and basic JavaScript concepts taught in the first semester.',
  30, 1.00, 0.25, 8.00, 1),
 (2,'Computer Awareness','General Knowledge',
  'Basic computer literacy - hardware, software, networking and the internet.',
  15, 2.00, 0.00, 10.00, 1),
 (3,'Quantitative Aptitude & Reasoning','Mathematics',
  'Arithmetic, percentages and logical reasoning. Mixed MCQ and True/False.',
  20, 2.00, 0.50, 12.00, 1);

-- ---------------- Exam 1: Web Development Fundamentals (20 Q) ---------
INSERT INTO `questions`
  (`id`,`exam_id`,`question_text`,`type`,`category`,`difficulty`,`marks`,`explanation`,`sort_order`) VALUES
 (1,1,'What is the full form of HTML?','mcq','HTML','easy',1.00,'HTML stands for Hyper Text Markup Language - the standard markup language for documents designed to be displayed in a browser.',1),
 (2,1,'Which HTML tag is used to create a hyperlink?','mcq','HTML','easy',1.00,'The anchor tag <a> with an href attribute creates a hyperlink.',2),
 (3,1,'What does CSS stand for?','mcq','CSS','easy',1.00,'CSS stands for Cascading Style Sheets.',3),
 (4,1,'Which CSS property changes the text colour of an element?','mcq','CSS','easy',1.00,'The "color" property sets the foreground (text) colour. "background-color" sets the background.',4),
 (5,1,'Which HTML element is used for the largest heading?','mcq','HTML','easy',1.00,'<h1> is the highest level heading; <h6> is the lowest.',5),
 (6,1,'How do you write a single line comment in JavaScript?','mcq','JavaScript','easy',1.00,'Two forward slashes begin a single line comment in JavaScript.',6),
 (7,1,'Which attribute supplies alternative text for an image?','mcq','HTML','easy',1.00,'The alt attribute provides alternative text for screen readers and when the image fails to load.',7),
 (8,1,'Which of these is a valid JavaScript variable declaration keyword?','mcq','JavaScript','easy',1.00,'let, const and var are the declaration keywords. "int" belongs to languages such as C and Java.',8),
 (9,1,'What is the correct HTML element for inserting a line break?','mcq','HTML','easy',1.00,'<br> inserts a single line break.',9),
 (10,1,'Which CSS property controls the space inside an element border?','mcq','CSS','medium',1.00,'Padding is the space inside the border; margin is the space outside it.',10),
 (11,1,'Which symbol is used to select an element by id in CSS?','mcq','CSS','easy',1.00,'The hash symbol (#) selects by id; the dot (.) selects by class.',11),
 (12,1,'Which method writes output to the browser console?','mcq','JavaScript','easy',1.00,'console.log() prints a message to the developer console.',12),
 (13,1,'What does the DOM stand for?','mcq','JavaScript','medium',1.00,'The Document Object Model is the tree representation of a page that JavaScript can manipulate.',13),
 (14,1,'Which HTML tag is used to define an unordered list?','mcq','HTML','easy',1.00,'<ul> defines an unordered (bulleted) list; <ol> defines an ordered list.',14),
 (15,1,'Which value of the CSS display property hides an element completely?','mcq','CSS','medium',1.00,'display:none removes the element from the layout entirely, unlike visibility:hidden which keeps its space.',15),
 (16,1,'In JavaScript, which operator checks value AND type?','mcq','JavaScript','medium',1.00,'The strict equality operator === compares both value and type without coercion.',16),
 (17,1,'Which HTML5 element represents navigation links?','mcq','HTML','medium',1.00,'<nav> is the semantic HTML5 element for a block of navigation links.',17),
 (18,1,'Which CSS layout module is designed for one dimensional layouts?','mcq','CSS','medium',1.00,'Flexbox is designed for one dimensional layouts (a row OR a column). Grid handles two dimensions.',18),
 (19,1,'What is the default value of the CSS position property?','mcq','CSS','hard',1.00,'The default is "static" - the element follows the normal document flow.',19),
 (20,1,'Which JavaScript method converts a JSON string into an object?','mcq','JavaScript','medium',1.00,'JSON.parse() converts a JSON string to an object; JSON.stringify() does the reverse.',20);

INSERT INTO `options` (`question_id`,`option_text`,`is_correct`,`option_order`) VALUES
 (1,'Hyper Text Markup Language',1,1),(1,'High Text Machine Language',0,2),(1,'Hyperlink Text Management Language',0,3),(1,'High-level Text Markup Language',0,4),
 (2,'<link>',0,1),(2,'<a>',1,2),(2,'<href>',0,3),(2,'<hyper>',0,4),
 (3,'Cascading Style Sheets',1,1),(3,'Creative Style System',0,2),(3,'Computer Styled Sections',0,3),(3,'Colourful Style Sheets',0,4),
 (4,'background-color',0,1),(4,'font-style',0,2),(4,'color',1,3),(4,'text-decoration',0,4),
 (5,'<h6>',0,1),(5,'<heading>',0,2),(5,'<head>',0,3),(5,'<h1>',1,4),
 (6,'// comment',1,1),(6,'<!-- comment -->',0,2),(6,'# comment',0,3),(6,'** comment **',0,4),
 (7,'title',0,1),(7,'alt',1,2),(7,'src',0,3),(7,'caption',0,4),
 (8,'int',0,1),(8,'let',1,2),(8,'define',0,3),(8,'string',0,4),
 (9,'<lb>',0,1),(9,'<break>',0,2),(9,'<br>',1,3),(9,'<newline>',0,4),
 (10,'margin',0,1),(10,'border',0,2),(10,'padding',1,3),(10,'outline',0,4),
 (11,'.',0,1),(11,'#',1,2),(11,'*',0,3),(11,'@',0,4),
 (12,'print()',0,1),(12,'echo()',0,2),(12,'console.log()',1,3),(12,'document.print()',0,4),
 (13,'Document Object Model',1,1),(13,'Data Object Method',0,2),(13,'Display Output Mode',0,3),(13,'Document Oriented Markup',0,4),
 (14,'<ol>',0,1),(14,'<ul>',1,2),(14,'<li>',0,3),(14,'<list>',0,4),
 (15,'visibility: hidden',0,1),(15,'opacity: 0',0,2),(15,'display: none',1,3),(15,'overflow: hidden',0,4),
 (16,'==',0,1),(16,'===',1,2),(16,'=',0,3),(16,'!=',0,4),
 (17,'<menu>',0,1),(17,'<navigate>',0,2),(17,'<nav>',1,3),(17,'<section>',0,4),
 (18,'Grid',0,1),(18,'Flexbox',1,2),(18,'Float',0,3),(18,'Table',0,4),
 (19,'relative',0,1),(19,'absolute',0,2),(19,'static',1,3),(19,'fixed',0,4),
 (20,'JSON.stringify()',0,1),(20,'JSON.parse()',1,2),(20,'JSON.toObject()',0,3),(20,'JSON.convert()',0,4);

-- ---------------- Exam 2: Computer Awareness (10 Q) -------------------
INSERT INTO `questions`
  (`id`,`exam_id`,`question_text`,`type`,`category`,`difficulty`,`marks`,`explanation`,`sort_order`) VALUES
 (21,2,'Which of the following is an input device?','mcq','Hardware','easy',2.00,'A keyboard sends data into the computer, so it is an input device.',1),
 (22,2,'What does CPU stand for?','mcq','Hardware','easy',2.00,'CPU stands for Central Processing Unit, the primary processor of a computer.',2),
 (23,2,'Which memory is volatile?','mcq','Hardware','easy',2.00,'RAM loses its contents when power is switched off, so it is volatile memory.',3),
 (24,2,'1 Kilobyte is equal to how many bytes?','mcq','Fundamentals','easy',2.00,'1 KB = 1024 bytes.',4),
 (25,2,'Which of these is an operating system?','mcq','Software','easy',2.00,'Linux is an operating system; the others are application programs.',5),
 (26,2,'What does WWW stand for?','mcq','Internet','easy',2.00,'WWW stands for World Wide Web.',6),
 (27,2,'Which protocol is used to send email?','mcq','Internet','medium',2.00,'SMTP (Simple Mail Transfer Protocol) is used to send email.',7),
 (28,2,'Which shortcut key is used to copy the selected text?','mcq','Software','easy',2.00,'Ctrl + C copies the selection to the clipboard.',8),
 (29,2,'A byte consists of how many bits?','mcq','Fundamentals','easy',2.00,'One byte is made of 8 bits.',9),
 (30,2,'Which company develops the Windows operating system?','mcq','General','easy',2.00,'Microsoft develops and maintains Windows.',10);

INSERT INTO `options` (`question_id`,`option_text`,`is_correct`,`option_order`) VALUES
 (21,'Monitor',0,1),(21,'Printer',0,2),(21,'Keyboard',1,3),(21,'Speaker',0,4),
 (22,'Central Processing Unit',1,1),(22,'Computer Personal Unit',0,2),(22,'Central Program Utility',0,3),(22,'Control Processing Unit',0,4),
 (23,'ROM',0,1),(23,'RAM',1,2),(23,'Hard Disk',0,3),(23,'SSD',0,4),
 (24,'1000',0,1),(24,'1024',1,2),(24,'512',0,3),(24,'2048',0,4),
 (25,'MS Word',0,1),(25,'Photoshop',0,2),(25,'Linux',1,3),(25,'Chrome',0,4),
 (26,'World Wide Web',1,1),(26,'Web Wide World',0,2),(26,'Wide World Web',0,3),(26,'World Web Wide',0,4),
 (27,'HTTP',0,1),(27,'FTP',0,2),(27,'SMTP',1,3),(27,'SNMP',0,4),
 (28,'Ctrl + V',0,1),(28,'Ctrl + C',1,2),(28,'Ctrl + X',0,3),(28,'Ctrl + P',0,4),
 (29,'4',0,1),(29,'8',1,2),(29,'16',0,3),(29,'32',0,4),
 (30,'Apple',0,1),(30,'Google',0,2),(30,'Microsoft',1,3),(30,'IBM',0,4);

-- ---------------- Exam 3: Aptitude & Reasoning (10 Q, mixed) ---------
INSERT INTO `questions`
  (`id`,`exam_id`,`question_text`,`type`,`category`,`difficulty`,`marks`,`explanation`,`sort_order`) VALUES
 (31,3,'What is 25% of 480?','mcq','Percentage','easy',2.00,'25% of 480 = 480 / 4 = 120.',1),
 (32,3,'If a book costs 250 and is sold for 300, what is the profit percentage?','mcq','Profit & Loss','medium',2.00,'Profit = 50. Profit% = (50/250) x 100 = 20%.',2),
 (33,3,'Find the next number in the series: 2, 6, 12, 20, 30, ?','mcq','Series','medium',2.00,'Differences are 4, 6, 8, 10, so the next difference is 12 giving 42.',3),
 (34,3,'The average of 10, 20, 30, 40 and 50 is 30.','truefalse','Average','easy',2.00,'Sum = 150 and 150 / 5 = 30, so the statement is true.',4),
 (35,3,'A train travels 180 km in 3 hours. What is its speed?','mcq','Speed','easy',2.00,'Speed = distance / time = 180 / 3 = 60 km/h.',5),
 (36,3,'Every prime number is an odd number.','truefalse','Numbers','easy',2.00,'False - 2 is prime and even.',6),
 (37,3,'What is the value of 15 x 12?','mcq','Arithmetic','easy',2.00,'15 x 12 = 180.',7),
 (38,3,'If TODAY is coded as UPEBZ, how is COLD coded?','mcq','Coding','hard',2.00,'Each letter moves one step forward, so COLD becomes DPME.',8),
 (39,3,'The square root of 169 is 13.','truefalse','Numbers','easy',2.00,'13 x 13 = 169, so the statement is true.',9),
 (40,3,'A shop gives two successive discounts of 10% and 20%. What is the single equivalent discount?','mcq','Discount','hard',2.00,'Remaining price = 0.9 x 0.8 = 0.72, so the total discount is 28%.',10);

INSERT INTO `options` (`question_id`,`option_text`,`is_correct`,`option_order`) VALUES
 (31,'100',0,1),(31,'110',0,2),(31,'120',1,3),(31,'125',0,4),
 (32,'15%',0,1),(32,'20%',1,2),(32,'25%',0,3),(32,'30%',0,4),
 (33,'36',0,1),(33,'40',0,2),(33,'42',1,3),(33,'44',0,4),
 (34,'True',1,1),(34,'False',0,2),
 (35,'50 km/h',0,1),(35,'60 km/h',1,2),(35,'70 km/h',0,3),(35,'90 km/h',0,4),
 (36,'True',0,1),(36,'False',1,2),
 (37,'150',0,1),(37,'165',0,2),(37,'180',1,3),(37,'195',0,4),
 (38,'DPME',1,1),(38,'BNKC',0,2),(38,'DPNE',0,3),(38,'CPME',0,4),
 (39,'True',1,1),(39,'False',0,2),
 (40,'26%',0,1),(40,'28%',1,2),(40,'30%',0,3),(40,'32%',0,4);
