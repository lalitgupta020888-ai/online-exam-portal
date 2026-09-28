<?php
/**
 * Student registration.
 *
 * A student signs up under one college and states their branch, year and
 * semester - that is what lets the faculty allot a paper to, say, "CSE,
 * 3rd year, semester 5" instead of picking names one by one.
 */
require_once __DIR__ . '/includes/auth.php';

if (is_student_logged_in()) {
    redirect('dashboard.php');
}

$pageTitle = 'Student Registration';
$activeNav = 'login';
$errors    = [];
$old       = ['name' => '', 'email' => '', 'phone' => '', 'college_id' => '',
              'branch_id' => '', 'study_year' => '', 'semester' => '',
              'enrollment_no' => '', 'admission_year' => ''];

$colleges = get_colleges();

// Branch lists for every college, so the branch dropdown can follow the
// college choice without a page reload.
$branchMap = [];
foreach ($colleges as $col) {
    $branchMap[(int)$col['id']] = array_map(
        static fn($b) => ['id' => (int)$b['id'], 'name' => $b['name'] . ' (' . $b['code'] . ')'],
        get_branches((int)$col['id'])
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    foreach ($old as $k => $_) { $old[$k] = post($k); }
    $password = post('password');
    $confirm  = post('confirm_password');

    if ($old['name'] === '' || mb_strlen($old['name']) < 3) {
        $errors['name'] = 'Please enter your full name (at least 3 characters).';
    }
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    }
    if ($old['phone'] !== '' && !preg_match('/^[0-9+\-\s]{6,20}$/', $old['phone'])) {
        $errors['phone'] = 'Please enter a valid phone number.';
    }

    $collegeId = (int)$old['college_id'];
    if (!$collegeId || !get_college($collegeId)) {
        $errors['college_id'] = 'Choose your college.';
    }

    $branchId = (int)$old['branch_id'];
    if (!$branchId) {
        $errors['branch_id'] = 'Choose your branch.';
    } elseif (!branch_belongs_to($branchId, $collegeId)) {
        $errors['branch_id'] = 'That branch does not belong to the chosen college.';
    }

    $year = (int)$old['study_year'];
    if (!isset(study_years()[$year])) {
        $errors['study_year'] = 'Choose your current year.';
    }
    $sem = (int)$old['semester'];
    if ($sem < 1 || $sem > 10) {
        $errors['semester'] = 'Choose your current semester.';
    }
    if ($old['admission_year'] !== ''
        && ((int)$old['admission_year'] < 1990 || (int)$old['admission_year'] > (int)date('Y') + 1)) {
        $errors['admission_year'] = 'Enter a valid admission year.';
    }

    if (strlen($password) < MIN_PASSWORD_LENGTH) {
        $errors['password'] = 'Password must be at least ' . MIN_PASSWORD_LENGTH
                            . ' characters long.';
    }
    if ($password !== $confirm) {
        $errors['confirm_password'] = 'The two passwords do not match.';
    }

    if (!$errors) {
        $check = db()->prepare('SELECT id FROM students WHERE email = ?');
        $check->execute([$old['email']]);
        if ($check->fetch()) {
            $errors['email'] = 'This email address is already registered.';
        }
        if ($old['enrollment_no'] !== '') {
            $check = db()->prepare('SELECT id FROM students
                                     WHERE enrollment_no = ? AND college_id = ?');
            $check->execute([$old['enrollment_no'], $collegeId]);
            if ($check->fetch()) {
                $errors['enrollment_no'] = 'That enrolment number is already registered.';
            }
        }
    }

    if (!$errors) {
        db()->prepare(
            'INSERT INTO students (college_id, name, email, phone, branch_id, study_year,
                    semester, enrollment_no, admission_year, password_hash, password_changed_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $collegeId, $old['name'], $old['email'],
            $old['phone'] !== '' ? $old['phone'] : null,
            $branchId, $year, $sem,
            $old['enrollment_no'] !== '' ? $old['enrollment_no'] : null,
            $old['admission_year'] !== '' ? (int)$old['admission_year'] : null,
            password_hash($password, PASSWORD_DEFAULT), date('Y-m-d H:i:s'),
        ]);
        set_flash('success', 'Registration successful. You can now log in - the papers your '
                           . 'faculty allots to your class will appear on your dashboard.');
        redirect('login.php');
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-4 py-lg-5">
  <div class="row justify-content-center">
    <div class="col-lg-9 col-xl-8">

      <div class="text-center mb-4">
        <div class="feature-icon bg-soft-primary mx-auto"><i class="bi bi-person-plus"></i></div>
        <span class="eyebrow">Student sign up</span>
        <h2 class="mb-1">Create your account</h2>
        <p class="text-muted-2 mb-0">
          Register under your college and class - your faculty then allots papers to you.
        </p>
      </div>

      <?php if (!$colleges): ?>
        <div class="alert alert-warning">
          <i class="bi bi-exclamation-triangle me-1"></i>
          No college has registered on the portal yet, so there is nothing to join.
          Ask your administration to
          <a href="<?= url('admin/register.php') ?>" class="fw-semibold">register the college</a>
          first.
        </div>
      <?php else: ?>

      <form method="post" novalidate class="needs-validation">
        <?= csrf_field() ?>

        <?php if ($errors): ?>
          <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle-fill me-1"></i>
            Please correct the highlighted fields below.
          </div>
        <?php endif; ?>

        <!-- ============ 1. College and class ============ -->
        <div class="card border-0 mb-3">
          <div class="card-body p-4">
            <div class="form-section-head">
              <span class="form-section-num">1</span>
              <div>
                <h5 class="mb-0">College &amp; class</h5>
                <span class="small text-muted-2">
                  Papers are allotted branch and semester wise, so get this right.
                </span>
              </div>
            </div>

            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label" for="college_id">College <span class="req">*</span></label>
                <select class="form-select <?= isset($errors['college_id']) ? 'is-invalid' : '' ?>"
                        id="college_id" name="college_id" required>
                  <option value="">-- select your college --</option>
                  <?php foreach ($colleges as $col): ?>
                    <option value="<?= (int)$col['id'] ?>"
                      <?= (int)$old['college_id'] === (int)$col['id'] ? 'selected' : '' ?>>
                      <?= e($col['name']) ?> (<?= e($col['code']) ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
                <div class="invalid-feedback"><?= e($errors['college_id'] ?? 'Choose your college.') ?></div>
              </div>

              <div class="col-md-6">
                <label class="form-label" for="branch_id">Branch <span class="req">*</span></label>
                <select class="form-select <?= isset($errors['branch_id']) ? 'is-invalid' : '' ?>"
                        id="branch_id" name="branch_id" required
                        data-selected="<?= e($old['branch_id']) ?>">
                  <option value="">Choose a college first</option>
                </select>
                <div class="invalid-feedback"><?= e($errors['branch_id'] ?? 'Choose your branch.') ?></div>
              </div>

              <div class="col-md-4">
                <label class="form-label" for="study_year">Current Year <span class="req">*</span></label>
                <select class="form-select <?= isset($errors['study_year']) ? 'is-invalid' : '' ?>"
                        id="study_year" name="study_year" required>
                  <option value="">-- select --</option>
                  <?php foreach (study_years() as $v => $label): ?>
                    <option value="<?= $v ?>" <?= (int)$old['study_year'] === $v ? 'selected' : '' ?>>
                      <?= e($label) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <div class="invalid-feedback"><?= e($errors['study_year'] ?? 'Choose your year.') ?></div>
              </div>

              <div class="col-md-4">
                <label class="form-label" for="semester">Current Semester <span class="req">*</span></label>
                <select class="form-select <?= isset($errors['semester']) ? 'is-invalid' : '' ?>"
                        id="semester" name="semester" required>
                  <option value="">-- select --</option>
                  <?php foreach (semesters() as $v => $label): ?>
                    <option value="<?= $v ?>" <?= (int)$old['semester'] === $v ? 'selected' : '' ?>>
                      <?= e($label) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <div class="invalid-feedback"><?= e($errors['semester'] ?? 'Choose your semester.') ?></div>
              </div>

              <div class="col-md-4">
                <label class="form-label" for="admission_year">Admission Year</label>
                <input type="number" id="admission_year" name="admission_year"
                       min="1990" max="<?= (int)date('Y') + 1 ?>"
                       class="form-control <?= isset($errors['admission_year']) ? 'is-invalid' : '' ?>"
                       value="<?= e($old['admission_year']) ?>" placeholder="<?= (int)date('Y') ?>">
                <div class="invalid-feedback"><?= e($errors['admission_year'] ?? '') ?></div>
              </div>

              <div class="col-md-6">
                <label class="form-label" for="enrollment_no">Enrolment / Roll Number</label>
                <div class="input-group">
                  <span class="input-group-text bg-white"><i class="bi bi-card-list"></i></span>
                  <input type="text" id="enrollment_no" name="enrollment_no"
                         class="form-control <?= isset($errors['enrollment_no']) ? 'is-invalid' : '' ?>"
                         value="<?= e($old['enrollment_no']) ?>" placeholder="e.g. 21CSE045">
                  <div class="invalid-feedback"><?= e($errors['enrollment_no'] ?? '') ?></div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- ============ 2. Your details ============ -->
        <div class="card border-0 mb-3">
          <div class="card-body p-4">
            <div class="form-section-head">
              <span class="form-section-num">2</span>
              <div><h5 class="mb-0">Your details</h5></div>
            </div>

            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label" for="name">Full Name <span class="req">*</span></label>
                <div class="input-group">
                  <span class="input-group-text bg-white"><i class="bi bi-person"></i></span>
                  <input type="text" class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>"
                         id="name" name="name" value="<?= e($old['name']) ?>"
                         placeholder="e.g. Rahul Sharma" required minlength="3">
                  <div class="invalid-feedback"><?= e($errors['name'] ?? 'Enter your full name.') ?></div>
                </div>
              </div>

              <div class="col-md-6">
                <label class="form-label" for="email">Email Address <span class="req">*</span></label>
                <div class="input-group">
                  <span class="input-group-text bg-white"><i class="bi bi-envelope"></i></span>
                  <input type="email" class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>"
                         id="email" name="email" value="<?= e($old['email']) ?>"
                         placeholder="you@example.com" required>
                  <div class="invalid-feedback"><?= e($errors['email'] ?? 'Enter a valid email address.') ?></div>
                </div>
                <div class="form-text">This is also your sign in name.</div>
              </div>

              <div class="col-md-4">
                <label class="form-label" for="phone">Phone</label>
                <div class="input-group">
                  <span class="input-group-text bg-white"><i class="bi bi-telephone"></i></span>
                  <input type="tel" class="form-control <?= isset($errors['phone']) ? 'is-invalid' : '' ?>"
                         id="phone" name="phone" value="<?= e($old['phone']) ?>" placeholder="9876543210">
                  <div class="invalid-feedback"><?= e($errors['phone'] ?? '') ?></div>
                </div>
              </div>

              <div class="col-md-4">
                <label class="form-label" for="password">Password <span class="req">*</span></label>
                <div class="input-group">
                  <span class="input-group-text bg-white"><i class="bi bi-lock"></i></span>
                  <input type="password" class="form-control <?= isset($errors['password']) ? 'is-invalid' : '' ?>"
                         id="password" name="password" required minlength="<?= MIN_PASSWORD_LENGTH ?>"
                         data-strength="#pwMeter" placeholder="Min. <?= MIN_PASSWORD_LENGTH ?> characters">
                  <button class="btn btn-outline-secondary" type="button"
                          data-toggle-password="#password" aria-label="Show password">
                    <i class="bi bi-eye"></i>
                  </button>
                  <div class="invalid-feedback">
                    <?= e($errors['password'] ?? 'At least ' . MIN_PASSWORD_LENGTH . ' characters.') ?>
                  </div>
                </div>
                <div class="pw-meter mt-2" id="pwMeter">
                  <div class="pw-meter-track"><span></span></div>
                  <span class="pw-meter-label">Password strength</span>
                </div>
              </div>

              <div class="col-md-4">
                <label class="form-label" for="confirm_password">
                  Confirm Password <span class="req">*</span>
                </label>
                <div class="input-group">
                  <span class="input-group-text bg-white"><i class="bi bi-lock-fill"></i></span>
                  <input type="password"
                         class="form-control <?= isset($errors['confirm_password']) ? 'is-invalid' : '' ?>"
                         id="confirm_password" name="confirm_password" required
                         minlength="<?= MIN_PASSWORD_LENGTH ?>" placeholder="Repeat password">
                  <div class="invalid-feedback">
                    <?= e($errors['confirm_password'] ?? 'Passwords must match.') ?>
                  </div>
                </div>
              </div>
            </div>

            <div class="form-check mt-4 p-3 bg-light rounded border">
              <input class="form-check-input ms-0 me-2" type="checkbox" id="terms" required>
              <label class="form-check-label" for="terms">
                I agree to follow the examination rules and code of conduct.
              </label>
              <div class="invalid-feedback">You must accept the examination rules.</div>
            </div>
          </div>
        </div>

        <div class="card border-0">
          <div class="card-body d-flex flex-wrap gap-2 align-items-center">
            <button type="submit" class="btn btn-primary btn-hero">
              <i class="bi bi-check2-circle me-1"></i>Create Account
            </button>
            <a href="<?= url('login.php') ?>" class="btn btn-outline-secondary">
              I already have an account
            </a>
            <span class="small text-muted-2 ms-auto"><span class="req">*</span> required</span>
          </div>
        </div>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
/* Branch options per college, used by main.js to refresh the branch list. */
window.OEP_BRANCHES = <?= json_encode($branchMap) ?>;
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
