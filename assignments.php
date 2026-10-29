<?php
/**
 * Student - assignments sent to my class: pending first, then submitted.
 */
require_once __DIR__ . '/includes/auth.php';
$student = require_student();

$assignments = get_student_assignments($student);
$pending = count(array_filter($assignments, fn($a) => !$a['submission_id']));

$pageTitle = 'My Assignments';
$activeNav = 'assignments';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-4">
  <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
    <div>
      <h3 class="mb-1"><i class="bi bi-file-earmark-text me-2"></i>My Assignments</h3>
      <div class="text-muted-2"><?= $pending ?> pending &middot; <?= count($assignments) - $pending ?> submitted</div>
    </div>
  </div>

  <?php if (!$assignments): ?>
    <div class="card border-0 p-5 text-center">
      <i class="bi bi-inbox display-4 text-muted-2"></i>
      <h5 class="mt-3 mb-1">No assignments yet</h5>
      <p class="text-muted-2 mb-0">When your faculty sends an assignment to your class, it appears here.</p>
    </div>
  <?php endif; ?>

  <div class="row g-3">
    <?php foreach ($assignments as $a):
        $overdue = strtotime($a['due_at']) < time();
    ?>
      <div class="col-lg-6">
        <a href="<?= url('assignment.php?id=' . (int)$a['id']) ?>" class="card border-0 h-100 text-reset text-decoration-none assignment-tile">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-start gap-2">
              <div>
                <span class="eyebrow"><?= e($a['subject']) ?></span>
                <h5 class="mb-1"><?= e($a['title']) ?></h5>
                <div class="small text-muted-2"><?= e($a['faculty_name']) ?> &middot; <?= num((float)$a['max_marks']) ?> marks</div>
              </div>
              <?php if ($a['graded_at']): ?>
                <span class="badge bg-success"><?= num((float)$a['marks']) ?> / <?= num((float)$a['max_marks']) ?></span>
              <?php elseif ($a['submission_id']): ?>
                <span class="badge bg-info text-dark">Submitted<?= (int)$a['is_late'] ? ' (late)' : '' ?></span>
              <?php elseif ($overdue): ?>
                <span class="badge bg-danger">Overdue</span>
              <?php else: ?>
                <span class="badge bg-warning text-dark">Pending</span>
              <?php endif; ?>
            </div>
            <div class="small mt-3 <?= $overdue && !$a['submission_id'] ? 'text-danger' : 'text-muted-2' ?>">
              <i class="bi bi-calendar-event me-1"></i>Last date <?= e(format_datetime($a['due_at'])) ?>
              <?php if (!$a['submission_id']): ?>&middot; <?= e(due_label($a['due_at'])) ?><?php endif; ?>
            </div>
          </div>
        </a>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
