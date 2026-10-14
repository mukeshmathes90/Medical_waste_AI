<?php
/** Collector - view assigned & available collection requests. */
declare(strict_types=1);

$page_title = 'Collection Requests';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/collection.php';
require_role('collector', 'admin');

$pdo = db();
$uid = (int)current_user()['id'];

// Claim an available (approved, unassigned) request.
if (is_post() && post_str('action') === 'claim') {
    require_csrf();
    $id = post_int('id');
    $req = get_request($id);
    if (!$req) {
        set_flash('danger', 'Request not found.');
    } elseif (!in_array($req['status'], ['approved','assigned'], true) || $req['assigned_to'] !== null) {
        set_flash('danger', 'That request is no longer available.');
    } else {
        $res = update_request_status($id, 'assigned', $uid, 'Claimed by collector', ['assigned_to' => $uid]);
        set_flash($res['ok'] ? 'success' : 'danger', $res['ok'] ? 'Request claimed.' : $res['error']);
    }
    redirect(app_base_url() . '/collector/requests.php');
}

$tab = get_str('tab', 'mine');
if ($tab === 'available') {
    $where = "r.status = 'approved' AND r.assigned_to IS NULL";
    $args = [];
} else {
    $where = "r.assigned_to = $uid AND r.status IN ('assigned','in_progress')";
    $args = [];
}

$requests = $pdo->prepare(
    "SELECT r.*, c.container_code, c.location AS container_location, u.full_name AS requester, wc.name AS category_name
     FROM collection_requests r
     LEFT JOIN containers c ON c.id = r.container_id
     LEFT JOIN users u ON u.id = r.requested_by
     LEFT JOIN waste_records w ON w.id = r.waste_record_id
     LEFT JOIN waste_categories wc ON wc.id = w.category_id
     WHERE $where ORDER BY CASE r.priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 ELSE 2 END, r.created_at ASC"
);
$requests->execute($args);
$requests = $requests->fetchAll();

include __DIR__ . '/../includes/header.php';
?>
<div class="page-title mb-3"><i class="bi bi-truck me-2"></i>Collection Requests</div>

<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link <?= $tab !== 'available' ? 'active' : '' ?>" href="?tab=mine">Assigned to me</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab === 'available' ? 'active' : '' ?>" href="?tab=available">Available (unassigned)</a></li>
</ul>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th>Request</th><th>Container</th><th>Category</th><th>Location</th><th>Priority</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php if (!$requests): ?>
        <tr><td colspan="7"><div class="empty-state"><i class="bi bi-inbox"></i>Nothing here.</div></td></tr>
      <?php else: foreach ($requests as $r): ?>
        <tr>
          <td class="text-mono"><?= e($r['request_no']) ?><div class="text-muted small"><?= e($r['requester'] ?? '') ?></div></td>
          <td class="text-mono"><?= e($r['container_code'] ?? '-') ?></td>
          <td><?= e($r['category_name'] ?? '-') ?></td>
          <td><?= e($r['location'] ?: ($r['container_location'] ?? '-')) ?></td>
          <td><?= priority_badge($r['priority']) ?></td>
          <td><?= status_badge($r['status']) ?></td>
          <td class="text-end text-nowrap">
            <?php if ($tab === 'available'): ?>
              <form method="post" class="d-inline" data-confirm="Claim request <?= e($r['request_no']) ?>?">
                <?= csrf_field() ?><input type="hidden" name="action" value="claim"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="btn btn-sm btn-primary"><i class="bi bi-hand-index"></i> Claim</button>
              </form>
            <?php else: ?>
              <a class="btn btn-sm btn-outline-primary" href="<?= e(app_base_url()) ?>/collector/update_status.php?request=<?= (int)$r['id'] ?>"><i class="bi bi-arrow-repeat"></i> Manage</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
