<?php
/**
 * Smart Medical-Waste Collection and Segregation System
 * Global configuration.
 *
 * This project uses PHP (built-in server) + SQLite so it runs with no external
 * database server. All paths are resolved relative to the project root so the
 * project can live anywhere on disk (for example on the D: drive).
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Paths
// ---------------------------------------------------------------------------
define('APP_ROOT', dirname(__DIR__));
define('APP_DATABASE_DIR', APP_ROOT . DIRECTORY_SEPARATOR . 'database');
// Allow an alternate database file (used by the automated self-test).
$mwsDbOverride = getenv('MWS_DB');
define('APP_DATABASE_FILE', $mwsDbOverride ? $mwsDbOverride : (APP_DATABASE_DIR . DIRECTORY_SEPARATOR . 'medical_waste.sqlite'));
define('APP_SCHEMA_FILE', APP_DATABASE_DIR . DIRECTORY_SEPARATOR . 'schema.sql');
define('APP_VERSION', '1.0.0');

// ---------------------------------------------------------------------------
// Application defaults (overridable at runtime through the settings table)
// ---------------------------------------------------------------------------
define('APP_NAME', 'Smart Medical-Waste System');
define('FACILITY_NAME', 'City Care Hospital');

// Session lifetime in seconds (idle timeout). Overridable via settings table.
define('SESSION_TIMEOUT_DEFAULT', 1800);

// Login throttling
define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_LOCK_MINUTES', 10);

// Device API defaults
define('DEVICE_RATE_LIMIT_PER_MINUTE', 60);

// Development vs production error display
define('APP_DEBUG', true);

if (APP_DEBUG) {
    error_reporting(E_ALL & ~E_DEPRECATED);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED);
    ini_set('display_errors', '0');
}

date_default_timezone_set('Asia/Kolkata');

// ---------------------------------------------------------------------------
// Session hardening (must run before session_start in auth.php)
// ---------------------------------------------------------------------------
if (PHP_SAPI !== 'cli') {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    // The built-in PHP dev server is normally HTTP on localhost, so "secure"
    // cookies would break it. Enable this in a real HTTPS deployment.
    ini_set('session.cookie_samesite', 'Lax');
}
