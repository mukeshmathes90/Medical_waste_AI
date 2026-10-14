<?php
/** Admin - general settings, own password and safe maintenance. */
declare(strict_types=1);

$page_title = 'Settings';
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');
require_once __DIR__ . '/../database/init.php';

$pdo = db();
$user = current_user();
$errors = [];

if (is_post()) {
    require_csrf();
    $action = post_str('action');
    try {
        if ($action === 'general') {
            set_setting('facility_name', post_str('facility_name', FACILITY_NAME));
            set_setting('public_app_url', rtrim(post_str('public_app_url'), '/'));
            set_setting('session_timeout_minutes', (string)max(5, (int)post_int('session_timeout_minutes') ?: 30));
            log_audit('settings_updated', 'settings', null, 'General settings updated');
            set_flash('success', 'Settings saved.');
            redirect(app_base_url() . '/admin/settings.php');
        } elseif ($action === 'password') {
            $current = (string)($_POST['current_password'] ?? '');
            $new = (string)($_POST['new_password'] ?? '');
            $confirm = (string)($_POST['confirm_password'] ?? '');
            $row = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
            $row->execute([$user['id']]);
            $hash = $row->fetch()['password_hash'];
            if (!password_verify($current, $hash)) { $errors[] = 'Current password is incorrect.'; }
            if (strlen($new) < 8 || !preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) { $errors[] = 'New password must be 8+ chars with letters and numbers.'; }
            if ($new !== $confirm) { $errors[] = 'New passwords do not match.'; }
            if (!$errors) {
                $pdo->prepare('UPDATE users SET password_hash = ?, updated_at = datetime(\'now\') WHERE id = ?')
                    ->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
                log_audit('password_changed', 'user', (int)$user['id'], 'Admin changed own password');
                set_flash('success', 'Password changed.');
                redirect(app_base_url() . '/admin/settings.php');
            }
        } elseif ($action === 'seed') {
            init_database(false, false, false);
            log_audit('settings_seeded', 'settings', null, 'Re-ran initialisation');
            set_flash('success', 'Initialisation re-run (missing defaults seeded).');
            redirect(app_base_url() . '/admin/settings.php');
        }
    } catch (Throwable $e) {
        $errors[] = 'Operation failed: ' . $e->getMessage();
    }
}
foreach ($errors as $er) { set_flash('danger', $er); }

$dbSize = file_exists(APP_DATABASE_FILE) ? round(filesize(APP_DATABASE_FILE) / 1024, 1) : 0;
$counts = [
    'users' => (int)$pdo->query('SELECT COUNT(*) c FROM users')->fetch()['c'],
    'waste_records' => (int)$pdo->query('SELECT COUNT(*) c FROM waste_records')->fetch()['c'],
    'containers' => (int)$pdo->query('SELECT COUNT(*) c FROM containers')->fetch()['c'],
    'requests' => (int)$pdo->query('SELECT COUNT(*) c FROM collection_requests')->fetch()['c'],
];

include __DIR__ . '/../includes/header.php';
?>
<div class="page-title mb-3"><i class="bi bi-gear me-2"></i>Settings</div>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card mb-3">
      <div class="card-header">General</div>
      <div class="card-body">
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="action" value="general">
          <div class="mb-3"><label class="form-label">Facility name</label><input name="facility_name" class="form-control" value="<?= e(setting('facility_name', FACILITY_NAME)) ?>"></div>
          <div class="mb-3"><label class="form-label">Public app URL (for QR links)</label><input name="public_app_url" class="form-control" value="<?= e(setting('public_app_url', '')) ?>" placeholder="leave blank to auto-detect">
            <div class="form-text">Set this if QR codes must be scannable from another device (e.g. http://192.168.1.5:8090).</div></div>
          <div class="mb-3"><label class="form-label">Session idle timeout (minutes)</label><input type="number" min="5" name="session_timeout_minutes" class="form-control" value="<?= e(setting('session_timeout_minutes', '30')) ?>"></div>
          <button class="btn btn-primary">Save</button>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header">Change my password</div>
      <div class="card-body">
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="action" value="password">
          <div class="mb-3"><label class="form-label">Current password</label><input type="password" name="current_password" class="form-control" required></div>
          <div class="mb-3"><label class="form-label">New password</label><input type="password" name="new_password" class="form-control" required></div>
          <div class="mb-3"><label class="form-label">Confirm new password</label><input type="password" name="confirm_password" class="form-control" required></div>
          <button class="btn btn-primary">Update password</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card mb-3">
      <div class="card-header">Database</div>
      <div class="card-body">
        <dl class="row mb-3 small">
          <dt class="col-6">Engine</dt><dd class="col-6">SQLite (PDO)</dd>
          <dt class="col-6">File</dt><dd class="col-6 text-mono small"><?= e(basename(APP_DATABASE_FILE)) ?></dd>
          <dt class="col-6">Size</dt><dd class="col-6"><?= e($dbSize) ?> KB</dd>
          <dt class="col-6">Users / Records</dt><dd class="col-6"><?= $counts['users'] ?> / <?= $counts['waste_records'] ?></dd>
          <dt class="col-6">Containers / Requests</dt><dd class="col-6"><?= $counts['containers'] ?> / <?= $counts['requests'] ?></dd>
        </dl>
        <form method="post" data-confirm="Re-run initialisation? This only seeds missing default data.">
          <?= csrf_field() ?><input type="hidden" name="action" value="seed">
          <button class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-repeat"></i> Re-run initialisation (seed defaults)</button>
        </form>
        <p class="small text-muted mt-2 mb-0">To fully reset, stop the server and delete <code>database/medical_waste.sqlite</code>, then reopen the app to run first-run setup again.</p>
      </div>
    </div>

    <div class="card">
      <div class="card-header">Security notes</div>
      <div class="card-body small">
        <ul class="mb-0">
          <li>Passwords are hashed with PHP <code>password_hash()</code>.</li>
          <li>Sessions regenerate on login and expire when idle.</li>
          <li>All state-changing forms are CSRF protected.</li>
          <li>All queries use prepared statements.</li>
          <li>Device tokens are stored hashed and can be rotated or revoked.</li>
        </ul>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
