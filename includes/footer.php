<?php
/** Closes the layout opened in header.php. */
$base = app_base_url();
?>
    </div><!-- /.container-fluid -->
    <footer class="app-footer text-center text-muted small py-3">
      <?= e(APP_NAME) ?> v<?= e(APP_VERSION) ?> &middot; <?= e(setting('facility_name', FACILITY_NAME)) ?>
      &middot; <?= e(date('Y')) ?>
    </footer>
  </main>
</div><!-- /.app-shell -->

<script src="<?= e($base) ?>/assets/js/bootstrap.bundle.min.js"></script>
<script src="<?= e($base) ?>/assets/js/app.js"></script>
<?php if (!empty($page_scripts)) { echo $page_scripts; } ?>
</body>
</html>
