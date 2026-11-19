<?php
/**
 * E-Content (reading material) and E-Lecture (YouTube videos).
 *
 * A faculty member publishes them to a class (branch / year / semester - any
 * of them may be "all"), exactly like an assignment. Every active student of
 * the college in that class can read the content or watch the lecture.
 *
 * E-Content is stored as plain text only. A PDF the faculty uploads is read
 * into text on the server and the file itself is never kept, so students get
 * the words on screen and there is no PDF to download. The reader page adds
 * copy / print / save guards and a watermark with the student's name.
 */

/** Create the two tables on first use. */
function ensure_elearning_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    db()->exec('CREATE TABLE IF NOT EXISTS `e_contents` (
        `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `college_id`  INT UNSIGNED NOT NULL,
        `faculty_id`  INT UNSIGNED NOT NULL,
        `title`       VARCHAR(200) NOT NULL,
        `subject`     VARCHAR(120) NOT NULL,
        `unit`        VARCHAR(120) DEFAULT NULL,
        `body`        MEDIUMTEXT NOT NULL,
        `source`      VARCHAR(10) NOT NULL DEFAULT \'text\',
        `source_name` VARCHAR(200) DEFAULT NULL,
        `branch_id`   INT UNSIGNED DEFAULT NULL,
        `study_year`  TINYINT UNSIGNED DEFAULT NULL,
        `semester`    TINYINT UNSIGNED DEFAULT NULL,
        `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
        `views`       INT UNSIGNED NOT NULL DEFAULT 0,
        `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`  DATETIME DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_econtent_college` (`college_id`, `is_active`),
        KEY `idx_econtent_faculty` (`faculty_id`),
        CONSTRAINT `fk_econtent_faculty` FOREIGN KEY (`faculty_id`)
          REFERENCES `faculty` (`id`) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    db()->exec('CREATE TABLE IF NOT EXISTS `e_lectures` (
        `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `college_id`  INT UNSIGNED NOT NULL,
        `faculty_id`  INT UNSIGNED NOT NULL,
        `title`       VARCHAR(200) NOT NULL,
        `subject`     VARCHAR(120) NOT NULL,
        `unit`        VARCHAR(120) DEFAULT NULL,
        `description` TEXT DEFAULT NULL,
        `youtube_id`  VARCHAR(20) NOT NULL,
        `branch_id`   INT UNSIGNED DEFAULT NULL,
        `study_year`  TINYINT UNSIGNED DEFAULT NULL,
        `semester`    TINYINT UNSIGNED DEFAULT NULL,
        `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
        `views`       INT UNSIGNED NOT NULL DEFAULT 0,
        `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_electure_college` (`college_id`, `is_active`),
        KEY `idx_electure_faculty` (`faculty_id`),
        CONSTRAINT `fk_electure_faculty` FOREIGN KEY (`faculty_id`)
          REFERENCES `faculty` (`id`) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $done = true;
}

/** Table name for a kind ('content' or 'lecture'). */
function elearning_table(string $kind): string
{
    return $kind === 'lecture' ? 'e_lectures' : 'e_contents';
}

/** A faculty member's items of one kind, newest first, with class size. */
function get_faculty_elearning(string $kind, int $facultyId): array
{
    ensure_elearning_schema();
    $st = db()->prepare('SELECT a.*,
            (SELECT COUNT(*) FROM students s WHERE ' . assignment_match_sql() . ') AS student_count
          FROM ' . elearning_table($kind) . ' a WHERE a.faculty_id = ? ORDER BY a.id DESC');
    $st->execute([$facultyId]);
    return $st->fetchAll();
}

/** One of this faculty member's items, or null. */
function get_owned_elearning(string $kind, int $id, int $facultyId): ?array
{
    ensure_elearning_schema();
    $st = db()->prepare('SELECT * FROM ' . elearning_table($kind) . ' WHERE id = ? AND faculty_id = ?');
    $st->execute([$id, $facultyId]);
    return $st->fetch() ?: null;
}

/** Active items of one kind sent to this student's class, newest first. */
function get_student_elearning(string $kind, array $student): array
{
    ensure_elearning_schema();
    $st = db()->prepare('SELECT a.*, f.name AS faculty_name, f.designation AS faculty_designation
                           FROM students s
                           JOIN ' . elearning_table($kind) . ' a ON a.is_active = 1 AND ' . assignment_match_sql() . '
                           JOIN faculty f ON f.id = a.faculty_id
                          WHERE s.id = ?
                       ORDER BY a.id DESC');
    $st->execute([$student['id']]);
    return $st->fetchAll();
}

/** One active item this student may open, or null. */
function get_student_elearning_item(string $kind, int $id, array $student): ?array
{
    ensure_elearning_schema();
    $st = db()->prepare('SELECT a.*, f.name AS faculty_name, f.designation AS faculty_designation
                           FROM students s
                           JOIN ' . elearning_table($kind) . ' a ON a.is_active = 1 AND ' . assignment_match_sql() . '
                           JOIN faculty f ON f.id = a.faculty_id
                          WHERE s.id = ? AND a.id = ?');
    $st->execute([$student['id'], $id]);
    return $st->fetch() ?: null;
}

/** Read the branch / year / semester / visible fields of a publish form into $a. */
function read_audience_post(array &$a, int $collegeId, array &$errors): void
{
    $a['branch_id']  = (int)post('branch_id') ?: null;
    $a['study_year'] = (int)post('study_year') ?: null;
    $a['semester']   = (int)post('semester') ?: null;
    if (!isset(study_years()[(int)$a['study_year']])) { $a['study_year'] = null; }
    if (!isset(semesters()[(int)$a['semester']]))     { $a['semester'] = null; }
    $a['is_active']  = post('is_active') === '1' ? 1 : 0;
    if ($a['branch_id'] && !branch_belongs_to($a['branch_id'], $collegeId)) {
        $errors['branch_id'] = 'Choose a branch of your college.';
    }
}

/** Count one view (once per session per item). */
function elearning_count_view(string $kind, int $id): void
{
    $key = 'el_seen_' . $kind;
    if (!empty($_SESSION[$key][$id])) {
        return;
    }
    $_SESSION[$key][$id] = 1;
    db()->prepare('UPDATE ' . elearning_table($kind) . ' SET views = views + 1 WHERE id = ?')->execute([$id]);
}

/**
 * The 11 character video id from any kind of YouTube link: watch?v=, youtu.be/,
 * shorts/, live/, embed/, m.youtube.com, music.youtube.com, or a bare id.
 */
function youtube_video_id(string $url): ?string
{
    $url = trim($url);
    if (preg_match('/^[A-Za-z0-9_-]{11}$/', $url)) {
        return $url;
    }
    if (!preg_match('#^(https?://)?([a-z0-9-]+\.)*(youtube\.com|youtube-nocookie\.com|youtu\.be)(/|$)#i', $url)) {
        return null;
    }
    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . $url;
    }
    $parts = parse_url($url);
    $host  = strtolower($parts['host'] ?? '');
    $path  = $parts['path'] ?? '';
    parse_str($parts['query'] ?? '', $q);

    if ($host === 'youtu.be') {
        $id = explode('/', trim($path, '/'))[0] ?? '';
    } elseif (!empty($q['v'])) {
        $id = (string)$q['v'];
    } elseif (preg_match('#^/(shorts|live|embed|v|e)/([^/?]+)#', $path, $m)) {
        $id = $m[2];
    } else {
        $id = '';
    }
    return preg_match('/^[A-Za-z0-9_-]{11}$/', $id) ? $id : null;
}

/** Thumbnail image for a YouTube video. */
function youtube_thumb(string $id, string $size = 'hqdefault'): string
{
    return 'https://i.ytimg.com/vi/' . rawurlencode($id) . '/' . $size . '.jpg';
}

/** Privacy-friendly embed URL for the player. */
function youtube_embed_url(string $id): string
{
    return 'https://www.youtube-nocookie.com/embed/' . rawurlencode($id)
         . '?rel=0&modestbranding=1&playsinline=1';
}

/** Rough reading time in minutes. */
function reading_minutes(string $text): int
{
    $words = count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY));
    return max(1, (int)ceil($words / 200));
}

/**
 * Turn the stored plain text into safe reading HTML.
 *
 * Light formatting the faculty can type:
 *   # Heading / ## Sub heading     - * or • bullet     1. numbered
 *   **bold**   _italic_            blank line = new paragraph
 * A short line in CAPITALS or ending with ":" (typical of PDF headings) is
 * shown as a sub heading.
 */
function econtent_html(string $text): string
{
    $text   = str_replace(["\r\n", "\r"], "\n", $text);
    $blocks = preg_split('/\n\s*\n/', trim($text));
    $out    = '';

    $inline = function (string $s): string {
        $s = e($s);
        $s = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $s);
        $s = preg_replace('/(?<![\w])_(.+?)_(?![\w])/u', '<em>$1</em>', $s);
        return $s;
    };

    foreach ($blocks as $block) {
        $lines = array_values(array_filter(array_map('rtrim', explode("\n", $block)), fn($l) => trim($l) !== ''));
        if (!$lines) {
            continue;
        }
        $list = null;
        $para = [];
        $flushPara = function () use (&$para, &$out, $inline) {
            if ($para) {
                $out .= '<p>' . implode('<br>', array_map($inline, $para)) . '</p>';
                $para = [];
            }
        };
        $flushList = function () use (&$list, &$out) {
            if ($list) {
                $out .= '<' . $list['tag'] . '>' . $list['html'] . '</' . $list['tag'] . '>';
                $list = null;
            }
        };

        foreach ($lines as $line) {
            $t = trim($line);
            if (preg_match('/^(#{1,3})\s+(.+)$/u', $t, $m)) {
                $flushPara(); $flushList();
                $lvl = strlen($m[1]) + 1;
                $out .= "<h$lvl>" . $inline($m[2]) . "</h$lvl>";
            } elseif (preg_match('/^(?:[-*•▪●◦]|\x{F0B7})\s+(.+)$/u', $t, $m)) {
                $flushPara();
                if ($list && $list['tag'] !== 'ul') { $flushList(); }
                $list = $list ?: ['tag' => 'ul', 'html' => ''];
                $list['html'] .= '<li>' . $inline($m[1]) . '</li>';
            } elseif (preg_match('/^\(?(\d{1,3})[.)]\s+(.+)$/u', $t, $m)) {
                $flushPara();
                if ($list && $list['tag'] !== 'ol') { $flushList(); }
                $list = $list ?: ['tag' => 'ol', 'html' => ''];
                $list['html'] .= '<li value="' . (int)$m[1] . '">' . $inline($m[2]) . '</li>';
            } elseif (mb_strlen($t) <= 80 && (
                         (preg_match('/[A-Z]/', $t) && mb_strtoupper($t) === $t && preg_match('/[A-Z]{3}/', $t))
                      || preg_match('/^[^.!?]{2,70}:$/u', $t))) {
                $flushPara(); $flushList();
                $out .= '<h4>' . $inline(rtrim($t, ':')) . '</h4>';
            } else {
                $flushList();
                $para[] = $t;
            }
        }
        $flushPara(); $flushList();
    }
    return $out;
}
