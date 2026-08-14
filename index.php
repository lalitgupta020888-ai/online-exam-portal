<?php
/**
 * Home page - hero, features and the subjects the portal covers.
 *
 * Individual papers are not listed here on purpose: a student only ever sees
 * the exams their faculty has allotted to them, and that list lives on the
 * dashboard behind the login.
 */
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Home';
$activeNav = 'home';

$exams      = get_exams(true);
$examCount  = count($exams);
$totalQ     = array_sum(array_column($exams, 'question_count'));
$studentCnt = (int)db()->query('SELECT COUNT(*) FROM students')->fetchColumn();
$facultyCnt = (int)db()->query('SELECT COUNT(*) FROM faculty')->fetchColumn();

// Subjects covered, with how many papers sit under each.
$subjects = [];
foreach ($exams as $exam) {
    $subjects[$exam['subject']] = ($subjects[$exam['subject']] ?? 0) + 1;
}
ksort($subjects);

require_once __DIR__ . '/includes/header.php';
?>

<!-- ============================ HERO ============================ -->
<section class="hero">
  <div class="container position-relative">
    <div class="row align-items-center gy-5">
      <div class="col-lg-7">
        <span class="hero-pill">
          <span class="dot"><i class="bi bi-gem"></i></span>
          A premium examination experience
        </span>

        <h1 class="mb-3">
          Take Your Exam <span class="accent">Online</span>
        </h1>

        <p class="lead mb-4">
          Attempt the papers your faculty has allotted to you, on any device, with a
          server controlled timer and answers that save themselves. Objective questions
          are evaluated the instant you submit; written answers are marked by your
          faculty and appear here with their feedback.
        </p>

        <div class="d-flex flex-wrap gap-3">
          <a href="<?= url(is_student_logged_in() ? 'dashboard.php' : 'login.php') ?>"
             class="btn btn-light btn-hero">
            <i class="bi bi-play-circle me-2"></i>Start Exam
          </a>
          <a href="<?= url('instructions.php') ?>" class="btn btn-outline-light btn-hero">
            <i class="bi bi-info-circle me-2"></i>Read Instructions
          </a>
        </div>
      </div>

      <div class="col-lg-5">
        <div class="hero-card">
          <div class="row text-center g-3">
            <div class="col-4">
              <div class="stat-value"><?= $examCount ?></div>
              <div class="stat-label">Papers</div>
            </div>
            <div class="col-4">
              <div class="stat-value"><?= $totalQ ?></div>
              <div class="stat-label">Questions</div>
            </div>
            <div class="col-4">
              <div class="stat-value"><?= $studentCnt ?></div>
              <div class="stat-label">Students</div>
            </div>
          </div>
          <hr class="border-light opacity-25 my-4">
          <ul class="list-unstyled mb-0 small d-grid gap-2">
            <li><i class="bi bi-check-circle-fill me-2"></i>Server controlled countdown timer</li>
            <li><i class="bi bi-check-circle-fill me-2"></i>Answers saved automatically</li>
            <li><i class="bi bi-check-circle-fill me-2"></i>Objective results the moment you submit</li>
            <li><i class="bi bi-check-circle-fill me-2"></i>Faculty marked written answers</li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</section>
<svg class="hero-wave" viewBox="0 0 1440 48" preserveAspectRatio="none" aria-hidden="true">
  <path d="M0,26 C240,50 480,0 720,14 C960,28 1200,50 1440,30 L1440,48 L0,48 Z"></path>
</svg>

<!-- ========================== FEATURES ========================== -->
<section class="py-5">
  <div class="container">
    <div class="section-head">
      <span class="eyebrow">Why this portal</span>
      <h2>Everything an examination needs, built in</h2>
      <p class="text-muted-2 mb-0">
        Designed for the student sitting the paper and the faculty setting it.
      </p>
    </div>

    <div class="row g-4">
      <?php
      $features = [
        ['stopwatch',       'bg-soft-primary', 'Timed Exams',
         'Each paper runs on a server controlled countdown. The clock keeps running even if you refresh, and the paper is submitted automatically when time is over.'],
        ['lightning-charge','bg-soft-gold',    'Instant Results',
         'Your objective score, percentage and pass or fail status appear the moment you submit - no waiting for manual checking.'],
        ['cpu',             'bg-soft-success', 'Automatic Evaluation',
         'Answers are compared with the answer key on the server, including negative marking, so every paper is graded exactly the same way.'],
        ['shield-lock',     'bg-soft-purple',  'Allotted Access',
         'You only ever see the subject papers your faculty allotted to you. No other examination is visible, or reachable, from your account.'],
        ['pencil-square',   'bg-soft-info',    'Written Answers',
         'Subjective questions get a full text editor with a live word counter, and are marked by your faculty with per question feedback.'],
        ['graph-up-arrow',  'bg-soft-danger',  'Deep Analysis',
         'Faculty get class distribution, toppers and per question success rates, plus every student\'s complete history and progress trend.'],
      ];
      foreach ($features as [$icon, $tone, $title, $text]): ?>
        <div class="col-md-6 col-lg-4">
          <div class="card card-hover feature-card h-100 p-4">
            <div class="feature-icon <?= $tone ?>"><i class="bi bi-<?= $icon ?>"></i></div>
            <h5 class="mb-2"><?= e($title) ?></h5>
            <p class="text-muted-2 mb-0 small"><?= e($text) ?></p>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ========================= HOW IT WORKS ======================== -->
<section class="py-5">
  <div class="container">
    <div class="section-head">
      <span class="eyebrow">How it works</span>
      <h2>From allotment to result</h2>
    </div>

    <div class="row g-4">
      <?php
      $steps = [
        ['1', 'Faculty sets the paper',
         'Questions are typed in or imported straight from a PDF, Word or text question paper.'],
        ['2', 'The paper is allotted',
         'Your faculty picks exactly which students may attempt it. It then appears on your dashboard under its subject.'],
        ['3', 'You attempt it',
         'One question at a time, with a palette, review flags and a countdown you cannot reset.'],
        ['4', 'Result and review',
         'Objective marks arrive instantly, written answers once your faculty has marked them - with feedback.'],
      ];
      foreach ($steps as [$n, $title, $text]): ?>
        <div class="col-md-6 col-lg-3">
          <div class="card card-hover h-100 p-4">
            <div class="feature-icon bg-soft-primary" style="font-weight:800"><?= $n ?></div>
            <h6 class="mb-2"><?= e($title) ?></h6>
            <p class="text-muted-2 mb-0 small"><?= e($text) ?></p>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ========================== SUBJECTS =========================== -->
<?php if ($subjects): ?>
<section class="py-5">
  <div class="container">
    <div class="section-head">
      <span class="eyebrow">Curriculum</span>
      <h2>Subjects on the portal</h2>
      <p class="text-muted-2 mb-0">
        Papers are allotted subject by subject - log in to see the ones assigned to you.
      </p>
    </div>

    <div class="row g-3 justify-content-center">
      <?php foreach ($subjects as $subject => $count): ?>
        <div class="col-sm-6 col-lg-4">
          <div class="card card-hover h-100">
            <div class="card-body d-flex align-items-center gap-3">
              <div class="subject-mark"><?= e(mb_strtoupper(mb_substr($subject, 0, 1))) ?></div>
              <div>
                <div class="fw-bold"><?= e($subject) ?></div>
                <div class="small text-muted-2">
                  <?= (int)$count ?> paper<?= $count > 1 ? 's' : '' ?> available
                </div>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <p class="text-center small text-muted-2 mt-4 mb-0">
      <i class="bi bi-shield-lock me-1"></i>
      You can only attempt a paper after your faculty allots it to you.
    </p>
  </div>
</section>
<?php endif; ?>

<!-- =========================== CTA ============================== -->
<section class="py-5">
  <div class="container">
    <div class="card border-0 p-4 p-md-5 text-center position-relative overflow-hidden"
         style="background:var(--oep-grad-ink)">
      <div style="position:absolute;inset:0;background:radial-gradient(30rem 16rem at 50% -30%,rgba(79,70,229,.6),transparent 62%)"></div>
      <div class="position-relative">
        <span class="eyebrow" style="color:var(--oep-gold)">Get started</span>
        <h3 class="text-white mb-2">Ready to test your knowledge?</h3>
        <p class="mb-4" style="color:rgba(255,255,255,.72)">
          Create a free student account, and the papers your faculty allots will be
          waiting on your dashboard.
        </p>
        <div class="d-flex justify-content-center flex-wrap gap-3">
          <a href="<?= url('register.php') ?>" class="btn btn-gold btn-hero">Register Now</a>
          <a href="<?= url('login.php') ?>" class="btn btn-outline-light btn-hero">Student Login</a>
        </div>
        <div class="mt-4 small" style="color:rgba(255,255,255,.5)">
          <?= $facultyCnt ?> faculty member<?= $facultyCnt === 1 ? '' : 's' ?> setting papers
          &middot; <?= $studentCnt ?> registered student<?= $studentCnt === 1 ? '' : 's' ?>
        </div>
      </div>
    </div>
  </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
