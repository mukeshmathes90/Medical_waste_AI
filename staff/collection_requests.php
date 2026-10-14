<?php
/** Staff - submit and track collection requests. */
declare(strict_types=1);

$page_title = 'Collection Requests';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/collection.php';
require_role('staff', 'admin');

$pdo = db();
$user = current_user();

if (is_post() && post_str('action') === 'cancel') {
    require_csrf();
    $id = post_int('id');
    $req = get_request($id);
    if (!$req) {
        set_flash('danger', 'Request not found.');
    } elseif ($user['role'] === 'staff' && (int)$req['requested_by'] !== (int)$user['id']) {
        set_flash('danger', 'You can only cancel your own requests.');
    } elseif (!in_array($req['status'], ['pending','approved'], true)) {
        set_flash('danger', 'Only pending or approved requests can be cancelled.');
    } else {
        $res = update_request_status($id, 'cancelled', (int)$user['id'], post_str('reason', 'Cancelled by staff'));
        set_flash($res['ok'] ? 'success' : 'danger', $res['ok'] ? 'Request cancelled.' : $res['error']);
    }
    redirect(app_base_url() . '/staff/collection_requests.php');
}

$status = get_str('status');
$where = []; $args = [];
if ($user['role'] === 'staff') { $where[] = 'r.requested_by = ?'; $args[] = $user['id']; }
if ($status !== '') { $where[] = 'r.status = ?'; $args[] = $status; }
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$requests = $pdo->prepare(
    'SELECT r.*, c.container_code, a.full_name AS collector, wc.name AS category_name
     FROM collection_requests r
     LEFT JOIN containers c ON c.id = r.container_id
     LEFT JOIN users a ON a.id = r.assigned_to
     LEFT JOIN waste_records w ON w.id = r.waste_record_id
     LEFT JOIN waste_categories wc ON wc.id = w.category_id'
    . $whereSql . ' ORDER BY r.id DESC LIMIT 100'
);
$requests->execute($args);
$requests = $requests->fetchAll();

$viewId = get_str('id');
$view = $viewId ? get_request((int)$viewId) : null;
$history = [];
if ($view) {
    $hs = $pdo->prepare('SELECT h.*, u.full_name FROM collection_status_history h LEFT JOIN users u ON u.id = h.changed_by WHERE h.request_id = ? ORDER BY h.id');
    $hs->execute([(int)$viewId]);
    $history = $hs->fetchAll();
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-title mb-3"><i class="bi bi-truck me-2"></i>Collection Requests</div>

<?php if ($view): ?>
  <div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
      <span>Request <?= e($view['request_no']) ?></span>
      <div><?= status_badge($view['status']) ?> <a href="<?= e(app_base_url()) ?>/staff/collection_requests.php" class="btn btn-sm btn-outline-secondary ms-2">Close</a></div>
    </div>
    <div class="card-body">
      <div class="row">
        <div class="col-md-6">
          <dl class="row mb-0">
            <dt class="col-5">Container</dt><dd class="col-7 text-mono"><?= e($view['container_code'] ?? '-') ?></dd>
            <dt class="col-5">Category</dt><dd class="col-7"><?= e($view['category_name'] ?? '-') ?></dd>
            <dt class="col-5">Quantity</dt><dd class="col-7"><?= e($view['quantity']) ?> <?= e($view['unit']) ?></dd>
            <dt class="col-5">Location</dt><dd class="col-7"><?= e($view['location'] ?? '-') ?></dd>
          </dl>
        </div>
        <div class="col-md-6">
          <dl class="row mb-0">
            <dt class="col-5">Collector</dt><dd class="col-7"><?= e($view['collector_name'] ?? 'Unassigned') ?></dd>
            <dt class="col-5">Priority</dt><dd class="col-7"><?= priority_badge($view['priority']) ?></dd>
            <dt class="col-5">QR verified</dt><dd class="col-7"><?= (int)$view['qr_verified'] ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>' ?></dd>
            <dt class="col-5">RFID verified</dt><dd class="col-7"><?= (int)$view['rfid_verified'] ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>' ?></dd>
          </dl>
        </div>
      </div>
      <hr>
      <h6>Status history</h6>
      <ul class="list-group">
        <?php foreach ($history as $h): ?>
          <li class="list-group-item d-flex justify-content-between">
            <span><?= $h['old_status'] ? e($h['old_status']) . ' &rarr; ' : '' ?><strong><?= e($h['new_status']) ?></strong>
              <?= $h['remarks'] ? '<span class="text-muted"> - ' . e($h['remarks']) . '</span>' : '' ?></span>
            <span class="small text-muted"><?= e($h['full_name'] ?? 'system') ?> &middot; <?= e(format_dt($h['created_at'])) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
<?php endif; ?>

<div class="card">
  <div class="card-body pb-0">
    <form class="row g-2 mb-3" method="get">
      <div class="col-md-3">
        <select name="status" class="form-select form-select-sm">
          <option value="">All statuses</option>
          <?php foreach (['pending','approved','assigned','in_progress','collected','completed','cancelled','rejected'] as $s): ?>
            <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucwords(str_replace('_',' ',$s)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2"><button class="btn btn-outline-secondary btn-sm w-100">Filter</button></div>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th>Request</th><th>Container</th><th>Qty</th><th>Priority</th><th>Collector</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php if (!$requests): ?>
        <tr><td colspan="7"><div class="empty-state"><i class="bi bi-truck"></i>No collection requests yet. Create one from a waste record.</div></td></tr>
      <?php else: foreach ($requests as $r): ?>
        <tr>
          <td class="text-mono"><?= e($r['request_no']) ?><div class="text-muted small"><?= e(format_dt($r['created_at'])) ?></div></td>
          <td class="text-mono"><?= e($r['container_code'] ?? '-') ?></td>
          <td><?= e($r['quantity']) ?> <?= e($r['unit']) ?></td>
          <td><?= priority_badge($r['priority']) ?></td>
          <td><?= e($r['collector'] ?? '-') ?></td>
          <td><?= status_badge($r['status']) ?></td>
          <td class="text-end text-nowrap">
            <a class="btn btn-sm btn-outline-secondary" href="?id=<?= (int)$r['id'] ?>"><i class="bi bi-eye"></i></a>
            <?php if (in_array($r['status'], ['pending','approved'], true)): ?>
              <form method="post" class="d-inline" data-confirm="Cancel request <?= e($r['request_no']) ?>?">
                <?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-circle"></i></button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
