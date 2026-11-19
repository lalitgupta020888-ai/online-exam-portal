<?php
/**
 * "Publish to" fields shared by the E-Content and E-Lecture forms.
 * Expects $a (with branch_id / study_year / semester), $branches, $err, $msg.
 */
?>
<div class="col-12">
  <div class="el-section-title"><i class="bi bi-people"></i>Publish to
    <span>Every student of the chosen class gets it. Leave "All" to include everyone.</span></div>
</div>
<div class="col-md-4">
  <label class="form-label" for="branch_id">Branch</label>
  <select class="form-select <?= $err('branch_id') ?>" id="branch_id" name="branch_id">
    <option value="0">All branches</option>
    <?php foreach ($branches as $b): ?>
      <option value="<?= (int)$b['id'] ?>" <?= (int)$a['branch_id'] === (int)$b['id'] ? 'selected' : '' ?>>
        <?= e($b['code']) ?> - <?= e($b['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <?= $msg('branch_id') ?>
</div>
<div class="col-6 col-md-4">
  <label class="form-label" for="study_year">Year</label>
  <select class="form-select" id="study_year" name="study_year">
    <option value="0">All years</option>
    <?php foreach (study_years() as $v => $label): ?>
      <option value="<?= $v ?>" <?= (int)$a['study_year'] === $v ? 'selected' : '' ?>><?= e($label) ?></option>
    <?php endforeach; ?>
  </select>
</div>
<div class="col-6 col-md-4">
  <label class="form-label" for="semester">Semester</label>
  <select class="form-select" id="semester" name="semester">
    <option value="0">All semesters</option>
    <?php foreach (semesters() as $v => $label): ?>
      <option value="<?= $v ?>" <?= (int)$a['semester'] === $v ? 'selected' : '' ?>><?= e($label) ?></option>
    <?php endforeach; ?>
  </select>
</div>
<div class="col-12">
  <div class="form-check form-switch">
    <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active"
           value="1" <?= (int)$a['is_active'] ? 'checked' : '' ?>>
    <label class="form-check-label" for="is_active">Visible to students</label>
  </div>
</div>
