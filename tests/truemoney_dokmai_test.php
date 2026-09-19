<?php
require_once __DIR__ . '/../public_html/includes/truemoney_dokmai.php';

$tests = 0;
$failures = 0;

function d_assert($condition, string $message): void
{
    global $tests, $failures;
    $tests++;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

function d_response(array $json, int $http = 200, array $headers = []): array
{
    return [
        'executed' => true,
        'http_code' => $http,
        'curl_errno' => 0,
        'curl_error' => '',
        'body' => json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'headers' => array_merge(['content-type' => 'application/json'], $headers),
        'response_too_large' => false,
        'timing' => ['total_ms' => 12],
    ];
}

$phone = '0812345678';
$userId = 594;
$token = '019e0d03f0fa78bf893eb17f3750d31a4cS';
$voucher = 'https://gift.truemoney.com/campaign/?v=' . $token;
$voucherHash = hash('sha256', $token);
$apiKey = 'test-dokmai-key-never-used-live';

$normalized = trueMoneyDokmaiNormalizeVoucher($voucher);
d_assert(!empty($normalized['success']), 'valid voucher URL should normalize');
d_assert(($normalized['token'] ?? '') === $token, 'voucher token should be preserved');
d_assert(empty(trueMoneyDokmaiNormalizeVoucher('https://evil.example/campaign/?v=' . $token)['success']), 'foreign voucher host should be rejected');

$key1 = trueMoneyDokmaiIdempotencyKey($userId, $voucherHash);
$key2 = trueMoneyDokmaiIdempotencyKey($userId, $voucherHash);
$keyOtherUser = trueMoneyDokmaiIdempotencyKey($userId + 1, $voucherHash);
$keyOtherVoucher = trueMoneyDokmaiIdempotencyKey($userId, hash('sha256', 'other-voucher'));
d_assert($key1 !== '' && $key1 === $key2, 'idempotency key must be stable for same user and voucher');
d_assert($key1 !== $keyOtherUser, 'idempotency key must vary by user');
d_assert($key1 !== $keyOtherVoucher, 'idempotency key must vary by voucher');
d_assert(strpos($key1, 'sakazuki-tmn-') === 0, 'idempotency key should use stable namespace');

$error = trueMoneyDokmaiError([
    'success' => false,
    'error' => [
        'code' => 'invalid_api_key',
        'message' => 'API key is invalid or inactive',
        'retryable' => false,
        'requestId' => 'req-test-1',
    ],
]);
d_assert(($error['code'] ?? '') === 'invalid_api_key', 'nested error code should parse');
d_assert(($error['retryable'] ?? null) === false, 'nested retryable=false should parse');
d_assert(($error['request_id'] ?? '') === 'req-test-1', 'nested requestId should parse');

d_assert(trueMoneyDokmaiAmount(['success' => true, 'data' => ['amount_baht' => '155.00']]) === 155.0, 'data.amount_baht should parse');
d_assert(trueMoneyDokmaiAmount(['success' => true, 'data' => ['redeem' => ['amount_baht' => '88.50']]]) === 88.5, 'data.redeem.amount_baht should parse');
d_assert(trueMoneyDokmaiAmount(['success' => true, 'amount' => 42.25]) === 42.25, 'top-level amount should parse');
d_assert(trueMoneyDokmaiAmount(['success' => true, 'data' => ['fee' => 3.00]]) === null, 'unrelated numeric fields must not be accepted as redeemed amount');

$calls = [];
$transport = static function (string $url, array $body, string $receivedKey, string $idempotency) use (&$calls): array {
    $calls[] = compact('url', 'body', 'receivedKey', 'idempotency');
    return d_response([
        'success' => true,
        'data' => ['amount_baht' => '155.00'],
        'requestId' => 'req-success-1',
    ]);
};
$debug = null;
$result = redeemAngpaoDokmai($voucher, $phone, $userId, $voucherHash, $debug, $transport, $apiKey);
d_assert(!empty($result['success']), 'success contract should redeem');
d_assert(($result['amount'] ?? null) === 155.0, 'success contract should return gross amount');
d_assert(count($calls) === 1, 'redeem must issue exactly one provider POST');
d_assert(($calls[0]['url'] ?? '') === TM_DOKMAI_API_URL, 'redeem must call Dokmai endpoint');
d_assert(($calls[0]['body']['phoneNumber'] ?? '') === $phone, 'phoneNumber must be sent in JSON body');
d_assert(($calls[0]['body']['voucher'] ?? '') === $voucher, 'canonical voucher URL must be sent in JSON body');
d_assert(($calls[0]['receivedKey'] ?? '') === $apiKey, 'API key must be handed to transport');
d_assert(($calls[0]['idempotency'] ?? '') === $key1, 'provider request must use stable idempotency key');

$debug = null;
$result = redeemAngpaoDokmai($voucher, $phone, $userId, $voucherHash, $debug, static function (): array {
    return d_response([
        'success' => false,
        'error' => [
            'code' => 'invalid_api_key',
            'message' => 'API key is invalid or inactive',
            'retryable' => false,
            'requestId' => 'req-auth',
        ],
    ], 401);
}, $apiKey);
d_assert(empty($result['success']) && ($result['code'] ?? '') === 'invalid_api_key', 'invalid API key should be a definitive failure');
d_assert(empty($result['indeterminate']), 'invalid API key must not be indeterminate');

$debug = null;
$result = redeemAngpaoDokmai($voucher, $phone, $userId, $voucherHash, $debug, static function (): array {
    return d_response([
        'success' => false,
        'error' => [
            'code' => 'upstream_temporarily_unavailable',
            'message' => 'please retry',
            'retryable' => true,
            'requestId' => 'req-retryable',
        ],
    ], 409);
}, $apiKey);
d_assert(empty($result['success']) && !empty($result['indeterminate']), 'retryable provider error should be indeterminate');
d_assert(($result['code'] ?? '') === 'provider_indeterminate', 'retryable error should use provider_indeterminate code');

$debug = null;
$result = redeemAngpaoDokmai($voucher, $phone, $userId, $voucherHash, $debug, static function (): array {
    return d_response([
        'success' => false,
        'error' => [
            'code' => 'voucher_expired',
            'message' => 'voucher expired',
            'retryable' => false,
            'requestId' => 'req-expired',
        ],
    ], 422);
}, $apiKey);
d_assert(empty($result['success']) && ($result['code'] ?? '') === 'voucher_expired', 'non-retryable voucher error should preserve provider code');
d_assert(empty($result['indeterminate']), 'non-retryable voucher error must be definitive');

$debug = null;
$result = redeemAngpaoDokmai($voucher, $phone, $userId, $voucherHash, $debug, static function (): array {
    return d_response([
        'success' => false,
        'error' => [
            'code' => 'rate_limit_exceeded',
            'message' => 'slow down',
            'retryable' => true,
            'requestId' => 'req-rate',
        ],
    ], 429, ['retry-after' => '60']);
}, $apiKey);
d_assert(empty($result['success']) && ($result['code'] ?? '') === 'rate_limit_exceeded', 'rate-limit should preserve provider code');
d_assert(empty($result['indeterminate']), 'rate-limit is safe to retry later and should not be indeterminate');

$debug = null;
$result = redeemAngpaoDokmai($voucher, $phone, $userId, $voucherHash, $debug, static function (): array {
    return d_response([
        'success' => false,
        'error' => [
            'code' => 'upstream_error',
            'message' => 'upstream failed',
            'retryable' => true,
        ],
    ], 502);
}, $apiKey);
d_assert(empty($result['success']) && !empty($result['indeterminate']), 'HTTP 5xx must be indeterminate');

$debug = null;
$result = redeemAngpaoDokmai($voucher, $phone, $userId, $voucherHash, $debug, static function (): array {
    return [
        'executed' => false,
        'http_code' => 0,
        'curl_errno' => 28,
        'curl_error' => 'Operation timed out',
        'body' => '',
        'headers' => [],
        'response_too_large' => false,
        'timing' => ['total_ms' => 30000],
    ];
}, $apiKey);
d_assert(empty($result['success']) && !empty($result['indeterminate']), 'transport timeout must be indeterminate');

$debug = null;
$result = redeemAngpaoDokmai($voucher, $phone, $userId, $voucherHash, $debug, static function (): array {
    return d_response(['success' => true, 'data' => ['requestId' => 'req-no-amount']]);
}, $apiKey);
d_assert(empty($result['success']) && !empty($result['indeterminate']), 'success without a safe amount must be indeterminate');

$debug = null;
$result = redeemAngpaoDokmai($voucher, $phone, $userId, $voucherHash, $debug, static function (): array {
    return d_response(['hello' => 'world'], 200);
}, $apiKey);
d_assert(empty($result['success']) && !empty($result['indeterminate']), 'unrecognized 2xx contract must be indeterminate');

$debug = null;
$result = redeemAngpaoDokmai($voucher, $phone, $userId, $voucherHash, $debug, static function (): array {
    return d_response([
        'success' => false,
        'error' => [
            'code' => 'voucher_not_found',
            'message' => 'not found',
            'retryable' => false,
        ],
    ], 404);
}, '');
d_assert(empty($result['success']) && ($result['code'] ?? '') === 'dokmai_api_key_missing', 'missing API key should fail before transport');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} of {$tests} assertions failed\n");
    exit(1);
}

echo "PASS: {$tests} assertions\n";
