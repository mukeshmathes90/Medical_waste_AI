<?php
/**
 * Collection request workflow: creation, the controlled status flow, server-side
 * transition validation, verification gating and status history. All multi-step
 * updates run inside a transaction.
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

/** Allowed status transitions (single source of truth). */
function request_transitions(): array
{
    return [
        'pending'     => ['approved', 'rejected', 'cancelled'],
        'approved'    => ['assigned', 'cancelled', 'rejected'],
        'assigned'    => ['in_progress', 'assigned', 'cancelled'], // 'assigned' allows reassignment
        'in_progress' => ['collected', 'assigned', 'cancelled'],
        'collected'   => ['completed'],
        'completed'   => [],
        'cancelled'   => [],
        'rejected'    => [],
    ];
}

function can_transition(string $from, string $to): bool
{
    $map = request_transitions();
    return in_array($to, $map[$from] ?? [], true);
}

function get_request(int $id): ?array
{
    $stmt = db()->prepare(
        'SELECT r.*, c.container_code, c.qr_token, u.full_name AS requester_name, a.full_name AS collector_name,
                w.record_code, wc.name AS category_name
         FROM collection_requests r
         LEFT JOIN containers c ON c.id = r.container_id
         LEFT JOIN users u ON u.id = r.requested_by
         LEFT JOIN users a ON a.id = r.assigned_to
         LEFT JOIN waste_records w ON w.id = r.waste_record_id
         LEFT JOIN waste_categories wc ON wc.id = w.category_id
         WHERE r.id = ?'
    );
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/**
 * Verification checks still outstanding for a request.
 * @return string[] human-readable list of missing checks
 */
function missing_verification(array $request): array
{
    $missing = [];
    if ((int)$request['verification_required'] !== 1) {
        return $missing;
    }
    if (setting('require_qr_verification', '1') === '1' && (int)$request['qr_verified'] !== 1) {
        $missing[] = 'QR code scan not verified';
    }
    if (setting('require_rfid_verification', '1') === '1' && (int)$request['rfid_verified'] !== 1) {
        $missing[] = 'RFID verification not completed';
    }
    return $missing;
}

/**
 * Apply a validated status change inside a transaction.
 *
 * @param array $opts Keys: assigned_to (int), completion_notes (string)
 * @return array{ok:bool,error?:string}
 */
function update_request_status(int $id, string $newStatus, int $userId, string $remarks = '', array $opts = []): array
{
    try {
        return db_transaction(function (PDO $pdo) use ($id, $newStatus, $userId, $remarks, $opts) {
            $stmt = $pdo->prepare('SELECT * FROM collection_requests WHERE id = ?');
            $stmt->execute([$id]);
            $req = $stmt->fetch();
            if (!$req) {
                return ['ok' => false, 'error' => 'Collection request not found.'];
            }

            $old = $req['status'];
            if ($newStatus === $old && $newStatus !== 'assigned') {
                return ['ok' => false, 'error' => "Request is already '$old'."];
            }
            if ($newStatus !== $old && !can_transition($old, $newStatus)) {
                return ['ok' => false, 'error' => "Invalid status change: $old -> $newStatus."];
            }

            $fields = ['status = ?', "updated_at = datetime('now')"];
            $args   = [$newStatus];

            switch ($newStatus) {
                case 'assigned':
                    $assignee = (int)($opts['assigned_to'] ?? 0);
                    if ($assignee <= 0) {
                        return ['ok' => false, 'error' => 'Select a collector to assign.'];
                    }
                    $c = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role='collector' AND is_active=1");
                    $c->execute([$assignee]);
                    if (!$c->fetch()) {
                        return ['ok' => false, 'error' => 'The selected collector is not active.'];
                    }
                    $fields[] = 'assigned_to = ?';
                    $args[]   = $assignee;
                    break;

                case 'collected':
                    $missing = missing_verification($req);
                    if ($missing) {
                        return ['ok' => false, 'error' => 'Cannot mark collected - outstanding verification: ' . implode(', ', $missing) . '.'];
                    }
                    $fields[] = "collected_at = datetime('now')";
                    if ($req['waste_record_id']) {
                        $pdo->prepare("UPDATE waste_records SET status='collected', updated_at=datetime('now') WHERE id=?")
                            ->execute([$req['waste_record_id']]);
                    }
                    break;

                case 'completed':
                    $fields[] = "completed_at = datetime('now')";
                    if ($req['waste_record_id']) {
                        $pdo->prepare("UPDATE waste_records SET status='disposed', updated_at=datetime('now') WHERE id=?")
                            ->execute([$req['waste_record_id']]);
                    }
                    if (!empty($opts['completion_notes'])) {
                        $fields[] = 'completion_notes = ?';
                        $args[]   = $opts['completion_notes'];
                    }
                    break;

                case 'rejected':
                case 'cancelled':
                    if ($remarks === '') {
                        return ['ok' => false, 'error' => 'A reason is required to cancel or reject a request.'];
                    }
                    if ($req['waste_record_id']) {
                        $pdo->prepare("UPDATE waste_records SET status='pending', updated_at=datetime('now') WHERE id=? AND status='requested'")
                            ->execute([$req['waste_record_id']]);
                    }
                    break;
            }

            $args[] = $id;
            $pdo->prepare('UPDATE collection_requests SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($args);

            $pdo->prepare('INSERT INTO collection_status_history (request_id, old_status, new_status, changed_by, remarks) VALUES (?,?,?,?,?)')
                ->execute([$id, $old, $newStatus, $userId, $remarks ?: null]);

            log_audit('request_status_changed', 'collection_request', $id, "{$req['request_no']}: $old -> $newStatus" . ($remarks ? " ($remarks)" : ''));

            return ['ok' => true];
        });
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Update failed: ' . $e->getMessage()];
    }
}

/**
 * Create a collection request for an existing waste record.
 * @return array{ok:bool,id?:int,error?:string}
 */
function create_collection_request(int $wasteRecordId, int $requestedBy, string $priority = 'normal', string $notes = ''): array
{
    try {
        return db_transaction(function (PDO $pdo) use ($wasteRecordId, $requestedBy, $priority, $notes) {
            $stmt = $pdo->prepare('SELECT * FROM waste_records WHERE id = ?');
            $stmt->execute([$wasteRecordId]);
            $w = $stmt->fetch();
            if (!$w) {
                return ['ok' => false, 'error' => 'Waste record not found.'];
            }
            if (in_array($w['status'], ['collected', 'disposed'], true)) {
                return ['ok' => false, 'error' => 'This waste record has already been collected.'];
            }
            $open = $pdo->prepare("SELECT COUNT(*) c FROM collection_requests WHERE waste_record_id = ? AND status NOT IN ('completed','cancelled','rejected')");
            $open->execute([$wasteRecordId]);
            if ((int)$open->fetch()['c'] > 0) {
                return ['ok' => false, 'error' => 'An open collection request already exists for this waste record.'];
            }

            $requestNo = generate_code('collection_requests', 'request_no', 'REQ', 4);
            $pdo->prepare(
                'INSERT INTO collection_requests
                 (request_no, waste_record_id, container_id, requested_by, department, location, quantity, unit, priority, status, created_at, updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?, \'pending\', datetime(\'now\'), datetime(\'now\'))'
            )->execute([
                $requestNo, $wasteRecordId, $w['container_id'], $requestedBy,
                $w['department'], $w['location'], $w['quantity'], $w['unit'], $priority,
            ]);
            $reqId = (int)$pdo->lastInsertId();

            $pdo->prepare("UPDATE waste_records SET status='requested', updated_at=datetime('now') WHERE id=?")->execute([$wasteRecordId]);
            $pdo->prepare('INSERT INTO collection_status_history (request_id, old_status, new_status, changed_by, remarks) VALUES (?,?,?,?,?)')
                ->execute([$reqId, null, 'pending', $requestedBy, 'Request created' . ($notes ? ": $notes" : '')]);

            log_audit('request_created', 'collection_request', $reqId, "Created $requestNo for waste record {$w['record_code']}");
            return ['ok' => true, 'id' => $reqId];
        });
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not create request: ' . $e->getMessage()];
    }
}
