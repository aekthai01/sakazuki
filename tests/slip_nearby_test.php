<?php
$GLOBALS['nearby_test_settings'] = [];
$GLOBALS['nearby_test_easyslip_calls'] = [];

function getSetting($key, $default = '')
{
    return array_key_exists((string) $key, $GLOBALS['nearby_test_settings'])
        ? $GLOBALS['nearby_test_settings'][(string) $key]
        : $default;
}

function verifySlipWithEasyslip($imageBase64, string $remark = '', array $debugContext = []): array
{
    $GLOBALS['nearby_test_easyslip_calls'][] = [
        'image' => $imageBase64,
        'remark' => $remark,
        'debug' => $debugContext,
    ];
    return [
        'success' => true,
        'data' => [
            'transaction_ref' => 'easy-ref',
            'amount' => 1.0,
            'transfer_date' => '2026-09-24T00:00:00+00:00',
            'sender_name' => 'sender',
            'sender_account' => '1111',
            'receiver_name' => 'receiver',
            'receiver_account' => '2222',
            'bank_code' => '014',
            'verification_remark' => $remark,
            'is_duplicate' => false,
        ],
    ];
}

require_once __DIR__ . '/../public_html/includes/slip_nearby.php';

$tests = 0;
$failures = 0;

function n_assert($condition, string $message): void
{
    global $tests, $failures;
    $tests++;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

function n_http(array $json, int $http = 200, int $durationMs = 25): array
{
    return [
        'executed' => true,
        'http_code' => $http,
        'curl_errno' => 0,
        'curl_error' => '',
        'body' => json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'response_too_large' => false,
        'duration_ms' => $durationMs,
    ];
}

$apiKey = 'nb_live_1234567890abcdef';
n_assert(nearbySlipValidateApiKey($apiKey) === $apiKey, 'documented API key shape should be accepted');
n_assert(nearbySlipValidateApiKey('Bearer old.jwt.token') === '', 'legacy Bearer token input must be rejected');
n_assert(nearbySlipValidateApiKey("nb_live_bad\nkey") === '', 'API key control characters must be rejected');
$headers = nearbySlipAuthHeaders($apiKey);
n_assert(in_array('X-API-Key: ' . $apiKey, $headers, true), 'API requests must send X-API-Key');
n_assert(count(array_filter($headers, static fn($h) => stripos($h, 'Authorization:') === 0)) === 0, 'legacy Authorization header must never be emitted');

$fixture = [
    'status' => 'success',
    'data' => [
        'trans_id' => '202609031200183310899',
        'type' => 'BANK_TRANSFER',
        'amount' => 100,
        'date_time' => '2026-09-03T12:00:00.000Z',
        'sender' => [
            'name' => 'นาย สมศักดิ์ สุขใจ',
            'bank' => 'ธนาคารไทยพาณิชย์ จำกัด (มหาชน)',
            'bank_code' => '014',
            'bank_abbr' => 'SCB',
            'account_no' => 'xxx-x-x1234-x',
        ],
        'receiver' => [
            'name' => 'นาย สมชาย ใจดี',
            'bank' => 'ธนาคารกสิกรไทย จำกัด (มหาชน)',
            'bank_code' => '004',
            'bank_abbr' => 'KBANK',
            'details' => ['account_no' => '067-8-xxx346'],
        ],
    ],
    'extracted_amount' => 100,
];

$result = nearbySlipNormalizeSuccessResponse($fixture);
n_assert(!empty($result['success']), 'documented v2 success shape should normalize');
n_assert(($result['data']['transaction_ref'] ?? '') === '202609031200183310899', 'trans_id should map to transaction_ref');
n_assert(($result['data']['amount'] ?? null) === 100.0, 'amount should be normalized as decimal');
n_assert(($result['data']['receiver_account'] ?? '') === '067-8-xxx346', 'receiver details.account_no should be accepted');
n_assert(($result['data']['sender_bank_code'] ?? '') === '014', 'sender bank code should be preserved');
n_assert(($result['data']['bank_code'] ?? '') === '014', 'main deposit bank_code should use sending bank code');
n_assert(array_key_exists('is_duplicate', $result['data']) && $result['data']['is_duplicate'] === false, 'Nearby success should not invent a provider duplicate decision');

$bad = $fixture;
unset($bad['data']['date_time']);
$result = nearbySlipNormalizeSuccessResponse($bad);
n_assert(empty($result['success']) && ($result['error_code'] ?? '') === 'invalid_transfer_date', 'missing date_time must fail closed');

$bad = $fixture;
$bad['data']['amount'] = 'not-money';
$result = nearbySlipNormalizeSuccessResponse($bad);
n_assert(empty($result['success']) && ($result['error_code'] ?? '') === 'invalid_amount', 'non-numeric amount must fail closed');

$bad = $fixture;
$bad['data']['trans_id'] = "bad ref\n";
$result = nearbySlipNormalizeSuccessResponse($bad);
n_assert(empty($result['success']) && ($result['error_code'] ?? '') === 'invalid_transaction_ref', 'transaction reference with whitespace/control characters must fail');

$transportCalls = [];
$result = nearbySlipVerifyV2(
    $apiKey,
    'fake-jpeg-bytes',
    'image/jpeg',
    [
        'expected_receiver_name' => 'นาย สมชาย ใจดี',
        'expected_account_no' => '346',
        'unexpected' => 'must not pass',
    ],
    5000,
    static function (string $receivedKey, string $image, string $mime, array $options, int $timeout) use (&$transportCalls, $fixture): array {
        $transportCalls[] = compact('receivedKey', 'image', 'mime', 'options', 'timeout');
        return n_http($fixture);
    }
);
n_assert(!empty($result['success']), 'injected transport success should normalize');
n_assert(count($transportCalls) === 1, 'verification should make exactly one transport call');
n_assert(($transportCalls[0]['receivedKey'] ?? '') === $apiKey, 'API key should reach transport unchanged');
n_assert(($transportCalls[0]['options']['expected_receiver_name'] ?? '') === 'นาย สมชาย ใจดี', 'receiver name option should be forwarded');
n_assert(($transportCalls[0]['options']['expected_account_no'] ?? '') === '346', 'receiver account option should be forwarded');
n_assert(!isset($transportCalls[0]['options']['unexpected']), 'unknown receiver options must be stripped');

$result = nearbySlipVerifyV2('', 'bytes', 'image/jpeg', [], 5000, static fn() => n_http($fixture));
n_assert(empty($result['success']) && ($result['error_code'] ?? '') === 'missing_api_key', 'missing API key must stop before transport');

$result = nearbySlipVerifyV2($apiKey, 'bytes', 'image/gif', [], 5000, static fn() => n_http($fixture));
n_assert(empty($result['success']) && ($result['error_code'] ?? '') === 'invalid_image_mime', 'GIF should be rejected because provider v2 docs list JPEG/PNG/WEBP');

$result = nearbySlipVerifyV2($apiKey, 'bytes', 'image/jpeg', [], 5000, static fn() => n_http([
    'status' => 'error',
    'code' => 'RECEIVER_ACCOUNT_MISMATCH',
    'message' => 'receiver mismatch',
], 400));
n_assert(empty($result['success']), 'provider business rejection must fail');
n_assert(($result['provider_code'] ?? '') === 'RECEIVER_ACCOUNT_MISMATCH', 'provider rejection code should be preserved');
n_assert(empty($result['retryable']), 'receiver mismatch should not be retryable');

$result = nearbySlipVerifyV2($apiKey, 'bytes', 'image/jpeg', [], 5000, static fn() => n_http([
    'status' => 'error', 'code' => 'DUPLICATE_SLIP', 'message' => 'duplicate'
], 400));
n_assert(($result['provider_code'] ?? '') === 'DUPLICATE_SLIP', 'duplicate provider code should be preserved');
n_assert(empty($result['retryable']), 'provider duplicate response should not be retried automatically');

$result = nearbySlipVerifyV2($apiKey, 'bytes', 'image/jpeg', [], 5000, static fn() => n_http([
    'status' => 'error', 'message' => 'busy'
], 429));
n_assert(empty($result['success']) && !empty($result['retryable']), 'HTTP 429 should be retryable');
n_assert(($result['error_code'] ?? '') === 'provider_rate_limited', 'HTTP 429 should expose rate-limit error code');

$result = nearbySlipVerifyV2($apiKey, 'bytes', 'image/jpeg', [], 5000, static function (): array {
    return [
        'executed' => false,
        'http_code' => 0,
        'curl_errno' => defined('CURLE_OPERATION_TIMEDOUT') ? CURLE_OPERATION_TIMEDOUT : 28,
        'curl_error' => 'Operation timed out',
        'body' => '',
        'response_too_large' => false,
        'duration_ms' => 5000,
    ];
});
n_assert(empty($result['success']) && !empty($result['pending']), 'timeout after submission should be held as ambiguous');
n_assert(empty($result['retryable']), 'ambiguous timeout must not be automatically resent');
n_assert(($result['error_code'] ?? '') === 'provider_outcome_unknown', 'timeout should use the durable main-flow ambiguity code');

$result = nearbySlipVerifyV2($apiKey, 'bytes', 'image/jpeg', [], 5000, static function (): array {
    return [
        'executed' => true,
        'http_code' => 200,
        'curl_errno' => 0,
        'curl_error' => '',
        'body' => '<html>unexpected</html>',
        'response_too_large' => false,
        'duration_ms' => 5,
    ];
});
n_assert(empty($result['success']) && !empty($result['pending']), 'invalid HTTP 200 JSON is an ambiguous submitted request');
n_assert(($result['error_code'] ?? '') === 'provider_outcome_unknown', 'invalid HTTP 200 JSON should not invite an automatic resend');

$result = nearbySlipVerifyV2($apiKey, 'bytes', 'image/jpeg', [], 5000, static function (): array {
    return [
        'executed' => true,
        'http_code' => 200,
        'curl_errno' => 23,
        'curl_error' => 'write callback aborted',
        'body' => '',
        'response_too_large' => true,
        'duration_ms' => 5,
    ];
});
n_assert(empty($result['success']) && !empty($result['pending']), 'oversized provider response after submission is ambiguous');
n_assert(($result['error_code'] ?? '') === 'provider_outcome_unknown', 'oversized provider response should not be resent automatically');

$pngDataUri = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
$decodedImage = nearbySlipDecodeBase64Image($pngDataUri);
n_assert(!empty($decodedImage['success']), 'main-flow data URI should decode');
n_assert(($decodedImage['mime'] ?? '') === 'image/png', 'decoded MIME should come from bytes');

$base64Calls = [];
$result = nearbySlipVerifyBase64(
    $apiKey,
    $pngDataUri,
    ['expected_receiver_name' => 'นาย สมชาย ใจดี', 'expected_account_no' => '346'],
    6000,
    static function (string $receivedKey, string $image, string $mime, array $options, int $timeout) use (&$base64Calls, $fixture): array {
        $base64Calls[] = compact('receivedKey', 'image', 'mime', 'options', 'timeout');
        return n_http($fixture, 200, 12);
    }
);
n_assert(!empty($result['success']), 'base64 main-flow helper should delegate to v2');
n_assert(count($base64Calls) === 1 && ($base64Calls[0]['mime'] ?? '') === 'image/png', 'base64 helper should pass detected image MIME');
n_assert(($base64Calls[0]['options']['expected_account_no'] ?? '') === '346', 'base64 helper should preserve validated receiver options');

$result = nearbySlipVerifyBase64($apiKey, 'not-base64***', [], 5000, static fn() => n_http($fixture));
n_assert(empty($result['success']) && ($result['error_code'] ?? '') === 'invalid_image_payload', 'invalid base64 should fail before transport');

// Provider selection must be explicit, conservative, and reuse the one store account source.
$GLOBALS['nearby_test_settings'] = [];
n_assert(slipVerificationConfiguredProvider() === 'easyslip', 'missing provider setting must preserve EasySlip as the safe default');
$GLOBALS['nearby_test_settings']['slip_verification_provider'] = 'unknown';
n_assert(slipVerificationConfiguredProvider() === 'easyslip', 'unknown provider setting must fall back to EasySlip');
$GLOBALS['nearby_test_settings']['slip_verification_provider'] = 'nearby';
n_assert(slipVerificationConfiguredProvider() === 'nearby', 'Nearby provider setting should be recognized');
$GLOBALS['nearby_test_settings']['easyslip_receiver_name'] = 'นาย อัครชัย แจ้งกระจ่าง';
$GLOBALS['nearby_test_settings']['easyslip_receiver_name_en'] = 'Akkarachai';
$GLOBALS['nearby_test_settings']['easyslip_account_number'] = '6798475698';
$GLOBALS['nearby_test_settings']['easyslip_bank_name'] = 'กรุงไทย';
$receiverOptions = nearbySlipConfiguredReceiverOptions();
n_assert(($receiverOptions['expected_receiver_name'] ?? '') === 'นาย อัครชัย แจ้งกระจ่าง', 'Nearby must reuse the existing Thai receiver name');
n_assert(($receiverOptions['expected_account_no'] ?? '') === '6798475698', 'Nearby must reuse the existing store account number');
n_assert(!isset($receiverOptions['expected_bank_code']), 'Nearby must not guess a bank code from the stored display name');

$GLOBALS['nearby_test_settings']['slip_verification_provider'] = 'easyslip';
$GLOBALS['nearby_test_easyslip_calls'] = [];
$routerResult = verifySlipWithConfiguredProvider('data:image/png;base64,ZmFrZQ==', 'ez2:test', ['attempt_uuid' => 'attempt-test']);
n_assert(!empty($routerResult['success']), 'configured provider router should preserve the EasySlip path');
n_assert(($routerResult['provider'] ?? '') === 'easyslip', 'EasySlip route should identify its provider');
n_assert(count($GLOBALS['nearby_test_easyslip_calls']) === 1, 'EasySlip router path should make exactly one provider call');
n_assert(($GLOBALS['nearby_test_easyslip_calls'][0]['remark'] ?? '') === 'ez2:test', 'router must preserve the signed verification remark for EasySlip');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} of {$tests} assertions failed\n");
    exit(1);
}

echo "PASS: {$tests} assertions\n";
