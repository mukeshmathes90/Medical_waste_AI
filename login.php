<?php
/** Secure sign-in page. */
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

if (!db_is_installed()) {
    redirect(app_base_url() . '/setup.php');
}
if (is_logged_in()) {
    redirect(app_base_url() . '/index.php');
}

$base  = app_base_url();
$error = '';
$username = '';
$demoUser = '';

if (is_post()) {
    if (!csrf_verify()) {
        $error = 'Your session expired. Please try again.';
    } else {
        $username = post_str('username');
        $password = (string)($_POST['password'] ?? '');
        $result = attempt_login($username, $password);
        if ($result['ok']) {
            set_flash('success', 'Welcome back, ' . $result['user']['full_name'] . '!');
            redirect($base . '/index.php');
        }
        $error = $result['error'] ?? 'Sign-in failed.';
    }
}

// Convenience: pre-fill demo credentials when demo data is enabled.
if (setting('demo_data', '0') === '1') {
    $demoUser = 'staff / Staff@123  or  collector / Collector@123';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in &middot; <?= e(APP_NAME) ?></title>
<link href="<?= e($base) ?>/assets/css/bootstrap.min.css" rel="stylesheet">
<link href="<?= e($base) ?>/assets/css/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= e($base) ?>/assets/css/style.css" rel="stylesheet">
</head>
<body>
<div class="auth-wrap">
  <div class="card auth-card shadow-lg border-0">
    <div class="card-body p-4 p-md-5">
      <div class="text-center mb-4">
        <div class="auth-brand display-5"><i class="bi bi-clipboard2-pulse"></i></div>
        <h1 class="h4 mt-2 mb-0"><?= e(APP_NAME) ?></h1>
        <p class="text-muted small mb-0"><?= e(setting('facility_name', FACILITY_NAME)) ?></p>
      </div>

      <?php if ($error): ?>
        <div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle me-1"></i><?= e($error) ?></div>
      <?php endif; ?>

      <form method="post" novalidate autocomplete="off">
        <?= csrf_field() ?>
        <div class="mb-3">
          <label class="form-label">Username</label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-person"></i></span>
            <input type="text" name="username" class="form-control" value="<?= e($username) ?>" required autofocus>
          </div>
        </div>
        <div class="mb-4">
          <label class="form-label">Password</label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-lock"></i></span>
            <input type="password" name="password" class="form-control" required>
          </div>
        </div>
        <button class="btn btn-primary w-100 py-2" type="submit"><i class="bi bi-box-arrow-in-right me-1"></i>Sign in</button>
      </form>

      <?php if ($demoUser): ?>
      <div class="alert alert-info mt-4 mb-0 small">
        <strong>Demo accounts:</strong><br><?= e($demoUser) ?><br>
        <span class="text-muted">(created by the demo data seeder)</span>
      </div>
      <?php endif; ?>
    </div>
    <div class="card-footer text-center small text-muted bg-white py-3">
      <?= e(APP_NAME) ?> v<?= e(APP_VERSION) ?>
    </div>
  </div>
</div>
</body>
</html>
