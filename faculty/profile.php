<?php
/**
 * Faculty - my profile: view and edit the professional details, and change
 * the account password.
 */
require_once __DIR__ . '/../includes/auth.php';
$faculty = require_faculty();

$profileErrors = [];
$passwordErrors = [];
$openTab = $_GET['tab'] ?? 'profile';

$designations = ['Professor', 'Associate Professor', 'Assistant Professor',
                 'Senior Lecturer', 'Lecturer', 'Visiting Faculty', 'Lab Instructor'];

$f = $faculty;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    /* ------------------------- Update profile ------------------------- */
    if (post('action') === 'profile') {
        $openTab = 'profile';

        foreach (['name', 'employee_code', 'email', 'phone', 'department', 'designation',
                  'qualification', 'specialization', 'experience_years', 'joining_date',
                  'about', 'branch_id'] as $key) {
            $f[$key] = post($key);
        }

        $branchId = (int)$f['branch_id'];
        if ($branchId && !branch_belongs_to($branchId, (int)$faculty['college_id'])) {
            $profileErrors['branch_id'] = 'Choose a branch offered by your college.';
        }

        if (mb_strlen($f['name']) < 3) {
            $profileErrors['name'] = 'Please enter your full name.';
        }
        if (!filter_var($f['email'], FILTER_VALIDATE_EMAIL)) {
            $profileErrors['email'] = 'Please enter a valid email address.';
        }
        if ($f['phone'] !== '' && !preg_match('/^[0-9+\-\s]{6,20}$/', $f['phone'])) {
            $profileErrors['phone'] = 'Please enter a valid phone number.';
        }
        if ($f['department'] === '') {
            $profileErrors['department'] = 'Enter your department.';
        }
        if ($f['qualification'] === '') {
            $profileErrors['qualification'] = 'Enter your highest qualification.';
        }
        if ($f['experience_years'] !== ''
            && (!is_numeric($f['experience_years']) || (float)$f['experience_years'] < 0
                || (float)$f['experience_years'] > 60)) {
            $profileErrors['experience_years'] = 'Experience must be between 0 and 60 years.';
        }

        // Email and employee ID stay unique across the faculty table.
        if (!$profileErrors) {
            $st = db()->prepare('SELECT id FROM faculty WHERE email = ? AND id <> ?');
            $st->execute([$f['email'], $faculty['id']]);
            if ($st->fetch()) {
                $profileErrors['email'] = 'Another faculty account already uses that email.';
            }
            if ($f['employee_code'] !== '') {
                $st = db()->prepare('SELECT id FROM faculty WHERE employee_code = ? AND id <> ?');
                $st->execute([$f['employee_code'], $faculty['id']]);
                if ($st->fetch()) {
                    $profileErrors['employee_code'] = 'That employee ID is already in use.';
                }
            }
        }

        if (!$profileErrors) {
            db()->prepare(
                'UPDATE faculty SET name = ?, employee_code = ?, email = ?, phone = ?,
                        department = ?, branch_id = ?, designation = ?, qualification = ?,
                        specialization = ?, experience_years = ?, joining_date = ?, about = ?
                  WHERE id = ?'
            )->execute([
                $f['name'],
                $f['employee_code'] !== '' ? $f['employee_code'] : null,
                $f['email'],
                $f['phone'] !== '' ? $f['phone'] : null,
                $f['department'],
                $branchId ?: null,
                $f['designation'] !== '' ? $f['designation'] : null,
                $f['qualification'],
                $f['specialization'] !== '' ? $f['specialization'] : null,
                $f['experience_years'] !== '' ? (float)$f['experience_years'] : null,
                $f['joining_date'] !== '' ? date('Y-m-d', strtotime($f['joining_date'])) : null,
                $f['about'] !== '' ? $f['about'] : null,
                $faculty['id'],
            ]);
            $_SESSION['faculty_name'] = $f['name'];
            set_flash('success', 'Your profile has been updated.');
            redirect('faculty/profile.php');
        }
    }

    /* ------------------------ Change password ------------------------ */
    if (post('action') === 'password') {
        $openTab = 'password';
        $passwordErrors = change_account_password(
            'faculty', (int)$faculty['id'],
            post('current_password'), post('new_password'), post('confirm_password')
        );

        if (!$passwordErrors) {
            // A password change re-issues the session id.
            session_regenerate_id(true);
            set_flash('success', 'Your password has been changed.');
            redirect('faculty/profile.php');
        }
    }
}

// Teaching footprint, shown on the profile card.
$st = db()->prepare(
    'SELECT (SELECT COUNT(*) FROM exams WHERE faculty_id = :f1) AS exams,
            (SELECT COUNT(*) FROM questions q JOIN exams e ON e.id = q.exam_id
              WHERE e.faculty_id = :f2) AS questions,
            (SELECT COUNT(DISTINCT a.student_id) FROM exam_assignments a
               JOIN exams e ON e.id = a.exam_id WHERE e.faculty_id = :f3) AS students,
            (SELECT COUNT(*) FROM results r JOIN exams e ON e.id = r.exam_id
              WHERE e.faculty_id = :f4) AS papers'
);
$st->execute(['f1' => $faculty['id'], 'f2' => $faculty['id'],
              'f3' => $faculty['id'], 'f4' => $faculty['id']]);
$stats = $st->fetch();

$pageTitle = 'My Profile';
$activeNav = 'profile';
require_once __DIR__ . '/includes/faculty_header.php';
?>

<!-- ------------------------- Profile header ------------------------- -->
<div class="card border-0 profile-header mb-4">
  <div class="profile-header-bg"></div>
  <div class="card-body position-relative">
    <div class="d-flex flex-wrap align-items-start gap-3">
      <div class="avatar-xl"><?= e(initials($faculty['name'])) ?></div>
      <div class="flex-grow-1">
        <h3 class="mb-1"><?= e($faculty['name']) ?></h3>
        <div class="text-muted-2">
          <?= e($faculty['designation'] ?: 'Faculty') ?>
          <?php if ($faculty['department']): ?>
            &middot; <?= e($faculty['department']) ?>
          <?php endif; ?>
        </div>
        <div class="d-flex flex-wrap gap-2 mt-2">
          <?php if ($faculty['employee_code']): ?>
            <span class="chip chip-soft"><i class="bi bi-person-vcard"></i><?= e($faculty['employee_code']) ?></span>
          <?php endif; ?>
          <span class="chip chip-soft"><i class="bi bi-at"></i><?= e($faculty['username']) ?></span>
          <span class="chip chip-soft"><i class="bi bi-envelope"></i><?= e($faculty['email']) ?></span>
          <?php if ($faculty['status'] === 'approved'): ?>
            <span class="chip chip-verified"><i class="bi bi-patch-check-fill"></i>Verified</span>
          <?php endif; ?>
        </div>
      </div>
      <div class="row g-2 text-center" style="min-width:280px">
        <?php foreach ([
          ['journal-text', 'Exams', (int)$stats['exams']],
          ['patch-question', 'Questions', (int)$stats['questions']],
          ['people', 'Students', (int)$stats['students']],
          ['clipboard-data', 'Papers', (int)$stats['papers']],
        ] as [$icon, $label, $value]): ?>
          <div class="col-3">
            <div class="stat-card p-2">
              <div class="value fs-5"><?= $value ?></div>
              <div class="label"><?= e($label) ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<!-- ----------------------------- Tabs ------------------------------- -->
<ul class="nav nav-pills-premium mb-3" role="tablist">
  <li><button class="nav-pill <?= $openTab === 'profile' ? 'active' : '' ?>"
              data-bs-toggle="tab" data-bs-target="#tabProfile" type="button">
    <i class="bi bi-person-lines-fill me-1"></i>Profile details</button></li>
  <li><button class="nav-pill <?= $openTab === 'password' ? 'active' : '' ?>"
              data-bs-toggle="tab" data-bs-target="#tabPassword" type="button">
    <i class="bi bi-shield-lock me-1"></i>Change password</button></li>
</ul>

<div class="tab-content">
  <!-- ======================= Profile details ======================= -->
  <div class="tab-pane fade <?= $openTab === 'profile' ? 'show active' : '' ?>" id="tabProfile">
    <form method="post" novalidate class="needs-validation">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="profile">

      <div class="card border-0 mb-3">
        <div class="card-body p-4">
          <div class="form-section-head">
            <span class="form-section-num"><i class="bi bi-person"></i></span>
            <div><h5 class="mb-0">Personal details</h5></div>
          </div>

          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label" for="name">Full Name</label>
              <input type="text" id="name" name="name" required
                     class="form-control <?= isset($profileErrors['name']) ? 'is-invalid' : '' ?>"
                     value="<?= e($f['name']) ?>">
              <div class="invalid-feedback"><?= e($profileErrors['name'] ?? 'Enter your name.') ?></div>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="employee_code">Employee ID</label>
              <input type="text" id="employee_code" name="employee_code"
                     class="form-control <?= isset($profileErrors['employee_code']) ? 'is-invalid' : '' ?>"
                     value="<?= e($f['employee_code'] ?? '') ?>">
              <div class="invalid-feedback"><?= e($profileErrors['employee_code'] ?? '') ?></div>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="email">Official Email</label>
              <input type="email" id="email" name="email" required
                     class="form-control <?= isset($profileErrors['email']) ? 'is-invalid' : '' ?>"
                     value="<?= e($f['email']) ?>">
              <div class="invalid-feedback"><?= e($profileErrors['email'] ?? 'Enter a valid email.') ?></div>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="phone">Contact Number</label>
              <input type="tel" id="phone" name="phone"
                     class="form-control <?= isset($profileErrors['phone']) ? 'is-invalid' : '' ?>"
                     value="<?= e($f['phone'] ?? '') ?>">
              <div class="invalid-feedback"><?= e($profileErrors['phone'] ?? '') ?></div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Username</label>
              <input type="text" class="form-control" value="<?= e($faculty['username']) ?>" disabled>
              <div class="form-text">The username cannot be changed.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Member since</label>
              <input type="text" class="form-control"
                     value="<?= e(format_datetime($faculty['created_at'])) ?>" disabled>
            </div>
          </div>
        </div>
      </div>

      <div class="card border-0 mb-3">
        <div class="card-body p-4">
          <div class="form-section-head">
            <span class="form-section-num"><i class="bi bi-mortarboard"></i></span>
            <div><h5 class="mb-0">Academic &amp; professional</h5></div>
          </div>

          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label" for="department">Department</label>
              <input type="text" id="department" name="department" required
                     class="form-control <?= isset($profileErrors['department']) ? 'is-invalid' : '' ?>"
                     value="<?= e($f['department'] ?? '') ?>">
              <div class="invalid-feedback">
                <?= e($profileErrors['department'] ?? 'Enter your department.') ?>
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="designation">Designation</label>
              <select id="designation" name="designation" class="form-select">
                <option value="">-- select --</option>
                <?php foreach ($designations as $d): ?>
                  <option value="<?= e($d) ?>" <?= ($f['designation'] ?? '') === $d ? 'selected' : '' ?>>
                    <?= e($d) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-md-6">
              <label class="form-label">College</label>
              <input type="text" class="form-control" disabled
                     value="<?= e(current_college()['name'] ?? 'Not set') ?>">
            </div>

            <div class="col-md-6">
              <label class="form-label" for="branch_id">Primary Branch</label>
              <select class="form-select <?= isset($profileErrors['branch_id']) ? 'is-invalid' : '' ?>"
                      id="branch_id" name="branch_id">
                <option value="">-- none --</option>
                <?php foreach (get_branches((int)$faculty['college_id']) as $b): ?>
                  <option value="<?= (int)$b['id'] ?>"
                    <?= (int)($f['branch_id'] ?? 0) === (int)$b['id'] ? 'selected' : '' ?>>
                    <?= e($b['name']) ?> (<?= e($b['code']) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="invalid-feedback"><?= e($profileErrors['branch_id'] ?? '') ?></div>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="qualification">Highest Qualification</label>
              <input type="text" id="qualification" name="qualification" required
                     class="form-control <?= isset($profileErrors['qualification']) ? 'is-invalid' : '' ?>"
                     value="<?= e($f['qualification'] ?? '') ?>">
              <div class="invalid-feedback">
                <?= e($profileErrors['qualification'] ?? 'Enter your qualification.') ?>
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="specialization">Subjects / Specialisation</label>
              <input type="text" id="specialization" name="specialization" class="form-control"
                     value="<?= e($f['specialization'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label" for="experience_years">Teaching Experience (years)</label>
              <input type="number" step="0.5" min="0" max="60" id="experience_years"
                     name="experience_years"
                     class="form-control <?= isset($profileErrors['experience_years']) ? 'is-invalid' : '' ?>"
                     value="<?= e($f['experience_years'] ?? '') ?>">
              <div class="invalid-feedback"><?= e($profileErrors['experience_years'] ?? '') ?></div>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="joining_date">Date of Joining</label>
              <input type="date" id="joining_date" name="joining_date" class="form-control"
                     value="<?= e($f['joining_date'] ?? '') ?>">
            </div>
            <div class="col-12">
              <label class="form-label" for="about">About you</label>
              <textarea id="about" name="about" rows="3" class="form-control"
                        maxlength="1000"><?= e($f['about'] ?? '') ?></textarea>
            </div>
          </div>
        </div>
      </div>

      <div class="card border-0">
        <div class="card-body d-flex gap-2">
          <button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Save Changes</button>
          <a href="<?= url('faculty/index.php') ?>" class="btn btn-outline-secondary">Cancel</a>
        </div>
      </div>
    </form>
  </div>

  <!-- ======================= Change password ======================= -->
  <div class="tab-pane fade <?= $openTab === 'password' ? 'show active' : '' ?>" id="tabPassword">
    <div class="row g-4">
      <div class="col-lg-7">
        <form method="post" novalidate class="needs-validation card border-0">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="password">

          <div class="card-body p-4">
            <div class="form-section-head">
              <span class="form-section-num"><i class="bi bi-shield-lock"></i></span>
              <div>
                <h5 class="mb-0">Change password</h5>
                <span class="small text-muted-2">
                  You will stay signed in on this device.
                </span>
              </div>
            </div>

            <?php if ($passwordErrors): ?>
              <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle-fill me-1"></i>
                <?= e(reset($passwordErrors)) ?>
              </div>
            <?php endif; ?>

            <div class="mb-3">
              <label class="form-label" for="current_password">Current Password</label>
              <div class="input-group">
                <span class="input-group-text bg-white"><i class="bi bi-lock"></i></span>
                <input type="password" id="current_password" name="current_password" required
                       class="form-control <?= isset($passwordErrors['current_password']) ? 'is-invalid' : '' ?>">
                <button class="btn btn-outline-secondary" type="button"
                        data-toggle-password="#current_password" aria-label="Show password">
                  <i class="bi bi-eye"></i>
                </button>
                <div class="invalid-feedback">
                  <?= e($passwordErrors['current_password'] ?? 'Enter your current password.') ?>
                </div>
              </div>
            </div>

            <div class="mb-3">
              <label class="form-label" for="new_password">New Password</label>
              <div class="input-group">
                <span class="input-group-text bg-white"><i class="bi bi-key"></i></span>
                <input type="password" id="new_password" name="new_password" required
                       data-strength="#pwMeter"
                       class="form-control <?= isset($passwordErrors['new_password']) ? 'is-invalid' : '' ?>"
                       placeholder="Min. <?= MIN_PASSWORD_LENGTH ?> characters">
                <button class="btn btn-outline-secondary" type="button"
                        data-toggle-password="#new_password" aria-label="Show password">
                  <i class="bi bi-eye"></i>
                </button>
                <div class="invalid-feedback">
                  <?= e($passwordErrors['new_password'] ?? 'At least ' . MIN_PASSWORD_LENGTH . ' characters.') ?>
                </div>
              </div>
              <div class="pw-meter mt-2" id="pwMeter">
                <div class="pw-meter-track"><span></span></div>
                <span class="pw-meter-label">Password strength</span>
              </div>
            </div>

            <div class="mb-4">
              <label class="form-label" for="confirm_password">Confirm New Password</label>
              <div class="input-group">
                <span class="input-group-text bg-white"><i class="bi bi-lock-fill"></i></span>
                <input type="password" id="confirm_password" name="confirm_password" required
                       class="form-control <?= isset($passwordErrors['confirm_password']) ? 'is-invalid' : '' ?>">
                <div class="invalid-feedback">
                  <?= e($passwordErrors['confirm_password'] ?? 'Passwords must match.') ?>
                </div>
              </div>
            </div>

            <button class="btn btn-primary">
              <i class="bi bi-shield-check me-1"></i>Update Password
            </button>
          </div>
        </form>
      </div>

      <div class="col-lg-5">
        <div class="card border-0 h-100">
          <div class="card-body p-4">
            <h6 class="mb-3"><i class="bi bi-info-circle me-1"></i>Account security</h6>
            <ul class="info-list">
              <li>
                <span>Password last changed</span>
                <strong><?= $faculty['password_changed_at']
                            ? e(format_datetime($faculty['password_changed_at']))
                            : 'Never' ?></strong>
              </li>
              <li>
                <span>Account status</span>
                <strong class="text-success text-capitalize"><?= e($faculty['status']) ?></strong>
              </li>
              <li>
                <span>Registered on</span>
                <strong><?= e(format_datetime($faculty['created_at'])) ?></strong>
              </li>
            </ul>
            <hr>
            <p class="small text-muted-2 mb-0">
              Choose a password you do not use anywhere else. A mix of upper and lower
              case letters, digits and a symbol makes it far harder to guess.
            </p>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/faculty_footer.php'; ?>
