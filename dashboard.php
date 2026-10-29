<?php
/**
 * Student exam dashboard.
 *
 * Shows ONLY the papers a faculty member (or the administrator) has allotted to
 * this student, grouped subject by subject. Nothing else is visible here.
 */
require_once __DIR__ . '/includes/auth.php';

ensure_attendance_schema();      // aadhaar_no column on an older database
$student = require_student();

// Any abandoned attempt whose time is over gets graded before we draw the page.
close_expired_attempts((int)$student['id']);

$pageTitle = 'Exam Dashboard';
$activeNav = 'exams';

$bySubject = get_student_exams_by_subject((int)$student['id']);
$examCount = array_sum(array_map('count', $bySubject));

// Live attempt (if the student walked away mid exam) per exam.
$live = db()->prepare(
    "SELECT exam_id, id FROM attempts
      WHERE student_id = ? AND status = 'in_progress'"
);
$live->execute([$student['id']]);
$inProgress = array_column($live->fetchAll(), 'id', 'exam_id');

// Best previous result per exam.
$prev = db()->prepare(
    'SELECT exam_id, MAX(percentage) AS best, COUNT(*) AS attempts
       FROM results WHERE student_id = ? GROUP BY exam_id'
);
$prev->execute([$student['id']]);
$history = [];
foreach ($prev->fetchAll() as $r) {
    $history[$r['exam_id']] = $r;
}

// Campus attendance
$attCollege  = get_college((int)$student['college_id']);
$attToday    = attendance_today((int)$student['id']);
$attSummary  = attendance_summary((int)$student['id']);
$hasAadhaar  = !empty($student['aadhaar_no']);
$hasFace     = student_has_face($student);
$campusReady = college_has_location($attCollege);
$attState    = $attToday ? 'done' : (!$hasAadhaar ? 'aadhaar' : (!$hasFace ? 'face'
             : (!$campusReady ? 'campus' : 'ready')));

// Monday-to-Sunday strip for this week.
$weekStart = strtotime('monday this week');
$week = [];
for ($i = 0; $i < 7; $i++) {
    $d = date('Y-m-d', strtotime("+$i day", $weekStart));
    $week[] = ['label' => date('D', strtotime($d)), 'date' => $d,
               'present' => isset($attSummary['days'][$d]),
               'today' => $d === date('Y-m-d'), 'future' => $d > date('Y-m-d')];
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-4 py-lg-5">

  <div class="page-head d-flex flex-wrap justify-content-between align-items-end mb-4 gap-3">
    <div>
      <span class="eyebrow">My Dashboard</span>
      <h2 class="mb-1">Hello, <?= e($student['name']) ?></h2>
      <p class="text-muted-2 mb-0">
        <?php if ($examCount): ?>
          You have <strong><?= $examCount ?></strong> paper<?= $examCount > 1 ? 's' : '' ?>
          allotted across <strong><?= count($bySubject) ?></strong>
          subject<?= count($bySubject) > 1 ? 's' : '' ?>.
        <?php else: ?>
          Your allotted examinations will appear here.
        <?php endif; ?>
      </p>
    </div>
    <a href="<?= url('results.php') ?>" class="btn btn-outline-primary">
      <i class="bi bi-bar-chart me-1"></i>My Results
    </a>
  </div>

  <!-- ------------------------ Campus attendance ------------------------ -->
  <section class="att-card mb-4" id="attCard" data-state="<?= e($attState) ?>">
    <div class="att-glow"></div>
    <div class="att-body">
      <div class="att-info">
        <span class="att-eyebrow"><i class="bi bi-geo-alt-fill"></i>Campus Attendance ·
          <?= e(date('l, d M Y')) ?></span>
        <h3 class="att-title" id="attTitle">
          <?php if ($attState === 'done'): ?>You're marked present today
          <?php elseif ($attState === 'aadhaar'): ?>Verify your Aadhaar to begin
          <?php elseif ($attState === 'face'): ?>Register your face to begin
          <?php elseif ($attState === 'campus'): ?>Campus point not set yet
          <?php else: ?>Ready when you are on campus<?php endif; ?>
        </h3>
        <p class="att-sub" id="attMsg">
          <?php if ($attState === 'done'): ?>
            Checked in at <strong><?= e(date('h:i A', strtotime($attToday['marked_at']))) ?></strong>,
            <?= e(num((float)$attToday['distance_m'])) ?> m from the campus point.
          <?php elseif ($attState === 'aadhaar'): ?>
            Attendance is linked to your identity. Add your Aadhaar number once in My Account.
          <?php elseif ($attState === 'face'): ?>
            Attendance is marked by face authentication. Scan your face once in My Account.
          <?php elseif ($attState === 'campus'): ?>
            Your college administrator has not saved the campus location. Attendance opens as soon as they do.
          <?php else: ?>
            Step inside the campus - your face is scanned, then you must be within
            <?= ATTENDANCE_RADIUS_M ?> m of the attendance point. Allow camera and location when asked.
          <?php endif; ?>
        </p>

        <div class="att-week">
          <?php foreach ($week as $w): ?>
            <div class="att-day <?= $w['present'] ? 'present' : '' ?> <?= $w['today'] ? 'today' : '' ?> <?= $w['future'] ? 'future' : '' ?>"
                 <?= $w['today'] ? 'id="attTodayDot"' : '' ?> title="<?= e(date('d M', strtotime($w['date']))) ?>">
              <span class="att-dot"><i class="bi bi-check-lg"></i></span>
              <small><?= e($w['label']) ?></small>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="att-stats">
          <div><strong id="attMonth"><?= (int)$attSummary['month'] ?></strong><span>days this month</span></div>
          <div><strong id="attStreak"><?= (int)$attSummary['streak'] ?></strong><span>day streak <i class="bi bi-fire"></i></span></div>
        </div>
      </div>

      <div class="att-action">
        <div class="att-radar" aria-hidden="true">
          <span class="ring r1"></span><span class="ring r2"></span><span class="ring r3"></span>
          <span class="sweep"></span>
          <span class="core"><i class="bi <?= $attState === 'done' ? 'bi-check-lg' : 'bi-fingerprint' ?>" id="attIcon"></i></span>
        </div>
        <?php if ($attState === 'aadhaar'): ?>
          <a href="<?= url('profile.php#aadhaar') ?>" class="btn btn-gold att-btn">
            <i class="bi bi-person-vcard me-1"></i>Add Aadhaar
          </a>
        <?php elseif ($attState === 'face'): ?>
          <a href="<?= url('profile.php#face') ?>" class="btn btn-gold att-btn">
            <i class="bi bi-person-bounding-box me-1"></i>Register Face
          </a>
        <?php elseif ($attState === 'ready'): ?>
          <button type="button" class="btn btn-gold att-btn" id="attBtn">
            <i class="bi bi-person-bounding-box me-1"></i>Mark Attendance
          </button>
        <?php elseif ($attState === 'done'): ?>
          <span class="att-badge"><i class="bi bi-patch-check-fill me-1"></i>Present</span>
        <?php else: ?>
          <button type="button" class="btn btn-light att-btn" disabled>
            <i class="bi bi-hourglass me-1"></i>Awaiting campus setup
          </button>
        <?php endif; ?>
        <div class="att-live" id="attLive"></div>
      </div>
    </div>
  </section>

  <?php if ($inProgress): ?>
    <div class="alert alert-warning d-flex align-items-center">
      <i class="bi bi-hourglass-split me-2 fs-5"></i>
      <div>You have an examination in progress. The timer is still running -
        resume it from its card below before the time runs out.</div>
    </div>
  <?php endif; ?>

  <?php if (!$examCount): ?>
    <div class="card empty-state text-center">
      <div class="empty-state-icon"><i class="bi bi-journal-bookmark"></i></div>
      <h5 class="mb-2">No examination has been allotted to you yet</h5>
      <p class="text-muted-2 mb-0 mx-auto" style="max-width:32rem">
        Your faculty decides which subject's paper you may attempt. As soon as a paper
        is allotted to you it will appear here, grouped by subject. You cannot see or
        start any other examination.
      </p>
    </div>
  <?php endif; ?>

  <?php foreach ($bySubject as $subject => $exams): ?>
    <section class="subject-block">
      <div class="subject-head">
        <div class="subject-mark"><?= e(mb_strtoupper(mb_substr($subject, 0, 1))) ?></div>
        <div>
          <h4 class="mb-0"><?= e($subject) ?></h4>
          <span class="small text-muted-2">
            <?= count($exams) ?> paper<?= count($exams) > 1 ? 's' : '' ?> allotted
          </span>
        </div>
        <span class="subject-rule"></span>
      </div>

      <div class="row g-4">
        <?php foreach ($exams as $exam):
          $eid     = (int)$exam['id'];
          $qCount  = (int)$exam['question_count'];
          $running = $inProgress[$eid] ?? null;
          $past    = $history[$eid] ?? null;
        ?>
          <div class="col-md-6 col-xl-4">
            <article class="card card-hover exam-card h-100">
              <div class="exam-head">
                <div class="exam-head-top">
                  <span class="chip chip-light">
                    <i class="bi bi-bookmark-fill"></i><?= e($exam['subject']) ?>
                  </span>
                  <?php if ($running): ?>
                    <span class="chip chip-warning">In progress</span>
                  <?php elseif ($past): ?>
                    <span class="chip chip-success">Attempted</span>
                  <?php endif; ?>
                </div>
                <h5 class="exam-title"><?= e($exam['title']) ?></h5>
                <?php if (!empty($exam['faculty_name'])): ?>
                  <div class="exam-faculty">
                    <i class="bi bi-person-video3"></i>Allotted by <?= e($exam['faculty_name']) ?>
                  </div>
                <?php endif; ?>
              </div>

              <div class="card-body d-flex flex-column">
                <?php if (trim((string)$exam['description']) !== ''): ?>
                  <p class="text-muted-2 small"><?= e($exam['description']) ?></p>
                <?php endif; ?>

                <div class="exam-meta mb-3">
                  <div class="meta-item"><span>Questions</span><strong><?= $qCount ?></strong></div>
                  <div class="meta-item"><span>Duration</span>
                    <strong><?= (int)$exam['duration_minutes'] ?> min</strong></div>
                  <div class="meta-item"><span>Total Marks</span>
                    <strong><?= num((float)$exam['total_marks']) ?></strong></div>
                  <div class="meta-item"><span>Passing Marks</span>
                    <strong><?= num((float)$exam['passing_marks']) ?></strong></div>
                </div>

                <ul class="exam-notes">
                  <?php if ((int)$exam['subjective_count'] > 0): ?>
                    <li><i class="bi bi-pencil-square text-warning"></i>
                      <?= (int)$exam['subjective_count'] ?> written question(s) -
                      marked by your faculty</li>
                  <?php endif; ?>
                  <li>
                    <?php if ((float)$exam['negative_marks'] > 0): ?>
                      <i class="bi bi-dash-circle text-danger"></i>
                      Negative marking: <?= num((float)$exam['negative_marks']) ?> per wrong answer
                    <?php else: ?>
                      <i class="bi bi-check-circle text-success"></i>No negative marking
                    <?php endif; ?>
                  </li>
                  <?php if ($past): ?>
                    <li><i class="bi bi-trophy text-primary"></i>
                      Best score <strong><?= num((float)$past['best']) ?>%</strong>
                      in <?= (int)$past['attempts'] ?> attempt<?= (int)$past['attempts'] > 1 ? 's' : '' ?></li>
                  <?php endif; ?>
                </ul>

                <div class="mt-auto pt-3">
                  <?php if ($qCount === 0): ?>
                    <button class="btn btn-secondary w-100" disabled>No questions added yet</button>
                  <?php elseif ($running): ?>
                    <a href="<?= url('exam.php?attempt=' . (int)$running) ?>"
                       class="btn btn-warning w-100 fw-bold">
                      <i class="bi bi-arrow-right-circle me-1"></i>Resume Exam
                    </a>
                  <?php elseif ($past): ?>
                    <a href="<?= url('results.php') ?>" class="btn btn-outline-success w-100">
                      <i class="bi bi-check2-circle me-1"></i>Submitted - view result
                    </a>
                  <?php else: ?>
                    <a href="<?= url('instructions.php?exam_id=' . $eid) ?>" class="btn btn-primary w-100">
                      <i class="bi bi-play-circle me-1"></i>Start Exam
                    </a>
                  <?php endif; ?>
                </div>
              </div>
            </article>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endforeach; ?>
</div>

<?php if ($attState === 'ready'): ?>
<script src="<?= asset_url('assets/js/face.js') ?>"></script>
<script>
(function () {
  var card = document.getElementById('attCard'), btn = document.getElementById('attBtn'),
      title = document.getElementById('attTitle'), msg = document.getElementById('attMsg'),
      live = document.getElementById('attLive'), icon = document.getElementById('attIcon'),
      RADIUS = <?= ATTENDANCE_RADIUS_M ?>, busy = false;

  function setState(state, head, text, ic) {
    card.dataset.state = state;
    if (head) title.textContent = head;
    if (text) msg.innerHTML = text;
    if (ic) icon.className = 'bi ' + ic;
  }
  function fail(head, text) {
    busy = false; btn.disabled = false; live.textContent = '';
    btn.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i>Try Again';
    setState('error', head, text, 'bi-x-lg');
  }

  // Watch the GPS for a few seconds and send the sharpest fix we get.
  function locate(done) {
    var best = null, id, timer;
    function finish() { navigator.geolocation.clearWatch(id); clearTimeout(timer); done(best); }
    id = navigator.geolocation.watchPosition(function (p) {
      if (!best || p.coords.accuracy < best.accuracy) best = p.coords;
      live.textContent = 'GPS accuracy ±' + Math.round(best.accuracy) + ' m';
      if (best.accuracy <= 10) finish();
    }, function (err) {
      navigator.geolocation.clearWatch(id); clearTimeout(timer);
      if (best) return done(best);
      fail('Location unavailable', err.code === 1
        ? 'Location permission was denied. Allow location for this site in your browser settings and try again.'
        : 'We could not read your location. Turn on GPS / location services and try again.');
    }, { enableHighAccuracy: true, maximumAge: 0, timeout: 20000 });
    timer = setTimeout(finish, 12000);
  }

  btn.addEventListener('click', function () {
    if (busy) return;
    if (!navigator.geolocation || !window.isSecureContext) {
      return fail('Location not supported', 'Open the portal over https in an up to date browser to mark attendance.');
    }
    busy = true; btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Scanning face…';
    setState('scanning', 'Verifying your face…', 'Look straight at the camera.', 'bi-person-bounding-box');

    FaceScan.capture({ title: 'Face authentication' }).then(function (face) {
      btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Scanning…';
      setState('scanning', 'Verifying your location…', 'Hold steady - locking on to GPS satellites.', 'bi-broadcast');
      locate(function (c) { if (c) send(face, c); });
    }, function (e) {
      if (!e.cancelled) return fail('Face scan failed', e.message);
      busy = false; btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-person-bounding-box me-1"></i>Mark Attendance';
      setState('ready', 'Ready when you are on campus', 'Tap Mark Attendance again when you are ready for the face scan.', 'bi-fingerprint');
    });
  });

  function send(face, c) {
    var body = new FormData();
    body.append('csrf_token', <?= json_encode(csrf_token()) ?>);
    body.append('face', JSON.stringify(face));
    body.append('lat', c.latitude); body.append('lng', c.longitude); body.append('accuracy', c.accuracy);
    fetch(<?= json_encode(url('api/mark_attendance.php')) ?>, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) {
          var head = d.code === 'outside' ? 'You are outside the campus zone'
                   : d.code === 'accuracy' ? 'GPS signal too weak'
                   : d.code === 'face' ? 'Face not recognised' : 'Attendance not marked';
          return fail(head, d.error);
        }
        busy = false; live.textContent = '';
        setState('done', 'You\'re marked present today',
          'Checked in at <strong>' + d.time + '</strong>, ' + Math.round(d.distance) + ' m from the campus point.',
          'bi-check-lg');
        btn.outerHTML = '<span class="att-badge"><i class="bi bi-patch-check-fill me-1"></i>Present</span>';
        var dot = document.getElementById('attTodayDot');
        if (dot && !dot.classList.contains('present')) {
          dot.classList.add('present');
          ['attMonth', 'attStreak'].forEach(function (k) {
            var el = document.getElementById(k); el.textContent = (+el.textContent) + 1;
          });
        }
      })
      .catch(function () { fail('Connection problem', 'We could not reach the server. Check your internet and try again.'); });
  }
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
