<?php
/** Staff - register a new medical-waste entry and run rule-based classification. */
declare(strict_types=1);

$page_title = 'New Waste Entry';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/classification.php';
require_role('staff', 'admin');

$pdo = db();
$user = current_user();
$errors = [];
$preview = null;

$categories = $pdo->query('SELECT * FROM waste_categories WHERE is_active = 1 ORDER BY name')->fetchAll();
$containers = $pdo->query(
    "SELECT c.*, wc.container_type AS category_container_type, wc.name AS category_name
     FROM containers c LEFT JOIN waste_categories wc ON wc.id = c.category_id
     WHERE c.status <> 'inactive' ORDER BY c.container_code"
)->fetchAll();

$form = [
    'category_id' => '', 'container_id' => '', 'quantity' => '', 'unit' => 'kg',
    'department' => $user['department'] ?? '', 'location' => '', 'priority' => 'normal', 'notes' => '',
];

if (is_post()) {
    require_csrf();
    foreach ($form as $k => $_) { $form[$k] = $_POST[$k] ?? $form[$k]; }

    $categoryId  = post_int('category_id');
    $containerId = post_int('container_id');
    $quantity    = post_float('quantity');
    $unit        = post_str('unit', 'kg');
    $department  = post_str('department');
    $location    = post_str('location');
    $priority    = post_str('priority', 'normal');
    $notes       = post_str('notes');

    if (!$categoryId) { $errors[] = 'Select a waste category.'; }
    if ($quantity === null || $quantity <= 0) { $errors[] = 'Enter a quantity greater than zero.'; }
    if (!in_array($priority, ['low','normal','high','urgent'], true)) { $priority = 'normal'; }

    $category  = get_category($categoryId);
    $container = get_container($containerId);
    if ($categoryId && !$category) { $errors[] = 'Selected category no longer exists.'; }

    if (!$errors && $category) {
        $result = classify_waste(
            ['quantity' => $quantity, 'unit' => $unit, 'priority' => $priority, 'department' => $department, 'location' => $location],
            $category,
            $container
        );
        $preview = $result;
        if (!$result['valid']) {
            $errors = array_merge($errors, $result['errors']);
        } else {
            try {
                db_transaction(function (PDO $pdo) use ($category, $containerId, $quantity, $unit, $department, $location, $priority, $notes, $user) {
                    $code = generate_code('waste_records', 'record_code', 'WR', 4);
                    $pdo->prepare(
                        'INSERT INTO waste_records (record_code, category_id, container_id, quantity, unit, department, location, priority, status, notes, created_by)
                         VALUES (?,?,?,?,?,?,?,?, \'pending\', ?, ?)'
                    )->execute([$code, $category['id'], $containerId ?: null, $quantity, $unit, $department ?: null, $location ?: null, $priority, $notes ?: null, $user['id']]);
                    $wid = (int)$pdo->lastInsertId();

                    // Reflect the added quantity in the container fill level (best effort).
                    if ($containerId) {
                        $pdo->prepare('UPDATE containers SET fill_level = COALESCE(fill_level,0) + ?, updated_at = datetime(\'now\') WHERE id = ?')
                            ->execute([$quantity, $containerId]);
                    }
                    log_audit('waste_created', 'waste_record', $wid, "Created $code ({$category['name']})");
                });
                set_flash('success', 'Waste record saved successfully.');
                redirect(app_base_url() . '/staff/waste_records.php');
            } catch (Throwable $e) {
                $errors[] = 'Could not save: ' . $e->getMessage();
            }
        }
    }
}

$units = ['kg' => 'Kilogram (kg)', 'g' => 'Gram (g)', 'litre' => 'Litre', 'count' => 'Count', 'pieces' => 'Pieces', 'units' => 'Units', 'bag' => 'Bag'];

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div class="page-title"><i class="bi bi-plus-circle me-2"></i>New Waste Entry</div>
  <a href="<?= e(app_base_url()) ?>/staff/waste_records.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-list-ul"></i> All Records</a>
</div>

<?php if ($errors): ?>
  <div class="alert alert-danger"><strong><i class="bi bi-x-octagon me-1"></i>Please fix the following:</strong>
    <ul class="mb-0 ps-3"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card">
      <div class="card-body">
        <form method="post" id="wasteForm">
          <?= csrf_field() ?>
          <div class="row g-3">
            <div class="col-md-6 mb-3">
              <label class="form-label">Waste category <span class="text-danger">*</span></label>
              <select name="category_id" id="categoryId" class="form-select" required>
                <option value="">Select category</option>
                <?php foreach ($categories as $c): ?>
                  <option value="<?= (int)$c['id'] ?>" data-type="<?= e($c['container_type'] ?? '') ?>" data-hazard="<?= (int)$c['is_hazardous'] ?>"
                    <?= (string)$form['category_id'] === (string)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Container</label>
              <select name="container_id" id="containerId" class="form-select">
                <option value="">No container</option>
                <?php foreach ($containers as $c): ?>
                  <option value="<?= (int)$c['id'] ?>" data-category="<?= (int)($c['category_id'] ?? 0) ?>" data-capacity="<?= e($c['capacity'] ?? '') ?>" data-fill="<?= e($c['fill_level'] ?? '0') ?>"
                    <?= (string)$form['container_id'] === (string)$c['id'] ? 'selected' : '' ?>><?= e($c['container_code']) ?> &middot; <?= e($c['label'] ?? '') ?> (<?= e($c['category_name'] ?? 'unassigned') ?>)</option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">Containers are filtered to the selected category where possible.</div>
            </div>
            <div class="col-md-4 mb-3">
              <label class="form-label">Quantity <span class="text-danger">*</span></label>
              <input type="number" step="0.01" min="0.01" name="quantity" id="quantity" class="form-control" value="<?= e($form['quantity']) ?>" required>
            </div>
            <div class="col-md-4 mb-3">
              <label class="form-label">Unit</label>
              <select name="unit" id="unit" class="form-select">
                <?php foreach ($units as $k => $v): ?><option value="<?= e($k) ?>" <?= $form['unit'] === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4 mb-3">
              <label class="form-label">Priority</label>
              <select name="priority" id="priority" class="form-select">
                <?php foreach (['low','normal','high','urgent'] as $p): ?><option value="<?= $p ?>" <?= $form['priority'] === $p ? 'selected' : '' ?>><?= ucfirst($p) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6 mb-3"><label class="form-label">Department</label><input name="department" class="form-control" value="<?= e($form['department']) ?>"></div>
            <div class="col-md-6 mb-3"><label class="form-label">Location</label><input name="location" class="form-control" value="<?= e($form['location']) ?>"></div>
            <div class="col-12 mb-3"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2" placeholder="Optional remarks"><?= e($form['notes']) ?></textarea></div>
          </div>
          <button class="btn btn-primary" type="submit"><i class="bi bi-save me-1"></i>Validate &amp; Save</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-clipboard-check me-1"></i>Classification &amp; segregation guidance</div>
      <div class="card-body" id="previewPanel">
        <p class="text-muted small mb-0">Select a category, container and quantity to see the rule-based validation and handling guidance.</p>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
    const form = document.getElementById('wasteForm');
    const fields = ['categoryId', 'containerId', 'quantity', 'unit', 'priority', 'unit'];
    const panel = document.getElementById('previewPanel');

    function render(data) {
        let html = '';
        if (data.guidance) {
            html += '<div class="alert alert-info small mb-2"><strong>Handling guidance</strong><br>' + escapeHtml(data.guidance) + '</div>';
        }
        (data.errors || []).forEach(function (e) {
            html += '<div class="alert alert-danger py-2 small mb-2"><i class="bi bi-x-circle me-1"></i>' + escapeHtml(e) + '</div>';
        });
        (data.warnings || []).forEach(function (w) {
            html += '<div class="alert alert-warning py-2 small mb-2"><i class="bi bi-exclamation-triangle me-1"></i>' + escapeHtml(w) + '</div>';
        });
        if (!data.errors || !data.errors.length) {
            html += '<div class="alert alert-success py-2 small mb-0"><i class="bi bi-check-circle me-1"></i>No blocking rule violations.</div>';
        }
        if (data.priority_suggestion) {
            html += '<div class="small text-muted mt-2">Suggested priority: <strong>' + escapeHtml(data.priority_suggestion) + '</strong></div>';
        }
        panel.innerHTML = html;
    }
    function escapeHtml(s){return String(s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}

    function postPreview() {
        const fd = new FormData();
        fd.append('category_id', document.getElementById('categoryId').value);
        fd.append('container_id', document.getElementById('containerId').value);
        fd.append('quantity', document.getElementById('quantity').value);
        fd.append('unit', document.getElementById('unit').value);
        fetch('<?= e(app_base_url()) ?>/api/classify.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json()).then(render).catch(() => {});
    }

    // Container filtering by category.
    function filterContainers() {
        const cat = document.getElementById('categoryId');
        const catId = cat.value;
        const select = document.getElementById('containerId');
        Array.from(select.options).forEach(function (opt) {
            if (!opt.value) return;
            const oc = opt.getAttribute('data-category');
            const match = !catId || oc === catId || oc === '0';
            opt.hidden = !match;
            if (!match && opt.selected) select.value = '';
        });
    }

    ['categoryId', 'containerId', 'quantity', 'unit', 'priority'].forEach(function (id) {
        const el = document.getElementById(id);
        if (el) el.addEventListener('change', function () { filterContainers(); postPreview(); });
    });
    const q = document.getElementById('quantity');
    if (q) q.addEventListener('input', debounce(postPreview, 400));
    filterContainers();
})();
function debounce(fn, ms){let t;return function(){clearTimeout(t);t=setTimeout(fn,ms);};}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
