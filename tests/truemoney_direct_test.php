<?php
require_once __DIR__ . '/../public_html/includes/truemoney_direct.php';

$tests = 0;
$failures = 0;

function tm_assert($condition, string $message): void
{
    global $tests, $failures;
    $tests++;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

function tm_response(array $json, int $http = 200): array
{
    return [
        'executed' => true,
        'http_code' => $http,
        'curl_errno' => 0,
        'curl_error' => '',
        'body' => json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'headers' => ['content-type' => 'application/json'],
        'header_names' => ['content-type'],
        'timing' => ['total_ms' => 12],
        'response_too_large' => false,
    ];
}

function tm_config_ok(): array
{
    return tm_response([
        'status' => ['code' => 'SUCCESS', 'message' => 'success'],
        'data' => ['ma' => ['title_th' => null, 'title_en' => null, 'message_th' => null, 'message_en' => null]],
    ]);
}

$phone = '0812345678';
$token = '019e0d03f0fa78bf893eb17f3750d31a4cS';
$voucher = 'https://gift.truemoney.com/campaign/?v=' . $token;

$normalized = trueMoneyDirectNormalizeVoucher($voucher);
tm_assert(!empty($normalized['success']), 'valid TrueMoney voucher URL should normalize');
tm_assert(($normalized['token'] ?? '') === $token, 'voucher token must be preserved');

tm_assert(trueMoneyDirectNormalizeVoucher('http://gift.truemoney.com/campaign/?v=' . $token)['success'] === false, 'HTTP voucher URL must be rejected');
tm_assert(trueMoneyDirectNormalizeVoucher('https://evil.example/campaign/?v=' . $token)['success'] === false, 'foreign host must be rejected');

$successPayload = [
    'status' => ['code' => 'SUCCESS', 'message' => 'success'],
    'data' => [
        'my_ticket' => ['mobile' => $phone, 'amount_baht' => '155.00'],
        'redeemer_profile' => ['mobile_number' => $phone],
    ],
];
tm_assert(trueMoneyDirectAmountFromRedeem($successPayload) === 155.0, 'SUCCESS amount should parse as baht');

$ticket = trueMoneyDirectFindReceiverTicket([
    'data' => ['tickets' => [
        ['mobile' => '0899999999', 'amount_baht' => '10.00'],
        ['mobile' => $phone, 'amount_baht' => '155.00'],
    ]],
], $phone);
tm_assert(is_array($ticket) && ($ticket['amount'] ?? null) === 155.0, 'verify ticket must match exact receiver phone');

tm_assert(trueMoneyDirectFindReceiverTicket([
    'data' => ['tickets' => [['mobile' => '081***5678', 'amount_baht' => '155.00']]],
], $phone) === null, 'masked phone must not be treated as exact reconciliation evidence');

$queue = [
    tm_config_ok(),
    tm_response($successPayload),
];
$calls = [];
$transport = static function (string $method, string $url, ?array $body, array $headers) use (&$queue, &$calls): array {
    $calls[] = compact('method', 'url', 'body');
    return array_shift($queue);
};
$debug = null;
$result = redeemAngpaoDirect($voucher, $phone, $debug, $transport);
tm_assert(!empty($result['success']), 'direct redeem should succeed for valid SUCCESS response');
tm_assert(($result['amount'] ?? null) === 155.0, 'direct redeem should return exact gross amount');
tm_assert(count($calls) === 2, 'normal direct flow should call configuration then redeem exactly once');
tm_assert($calls[1]['method'] === 'POST', 'redeem must use POST');
tm_assert(($calls[1]['body']['mobile'] ?? '') === $phone, 'redeem body must contain receiver phone');
tm_assert(($calls[1]['body']['voucher_hash'] ?? '') === $token, 'redeem body must contain voucher hash');

$maintenanceQueue = [
    tm_response([
        'status' => ['code' => 'SUCCESS', 'message' => 'success'],
        'data' => ['ma' => ['title_th' => 'ปิดปรับปรุง', 'title_en' => null, 'message_th' => null, 'message_en' => null]],
    ]),
];
$maintenanceTransport = static function () use (&$maintenanceQueue): array {
    return array_shift($maintenanceQueue);
};
$debug = null;
$result = redeemAngpaoDirect($voucher, $phone, $debug, $maintenanceTransport);
tm_assert(empty($result['success']) && ($result['code'] ?? '') === 'MAINTENANCE', 'maintenance must stop before redeem POST');

$reconcileQueue = [
    tm_config_ok(),
    [
        'executed' => false,
        'http_code' => 0,
        'curl_errno' => 28,
        'curl_error' => 'Operation timed out',
        'body' => '',
        'headers' => [],
        'header_names' => [],
        'timing' => ['total_ms' => 20000],
        'response_too_large' => false,
    ],
    tm_response([
        'status' => ['code' => 'SUCCESS', 'message' => 'success'],
        'data' => ['tickets' => [['mobile' => $phone, 'amount_baht' => '88.50']]],
    ]),
];
$reconcileTransport = static function () use (&$reconcileQueue): array {
    return array_shift($reconcileQueue);
};
$debug = null;
$result = redeemAngpaoDirect($voucher, $phone, $debug, $reconcileTransport);
tm_assert(!empty($result['success']) && !empty($result['reconciled']), 'ambiguous POST should reconcile with exact verify ticket');
tm_assert(($result['amount'] ?? null) === 88.5, 'reconciled amount should come from verified receiver ticket');

$pendingQueue = [
    tm_config_ok(),
    [
        'executed' => false,
        'http_code' => 0,
        'curl_errno' => 28,
        'curl_error' => 'Operation timed out',
        'body' => '',
        'headers' => [],
        'header_names' => [],
        'timing' => ['total_ms' => 20000],
        'response_too_large' => false,
    ],
    tm_response([
        'status' => ['code' => 'SUCCESS', 'message' => 'success'],
        'data' => ['tickets' => [['mobile' => '0899999999', 'amount_baht' => '88.50']]],
    ]),
];
$pendingTransport = static function () use (&$pendingQueue): array {
    return array_shift($pendingQueue);
};
$debug = null;
$result = redeemAngpaoDirect($voucher, $phone, $debug, $pendingTransport);
tm_assert(empty($result['success']) && !empty($result['indeterminate']), 'ambiguous POST without exact ticket must remain indeterminate');
tm_assert(($result['code'] ?? '') === 'provider_indeterminate', 'indeterminate result must use stable provider_indeterminate code');

$businessErrorQueue = [
    tm_config_ok(),
    tm_response([
        'status' => ['code' => 'VOUCHER_EXPIRED', 'message' => 'voucher expired'],
        'data' => null,
    ]),
];
$businessErrorTransport = static function () use (&$businessErrorQueue): array {
    return array_shift($businessErrorQueue);
};
$debug = null;
$result = redeemAngpaoDirect($voucher, $phone, $debug, $businessErrorTransport);
tm_assert(empty($result['success']) && ($result['code'] ?? '') === 'VOUCHER_EXPIRED', 'business error code should be preserved');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} of {$tests} assertions failed\n");
    exit(1);
}

echo "PASS: {$tests} assertions\n";
