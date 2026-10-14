<?php
/** Collector dashboard. */
declare(strict_types=1);

$page_title = 'Collector Dashboard';
require_once __DIR__ . '/../includes/auth.php';
require_role('collector', 'admin');

$pdo = db();
$uid = (int)current_user()['id'];

$assigned = (int)$pdo->query("SELECT COUNT(*) c FROM collection_requests WHERE assigned_to=$uid AND status IN ('assigned','in_progress')")->fetch()['c'];
$completed = (int)$pdo->query("SELECT COUNT(*) c FROM collection_requests WHERE assigned_to=$uid AND status IN ('collected','completed')")->fetch()['c'];
$available = (int)$pdo->query("SELECT COUNT(*) c FROM collection_requests WHERE status='approved' AND assigned_to IS NULL")->fetch()['c'];
$todayDone = (int)$pdo->query("SELECT COUNT(*) c FROM collection_requests WHERE assigned_to=$uid AND status IN ('collected','completed') AND date(collected_at)=date('now')")->fetch()['c'];

$mine = $pdo->query(
    "SELECT r.*, c.container_code FROM collection_requests r LEFT JOIN containers c ON c.id=r.container_id
     WHERE r.assigned_to=$uid AND r.status IN ('assigned','in_progress') ORDER BY r.created_at DESC LIMIT 6"
)->fetchAll();

include __DIR__ . '/../includes/header.php';
?>
<div class="page-title mb-3">Welcome, <?= e(current_user()['full_name']) ?></div>

<div class="row g-3 mb-4">
  <?php
  $cards = [
      ['Assigned to me', $assigned, 'bi-truck', 'primary'],
      ['Available', $available, 'bi-inbox', 'info'],
      ['Completed', $completed, 'bi-check2-circle', 'success'],
      ['Collected today', $todayDone, 'bi-calendar-check', 'secondary'],
  ];
  foreach ($cards as $c): ?>
    <div class="col-6 col-lg-3"><div class="card stat-card h-100"><div class="card-body d-flex align-items-center gap-3">
      <div class="stat-icon bg-<?= $c[3] ?> bg-opacity-10 text-<?= $c[3] ?>"><i class="bi <?= $c[2] ?>"></i></div>
      <div><div class="stat-value"><?= e($c[1]) ?></div><div class="stat-label"><?= e($c[0]) ?></div></div>
    </div></div></div>
  <?php endforeach; ?>
</div>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="card h-100"><div class="card-header">Quick actions</div>
      <div class="card-body d-grid gap-2">
        <a class="btn btn-primary" href="<?= e(app_base_url()) ?>/collector/requests.php"><i class="bi bi-truck me-1"></i>Collection Requests</a>
        <a class="btn btn-outline-primary" href="<?= e(app_base_url()) ?>/collector/scan_qr.php"><i class="bi bi-upc-scan me-1"></i>Scan Container QR</a>
        <a class="btn btn-outline-secondary" href="<?= e(app_base_url()) ?>/collector/verify_rfid.php"><i class="bi bi-wifi me-1"></i>RFID Verification</a>
      </div>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="card h-100"><div class="card-header d-flex justify-content-between"><span>My active jobs</span><a class="small" href="<?= e(app_base_url()) ?>/collector/requests.php">View all</a></div>
      <div class="card-body p-0"><div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead><tr><th>Request</th><th>Container</th><th>Location</th><th>Priority</th><th>Status</th><th></th></tr></thead>
          <tbody>
          <?php if (!$mine): ?>
            <tr><td colspan="6" class="empty-state"><i class="bi bi-inbox"></i>No active jobs. Claim one from the requests page.</td></tr>
          <?php else: foreach ($mine as $r): ?>
            <tr>
              <td class="text-mono"><?= e($r['request_no']) ?></td>
              <td class="text-mono"><?= e($r['container_code'] ?? '-') ?></td>
              <td><?= e($r['location'] ?? '-') ?></td>
              <td><?= priority_badge($r['priority']) ?></td>
              <td><?= status_badge($r['status']) ?></td>
              <td><a class="btn btn-sm btn-outline-primary" href="<?= e(app_base_url()) ?>/collector/update_status.php?request=<?= (int)$r['id'] ?>">Open</a></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div></div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
