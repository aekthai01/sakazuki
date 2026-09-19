<?php
require_once __DIR__ . '/../public_html/includes/truemoney_dokmai.php';

$apiKey = trim((string) (getenv('DOKMAI_API_KEY') ?: ''));
if ($apiKey === '') {
    fwrite(STDERR, "DOKMAI_API_KEY is not configured in CI\n");
    exit(1);
}

// Deliberately invalid synthetic voucher. This verifies authenticated gateway
// connectivity and response contract without risking redemption of real value.
$fakeVoucher = 'https://gift.truemoney.com/campaign/?v=SAKAZUKI_CI_DO_NOT_REDEEM';
$voucherHash = hash('sha256', 'SAKAZUKI_CI_DO_NOT_REDEEM');
$idempotencyKey = trueMoneyDokmaiIdempotencyKey(1, $voucherHash);
$response = trueMoneyDokmaiCurlRequest(
    (string) TM_DOKMAI_API_URL,
    ['phoneNumber' => '0812345678', 'voucher' => $fakeVoucher],
    $apiKey,
    $idempotencyKey
);

$http = (int) ($response['http_code'] ?? 0);
$errno = (int) ($response['curl_errno'] ?? 0);
if (empty($response['executed']) || $errno !== 0 || $http === 0 || $http >= 500) {
    fwrite(STDERR, "Dokmai live smoke failed: http={$http} curl_errno={$errno}\n");
    exit(1);
}

$decoded = trueMoneyDokmaiDecode($response);
if (empty($decoded['valid']) || !is_array($decoded['json'])) {
    fwrite(STDERR, "Dokmai live smoke returned invalid JSON (HTTP {$http})\n");
    exit(1);
}

$json = $decoded['json'];
$success = trueMoneyDokmaiBool($json['success'] ?? null);
$error = trueMoneyDokmaiError($json);
if ($success === true) {
    fwrite(STDERR, "Synthetic Dokmai voucher unexpectedly succeeded; refusing to continue\n");
    exit(1);
}
if (($error['code'] ?? '') === 'invalid_api_key') {
    fwrite(STDERR, "Dokmai CI secret is invalid or inactive\n");
    exit(1);
}
if ($success !== false && ($error['code'] ?? '') === '') {
    fwrite(STDERR, "Dokmai live smoke returned unrecognized response contract\n");
    exit(1);
}

printf(
    "PASS: Dokmai authenticated endpoint reachable (HTTP %d, code %s, retryable=%s)\n",
    $http,
    ($error['code'] ?? '') !== '' ? $error['code'] : 'definitive-rejection',
    ($error['retryable'] ?? null) === true ? 'true' : (($error['retryable'] ?? null) === false ? 'false' : 'unknown')
);
