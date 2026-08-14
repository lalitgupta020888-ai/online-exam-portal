<?php
/**
 * Register a college on the portal.
 *
 * This creates the institution (name, code, logo, contact) together with its
 * first administrator account. That college's faculty and students then
 * register under it, and from then on the college only ever sees its own data.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/migrate_college.php';   // default_branches()

if (is_admin_logged_in()) {
    redirect('admin/index.php');
}

$errors = [];
$c = ['name' => '', 'code' => '', 'email' => '', 'phone' => '', 'website' => '',
      'address' => '', 'city' => '', 'state' => ''];
$a = ['admin_name' => '', 'admin_email' => '', 'admin_phone' => '',
      'designation' => '', 'username' => ''];

$designations = ['Principal', 'Director', 'Dean', 'Head of Department',
                 'Examination Controller', 'Registrar', 'System Administrator'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    foreach ($c as $k => $_) { $c[$k] = post($k); }
    foreach ($a as $k => $_) { $a[$k] = post($k); }
    $password = post('password');
    $confirm  = post('confirm_password');

    /* ---------------- College ---------------- */
    if (mb_strlen($c['name']) < 3) {
        $errors['name'] = 'Enter the full name of the college.';
    }
    if (!preg_match('/^[A-Za-z0-9\-]{2,30}$/', $c['code'])) {
        $errors['code'] = 'Code must be 2-30 characters: letters, digits or hyphen.';
    }
    if ($c['email'] !== '' && !filter_var($c['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid college email address.';
    }
    if ($c['phone'] !== '' && !preg_match('/^[0-9+\-\s]{6,20}$/', $c['phone'])) {
        $errors['phone'] = 'Enter a valid phone number.';
    }
    if ($c['city'] === '') {
        $errors['city'] = 'Enter the city.';
    }

    /* ---------------- Administrator ---------------- */
    if (mb_strlen($a['admin_name']) < 3) {
        $errors['admin_name'] = 'Enter your full name.';
    }
    if (!filter_var($a['admin_email'], FILTER_VALIDATE_EMAIL)) {
        $errors['admin_email'] = 'Enter a valid email address.';
    }
    if (!preg_match('/^[A-Za-z0-9._]{4,50}$/', $a['username'])) {
        $errors['username'] = 'Username must be 4-50 characters: letters, digits, dot or underscore.';
    }
    $pwErrors = password_errors($password, $confirm);
    if (isset($pwErrors['new_password'])) { $errors['password'] = $pwErrors['new_password']; }
    if (isset($pwErrors['confirm_password'])) {
        $errors['confirm_password'] = $pwErrors['confirm_password'];
    }
    if (post('accept') !== '1') {
        $errors['accept'] = 'Please confirm the declaration.';
    }

    /* ---------------- Uniqueness ---------------- */
    if (!$errors) {
        $st = db()->prepare('SELECT id FROM colleges WHERE code = ?');
        $st->execute([$c['code']]);
        if ($st->fetch()) {
            $errors['code'] = 'That college code is already registered.';
        }
        $st = db()->prepare('SELECT id FROM admins WHERE username = ?');
        $st->execute([$a['username']]);
        if ($st->fetch()) {
            $errors['username'] = 'That username is already taken.';
        }
    }

    /* ---------------- Logo ---------------- */
    $logo = null;
    if (!$errors) {
        [$logo, $logoError] = store_college_logo($_FILES['logo'] ?? []);
        if ($logoError) {
            $errors['logo'] = $logoError;
        }
    }

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO colleges (name, code, logo, email, phone, website,
                        address, city, state)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            )->execute([
                $c['name'], strtoupper($c['code']), $logo,
                $c['email'] ?: null, $c['phone'] ?: null, $c['website'] ?: null,
                $c['address'] ?: null, $c['city'], $c['state'] ?: null,
            ]);
            $collegeId = (int)$pdo->lastInsertId();

            // Every college starts with the usual branch list, editable later.
            $ins = $pdo->prepare('INSERT INTO branches (college_id, name, code) VALUES (?,?,?)');
            foreach (default_branches() as [$bn, $bc]) {
                $ins->execute([$collegeId, $bn, $bc]);
            }

            $pdo->prepare(
                'INSERT INTO admins (college_id, username, name, email, phone, designation,
                        password_hash, password_changed_at)
                 VALUES (?,?,?,?,?,?,?,?)'
            )->execute([
                $collegeId, $a['username'], $a['admin_name'], $a['admin_email'],
                $a['admin_phone'] ?: null, $a['designation'] ?: null,
                password_hash($password, PASSWORD_DEFAULT), date('Y-m-d H:i:s'),
            ]);

            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            delete_college_logo($logo);
            $errors['general'] = 'The registration could not be saved. Please try again.';
        }

        if (!$errors) {
            set_flash('success', $c['name'] . ' has been registered. Sign in to set up '
                               . 'your branches, and invite your faculty and students.');
            redirect('admin/login.php');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Register your College &middot; <?= e(APP_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= url('assets/css/style.css') ?>" rel="stylesheet">
</head>
<body class="auth-body-admin">

<div class="container py-5" style="max-width:960px">

  <div class="text-center mb-4">
    <a href="<?= url('index.php') ?>" class="d-inline-flex align-items-center gap-2 mb-3"
       style="color:#fff;text-decoration:none">
      <span class="brand-badge" style="width:40px;height:40px;border-radius:13px;
            display:grid;place-items:center;background:var(--oep-grad-brand)">
        <i class="bi bi-mortarboard-fill"></i>
      </span>
      <span class="fw-bold fs-5"><?= e(APP_NAME) ?></span>
    </a>
    <h2 style="color:#fff">Register your College</h2>
    <p style="color:rgba(255,255,255,.68)" class="mb-0">
      Create your institution on the portal. Your faculty and students then register
      under it, branch and year wise - and your college only ever sees its own data.
    </p>
  </div>

  <form method="post" enctype="multipart/form-data" novalidate class="needs-validation">
    <?= csrf_field() ?>

    <?php if ($errors): ?>
      <div class="alert alert-danger">
        <i class="bi bi-exclamation-triangle-fill me-1"></i>
        <?= e($errors['general'] ?? 'Please correct the highlighted fields below.') ?>
      </div>
    <?php endif; ?>

    <!-- ==================== 1. Institution ==================== -->
    <div class="card border-0 mb-3">
      <div class="card-body p-4">
        <div class="form-section-head">
          <span class="form-section-num">1</span>
          <div>
            <h5 class="mb-0">Institution details</h5>
            <span class="small text-muted-2">
              The name and logo appear across the portal for everyone in your college.
            </span>
          </div>
        </div>

        <div class="row g-3">
          <div class="col-md-8">
            <label class="form-label" for="name">College Name <span class="req">*</span></label>
            <div class="input-group">
              <span class="input-group-text bg-white"><i class="bi bi-bank"></i></span>
              <input type="text" id="name" name="name" required
                     class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>"
                     value="<?= e($c['name']) ?>"
                     placeholder="e.g. Sunrise Institute of Technology">
              <div class="invalid-feedback"><?= e($errors['name'] ?? 'Enter the college name.') ?></div>
            </div>
          </div>

          <div class="col-md-4">
            <label class="form-label" for="code">College Code <span class="req">*</span></label>
            <input type="text" id="code" name="code" required maxlength="30"
                   class="form-control text-uppercase <?= isset($errors['code']) ? 'is-invalid' : '' ?>"
                   value="<?= e($c['code']) ?>" placeholder="e.g. SIT">
            <div class="invalid-feedback"><?= e($errors['code'] ?? 'Enter a short code.') ?></div>
          </div>

          <div class="col-12">
            <label class="form-label" for="logo">College Logo</label>
            <div class="logo-picker">
              <div class="logo-preview" id="logoPreview">
                <i class="bi bi-image"></i>
              </div>
              <div class="flex-grow-1">
                <input type="file" class="form-control <?= isset($errors['logo']) ? 'is-invalid' : '' ?>"
                       id="logo" name="logo" accept="image/png,image/jpeg,image/gif,image/webp">
                <div class="invalid-feedback"><?= e($errors['logo'] ?? '') ?></div>
                <div class="form-text">
                  PNG, JPG, GIF or WEBP up to 2 MB. A square image looks best.
                </div>
              </div>
            </div>
          </div>

          <div class="col-md-6">
            <label class="form-label" for="email">College Email</label>
            <div class="input-group">
              <span class="input-group-text bg-white"><i class="bi bi-envelope"></i></span>
              <input type="email" id="email" name="email"
                     class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>"
                     value="<?= e($c['email']) ?>" placeholder="office@college.edu">
              <div class="invalid-feedback"><?= e($errors['email'] ?? '') ?></div>
            </div>
          </div>

          <div class="col-md-6">
            <label class="form-label" for="phone">College Phone</label>
            <div class="input-group">
              <span class="input-group-text bg-white"><i class="bi bi-telephone"></i></span>
              <input type="tel" id="phone" name="phone"
                     class="form-control <?= isset($errors['phone']) ? 'is-invalid' : '' ?>"
                     value="<?= e($c['phone']) ?>">
              <div class="invalid-feedback"><?= e($errors['phone'] ?? '') ?></div>
            </div>
          </div>

          <div class="col-12">
            <label class="form-label" for="address">Address</label>
            <input type="text" id="address" name="address" class="form-control"
                   value="<?= e($c['address']) ?>" placeholder="Street / area">
          </div>

          <div class="col-md-4">
            <label class="form-label" for="city">City <span class="req">*</span></label>
            <input type="text" id="city" name="city" required
                   class="form-control <?= isset($errors['city']) ? 'is-invalid' : '' ?>"
                   value="<?= e($c['city']) ?>">
            <div class="invalid-feedback"><?= e($errors['city'] ?? 'Enter the city.') ?></div>
          </div>

          <div class="col-md-4">
            <label class="form-label" for="state">State</label>
            <input type="text" id="state" name="state" class="form-control"
                   value="<?= e($c['state']) ?>">
          </div>

          <div class="col-md-4">
            <label class="form-label" for="website">Website</label>
            <input type="text" id="website" name="website" class="form-control"
                   value="<?= e($c['website']) ?>" placeholder="www.college.edu">
          </div>
        </div>
      </div>
    </div>

    <!-- ==================== 2. Administrator ==================== -->
    <div class="card border-0 mb-3">
      <div class="card-body p-4">
        <div class="form-section-head">
          <span class="form-section-num">2</span>
          <div>
            <h5 class="mb-0">Administrator account</h5>
            <span class="small text-muted-2">
              You approve faculty, analyse their work and oversee every paper.
            </span>
          </div>
        </div>

        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label" for="admin_name">Your Name <span class="req">*</span></label>
            <div class="input-group">
              <span class="input-group-text bg-white"><i class="bi bi-person"></i></span>
              <input type="text" id="admin_name" name="admin_name" required
                     class="form-control <?= isset($errors['admin_name']) ? 'is-invalid' : '' ?>"
                     value="<?= e($a['admin_name']) ?>">
              <div class="invalid-feedback"><?= e($errors['admin_name'] ?? 'Enter your name.') ?></div>
            </div>
          </div>

          <div class="col-md-6">
            <label class="form-label" for="designation">Designation</label>
            <select class="form-select" id="designation" name="designation">
              <option value="">-- select --</option>
              <?php foreach ($designations as $d): ?>
                <option value="<?= e($d) ?>" <?= $a['designation'] === $d ? 'selected' : '' ?>>
                  <?= e($d) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-6">
            <label class="form-label" for="admin_email">Your Email <span class="req">*</span></label>
            <div class="input-group">
              <span class="input-group-text bg-white"><i class="bi bi-envelope-at"></i></span>
              <input type="email" id="admin_email" name="admin_email" required
                     class="form-control <?= isset($errors['admin_email']) ? 'is-invalid' : '' ?>"
                     value="<?= e($a['admin_email']) ?>">
              <div class="invalid-feedback"><?= e($errors['admin_email'] ?? 'Enter a valid email.') ?></div>
            </div>
          </div>

          <div class="col-md-6">
            <label class="form-label" for="admin_phone">Your Phone</label>
            <div class="input-group">
              <span class="input-group-text bg-white"><i class="bi bi-telephone"></i></span>
              <input type="tel" id="admin_phone" name="admin_phone" class="form-control"
                     value="<?= e($a['admin_phone']) ?>">
            </div>
          </div>

          <div class="col-md-4">
            <label class="form-label" for="username">Username <span class="req">*</span></label>
            <div class="input-group">
              <span class="input-group-text bg-white"><i class="bi bi-at"></i></span>
              <input type="text" id="username" name="username" required
                     class="form-control <?= isset($errors['username']) ? 'is-invalid' : '' ?>"
                     value="<?= e($a['username']) ?>" placeholder="sit.admin">
              <div class="invalid-feedback"><?= e($errors['username'] ?? '4-50 characters.') ?></div>
            </div>
          </div>

          <div class="col-md-4">
            <label class="form-label" for="password">Password <span class="req">*</span></label>
            <div class="input-group">
              <span class="input-group-text bg-white"><i class="bi bi-lock"></i></span>
              <input type="password" id="password" name="password" required
                     data-strength="#pwMeter"
                     class="form-control <?= isset($errors['password']) ? 'is-invalid' : '' ?>"
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
                     class="form-control <?= isset($errors['confirm_password']) ? 'is-invalid' : '' ?>">
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
            I confirm that I am authorised to register this institution and to administer
            its examinations.
          </label>
          <div class="invalid-feedback d-block"><?= e($errors['accept'] ?? '') ?></div>
        </div>

        <div class="alert alert-info mt-3 mb-0 small">
          <i class="bi bi-info-circle me-1"></i>
          Nine common branches (CSE, IT, ECE, EE, ME, CE, BCA, MCA, BBA) are created for
          you - add, rename or remove them from <strong>Branches</strong> after signing in.
        </div>
      </div>
    </div>

    <div class="card border-0">
      <div class="card-body d-flex flex-wrap gap-2 align-items-center">
        <button type="submit" class="btn btn-primary btn-hero">
          <i class="bi bi-bank me-1"></i>Register College
        </button>
        <a href="<?= url('admin/login.php') ?>" class="btn btn-outline-secondary">
          We are already registered
        </a>
        <span class="small text-muted-2 ms-auto"><span class="req">*</span> required</span>
      </div>
    </div>
  </form>

  <p class="text-center small mt-4 mb-0" style="color:rgba(255,255,255,.6)">
    <a href="<?= url('index.php') ?>" style="color:rgba(255,255,255,.8)">
      <i class="bi bi-arrow-left me-1"></i>Back to website
    </a>
  </p>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= url('assets/js/main.js') ?>"></script>
</body>
</html>
