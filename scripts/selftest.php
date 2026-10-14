<?php
/**
 * Automated self-test for the Smart Medical-Waste System (CLI only).
 *
 * Run:  set MWS_DB=<temp path>  &&  php scripts\selftest.php
 * or simply:  php scripts\selftest.php         (uses the normal database)
 *
 * It exercises the real functions (classification, workflow, RFID verification,
 * authentication, device auth) so passing results reflect genuine behaviour.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/classification.php';
require_once __DIR__ . '/../includes/collection.php';
require_once __DIR__ . '/../includes/rfid.php';
require_once __DIR__ . '/../database/init.php';

$pass = 0; $fail = 0; $lines = [];
function check(string $name, bool $cond): void
{
    global $pass, $fail, $lines;
    if ($cond) { $pass++; $lines[] = "  PASS  $name"; }
    else { $fail++; $lines[] = "  FAIL  $name"; }
}

echo "== Smart Medical-Waste System self-test ==\n";
echo "Database: " . APP_DATABASE_FILE . "\n\n";

// Fresh database.
if (file_exists(APP_DATABASE_FILE)) { @unlink(APP_DATABASE_FILE); }
init_database(true, true, false);

$pdo = db();

// ---- Schema / seed --------------------------------------------------------
check('categories seeded', (int)$pdo->query('SELECT COUNT(*) c FROM waste_categories')->fetch()['c'] >= 5);
check('containers seeded', (int)$pdo->query('SELECT COUNT(*) c FROM containers')->fetch()['c'] >= 5);
check('demo staff exists', (bool)$pdo->query("SELECT id FROM users WHERE username='staff'")->fetch());
check('demo collector exists', (bool)$pdo->query("SELECT id FROM users WHERE username='collector'")->fetch());
check('demo device exists', (bool)$pdo->query("SELECT id FROM devices WHERE device_code='ESP32-01'")->fetch());

// ---- Authentication -------------------------------------------------------
$bad = attempt_login('staff', 'wrong-password');
check('login rejects wrong password', $bad['ok'] === false);
$good = attempt_login('staff', 'Staff@123');
check('login accepts correct password', $good['ok'] === true);
check('session populated after login', ($_SESSION['user']['role'] ?? '') === 'staff');
check('has_role staff true', has_role('staff'));
check('has_role admin false for staff', !has_role('admin'));

// ---- Classification rules -------------------------------------------------
$sharps = get_category((int)$pdo->query("SELECT id FROM waste_categories WHERE code='SHP'")->fetch()['id']);
$infect = get_category((int)$pdo->query("SELECT id FROM waste_categories WHERE code='INF'")->fetch()['id']);
$genContainerId = (int)$pdo->query("SELECT id FROM containers WHERE container_code='MWC-0004'")->fetch()['id'];
$genContainer = get_container($genContainerId);
$shContainerId = (int)$pdo->query("SELECT id FROM containers WHERE container_code='MWC-0002'")->fetch()['id'];
$shContainer = get_container($shContainerId);

$r1 = classify_waste(['quantity' => 1, 'unit' => 'pieces'], $sharps, $genContainer);
check('sharps in general container is rejected', $r1['valid'] === false && count($r1['errors']) > 0);
$r2 = classify_waste(['quantity' => 1, 'unit' => 'pieces'], $sharps, $shContainer);
check('sharps in sharps container is valid', $r2['valid'] === true);
$r3 = classify_waste(['quantity' => 3.5, 'unit' => 'kg'], $infect, $genContainer);
check('infectious in general container is rejected', $r3['valid'] === false);

// ---- Container code uniqueness -------------------------------------------
$dupOk = false;
try {
    $pdo->prepare('INSERT INTO containers (container_code, qr_token) VALUES (?, ?)')->execute(['MWC-0001', bin2hex(random_bytes(6))]);
} catch (Throwable $e) { $dupOk = true; }
check('duplicate container code rejected', $dupOk);

// ---- Waste record + collection workflow -----------------------------------
$staffId = (int)$pdo->query("SELECT id FROM users WHERE username='staff'")->fetch()['id'];
$collectorId = (int)$pdo->query("SELECT id FROM users WHERE username='collector'")->fetch()['id'];
$infCatId = (int)$infect['id'];
$infContainerId = (int)$pdo->query("SELECT id FROM containers WHERE container_code='MWC-0001'")->fetch()['id'];

$code = generate_code('waste_records', 'record_code', 'WR', 4);
$pdo->prepare('INSERT INTO waste_records (record_code, category_id, container_id, quantity, unit, department, location, priority, status, created_by)
               VALUES (?,?,?,?,?,?,?,?,?,?)')
    ->execute([$code, $infCatId, $infContainerId, 2.0, 'kg', 'General Ward', 'Ward A', 'normal', 'pending', $staffId]);
$wasteId = (int)$pdo->lastInsertId();
check('waste record created', $wasteId > 0);

$cr = create_collection_request($wasteId, $staffId, 'high', 'self-test');
check('collection request created', $cr['ok'] === true);
$reqId = (int)($cr['id'] ?? 0);
check('open request blocks duplicate', create_collection_request($wasteId, $staffId)['ok'] === false);

// Invalid transition (pending -> completed) must fail.
check('invalid transition pending->completed rejected', update_request_status($reqId, 'completed', $staffId)['ok'] === false);

check('approve pending request', update_request_status($reqId, 'approved', $staffId, 'ok')['ok'] === true);
check('assign to collector', update_request_status($reqId, 'assigned', $staffId, 'assign', ['assigned_to' => $collectorId])['ok'] === true);
check('start collection', update_request_status($reqId, 'in_progress', $collectorId)['ok'] === true);

// Collection must be blocked until verification is complete.
$blk = update_request_status($reqId, 'collected', $collectorId);
check('collect blocked while unverified', $blk['ok'] === false);

// ---- RFID verification ----------------------------------------------------
$tag = $pdo->query("SELECT t.tag_uid FROM rfid_tags t JOIN containers c ON c.id=t.container_id WHERE c.container_code='MWC-0001'")->fetch();
$reqRow = get_request($reqId);
$vBad = verify_rfid_scan('DEADBEEF', null, $reqRow['request_no'], $collectorId);
check('unknown tag -> not_registered', $vBad['result'] === 'not_registered');
$vOk = verify_rfid_scan($tag['tag_uid'], null, $reqRow['request_no'], $collectorId);
check('correct tag -> verified', $vOk['ok'] === true && $vOk['result'] === 'verified');
check('rfid_verified flag set', (int)get_request($reqId)['rfid_verified'] === 1);

// Tag mapped to a *different* container should mismatch the request.
$otherTag = $pdo->query("SELECT t.tag_uid FROM rfid_tags t JOIN containers c ON c.id=t.container_id WHERE c.container_code='MWC-0005'")->fetch();
if ($otherTag) {
    $vMis = verify_rfid_scan($otherTag['tag_uid'], null, $reqRow['request_no'], $collectorId);
    check('wrong container tag -> mismatch', $vMis['result'] === 'mismatch');
}

// QR verification (server-side).
$pdo->prepare("UPDATE collection_requests SET qr_verified = 1 WHERE id = ?")->execute([$reqId]);

// Now collection should succeed.
$done = update_request_status($reqId, 'collected', $collectorId);
check('collect allowed after verification', $done['ok'] === true);
check('collector authorization enforced', verify_rfid_scan($tag['tag_uid'], null, $reqRow['request_no'], 999999)['result'] === 'denied');
check('complete collection', update_request_status($reqId, 'completed', $collectorId, 'done', ['completion_notes' => 'ok'])['ok'] === true);

// ---- Device authentication ------------------------------------------------
$_SERVER['HTTP_X_DEVICE_CODE'] = 'ESP32-01';
$_SERVER['HTTP_X_DEVICE_TOKEN'] = 'demo-device-token-123';
check('device auth accepts valid token', authenticate_device() !== null);
$_SERVER['HTTP_X_DEVICE_TOKEN'] = 'wrong-token';
check('device auth rejects bad token', authenticate_device() === null);

// ---- Password hashing -----------------------------------------------------
$hash = $pdo->query("SELECT password_hash FROM users WHERE username='staff'")->fetch()['password_hash'];
check('password stored hashed (not plain)', $hash !== 'Staff@123' && str_starts_with($hash, '$'));

echo implode("\n", $lines) . "\n\n";
echo "---------------------------------------------\n";
echo "  PASSED: $pass   FAILED: $fail\n";
echo "---------------------------------------------\n";
exit($fail === 0 ? 0 : 1);
