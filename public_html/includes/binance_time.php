<?php
/**
 * Binance application-level clock synchronization for shared hosting.
 *
 * Signed Binance requests are validated against Binance server time. Shared
 * hosting may expose a system clock that the application cannot correct, so we
 * measure the offset against Binance's public /api/v3/time endpoint and apply
 * that offset when creating signed timestamps.
 */

/** @return array<string,mixed> */
function binanceClockSync(bool $force = false): array
{
    static $cached = null;
    static $cachedAt = 0.0;

    $now = microtime(true);
    if (!$force && is_array($cached) && ($now - $cachedAt) < 60.0) {
        return $cached;
    }

    if (!function_exists('curl_init') || !function_exists('configureBoundedCurlResponse')) {
        return $cached = [
            'success' => false,
            'offset_ms' => 0.0,
            'applied_offset_ms' => 0.0,
            'rtt_ms' => 0,
            'server_time_ms' => '',
            'local_midpoint_ms' => '',
            'message' => 'clock_transport_unavailable',
            'http_code' => 0,
            'curl_errno' => 0,
            'oversized' => false,
        ];
    }

    $base = defined('BINANCE_API_BASE') ? (string) BINANCE_API_BASE : 'https://api.binance.com';
    if (preg_match('#^https://api(?:[1-4])?\.binance\.com$#iD', $base) !== 1) {
        return $cached = [
            'success' => false,
            'offset_ms' => 0.0,
            'applied_offset_ms' => 0.0,
            'rtt_ms' => 0,
            'server_time_ms' => '',
            'local_midpoint_ms' => '',
            'message' => 'clock_endpoint_invalid',
            'http_code' => 0,
            'curl_errno' => 0,
            'oversized' => false,
        ];
    }

    $startedMs = microtime(true) * 1000.0;
    $ch = curl_init($base . '/api/v3/time');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_USERAGENT => 'SakazukiBinanceClock/1.0',
    ]);
    $response = '';
    $tooLarge = false;
    configureBoundedCurlResponse($ch, $response, $tooLarge, 16 * 1024);
    $executed = curl_exec($ch);
    $finishedMs = microtime(true) * 1000.0;
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErrorNo = (int) curl_errno($ch);
    curl_close($ch);

    $rttMs = max(0, (int) round($finishedMs - $startedMs));
    if ($executed === false || $curlErrorNo !== 0 || $tooLarge || $httpCode !== 200) {
        error_log(
            'Binance clock sync failed; http=' . $httpCode
            . '; curl=' . $curlErrorNo
            . '; oversized=' . ($tooLarge ? '1' : '0')
            . '; rtt_ms=' . $rttMs
        );
        $cachedAt = microtime(true);
        return $cached = [
            'success' => false,
            'offset_ms' => 0.0,
            'applied_offset_ms' => 0.0,
            'rtt_ms' => $rttMs,
            'server_time_ms' => '',
            'local_midpoint_ms' => '',
            'message' => 'clock_sync_failed',
            'http_code' => $httpCode,
            'curl_errno' => $curlErrorNo,
            'oversized' => $tooLarge,
        ];
    }

    $decoded = json_decode($response, true, 16);
    $serverTimeRaw = is_array($decoded) ? ($decoded['serverTime'] ?? null) : null;
    if (!is_scalar($serverTimeRaw) || !is_numeric((string) $serverTimeRaw)) {
        error_log('Binance clock sync returned invalid serverTime; http=' . $httpCode . '; rtt_ms=' . $rttMs);
        $cachedAt = microtime(true);
        return $cached = [
            'success' => false,
            'offset_ms' => 0.0,
            'applied_offset_ms' => 0.0,
            'rtt_ms' => $rttMs,
            'server_time_ms' => '',
            'local_midpoint_ms' => '',
            'message' => 'clock_response_invalid',
            'http_code' => $httpCode,
            'curl_errno' => $curlErrorNo,
            'oversized' => $tooLarge,
        ];
    }

    $serverTimeMs = (float) $serverTimeRaw;
    if (!is_finite($serverTimeMs) || $serverTimeMs < 1000000000000.0 || $serverTimeMs > 9999999999999.0) {
        error_log('Binance clock sync rejected implausible serverTime; rtt_ms=' . $rttMs);
        $cachedAt = microtime(true);
        return $cached = [
            'success' => false,
            'offset_ms' => 0.0,
            'applied_offset_ms' => 0.0,
            'rtt_ms' => $rttMs,
            'server_time_ms' => '',
            'local_midpoint_ms' => '',
            'message' => 'clock_response_invalid',
            'http_code' => $httpCode,
            'curl_errno' => $curlErrorNo,
            'oversized' => $tooLarge,
        ];
    }

    // The response was observed sometime between startedMs and finishedMs. The
    // midpoint removes roughly half of the network round-trip from the offset.
    $localMidpointMs = ($startedMs + $finishedMs) / 2.0;
    $offsetMs = $serverTimeMs - $localMidpointMs;
    // For signing, anchor to response-arrival time rather than the midpoint. This
    // intentionally leaves the request slightly behind Binance by the response
    // transit time, which is safer than drifting into Binance's strict +1000ms
    // future-time rejection boundary. recvWindow absorbs this small lag.
    $appliedOffsetMs = $serverTimeMs - $finishedMs;
    $cachedAt = microtime(true);
    $cached = [
        'success' => true,
        'offset_ms' => $offsetMs,
        'applied_offset_ms' => $appliedOffsetMs,
        'rtt_ms' => $rttMs,
        'server_time_ms' => sprintf('%.0f', $serverTimeMs),
        'local_midpoint_ms' => sprintf('%.0f', $localMidpointMs),
        'message' => 'ok',
        'http_code' => $httpCode,
        'curl_errno' => $curlErrorNo,
        'oversized' => $tooLarge,
    ];

    if (abs($offsetMs) >= 750.0 || $rttMs >= 2000) {
        error_log(
            'Binance clock diagnostic; offset_ms=' . (int) round($offsetMs)
            . '; applied_offset_ms=' . (int) round($appliedOffsetMs)
            . '; rtt_ms=' . $rttMs
            . '; php_int_bits=' . (PHP_INT_SIZE * 8)
        );
    }

    return $cached;
}

/** @return array{timestamp:string,synced:bool,offset_ms:int,applied_offset_ms:int,rtt_ms:int} */
function binanceClockTimestamp(bool $forceSync = false): array
{
    $sync = binanceClockSync($forceSync);
    $offsetMs = !empty($sync['success']) ? (float) ($sync['offset_ms'] ?? 0.0) : 0.0;
    $appliedOffsetMs = !empty($sync['success']) ? (float) ($sync['applied_offset_ms'] ?? $offsetMs) : 0.0;
    $timestampMs = (microtime(true) * 1000.0) + $appliedOffsetMs;

    return [
        // Keep timestamps as decimal strings so the integration does not depend
        // on the hosting provider using a 64-bit PHP integer build.
        'timestamp' => sprintf('%.0f', $timestampMs),
        'synced' => !empty($sync['success']),
        'offset_ms' => (int) round($offsetMs),
        'applied_offset_ms' => (int) round($appliedOffsetMs),
        'rtt_ms' => max(0, (int) ($sync['rtt_ms'] ?? 0)),
    ];
}

/** @return array<string,mixed> */
function binanceClockDiagnostic(bool $force = true): array
{
    $sync = binanceClockSync($force);
    return [
        'success' => !empty($sync['success']),
        'status' => !empty($sync['success']) ? 'ok' : 'failed',
        'offset_ms' => (int) round((float) ($sync['offset_ms'] ?? 0.0)),
        'applied_offset_ms' => (int) round((float) ($sync['applied_offset_ms'] ?? ($sync['offset_ms'] ?? 0.0))),
        'rtt_ms' => max(0, (int) ($sync['rtt_ms'] ?? 0)),
        'server_time_ms' => (string) ($sync['server_time_ms'] ?? ''),
        'local_midpoint_ms' => (string) ($sync['local_midpoint_ms'] ?? ''),
        'php_int_bits' => PHP_INT_SIZE * 8,
        'message' => (string) ($sync['message'] ?? 'clock_sync_failed'),
        'http_code' => max(0, (int) ($sync['http_code'] ?? 0)),
        'curl_errno' => max(0, (int) ($sync['curl_errno'] ?? 0)),
        'oversized' => !empty($sync['oversized']),
    ];
}
