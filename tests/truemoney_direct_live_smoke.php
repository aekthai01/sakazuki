<?php
require_once __DIR__ . '/../public_html/includes/truemoney_direct.php';

$response = trueMoneyDirectCurlRequest(
    'GET',
    trueMoneyDirectApiUrl('/campaign/vouchers/configuration'),
    null,
    []
);

$http = (int) ($response['http_code'] ?? 0);
$errno = (int) ($response['curl_errno'] ?? 0);
if (empty($response['executed']) || $errno !== 0 || $http < 200 || $http >= 300) {
    fwrite(STDERR, "TrueMoney configuration smoke failed: http={$http} curl_errno={$errno}\n");
    exit(1);
}

$decoded = trueMoneyDirectDecode($response);
if (empty($decoded['valid']) || !is_array($decoded['json'])) {
    fwrite(STDERR, "TrueMoney configuration smoke returned invalid JSON\n");
    exit(1);
}
$status = trueMoneyDirectStatus($decoded['json']);
if (($status['code'] ?? '') === '') {
    fwrite(STDERR, "TrueMoney configuration smoke returned no status code\n");
    exit(1);
}

printf("PASS: TrueMoney configuration reachable (HTTP %d, status %s)\n", $http, $status['code']);
