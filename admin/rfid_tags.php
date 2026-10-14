<?php
/** Admin - register RFID tag UIDs and map them to containers. */
declare(strict_types=1);

$page_title = 'RFID Tags';
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$pdo = db();
$edit = null;
$errors = [];

$containers = $pdo->query('SELECT id, container_code, label FROM containers ORDER BY container_code')->fetchAll();
$devices    = $pdo->query('SELECT id, device_code, name FROM devices ORDER BY device_code')->fetchAll();

if (is_post()) {
    require_csrf();
    $action = post_str('action');
    try {
        if ($action === 'create' || $action === 'update') {
            $id        = post_int('id');
            $uid       = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', post_str('tag_uid')));
            $container = post_int('container_id');
            $device    = post_int('device_id');
            $label     = post_str('label');
            $notes     = post_str('notes');
            $active    = isset($_POST['is_active']) ? 1 : 0;

            if ($uid === '' || strlen($uid) < 2) { $errors[] = 'A valid RFID UID is required (hex characters).'; }
            if (!$container) { $errors[] = 'Select the container this tag belongs to.'; }
            $dup = $pdo->prepare('SELECT id FROM rfid_tags WHERE tag_uid = ? AND id <> ?');
            $dup->execute([$uid, $id ?? 0]);
            if ($dup->fetch()) { $errors[] = 'That RFID UID is already registered.'; }

            if (!$errors) {
                if ($action === 'create') {
                    $pdo->prepare('INSERT INTO rfid_tags (tag_uid, container_id, device_id, label, notes, is_active) VALUES (?,?,?,?,?,?)')
                        ->execute([$uid, $container, $device ?: null, $label ?: null, $notes ?: null, $active]);
                    log_audit('rfid_tag_created', 'rfid_tag', (int)$pdo->lastInsertId(), "Registered tag $uid");
                    set_flash('success', "RFID tag $uid registered.");
                } else {
                    $pdo->prepare('UPDATE rfid_tags SET tag_uid=?, container_id=?, device_id=?, label=?, notes=?, is_active=? WHERE id=?')
                        ->execute([$uid, $container, $device ?: null, $label ?: null, $notes ?: null, $active, $id]);
                    log_audit('rfid_tag_updated', 'rfid_tag', $id, "Updated tag $uid");
                    set_flash('success', "RFID tag $uid updated.");
                }
                redirect(app_base_url() . '/admin/rfid_tags.php');
            }
        } elseif ($action === 'delete') {
            $id = post_int('id');
            $pdo->prepare('DELETE FROM rfid_tags WHERE id=?')->execute([$id]);
            log_audit('rfid_tag_deleted', 'rfid_tag', $id, 'Deleted RFID tag');
            set_flash('success', 'RFID tag deleted.');
            redirect(app_base_url() . '/admin/rfid_tags.php');
        }
    } catch (Throwable $e) {
        $errors[] = 'Operation failed: ' . $e->getMessage();
    }
}
foreach ($errors as $er) { set_flash('danger', $er); }

if (get_str('edit')) {
    $s = $pdo->prepare('SELECT * FROM rfid_tags WHERE id = ?');
    $s->execute([(int)get_str('edit')]);
    $edit = $s->fetch() ?: null;
}

$tags = $pdo->query(
    'SELECT t.*, c.container_code, d.device_code
     FROM rfid_tags t
     JOIN containers c ON c.id = t.container_id
     LEFT JOIN devices d ON d.id = t.device_id
     ORDER BY c.container_code'
)->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div class="page-title"><i class="bi bi-wifi me-2"></i>RFID Tags</div>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#tagModal"><i class="bi bi-plus-lg"></i> Register Tag</button>
</div>

<div class="alert alert-warning small">
  <i class="bi bi-exclamation-triangle me-1"></i>
  An RFID UID is <strong>not cryptographic proof</strong> - UID-only tags can be cloned. The server always validates the UID
  against this registry and the request status before approving a collection.
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th>UID</th><th>Container</th><th>Bound device</th><th>Label</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php if (!$tags): ?>
        <tr><td colspan="6"><div class="empty-state"><i class="bi bi-wifi-off"></i>No RFID tags registered.</div></td></tr>
      <?php else: foreach ($tags as $t): ?>
        <tr>
          <td class="text-mono"><?= e($t['tag_uid']) ?></td>
          <td class="text-mono"><?= e($t['container_code']) ?></td>
          <td><?= e($t['device_code'] ?? 'any') ?></td>
          <td><?= e($t['label'] ?? '-') ?></td>
          <td><?= (int)$t['is_active'] === 1 ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' ?></td>
          <td class="text-end text-nowrap">
            <a class="btn btn-sm btn-outline-primary" href="?edit=<?= (int)$t['id'] ?>"><i class="bi bi-pencil"></i></a>
            <form method="post" class="d-inline" data-confirm="Delete tag <?= e($t['tag_uid']) ?>?">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
              <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal fade" id="tagModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="<?= $edit ? 'update' : 'create' ?>">
      <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
      <div class="modal-header"><h5 class="modal-title"><?= $edit ? 'Edit tag' : 'Register RFID tag' ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="mb-3"><label class="form-label">Tag UID (hex)</label><input name="tag_uid" class="form-control text-mono" value="<?= e($edit['tag_uid'] ?? '') ?>" placeholder="e.g. A1B2C304" required><div class="form-text">Read the UID shown on the ESP32 serial monitor when the tag is scanned.</div></div>
        <div class="mb-3"><label class="form-label">Container</label>
          <select name="container_id" class="form-select" required>
            <option value="">Select container</option>
            <?php foreach ($containers as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)($edit['container_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['container_code']) ?> - <?= e($c['label'] ?? '') ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3"><label class="form-label">Bound device (optional)</label>
          <select name="device_id" class="form-select">
            <option value="">Any device</option>
            <?php foreach ($devices as $d): ?><option value="<?= (int)$d['id'] ?>" <?= (int)($edit['device_id'] ?? 0) === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['device_code']) ?> - <?= e($d['name'] ?? '') ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3"><label class="form-label">Label</label><input name="label" class="form-control" value="<?= e($edit['label'] ?? '') ?>"></div>
        <div class="mb-3"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2"><?= e($edit['notes'] ?? '') ?></textarea></div>
        <div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" id="tagActive" <?= (!$edit || (int)$edit['is_active'] === 1) ? 'checked' : '' ?>><label class="form-check-label" for="tagActive">Active</label></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save</button></div>
    </form>
  </div>
</div>

<?php $page_scripts = $edit ? '<script>document.addEventListener("DOMContentLoaded",function(){new bootstrap.Modal(document.getElementById("tagModal")).show();});</script>' : ''; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
