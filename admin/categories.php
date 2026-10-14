<?php
/** Admin - manage editable waste categories used by the rule engine. */
declare(strict_types=1);

$page_title = 'Waste Categories';
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$pdo = db();
$edit = null;
$errors = [];

if (is_post()) {
    require_csrf();
    $action = post_str('action');
    try {
        if ($action === 'create' || $action === 'update') {
            $id     = post_int('id');
            $name   = post_str('name');
            $code   = strtoupper(post_str('code'));
            $desc   = post_str('description');
            $guide  = post_str('handling_guidance');
            $color  = post_str('color', '#6c757d');
            $ctype  = post_str('container_type');
            $haz    = isset($_POST['is_hazardous']) ? 1 : 0;
            $active = isset($_POST['is_active']) ? 1 : 0;

            if ($name === '') { $errors[] = 'Category name is required.'; }
            if (!preg_match('/^[A-Z0-9]{2,8}$/', $code)) { $errors[] = 'Code must be 2-8 uppercase letters/numbers.'; }
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) { $errors[] = 'Colour must be a hex value like #f0ad4e.'; }

            $dup = $pdo->prepare('SELECT id FROM waste_categories WHERE (name = ? OR code = ?) AND id <> ?');
            $dup->execute([$name, $code, $id ?? 0]);
            if ($dup->fetch()) { $errors[] = 'A category with that name or code already exists.'; }

            if (!$errors) {
                if ($action === 'create') {
                    $pdo->prepare('INSERT INTO waste_categories (name, code, description, handling_guidance, color, container_type, is_hazardous, is_active) VALUES (?,?,?,?,?,?,?,?)')
                        ->execute([$name, $code, $desc, $guide, $color, $ctype ?: null, $haz, $active]);
                    log_audit('category_created', 'waste_category', (int)$pdo->lastInsertId(), "Created category $name");
                    set_flash('success', "Category '$name' created.");
                } else {
                    $pdo->prepare('UPDATE waste_categories SET name=?, code=?, description=?, handling_guidance=?, color=?, container_type=?, is_hazardous=?, is_active=? WHERE id=?')
                        ->execute([$name, $code, $desc, $guide, $color, $ctype ?: null, $haz, $active, $id]);
                    log_audit('category_updated', 'waste_category', $id, "Updated category $name");
                    set_flash('success', "Category '$name' updated.");
                }
                redirect(app_base_url() . '/admin/categories.php');
            }
        } elseif ($action === 'delete') {
            $id = post_int('id');
            $used = $pdo->prepare('SELECT COUNT(*) c FROM waste_records WHERE category_id = ?');
            $used->execute([$id]);
            if ((int)$used->fetch()['c'] > 0) {
                // Soft-disable instead of breaking referential integrity.
                $pdo->prepare('UPDATE waste_categories SET is_active = 0 WHERE id = ?')->execute([$id]);
                set_flash('warning', 'Category is in use; it was deactivated instead of deleted.');
            } else {
                $pdo->prepare('DELETE FROM waste_categories WHERE id = ?')->execute([$id]);
                log_audit('category_deleted', 'waste_category', $id, 'Deleted category');
                set_flash('success', 'Category deleted.');
            }
            redirect(app_base_url() . '/admin/categories.php');
        }
    } catch (Throwable $e) {
        $errors[] = 'Operation failed: ' . $e->getMessage();
    }
}
foreach ($errors as $er) { set_flash('danger', $er); }

if (get_str('edit')) {
    $s = $pdo->prepare('SELECT * FROM waste_categories WHERE id = ?');
    $s->execute([(int)get_str('edit')]);
    $edit = $s->fetch() ?: null;
}

$cats = $pdo->query(
    'SELECT wc.*, (SELECT COUNT(*) FROM waste_records w WHERE w.category_id = wc.id) AS record_count,
            (SELECT COUNT(*) FROM containers c WHERE c.category_id = wc.id) AS container_count
     FROM waste_categories wc ORDER BY wc.name'
)->fetchAll();

$containerTypes = ['yellow' => 'Yellow bag/bin', 'sharps' => 'Sharps container', 'red' => 'Red bin', 'general' => 'General (black/green)', 'chemical' => 'Chemical container'];

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div class="page-title"><i class="bi bi-tags me-2"></i>Waste Categories</div>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#catModal"><i class="bi bi-plus-lg"></i> Add Category</button>
</div>

<div class="alert alert-info small">
  <i class="bi bi-info-circle me-1"></i> These categories drive the <strong>rule-based classification</strong> engine.
  Container type and handling guidance are used to validate entries and prevent mixing incompatible waste streams.
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th>Category</th><th>Code</th><th>Container type</th><th>Hazardous</th><th>Records</th><th>Containers</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($cats as $c): ?>
        <tr>
          <td><span class="badge" style="background:<?= e($c['color']) ?>">&nbsp;</span> <?= e($c['name']) ?></td>
          <td class="text-mono"><?= e($c['code']) ?></td>
          <td><?= e($containerTypes[$c['container_type']] ?? ($c['container_type'] ?: '-')) ?></td>
          <td><?= (int)$c['is_hazardous'] === 1 ? '<span class="badge bg-danger">Yes</span>' : '<span class="badge bg-secondary">No</span>' ?></td>
          <td><?= (int)$c['record_count'] ?></td>
          <td><?= (int)$c['container_count'] ?></td>
          <td><?= (int)$c['is_active'] === 1 ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' ?></td>
          <td class="text-end text-nowrap">
            <a class="btn btn-sm btn-outline-primary" href="?edit=<?= (int)$c['id'] ?>"><i class="bi bi-pencil"></i></a>
            <form method="post" class="d-inline" data-confirm="Delete category '<?= e($c['name']) ?>'?">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
              <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal fade" id="catModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <form method="post" class="modal-content">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="<?= $edit ? 'update' : 'create' ?>">
      <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
      <div class="modal-header"><h5 class="modal-title"><?= $edit ? 'Edit category' : 'Add category' ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-8 mb-3"><label class="form-label">Name</label><input name="name" class="form-control" value="<?= e($edit['name'] ?? '') ?>" required></div>
          <div class="col-md-4 mb-3"><label class="form-label">Code</label><input name="code" class="form-control" value="<?= e($edit['code'] ?? '') ?>" required></div>
          <div class="col-md-6 mb-3"><label class="form-label">Container type</label>
            <select name="container_type" class="form-select">
              <option value="">Not specified</option>
              <?php foreach ($containerTypes as $k => $v): ?><option value="<?= $k ?>" <?= ($edit['container_type'] ?? '') === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3 mb-3"><label class="form-label">Colour</label><input type="color" name="color" class="form-control form-control-color" value="<?= e($edit['color'] ?? '#6c757d') ?>"></div>
          <div class="col-md-3 mb-3 d-flex align-items-end"><div class="form-check me-3"><input class="form-check-input" type="checkbox" name="is_hazardous" id="hazChk" <?= (int)($edit['is_hazardous'] ?? 0) === 1 ? 'checked' : '' ?>><label class="form-check-label" for="hazChk">Hazardous</label></div></div>
          <div class="col-12 mb-3"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="2"><?= e($edit['description'] ?? '') ?></textarea></div>
          <div class="col-12 mb-3"><label class="form-label">Handling guidance</label><textarea name="handling_guidance" class="form-control" rows="2"><?= e($edit['handling_guidance'] ?? '') ?></textarea></div>
        </div>
        <div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" id="catActive" <?= (!$edit || (int)$edit['is_active'] === 1) ? 'checked' : '' ?>><label class="form-check-label" for="catActive">Active</label></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save</button></div>
    </form>
  </div>
</div>

<?php $page_scripts = $edit ? '<script>document.addEventListener("DOMContentLoaded",function(){new bootstrap.Modal(document.getElementById("catModal")).show();});</script>' : ''; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
