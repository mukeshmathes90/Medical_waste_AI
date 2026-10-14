<?php
/** Admin - review, assign and progress all collection requests. */
declare(strict_types=1);

$page_title = 'Collection Requests';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/collection.php';
require_role('admin');

$pdo = db();
$uid = (int)current_user()['id'];

if (is_post()) {
    require_csrf();
    $action = post_str('action');
    $id = post_int('id');
    $req = get_request($id);

    if (!$req) {
        set_flash('danger', 'Request not found.');
    } else {
        switch ($action) {
            case 'approve':
                $res = update_request_status($id, 'approved', $uid, post_str('remarks', 'Approved by admin'));
                break;
            case 'reject':
                $res = update_request_status($id, 'rejected', $uid, post_str('remarks', 'Rejected'));
                break;
            case 'cancel':
                $res = update_request_status($id, 'cancelled', $uid, post_str('remarks', 'Cancelled by admin'));
                break;
            case 'assign':
                $res = update_request_status($id, 'assigned', $uid, 'Assigned to collector', ['assigned_to' => (int)post_int('assigned_to')]);
                break;
            case 'complete':
                $res = update_request_status($id, 'completed', $uid, 'Completed by admin', ['completion_notes' => post_str('completion_notes')]);
                break;
            default:
                $res = ['ok' => false, 'error' => 'Unknown action.'];
        }
        set_flash($res['ok'] ? 'success' : 'danger', $res['ok'] ? 'Request updated.' : $res['error']);
    }
    redirect(app_base_url() . '/admin/requests.php' . (get_str('id') ? '?id=' . (int)get_str('id') : ''));
}

$collectors = $pdo->query("SELECT id, full_name, department FROM users WHERE role='collector' AND is_active=1 ORDER BY full_name")->fetchAll();

$status = get_str('status');
$search = get_str('q');
$where = []; $args = [];
if ($status !== '') { $where[] = 'r.status = ?'; $args[] = $status; }
if ($search !== '') { $where[] = '(r.request_no LIKE ? OR c.container_code LIKE ?)'; array_push($args, "%$search%", "%$search%"); }
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$requests = $pdo->prepare(
    'SELECT r.*, c.container_code, a.full_name AS collector, u.full_name AS requester, wc.name AS category_name
     FROM collection_requests r
     LEFT JOIN containers c ON c.id = r.container_id
     LEFT JOIN users a ON a.id = r.assigned_to
     LEFT JOIN users u ON u.id = r.requested_by
     LEFT JOIN waste_records w ON w.id = r.waste_record_id
     LEFT JOIN waste_categories wc ON wc.id = w.category_id'
    . $whereSql . ' ORDER BY r.id DESC LIMIT 200'
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
      <div><?= status_badge($view['status']) ?><a class="btn btn-sm btn-outline-secondary ms-2" href="<?= e(app_base_url()) ?>/admin/requests.php">Close</a></div>
    </div>
    <div class="card-body">
      <div class="row mb-3">
        <div class="col-md-6"><dl class="row mb-0 small">
          <dt class="col-5">Container</dt><dd class="col-7 text-mono"><?= e($view['container_code'] ?? '-') ?></dd>
          <dt class="col-5">Category</dt><dd class="col-7"><?= e($view['category_name'] ?? '-') ?></dd>
          <dt class="col-5">Quantity</dt><dd class="col-7"><?= e($view['quantity']) ?> <?= e($view['unit']) ?></dd>
          <dt class="col-5">Requester</dt><dd class="col-7"><?= e($view['requester_name'] ?? '-') ?></dd>
        </dl></div>
        <div class="col-md-6"><dl class="row mb-0 small">
          <dt class="col-5">Collector</dt><dd class="col-7"><?= e($view['collector_name'] ?? 'Unassigned') ?></dd>
          <dt class="col-5">QR / RFID</dt><dd class="col-7"><?= (int)$view['qr_verified'] ? '<span class="badge bg-success">QR ok</span>' : '<span class="badge bg-secondary">QR no</span>' ?> <?= (int)$view['rfid_verified'] ? '<span class="badge bg-success">RFID ok</span>' : '<span class="badge bg-secondary">RFID no</span>' ?></dd>
          <dt class="col-5">Created</dt><dd class="col-7"><?= e(format_dt($view['created_at'])) ?></dd>
          <dt class="col-5">Collected</dt><dd class="col-7"><?= e(format_dt($view['collected_at'])) ?></dd>
        </dl></div>
      </div>

      <div class="d-flex flex-wrap gap-2 mb-3">
        <?php if ($view['status'] === 'pending'): ?>
          <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?= (int)$view['id'] ?>"><button class="btn btn-sm btn-info">Approve</button></form>
          <form method="post" class="d-inline" data-confirm="Reject this request?"><?= csrf_field() ?><input type="hidden" name="action" value="reject"><input type="hidden" name="id" value="<?= (int)$view['id'] ?>"><input type="hidden" name="remarks" value="Rejected by admin"><button class="btn btn-sm btn-danger">Reject</button></form>
        <?php endif; ?>
        <?php if (in_array($view['status'], ['approved','assigned','in_progress'], true)): ?>
          <form method="post" class="d-flex gap-2"><?= csrf_field() ?><input type="hidden" name="action" value="assign"><input type="hidden" name="id" value="<?= (int)$view['id'] ?>">
            <select name="assigned_to" class="form-select form-select-sm" style="width:auto">
              <option value="">Assign to collector…</option>
              <?php foreach ($collectors as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)$view['assigned_to'] === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['full_name']) ?></option><?php endforeach; ?>
            </select>
            <button class="btn btn-sm btn-primary">Assign</button>
          </form>
        <?php endif; ?>
        <?php if ($view['status'] === 'collected'): ?>
          <form method="post" class="d-flex gap-2"><?= csrf_field() ?><input type="hidden" name="action" value="complete"><input type="hidden" name="id" value="<?= (int)$view['id'] ?>">
            <input name="completion_notes" class="form-control form-control-sm" placeholder="Completion notes" style="width:auto">
            <button class="btn btn-sm btn-success">Complete</button></form>
        <?php endif; ?>
        <?php if (in_array($view['status'], ['pending','approved','assigned','in_progress'], true)): ?>
          <form method="post" class="d-inline" data-confirm="Cancel this request?"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int)$view['id'] ?>"><input type="hidden" name="remarks" value="Cancelled by admin"><button class="btn btn-sm btn-outline-dark">Cancel</button></form>
        <?php endif; ?>
      </div>

      <h6>Status history</h6>
      <ul class="list-group">
        <?php foreach ($history as $h): ?>
          <li class="list-group-item d-flex justify-content-between">
            <span><?= $h['old_status'] ? e($h['old_status']) . ' &rarr; ' : '' ?><strong><?= e($h['new_status']) ?></strong><?= $h['remarks'] ? ' <span class="text-muted">- ' . e($h['remarks']) . '</span>' : '' ?></span>
            <span class="small text-muted"><?= e($h['full_name'] ?? 'system') ?> &middot; <?= e(format_dt($h['created_at'])) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
<?php endif; ?>

<form class="row g-2 mb-3" method="get">
  <div class="col-md-3"><input type="text" name="q" class="form-control form-control-sm" placeholder="Search request / container" value="<?= e($search) ?>"></div>
  <div class="col-md-3">
    <select name="status" class="form-select form-select-sm">
      <option value="">All statuses</option>
      <?php foreach (['pending','approved','assigned','in_progress','collected','completed','cancelled','rejected'] as $s): ?>
        <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucwords(str_replace('_', ' ', $s)) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-2"><button class="btn btn-outline-secondary btn-sm w-100">Filter</button></div>
</form>

<div class="card"><div class="table-responsive">
  <table class="table table-hover align-middle mb-0">
    <thead><tr><th>Request</th><th>Container</th><th>Requester</th><th>Collector</th><th>Priority</th><th>Status</th><th class="text-end">View</th></tr></thead>
    <tbody>
    <?php if (!$requests): ?>
      <tr><td colspan="7"><div class="empty-state"><i class="bi bi-truck"></i>No requests found.</div></td></tr>
    <?php else: foreach ($requests as $r): ?>
      <tr>
        <td class="text-mono"><?= e($r['request_no']) ?><div class="text-muted small"><?= e(format_dt($r['created_at'], 'd M H:i')) ?></div></td>
        <td class="text-mono"><?= e($r['container_code'] ?? '-') ?></td>
        <td><?= e($r['requester'] ?? '-') ?></td>
        <td><?= e($r['collector'] ?? '-') ?></td>
        <td><?= priority_badge($r['priority']) ?></td>
        <td><?= status_badge($r['status']) ?></td>
        <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="?id=<?= (int)$r['id'] ?>"><i class="bi bi-eye"></i></a></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div></div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
