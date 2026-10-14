<?php
/**
 * Database layer - PDO connection to SQLite.
 *
 * The whole application shares a single PDO connection (per request) with
 * foreign-key enforcement switched on. Every query in the application uses
 * prepared statements.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Returns the shared PDO connection, creating it on first use.
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!is_dir(APP_DATABASE_DIR)) {
        if (!@mkdir(APP_DATABASE_DIR, 0775, true) && !is_dir(APP_DATABASE_DIR)) {
            throw new RuntimeException('Cannot create database directory: ' . APP_DATABASE_DIR);
        }
    }

    $isNew = !file_exists(APP_DATABASE_FILE);

    try {
        $pdo = new PDO('sqlite:' . APP_DATABASE_FILE, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        // Never leak credentials or paths in the message shown to users.
        http_response_code(500);
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, 'Database connection failed: ' . $e->getMessage() . PHP_EOL);
            exit(1);
        }
        echo '<h1>Database unavailable</h1><p>The application could not open its database. '
           . 'Run <code>scripts\setup_check.bat</code> to diagnose the problem.</p>';
        exit;
    }

    // Enforce relational integrity (off by default in SQLite).
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA synchronous = NORMAL');

    return $pdo;
}

/**
 * True when the database file exists and the core tables are present.
 */
function db_is_installed(): bool
{
    if (!file_exists(APP_DATABASE_FILE)) {
        return false;
    }
    try {
        $row = db()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetch();
        return (bool)$row;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Run a callback inside a SQLite transaction.
 */
function db_transaction(callable $fn)
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $result = $fn($pdo);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
