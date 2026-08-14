<?php
/**
 * Admin - the branches / departments this college offers.
 *
 * Students pick one when they register, faculty can be attached to one, and
 * papers are allotted branch and semester wise - so this list is what the
 * whole class structure hangs off.
 */
require_once __DIR__ . '/../includes/auth.php';
$admin     = require_admin();
$collegeId = (int)$admin['college_id'];

$errors = [];
$edit   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post('action');
    $id     = (int)post('branch_id');
    $name   = post('name');
    $code   = strtoupper(post('code'));

    // A branch may only ever be touched inside the admin's own college.
    $owned = false;
    if ($id) {
        $st = db()->prepare('SELECT id FROM branches WHERE id = ? AND college_id = ?');
        $st->execute([$id, $collegeId]);
        $owned = (bool)$st->fetchColumn();
    }

    if ($action === 'save') {
        if (mb_strlen($name) < 2) {
            $errors['name'] = 'Enter the branch name.';
        }
        if (!preg_match('/^[A-Za-z0-9\-]{1,20}$/', $code)) {
            $errors['code'] = 'Code must be 1-20 characters: letters, digits or hyphen.';
        }
        if (!$errors) {
            $st = db()->prepare('SELECT id FROM branches
                                  WHERE college_id = ? AND code = ? AND id <> ?');
            $st->execute([$collegeId, $code, $id]);
            if ($st->fetch()) {
                $errors['code'] = 'That code is already used by another branch.';
            }
        }

        if (!$errors) {
            if ($id && $owned) {
                db()->prepare('UPDATE branches SET name = ?, code = ? WHERE id = ? AND college_id = ?')
                    ->execute([$name, $code, $id, $collegeId]);
                set_flash('success', 'Branch updated.');
            } elseif (!$id) {
                db()->prepare('INSERT INTO branches (college_id, name, code) VALUES (?,?,?)')
                    ->execute([$collegeId, $name, $code]);
                set_flash('success', 'Branch added.');
            }
            redirect('admin/branches.php');
        }
        $edit = ['id' => $id, 'name' => $name, 'code' => $code];

    } elseif ($action === 'toggle' && $owned) {
        db()->prepare('UPDATE branches SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
        set_flash('success', 'Branch visibility updated.');
        redirect('admin/branches.php');

    } elseif ($action === 'delete' && $owned) {
        // Students and faculty keep working; their branch simply becomes unset.
        $st = db()->prepare('SELECT COUNT(*) FROM students WHERE branch_id = ?');
        $st->execute([$id]);
        $used = (int)$st->fetchColumn();

        db()->prepare('DELETE FROM branches WHERE id = ? AND college_id = ?')
            ->execute([$id, $collegeId]);
        set_flash('success', 'Branch removed.'
            . ($used > 0 ? " $used student(s) now have no branch set - ask them to update "
                         . 'their profile.' : ''));
        redirect('admin/branches.php');
    }
}

if ($edit === null && get_int('edit')) {
    $st = db()->prepare('SELECT * FROM branches WHERE id = ? AND college_id = ?');
    $st->execute([get_int('edit'), $collegeId]);
    $edit = $st->fetch() ?: null;
}

$st = db()->prepare(
    'SELECT b.*,
            (SELECT COUNT(*) FROM students s WHERE s.branch_id = b.id) AS students,
            (SELECT COUNT(*) FROM faculty f WHERE f.branch_id = b.id) AS faculty,
            (SELECT COUNT(*) FROM exams e WHERE e.target_branch_id = b.id) AS papers
       FROM branches b WHERE b.college_id = ? ORDER BY b.name'
);
$st->execute([$collegeId]);
$branches = $st->fetchAll();

$pageTitle = 'Branches';
$activeNav = 'branches';
require_once __DIR__ . '/includes/admin_header.php';
?>

<div class="alert alert-info d-flex align-items-start gap-2">
  <i class="bi bi-diagram-3 fs-5"></i>
  <div>
    Students choose one of these when they register, and your faculty allot papers
    <strong>branch and semester wise</strong>. Keep the list tidy - it is the backbone of
    every roster and filter in the portal.
  </div>
</div>

<div class="row g-4">
  <!-- ------------------------- The list ------------------------- -->
  <div class="col-lg-8">
    <div class="card border-0">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead>
            <tr><th>Branch</th><th>Code</th><th>Students</th><th>Faculty</th>
                <th>Papers</th><th>Status</th><th class="text-end">Actions</th></tr>
          </thead>
          <tbody>
            <?php foreach ($branches as $b): $id = (int)$b['id']; ?>
              <tr>
                <td class="fw-semibold"><?= e($b['name']) ?></td>
                <td><span class="class-chip"><?= e($b['code']) ?></span></td>
                <td><?= (int)$b['students'] ?></td>
                <td><?= (int)$b['faculty'] ?></td>
                <td><?= (int)$b['papers'] ?></td>
                <td>
                  <span class="badge <?= (int)$b['is_active'] ? 'bg-success' : 'bg-secondary' ?>">
                    <?= (int)$b['is_active'] ? 'Active' : 'Hidden' ?>
                  </span>
                </td>
                <td class="text-end text-nowrap">
                  <a class="btn btn-sm btn-outline-primary"
                     href="<?= url('admin/branches.php?edit=' . $id) ?>"><i class="bi bi-pencil"></i></a>
                  <form method="post" class="d-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="branch_id" value="<?= $id ?>">
                    <input type="hidden" name="action" value="toggle">
                    <button class="btn btn-sm btn-outline-warning" title="Show / hide">
                      <i class="bi bi-eye<?= (int)$b['is_active'] ? '-slash' : '' ?>"></i>
                    </button>
                  </form>
                  <form method="post" class="d-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="branch_id" value="<?= $id ?>">
                    <input type="hidden" name="action" value="delete">
                    <button class="btn btn-sm btn-outline-danger"
                            data-confirm="Remove this branch? Students in it will have no branch set until they update their profile.">
                      <i class="bi bi-trash"></i>
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$branches): ?>
              <tr><td colspan="7" class="text-center text-muted-2 py-5">
                No branches yet - add the first one on the right.
              </td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ------------------------- Add / edit ------------------------- -->
  <div class="col-lg-4">
    <form method="post" novalidate class="needs-validation card border-0">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="branch_id" value="<?= (int)($edit['id'] ?? 0) ?>">
      <div class="card-body p-4">
        <div class="form-section-head">
          <span class="form-section-num"><i class="bi bi-<?= $edit ? 'pencil' : 'plus-lg' ?>"></i></span>
          <div>
            <h5 class="mb-0"><?= $edit ? 'Edit branch' : 'Add a branch' ?></h5>
            <span class="small text-muted-2">e.g. Computer Science &amp; Engineering / CSE</span>
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label" for="name">Branch Name</label>
          <input type="text" id="name" name="name" required
                 class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>"
                 value="<?= e($edit['name'] ?? '') ?>">
          <div class="invalid-feedback"><?= e($errors['name'] ?? 'Enter the branch name.') ?></div>
        </div>

        <div class="mb-4">
          <label class="form-label" for="code">Short Code</label>
          <input type="text" id="code" name="code" required maxlength="20"
                 class="form-control text-uppercase <?= isset($errors['code']) ? 'is-invalid' : '' ?>"
                 value="<?= e($edit['code'] ?? '') ?>">
          <div class="invalid-feedback"><?= e($errors['code'] ?? 'Enter a short code.') ?></div>
        </div>

        <div class="d-flex gap-2">
          <button class="btn btn-primary">
            <i class="bi bi-check2 me-1"></i><?= $edit ? 'Save' : 'Add Branch' ?>
          </button>
          <?php if ($edit): ?>
            <a href="<?= url('admin/branches.php') ?>" class="btn btn-outline-secondary">Cancel</a>
          <?php endif; ?>
        </div>
      </div>
    </form>
  </div>
</div>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
