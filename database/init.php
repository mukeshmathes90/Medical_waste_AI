<?php
/**
 * Database initialiser.
 *
 * Creates the SQLite schema and inserts default settings plus clearly-labelled
 * DEMO data (categories, containers, one staff and one collector account and a
 * demo hardware device). It deliberately does NOT create an administrator;
 * create the first administrator through setup.php (or the CLI flag below).
 *
 * CLI:   php database/init.php [--force] [--demo=0|1] [--admin-user=NAME --admin-pass=PASS]
 * Web:   open  /setup.php  (recommended for first run)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

function init_database(bool $seedDemo = true, bool $force = false, bool $verbose = false): array
{
    $log = [];
    $note = function (string $m) use (&$log, $verbose) {
        $log[] = $m;
        if ($verbose) {
            echo $m . PHP_EOL;
        }
    };

    if (file_exists(APP_DATABASE_FILE) && !$force && db_is_installed()) {
        $note('Database already initialised. Use --force to rebuild.');
        return $log;
    }

    $pdo = db();

    $schema = file_get_contents(APP_SCHEMA_FILE);
    if ($schema === false) {
        throw new RuntimeException('Schema file not found: ' . APP_SCHEMA_FILE);
    }
    $pdo->exec($schema);
    $note('Schema created.');

    // ---- Default settings -------------------------------------------------
    $defaults = [
        'facility_name'              => 'City Care Hospital',
        'hardware_enabled'           => '0',
        'device_api_enabled'         => '1',
        'require_rfid_verification'  => '1',
        'require_qr_verification'    => '1',
        'session_timeout_minutes'    => '30',
        'api_base_url'               => '',
        'public_app_url'             => '',
        'demo_data'                  => $seedDemo ? '1' : '0',
    ];
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)');
    foreach ($defaults as $k => $v) {
        $stmt->execute([$k, $v]);
    }
    $note('Default settings inserted.');

    // ---- Waste categories (facility segregation rules) --------------------
    $categories = [
        ['Infectious Waste', 'INF', 'Waste contaminated with blood, body fluids or pathogens (dressings, swabs, used PPE).',
            'Collect in a labelled yellow bag/bin. Never compress. Tie securely before transport.',
            '#f0ad4e', 'yellow', 1],
        ['Sharps', 'SHP', 'Needles, syringes, scalpels, blades and broken glass that can puncture skin.',
            'Use a puncture-proof sharps container only. Never recap needles. Never mix with soft waste.',
            '#d9534f', 'sharps', 1],
        ['Contaminated Recyclable Plastics', 'CRP', 'Plastic bottles, tubing and packaging contaminated with body fluids.',
            'Collect in a labelled red bin. Decontaminate per facility SOP before recycling.',
            '#5bc0de', 'red', 1],
        ['General Non-Hazardous Waste', 'GEN', 'Non-contaminated, non-hazardous waste such as paper and clean packaging.',
            'Collect in a black/green bin. Never mix with infectious or sharp items.',
            '#5cb85c', 'general', 0],
        ['Pharmaceutical Waste', 'PHA', 'Expired, unused or contaminated medicines and vaccines.',
            'Collect in a labelled bin. Return controlled substances to pharmacy per regulation.',
            '#9b59b6', 'yellow', 1],
        ['Pathological Waste', 'PAT', 'Human tissue, organs and anatomical waste.',
            'Collect in a labelled yellow bin. Handle per biohazard regulation.',
            '#c0392b', 'yellow', 1],
        ['Chemical Waste', 'CHM', 'Disinfectants, solvents and reagents from diagnostic activity.',
            'Collect separately in a chemical-resistant container. Never mix with other streams.',
            '#34495e', 'chemical', 1],
    ];
    $catStmt = $pdo->prepare(
        'INSERT OR IGNORE INTO waste_categories (name, code, description, handling_guidance, color, container_type, is_hazardous)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($categories as $c) {
        $catStmt->execute($c);
    }
    $note('Waste categories inserted.');

    // ---- Sample containers + RFID tags -----------------------------------
    $catId = [];
    foreach ($pdo->query('SELECT id, code FROM waste_categories') as $row) {
        $catId[$row['code']] = (int)$row['id'];
    }

    $containers = [
        ['MWC-0001', 'Yellow bin - Ward A',   'INF', 'General Ward', 'Ward A corridor',        30, 12],
        ['MWC-0002', 'Sharps box - Ward A',   'SHP', 'General Ward', 'Ward A treatment room',   5,  2],
        ['MWC-0003', 'Red bin - ICU',         'CRP', 'ICU',          'ICU utility room',       25, 20],
        ['MWC-0004', 'Black bin - OPD',       'GEN', 'OPD',          'OPD back corridor',      40, 15],
        ['MWC-0005', 'Yellow bin - Pharmacy', 'PHA', 'Pharmacy',     'Pharmacy store',         20,  8],
    ];
    $contStmt = $pdo->prepare(
        'INSERT OR IGNORE INTO containers (container_code, label, category_id, department, location, capacity, fill_level, status, qr_token)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $tagStmt = $pdo->prepare('INSERT OR IGNORE INTO rfid_tags (tag_uid, container_id, label, notes) VALUES (?, ?, ?, ?)');
    foreach ($containers as $c) {
        $token = bin2hex(random_bytes(12));
        $contStmt->execute([$c[0], $c[1], $catId[$c[2]] ?? null, $c[3], $c[4], $c[5], $c[6], 'active', $token]);
        $cid = (int)$pdo->lastInsertId();
        if ($cid) {
            $uid = 'A1B2C3' . str_pad((string)$cid, 2, '0', STR_PAD_LEFT);
            $tagStmt->execute([$uid, $cid, 'Tag for ' . $c[0], 'Demo RFID tag seeded by init.php']);
        }
    }
    $note('Sample containers and RFID tags inserted.');

    // ---- Demo users (clearly labelled) -----------------------------------
    if ($seedDemo) {
        $demoUsers = [
            ['staff', 'Staff@123', 'Demo Staff (Ward Nurse)', 'staff', 'General Ward', 'staff@example.com'],
            ['collector', 'Collector@123', 'Demo Collector', 'collector', 'Housekeeping', 'collector@example.com'],
        ];
        $uStmt = $pdo->prepare(
            'INSERT OR IGNORE INTO users (username, password_hash, full_name, email, role, department, is_active)
             VALUES (?, ?, ?, ?, ?, ?, 1)'
        );
        foreach ($demoUsers as $u) {
            $uStmt->execute([$u[0], password_hash($u[1], PASSWORD_DEFAULT), $u[2], $u[5], $u[3], $u[4]]);
        }
        $note('Demo staff and collector accounts inserted (staff/Staff@123, collector/Collector@123).');

        // ---- Demo device (token documented as demo-only) -----------------
        $devStmt = $pdo->prepare('INSERT OR IGNORE INTO devices (device_code, name, api_token_hash, location, is_active) VALUES (?, ?, ?, ?, 1)');
        $devStmt->execute(['ESP32-01', 'Demo ESP32 RFID Reader', password_hash('demo-device-token-123', PASSWORD_DEFAULT), 'Housekeeping']);
        $note('Demo device inserted (code ESP32-01, token demo-device-token-123).');
    }

    // ---- Sample waste records + one open request (demo) ------------------
    if ($seedDemo) {
        $staffRow = $pdo->query("SELECT id FROM users WHERE username='staff'")->fetch();
        $contRow  = $pdo->query("SELECT id, category_id, department, location FROM containers WHERE container_code='MWC-0001'")->fetch();
        if ($staffRow && $contRow) {
            $wr = $pdo->prepare(
                'INSERT INTO waste_records (record_code, category_id, container_id, quantity, unit, department, location, priority, status, notes, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $wr->execute(['WR-' . date('Y') . '-0001', $contRow['category_id'], $contRow['id'], 3.5, 'kg',
                $contRow['department'], $contRow['location'], 'high', 'pending', 'Demo infectious waste entry.', $staffRow['id']]);
            $note('Demo waste record inserted.');
        }
    }

    $note('Database initialisation complete.');
    return $log;
}

// ---------------------------------------------------------------------------
// Standalone CLI execution
// ---------------------------------------------------------------------------
if (PHP_SAPI === 'cli' && isset($argv) && realpath($argv[0]) === realpath(__FILE__)) {
    $force = in_array('--force', $argv, true);
    $seed  = true;
    $adminUser = null;
    $adminPass = null;
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--demo=')) {
            $seed = ($arg === '--demo=1');
        }
        if (str_starts_with($arg, '--admin-user=')) {
            $adminUser = substr($arg, strlen('--admin-user='));
        }
        if (str_starts_with($arg, '--admin-pass=')) {
            $adminPass = substr($arg, strlen('--admin-pass='));
        }
    }
    try {
        init_database($seed, $force, true);
        if ($adminUser && $adminPass) {
            $pdo = db();
            $exists = $pdo->query("SELECT COUNT(*) c FROM users WHERE role='admin'")->fetch()['c'];
            if ($exists > 0) {
                echo "An administrator already exists; skipping admin creation.\n";
            } else {
                $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role, is_active) VALUES (?, ?, ?, ?, 1)')
                    ->execute([$adminUser, password_hash($adminPass, PASSWORD_DEFAULT), 'System Administrator', 'admin']);
                echo "Administrator '$adminUser' created.\n";
            }
        }
    } catch (Throwable $e) {
        fwrite(STDERR, 'Init failed: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
}
