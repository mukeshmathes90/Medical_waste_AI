<?php
/** Administrator dashboard - system-wide statistics from live queries. */
declare(strict_types=1);

$page_title = 'Admin Dashboard';
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$pdo = db();

$totalWaste     = (int)$pdo->query('SELECT COUNT(*) c FROM waste_records')->fetch()['c'];
$totalContainers= (int)$pdo->query("SELECT COUNT(*) c FROM containers WHERE status <> 'inactive'")->fetch()['c'];
$pendingReq     = (int)$pdo->query("SELECT COUNT(*) c FROM collection_requests WHERE status IN ('pending','approved')")->fetch()['c'];
$assignedReq    = (int)$pdo->query("SELECT COUNT(*) c FROM collection_requests WHERE status IN ('assigned','in_progress')")->fetch()['c'];
$completedReq   = (int)$pdo->query("SELECT COUNT(*) c FROM collection_requests WHERE status IN ('collected','completed')")->fetch()['c'];
$rfidFailures   = (int)$pdo->query("SELECT COUNT(*) c FROM rfid_verification_logs WHERE result <> 'verified'")->fetch()['c'];
$totalUsers     = (int)$pdo->query('SELECT COUNT(*) c FROM users WHERE is_active = 1')->fetch()['c'];

$byCategory = $pdo->query(
    'SELECT wc.name, wc.color, COUNT(w.id) AS records, COALESCE(SUM(w.quantity),0) AS qty
     FROM waste_categories wc
     LEFT JOIN waste_records w ON w.category_id = wc.id
     GROUP BY wc.id ORDER BY qty DESC'
)->fetchAll();

$maxQty = 0.0;
foreach ($byCategory as $b) { $maxQty = max($maxQty, (float)$b['qty']); }

$priorityReq = $pdo->query(
    "SELECT r.*, c.container_code, u.full_name AS requester
     FROM collection_requests r
     LEFT JOIN containers c ON c.id = r.container_id
     LEFT JOIN users u ON u.id = r.requested_by
     WHERE r.status IN ('pending','approved','assigned','in_progress')
       AND (r.priority IN ('high','urgent') OR r.created_at < datetime('now','-2 days'))
     ORDER BY CASE r.priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 ELSE 2 END, r.created_at ASC
     LIMIT 8"
)->fetchAll();

$recentAudit = $pdo->query(
    'SELECT a.*, u.full_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 10'
)->fetchAll();

$recentRfid = $pdo->query(
    "SELECT l.*, c.container_code FROM rfid_verification_logs l
     LEFT JOIN containers c ON c.id = l.container_id
     WHERE l.result <> 'verified' ORDER BY l.id DESC LIMIT 5"
)->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <div class="page-title">Dashboard</div>
    <div class="text-muted small">Live overview of medical-waste operations</div>
  </div>
  <div class="text-muted small d-none d-md-block"><?= e(date('l, d M Y')) ?></div>
</div>

<div class="row g-3 mb-4">
  <?php
  $cards = [
      ['Total Waste Records', $totalWaste, 'bi-clipboard2-data', 'primary'],
      ['Active Containers', $totalContainers, 'bi-box-seam', 'info'],
      ['Pending Requests', $pendingReq, 'bi-hourglass-split', 'warning'],
      ['Assigned / In progress', $assignedReq, 'bi-truck', 'secondary'],
      ['Completed Collections', $completedReq, 'bi-check2-circle', 'success'],
      ['RFID Failures', $rfidFailures, 'bi-shield-exclamation', 'danger'],
  ];
  foreach ($cards as $c): ?>
    <div class="col-6 col-lg-2">
      <div class="card stat-card h-100">
        <div class="card-body d-flex align-items-center gap-3">
          <div class="stat-icon bg-<?= $c[3] ?> bg-opacity-10 text-<?= $c[3] ?>"><i class="bi <?= $c[2] ?>"></i></div>
          <div>
            <div class="stat-value"><?= number_format($c[1]) ?></div>
            <div class="stat-label"><?= e($c[0]) ?></div>
          </div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-bar-chart me-1"></i>Waste quantity by category</span>
        <a href="<?= e(app_base_url()) ?>/admin/reports.php" class="small">Reports</a>
      </div>
      <div class="card-body">
        <?php if (!$byCategory): ?>
          <div class="empty-state"><i class="bi bi-inbox"></i>No waste records yet.</div>
        <?php else: foreach ($byCategory as $b): $pct = $maxQty > 0 ? ((float)$b['qty'] / $maxQty) * 100 : 0; ?>
          <div class="mb-3">
            <div class="d-flex justify-content-between small">
              <span><span class="badge" style="background:<?= e($b['color']) ?>">&nbsp;</span> <?= e($b['name']) ?></span>
              <span class="text-muted"><?= number_format((float)$b['qty'], 2) ?> &middot; <?= (int)$b['records'] ?> rec</span>
            </div>
            <div class="progress mt-1" style="height:8px">
              <div class="progress-bar" role="progressbar" style="width: <?= number_format($pct, 1) ?>%; background:<?= e($b['color']) ?>"></div>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-exclamation-triangle me-1"></i>High-priority / overdue requests</span>
        <a href="<?= e(app_base_url()) ?>/admin/requests.php" class="small">All requests</a>
      </div>
      <div class="card-body p-0">
        <?php if (!$priorityReq): ?>
          <div class="empty-state"><i class="bi bi-check2-circle"></i>Nothing urgent right now.</div>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm table-hover mb-0">
            <thead><tr><th>Request</th><th>Container</th><th>Priority</th><th>Status</th><th>Created</th></tr></thead>
            <tbody>
            <?php foreach ($priorityReq as $r): ?>
              <tr>
                <td><a href="<?= e(app_base_url()) ?>/admin/requests.php?id=<?= (int)$r['id'] ?>"><?= e($r['request_no']) ?></a></td>
                <td><?= e($r['container_code'] ?? '-') ?></td>
                <td><?= priority_badge($r['priority']) ?></td>
                <td><?= status_badge($r['status']) ?></td>
                <td class="text-muted small"><?= e(format_dt($r['created_at'])) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-activity me-1"></i>Recent activity</div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <thead><tr><th>When</th><th>User</th><th>Action</th><th>Details</th></tr></thead>
            <tbody>
            <?php if (!$recentAudit): ?>
              <tr><td colspan="4" class="empty-state">No activity recorded.</td></tr>
            <?php else: foreach ($recentAudit as $a): ?>
              <tr>
                <td class="text-muted small text-nowrap"><?= e(format_dt($a['created_at'], 'd M H:i')) ?></td>
                <td><?= e($a['full_name'] ?? 'system') ?></td>
                <td><span class="badge bg-light text-dark border"><?= e($a['action']) ?></span></td>
                <td class="small text-muted"><?= e($a['details']) ?></td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-shield-exclamation me-1"></i>Recent RFID verification failures</div>
      <div class="card-body p-0">
        <?php if (!$recentRfid): ?>
          <div class="empty-state"><i class="bi bi-shield-check"></i>No verification failures.</div>
        <?php else: ?>
        <ul class="list-group list-group-flush">
          <?php foreach ($recentRfid as $l): ?>
            <li class="list-group-item d-flex justify-content-between align-items-start">
              <div>
                <span class="badge bg-danger"><?= e($l['result']) ?></span>
                <span class="small"><?= e($l['message']) ?></span>
                <div class="text-muted small">UID <?= e($l['tag_uid'] ?? '-') ?><?= $l['container_code'] ? ' &middot; ' . e($l['container_code']) : '' ?></div>
              </div>
              <small class="text-muted text-nowrap"><?= e(format_dt($l['created_at'], 'd M H:i')) ?></small>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
