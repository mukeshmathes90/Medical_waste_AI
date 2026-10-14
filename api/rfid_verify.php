<?php
/**
 * RFID verification API for the ESP32 device.
 *
 * POST fields (or headers):
 *   device_code  (X-Device-Code)   - registered device code
 *   token        (X-Device-Token)  - device API token (verified against a hash)
 *   tag_uid      - the RFID UID read by the reader
 *   request_no   - optional collection request number to verify against
 *
 * Returns JSON: {ok, result, oled, message, container_code}
 * The OLED should display the short `oled` value.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/rfid.php';

header('X-Content-Type-Options: nosniff');

if (setting('device_api_enabled', '1') !== '1') {
    json_out(['ok' => false, 'result' => 'denied', 'oled' => 'API DISABLED', 'message' => 'Device API is disabled.'], 503);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_out(['ok' => false, 'result' => 'denied', 'oled' => 'POST ONLY', 'message' => 'Use HTTP POST.'], 405);
}

if (!api_rate_limit('rfid:' . client_ip(), DEVICE_RATE_LIMIT_PER_MINUTE)) {
    json_out(['ok' => false, 'result' => 'denied', 'oled' => 'RATE LIMITED', 'message' => 'Too many requests.'], 429);
}

$device = authenticate_device();
if (!$device) {
    json_out(['ok' => false, 'result' => 'denied', 'oled' => 'AUTH FAILED', 'message' => 'Device authentication failed.'], 401);
}

$tagUid    = post_str('tag_uid');
$requestNo = post_str('request_no');

if ($tagUid === '') {
    json_out(['ok' => false, 'result' => 'not_registered', 'oled' => 'NO TAG', 'message' => 'Missing tag_uid.'], 422);
}

// Heartbeat
db()->prepare("UPDATE devices SET last_seen = datetime('now') WHERE id = ?")->execute([$device['id']]);

$deviceUserId = $device['user_id'] !== null ? (int)$device['user_id'] : null;

$res = verify_rfid_scan($tagUid, (int)$device['id'], $requestNo ?: null, $deviceUserId, $deviceUserId);

json_out([
    'ok'             => $res['ok'],
    'result'         => $res['result'],
    'oled'           => $res['oled'],
    'message'        => $res['message'],
    'container_code' => $res['container_code'] ?? null,
    'request_id'     => $res['request_id'] ?? null,
    'server_time'    => date('c'),
]);
