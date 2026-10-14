<?php
/**
 * First-run setup.
 *
 * Builds the SQLite schema (if needed) and creates the first administrator
 * account. The administrator password is chosen here - the project never ships
 * a default administrator password. Once an administrator exists this page
 * refuses to create another one.
 */
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/database/init.php';

$base    = app_base_url();
$rootReady = file_exists(APP_SCHEMA_FILE);
$installed = db_is_installed();
$hasAdmin  = false;
if ($installed) {
    try {
        $hasAdmin = ((int)db()->query("SELECT COUNT(*) c FROM users WHERE role='admin'")->fetch()['c']) > 0;
    } catch (Throwable $e) {
        $hasAdmin = false;
    }
}

$errors = [];
$done   = false;
$form = ['full_name' => '', 'username' => '', 'email' => '', 'seed' => '1'];

if (is_post() && !$hasAdmin) {
    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Reload the page and try again.';
    } else {
        $form['full_name'] = post_str('full_name');
        $form['username']  = post_str('username');
        $form['email']     = post_str('email');
        $form['seed']      = isset($_POST['seed']) ? '1' : '0';
        $password = (string)($_POST['password'] ?? '');
        $confirm  = (string)($_POST['password_confirm'] ?? '');

        if ($form['full_name'] === '') { $errors[] = 'Full name is required.'; }
        if (!preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $form['username'])) { $errors[] = 'Username must be 3-32 characters (letters, numbers, . _ -).'; }
        if ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) { $errors[] = 'Email address is not valid.'; }
        if (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            $errors[] = 'Password must be at least 8 characters and contain letters and numbers.';
        }
        if ($password !== $confirm) { $errors[] = 'The two passwords do not match.'; }

        if (!$errors) {
            try {
                if (!db_is_installed()) {
                    init_database($form['seed'] === '1', false, false);
                }
                // Guard against a race / double submit.
                $stillNoAdmin = ((int)db()->query("SELECT COUNT(*) c FROM users WHERE role='admin'")->fetch()['c']) === 0;
                if (!$stillNoAdmin) {
                    $errors[] = 'An administrator already exists.';
                } else {
                    $stmt = db()->prepare('INSERT INTO users (username, password_hash, full_name, email, role, is_active) VALUES (?, ?, ?, ?, ?, 1)');
                    $stmt->execute([
                        $form['username'],
                        password_hash($password, PASSWORD_DEFAULT),
                        $form['full_name'],
                        $form['email'] ?: null,
                        'admin',
                    ]);
                    log_audit('admin_created', 'user', (int)db()->lastInsertId(), 'Initial administrator created during setup');
                    $done = true;
                }
            } catch (Throwable $e) {
                $errors[] = 'Setup failed: ' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>First-run setup &middot; <?= e(APP_NAME) ?></title>
<link href="<?= e($base) ?>/assets/css/bootstrap.min.css" rel="stylesheet">
<link href="<?= e($base) ?>/assets/css/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= e($base) ?>/assets/css/style.css" rel="stylesheet">
</head>
<body>
<div class="auth-wrap">
  <div class="card auth-card shadow-lg border-0" style="max-width:560px">
    <div class="card-body p-4 p-md-5">
      <div class="text-center mb-3">
        <div class="auth-brand display-5"><i class="bi bi-clipboard2-pulse"></i></div>
        <h1 class="h4 mt-2 mb-0">First-run setup</h1>
        <p class="text-muted small">Create the initial administrator account</p>
      </div>

      <?php if ($done): ?>
        <div class="alert alert-success">
          <i class="bi bi-check-circle me-1"></i> Administrator created successfully.
        </div>
        <a class="btn btn-primary w-100" href="<?= e($base) ?>/login.php">Continue to sign in</a>

      <?php elseif ($hasAdmin): ?>
        <div class="alert alert-info mb-3">
          <i class="bi bi-info-circle me-1"></i> Setup is already complete - an administrator account exists.
        </div>
        <a class="btn btn-primary w-100" href="<?= e($base) ?>/login.php">Go to sign in</a>

      <?php else: ?>
        <?php if (!$rootReady): ?>
          <div class="alert alert-danger">Schema file missing: <code>database/schema.sql</code>. Restore the project files.</div>
        <?php endif; ?>

        <?php if ($errors): ?>
          <div class="alert alert-danger"><ul class="mb-0 ps-3">
            <?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?>
          </ul></div>
        <?php endif; ?>

        <form method="post" autocomplete="off">
          <?= csrf_field() ?>
          <div class="mb-3">
            <label class="form-label">Full name</label>
            <input type="text" name="full_name" class="form-control" value="<?= e($form['full_name']) ?>" required>
          </div>
          <div class="row g-3">
            <div class="col-md-6 mb-3">
              <label class="form-label">Username</label>
              <input type="text" name="username" class="form-control" value="<?= e($form['username']) ?>" required>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Email <span class="text-muted">(optional)</span></label>
              <input type="email" name="email" class="form-control" value="<?= e($form['email']) ?>">
            </div>
          </div>
          <div class="row g-3">
            <div class="col-md-6 mb-3">
              <label class="form-label">Password</label>
              <input type="password" name="password" class="form-control" required>
              <div class="form-text">At least 8 characters with letters and numbers.</div>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Confirm password</label>
              <input type="password" name="password_confirm" class="form-control" required>
            </div>
          </div>
          <?php if (!$installed): ?>
          <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="seed" id="seed" value="1" <?= $form['seed'] === '1' ? 'checked' : '' ?>>
            <label class="form-check-label" for="seed">
              Include demonstration data (sample categories, containers, a demo staff and collector account).
            </label>
          </div>
          <?php endif; ?>
          <button class="btn btn-primary w-100 py-2" type="submit"><i class="bi bi-shield-check me-1"></i>Create administrator</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
