<?php
/**
 * College (tenant) helpers.
 *
 * Every account belongs to a college, and a college only ever sees its own
 * students, faculty, papers and results. current_college_id() is the single
 * source of that boundary - every listing query filters on it.
 */
require_once __DIR__ . '/../config/db.php';

/* ------------------------------------------------------------------ */
/*  Lookups                                                            */
/* ------------------------------------------------------------------ */

function get_college(?int $id): ?array
{
    if (!$id) {
        return null;
    }
    static $cache = [];
    if (!array_key_exists($id, $cache)) {
        $st = db()->prepare('SELECT * FROM colleges WHERE id = ?');
        $st->execute([$id]);
        $cache[$id] = $st->fetch() ?: null;
    }
    return $cache[$id];
}

/** Colleges a new student or faculty member may register under. */
function get_colleges(bool $activeOnly = true): array
{
    $sql = 'SELECT * FROM colleges';
    if ($activeOnly) {
        $sql .= ' WHERE is_active = 1';
    }
    return db()->query($sql . ' ORDER BY name')->fetchAll();
}

/** Branches offered by one college. */
function get_branches(?int $collegeId, bool $activeOnly = true): array
{
    if (!$collegeId) {
        return [];
    }
    $sql = 'SELECT * FROM branches WHERE college_id = ?';
    if ($activeOnly) {
        $sql .= ' AND is_active = 1';
    }
    $st = db()->prepare($sql . ' ORDER BY name');
    $st->execute([$collegeId]);
    return $st->fetchAll();
}

/** True when this branch really belongs to that college. */
function branch_belongs_to(?int $branchId, ?int $collegeId): bool
{
    if (!$branchId || !$collegeId) {
        return false;
    }
    $st = db()->prepare('SELECT 1 FROM branches WHERE id = ? AND college_id = ?');
    $st->execute([$branchId, $collegeId]);
    return (bool)$st->fetchColumn();
}

function branch_name(?int $branchId): string
{
    if (!$branchId) {
        return 'All branches';
    }
    static $cache = [];
    if (!array_key_exists($branchId, $cache)) {
        $st = db()->prepare('SELECT name FROM branches WHERE id = ?');
        $st->execute([$branchId]);
        $cache[$branchId] = (string)($st->fetchColumn() ?: '-');
    }
    return $cache[$branchId];
}

/* ------------------------------------------------------------------ */
/*  Who an account registers under                                     */
/* ------------------------------------------------------------------ */

/** Administrators of one college - a new faculty member registers under one. */
function get_college_admins(?int $collegeId): array
{
    if (!$collegeId) {
        return [];
    }
    $st = db()->prepare('SELECT id, name, designation FROM admins
                          WHERE college_id = ? ORDER BY name');
    $st->execute([$collegeId]);
    return $st->fetchAll();
}

/** "Dr. Anita Verma (HOD)" style label for an admin or faculty row. */
function person_label(array $row, string $extra = ''): string
{
    $label = $row['name'];
    if (!empty($row['designation'])) {
        $label .= ' (' . $row['designation'] . ')';
    }
    return $extra !== '' ? $label . ' - ' . $extra : $label;
}

/**
 * Designations used across the portal. Pass the value already saved on an
 * account so an older designation outside this list still shows as selected.
 */
function designations(?string $current = null): array
{
    $list = ['Principal/Director', 'HOD', 'Lecturer', 'Workshop Superintendent',
             'Workshop Instructor', 'Computer Operator'];
    if ($current !== null && $current !== '' && !in_array($current, $list, true)) {
        $list[] = $current;
    }
    return $list;
}

/* ------------------------------------------------------------------ */
/*  Year and semester                                                  */
/* ------------------------------------------------------------------ */

/** Study years a student can be in (a three year course). */
function study_years(): array
{
    return [1 => '1st Year', 2 => '2nd Year', 3 => '3rd Year'];
}

/** Semesters - two per study year, so six in all. */
function semesters(): array
{
    $out = [];
    for ($s = 1; $s <= 2 * count(study_years()); $s++) {
        $out[$s] = 'Semester ' . $s;
    }
    return $out;
}

function year_label(?int $year): string
{
    return $year ? (study_years()[$year] ?? ('Year ' . $year)) : 'All years';
}

function semester_label(?int $sem): string
{
    return $sem ? ('Semester ' . $sem) : 'All semesters';
}

/** "CSE - 3rd Year, Semester 5" for a student row. */
function student_class_label(array $student): string
{
    $bits = [];
    if (!empty($student['branch_name'])) {
        $bits[] = $student['branch_name'];
    } elseif (!empty($student['branch_id'])) {
        $bits[] = branch_name((int)$student['branch_id']);
    }
    if (!empty($student['study_year'])) {
        $bits[] = year_label((int)$student['study_year']);
    }
    if (!empty($student['semester'])) {
        $bits[] = 'Sem ' . (int)$student['semester'];
    }
    return $bits ? implode(' · ', $bits) : 'Class not set';
}

/** "CSE · 3rd Year · Semester 5" for an exam's intended audience. */
function exam_audience_label(array $exam): string
{
    $bits = [];
    $bits[] = !empty($exam['target_branch_id'])
              ? branch_name((int)$exam['target_branch_id']) : 'All branches';
    if (!empty($exam['target_year']))     { $bits[] = year_label((int)$exam['target_year']); }
    if (!empty($exam['target_semester'])) { $bits[] = 'Sem ' . (int)$exam['target_semester']; }
    return implode(' · ', $bits);
}

/**
 * The branch / year / semester / search bar that sits above every roster.
 * One implementation so the filters look and behave the same everywhere.
 *
 * @param array<string,string|int> $hidden extra query fields to preserve
 */
function render_class_filter(string $action, array $branches, array $filters,
                             array $hidden = [], string $searchPlaceholder = 'Name, email or roll no'): string
{
    ob_start(); ?>
    <form method="get" class="card border-0 mb-3 filter-bar">
      <div class="card-body row g-2 align-items-end">
        <?php foreach ($hidden as $k => $v): ?>
          <input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>">
        <?php endforeach; ?>

        <div class="col-md-3">
          <label class="form-label" for="fbranch">Branch</label>
          <select class="form-select" id="fbranch" name="branch_id">
            <option value="0">All branches</option>
            <?php foreach ($branches as $b): ?>
              <option value="<?= (int)$b['id'] ?>"
                <?= (int)($filters['branch_id'] ?? 0) === (int)$b['id'] ? 'selected' : '' ?>>
                <?= e($b['code']) ?> - <?= e($b['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-6 col-md-2">
          <label class="form-label" for="fyear">Year</label>
          <select class="form-select" id="fyear" name="study_year">
            <option value="0">All years</option>
            <?php foreach (study_years() as $v => $label): ?>
              <option value="<?= $v ?>" <?= (int)($filters['study_year'] ?? 0) === $v ? 'selected' : '' ?>>
                <?= e($label) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-6 col-md-2">
          <label class="form-label" for="fsem">Semester</label>
          <select class="form-select" id="fsem" name="semester">
            <option value="0">All</option>
            <?php foreach (semesters() as $v => $label): ?>
              <option value="<?= $v ?>" <?= (int)($filters['semester'] ?? 0) === $v ? 'selected' : '' ?>>
                Sem <?= $v ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-3">
          <label class="form-label" for="fq">Search</label>
          <div class="input-group">
            <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
            <input type="search" class="form-control" id="fq" name="q"
                   value="<?= e($filters['q'] ?? '') ?>"
                   placeholder="<?= e($searchPlaceholder) ?>">
          </div>
        </div>

        <div class="col-md-2 d-grid">
          <button class="btn btn-outline-primary"><i class="bi bi-funnel me-1"></i>Filter</button>
        </div>
      </div>
    </form>
    <?php
    return (string)ob_get_clean();
}

/** The little branch / year / semester chips shown next to a student's name. */
function class_chips(array $student): string
{
    $out = '';
    if (!empty($student['branch_code'])) {
        $out .= '<span class="class-chip">' . e($student['branch_code']) . '</span> ';
    }
    if (!empty($student['study_year'])) {
        $out .= '<span class="class-chip year">' . e(year_label((int)$student['study_year']))
              . '</span> ';
    }
    if (!empty($student['semester'])) {
        $out .= '<span class="class-chip sem">Sem ' . (int)$student['semester'] . '</span>';
    }
    return $out !== '' ? $out
        : '<span class="badge bg-warning text-dark">Class not set</span>';
}

/* ------------------------------------------------------------------ */
/*  Logo upload                                                        */
/* ------------------------------------------------------------------ */

define('LOGO_DIR', __DIR__ . '/../uploads/logos');
define('LOGO_MAX_BYTES', 2 * 1024 * 1024);      // 2 MB is plenty for a crest

/**
 * Validate and store an uploaded college logo.
 *
 * @return array{0:?string,1:string} [stored file name, error message]
 */
function store_college_logo(array $file): array
{
    return store_uploaded_image($file, LOGO_DIR, 'logo', 'The logo');
}

/**
 * Validate an uploaded image and move it into $dir under a random name.
 *
 * @return array{0:?string,1:string} [stored file name, error message]
 */
function store_uploaded_image(array $file, string $dir, string $prefix, string $what): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [null, ''];                       // nothing uploaded - not an error
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return [null, "$what could not be uploaded. Please try a smaller file."];
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return [null, 'Invalid upload.'];
    }
    if ($file['size'] > LOGO_MAX_BYTES) {
        return [null, "$what must be 2 MB or smaller."];
    }

    // Trust the image header, not the sent file name or MIME type.
    $info = @getimagesize($file['tmp_name']);
    if ($info === false) {
        return [null, 'That file is not an image.'];
    }
    $ext = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'][$info[2]] ?? null;
    if ($ext === null) {
        return [null, 'Use a PNG, JPG, GIF or WEBP image.'];
    }

    if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
        return [null, 'The server cannot write to uploads/' . basename($dir) . '.'];
    }

    $name = $prefix . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        return [null, "$what could not be saved."];
    }
    return [$name, ''];
}

/* ------------------------------------------------------------------ */
/*  Profile photos (students and faculty)                              */
/* ------------------------------------------------------------------ */

define('PHOTO_DIR', __DIR__ . '/../uploads/photos');

/**
 * Add the photo column to students / faculty on a site that has not re-run
 * upgrade.php. Only called when a photo is saved, so pages never pay for it.
 */
function ensure_photo_column(string $table): void
{
    $st = db()->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS
                          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([$table, 'photo']);
    if (!(int)$st->fetchColumn()) {
        db()->exec("ALTER TABLE `$table` ADD COLUMN `photo` VARCHAR(255) DEFAULT NULL");
    }
}

/**
 * Handle the photo form of a profile page: action "photo" uploads a new one,
 * "photo_remove" clears it. Returns an error message, or '' on success.
 *
 * @param string $table students | faculty
 */
function save_profile_photo(string $table, array $account, bool $remove): string
{
    if (!in_array($table, ['students', 'faculty'], true)) {
        return 'Invalid account.';
    }
    ensure_photo_column($table);

    $name = null;
    if (!$remove) {
        [$name, $error] = store_uploaded_image($_FILES['photo'] ?? [], PHOTO_DIR, 'photo', 'The photo');
        if ($error !== '') {
            return $error;
        }
        if ($name === null) {
            return 'Choose a photo to upload.';
        }
    }
    db()->prepare("UPDATE `$table` SET photo = ? WHERE id = ?")->execute([$name, (int)$account['id']]);
    if (!empty($account['photo'])) {
        $old = PHOTO_DIR . '/' . basename($account['photo']);
        if (is_file($old)) {
            @unlink($old);
        }
    }
    return '';
}

/**
 * The big avatar on a profile page with its own upload / remove form.
 * Picking a file submits straight away.
 */
function profile_photo_picker(array $person): string
{
    ob_start(); ?>
    <div class="text-center" style="width:110px">
      <div class="d-flex justify-content-center"><?= avatar_html($person, 'avatar-xl') ?></div>
      <form method="post" enctype="multipart/form-data" class="mt-2">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="photo">
        <label class="btn btn-sm btn-outline-primary py-0 px-2 small mb-1" title="JPG, PNG, GIF or WEBP, up to 2 MB">
          <i class="bi bi-camera me-1"></i><?= empty($person['photo']) ? 'Add photo' : 'Change' ?>
          <input type="file" name="photo" accept="image/png,image/jpeg,image/gif,image/webp"
                 class="d-none" onchange="this.form.submit()">
        </label>
        <?php if (!empty($person['photo'])): ?>
          <button class="btn btn-sm btn-link text-danger py-0 px-1 small d-block mx-auto"
                  name="action" value="photo_remove" data-confirm="Remove your photo?">Remove</button>
        <?php endif; ?>
      </form>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Round avatar for a student / faculty row: their photo, or their initials.
 *
 * @param string $class avatar-xl | avatar-md | avatar-sm
 */
function avatar_html(array $person, string $class = 'avatar-sm'): string
{
    if (!empty($person['photo'])) {
        return '<img src="' . e(url('uploads/photos/' . rawurlencode(basename($person['photo']))))
             . '" alt="" class="' . e($class) . ' avatar-photo" style="object-fit:cover;background:#fff">';
    }
    return '<span class="' . e($class) . '">' . e(initials($person['name'] ?? '?')) . '</span>';
}

/** Remove a logo file that is no longer referenced. */
function delete_college_logo(?string $name): void
{
    if (!$name) {
        return;
    }
    // basename() keeps a crafted value from escaping the folder.
    $path = LOGO_DIR . '/' . basename($name);
    if (is_file($path)) {
        @unlink($path);
    }
}

/** Public URL of a college logo, or null when it has none. */
function college_logo_url(?array $college): ?string
{
    if (!$college || empty($college['logo'])) {
        return null;
    }
    return url('uploads/logos/' . rawurlencode(basename($college['logo'])));
}

/**
 * The <img> or initials badge shown for a college.
 * Falls back to the initials of the name so the layout never breaks.
 */
function college_badge(?array $college, int $size = 40): string
{
    $logo = college_logo_url($college);
    if ($logo) {
        return '<img src="' . e($logo) . '" alt="" class="college-logo"'
             . ' style="width:' . $size . 'px;height:' . $size . 'px">';
    }
    $initials = $college ? initials($college['name']) : '?';
    return '<span class="college-logo college-logo-fallback" style="width:' . $size
         . 'px;height:' . $size . 'px;font-size:' . round($size * 0.36) . 'px">'
         . e($initials) . '</span>';
}
