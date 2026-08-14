<?php
/**
 * Student - my account: edit personal details and change the password.
 */
require_once __DIR__ . '/includes/auth.php';

$student = require_student();

$profileErrors  = [];
$passwordErrors = [];
$openTab = $_GET['tab'] ?? 'profile';

$s = $student;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    /* ------------------------- Update profile ------------------------- */
    if (post('action') === 'profile') {
        $openTab = 'profile';
        foreach (['name', 'email', 'phone', 'branch_id', 'study_year', 'semester',
                  'enrollment_no', 'admission_year'] as $k) {
            $s[$k] = post($k);
        }

        if (mb_strlen($s['name']) < 3) {
            $profileErrors['name'] = 'Please enter your full name (at least 3 characters).';
        }
        if (!filter_var($s['email'], FILTER_VALIDATE_EMAIL)) {
            $profileErrors['email'] = 'Please enter a valid email address.';
        }
        if ($s['phone'] !== '' && !preg_match('/^[0-9+\-\s]{6,20}$/', $s['phone'])) {
            $profileErrors['phone'] = 'Please enter a valid phone number.';
        }

        // The college itself is fixed - only the class within it can change.
        $branchId = (int)$s['branch_id'];
        if (!$branchId || !branch_belongs_to($branchId, (int)$student['college_id'])) {
            $profileErrors['branch_id'] = 'Choose a branch offered by your college.';
        }
        $year = (int)$s['study_year'];
        if (!isset(study_years()[$year])) {
            $profileErrors['study_year'] = 'Choose your current year.';
        }
        $sem = (int)$s['semester'];
        if ($sem < 1 || $sem > 10) {
            $profileErrors['semester'] = 'Choose your current semester.';
        }

        if (!$profileErrors) {
            $st = db()->prepare('SELECT id FROM students WHERE email = ? AND id <> ?');
            $st->execute([$s['email'], $student['id']]);
            if ($st->fetch()) {
                $profileErrors['email'] = 'Another account already uses that email address.';
            }
            if ($s['enrollment_no'] !== '') {
                $st = db()->prepare('SELECT id FROM students
                                      WHERE enrollment_no = ? AND college_id = ? AND id <> ?');
                $st->execute([$s['enrollment_no'], $student['college_id'], $student['id']]);
                if ($st->fetch()) {
                    $profileErrors['enrollment_no'] = 'That enrolment number is already in use.';
                }
            }
        }

        if (!$profileErrors) {
            db()->prepare(
                'UPDATE students SET name = ?, email = ?, phone = ?, branch_id = ?,
                        study_year = ?, semester = ?, enrollment_no = ?, admission_year = ?
                  WHERE id = ?'
            )->execute([
                $s['name'], $s['email'], $s['phone'] !== '' ? $s['phone'] : null,
                $branchId, $year, $sem,
                $s['enrollment_no'] !== '' ? $s['enrollment_no'] : null,
                $s['admission_year'] !== '' ? (int)$s['admission_year'] : null,
                $student['id'],
            ]);
            $_SESSION['student_name'] = $s['name'];
            set_flash('success', 'Your details have been updated.');
            redirect('profile.php');
        }
    }

    /* ------------------------ Change password ------------------------ */
    if (post('action') === 'password') {
        $openTab = 'password';
        $passwordErrors = change_account_password(
            'students', (int)$student['id'],
            post('current_password'), post('new_password'), post('confirm_password')
        );

        if (!$passwordErrors) {
            session_regenerate_id(true);
            set_flash('success', 'Your password has been changed.');
            redirect('profile.php');
        }
    }
}

// A quick summary of this student's exam record for the profile header.
$st = db()->prepare(
    'SELECT COUNT(*) AS taken,
            COALESCE(AVG(percentage),0) AS avg_pct,
            COALESCE(MAX(percentage),0) AS best,
            SUM(CASE WHEN status = "PASS" THEN 1 ELSE 0 END) AS passed
       FROM results WHERE student_id = ?'
);
$st->execute([$student['id']]);
$stats = $st->fetch();

$allotted = (int)db()->query(
    'SELECT COUNT(*) FROM exam_assignments WHERE student_id = ' . (int)$student['id']
)->fetchColumn();

$college = get_college((int)$student['college_id']);
$branches = get_branches((int)$student['college_id']);

$pageTitle = 'My Account';
$activeNav = '';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-4 py-lg-5">

  <!-- ------------------------ Profile header ------------------------ -->
  <div class="card border-0 profile-header mb-4">
    <div class="profile-header-bg"></div>
    <div class="card-body position-relative">
      <div class="d-flex flex-wrap align-items-start gap-3">
        <div class="avatar-xl"><?= e(initials($student['name'])) ?></div>
        <div class="flex-grow-1">
          <span class="eyebrow">Student account</span>
          <h3 class="mb-1"><?= e($student['name']) ?></h3>
          <?php if ($college): ?>
            <div class="d-flex align-items-center gap-2 mb-2">
              <?= college_badge($college, 26) ?>
              <span class="text-muted-2 small"><?= e($college['name']) ?></span>
            </div>
          <?php endif; ?>
          <div class="d-flex flex-wrap gap-2 mt-2">
            <?php if ($student['branch_id']): ?>
              <span class="class-chip"><i class="bi bi-diagram-3"></i>
                <?= e(branch_name((int)$student['branch_id'])) ?></span>
            <?php endif; ?>
            <?php if ($student['study_year']): ?>
              <span class="class-chip year"><?= e(year_label((int)$student['study_year'])) ?></span>
            <?php endif; ?>
            <?php if ($student['semester']): ?>
              <span class="class-chip sem">Semester <?= (int)$student['semester'] ?></span>
            <?php endif; ?>
            <?php if ($student['enrollment_no']): ?>
              <span class="chip chip-soft"><i class="bi bi-card-list"></i><?= e($student['enrollment_no']) ?></span>
            <?php endif; ?>
            <span class="chip chip-soft"><i class="bi bi-envelope"></i><?= e($student['email']) ?></span>
            <?php if ($student['phone']): ?>
              <span class="chip chip-soft"><i class="bi bi-telephone"></i><?= e($student['phone']) ?></span>
            <?php endif; ?>
            <span class="chip chip-soft">
              <i class="bi bi-calendar-event"></i>Joined <?= e(format_datetime($student['created_at'])) ?>
            </span>
          </div>
        </div>
        <div class="row g-2 text-center" style="min-width:290px">
          <?php foreach ([
            ['journal-bookmark', 'Allotted', $allotted],
            ['journal-check',    'Taken',    (int)$stats['taken']],
            ['patch-check',      'Passed',   (int)$stats['passed']],
            ['graph-up',         'Average',  num((float)$stats['avg_pct']) . '%'],
          ] as [$icon, $label, $value]): ?>
            <div class="col-3">
              <div class="stat-card p-2">
                <div class="value fs-6"><?= e($value) ?></div>
                <div class="label"><?= e($label) ?></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- ------------------------------ Tabs ---------------------------- -->
  <ul class="nav nav-pills-premium mb-3" role="tablist">
    <li><button class="nav-pill <?= $openTab === 'profile' ? 'active' : '' ?>"
                data-bs-toggle="tab" data-bs-target="#tabProfile" type="button">
      <i class="bi bi-person-lines-fill me-1"></i>My details</button></li>
    <li><button class="nav-pill <?= $openTab === 'password' ? 'active' : '' ?>"
                data-bs-toggle="tab" data-bs-target="#tabPassword" type="button">
      <i class="bi bi-shield-lock me-1"></i>Change password</button></li>
  </ul>

  <div class="tab-content">
    <!-- ========================= My details ========================= -->
    <div class="tab-pane fade <?= $openTab === 'profile' ? 'show active' : '' ?>" id="tabProfile">
      <div class="row g-4">
        <div class="col-lg-7">
          <form method="post" novalidate class="needs-validation card border-0">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="profile">

            <div class="card-body p-4">
              <div class="form-section-head">
                <span class="form-section-num"><i class="bi bi-person"></i></span>
                <div>
                  <h5 class="mb-0">Personal details</h5>
                  <span class="small text-muted-2">Your name appears on every result sheet.</span>
                </div>
              </div>

              <div class="mb-3">
                <label class="form-label" for="name">Full Name</label>
                <div class="input-group">
                  <span class="input-group-text bg-white"><i class="bi bi-person"></i></span>
                  <input type="text" id="name" name="name" required minlength="3"
                         class="form-control <?= isset($profileErrors['name']) ? 'is-invalid' : '' ?>"
                         value="<?= e($s['name']) ?>">
                  <div class="invalid-feedback">
                    <?= e($profileErrors['name'] ?? 'Enter your full name.') ?>
                  </div>
                </div>
              </div>

              <div class="mb-3">
                <label class="form-label" for="email">Email Address</label>
                <div class="input-group">
                  <span class="input-group-text bg-white"><i class="bi bi-envelope"></i></span>
                  <input type="email" id="email" name="email" required
                         class="form-control <?= isset($profileErrors['email']) ? 'is-invalid' : '' ?>"
                         value="<?= e($s['email']) ?>">
                  <div class="invalid-feedback">
                    <?= e($profileErrors['email'] ?? 'Enter a valid email address.') ?>
                  </div>
                </div>
                <div class="form-text">This is also your sign in name.</div>
              </div>

              <div class="mb-4">
                <label class="form-label" for="phone">Contact Number</label>
                <div class="input-group">
                  <span class="input-group-text bg-white"><i class="bi bi-telephone"></i></span>
                  <input type="tel" id="phone" name="phone"
                         class="form-control <?= isset($profileErrors['phone']) ? 'is-invalid' : '' ?>"
                         value="<?= e($s['phone'] ?? '') ?>" placeholder="9876543210">
                  <div class="invalid-feedback">
                    <?= e($profileErrors['phone'] ?? 'Enter a valid phone number.') ?>
                  </div>
                </div>
              </div>

              <div class="form-section-head">
                <span class="form-section-num"><i class="bi bi-diagram-3"></i></span>
                <div>
                  <h5 class="mb-0">My class</h5>
                  <span class="small text-muted-2">
                    Keep this current - papers are allotted branch and semester wise.
                  </span>
                </div>
              </div>

              <div class="mb-3">
                <label class="form-label">College</label>
                <input type="text" class="form-control"
                       value="<?= e($college['name'] ?? 'Not set') ?>" disabled>
                <div class="form-text">Contact your administrator to move to another college.</div>
              </div>

              <div class="row g-3 mb-3">
                <div class="col-md-6">
                  <label class="form-label" for="branch_id">Branch</label>
                  <select class="form-select <?= isset($profileErrors['branch_id']) ? 'is-invalid' : '' ?>"
                          id="branch_id" name="branch_id" required>
                    <option value="">-- select --</option>
                    <?php foreach ($branches as $b): ?>
                      <option value="<?= (int)$b['id'] ?>"
                        <?= (int)($s['branch_id'] ?? 0) === (int)$b['id'] ? 'selected' : '' ?>>
                        <?= e($b['name']) ?> (<?= e($b['code']) ?>)
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <div class="invalid-feedback">
                    <?= e($profileErrors['branch_id'] ?? 'Choose your branch.') ?>
                  </div>
                </div>

                <div class="col-md-3">
                  <label class="form-label" for="study_year">Year</label>
                  <select class="form-select <?= isset($profileErrors['study_year']) ? 'is-invalid' : '' ?>"
                          id="study_year" name="study_year" required>
                    <option value="">--</option>
                    <?php foreach (study_years() as $v => $label): ?>
                      <option value="<?= $v ?>" <?= (int)($s['study_year'] ?? 0) === $v ? 'selected' : '' ?>>
                        <?= e($label) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <div class="invalid-feedback">
                    <?= e($profileErrors['study_year'] ?? 'Choose your year.') ?>
                  </div>
                </div>

                <div class="col-md-3">
                  <label class="form-label" for="semester">Semester</label>
                  <select class="form-select <?= isset($profileErrors['semester']) ? 'is-invalid' : '' ?>"
                          id="semester" name="semester" required>
                    <option value="">--</option>
                    <?php foreach (semesters() as $v => $label): ?>
                      <option value="<?= $v ?>" <?= (int)($s['semester'] ?? 0) === $v ? 'selected' : '' ?>>
                        <?= $v ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <div class="invalid-feedback">
                    <?= e($profileErrors['semester'] ?? 'Choose your semester.') ?>
                  </div>
                </div>
              </div>

              <div class="row g-3 mb-4">
                <div class="col-md-6">
                  <label class="form-label" for="enrollment_no">Enrolment / Roll Number</label>
                  <input type="text" id="enrollment_no" name="enrollment_no"
                         class="form-control <?= isset($profileErrors['enrollment_no']) ? 'is-invalid' : '' ?>"
                         value="<?= e($s['enrollment_no'] ?? '') ?>">
                  <div class="invalid-feedback"><?= e($profileErrors['enrollment_no'] ?? '') ?></div>
                </div>
                <div class="col-md-6">
                  <label class="form-label" for="admission_year">Admission Year</label>
                  <input type="number" id="admission_year" name="admission_year"
                         min="1990" max="<?= (int)date('Y') + 1 ?>" class="form-control"
                         value="<?= e($s['admission_year'] ?? '') ?>">
                </div>
              </div>

              <button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Save Changes</button>
            </div>
          </form>
        </div>

        <div class="col-lg-5">
          <div class="card border-0 h-100">
            <div class="card-body p-4">
              <h6 class="mb-3"><i class="bi bi-clipboard-data me-1"></i>My exam record</h6>
              <ul class="info-list">
                <li><span>Papers allotted to me</span><strong><?= $allotted ?></strong></li>
                <li><span>Examinations taken</span><strong><?= (int)$stats['taken'] ?></strong></li>
                <li><span>Passed</span><strong><?= (int)$stats['passed'] ?></strong></li>
                <li><span>Best score</span><strong><?= num((float)$stats['best']) ?>%</strong></li>
                <li><span>Average score</span><strong><?= num((float)$stats['avg_pct']) ?>%</strong></li>
              </ul>
              <hr>
              <div class="d-grid gap-2">
                <a href="<?= url('dashboard.php') ?>" class="btn btn-outline-primary btn-sm">
                  <i class="bi bi-journal-text me-1"></i>My Exams
                </a>
                <a href="<?= url('results.php') ?>" class="btn btn-outline-secondary btn-sm">
                  <i class="bi bi-bar-chart me-1"></i>My Results
                </a>
              </div>
            </div>
          </div>
        </div>
      </div>
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
                  <span class="small text-muted-2">You will stay signed in on this device.</span>
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
                <div class="form-text">
                  Forgotten it? <a href="<?= url('forgot_password.php') ?>">Reset it by email</a>.
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
                  <strong><?= $student['password_changed_at']
                              ? e(format_datetime($student['password_changed_at']))
                              : 'Never' ?></strong>
                </li>
                <li><span>Registered on</span>
                  <strong><?= e(format_datetime($student['created_at'])) ?></strong></li>
              </ul>
              <hr>
              <p class="small text-muted-2 mb-0">
                Never share your password. Your faculty will never ask you for it, and no
                one else can see the papers allotted to you.
              </p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
