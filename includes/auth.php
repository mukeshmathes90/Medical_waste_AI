<?php
/**
 * Authentication, session management and role-based access control.
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

/** Start the session once with hardened cookie parameters. */
function session_boot(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }
    $params = [
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ];
    session_set_cookie_params($params);
    session_start();
}
session_boot();

function is_logged_in(): bool
{
    return !empty($_SESSION['user']['id']);
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function has_role(string ...$roles): bool
{
    $user = current_user();
    return $user && in_array($user['role'], $roles, true);
}

/** Re-validate the user against the database and apply idle timeout. */
function refresh_user(): void
{
    if (!is_logged_in()) {
        return;
    }
    $timeout = (int)(setting('session_timeout_minutes', '30')) * 60;
    if ($timeout > 0 && isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
        do_logout('timeout');
        return;
    }
    $_SESSION['last_activity'] = time();

    // Ensure the account is still active (e.g. admin deactivated it mid-session).
    $stmt = db()->prepare('SELECT id, username, full_name, role, department, is_active FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user']['id']]);
    $row = $stmt->fetch();
    if (!$row || (int)$row['is_active'] !== 1) {
        do_logout('disabled');
        return;
    }
    $_SESSION['user'] = [
        'id'         => (int)$row['id'],
        'username'   => $row['username'],
        'full_name'  => $row['full_name'],
        'role'       => $row['role'],
        'department' => $row['department'],
    ];
}

function require_login(): void
{
    if (!is_logged_in()) {
        set_flash('warning', 'Please sign in to continue.');
        redirect(app_base_url() . '/login.php');
    }
    refresh_user();
    if (!is_logged_in()) {
        redirect(app_base_url() . '/login.php');
    }
}

function require_role(string ...$roles): void
{
    require_login();
    if (!has_role(...$roles)) {
        http_response_code(403);
        log_audit('access_denied', 'page', null, 'Blocked access to ' . ($_SERVER['REQUEST_URI'] ?? ''));
        include __DIR__ . '/error_403.php';
        exit;
    }
}

/** Build the session for an authenticated user id. */
function establish_session(array $user): void
{
    if (!headers_sent()) {
        session_regenerate_id(true);
    }
    $_SESSION['user'] = [
        'id'         => (int)$user['id'],
        'username'   => $user['username'],
        'full_name'  => $user['full_name'],
        'role'       => $user['role'],
        'department' => $user['department'],
    ];
    $_SESSION['last_activity'] = time();
    unset($_SESSION['csrf_token']);
    csrf_token();
}

// ---------------------------------------------------------------------------
// Login throttling
// ---------------------------------------------------------------------------

function recent_failed_attempts(string $username, string $ip): int
{
    $since = date('Y-m-d H:i:s', time() - LOGIN_LOCK_MINUTES * 60);
    $stmt = db()->prepare(
        'SELECT COUNT(*) c FROM login_attempts WHERE success = 0 AND created_at > ? AND (username = ? OR ip_address = ?)'
    );
    $stmt->execute([$since, $username, $ip]);
    return (int)$stmt->fetch()['c'];
}

function record_login_attempt(string $username, string $ip, bool $success): void
{
    db()->prepare('INSERT INTO login_attempts (username, ip_address, success) VALUES (?, ?, ?)')
        ->execute([$username, $ip, $success ? 1 : 0]);

    // Light housekeeping so the table does not grow forever.
    if (random_int(1, 20) === 1) {
        db()->exec("DELETE FROM login_attempts WHERE created_at < datetime('now', '-7 days')");
    }
}

/**
 * Attempt to log a user in.
 * @return array{ok:bool,error?:string,user?:array}
 */
function attempt_login(string $username, string $password): array
{
    $ip = client_ip();
    $username = trim($username);

    if ($username === '' || $password === '') {
        return ['ok' => false, 'error' => 'Please enter both username and password.'];
    }

    if (recent_failed_attempts($username, $ip) >= LOGIN_MAX_ATTEMPTS) {
        return ['ok' => false, 'error' => 'Too many failed attempts. Please wait ' . LOGIN_LOCK_MINUTES . ' minutes and try again.'];
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    $hash = $user['password_hash'] ?? '$2y$10$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidinv';
    $passwordOk = password_verify($password, $hash);

    if (!$user || !$passwordOk) {
        record_login_attempt($username, $ip, false);
        if ($user) {
            db()->prepare('UPDATE users SET failed_logins = failed_logins + 1 WHERE id = ?')->execute([$user['id']]);
        }
        log_audit('login_failed', 'user', $user['id'] ?? null, 'Failed login for ' . $username);
        return ['ok' => false, 'error' => 'Invalid username or password.'];
    }

    if ((int)$user['is_active'] !== 1) {
        record_login_attempt($username, $ip, false);
        return ['ok' => false, 'error' => 'This account has been deactivated. Contact an administrator.'];
    }

    // Re-hash if the algorithm cost changed.
    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }

    db()->prepare('UPDATE users SET failed_logins = 0, last_login = datetime(\'now\') WHERE id = ?')->execute([$user['id']]);
    record_login_attempt($username, $ip, true);

    establish_session($user);
    log_audit('login_success', 'user', $user['id'], 'User ' . $username . ' logged in');

    return ['ok' => true, 'user' => $user];
}

function do_logout(string $reason = ''): void
{
    if (is_logged_in()) {
        log_audit('logout', 'user', $_SESSION['user']['id'] ?? null, $reason ?: 'User logged out');
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => 'Lax',
        ]);
    }
    session_destroy();
}
