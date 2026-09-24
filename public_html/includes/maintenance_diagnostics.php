<?php
/**
 * Maintenance-only observability helpers.
 *
 * These probes are deliberately read-only. They summarize schema/state that is
 * useful to an operator without exposing credentials, provider payloads, gift
 * codes, customer names/emails, or raw financial identifiers.
 */

if (!function_exists('maintenanceDiagnosticSafeText')) {
    function maintenanceDiagnosticSafeText($value, int $max = 500): string
    {
        if (!is_scalar($value)) return '';
        $text = trim((string) $value);
        if ($text === '') return '';
        $text = str_replace(["\r", "\n", "\t"], ' ', $text);
        $text = preg_replace('/\s{2,}/', ' ', $text) ?? $text;
        // Never echo bearer/query credentials or long opaque secrets back into
        // the maintenance JSON surface.
        $text = preg_replace('/(Bearer\s+)[A-Za-z0-9._~+\/=\-]{12,}/i', '$1[redacted]', $text) ?? $text;
        $text = preg_replace('/([?&](?:token|api[_-]?key|secret|signature|authorization)=)[^&\s]+/i', '$1[redacted]', $text) ?? $text;
        $text = preg_replace('/\bGC-[A-Z0-9]{8,}\b/i', 'GC-[redacted]', $text) ?? $text;
        $text = preg_replace('/\b[A-Fa-f0-9]{40,}\b/', '[hex-redacted]', $text) ?? $text;
        $text = preg_replace('/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/i', '[email-redacted]', $text) ?? $text;
        return substr($text, 0, max(1, min(1200, $max)));
    }
}

if (!function_exists('maintenanceDiagnosticRecordProbeFailure')) {
    function maintenanceDiagnosticRecordProbeFailure(string $probe, $errno = 0, $error = ''): void
    {
        $probe = preg_replace('/[^a-z0-9_.:-]/i', '', strtolower(trim($probe))) ?? '';
        if ($probe === '') $probe = 'diagnostic_query';
        if (!isset($GLOBALS['sakazuki_maintenance_probe_failures']) || !is_array($GLOBALS['sakazuki_maintenance_probe_failures'])) {
            $GLOBALS['sakazuki_maintenance_probe_failures'] = [];
        }
        $entry = [
            'probe' => substr($probe, 0, 80),
            'errno' => max(0, (int) $errno),
            'error' => maintenanceDiagnosticSafeText($error, 500),
        ];
        // Keep the response bounded even if several optional probes fail at once.
        if (count($GLOBALS['sakazuki_maintenance_probe_failures']) < 20) {
            $GLOBALS['sakazuki_maintenance_probe_failures'][] = $entry;
        }
    }
}

if (!function_exists('maintenanceDiagnosticFetchRow')) {
    /** @return array<string,mixed>|null */
    function maintenanceDiagnosticFetchRow(string $sql, string $probe = 'diagnostic_query'): ?array
    {
        global $conn;
        if (!isset($conn) || !($conn instanceof mysqli)) {
            maintenanceDiagnosticRecordProbeFailure($probe, 0, 'Database connection unavailable');
            return null;
        }
        try {
            $result = $conn->query($sql);
            if (!($result instanceof mysqli_result)) {
                maintenanceDiagnosticRecordProbeFailure($probe, $conn->errno, $conn->error);
                return null;
            }
            $row = $result->fetch_assoc();
            $result->free();
            return is_array($row) ? $row : null;
        } catch (Throwable $e) {
            maintenanceDiagnosticRecordProbeFailure($probe, (int) $e->getCode(), $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('maintenanceDiagnosticFetchRows')) {
    /** @return array<int,array<string,mixed>> */
    function maintenanceDiagnosticFetchRows(string $sql, int $maxRows = 20, string $probe = 'diagnostic_query'): array
    {
        global $conn;
        if (!isset($conn) || !($conn instanceof mysqli)) {
            maintenanceDiagnosticRecordProbeFailure($probe, 0, 'Database connection unavailable');
            return [];
        }
        $rows = [];
        try {
            $result = $conn->query($sql);
            if (!($result instanceof mysqli_result)) {
                maintenanceDiagnosticRecordProbeFailure($probe, $conn->errno, $conn->error);
                return [];
            }
            while (($row = $result->fetch_assoc()) !== null && count($rows) < max(1, min(50, $maxRows))) {
                $rows[] = $row;
            }
            $result->free();
        } catch (Throwable $e) {
            maintenanceDiagnosticRecordProbeFailure($probe, (int) $e->getCode(), $e->getMessage());
            return [];
        }
        return $rows;
    }
}

if (!function_exists('maintenanceDiagnosticTable')) {
    /** @return array<string,mixed> */
    function maintenanceDiagnosticTable(string $table, array $requiredColumns): array
    {
        $exists = function_exists('sakazukiTableReady') ? sakazukiTableReady($table, true) : false;
        $columnsReady = $exists && function_exists('sakazukiTableColumnsReady')
            ? sakazukiTableColumnsReady($table, $requiredColumns, true)
            : false;
        return [
            'table_exists' => $exists,
            'columns_ready' => $columnsReady,
        ];
    }
}

if (!function_exists('maintenancePublicJobDiagnostics')) {
    /**
     * Return a bounded, explicitly allow-listed diagnostic view of a job result.
     * Critical-mode responses never call this helper.
     *
     * @return array<string,mixed>
     */
    function maintenancePublicJobDiagnostics(string $jobName, array $result): array
    {
        $jobName = strtolower(trim($jobName));
        $details = [];
        $copyScalars = static function (array $source, array $keys) use (&$details): void {
            foreach ($keys as $key) {
                if (!array_key_exists($key, $source) || (!is_scalar($source[$key]) && $source[$key] !== null)) continue;
                $details[$key] = is_string($source[$key])
                    ? maintenanceDiagnosticSafeText($source[$key], 500)
                    : $source[$key];
            }
        };

        if ($jobName === 'cgo_catalog') {
            $copyScalars($result, [
                'synced','invalid','missing','removed','linked_synced','preserved_prices',
                'price_correction_count','removal_confirmation_attempted',
                'removal_confirmation_succeeded','removal_skipped','http_code','error_code','error_stage','error_detail'
            ]);
        } elseif ($jobName === 'shared_history') {
            $copyScalars($result, ['processed','completed_streams','slip_history_ready','next_interval_seconds']);
            $streams = [];
            foreach ((array) ($result['streams'] ?? []) as $name => $stream) {
                if (!is_array($stream) || !preg_match('/^[a-z0-9_\-]{1,40}$/iD', (string) $name)) continue;
                $safe = [];
                foreach (['completed','cursor','remaining','batch_size','blocked_row_id','blocked_status','last_error'] as $key) {
                    if (!array_key_exists($key, $stream) || (!is_scalar($stream[$key]) && $stream[$key] !== null)) continue;
                    $safe[$key] = is_string($stream[$key]) ? maintenanceDiagnosticSafeText($stream[$key], 400) : $stream[$key];
                }
                $streams[(string) $name] = $safe;
            }
            if ($streams !== []) $details['streams'] = $streams;
        } elseif ($jobName === 'shared_binance_history') {
            $copyScalars($result, ['processed','completed','cursor','remaining','next_interval_seconds','blocked_row_id','blocked_status']);
        } elseif ($jobName === 'commerce_center') {
            foreach (['source_attempted','source_succeeded','source_failed','source_query_failures'] as $key) {
                if (array_key_exists($key, $result) && is_scalar($result[$key])) {
                    $details[$key] = max(0, (int) $result[$key]);
                }
            }
            $sources = [];
            foreach ((array) ($result['sources'] ?? []) as $sourceName => $source) {
                if (!is_array($source) || !preg_match('/^[a-z0-9_\-]{1,40}$/iD', (string) $sourceName)) continue;
                $sources[(string) $sourceName] = [
                    'attempted' => max(0, (int) ($source['attempted'] ?? 0)),
                    'succeeded' => max(0, (int) ($source['succeeded'] ?? 0)),
                    'failed' => max(0, (int) ($source['failed'] ?? 0)),
                ];
                if (isset($source['error_code']) && is_scalar($source['error_code'])) {
                    $sources[(string) $sourceName]['error_code'] = maintenanceDiagnosticSafeText($source['error_code'], 80);
                }
                if (isset($source['error_detail']) && is_scalar($source['error_detail'])) {
                    $sources[(string) $sourceName]['error_detail'] = maintenanceDiagnosticSafeText($source['error_detail'], 500);
                }
            }
            if ($sources !== []) $details['sources'] = $sources;
            $ledger = (array) ($result['ledger'] ?? []);
            if ($ledger !== []) {
                $details['ledger'] = [
                    'success' => ($ledger['success'] ?? true) !== false,
                    'attempted' => max(0, (int) ($ledger['attempted'] ?? 0)),
                    'succeeded' => max(0, (int) ($ledger['succeeded'] ?? 0)),
                    'failed' => max(0, (int) ($ledger['failed'] ?? 0)),
                    'financials' => max(0, (int) ($ledger['financials'] ?? 0)),
                    'transactions' => max(0, (int) ($ledger['transactions'] ?? 0)),
                    'api_ledger' => max(0, (int) ($ledger['api_ledger'] ?? 0)),
                    'message' => maintenanceDiagnosticSafeText($ledger['message'] ?? '', 500),
                ];
                $ledgerErrors = [];
                foreach (array_slice((array) ($ledger['errors'] ?? []), 0, 8) as $error) {
                    if (!is_array($error)) continue;
                    $ledgerErrors[] = [
                        'stage' => maintenanceDiagnosticSafeText($error['stage'] ?? '', 80),
                        'code' => maintenanceDiagnosticSafeText($error['code'] ?? '', 100),
                        'detail' => maintenanceDiagnosticSafeText($error['detail'] ?? '', 500),
                    ];
                }
                if ($ledgerErrors !== []) $details['ledger']['errors'] = $ledgerErrors;
            }
        } elseif ($jobName === 'history_cleanup') {
            $copyScalars($result, ['deleted','archived_transactions','more','error_code','error_detail']);
        }

        return array_filter($details, static fn($value): bool => $value !== '' && $value !== [] && $value !== null);
    }
}

if (!function_exists('maintenanceAutomationStateDiagnostics')) {
    /** @return array<string,mixed> */
    function maintenanceAutomationStateDiagnostics(array $jobNames): array
    {
        $jobs = [];
        $issues = [];
        foreach (array_values(array_unique($jobNames)) as $jobName) {
            if (!is_string($jobName) || preg_match('/^[a-z0-9_]{2,64}$/D', $jobName) !== 1) continue;
            $state = function_exists('automationReadJobState') ? automationReadJobState($jobName) : null;
            if (!is_array($state)) {
                $jobs[$jobName] = ['state' => 'missing'];
                continue;
            }
            $failures = max(0, (int) ($state['consecutive_failures'] ?? 0));
            $jobs[$jobName] = [
                'state' => 'present',
                'last_started_at' => (string) ($state['last_started_at'] ?? ''),
                'last_finished_at' => (string) ($state['last_finished_at'] ?? ''),
                'last_success_at' => (string) ($state['last_success_at'] ?? ''),
                'next_run_at' => (string) ($state['next_run_at'] ?? ''),
                'consecutive_failures' => $failures,
                'last_duration_ms' => max(0, (int) ($state['last_duration_ms'] ?? 0)),
                'last_runner' => maintenanceDiagnosticSafeText($state['last_runner'] ?? '', 40),
                'last_message' => maintenanceDiagnosticSafeText($state['last_message'] ?? '', 500),
            ];
            // One transient failure is visible but does not degrade maintenance.
            // Two consecutive failures are a useful signal that the background
            // loop needs attention even if this particular job is not due now.
            if ($failures >= 2) $issues[] = 'job_consecutive_failures:' . $jobName;
        }
        // The existing heartbeat is shared by critical and maintenance
        // invocations, so it must not be presented as proof that the one-minute
        // critical schedule specifically is healthy. Per-job consecutive
        // failures/last-success timestamps above are the reliable evidence.
        $runnerHeartbeatRecent = function_exists('automationCronIsHealthy') ? automationCronIsHealthy(180) : null;
        return [
            'runner_heartbeat_recent' => $runnerHeartbeatRecent,
            'jobs' => $jobs,
            'issues' => array_values(array_unique($issues)),
        ];
    }
}

if (!function_exists('maintenanceCommerceDiagnostics')) {
    /** @return array<string,mixed> */
    function maintenanceCommerceDiagnostics(): array
    {
        if (!function_exists('sakazukiTableReady') || !sakazukiTableReady('commerce_sync_failures', true)) {
            return ['table_exists' => false, 'unresolved' => 0, 'recent_unresolved' => []];
        }
        $countRow = maintenanceDiagnosticFetchRow(
            "SELECT COUNT(*) AS unresolved FROM commerce_sync_failures WHERE resolved_at IS NULL",
            'commerce_center.unresolved_count'
        );
        $recent = maintenanceDiagnosticFetchRows(
            "SELECT source_type,error_code,error_message,attempts,last_failed_at "
            . "FROM commerce_sync_failures WHERE resolved_at IS NULL "
            . "ORDER BY last_failed_at DESC LIMIT 8",
            8,
            'commerce_center.recent_unresolved'
        );
        $safeRecent = [];
        foreach ($recent as $row) {
            $safeRecent[] = [
                'source_type' => maintenanceDiagnosticSafeText($row['source_type'] ?? '', 40),
                'error_code' => maintenanceDiagnosticSafeText($row['error_code'] ?? '', 80),
                'message' => maintenanceDiagnosticSafeText($row['error_message'] ?? '', 500),
                'attempts' => max(0, (int) ($row['attempts'] ?? 0)),
                'last_failed_at' => (string) ($row['last_failed_at'] ?? ''),
            ];
        }
        return [
            'table_exists' => true,
            'unresolved' => max(0, (int) ($countRow['unresolved'] ?? 0)),
            'recent_unresolved' => $safeRecent,
        ];
    }
}

if (!function_exists('maintenancePaymentRailDiagnostics')) {
    /** @return array<string,mixed> */
    function maintenancePaymentRailDiagnostics(?array $binanceClockHealth = null): array
    {
        $issues = [];
        $rails = [];

        $walletReady = function_exists('walletLedgerRuntimeSchemaReady')
            ? walletLedgerRuntimeSchemaReady(true)
            : false;
        $rails['wallet_ledger'] = [
            'status' => $walletReady ? 'ok' : 'failed',
            'schema_ready' => $walletReady,
        ];
        if (!$walletReady) $issues[] = 'wallet_ledger_schema_unhealthy';

        // EasySlip / bank transfer. Missing tables on a never-used disabled site
        // are not failures. If enabled, a missing table is reported as
        // not_initialized so the operator can distinguish it from corrupt schema.
        $slipEnabled = getSetting('easyslip_enabled', '0') === '1';
        $slipTable = maintenanceDiagnosticTable('slip_deposits', [
            'id','user_id','transaction_ref','amount','slip_hash','verified_at'
        ]);
        $slipJobs = maintenanceDiagnosticTable('slip_verification_jobs', [
            'attempt_uuid','slip_hash','user_id','status','last_error_code','provider_next_retry_at','updated_at'
        ]);
        $slip = [
            'enabled' => $slipEnabled,
            'status' => !$slipEnabled ? 'disabled' : (!$slipTable['table_exists'] || !$slipJobs['table_exists'] ? 'not_initialized' : (($slipTable['columns_ready'] && $slipJobs['columns_ready']) ? 'ok' : 'failed')),
            'deposit_schema_ready' => (bool) $slipTable['columns_ready'],
            'job_schema_ready' => (bool) $slipJobs['columns_ready'],
        ];
        if ($slipJobs['columns_ready']) {
            $row = maintenanceDiagnosticFetchRow(
                "SELECT "
                . "SUM(status IN ('verifying','crediting') AND updated_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)) AS stale_active, "
                . "SUM(status='provider_pending') AS provider_pending, "
                . "SUM(status='provider_pending' AND provider_next_retry_at IS NOT NULL AND provider_next_retry_at<=NOW() AND updated_at<DATE_SUB(NOW(), INTERVAL 10 MINUTE)) AS overdue_provider_pending, "
                . "SUM(status='review') AS review_count, "
                . "SUM(status='failed' AND updated_at>=DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS failed_24h "
                . "FROM slip_verification_jobs",
                'payment.bank_slip.state'
            ) ?? [];
            $slip['stale_active'] = max(0, (int) ($row['stale_active'] ?? 0));
            $slip['provider_pending'] = max(0, (int) ($row['provider_pending'] ?? 0));
            $slip['overdue_provider_pending'] = max(0, (int) ($row['overdue_provider_pending'] ?? 0));
            $slip['review_count'] = max(0, (int) ($row['review_count'] ?? 0));
            $slip['failed_24h'] = max(0, (int) ($row['failed_24h'] ?? 0));
            if ($slipEnabled && ($slip['stale_active'] > 0 || $slip['overdue_provider_pending'] > 0)) {
                $slip['status'] = 'degraded';
                $issues[] = 'easyslip_stale_jobs';
            }
        }
        if ($slipEnabled && $slipTable['table_exists'] && !$slipTable['columns_ready']) $issues[] = 'easyslip_deposit_schema_unhealthy';
        if ($slipEnabled && $slipJobs['table_exists'] && !$slipJobs['columns_ready']) $issues[] = 'easyslip_job_schema_unhealthy';
        $rails['bank_slip'] = $slip;

        $tmEnabled = getSetting('truemoney_enabled', '0') === '1';
        $tmTable = maintenanceDiagnosticTable('truemoney_redemptions', [
            'id','voucher_hash','user_id','status','amount_thb','credited_amount','attempt_count','updated_at','completed_at'
        ]);
        $tm = [
            'enabled' => $tmEnabled,
            'status' => !$tmEnabled ? 'disabled' : (!$tmTable['table_exists'] ? 'not_initialized' : ($tmTable['columns_ready'] ? 'ok' : 'failed')),
            'schema_ready' => (bool) $tmTable['columns_ready'],
        ];
        if ($tmTable['columns_ready']) {
            $row = maintenanceDiagnosticFetchRow(
                "SELECT "
                . "SUM(status='provider_confirmed') AS provider_confirmed_pending_credit, "
                . "SUM(status='processing' AND updated_at<DATE_SUB(NOW(), INTERVAL 5 MINUTE)) AS stale_processing, "
                . "SUM(status='failed' AND updated_at>=DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS failed_24h "
                . "FROM truemoney_redemptions",
                'payment.truemoney.state'
            ) ?? [];
            $tm['provider_confirmed_pending_credit'] = max(0, (int) ($row['provider_confirmed_pending_credit'] ?? 0));
            $tm['stale_processing'] = max(0, (int) ($row['stale_processing'] ?? 0));
            $tm['failed_24h'] = max(0, (int) ($row['failed_24h'] ?? 0));
            if ($tmEnabled && ($tm['provider_confirmed_pending_credit'] > 0 || $tm['stale_processing'] > 0)) {
                $tm['status'] = 'degraded';
                if ($tm['provider_confirmed_pending_credit'] > 0) $issues[] = 'truemoney_pending_local_credit';
                if ($tm['stale_processing'] > 0) $issues[] = 'truemoney_stale_processing';
            }
        }
        if ($tmEnabled && $tmTable['table_exists'] && !$tmTable['columns_ready']) $issues[] = 'truemoney_schema_unhealthy';
        $rails['truemoney'] = $tm;

        $binanceEnabled = getSetting('binance_enabled', '0') === '1';
        $binanceTable = maintenanceDiagnosticTable('binance_deposits', [
            'id','user_id','tx_id','coin','network','amount_usdt','amount_thb','binance_status','processing','created_at'
        ]);
        $bn = [
            'enabled' => $binanceEnabled,
            'status' => !$binanceEnabled ? 'disabled' : (!$binanceTable['table_exists'] ? 'not_initialized' : ($binanceTable['columns_ready'] ? 'ok' : 'failed')),
            'schema_ready' => (bool) $binanceTable['columns_ready'],
            'clock_status' => is_array($binanceClockHealth) ? (string) ($binanceClockHealth['status'] ?? 'unknown') : 'unknown',
        ];
        if ($binanceTable['columns_ready']) {
            $row = maintenanceDiagnosticFetchRow(
                "SELECT SUM(processing=1 AND user_id=0 AND created_at<DATE_SUB(NOW(), INTERVAL 15 MINUTE)) AS stale_locks "
                . "FROM binance_deposits",
                'payment.binance_trc20.stale_locks'
            ) ?? [];
            $bn['stale_processing_locks'] = max(0, (int) ($row['stale_locks'] ?? 0));
            if ($binanceEnabled && $bn['stale_processing_locks'] > 0) {
                $bn['status'] = 'degraded';
                $issues[] = 'binance_trc20_stale_processing_locks';
            }
        }
        if ($binanceEnabled && $binanceTable['table_exists'] && !$binanceTable['columns_ready']) $issues[] = 'binance_trc20_schema_unhealthy';
        if ($binanceEnabled && is_array($binanceClockHealth) && ($binanceClockHealth['status'] ?? '') !== 'ok') {
            $bn['status'] = $bn['status'] === 'failed' ? 'failed' : 'degraded';
            $issues[] = 'binance_clock_unhealthy';
        }
        $rails['binance_trc20'] = $bn;

        $giftEnabled = getSetting('binance_giftcard_enabled', '0') === '1';
        $giftTable = maintenanceDiagnosticTable('binance_giftcard_redemptions', [
            'id','user_id','status','request_stage','token_amount','credit_amount','transaction_id','requested_at','credited_at'
        ]);
        $gift = [
            'enabled' => $giftEnabled,
            'status' => !$giftEnabled ? 'disabled' : (!$giftTable['table_exists'] ? 'not_initialized' : ($giftTable['columns_ready'] ? 'ok' : 'failed')),
            'schema_ready' => (bool) $giftTable['columns_ready'],
        ];
        if ($giftTable['columns_ready']) {
            $row = maintenanceDiagnosticFetchRow(
                "SELECT "
                . "SUM(status='redeemed_pending_credit') AS pending_credit, "
                . "SUM(request_stage='provider_accepted' AND credited_at IS NULL) AS provider_accepted_uncredited, "
                . "MIN(CASE WHEN status='redeemed_pending_credit' THEN requested_at END) AS oldest_pending_at "
                . "FROM binance_giftcard_redemptions",
                'payment.binance_giftcard.pending_credit'
            ) ?? [];
            $gift['pending_credit'] = max(0, (int) ($row['pending_credit'] ?? 0));
            $gift['provider_accepted_uncredited'] = max(0, (int) ($row['provider_accepted_uncredited'] ?? 0));
            $gift['oldest_pending_at'] = (string) ($row['oldest_pending_at'] ?? '');
            if ($giftEnabled && ($gift['pending_credit'] > 0 || $gift['provider_accepted_uncredited'] > 0)) {
                $gift['status'] = 'degraded';
                $issues[] = 'binance_giftcard_pending_local_credit';
            }
        }
        if ($giftEnabled && $giftTable['table_exists'] && !$giftTable['columns_ready']) $issues[] = 'binance_giftcard_schema_unhealthy';
        $rails['binance_giftcard'] = $gift;

        return [
            'status' => $issues === [] ? 'ok' : 'degraded',
            'issues' => array_values(array_unique($issues)),
            'rails' => $rails,
        ];
    }
}

if (!function_exists('maintenanceCollectDiagnostics')) {
    /** @return array<string,mixed> */
    function maintenanceCollectDiagnostics(array $jobNames, ?array $binanceClockHealth = null): array
    {
        $automation = maintenanceAutomationStateDiagnostics(array_merge(['cron_heartbeat'], $jobNames));
        $commerce = maintenanceCommerceDiagnostics();
        $payment = maintenancePaymentRailDiagnostics($binanceClockHealth);
        $issues = array_merge(
            (array) ($automation['issues'] ?? []),
            (array) ($payment['issues'] ?? [])
        );
        if ((int) ($commerce['unresolved'] ?? 0) > 0) $issues[] = 'commerce_center_unresolved_failures';
        $probeFailures = [];
        foreach (array_slice((array) ($GLOBALS['sakazuki_maintenance_probe_failures'] ?? []), 0, 20) as $failure) {
            if (!is_array($failure)) continue;
            $probeFailures[] = [
                'probe' => maintenanceDiagnosticSafeText($failure['probe'] ?? '', 80),
                'errno' => max(0, (int) ($failure['errno'] ?? 0)),
                'error' => maintenanceDiagnosticSafeText($failure['error'] ?? '', 500),
            ];
        }
        if ($probeFailures !== []) $issues[] = 'diagnostic_probe_failures';
        return [
            'status' => $issues === [] ? 'ok' : 'degraded',
            'issues' => array_values(array_unique($issues)),
            'probe_failures' => $probeFailures,
            'payment_rails' => $payment,
            'automation_state' => $automation,
            'commerce_center' => $commerce,
        ];
    }
}
