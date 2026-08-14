<?php
/**
 * Faculty self registration.
 *
 * Collects the full professional profile in three sections. Whether the new
 * account can sign in immediately is decided by FACULTY_SELF_APPROVE in
 * config/config.php - by default it is created as "pending" and an
 * administrator approves it from Admin > Faculty, because a faculty account
 * can set papers and read every allotted student's marks.
 */
require_once __DIR__ . '/../includes/auth.php';

if (is_faculty_logged_in()) {
    redirect('faculty/index.php');
}

$errors = [];
$done   = false;

$f = [
    'college_id' => '', 'branch_id' => '',
    'name' => '', 'employee_code' => '', 'email' => '', 'phone' => '',
    'department' => '', 'designation' => '', 'qualification' => '',
    'specialization' => '', 'experience_years' => '', 'joining_date' => '',
    'about' => '', 'username' => '',
];

$colleges  = get_colleges();
$branchMap = [];
foreach ($colleges as $col) {
    $branchMap[(int)$col['id']] = array_map(
        static fn($b) => ['id' => (int)$b['id'], 'name' => $b['name'] . ' (' . $b['code'] . ')'],
        get_branches((int)$col['id'])
    );
}

$designations = ['Professor', 'Associate Professor', 'Assistant Professor',
                 'Senior Lecturer', 'Lecturer', 'Visiting Faculty', 'Lab Instructor'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    foreach ($f as $key => $_) {
        $f[$key] = post($key);
    }
    $password = post('password');
    $confirm  = post('confirm_password');

    /* ---------------- Personal ---------------- */
    if (mb_strlen($f['name']) < 3) {
        $errors['name'] = 'Please enter your full name.';
    }
    if (!filter_var($f['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    }
    if ($f['phone'] !== '' && !preg_match('/^[0-9+\-\s]{6,20}$/', $f['phone'])) {
        $errors['phone'] = 'Please enter a valid phone number.';
    }

    /* ---------------- College ---------------- */
    $collegeId = (int)$f['college_id'];
    if (!$collegeId || !get_college($collegeId)) {
        $errors['college_id'] = 'Choose the college you teach at.';
    }
    $branchId = (int)$f['branch_id'];
    if ($branchId && !branch_belongs_to($branchId, $collegeId)) {
        $errors['branch_id'] = 'That branch does not belong to the chosen college.';
    }

    /* ---------------- Professional ---------------- */
    if ($f['department'] === '') {
        $errors['department'] = 'Enter your department.';
    }
    if ($f['designation'] === '') {
        $errors['designation'] = 'Select your designation.';
    }
    if ($f['qualification'] === '') {
        $errors['qualification'] = 'Enter your highest qualification.';
    }
    if ($f['experience_years'] !== ''
        && (!is_numeric($f['experience_years']) || (float)$f['experience_years'] < 0
            || (float)$f['experience_years'] > 60)) {
        $errors['experience_years'] = 'Experience must be between 0 and 60 years.';
    }
    if ($f['joining_date'] !== '' && !strtotime($f['joining_date'])) {
        $errors['joining_date'] = 'Enter a valid date.';
    }

    /* ---------------- Account ---------------- */
    if (!preg_match('/^[A-Za-z0-9._]{4,50}$/', $f['username'])) {
        $errors['username'] = 'Username must be 4-50 characters: letters, digits, dot or underscore.';
    }
    $errors += password_errors($password, $confirm);
    if (isset($errors['new_password'])) {
        $errors['password'] = $errors['new_password'];
        unset($errors['new_password']);
    }
    if (post('accept') !== '1') {
        $errors['accept'] = 'Please confirm the declaration.';
    }

    /* ---------------- Uniqueness ---------------- */
    if (!$errors) {
        $st = db()->prepare('SELECT username, email FROM faculty WHERE username = ? OR email = ?');
        $st->execute([$f['username'], $f['email']]);
        foreach ($st->fetchAll() as $row) {
            if (strcasecmp($row['username'], $f['username']) === 0) {
                $errors['username'] = 'That username is already taken.';
            }
            if (strcasecmp($row['email'], $f['email']) === 0) {
                $errors['email'] = 'That email address is already registered.';
            }
        }
        if ($f['employee_code'] !== '') {
            $st = db()->prepare('SELECT id FROM faculty WHERE employee_code = ?');
            $st->execute([$f['employee_code']]);
            if ($st->fetch()) {
                $errors['employee_code'] = 'That employee ID is already registered.';
            }
        }
    }

    if (!$errors) {
        $status = FACULTY_SELF_APPROVE ? 'approved' : 'pending';
        $st = db()->prepare(
            'INSERT INTO faculty
               (college_id, branch_id, name, employee_code, username, email, phone,
                department, designation, qualification, specialization, experience_years,
                joining_date, about, password_hash, password_changed_at, status, approved_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $st->execute([
            $collegeId,
            $branchId ?: null,
            $f['name'],
            $f['employee_code'] !== '' ? $f['employee_code'] : null,
            $f['username'], $f['email'],
            $f['phone'] !== '' ? $f['phone'] : null,
            $f['department'], $f['designation'], $f['qualification'],
            $f['specialization'] !== '' ? $f['specialization'] : null,
            $f['experience_years'] !== '' ? (float)$f['experience_years'] : null,
            $f['joining_date'] !== '' ? date('Y-m-d', strtotime($f['joining_date'])) : null,
            $f['about'] !== '' ? $f['about'] : null,
            password_hash($password, PASSWORD_DEFAULT), date('Y-m-d H:i:s'),
            $status, $status === 'approved' ? date('Y-m-d H:i:s') : null,
        ]);

        if ($status === 'approved') {
            set_flash('success', 'Registration complete. You can now sign in.');
            redirect('faculty/login.php');
        }
        $done = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Faculty Registration &middot; <?= e(APP_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= url('assets/css/style.css') ?>" rel="stylesheet">
</head>
<body class="auth-body-faculty">

<div class="container py-5" style="max-width:940px">

  <div class="text-center mb-4">
    <a href="<?= url('index.php') ?>" class="d-inline-flex align-items-center gap-2 mb-3"
       style="color:#fff;text-decoration:none">
      <span class="brand-badge" style="width:40px;height:40px;border-radius:13px;
            display:grid;place-items:center;background:var(--oep-grad-brand)">
        <i class="bi bi-mortarboard-fill"></i>
      </span>
      <span class="fw-bold fs-5"><?= e(APP_NAME) ?></span>
    </a>
    <h2 style="color:#fff">Faculty Registration</h2>
    <p style="color:rgba(255,255,255,.68)" class="mb-0">
      Create your teaching account - set papers, allot them to your students and
      publish results.
    </p>
  </div>

  <?php if ($done): ?>
    <div class="card border-0">
      <div class="card-body p-4 p-md-5 text-center">
        <div class="feature-icon bg-soft-warning mx-auto"><i class="bi bi-hourglass-split"></i></div>
        <h3 class="mb-2">Registration received</h3>
        <p class="text-muted-2 mb-4 mx-auto" style="max-width:34rem">
          Your account <strong><?= e($f['username']) ?></strong> has been created and is
          waiting for administrator approval. A faculty account can set examinations and
          read student marks, so every new registration is verified first.
          You will be able to sign in as soon as it is approved.
        </p>
        <div class="d-flex justify-content-center flex-wrap gap-2">
          <a href="<?= url('faculty/login.php') ?>" class="btn btn-primary">Go to Faculty Login</a>
          <a href="<?= url('index.php') ?>" class="btn btn-outline-secondary">Back to Website</a>
        </div>
      </div>
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

      <!-- ==================== 1. Personal ==================== -->
      <div class="card border-0 mb-3">
        <div class="card-body p-4">
          <div class="form-section-head">
            <span class="form-section-num">1</span>
            <div>
              <h5 class="mb-0">Personal details</h5>
              <span class="small text-muted-2">How students and the administration identify you.</span>
            </div>
          </div>

          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label" for="name">Full Name <span class="req">*</span></label>
              <div class="input-group">
                <span class="input-group-text bg-white"><i class="bi bi-person"></i></span>
                <input type="text" id="name" name="name" required
                       class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>"
                       value="<?= e($f['name']) ?>" placeholder="e.g. Dr. Anita Verma">
                <div class="invalid-feedback"><?= e($errors['name'] ?? 'Enter your full name.') ?></div>
              </div>
            </div>

            <div class="col-md-6">
              <label class="form-label" for="employee_code">Employee ID</label>
              <div class="input-group">
                <span class="input-group-text bg-white"><i class="bi bi-person-vcard"></i></span>
                <input type="text" id="employee_code" name="employee_code"
                       class="form-control <?= isset($errors['employee_code']) ? 'is-invalid' : '' ?>"
                       value="<?= e($f['employee_code']) ?>" placeholder="e.g. FAC-2201">
                <div class="invalid-feedback"><?= e($errors['employee_code'] ?? '') ?></div>
              </div>
            </div>

            <div class="col-md-6">
              <label class="form-label" for="email">Official Email <span class="req">*</span></label>
              <div class="input-group">
                <span class="input-group-text bg-white"><i class="bi bi-envelope"></i></span>
                <input type="email" id="email" name="email" required
                       class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>"
                       value="<?= e($f['email']) ?>" placeholder="you@college.edu">
                <div class="invalid-feedback"><?= e($errors['email'] ?? 'Enter a valid email.') ?></div>
              </div>
            </div>

            <div class="col-md-6">
              <label class="form-label" for="phone">Contact Number</label>
              <div class="input-group">
                <span class="input-group-text bg-white"><i class="bi bi-telephone"></i></span>
                <input type="tel" id="phone" name="phone"
                       class="form-control <?= isset($errors['phone']) ? 'is-invalid' : '' ?>"
                       value="<?= e($f['phone']) ?>" placeholder="9876543210">
                <div class="invalid-feedback"><?= e($errors['phone'] ?? 'Enter a valid number.') ?></div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ==================== 2. Professional ==================== -->
      <div class="card border-0 mb-3">
        <div class="card-body p-4">
          <div class="form-section-head">
            <span class="form-section-num">2</span>
            <div>
              <h5 class="mb-0">Academic &amp; professional details</h5>
              <span class="small text-muted-2">Shown on your faculty profile.</span>
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
                    <?= (int)$f['college_id'] === (int)$col['id'] ? 'selected' : '' ?>>
                    <?= e($col['name']) ?> (<?= e($col['code']) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="invalid-feedback"><?= e($errors['college_id'] ?? 'Choose your college.') ?></div>
            </div>

            <div class="col-md-6">
              <label class="form-label" for="branch_id">
                Primary Branch <span class="text-muted-2 fw-normal small">(optional)</span>
              </label>
              <select class="form-select <?= isset($errors['branch_id']) ? 'is-invalid' : '' ?>"
                      id="branch_id" name="branch_id" data-selected="<?= e($f['branch_id']) ?>">
                <option value="">Choose a college first</option>
              </select>
              <div class="invalid-feedback"><?= e($errors['branch_id'] ?? '') ?></div>
              <div class="form-text">The branch you mainly teach - helps filter your students.</div>
            </div>

            <div class="col-md-6">
              <label class="form-label" for="department">Department <span class="req">*</span></label>
              <div class="input-group">
                <span class="input-group-text bg-white"><i class="bi bi-building"></i></span>
                <input type="text" id="department" name="department" required
                       class="form-control <?= isset($errors['department']) ? 'is-invalid' : '' ?>"
                       value="<?= e($f['department']) ?>" placeholder="e.g. Computer Science">
                <div class="invalid-feedback"><?= e($errors['department'] ?? 'Enter your department.') ?></div>
              </div>
            </div>

            <div class="col-md-6">
              <label class="form-label" for="designation">Designation <span class="req">*</span></label>
              <select id="designation" name="designation" required
                      class="form-select <?= isset($errors['designation']) ? 'is-invalid' : '' ?>">
                <option value="">-- select --</option>
                <?php foreach ($designations as $d): ?>
                  <option value="<?= e($d) ?>" <?= $f['designation'] === $d ? 'selected' : '' ?>>
                    <?= e($d) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="invalid-feedback"><?= e($errors['designation'] ?? 'Select a designation.') ?></div>
            </div>

            <div class="col-md-6">
              <label class="form-label" for="qualification">
                Highest Qualification <span class="req">*</span>
              </label>
              <div class="input-group">
                <span class="input-group-text bg-white"><i class="bi bi-mortarboard"></i></span>
                <input type="text" id="qualification" name="qualification" required
                       class="form-control <?= isset($errors['qualification']) ? 'is-invalid' : '' ?>"
                       value="<?= e($f['qualification']) ?>" placeholder="e.g. Ph.D. (Computer Science)">
                <div class="invalid-feedback">
                  <?= e($errors['qualification'] ?? 'Enter your qualification.') ?>
                </div>
              </div>
            </div>

            <div class="col-md-6">
              <label class="form-label" for="specialization">Subjects / Specialisation</label>
              <div class="input-group">
                <span class="input-group-text bg-white"><i class="bi bi-bookmarks"></i></span>
                <input type="text" id="specialization" name="specialization" class="form-control"
                       value="<?= e($f['specialization']) ?>"
                       placeholder="e.g. Data Structures, Networks">
              </div>
              <div class="form-text">Comma separated - these are the subjects you teach.</div>
            </div>

            <div class="col-md-6">
              <label class="form-label" for="experience_years">Teaching Experience (years)</label>
              <input type="number" step="0.5" min="0" max="60" id="experience_years"
                     name="experience_years"
                     class="form-control <?= isset($errors['experience_years']) ? 'is-invalid' : '' ?>"
                     value="<?= e($f['experience_years']) ?>" placeholder="e.g. 8">
              <div class="invalid-feedback"><?= e($errors['experience_years'] ?? '0 - 60.') ?></div>
            </div>

            <div class="col-md-6">
              <label class="form-label" for="joining_date">Date of Joining</label>
              <input type="date" id="joining_date" name="joining_date"
                     class="form-control <?= isset($errors['joining_date']) ? 'is-invalid' : '' ?>"
                     value="<?= e($f['joining_date']) ?>">
              <div class="invalid-feedback"><?= e($errors['joining_date'] ?? 'Enter a valid date.') ?></div>
            </div>

            <div class="col-12">
              <label class="form-label" for="about">About you</label>
              <textarea id="about" name="about" rows="3" class="form-control" maxlength="1000"
                        placeholder="A short note about your teaching areas, research or interests"><?= e($f['about']) ?></textarea>
            </div>
          </div>
        </div>
      </div>

      <!-- ==================== 3. Account ==================== -->
      <div class="card border-0 mb-3">
        <div class="card-body p-4">
          <div class="form-section-head">
            <span class="form-section-num">3</span>
            <div>
              <h5 class="mb-0">Sign in details</h5>
              <span class="small text-muted-2">You can sign in with the username or the email.</span>
            </div>
          </div>

          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label" for="username">Username <span class="req">*</span></label>
              <div class="input-group">
                <span class="input-group-text bg-white"><i class="bi bi-at"></i></span>
                <input type="text" id="username" name="username" required
                       class="form-control <?= isset($errors['username']) ? 'is-invalid' : '' ?>"
                       value="<?= e($f['username']) ?>" placeholder="anita.verma">
                <div class="invalid-feedback">
                  <?= e($errors['username'] ?? '4-50 characters.') ?>
                </div>
              </div>
            </div>

            <div class="col-md-4">
              <label class="form-label" for="password">Password <span class="req">*</span></label>
              <div class="input-group">
                <span class="input-group-text bg-white"><i class="bi bi-lock"></i></span>
                <input type="password" id="password" name="password" required
                       class="form-control <?= isset($errors['password']) ? 'is-invalid' : '' ?>"
                       data-strength="#pwMeter"
                       placeholder="Min. <?= MIN_PASSWORD_LENGTH ?> characters">
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
                <input type="password" id="confirm_password" name="confirm_password" required
                       class="form-control <?= isset($errors['confirm_password']) ? 'is-invalid' : '' ?>"
                       placeholder="Repeat password">
                <div class="invalid-feedback">
                  <?= e($errors['confirm_password'] ?? 'Passwords must match.') ?>
                </div>
              </div>
            </div>
          </div>

          <div class="form-check mt-4 p-3 bg-light rounded border">
            <input class="form-check-input ms-0 me-2 <?= isset($errors['accept']) ? 'is-invalid' : '' ?>"
                   type="checkbox" id="accept" name="accept" value="1" required>
            <label class="form-check-label" for="accept">
              I confirm that the details above are correct and that I am authorised to set
              examinations for this institution.
            </label>
            <div class="invalid-feedback d-block">
              <?= e($errors['accept'] ?? '') ?>
            </div>
          </div>

          <?php if (!FACULTY_SELF_APPROVE): ?>
            <div class="alert alert-info mt-3 mb-0 small">
              <i class="bi bi-shield-check me-1"></i>
              New faculty accounts are activated by the administrator. You will be able
              to sign in once your registration is approved.
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="card border-0">
        <div class="card-body d-flex flex-wrap gap-2 align-items-center">
          <button type="submit" class="btn btn-primary btn-hero">
            <i class="bi bi-person-plus me-1"></i>Create Faculty Account
          </button>
          <a href="<?= url('faculty/login.php') ?>" class="btn btn-outline-secondary">
            I already have an account
          </a>
          <span class="small text-muted-2 ms-auto"><span class="req">*</span> required</span>
        </div>
      </div>
    </form>
  <?php endif; ?>

  <p class="text-center small mt-4 mb-0" style="color:rgba(255,255,255,.6)">
    <a href="<?= url('index.php') ?>" style="color:rgba(255,255,255,.8)">
      <i class="bi bi-arrow-left me-1"></i>Back to website
    </a>
  </p>
</div>

<script>
/* Branch options per college, used by main.js to refresh the branch list. */
window.OEP_BRANCHES = <?= json_encode($branchMap) ?>;
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= url('assets/js/main.js') ?>"></script>
</body>
</html>
