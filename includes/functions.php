<?php
/**
 * Shared helper functions.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

// ---------------------------------------------------------------------------
// Output / escaping
// ---------------------------------------------------------------------------

/** Escape a value for safe HTML output. */
function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Emit JSON and stop. */
function json_out(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------------------------------------------------------
// Navigation / URLs
// ---------------------------------------------------------------------------

/** Detect the application base URL from the current request. */
function app_base_url(): string
{
    $override = setting('public_app_url', '');
    if ($override !== '') {
        return rtrim($override, '/');
    }
    if (PHP_SAPI === 'cli') {
        return 'http://localhost:8090';
    }
    $scheme = 'http';
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $scheme = 'https';
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $scheme = $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https' ? 'https' : 'http';
    }
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_ADDR'] ?? 'localhost');
    return $scheme . '://' . $host;
}

function redirect(string $path): void
{
    $path = preg_replace('/[\r\n]+/', '', $path);
    header('Location: ' . $path);
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

// ---------------------------------------------------------------------------
// Settings (persisted in the settings table)
// ---------------------------------------------------------------------------

function setting(string $key, $default = null)
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db()->query('SELECT key, value FROM settings') as $row) {
                $cache[$row['key']] = $row['value'];
            }
        } catch (Throwable $e) {
            $cache = [];
        }
    }
    return $cache[$key] ?? $default;
}

function set_setting(string $key, $value): void
{
    $stmt = db()->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $stmt->execute([$key, (string)$value]);
    // refresh static cache within this request
    $refl = new ReflectionFunction('setting');
    // simplest: settings cache is per-request; callers that need fresh values should call reload
}

// ---------------------------------------------------------------------------
// Flash messages
// ---------------------------------------------------------------------------

function set_flash(string $type, string $message): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function get_flash(): array
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

// ---------------------------------------------------------------------------
// CSRF
// ---------------------------------------------------------------------------

function csrf_token(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** Validate a CSRF token from POST or a request header. Returns bool. */
function csrf_verify(?string $token = null): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $token = $token ?? ($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if (!is_string($token) || $token === '' || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/** Abort the request when CSRF validation fails. */
function require_csrf(): void
{
    if (!csrf_verify()) {
        http_response_code(419);
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
            json_out(['ok' => false, 'error' => 'Invalid or expired CSRF token.'], 419);
        }
        echo '<h1>Session expired</h1><p>Your form token is invalid or has expired. Please go back and try again.</p>';
        exit;
    }
}

// ---------------------------------------------------------------------------
// Audit log
// ---------------------------------------------------------------------------

function log_audit(string $action, ?string $entity = null, ?int $entityId = null, string $details = ''): void
{
    try {
        $uid = $_SESSION['user']['id'] ?? null;
        $stmt = db()->prepare('INSERT INTO audit_logs (user_id, action, entity, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$uid, $action, $entity, $entityId, $details, client_ip()]);
    } catch (Throwable $e) {
        // Audit logging must never break the main flow.
    }
}

function log_rfid(?int $deviceId, ?int $containerId, ?string $tagUid, ?int $requestId, ?int $collectorId, string $result, string $message): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO rfid_verification_logs (device_id, container_id, tag_uid, request_id, collector_id, result, message, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$deviceId, $containerId, $tagUid, $requestId, $collectorId, $result, $message, client_ip()]);
    } catch (Throwable $e) {
        // ignore
    }
}

// ---------------------------------------------------------------------------
// Codes
// ---------------------------------------------------------------------------

/** Generate a sequential, human-friendly code like WR-2026-0007. */
function generate_code(string $table, string $column, string $prefix, int $pad = 4): string
{
    $year = date('Y');
    $like = $prefix . '-' . $year . '-%';
    $stmt = db()->prepare("SELECT $column FROM $table WHERE $column LIKE ? ORDER BY $column DESC LIMIT 1");
    $stmt->execute([$like]);
    $last = $stmt->fetchColumn();
    $next = 1;
    if ($last) {
        $parts = explode('-', (string)$last);
        $next = (int)end($parts) + 1;
    }
    return $prefix . '-' . $year . '-' . str_pad((string)$next, $pad, '0', STR_PAD_LEFT);
}

// ---------------------------------------------------------------------------
// Formatting helpers
// ---------------------------------------------------------------------------

function format_dt(?string $value, string $format = 'd M Y, H:i'): string
{
    if (!$value) {
        return '-';
    }
    $ts = strtotime($value);
    return $ts ? date($format, $ts) : '-';
}

function status_badge(string $status): string
{
    $map = [
        'pending'     => 'secondary',
        'approved'    => 'info',
        'assigned'    => 'primary',
        'in_progress' => 'warning',
        'collected'   => 'success',
        'completed'   => 'success',
        'cancelled'   => 'dark',
        'rejected'    => 'danger',
        'disposed'    => 'success',
        'requested'   => 'info',
        'active'      => 'success',
        'inactive'    => 'secondary',
        'full'        => 'danger',
        'maintenance' => 'warning',
    ];
    $cls = $map[$status] ?? 'secondary';
    $label = ucwords(str_replace('_', ' ', $status));
    return '<span class="badge bg-' . $cls . '">' . e($label) . '</span>';
}

function priority_badge(string $priority): string
{
    $map = ['low' => 'secondary', 'normal' => 'info', 'high' => 'warning', 'urgent' => 'danger'];
    $cls = $map[$priority] ?? 'secondary';
    return '<span class="badge bg-' . $cls . '">' . e(ucfirst($priority)) . '</span>';
}

function role_badge(string $role): string
{
    $map = ['admin' => 'danger', 'staff' => 'primary', 'collector' => 'success'];
    return '<span class="badge bg-' . ($map[$role] ?? 'secondary') . '">' . e(ucfirst($role)) . '</span>';
}

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------

function post_str(string $key, string $default = ''): string
{
    return trim((string)($_POST[$key] ?? $default));
}

function post_float(string $key): ?float
{
    if (!isset($_POST[$key]) || $_POST[$key] === '') {
        return null;
    }
    return is_numeric($_POST[$key]) ? (float)$_POST[$key] : null;
}

function post_int(string $key): ?int
{
    if (!isset($_POST[$key]) || $_POST[$key] === '') {
        return null;
    }
    return (int)$_POST[$key];
}

function get_str(string $key, string $default = ''): string
{
    return trim((string)($_GET[$key] ?? $default));
}

// ---------------------------------------------------------------------------
// Pagination
// ---------------------------------------------------------------------------

function current_page(): int
{
    $p = (int)($_GET['page'] ?? 1);
    return $p < 1 ? 1 : $p;
}

function pagination_links(int $total, int $perPage, int $page, array $query = []): string
{
    $pages = (int)ceil($total / max(1, $perPage));
    if ($pages <= 1) {
        return '';
    }
    $html = '<nav><ul class="pagination pagination-sm mb-0">';
    for ($i = 1; $i <= $pages; $i++) {
        $query['page'] = $i;
        $url = '?' . http_build_query($query);
        $active = $i === $page ? ' active' : '';
        $html .= '<li class="page-item' . $active . '"><a class="page-link" href="' . e($url) . '">' . $i . '</a></li>';
    }
    $html .= '</ul></nav>';
    return $html;
}
