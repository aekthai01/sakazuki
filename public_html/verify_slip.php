<?php
/** Verify a bank-slip image and credit the authenticated account exactly once. */
ob_start();
// Wall-clock budget is anchored at the web request itself, not at the EasySlip
// call. This keeps upload/parsing/shared-ledger time inside the same customer SLA.
$requestStartedAt = isset($_SERVER['REQUEST_TIME_FLOAT']) && is_numeric($_SERVER['REQUEST_TIME_FLOAT'])
    ? (float) $_SERVER['REQUEST_TIME_FLOAT']
    : microtime(true);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ranking.php';
require_once __DIR__ . '/includes/store_bridge.php';
requireLogin(true);
requireActive(true);
accountVerificationRequireComplete(true);
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    ob_clean();
    jsonResponse(['success' => false, 'message' => 'Method not allowed']);
}
requireCsrfToken();

$userId = (int) ($_SESSION['user_id'] ?? 0);
$action = strtolower(trim((string) ($_POST['action'] ?? 'verify')));

// Status polling is intentionally lightweight and does not consume the stricter
// verification quota. It lets mobile browsers recover after a proxy timeout or
// an interrupted connection without uploading the same image again.
if ($action === 'status') {
    if (!checkRateLimit('verify_slip_status_' . $userId, 120, 600)) {
        http_response_code(429);
        ob_clean();
        jsonResponse([
            'success' => false,
            'pending' => true,
            'retryable' => true,
            'code' => 'status_rate_limited',
            'message' => 'ตรวจสอบสถานะถี่เกินไป กรุณารอสักครู่',
        ]);
    }
    $attemptId = normalizeSlipVerificationAttemptUuid($_POST['attempt_id'] ?? '');
    $statusSlipHash = strtolower(trim((string) ($_POST['slip_hash'] ?? '')));
    if (!preg_match('/^[a-f0-9]{64}$/D', $statusSlipHash)) $statusSlipHash = '';
    if ($attemptId === '' && $statusSlipHash === '') {
        http_response_code(422);
        ob_clean();
        jsonResponse(['success' => false, 'message' => 'รหัสติดตามรายการไม่ถูกต้อง']);
    }
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    ignore_user_abort(true);
    @set_time_limit(30);
    try {
        $statusDeadline = microtime(true) + 6.0;
        $result = getSlipVerificationJobStatusForUser($userId, $attemptId, $statusDeadline, $statusSlipHash);
        ob_clean();
        jsonResponse(is_array($result) ? $result : ['success' => false, 'message' => 'System error']);
    } catch (Throwable $e) {
        error_log('Slip status endpoint error: ' . $e->getMessage());
        ob_clean();
        jsonResponse(['success' => false, 'message' => 'ไม่สามารถอ่านสถานะรายการได้']);
    }
}

if ($action !== 'verify') {
    http_response_code(422);
    ob_clean();
    jsonResponse(['success' => false, 'message' => 'Invalid action']);
}

// The Admin switch must be enforced by the backend, not only by hiding the
// upload control. Status lookups above remain available so an already-finished
// attempt can still be recovered while verification is disabled.
if ((string) getSetting('easyslip_enabled') !== '1') {
    http_response_code(503);
    ob_clean();
    jsonResponse([
        'success' => false,
        'retryable' => false,
        'error_code' => 'easyslip_disabled',
        'message' => 'ระบบตรวจสอบสลิปธนาคารถูกปิดใช้งานชั่วคราว',
    ]);
}

if (!checkRateLimit('verify_slip_user_' . $userId, 10, 600)) {
    http_response_code(429);
    ob_clean();
    jsonResponse(['success' => false, 'message' => 'ตรวจสอบสลิปถี่เกินไป กรุณารอประมาณ 10 นาทีแล้วลองใหม่']);
}

$attemptId = normalizeSlipVerificationAttemptUuid($_POST['attempt_id'] ?? '');
if ($attemptId === '') $attemptId = generateSlipVerificationAttemptUuid();

$endpointLog = static function (string $stage, string $severity, string $message, array $details = []) use (&$attemptId, $userId): void {
    slipDebugLogEvent($stage, $severity, $message, array_merge([
        'attempt_uuid' => $attemptId,
        'user_id' => $userId,
    ], $details));
};
$endpointLog('endpoint_received', 'info', 'Slip verification endpoint received a request', [
    'include_request_context' => true,
    'request' => [
        'action' => $action,
        'upload_present' => isset($_FILES['slip_image']) && is_array($_FILES['slip_image']),
        'legacy_base64_present' => isset($_POST['slip_base64']) && is_string($_POST['slip_base64']),
        'upload' => isset($_FILES['slip_image']) && is_array($_FILES['slip_image']) ? [
            'original_name' => substr((string) ($_FILES['slip_image']['name'] ?? ''), 0, 255),
            'browser_mime' => substr((string) ($_FILES['slip_image']['type'] ?? ''), 0, 100),
            'size' => (int) ($_FILES['slip_image']['size'] ?? 0),
            'error' => (int) ($_FILES['slip_image']['error'] ?? UPLOAD_ERR_NO_FILE),
        ] : null,
    ],
    'context' => ['clock' => slipDebugClockSnapshot()],
]);

$imageBase64 = '';
$endpointSlipHash = '';
if (isset($_FILES['slip_image']) && is_array($_FILES['slip_image'])) {
    $upload = $_FILES['slip_image'];
    $uploadError = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError !== UPLOAD_ERR_OK) {
        $message = in_array($uploadError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 'File size must not exceed 4MB'
            : ($uploadError === UPLOAD_ERR_PARTIAL
                ? 'อัปโหลดสลิปไม่ครบ เนื่องจากการเชื่อมต่อขาด กรุณาใช้สลิปเดิมลองใหม่'
                : 'Slip upload failed');
        $endpointLog('upload_rejected', 'error', 'PHP reported a slip upload error', [
            'error_code' => 'upload_error_' . $uploadError,
            'request' => ['upload_error' => $uploadError, 'upload_size' => (int) ($upload['size'] ?? 0)],
            'response' => ['message' => $message],
        ]);
        ob_clean();
        jsonResponse(['success' => false, 'attempt_id' => $attemptId, 'message' => $message]);
    }

    $tmpPath = (string) ($upload['tmp_name'] ?? '');
    $fileSize = (int) ($upload['size'] ?? 0);
    if ($tmpPath === '' || !is_uploaded_file($tmpPath) || $fileSize < 1 || $fileSize > 4 * 1024 * 1024) {
        $endpointLog('upload_rejected', 'error', 'Uploaded slip file failed path or size validation', [
            'error_code' => 'invalid_uploaded_file',
            'request' => [
                'tmp_path_present' => $tmpPath !== '',
                'is_uploaded_file' => $tmpPath !== '' ? is_uploaded_file($tmpPath) : false,
                'file_size' => $fileSize,
                'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
                'post_max_size' => (string) ini_get('post_max_size'),
            ],
        ]);
        ob_clean();
        jsonResponse(['success' => false, 'attempt_id' => $attemptId, 'message' => 'Invalid slip file or file is larger than 4MB']);
    }

    $imageBytes = file_get_contents($tmpPath);
    if (!is_string($imageBytes) || $imageBytes === '' || strlen($imageBytes) > 4 * 1024 * 1024) {
        $endpointLog('upload_rejected', 'error', 'Uploaded slip file could not be read safely', [
            'error_code' => 'upload_read_failed',
            'request' => ['declared_file_size' => $fileSize, 'read_bytes' => is_string($imageBytes) ? strlen($imageBytes) : null],
        ]);
        ob_clean();
        jsonResponse(['success' => false, 'attempt_id' => $attemptId, 'message' => 'Cannot read slip file']);
    }

    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
    $mime = $finfo ? finfo_buffer($finfo, $imageBytes) : false;
    if ($finfo) finfo_close($finfo);
    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $imageDimensions = @getimagesizefromstring($imageBytes);
    if (!is_string($mime) || !in_array($mime, $allowedMimes, true) || $imageDimensions === false) {
        $endpointLog('upload_rejected', 'error', 'Uploaded slip failed MIME or image structure validation', [
            'error_code' => 'invalid_image_structure',
            'request' => [
                'detected_mime' => $mime,
                'allowed_mimes' => $allowedMimes,
                'getimagesize_success' => $imageDimensions !== false,
                'file_bytes' => strlen($imageBytes),
                'sha256' => hash('sha256', $imageBytes),
            ],
        ]);
        ob_clean();
        jsonResponse(['success' => false, 'attempt_id' => $attemptId, 'message' => 'Only valid JPEG, PNG, GIF, and WebP images are supported']);
    }
    $imageHash = hash('sha256', $imageBytes);
    $endpointSlipHash = $imageHash;
    $endpointLog('upload_validated', 'info', 'Uploaded slip image passed endpoint validation', [
        'slip_hash' => $imageHash,
        'request' => [
            'original_name' => substr((string) ($upload['name'] ?? ''), 0, 255),
            'browser_mime' => substr((string) ($upload['type'] ?? ''), 0, 100),
            'detected_mime' => $mime,
            'file_bytes' => strlen($imageBytes),
            'sha256' => $imageHash,
            'width' => is_array($imageDimensions) ? (int) ($imageDimensions[0] ?? 0) : 0,
            'height' => is_array($imageDimensions) ? (int) ($imageDimensions[1] ?? 0) : 0,
            'image_type' => is_array($imageDimensions) ? (int) ($imageDimensions[2] ?? 0) : 0,
        ],
        'context' => ['clock' => slipDebugClockSnapshot()],
    ]);
    $imageBase64 = 'data:' . $mime . ';base64,' . base64_encode($imageBytes);
    unset($imageBytes);
} elseif (isset($_POST['slip_base64']) && is_string($_POST['slip_base64'])) {
    // Compatibility for a page cached before this deployment. New pages upload
    // the binary file directly, which is faster and smaller on Android/iOS.
    $imageBase64 = $_POST['slip_base64'];
    if (strlen($imageBase64) > 6 * 1024 * 1024) {
        $endpointLog('upload_rejected', 'error', 'Legacy Base64 slip payload exceeded the size limit', [
            'error_code' => 'legacy_base64_too_large',
            'request' => ['encoded_bytes' => strlen($imageBase64)],
        ]);
        ob_clean();
        jsonResponse(['success' => false, 'attempt_id' => $attemptId, 'message' => 'Slip image is too large']);
    }
    $legacyMetadata = slipDebugImageMetadataFromDataUri($imageBase64);
    $endpointSlipHash = (string) ($legacyMetadata['decoded_sha256'] ?? '');
    $endpointLog('upload_validated', $endpointSlipHash !== '' ? 'info' : 'warning', 'Legacy Base64 slip payload received', [
        'slip_hash' => $endpointSlipHash,
        'error_code' => $endpointSlipHash !== '' ? '' : 'legacy_base64_decode_failed',
        'request' => ['image' => $legacyMetadata],
        'context' => ['clock' => slipDebugClockSnapshot()],
    ]);
} else {
    $endpointLog('upload_rejected', 'error', 'No slip file or legacy Base64 payload was supplied', [
        'error_code' => 'no_slip_file',
    ]);
    ob_clean();
    jsonResponse(['success' => false, 'attempt_id' => $attemptId, 'message' => 'No slip file uploaded']);
}

$qrPayload = trim((string) ($_POST['slip_qr_payload'] ?? ''));
if ($qrPayload !== '' && (strlen($qrPayload) > 128 || preg_match('/[^\x21-\x7E]/', $qrPayload))) {
    $endpointLog('qr_payload_hint_rejected', 'warning', 'Browser QR payload hint failed validation; provider image mode will be used', [
        'slip_hash' => $endpointSlipHash,
        'request' => ['payload_length' => strlen($qrPayload), 'payload_sha256' => hash('sha256', $qrPayload)],
    ]);
    $qrPayload = '';
} elseif ($qrPayload !== '') {
    $endpointLog('qr_payload_hint_accepted', 'info', 'Browser extracted a QR payload; EasySlip payload mode can be used', [
        'slip_hash' => $endpointSlipHash,
        'request' => ['payload_length' => strlen($qrPayload), 'payload_sha256' => hash('sha256', $qrPayload)],
    ]);
}

// Do not keep the PHP session locked during the external verification call, and
// continue safely if a mobile browser disconnects while the server is working.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
ignore_user_abort(true);
@set_time_limit(65);
// Hard ceiling for the complete server-side operation. The browser caps the user
// experience at one minute; keep several seconds inside that limit for JSON flush
// and network variance. Provider code separately reserves time for financial
// fencing/COMMIT, so increasing EasySlip wait cannot starve money correctness.
$requestDeadline = $requestStartedAt + 52.0;

try {
    $result = processSlipDeposit($userId, $imageBase64, $attemptId, $requestDeadline, $qrPayload);
    if (is_array($result) && empty($result['attempt_id'])) $result['attempt_id'] = $attemptId;
    if (is_array($result) && !empty($result['attempt_id'])) {
        $canonicalAttemptId = normalizeSlipVerificationAttemptUuid($result['attempt_id']);
        if ($canonicalAttemptId !== '') $attemptId = $canonicalAttemptId;
    }
    $resultForLog = is_array($result) ? $result : ['success' => false, 'message' => 'System error'];
    $endpointLog('endpoint_response', !empty($resultForLog['success']) ? 'info' : (!empty($resultForLog['pending']) ? 'warning' : 'error'), 'Slip verification endpoint returned a response to the browser', [
        'slip_hash' => $endpointSlipHash,
        'error_code' => !empty($resultForLog['success']) ? '' : (string) ($resultForLog['error_code'] ?? ($resultForLog['pending'] ?? false ? 'pending' : 'failed')),
        'response' => $resultForLog,
        'context' => ['clock' => slipDebugClockSnapshot()],
    ]);
    ob_clean();
    jsonResponse(is_array($result) ? $result : ['success' => false, 'attempt_id' => $attemptId, 'message' => 'System error']);
} catch (Throwable $e) {
    error_log('Slip endpoint error: ' . $e->getMessage());
    $endpointLog('endpoint_exception', 'critical', 'Unhandled exception occurred in the slip verification endpoint', [
        'error_code' => 'endpoint_exception',
        'slip_hash' => $endpointSlipHash,
        'response' => [
            'exception_class' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ],
        'context' => ['clock' => slipDebugClockSnapshot()],
    ]);
    ob_clean();
    jsonResponse([
        'success' => false,
        'pending' => true,
        'retryable' => true,
        'error_code' => 'endpoint_exception',
        'attempt_id' => $attemptId,
        'message' => 'การเชื่อมต่อสะดุด ระบบกำลังตรวจสถานะรายการนี้แบบสั้น ๆ กรุณาอย่าส่งสลิปซ้ำทันที',
    ]);
}
