<?php
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
n_assert(empty($result['retryable']), 'duplicate slip should not be retryable');

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
n_assert(empty($result['success']) && !empty($result['retryable']), 'timeout should be retryable');
n_assert(($result['error_code'] ?? '') === 'provider_timeout', 'timeout should be classified explicitly');

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
n_assert(empty($result['success']) && ($result['error_code'] ?? '') === 'provider_invalid_response', 'invalid JSON must fail closed');

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
n_assert(empty($result['success']) && ($result['error_code'] ?? '') === 'provider_response_too_large', 'oversized provider response must be rejected');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} of {$tests} assertions failed\n");
    exit(1);
}

echo "PASS: {$tests} assertions\n";
