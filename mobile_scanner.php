<?php
/**
 * mobile_scanner.php
 *
 * Dedicated Mobile Scanning Portal for Remote Aadhaar Sync (Mode B).
 * Opened by front desk operators or field staff scanning the desktop's sync QR code.
 *
 * Architecture:
 * - Operates 100% client-side: video stream capture, QR extraction, gzip decompression,
 *   field parsing, and JPEG 2000 photo decompression occur inside the mobile browser.
 * - When decrypted, renders a review card of demographic profile metrics.
 * - The "Send Data to ERP Dashboard" action sends the structured payload to aadhaar_api_handlers.php,
 *   which immediately synchronizes with the waiting desktop workstation.
 */

$room = trim($_GET['room'] ?? '');
// Basic token sanity validation
$cleanRoom = preg_replace('/[^a-zA-Z0-9_\-]/', '', $room);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Aadhaar Mobile Scanner &mdash; ERP Sync Portal</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <style>
        body {
            background-color: #f4f6f9;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            min-height: 100vh;
        }
        .scanner-viewport-wrap {
            position: relative;
            width: 100%;
            max-width: 380px;
            margin: 0 auto;
            aspect-ratio: 1/1;
            background: #11140f;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 16px rgba(0,0,0,0.15);
        }
        #mobile-video {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .scan-overlay-frame {
            position: absolute;
            inset: 15%;
            border: 2px dashed #46e6a0;
            border-radius: 14px;
            pointer-events: none;
            box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.45);
        }
        .scan-laser {
            position: absolute;
            left: 10%;
            right: 10%;
            height: 2px;
            background: linear-gradient(90deg, transparent, #46e6a0, transparent);
            box-shadow: 0 0 8px #46e6a0;
            animation: scanAnim 2s infinite ease-in-out;
        }
        @keyframes scanAnim {
            0%, 100% { top: 15%; }
            50% { top: 85%; }
        }
        .patient-photo {
            width: 90px;
            height: 110px;
            object-fit: cover;
            border-radius: 8px;
            border: 2px solid #dee2e6;
            background: #e9ecef;
        }
    </style>
</head>
<body class="py-3">

<div class="container" style="max-width: 480px;">
    
    <!-- Mobile Header -->
    <div class="d-flex align-items-center justify-content-between mb-3 px-1">
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-primary text-uppercase px-2 py-1">UIDAI</span>
            <h6 class="mb-0 fw-bold text-dark">Aadhaar Remote Scanner</h6>
        </div>
        <span class="badge bg-secondary font-monospace" id="room-badge">
            Room: <?= htmlspecialchars(substr($cleanRoom ?: 'None', 0, 10), ENT_QUOTES, 'UTF-8') ?>
        </span>
    </div>

    <?php if (empty($cleanRoom)): ?>
        <div class="alert alert-danger shadow-sm" role="alert">
            <h6 class="alert-heading fw-bold mb-1">Session Token Missing</h6>
            <p class="small mb-0">Please scan the sync QR code displayed on your desktop ERP workstation screen to initiate a valid session.</p>
        </div>
    <?php else: ?>

        <!-- ── SCREEN 1: ACTIVE CAMERA SCANNER ─────────────────────────────── -->
        <div id="screen-scanner">
            <div class="card shadow-sm border-0 mb-3">
                <div class="card-body p-3">
                    <p class="text-muted small text-center mb-2">
                        Point rear camera at the <strong>Secure QR Code</strong> on the Aadhaar card.
                    </p>

                    <div class="scanner-viewport-wrap">
                        <video id="mobile-video" autoplay playsinline muted></video>
                        <canvas id="mobile-canvas" class="d-none"></canvas>
                        
                        <div class="scan-overlay-frame">
                            <div class="scan-laser"></div>
                        </div>

                        <div id="mobile-placeholder" class="d-flex flex-column justify-content-center align-items-center text-white h-100 p-3 text-center position-absolute inset-0 bg-dark">
                            <div class="spinner-border spinner-border-sm text-light mb-2" role="status"></div>
                            <span class="small">Initializing camera stream...</span>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-3">
                        <button type="button" class="btn btn-outline-secondary btn-sm flex-fill" id="btn-switch-cam">
                            🔄 Flip Camera
                        </button>
                        <label class="btn btn-outline-primary btn-sm flex-fill mb-0 cursor-pointer">
                            📁 Upload Photo
                            <input type="file" id="mobile-file-input" accept="image/*" class="d-none">
                        </label>
                    </div>

                    <div id="mobile-status" class="alert alert-light border small text-center mt-3 mb-0 py-2">
                        Status: Ready. Point camera at QR code.
                    </div>
                </div>
            </div>
        </div>

        <!-- ── SCREEN 2: DECODED REVIEW & TRANSMIT VIEW ────────────────────── -->
        <div id="screen-review" class="d-none">
            <div class="card shadow-sm border-0 mb-3">
                <div class="card-header bg-success text-white py-2 d-flex justify-content-between align-items-center">
                    <strong class="small">✓ Aadhaar Verified Offline</strong>
                    <span class="badge bg-white text-success font-monospace" id="review-aadhaar-num">XXXX XXXX ????</span>
                </div>
                <div class="card-body p-3">
                    
                    <div class="d-flex gap-3 mb-3">
                        <img id="review-photo" class="patient-photo flex-shrink-0" src="images/default_user.svg" alt="Cardholder Photo">
                        <div class="flex-grow-1 min-w-0">
                            <h5 class="fw-bold text-dark mb-1 text-truncate" id="review-name">-</h5>
                            <div class="small text-muted" id="review-dob-gender">DOB: - | Gender: -</div>
                            <div class="small text-muted mt-1" id="review-mobile">Mobile: -</div>
                        </div>
                    </div>

                    <div class="bg-light p-2 rounded mb-3 border">
                        <div class="text-uppercase text-muted" style="font-size: 10px; font-weight: 700;">Resident Address</div>
                        <div class="small text-secondary" id="review-address">-</div>
                    </div>

                    <!-- Prominent Action Button -->
                    <button type="button" class="btn btn-success btn-lg w-100 py-3 fw-bold shadow mb-2" id="btn-send-to-erp">
                        🚀 Send Data to ERP Dashboard
                    </button>

                    <button type="button" class="btn btn-outline-secondary btn-sm w-100" id="btn-scan-again">
                        ↺ Scan Another Card
                    </button>

                    <div id="send-status" class="small text-center mt-2"></div>
                </div>
            </div>
        </div>

        <!-- ── SCREEN 3: TRANSMISSION CONFIRMED STATE ──────────────────────── -->
        <div id="screen-success" class="d-none">
            <div class="card shadow-sm border-0 text-center p-4">
                <div class="mb-3 text-success">
                    <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" fill="currentColor" class="bi bi-check-circle-fill" viewBox="0 0 16 16">
                        <path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0m-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05z"/>
                    </svg>
                </div>
                <h4 class="fw-bold text-dark mb-1">Data Transmitted!</h4>
                <p class="text-muted small mb-4">
                    The demographic details have been securely delivered to the desktop ERP workstation in Room <strong class="font-monospace"><?= htmlspecialchars($cleanRoom, ENT_QUOTES, 'UTF-8') ?></strong>.
                </p>
                <button type="button" class="btn btn-primary" id="btn-success-scan-new">
                    Scan Next Patient
                </button>
            </div>
        </div>

    <?php endif; ?>

</div>

<!-- CDN Libraries -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js"></script>
<script src="https://cdn.jsdelivr.net/gh/kripken/j2k.js/openjpeg.js"></script>

<!-- Mobile Scanner Module Controller -->
<script type="module">
    import { decodeAadhaarQR } from "./script/modules/aadhaar.js";
    import { decodeJp2Image } from "./script/modules/jpeg_decoder.js";
    import { createCameraController, handleFileUpload } from "./script/modules/camera.js";
    import { formatAadhaarJson } from "./script/utils/format.js";

    const ROOM_ID = <?= json_encode($cleanRoom) ?>;
    const API_ENDPOINT = "aadhaar_api_handlers.php";

    let decodedPayload = null;

    // DOM Elements
    const dom = {
        screenScanner:   document.getElementById("screen-scanner"),
        screenReview:    document.getElementById("screen-review"),
        screenSuccess:   document.getElementById("screen-success"),
        video:           document.getElementById("mobile-video"),
        canvas:          document.getElementById("mobile-canvas"),
        placeholder:     document.getElementById("mobile-placeholder"),
        status:          document.getElementById("mobile-status"),
        btnSwitchCam:    document.getElementById("btn-switch-cam"),
        fileInput:       document.getElementById("mobile-file-input"),
        btnSendToErp:    document.getElementById("btn-send-to-erp"),
        btnScanAgain:    document.getElementById("btn-scan-again"),
        btnSuccessNew:   document.getElementById("btn-success-scan-new"),
        sendStatus:      document.getElementById("send-status"),
        reviewPhoto:     document.getElementById("review-photo"),
        reviewName:      document.getElementById("review-name"),
        reviewDobGender: document.getElementById("review-dob-gender"),
        reviewMobile:    document.getElementById("review-mobile"),
        reviewAddress:   document.getElementById("review-address"),
        reviewAadhaar:   document.getElementById("review-aadhaar-num"),
    };

    function setMobileStatus(msg, isError = false) {
        if (!dom.status) return;
        dom.status.textContent = msg;
        dom.status.className = `alert ${isError ? "alert-danger" : "alert-light border"} small text-center mt-3 mb-0 py-2`;
    }

    // Camera controller instance
    const camera = createCameraController({
        video:        dom.video,
        canvas:       dom.canvas,
        onQrDetected: handleQrDetection,
        onStatus:     (msg, type) => setMobileStatus(msg, type === "error"),
    });

    async function handleQrDetection(rawQrString) {
        setMobileStatus("QR code detected! Decrypting completely offline...", false);
        camera.stopCamera();

        try {
            // 1. Decompress and parse Aadhaar bytes
            const fields = await decodeAadhaarQR(rawQrString);

            // 2. Extract JPEG 2000 photo if present
            let photoBase64 = null;
            if (fields.imageBytes && fields.imageBytes.length) {
                try {
                    const photoData = await decodeJp2Image(fields.imageBytes);
                    if (photoData && photoData.data) {
                        photoBase64 = photoData.data;
                    }
                } catch (e) {
                    console.warn("Mobile photo decode error:", e);
                }
            }

            // 3. Format into standardized JSON
            decodedPayload = formatAadhaarJson(fields, photoBase64, rawQrString);

            // 4. Render review screen
            renderReviewScreen(decodedPayload);
        } catch (err) {
            console.error(err);
            setMobileStatus(`Decryption failed: ${err.message}`, true);
            setTimeout(() => {
                camera.startCamera();
            }, 2500);
        }
    }

    function renderReviewScreen(data) {
        dom.screenScanner?.classList.add("d-none");
        dom.screenReview?.classList.remove("d-none");
        dom.screenSuccess?.classList.add("d-none");

        dom.reviewName.textContent = data.name || "N/A";
        dom.reviewDobGender.textContent = `DOB: ${data.dob || "N/A"} | Gender: ${data.gender || "N/A"}`;
        dom.reviewMobile.textContent = data.mobileLast4 ? `Mobile: XXXXXX${data.mobileLast4}` : "Mobile: Not registered";
        dom.reviewAddress.textContent = data.address?.full || "N/A";
        dom.reviewAadhaar.textContent = data.aadhaarNumber || "XXXX XXXX ????";

        if (data.photoBase64) {
            dom.reviewPhoto.src = data.photoBase64;
        } else {
            dom.reviewPhoto.src = "images/default_user.svg";
        }
    }

    async function sendDataToErp() {
        if (!decodedPayload || !ROOM_ID) return;

        dom.btnSendToErp.disabled = true;
        dom.btnSendToErp.innerHTML = `<span class="spinner-border spinner-border-sm me-2" role="status"></span> Transmitting to Desktop...`;
        dom.sendStatus.textContent = "";

        try {
            const response = await fetch(API_ENDPOINT, {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({
                    room: ROOM_ID,
                    data: decodedPayload,
                }),
            });

            const result = await response.json();

            if (result.success) {
                dom.screenReview?.classList.add("d-none");
                dom.screenSuccess?.classList.remove("d-none");
            } else {
                throw new Error(result.error || "Server rejected transmission.");
            }
        } catch (err) {
            dom.btnSendToErp.disabled = false;
            dom.btnSendToErp.textContent = "🚀 Retry Sending to ERP";
            dom.sendStatus.innerHTML = `<span class="text-danger">Error: ${err.message}</span>`;
        }
    }

    function resetScanner() {
        decodedPayload = null;
        dom.screenSuccess?.classList.add("d-none");
        dom.screenReview?.classList.add("d-none");
        dom.screenScanner?.classList.remove("d-none");
        if (dom.btnSendToErp) {
            dom.btnSendToErp.disabled = false;
            dom.btnSendToErp.textContent = "🚀 Send Data to ERP Dashboard";
        }
        if (dom.sendStatus) dom.sendStatus.textContent = "";

        camera.startCamera().then(started => {
            if (started && dom.placeholder) dom.placeholder.style.display = "none";
        });
    }

    // Event Wireup
    if (dom.btnSwitchCam) dom.btnSwitchCam.addEventListener("click", () => camera.switchCamera());
    if (dom.btnSendToErp) dom.btnSendToErp.addEventListener("click", sendDataToErp);
    if (dom.btnScanAgain) dom.btnScanAgain.addEventListener("click", resetScanner);
    if (dom.btnSuccessNew) dom.btnSuccessNew.addEventListener("click", resetScanner);

    if (dom.fileInput) {
        dom.fileInput.addEventListener("change", (e) => {
            handleFileUpload(e, {
                canvas: dom.canvas,
                onQrDetected: handleQrDetection,
                onStatus: (msg, type) => setMobileStatus(msg, type === "error"),
                onPlaceholderHide: () => {
                    if (dom.placeholder) dom.placeholder.style.display = "none";
                },
            });
        });
    }

    // Auto-start camera if room is valid
    if (ROOM_ID) {
        camera.startCamera().then(started => {
            if (started && dom.placeholder) dom.placeholder.style.display = "none";
        });
    }
</script>
</body>
</html>

