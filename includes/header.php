<?php
/**
 * Common page header: <head>, top navbar and opening layout markup.
 * Expects $page_title (optional) to be set by the including page.
 */
require_once __DIR__ . '/auth.php';
require_login();
refresh_user();

$user     = current_user();
$base     = app_base_url();
$page_title = $page_title ?? 'Dashboard';
$facility = setting('facility_name', FACILITY_NAME);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($page_title) ?> &middot; <?= e(APP_NAME) ?></title>
<link href="<?= e($base) ?>/assets/css/bootstrap.min.css" rel="stylesheet">
<link href="<?= e($base) ?>/assets/css/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= e($base) ?>/assets/css/style.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg app-topbar sticky-top">
  <div class="container-fluid">
    <button class="btn btn-link text-white d-lg-none me-2" type="button" data-bs-toggle="offcanvas" data-bs-target="#appSidebar" aria-label="Toggle navigation">
      <i class="bi bi-list fs-4"></i>
    </button>
    <a class="navbar-brand text-white fw-semibold d-flex align-items-center gap-2" href="<?= e($base) ?>/index.php">
      <i class="bi bi-clipboard2-pulse"></i>
      <span class="d-none d-sm-inline"><?= e(APP_NAME) ?></span>
    </a>
    <div class="ms-auto d-flex align-items-center gap-3">
      <span class="text-white-50 small d-none d-md-inline"><?= e($facility) ?></span>
      <div class="dropdown">
        <button class="btn btn-outline-light btn-sm dropdown-toggle" data-bs-toggle="dropdown">
          <i class="bi bi-person-circle"></i> <?= e($user['full_name']) ?>
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
          <li><span class="dropdown-item-text small text-muted"><?= e(ucfirst($user['role'])) ?><?= $user['department'] ? ' &middot; ' . e($user['department']) : '' ?></span></li>
          <li><hr class="dropdown-divider"></li>
          <?php if ($user['role'] === 'admin'): ?>
          <li><a class="dropdown-item" href="<?= e($base) ?>/admin/settings.php"><i class="bi bi-gear me-2"></i>Settings</a></li>
          <?php endif; ?>
          <li><a class="dropdown-item text-danger" href="<?= e($base) ?>/logout.php"><i class="bi bi-box-arrow-right me-2"></i>Sign out</a></li>
        </ul>
      </div>
    </div>
  </div>
</nav>

<div class="app-shell">
  <?php include __DIR__ . '/sidebar.php'; ?>
  <main class="app-main">
    <div class="container-fluid py-4">
      <?php foreach (get_flash() as $flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show" role="alert">
          <?= e($flash['message']) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endforeach; ?>
