<?php
/** Sign out and destroy the session. */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

$reason = get_str('reason');
do_logout($reason ?: 'User requested logout');

session_boot();
set_flash('success', 'You have been signed out.');
redirect(app_base_url() . '/login.php');
