<?php
/**
 * Admin - faculty accounts: approve new registrations, suspend or delete.
 *
 * A faculty account can set papers and read every allotted student's marks, so
 * self registrations land here as "pending" until an administrator approves
 * them (see FACULTY_SELF_APPROVE in config/config.php).
 */
require_once __DIR__ . '/../includes/auth.php';
$admin = require_admin();
$collegeId = (int)$admin['college_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id     = (int)post('faculty_id');
    $action = post('action');

    $st = db()->prepare('SELECT * FROM faculty WHERE id = ? AND college_id = ?');
    $st->execute([$id, $collegeId]);
    $target = $st->fetch();

    if (!$target) {
        set_flash('danger', 'Faculty account not found.');
    } else {
        switch ($action) {
            case 'approve':
                db()->prepare("UPDATE faculty SET status = 'approved', approved_at = ?, is_active = 1
                                WHERE id = ?")
                    ->execute([date('Y-m-d H:i:s'), $id]);
                set_flash('success', $target['name'] . ' has been approved and can now sign in.');
                break;

            case 'suspend':
                db()->prepare("UPDATE faculty SET status = 'suspended' WHERE id = ?")->execute([$id]);
                set_flash('success', $target['name'] . ' has been suspended.');
                break;

            case 'reinstate':
                db()->prepare("UPDATE faculty SET status = 'approved', is_active = 1 WHERE id = ?")
                    ->execute([$id]);
                set_flash('success', $target['name'] . ' has been reinstated.');
                break;

            case 'delete':
                // Their exams survive with faculty_id set to NULL (ON DELETE SET NULL),
                // so no student loses a result because a teacher left.
                db()->prepare('DELETE FROM faculty WHERE id = ?')->execute([$id]);
                set_flash('success', $target['name'] . ' has been removed. Their exams and '
                                   . 'results were kept.');
                break;

            case 'reset_password':
                $temp = 'Temp@' . random_int(10000, 99999);
                db()->prepare('UPDATE faculty SET password_hash = ?, password_changed_at = NULL
                                WHERE id = ?')
                    ->execute([password_hash($temp, PASSWORD_DEFAULT), $id]);
                set_flash('warning', 'Temporary password for ' . $target['name'] . ': ' . $temp
                                   . ' - share it with them and ask them to change it.');
                break;
        }
    }
    redirect('admin/faculty.php');
}

$filter = $_GET['status'] ?? '';
$search = trim($_GET['q'] ?? '');

$sql = 'SELECT f.*,
               (SELECT COUNT(*) FROM exams e WHERE e.faculty_id = f.id) AS exam_count,
               (SELECT COUNT(DISTINCT a.student_id) FROM exam_assignments a
                  JOIN exams e ON e.id = a.exam_id WHERE e.faculty_id = f.id) AS student_count
          FROM faculty f';
$where  = ['f.college_id = ?'];
$params = [$collegeId];
if (in_array($filter, ['pending', 'approved', 'suspended'], true)) {
    $where[] = 'f.status = ?';
    $params[] = $filter;
}
if ($search !== '') {
    $where[] = '(f.name LIKE ? OR f.email LIKE ? OR f.username LIKE ? OR f.department LIKE ?)';
    array_push($params, "%$search%", "%$search%", "%$search%", "%$search%");
}
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= " ORDER BY FIELD(f.status,'pending','approved','suspended'), f.created_at DESC";

$st = db()->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

$cst = db()->prepare(
    "SELECT
        SUM(status = 'pending')   AS pending,
        SUM(status = 'approved')  AS approved,
        SUM(status = 'suspended') AS suspended,
        COUNT(*) AS total
     FROM faculty WHERE college_id = ?");
$cst->execute([$collegeId]);
$counts = $cst->fetch();

$pageTitle = 'Faculty Accounts';
$activeNav = 'faculty';
require_once __DIR__ . '/includes/admin_header.php';
?>

<div class="row g-3 mb-3">
  <?php foreach ([
    ['people',          'text-primary', 'Total Faculty', (int)$counts['total']],
    ['hourglass-split', 'text-danger',  'Pending',       (int)$counts['pending']],
    ['patch-check',     'text-success', 'Approved',      (int)$counts['approved']],
    ['slash-circle',    'text-warning', 'Suspended',     (int)$counts['suspended']],
  ] as [$icon, $tone, $label, $value]): ?>
    <div class="col-6 col-lg-3">
      <div class="stat-card h-100">
        <i class="bi bi-<?= $icon ?> fs-4 <?= $tone ?>"></i>
        <div class="value mt-1"><?= $value ?></div>
        <div class="label"><?= e($label) ?></div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php if ((int)$counts['pending'] > 0): ?>
  <div class="alert alert-warning d-flex flex-wrap align-items-center gap-2">
    <i class="bi bi-person-exclamation fs-4"></i>
    <div class="flex-grow-1">
      <strong><?= (int)$counts['pending'] ?> faculty registration(s)</strong> are waiting for
      your approval. They cannot sign in until you approve them.
    </div>
    <a class="btn btn-sm btn-warning" href="<?= url('admin/faculty.php?status=pending') ?>">
      Show pending
    </a>
  </div>
<?php endif; ?>

<form method="get" class="card border-0 mb-3">
  <div class="card-body row g-2 align-items-end">
    <div class="col-md-5">
      <label class="form-label" for="q">Search</label>
      <div class="input-group">
        <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
        <input type="search" class="form-control" id="q" name="q" value="<?= e($search) ?>"
               placeholder="Name, email, username or department">
      </div>
    </div>
    <div class="col-md-4">
      <label class="form-label" for="status">Status</label>
      <select class="form-select" id="status" name="status">
        <option value="">All</option>
        <?php foreach (['pending' => 'Pending approval', 'approved' => 'Approved',
                        'suspended' => 'Suspended'] as $k => $label): ?>
          <option value="<?= $k ?>" <?= $filter === $k ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3"><button class="btn btn-outline-primary w-100">
      <i class="bi bi-funnel me-1"></i>Apply</button></div>
  </div>
</form>

<div class="row g-3">
  <?php foreach ($rows as $r): $id = (int)$r['id']; ?>
    <div class="col-xl-6">
      <div class="card border-0 h-100 <?= $r['status'] === 'pending'
                ? 'border-start border-4 border-warning' : '' ?>">
        <div class="card-body">
          <div class="d-flex align-items-start gap-3 mb-3">
            <span class="avatar-md"><?= e(initials($r['name'])) ?></span>
            <div class="flex-grow-1">
              <h6 class="mb-1"><?= e($r['name']) ?></h6>
              <div class="small text-muted-2">
                <?= e($r['designation'] ?: 'Faculty') ?>
                <?php if ($r['department']): ?> &middot; <?= e($r['department']) ?><?php endif; ?>
              </div>
            </div>
            <span class="badge <?= ['pending' => 'bg-warning text-dark',
                                    'approved' => 'bg-success',
                                    'suspended' => 'bg-secondary'][$r['status']] ?>">
              <?= e(ucfirst($r['status'])) ?>
            </span>
          </div>

          <ul class="info-list small mb-3">
            <li><span>Username</span><strong><?= e($r['username']) ?></strong></li>
            <li><span>Email</span><strong><?= e($r['email']) ?></strong></li>
            <?php if ($r['phone']): ?>
              <li><span>Phone</span><strong><?= e($r['phone']) ?></strong></li>
            <?php endif; ?>
            <?php if ($r['employee_code']): ?>
              <li><span>Employee ID</span><strong><?= e($r['employee_code']) ?></strong></li>
            <?php endif; ?>
            <?php if ($r['qualification']): ?>
              <li><span>Qualification</span><strong><?= e($r['qualification']) ?></strong></li>
            <?php endif; ?>
            <?php if ($r['specialization']): ?>
              <li><span>Specialisation</span><strong><?= e($r['specialization']) ?></strong></li>
            <?php endif; ?>
            <?php if ($r['experience_years'] !== null): ?>
              <li><span>Experience</span>
                <strong><?= num((float)$r['experience_years']) ?> years</strong></li>
            <?php endif; ?>
            <?php if ($r['joining_date']): ?>
              <li><span>Joined institution</span>
                <strong><?= e(date('d M Y', strtotime($r['joining_date']))) ?></strong></li>
            <?php endif; ?>
            <li><span>Registered</span><strong><?= e(format_datetime($r['created_at'])) ?></strong></li>
            <li><span>Exams / students</span>
              <strong><?= (int)$r['exam_count'] ?> / <?= (int)$r['student_count'] ?></strong></li>
          </ul>

          <?php if ($r['about']): ?>
            <div class="explanation-box mb-3 small"><?= e($r['about']) ?></div>
          <?php endif; ?>

          <div class="d-flex flex-wrap gap-1">
            <?php if ($r['status'] === 'pending'): ?>
              <form method="post" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="faculty_id" value="<?= $id ?>">
                <input type="hidden" name="action" value="approve">
                <button class="btn btn-sm btn-success">
                  <i class="bi bi-check2-circle me-1"></i>Approve
                </button>
              </form>
            <?php elseif ($r['status'] === 'approved'): ?>
              <form method="post" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="faculty_id" value="<?= $id ?>">
                <input type="hidden" name="action" value="suspend">
                <button class="btn btn-sm btn-outline-warning"
                        data-confirm="Suspend this faculty member? They will be signed out.">
                  <i class="bi bi-slash-circle me-1"></i>Suspend
                </button>
              </form>
            <?php else: ?>
              <form method="post" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="faculty_id" value="<?= $id ?>">
                <input type="hidden" name="action" value="reinstate">
                <button class="btn btn-sm btn-outline-success">
                  <i class="bi bi-arrow-counterclockwise me-1"></i>Reinstate
                </button>
              </form>
            <?php endif; ?>

            <form method="post" class="d-inline">
              <?= csrf_field() ?>
              <input type="hidden" name="faculty_id" value="<?= $id ?>">
              <input type="hidden" name="action" value="reset_password">
              <button class="btn btn-sm btn-outline-secondary"
                      data-confirm="Generate a temporary password for this faculty member?">
                <i class="bi bi-key me-1"></i>Reset password
              </button>
            </form>

            <form method="post" class="d-inline ms-auto">
              <?= csrf_field() ?>
              <input type="hidden" name="faculty_id" value="<?= $id ?>">
              <input type="hidden" name="action" value="delete">
              <button class="btn btn-sm btn-outline-danger"
                      data-confirm="Remove this faculty account? Their exams and results are kept.">
                <i class="bi bi-trash"></i>
              </button>
            </form>
          </div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>

  <?php if (!$rows): ?>
    <div class="col-12">
      <div class="card border-0 empty-state text-center">
        <div class="empty-state-icon"><i class="bi bi-person-video3"></i></div>
        <h5 class="mb-1">No faculty accounts match this filter</h5>
        <p class="text-muted-2 mb-0">
          Faculty register themselves at
          <code><?= e(url('faculty/register.php')) ?></code>.
        </p>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
