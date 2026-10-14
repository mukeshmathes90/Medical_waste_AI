<?php
/**
 * Collector - server-side QR verification against a collection request.
 * Sets qr_verified only when the scanned container matches the request's
 * container. Prevents verifying the wrong container.
 */
declare(strict_types=1);

$page_title = 'QR Verification';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/collection.php';
require_role('collector', 'admin');

$pdo = db();
$uid = (int)current_user()['id'];

$requestId = (int)(get_str('request') ?: post_str('request'));
$token = get_str('token') ?: post_str('token');
$code  = get_str('code') ?: post_str('code');

$request = $requestId ? get_request($requestId) : null;
$result = null; // ['ok'=>bool,'message'=>string]

if (!$request) {
    $result = ['ok' => false, 'message' => 'No collection request selected. Open a request and choose "Verify QR".'];
} elseif (!in_array($request['status'], ['assigned', 'in_progress'], true)) {
    $result = ['ok' => false, 'message' => 'This request is not in a state that allows verification (status: ' . $request['status'] . ').'];
} elseif ($token === '' && $code === '') {
    $result = ['ok' => false, 'message' => 'No QR identifier supplied.'];
} else {
    $container = null;
    if ($token !== '') {
        $s = $pdo->prepare('SELECT * FROM containers WHERE qr_token = ?');
        $s->execute([$token]);
        $container = $s->fetch() ?: null;
    } elseif ($code !== '') {
        // A waste-record code: resolve to its container.
        $s = $pdo->prepare('SELECT c.* FROM waste_records w JOIN containers c ON c.id = w.container_id WHERE w.record_code = ?');
        $s->execute([$code]);
        $container = $s->fetch() ?: null;
    }

    if (!$container) {
        $result = ['ok' => false, 'message' => 'Unknown or unregistered QR code.'];
        log_rfid(null, null, null, $requestId, $uid, 'denied', 'QR verify failed: unknown code');
    } elseif ((int)$container['id'] !== (int)$request['container_id']) {
        $result = ['ok' => false, 'message' => 'QR mismatch: scanned container ' . $container['container_code']
            . ' does not match this request.'];
        log_rfid(null, (int)$container['id'], null, $requestId, $uid, 'mismatch', 'QR container mismatch');
    } else {
        try {
            db_transaction(function (PDO $pdo) use ($request, $uid) {
                $pdo->prepare("UPDATE collection_requests SET qr_verified = 1, updated_at = datetime('now') WHERE id = ?")->execute([$request['id']]);
                $pdo->prepare('INSERT INTO collection_status_history (request_id, old_status, new_status, changed_by, remarks) VALUES (?,?,?,?,?)')
                    ->execute([$request['id'], $request['status'], $request['status'], $uid, 'QR verified']);
                log_audit('qr_verified', 'collection_request', (int)$request['id'], 'QR verified for ' . $request['request_no']);
            });
            log_rfid(null, (int)$request['container_id'], null, $requestId, $uid, 'verified', 'QR container verified');
            $result = ['ok' => true, 'message' => 'QR verified - container matches the request.'];
        } catch (Throwable $e) {
            $result = ['ok' => false, 'message' => 'Could not record verification: ' . $e->getMessage()];
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>
<div class="page-title mb-3"><i class="bi bi-qr-code-check me-2"></i>QR Verification</div>

<?php if ($result): ?>
  <div class="alert alert-<?= $result['ok'] ? 'success' : 'danger' ?>"><i class="bi bi-<?= $result['ok'] ? 'check-circle' : 'x-octagon' ?> me-1"></i><?= e($result['message']) ?></div>
<?php endif; ?>

<?php if ($request): ?>
  <div class="card mb-3">
    <div class="card-body">
      Request <span class="text-mono"><?= e($request['request_no']) ?></span> &middot; container
      <span class="text-mono"><?= e($request['container_code'] ?? '-') ?></span> &middot; status <?= status_badge($request['status']) ?>
    </div>
  </div>
<?php endif; ?>

<div class="d-flex gap-2">
  <?php if ($request): ?>
    <a class="btn btn-primary" href="<?= e(app_base_url()) ?>/collector/update_status.php?request=<?= (int)$request['id'] ?>"><i class="bi bi-arrow-repeat me-1"></i>Back to request</a>
    <a class="btn btn-outline-secondary" href="<?= e(app_base_url()) ?>/collector/scan_qr.php?request=<?= (int)$request['id'] ?>"><i class="bi bi-upc-scan me-1"></i>Scan again</a>
  <?php else: ?>
    <a class="btn btn-primary" href="<?= e(app_base_url()) ?>/collector/requests.php">Go to requests</a>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
