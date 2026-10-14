<?php
/** Application entry point - routes the visitor to the right place. */
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

// First run: database not built yet -> send to setup.
if (!db_is_installed()) {
    redirect(app_base_url() . '/setup.php');
}

if (!is_logged_in()) {
    redirect(app_base_url() . '/login.php');
}

$user = current_user();
switch ($user['role']) {
    case 'admin':
        redirect(app_base_url() . '/admin/dashboard.php');
    case 'collector':
        redirect(app_base_url() . '/collector/dashboard.php');
    case 'staff':
    default:
        redirect(app_base_url() . '/staff/dashboard.php');
}
