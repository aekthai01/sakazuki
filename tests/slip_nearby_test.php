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

n_assert(nearbySlipValidateToken('Bearer header.payload.signature') === 'header.payload.signature', 'Bearer prefix should be accepted and stripped');
n_assert(nearbySlipValidateToken("token\nheader") === '', 'token control characters must be rejected');

$fixture = [
    'status' => 'success',
    'data' => [
        'trans_id' => '202609241234567890',
        'amount' => 125.50,
        'date_time' => '2026-09-24T20:15:30+07:00',
        'sender' => [
            'name' => 'ผู้โอน ทดสอบ',
            'bank_code' => '004',
            'bank_abbr' => 'KBANK',
            'account_no' => 'xxx-x-x1234-x',
        ],
        'receiver' => [
            'name' => 'ผู้รับ ทดสอบ',
            'bank_code' => '014',
            'bank_abbr' => 'SCB',
            'details' => ['account_no' => 'xxx-x-x5678-x'],
        ],
    ],
];

$result = nearbySlipNormalizeSuccessResponse($fixture);
n_assert(!empty($result['success']), 'documented v2 success shape should normalize');
n_assert(($result['data']['transaction_ref'] ?? '') === '202609241234567890', 'trans_id should map to transaction_ref');
n_assert(($result['data']['amount'] ?? null) === 125.50, 'amount should be normalized as decimal');
n_assert(($result['data']['receiver_account'] ?? '') === 'xxx-x-x5678-x', 'receiver details.account_no should be accepted');
n_assert(($result['data']['sender_bank_code'] ?? '') === '004', 'sender bank code should be preserved');
n_assert(!array_key_exists('country_code', $result['data']), 'adapter must not invent country_code absent from provider contract');
n_assert(!array_key_exists('local_currency', $result['data']), 'adapter must not invent local_currency absent from provider contract');

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
    'header.payload.signature',
    'fake-jpeg-bytes',
    'image/jpeg',
    [
        'expected_receiver_name' => 'ผู้รับ ทดสอบ',
        'expected_account_no' => '5678',
        'unexpected' => 'must not pass',
    ],
    5000,
    static function (string $token, string $image, string $mime, array $options, int $timeout) use (&$transportCalls, $fixture): array {
        $transportCalls[] = compact('token', 'image', 'mime', 'options', 'timeout');
        return n_http($fixture);
    }
);
n_assert(!empty($result['success']), 'injected transport success should normalize');
n_assert(count($transportCalls) === 1, 'verification should make exactly one transport call');
n_assert(($transportCalls[0]['options']['expected_receiver_name'] ?? '') === 'ผู้รับ ทดสอบ', 'receiver name option should be forwarded');
n_assert(($transportCalls[0]['options']['expected_account_no'] ?? '') === '5678', 'receiver account option should be forwarded');
n_assert(!isset($transportCalls[0]['options']['unexpected']), 'unknown receiver options must be stripped');

$result = nearbySlipVerifyV2('', 'bytes', 'image/jpeg', [], 5000, static fn() => n_http($fixture));
n_assert(empty($result['success']) && ($result['error_code'] ?? '') === 'missing_token', 'missing token must stop before transport');

$result = nearbySlipVerifyV2('token', 'bytes', 'image/gif', [], 5000, static fn() => n_http($fixture));
n_assert(empty($result['success']) && ($result['error_code'] ?? '') === 'invalid_image_mime', 'GIF should be rejected because provider v2 docs list JPEG/PNG/WEBP');

$result = nearbySlipVerifyV2('token', 'bytes', 'image/jpeg', [], 5000, static fn() => n_http([
    'status' => 'error',
    'code' => 'RECEIVER_ACCOUNT_MISMATCH',
    'message' => 'receiver mismatch',
], 400));
n_assert(empty($result['success']), 'provider business rejection must fail');
n_assert(($result['provider_code'] ?? '') === 'RECEIVER_ACCOUNT_MISMATCH', 'provider rejection code should be preserved');
n_assert(empty($result['retryable']), 'receiver mismatch should not be retryable');

$result = nearbySlipVerifyV2('token', 'bytes', 'image/jpeg', [], 5000, static fn() => n_http([
    'status' => 'error', 'message' => 'busy'
], 429));
n_assert(empty($result['success']) && !empty($result['retryable']), 'HTTP 429 should be retryable');
n_assert(($result['error_code'] ?? '') === 'provider_rate_limited', 'HTTP 429 should expose rate-limit error code');

$result = nearbySlipVerifyV2('token', 'bytes', 'image/jpeg', [], 5000, static function (): array {
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

$result = nearbySlipVerifyV2('token', 'bytes', 'image/jpeg', [], 5000, static function (): array {
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

$result = nearbySlipVerifyV2('token', 'bytes', 'image/jpeg', [], 5000, static function (): array {
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
