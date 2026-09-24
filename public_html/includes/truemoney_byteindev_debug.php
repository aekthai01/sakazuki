<?php
/**
 * Admin diagnostics for the production ByteInDev TrueMoney route.
 *
 * This file intentionally contains no wallet mutation logic. The probe uses an
 * invalid synthetic voucher and records sanitized network evidence only.
 */

function trueMoneyByteIndevDebugRoutingPolicy(): array
{
    $rows = [];
    foreach (trueMoneyByteIndevProviders() as $provider) {
        $name = (string) ($provider['name'] ?? '');
        $baseUrl = rtrim((string) ($provider['base_url'] ?? ''), '/');
        $host = strtolower((string) (parse_url($baseUrl, PHP_URL_HOST) ?? ''));
        $enabled = $name !== 'go';
        $role = 'fallback';
        if ($name === 'go') {
            $role = 'disabled';
        } elseif ($name === 'nestjs') {
            $role = 'primary';
        }
        $rows[] = [
            'name' => $name,
            'host' => $host,
            'base_url' => $baseUrl,
            'enabled' => $enabled,
            'role' => $role,
            'health_path' => '/status',
            'redeem_path_template' => '/truemoney/{voucher}/{mobile}',
        ];
    }
    return $rows;
}

function trueMoneyByteIndevDebugContract(): array
{
    $policy = trueMoneyByteIndevDebugRoutingPolicy();
    $enabledOrder = [];
    $disabled = [];
    foreach ($policy as $backend) {
        if (!empty($backend['enabled'])) {
            $enabledOrder[] = (string) $backend['name'];
        } else {
            $disabled[] = (string) $backend['name'];
        }
    }

    return [
        'provider' => trueMoneyByteIndevProviderName(),
        'display_name' => trueMoneyByteIndevProviderDisplayName(),
        'production_adapter' => 'redeemAngpaoByteIndev',
        'selection_mode' => 'health_failover_before_redeem_only',
        'effective_order' => $enabledOrder,
        'disabled_backends' => $disabled,
        'automatic_retry_after_redeem' => false,
        'success_contract' => [
            'http' => '2xx',
            'status_path' => 'status.code',
            'success_value' => 'SUCCESS',
            'amount_path' => 'data.my_ticket.amount_baht',
        ],
        'recipient_guard' => [
            'enabled' => true,
            'phone_match_required' => true,
            'name_match_when_present' => true,
            'mismatch_action' => 'indeterminate_no_wallet_credit_no_retry',
        ],
        'probe' => [
            'uses_real_voucher' => false,
            'creates_redemption' => false,
            'writes_wallet' => false,
            'health_checks' => true,
            'synthetic_redeem_checks' => true,
            'raw_provider_body_stored' => false,
            'raw_effective_url_stored' => false,
        ],
        'backends' => $policy,
    ];
}

function trueMoneyByteIndevDebugTransportSummary(array $response): array
{
    $safeHeaders = [];
    $headers = is_array($response['headers'] ?? null) ? $response['headers'] : [];
    foreach (['status_line', 'content-type', 'retry-after', 'server', 'x-vercel-id'] as $key) {
        if (!array_key_exists($key, $headers)) continue;
        $value = $headers[$key];
        if (is_array($value)) {
            $value = array_slice(array_map(static function ($item): string {
                return trueMoneyDebugSafeText($item, 300);
            }, $value), 0, 5);
        } else {
            $value = trueMoneyDebugSafeText($value, 300);
        }
        $safeHeaders[$key] = $value;
    }

    return [
        'executed' => !empty($response['executed']),
        'http_code' => (int) ($response['http_code'] ?? 0),
        'curl_errno' => (int) ($response['curl_errno'] ?? 0),
        'curl_error' => trueMoneyDebugSafeText((string) ($response['curl_error'] ?? ''), 300),
        'primary_ip' => trueMoneyDebugSafeText((string) ($response['primary_ip'] ?? ''), 80),
        'ssl_verify_result' => $response['ssl_verify_result'] ?? null,
        'response_too_large' => !empty($response['response_too_large']),
        'body_bytes' => strlen((string) ($response['body'] ?? '')),
        'timing' => is_array($response['timing'] ?? null) ? trueMoneyDebugSanitize($response['timing']) : [],
        'headers' => $safeHeaders,
    ];
}

function trueMoneyByteIndevDebugResponseSummary(array $response): array
{
    $decoded = trueMoneyByteIndevDecode($response);
    $json = !empty($decoded['valid']) && is_array($decoded['json']) ? $decoded['json'] : null;
    $status = is_array($json) ? trueMoneyByteIndevStatus($json) : ['code' => '', 'message' => ''];
    $gateway = is_array($json) ? trueMoneyByteIndevGatewayError($json) : ['code' => '', 'message' => ''];
    $recognized = is_array($json)
        && (($status['code'] ?? '') !== '' || ($gateway['code'] ?? '') !== '' || ($gateway['message'] ?? '') !== '');

    return [
        'json_valid' => is_array($json),
        'json_error' => is_array($json) ? null : trueMoneyDebugSafeText((string) ($decoded['error'] ?? ''), 240),
        'top_level_keys' => is_array($json) ? array_slice(array_keys($json), 0, 20) : [],
        'status_code' => (string) ($status['code'] ?? ''),
        'status_message' => trueMoneyDebugSafeText((string) ($status['message'] ?? ''), 240),
        'gateway_code' => trueMoneyDebugSafeText((string) ($gateway['code'] ?? ''), 80),
        'gateway_message' => trueMoneyDebugSafeText((string) ($gateway['message'] ?? ''), 240),
        'contract_recognized' => $recognized,
    ];
}

/**
 * Probe the exact hosted provider family used by production without creating a
 * redemption row or changing a wallet. Go is reported as disabled by the same
 * routing guard used in production; NestJS and FastAPI are health-checked and,
 * when healthy, receive a deliberately invalid synthetic voucher request.
 */
function trueMoneyByteIndevRunSafeProviderProbe(int $adminId, ?callable $delegate = null): array
{
    $transport = $delegate ?? 'trueMoneyByteIndevCurlGet';
    if (!is_callable($transport)) {
        throw new RuntimeException('TrueMoney debug probe transport is not callable');
    }

    $debug = trueMoneyDebugStart($adminId);
    $debug['type'] = 'truemoney.provider_probe';
    $debug['request_id'] = trueMoneyDebugId('tmprobe_');
    $debug['attempt_id'] = trueMoneyDebugId('tmprobe_attempt_');
    trueMoneyByteIndevInitializeDebug($debug);

    $contract = trueMoneyByteIndevDebugContract();
    $debug['configuration'] = [
        'provider' => trueMoneyByteIndevProviderName(),
        'provider_display_name' => trueMoneyByteIndevProviderDisplayName(),
        'selection_mode' => 'health_failover_before_redeem_only',
        'effective_order' => $contract['effective_order'],
        'disabled_backends' => $contract['disabled_backends'],
        'automatic_retry_after_redeem' => false,
        'recipient_guard_enabled' => true,
    ];
    $debug['probe'] = [
        'mode' => 'byteindev_multi_backend_synthetic_voucher',
        'uses_real_voucher' => false,
        'creates_redemption' => false,
        'writes_wallet' => false,
        'synthetic_receiver_phone_masked' => '******5678',
        'backends' => [],
        'summary' => [],
    ];
    $debug['privacy']['synthetic_voucher'] = true;
    $debug['privacy']['raw_provider_body_stored'] = false;
    $debug['privacy']['raw_provider_effective_url_stored'] = false;
    trueMoneyDebugEvent($debug, 'probe_started', [
        'provider' => trueMoneyByteIndevProviderName(),
        'backend_count' => count((array) $contract['backends']),
    ]);

    $fakeToken = 'SAKAZUKI_ADMIN_PROBE_INVALID_VOUCHER';
    $fakePhone = '0812345678';
    $selectedByHealth = null;
    $selectedResult = null;
    $usableCount = 0;
    $unexpectedSuccess = false;

    foreach (trueMoneyByteIndevProviders() as $provider) {
        $name = (string) ($provider['name'] ?? '');
        $baseUrl = rtrim((string) ($provider['base_url'] ?? ''), '/');
        $host = strtolower((string) (parse_url($baseUrl, PHP_URL_HOST) ?? ''));
        $enabled = $name !== 'go';

        $healthResponse = trueMoneyByteIndevRunTransport(
            static function (string $url, int $timeoutSeconds) use ($transport): array {
                return trueMoneyByteIndevProductionHealthTransport($url, $timeoutSeconds, $transport);
            },
            $baseUrl . '/status',
            (int) TM_BYTEINDEV_HEALTH_TIMEOUT
        );
        $healthHealthy = trueMoneyByteIndevTransportSucceeded($healthResponse);
        $healthSummary = trueMoneyByteIndevDebugTransportSummary($healthResponse);

        trueMoneyDebugEvent($debug, 'probe_backend_health_checked', [
            'backend' => $name,
            'host' => $host,
            'enabled' => $enabled,
            'healthy' => $healthHealthy,
            'http_code' => $healthSummary['http_code'],
            'curl_errno' => $healthSummary['curl_errno'],
            'total_ms' => (int) ($healthSummary['timing']['total_ms'] ?? 0),
        ]);

        $backendResult = [
            'backend' => $name,
            'host' => $host,
            'enabled' => $enabled,
            'role' => $name === 'go' ? 'disabled' : ($name === 'nestjs' ? 'primary' : 'fallback'),
            'health' => array_merge($healthSummary, ['healthy' => $healthHealthy]),
            'synthetic_redeem' => [
                'executed' => false,
                'reason' => !$enabled ? 'backend_disabled_by_production_guard' : (!$healthHealthy ? 'health_check_failed' : 'not_started'),
            ],
            'usable' => false,
        ];

        if ($enabled && $healthHealthy) {
            if ($selectedByHealth === null) {
                $selectedByHealth = $name;
            }

            $redeemUrl = $baseUrl . '/truemoney/' . rawurlencode($fakeToken) . '/' . rawurlencode($fakePhone);
            $redeemResponse = trueMoneyByteIndevRunTransport($transport, $redeemUrl, (int) TM_BYTEINDEV_REDEEM_TIMEOUT);
            $transportSummary = trueMoneyByteIndevDebugTransportSummary($redeemResponse);
            $responseSummary = trueMoneyByteIndevDebugResponseSummary($redeemResponse);
            $transportOk = trueMoneyByteIndevTransportSucceeded($redeemResponse);
            $statusCode = strtoupper(trim((string) ($responseSummary['status_code'] ?? '')));
            $reportedSuccess = $statusCode === 'SUCCESS';
            $usable = $transportOk && !empty($responseSummary['json_valid']) && !empty($responseSummary['contract_recognized']) && !$reportedSuccess;
            if ($reportedSuccess) {
                $unexpectedSuccess = true;
            }
            if ($usable) {
                $usableCount++;
            }

            $backendResult['synthetic_redeem'] = [
                'executed' => true,
                'voucher_fingerprint' => substr(hash('sha256', $fakeToken), 0, 16),
                'receiver_phone_masked' => '******5678',
                'transport' => $transportSummary,
                'response' => $responseSummary,
                'expected_rejection_observed' => $usable,
                'unexpected_success' => $reportedSuccess,
            ];
            $backendResult['usable'] = $usable;

            trueMoneyDebugEvent($debug, 'probe_backend_synthetic_redeem_checked', [
                'backend' => $name,
                'host' => $host,
                'http_code' => $transportSummary['http_code'],
                'curl_errno' => $transportSummary['curl_errno'],
                'json_valid' => $responseSummary['json_valid'],
                'status_code' => $responseSummary['status_code'],
                'gateway_code' => $responseSummary['gateway_code'],
                'usable' => $usable,
                'unexpected_success' => $reportedSuccess,
            ]);

            if ($selectedByHealth === $name && $selectedResult === null) {
                $selectedResult = [
                    'host' => $host,
                    'transport' => $transportSummary,
                    'response' => $responseSummary,
                    'usable' => $usable,
                ];
            }
        }

        $debug['probe']['backends'][] = $backendResult;
    }

    $enabledBackendCount = count(array_filter(trueMoneyByteIndevDebugRoutingPolicy(), static function (array $row): bool {
        return !empty($row['enabled']);
    }));
    $degraded = $usableCount < $enabledBackendCount;
    $selectedUsable = is_array($selectedResult) && !empty($selectedResult['usable']);
    $success = $selectedByHealth !== null && $selectedUsable && !$unexpectedSuccess;

    if ($unexpectedSuccess) {
        $resultCode = 'probe_synthetic_voucher_unexpected_success';
        $summaryStatus = 'critical_unexpected_success';
    } elseif ($success && $degraded) {
        $resultCode = 'probe_byteindev_degraded';
        $summaryStatus = 'degraded_but_primary_path_usable';
    } elseif ($success) {
        $resultCode = 'probe_byteindev_ready';
        $summaryStatus = 'all_enabled_backends_usable';
    } else {
        $resultCode = 'probe_byteindev_unavailable';
        $summaryStatus = 'selected_production_path_unusable';
    }

    $debug['probe']['summary'] = [
        'status' => $summaryStatus,
        'selected_backend_by_health' => $selectedByHealth,
        'selected_backend_usable' => $selectedUsable,
        'enabled_backend_count' => $enabledBackendCount,
        'usable_backend_count' => $usableCount,
        'degraded' => $degraded,
        'unexpected_success' => $unexpectedSuccess,
        'automatic_failover_after_redeem' => false,
    ];

    $debug['provider']['selected_backend'] = $selectedByHealth;
    $debug['provider']['target'] = [
        'scheme' => 'https',
        'host' => is_array($selectedResult) ? (string) ($selectedResult['host'] ?? '') : '',
        'port' => 443,
        'path' => '/truemoney/{voucher}/{mobile}',
        'sensitive_path_redacted' => true,
    ];
    $debug['provider']['request'] = [
        'method' => 'GET',
        'synthetic_voucher' => true,
        'voucher_fingerprint' => substr(hash('sha256', $fakeToken), 0, 16),
        'receiver_phone_masked' => '******5678',
        'automatic_retry_after_redeem' => false,
    ];
    $debug['provider']['transport'] = is_array($selectedResult) ? (array) $selectedResult['transport'] : [];
    $debug['provider']['response'] = is_array($selectedResult) ? (array) $selectedResult['response'] : [];

    trueMoneyDebugEvent($debug, 'probe_assessment_completed', $debug['probe']['summary']);
    trueMoneyDebugSetResult($debug, $success, 'probe_completed', $resultCode);
    trueMoneyDebugPersist($debug);
    return $debug;
}
