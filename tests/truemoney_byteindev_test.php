<?php
require_once __DIR__ . '/../public_html/includes/truemoney_byteindev.php';

$tests = 0;
$failures = 0;

function b_assert($condition, string $message): void
{
    global $tests, $failures;
    $tests++;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

function b_response(array $json, int $http = 200): array
{
    return [
        'executed' => true,
        'http_code' => $http,
        'curl_errno' => 0,
        'curl_error' => '',
        'body' => json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'headers' => ['content-type' => 'application/json'],
        'response_too_large' => false,
        'primary_ip' => '127.0.0.1',
        'ssl_verify_result' => 0,
        'timing' => ['total_ms' => 12],
    ];
}

function b_health_ok(string $url, int $timeout): array
{
    return [
        'executed' => true,
        'http_code' => 200,
        'curl_errno' => 0,
        'curl_error' => '',
        'body' => '',
        'headers' => [],
        'response_too_large' => false,
        'timing' => ['total_ms' => 5],
    ];
}

function b_redeem(string $voucher, string $phone, callable $redeemTransport, ?callable $healthTransport = null): array
{
    $debug = null;
    return redeemAngpaoByteIndev(
        $voucher,
        $phone,
        $debug,
        $healthTransport ?? 'b_health_ok',
        $redeemTransport
    );
}

$token = '019e0d03f0fa78bf893eb17f3750d31a4cS';
$voucher = 'https://gift.truemoney.com/campaign/?v=' . $token;
$phone = '0812345678';

$normalized = trueMoneyByteIndevNormalizeVoucher($voucher);
b_assert(!empty($normalized['success']), 'valid TrueMoney voucher should normalize');
b_assert(($normalized['token'] ?? '') === $token, 'voucher token should be preserved');
b_assert(empty(trueMoneyByteIndevNormalizeVoucher('https://example.com/campaign/?v=' . $token)['success']), 'foreign host should be rejected');
b_assert(empty(trueMoneyByteIndevNormalizeVoucher('not-a-url')['success']), 'invalid URL should be rejected');

b_assert(trueMoneyByteIndevAmount([
    'status' => ['code' => 'SUCCESS'],
    'data' => ['my_ticket' => ['amount_baht' => '155.00']],
]) === 155.0, 'data.my_ticket.amount_baht should be accepted');
b_assert(trueMoneyByteIndevAmount([
    'status' => ['code' => 'SUCCESS'],
    'data' => ['amount_baht' => '155.00'],
]) === null, 'unapproved amount path must not be accepted');

$healthCalls = [];
$unusedDebug = null;
$selection = trueMoneyByteIndevSelectProvider($unusedDebug, static function (string $url, int $timeout) use (&$healthCalls): array {
    $healthCalls[] = $url;
    if (strpos($url, 'truemoney-voucher-go.vercel.app') !== false) {
        return [
            'executed' => false,
            'http_code' => 0,
            'curl_errno' => 6,
            'curl_error' => 'Could not resolve host',
            'body' => '',
            'headers' => [],
            'response_too_large' => false,
            'timing' => ['total_ms' => 1],
        ];
    }
    return b_health_ok($url, $timeout);
});
b_assert(!empty($selection['success']), 'health selection should fall back to second backend');
b_assert(($selection['provider']['name'] ?? '') === 'nestjs', 'NestJS should be selected after Go health failure');
b_assert(count($healthCalls) === 2, 'health selection should stop after first healthy backend');

$redeemCalls = [];
$result = b_redeem(
    $voucher,
    $phone,
    static function (string $url, int $timeout) use (&$redeemCalls): array {
        $redeemCalls[] = $url;
        return b_response([
            'status' => ['code' => 'SUCCESS', 'message' => 'Success'],
            'data' => ['my_ticket' => ['amount_baht' => '155.00']],
        ]);
    }
);
b_assert(!empty($result['success']), 'SUCCESS contract should redeem');
b_assert(($result['amount'] ?? null) === 155.0, 'SUCCESS should return gross redeemed amount');
b_assert(($result['backend'] ?? '') === 'go', 'first healthy backend should be used');
b_assert(count($redeemCalls) === 1, 'redeem must call exactly one backend');
b_assert(strpos($redeemCalls[0], '/truemoney/' . rawurlencode($token) . '/' . $phone) !== false, 'redeem path should contain encoded voucher token and receiver phone');

$result = b_redeem(
    $voucher,
    $phone,
    static function (): array {
        return b_response([
            'status' => ['code' => 'VOUCHER_NOT_FOUND', 'message' => "Voucher doesn't exist."],
            'data' => null,
        ]);
    }
);
b_assert(empty($result['success']), 'VOUCHER_NOT_FOUND should fail');
b_assert(($result['code'] ?? '') === 'VOUCHER_NOT_FOUND', 'business failure code should be preserved');
b_assert(empty($result['indeterminate']), 'business failure should be definitive');

$result = b_redeem(
    $voucher,
    $phone,
    static function (): array {
        return b_response(['code' => 400, 'message' => 'Bad Request'], 200);
    }
);
b_assert(empty($result['success']) && ($result['code'] ?? '') === 'provider_400', 'backend validation envelope should be definitive');
b_assert(empty($result['indeterminate']), 'backend 400 envelope should not be indeterminate');

$result = b_redeem(
    $voucher,
    $phone,
    static function (): array {
        return b_response(['code' => 500, 'message' => 'Internal Server Error'], 200);
    }
);
b_assert(empty($result['success']) && !empty($result['indeterminate']), 'backend 500 envelope after redeem should be indeterminate');

$timeoutCalls = 0;
$result = b_redeem(
    $voucher,
    $phone,
    static function () use (&$timeoutCalls): array {
        $timeoutCalls++;
        return [
            'executed' => false,
            'http_code' => 0,
            'curl_errno' => 28,
            'curl_error' => 'Operation timed out',
            'body' => '',
            'headers' => [],
            'response_too_large' => false,
            'timing' => ['total_ms' => 35000],
        ];
    }
);
b_assert(empty($result['success']) && !empty($result['indeterminate']), 'redeem timeout should be indeterminate');
b_assert($timeoutCalls === 1, 'redeem timeout must not trigger backend failover');

$result = b_redeem(
    $voucher,
    $phone,
    static function (): array {
        return b_response(['error' => 'gateway exploded'], 502);
    }
);
b_assert(empty($result['success']) && !empty($result['indeterminate']), 'HTTP 5xx after redeem should be indeterminate');

$result = b_redeem(
    $voucher,
    $phone,
    static function (): array {
        return [
            'executed' => true,
            'http_code' => 200,
            'curl_errno' => 0,
            'curl_error' => '',
            'body' => '<html>unexpected</html>',
            'headers' => ['content-type' => 'text/html'],
            'response_too_large' => false,
            'timing' => ['total_ms' => 10],
        ];
    }
);
b_assert(empty($result['success']) && !empty($result['indeterminate']), 'invalid 2xx JSON should be indeterminate');

$result = b_redeem(
    $voucher,
    $phone,
    static function (): array {
        return b_response([
            'status' => ['code' => 'SUCCESS', 'message' => 'Success'],
            'data' => ['my_ticket' => []],
        ]);
    }
);
b_assert(empty($result['success']) && !empty($result['indeterminate']), 'SUCCESS without amount should be indeterminate');

$result = b_redeem(
    $voucher,
    $phone,
    static function (): array {
        return b_response(['code' => 429, 'message' => 'Too Many Requests'], 429);
    }
);
b_assert(empty($result['success']) && ($result['code'] ?? '') === 'provider_rate_limited', 'HTTP 429 should expose rate-limit code');
b_assert(empty($result['indeterminate']), 'HTTP 429 should be safe to retry later');

$redeemWasCalled = false;
$result = b_redeem(
    $voucher,
    $phone,
    static function () use (&$redeemWasCalled): array {
        $redeemWasCalled = true;
        return b_response([]);
    },
    static function (): array {
        return [
            'executed' => false,
            'http_code' => 0,
            'curl_errno' => 6,
            'curl_error' => 'Could not resolve host',
            'body' => '',
            'headers' => [],
            'response_too_large' => false,
            'timing' => ['total_ms' => 1],
        ];
    }
);
b_assert(empty($result['success']) && ($result['code'] ?? '') === 'provider_unavailable', 'all failed health probes should stop before redeem');
b_assert($redeemWasCalled === false, 'redeem must not run when all backend health checks fail');
b_assert(empty($result['indeterminate']), 'pre-redeem provider outage should be safe and definitive');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} of {$tests} assertions failed\n");
    exit(1);
}

echo "PASS: {$tests} assertions\n";
