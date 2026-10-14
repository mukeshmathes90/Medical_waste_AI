<?php
/** Rendered when an authenticated user attempts an action outside their role. */
if (!headers_sent()) {
    http_response_code(403);
}
$user = current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Access denied</title>
<link href="<?= e(app_base_url()) ?>/assets/css/bootstrap.min.css" rel="stylesheet">
<link href="<?= e(app_base_url()) ?>/assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-md-6">
      <div class="card shadow-sm border-0">
        <div class="card-body text-center p-5">
          <div class="display-4 text-danger mb-3"><i class="bi bi-shield-lock"></i></div>
          <h1 class="h4">Access denied</h1>
          <p class="text-muted">Your account role does not have permission to view this page.</p>
          <?php if ($user): ?>
            <p class="small text-muted">Signed in as <strong><?= e($user['full_name']) ?></strong> (<?= e($user['role']) ?>)</p>
          <?php endif; ?>
          <a class="btn btn-primary" href="<?= e(app_base_url()) ?>/index.php">Return to dashboard</a>
        </div>
      </div>
    </div>
  </div>
</div>
</body>
</html>
