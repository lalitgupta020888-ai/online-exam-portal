<?php
/**
 * Faculty - add or edit an E-Lecture. Every video is a YouTube link
 * (normal, unlisted, Shorts or live) that students watch inside the portal.
 */
require_once __DIR__ . '/../includes/auth.php';
$faculty   = require_faculty();
$collegeId = (int)$faculty['college_id'];
ensure_elearning_schema();

$id     = get_int('id');
$isEdit = $id > 0;
$errors = [];

$a = [
    'title' => '', 'subject' => $faculty['department'] ?? '', 'unit' => '', 'description' => '',
    'youtube_id' => '', 'url' => '',
    'branch_id' => $faculty['branch_id'] ?? '', 'study_year' => '', 'semester' => '', 'is_active' => 1,
];
if ($isEdit) {
    $found = get_owned_elearning('lecture', $id, (int)$faculty['id']);
    if (!$found) {
        set_flash('danger', 'That E-Lecture does not belong to you.');
        redirect('faculty/electures.php');
    }
    $a = array_merge($a, $found);
    $a['url'] = 'https://youtu.be/' . $found['youtube_id'];
}
$branches = get_branches($collegeId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    foreach (['title', 'subject', 'unit', 'description', 'url'] as $k) {
        $a[$k] = post($k);
    }
    read_audience_post($a, $collegeId, $errors);
    $a['youtube_id'] = youtube_video_id($a['url']) ?? '';

    if ($a['youtube_id'] === '')     { $errors['url'] = 'Paste a valid YouTube video link (youtube.com or youtu.be).'; }
    if (mb_strlen($a['title']) < 3)  { $errors['title'] = 'Give the lecture a title.'; }
    if ($a['subject'] === '')        { $errors['subject'] = 'Enter the subject.'; }
    if (mb_strlen($a['description']) > 5000) { $errors['description'] = 'Keep the description under 5,000 characters.'; }

    if (!$errors) {
        $vals = [mb_substr($a['title'], 0, 200), mb_substr($a['subject'], 0, 120),
                 $a['unit'] !== '' ? mb_substr($a['unit'], 0, 120) : null,
                 $a['description'] !== '' ? $a['description'] : null, $a['youtube_id'],
                 $a['branch_id'], $a['study_year'], $a['semester'], $a['is_active']];
        if ($isEdit) {
            db()->prepare('UPDATE e_lectures SET title = ?, subject = ?, unit = ?, description = ?, youtube_id = ?,
                                  branch_id = ?, study_year = ?, semester = ?, is_active = ?
                            WHERE id = ? AND faculty_id = ?')
                ->execute(array_merge($vals, [$id, $faculty['id']]));
            set_flash('success', 'E-Lecture updated.');
        } else {
            db()->prepare('INSERT INTO e_lectures (title, subject, unit, description, youtube_id,
                                  branch_id, study_year, semester, is_active, college_id, faculty_id)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute(array_merge($vals, [$collegeId, $faculty['id']]));
            set_flash('success', 'E-Lecture published to ' . assignment_audience_label($a) . '.');
        }
        redirect('faculty/electures.php');
    }
}

$pageTitle = $isEdit ? 'Edit E-Lecture' : 'New E-Lecture';
$activeNav = 'electures';
require_once __DIR__ . '/includes/faculty_header.php';

$err = fn($k) => isset($errors[$k]) ? 'is-invalid' : '';
$msg = fn($k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . e($errors[$k]) . '</div>' : '';
?>

<div class="el-hero el-hero-lecture mb-4">
  <div>
    <span class="el-hero-eyebrow"><i class="bi bi-play-btn me-1"></i>E-Lecture</span>
    <h3 class="mb-1"><?= $isEdit ? 'Edit video lecture' : 'Add a video lecture' ?></h3>
    <p class="mb-0">Paste any YouTube link &mdash; normal, unlisted, Shorts or a live class. Students watch it right here.</p>
  </div>
  <i class="bi bi-youtube el-hero-icon"></i>
</div>

<form method="post" class="card border-0 el-form" novalidate>
  <?= csrf_field() ?>
  <div class="card-body p-4">
    <div class="row g-4">
      <div class="col-lg-7">
        <div class="row g-3">
          <div class="col-12">
            <label class="form-label" for="url"><i class="bi bi-youtube text-danger me-1"></i>YouTube video URL</label>
            <input type="url" class="form-control form-control-lg <?= $err('url') ?>" id="url" name="url"
                   value="<?= e($a['url']) ?>" placeholder="https://www.youtube.com/watch?v=..." autocomplete="off">
            <?= $msg('url') ?>
            <div class="form-text">Tip: upload the video to YouTube as <strong>Unlisted</strong> so only people with the link can find it.</div>
          </div>
          <div class="col-12">
            <label class="form-label" for="title">Lecture title</label>
            <input type="text" class="form-control <?= $err('title') ?>" id="title" name="title"
                   value="<?= e($a['title']) ?>" placeholder="e.g. Lecture 3 - PN Junction Diode">
            <?= $msg('title') ?>
          </div>
          <div class="col-md-7">
            <label class="form-label" for="subject">Subject</label>
            <input type="text" class="form-control <?= $err('subject') ?>" id="subject" name="subject"
                   value="<?= e($a['subject']) ?>" list="subjectList">
            <datalist id="subjectList">
              <?php foreach (faculty_subjects((int)$faculty['id']) as $sub): ?>
                <option value="<?= e($sub) ?>">
              <?php endforeach; ?>
            </datalist>
            <?= $msg('subject') ?>
          </div>
          <div class="col-md-5">
            <label class="form-label" for="unit">Unit / Chapter <span class="text-muted-2 small">(optional)</span></label>
            <input type="text" class="form-control" id="unit" name="unit" value="<?= e((string)$a['unit']) ?>">
          </div>
          <div class="col-12">
            <label class="form-label" for="description">Description <span class="text-muted-2 small">(optional)</span></label>
            <textarea class="form-control <?= $err('description') ?>" id="description" name="description" rows="4"
                      placeholder="Topics covered, what to revise before watching..."><?= e((string)$a['description']) ?></textarea>
            <?= $msg('description') ?>
          </div>
        </div>
      </div>
      <div class="col-lg-5">
        <label class="form-label">Preview</label>
        <div class="el-video-frame" id="ytPreview">
          <?php if ($a['youtube_id']): ?>
            <iframe src="<?= e(youtube_embed_url($a['youtube_id'])) ?>" allowfullscreen
                    allow="accelerometer; encrypted-media; gyroscope; picture-in-picture"></iframe>
          <?php else: ?>
            <div class="el-video-empty"><i class="bi bi-play-circle"></i><span>Paste a link to preview the video</span></div>
          <?php endif; ?>
        </div>
      </div>

      <div class="col-12"><div class="row g-3">
        <?php require __DIR__ . '/includes/audience_fields.php'; ?>
      </div></div>
    </div>
  </div>
  <div class="card-footer bg-transparent d-flex justify-content-end gap-2 p-3">
    <a href="<?= url('faculty/electures.php') ?>" class="btn btn-light">Cancel</a>
    <button class="btn btn-primary px-4"><i class="bi bi-send me-1"></i><?= $isEdit ? 'Save Changes' : 'Publish Lecture' ?></button>
  </div>
</form>

<script>
(function () {
  var input = document.getElementById('url'), box = document.getElementById('ytPreview'), last = <?= json_encode($a['youtube_id']) ?>;
  function vid(u) {
    u = u.trim();
    if (/^[\w-]{11}$/.test(u)) return u;
    var m = u.match(/(?:youtu\.be\/|[?&]v=|\/(?:shorts|live|embed|v|e)\/)([\w-]{11})/);
    return m && /(youtube\.com|youtu\.be|youtube-nocookie\.com)/i.test(u) ? m[1] : '';
  }
  input.addEventListener('input', function () {
    var id = vid(input.value);
    if (id === last) return;
    last = id;
    box.innerHTML = id
      ? '<iframe src="https://www.youtube-nocookie.com/embed/' + id + '?rel=0&modestbranding=1" allowfullscreen ' +
        'allow="accelerometer; encrypted-media; gyroscope; picture-in-picture"></iframe>'
      : '<div class="el-video-empty"><i class="bi bi-play-circle"></i><span>' +
        (input.value.trim() ? 'That does not look like a YouTube link' : 'Paste a link to preview the video') + '</span></div>';
  });
})();
</script>

<?php require_once __DIR__ . '/includes/faculty_footer.php'; ?>
