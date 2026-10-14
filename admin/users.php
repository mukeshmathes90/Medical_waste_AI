<?php
/** Admin - manage staff, collector and admin accounts. */
declare(strict_types=1);

$page_title = 'User Management';
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$pdo = db();
$editUser = null;
$errors = [];

// ---- Handle actions -------------------------------------------------------
if (is_post()) {
    require_csrf();
    $action = post_str('action');
    try {
        if ($action === 'create' || $action === 'update') {
            $id        = post_int('id');
            $username  = post_str('username');
            $fullName  = post_str('full_name');
            $email     = post_str('email');
            $phone     = post_str('phone');
            $role      = post_str('role');
            $department= post_str('department');
            $isActive  = isset($_POST['is_active']) ? 1 : 0;
            $password  = (string)($_POST['password'] ?? '');

            if (!preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $username)) { $errors[] = 'Username must be 3-32 chars (letters, numbers, . _ -).'; }
            if ($fullName === '') { $errors[] = 'Full name is required.'; }
            if (!in_array($role, ['admin','staff','collector'], true)) { $errors[] = 'Invalid role.'; }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = 'Invalid email address.'; }

            if ($action === 'create') {
                if (strlen($password) < 8) { $errors[] = 'Password must be at least 8 characters.'; }
                $exists = $pdo->prepare('SELECT id FROM users WHERE username = ?');
                $exists->execute([$username]);
                if ($exists->fetch()) { $errors[] = 'That username is already taken.'; }
                if (!$errors) {
                    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, email, phone, role, department, is_active)
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                        ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $fullName, $email ?: null, $phone ?: null, $role, $department ?: null, $isActive]);
                    log_audit('user_created', 'user', (int)$pdo->lastInsertId(), "Created $role '$username'");
                    set_flash('success', "User '$username' created.");
                    redirect(app_base_url() . '/admin/users.php');
                }
            } else { // update
                $u = $pdo->prepare('SELECT * FROM users WHERE id = ?');
                $u->execute([$id]);
                $existing = $u->fetch();
                if (!$existing) { $errors[] = 'User not found.'; }
                // Prevent locking out the last active admin.
                if (!$errors && $existing['role'] === 'admin' && ($role !== 'admin' || $isActive === 0)) {
                    $admins = (int)$pdo->query("SELECT COUNT(*) c FROM users WHERE role='admin' AND is_active=1")->fetch()['c'];
                    if ($admins <= 1) { $errors[] = 'Cannot remove or deactivate the last active administrator.'; }
                }
                if (!$errors && $id === (int)current_user()['id'] && $isActive === 0) {
                    $errors[] = 'You cannot deactivate your own account.';
                }
                $dup = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id <> ?');
                $dup->execute([$username, $id]);
                if ($dup->fetch()) { $errors[] = 'That username is already taken.'; }
                if (!$errors) {
                    $pdo->prepare('UPDATE users SET username=?, full_name=?, email=?, phone=?, role=?, department=?, is_active=?, updated_at=datetime(\'now\') WHERE id=?')
                        ->execute([$username, $fullName, $email ?: null, $phone ?: null, $role, $department ?: null, $isActive, $id]);
                    if (strlen($password) >= 8) {
                        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
                    }
                    log_audit('user_updated', 'user', $id, "Updated $role '$username'");
                    set_flash('success', "User '$username' updated.");
                    redirect(app_base_url() . '/admin/users.php');
                }
            }
        } elseif ($action === 'toggle') {
            $id = post_int('id');
            $u = $pdo->prepare('SELECT * FROM users WHERE id = ?');
            $u->execute([$id]);
            $target = $u->fetch();
            if ($target && $id === (int)current_user()['id']) {
                set_flash('danger', 'You cannot deactivate your own account.');
            } elseif ($target) {
                if ($target['role'] === 'admin' && (int)$target['is_active'] === 1) {
                    $admins = (int)$pdo->query("SELECT COUNT(*) c FROM users WHERE role='admin' AND is_active=1")->fetch()['c'];
                    if ($admins <= 1) { set_flash('danger', 'Cannot deactivate the last active administrator.'); redirect(app_base_url().'/admin/users.php'); }
                }
                $new = (int)$target['is_active'] === 1 ? 0 : 1;
                $pdo->prepare('UPDATE users SET is_active=?, updated_at=datetime(\'now\') WHERE id=?')->execute([$new, $id]);
                log_audit('user_toggled', 'user', $id, ($new ? 'Activated' : 'Deactivated') . " user {$target['username']}");
                set_flash('success', "User {$target['username']} " . ($new ? 'activated' : 'deactivated') . '.');
            }
            redirect(app_base_url() . '/admin/users.php');
        }
    } catch (Throwable $e) {
        $errors[] = 'Operation failed: ' . $e->getMessage();
    }
}

if ($errors) { foreach ($errors as $er) { set_flash('danger', $er); } }

if (get_str('edit')) {
    $s = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $s->execute([(int)get_str('edit')]);
    $editUser = $s->fetch() ?: null;
}

$filterRole = get_str('role');
$search = get_str('q');
$where = []; $args = [];
if (in_array($filterRole, ['admin','staff','collector'], true)) { $where[] = 'role = ?'; $args[] = $filterRole; }
if ($search !== '') { $where[] = '(username LIKE ? OR full_name LIKE ? OR email LIKE ?)'; $args[] = "%$search%"; $args[] = "%$search%"; $args[] = "%$search%"; }
$sql = 'SELECT * FROM users' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY role, username';
$stmt = $pdo->prepare($sql); $stmt->execute($args);
$users = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div class="page-title"><i class="bi bi-people me-2"></i>User Management</div>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#userModal"><i class="bi bi-plus-lg"></i> Add User</button>
</div>

<form class="row g-2 mb-3" method="get">
  <div class="col-md-4"><input type="text" name="q" class="form-control form-control-sm" placeholder="Search name / username / email" value="<?= e($search) ?>"></div>
  <div class="col-md-3">
    <select name="role" class="form-select form-select-sm">
      <option value="">All roles</option>
      <?php foreach (['admin','staff','collector'] as $r): ?>
        <option value="<?= $r ?>" <?= $filterRole === $r ? 'selected' : '' ?>><?= ucfirst($r) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-2"><button class="btn btn-outline-secondary btn-sm w-100">Filter</button></div>
</form>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Department</th><th>Status</th><th>Last login</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php if (!$users): ?>
        <tr><td colspan="7"><div class="empty-state"><i class="bi bi-people"></i>No users found.</div></td></tr>
      <?php else: foreach ($users as $u): ?>
        <tr>
          <td><?= e($u['full_name']) ?><div class="text-muted small"><?= e($u['email'] ?? '') ?></div></td>
          <td class="text-mono"><?= e($u['username']) ?></td>
          <td><?= role_badge($u['role']) ?></td>
          <td><?= e($u['department'] ?? '-') ?></td>
          <td><?= (int)$u['is_active'] === 1 ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' ?></td>
          <td class="text-muted small"><?= e(format_dt($u['last_login'])) ?></td>
          <td class="text-end text-nowrap">
            <a class="btn btn-sm btn-outline-primary" href="?edit=<?= (int)$u['id'] ?>"><i class="bi bi-pencil"></i></a>
            <form method="post" class="d-inline" data-confirm="Toggle active status for <?= e($u['username']) ?>?">
              <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
              <button class="btn btn-sm btn-outline-<?= (int)$u['is_active'] === 1 ? 'warning' : 'success' ?>"><i class="bi bi-power"></i></button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Create / edit modal (auto-open when editing) -->
<div class="modal fade" id="userModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="<?= $editUser ? 'update' : 'create' ?>">
      <?php if ($editUser): ?><input type="hidden" name="id" value="<?= (int)$editUser['id'] ?>"><?php endif; ?>
      <div class="modal-header"><h5 class="modal-title"><?= $editUser ? 'Edit user' : 'Add user' ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="mb-3"><label class="form-label">Full name</label><input name="full_name" class="form-control" value="<?= e($editUser['full_name'] ?? '') ?>" required></div>
        <div class="row g-3">
          <div class="col-md-6 mb-3"><label class="form-label">Username</label><input name="username" class="form-control" value="<?= e($editUser['username'] ?? '') ?>" required></div>
          <div class="col-md-6 mb-3"><label class="form-label">Role</label>
            <select name="role" class="form-select">
              <?php foreach (['staff','collector','admin'] as $r): ?>
                <option value="<?= $r ?>" <?= ($editUser['role'] ?? 'staff') === $r ? 'selected' : '' ?>><?= ucfirst($r) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6 mb-3"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= e($editUser['email'] ?? '') ?>"></div>
          <div class="col-md-6 mb-3"><label class="form-label">Phone</label><input name="phone" class="form-control" value="<?= e($editUser['phone'] ?? '') ?>"></div>
          <div class="col-md-6 mb-3"><label class="form-label">Department</label><input name="department" class="form-control" value="<?= e($editUser['department'] ?? '') ?>"></div>
          <div class="col-md-6 mb-3"><label class="form-label"><?= $editUser ? 'New password (optional)' : 'Password' ?></label><input type="password" name="password" class="form-control" <?= $editUser ? '' : 'required' ?>></div>
        </div>
        <div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" id="isActiveChk" <?= (!$editUser || (int)$editUser['is_active'] === 1) ? 'checked' : '' ?>><label class="form-check-label" for="isActiveChk">Account active</label></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
    </form>
  </div>
</div>

<?php if ($editUser): $page_scripts = '<script>document.addEventListener("DOMContentLoaded",function(){new bootstrap.Modal(document.getElementById("userModal")).show();});</script>'; ?>
<?php else: $page_scripts = ''; endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
