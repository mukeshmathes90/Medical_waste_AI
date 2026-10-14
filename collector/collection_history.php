<?php
/** Collector - history of past collections. */
declare(strict_types=1);

$page_title = 'Collection History';
require_once __DIR__ . '/../includes/auth.php';
require_role('collector', 'admin');

$pdo = db();
$user = current_user();
$uid = (int)$user['id'];

$from = get_str('from');
$to   = get_str('to');
$page = current_page();
$perPage = 20;

$where = ["r.status IN ('collected','completed')"]; $args = [];
if ($user['role'] === 'collector') { $where[] = 'r.assigned_to = ?'; $args[] = $uid; }
if ($from !== '') { $where[] = "date(r.collected_at) >= date(?)"; $args[] = $from; }
if ($to !== '')   { $where[] = "date(r.collected_at) <= date(?)"; $args[] = $to; }
$whereSql = ' WHERE ' . implode(' AND ', $where);

$count = $pdo->prepare('SELECT COUNT(*) c FROM collection_requests r' . $whereSql);
$count->execute($args);
$total = (int)$count->fetch()['c'];

$stmt = $pdo->prepare(
    "SELECT r.*, c.container_code, wc.name AS category_name, u.full_name AS requester
     FROM collection_requests r
     LEFT JOIN containers c ON c.id = r.container_id
     LEFT JOIN waste_records w ON w.id = r.waste_record_id
     LEFT JOIN waste_categories wc ON wc.id = w.category_id
     LEFT JOIN users u ON u.id = r.requested_by"
    . $whereSql . ' ORDER BY r.collected_at DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage)
);
$stmt->execute($args);
$rows = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>
<div class="page-title mb-3"><i class="bi bi-clock-history me-2"></i>Collection History</div>

<form class="row g-2 mb-3" method="get">
  <div class="col-md-3"><label class="form-label small mb-0">From</label><input type="date" name="from" class="form-control form-control-sm" value="<?= e($from) ?>"></div>
  <div class="col-md-3"><label class="form-label small mb-0">To</label><input type="date" name="to" class="form-control form-control-sm" value="<?= e($to) ?>"></div>
  <div class="col-md-2 d-flex align-items-end"><button class="btn btn-outline-secondary btn-sm w-100">Filter</button></div>
</form>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th>Request</th><th>Container</th><th>Category</th><th>Qty</th><th>Collected</th><th>Status</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="6"><div class="empty-state"><i class="bi bi-clock-history"></i>No completed collections yet.</div></td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr>
          <td class="text-mono"><?= e($r['request_no']) ?></td>
          <td class="text-mono"><?= e($r['container_code'] ?? '-') ?></td>
          <td><?= e($r['category_name'] ?? '-') ?></td>
          <td><?= e($r['quantity']) ?> <?= e($r['unit']) ?></td>
          <td class="small text-muted"><?= e(format_dt($r['collected_at'])) ?></td>
          <td><?= status_badge($r['status']) ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($total > $perPage): ?>
  <div class="card-footer bg-white d-flex justify-content-between align-items-center">
    <small class="text-muted"><?= $total ?> record(s)</small>
    <?= pagination_links($total, $perPage, $page, ['from' => $from, 'to' => $to]) ?>
  </div>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
