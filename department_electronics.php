<?php
/**
 * Department of Electronics: faculty / Head of Department details.
 */
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Department of Electronics';
$activeNav = 'department';

require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-5">
  <h1 class="h3 fw-bold mb-4"><i class="bi bi-cpu me-2"></i>Department of Electronics</h1>

  <div class="card border-0 shadow-sm" style="max-width: 420px;">
    <img src="<?= asset_url('assets/images/hod_electronics.jpg') ?>"
         class="card-img-top" alt="Mr. Ashish Singh, Head of Department, Electronics"
         style="object-fit: cover; max-height: 460px;">
    <div class="card-body text-center">
      <h2 class="h5 fw-bold mb-1">Mr. Ashish Singh</h2>
      <p class="mb-1">Head of Department</p>
      <p class="mb-0 text-muted"><strong>Qualification:</strong> M.E.</p>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
