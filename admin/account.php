<?php
/**
 * Admin - my account: personal details, the college profile (name, logo,
 * contact) and the password.
 */
require_once __DIR__ . '/../includes/auth.php';
$admin = require_admin();
ensure_attendance_schema();      // campus location columns on an older database

$profileErrors  = [];
$collegeErrors  = [];
$passwordErrors = [];
$openTab = $_GET['tab'] ?? 'profile';

$a = $admin;
$college = get_college((int)$admin['college_id']);
$c = $college ?? ['name' => '', 'code' => '', 'logo' => null, 'email' => '', 'phone' => '',
                  'website' => '', 'address' => '', 'city' => '', 'state' => ''];

$designations = designations($admin['designation'] ?? null);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    /* ---------------------- My details ---------------------- */
    if (post('action') === 'profile') {
        $openTab = 'profile';
        foreach (['name', 'email', 'phone', 'designation'] as $k) {
            $a[$k] = post($k);
        }

        if (mb_strlen($a['name']) < 3) {
            $profileErrors['name'] = 'Please enter your full name.';
        }
        if ($a['email'] !== '' && !filter_var($a['email'], FILTER_VALIDATE_EMAIL)) {
            $profileErrors['email'] = 'Please enter a valid email address.';
        }
        if ($a['phone'] !== '' && !preg_match('/^[0-9+\-\s]{6,20}$/', $a['phone'])) {
            $profileErrors['phone'] = 'Please enter a valid phone number.';
        }

        if (!$profileErrors) {
            db()->prepare('UPDATE admins SET name = ?, email = ?, phone = ?, designation = ?
                            WHERE id = ?')
                ->execute([$a['name'], $a['email'] ?: null, $a['phone'] ?: null,
                           $a['designation'] ?: null, $admin['id']]);
            $_SESSION['admin_name'] = $a['name'];
            set_flash('success', 'Your details have been updated.');
            redirect('admin/account.php');
        }
    }

    /* ---------------------- College profile ---------------------- */
    if (post('action') === 'college' && $college) {
        $openTab = 'college';
        foreach (['name', 'code', 'email', 'phone', 'website', 'address', 'city', 'state'] as $k) {
            $c[$k] = post($k);
        }

        if (mb_strlen($c['name']) < 3) {
            $collegeErrors['name'] = 'Enter the full name of the college.';
        }
        if (!preg_match('/^[A-Za-z0-9\-]{2,30}$/', $c['code'])) {
            $collegeErrors['code'] = 'Code must be 2-30 characters: letters, digits or hyphen.';
        }
        if ($c['email'] !== '' && !filter_var($c['email'], FILTER_VALIDATE_EMAIL)) {
            $collegeErrors['email'] = 'Enter a valid email address.';
        }
        if ($c['city'] === '') {
            $collegeErrors['city'] = 'Enter the city.';
        }
        if (!$collegeErrors) {
            $st = db()->prepare('SELECT id FROM colleges WHERE code = ? AND id <> ?');
            $st->execute([strtoupper($c['code']), $college['id']]);
            if ($st->fetch()) {
                $collegeErrors['code'] = 'Another college already uses that code.';
            }
        }

        $newLogo = null;
        if (!$collegeErrors) {
            [$newLogo, $logoError] = store_college_logo($_FILES['logo'] ?? []);
            if ($logoError) {
                $collegeErrors['logo'] = $logoError;
            }
        }

        if (!$collegeErrors) {
            $removeLogo = post('remove_logo') === '1';
            $logoValue  = $newLogo ?: ($removeLogo ? null : $college['logo']);

            db()->prepare(
                'UPDATE colleges SET name = ?, code = ?, logo = ?, email = ?, phone = ?,
                        website = ?, address = ?, city = ?, state = ?
                  WHERE id = ?'
            )->execute([
                $c['name'], strtoupper($c['code']), $logoValue,
                $c['email'] ?: null, $c['phone'] ?: null, $c['website'] ?: null,
                $c['address'] ?: null, $c['city'], $c['state'] ?: null, $college['id'],
            ]);

            // Only delete the old file once the row points somewhere else.
            if (($newLogo || $removeLogo) && $college['logo'] && $college['logo'] !== $logoValue) {
                delete_college_logo($college['logo']);
            }
            set_flash('success', 'The college profile has been updated.');
            redirect('admin/account.php?tab=college');
        }
    }

    /* ---------------------- Campus location ---------------------- */
    if (post('action') === 'location' && $college) {
        $lat = (float)post('lat');
        $lng = (float)post('lng');
        $acc = (float)post('accuracy');
        if (!valid_coordinates($lat, $lng)) {
            set_flash('danger', 'No location was captured. Press "Fetch my location" first.');
        } elseif ($acc <= 0 || $acc > ATTENDANCE_MAX_ACCURACY_M) {
            set_flash('danger', 'The GPS fix was too rough (±' . round($acc) . ' m). Wait for a sharper '
                              . 'signal - ideally under ' . ATTENDANCE_RADIUS_M . ' m - and save again.');
        } else {
            db()->prepare('UPDATE colleges SET latitude = ?, longitude = ?, geo_accuracy = ?,
                                  geo_updated_at = ? WHERE id = ?')
                ->execute([$lat, $lng, round($acc, 1), date('Y-m-d H:i:s'), $college['id']]);
            set_flash('success', 'Campus location saved. Students can now mark attendance within '
                               . ATTENDANCE_RADIUS_M . ' m of this point.');
        }
        redirect('admin/account.php?tab=location');
    }

    /* ---------------------- Password ---------------------- */
    if (post('action') === 'password') {
        $openTab = 'password';
        $passwordErrors = change_account_password(
            'admins', (int)$admin['id'],
            post('current_password'), post('new_password'), post('confirm_password')
        );
        if (!$passwordErrors) {
            session_regenerate_id(true);
            set_flash('success', 'Your administrator password has been changed.');
            redirect('admin/account.php?tab=password');
        }
    }
}

$usingDefault = password_verify('admin123', $admin['password_hash']);

$counts = [];
if ($college) {
    $st = db()->prepare(
        'SELECT (SELECT COUNT(*) FROM students s WHERE s.college_id = :c1) AS students,
                (SELECT COUNT(*) FROM faculty f WHERE f.college_id = :c2) AS faculty,
                (SELECT COUNT(*) FROM exams e WHERE e.college_id = :c3) AS exams,
                (SELECT COUNT(*) FROM branches b WHERE b.college_id = :c4) AS branches'
    );
    $st->execute(['c1' => $college['id'], 'c2' => $college['id'],
                  'c3' => $college['id'], 'c4' => $college['id']]);
    $counts = $st->fetch();
}

$todayAttendance = [];
if ($college) {
    $st = db()->prepare(
        'SELECT a.marked_at, a.distance_m, s.name, s.enrollment_no
           FROM attendance a JOIN students s ON s.id = a.student_id
          WHERE a.college_id = ? AND a.att_date = ? ORDER BY a.marked_at DESC'
    );
    $st->execute([$college['id'], date('Y-m-d')]);
    $todayAttendance = $st->fetchAll();
}

$pageTitle = 'My Account';
$activeNav = 'account';
require_once __DIR__ . '/includes/admin_header.php';
?>

<div class="card border-0 profile-header mb-4">
  <div class="profile-header-bg"></div>
  <div class="card-body position-relative">
    <div class="d-flex flex-wrap align-items-start gap-3">
      <div class="avatar-xl"><?= e(initials($admin['name'])) ?></div>
      <div class="flex-grow-1">
        <span class="eyebrow">Administrator</span>
        <h3 class="mb-1"><?= e($admin['name']) ?></h3>
        <?php if ($college): ?>
          <div class="d-flex align-items-center gap-2 mb-2">
            <?= college_badge($college, 26) ?>
            <span class="text-muted-2 small">
              <?= e($college['name']) ?> (<?= e($college['code']) ?>)
            </span>
          </div>
        <?php endif; ?>
        <div class="d-flex flex-wrap gap-2 mt-2">
          <span class="chip chip-soft"><i class="bi bi-at"></i><?= e($admin['username']) ?></span>
          <?php if ($admin['designation']): ?>
            <span class="chip chip-soft"><i class="bi bi-briefcase"></i><?= e($admin['designation']) ?></span>
          <?php endif; ?>
          <?php if ($admin['email']): ?>
            <span class="chip chip-soft"><i class="bi bi-envelope"></i><?= e($admin['email']) ?></span>
          <?php endif; ?>
        </div>
      </div>
      <?php if ($counts): ?>
        <div class="row g-2 text-center" style="min-width:290px">
          <?php foreach ([
            ['people', 'Students', (int)$counts['students']],
            ['person-video3', 'Faculty', (int)$counts['faculty']],
            ['journal-text', 'Papers', (int)$counts['exams']],
            ['diagram-3', 'Branches', (int)$counts['branches']],
          ] as [$icon, $label, $value]): ?>
            <div class="col-3">
              <div class="stat-card p-2">
                <div class="value fs-6"><?= $value ?></div>
                <div class="label"><?= e($label) ?></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($usingDefault): ?>
  <div class="alert alert-danger d-flex align-items-start gap-2">
    <i class="bi bi-shield-exclamation fs-4"></i>
    <div>
      <strong>You are still using the default password.</strong>
      Anyone who has seen the setup instructions can sign in as administrator.
      Please change it now.
    </div>
  </div>
<?php endif; ?>

<ul class="nav nav-pills-premium mb-3" role="tablist">
  <li><button class="nav-pill <?= $openTab === 'profile' ? 'active' : '' ?>"
              data-bs-toggle="tab" data-bs-target="#tabProfile" type="button">
    <i class="bi bi-person-lines-fill me-1"></i>My details</button></li>
  <li><button class="nav-pill <?= $openTab === 'college' ? 'active' : '' ?>"
              data-bs-toggle="tab" data-bs-target="#tabCollege" type="button">
    <i class="bi bi-bank me-1"></i>College profile</button></li>
  <li><button class="nav-pill <?= $openTab === 'location' ? 'active' : '' ?>"
              data-bs-toggle="tab" data-bs-target="#tabLocation" type="button">
    <i class="bi bi-geo-alt me-1"></i>Campus location</button></li>
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
              <div><h5 class="mb-0">Personal details</h5></div>
            </div>

            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label" for="name">Full Name</label>
                <input type="text" id="name" name="name" required
                       class="form-control <?= isset($profileErrors['name']) ? 'is-invalid' : '' ?>"
                       value="<?= e($a['name']) ?>">
                <div class="invalid-feedback"><?= e($profileErrors['name'] ?? 'Enter your name.') ?></div>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="designation">Designation</label>
                <select class="form-select" id="designation" name="designation">
                  <option value="">-- select --</option>
                  <?php foreach ($designations as $d): ?>
                    <option value="<?= e($d) ?>" <?= ($a['designation'] ?? '') === $d ? 'selected' : '' ?>>
                      <?= e($d) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="email">Email</label>
                <input type="email" id="email" name="email"
                       class="form-control <?= isset($profileErrors['email']) ? 'is-invalid' : '' ?>"
                       value="<?= e($a['email'] ?? '') ?>">
                <div class="invalid-feedback"><?= e($profileErrors['email'] ?? '') ?></div>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="phone">Phone</label>
                <input type="tel" id="phone" name="phone"
                       class="form-control <?= isset($profileErrors['phone']) ? 'is-invalid' : '' ?>"
                       value="<?= e($a['phone'] ?? '') ?>">
                <div class="invalid-feedback"><?= e($profileErrors['phone'] ?? '') ?></div>
              </div>
              <div class="col-md-6">
                <label class="form-label">Username</label>
                <input type="text" class="form-control" value="<?= e($admin['username']) ?>" disabled>
                <div class="form-text">The username cannot be changed.</div>
              </div>
              <div class="col-md-6">
                <label class="form-label">Account created</label>
                <input type="text" class="form-control" disabled
                       value="<?= e(format_datetime($admin['created_at'])) ?>">
              </div>
            </div>

            <button class="btn btn-primary mt-4">
              <i class="bi bi-check2 me-1"></i>Save Changes
            </button>
          </div>
        </form>
      </div>

      <div class="col-lg-5">
        <div class="card border-0 h-100">
          <div class="card-body p-4">
            <h6 class="mb-3"><i class="bi bi-shield-check me-1"></i>What this account can do</h6>
            <ul class="info-list">
              <li><span>Approve faculty</span><strong>Yes</strong></li>
              <li><span>Analyse faculty work</span><strong>Yes</strong></li>
              <li><span>Inspect any paper</span><strong>Read only</strong></li>
              <li><span>Write papers or questions</span><strong>No</strong></li>
              <li><span>Manage branches</span><strong>Yes</strong></li>
            </ul>
            <hr>
            <p class="small text-muted-2 mb-0">
              Papers and questions are written by the faculty of your college. You
              supervise, approve and analyse.
            </p>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ======================= College profile ======================= -->
  <div class="tab-pane fade <?= $openTab === 'college' ? 'show active' : '' ?>" id="tabCollege">
    <?php if (!$college): ?>
      <div class="alert alert-warning">
        This administrator account is not linked to a college yet. Re-run
        <code>upgrade.php</code> to create one.
      </div>
    <?php else: ?>
      <form method="post" enctype="multipart/form-data" novalidate
            class="needs-validation card border-0">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="college">
        <div class="card-body p-4">
          <div class="form-section-head">
            <span class="form-section-num"><i class="bi bi-bank"></i></span>
            <div>
              <h5 class="mb-0">College profile</h5>
              <span class="small text-muted-2">
                The name and logo your students and faculty see across the portal.
              </span>
            </div>
          </div>

          <div class="row g-3">
            <div class="col-12">
              <label class="form-label" for="logo">College Logo</label>
              <div class="logo-picker">
                <div class="logo-preview" id="logoPreview">
                  <?php $logoUrl = college_logo_url($college); ?>
                  <?php if ($logoUrl): ?>
                    <img src="<?= e($logoUrl) ?>" alt="">
                  <?php else: ?>
                    <i class="bi bi-image"></i>
                  <?php endif; ?>
                </div>
                <div class="flex-grow-1">
                  <input type="file" class="form-control <?= isset($collegeErrors['logo']) ? 'is-invalid' : '' ?>"
                         id="logo" name="logo" accept="image/png,image/jpeg,image/gif,image/webp">
                  <div class="invalid-feedback"><?= e($collegeErrors['logo'] ?? '') ?></div>
                  <div class="form-text">PNG, JPG, GIF or WEBP up to 2 MB.</div>
                  <?php if ($logoUrl): ?>
                    <div class="form-check mt-2">
                      <input class="form-check-input" type="checkbox" id="remove_logo"
                             name="remove_logo" value="1">
                      <label class="form-check-label small" for="remove_logo">
                        Remove the current logo
                      </label>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>

            <div class="col-md-8">
              <label class="form-label" for="cname">College Name</label>
              <input type="text" id="cname" name="name" required
                     class="form-control <?= isset($collegeErrors['name']) ? 'is-invalid' : '' ?>"
                     value="<?= e($c['name']) ?>">
              <div class="invalid-feedback"><?= e($collegeErrors['name'] ?? 'Enter the name.') ?></div>
            </div>
            <div class="col-md-4">
              <label class="form-label" for="code">College Code</label>
              <input type="text" id="code" name="code" required maxlength="30"
                     class="form-control text-uppercase <?= isset($collegeErrors['code']) ? 'is-invalid' : '' ?>"
                     value="<?= e($c['code']) ?>">
              <div class="invalid-feedback"><?= e($collegeErrors['code'] ?? 'Enter a code.') ?></div>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="cemail">College Email</label>
              <input type="email" id="cemail" name="email"
                     class="form-control <?= isset($collegeErrors['email']) ? 'is-invalid' : '' ?>"
                     value="<?= e($c['email'] ?? '') ?>">
              <div class="invalid-feedback"><?= e($collegeErrors['email'] ?? '') ?></div>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="cphone">College Phone</label>
              <input type="tel" id="cphone" name="phone" class="form-control"
                     value="<?= e($c['phone'] ?? '') ?>">
            </div>
            <div class="col-12">
              <label class="form-label" for="address">Address</label>
              <input type="text" id="address" name="address" class="form-control"
                     value="<?= e($c['address'] ?? '') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label" for="city">City</label>
              <input type="text" id="city" name="city" required
                     class="form-control <?= isset($collegeErrors['city']) ? 'is-invalid' : '' ?>"
                     value="<?= e($c['city'] ?? '') ?>">
              <div class="invalid-feedback"><?= e($collegeErrors['city'] ?? 'Enter the city.') ?></div>
            </div>
            <div class="col-md-4">
              <label class="form-label" for="state">State</label>
              <input type="text" id="state" name="state" class="form-control"
                     value="<?= e($c['state'] ?? '') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label" for="website">Website</label>
              <input type="text" id="website" name="website" class="form-control"
                     value="<?= e($c['website'] ?? '') ?>">
            </div>
          </div>

          <hr class="my-4">
          <div class="d-flex flex-wrap gap-2">
            <button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Save College Profile</button>
            <a href="<?= url('admin/branches.php') ?>" class="btn btn-outline-primary">
              <i class="bi bi-diagram-3 me-1"></i>Manage Branches
            </a>
          </div>
        </div>
      </form>
    <?php endif; ?>
  </div>

  <!-- ======================= Campus location ======================= -->
  <div class="tab-pane fade <?= $openTab === 'location' ? 'show active' : '' ?>" id="tabLocation">
    <?php if (!$college): ?>
      <div class="alert alert-warning">This administrator account is not linked to a college yet.</div>
    <?php else: $hasGeo = college_has_location($college); ?>
      <div class="row g-4">
        <div class="col-lg-7">
          <form method="post" class="card border-0" id="geoForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="location">
            <input type="hidden" name="lat" id="geoLat">
            <input type="hidden" name="lng" id="geoLng">
            <input type="hidden" name="accuracy" id="geoAcc">
            <div class="card-body p-4">
              <div class="form-section-head">
                <span class="form-section-num"><i class="bi bi-crosshair"></i></span>
                <div>
                  <h5 class="mb-0">Campus attendance point</h5>
                  <span class="small text-muted-2">
                    Stand at the centre of your campus (e.g. the main block) and fetch the location.
                    Students can mark attendance only within <?= ATTENDANCE_RADIUS_M ?> m of it.
                  </span>
                </div>
              </div>

              <div class="geo-stage" id="geoStage" data-state="<?= $hasGeo ? 'locked' : 'idle' ?>">
                <div class="geo-radar">
                  <span class="geo-ring r1"></span><span class="geo-ring r2"></span>
                  <span class="geo-ring r3"></span><span class="geo-sweep"></span>
                  <span class="geo-pin"><i class="bi bi-geo-alt-fill"></i></span>
                </div>
                <div class="geo-readout">
                  <div class="geo-status" id="geoStatus">
                    <?= $hasGeo ? 'Campus point is set' : 'No campus point saved yet' ?>
                  </div>
                  <div class="geo-coords">
                    <div><span>Latitude</span><strong id="geoLatOut"><?= $hasGeo ? e(number_format((float)$college['latitude'], 6)) : '—' ?></strong></div>
                    <div><span>Longitude</span><strong id="geoLngOut"><?= $hasGeo ? e(number_format((float)$college['longitude'], 6)) : '—' ?></strong></div>
                    <div><span>Accuracy</span><strong id="geoAccOut"><?= $hasGeo ? '±' . e(num((float)$college['geo_accuracy'])) . ' m' : '—' ?></strong></div>
                  </div>
                  <?php if ($hasGeo): ?>
                    <div class="small text-muted-2 mt-2">
                      Updated <?= e(format_datetime($college['geo_updated_at'])) ?> ·
                      <a href="https://www.google.com/maps?q=<?= e($college['latitude'] . ',' . $college['longitude']) ?>"
                         target="_blank" rel="noopener">View on map <i class="bi bi-box-arrow-up-right"></i></a>
                    </div>
                  <?php endif; ?>
                </div>
              </div>

              <div class="d-flex flex-wrap gap-2 mt-4">
                <button type="button" class="btn btn-outline-primary" id="geoFetch">
                  <i class="bi bi-broadcast me-1"></i>Fetch my location
                </button>
                <button class="btn btn-primary" id="geoSave" disabled>
                  <i class="bi bi-check2 me-1"></i><?= $hasGeo ? 'Update' : 'Save' ?> campus point
                </button>
              </div>
              <div class="form-text mt-2">
                Keep the page open for a few seconds - the reading sharpens as the GPS settles.
                A phone used in the open gives the best fix.
              </div>
            </div>
          </form>
        </div>

        <div class="col-lg-5">
          <div class="card border-0 h-100">
            <div class="card-body p-4">
              <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="mb-0"><i class="bi bi-calendar-check me-1"></i>Present today</h6>
                <span class="chip chip-soft"><?= count($todayAttendance) ?> students</span>
              </div>
              <?php if (!$todayAttendance): ?>
                <p class="small text-muted-2 mb-0">No student has marked attendance today yet.</p>
              <?php else: ?>
                <ul class="info-list">
                  <?php foreach ($todayAttendance as $r): ?>
                    <li>
                      <span><?= e($r['name']) ?>
                        <?php if ($r['enrollment_no']): ?>
                          <small class="text-muted-2">· <?= e($r['enrollment_no']) ?></small>
                        <?php endif; ?></span>
                      <strong><?= e(date('h:i A', strtotime($r['marked_at']))) ?>
                        <small class="text-muted-2 fw-normal">· <?= e(num((float)$r['distance_m'])) ?> m</small></strong>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <script>
      (function () {
        var fetchBtn = document.getElementById('geoFetch'), saveBtn = document.getElementById('geoSave'),
            stage = document.getElementById('geoStage'), status = document.getElementById('geoStatus'),
            best = null, watch = null, timer = null;
        function $(id) { return document.getElementById(id); }
        function stop() {
          if (watch !== null) navigator.geolocation.clearWatch(watch);
          watch = null; clearTimeout(timer);
        }
        fetchBtn.addEventListener('click', function () {
          if (!navigator.geolocation || !window.isSecureContext) {
            stage.dataset.state = 'error';
            status.textContent = 'Location needs a secure (https) page in a modern browser.';
            return;
          }
          stop(); best = null; stage.dataset.state = 'scanning';
          status.textContent = 'Locking on to GPS satellites…';
          watch = navigator.geolocation.watchPosition(function (p) {
            // Keep the sharpest reading seen so far.
            if (best && p.coords.accuracy >= best.accuracy) return;
            best = { lat: p.coords.latitude, lng: p.coords.longitude, acc: p.coords.accuracy };
            $('geoLat').value = best.lat; $('geoLng').value = best.lng; $('geoAcc').value = best.acc;
            $('geoLatOut').textContent = best.lat.toFixed(6);
            $('geoLngOut').textContent = best.lng.toFixed(6);
            $('geoAccOut').textContent = '±' + Math.round(best.acc) + ' m';
            saveBtn.disabled = best.acc > <?= ATTENDANCE_MAX_ACCURACY_M ?>;
            status.textContent = best.acc <= <?= ATTENDANCE_RADIUS_M ?>
              ? 'Precise fix acquired - ready to save' : 'Refining the fix… ±' + Math.round(best.acc) + ' m';
            if (best.acc <= 8) { stop(); stage.dataset.state = 'locked'; }
          }, function (err) {
            stop(); stage.dataset.state = 'error';
            status.textContent = err.code === 1
              ? 'Location permission was denied. Allow it in the browser and try again.'
              : 'Could not read the location. Please try again.';
          }, { enableHighAccuracy: true, maximumAge: 0, timeout: 30000 });
          timer = setTimeout(function () { stop(); if (best) stage.dataset.state = 'locked'; }, 25000);
        });
        $('geoForm').addEventListener('submit', stop);
      })();
      </script>
    <?php endif; ?>
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
                <i class="bi bi-exclamation-triangle-fill me-1"></i><?= e(reset($passwordErrors)) ?>
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
                <strong><?= $admin['password_changed_at']
                            ? e(format_datetime($admin['password_changed_at'])) : 'Never' ?></strong>
              </li>
              <li><span>Account created</span>
                <strong><?= e(format_datetime($admin['created_at'])) ?></strong></li>
              <li><span>Faculty awaiting approval</span><strong><?= $pendingFaculty ?></strong></li>
            </ul>
            <hr>
            <p class="small text-muted-2 mb-0">
              The administrator account can read and change everything in your college.
              Use a long, unique password and do not share it.
            </p>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
