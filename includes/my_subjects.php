<?php
/**
 * My Subject - the subjects a faculty member teaches, kept session wise.
 *
 * An academic session runs July to June (e.g. 2026-27) and has two terms:
 *   Odd term  - July to December    - Semesters 1, 3, 5
 *   Even term - January to June     - Semesters 2, 4, 6
 * Every session keeps its own list, so earlier sessions stay on record.
 *
 * Wherever the site asks a faculty member for a subject (exam, assignment,
 * E-Content, E-Lecture, My Students) it offers these subjects in a drop-down.
 */

/** Create the table on first use. */
function ensure_my_subjects_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    db()->exec('CREATE TABLE IF NOT EXISTS `faculty_teaching` (
        `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `faculty_id`   INT UNSIGNED NOT NULL,
        `college_id`   INT UNSIGNED NOT NULL,
        `session`      CHAR(7) NOT NULL,
        `semester`     TINYINT UNSIGNED NOT NULL,
        `study_year`   TINYINT UNSIGNED NOT NULL,
        `branch_id`    INT UNSIGNED NOT NULL DEFAULT 0,
        `subject_name` VARCHAR(120) NOT NULL,
        `subject_code` VARCHAR(30) DEFAULT NULL,
        `subject_type` VARCHAR(12) NOT NULL DEFAULT \'Theory\',
        `periods`      TINYINT UNSIGNED DEFAULT NULL,
        `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_teaching` (`faculty_id`, `session`, `semester`, `branch_id`, `subject_name`),
        KEY `idx_teaching_session` (`faculty_id`, `session`),
        CONSTRAINT `fk_teaching_faculty` FOREIGN KEY (`faculty_id`)
          REFERENCES `faculty` (`id`) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $done = true;
}

/** Subject types a faculty member can choose. */
function subject_types(): array
{
    return ['Theory' => 'journal-text', 'Practical' => 'cpu', 'Both' => 'layers'];
}

/** The session running today: "2026-27" from July 2026 to June 2027. */
function current_session(?int $time = null): string
{
    $time  = $time ?? time();
    $year  = (int)date('Y', $time);
    $start = (int)date('n', $time) >= 7 ? $year : $year - 1;
    return session_from_start($start);
}

/** "2026-27" for a session that starts in July 2026. */
function session_from_start(int $startYear): string
{
    return $startYear . '-' . substr((string)($startYear + 1), -2);
}

/** Start year of a session string, or 0 if it is not a valid "YYYY-YY". */
function session_start_year(string $session): int
{
    if (!preg_match('/^(\d{4})-(\d{2})$/', $session, $m)) {
        return 0;
    }
    $start = (int)$m[1];
    return substr((string)($start + 1), -2) === $m[2] && $start >= 2000 && $start <= 2100 ? $start : 0;
}

/** Odd term for semesters 1, 3, 5; even term for 2, 4, 6. */
function semester_term(int $semester): string
{
    return $semester % 2 ? 'odd' : 'even';
}

/** The study year a semester belongs to (Sem 3 and 4 are the 2nd year). */
function semester_year(int $semester): int
{
    return (int)ceil($semester / 2);
}

/** Label and months of a term within a session. */
function term_info(string $session, string $term): array
{
    $start = session_start_year($session) ?: (int)date('Y');
    return $term === 'odd'
        ? ['label' => 'Odd Semester',  'months' => 'July – December ' . $start, 'sems' => [1, 3, 5]]
        : ['label' => 'Even Semester', 'months' => 'January – June ' . ($start + 1), 'sems' => [2, 4, 6]];
}

/** Which term is running today within the current session. */
function current_term(): string
{
    return (int)date('n') >= 7 ? 'odd' : 'even';
}

/** Every subject row of one session for a faculty member. */
function get_teaching(int $facultyId, string $session): array
{
    ensure_my_subjects_schema();
    $st = db()->prepare('SELECT t.*, b.code AS branch_code, b.name AS branch_name
                           FROM faculty_teaching t
                      LEFT JOIN branches b ON b.id = t.branch_id
                          WHERE t.faculty_id = ? AND t.session = ?
                       ORDER BY t.semester, t.subject_name');
    $st->execute([$facultyId, $session]);
    return $st->fetchAll();
}

/** Sessions this faculty member has a record for, newest first, with counts. */
function teaching_sessions(int $facultyId): array
{
    ensure_my_subjects_schema();
    $st = db()->prepare('SELECT session, COUNT(*) AS n FROM faculty_teaching
                          WHERE faculty_id = ? GROUP BY session ORDER BY session DESC');
    $st->execute([$facultyId]);
    return $st->fetchAll(PDO::FETCH_KEY_PAIR);
}

/**
 * Subjects for the drop-downs: this session's subjects first; if none are set
 * yet, the most recent session that has any.
 *
 * @return array{session:string, rows:array}
 */
function my_subject_choices(int $facultyId): array
{
    $session = current_session();
    $rows = get_teaching($facultyId, $session);
    if (!$rows) {
        $sessions = array_keys(teaching_sessions($facultyId));
        if ($sessions) {
            $session = $sessions[0];
            $rows = get_teaching($facultyId, $session);
        }
    }
    return ['session' => $session, 'rows' => $rows];
}

/** Plain list of subject names a faculty member may pick (for validation). */
function my_subject_names(int $facultyId): array
{
    return array_values(array_unique(array_column(my_subject_choices($facultyId)['rows'], 'subject_name')));
}

/** True when $subject is one of the faculty's set subjects (or was already saved). */
function subject_allowed(int $facultyId, string $subject, string $savedValue = ''): bool
{
    return $subject !== '' && ($subject === $savedValue || in_array($subject, my_subject_names($facultyId), true));
}

/**
 * The subject drop-down used across the faculty panel.
 *
 * Starts blank ("Select subject"), lists the set subjects grouped by
 * semester, and carries each subject's branch / year / semester so a form
 * can fill its class fields from the choice. An older saved value that is
 * no longer in the list is kept as an extra option.
 */
function subject_select(int $facultyId, string $current = '', array $opt = []): string
{
    $name     = $opt['name'] ?? 'subject';
    $id       = $opt['id'] ?? 'subject';
    $class    = trim('form-select ' . ($opt['class'] ?? ''));
    $required = !empty($opt['required']) ? ' required' : '';
    $style    = isset($opt['style']) ? ' style="' . e($opt['style']) . '"' : '';

    $choices = my_subject_choices($facultyId);
    $groups  = [];
    $names   = [];
    foreach ($choices['rows'] as $r) {
        $groups[(int)$r['semester']][] = $r;
        $names[$r['subject_name']] = true;
    }

    $html = '<select class="' . e($class) . '" id="' . e($id) . '" name="' . e($name) . '"' . $required . $style
          . ' data-subject-select>';
    $html .= '<option value="">' . ($choices['rows'] ? '-- Select subject --' : '-- No subject set --') . '</option>';
    if ($current !== '' && !isset($names[$current])) {
        $html .= '<option value="' . e($current) . '" selected>' . e($current) . ' (earlier)</option>';
    }
    foreach ($groups as $sem => $rows) {
        $html .= '<optgroup label="Semester ' . $sem . ' · ' . e(year_label(semester_year($sem))) . '">';
        $seen = [];
        foreach ($rows as $r) {
            if (isset($seen[$r['subject_name']])) {
                continue; // same subject for two branches: list it once
            }
            $seen[$r['subject_name']] = true;
            $label = $r['subject_name'] . ($r['subject_code'] ? ' (' . $r['subject_code'] . ')' : '');
            $html .= '<option value="' . e($r['subject_name']) . '"'
                   . ' data-semester="' . (int)$r['semester'] . '"'
                   . ' data-year="' . (int)$r['study_year'] . '"'
                   . ' data-branch="' . (int)$r['branch_id'] . '"'
                   . ($current === $r['subject_name'] ? ' selected' : '') . '>' . e($label) . '</option>';
        }
        $html .= '</optgroup>';
    }
    $html .= '</select>';

    if (!$choices['rows']) {
        $html .= '<div class="form-text text-warning-emphasis"><i class="bi bi-exclamation-circle me-1"></i>'
               . 'Set your subjects first in <a href="' . e(url('faculty/my_subjects.php')) . '">My Subject</a>.</div>';
    } elseif ($choices['session'] !== current_session()) {
        $html .= '<div class="form-text">Showing subjects of session ' . e($choices['session'])
               . '. <a href="' . e(url('faculty/my_subjects.php')) . '">Set ' . e(current_session()) . ' subjects</a>.</div>';
    }

    // Choosing a subject fills the form's branch / year / semester (once per page).
    static $scriptDone = false;
    if (empty($opt['no_autofill']) && !$scriptDone) {
        $scriptDone = true;
        $html .= <<<'JS'
<script>
document.addEventListener('change', function (e) {
  var s = e.target;
  if (!s.matches || !s.matches('[data-subject-select]')) return;
  var o = s.options[s.selectedIndex], f = s.form;
  if (!o || !o.dataset.semester || !f) return;
  var set = function (n, v) { var el = f.querySelector('[name="' + n + '"]'); if (el && el.querySelector('option[value="' + v + '"]')) el.value = v; };
  set('semester', o.dataset.semester);
  set('study_year', o.dataset.year);
  if (o.dataset.branch !== '0') set('branch_id', o.dataset.branch);
});
</script>
JS;
    }
    return $html;
}
