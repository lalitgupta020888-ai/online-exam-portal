<?php
/**
 * Admin - oversight of every question paper in the portal.
 *
 * The administrator does not write papers. From here they can inspect one,
 * allot it to students, hand an ownerless paper to a faculty member, or
 * remove a paper altogether.
 */
require_once __DIR__ . '/../includes/auth.php';
$admin     = require_admin();
$collegeId = (int)$admin['college_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $examId = (int)post('exam_id');
    $exam   = get_exam($examId);
    // A paper from another college is simply not there as far as this admin is concerned.
    if ($exam && (int)$exam['college_id'] !== $collegeId) { $exam = null; }

    if (!$exam) {
        set_flash('danger', 'Question paper not found.');
    } else {
        switch (post('action')) {
            case 'toggle':
                db()->prepare('UPDATE exams SET is_active = 1 - is_active WHERE id = ?')
                    ->execute([$examId]);
                set_flash('success', 'Paper visibility updated.');
                break;

            case 'transfer':
                $facultyId = (int)post('faculty_id');
                $st = db()->prepare("SELECT id, name FROM faculty
                                      WHERE id = ? AND status = 'approved' AND college_id = ?");
                $st->execute([$facultyId, $collegeId]);
                if ($owner = $st->fetch()) {
                    db()->prepare('UPDATE exams SET faculty_id = ? WHERE id = ?')
                        ->execute([$facultyId, $examId]);
                    set_flash('success', 'The paper now belongs to ' . $owner['name']
                                       . ', who can maintain it from the faculty panel.');
                } else {
                    set_flash('danger', 'Choose an approved faculty member.');
                }
                break;

            case 'delete':
                db()->prepare('DELETE FROM exams WHERE id = ?')->execute([$examId]);
                set_flash('success', 'Paper deleted along with its questions and results.');
                break;
        }
    }
    redirect('admin/papers.php');
}

$facultyFilter = get_int('faculty_id');
$search        = trim($_GET['q'] ?? '');

$sql = 'SELECT ' . exam_select_sql() . ', f.name AS faculty_name, f.id AS owner_id,
               (SELECT COUNT(*) FROM results r WHERE r.exam_id = e.id) AS submissions,
               (SELECT COALESCE(AVG(r.percentage),0) FROM results r WHERE r.exam_id = e.id) AS avg_pct,
               (SELECT COUNT(*) FROM results r WHERE r.exam_id = e.id AND r.pending_evaluation = 1)
                 AS pending
          FROM exams e
     LEFT JOIN faculty f ON f.id = e.faculty_id';
$where  = ['e.college_id = ?'];
$params = [$collegeId];
if ($facultyFilter === -1) {
    $where[] = 'e.faculty_id IS NULL';
} elseif ($facultyFilter > 0) {
    $where[] = 'e.faculty_id = ?';
    $params[] = $facultyFilter;
}
if ($search !== '') {
    $where[] = '(e.title LIKE ? OR e.subject LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY e.id DESC';

$st = db()->prepare($sql);
$st->execute($params);
$papers = $st->fetchAll();

$fl = db()->prepare("SELECT id, name, department FROM faculty
                      WHERE status = 'approved' AND college_id = ? ORDER BY name");
$fl->execute([$collegeId]);
$facultyList = $fl->fetchAll();
$os = db()->prepare('SELECT COUNT(*) FROM exams WHERE faculty_id IS NULL AND college_id = ?');
$os->execute([$collegeId]);
$orphans = (int)$os->fetchColumn();

$pageTitle = 'Question Papers';
$activeNav = 'papers';
require_once __DIR__ . '/includes/admin_header.php';
?>

<div class="alert alert-info d-flex align-items-start gap-2">
  <i class="bi bi-eye fs-5"></i>
  <div>
    Papers and questions are written by the faculty. From here you can
    <strong>inspect</strong> any paper, allot it to students, transfer it to a faculty
    member, or remove it.
  </div>
</div>

<?php if ($orphans > 0): ?>
  <div class="alert alert-warning d-flex align-items-center gap-2">
    <i class="bi bi-person-dash fs-4"></i>
    <div class="flex-grow-1">
      <strong><?= $orphans ?> paper(s) have no faculty owner</strong> - nobody can edit or
      mark them. Use <em>Transfer</em> to hand each one to a faculty member.
    </div>
    <a class="btn btn-sm btn-warning" href="<?= url('admin/papers.php?faculty_id=-1') ?>">
      Show them
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
               placeholder="Paper title or subject">
      </div>
    </div>
    <div class="col-md-4">
      <label class="form-label" for="faculty_id">Set by</label>
      <select class="form-select" id="faculty_id" name="faculty_id">
        <option value="0">All faculty</option>
        <option value="-1" <?= $facultyFilter === -1 ? 'selected' : '' ?>>No owner</option>
        <?php foreach ($facultyList as $fl): ?>
          <option value="<?= (int)$fl['id'] ?>" <?= $facultyFilter === (int)$fl['id'] ? 'selected' : '' ?>>
            <?= e($fl['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3"><button class="btn btn-outline-primary w-100">
      <i class="bi bi-funnel me-1"></i>Apply</button></div>
  </div>
</form>

<div class="card border-0">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead>
        <tr>
          <th>Paper</th><th>Set by</th><th>Questions</th><th>Marks</th>
          <th>Allotted</th><th>Submitted</th><th>Average</th><th>Status</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($papers as $p): $id = (int)$p['id']; ?>
          <tr>
            <td>
              <div class="fw-semibold"><?= e($p['title']) ?></div>
              <div class="small text-muted-2"><?= e($p['subject']) ?></div>
            </td>
            <td>
              <?php if ($p['faculty_name']): ?>
                <a class="small fw-semibold"
                   href="<?= url('admin/faculty_detail.php?id=' . (int)$p['owner_id']) ?>">
                  <?= e($p['faculty_name']) ?>
                </a>
              <?php else: ?>
                <span class="badge bg-warning text-dark">No owner</span>
              <?php endif; ?>
            </td>
            <td>
              <?= (int)$p['question_count'] ?>
              <?php if ((int)$p['subjective_count'] > 0): ?>
                <div class="small text-muted-2"><?= (int)$p['subjective_count'] ?> written</div>
              <?php endif; ?>
            </td>
            <td><?= num((float)$p['total_marks']) ?></td>
            <td><?= (int)$p['assigned_count'] ?></td>
            <td><?= (int)$p['submissions'] ?></td>
            <td><?= (int)$p['submissions'] > 0 ? num((float)$p['avg_pct']) . '%' : '-' ?></td>
            <td>
              <?php if ((int)$p['pending'] > 0): ?>
                <span class="badge bg-danger"><?= (int)$p['pending'] ?> unmarked</span>
              <?php elseif (!(int)$p['is_active']): ?>
                <span class="badge bg-secondary">Hidden</span>
              <?php else: ?>
                <span class="badge bg-success">Active</span>
              <?php endif; ?>
            </td>
            <td class="text-end text-nowrap">
              <a class="btn btn-sm btn-outline-primary"
                 href="<?= url('admin/paper_view.php?exam_id=' . $id) ?>"
                 title="View the questions"><i class="bi bi-eye"></i></a>
              <a class="btn btn-sm btn-outline-secondary"
                 href="<?= url('admin/assign.php?exam_id=' . $id) ?>"
                 title="Allot to students"><i class="bi bi-people"></i></a>
              <a class="btn btn-sm btn-outline-secondary"
                 href="<?= url('admin/results.php?exam_id=' . $id) ?>"
                 title="Results"><i class="bi bi-bar-chart"></i></a>
              <button class="btn btn-sm btn-outline-secondary" type="button"
                      data-bs-toggle="modal" data-bs-target="#transfer<?= $id ?>"
                      title="Transfer to a faculty member"><i class="bi bi-arrow-left-right"></i></button>

              <form method="post" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="exam_id" value="<?= $id ?>">
                <input type="hidden" name="action" value="toggle">
                <button class="btn btn-sm btn-outline-warning" title="Show / hide">
                  <i class="bi bi-eye<?= (int)$p['is_active'] ? '-slash' : '' ?>"></i>
                </button>
              </form>

              <form method="post" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="exam_id" value="<?= $id ?>">
                <input type="hidden" name="action" value="delete">
                <button class="btn btn-sm btn-outline-danger"
                        data-confirm="Delete this paper? Its questions, attempts and results are removed permanently.">
                  <i class="bi bi-trash"></i>
                </button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$papers): ?>
          <tr><td colspan="9" class="text-center text-muted-2 py-5">
            No question papers match this filter.
          </td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ------------------------ Transfer dialogs ------------------------ -->
<?php foreach ($papers as $p): $id = (int)$p['id']; ?>
  <div class="modal fade" id="transfer<?= $id ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <form method="post" class="modal-content">
        <?= csrf_field() ?>
        <input type="hidden" name="exam_id" value="<?= $id ?>">
        <input type="hidden" name="action" value="transfer">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-arrow-left-right me-2"></i>Transfer paper</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p class="small text-muted-2">
            Hand <strong><?= e($p['title']) ?></strong> to a faculty member. They will be able
            to edit its questions, allot it and mark the written answers.
          </p>
          <label class="form-label" for="tf<?= $id ?>">Faculty member</label>
          <select class="form-select" id="tf<?= $id ?>" name="faculty_id" required>
            <option value="">-- select --</option>
            <?php foreach ($facultyList as $fl): ?>
              <option value="<?= (int)$fl['id'] ?>"
                <?= (int)$p['faculty_id'] === (int)$fl['id'] ? 'selected' : '' ?>>
                <?= e($fl['name']) ?><?= $fl['department'] ? ' - ' . e($fl['department']) : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php if (!$facultyList): ?>
            <div class="alert alert-warning mt-3 mb-0 small">
              There is no approved faculty member to transfer this paper to yet.
            </div>
          <?php endif; ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary" <?= $facultyList ? '' : 'disabled' ?>>Transfer</button>
        </div>
      </form>
    </div>
  </div>
<?php endforeach; ?>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
