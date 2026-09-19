<?php
/**
 * TrueMoney Angpao provider adapter for the Zelthr gateway.
 *
 * SAKAZUKI sends voucher links and the configured receiver phone in a JSON body
 * to https://api.zelthr.rest/. The gateway handles the current TrueMoney edge
 * protections and returns the upstream redemption result.
 *
 * Financial safety rule: a redeem POST is never retried automatically after a
 * timeout/network/5xx/unknown-success result. Those cases are persisted as
 * indeterminate so the same voucher cannot be fired again blindly.
 */

if (!defined('TM_ZELTHR_API_URL')) {
    define('TM_ZELTHR_API_URL', 'https://api.zelthr.rest/');
}
if (!defined('TM_ZELTHR_CONNECT_TIMEOUT')) {
    define('TM_ZELTHR_CONNECT_TIMEOUT', 10);
}
if (!defined('TM_ZELTHR_TOTAL_TIMEOUT')) {
    define('TM_ZELTHR_TOTAL_TIMEOUT', 30);
}
if (!defined('TM_ZELTHR_MAX_RESPONSE_BYTES')) {
    define('TM_ZELTHR_MAX_RESPONSE_BYTES', 1048576);
}

function trueMoneyZelthrProviderName(): string
{
    return 'zelthr';
}

function trueMoneyZelthrProviderDisplayName(): string
{
    return 'Zelthr / api.zelthr.rest';
}

function trueMoneyZelthrSafeText($value, int $maxLength = 500): string
{
    $text = is_scalar($value) ? trim((string) $value) : '';
    if (function_exists('trueMoneyDebugSafeText')) {
        return trueMoneyDebugSafeText($text, $maxLength);
    }
    $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? '';
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $maxLength, 'UTF-8');
    }
    return substr($text, 0, $maxLength);
}

function trueMoneyZelthrNormalizeVoucher($voucherUrl): array
{
    if (function_exists('normalizeTrueMoneyVoucherUrl')) {
        return normalizeTrueMoneyVoucherUrl($voucherUrl);
    }
    if (!is_string($voucherUrl) && !is_scalar($voucherUrl)) {
        return ['success' => false, 'message' => 'ลิงก์ซองของขวัญไม่ถูกต้อง'];
    }
    $voucherUrl = trim((string) $voucherUrl);
    if ($voucherUrl === '' || strlen($voucherUrl) > 500 || !filter_var($voucherUrl, FILTER_VALIDATE_URL)) {
        return ['success' => false, 'message' => 'ลิงก์ซองของขวัญไม่ถูกต้อง'];
    }
    $parts = parse_url($voucherUrl);
    if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
        || strtolower((string) ($parts['host'] ?? '')) !== 'gift.truemoney.com'
        || preg_match('#^/campaign/?$#', (string) ($parts['path'] ?? '')) !== 1) {
        return ['success' => false, 'message' => 'รองรับเฉพาะลิงก์ https://gift.truemoney.com/campaign เท่านั้น'];
    }
    parse_str((string) ($parts['query'] ?? ''), $query);
    $token = isset($query['v']) && is_string($query['v']) ? trim($query['v']) : '';
    if ($token === '' || preg_match('/^[A-Za-z0-9_-]{8,200}$/D', $token) !== 1) {
        return ['success' => false, 'message' => 'รหัสซองของขวัญไม่ถูกต้อง'];
    }
    return [
        'success' => true,
        'url' => 'https://gift.truemoney.com/campaign/?v=' . rawurlencode($token),
        'token' => $token,
    ];
}

function trueMoneyZelthrInitializeDebug(array &$debug): void
{
    $debug['provider'] = [
        'name' => trueMoneyZelthrProviderName(),
        'target' => [
            'scheme' => 'https',
            'host' => 'api.zelthr.rest',
            'port' => 443,
            'path' => '/',
            'query_present' => false,
            'path_segment_count' => 0,
            'sensitive_path_redacted' => false,
        ],
        'request' => [],
        'transport' => [],
        'response' => [],
    ];
    if (isset($debug['privacy']) && is_array($debug['privacy'])) {
        $debug['privacy']['voucher_token_stored'] = false;
        $debug['privacy']['raw_provider_body_stored'] = false;
        $debug['privacy']['raw_provider_effective_url_stored'] = false;
        $debug['privacy']['xpluem_path_secrets_stored'] = false;
        $debug['privacy']['credentials_redacted'] = true;
    }
}

function trueMoneyZelthrCurlRequest(string $url, array $jsonBody): array
{
    $responseHeaders = [];
    $body = json_encode($jsonBody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($body)) {
        return [
            'executed' => false,
            'http_code' => 0,
            'curl_errno' => -2,
            'curl_error' => 'json_encode failed',
            'body' => '',
            'headers' => [],
            'response_too_large' => false,
            'timing' => [],
        ];
    }

    $ch = curl_init();
    if ($ch === false) {
        return [
            'executed' => false,
            'http_code' => 0,
            'curl_errno' => -1,
            'curl_error' => 'curl_init failed',
            'body' => '',
            'headers' => [],
            'response_too_large' => false,
            'timing' => [],
        ];
    }

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => (int) TM_ZELTHR_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT => (int) TM_ZELTHR_TOTAL_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'Cache-Control: no-cache, no-store, max-age=0',
            'Pragma: no-cache',
        ],
        CURLOPT_USERAGENT => 'SakazukiTrueMoney/3.0',
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
            $length = strlen($line);
            $trimmed = trim($line);
            if ($trimmed === '') return $length;
            if (stripos($trimmed, 'HTTP/') === 0) {
                $responseHeaders['status_line'] = $trimmed;
                return $length;
            }
            if (strpos($trimmed, ':') === false) return $length;
            [$name, $value] = explode(':', $trimmed, 2);
            $key = strtolower(trim($name));
            $value = trim($value);
            if ($key === '') return $length;
            if (isset($responseHeaders[$key])) {
                $responseHeaders[$key] = is_array($responseHeaders[$key])
                    ? array_merge($responseHeaders[$key], [$value])
                    : [$responseHeaders[$key], $value];
            } else {
                $responseHeaders[$key] = $value;
            }
            return $length;
        },
    ]);

    $started = microtime(true);
    $responseBody = curl_exec($ch);
    $wallMs = (int) round((microtime(true) - $started) * 1000);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);

    $responseString = is_string($responseBody) ? $responseBody : '';
    $tooLarge = strlen($responseString) > (int) TM_ZELTHR_MAX_RESPONSE_BYTES;
    if ($tooLarge) $responseString = '';

    return [
        'executed' => $responseBody !== false,
        'http_code' => (int) ($info['http_code'] ?? 0),
        'curl_errno' => (int) $errno,
        'curl_error' => trueMoneyZelthrSafeText($error, 300),
        'body' => $responseString,
        'headers' => $responseHeaders,
        'response_too_large' => $tooLarge,
        'primary_ip' => trueMoneyZelthrSafeText((string) ($info['primary_ip'] ?? ''), 80),
        'ssl_verify_result' => isset($info['ssl_verifyresult']) ? (int) $info['ssl_verifyresult'] : null,
        'timing' => [
            'dns_complete_ms' => isset($info['namelookup_time']) ? (int) round(((float) $info['namelookup_time']) * 1000) : null,
            'tcp_connect_complete_ms' => isset($info['connect_time']) ? (int) round(((float) $info['connect_time']) * 1000) : null,
            'tls_complete_ms' => isset($info['appconnect_time']) ? (int) round(((float) $info['appconnect_time']) * 1000) : null,
            'ttfb_complete_ms' => isset($info['starttransfer_time']) ? (int) round(((float) $info['starttransfer_time']) * 1000) : null,
            'total_ms' => isset($info['total_time']) ? (int) round(((float) $info['total_time']) * 1000) : $wallMs,
            'wall_clock_ms' => $wallMs,
        ],
        'download_bytes_curl' => isset($info['size_download']) ? (int) round((float) $info['size_download']) : strlen($responseString),
    ];
}

function trueMoneyZelthrRunTransport(callable $transport, string $url, array $jsonBody): array
{
    try {
        $result = $transport($url, $jsonBody);
        if (!is_array($result)) throw new RuntimeException('transport returned invalid result');
        return array_merge([
            'executed' => true,
            'http_code' => 0,
            'curl_errno' => 0,
            'curl_error' => '',
            'body' => '',
            'headers' => [],
            'response_too_large' => false,
            'primary_ip' => '',
            'ssl_verify_result' => null,
            'timing' => [],
            'download_bytes_curl' => 0,
        ], $result);
    } catch (Throwable $e) {
        return [
            'executed' => false,
            'http_code' => 0,
            'curl_errno' => -4,
            'curl_error' => trueMoneyZelthrSafeText($e->getMessage(), 300),
            'body' => '',
            'headers' => [],
            'response_too_large' => false,
            'primary_ip' => '',
            'ssl_verify_result' => null,
            'timing' => [],
            'download_bytes_curl' => 0,
        ];
    }
}

function trueMoneyZelthrDecode(array $transport): array
{
    $body = (string) ($transport['body'] ?? '');
    if ($body === '') return ['valid' => false, 'json' => null, 'error' => 'empty response body'];
    $json = json_decode($body, true);
    if (!is_array($json) || json_last_error() !== JSON_ERROR_NONE) {
        return ['valid' => false, 'json' => null, 'error' => json_last_error_msg()];
    }
    return ['valid' => true, 'json' => $json, 'error' => null];
}

function trueMoneyZelthrStatus(array $json): array
{
    $status = isset($json['status']) && is_array($json['status']) ? $json['status'] : [];
    $code = isset($status['code']) && is_scalar($status['code']) ? strtoupper(trim((string) $status['code'])) : '';
    $message = isset($status['message']) && is_scalar($status['message']) ? trim((string) $status['message']) : '';
    return ['code' => $code, 'message' => $message];
}

function trueMoneyZelthrGatewayError(array $json): array
{
    $slug = '';
    foreach (['error', 'slug', 'code'] as $key) {
        if (isset($json[$key]) && is_scalar($json[$key]) && trim((string) $json[$key]) !== '') {
            $slug = strtolower(trim((string) $json[$key]));
            break;
        }
    }
    $message = isset($json['message']) && is_scalar($json['message']) ? trim((string) $json['message']) : '';
    return ['slug' => $slug, 'message' => $message];
}

function trueMoneyZelthrAmount(array $json): ?float
{
    $paths = [
        ['data', 'redeem', 'amount_baht'],
        ['data', 'redeem', 'amount'],
        ['data', 'my_ticket', 'amount_baht'],
        ['amount'],
        ['data', 'amount'],
    ];
    foreach ($paths as $path) {
        $value = $json;
        foreach ($path as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                $value = null;
                break;
            }
            $value = $value[$key];
        }
        if (!is_scalar($value) || !is_numeric((string) $value)) continue;
        $amount = round((float) $value, 2, PHP_ROUND_HALF_UP);
        if (is_finite($amount) && $amount > 0 && $amount <= 1000000) return $amount;
    }
    return null;
}

function trueMoneyZelthrUserMessage(string $code, string $fallback = ''): string
{
    $messages = [
        'BAD_PARAM' => 'ข้อมูลซองหรือเบอร์รับเงินไม่ถูกต้อง',
        'VOUCHER_NOT_FOUND' => 'ไม่พบซองของขวัญนี้ หรือรหัสซองไม่ถูกต้อง',
        'VOUCHER_OUT_OF_STOCK' => 'ซองของขวัญนี้ถูกรับครบแล้ว',
        'VOUCHER_EXPIRED' => 'ซองของขวัญหมดอายุแล้ว',
        'CANNOT_GET_OWN_VOUCHER' => 'ไม่สามารถรับซองของขวัญของตัวเองได้',
        'TARGET_USER_NOT_FOUND' => 'ไม่พบบัญชี TrueMoney ของเบอร์รับเงินนี้',
        'TARGET_USER_REDEEMED' => 'เบอร์รับเงินนี้เคยรับซองนี้แล้ว',
        'TARGET_USER_STATUS_INACTIVE' => 'บัญชี TrueMoney ของผู้รับไม่พร้อมใช้งาน',
        'INTERNAL_ERROR' => 'ระบบรับซองตอบกลับผิดพลาด กรุณาลองใหม่ภายหลัง',
        'rate-limit-exceeded' => 'ระบบรับซองจำกัดการเรียกใช้งานชั่วคราว กรุณารอสักครู่',
        'tmn-voucher-not-found' => 'ไม่พบซองของขวัญนี้ หรือรหัสซองไม่ถูกต้อง',
        'validation-error' => 'ข้อมูลที่ส่งไปยังระบบรับซองไม่ถูกต้อง',
    ];
    if (isset($messages[$code])) return $messages[$code];
    return $fallback !== '' ? trueMoneyZelthrSafeText($fallback, 300) : 'ไม่สามารถรับซองของขวัญได้';
}

function trueMoneyZelthrDebugRequest(array &$debug, string $token, string $phone): void
{
    if (!isset($debug['provider']) || !is_array($debug['provider'])) trueMoneyZelthrInitializeDebug($debug);
    $debug['provider']['request'] = [
        'provider' => trueMoneyZelthrProviderName(),
        'method' => 'POST',
        'attempt' => 1,
        'receiver_phone_masked' => function_exists('trueMoneyDebugMaskedPhone') ? trueMoneyDebugMaskedPhone($phone) : str_repeat('*', 6) . substr($phone, -4),
        'voucher_fingerprint' => substr(hash('sha256', $token), 0, 16),
        'voucher_token_length' => strlen($token),
        'body_fields' => ['gift', 'phone'],
        'voucher_value_stored' => false,
        'phone_value_stored' => false,
        'authorization_configured' => false,
        'connect_timeout_seconds' => (int) TM_ZELTHR_CONNECT_TIMEOUT,
        'total_timeout_seconds' => (int) TM_ZELTHR_TOTAL_TIMEOUT,
        'tls_verify_peer' => true,
        'tls_verify_host' => true,
        'follow_redirects' => false,
        'automatic_retry' => false,
        'request_url' => [
            'scheme' => 'https',
            'host' => 'api.zelthr.rest',
            'port' => 443,
            'path' => '/',
            'query_present' => false,
            'sensitive_path_redacted' => false,
        ],
        'full_request_url_stored' => false,
    ];
    if (function_exists('trueMoneyDebugEvent')) {
        trueMoneyDebugEvent($debug, 'provider_request_started', [
            'provider' => trueMoneyZelthrProviderName(),
            'method' => 'POST',
        ]);
    }
}

function trueMoneyZelthrDebugResponse(array &$debug, array $transport): void
{
    $decoded = trueMoneyZelthrDecode($transport);
    $json = $decoded['valid'] ? $decoded['json'] : null;
    $status = is_array($json) ? trueMoneyZelthrStatus($json) : ['code' => '', 'message' => ''];
    $gatewayError = is_array($json) ? trueMoneyZelthrGatewayError($json) : ['slug' => '', 'message' => ''];
    $amount = is_array($json) ? trueMoneyZelthrAmount($json) : null;
    $headers = isset($transport['headers']) && is_array($transport['headers']) ? $transport['headers'] : [];

    $headerValue = static function (array $headers, string $key): ?string {
        if (!isset($headers[$key])) return null;
        $value = $headers[$key];
        if (is_array($value)) $value = implode(', ', $value);
        return trueMoneyZelthrSafeText((string) $value, 160);
    };

    $debug['provider']['transport'] = [
        'executed' => !empty($transport['executed']),
        'curl_errno' => (int) ($transport['curl_errno'] ?? 0),
        'curl_error' => trueMoneyZelthrSafeText((string) ($transport['curl_error'] ?? ''), 300),
        'response_too_large' => !empty($transport['response_too_large']),
        'http_code' => (int) ($transport['http_code'] ?? 0),
        'primary_ip' => trueMoneyZelthrSafeText((string) ($transport['primary_ip'] ?? ''), 80),
        'ssl_verify_result' => $transport['ssl_verify_result'] ?? null,
        'timing' => isset($transport['timing']) && is_array($transport['timing']) ? $transport['timing'] : [],
        'download_bytes_curl' => (int) ($transport['download_bytes_curl'] ?? strlen((string) ($transport['body'] ?? ''))),
    ];
    $debug['provider']['response'] = [
        'headers' => [
            'status_line' => $headerValue($headers, 'status_line'),
            'content-type' => $headerValue($headers, 'content-type'),
            'retry-after' => $headerValue($headers, 'retry-after'),
            'server' => $headerValue($headers, 'server'),
            'cf-ray' => $headerValue($headers, 'cf-ray'),
        ],
        'body_bytes' => strlen((string) ($transport['body'] ?? '')),
        'body_sha256' => (string) ($transport['body'] ?? '') !== '' ? hash('sha256', (string) $transport['body']) : null,
        'json_valid' => (bool) $decoded['valid'],
        'json_error' => $decoded['valid'] ? null : trueMoneyZelthrSafeText((string) $decoded['error'], 200),
        'top_level_keys' => is_array($json) ? array_values(array_slice(array_map('strval', array_keys($json)), 0, 30)) : [],
        'provider_status_code' => $status['code'] !== '' ? $status['code'] : null,
        'provider_message' => $status['message'] !== '' ? trueMoneyZelthrSafeText($status['message'], 300) : null,
        'gateway_error' => $gatewayError['slug'] !== '' ? $gatewayError['slug'] : null,
        'amount_thb' => $amount,
        'raw_provider_body_stored' => false,
    ];
    if (function_exists('trueMoneyDebugEvent')) {
        trueMoneyDebugEvent($debug, 'provider_response_received', [
            'provider' => trueMoneyZelthrProviderName(),
            'http_code' => (int) ($transport['http_code'] ?? 0),
            'curl_errno' => (int) ($transport['curl_errno'] ?? 0),
            'json_valid' => (bool) $decoded['valid'],
            'provider_status_code' => $status['code'] !== '' ? $status['code'] : null,
            'gateway_error' => $gatewayError['slug'] !== '' ? $gatewayError['slug'] : null,
        ]);
    }
}

function trueMoneyZelthrPendingState(string $voucherHash, int $userId): array
{
    global $conn;
    if ($userId < 1 || preg_match('/^[a-f0-9]{64}$/D', $voucherHash) !== 1 || !isset($conn) || !($conn instanceof mysqli)) {
        return ['pending' => false];
    }
    try {
        $stmt = $conn->prepare('SELECT id,status,provider_message,updated_at FROM truemoney_redemptions WHERE voucher_hash=? AND user_id=? LIMIT 1');
        if (!$stmt) return ['pending' => false];
        $stmt->bind_param('si', $voucherHash, $userId);
        if (!$stmt->execute()) {
            $stmt->close();
            return ['pending' => false];
        }
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if (!$row || (string) ($row['status'] ?? '') !== 'indeterminate') return ['pending' => false];
        return [
            'pending' => true,
            'redemption_id' => (int) $row['id'],
            'message' => 'รายการรับซองก่อนหน้ายังยืนยันผลไม่ได้ เพื่อป้องกันรับซ้ำระบบจะไม่ยิงซองเดิมซ้ำอัตโนมัติ กรุณาติดต่อผู้ดูแลพร้อมรหัสรายการ',
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    } catch (Throwable $e) {
        return ['pending' => false];
    }
}

function trueMoneyZelthrMarkIndeterminate(int $redemptionId, int $userId, string $message, ?array &$debug = null): bool
{
    global $conn;
    if ($redemptionId < 1 || $userId < 1 || !isset($conn) || !($conn instanceof mysqli)) return false;
    $message = trueMoneyZelthrSafeText($message, 240);
    if ($message === '') $message = 'Zelthr redemption result is indeterminate';
    $status = 'indeterminate';
    $stmt = $conn->prepare("UPDATE truemoney_redemptions SET status=?, provider_message=?, updated_at=NOW() WHERE id=? AND user_id=? AND status<>'completed'");
    if (!$stmt) return false;
    $stmt->bind_param('ssii', $status, $message, $redemptionId, $userId);
    $ok = $stmt->execute();
    $affected = (int) $stmt->affected_rows;
    $stmt->close();
    if ($debug !== null && function_exists('trueMoneyDebugEvent')) {
        trueMoneyDebugEvent($debug, 'redemption_marked_indeterminate', [
            'affected_rows' => $affected,
            'provider_message' => $message,
        ]);
        if (function_exists('trueMoneyDebugCaptureState')) trueMoneyDebugCaptureState($debug, $redemptionId, $userId);
    }
    return $ok;
}

function redeemAngpaoZelthr(string $voucherUrl, string $mobile, ?array &$debug = null, ?callable $transport = null): array
{
    $normalized = trueMoneyZelthrNormalizeVoucher($voucherUrl);
    if (empty($normalized['success'])) {
        if ($debug !== null && function_exists('trueMoneyDebugError')) {
            trueMoneyDebugError($debug, 'provider_preflight_failed', 'voucher_url_invalid', (string) ($normalized['message'] ?? 'Invalid voucher URL'));
        }
        return ['success' => false, 'code' => 'INVALID_INPUT', 'message' => (string) ($normalized['message'] ?? 'ลิงก์ซองไม่ถูกต้อง')];
    }

    $mobile = preg_replace('/\D+/', '', $mobile) ?? '';
    if (preg_match('/^0\d{9}$/D', $mobile) !== 1) {
        if ($debug !== null && function_exists('trueMoneyDebugError')) {
            trueMoneyDebugError($debug, 'provider_preflight_failed', 'receiver_phone_invalid', 'Configured TrueMoney receiver phone is invalid');
        }
        return ['success' => false, 'code' => 'INVALID_INPUT', 'message' => 'เบอร์รับเงิน TrueMoney ไม่ถูกต้อง'];
    }

    $token = (string) $normalized['token'];
    if ($debug !== null) {
        trueMoneyZelthrInitializeDebug($debug);
        if (function_exists('trueMoneyDebugEvent')) {
            trueMoneyDebugEvent($debug, 'provider_preflight_passed', [
                'provider' => trueMoneyZelthrProviderName(),
                'endpoint' => 'https://api.zelthr.rest/',
                'method' => 'POST',
            ]);
        }
        trueMoneyZelthrDebugRequest($debug, $token, $mobile);
    }

    $transport = $transport ?? 'trueMoneyZelthrCurlRequest';
    $response = trueMoneyZelthrRunTransport(
        $transport,
        (string) TM_ZELTHR_API_URL,
        ['gift' => (string) $normalized['url'], 'phone' => $mobile]
    );
    if ($debug !== null) trueMoneyZelthrDebugResponse($debug, $response);

    $httpCode = (int) ($response['http_code'] ?? 0);
    $transportFailed = empty($response['executed'])
        || (int) ($response['curl_errno'] ?? 0) !== 0
        || $httpCode === 0
        || !empty($response['response_too_large']);
    if ($transportFailed) {
        if ($debug !== null && function_exists('trueMoneyDebugError')) {
            trueMoneyDebugError($debug, 'provider_result_indeterminate', 'provider_indeterminate', 'Zelthr redeem request did not return a definitive HTTP response');
        }
        return [
            'success' => false,
            'code' => 'provider_indeterminate',
            'indeterminate' => true,
            'message' => 'ส่งคำขอรับซองแล้ว แต่ยังยืนยันผลไม่ได้ ระบบหยุดการยิงซ้ำอัตโนมัติเพื่อป้องกันรายการซ้ำ กรุณาติดต่อผู้ดูแล',
        ];
    }

    $decoded = trueMoneyZelthrDecode($response);
    $json = !empty($decoded['valid']) && is_array($decoded['json']) ? $decoded['json'] : null;

    if ($httpCode === 429) {
        if ($debug !== null && function_exists('trueMoneyDebugError')) {
            trueMoneyDebugError($debug, 'provider_failed', 'provider_rate_limited', 'Zelthr gateway returned HTTP 429');
        }
        return ['success' => false, 'code' => 'rate-limit-exceeded', 'message' => trueMoneyZelthrUserMessage('rate-limit-exceeded')];
    }

    if ($httpCode >= 500) {
        $gatewayError = is_array($json) ? trueMoneyZelthrGatewayError($json) : ['slug' => '', 'message' => ''];
        if ($debug !== null && function_exists('trueMoneyDebugError')) {
            trueMoneyDebugError($debug, 'provider_result_indeterminate', 'provider_indeterminate', 'Zelthr gateway returned HTTP ' . $httpCode . ($gatewayError['slug'] !== '' ? ' (' . $gatewayError['slug'] . ')' : ''));
        }
        return [
            'success' => false,
            'code' => 'provider_indeterminate',
            'indeterminate' => true,
            'message' => 'ระบบรับซองขัดข้องระหว่างทำรายการและยังยืนยันผลไม่ได้ ระบบหยุดการยิงซ้ำไว้เพื่อป้องกันรายการซ้ำ',
        ];
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $gatewayError = is_array($json) ? trueMoneyZelthrGatewayError($json) : ['slug' => 'gateway-http-' . $httpCode, 'message' => ''];
        $code = $gatewayError['slug'] !== '' ? $gatewayError['slug'] : 'gateway-http-' . $httpCode;
        $message = trueMoneyZelthrUserMessage($code, (string) ($gatewayError['message'] ?? ''));
        if ($debug !== null && function_exists('trueMoneyDebugError')) {
            trueMoneyDebugError($debug, 'provider_failed', 'zelthr_' . preg_replace('/[^a-z0-9_-]+/i', '_', $code), (string) ($gatewayError['message'] !== '' ? $gatewayError['message'] : $message));
        }
        return ['success' => false, 'code' => $code, 'message' => $message];
    }

    if (!is_array($json)) {
        if ($debug !== null && function_exists('trueMoneyDebugError')) {
            trueMoneyDebugError($debug, 'provider_result_indeterminate', 'provider_indeterminate', 'Zelthr returned 2xx but response body was not valid JSON');
        }
        return [
            'success' => false,
            'code' => 'provider_indeterminate',
            'indeterminate' => true,
            'message' => 'ระบบรับซองตอบกลับไม่สมบูรณ์หลังส่งคำขอ เพื่อป้องกันรายการซ้ำระบบจะไม่ยิงซ้ำอัตโนมัติ',
        ];
    }

    $status = trueMoneyZelthrStatus($json);
    if ($status['code'] === 'SUCCESS') {
        $amount = trueMoneyZelthrAmount($json);
        if ($amount === null) {
            if ($debug !== null && function_exists('trueMoneyDebugError')) {
                trueMoneyDebugError($debug, 'provider_result_indeterminate', 'provider_indeterminate', 'Zelthr reported SUCCESS without a safely extractable redeemed amount');
            }
            return [
                'success' => false,
                'code' => 'provider_indeterminate',
                'indeterminate' => true,
                'message' => 'รับซองอาจสำเร็จแล้ว แต่ไม่พบยอดเงินที่ยืนยันได้ ระบบหยุดการยิงซ้ำและต้องตรวจสอบรายการก่อน',
            ];
        }
        if ($debug !== null && function_exists('trueMoneyDebugEvent')) {
            trueMoneyDebugEvent($debug, 'provider_voucher_confirmed', [
                'provider' => trueMoneyZelthrProviderName(),
                'amount_thb' => $amount,
            ]);
        }
        return ['success' => true, 'amount' => $amount, 'code' => 'SUCCESS'];
    }

    if ($status['code'] !== '') {
        $message = trueMoneyZelthrUserMessage($status['code'], $status['message']);
        if ($debug !== null && function_exists('trueMoneyDebugError')) {
            trueMoneyDebugError($debug, 'provider_failed', 'zelthr_' . strtolower($status['code']), $status['message'] !== '' ? $status['message'] : $message);
        }
        return [
            'success' => false,
            'code' => $status['code'],
            'message' => $message,
            'provider_message' => trueMoneyZelthrSafeText($status['message'], 300),
        ];
    }

    if ($debug !== null && function_exists('trueMoneyDebugError')) {
        trueMoneyDebugError($debug, 'provider_result_indeterminate', 'provider_indeterminate', 'Zelthr returned 2xx without a recognizable TrueMoney status code');
    }
    return [
        'success' => false,
        'code' => 'provider_indeterminate',
        'indeterminate' => true,
        'message' => 'ระบบรับซองตอบกลับไม่ชัดเจนหลังส่งคำขอ ระบบจะไม่ยิงซ้ำอัตโนมัติเพื่อป้องกันรายการซ้ำ',
    ];
}
