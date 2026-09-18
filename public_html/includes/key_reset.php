<?php
/**
 * Provider-neutral key reset facade.
 *
 * The xChetos adapter is the first provider. New provider adapters can be added
 * here without putting provider-specific code back into reseller/admin pages.
 */

require_once __DIR__ . '/cheatgame.php';
require_once __DIR__ . '/xchetos.php';

if (!function_exists('keyResetProviders')) {
    function keyResetProviders(): array
    {
        return [
            'xchetos' => [
                'code' => 'xchetos',
                'label' => 'xChetos',
                'configured' => xchetosIsConfigured(),
                'enabled' => !empty(xchetosConfig()['enabled']),
            ],
        ];
    }
}

if (!function_exists('keyResetNormalizeProvider')) {
    function keyResetNormalizeProvider(string $provider): string
    {
        $provider = strtolower(trim($provider));
        return isset(keyResetProviders()[$provider]) ? $provider : '';
    }
}

if (!function_exists('keyResetMatchProvider')) {
    function keyResetMatchProvider(string $licenseKey): string
    {
        return xchetosKeyIsEligible($licenseKey) ? 'xchetos' : '';
    }
}

if (!function_exists('keyResetProviderLabel')) {
    function keyResetProviderLabel(string $provider): string
    {
        $providers = keyResetProviders();
        return (string) ($providers[$provider]['label'] ?? $provider ?: '-');
    }
}

if (!function_exists('keyResetGetPolicy')) {
    function keyResetGetPolicy(string $provider = 'xchetos'): array
    {
        if (keyResetNormalizeProvider($provider) !== 'xchetos') {
            return ['daily_limit' => 0, 'key_limit' => 0, 'window_seconds' => 86400];
        }
        $config = xchetosConfig();
        return [
            'daily_limit' => max(1, (int) ($config['reseller_daily_limit'] ?? 100)),
            'key_limit' => 2,
            'duration_limits' => [
                ['min_days' => 1, 'max_days' => 1, 'limit' => 2],
                ['min_days' => 2, 'max_days' => 3, 'limit' => 4],
                ['min_days' => 4, 'max_days' => null, 'limit' => 5],
            ],
            'window_seconds' => 86400,
            'provider_page_size' => (int) ($config['provider_page_size'] ?? 100),
            'provider_max_pages' => (int) ($config['provider_max_pages'] ?? 250),
            'provider_max_records' => (int) ($config['provider_max_records'] ?? 25000),
        ];
    }
}

if (!function_exists('keyResetGetResellerKeys')) {
    /** Return only keys supported by a configured reset provider. */
    function keyResetGetResellerKeys(int $userId): array
    {
        if ($userId < 1) return [];
        $rows = cgoGetUnifiedUserKeys($userId);
        $supported = [];
        $licenseKeys = [];
        foreach ($rows as $row) {
            $key = trim((string) ($row['key_code'] ?? ''));
            $provider = keyResetMatchProvider($key);
            if ($provider === '') continue;
            $row['provider_code'] = $provider;
            $row['provider_label'] = keyResetProviderLabel($provider);
            $supported[] = $row;
            $licenseKeys[] = $key;
        }

        $usageMap = xchetosGetKeyResetUsageMap($licenseKeys);
        foreach ($supported as &$row) {
            $key = trim((string) ($row['key_code'] ?? ''));
            $hash = hash('sha256', $key);
            $keyPolicy = xchetosKeyResetPolicy($key);
            $usage = $usageMap[$hash] ?? xchetosGetKeyResetUsage($hash, true, (int) ($keyPolicy['limit'] ?? 2));
            $row['reset_used'] = max(0, (int) ($usage['used'] ?? 0));
            $row['reset_limit'] = max(1, (int) ($usage['limit'] ?? $keyPolicy['limit'] ?? 2));
            $row['reset_duration_days'] = max(0, (int) ($usage['days'] ?? $keyPolicy['days'] ?? 0));
            $row['reset_remaining'] = max(0, (int) ($usage['remaining'] ?? 0));
            $row['reset_available'] = !empty($usage['available']) && $row['reset_remaining'] > 0;
        }
        unset($row);
        return $supported;
    }
}

if (!function_exists('keyResetResellerKey')) {
    function keyResetResellerKey(int $userId, string $provider, string $licenseKey, string $requestId = ''): array
    {
        $provider = keyResetNormalizeProvider($provider);
        if ($provider === 'xchetos') return xchetosResetResellerKey($userId, $licenseKey, $requestId);
        return [
            'success' => false,
            'status' => 'failed',
            'code' => 'provider_not_supported',
            'request_id' => xchetosRequestId(),
        ];
    }
}

if (!function_exists('keyResetOwnedKey')) {
    function keyResetOwnedKey(int $userId, string $provider, string $source, $recordId): array
    {
        $provider = keyResetNormalizeProvider($provider);
        if ($provider === 'xchetos') return xchetosResetOwnedKey($userId, $source, $recordId);
        return [
            'success' => false,
            'status' => 'failed',
            'code' => 'provider_not_supported',
            'request_id' => xchetosRequestId(),
        ];
    }
}

if (!function_exists('keyResetAdminKey')) {
    function keyResetAdminKey(int $adminId, string $provider, string $licenseKey): array
    {
        $provider = keyResetNormalizeProvider($provider);
        if ($provider === 'xchetos') return xchetosResetAdminKey($adminId, $licenseKey, 'Admin manual reset');
        return [
            'success' => false,
            'status' => 'failed',
            'code' => 'provider_not_supported',
            'request_id' => xchetosRequestId(),
        ];
    }
}

if (!function_exists('keyResetGetResellerQuota')) {
    function keyResetGetResellerQuota(int $userId, string $provider = 'xchetos'): array
    {
        if (keyResetNormalizeProvider($provider) === 'xchetos') return xchetosGetResellerDailyQuota($userId);
        return ['used' => 0, 'limit' => 0, 'remaining' => 0, 'window_seconds' => 86400, 'reset_at' => null, 'wait_seconds' => 0, 'available' => false];
    }
}

if (!function_exists('keyResetGetResellerRequestStatus')) {
    function keyResetGetResellerRequestStatus(int $userId, string $provider, string $requestId): array
    {
        if (keyResetNormalizeProvider($provider) === 'xchetos') {
            return xchetosGetResellerResetStatus($userId, $requestId);
        }
        return [
            'found' => false,
            'success' => false,
            'status' => 'failed',
            'code' => 'provider_not_supported',
            'request_id' => $requestId,
        ];
    }
}

if (!function_exists('keyResetGetHistory')) {
    function keyResetGetHistory(int $userId, int $limit = 30): array
    {
        return xchetosGetResetHistory($userId, $limit);
    }
}

if (!function_exists('keyResetGetAdminStats')) {
    function keyResetGetAdminStats(): array
    {
        return xchetosGetAdminLogStats();
    }
}

if (!function_exists('keyResetGetAdminLogs')) {
    function keyResetGetAdminLogs(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        return xchetosGetAdminLogs($filters, $page, $perPage);
    }
}

if (!function_exists('keyResetGetAdminQuotaRows')) {
    function keyResetGetAdminQuotaRows(int $limit = 30): array
    {
        return xchetosGetAdminResellerQuotaRows($limit);
    }
}


if (!function_exists('keyResetGetStorageDiagnostics')) {
    function keyResetGetStorageDiagnostics(bool $repair = false): array
    {
        return xchetosGetStorageDiagnostics($repair);
    }
}

if (!function_exists('keyResetGetSystemDebugLogs')) {
    function keyResetGetSystemDebugLogs(int $limit = 50): array
    {
        return xchetosGetSystemDebugLogs($limit);
    }
}

if (!function_exists('keyResetRepairStorage')) {
    function keyResetRepairStorage(int $adminId): array
    {
        $requestId = xchetosRequestId();
        if ($adminId < 1 || !function_exists('isAdmin') || !isAdmin()) {
            return ['success' => false, 'status' => 'failed', 'code' => 'forbidden', 'request_id' => $requestId];
        }
        $report = xchetosGetStorageDiagnostics(true);
        $success = !empty($report['ready']);
        xchetosWriteSystemLog('admin_storage_repair', [
            'ready' => $success,
            'audit_table' => $report['audit_table'] ?? [],
            'search_table' => $report['search_table'] ?? [],
            'debug_table' => $report['debug_table'] ?? [],
            'vault_ready' => !empty($report['vault_ready']),
            'last_error' => $report['last_error'] ?? [],
        ], $success ? 'info' : 'error', 'storage_repair', $requestId, $adminId, 'admin');
        return [
            'success' => $success,
            'status' => $success ? 'success' : 'failed',
            'code' => $success ? 'storage_repaired' : 'storage_repair_failed',
            'request_id' => $requestId,
            'diagnostics' => $report,
        ];
    }
}

if (!function_exists('keyResetRebuildSearchIndex')) {
    function keyResetRebuildSearchIndex(int $adminId, int $limit = 1000): array
    {
        $requestId = xchetosRequestId();
        if ($adminId < 1 || !function_exists('isAdmin') || !isAdmin()) {
            return ['success' => false, 'status' => 'failed', 'code' => 'forbidden', 'request_id' => $requestId];
        }
        $result = xchetosRebuildSearchIndex($limit);
        $result['status'] = !empty($result['success']) ? 'success' : 'failed';
        $result['request_id'] = $requestId;
        return $result;
    }
}

if (!function_exists('keyResetRunDiagnostic')) {
    function keyResetRunDiagnostic(int $adminId, string $provider): array
    {
        if (keyResetNormalizeProvider($provider) === 'xchetos') return xchetosRunAdminDiagnostic($adminId);
        return ['success' => false, 'status' => 'failed', 'code' => 'provider_not_supported', 'request_id' => xchetosRequestId()];
    }
}

if (!function_exists('keyResetClearProviderToken')) {
    function keyResetClearProviderToken(int $adminId, string $provider): array
    {
        if (keyResetNormalizeProvider($provider) === 'xchetos') return xchetosAdminClearToken($adminId);
        return ['success' => false, 'status' => 'failed', 'code' => 'provider_not_supported', 'request_id' => xchetosRequestId()];
    }
}

if (!function_exists('keyResetUpdatePolicy')) {
    function keyResetUpdatePolicy(int $adminId, string $provider, int $dailyLimit, int $keyLimit = 2): array
    {
        $requestId = xchetosRequestId();
        if ($adminId < 1 || !function_exists('isAdmin') || !isAdmin()) {
            return ['success' => false, 'status' => 'failed', 'code' => 'forbidden', 'request_id' => $requestId];
        }
        if (keyResetNormalizeProvider($provider) !== 'xchetos') {
            return ['success' => false, 'status' => 'failed', 'code' => 'provider_not_supported', 'request_id' => $requestId];
        }
        $dailyLimit = max(1, min(5000, $dailyLimit));
        $keyLimit = 2;
        if (!function_exists('updateSetting')) {
            return ['success' => false, 'status' => 'failed', 'code' => 'settings_unavailable', 'request_id' => $requestId];
        }

        global $conn;
        $transactionStarted = isset($conn) && $conn instanceof mysqli && $conn->begin_transaction();
        $dailySaved = updateSetting('key_reset_xchetos_reseller_daily_limit', (string) $dailyLimit);
        $keySaved = $dailySaved && updateSetting('key_reset_xchetos_reseller_key_limit', (string) $keyLimit);
        if (!$dailySaved || !$keySaved) {
            if ($transactionStarted) $conn->rollback();
            return ['success' => false, 'status' => 'failed', 'code' => 'settings_save_failed', 'request_id' => $requestId];
        }
        if ($transactionStarted && !$conn->commit()) {
            $conn->rollback();
            return ['success' => false, 'status' => 'failed', 'code' => 'settings_save_failed', 'request_id' => $requestId];
        }

        $context = [
            'request_id' => $requestId,
            'actor_user_id' => $adminId,
            'actor_role' => 'admin',
            'operation_type' => 'settings_update',
            'provider_code' => 'xchetos',
            'key_source' => 'system',
            'key_record_id' => 'policy',
            'product_name' => 'xChetos reset policy',
        ];
        $result = xchetosImmediateResult($context, 'limits_updated', 'success', [
            'request_stage' => 'policy_update',
            'debug' => [
                'reseller_daily_limit' => $dailyLimit,
                'reseller_key_limit' => $keyLimit,
                'duration_limits' => ['1D' => 2, '2-3D' => 4, '4D+' => 5],
                'window_seconds' => 86400,
            ],
        ]);
        $result['daily_limit'] = $dailyLimit;
        $result['key_limit'] = $keyLimit;
        $result['duration_limits'] = ['1D' => 2, '2-3D' => 4, '4D+' => 5];
        if (function_exists('logHistory')) {
            logHistory($adminId, 'key_reset_policy_update', "xChetos daily_limit={$dailyLimit} duration_limits=1D:2,2-3D:4,4D+:5 ref={$requestId}");
        }
        return $result;
    }
}
