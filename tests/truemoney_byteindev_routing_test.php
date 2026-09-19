<?php
require_once __DIR__ . '/../public_html/includes/truemoney_byteindev.php';
require_once __DIR__ . '/../public_html/includes/truemoney_byteindev_route.php';

$tests = 0;
$failures = 0;

function r_assert($condition, string $message): void
{
    global $tests, $failures;
    $tests++;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

function r_health_ok(): array
{
    return [
        'executed' => true,
        'http_code' => 200,
        'curl_errno' => 0,
        'curl_error' => '',
        'body' => '',
        'headers' => [],
        'response_too_large' => false,
        'primary_ip' => '127.0.0.1',
        'ssl_verify_result' => 0,
        'timing' => ['total_ms' => 5],
    ];
}

$delegateCalls = 0;
$go = trueMoneyByteIndevProductionHealthTransport(
    'https://truemoney-voucher-go.vercel.app/status',
    5,
    static function () use (&$delegateCalls): array {
        $delegateCalls++;
        return r_health_ok();
    }
);
r_assert(empty($go['executed']), 'Go backend must be disabled before any network call');
r_assert(($go['curl_errno'] ?? 0) === -2, 'Go backend should expose routing-disabled marker');
r_assert($delegateCalls === 0, 'Go backend must not invoke health delegate');

$nest = trueMoneyByteIndevProductionHealthTransport(
    'https://truemoney-voucher-nestjs.vercel.app/status',
    5,
    static function () use (&$delegateCalls): array {
        $delegateCalls++;
        return r_health_ok();
    }
);
r_assert(!empty($nest['executed']) && ($nest['http_code'] ?? 0) === 200, 'NestJS health must delegate normally');
r_assert($delegateCalls === 1, 'NestJS health should invoke delegate exactly once');

$fast = trueMoneyByteIndevProductionHealthTransport(
    'https://truemoney-voucher-fastapi.vercel.app/status',
    5,
    static function () use (&$delegateCalls): array {
        $delegateCalls++;
        return r_health_ok();
    }
);
r_assert(!empty($fast['executed']) && ($fast['http_code'] ?? 0) === 200, 'FastAPI health must delegate normally');
r_assert($delegateCalls === 2, 'FastAPI health should invoke delegate exactly once');

$debug = null;
$selection = trueMoneyByteIndevSelectProvider(
    $debug,
    static function (string $url, int $timeout): array {
        return trueMoneyByteIndevProductionHealthTransport(
            $url,
            $timeout,
            static function (): array {
                return r_health_ok();
            }
        );
    }
);
r_assert(!empty($selection['success']), 'Production routing should select a healthy backend');
r_assert(($selection['provider']['name'] ?? '') === 'nestjs', 'NestJS should be selected first while Go is disabled');

$debug = null;
$selection = trueMoneyByteIndevSelectProvider(
    $debug,
    static function (string $url, int $timeout): array {
        return trueMoneyByteIndevProductionHealthTransport(
            $url,
            $timeout,
            static function (string $delegatedUrl): array {
                $host = strtolower((string) (parse_url($delegatedUrl, PHP_URL_HOST) ?? ''));
                if ($host === 'truemoney-voucher-nestjs.vercel.app') {
                    return [
                        'executed' => true,
                        'http_code' => 503,
                        'curl_errno' => 0,
                        'curl_error' => '',
                        'body' => '',
                        'headers' => [],
                        'response_too_large' => false,
                        'primary_ip' => '127.0.0.1',
                        'ssl_verify_result' => 0,
                        'timing' => ['total_ms' => 5],
                    ];
                }
                return r_health_ok();
            }
        );
    }
);
r_assert(!empty($selection['success']), 'Production routing should fall back when NestJS health fails');
r_assert(($selection['provider']['name'] ?? '') === 'fastapi', 'FastAPI should be selected after NestJS health failure');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} of {$tests} assertions failed\n");
    exit(1);
}

echo "PASS: {$tests} routing assertions\n";
