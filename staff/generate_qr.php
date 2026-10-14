<?php
/** Staff - generate and print QR labels for containers (and waste records). */
declare(strict_types=1);

$page_title = 'Generate QR';
require_once __DIR__ . '/../includes/auth.php';
require_role('staff', 'admin');

$pdo = db();
$base = app_base_url();

$containers = $pdo->query(
    'SELECT c.*, wc.name AS category_name FROM containers c LEFT JOIN waste_categories wc ON wc.id = c.category_id ORDER BY c.container_code'
)->fetchAll();

$selectedContainer = null;
if (get_str('container')) {
    $s = $pdo->prepare('SELECT c.*, wc.name AS category_name FROM containers c LEFT JOIN waste_categories wc ON wc.id = c.category_id WHERE c.id = ?');
    $s->execute([(int)get_str('container')]);
    $selectedContainer = $s->fetch() ?: null;
}

$selectedRecord = null;
if (get_str('record')) {
    $s = $pdo->prepare('SELECT w.*, wc.name AS category_name FROM waste_records w LEFT JOIN waste_categories wc ON wc.id = w.category_id WHERE w.id = ? OR w.record_code = ?');
    $s->execute([(int)get_str('record'), get_str('record')]);
    $selectedRecord = $s->fetch() ?: null;
}

$qrUrl = '';
$qrTitle = '';
$qrSubtitle = '';
if ($selectedContainer) {
    $qrUrl = $base . '/verify.php?token=' . urlencode($selectedContainer['qr_token']);
    $qrTitle = $selectedContainer['container_code'];
    $qrSubtitle = $selectedContainer['label'] . ' - ' . ($selectedContainer['category_name'] ?? 'Unassigned');
} elseif ($selectedRecord) {
    $qrUrl = $base . '/verify.php?code=' . urlencode($selectedRecord['record_code']);
    $qrTitle = $selectedRecord['record_code'];
    $qrSubtitle = $selectedRecord['category_name'] . ' - ' . $selectedRecord['quantity'] . ' ' . $selectedRecord['unit'];
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-title mb-3"><i class="bi bi-qr-code me-2"></i>Generate QR Code</div>

<div class="row g-3">
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header">Select an item to label</div>
      <div class="card-body">
        <label class="form-label">Container</label>
        <form method="get" class="mb-3">
          <div class="input-group">
            <select name="container" class="form-select" onchange="this.form.submit()">
              <option value="">-- choose container --</option>
              <?php foreach ($containers as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= ($selectedContainer && (int)$selectedContainer['id'] === (int)$c['id']) ? 'selected' : '' ?>>
                  <?= e($c['container_code']) ?> - <?= e($c['label'] ?? '') ?>
                </option>
              <?php endforeach; ?>
            </select>
            <button class="btn btn-outline-secondary">Go</button>
          </div>
        </form>
        <p class="text-muted small mb-0">
          The QR encodes a link to <code>verify.php</code> with a random container token - no personal data is stored in the code.
          Scanners on phones will open the verification page; the in-app scanner reads the same token.
        </p>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card">
      <div class="card-header">Label preview</div>
      <div class="card-body text-center">
        <?php if ($qrUrl): ?>
          <div class="qr-box" id="qrPrint">
            <div id="qrcode"></div>
            <div class="mt-2 fw-bold text-mono"><?= e($qrTitle) ?></div>
            <div class="small text-muted"><?= e($qrSubtitle) ?></div>
            <div class="small text-muted"><?= e(setting('facility_name', FACILITY_NAME)) ?></div>
          </div>
          <div class="mt-3 no-print">
            <button class="btn btn-primary" onclick="window.print()"><i class="bi bi-printer me-1"></i>Print label</button>
            <span class="text-muted small ms-2">URL: <code><?= e($qrUrl) ?></code></span>
          </div>
        <?php else: ?>
          <div class="empty-state"><i class="bi bi-qr-code"></i>Choose a container (or open this page from the records list) to preview its QR label.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script src="<?= e($base) ?>/assets/js/qrcode.min.js"></script>
<?php if ($qrUrl): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    new QRCode(document.getElementById('qrcode'), {
        text: <?= json_encode($qrUrl) ?>,
        width: 200, height: 200,
        correctLevel: QRCode.CorrectLevel.M
    });
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
