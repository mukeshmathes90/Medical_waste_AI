<?php
/**
 * Collector - RFID verification.
 *
 * Two modes:
 *  - Hardware mode: the ESP32 posts to api/rfid_verify.php; results appear in the
 *    log below and on the request.
 *  - Software simulation: choose a registered tag (or type a UID) and verify it
 *    here without any hardware. This exercises the exact same server-side rules.
 */
declare(strict_types=1);

$page_title = 'RFID Verification';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/collection.php';
require_once __DIR__ . '/../includes/rfid.php';
require_role('collector', 'admin');

$pdo = db();
$uid = (int)current_user()['id'];

$requestId = (int)get_str('request');
$request = $requestId ? get_request($requestId) : null;
$simResult = null;

if (is_post()) {
    require_csrf();
    $tagUid = post_str('tag_uid');
    $reqNo  = post_str('request_no');
    if ($tagUid === '') {
        set_flash('danger', 'Select or enter a tag UID.');
    } else {
        $simResult = verify_rfid_scan($tagUid, null, $reqNo ?: null, $uid, $uid);
        set_flash($simResult['ok'] ? 'success' : 'danger', 'Simulated scan: ' . $simResult['message']);
        if ($requestId) {
            redirect(app_base_url() . '/collector/verify_rfid.php?request=' . $requestId);
        }
    }
}

$hardwareEnabled = setting('hardware_enabled', '0') === '1';
$devices = $pdo->query('SELECT device_code, name, last_seen, is_active FROM devices ORDER BY device_code')->fetchAll();
$tags = $pdo->query(
    'SELECT t.tag_uid, c.container_code FROM rfid_tags t JOIN containers c ON c.id = t.container_id
     WHERE t.is_active = 1 ORDER BY c.container_code'
)->fetchAll();

$logs = $pdo->query(
    "SELECT l.*, c.container_code, d.device_code, u.full_name AS collector
     FROM rfid_verification_logs l
     LEFT JOIN containers c ON c.id = l.container_id
     LEFT JOIN devices d ON d.id = l.device_id
     LEFT JOIN users u ON u.id = l.collector_id
     ORDER BY l.id DESC LIMIT 12"
)->fetchAll();

include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div class="page-title"><i class="bi bi-wifi me-2"></i>RFID Verification</div>
  <span class="badge bg-<?= $hardwareEnabled ? 'success' : 'secondary' ?>"><?= $hardwareEnabled ? 'Hardware enabled' : 'Hardware disabled' ?></span>
</div>

<?php if ($request): ?>
  <div class="alert alert-primary small">
    Verifying for request <span class="text-mono"><?= e($request['request_no']) ?></span>
    (container <span class="text-mono"><?= e($request['container_code'] ?? '-') ?></span>).
  </div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-hdd-network me-1"></i>Registered devices</div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead><tr><th>Code</th><th>Name</th><th>Last seen</th><th>Status</th></tr></thead>
          <tbody>
          <?php if (!$devices): ?>
            <tr><td colspan="4" class="text-muted p-3">No devices registered. Add one in Admin &rarr; Hardware &amp; Devices.</td></tr>
          <?php else: foreach ($devices as $d): ?>
            <tr>
              <td class="text-mono"><?= e($d['device_code']) ?></td>
              <td><?= e($d['name']) ?></td>
              <td class="small text-muted"><?= e(format_dt($d['last_seen'])) ?></td>
              <td><?= (int)$d['is_active'] ? '<span class="badge bg-success">active</span>' : '<span class="badge bg-secondary">off</span>' ?></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <div class="card-header bg-warning-subtle"><i class="bi bi-motherboard me-1"></i>Software simulation (no hardware)</div>
      <div class="card-body">
        <p class="small text-muted">Use this when no ESP32/RFID reader is connected. It runs the same server-side validation as the device API.</p>
        <form method="post">
          <?= csrf_field() ?>
          <div class="mb-3">
            <label class="form-label">Tag UID</label>
            <select name="tag_uid" class="form-select mb-2">
              <option value="">-- choose a registered tag --</option>
              <?php foreach ($tags as $t): ?>
                <option value="<?= e($t['tag_uid']) ?>"><?= e($t['tag_uid']) ?> (<?= e($t['container_code']) ?>)</option>
              <?php endforeach; ?>
              <option value="DEADBEEF">DEADBEEF (unregistered - to test rejection)</option>
            </select>
            <input name="tag_uid" class="form-control text-mono" placeholder="or type a UID (e.g. A1B2C301)">
          </div>
          <div class="mb-3">
            <label class="form-label">Request number (optional)</label>
            <input name="request_no" class="form-control text-mono" value="<?= e($request['request_no'] ?? '') ?>" placeholder="REQ-2026-0001">
          </div>
          <button class="btn btn-warning"><i class="bi bi-play-circle me-1"></i>Simulate RFID scan</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-journal-text me-1"></i>Recent verification log</div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <thead><tr><th>When</th><th>UID</th><th>Container</th><th>Result</th></tr></thead>
            <tbody>
            <?php if (!$logs): ?>
              <tr><td colspan="4" class="empty-state"><i class="bi bi-journal"></i>No verification activity yet.</td></tr>
            <?php else: foreach ($logs as $l): ?>
              <tr>
                <td class="small text-muted text-nowrap"><?= e(format_dt($l['created_at'], 'd M H:i:s')) ?></td>
                <td class="text-mono small"><?= e($l['tag_uid'] ?? '-') ?></td>
                <td class="text-mono small"><?= e($l['container_code'] ?? '-') ?></td>
                <td><span class="badge bg-<?= $l['result'] === 'verified' ? 'success' : 'danger' ?>"><?= e($l['result']) ?></span></td>
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
