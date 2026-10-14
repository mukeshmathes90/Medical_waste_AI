<?php
/**
 * Device heartbeat / status API for the ESP32.
 *
 * The device can poll this endpoint to confirm connectivity and learn whether
 * verification is enabled. It also returns the number of open collection
 * requests assigned to the device's bound collector (handy for the OLED).
 *
 * Auth: device_code + token (same as rfid_verify.php).
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/rfid.php';

header('X-Content-Type-Options: nosniff');

if (setting('device_api_enabled', '1') !== '1') {
    json_out(['ok' => false, 'message' => 'Device API disabled.'], 503);
}

$device = authenticate_device();
if (!$device) {
    json_out(['ok' => false, 'message' => 'Device authentication failed.'], 401);
}

db()->prepare("UPDATE devices SET last_seen = datetime('now') WHERE id = ?")->execute([$device['id']]);

$openCount = 0;
if ($device['user_id'] !== null) {
    $stmt = db()->prepare("SELECT COUNT(*) c FROM collection_requests WHERE assigned_to = ? AND status IN ('assigned','in_progress')");
    $stmt->execute([(int)$device['user_id']]);
    $openCount = (int)$stmt->fetch()['c'];
}

json_out([
    'ok'                 => true,
    'device_code'        => $device['device_code'],
    'name'               => $device['name'],
    'hardware_enabled'   => setting('hardware_enabled', '0') === '1',
    'rfid_required'      => setting('require_rfid_verification', '1') === '1',
    'open_requests'      => $openCount,
    'server_time'        => date('c'),
]);
