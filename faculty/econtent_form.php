<?php
/**
 * Faculty - write E-Content, or upload a PDF that is turned into on-screen
 * text, and publish it to a class. The PDF file itself is never stored.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/doc_text.php';
$faculty   = require_faculty();
$collegeId = (int)$faculty['college_id'];
ensure_elearning_schema();

const ECONTENT_PDF_MAX_BYTES = 10 * 1024 * 1024;

$id     = get_int('id') ?: (int)post('id');
$isEdit = $id > 0;
$errors = [];
$notice = '';

$a = [
    'title' => '', 'subject' => $faculty['department'] ?? '', 'unit' => '', 'body' => '',
    'source' => 'text', 'source_name' => '',
    'branch_id' => $faculty['branch_id'] ?? '', 'study_year' => '', 'semester' => '', 'is_active' => 1,
];
if ($isEdit) {
    $found = get_owned_elearning('content', $id, (int)$faculty['id']);
    if (!$found) {
        set_flash('danger', 'That E-Content does not belong to you.');
        redirect('faculty/econtent.php');
    }
    $a = array_merge($a, $found);
}
$branches = get_branches($collegeId);

// A file over post_max_size makes PHP drop the whole form, CSRF token included.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    set_flash('danger', 'The PDF was too large. Keep it under ' . (ECONTENT_PDF_MAX_BYTES >> 20) . ' MB.');
    redirect('faculty/econtent_form.php' . ($isEdit ? '?id=' . $id : ''));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    foreach (['title', 'subject', 'unit'] as $k) {
        $a[$k] = post($k);
    }
    $a['body'] = trim(str_replace("\r\n", "\n", (string)($_POST['body'] ?? '')));
    $a['source'] = post('source') === 'pdf' ? 'pdf' : 'text';
    $a['source_name'] = post('source_name');
    read_audience_post($a, $collegeId, $errors);

    $file = $_FILES['pdf'] ?? null;
    $hasPdf = $file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

    if (!$hasPdf && post('convert') === '1') {
        $errors['pdf'] = 'Choose a PDF file first.';
    } elseif ($hasPdf) {
        // Step 1 of a PDF upload: read the text and show it for review.
        if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            || $file['size'] > ECONTENT_PDF_MAX_BYTES) {
            $errors['pdf'] = 'The PDF must be ' . (ECONTENT_PDF_MAX_BYTES >> 20) . ' MB or smaller.';
        } elseif ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            $errors['pdf'] = 'The PDF could not be uploaded. Please try again.';
        } elseif ((string)file_get_contents($file['tmp_name'], false, null, 0, 5) !== '%PDF-') {
            $errors['pdf'] = 'That file is not a PDF.';
        } else {
            $res = extract_document_text($file['tmp_name'], 'upload.pdf');
            @unlink($file['tmp_name']);
            if (trim($res['text']) === '') {
                $errors['pdf'] = $res['warning'] ?: 'No text could be read from this PDF.';
            } else {
                $a['body'] = $res['text'];
                $a['source'] = 'pdf';
                $a['source_name'] = mb_substr(basename((string)$file['name']), 0, 200);
                if ($a['title'] === '') {
                    $a['title'] = mb_substr(trim(preg_replace('/[_-]+/', ' ',
                        pathinfo($a['source_name'], PATHINFO_FILENAME))), 0, 200);
                }
                $notice = 'Text read from <strong>' . e($a['source_name']) . '</strong> ('
                        . number_format(count(preg_split('/\s+/u', $a['body'], -1, PREG_SPLIT_NO_EMPTY))) . ' words). Check it, tidy anything '
                        . 'that came out wrong, then press <strong>Publish</strong>.'
                        . ($res['warning'] ? '<br><span class="text-danger">' . e($res['warning']) . '</span>' : '');
            }
        }
    } else {
        // Step 2 (or a typed note): validate and save.
        if (mb_strlen($a['title']) < 3)   { $errors['title'] = 'Give the content a title.'; }
        if ($a['subject'] === '')         { $errors['subject'] = 'Enter the subject.'; }
        if (mb_strlen($a['body']) < 20)   { $errors['body'] = 'Write the content, or upload a PDF to read it from.'; }
        if (mb_strlen($a['body']) > 4000000) { $errors['body'] = 'The content is too long.'; }

        if (!$errors) {
            $vals = [mb_substr($a['title'], 0, 200), mb_substr($a['subject'], 0, 120),
                     $a['unit'] !== '' ? mb_substr($a['unit'], 0, 120) : null, $a['body'],
                     $a['source'], $a['source'] === 'pdf' ? mb_substr($a['source_name'], 0, 200) : null,
                     $a['branch_id'], $a['study_year'], $a['semester'], $a['is_active']];
            if ($isEdit) {
                db()->prepare('UPDATE e_contents SET title = ?, subject = ?, unit = ?, body = ?, source = ?,
                                      source_name = ?, branch_id = ?, study_year = ?, semester = ?,
                                      is_active = ?, updated_at = NOW()
                                WHERE id = ? AND faculty_id = ?')
                    ->execute(array_merge($vals, [$id, $faculty['id']]));
                set_flash('success', 'E-Content updated.');
            } else {
                db()->prepare('INSERT INTO e_contents (title, subject, unit, body, source, source_name,
                                      branch_id, study_year, semester, is_active, college_id, faculty_id)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute(array_merge($vals, [$collegeId, $faculty['id']]));
                set_flash('success', 'E-Content published to ' . assignment_audience_label($a) . '.');
            }
            redirect('faculty/econtent.php');
        }
    }
}

$pageTitle = $isEdit ? 'Edit E-Content' : 'New E-Content';
$activeNav = 'econtent';
require_once __DIR__ . '/includes/faculty_header.php';

$err = fn($k) => isset($errors[$k]) ? 'is-invalid' : '';
$msg = fn($k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . e($errors[$k]) . '</div>' : '';
$mode = isset($errors['pdf']) ? 'pdf' : 'text';
?>

<div class="el-hero el-hero-content mb-4">
  <div>
    <span class="el-hero-eyebrow"><i class="bi bi-journal-richtext me-1"></i>E-Content</span>
    <h3 class="mb-1"><?= $isEdit ? 'Edit study material' : 'Publish study material' ?></h3>
    <p class="mb-0">Type your notes or upload a PDF &mdash; students read it as clean on-screen text.
      It cannot be downloaded, printed or copied.</p>
  </div>
  <i class="bi bi-shield-lock el-hero-icon"></i>
</div>

<?php if ($notice): ?>
  <div class="alert alert-info border-0 d-flex gap-2"><i class="bi bi-magic fs-5"></i><div><?= $notice ?></div></div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="card border-0 el-form" novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int)$id ?>">
  <input type="hidden" name="source" value="<?= e($a['source']) ?>">
  <input type="hidden" name="source_name" value="<?= e($a['source_name']) ?>">
  <div class="card-body p-4">
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label" for="title">Title</label>
        <input type="text" class="form-control <?= $err('title') ?>" id="title" name="title"
               value="<?= e($a['title']) ?>" placeholder="e.g. Introduction to Semiconductors">
        <?= $msg('title') ?>
      </div>
      <div class="col-md-3">
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
      <div class="col-md-3">
        <label class="form-label" for="unit">Unit / Chapter <span class="text-muted-2 small">(optional)</span></label>
        <input type="text" class="form-control" id="unit" name="unit" value="<?= e((string)$a['unit']) ?>"
               placeholder="e.g. Unit 2">
      </div>

      <div class="col-12">
        <div class="el-section-title"><i class="bi bi-pencil-square"></i>Content
          <span>Choose how you want to add it.</span></div>
        <div class="el-mode-switch" role="tablist">
          <button type="button" class="<?= $mode === 'text' ? 'active' : '' ?>" data-mode="text">
            <i class="bi bi-keyboard"></i><span><strong>Type text</strong><small>Write or paste your notes</small></span></button>
          <button type="button" class="<?= $mode === 'pdf' ? 'active' : '' ?>" data-mode="pdf">
            <i class="bi bi-file-earmark-pdf"></i><span><strong>Upload PDF</strong><small>We convert it to text</small></span></button>
        </div>
      </div>

      <div class="col-12 <?= $mode === 'pdf' ? '' : 'd-none' ?>" data-pane="pdf">
        <label class="el-drop <?= $err('pdf') ?>" for="pdf">
          <i class="bi bi-cloud-arrow-up"></i>
          <strong>Choose a PDF</strong>
          <span class="small text-muted-2">Up to <?= ECONTENT_PDF_MAX_BYTES >> 20 ?> MB. Text PDFs only &mdash;
            a scanned (photo) PDF has no text to read.</span>
          <span class="el-drop-name" id="pdfName"></span>
          <input type="file" id="pdf" name="pdf" accept="application/pdf,.pdf" class="visually-hidden">
        </label>
        <?= $msg('pdf') ?>
        <div class="d-flex justify-content-end mt-3">
          <button class="btn btn-primary" name="convert" value="1">
            <i class="bi bi-magic me-1"></i>Convert to text</button>
        </div>
      </div>

      <div class="col-12 <?= $mode === 'text' ? '' : 'd-none' ?>" data-pane="text">
        <div class="el-editor <?= isset($errors['body']) ? 'is-invalid' : '' ?>">
          <div class="el-toolbar">
            <button type="button" data-wrap="# " data-line title="Heading"><i class="bi bi-type-h1"></i></button>
            <button type="button" data-wrap="## " data-line title="Sub heading"><i class="bi bi-type-h2"></i></button>
            <button type="button" data-wrap="**" title="Bold"><i class="bi bi-type-bold"></i></button>
            <button type="button" data-wrap="_" title="Italic"><i class="bi bi-type-italic"></i></button>
            <button type="button" data-wrap="- " data-line title="Bullet list"><i class="bi bi-list-ul"></i></button>
            <button type="button" data-wrap="1. " data-line title="Numbered list"><i class="bi bi-list-ol"></i></button>
            <span class="ms-auto small text-muted-2" id="wordCount"></span>
          </div>
          <textarea id="body" name="body" rows="18"
                    placeholder="# Chapter heading&#10;&#10;Write a paragraph here...&#10;&#10;- a bullet point&#10;- another point"><?= e($a['body']) ?></textarea>
        </div>
        <?= $msg('body') ?>
        <?php if ($a['source'] === 'pdf' && $a['source_name']): ?>
          <div class="small text-muted-2 mt-2"><i class="bi bi-file-earmark-pdf me-1"></i>Text from
            <?= e($a['source_name']) ?> &middot; the PDF file itself is not kept.</div>
        <?php endif; ?>
      </div>

      <?php require __DIR__ . '/includes/audience_fields.php'; ?>
    </div>
  </div>
  <div class="card-footer bg-transparent d-flex justify-content-end gap-2 p-3" data-pane-footer>
    <a href="<?= url('faculty/econtent.php') ?>" class="btn btn-light">Cancel</a>
    <button class="btn btn-primary px-4" id="publishBtn"><i class="bi bi-send me-1"></i><?= $isEdit ? 'Save Changes' : 'Publish' ?></button>
  </div>
</form>

<script>
(function () {
  var panes = document.querySelectorAll('[data-pane]');
  var pdf = document.getElementById('pdf');
  var publish = document.getElementById('publishBtn');
  function setMode(m) {
    document.querySelectorAll('.el-mode-switch button').forEach(function (b) {
      b.classList.toggle('active', b.dataset.mode === m);
    });
    panes.forEach(function (p) { p.classList.toggle('d-none', p.dataset.pane !== m); });
    publish.classList.toggle('d-none', m === 'pdf');
    if (m === 'text') { pdf.value = ''; document.getElementById('pdfName').textContent = ''; }
  }
  document.querySelectorAll('.el-mode-switch button').forEach(function (b) {
    b.addEventListener('click', function () { setMode(b.dataset.mode); });
  });
  setMode(<?= json_encode($mode) ?>);
  pdf.addEventListener('change', function () {
    document.getElementById('pdfName').textContent = pdf.files[0] ? pdf.files[0].name : '';
  });

  var ta = document.getElementById('body'), wc = document.getElementById('wordCount');
  function count() {
    var n = (ta.value.match(/\S+/g) || []).length;
    wc.textContent = n.toLocaleString() + ' words · ' + Math.max(1, Math.ceil(n / 200)) + ' min read';
  }
  ta.addEventListener('input', count); count();
  document.querySelectorAll('.el-toolbar [data-wrap]').forEach(function (b) {
    b.addEventListener('click', function () {
      var w = b.dataset.wrap, s = ta.selectionStart, e = ta.selectionEnd, v = ta.value;
      if (b.hasAttribute('data-line')) {
        var ls = v.lastIndexOf('\n', s - 1) + 1;
        ta.value = v.slice(0, ls) + w + v.slice(ls);
        ta.selectionStart = ta.selectionEnd = e + w.length;
      } else {
        var sel = v.slice(s, e) || 'text';
        ta.value = v.slice(0, s) + w + sel + w + v.slice(e);
        ta.selectionStart = s + w.length; ta.selectionEnd = s + w.length + sel.length;
      }
      ta.focus(); count();
    });
  });
})();
</script>

<?php require_once __DIR__ . '/includes/faculty_footer.php'; ?>
