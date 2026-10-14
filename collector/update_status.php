<?php
/** Collector - advance a collection request through the controlled status flow. */
declare(strict_types=1);

$page_title = 'Update Collection Status';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/collection.php';
require_role('collector', 'admin');

$pdo = db();
$uid = (int)current_user()['id'];

$requestId = (int)(get_str('request') ?: post_str('request'));
$request = $requestId ? get_request($requestId) : null;

if (is_post()) {
    require_csrf();
    if (!$request) {
        set_flash('danger', 'Request not found.');
        redirect(app_base_url() . '/collector/requests.php');
    }
    // Only the assigned collector (or admin) may act on a request.
    if (current_user()['role'] === 'collector' && $request['assigned_to'] !== null && (int)$request['assigned_to'] !== $uid) {
        set_flash('danger', 'You are not assigned to this request.');
        redirect(app_base_url() . '/collector/requests.php');
    }
    $action = post_str('action');
    $map = ['start' => 'in_progress', 'collected' => 'collected', 'completed' => 'completed'];
    if (!isset($map[$action])) {
        set_flash('danger', 'Unknown action.');
    } else {
        $new = $map[$action];
        $opts = [];
        if ($new === 'completed') { $opts['completion_notes'] = post_str('completion_notes'); }
        if ($new === 'in_progress') { $opts['assigned_to'] = $request['assigned_to'] ?? $uid; }
        $res = update_request_status($requestId, $new, $uid, post_str('remarks'), $opts);
        set_flash($res['ok'] ? 'success' : 'danger', $res['ok'] ? "Status updated to '" . str_replace('_', ' ', $new) . "'." : $res['error']);
    }
    redirect(app_base_url() . '/collector/update_status.php?request=' . $requestId);
}

if (!$request) {
    set_flash('warning', 'Select a collection request to manage.');
    redirect(app_base_url() . '/collector/requests.php');
}

$missing = missing_verification($request);
$history = $pdo->prepare('SELECT h.*, u.full_name FROM collection_status_history h LEFT JOIN users u ON u.id = h.changed_by WHERE h.request_id = ? ORDER BY h.id');
$history->execute([$requestId]);
$history = $history->fetchAll();

include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div class="page-title"><i class="bi bi-arrow-repeat me-2"></i>Request <?= e($request['request_no']) ?></div>
  <a href="<?= e(app_base_url()) ?>/collector/requests.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card mb-3">
      <div class="card-header d-flex justify-content-between"><span>Details</span><?= status_badge($request['status']) ?></div>
      <div class="card-body">
        <dl class="row mb-0">
          <dt class="col-sm-4">Container</dt><dd class="col-sm-8 text-mono"><?= e($request['container_code'] ?? '-') ?></dd>
          <dt class="col-sm-4">Category</dt><dd class="col-sm-8"><?= e($request['category_name'] ?? '-') ?></dd>
          <dt class="col-sm-4">Quantity</dt><dd class="col-sm-8"><?= e($request['quantity']) ?> <?= e($request['unit']) ?></dd>
          <dt class="col-sm-4">Location</dt><dd class="col-sm-8"><?= e($request['location'] ?? '-') ?> (<?= e($request['department'] ?? '-') ?>)</dd>
          <dt class="col-sm-4">Requester</dt><dd class="col-sm-8"><?= e($request['requester_name'] ?? '-') ?></dd>
          <dt class="col-sm-4">Priority</dt><dd class="col-sm-8"><?= priority_badge($request['priority']) ?></dd>
          <dt class="col-sm-4">QR verified</dt><dd class="col-sm-8"><?= (int)$request['qr_verified'] ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>' ?></dd>
          <dt class="col-sm-4">RFID verified</dt><dd class="col-sm-8"><?= (int)$request['rfid_verified'] ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>' ?></dd>
        </dl>
      </div>
    </div>

    <div class="card">
      <div class="card-header">Verification status</div>
      <div class="card-body">
        <?php if ((int)$request['verification_required'] !== 1): ?>
          <div class="alert alert-secondary mb-0 small">Verification is not required for this request.</div>
        <?php elseif (!$missing): ?>
          <div class="alert alert-success mb-0 small"><i class="bi bi-check-circle me-1"></i>All required verification checks are satisfied.</div>
        <?php else: ?>
          <div class="alert alert-warning small mb-2"><i class="bi bi-exclamation-triangle me-1"></i>Outstanding before collection:</div>
          <ul class="mb-0 small"><?php foreach ($missing as $m): ?><li><?= e($m) ?></li><?php endforeach; ?></ul>
          <div class="d-flex gap-2 mt-3">
            <a class="btn btn-sm btn-outline-primary" href="<?= e(app_base_url()) ?>/collector/scan_qr.php?request=<?= (int)$request['id'] ?>"><i class="bi bi-upc-scan me-1"></i>Verify QR</a>
            <a class="btn btn-sm btn-outline-primary" href="<?= e(app_base_url()) ?>/collector/verify_rfid.php?request=<?= (int)$request['id'] ?>"><i class="bi bi-wifi me-1"></i>Verify RFID</a>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card mb-3">
      <div class="card-header">Actions</div>
      <div class="card-body d-grid gap-2">
        <?php if ($request['status'] === 'assigned'): ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="request" value="<?= (int)$request['id'] ?>"><input type="hidden" name="action" value="start">
            <button class="btn btn-warning w-100"><i class="bi bi-play-circle me-1"></i>Start collection</button></form>
        <?php elseif ($request['status'] === 'in_progress'): ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="request" value="<?= (int)$request['id'] ?>"><input type="hidden" name="action" value="collected">
            <button class="btn btn-success w-100" <?= $missing ? 'disabled title="Complete verification first"' : '' ?>><i class="bi bi-check2-circle me-1"></i>Mark collected</button>
            <?php if ($missing): ?><div class="form-text">Button disabled until verification completes.</div><?php endif; ?></form>
        <?php elseif ($request['status'] === 'collected'): ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="request" value="<?= (int)$request['id'] ?>"><input type="hidden" name="action" value="completed">
            <textarea name="completion_notes" class="form-control mb-2" rows="2" placeholder="Completion / disposal notes"></textarea>
            <button class="btn btn-primary w-100"><i class="bi bi-flag me-1"></i>Mark completed</button></form>
        <?php else: ?>
          <div class="text-muted small">No actions available for status '<?= e($request['status']) ?>'.</div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header">Status history</div>
      <div class="card-body p-0">
        <ul class="list-group list-group-flush">
          <?php foreach ($history as $h): ?>
            <li class="list-group-item">
              <div class="d-flex justify-content-between">
                <span class="small"><?= $h['old_status'] ? e($h['old_status']) . ' &rarr; ' : '' ?><strong><?= e($h['new_status']) ?></strong></span>
                <span class="small text-muted"><?= e(format_dt($h['created_at'], 'd M H:i')) ?></span>
              </div>
              <?php if ($h['remarks']): ?><div class="small text-muted"><?= e($h['remarks']) ?></div><?php endif; ?>
              <div class="small text-muted">by <?= e($h['full_name'] ?? 'system') ?></div>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
