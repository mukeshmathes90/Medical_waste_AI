<?php
/** Staff - list and manage their own waste records. */
declare(strict_types=1);

$page_title = 'Waste Records';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/collection.php';
require_role('staff', 'admin');

$pdo = db();
$user = current_user();

// Create a collection request directly from a record.
if (is_post() && post_str('action') === 'request') {
    require_csrf();
    $wid = post_int('waste_record_id');
    $rec = $pdo->prepare('SELECT * FROM waste_records WHERE id = ?');
    $rec->execute([$wid]);
    $w = $rec->fetch();
    if (!$w) {
        set_flash('danger', 'Waste record not found.');
    } elseif ($user['role'] === 'staff' && (int)$w['created_by'] !== (int)$user['id']) {
        set_flash('danger', 'You can only request collection for your own records.');
    } else {
        $res = create_collection_request($wid, (int)$user['id'], $w['priority'], post_str('remarks'));
        set_flash($res['ok'] ? 'success' : 'danger', $res['ok'] ? 'Collection request created.' : $res['error']);
    }
    redirect(app_base_url() . '/staff/waste_records.php');
}

$status = get_str('status');
$catFilter = get_str('category');
$search = get_str('q');
$page = current_page();
$perPage = 15;

$where = []; $args = [];
if ($user['role'] === 'staff') { $where[] = 'w.created_by = ?'; $args[] = $user['id']; }
if ($status !== '') { $where[] = 'w.status = ?'; $args[] = $status; }
if ($catFilter !== '') { $where[] = 'w.category_id = ?'; $args[] = (int)$catFilter; }
if ($search !== '') { $where[] = '(w.record_code LIKE ? OR w.location LIKE ? OR w.department LIKE ?)'; array_push($args, "%$search%", "%$search%", "%$search%"); }
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$count = $pdo->prepare('SELECT COUNT(*) c FROM waste_records w' . $whereSql);
$count->execute($args);
$total = (int)$count->fetch()['c'];

$sql = 'SELECT w.*, wc.name AS category_name, wc.color, c.container_code, u.full_name AS creator,
               (SELECT COUNT(*) FROM collection_requests r WHERE r.waste_record_id = w.id AND r.status NOT IN (\'completed\',\'cancelled\',\'rejected\')) AS open_requests
        FROM waste_records w
        LEFT JOIN waste_categories wc ON wc.id = w.category_id
        LEFT JOIN containers c ON c.id = w.container_id
        LEFT JOIN users u ON u.id = w.created_by'
        . $whereSql . ' ORDER BY w.id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage);
$stmt = $pdo->prepare($sql);
$stmt->execute($args);
$records = $stmt->fetchAll();

$categories = $pdo->query('SELECT id, name FROM waste_categories ORDER BY name')->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div class="page-title"><i class="bi bi-list-ul me-2"></i><?= $user['role'] === 'admin' ? 'All Waste Records' : 'My Waste Records' ?></div>
  <a href="<?= e(app_base_url()) ?>/staff/waste_entry.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> New Entry</a>
</div>

<form class="row g-2 mb-3" method="get">
  <div class="col-md-3"><input type="text" name="q" class="form-control form-control-sm" placeholder="Search code / location" value="<?= e($search) ?>"></div>
  <div class="col-md-3">
    <select name="category" class="form-select form-select-sm">
      <option value="">All categories</option>
      <?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (string)$catFilter === (string)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-2">
    <select name="status" class="form-select form-select-sm">
      <option value="">All status</option>
      <?php foreach (['pending','requested','collected','disposed','cancelled'] as $s): ?><option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-2"><button class="btn btn-outline-secondary btn-sm w-100">Filter</button></div>
</form>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th>Record</th><th>Category</th><th>Container</th><th>Qty</th><th>Location</th><th>Priority</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php if (!$records): ?>
        <tr><td colspan="8"><div class="empty-state"><i class="bi bi-inbox"></i>No waste records found.</div></td></tr>
      <?php else: foreach ($records as $r): ?>
        <tr>
          <td class="text-mono"><?= e($r['record_code']) ?><div class="text-muted small"><?= e(format_dt($r['created_at'])) ?></div></td>
          <td><span class="badge" style="background:<?= e($r['color']) ?>">&nbsp;</span> <?= e($r['category_name']) ?></td>
          <td class="text-mono"><?= e($r['container_code'] ?? '-') ?></td>
          <td><?= number_format((float)$r['quantity'], 2) ?> <?= e($r['unit']) ?></td>
          <td><?= e($r['location'] ?? '-') ?><div class="text-muted small"><?= e($r['department'] ?? '') ?></div></td>
          <td><?= priority_badge($r['priority']) ?></td>
          <td><?= status_badge($r['status']) ?></td>
          <td class="text-end text-nowrap">
            <?php if ((int)$r['open_requests'] === 0 && !in_array($r['status'], ['collected','disposed','cancelled'], true)): ?>
              <form method="post" class="d-inline" data-confirm="Create a collection request for <?= e($r['record_code']) ?>?">
                <?= csrf_field() ?><input type="hidden" name="action" value="request"><input type="hidden" name="waste_record_id" value="<?= (int)$r['id'] ?>">
                <button class="btn btn-sm btn-outline-primary"><i class="bi bi-truck"></i> Request</button>
              </form>
            <?php else: ?>
              <span class="text-muted small"><?= $r['open_requests'] ? 'Request open' : '' ?></span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($total > $perPage): ?>
  <div class="card-footer bg-white d-flex justify-content-between align-items-center">
    <small class="text-muted"><?= $total ?> record(s)</small>
    <?= pagination_links($total, $perPage, $page, ['status' => $status, 'category' => $catFilter, 'q' => $search]) ?>
  </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
