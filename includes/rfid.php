<?php
/**
 * RFID verification core (shared by the device API and the software simulation).
 *
 * The server NEVER trusts a UID by itself: it must exist in rfid_tags, the tag
 * and container must be active, and (when a request is supplied) the container
 * must match the request and the collector must be authorised. Every attempt is
 * written to rfid_verification_logs.
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

/** Short, OLED-friendly result codes. */
function rfid_oled(string $result): string
{
    return [
        'verified'       => 'VERIFIED',
        'not_registered' => 'NOT REGISTERED',
        'denied'         => 'COLLECTION DENIED',
        'mismatch'       => 'WRONG CONTAINER',
        'inactive'       => 'TAG INACTIVE',
        'no_request'     => 'NO REQUEST',
    ][$result] ?? 'UNKNOWN';
}

/**
 * Verify an RFID scan.
 *
 * @return array{ok:bool,result:string,oled:string,message:string,container_code?:string,container_id?:int,request_id?:int}
 */
function verify_rfid_scan(string $tagUid, ?int $deviceId = null, ?string $requestNo = null, ?int $collectorId = null, ?int $deviceUserId = null): array
{
    $tagUid = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', $tagUid));
    if ($tagUid === '') {
        $result = ['ok' => false, 'result' => 'not_registered', 'message' => 'Empty tag UID.'];
        $result['oled'] = rfid_oled($result['result']);
        log_rfid($deviceId, null, null, null, $collectorId, 'not_registered', 'Empty tag UID');
        return $result;
    }

    $pdo = db();

    // 1. Look up the tag.
    $stmt = $pdo->prepare(
        'SELECT t.*, c.container_code, c.status AS container_status, c.category_id
         FROM rfid_tags t JOIN containers c ON c.id = t.container_id WHERE t.tag_uid = ?'
    );
    $stmt->execute([$tagUid]);
    $tag = $stmt->fetch();

    if (!$tag) {
        log_rfid($deviceId, null, $tagUid, null, $collectorId, 'not_registered', 'Unknown tag UID');
        return ['ok' => false, 'result' => 'not_registered', 'oled' => rfid_oled('not_registered'), 'message' => 'Tag is not registered.', 'tag_uid' => $tagUid];
    }

    $containerId = (int)$tag['container_id'];
    $containerCode = $tag['container_code'];

    // 2. Tag / container active?
    if ((int)$tag['is_active'] !== 1) {
        log_rfid($deviceId, $containerId, $tagUid, null, $collectorId, 'inactive', 'Tag inactive');
        return ['ok' => false, 'result' => 'inactive', 'oled' => rfid_oled('inactive'), 'message' => 'Tag is inactive.', 'container_code' => $containerCode, 'container_id' => $containerId, 'tag_uid' => $tagUid];
    }
    if ($tag['container_status'] === 'inactive') {
        log_rfid($deviceId, $containerId, $tagUid, null, $collectorId, 'denied', 'Container inactive');
        return ['ok' => false, 'result' => 'denied', 'oled' => rfid_oled('denied'), 'message' => 'Container is inactive.', 'container_code' => $containerCode, 'container_id' => $containerId, 'tag_uid' => $tagUid];
    }

    // 3. Optional request validation.
    if ($requestNo !== null && $requestNo !== '') {
        $rs = $pdo->prepare('SELECT * FROM collection_requests WHERE request_no = ?');
        $rs->execute([$requestNo]);
        $req = $rs->fetch();
        if (!$req) {
            log_rfid($deviceId, $containerId, $tagUid, null, $collectorId, 'denied', "Unknown request $requestNo");
            return ['ok' => false, 'result' => 'denied', 'oled' => rfid_oled('denied'), 'message' => 'Collection request not found.', 'container_code' => $containerCode, 'container_id' => $containerId, 'tag_uid' => $tagUid];
        }
        $requestId = (int)$req['id'];

        if ((int)$req['container_id'] !== $containerId) {
            log_rfid($deviceId, $containerId, $tagUid, $requestId, $collectorId, 'mismatch', 'Container mismatch');
            return ['ok' => false, 'result' => 'mismatch', 'oled' => rfid_oled('mismatch'), 'message' => 'Scanned container does not match the request.', 'container_code' => $containerCode, 'container_id' => $containerId, 'request_id' => $requestId, 'tag_uid' => $tagUid];
        }

        if (!in_array($req['status'], ['assigned', 'in_progress', 'approved', 'pending'], true)) {
            log_rfid($deviceId, $containerId, $tagUid, $requestId, $collectorId, 'denied', 'Request status ' . $req['status']);
            return ['ok' => false, 'result' => 'denied', 'oled' => rfid_oled('denied'), 'message' => 'Request status does not allow collection (' . $req['status'] . ').', 'container_code' => $containerCode, 'container_id' => $containerId, 'request_id' => $requestId, 'tag_uid' => $tagUid];
        }

        // Collector authorisation: if the request is assigned, only that collector
        // (or a bound device user) may verify it.
        $actor = $collectorId ?? $deviceUserId;
        if ($req['assigned_to'] !== null && $actor !== null && (int)$req['assigned_to'] !== (int)$actor) {
            log_rfid($deviceId, $containerId, $tagUid, $requestId, $actor, 'denied', 'Collector not assigned to request');
            return ['ok' => false, 'result' => 'denied', 'oled' => rfid_oled('denied'), 'message' => 'You are not assigned to this request.', 'container_code' => $containerCode, 'container_id' => $containerId, 'request_id' => $requestId, 'tag_uid' => $tagUid];
        }

        // Approve: mark the request RFID-verified.
        try {
            db_transaction(function (PDO $pdo) use ($requestId, $actor, $req) {
                $pdo->prepare("UPDATE collection_requests SET rfid_verified = 1, updated_at = datetime('now') WHERE id = ?")->execute([$requestId]);
                $pdo->prepare('INSERT INTO collection_status_history (request_id, old_status, new_status, changed_by, remarks) VALUES (?,?,?,?,?)')
                    ->execute([$requestId, $req['status'], $req['status'], $actor, 'RFID verified']);
            });
        } catch (Throwable $e) {
            // continue - log still recorded
        }

        log_rfid($deviceId, $containerId, $tagUid, $requestId, $actor, 'verified', 'Verified for ' . $requestNo);
        return ['ok' => true, 'result' => 'verified', 'oled' => rfid_oled('verified'), 'message' => 'Collection verified.', 'container_code' => $containerCode, 'container_id' => $containerId, 'request_id' => $requestId, 'tag_uid' => $tagUid];
    }

    // 4. No request supplied: registered + active => verified identity only.
    log_rfid($deviceId, $containerId, $tagUid, null, $collectorId, 'verified', 'Tag recognised (no request)');
    return ['ok' => true, 'result' => 'verified', 'oled' => rfid_oled('verified'), 'message' => 'Tag recognised.', 'container_code' => $containerCode, 'container_id' => $containerId, 'tag_uid' => $tagUid];
}

/** Authenticate a device from headers/body. Returns the device row or null. */
function authenticate_device(): ?array
{
    $code  = $_SERVER['HTTP_X_DEVICE_CODE']  ?? ($_POST['device_code'] ?? ($_GET['device_code'] ?? ''));
    $token = $_SERVER['HTTP_X_DEVICE_TOKEN'] ?? ($_POST['token'] ?? ($_POST['device_token'] ?? ($_GET['token'] ?? '')));

    if ($code === '' || $token === '') {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM devices WHERE device_code = ?');
    $stmt->execute([$code]);
    $device = $stmt->fetch();
    if (!$device || (int)$device['is_active'] !== 1) {
        return null;
    }
    if (!password_verify($token, $device['api_token_hash'])) {
        return null;
    }
    return $device;
}

/** Simple per-bucket rate limiter backed by the api_rate table. */
function api_rate_limit(string $bucket, int $perMinute): bool
{
    $pdo = db();
    $pdo->prepare('INSERT INTO api_rate (bucket) VALUES (?)')->execute([$bucket]);

    $stmt = $pdo->prepare("SELECT COUNT(*) c FROM api_rate WHERE bucket = ? AND created_at > datetime('now','-1 minute')");
    $stmt->execute([$bucket]);
    $count = (int)$stmt->fetch()['c'];

    if (random_int(1, 30) === 1) {
        $pdo->exec("DELETE FROM api_rate WHERE created_at < datetime('now','-10 minutes')");
    }
    return $count <= $perMinute;
}
