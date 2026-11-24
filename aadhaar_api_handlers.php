<?php
/**
 * aadhaar_api_handlers.php
 *
 * Production Backend Communication Bridge for Aadhaar Tri-Mode Sync.
 * Handles cross-device mobile push and desktop polling requests.
 *
 * Architecture:
 * - Operates as both an includable function library AND a direct JSON API endpoint.
 * - Uses a high-performance, file-based transient sync cache (with file locking)
 *   so desktop and mobile devices can synchronize across separate HTTP sessions
 *   without requiring redis or complex infrastructure.
 * - Automatically purges expired sessions (TTL = 10 minutes).
 */

// Start session if not already active and headers not sent
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    @session_start();
}

/**
 * Returns the transient cache storage directory path, creating it if necessary.
 *
 * @return string Path to the writable sync directory.
 */
function get_aadhaar_sync_dir(): string {
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aadhaar_sync_cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
    return $dir;
}

/**
 * Sanitizes and validates a sync room / session token.
 * Only alphanumeric characters, dashes, and underscores are allowed.
 *
 * @param string $sessionId
 * @return string Cleaned session ID.
 * @throws InvalidArgumentException If token format is invalid.
 */
function sanitize_sync_session_id(string $sessionId): string {
    $clean = preg_replace('/[^a-zA-Z0-9_\-]/', '', trim($sessionId));
    if (empty($clean) || strlen($clean) < 6 || strlen($clean) > 64) {
        throw new InvalidArgumentException('Invalid or malformed sync session ID.');
    }
    return $clean;
}

/**
 * Normalizes an Aadhaar date string (DD-MM-YYYY or DD/MM/YYYY) to standard ISO YYYY-MM-DD.
 * Required for HTML5 date inputs (<input type="date">) and database DATE columns.
 *
 * @param string|null $dob
 * @return string|null Formatted date string (YYYY-MM-DD) or original/null if unparseable.
 */
function normalize_aadhaar_dob(?string $dob): ?string {
    if (empty($dob)) {
        return null;
    }
    $dob = trim($dob);

    // Match DD-MM-YYYY or DD/MM/YYYY
    if (preg_match('/^(\d{1,2})[-\/](\d{1,2})[-\/](\d{4})$/', $dob, $m)) {
        $day   = str_pad($m[1], 2, '0', STR_PAD_LEFT);
        $month = str_pad($m[2], 2, '0', STR_PAD_LEFT);
        $year  = $m[3];
        return "{$year}-{$month}-{$day}";
    }

    // Match YYYY-MM-DD
    if (preg_match('/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})$/', $dob, $m)) {
        $year  = $m[1];
        $month = str_pad($m[2], 2, '0', STR_PAD_LEFT);
        $day   = str_pad($m[3], 2, '0', STR_PAD_LEFT);
        return "{$year}-{$month}-{$day}";
    }

    // Match Year only (e.g. "1985")
    if (preg_match('/^(\d{4})$/', $dob, $m)) {
        return "{$m[1]}-01-01";
    }

    return $dob;
}

/**
 * Performs automated garbage collection for expired transient sync files.
 * Cleans files older than $ttlSeconds (default: 600s / 10 minutes).
 *
 * @param int $ttlSeconds
 * @return void
 */
function purge_expired_sync_cache(int $ttlSeconds = 600): void {
    $dir = get_aadhaar_sync_dir();
    $files = @glob($dir . DIRECTORY_SEPARATOR . 'sync_*.json');
    if (!$files) {
        return;
    }
    $now = time();
    foreach ($files as $file) {
        if (is_file($file) && ($now - filemtime($file) > $ttlSeconds)) {
            @unlink($file);
        }
    }
}

/**
 * Handles incoming mobile push payload.
 * Validates, normalizes demographic dates, and stores the payload in the transient cache.
 *
 * @param string $sessionId Unique session / room token.
 * @param mixed $rawJsonData Array or raw JSON string received from mobile device.
 * @return array Standardized result response.
 */
function handle_mobile_push(string $sessionId, $rawJsonData): array {
    try {
        $cleanSessionId = sanitize_sync_session_id($sessionId);

        // Decode JSON payload if provided as raw string
        $data = is_array($rawJsonData) ? $rawJsonData : json_decode((string)$rawJsonData, true);
        if (!is_array($data) || empty($data)) {
            return [
                'success' => false,
                'error'   => 'Malformed or empty JSON payload received.',
            ];
        }

        // Normalize DOB if present
        if (!empty($data['dob'])) {
            $data['dob_raw'] = $data['dob'];
            $data['dob']     = normalize_aadhaar_dob($data['dob']);
        }
        if (isset($data['personal']['dob'])) {
            $data['personal']['dob_raw'] = $data['personal']['dob'];
            $data['personal']['dob']     = normalize_aadhaar_dob($data['personal']['dob']);
        }

        // Add metadata
        $data['_synced_at'] = date('c');
        $data['_session_id'] = $cleanSessionId;

        // Persist to atomic file cache with exclusive lock
        $dir = get_aadhaar_sync_dir();
        $targetFile = $dir . DIRECTORY_SEPARATOR . 'sync_' . hash('sha256', $cleanSessionId) . '.json';

        $payloadEncoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (file_put_contents($targetFile, $payloadEncoded, LOCK_EX) === false) {
            return [
                'success' => false,
                'error'   => 'Failed to write sync data to cache directory.',
            ];
        }

        // Periodic garbage collection (1 in 20 requests)
        if (mt_rand(1, 20) === 1) {
            purge_expired_sync_cache();
        }

        return [
            'success' => true,
            'message' => 'Aadhaar data successfully synced to desktop ERP session.',
            'room'    => $cleanSessionId,
        ];
    } catch (\Throwable $e) {
        return [
            'success' => false,
            'error'   => $e->getMessage(),
        ];
    }
}

/**
 * Checks if a pending Aadhaar payload exists for the given session ID.
 * If found, returns the payload and deletes the file immediately (one-time read).
 *
 * @param string $sessionId Unique session / room token.
 * @return array|null The decoded payload if found; null otherwise.
 */
function check_desktop_sync(string $sessionId): ?array {
    try {
        $cleanSessionId = sanitize_sync_session_id($sessionId);
        $dir = get_aadhaar_sync_dir();
        $targetFile = $dir . DIRECTORY_SEPARATOR . 'sync_' . hash('sha256', $cleanSessionId) . '.json';

        if (!file_exists($targetFile)) {
            return null;
        }

        $raw = @file_get_contents($targetFile);
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);

        // Flash behavior: delete immediately to prevent stale duplicate consumption
        @unlink($targetFile);

        return is_array($data) ? $data : null;
    } catch (\Throwable $e) {
        return null;
    }
}

// ─── Direct HTTP API Route Dispatcher ──────────────────────────────────────────
// Executes only when this script is requested directly via HTTP.
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = $_REQUEST['action'] ?? '';

    // Route: Mobile POST Push
    if ($method === 'POST' || $action === 'push') {
        $room = $_REQUEST['room'] ?? '';
        $rawInput = file_get_contents('php://input');

        // Check if payload came in POST fields or raw JSON body
        $jsonData = null;
        if (!empty($rawInput)) {
            $parsed = json_decode($rawInput, true);
            if (is_array($parsed)) {
                $jsonData = $parsed['data'] ?? $parsed['payload'] ?? $parsed;
                if (empty($room) && !empty($parsed['room'])) {
                    $room = $parsed['room'];
                }
            }
        }
        if (!$jsonData && isset($_POST['data'])) {
            $jsonData = $_POST['data'];
        }

        if (empty($room)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing required parameter: room']);
            exit;
        }

        $result = handle_mobile_push($room, $jsonData);
        if (!$result['success']) {
            http_response_code(422);
        }
        echo json_encode($result);
        exit;
    }

    // Route: Desktop GET Poll
    if ($method === 'GET' && $action === 'poll') {
        $room = $_GET['room'] ?? '';
        if (empty($room)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Missing required parameter: room']);
            exit;
        }

        $payload = check_desktop_sync($room);
        if ($payload !== null) {
            echo json_encode([
                'status'  => 'ready',
                'message' => 'Synchronized data retrieved successfully.',
                'data'    => $payload,
            ]);
        } else {
            echo json_encode([
                'status'  => 'waiting',
                'message' => 'Awaiting mobile device transmission...',
            ]);
        }
        exit;
    }

    // Route: Ping / Health Check
    if ($action === 'ping') {
        echo json_encode(['status' => 'ok', 'timestamp' => time()]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid action specified. Supported: push, poll, ping.']);
    exit;
}
