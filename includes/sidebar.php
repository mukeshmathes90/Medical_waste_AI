<?php
/**
 * Role-aware sidebar navigation. Rendered as a fixed column on desktop and an
 * offcanvas drawer on mobile (see header.php toggle).
 */
$base    = app_base_url();
$user    = current_user();
$current = basename($_SERVER['PHP_SELF']);
$dir     = basename(dirname($_SERVER['PHP_SELF']));

if (!function_exists('nav_item')) {
    function nav_item(string $href, string $icon, string $label, string $current, string $dir): void
    {
        $file = basename(parse_url($href, PHP_URL_PATH) ?? '');
        $linkDir = basename(dirname(parse_url($href, PHP_URL_PATH) ?? ''));
        $active = ($current === $file && $dir === $linkDir) ? ' active' : '';
        echo '<a class="nav-link d-flex align-items-center gap-2' . $active . '" href="' . e($href) . '">'
           . '<i class="bi ' . e($icon) . '"></i><span>' . e($label) . '</span></a>';
    }
}

$menus = [
    'admin' => [
        ['group' => 'Overview',   'items' => [
            ['/admin/dashboard.php',  'bi-speedometer2',    'Dashboard'],
        ]],
        ['group' => 'Management', 'items' => [
            ['/admin/users.php',      'bi-people',          'Users'],
            ['/admin/categories.php', 'bi-tags',            'Waste Categories'],
            ['/admin/containers.php', 'bi-box-seam',        'Containers'],
            ['/admin/rfid_tags.php',  'bi-wifi',            'RFID Tags'],
            ['/admin/requests.php',   'bi-truck',           'Collection Requests'],
        ]],
        ['group' => 'Insights',   'items' => [
            ['/admin/reports.php',    'bi-graph-up',        'Reports'],
            ['/admin/audit.php',      'bi-journal-text',    'Audit Log'],
            ['/admin/hardware.php',   'bi-cpu',             'Hardware & Devices'],
            ['/admin/settings.php',   'bi-gear',            'Settings'],
        ]],
    ],
    'staff' => [
        ['group' => 'Overview', 'items' => [
            ['/staff/dashboard.php',           'bi-speedometer2', 'Dashboard'],
        ]],
        ['group' => 'Waste',    'items' => [
            ['/staff/waste_entry.php',         'bi-plus-circle',  'New Waste Entry'],
            ['/staff/waste_records.php',       'bi-list-ul',      'Waste Records'],
            ['/staff/generate_qr.php',         'bi-qr-code',      'Generate QR'],
            ['/staff/scan_qr.php',             'bi-upc-scan',     'Scan QR'],
        ]],
        ['group' => 'Collections', 'items' => [
            ['/staff/collection_requests.php', 'bi-truck',        'Collection Requests'],
        ]],
    ],
    'collector' => [
        ['group' => 'Overview', 'items' => [
            ['/collector/dashboard.php',        'bi-speedometer2', 'Dashboard'],
        ]],
        ['group' => 'Collections', 'items' => [
            ['/collector/requests.php',         'bi-truck',        'Collection Requests'],
            ['/collector/scan_qr.php',          'bi-upc-scan',     'Scan QR'],
            ['/collector/verify_rfid.php',      'bi-wifi',         'Verify RFID'],
            ['/collector/update_status.php',    'bi-arrow-repeat', 'Update Status'],
            ['/collector/collection_history.php','bi-clock-history','Collection History'],
        ]],
    ],
];

$role = $user['role'];
?>
<aside class="offcanvas-lg offcanvas-start app-sidebar" id="appSidebar" tabindex="-1">
  <div class="offcanvas-header border-bottom">
    <h5 class="offcanvas-title mb-0"><i class="bi bi-clipboard2-pulse me-2"></i>Menu</h5>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#appSidebar"></button>
  </div>
  <div class="offcanvas-body p-0">
    <nav class="nav flex-column py-2">
      <?php foreach (($menus[$role] ?? []) as $section): ?>
        <?php if (!empty($section['group'])): ?>
          <div class="sidebar-heading"><?= e($section['group']) ?></div>
        <?php endif; ?>
        <?php foreach ($section['items'] as $item): ?>
          <?php nav_item($base . $item[0], $item[1], $item[2], $current, $dir); ?>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </nav>
  </div>
</aside>
