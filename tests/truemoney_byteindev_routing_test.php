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

function r_redeem_response(string $statusCode, string $mobile = '0812345678', ?string $fullName = 'Sakazuki ***'): array
{
    $data = [];
    if ($statusCode === 'SUCCESS') {
        $data = [
            'redeemer_profile' => ['mobile_number' => $mobile],
            'my_ticket' => [
                'mobile' => $mobile,
                'amount_baht' => '10.00',
            ],
        ];
        if ($fullName !== null) {
            $data['my_ticket']['full_name'] = $fullName;
        }
    }

    return [
        'executed' => true,
        'http_code' => 200,
        'curl_errno' => 0,
        'curl_error' => '',
        'body' => json_encode([
            'status' => ['code' => $statusCode, 'message' => strtolower($statusCode)],
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'headers' => [],
        'response_too_large' => false,
        'primary_ip' => '127.0.0.1',
        'ssl_verify_result' => 0,
        'timing' => ['total_ms' => 8],
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

$nameHash = hash('sha256', 'Sakazuki');
$verified = trueMoneyByteIndevProductionRecipientCheck(
    json_decode((string) r_redeem_response('SUCCESS')['body'], true),
    '0812345678',
    $nameHash
);
r_assert(!empty($verified['verified']), 'Recipient guard should accept exact receiver phone and first name');
r_assert(!empty($verified['phone_match']), 'Recipient guard should report exact phone match');
r_assert(!empty($verified['name_present']) && !empty($verified['name_match']), 'Recipient guard should verify present first name');

$wrongPhoneJson = json_decode((string) r_redeem_response('SUCCESS', '0899999999')['body'], true);
$wrongPhone = trueMoneyByteIndevProductionRecipientCheck($wrongPhoneJson, '0812345678', $nameHash);
r_assert(empty($wrongPhone['verified']), 'Recipient guard must reject a different receiver phone');
r_assert(($wrongPhone['reason'] ?? '') === 'recipient_mobile_mismatch', 'Phone mismatch should have a specific reason');

$wrongNameJson = json_decode((string) r_redeem_response('SUCCESS', '0812345678', 'Someone Else')['body'], true);
$wrongName = trueMoneyByteIndevProductionRecipientCheck($wrongNameJson, '0812345678', $nameHash);
r_assert(empty($wrongName['verified']), 'Recipient guard must reject a different first name when name is present');
r_assert(($wrongName['reason'] ?? '') === 'recipient_name_mismatch', 'Name mismatch should have a specific reason');

$noNameJson = json_decode((string) r_redeem_response('SUCCESS', '0812345678', null)['body'], true);
$noName = trueMoneyByteIndevProductionRecipientCheck($noNameJson, '0812345678', $nameHash);
r_assert(!empty($noName['verified']), 'Exact phone should remain sufficient if TrueMoney omits the name field');
r_assert(empty($noName['name_present']), 'Missing name should be reported without inventing a value');

$fallbackJson = $noNameJson;
unset($fallbackJson['data']['redeemer_profile']);
$fallbackJson['data']['my_ticket']['mobile'] = '0812345678';
$fallback = trueMoneyByteIndevProductionRecipientCheck($fallbackJson, '0812345678', $nameHash);
r_assert(!empty($fallback['verified']), 'Recipient guard should use full my_ticket.mobile when redeemer_profile is absent');

$redeemUrl = 'https://truemoney-voucher-nestjs.vercel.app/truemoney/TESTTOKEN/0812345678';
$passTransport = trueMoneyByteIndevProductionRedeemTransport(
    $redeemUrl,
    10,
    static function (): array {
        return r_redeem_response('SUCCESS');
    },
    null,
    $nameHash
);
r_assert(!empty($passTransport['executed']), 'Verified SUCCESS response must remain executable');
r_assert(($passTransport['curl_errno'] ?? -1) === 0, 'Verified SUCCESS response must preserve curl success');
r_assert(($passTransport['headers']['x-sakazuki-recipient-guard'] ?? '') === 'verified', 'Verified response should expose safe guard evidence');

$blockedTransport = trueMoneyByteIndevProductionRedeemTransport(
    $redeemUrl,
    10,
    static function (): array {
        return r_redeem_response('SUCCESS', '0899999999');
    },
    null,
    $nameHash
);
r_assert(empty($blockedTransport['executed']), 'Wrong recipient after SUCCESS must be converted to indeterminate transport result');
r_assert(($blockedTransport['curl_errno'] ?? 0) === -21, 'Wrong recipient should use dedicated guard errno');
r_assert(strpos((string) ($blockedTransport['headers']['x-sakazuki-recipient-guard'] ?? ''), 'blocked:') === 0, 'Blocked response should expose only a safe guard reason');

$nameBlockedTransport = trueMoneyByteIndevProductionRedeemTransport(
    $redeemUrl,
    10,
    static function (): array {
        return r_redeem_response('SUCCESS', '0812345678', 'Someone Else');
    },
    null,
    $nameHash
);
r_assert(empty($nameBlockedTransport['executed']), 'Wrong recipient name after SUCCESS must not reach wallet settlement');
r_assert(strpos((string) ($nameBlockedTransport['curl_error'] ?? ''), 'recipient_name_mismatch') !== false, 'Name mismatch should be diagnosable without logging the name');

$businessError = trueMoneyByteIndevProductionRedeemTransport(
    $redeemUrl,
    10,
    static function (): array {
        return r_redeem_response('VOUCHER_NOT_FOUND');
    },
    null,
    $nameHash
);
r_assert(!empty($businessError['executed']), 'Non-SUCCESS business responses must pass through unchanged');
r_assert(($businessError['curl_errno'] ?? -1) === 0, 'Business error must not be rewritten as recipient mismatch');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} of {$tests} assertions failed\n");
    exit(1);
}

echo "PASS: {$tests} routing assertions\n";
