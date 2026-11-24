<?php
/**
 * aadhaar_sync_widget.php
 *
 * Self-contained Bootstrap Tri-Mode Aadhaar Sync Widget.
 * Designed to drop cleanly into existing production ERP forms (e.g., patient registration).
 *
 * Operational Modes:
 * - Mode A: Local Desktop Webcam Scanner (canvas + jsQR)
 * - Mode B: Remote Mobile Sync via QR Code (Session Polling every 2.5s)
 * - Mode C: Flatbed Scanner / MFD Image File Drop-Zone (FileReader + jsQR)
 */

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    @session_start();
}

// Generate or reuse sync token for this operator session
if (empty($_SESSION['aadhaar_sync_token'])) {
    $_SESSION['aadhaar_sync_token'] = uniqid('erp_', true);
}
$syncToken = $_SESSION['aadhaar_sync_token'];

// Auto-detect web base path to this widget directory
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? 80) == 443) ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';

$docRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
$thisDir = rtrim(str_replace('\\', '/', __DIR__), '/');
$webBasePath = '';

if ($docRoot && strpos($thisDir, $docRoot) === 0) {
    $webBasePath = substr($thisDir, strlen($docRoot));
} else {
    // Default fallback path as specified for ERP integration
    $webBasePath = '/scripts/AadhaarQRCodeReader';
}
$webBasePath = '/' . ltrim($webBasePath, '/');

$mobileScannerUrl = "{$protocol}{$host}{$webBasePath}/mobile_scanner.php?room=" . urlencode($syncToken);
$apiEndpointUrl   = "{$protocol}{$host}{$webBasePath}/aadhaar_api_handlers.php";
$widgetScriptUrl  = "{$webBasePath}/aadhaar_sync.js";
?>

<!-- ─── AADHAAR TRI-MODE SCANNER WIDGET CONTAINER ──────────────────────────── -->
<div class="card shadow-sm border-primary mb-4" id="aadhaar-sync-widget"
     data-sync-room="<?= htmlspecialchars($syncToken, ENT_QUOTES, 'UTF-8') ?>"
     data-api-endpoint="<?= htmlspecialchars($apiEndpointUrl, ENT_QUOTES, 'UTF-8') ?>"
     data-mobile-url="<?= htmlspecialchars($mobileScannerUrl, ENT_QUOTES, 'UTF-8') ?>"
     data-web-base="<?= htmlspecialchars($webBasePath, ENT_QUOTES, 'UTF-8') ?>">
    
    <div class="card-header bg-light d-flex justify-content-between align-items-center py-2">
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-primary text-uppercase px-2 py-1">UIDAI</span>
            <strong class="text-dark">Aadhaar Card Scanner &amp; Sync</strong>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-secondary font-monospace" title="Active Sync Token">
                Room: <?= htmlspecialchars(substr($syncToken, 0, 12), ENT_QUOTES, 'UTF-8') ?>...
            </span>
            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" id="btn-reset-sync" title="Generate New Session Room">
                ↺ Refresh
            </button>
        </div>
    </div>

    <!-- Mode Selector Navigation Tabs -->
    <div class="card-header border-bottom-0 pb-0 bg-white">
        <ul class="nav nav-tabs card-header-tabs" id="aadhaar-sync-tabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active fw-semibold" id="tab-mode-a" data-bs-toggle="tab" data-bs-target="#pane-mode-a" type="button" role="tab" aria-controls="pane-mode-a" aria-selected="true">
                    📷 Mode A: Desktop Camera
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link fw-semibold" id="tab-mode-b" data-bs-toggle="tab" data-bs-target="#pane-mode-b" type="button" role="tab" aria-controls="pane-mode-b" aria-selected="false">
                    📱 Mode B: Mobile Sync
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link fw-semibold" id="tab-mode-c" data-bs-toggle="tab" data-bs-target="#pane-mode-c" type="button" role="tab" aria-controls="pane-mode-c" aria-selected="false">
                    🖨️ Mode C: MFD / Scanner File
                </button>
            </li>
        </ul>
    </div>

    <div class="card-body p-3">
        <div class="tab-content" id="aadhaar-sync-tab-content">
            
            <!-- ── MODE A: LOCAL DESKTOP CAMERA SCAN ────────────────────────────── -->
            <div class="tab-pane fade show active" id="pane-mode-a" role="tabpanel" aria-labelledby="tab-mode-a">
                <div class="row align-items-center g-3">
                    <div class="col-md-6 text-center">
                        <div class="position-relative bg-dark rounded overflow-hidden d-inline-block shadow-sm" style="width: 100%; max-width: 320px; aspect-ratio: 1/1;">
                            <video id="desktop-scanner-video" autoplay playsinline muted class="w-100 h-100 object-fit-cover" style="display: none;"></video>
                            <canvas id="desktop-scanner-canvas" class="d-none"></canvas>
                            
                            <!-- Scanner Target Frame Overlay -->
                            <div id="desktop-scanner-overlay" class="position-absolute top-50 start-50 translate-middle pointer-events-none" style="width: 75%; height: 75%; border: 2px dashed #46e6a0; border-radius: 12px; display: none;"></div>

                            <!-- Idle Camera Placeholder -->
                            <div id="desktop-camera-placeholder" class="d-flex flex-column justify-content-center align-items-center text-secondary h-100 p-3">
                                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="currentColor" class="bi bi-camera mb-2 text-muted" viewBox="0 0 16 16">
                                    <path d="M15 12a1 1 0 0 1-1 1H2a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h1.172a3 3 0 0 0 2.12-.879l.83-.828A1 1 0 0 1 6.827 3h2.344a1 1 0 0 1 .707.293l.828.828A3 3 0 0 0 12.828 5H14a1 1 0 0 1 1 1zM2 4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-1.172a2 2 0 0 1-1.414-.586l-.828-.828A2 2 0 0 0 9.172 2H6.828a2 2 0 0 0-1.414.586l-.828.828A2 2 0 0 1 3.172 4z"/>
                                    <path d="M8 11a2.5 2.5 0 1 1 0-5 2.5 2.5 0 0 1 0 5m0 1a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7M3 6.5a.5.5 0 1 1-1 0 .5.5 0 0 1 1 0"/>
                                </svg>
                                <span class="small">Camera is off. Click Start Camera below.</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <h6 class="fw-bold mb-1">Direct Desktop Webcam Scan</h6>
                        <p class="text-muted small mb-3">
                            Position the large Aadhaar Secure QR code in front of the lens. Keep steady until detected.
                        </p>
                        <div class="d-flex flex-wrap gap-2 mb-2">
                            <button type="button" class="btn btn-primary btn-sm" id="btn-start-desktop-cam">
                                📷 Start Camera
                            </button>
                            <button type="button" class="btn btn-outline-danger btn-sm" id="btn-stop-desktop-cam" disabled>
                                ⏹ Stop Camera
                            </button>
                        </div>
                        <div id="desktop-cam-status" class="small text-muted">Status: Idle</div>
                    </div>
                </div>
            </div>

            <!-- ── MODE B: REMOTE MOBILE SYNC VIA QR CODE ───────────────────────── -->
            <div class="tab-pane fade" id="pane-mode-b" role="tabpanel" aria-labelledby="tab-mode-b">
                <div class="row align-items-center g-3">
                    <div class="col-md-5 text-center">
                        <div class="p-2 border rounded bg-white shadow-sm d-inline-block position-relative" style="min-width: 180px; min-height: 180px;">
                            <div id="mobile-sync-qr-render" class="d-flex justify-content-center align-items-center">
                                <!-- Rendered dynamically by QRCode library -->
                            </div>
                        </div>
                    </div>
                    <div class="col-md-7">
                        <h6 class="fw-bold mb-1">Wireless Mobile Scanner Link</h6>
                        <p class="text-muted small mb-2">
                            No desktop webcam? Open your mobile phone/tablet camera, scan the QR code to launch the secure portal, and scan the Aadhaar card with your phone's camera.
                        </p>
                        <div class="alert alert-info py-2 px-3 small d-flex align-items-center gap-2 mb-2">
                            <div class="spinner-grow spinner-grow-sm text-info flex-shrink-0" id="polling-indicator" role="status"></div>
                            <span id="polling-status-text">Background sync active: Listening for mobile scan transmission...</span>
                        </div>
                        <div class="input-group input-group-sm">
                            <input type="text" class="form-control font-monospace" id="mobile-url-copy-input" value="<?= htmlspecialchars($mobileScannerUrl, ENT_QUOTES, 'UTF-8') ?>" readonly>
                            <button class="btn btn-outline-secondary" type="button" id="btn-copy-mobile-url">Copy Link</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── MODE C: MFD / FLATBED SCANNER & DIRECT CAPTURE ─────────────── -->
            <div class="tab-pane fade" id="pane-mode-c" role="tabpanel" aria-labelledby="tab-mode-c">
                <div class="row g-3">
                    <div class="col-12">
                        <!-- Direct Action Toolbar -->
                        <div class="d-flex flex-wrap gap-2 mb-3">
                            <button type="button" class="btn btn-outline-success btn-sm d-flex align-items-center gap-1" id="btn-paste-clipboard" title="Paste from clipboard">
                                📋 <strong>Paste Scanned Image</strong> <span class="badge bg-success text-white font-monospace ms-1">Ctrl + V</span>
                            </button>
                            <button type="button" class="btn btn-outline-primary btn-sm d-flex align-items-center gap-1" id="btn-flatbed-scan" title="Scan directly via local TWAIN bridge">
                                🖨️ <strong>Scan from Flatbed Now</strong>
                            </button>
                        </div>

                        <!-- Drag and Drop Target Area -->
                        <div id="mfd-drop-zone" class="p-4 text-center rounded bg-light transition-all cursor-pointer"
                             style="border: 2px dashed #0d6efd; background-color: #f8f9fa; cursor: pointer; transition: all 0.2s ease;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="42" height="42" fill="currentColor" class="bi bi-cloud-arrow-up text-primary mb-2" viewBox="0 0 16 16">
                                <path fill-rule="evenodd" d="M7.646 5.146a.5.5 0 0 1 .708 0l2 2a.5.5 0 0 1-.708.708L8.5 6.707V10.5a.5.5 0 0 1-1 0V6.707L6.354 7.854a.5.5 0 1 1-.708-.708z"/>
                                <path d="M4.406 3.342A5.53 5.53 0 0 1 8 2c2.69 0 4.923 2 5.166 4.579A3.5 3.5 0 0 1 13.5 13H4a3.5 3.5 0 0 1-.5-6.953 5.5 5.5 0 0 1 0-1.127A5.48 5.48 0 0 1 4.406 3.342m1.026.96A4.5 4.5 0 0 0 4.5 9h9a2.5 2.5 0 0 0 .5-4.975A4.5 4.5 0 0 0 8 3a4.5 4.5 0 0 0-2.568.802"/>
                            </svg>
                            <h6 class="fw-bold mb-1">Drag &amp; Drop Image Here OR Press Ctrl+V</h6>
                            <p class="text-muted small mb-2">You can scan directly to clipboard in your scanner utility, then press Ctrl+V anywhere here</p>
                            <span class="badge bg-secondary">MFD Flatbed / Clipboard Direct</span>
                            <input type="file" id="mfd-file-input" accept="image/*" class="d-none">
                        </div>
                        <canvas id="mfd-hidden-canvas" class="d-none"></canvas>
                        <div id="mfd-status" class="small text-muted mt-2">Ready. Scan to clipboard or drop image file &mdash; decodes in memory without saving.</div>
                    </div>
                </div>
            </div>

        </div>

        <!-- ── GLOBAL SYNC STATUS & ALERT BANNER ──────────────────────────────── -->
        <div id="aadhaar-widget-alert" class="alert d-none mt-3 mb-0 py-2 px-3 small d-flex justify-content-between align-items-center" role="alert">
            <span id="aadhaar-widget-alert-text"></span>
            <button type="button" class="btn-close btn-close-white ms-2" id="btn-close-sync-alert" aria-label="Close"></button>
        </div>
    </div>
</div>

<!-- Essential Third-Party QR Libraries from CDN (jsQR + QRCode generator) -->
<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js"></script>
<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script src="https://cdn.jsdelivr.net/gh/kripken/j2k.js/openjpeg.js"></script>

<!-- Standalone ERP Synchronization Controller -->
<script type="module" src="<?= htmlspecialchars($widgetScriptUrl, ENT_QUOTES, 'UTF-8') ?>"></script>
