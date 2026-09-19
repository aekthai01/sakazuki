<?php
/**
 * Dokmai Angpao provider adapter for SAKAZUKI.
 *
 * Runtime secret loading order:
 *   1. DOKMAI_API_KEY environment variable
 *   2. <domain-root>/private/dokmai.php returning ['api_key' => '...']
 *
 * No API key is committed to the repository or exposed in debug output.
 * A stable Idempotency-Key is derived from user + voucher fingerprint so a
 * later retry can safely recover an ambiguous request without creating a new
 * provider-side operation.
 */

if (!defined('TM_DOKMAI_API_URL')) {
    define('TM_DOKMAI_API_URL', 'https://api.dokmaistore.com/api/v1/payments/core/angpao/redeem');
}
if (!defined('TM_DOKMAI_CONNECT_TIMEOUT')) {
    define('TM_DOKMAI_CONNECT_TIMEOUT', 10);
}
if (!defined('TM_DOKMAI_TOTAL_TIMEOUT')) {
    define('TM_DOKMAI_TOTAL_TIMEOUT', 30);
}
if (!defined('TM_DOKMAI_MAX_RESPONSE_BYTES')) {
    define('TM_DOKMAI_MAX_RESPONSE_BYTES', 1048576);
}

function trueMoneyDokmaiProviderName(): string
{
    return 'dokmai';
}

function trueMoneyDokmaiProviderDisplayName(): string
{
    return 'Dokmai Angpao / api.dokmaistore.com';
}

function trueMoneyDokmaiSafeText($value, int $maxLength = 500): string
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

function trueMoneyDokmaiApiKey(): string
{
    static $loaded = false;
    static $cached = '';
    if ($loaded) return $cached;
    $loaded = true;

    $env = getenv('DOKMAI_API_KEY');
    if ($env !== false && trim((string) $env) !== '') {
        $cached = trim((string) $env);
        return $cached;
    }

    $candidate = dirname(__DIR__, 2) . '/private/dokmai.php';
    if (!is_file($candidate) || !is_readable($candidate)) return '';

    try {
        $config = require $candidate;
        if (is_array($config) && isset($config['api_key']) && is_scalar($config['api_key'])) {
            $cached = trim((string) $config['api_key']);
        } elseif (is_string($config)) {
            $cached = trim($config);
        }
    } catch (Throwable $e) {
        error_log('Dokmai private config load failed: ' . $e->getMessage());
        $cached = '';
    }
    return $cached;
}

function trueMoneyDokmaiApiKeyConfigured(): bool
{
    return trueMoneyDokmaiApiKey() !== '';
}

function trueMoneyDokmaiNormalizeVoucher($voucherUrl): array
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

function trueMoneyDokmaiIdempotencyKey(int $userId, string $voucherHash): string
{
    $voucherHash = strtolower(trim($voucherHash));
    if ($userId < 1 || preg_match('/^[a-f0-9]{64}$/D', $voucherHash) !== 1) return '';
    return 'sakazuki-tmn-' . substr(hash('sha256', 'v1|' . $userId . '|' . $voucherHash), 0, 48);
}

function trueMoneyDokmaiInitializeDebug(array &$debug): void
{
    $debug['provider'] = [
        'name' => trueMoneyDokmaiProviderName(),
        'target' => [
            'scheme' => 'https',
            'host' => 'api.dokmaistore.com',
            'port' => 443,
            'path' => '/api/v1/payments/core/angpao/redeem',
            'query_present' => false,
            'path_segment_count' => 6,
            'sensitive_path_redacted' => false,
        ],
        'request' => [],
        'transport' => [],
        'response' => [],
    ];
    if (isset($debug['privacy']) && is_array($debug['privacy'])) {
        $debug['privacy']['voucher_token_stored'] = false;
        $debug['privacy']['provider_authorization_stored'] = false;
        $debug['privacy']['raw_provider_body_stored'] = false;
        $debug['privacy']['raw_provider_effective_url_stored'] = false;
        $debug['privacy']['xpluem_path_secrets_stored'] = false;
        $debug['privacy']['credentials_redacted'] = true;
    }
}

function trueMoneyDokmaiCurlRequest(string $url, array $jsonBody, string $apiKey, string $idempotencyKey): array
{
    $responseHeaders = [];
    $payload = json_encode($jsonBody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($payload)) {
        return [
            'executed' => false, 'http_code' => 0, 'curl_errno' => -2,
            'curl_error' => 'json_encode failed', 'body' => '', 'headers' => [],
            'response_too_large' => false, 'timing' => [],
        ];
    }

    $ch = curl_init();
    if ($ch === false) {
        return [
            'executed' => false, 'http_code' => 0, 'curl_errno' => -1,
            'curl_error' => 'curl_init failed', 'body' => '', 'headers' => [],
            'response_too_large' => false, 'timing' => [],
        ];
    }

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => (int) TM_DOKMAI_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT => (int) TM_DOKMAI_TOTAL_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'x-dokmai-api-key: ' . $apiKey,
            'Idempotency-Key: ' . $idempotencyKey,
            'Cache-Control: no-cache, no-store, max-age=0',
            'Pragma: no-cache',
        ],
        CURLOPT_USERAGENT => 'SakazukiTrueMoney/4.0',
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
    $body = curl_exec($ch);
    $wallMs = (int) round((microtime(true) - $started) * 1000);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);

    $bodyString = is_string($body) ? $body : '';
    $tooLarge = strlen($bodyString) > (int) TM_DOKMAI_MAX_RESPONSE_BYTES;
    if ($tooLarge) $bodyString = '';

    return [
        'executed' => $body !== false,
        'http_code' => (int) ($info['http_code'] ?? 0),
        'curl_errno' => (int) $errno,
        'curl_error' => trueMoneyDokmaiSafeText($error, 300),
        'body' => $bodyString,
        'headers' => $responseHeaders,
        'response_too_large' => $tooLarge,
        'primary_ip' => trueMoneyDokmaiSafeText((string) ($info['primary_ip'] ?? ''), 80),
        'ssl_verify_result' => isset($info['ssl_verifyresult']) ? (int) $info['ssl_verifyresult'] : null,
        'timing' => [
            'dns_complete_ms' => isset($info['namelookup_time']) ? (int) round(((float) $info['namelookup_time']) * 1000) : null,
            'tcp_connect_complete_ms' => isset($info['connect_time']) ? (int) round(((float) $info['connect_time']) * 1000) : null,
            'tls_complete_ms' => isset($info['appconnect_time']) ? (int) round(((float) $info['appconnect_time']) * 1000) : null,
            'ttfb_complete_ms' => isset($info['starttransfer_time']) ? (int) round(((float) $info['starttransfer_time']) * 1000) : null,
            'total_ms' => isset($info['total_time']) ? (int) round(((float) $info['total_time']) * 1000) : $wallMs,
            'wall_clock_ms' => $wallMs,
        ],
        'download_bytes_curl' => isset($info['size_download']) ? (int) round((float) $info['size_download']) : strlen($bodyString),
    ];
}

function trueMoneyDokmaiRunTransport(callable $transport, string $url, array $jsonBody, string $apiKey, string $idempotencyKey): array
{
    try {
        $result = $transport($url, $jsonBody, $apiKey, $idempotencyKey);
        if (!is_array($result)) throw new RuntimeException('transport returned invalid result');
        return array_merge([
            'executed' => true, 'http_code' => 0, 'curl_errno' => 0,
            'curl_error' => '', 'body' => '', 'headers' => [],
            'response_too_large' => false, 'primary_ip' => '',
            'ssl_verify_result' => null, 'timing' => [], 'download_bytes_curl' => 0,
        ], $result);
    } catch (Throwable $e) {
        return [
            'executed' => false, 'http_code' => 0, 'curl_errno' => -4,
            'curl_error' => trueMoneyDokmaiSafeText($e->getMessage(), 300),
            'body' => '', 'headers' => [], 'response_too_large' => false,
            'primary_ip' => '', 'ssl_verify_result' => null, 'timing' => [],
            'download_bytes_curl' => 0,
        ];
    }
}

function trueMoneyDokmaiDecode(array $transport): array
{
    $body = (string) ($transport['body'] ?? '');
    if ($body === '') return ['valid' => false, 'json' => null, 'error' => 'empty response body'];
    $json = json_decode($body, true);
    if (!is_array($json) || json_last_error() !== JSON_ERROR_NONE) {
        return ['valid' => false, 'json' => null, 'error' => json_last_error_msg()];
    }
    return ['valid' => true, 'json' => $json, 'error' => null];
}

function trueMoneyDokmaiBool($value): ?bool
{
    if ($value === true || $value === 1 || $value === '1' || $value === 'true') return true;
    if ($value === false || $value === 0 || $value === '0' || $value === 'false') return false;
    return null;
}

function trueMoneyDokmaiError(array $json): array
{
    $error = isset($json['error']) && is_array($json['error']) ? $json['error'] : [];
    $code = '';
    foreach ([$error['code'] ?? null, $json['code'] ?? null] as $candidate) {
        if (is_scalar($candidate) && trim((string) $candidate) !== '') {
            $code = strtolower(trim((string) $candidate));
            break;
        }
    }
    $message = '';
    foreach ([$error['message'] ?? null, $json['message'] ?? null] as $candidate) {
        if (is_scalar($candidate) && trim((string) $candidate) !== '') {
            $message = trim((string) $candidate);
            break;
        }
    }
    $retryableRaw = $error['retryable'] ?? ($json['retryable'] ?? null);
    $retryable = trueMoneyDokmaiBool($retryableRaw);
    $requestId = isset($error['requestId']) && is_scalar($error['requestId'])
        ? trim((string) $error['requestId'])
        : (isset($json['requestId']) && is_scalar($json['requestId']) ? trim((string) $json['requestId']) : '');
    return [
        'code' => $code,
        'message' => $message,
        'retryable' => $retryable,
        'request_id' => $requestId,
    ];
}

function trueMoneyDokmaiAmount(array $json): ?float
{
    // Only explicit amount paths are accepted. Do not recursively pick an
    // arbitrary numeric field because a payment payload may also contain fees,
    // balances, counts, or identifiers.
    $paths = [
        ['data', 'amount_baht'],
        ['data', 'amount'],
        ['data', 'redeem', 'amount_baht'],
        ['data', 'redeem', 'amount'],
        ['data', 'my_ticket', 'amount_baht'],
        ['result', 'amount_baht'],
        ['result', 'amount'],
        ['amount_baht'],
        ['amount'],
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

function trueMoneyDokmaiUserMessage(string $code, string $fallback = ''): string
{
    $code = strtolower(trim($code));
    $messages = [
        'invalid_api_key' => 'ระบบรับซองยังไม่ได้ตั้งค่า API key ที่ถูกต้อง',
        'unauthorized' => 'ระบบรับซองยืนยันตัวตนไม่สำเร็จ',
        'rate_limit_exceeded' => 'ระบบรับซองจำกัดการเรียกใช้งานชั่วคราว กรุณารอสักครู่',
        'rate_limited' => 'ระบบรับซองจำกัดการเรียกใช้งานชั่วคราว กรุณารอสักครู่',
        'invalid_voucher' => 'ลิงก์ซองของขวัญไม่ถูกต้อง',
        'voucher_not_found' => 'ไม่พบซองของขวัญนี้ หรือรหัสซองไม่ถูกต้อง',
        'voucher_expired' => 'ซองของขวัญหมดอายุแล้ว',
        'voucher_redeemed' => 'ซองของขวัญนี้ถูกรับไปแล้ว',
        'already_redeemed' => 'ซองของขวัญนี้ถูกรับไปแล้ว',
        'target_user_redeemed' => 'เบอร์รับเงินนี้เคยรับซองนี้แล้ว',
        'cannot_get_own_voucher' => 'ไม่สามารถรับซองของขวัญของตัวเองได้',
        'invalid_phone' => 'เบอร์รับเงิน TrueMoney ไม่ถูกต้อง',
    ];
    if (isset($messages[$code])) return $messages[$code];
    return $fallback !== '' ? trueMoneyDokmaiSafeText($fallback, 300) : 'ไม่สามารถรับซองของขวัญได้';
}

function trueMoneyDokmaiDebugRequest(array &$debug, string $token, string $phone, string $idempotencyKey): void
{
    if (!isset($debug['provider']) || !is_array($debug['provider'])) trueMoneyDokmaiInitializeDebug($debug);
    $debug['provider']['request'] = [
        'provider' => trueMoneyDokmaiProviderName(),
        'method' => 'POST',
        'attempt' => 1,
        'receiver_phone_masked' => function_exists('trueMoneyDebugMaskedPhone') ? trueMoneyDebugMaskedPhone($phone) : str_repeat('*', 6) . substr($phone, -4),
        'voucher_fingerprint' => substr(hash('sha256', $token), 0, 16),
        'voucher_token_length' => strlen($token),
        'body_fields' => ['phoneNumber', 'voucher'],
        'voucher_value_stored' => false,
        'phone_value_stored' => false,
        'authorization_configured' => true,
        'authorization_stored' => false,
        'idempotency_configured' => $idempotencyKey !== '',
        'idempotency_key_sha256' => $idempotencyKey !== '' ? hash('sha256', $idempotencyKey) : null,
        'connect_timeout_seconds' => (int) TM_DOKMAI_CONNECT_TIMEOUT,
        'total_timeout_seconds' => (int) TM_DOKMAI_TOTAL_TIMEOUT,
        'tls_verify_peer' => true,
        'tls_verify_host' => true,
        'follow_redirects' => false,
        'automatic_retry' => false,
        'request_url' => [
            'scheme' => 'https',
            'host' => 'api.dokmaistore.com',
            'port' => 443,
            'path' => '/api/v1/payments/core/angpao/redeem',
            'query_present' => false,
            'sensitive_path_redacted' => false,
        ],
        'full_request_url_stored' => false,
    ];
    if (function_exists('trueMoneyDebugEvent')) {
        trueMoneyDebugEvent($debug, 'provider_request_started', [
            'provider' => trueMoneyDokmaiProviderName(),
            'method' => 'POST',
            'idempotency_configured' => $idempotencyKey !== '',
        ]);
    }
}

function trueMoneyDokmaiDebugResponse(array &$debug, array $transport): void
{
    $decoded = trueMoneyDokmaiDecode($transport);
    $json = $decoded['valid'] ? $decoded['json'] : null;
    $error = is_array($json) ? trueMoneyDokmaiError($json) : ['code' => '', 'message' => '', 'retryable' => null, 'request_id' => ''];
    $success = is_array($json) ? trueMoneyDokmaiBool($json['success'] ?? null) : null;
    $amount = is_array($json) ? trueMoneyDokmaiAmount($json) : null;
    $headers = isset($transport['headers']) && is_array($transport['headers']) ? $transport['headers'] : [];
    $headerValue = static function (array $headers, string $key): ?string {
        if (!isset($headers[$key])) return null;
        $value = $headers[$key];
        if (is_array($value)) $value = implode(', ', $value);
        return trueMoneyDokmaiSafeText((string) $value, 160);
    };

    $debug['provider']['transport'] = [
        'executed' => !empty($transport['executed']),
        'curl_errno' => (int) ($transport['curl_errno'] ?? 0),
        'curl_error' => trueMoneyDokmaiSafeText((string) ($transport['curl_error'] ?? ''), 300),
        'response_too_large' => !empty($transport['response_too_large']),
        'http_code' => (int) ($transport['http_code'] ?? 0),
        'primary_ip' => trueMoneyDokmaiSafeText((string) ($transport['primary_ip'] ?? ''), 80),
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
        'json_error' => $decoded['valid'] ? null : trueMoneyDokmaiSafeText((string) $decoded['error'], 200),
        'top_level_keys' => is_array($json) ? array_values(array_slice(array_map('strval', array_keys($json)), 0, 30)) : [],
        'provider_success' => $success,
        'provider_error_code' => $error['code'] !== '' ? $error['code'] : null,
        'provider_message' => $error['message'] !== '' ? trueMoneyDokmaiSafeText($error['message'], 300) : null,
        'provider_retryable' => $error['retryable'],
        'provider_request_id' => $error['request_id'] !== '' ? trueMoneyDokmaiSafeText($error['request_id'], 160) : null,
        'amount_thb' => $amount,
        'raw_provider_body_stored' => false,
    ];
    if (function_exists('trueMoneyDebugEvent')) {
        trueMoneyDebugEvent($debug, 'provider_response_received', [
            'provider' => trueMoneyDokmaiProviderName(),
            'http_code' => (int) ($transport['http_code'] ?? 0),
            'curl_errno' => (int) ($transport['curl_errno'] ?? 0),
            'json_valid' => (bool) $decoded['valid'],
            'provider_success' => $success,
            'provider_error_code' => $error['code'] !== '' ? $error['code'] : null,
            'provider_retryable' => $error['retryable'],
        ]);
    }
}

function trueMoneyDokmaiMarkIndeterminate(int $redemptionId, int $userId, string $message, ?array &$debug = null): bool
{
    global $conn;
    if ($redemptionId < 1 || $userId < 1 || !isset($conn) || !($conn instanceof mysqli)) return false;
    $message = trueMoneyDokmaiSafeText($message, 240);
    if ($message === '') $message = 'Dokmai redemption result is indeterminate';
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
            'retry_with_same_idempotency_key' => true,
        ]);
        if (function_exists('trueMoneyDebugCaptureState')) trueMoneyDebugCaptureState($debug, $redemptionId, $userId);
    }
    return $ok;
}

function redeemAngpaoDokmai(
    string $voucherUrl,
    string $mobile,
    int $userId,
    string $voucherHash,
    ?array &$debug = null,
    ?callable $transport = null,
    ?string $apiKey = null
): array {
    $normalized = trueMoneyDokmaiNormalizeVoucher($voucherUrl);
    if (empty($normalized['success'])) {
        if ($debug !== null && function_exists('trueMoneyDebugError')) {
            trueMoneyDebugError($debug, 'provider_preflight_failed', 'voucher_url_invalid', (string) ($normalized['message'] ?? 'Invalid voucher URL'));
        }
        return ['success' => false, 'code' => 'invalid_input', 'message' => (string) ($normalized['message'] ?? 'ลิงก์ซองไม่ถูกต้อง')];
    }

    $mobile = preg_replace('/\D+/', '', $mobile) ?? '';
    if (preg_match('/^0\d{9}$/D', $mobile) !== 1) {
        if ($debug !== null && function_exists('trueMoneyDebugError')) {
            trueMoneyDebugError($debug, 'provider_preflight_failed', 'receiver_phone_invalid', 'Configured TrueMoney receiver phone is invalid');
        }
        return ['success' => false, 'code' => 'invalid_phone', 'message' => 'เบอร์รับเงิน TrueMoney ไม่ถูกต้อง'];
    }

    $apiKey = $apiKey !== null ? trim($apiKey) : trueMoneyDokmaiApiKey();
    if ($apiKey === '') {
        if ($debug !== null && function_exists('trueMoneyDebugError')) {
            trueMoneyDebugError($debug, 'provider_preflight_failed', 'dokmai_api_key_missing', 'Dokmai API key is not configured');
        }
        return ['success' => false, 'code' => 'dokmai_api_key_missing', 'configuration_error' => true, 'message' => 'ระบบรับซองยังไม่ได้ตั้งค่า Dokmai API key'];
    }

    $idempotencyKey = trueMoneyDokmaiIdempotencyKey($userId, $voucherHash);
    if ($idempotencyKey === '') {
        if ($debug !== null && function_exists('trueMoneyDebugError')) {
            trueMoneyDebugError($debug, 'provider_preflight_failed', 'idempotency_key_invalid', 'Unable to derive Dokmai idempotency key');
        }
        return ['success' => false, 'code' => 'idempotency_key_invalid', 'configuration_error' => true, 'message' => 'ไม่สามารถเตรียมรหัสป้องกันรายการซ้ำได้'];
    }

    $token = (string) $normalized['token'];
    if ($debug !== null) {
        trueMoneyDokmaiInitializeDebug($debug);
        if (function_exists('trueMoneyDebugEvent')) {
            trueMoneyDebugEvent($debug, 'provider_preflight_passed', [
                'provider' => trueMoneyDokmaiProviderName(),
                'endpoint' => 'https://api.dokmaistore.com/api/v1/payments/core/angpao/redeem',
                'method' => 'POST',
                'authorization_configured' => true,
                'idempotency_configured' => true,
            ]);
        }
        trueMoneyDokmaiDebugRequest($debug, $token, $mobile, $idempotencyKey);
    }

    $transport = $transport ?? 'trueMoneyDokmaiCurlRequest';
    $response = trueMoneyDokmaiRunTransport(
        $transport,
        (string) TM_DOKMAI_API_URL,
        ['phoneNumber' => $mobile, 'voucher' => (string) $normalized['url']],
        $apiKey,
        $idempotencyKey
    );
    if ($debug !== null) trueMoneyDokmaiDebugResponse($debug, $response);

    $httpCode = (int) ($response['http_code'] ?? 0);
    $transportFailed = empty($response['executed'])
        || (int) ($response['curl_errno'] ?? 0) !== 0
        || $httpCode === 0
        || !empty($response['response_too_large']);
    if ($transportFailed) {
        if ($debug !== null && function_exists('trueMoneyDebugError')) {
            trueMoneyDebugError($debug, 'provider_result_indeterminate', 'provider_indeterminate', 'Dokmai redeem request did not return a definitive HTTP response');
        }
        return [
            'success' => false,
            'code' => 'provider_indeterminate',
            'indeterminate' => true,
            'message' => 'ส่งคำขอรับซองแล้ว แต่ยังยืนยันผลไม่ได้ ครั้งถัดไประบบจะใช้ Idempotency-Key เดิมเพื่อป้องกันรายการซ้ำ',
        ];
    }

    $decoded = trueMoneyDokmaiDecode($response);
    $json = !empty($decoded['valid']) && is_array($decoded['json']) ? $decoded['json'] : null;

    if ($httpCode >= 500) {
        $error = is_array($json) ? trueMoneyDokmaiError($json) : ['code' => '', 'message' => '', 'retryable' => true, 'request_id' => ''];
        if ($debug !== null && function_exists('trueMoneyDebugError')) {
            trueMoneyDebugError($debug, 'provider_result_indeterminate', 'provider_indeterminate', 'Dokmai returned HTTP ' . $httpCode . ($error['code'] !== '' ? ' (' . $error['code'] . ')' : ''));
        }
        return [
            'success' => false,
            'code' => 'provider_indeterminate',
            'indeterminate' => true,
            'message' => 'ระบบรับซองขัดข้องระหว่างทำรายการและยังยืนยันผลไม่ได้ ครั้งถัดไปจะใช้รหัสรายการเดิมเพื่อป้องกันการรับซ้ำ',
        ];
    }

    if (!is_array($json)) {
        $isAmbiguous = $httpCode >= 200 && $httpCode < 300;
        if ($debug !== null && function_exists('trueMoneyDebugError')) {
            trueMoneyDebugError($debug, $isAmbiguous ? 'provider_result_indeterminate' : 'provider_failed', $isAmbiguous ? 'provider_indeterminate' : 'provider_invalid_response', 'Dokmai response body was not valid JSON');
        }
        return $isAmbiguous
            ? ['success' => false, 'code' => 'provider_indeterminate', 'indeterminate' => true, 'message' => 'ระบบรับซองตอบกลับไม่สมบูรณ์หลังส่งคำขอ ครั้งถัดไปจะใช้ Idempotency-Key เดิม']
            : ['success' => false, 'code' => 'provider_invalid_response', 'message' => 'ระบบรับซองตอบกลับในรูปแบบที่ไม่ถูกต้อง'];
    }

    $success = trueMoneyDokmaiBool($json['success'] ?? null);
    $error = trueMoneyDokmaiError($json);

    if ($success === true) {
        $amount = trueMoneyDokmaiAmount($json);
        if ($amount === null) {
            if ($debug !== null && function_exists('trueMoneyDebugError')) {
                trueMoneyDebugError($debug, 'provider_result_indeterminate', 'provider_indeterminate', 'Dokmai reported success without a safely extractable redeemed amount');
            }
            return [
                'success' => false,
                'code' => 'provider_indeterminate',
                'indeterminate' => true,
                'message' => 'รับซองอาจสำเร็จแล้ว แต่ยังอ่านยอดเงินจากผลตอบกลับไม่ได้ ระบบจะใช้ Idempotency-Key เดิมในการตรวจซ้ำ',
            ];
        }
        if ($debug !== null && function_exists('trueMoneyDebugEvent')) {
            trueMoneyDebugEvent($debug, 'provider_voucher_confirmed', [
                'provider' => trueMoneyDokmaiProviderName(),
                'amount_thb' => $amount,
                'provider_request_id' => $error['request_id'] !== '' ? trueMoneyDokmaiSafeText($error['request_id'], 160) : null,
            ]);
        }
        return ['success' => true, 'amount' => $amount, 'code' => 'success'];
    }

    if ($success === false || $httpCode >= 400) {
        $code = $error['code'] !== '' ? $error['code'] : 'provider_rejected';
        $message = trueMoneyDokmaiUserMessage($code, $error['message']);
        $isRetryable = $error['retryable'] === true;

        // An authenticated provider may explicitly tell us an error is retryable.
        // Persist as indeterminate so the next attempt reuses the same provider
        // Idempotency-Key rather than creating a logically new redemption.
        if ($isRetryable && $code !== 'rate_limit_exceeded' && $code !== 'rate_limited') {
            if ($debug !== null && function_exists('trueMoneyDebugError')) {
                trueMoneyDebugError($debug, 'provider_result_indeterminate', 'provider_indeterminate', $error['message'] !== '' ? $error['message'] : $code);
            }
            return [
                'success' => false,
                'code' => 'provider_indeterminate',
                'provider_code' => $code,
                'indeterminate' => true,
                'message' => $message !== '' ? $message : 'ระบบรับซองแจ้งว่าสามารถลองใหม่ได้ ครั้งถัดไปจะใช้ Idempotency-Key เดิม',
            ];
        }

        if ($debug !== null && function_exists('trueMoneyDebugError')) {
            trueMoneyDebugError($debug, 'provider_failed', 'dokmai_' . preg_replace('/[^a-z0-9_-]+/i', '_', $code), $error['message'] !== '' ? $error['message'] : $message);
        }
        return [
            'success' => false,
            'code' => $code,
            'message' => $message,
            'provider_message' => trueMoneyDokmaiSafeText($error['message'], 300),
            'provider_request_id' => $error['request_id'] !== '' ? trueMoneyDokmaiSafeText($error['request_id'], 160) : null,
        ];
    }

    if ($debug !== null && function_exists('trueMoneyDebugError')) {
        trueMoneyDebugError($debug, 'provider_result_indeterminate', 'provider_indeterminate', 'Dokmai returned an unrecognized 2xx response contract');
    }
    return [
        'success' => false,
        'code' => 'provider_indeterminate',
        'indeterminate' => true,
        'message' => 'ระบบรับซองตอบกลับไม่ชัดเจนหลังส่งคำขอ ครั้งถัดไปจะใช้ Idempotency-Key เดิมเพื่อป้องกันรายการซ้ำ',
    ];
}
