<?php
/** Staff - scan a QR label to view its container / waste record. */
declare(strict_types=1);

$page_title = 'Scan QR';
require_once __DIR__ . '/../includes/auth.php';
require_role('staff', 'admin');
$base = app_base_url();

include __DIR__ . '/../includes/header.php';
?>
<div class="page-title mb-3"><i class="bi bi-upc-scan me-2"></i>Scan QR Code</div>
<div class="alert alert-info small"><i class="bi bi-info-circle me-1"></i>
  Scanning resolves the identifier on the server and shows the container's category, location and collection status.
  Unknown or inactive identifiers are rejected.
</div>
<?php include __DIR__ . '/../includes/qr_scanner.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
