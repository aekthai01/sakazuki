<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/cheatgame.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

$respond = static function (int $status, bool $success, string $message): void {
    http_response_code($status);
    echo json_encode([
        'success' => $success,
        'message' => $message,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

$normalizeProcessingResult = static function (array $result): array {
    $message = (string) ($result['message'] ?? '');
    if (in_array($message, ['Duplicate event is already processing', 'Duplicate event was not claimed'], true)) {
        return [
            'success' => false,
            'status' => 503,
            'message' => 'Webhook event is still processing; retry later',
        ];
    }
    return $result;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    $respond(405, false, 'Method not allowed');
}

$configStatus = cgoConfigurationStatus();
if (!$configStatus['webhook_ready']) {
    $respond(503, false, 'Webhook configuration or website identity is incomplete');
}

$contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
if ($contentLength > 1048576) {
    $respond(413, false, 'Payload too large');
}

$rawBody = (string) file_get_contents('php://input');
if ($rawBody === '' || strlen($rawBody) > 1048576) {
    $respond(400, false, 'Invalid payload');
}

$eventName = trim((string) ($_SERVER['HTTP_X_CGO_EVENT'] ?? ''));
$eventId = trim((string) ($_SERVER['HTTP_X_CGO_EVENT_ID'] ?? ''));
$timestamp = trim((string) ($_SERVER['HTTP_X_CGO_TIMESTAMP'] ?? ''));
$signature = trim((string) ($_SERVER['HTTP_X_CGO_SIGNATURE'] ?? ''));

if ($eventName === '' || strlen($eventName) > 100
    || $eventId === '' || strlen($eventId) > 190
    || $timestamp === '' || $signature === '') {
    $respond(400, false, 'Missing or invalid webhook headers');
}

if (!cgoVerifyWebhookSignature($timestamp, $eventId, $rawBody, $signature)) {
    $respond(401, false, 'Invalid signature');
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    $respond(400, false, 'Invalid JSON');
}

$routePrefix = cgoWebhookRoutePrefix($payload);
$webhookRole = (string) ($configStatus['webhook_role'] ?? 'invalid');
$localPrefix = (string) ($configStatus['site_prefix'] ?? '');

// CHEATGAME's Send Test may not contain an order external_ref. Verify it and
// return 202 without guessing a destination database.
if ($routePrefix === '') {
    if ($webhookRole === 'hub') {
        $payloadHash = hash('sha256', $rawBody);
        $claim = cgoClaimGlobalWebhookEvent($eventId, $eventName, (int) $timestamp, $payloadHash, 'UNROUTED');
        if (empty($claim['claimed'])) {
            $claimStatus = (int) ($claim['status'] ?? 500);
            $claimMessage = (string) ($claim['message'] ?? 'Event not claimed');
            if (in_array($claimMessage, ['Duplicate event is already processing', 'Duplicate event was not claimed'], true)) {
                $respond(503, false, 'Webhook event is still processing; retry later');
            }
            $respond($claimStatus, $claimStatus < 400, $claimMessage);
        }
        if (!cgoMarkGlobalWebhookEvent($eventId, 'ignored', 202, 'Verified webhook did not contain a routable external_ref')) {
            $respond(500, false, 'Unable to record webhook result');
        }
    }
    $respond(202, true, 'Webhook verified; no routable external_ref was supplied');
}

if ($webhookRole === 'hub') {
    $target = cgoWebhookRouteTarget($routePrefix);
    if ($target === '') {
        $respond(422, false, 'No configured webhook route for ' . $routePrefix);
    }

    $payloadHash = hash('sha256', $rawBody);
    $claim = cgoClaimGlobalWebhookEvent($eventId, $eventName, (int) $timestamp, $payloadHash, $routePrefix);
    if (empty($claim['claimed'])) {
        $claimStatus = (int) ($claim['status'] ?? 500);
        $claimMessage = (string) ($claim['message'] ?? 'Event not claimed');
        if (in_array($claimMessage, ['Duplicate event is already processing', 'Duplicate event was not claimed'], true)) {
            $respond(503, false, 'Webhook event is still processing; retry later');
        }
        $respond($claimStatus, $claimStatus < 400, $claimMessage);
    }

    if ($target === 'local') {
        if (!hash_equals($localPrefix, $routePrefix)) {
            cgoMarkGlobalWebhookEvent($eventId, 'error', 500, 'Local route does not match the hub database identity');
            $respond(500, false, 'Local webhook route does not match this database');
        }
        $result = cgoProcessWebhook($eventName, $eventId, (int) $timestamp, $payload, $rawBody);
    } else {
        $result = cgoForwardWebhook($target, $eventName, $eventId, $timestamp, $signature, $rawBody);
    }
    $result = $normalizeProcessingResult($result);

    $resultStatus = (int) ($result['status'] ?? 500);
    $resultSuccess = !empty($result['success']) && $resultStatus >= 200 && $resultStatus < 300;
    $finalState = $resultSuccess
        ? ($eventName === 'order.success' ? 'processed' : 'ignored')
        : 'error';
    if (!cgoMarkGlobalWebhookEvent($eventId, $finalState, $resultStatus, (string) ($result['message'] ?? ''))) {
        $respond(500, false, 'Webhook completed but its global event state could not be saved');
    }
    $respond($resultStatus, $resultSuccess, (string) ($result['message'] ?? ''));
}

if (!in_array($webhookRole, ['receiver', 'local'], true)) {
    $respond(503, false, 'Invalid webhook role');
}
if (!hash_equals($localPrefix, $routePrefix)) {
    $respond(409, false, 'Webhook route does not match this website database');
}

$result = cgoProcessWebhook($eventName, $eventId, (int) $timestamp, $payload, $rawBody);
$result = $normalizeProcessingResult($result);
$resultStatus = (int) ($result['status'] ?? 500);
$respond($resultStatus, !empty($result['success']), (string) ($result['message'] ?? ''));
