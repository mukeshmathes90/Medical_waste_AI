<?php
/**
 * Reusable QR scanner widget (camera + image upload + manual entry).
 * Expects $base to be defined. On a successful scan it redirects to the target
 * verify page. Optional:
 *   $scanPage  - path (relative to $base) to redirect to, default /verify.php
 *   $scanExtra - already-encoded query fragment to keep, e.g. 'request=5&'
 */
$scanPage  = $scanPage ?? '/verify.php';
$scanExtra = $scanExtra ?? '';
?>
<div class="row g-3">
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header"><i class="bi bi-camera me-1"></i>Camera scanner</div>
      <div class="card-body text-center">
        <div id="qr-reader" class="mx-auto"></div>
        <div id="qr-reader-status" class="small text-muted mt-2">Starting camera&hellip;</div>
        <button id="startCam" class="btn btn-primary btn-sm mt-2"><i class="bi bi-camera-video"></i> Start camera</button>
        <button id="stopCam" class="btn btn-outline-secondary btn-sm mt-2" disabled><i class="bi bi-stop-circle"></i> Stop</button>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-file-earmark-image me-1"></i>Upload QR image</div>
      <div class="card-body">
        <input type="file" id="qrFile" accept="image/*" class="form-control">
        <div class="form-text">Works when the camera is unavailable or denied.</div>
      </div>
    </div>
    <div class="card">
      <div class="card-header"><i class="bi bi-keyboard me-1"></i>Manual entry</div>
      <div class="card-body">
        <form method="get" action="<?= e($base) ?>/verify.php">
          <div class="input-group">
            <input name="token" class="form-control" placeholder="Container token / waste code">
            <button class="btn btn-primary">Verify</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script src="<?= e($base) ?>/assets/js/html5-qrcode.min.js"></script>
<script>
(function () {
    const statusEl = document.getElementById('qr-reader-status');
    const startBtn = document.getElementById('startCam');
    const stopBtn = document.getElementById('stopCam');
    let scanner = null;
    let running = false;
    let handled = false;

    const SCAN_PAGE = '<?= e($base . $scanPage) ?>';
    const SCAN_EXTRA = <?= json_encode($scanExtra) ?>;

    function go(text) {
        if (handled) return;
        handled = true;
        let token = null, code = null;
        try {
            if (/^https?:\/\//i.test(text)) {
                const u = new URL(text);
                token = u.searchParams.get('token');
                code = u.searchParams.get('code');
                if (!token && !code) { window.location.href = text; return; }
            }
        } catch (e) { /* not a URL */ }
        if (!token && !code) {
            if (text.toUpperCase().startsWith('MWS:')) text = text.substring(4);
            token = text.trim();
        }
        const param = code && !token ? 'code' : 'token';
        const value = code && !token ? code : token;
        statusEl.textContent = 'Scanned! Redirecting…';
        window.location.href = SCAN_PAGE + '?' + SCAN_EXTRA + param + '=' + encodeURIComponent(value);
    }

    function onSuccess(decodedText) { go(decodedText); }
    function onError() { /* ignore per-frame errors */ }

    function start() {
        if (running) return;
        scanner = scanner || new Html5Qrcode('qr-reader');
        scanner.start({ facingMode: 'environment' }, { fps: 10, qrbox: { width: 240, height: 240 } }, onSuccess, onError)
            .then(function () {
                running = true;
                startBtn.disabled = true; stopBtn.disabled = false;
                statusEl.textContent = 'Point the camera at a QR label.';
            })
            .catch(function (err) {
                statusEl.innerHTML = 'Camera unavailable: ' + err + '<br>Use image upload or manual entry instead.';
            });
    }

    function stop() {
        if (!running || !scanner) return;
        scanner.stop().then(function () {
            running = false;
            startBtn.disabled = false; stopBtn.disabled = true;
            statusEl.textContent = 'Camera stopped.';
        });
    }

    startBtn.addEventListener('click', start);
    stopBtn.addEventListener('click', stop);

    document.getElementById('qrFile').addEventListener('change', function (e) {
        const file = e.target.files[0];
        if (!file) return;
        scanner = scanner || new Html5Qrcode('qr-reader');
        scanner.scanFile(file, true).then(onSuccess).catch(function (err) {
            statusEl.textContent = 'Could not read a QR code from the image.';
        });
    });

    if (navigator.mediaDevices) { start(); }
    else { statusEl.textContent = 'Camera not supported here - use upload or manual entry.'; }
})();
</script>
