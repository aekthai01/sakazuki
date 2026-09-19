<?php
require_once __DIR__ . '/../public_html/includes/truemoney_zelthr.php';

// Deliberately non-existent voucher: exercises the live gateway/TrueMoney route
// without ever redeeming real value.
$fakeVoucher = 'https://gift.truemoney.com/campaign/?v=ZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZ';
$response = trueMoneyZelthrCurlRequest(
    (string) TM_ZELTHR_API_URL,
    ['gift' => $fakeVoucher, 'phone' => '0812345678']
);

$http = (int) ($response['http_code'] ?? 0);
$errno = (int) ($response['curl_errno'] ?? 0);
if (empty($response['executed']) || $errno !== 0 || $http === 0 || $http >= 500) {
    fwrite(STDERR, "Zelthr live smoke failed: http={$http} curl_errno={$errno}\n");
    exit(1);
}

$decoded = trueMoneyZelthrDecode($response);
if (empty($decoded['valid']) || !is_array($decoded['json'])) {
    fwrite(STDERR, "Zelthr live smoke returned invalid JSON (HTTP {$http})\n");
    exit(1);
}

$json = $decoded['json'];
$status = trueMoneyZelthrStatus($json);
$gateway = trueMoneyZelthrGatewayError($json);
$code = $status['code'] !== '' ? $status['code'] : $gateway['slug'];
if ($code === '' && $http >= 400) {
    fwrite(STDERR, "Zelthr live smoke returned unrecognized error contract (HTTP {$http})\n");
    exit(1);
}
if ($status['code'] === 'SUCCESS') {
    fwrite(STDERR, "Zelthr smoke voucher unexpectedly redeemed; refusing to continue\n");
    exit(1);
}

printf("PASS: Zelthr gateway reachable (HTTP %d, code %s)\n", $http, $code !== '' ? $code : 'reachable');
