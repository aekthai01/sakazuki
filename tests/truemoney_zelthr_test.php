<?php
require_once __DIR__ . '/../public_html/includes/truemoney_zelthr.php';

$tests = 0;
$failures = 0;

function z_assert($condition, string $message): void
{
    global $tests, $failures;
    $tests++;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

function z_response(array $json, int $http = 200, array $headers = []): array
{
    return [
        'executed' => true,
        'http_code' => $http,
        'curl_errno' => 0,
        'curl_error' => '',
        'body' => json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'headers' => array_merge(['content-type' => 'application/json'], $headers),
        'response_too_large' => false,
        'timing' => ['total_ms' => 10],
    ];
}

$phone = '0812345678';
$token = '019e0d03f0fa78bf893eb17f3750d31a4cS';
$voucher = 'https://gift.truemoney.com/campaign/?v=' . $token;

$normalized = trueMoneyZelthrNormalizeVoucher($voucher);
z_assert(!empty($normalized['success']), 'valid voucher URL should normalize');
z_assert(($normalized['token'] ?? '') === $token, 'voucher token should be preserved');
z_assert(empty(trueMoneyZelthrNormalizeVoucher('https://evil.example/campaign/?v=' . $token)['success']), 'foreign voucher host should be rejected');

z_assert(trueMoneyZelthrAmount([
    'status' => ['code' => 'SUCCESS'],
    'data' => ['redeem' => ['amount_baht' => '155.00']],
]) === 155.0, 'data.redeem.amount_baht should be preferred');
z_assert(trueMoneyZelthrAmount(['amount' => 88.5]) === 88.5, 'top-level amount should be supported');

$calls = [];
$transport = static function (string $url, array $body) use (&$calls): array {
    $calls[] = compact('url', 'body');
    return z_response([
        'status' => ['code' => 'SUCCESS', 'message' => 'success'],
        'data' => ['redeem' => ['amount_baht' => '155.00', 'amount' => 155]],
    ]);
};
$debug = null;
$result = redeemAngpaoZelthr($voucher, $phone, $debug, $transport);
z_assert(!empty($result['success']), 'SUCCESS response should redeem');
z_assert(($result['amount'] ?? null) === 155.0, 'SUCCESS response should return gross amount');
z_assert(count($calls) === 1, 'redeem must issue exactly one provider POST');
z_assert(($calls[0]['url'] ?? '') === TM_ZELTHR_API_URL, 'redeem must call configured Zelthr endpoint');
z_assert(($calls[0]['body']['gift'] ?? '') === $voucher, 'gift link must be in JSON body');
z_assert(($calls[0]['body']['phone'] ?? '') === $phone, 'phone must be in JSON body');

$debug = null;
$result = redeemAngpaoZelthr($voucher, $phone, $debug, static function (): array {
    return z_response([
        'status' => ['code' => 'VOUCHER_EXPIRED', 'message' => 'voucher expired'],
        'data' => null,
    ]);
});
z_assert(empty($result['success']) && ($result['code'] ?? '') === 'VOUCHER_EXPIRED', 'business error code should be preserved');
z_assert(empty($result['indeterminate']), 'definitive business error must not be indeterminate');

$debug = null;
$result = redeemAngpaoZelthr($voucher, $phone, $debug, static function (): array {
    return z_response(['error' => 'rate-limit-exceeded', 'message' => 'slow down'], 429, ['retry-after' => '60']);
});
z_assert(empty($result['success']) && ($result['code'] ?? '') === 'rate-limit-exceeded', 'HTTP 429 should be a definite rate-limit failure');
z_assert(empty($result['indeterminate']), 'HTTP 429 should not be marked indeterminate');

$debug = null;
$result = redeemAngpaoZelthr($voucher, $phone, $debug, static function (): array {
    return z_response(['error' => 'upstream-error', 'message' => 'upstream failed'], 502);
});
z_assert(empty($result['success']) && !empty($result['indeterminate']), 'HTTP 5xx after redeem must be indeterminate');
z_assert(($result['code'] ?? '') === 'provider_indeterminate', 'HTTP 5xx should use provider_indeterminate code');

$debug = null;
$result = redeemAngpaoZelthr($voucher, $phone, $debug, static function (): array {
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
});
z_assert(empty($result['success']) && !empty($result['indeterminate']), 'timeout must be indeterminate');

$debug = null;
$result = redeemAngpaoZelthr($voucher, $phone, $debug, static function (): array {
    return z_response(['status' => ['code' => 'SUCCESS', 'message' => 'success'], 'data' => []]);
});
z_assert(empty($result['success']) && !empty($result['indeterminate']), 'SUCCESS without amount must be indeterminate');

$debug = null;
$result = redeemAngpaoZelthr($voucher, $phone, $debug, static function (): array {
    return z_response(['error' => 'tmn-voucher-not-found', 'message' => 'voucher not found'], 422);
});
z_assert(empty($result['success']) && ($result['code'] ?? '') === 'tmn-voucher-not-found', 'gateway 422 error slug should be preserved');
z_assert(empty($result['indeterminate']), 'gateway validation/not-found response should be definite');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} of {$tests} assertions failed\n");
    exit(1);
}

echo "PASS: {$tests} assertions\n";
