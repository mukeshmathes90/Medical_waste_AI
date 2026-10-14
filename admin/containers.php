<?php
/** Admin - register and manage containers (permanent IDs + QR tokens). */
declare(strict_types=1);

$page_title = 'Containers';
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$pdo = db();
$edit = null;
$errors = [];

$categories = $pdo->query('SELECT id, name FROM waste_categories ORDER BY name')->fetchAll();

if (is_post()) {
    require_csrf();
    $action = post_str('action');
    try {
        if ($action === 'create' || $action === 'update') {
            $id       = post_int('id');
            $code     = strtoupper(post_str('container_code'));
            $label    = post_str('label');
            $catId    = post_int('category_id');
            $dept     = post_str('department');
            $location = post_str('location');
            $capacity = post_float('capacity');
            $fill     = post_float('fill_level');
            $status   = post_str('status', 'active');

            if (!preg_match('/^[A-Z0-9-]{3,20}$/', $code)) { $errors[] = 'Container code must be 3-20 uppercase letters/numbers/hyphens.'; }
            if (!in_array($status, ['active','inactive','full','maintenance'], true)) { $errors[] = 'Invalid status.'; }
            if ($capacity !== null && $capacity < 0) { $errors[] = 'Capacity cannot be negative.'; }
            if ($fill !== null && $fill < 0) { $errors[] = 'Fill level cannot be negative.'; }

            $dup = $pdo->prepare('SELECT id FROM containers WHERE container_code = ? AND id <> ?');
            $dup->execute([$code, $id ?? 0]);
            if ($dup->fetch()) { $errors[] = 'That container code already exists.'; }

            if (!$errors) {
                if ($action === 'create') {
                    $token = bin2hex(random_bytes(12));
                    $pdo->prepare('INSERT INTO containers (container_code, label, category_id, department, location, capacity, fill_level, status, qr_token) VALUES (?,?,?,?,?,?,?,?,?)')
                        ->execute([$code, $label ?: null, $catId ?: null, $dept ?: null, $location ?: null, $capacity, $fill, $status, $token]);
                    log_audit('container_created', 'container', (int)$pdo->lastInsertId(), "Created container $code");
                    set_flash('success', "Container '$code' created.");
                } else {
                    $pdo->prepare('UPDATE containers SET container_code=?, label=?, category_id=?, department=?, location=?, capacity=?, fill_level=?, status=?, updated_at=datetime(\'now\') WHERE id=?')
                        ->execute([$code, $label ?: null, $catId ?: null, $dept ?: null, $location ?: null, $capacity, $fill, $status, $id]);
                    log_audit('container_updated', 'container', $id, "Updated container $code");
                    set_flash('success', "Container '$code' updated.");
                }
                redirect(app_base_url() . '/admin/containers.php');
            }
        } elseif ($action === 'delete') {
            $id = post_int('id');
            $used = $pdo->prepare('SELECT COUNT(*) c FROM waste_records WHERE container_id = ?');
            $used->execute([$id]);
            if ((int)$used->fetch()['c'] > 0) {
                $pdo->prepare("UPDATE containers SET status='inactive' WHERE id=?")->execute([$id]);
                set_flash('warning', 'Container has waste records; it was set to inactive instead of deleted.');
            } else {
                $pdo->prepare('DELETE FROM containers WHERE id=?')->execute([$id]);
                log_audit('container_deleted', 'container', $id, 'Deleted container');
                set_flash('success', 'Container deleted.');
            }
            redirect(app_base_url() . '/admin/containers.php');
        }
    } catch (Throwable $e) {
        $errors[] = 'Operation failed: ' . $e->getMessage();
    }
}
foreach ($errors as $er) { set_flash('danger', $er); }

if (get_str('edit')) {
    $s = $pdo->prepare('SELECT * FROM containers WHERE id = ?');
    $s->execute([(int)get_str('edit')]);
    $edit = $s->fetch() ?: null;
}

$search = get_str('q');
$stmt = $pdo->prepare(
    'SELECT c.*, wc.name AS category_name, wc.color,
            (SELECT COUNT(*) FROM rfid_tags t WHERE t.container_id = c.id) AS tag_count
     FROM containers c LEFT JOIN waste_categories wc ON wc.id = c.category_id
     WHERE c.container_code LIKE ? OR c.label LIKE ? OR c.location LIKE ?
     ORDER BY c.container_code'
);
$like = "%$search%"; $stmt->execute([$like, $like, $like]);
$containers = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div class="page-title"><i class="bi bi-box-seam me-2"></i>Containers</div>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#contModal"><i class="bi bi-plus-lg"></i> Add Container</button>
</div>

<form class="row g-2 mb-3" method="get">
  <div class="col-md-4"><input type="text" name="q" class="form-control form-control-sm" placeholder="Search code / label / location" value="<?= e($search) ?>"></div>
  <div class="col-md-2"><button class="btn btn-outline-secondary btn-sm w-100">Search</button></div>
</form>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th>Code</th><th>Label</th><th>Category</th><th>Location</th><th>Fill</th><th>RFID</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php if (!$containers): ?>
        <tr><td colspan="8"><div class="empty-state"><i class="bi bi-box"></i>No containers registered.</div></td></tr>
      <?php else: foreach ($containers as $c): ?>
        <tr>
          <td class="text-mono"><?= e($c['container_code']) ?></td>
          <td><?= e($c['label'] ?? '-') ?></td>
          <td><?php if ($c['category_name']): ?><span class="badge" style="background:<?= e($c['color']) ?>">&nbsp;</span> <?= e($c['category_name']) ?><?php else: ?>-<?php endif; ?></td>
          <td><?= e($c['location'] ?? '-') ?><div class="text-muted small"><?= e($c['department'] ?? '') ?></div></td>
          <td class="small"><?= $c['capacity'] !== null ? number_format((float)$c['fill_level'],1) . ' / ' . number_format((float)$c['capacity'],1) : '-' ?></td>
          <td><?= (int)$c['tag_count'] > 0 ? '<span class="badge bg-info text-dark">' . (int)$c['tag_count'] . '</span>' : '<span class="text-muted small">none</span>' ?></td>
          <td><?= status_badge($c['status']) ?></td>
          <td class="text-end text-nowrap">
            <a class="btn btn-sm btn-outline-secondary" href="<?= e(app_base_url()) ?>/staff/generate_qr.php?container=<?= (int)$c['id'] ?>" title="QR code"><i class="bi bi-qr-code"></i></a>
            <a class="btn btn-sm btn-outline-primary" href="?edit=<?= (int)$c['id'] ?>"><i class="bi bi-pencil"></i></a>
            <form method="post" class="d-inline" data-confirm="Delete container <?= e($c['container_code']) ?>?">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
              <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal fade" id="contModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="<?= $edit ? 'update' : 'create' ?>">
      <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
      <div class="modal-header"><h5 class="modal-title"><?= $edit ? 'Edit container' : 'Register container' ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-6 mb-3"><label class="form-label">Container code</label><input name="container_code" class="form-control" value="<?= e($edit['container_code'] ?? '') ?>" required></div>
          <div class="col-md-6 mb-3"><label class="form-label">Status</label>
            <select name="status" class="form-select">
              <?php foreach (['active','full','maintenance','inactive'] as $s): ?><option value="<?= $s ?>" <?= ($edit['status'] ?? 'active') === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 mb-3"><label class="form-label">Label</label><input name="label" class="form-control" value="<?= e($edit['label'] ?? '') ?>"></div>
          <div class="col-md-6 mb-3"><label class="form-label">Category</label>
            <select name="category_id" class="form-select">
              <option value="">Unassigned</option>
              <?php foreach ($categories as $cat): ?><option value="<?= (int)$cat['id'] ?>" <?= (int)($edit['category_id'] ?? 0) === (int)$cat['id'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6 mb-3"><label class="form-label">Department</label><input name="department" class="form-control" value="<?= e($edit['department'] ?? '') ?>"></div>
          <div class="col-12 mb-3"><label class="form-label">Location</label><input name="location" class="form-control" value="<?= e($edit['location'] ?? '') ?>"></div>
          <div class="col-md-6 mb-3"><label class="form-label">Capacity</label><input type="number" step="0.01" name="capacity" class="form-control" value="<?= e($edit['capacity'] ?? '') ?>"></div>
          <div class="col-md-6 mb-3"><label class="form-label">Current fill level</label><input type="number" step="0.01" name="fill_level" class="form-control" value="<?= e($edit['fill_level'] ?? '0') ?>"></div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save</button></div>
    </form>
  </div>
</div>

<?php $page_scripts = $edit ? '<script>document.addEventListener("DOMContentLoaded",function(){new bootstrap.Modal(document.getElementById("contModal")).show();});</script>' : ''; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
