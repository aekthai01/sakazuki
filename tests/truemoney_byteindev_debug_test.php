<?php
function trueMoneyDebugSafeText($value, int $maxLength = 1500): string
{
    $text = is_scalar($value) ? trim((string) $value) : '';
    return strlen($text) > $maxLength ? substr($text, 0, $maxLength) : $text;
}

function trueMoneyDebugSanitize($value, int $depth = 0)
{
    return $value;
}

function trueMoneyDebugMaskedPhone(string $digits): string
{
    $digits = preg_replace('/\D+/', '', $digits) ?? '';
    return $digits === '' ? '' : str_repeat('*', max(0, strlen($digits) - 4)) . substr($digits, -4);
}

function trueMoneyDebugId(string $prefix): string
{
    return $prefix . substr(hash('sha256', $prefix . microtime(true) . random_int(1, 999999)), 0, 24);
}

function trueMoneyDebugStart(int $userId): array
{
    return [
        '_started_at' => microtime(true),
        'schema' => 'sakazuki.debug',
        'version' => 1,
        'type' => 'truemoney.redemption',
        'request_id' => trueMoneyDebugId('tmreq_'),
        'attempt_id' => null,
        'client' => ['user_id' => $userId],
        'redemption' => ['id' => null, 'voucher_fingerprint' => null, 'reservation_mode' => null],
        'provider' => [],
        'settlement' => [],
        'wallet' => [],
        'integrity' => [],
        'timeline' => [],
        'result' => ['success' => null, 'stage' => 'request_received', 'code' => null],
        'errors' => [],
        'privacy' => [
            'voucher_token_stored' => false,
            'raw_provider_body_stored' => false,
            'raw_provider_effective_url_stored' => false,
            'credentials_redacted' => true,
        ],
    ];
}

function trueMoneyDebugEvent(array &$debug, string $stage, array $details = []): void
{
    $debug['timeline'][] = ['stage' => $stage, 'details' => $details];
    $debug['result']['stage'] = $stage;
}

function trueMoneyDebugSetResult(array &$debug, bool $success, string $stage, string $code): void
{
    $debug['result'] = ['success' => $success, 'stage' => $stage, 'code' => $code];
}

function trueMoneyDebugPersist(array &$debug): bool
{
    return true;
}

require_once __DIR__ . '/../public_html/includes/truemoney_byteindev.php';
require_once __DIR__ . '/../public_html/includes/truemoney_byteindev_route.php';
require_once __DIR__ . '/../public_html/includes/truemoney_byteindev_debug.php';

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

function d_transport(int $http, array $payload = [], int $errno = 0): array
{
    return [
        'executed' => $errno === 0,
        'http_code' => $http,
        'curl_errno' => $errno,
        'curl_error' => $errno === 0 ? '' : 'synthetic error',
        'body' => $payload === [] ? '{}' : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'headers' => [
            'status_line' => 'HTTP/1.1 ' . $http . ' Test',
            'content-type' => 'application/json',
            'server' => 'test',
        ],
        'response_too_large' => false,
        'primary_ip' => '127.0.0.1',
        'ssl_verify_result' => 0,
        'timing' => ['total_ms' => 7],
    ];
}

$contract = trueMoneyByteIndevDebugContract();
d_assert(($contract['provider'] ?? '') === 'byteindev_failover', 'Debug contract must describe ByteInDev provider family');
d_assert(($contract['effective_order'] ?? []) === ['nestjs', 'fastapi'], 'Effective order must be NestJS then FastAPI');
d_assert(($contract['disabled_backends'] ?? []) === ['go'], 'Go must be reported disabled');
d_assert(($contract['automatic_retry_after_redeem'] ?? true) === false, 'Contract must preserve no retry after redeem');
d_assert(($contract['recipient_guard']['enabled'] ?? false) === true, 'Recipient guard must be reported enabled');

$policy = trueMoneyByteIndevDebugRoutingPolicy();
d_assert(count($policy) === 3, 'Routing policy must expose all three known backends');
d_assert(($policy[0]['name'] ?? '') === 'go' && empty($policy[0]['enabled']), 'Go must be disabled in policy');
d_assert(($policy[1]['name'] ?? '') === 'nestjs' && ($policy[1]['role'] ?? '') === 'primary', 'NestJS must be primary');
d_assert(($policy[2]['name'] ?? '') === 'fastapi' && ($policy[2]['role'] ?? '') === 'fallback', 'FastAPI must be fallback');

$delegateCalls = [];
$probe = trueMoneyByteIndevRunSafeProviderProbe(123, static function (string $url, int $timeout) use (&$delegateCalls): array {
    $delegateCalls[] = $url;
    if (str_ends_with($url, '/status')) {
        return d_transport(200, ['status' => 'ok']);
    }
    return d_transport(200, [
        'status' => ['code' => 'VOUCHER_NOT_FOUND', 'message' => 'not found'],
        'data' => null,
    ]);
});

d_assert(($probe['type'] ?? '') === 'truemoney.provider_probe', 'Probe type must identify provider diagnostics');
d_assert(($probe['result']['success'] ?? false) === true, 'Probe should pass when both enabled backends reject the synthetic voucher normally');
d_assert(($probe['result']['code'] ?? '') === 'probe_byteindev_ready', 'Healthy probe should use current ready result code');
d_assert(($probe['probe']['summary']['selected_backend_by_health'] ?? '') === 'nestjs', 'Probe should select NestJS by production health order');
d_assert(($probe['probe']['summary']['usable_backend_count'] ?? 0) === 2, 'Both enabled backends should be usable');
d_assert(($probe['probe']['summary']['degraded'] ?? true) === false, 'Two usable backends must not be degraded');
d_assert(($probe['provider']['target']['host'] ?? '') === 'truemoney-voucher-nestjs.vercel.app', 'Summary host should be selected NestJS');
d_assert(count($delegateCalls) === 4, 'Go must not call delegate; NestJS/FastAPI each perform health and synthetic redeem');
d_assert(($probe['probe']['backends'][0]['synthetic_redeem']['reason'] ?? '') === 'backend_disabled_by_production_guard', 'Go synthetic redeem must never execute');
d_assert(!empty($probe['probe']['backends'][1]['synthetic_redeem']['expected_rejection_observed']), 'NestJS should record expected synthetic rejection');
d_assert(!empty($probe['probe']['backends'][2]['synthetic_redeem']['expected_rejection_observed']), 'FastAPI should record expected synthetic rejection');

$critical = trueMoneyByteIndevRunSafeProviderProbe(123, static function (string $url, int $timeout): array {
    if (str_ends_with($url, '/status')) {
        return d_transport(200, ['status' => 'ok']);
    }
    if (str_contains($url, 'truemoney-voucher-nestjs.vercel.app')) {
        return d_transport(200, [
            'status' => ['code' => 'SUCCESS', 'message' => 'success'],
            'data' => ['my_ticket' => ['amount_baht' => 10]],
        ]);
    }
    return d_transport(200, [
        'status' => ['code' => 'VOUCHER_NOT_FOUND', 'message' => 'not found'],
    ]);
});

d_assert(($critical['result']['success'] ?? true) === false, 'Synthetic voucher SUCCESS must fail the probe');
d_assert(($critical['result']['code'] ?? '') === 'probe_synthetic_voucher_unexpected_success', 'Unexpected success must use critical result code');
d_assert(!empty($critical['probe']['summary']['unexpected_success']), 'Unexpected success must be explicit in JSON');

$degraded = trueMoneyByteIndevRunSafeProviderProbe(123, static function (string $url, int $timeout): array {
    if (str_contains($url, 'truemoney-voucher-fastapi.vercel.app/status')) {
        return d_transport(503, ['status' => 'down']);
    }
    if (str_ends_with($url, '/status')) {
        return d_transport(200, ['status' => 'ok']);
    }
    return d_transport(200, [
        'status' => ['code' => 'VOUCHER_NOT_FOUND', 'message' => 'not found'],
    ]);
});

d_assert(($degraded['result']['success'] ?? false) === true, 'Primary usable with fallback down should still be operational');
d_assert(($degraded['result']['code'] ?? '') === 'probe_byteindev_degraded', 'Fallback outage must be reported as degraded');
d_assert(($degraded['probe']['summary']['usable_backend_count'] ?? 0) === 1, 'Degraded probe should report one usable backend');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} of {$tests} assertions failed\n");
    exit(1);
}

echo "PASS: {$tests} admin-debug assertions\n";
