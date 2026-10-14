<?php
/**
 * Admin - reports with filters, CSV export and a print-friendly view.
 * Every figure comes from live SQL; nothing is hard-coded.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$page_title = 'Reports';
require_once __DIR__ . '/../includes/functions.php';

$pdo = db();

$type   = get_str('type', 'waste_generation');
$from   = get_str('from');
$to     = get_str('to');
$catId  = get_str('category');
$dept   = get_str('department');
$contId = get_str('container');
$collId = get_str('collector');
$status = get_str('status');

$allowedTypes = ['waste_generation', 'category', 'container', 'pending', 'collector', 'collection_history', 'rfid'];
if (!in_array($type, $allowedTypes, true)) { $type = 'waste_generation'; }

// ---- Build filters -------------------------------------------------------
$dateCol = in_array($type, ['collection_history', 'collector'], true) ? 'r.collected_at' : 'w.created_at';
$filters = []; $args = [];
if ($from !== '') { $filters[] = "date($dateCol) >= date(?)"; $args[] = $from; }
if ($to !== '')   { $filters[] = "date($dateCol) <= date(?)"; $args[] = $to; }
$where = $filters ? ' WHERE ' . implode(' AND ', $filters) : '';

$columns = [];
$rows = [];
$title = '';

switch ($type) {
    case 'waste_generation':
        $title = 'Waste Generation Report';
        $columns = ['Record', 'Date', 'Category', 'Department', 'Location', 'Quantity', 'Unit', 'Priority', 'Status', 'Recorded by'];
        $catF = $catId !== '' ? ' AND w.category_id = ?' : '';
        $depF = $dept !== '' ? ' AND w.department = ?' : '';
        $stF  = $status !== '' ? ' AND w.status = ?' : '';
        $sql = "SELECT w.record_code, w.created_at, wc.name cat, w.department, w.location, w.quantity, w.unit, w.priority, w.status, u.full_name
                FROM waste_records w LEFT JOIN waste_categories wc ON wc.id=w.category_id LEFT JOIN users u ON u.id=w.created_by
                $where " . str_replace('WHERE', 'AND', $catF) . $depF . $stF . ' ORDER BY w.id DESC LIMIT 5000';
        // Rebuild cleanly (avoid WHERE/AND mixing)
        $cond = $filters; $a2 = $args;
        if ($catId !== '') { $cond[] = 'w.category_id = ?'; $a2[] = (int)$catId; }
        if ($dept !== '')  { $cond[] = 'w.department = ?'; $a2[] = $dept; }
        if ($status !== ''){ $cond[] = 'w.status = ?'; $a2[] = $status; }
        $sql = 'SELECT w.record_code, w.created_at, wc.name cat, w.department, w.location, w.quantity, w.unit, w.priority, w.status, u.full_name
                FROM waste_records w LEFT JOIN waste_categories wc ON wc.id=w.category_id LEFT JOIN users u ON u.id=w.created_by'
               . ($cond ? ' WHERE ' . implode(' AND ', $cond) : '') . ' ORDER BY w.id DESC LIMIT 5000';
        $stmt = $pdo->prepare($sql); $stmt->execute($a2); $rows = $stmt->fetchAll();
        $rows = array_map(fn($r) => [$r['record_code'], format_dt($r['created_at']), $r['cat'], $r['department'], $r['location'], $r['quantity'], $r['unit'], $r['priority'], $r['status'], $r['full_name']], $rows);
        break;

    case 'category':
        $title = 'Category-wise Waste Report';
        $columns = ['Category', 'Hazardous', 'Records', 'Total Quantity', 'Active Containers'];
        $rows = $pdo->query(
            "SELECT wc.name, wc.is_hazardous, COUNT(w.id) records, COALESCE(SUM(w.quantity),0) qty,
                    (SELECT COUNT(*) FROM containers c WHERE c.category_id = wc.id AND c.status='active') conts
             FROM waste_categories wc LEFT JOIN waste_records w ON w.category_id = wc.id
             GROUP BY wc.id ORDER BY qty DESC"
        )->fetchAll();
        $rows = array_map(fn($r) => [$r['name'], (int)$r['is_hazardous'] ? 'Yes' : 'No', $r['records'], number_format((float)$r['qty'], 2), $r['conts']], $rows);
        break;

    case 'container':
        $title = 'Container Report';
        $columns = ['Container', 'Label', 'Category', 'Location', 'Capacity', 'Fill', 'Status', 'Records', 'Tags'];
        $rows = $pdo->query(
            "SELECT c.container_code, c.label, wc.name cat, c.location, c.capacity, c.fill_level, c.status,
                    (SELECT COUNT(*) FROM waste_records w WHERE w.container_id=c.id) records,
                    (SELECT COUNT(*) FROM rfid_tags t WHERE t.container_id=c.id) tags
             FROM containers c LEFT JOIN waste_categories wc ON wc.id=c.category_id ORDER BY c.container_code"
        )->fetchAll();
        $rows = array_map(fn($r) => [$r['container_code'], $r['label'], $r['cat'], $r['location'], $r['capacity'], $r['fill_level'], $r['status'], $r['records'], $r['tags']], $rows);
        break;

    case 'pending':
        $title = 'Pending Collection Report';
        $columns = ['Request', 'Container', 'Category', 'Priority', 'Status', 'Age (hrs)', 'Location'];
        $rows = $pdo->query(
            "SELECT r.request_no, c.container_code, wc.name cat, r.priority, r.status,
                    CAST((julianday('now') - julianday(r.created_at)) * 24 AS INTEGER) age, r.location
             FROM collection_requests r LEFT JOIN containers c ON c.id=r.container_id
             LEFT JOIN waste_records w ON w.id=r.waste_record_id LEFT JOIN waste_categories wc ON wc.id=w.category_id
             WHERE r.status IN ('pending','approved','assigned','in_progress') ORDER BY age DESC"
        )->fetchAll();
        $rows = array_map(fn($r) => [$r['request_no'], $r['container_code'], $r['cat'], $r['priority'], $r['status'], $r['age'], $r['location']], $rows);
        break;

    case 'collector':
        $title = 'Collector Performance Report';
        $columns = ['Collector', 'Department', 'Assigned', 'Completed', 'In Progress', 'Avg hours to collect'];
        $disp = "CASE WHEN r.status IN ('collected','completed') THEN (julianday(r.collected_at)-julianday(r.created_at))*24 END";
        $rows = $pdo->query(
            "SELECT u.full_name, u.department,
                    COUNT(*) assigned,
                    SUM(CASE WHEN r.status IN ('collected','completed') THEN 1 ELSE 0 END) completed,
                    SUM(CASE WHEN r.status='in_progress' THEN 1 ELSE 0 END) inprog,
                    ROUND(AVG($disp), 1) avg_hours
             FROM users u LEFT JOIN collection_requests r ON r.assigned_to = u.id
             WHERE u.role='collector' GROUP BY u.id ORDER BY completed DESC"
        )->fetchAll();
        $rows = array_map(fn($r) => [$r['full_name'], $r['department'], $r['assigned'], $r['completed'], $r['inprog'], $r['avg_hours'] ?? '-'], $rows);
        break;

    case 'collection_history':
        $title = 'Collection History Report';
        $columns = ['Request', 'Container', 'Category', 'Collector', 'Collected', 'Completed', 'Status'];
        $cond = []; $a2 = [];
        if ($from !== '') { $cond[] = 'date(r.collected_at) >= date(?)'; $a2[] = $from; }
        if ($to !== '') { $cond[] = 'date(r.collected_at) <= date(?)'; $a2[] = $to; }
        if ($collId !== '') { $cond[] = 'r.assigned_to = ?'; $a2[] = (int)$collId; }
        $sql = "SELECT r.request_no, c.container_code, wc.name cat, u.full_name collector, r.collected_at, r.completed_at, r.status
                FROM collection_requests r LEFT JOIN containers c ON c.id=r.container_id
                LEFT JOIN waste_records w ON w.id=r.waste_record_id LEFT JOIN waste_categories wc ON wc.id=w.category_id
                LEFT JOIN users u ON u.id=r.assigned_to WHERE r.status IN ('collected','completed')"
               . ($cond ? ' AND ' . implode(' AND ', $cond) : '') . ' ORDER BY r.collected_at DESC LIMIT 5000';
        $stmt = $pdo->prepare($sql); $stmt->execute($a2); $rows = $stmt->fetchAll();
        $rows = array_map(fn($r) => [$r['request_no'], $r['container_code'], $r['cat'], $r['collector'], format_dt($r['collected_at']), format_dt($r['completed_at']), $r['status']], $rows);
        break;

    case 'rfid':
        $title = 'RFID Verification & Audit Report';
        $columns = ['When', 'Result', 'Tag UID', 'Container', 'Request', 'Collector', 'Device', 'IP'];
        $cond = []; $a2 = [];
        if ($from !== '') { $cond[] = 'date(l.created_at) >= date(?)'; $a2[] = $from; }
        if ($to !== '') { $cond[] = 'date(l.created_at) <= date(?)'; $a2[] = $to; }
        $sql = "SELECT l.created_at, l.result, l.tag_uid, c.container_code, r.request_no, u.full_name collector, d.device_code, l.ip_address
                FROM rfid_verification_logs l
                LEFT JOIN containers c ON c.id=l.container_id
                LEFT JOIN collection_requests r ON r.id=l.request_id
                LEFT JOIN users u ON u.id=l.collector_id
                LEFT JOIN devices d ON d.id=l.device_id"
               . ($cond ? ' WHERE ' . implode(' AND ', $cond) : '') . ' ORDER BY l.id DESC LIMIT 5000';
        $stmt = $pdo->prepare($sql); $stmt->execute($a2); $rows = $stmt->fetchAll();
        $rows = array_map(fn($r) => [format_dt($r['created_at']), $r['result'], $r['tag_uid'], $r['container_code'], $r['request_no'], $r['collector'], $r['device_code'], $r['ip_address']], $rows);
        break;
}

// ---- CSV export ----------------------------------------------------------
if (get_str('export') === 'csv') {
    $fname = 'report_' . $type . '_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
    fputcsv($out, $columns);
    foreach ($rows as $r) { fputcsv($out, $r); }
    fclose($out);
    log_audit('report_exported', 'report', null, "Exported $type CSV");
    exit;
}

// ---- Filter option lists -------------------------------------------------
$categories = $pdo->query('SELECT id, name FROM waste_categories ORDER BY name')->fetchAll();
$departments = $pdo->query("SELECT DISTINCT department FROM waste_records WHERE department IS NOT NULL AND department <> '' ORDER BY department")->fetchAll();
$containers = $pdo->query('SELECT id, container_code FROM containers ORDER BY container_code')->fetchAll();
$collectors = $pdo->query("SELECT id, full_name FROM users WHERE role='collector' ORDER BY full_name")->fetchAll();

$typeLabels = [
    'waste_generation'   => 'Waste Generation',
    'category'           => 'Category-wise Waste',
    'container'          => 'Container',
    'pending'            => 'Pending Collections',
    'collector'          => 'Collector Performance',
    'collection_history' => 'Collection History',
    'rfid'               => 'RFID Verification & Audit',
];

include __DIR__ . '/../includes/header.php';
$base = app_base_url();
?>
<div class="d-flex justify-content-between align-items-center mb-3 no-print">
  <div class="page-title"><i class="bi bi-graph-up me-2"></i>Reports</div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary btn-sm" href="?<?= e(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>"><i class="bi bi-filetype-csv"></i> Export CSV</a>
    <button class="btn btn-outline-primary btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
  </div>
</div>

<form class="row g-2 mb-3 no-print" method="get">
  <div class="col-md-3">
    <select name="type" class="form-select form-select-sm" onchange="this.form.submit()">
      <?php foreach ($typeLabels as $k => $v): ?><option value="<?= $k ?>" <?= $type === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-2"><input type="date" name="from" class="form-control form-control-sm" value="<?= e($from) ?>" title="From"></div>
  <div class="col-md-2"><input type="date" name="to" class="form-control form-control-sm" value="<?= e($to) ?>" title="To"></div>
  <?php if (in_array($type, ['waste_generation','category'], true)): ?>
    <div class="col-md-2"><select name="category" class="form-select form-select-sm"><option value="">All categories</option>
      <?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (string)$catId === (string)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
  <?php endif; ?>
  <?php if ($type === 'waste_generation'): ?>
    <div class="col-md-2"><select name="status" class="form-select form-select-sm"><option value="">All status</option>
      <?php foreach (['pending','requested','collected','disposed','cancelled'] as $s): ?><option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select></div>
  <?php endif; ?>
  <?php if ($type === 'collection_history'): ?>
    <div class="col-md-2"><select name="collector" class="form-select form-select-sm"><option value="">All collectors</option>
      <?php foreach ($collectors as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (string)$collId === (string)$c['id'] ? 'selected' : '' ?>><?= e($c['full_name']) ?></option><?php endforeach; ?></select></div>
  <?php endif; ?>
  <div class="col-md-2"><button class="btn btn-primary btn-sm w-100">Apply</button></div>
</form>

<div class="card">
  <div class="card-header d-flex justify-content-between">
    <span><?= e($title) ?></span>
    <span class="text-muted small"><?= count($rows) ?> row(s)<?= $from || $to ? ' &middot; ' . e($from ?: '…') . ' to ' . e($to ?: '…') : '' ?></span>
  </div>
  <div class="table-responsive">
    <table class="table table-sm table-striped table-hover mb-0">
      <thead><tr><?php foreach ($columns as $c): ?><th><?= e($c) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="<?= count($columns) ?>"><div class="empty-state"><i class="bi bi-inbox"></i>No data for the selected filters.</div></td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr><?php foreach ($r as $cell): ?><td><?= e($cell) ?></td><?php endforeach; ?></tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
