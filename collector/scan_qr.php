<?php
/** Collector - scan a container QR to verify it against a collection request. */
declare(strict_types=1);

$page_title = 'Scan Container QR';
require_once __DIR__ . '/../includes/auth.php';
require_role('collector', 'admin');

$base = app_base_url();
$requestId = (int)get_str('request');

// Redirect scans into the QR verification handler, preserving the request id.
$scanPage  = '/collector/qr_verify.php';
$scanExtra = $requestId ? 'request=' . $requestId . '&' : '';

include __DIR__ . '/../includes/header.php';
?>
<div class="page-title mb-3"><i class="bi bi-upc-scan me-2"></i>Scan Container QR</div>
<?php if ($requestId): ?>
  <div class="alert alert-primary small">Verifying QR for request #<?= $requestId ?>. The scanned container must match this request.</div>
<?php else: ?>
  <div class="alert alert-info small">Tip: open this page from a request ("Verify QR") so the scan is checked against that job.</div>
<?php endif; ?>
<?php include __DIR__ . '/../includes/qr_scanner.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
