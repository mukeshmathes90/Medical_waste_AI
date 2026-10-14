<?php
/** Admin - device registration, hardware settings and ESP32 API reference. */
declare(strict_types=1);

$page_title = 'Hardware & Devices';
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$pdo = db();
$newToken = null;
$errors = [];

if (is_post()) {
    require_csrf();
    $action = post_str('action');
    try {
        if ($action === 'create_device') {
            $code = strtoupper(post_str('device_code'));
            $name = post_str('name');
            $loc  = post_str('location');
            $bind = post_int('user_id');
            if (!preg_match('/^[A-Z0-9-]{3,32}$/', $code)) { $errors[] = 'Device code must be 3-32 uppercase letters/numbers/hyphens.'; }
            $dup = $pdo->prepare('SELECT id FROM devices WHERE device_code = ?');
            $dup->execute([$code]);
            if ($dup->fetch()) { $errors[] = 'That device code already exists.'; }
            if (!$errors) {
                $token = bin2hex(random_bytes(16));
                $pdo->prepare('INSERT INTO devices (device_code, name, api_token_hash, user_id, location, is_active) VALUES (?,?,?,?,?,1)')
                    ->execute([$code, $name ?: null, password_hash($token, PASSWORD_DEFAULT), $bind ?: null, $loc ?: null]);
                log_audit('device_created', 'device', (int)$pdo->lastInsertId(), "Created device $code");
                // Show the token exactly once.
                $_SESSION['new_device_token'] = ['code' => $code, 'token' => $token];
                set_flash('success', "Device '$code' created. Copy the token now - it is shown only once.");
                redirect(app_base_url() . '/admin/hardware.php');
            }
        } elseif ($action === 'toggle_device') {
            $id = post_int('id');
            $d = $pdo->prepare('SELECT * FROM devices WHERE id=?'); $d->execute([$id]); $dev = $d->fetch();
            if ($dev) {
                $pdo->prepare('UPDATE devices SET is_active = ? WHERE id = ?')->execute([(int)$dev['is_active'] ? 0 : 1, $id]);
                log_audit('device_toggled', 'device', $id, 'Toggled device ' . $dev['device_code']);
                set_flash('success', 'Device updated.');
            }
            redirect(app_base_url() . '/admin/hardware.php');
        } elseif ($action === 'rotate_token') {
            $id = post_int('id');
            $d = $pdo->prepare('SELECT * FROM devices WHERE id=?'); $d->execute([$id]); $dev = $d->fetch();
            if ($dev) {
                $token = bin2hex(random_bytes(16));
                $pdo->prepare('UPDATE devices SET api_token_hash = ? WHERE id = ?')->execute([password_hash($token, PASSWORD_DEFAULT), $id]);
                $pdo->prepare('UPDATE rfid_tags SET device_id = NULL WHERE device_id = ?')->execute([$id]);
                $_SESSION['new_device_token'] = ['code' => $dev['device_code'], 'token' => $token];
                log_audit('device_token_rotated', 'device', $id, 'Rotated token for ' . $dev['device_code']);
                set_flash('success', 'New token generated. Copy it now - shown once.');
            }
            redirect(app_base_url() . '/admin/hardware.php');
        } elseif ($action === 'delete_device') {
            $id = post_int('id');
            $pdo->prepare('DELETE FROM devices WHERE id=?')->execute([$id]);
            log_audit('device_deleted', 'device', $id, 'Deleted device');
            set_flash('success', 'Device deleted.');
            redirect(app_base_url() . '/admin/hardware.php');
        } elseif ($action === 'save_settings') {
            set_setting('hardware_enabled', isset($_POST['hardware_enabled']) ? '1' : '0');
            set_setting('device_api_enabled', isset($_POST['device_api_enabled']) ? '1' : '0');
            set_setting('require_rfid_verification', isset($_POST['require_rfid_verification']) ? '1' : '0');
            set_setting('require_qr_verification', isset($_POST['require_qr_verification']) ? '1' : '0');
            log_audit('settings_updated', 'settings', null, 'Hardware settings updated');
            set_flash('success', 'Hardware settings saved.');
            redirect(app_base_url() . '/admin/hardware.php');
        }
    } catch (Throwable $e) {
        $errors[] = 'Operation failed: ' . $e->getMessage();
    }
}
foreach ($errors as $er) { set_flash('danger', $er); }

if (!empty($_SESSION['new_device_token'])) {
    $newToken = $_SESSION['new_device_token'];
    unset($_SESSION['new_device_token']);
}

$devices = $pdo->query(
    'SELECT d.*, u.full_name FROM devices d LEFT JOIN users u ON u.id = d.user_id ORDER BY d.device_code'
)->fetchAll();
$collectors = $pdo->query("SELECT id, full_name FROM users WHERE role='collector' AND is_active=1 ORDER BY full_name")->fetchAll();

// Best-effort LAN IP detection (so ESP32 uses a real address, not localhost).
$lanIp = $_SERVER['SERVER_ADDR'] ?? '';
if ($lanIp === '' || $lanIp === '::1' || $lanIp === '127.0.0.1') {
    $h = @gethostbyname(gethostname());
    if ($h && $h !== gethostname()) { $lanIp = $h; }
}
$port = $_SERVER['SERVER_PORT'] ?? '8090';
$apiBase = 'http://' . ($lanIp ?: 'YOUR-PC-IP') . ':' . $port;

include __DIR__ . '/../includes/header.php';
?>
<div class="page-title mb-3"><i class="bi bi-cpu me-2"></i>Hardware &amp; Devices</div>

<?php if ($newToken): ?>
  <div class="alert alert-warning">
    <strong>Copy this device token now.</strong> It is not stored in plain text and cannot be shown again.
    <div class="mt-2"><code>Device code: <?= e($newToken['code']) ?></code><br><code>API token: <?= e($newToken['token']) ?></code></div>
  </div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-hdd-network me-1"></i>Registered devices</div>
      <div class="card-body p-0">
        <div class="table-responsive"><table class="table table-hover-align align-middle mb-0">
          <thead><tr><th>Code</th><th>Name</th><th>Bound collector</th><th>Last seen</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
          <tbody>
          <?php if (!$devices): ?>
            <tr><td colspan="6" class="text-muted p-3">No devices yet.</td></tr>
          <?php else: foreach ($devices as $d): ?>
            <tr>
              <td class="text-mono"><?= e($d['device_code']) ?></td>
              <td><?= e($d['name'] ?? '-') ?></td>
              <td><?= e($d['full_name'] ?? 'any') ?></td>
              <td class="small text-muted"><?= e(format_dt($d['last_seen'])) ?></td>
              <td><?= (int)$d['is_active'] ? '<span class="badge bg-success">active</span>' : '<span class="badge bg-secondary">inactive</span>' ?></td>
              <td class="text-end text-nowrap">
                <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="rotate_token"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><button class="btn btn-sm btn-outline-secondary" title="Rotate token"><i class="bi bi-key"></i></button></form>
                <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_device"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><button class="btn btn-sm btn-outline-warning" title="Toggle"><i class="bi bi-power"></i></button></form>
                <form method="post" class="d-inline" data-confirm="Delete device <?= e($d['device_code']) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="delete_device"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table></div>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><i class="bi bi-plus-circle me-1"></i>Register a device</div>
      <div class="card-body">
        <form method="post" class="row g-3">
          <?= csrf_field() ?><input type="hidden" name="action" value="create_device">
          <div class="col-md-4"><label class="form-label">Device code</label><input name="device_code" class="form-control" placeholder="ESP32-01" required></div>
          <div class="col-md-4"><label class="form-label">Name</label><input name="name" class="form-control" placeholder="Housekeeping reader"></div>
          <div class="col-md-4"><label class="form-label">Location</label><input name="location" class="form-control"></div>
          <div class="col-md-6"><label class="form-label">Bind to collector (optional)</label>
            <select name="user_id" class="form-select"><option value="">Any collector</option>
              <?php foreach ($collectors as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['full_name']) ?></option><?php endforeach; ?></select>
          </div>
          <div class="col-md-6 d-flex align-items-end"><button class="btn btn-primary w-100"><i class="bi bi-plus-lg"></i> Create device &amp; token</button></div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-sliders me-1"></i>Hardware settings</div>
      <div class="card-body">
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="action" value="save_settings">
          <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="hardware_enabled" id="hwEn" <?= setting('hardware_enabled','0')==='1'?'checked':'' ?>><label class="form-check-label" for="hwEn">Hardware / ESP32 enabled</label></div>
          <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="device_api_enabled" id="apiEn" <?= setting('device_api_enabled','1')==='1'?'checked':'' ?>><label class="form-check-label" for="apiEn">Device API enabled</label></div>
          <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="require_rfid_verification" id="rfidReq" <?= setting('require_rfid_verification','1')==='1'?'checked':'' ?>><label class="form-check-label" for="rfidReq">Require RFID verification before collection</label></div>
          <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" name="require_qr_verification" id="qrReq" <?= setting('require_qr_verification','1')==='1'?'checked':'' ?>><label class="form-check-label" for="qrReq">Require QR verification before collection</label></div>
          <button class="btn btn-primary btn-sm">Save settings</button>
        </form>
        <p class="small text-muted mt-2 mb-0">Turning a requirement off lets you complete collections without hardware (useful for demos).</p>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><i class="bi bi-plug me-1"></i>ESP32 API endpoints</div>
      <div class="card-body">
        <p class="small mb-1">Point the ESP32 at your computer's LAN IP (not localhost):</p>
        <div class="p-2 bg-light rounded text-mono small mb-2"><?= e($apiBase) ?></div>
        <ul class="small mb-2">
          <li><code>POST <?= e($apiBase) ?>/api/rfid_verify.php</code></li>
          <li><code>POST <?= e($apiBase) ?>/api/device_status.php</code></li>
        </ul>
        <p class="small text-muted mb-0">Headers: <code>X-Device-Code</code>, <code>X-Device-Token</code>.
          The two devices must share the same Wi-Fi network with client isolation disabled.</p>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
