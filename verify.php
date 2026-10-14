<?php
/**
 * QR verification page.
 *
 * Resolves a container token (?token=) or a waste record code (?code=) and shows
 * the associated record: category, container, location and collection status.
 * Unknown, inactive or invalid identifiers are rejected with a clear message.
 */
declare(strict_types=1);

$page_title = 'QR Verification';
require_once __DIR__ . '/includes/auth.php';
require_login();

$pdo  = db();
$token = get_str('token') ?: post_str('token');
$code  = get_str('code') ?: post_str('code');

$container = null;
$record = null;
$error = '';
$requests = [];

if ($token !== '') {
    $s = $pdo->prepare(
        'SELECT c.*, wc.name AS category_name, wc.color, wc.handling_guidance
         FROM containers c LEFT JOIN waste_categories wc ON wc.id = c.category_id WHERE c.qr_token = ?'
    );
    $s->execute([$token]);
    $container = $s->fetch() ?: null;
    if (!$container) {
        $error = 'Unknown or unregistered QR code. This identifier is not in the container registry.';
    } elseif ($container['status'] === 'inactive') {
        $error = 'This container is inactive. Do not deposit or collect waste from it.';
    }
} elseif ($code !== '') {
    $s = $pdo->prepare(
        'SELECT w.*, wc.name AS category_name, wc.color, c.container_code
         FROM waste_records w LEFT JOIN waste_categories wc ON wc.id = w.category_id
         LEFT JOIN containers c ON c.id = w.container_id
         WHERE w.record_code = ?'
    );
    $s->execute([$code]);
    $record = $s->fetch() ?: null;
    if (!$record) {
        $error = 'Unknown waste record code.';
    }
} else {
    $error = 'No QR identifier supplied. Scan a label or enter a container/waste code.';
}

if ($container) {
    $rs = $pdo->prepare(
        "SELECT r.*, u.full_name AS collector FROM collection_requests r LEFT JOIN users u ON u.id = r.assigned_to
         WHERE r.container_id = ? ORDER BY r.id DESC LIMIT 5"
    );
    $rs->execute([$container['id']]);
    $requests = $rs->fetchAll();
}

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div class="page-title"><i class="bi bi-upc-scan me-2"></i>QR Verification</div>
  <a href="<?= e(app_base_url()) ?>/<?= e(current_user()['role']) ?>/scan_qr.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-camera"></i> Scan another</a>
</div>

<?php if ($error): ?>
  <div class="alert alert-danger"><i class="bi bi-x-octagon me-1"></i><?= e($error) ?></div>
  <div class="card"><div class="card-body">
    <form method="get" class="row g-2">
      <div class="col-md-8"><input name="token" class="form-control" placeholder="Enter container token or waste code"></div>
      <div class="col-md-4"><button class="btn btn-primary w-100">Look up</button></div>
    </form>
  </div></div>
<?php elseif ($container): ?>
  <div class="row g-3">
    <div class="col-lg-7">
      <div class="card">
        <div class="card-header d-flex justify-content-between">
          <span><i class="bi bi-box-seam me-1"></i>Container <?= e($container['container_code']) ?></span>
          <?= status_badge($container['status']) ?>
        </div>
        <div class="card-body">
          <dl class="row mb-0">
            <dt class="col-sm-4">Label</dt><dd class="col-sm-8"><?= e($container['label'] ?? '-') ?></dd>
            <dt class="col-sm-4">Category</dt><dd class="col-sm-8"><span class="badge" style="background:<?= e($container['color'] ?? '#6c757d') ?>">&nbsp;</span> <?= e($container['category_name'] ?? 'Unassigned') ?></dd>
            <dt class="col-sm-4">Department</dt><dd class="col-sm-8"><?= e($container['department'] ?? '-') ?></dd>
            <dt class="col-sm-4">Location</dt><dd class="col-sm-8"><?= e($container['location'] ?? '-') ?></dd>
            <dt class="col-sm-4">Fill level</dt><dd class="col-sm-8"><?= $container['capacity'] !== null ? number_format((float)$container['fill_level'], 1) . ' / ' . number_format((float)$container['capacity'], 1) : '-' ?></dd>
          </dl>
          <?php if ($container['handling_guidance']): ?>
            <div class="alert alert-info small mt-3 mb-0"><strong>Handling:</strong> <?= e($container['handling_guidance']) ?></div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="col-lg-5">
      <div class="card">
        <div class="card-header"><i class="bi bi-truck me-1"></i>Recent collection requests</div>
        <div class="card-body p-0">
          <?php if (!$requests): ?>
            <div class="empty-state"><i class="bi bi-inbox"></i>No collection requests for this container.</div>
          <?php else: ?>
            <ul class="list-group list-group-flush">
              <?php foreach ($requests as $r): ?>
                <li class="list-group-item d-flex justify-content-between">
                  <div><span class="text-mono"><?= e($r['request_no']) ?></span><div class="small text-muted"><?= e($r['collector'] ?? 'Unassigned') ?></div></div>
                  <div class="text-end"><?= status_badge($r['status']) ?><div class="small text-muted"><?= e(format_dt($r['created_at'], 'd M H:i')) ?></div></div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
<?php elseif ($record): ?>
  <div class="card">
    <div class="card-header d-flex justify-content-between">
      <span><i class="bi bi-clipboard2-data me-1"></i>Waste record <?= e($record['record_code']) ?></span>
      <?= status_badge($record['status']) ?>
    </div>
    <div class="card-body">
      <dl class="row mb-0">
        <dt class="col-sm-4">Category</dt><dd class="col-sm-8"><span class="badge" style="background:<?= e($record['color'] ?? '#6c757d') ?>">&nbsp;</span> <?= e($record['category_name']) ?></dd>
        <dt class="col-sm-4">Quantity</dt><dd class="col-sm-8"><?= number_format((float)$record['quantity'], 2) ?> <?= e($record['unit']) ?></dd>
        <dt class="col-sm-4">Container</dt><dd class="col-sm-8 text-mono"><?= e($record['container_code'] ?? '-') ?></dd>
        <dt class="col-sm-4">Location</dt><dd class="col-sm-8"><?= e($record['location'] ?? '-') ?> (<?= e($record['department'] ?? '-') ?>)</dd>
        <dt class="col-sm-4">Priority</dt><dd class="col-sm-8"><?= priority_badge($record['priority']) ?></dd>
        <dt class="col-sm-4">Recorded</dt><dd class="col-sm-8"><?= e(format_dt($record['created_at'])) ?></dd>
      </dl>
    </div>
  </div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
