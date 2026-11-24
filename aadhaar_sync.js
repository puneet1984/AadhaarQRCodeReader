/**
 * aadhaar_sync.js
 *
 * Standalone Vanilla JavaScript Controller for Aadhaar Tri-Mode ERP Integration.
 * Designed for non-disruptive, plug-and-play inclusion in live ERP registration forms.
 *
 * Operational Responsibilities:
 * - Mode A: Initializes desktop webcam capture and live jsQR frame analysis.
 * - Mode B: Starts 2.5s interval background polling against the transient API sync bridge.
 * - Mode C: Manages drag-and-drop & file picker event handling for MFD flatbed scanner uploads.
 * - Form Injection Hook: Normalizes dates to YYYY-MM-DD and maps decoded values to ERP input IDs.
 */

import { decodeAadhaarQR } from "./script/modules/aadhaar.js";
import { decodeJp2Image } from "./script/modules/jpeg_decoder.js";
import { formatAadhaarJson } from "./script/utils/format.js";

(function () {
    "use strict";

    // ─── Default ERP Input ID & Name Mappings ──────────────────────────────────
    // Custom ERP field IDs can be overridden via `window.AadhaarFieldMap = { name: "my_name_id" };`
    const defaultFieldMap = {
        first_name:     ["first_name", "patient_first_name", "firstName"],
        middle_name:    ["middle_name", "patient_middle_name", "relative_name"],
        last_name:      ["last_name", "patient_last_name", "lastName"],
        relation:       ["relation", "relation_type", "relative_relation"],
        name:           ["patient_name", "name", "full_name", "patientName"],
        dob:            ["dob", "patient_dob", "birth_date", "birthdate"],
        age:            ["age", "patient_age"],
        gender:         ["gender", "patient_gender", "sex"],
        aadhaar_no:     ["aadhar_number", "patient_aadhaar", "aadhaar_no", "aadhaar_number", "national_id"],
        mobile:         ["contact", "patient_mobile", "mobile", "contact_no", "phone", "phone_number"],
        email:          ["email", "patient_email"],
        careof:         ["careof", "guardian_name", "father_name", "care_of"],
        address:        ["address", "patient_address", "residential_address", "full_address"],
        house:          ["address_house", "house", "building"],
        street:         ["address_street", "street"],
        landmark:       ["address_landmark", "landmark"],
        location:       ["address_location", "locality"],
        city:           ["city", "address_city", "district", "vtc"],
        state:          ["state", "address_state"],
        tehsil:         ["tehsil", "address_tehsil", "subdistrict"],
        pincode:        ["pincode", "address_pincode", "zipcode", "postal_code"],
        photo_preview:  ["patient_photo_preview", "photo_preview", "patient_avatar"],
        photo_base64:   ["photo_base64", "patient_photo_base64", "aadhaar_photo_input"],
    };

    /**
     * Finds the first existing DOM element from an array of candidate IDs or name attributes.
     *
     * @param {string[]} candidateIds
     * @returns {HTMLElement|null}
     */
    function findField(candidateIds) {
        if (!Array.isArray(candidateIds)) candidateIds = [candidateIds];
        for (const key of candidateIds) {
            // 1. Match by ID
            let el = document.getElementById(key);
            if (el) return el;
            // 2. Match by name attribute
            el = document.querySelector(`[name="${key}"]`);
            if (el) return el;
        }
        return null;
    }

    /**
     * Normalizes Aadhaar date string (DD-MM-YYYY or DD/MM/YYYY) to standard ISO YYYY-MM-DD.
     * Required by HTML5 <input type="date"> elements.
     *
     * @param {string} dateStr
     * @returns {string} Formatted YYYY-MM-DD or original string if unparseable.
     */
    function normalizeAadhaarDate(dateStr) {
        if (!dateStr || typeof dateStr !== "string") return "";
        const clean = dateStr.trim();

        // Match DD-MM-YYYY or DD/MM/YYYY
        const dmy = clean.match(/^(\d{1,2})[-\/](\d{1,2})[-\/](\d{4})$/);
        if (dmy) {
            const day = dmy[1].padStart(2, "0");
            const month = dmy[2].padStart(2, "0");
            const year = dmy[3];
            return `${year}-${month}-${day}`;
        }

        // Match YYYY-MM-DD
        const ymd = clean.match(/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})$/);
        if (ymd) {
            const year = ymd[1];
            const month = ymd[2].padStart(2, "0");
            const day = ymd[3].padStart(2, "0");
            return `${year}-${month}-${day}`;
        }

        // Match Year only (e.g., "1988")
        if (/^\d{4}$/.test(clean)) {
            return `${clean}-01-01`;
        }

        return clean;
    }

    /**
     * Injects a value into an input/select/textarea and triggers native input/change events
     * so reactive frameworks or ERP validation scripts register the updated data.
     *
     * @param {HTMLElement} el
     * @param {string} val
     */
    function setFieldValue(el, val) {
        if (!el || val === undefined || val === null) return;

        if (el.tagName === "SELECT") {
            const lowerVal = String(val).toLowerCase();
            let matched = false;
            for (let i = 0; i < el.options.length; i++) {
                const optText = el.options[i].text.toLowerCase();
                const optVal = el.options[i].value.toLowerCase();
                if (optVal === lowerVal || optText === lowerVal || (lowerVal === "male" && optVal === "m") || (lowerVal === "female" && optVal === "f")) {
                    el.selectedIndex = i;
                    matched = true;
                    break;
                }
            }
            if (!matched) el.value = val;
        } else if (el.type === "checkbox" || el.type === "radio") {
            el.checked = Boolean(val);
        } else {
            el.value = val;
        }

        el.dispatchEvent(new Event("input", { bubbles: true }));
        el.dispatchEvent(new Event("change", { bubbles: true }));
    }

    /**
     * Dedicated Form Injection Hook: Injects decoded Aadhaar demographic fields into ERP form.
     * Supports both unified name inputs and split first/middle/relation/last name inputs (as in add_patient.php).
     *
     * @param {Record<string, any>} payload Decoded Aadhaar data packet.
     */
    function injectIntoErpForm(payload) {
        const fieldMap = Object.assign({}, defaultFieldMap, window.AadhaarFieldMap || {});

        // 1. Personal Names (Supports single name input or split first_name/middle_name/relation/last_name)
        const fullNameEl = findField(fieldMap.name);
        if (fullNameEl && payload.name) setFieldValue(fullNameEl, payload.name);

        const firstNameEl = findField(fieldMap.first_name);
        const lastNameEl  = findField(fieldMap.last_name);
        if (firstNameEl && payload.name) {
            const parts = payload.name.trim().split(/\s+/);
            if (parts.length === 1) {
                setFieldValue(firstNameEl, parts[0]);
            } else if (parts.length === 2) {
                setFieldValue(firstNameEl, parts[0]);
                if (lastNameEl) setFieldValue(lastNameEl, parts[1]);
            } else {
                setFieldValue(firstNameEl, parts[0]);
                if (lastNameEl) setFieldValue(lastNameEl, parts.slice(1).join(" "));
            }
        }

        // 2. Relative & Guardian Care-Of Parsing (e.g. "S/O: Ramesh", "W/O: Anil", "D/O: ...")
        const rawCareof = (payload.careof || payload.address?.careof || "").trim();
        if (rawCareof) {
            const careMatch = rawCareof.match(/^(S\/O|W\/O|D\/O|C\/O)[:\s]+(.*)$/i);
            const relationEl = findField(fieldMap.relation);
            const middleNameEl = findField(fieldMap.middle_name);

            if (careMatch) {
                const relType = careMatch[1].toUpperCase() === "C/O" ? "S/O" : careMatch[1].toUpperCase();
                const relName = careMatch[2].trim();

                if (relationEl) {
                    setFieldValue(relationEl, relType);
                    if (typeof window.updateGender === "function") {
                        window.updateGender(relationEl.value);
                    }
                }
                if (middleNameEl) {
                    setFieldValue(middleNameEl, relName);
                }
            } else {
                if (middleNameEl) setFieldValue(middleNameEl, rawCareof);
            }
        }

        // 3. Date of Birth & Automatic Age Calculation
        const dobEl = findField(fieldMap.dob);
        if (dobEl && payload.dob) {
            const normalizedDob = normalizeAadhaarDate(payload.dob);
            setFieldValue(dobEl, normalizedDob);

            // Automatically invoke calculateAge() if defined on the ERP page (e.g. add_patient.php)
            if (typeof window.calculateAge === "function") {
                window.calculateAge(normalizedDob);
            }
        }

        // 4. Gender (Supports M/F/O codes or full Male/Female labels)
        const genderEl = findField(fieldMap.gender);
        if (genderEl) {
            let genderVal = payload.gender;
            if (genderVal === "Male") genderVal = "M";
            if (genderVal === "Female") genderVal = "F";
            setFieldValue(genderEl, genderVal);
        }

        // 5. Aadhar Number
        const aadhaarEl = findField(fieldMap.aadhaar_no);
        if (aadhaarEl) {
            if (payload.aadhaarNumber && /^\d{12}$/.test(payload.aadhaarNumber)) {
                setFieldValue(aadhaarEl, payload.aadhaarNumber);
            } else if (payload.aadhaarLast4) {
                // If the input has pattern="\d{12}", populate placeholder or focus
                if (aadhaarEl.pattern && aadhaarEl.pattern.includes("12")) {
                    aadhaarEl.placeholder = `Last 4 digits: ${payload.aadhaarLast4} (Enter full 12)`;
                } else {
                    setFieldValue(aadhaarEl, payload.aadhaarNumber || `XXXX XXXX ${payload.aadhaarLast4}`);
                }
            }
        }

        // 6. Contact / Mobile Number
        const mobileEl = findField(fieldMap.mobile);
        if (mobileEl) {
            if (payload.mobile && /^[6-9]\d{9}$/.test(payload.mobile)) {
                setFieldValue(mobileEl, payload.mobile);
            } else if (payload.mobileLast4) {
                mobileEl.placeholder = `Registered mobile ends in: ${payload.mobileLast4}`;
            }
        }

        // 7. Email
        const emailEl = findField(fieldMap.email);
        if (emailEl && payload.email) {
            setFieldValue(emailEl, payload.email);
        }

        // 8. Address Assembly
        const addr = payload.address || {};
        const addressParts = [
            addr.house,
            addr.street,
            addr.landmark,
            addr.location,
            addr.vtc,
            addr.district,
            addr.pincode ? `PIN: ${addr.pincode}` : null
        ].filter(Boolean);

        const fullAddr = addr.full || addressParts.join(", ");
        const addrEl = findField(fieldMap.address);
        if (addrEl) setFieldValue(addrEl, fullAddr);

        // 9. State, District/City & Tehsil (With Select2 Integration)
        const stateEl = findField(fieldMap.state);
        if (stateEl && addr.state) {
            setFieldValue(stateEl, addr.state);
            if (window.jQuery && window.jQuery(stateEl).hasClass("select2-hidden-accessible")) {
                window.jQuery(stateEl).val(addr.state).trigger("change");
            }
        }

        const cityEl = findField(fieldMap.city);
        if (cityEl && (addr.district || addr.vtc)) {
            const districtVal = addr.district || addr.vtc;
            setFieldValue(cityEl, districtVal);
            if (window.jQuery && window.jQuery(cityEl).hasClass("select2-hidden-accessible")) {
                window.jQuery(cityEl).val(districtVal).trigger("change");
            }
        }

        const tehsilEl = findField(fieldMap.tehsil);
        if (tehsilEl && addr.subdistrict) {
            setFieldValue(tehsilEl, addr.subdistrict);
            if (window.jQuery && window.jQuery(tehsilEl).hasClass("select2-hidden-accessible")) {
                window.jQuery(tehsilEl).val(addr.subdistrict).trigger("change");
            }
        }

        // 10. Photo Preview & Hidden Base64 Input
        if (payload.photoBase64) {
            const photoPreview = findField(fieldMap.photo_preview);
            if (photoPreview) {
                photoPreview.src = payload.photoBase64;
                photoPreview.style.display = "";
            }

            const photoHidden = findField(fieldMap.photo_base64);
            if (photoHidden) {
                setFieldValue(photoHidden, payload.photoBase64);
            }
        }

        // Custom Event Broadcast for external ERP hooks
        window.dispatchEvent(new CustomEvent("aadhaarSynced", { detail: payload }));
    }

    // ─── UI Alert Helper ────────────────────────────────────────────────────────
    function showWidgetAlert(message, type = "success") {
        const alertBox = document.getElementById("aadhaar-widget-alert");
        const alertText = document.getElementById("aadhaar-widget-alert-text");
        if (!alertBox || !alertText) return;

        alertBox.className = `alert alert-${type} mt-3 mb-0 py-2 px-3 small d-flex justify-content-between align-items-center`;
        alertText.innerHTML = message;
        alertBox.classList.remove("d-none");
    }

    // ─── Main Controller Class ──────────────────────────────────────────────────
    class AadhaarSyncController {
        constructor() {
            this.widget = document.getElementById("aadhaar-sync-widget");
            if (!this.widget) return;

            this.syncRoom = this.widget.dataset.syncRoom;
            this.apiEndpoint = this.widget.dataset.apiEndpoint || "aadhaar_api_handlers.php";
            this.mobileUrl = this.widget.dataset.mobileUrl;

            // Camera Controller State (Mode A)
            this.cameraStream = null;
            this.cameraScanning = false;
            this.animFrameId = null;

            // Polling State (Mode B)
            this.pollTimer = null;
            this.pollingIntervalMs = 2500;

            this.init();
        }

        init() {
            this.initModeA();
            this.initModeB();
            this.initModeC();
            this.initGeneralEvents();
        }

        initGeneralEvents() {
            // Dismiss alert
            const btnCloseAlert = document.getElementById("btn-close-sync-alert");
            if (btnCloseAlert) {
                btnCloseAlert.addEventListener("click", () => {
                    document.getElementById("aadhaar-widget-alert")?.classList.add("d-none");
                });
            }

            // Refresh room
            const btnResetSync = document.getElementById("btn-reset-sync");
            if (btnResetSync) {
                btnResetSync.addEventListener("click", () => {
                    window.location.reload();
                });
            }

            // Tab switch auto-stop camera if navigating away from Mode A
            const tabs = document.querySelectorAll('#aadhaar-sync-tabs button[data-bs-toggle="tab"]');
            tabs.forEach(tab => {
                tab.addEventListener("shown.bs.tab", (e) => {
                    if (e.target.id !== "tab-mode-a" && this.cameraScanning) {
                        this.stopDesktopCamera();
                    }
                });
            });
        }

        // ─── MODE A: Desktop Webcam Logic ───────────────────────────────────────
        initModeA() {
            const btnStart = document.getElementById("btn-start-desktop-cam");
            const btnStop = document.getElementById("btn-stop-desktop-cam");
            const video = document.getElementById("desktop-scanner-video");
            const canvas = document.getElementById("desktop-scanner-canvas");
            const placeholder = document.getElementById("desktop-camera-placeholder");
            const overlay = document.getElementById("desktop-scanner-overlay");
            const statusText = document.getElementById("desktop-cam-status");

            if (!btnStart || !btnStop || !video || !canvas) return;

            btnStart.addEventListener("click", async () => {
                btnStart.disabled = true;
                statusText.textContent = "Requesting webcam permission...";

                try {
                    const isLocal =
                        window.location.hostname === "localhost" ||
                        window.location.hostname === "127.0.0.1" ||
                        window.location.hostname === "[::1]";
                    const isSecure = window.isSecureContext || isLocal;

                    if (!isSecure) {
                        throw new Error(
                            "Webcam requires HTTPS or localhost. Browsers block camera access over insecure HTTP (" +
                            window.location.origin + "). Please use HTTPS or access via http://localhost."
                        );
                    }

                    if (!navigator.mediaDevices || typeof navigator.mediaDevices.getUserMedia !== "function") {
                        const legacyGetUserMedia =
                            navigator.getUserMedia ||
                            navigator.webkitGetUserMedia ||
                            navigator.mozGetUserMedia ||
                            navigator.msGetUserMedia;
                        if (legacyGetUserMedia) {
                            this.cameraStream = await new Promise((resolve, reject) => {
                                legacyGetUserMedia.call(navigator, { video: true }, resolve, reject);
                            });
                        } else {
                            throw new Error("Webcam API is not supported in this browser. Please use HTTPS or a modern browser.");
                        }
                    } else {
                        try {
                            this.cameraStream = await navigator.mediaDevices.getUserMedia({
                                video: { facingMode: "user", width: { ideal: 1280 }, height: { ideal: 1280 } }
                            });
                        } catch (mediaErr) {
                            if (mediaErr.name === "OverconstrainedError" || mediaErr.name === "ConstraintNotSatisfiedError") {
                                this.cameraStream = await navigator.mediaDevices.getUserMedia({ video: true });
                            } else {
                                throw mediaErr;
                            }
                        }
                    }

                    video.srcObject = this.cameraStream;
                    await video.play();

                    video.style.display = "block";
                    if (placeholder) placeholder.style.display = "none";
                    if (overlay) overlay.style.display = "block";

                    btnStop.disabled = false;
                    this.cameraScanning = true;
                    statusText.textContent = "Status: Scanning... hold QR code steady.";

                    this.scanDesktopFrame(video, canvas);
                } catch (err) {
                    btnStart.disabled = false;
                    let msg = err.message || String(err);
                    if (err.name === "NotAllowedError" || err.name === "PermissionDeniedError") {
                        msg = "Camera permission was denied. Please allow camera access in browser settings.";
                    } else if (err.name === "NotFoundError" || err.name === "DevicesNotFoundError") {
                        msg = "No webcam found on this device.";
                    } else if (err.name === "NotReadableError" || err.name === "TrackStartError") {
                        msg = "Camera is in use by another application or tab.";
                    }
                    statusText.textContent = `Camera error: ${msg}`;
                    showWidgetAlert(`<strong>Webcam Error:</strong> ${msg}`, "danger");
                }
            });

            btnStop.addEventListener("click", () => this.stopDesktopCamera());
        }

        stopDesktopCamera() {
            this.cameraScanning = false;
            if (this.animFrameId) {
                cancelAnimationFrame(this.animFrameId);
                this.animFrameId = null;
            }
            if (this.cameraStream) {
                this.cameraStream.getTracks().forEach(t => t.stop());
                this.cameraStream = null;
            }

            const video = document.getElementById("desktop-scanner-video");
            const placeholder = document.getElementById("desktop-camera-placeholder");
            const overlay = document.getElementById("desktop-scanner-overlay");
            const btnStart = document.getElementById("btn-start-desktop-cam");
            const btnStop = document.getElementById("btn-stop-desktop-cam");
            const statusText = document.getElementById("desktop-cam-status");

            if (video) {
                video.srcObject = null;
                video.style.display = "none";
            }
            if (placeholder) placeholder.style.display = "flex";
            if (overlay) overlay.style.display = "none";
            if (btnStart) btnStart.disabled = false;
            if (btnStop) btnStop.disabled = true;
            if (statusText) statusText.textContent = "Status: Camera stopped.";
        }

        scanDesktopFrame(video, canvas) {
            if (!this.cameraScanning) return;

            if (video.readyState === video.HAVE_ENOUGH_DATA) {
                const size = Math.min(video.videoWidth, video.videoHeight);
                canvas.width = size;
                canvas.height = size;

                const ctx = canvas.getContext("2d");
                const ox = (video.videoWidth - size) / 2;
                const oy = (video.videoHeight - size) / 2;
                ctx.drawImage(video, ox, oy, size, size, 0, 0, size, size);

                const imgData = ctx.getImageData(0, 0, size, size);
                const code = window.jsQR ? window.jsQR(imgData.data, imgData.width, imgData.height, { inversionAttempts: "dontInvert" }) : null;

                if (code && code.data && code.data.trim()) {
                    this.stopDesktopCamera();
                    this.processRawAadhaarQr(code.data.trim(), "Desktop Webcam (Mode A)");
                    return;
                }
            }

            this.animFrameId = requestAnimationFrame(() => this.scanDesktopFrame(video, canvas));
        }

        // ─── MODE B: Mobile Sync Background Poller ───────────────────────────────
        initModeB() {
            const qrContainer = document.getElementById("mobile-sync-qr-render");
            const btnCopyUrl = document.getElementById("btn-copy-mobile-url");
            const urlInput = document.getElementById("mobile-url-copy-input");

            // Render Mobile Link QR Code using QRCode.js
            if (qrContainer && this.mobileUrl) {
                qrContainer.innerHTML = "";
                if (typeof window.QRCode === "function") {
                    new window.QRCode(qrContainer, {
                        text: this.mobileUrl,
                        width: 170,
                        height: 170,
                        colorDark: "#11140f",
                        colorLight: "#ffffff",
                        correctLevel: window.QRCode.CorrectLevel.M,
                    });
                } else {
                    // Fallback to QR server image if CDN blocked
                    const img = document.createElement("img");
                    img.src = `https://api.qrserver.com/v1/create-qr-code/?size=170x170&data=${encodeURIComponent(this.mobileUrl)}`;
                    img.alt = "Scan with Mobile";
                    img.className = "img-fluid";
                    qrContainer.appendChild(img);
                }
            }

            // Copy Link button
            if (btnCopyUrl && urlInput) {
                btnCopyUrl.addEventListener("click", () => {
                    urlInput.select();
                    navigator.clipboard.writeText(urlInput.value).then(() => {
                        btnCopyUrl.textContent = "Copied!";
                        setTimeout(() => { btnCopyUrl.textContent = "Copy Link"; }, 2000);
                    });
                });
            }

            // Start background polling loop every 2.5 seconds
            this.startPolling();
        }

        startPolling() {
            if (this.pollTimer) clearInterval(this.pollTimer);

            const pollingText = document.getElementById("polling-status-text");
            const indicator = document.getElementById("polling-indicator");

            this.pollTimer = setInterval(async () => {
                if (!this.syncRoom) return;

                try {
                    const pollUrl = `${this.apiEndpoint}?action=poll&room=${encodeURIComponent(this.syncRoom)}&_t=${Date.now()}`;
                    const res = await fetch(pollUrl);
                    if (!res.ok) return;

                    const json = await res.json();
                    if (json.status === "ready" && json.data) {
                        // Data packet received from mobile!
                        clearInterval(this.pollTimer);
                        this.pollTimer = null;

                        if (indicator) {
                            indicator.className = "badge bg-success";
                            indicator.textContent = "✓";
                        }
                        if (pollingText) {
                            pollingText.textContent = "Transmission received! Data synchronized with form.";
                        }

                        injectIntoErpForm(json.data);
                        showWidgetAlert(`<strong>Success!</strong> Patient data received wirelessly from mobile device (${json.data.name || "Aadhaar Card"}). Form fields populated.`, "success");
                    }
                } catch (err) {
                    console.debug("Aadhaar polling tick failed:", err);
                }
            }, this.pollingIntervalMs);
        }

        // ─── MODE C: MFD / Flatbed Scanner Direct Capture, Clipboard & Drop ────
        initModeC() {
            const dropZone = document.getElementById("mfd-drop-zone");
            const fileInput = document.getElementById("mfd-file-input");
            const canvas = document.getElementById("mfd-hidden-canvas");
            const statusText = document.getElementById("mfd-status");
            const btnPaste = document.getElementById("btn-paste-clipboard");
            const btnFlatbedScan = document.getElementById("btn-flatbed-scan");

            if (!canvas) return;

            // 1. Direct Clipboard Paste Button
            if (btnPaste) {
                btnPaste.addEventListener("click", async () => {
                    try {
                        if (navigator.clipboard && navigator.clipboard.read) {
                            const items = await navigator.clipboard.read();
                            for (const item of items) {
                                const imgType = item.types.find(t => t.startsWith("image/"));
                                if (imgType) {
                                    const blob = await item.getType(imgType);
                                    this.processMfdBlob(blob, "Clipboard Image", canvas, statusText);
                                    return;
                                }
                            }
                            showWidgetAlert("<strong>Clipboard Empty:</strong> No image found in clipboard. Please scan or copy an image first.", "warning");
                        } else {
                            showWidgetAlert("Press <strong>Ctrl + V</strong> on your keyboard to paste the scanned image directly.", "info");
                        }
                    } catch (err) {
                        showWidgetAlert("Please press <strong>Ctrl + V</strong> to paste the image directly from your scanner tool.", "info");
                    }
                });
            }

            // 2. Global Keyboard Paste Listener (Ctrl + V anywhere on page)
            window.addEventListener("paste", (e) => {
                const items = (e.clipboardData || window.clipboardData)?.items;
                if (!items) return;
                for (let i = 0; i < items.length; i++) {
                    if (items[i].type.indexOf("image") !== -1) {
                        const file = items[i].getAsFile();
                        if (file) {
                            e.preventDefault();
                            showWidgetAlert("<strong>Clipboard Paste Detected:</strong> Processing scanned image from memory...", "info");
                            this.processMfdBlob(file, "Pasted Scanner Image", canvas, statusText);
                            break;
                        }
                    }
                }
            });

            // 3. Direct Flatbed Hardware Scan via Local Bridge
            if (btnFlatbedScan) {
                btnFlatbedScan.addEventListener("click", async () => {
                    btnFlatbedScan.disabled = true;
                    btnFlatbedScan.innerHTML = `<span class="spinner-border spinner-border-sm me-1" role="status"></span> Scanning Flatbed...`;
                    if (statusText) statusText.textContent = "Triggering flatbed scanner carriage...";

                    try {
                        // Connect to local scanner bridge (e.g. NAPS2 / TWAIN / WIA bridge on localhost)
                        const bridgeUrl = window.AadhaarScannerBridgeUrl || "http://127.0.0.1:8089/scan";
                        const res = await fetch(bridgeUrl, { method: "POST" });
                        if (!res.ok) throw new Error(`Bridge returned HTTP ${res.status}`);

                        const data = await res.json();
                        if (data && (data.image || data.data)) {
                            const base64Str = data.image || data.data;
                            this.processMfdDataUrl(base64Str, "Direct Flatbed TWAIN Scan", canvas, statusText);
                        } else {
                            throw new Error("No image data received from local scanner bridge.");
                        }
                    } catch (err) {
                        console.warn("Direct scanner bridge unreachable:", err);
                        showWidgetAlert(
                            `<strong>Direct Flatbed Bridge Note:</strong> Could not connect to local scan agent at <code>127.0.0.1:8089</code>.<br>` +
                            `Tip: You can scan directly to clipboard in your scanner utility and press <strong>Ctrl+V</strong> here without saving any file!`,
                            "warning"
                        );
                        if (statusText) statusText.textContent = "Bridge idle. Press Ctrl+V to paste or drag image file.";
                    } finally {
                        btnFlatbedScan.disabled = false;
                        btnFlatbedScan.innerHTML = `🖨️ <strong>Scan from Flatbed Now</strong>`;
                    }
                });
            }

            // 4. File Picker Trigger
            if (dropZone && fileInput) {
                dropZone.addEventListener("click", () => fileInput.click());

                ["dragenter", "dragover"].forEach(eventName => {
                    dropZone.addEventListener(eventName, (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        dropZone.style.borderColor = "#198754";
                        dropZone.style.backgroundColor = "#e8f5e9";
                    });
                });

                ["dragleave", "drop"].forEach(eventName => {
                    dropZone.addEventListener(eventName, (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        dropZone.style.borderColor = "#0d6efd";
                        dropZone.style.backgroundColor = "#f8f9fa";
                    });
                });

                dropZone.addEventListener("drop", (e) => {
                    const dt = e.dataTransfer;
                    const file = dt.files && dt.files[0];
                    if (file) this.processMfdBlob(file, file.name, canvas, statusText);
                });

                fileInput.addEventListener("change", (e) => {
                    const file = e.target.files && e.target.files[0];
                    if (file) this.processMfdBlob(file, file.name, canvas, statusText);
                    fileInput.value = "";
                });
            }
        }

        processMfdBlob(blob, sourceName, canvas, statusText) {
            if (!blob.type.startsWith("image/")) {
                showWidgetAlert("<strong>Invalid File:</strong> Please provide a valid image (JPG, PNG, BMP, TIFF).", "warning");
                return;
            }

            if (statusText) statusText.textContent = `Reading ${sourceName}... parsing pixel matrix in memory...`;

            const reader = new FileReader();
            reader.onload = (e) => {
                this.processMfdDataUrl(e.target.result, sourceName, canvas, statusText);
            };
            reader.readAsDataURL(blob);
        }

        processMfdDataUrl(dataUrl, sourceName, canvas, statusText) {
            const img = new Image();
            img.onload = () => {
                canvas.width = img.naturalWidth;
                canvas.height = img.naturalHeight;

                const ctx = canvas.getContext("2d");
                ctx.drawImage(img, 0, 0);

                const imgData = ctx.getImageData(0, 0, canvas.width, canvas.height);
                if (!window.jsQR) {
                    if (statusText) statusText.textContent = "Error: jsQR library is not loaded.";
                    return;
                }

                const code = window.jsQR(imgData.data, imgData.width, imgData.height, {
                    inversionAttempts: "attemptBoth",
                });

                if (code && code.data && code.data.trim()) {
                    if (statusText) statusText.textContent = `QR detected in ${sourceName}! Decrypting data...`;
                    this.processRawAadhaarQr(code.data.trim(), `MFD Scanner (${sourceName})`);
                } else {
                    if (statusText) statusText.textContent = `No QR code found in ${sourceName}.`;
                    showWidgetAlert(`<strong>No QR Code Detected:</strong> Could not detect an Aadhaar QR code in <em>${sourceName}</em>. Ensure the scan is crisp and contains the Secure QR code.`, "warning");
                }
            };
            img.onerror = () => {
                if (statusText) statusText.textContent = `Failed to load image from ${sourceName}.`;
            };
            img.src = dataUrl;
        }

        // ─── Core Offline Decryption Pipeline ──────────────────────────────────
        async processRawAadhaarQr(rawPayload, sourceMode) {
            showWidgetAlert(`<strong>Processing:</strong> Decrypting Secure QR payload from ${sourceMode}...`, "info");

            try {
                // 1. Client-side decompression & Latin-1 delimiter field parsing
                const fields = await decodeAadhaarQR(rawPayload);

                // 2. Client-side JPEG 2000 photo decompression via OpenJPEG WASM
                let photoBase64 = null;
                if (fields.imageBytes && fields.imageBytes.length) {
                    try {
                        const photoData = await decodeJp2Image(fields.imageBytes);
                        if (photoData && photoData.data) {
                            photoBase64 = photoData.data;
                        }
                    } catch (err) {
                        console.warn("JPEG 2000 photo decode failed:", err);
                    }
                }

                // 3. Transform to clean, normalized JSON schema
                const structuredData = formatAadhaarJson(fields, photoBase64, rawPayload);

                // 4. Form Injection Hook: Populate production ERP form
                injectIntoErpForm(structuredData);

                showWidgetAlert(
                    `<strong>Verified &amp; Synced!</strong> Aadhaar card for <strong>${structuredData.name || "Resident"}</strong> (${structuredData.aadhaarNumber}) successfully imported via ${sourceMode}.`,
                    "success"
                );
            } catch (err) {
                console.error("Aadhaar decryption error:", err);
                showWidgetAlert(`<strong>Decryption Failed:</strong> ${err.message}. Ensure this is an authentic UIDAI Secure QR code.`, "danger");
            }
        }
    }

    // Initialize when DOM content is ready
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", () => new AadhaarSyncController());
    } else {
        new AadhaarSyncController();
    }
})();

