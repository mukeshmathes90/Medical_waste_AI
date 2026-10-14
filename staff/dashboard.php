<?php
/** Staff dashboard. */
declare(strict_types=1);

$page_title = 'Staff Dashboard';
require_once __DIR__ . '/../includes/auth.php';
require_role('staff', 'admin');

$pdo = db();
$user = current_user();

$my = (int)$pdo->query('SELECT COUNT(*) c FROM waste_records WHERE created_by = ' . (int)$user['id'])->fetch()['c'];
$open = (int)$pdo->query("SELECT COUNT(*) c FROM collection_requests WHERE requested_by = " . (int)$user['id'] . " AND status NOT IN ('completed','cancelled','rejected')")->fetch()['c'];
$done = (int)$pdo->query("SELECT COUNT(*) c FROM collection_requests WHERE requested_by = " . (int)$user['id'] . " AND status IN ('collected','completed')")->fetch()['c'];
$qty = (float)$pdo->query('SELECT COALESCE(SUM(quantity),0) q FROM waste_records WHERE created_by = ' . (int)$user['id'])->fetch()['q'];

$recent = $pdo->query(
    'SELECT w.*, wc.name AS category_name FROM waste_records w LEFT JOIN waste_categories wc ON wc.id = w.category_id
     WHERE w.created_by = ' . (int)$user['id'] . ' ORDER BY w.id DESC LIMIT 6'
)->fetchAll();

include __DIR__ . '/../includes/header.php';
?>
<div class="page-title mb-3">Welcome, <?= e($user['full_name']) ?></div>

<div class="row g-3 mb-4">
  <?php
  $cards = [
      ['My Waste Records', $my, 'bi-clipboard2-data', 'primary'],
      ['Open Requests', $open, 'bi-hourglass-split', 'warning'],
      ['Completed', $done, 'bi-check2-circle', 'success'],
      ['Total Quantity', number_format($qty, 1), 'bi-speedometer', 'info'],
  ];
  foreach ($cards as $c): ?>
    <div class="col-6 col-lg-3">
      <div class="card stat-card h-100"><div class="card-body d-flex align-items-center gap-3">
        <div class="stat-icon bg-<?= $c[3] ?> bg-opacity-10 text-<?= $c[3] ?>"><i class="bi <?= $c[2] ?>"></i></div>
        <div><div class="stat-value"><?= e($c[1]) ?></div><div class="stat-label"><?= e($c[0]) ?></div></div>
      </div></div>
    </div>
  <?php endforeach; ?>
</div>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header">Quick actions</div>
      <div class="card-body d-grid gap-2">
        <a class="btn btn-primary" href="<?= e(app_base_url()) ?>/staff/waste_entry.php"><i class="bi bi-plus-circle me-1"></i>New Waste Entry</a>
        <a class="btn btn-outline-primary" href="<?= e(app_base_url()) ?>/staff/waste_records.php"><i class="bi bi-list-ul me-1"></i>My Waste Records</a>
        <a class="btn btn-outline-secondary" href="<?= e(app_base_url()) ?>/staff/generate_qr.php"><i class="bi bi-qr-code me-1"></i>Generate QR</a>
        <a class="btn btn-outline-secondary" href="<?= e(app_base_url()) ?>/staff/scan_qr.php"><i class="bi bi-upc-scan me-1"></i>Scan QR</a>
      </div>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between"><span>Recent entries</span><a class="small" href="<?= e(app_base_url()) ?>/staff/waste_records.php">View all</a></div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <thead><tr><th>Record</th><th>Category</th><th>Qty</th><th>Status</th><th>Date</th></tr></thead>
            <tbody>
            <?php if (!$recent): ?>
              <tr><td colspan="5" class="empty-state"><i class="bi bi-inbox"></i>No entries yet.</td></tr>
            <?php else: foreach ($recent as $r): ?>
              <tr>
                <td class="text-mono"><?= e($r['record_code']) ?></td>
                <td><?= e($r['category_name']) ?></td>
                <td><?= number_format((float)$r['quantity'],1) ?> <?= e($r['unit']) ?></td>
                <td><?= status_badge($r['status']) ?></td>
                <td class="text-muted small"><?= e(format_dt($r['created_at'], 'd M H:i')) ?></td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
