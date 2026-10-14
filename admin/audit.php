<?php
/** Admin - audit log viewer. */
declare(strict_types=1);

$page_title = 'Audit Log';
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$pdo = db();
$action = get_str('action');
$userId = get_str('user');
$from = get_str('from');
$to = get_str('to');
$page = current_page();
$perPage = 30;

$where = []; $args = [];
if ($action !== '') { $where[] = 'a.action LIKE ?'; $args[] = "%$action%"; }
if ($userId !== '') { $where[] = 'a.user_id = ?'; $args[] = (int)$userId; }
if ($from !== '') { $where[] = 'date(a.created_at) >= date(?)'; $args[] = $from; }
if ($to !== '') { $where[] = 'date(a.created_at) <= date(?)'; $args[] = $to; }
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$count = $pdo->prepare('SELECT COUNT(*) c FROM audit_logs a' . $whereSql);
$count->execute($args);
$total = (int)$count->fetch()['c'];

$sql = 'SELECT a.*, u.full_name, u.role FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id'
     . $whereSql . ' ORDER BY a.id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage);
$stmt = $pdo->prepare($sql); $stmt->execute($args); $logs = $stmt->fetchAll();

$users = $pdo->query('SELECT id, full_name FROM users ORDER BY full_name')->fetchAll();

include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div class="page-title"><i class="bi bi-journal-text me-2"></i>Audit Log</div>
  <span class="text-muted small"><?= $total ?> entr<?= $total === 1 ? 'y' : 'ies' ?></span>
</div>

<form class="row g-2 mb-3" method="get">
  <div class="col-md-3"><input type="text" name="action" class="form-control form-control-sm" placeholder="Action contains…" value="<?= e($action) ?>"></div>
  <div class="col-md-3"><select name="user" class="form-select form-select-sm"><option value="">All users</option>
    <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>" <?= (string)$userId === (string)$u['id'] ? 'selected' : '' ?>><?= e($u['full_name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2"><input type="date" name="from" class="form-control form-control-sm" value="<?= e($from) ?>"></div>
  <div class="col-md-2"><input type="date" name="to" class="form-control form-control-sm" value="<?= e($to) ?>"></div>
  <div class="col-md-2"><button class="btn btn-primary btn-sm w-100">Filter</button></div>
</form>

<div class="card"><div class="table-responsive">
  <table class="table table-sm table-hover align-middle mb-0">
    <thead><tr><th>When</th><th>User</th><th>Role</th><th>Action</th><th>Entity</th><th>Details</th><th>IP</th></tr></thead>
    <tbody>
    <?php if (!$logs): ?>
      <tr><td colspan="7"><div class="empty-state"><i class="bi bi-journal"></i>No audit entries.</div></td></tr>
    <?php else: foreach ($logs as $l): ?>
      <tr>
        <td class="small text-nowrap"><?= e(format_dt($l['created_at'])) ?></td>
        <td><?= e($l['full_name'] ?? 'system') ?></td>
        <td class="small"><?= e($l['role'] ?? '-') ?></td>
        <td><span class="badge bg-light text-dark border"><?= e($l['action']) ?></span></td>
        <td class="small text-mono"><?= e($l['entity'] ?? '') ?><?= $l['entity_id'] ? '#' . (int)$l['entity_id'] : '' ?></td>
        <td class="small text-muted"><?= e($l['details']) ?></td>
        <td class="small text-mono"><?= e($l['ip_address']) ?></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
<?php if ($total > $perPage): ?>
<div class="card-footer bg-white d-flex justify-content-between align-items-center">
  <small class="text-muted">Page <?= $page ?></small>
  <?= pagination_links($total, $perPage, $page, ['action' => $action, 'user' => $userId, 'from' => $from, 'to' => $to]) ?>
</div>
<?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
