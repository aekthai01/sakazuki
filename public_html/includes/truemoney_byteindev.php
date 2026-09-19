<?php
/**
 * ByteInDev TrueMoney voucher provider adapter for SAKAZUKI.
 *
 * The hosted Go/NestJS/FastAPI backends expose the same contract and proxy the
 * current TrueMoney voucher redeem endpoint using browser-like transports.
 * SAKAZUKI probes backends before redeeming and selects the first healthy one.
 * It never switches to another backend after a redeem request has been sent.
 * Ambiguous transport/5xx/unknown-success results are persisted as
 * `indeterminate` so the same voucher is not fired again blindly.
 */

if (!defined('TM_BYTEINDEV_HEALTH_TIMEOUT')) {
    define('TM_BYTEINDEV_HEALTH_TIMEOUT', 8);
}
if (!defined('TM_BYTEINDEV_REDEEM_TIMEOUT')) {
    define('TM_BYTEINDEV_REDEEM_TIMEOUT', 35);
}
if (!defined('TM_BYTEINDEV_CONNECT_TIMEOUT')) {
    define('TM_BYTEINDEV_CONNECT_TIMEOUT', 8);
}
if (!defined('TM_BYTEINDEV_MAX_RESPONSE_BYTES')) {
    define('TM_BYTEINDEV_MAX_RESPONSE_BYTES', 1048576);
}

function trueMoneyByteIndevProviderName(): string
{
    return 'byteindev_failover';
}

function trueMoneyByteIndevProviderDisplayName(): string
{
    return 'ByteInDev TrueMoney (Go / NestJS / FastAPI)';
}

function trueMoneyByteIndevProviders(): array
{
    return [
        ['name' => 'go', 'base_url' => 'https://truemoney-voucher-go.vercel.app'],
        ['name' => 'nestjs', 'base_url' => 'https://truemoney-voucher-nestjs.vercel.app'],
        ['name' => 'fastapi', 'base_url' => 'https://truemoney-voucher-fastapi.vercel.app'],
    ];
}

function trueMoneyByteIndevSafeText($value, int $maxLength = 500): string
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

function trueMoneyByteIndevNormalizeVoucher($voucherUrl): array
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

function trueMoneyByteIndevInitializeDebug(array &$debug): void
{
    $backends = [];
    foreach (trueMoneyByteIndevProviders() as $provider) {
        $parts = parse_url((string) $provider['base_url']);
        $backends[] = [
            'name' => (string) $provider['name'],
            'host' => strtolower((string) ($parts['host'] ?? '')),
        ];
    }
    $debug['provider'] = [
        'name' => trueMoneyByteIndevProviderName(),
        'display_name' => trueMoneyByteIndevProviderDisplayName(),
        'selection_mode' => 'health_failover_before_redeem_only',
        'backends' => $backends,
        'health_attempts' => [],
        'selected_backend' => null,
        'request' => [],
        'transport' => [],
        'response' => [],
    ];
    if (isset($debug['privacy']) && is_array($debug['privacy'])) {
        $debug['privacy']['voucher_token_stored'] = false;
        $debug['privacy']['raw_provider_body_stored'] = false;
        $debug['privacy']['raw_provider_effective_url_stored'] = false;
        $debug['privacy']['credentials_redacted'] = true;
    }
}

function trueMoneyByteIndevCurlGet(string $url, int $timeoutSeconds): array
{
    $headers = [];
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
            'primary_ip' => '',
            'ssl_verify_result' => null,
            'timing' => [],
        ];
    }

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => (int) TM_BYTEINDEV_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT => max(1, $timeoutSeconds),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Cache-Control: no-cache, no-store, max-age=0',
            'Pragma: no-cache',
        ],
        CURLOPT_USERAGENT => 'SakazukiTrueMoney/5.0',
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
            $length = strlen($line);
            $trimmed = trim($line);
            if ($trimmed === '') return $length;
            if (stripos($trimmed, 'HTTP/') === 0) {
                $headers['status_line'] = $trimmed;
                return $length;
            }
            if (strpos($trimmed, ':') === false) return $length;
            [$name, $value] = explode(':', $trimmed, 2);
            $key = strtolower(trim($name));
            if (!in_array($key, ['content-type', 'retry-after', 'server', 'x-vercel-id'], true)) return $length;
            $headers[$key] = trueMoneyByteIndevSafeText(trim($value), 300);
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
    $tooLarge = strlen($bodyString) > (int) TM_BYTEINDEV_MAX_RESPONSE_BYTES;
    if ($tooLarge) $bodyString = '';

    return [
        'executed' => $body !== false,
        'http_code' => (int) ($info['http_code'] ?? 0),
        'curl_errno' => (int) $errno,
        'curl_error' => trueMoneyByteIndevSafeText($error, 300),
        'body' => $bodyString,
        'headers' => $headers,
        'response_too_large' => $tooLarge,
        'primary_ip' => trueMoneyByteIndevSafeText((string) ($info['primary_ip'] ?? ''), 80),
        'ssl_verify_result' => isset($info['ssl_verifyresult']) ? (int) $info['ssl_verifyresult'] : null,
        'timing' => [
            'dns_complete_ms' => isset($info['namelookup_time']) ? (int) round((float) $info['namelookup_time'] * 1000) : null,
            'tcp_connect_complete_ms' => isset($info['connect_time']) ? (int) round((float) $info['connect_time'] * 1000) : null,
            'tls_complete_ms' => isset($info['appconnect_time']) ? (int) round((float) $info['appconnect_time'] * 1000) : null,
            'ttfb_complete_ms' => isset($info['starttransfer_time']) ? (int) round((float) $info['starttransfer_time'] * 1000) : null,
            'total_ms' => isset($info['total_time']) ? (int) round((float) $info['total_time'] * 1000) : $wallMs,
            'wall_clock_ms' => $wallMs,
        ],
        'download_bytes_curl' => isset($info['size_download']) ? (int) round((float) $info['size_download']) : strlen($bodyString),
    ];
}

function trueMoneyByteIndevRunTransport(callable $transport, string $url, int $timeoutSeconds): array
{
    try {
        $result = $transport($url, $timeoutSeconds);
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
            'curl_error' => trueMoneyByteIndevSafeText($e->getMessage(), 300),
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

function trueMoneyByteIndevTransportSucceeded(array $response): bool
{
    $http = (int) ($response['http_code'] ?? 0);
    return !empty($response['executed'])
        && (int) ($response['curl_errno'] ?? 0) === 0
        && empty($response['response_too_large'])
        && $http >= 200
        && $http < 300;
}

function trueMoneyByteIndevSelectProvider(?array &$debug = null, ?callable $healthTransport = null): array
{
    $transport = $healthTransport ?? 'trueMoneyByteIndevCurlGet';
    $attempts = [];
    foreach (trueMoneyByteIndevProviders() as $provider) {
        $baseUrl = rtrim((string) $provider['base_url'], '/');
        $response = trueMoneyByteIndevRunTransport(
            $transport,
            $baseUrl . '/status',
            (int) TM_BYTEINDEV_HEALTH_TIMEOUT
        );
        $attempt = [
            'backend' => (string) $provider['name'],
            'http_code' => (int) ($response['http_code'] ?? 0),
            'curl_errno' => (int) ($response['curl_errno'] ?? 0),
            'total_ms' => (int) (($response['timing']['total_ms'] ?? 0)),
            'healthy' => trueMoneyByteIndevTransportSucceeded($response),
        ];
        $attempts[] = $attempt;
        if ($debug !== null) {
            $debug['provider']['health_attempts'][] = $attempt;
            if (function_exists('trueMoneyDebugEvent')) {
                trueMoneyDebugEvent($debug, 'provider_health_checked', $attempt);
            }
        }
        if ($attempt['healthy']) {
            return ['success' => true, 'provider' => $provider, 'attempts' => $attempts];
        }
    }
    return ['success' => false, 'provider' => null, 'attempts' => $attempts];
}

function trueMoneyByteIndevDecode(array $response): array
{
    $body = (string) ($response['body'] ?? '');
    if ($body === '') return ['valid' => false, 'json' => null, 'error' => 'empty response body'];
    $json = json_decode($body, true);
    if (!is_array($json) || json_last_error() !== JSON_ERROR_NONE) {
        return ['valid' => false, 'json' => null, 'error' => json_last_error_msg()];
    }
    return ['valid' => true, 'json' => $json, 'error' => null];
}

function trueMoneyByteIndevStatus(array $json): array
{
    $status = isset($json['status']) && is_array($json['status']) ? $json['status'] : [];
    $code = isset($status['code']) && is_scalar($status['code']) ? strtoupper(trim((string) $status['code'])) : '';
    $message = isset($status['message']) && is_scalar($status['message']) ? trim((string) $status['message']) : '';
    return ['code' => $code, 'message' => $message];
}

function trueMoneyByteIndevAmount(array $json): ?float
{
    $value = $json['data']['my_ticket']['amount_baht'] ?? null;
    if (!is_scalar($value) || !is_numeric((string) $value)) return null;
    $amount = round((float) $value, 2, PHP_ROUND_HALF_UP);
    if (!is_finite($amount) || $amount <= 0 || $amount > 1000000) return null;
    return $amount;
}

function trueMoneyByteIndevGatewayError(array $json): array
{
    $code = $json['code'] ?? null;
    $message = $json['message'] ?? null;
    return [
        'code' => is_scalar($code) ? trim((string) $code) : '',
        'message' => is_scalar($message) ? trim((string) $message) : '',
    ];
}

function trueMoneyByteIndevUserMessage(string $code, string $fallback = ''): string
{
    $messages = [
        'VOUCHER_NOT_FOUND' => 'ไม่พบซองของขวัญนี้ หรือรหัสซองไม่ถูกต้อง',
        'VOUCHER_OUT_OF_STOCK' => 'ซองของขวัญนี้ถูกรับครบแล้ว',
        'VOUCHER_EXPIRED' => 'ซองของขวัญหมดอายุแล้ว',
        'CANNOT_GET_OWN_VOUCHER' => 'ไม่สามารถรับซองของขวัญของตัวเองได้',
        'TARGET_USER_NOT_FOUND' => 'ไม่พบบัญชี TrueMoney ของเบอร์รับเงินนี้',
        'TARGET_USER_REDEEMED' => 'เบอร์รับเงินนี้เคยรับซองนี้แล้ว',
        'TARGET_USER_STATUS_INACTIVE' => 'บัญชี TrueMoney ของผู้รับไม่พร้อมใช้งาน',
        'INTERNAL_ERROR' => 'TrueMoney ปฏิเสธรายการหรือข้อมูลซองไม่ถูกต้อง',
        'provider_rate_limited' => 'ระบบรับซองจำกัดการเรียกใช้งานชั่วคราว กรุณารอสักครู่',
        'provider_unavailable' => 'ระบบรับซองทุกช่องทางไม่พร้อมใช้งานชั่วคราว กรุณาลองใหม่ภายหลัง',
    ];
    if (isset($messages[$code])) return $messages[$code];
    $fallback = trueMoneyByteIndevSafeText($fallback, 240);
    return $fallback !== '' ? $fallback : 'ไม่สามารถรับซองของขวัญได้';
}

function trueMoneyByteIndevPendingState(string $voucherHash, int $userId): array
{
    global $conn;
    if ($userId < 1 || preg_match('/^[a-f0-9]{64}$/D', $voucherHash) !== 1 || !isset($conn) || !($conn instanceof mysqli)) {
        return ['pending' => false];
    }
    if (function_exists('ensureTrueMoneyRedemptionsTable') && !ensureTrueMoneyRedemptionsTable()) {
        return ['pending' => false];
    }
    try {
        $stmt = $conn->prepare('SELECT id, status, updated_at FROM truemoney_redemptions WHERE voucher_hash=? AND user_id=? LIMIT 1');
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

function trueMoneyByteIndevMarkIndeterminate(int $redemptionId, int $userId, string $message, ?array &$debug = null): bool
{
    global $conn;
    if ($redemptionId < 1 || $userId < 1 || !isset($conn) || !($conn instanceof mysqli)) return false;
    $message = trueMoneyByteIndevSafeText($message, 240);
    if ($message === '') $message = 'TrueMoney redemption result is indeterminate';
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
        if (function_exists('trueMoneyDebugCaptureState')) {
            trueMoneyDebugCaptureState($debug, $redemptionId, $userId);
        }
    }
    return $ok;
}

function trueMoneyByteIndevDebugRedeem(array &$debug, array $provider, string $token, string $mobile, array $response, ?array $json): void
{
    $parts = parse_url((string) $provider['base_url']);
    $status = is_array($json) ? trueMoneyByteIndevStatus($json) : ['code' => '', 'message' => ''];
    $gateway = is_array($json) ? trueMoneyByteIndevGatewayError($json) : ['code' => '', 'message' => ''];
    $debug['provider']['selected_backend'] = (string) $provider['name'];
    $debug['provider']['target'] = [
        'scheme' => 'https',
        'host' => strtolower((string) ($parts['host'] ?? '')),
        'port' => 443,
        'path' => '/truemoney/{voucher}/{mobile}',
        'sensitive_path_redacted' => true,
    ];
    $debug['provider']['request'] = [
        'method' => 'GET',
        'voucher_fingerprint' => substr(hash('sha256', $token), 0, 16),
        'receiver_phone_masked' => function_exists('trueMoneyDebugMaskedPhone') ? trueMoneyDebugMaskedPhone($mobile) : '***',
        'automatic_retry_after_redeem' => false,
    ];
    $debug['provider']['transport'] = [
        'executed' => !empty($response['executed']),
        'http_code' => (int) ($response['http_code'] ?? 0),
        'curl_errno' => (int) ($response['curl_errno'] ?? 0),
        'curl_error' => trueMoneyByteIndevSafeText((string) ($response['curl_error'] ?? ''), 300),
        'primary_ip' => trueMoneyByteIndevSafeText((string) ($response['primary_ip'] ?? ''), 80),
        'ssl_verify_result' => $response['ssl_verify_result'] ?? null,
        'timing' => is_array($response['timing'] ?? null) ? $response['timing'] : [],
        'response_too_large' => !empty($response['response_too_large']),
    ];
    $debug['provider']['response'] = [
        'json_valid' => is_array($json),
        'top_level_keys' => is_array($json) ? array_slice(array_keys($json), 0, 20) : [],
        'status_code' => $status['code'],
        'status_message' => trueMoneyByteIndevSafeText($status['message'], 240),
        'gateway_code' => trueMoneyByteIndevSafeText($gateway['code'], 80),
        'body_bytes' => strlen((string) ($response['body'] ?? '')),
        'headers' => is_array($response['headers'] ?? null) ? $response['headers'] : [],
    ];
}

function redeemAngpaoByteIndev(
    string $voucherUrl,
    string $mobile,
    ?array &$debug = null,
    ?callable $healthTransport = null,
    ?callable $redeemTransport = null
): array {
    $normalized = trueMoneyByteIndevNormalizeVoucher($voucherUrl);
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

    if ($debug !== null) {
        trueMoneyByteIndevInitializeDebug($debug);
    }

    $selection = trueMoneyByteIndevSelectProvider($debug, $healthTransport);
    if (empty($selection['success']) || !is_array($selection['provider'])) {
        if ($debug !== null && function_exists('trueMoneyDebugError')) {
            trueMoneyDebugError($debug, 'provider_preflight_failed', 'provider_unavailable', 'No ByteInDev TrueMoney backend passed the health check');
        }
        return [
            'success' => false,
            'code' => 'provider_unavailable',
            'message' => trueMoneyByteIndevUserMessage('provider_unavailable'),
        ];
    }

    $provider = $selection['provider'];
    $token = (string) $normalized['token'];
    $baseUrl = rtrim((string) $provider['base_url'], '/');
    $url = $baseUrl . '/truemoney/' . rawurlencode($token) . '/' . rawurlencode($mobile);

    if ($debug !== null && function_exists('trueMoneyDebugEvent')) {
        trueMoneyDebugEvent($debug, 'provider_selected', [
            'backend' => (string) $provider['name'],
            'host' => (string) (parse_url($baseUrl, PHP_URL_HOST) ?: ''),
            'automatic_failover_after_redeem' => false,
        ]);
    }

    $transport = $redeemTransport ?? 'trueMoneyByteIndevCurlGet';
    $response = trueMoneyByteIndevRunTransport($transport, $url, (int) TM_BYTEINDEV_REDEEM_TIMEOUT);
    $decoded = trueMoneyByteIndevDecode($response);
    $json = !empty($decoded['valid']) && is_array($decoded['json']) ? $decoded['json'] : null;
    if ($debug !== null) {
        trueMoneyByteIndevDebugRedeem($debug, $provider, $token, $mobile, $response, $json);
    }

    $httpCode = (int) ($response['http_code'] ?? 0);
    $transportFailed = empty($response['executed'])
        || (int) ($response['curl_errno'] ?? 0) !== 0
        || $httpCode === 0
        || !empty($response['response_too_large']);
    if ($transportFailed) {
        if ($debug !== null && function_exists('trueMoneyDebugError')) {
            trueMoneyDebugError($debug, 'provider_result_indeterminate', 'provider_indeterminate', 'Selected backend redeem request did not return a definitive HTTP response');
        }
        return [
            'success' => false,
            'code' => 'provider_indeterminate',
            'indeterminate' => true,
            'message' => 'ส่งคำขอรับซองแล้ว แต่ยังยืนยันผลไม่ได้ ระบบหยุดการยิงซ้ำอัตโนมัติเพื่อป้องกันรายการซ้ำ กรุณาติดต่อผู้ดูแล',
        ];
    }

    if ($httpCode === 429) {
        if ($debug !== null && function_exists('trueMoneyDebugError')) {
            trueMoneyDebugError($debug, 'provider_failed', 'provider_rate_limited', 'Selected backend returned HTTP 429');
        }
        return ['success' => false, 'code' => 'provider_rate_limited', 'message' => trueMoneyByteIndevUserMessage('provider_rate_limited')];
    }

    if ($httpCode >= 500) {
        if ($debug !== null && function_exists('trueMoneyDebugError')) {
            trueMoneyDebugError($debug, 'provider_result_indeterminate', 'provider_indeterminate', 'Selected backend returned HTTP ' . $httpCode . ' after redeem request');
        }
        return [
            'success' => false,
            'code' => 'provider_indeterminate',
            'indeterminate' => true,
            'message' => 'ระบบรับซองขัดข้องระหว่างทำรายการและยังยืนยันผลไม่ได้ ระบบหยุดการยิงซ้ำไว้เพื่อป้องกันรายการซ้ำ',
        ];
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $gateway = is_array($json) ? trueMoneyByteIndevGatewayError($json) : ['code' => '', 'message' => ''];
        $message = trueMoneyByteIndevSafeText($gateway['message'], 240);
        if ($message === '') $message = 'ระบบรับซองปฏิเสธคำขอ (HTTP ' . $httpCode . ')';
        if ($debug !== null && function_exists('trueMoneyDebugError')) {
            trueMoneyDebugError($debug, 'provider_failed', 'provider_http_' . $httpCode, $message);
        }
        return ['success' => false, 'code' => 'provider_http_' . $httpCode, 'message' => $message];
    }

    if (!is_array($json)) {
        if ($debug !== null && function_exists('trueMoneyDebugError')) {
            trueMoneyDebugError($debug, 'provider_result_indeterminate', 'provider_indeterminate', 'Selected backend returned 2xx with invalid JSON');
        }
        return [
            'success' => false,
            'code' => 'provider_indeterminate',
            'indeterminate' => true,
            'message' => 'ระบบรับซองตอบกลับไม่สมบูรณ์หลังส่งคำขอ เพื่อป้องกันรายการซ้ำระบบจะไม่ยิงซ้ำอัตโนมัติ',
        ];
    }

    $status = trueMoneyByteIndevStatus($json);
    if ($status['code'] === 'SUCCESS') {
        $amount = trueMoneyByteIndevAmount($json);
        if ($amount === null) {
            if ($debug !== null && function_exists('trueMoneyDebugError')) {
                trueMoneyDebugError($debug, 'provider_result_indeterminate', 'provider_indeterminate', 'TrueMoney reported SUCCESS without data.my_ticket.amount_baht');
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
                'backend' => (string) $provider['name'],
                'amount_thb' => $amount,
            ]);
        }
        return [
            'success' => true,
            'amount' => $amount,
            'code' => 'SUCCESS',
            'backend' => (string) $provider['name'],
        ];
    }

    if ($status['code'] !== '') {
        $message = trueMoneyByteIndevUserMessage($status['code'], $status['message']);
        if ($debug !== null && function_exists('trueMoneyDebugError')) {
            trueMoneyDebugError($debug, 'provider_failed', 'truemoney_' . strtolower($status['code']), $status['message'] !== '' ? $status['message'] : $message);
        }
        return [
            'success' => false,
            'code' => $status['code'],
            'message' => $message,
            'provider_message' => trueMoneyByteIndevSafeText($status['message'], 300),
        ];
    }

    $gateway = trueMoneyByteIndevGatewayError($json);
    if ($gateway['code'] !== '') {
        $numericCode = ctype_digit($gateway['code']) ? (int) $gateway['code'] : 0;
        if ($numericCode >= 500) {
            if ($debug !== null && function_exists('trueMoneyDebugError')) {
                trueMoneyDebugError($debug, 'provider_result_indeterminate', 'provider_indeterminate', 'Backend reported internal failure after redeem request: ' . $gateway['code']);
            }
            return [
                'success' => false,
                'code' => 'provider_indeterminate',
                'indeterminate' => true,
                'message' => 'ระบบรับซองแจ้งข้อผิดพลาดหลังส่งคำขอและยังยืนยันผลไม่ได้ ระบบหยุดการยิงซ้ำไว้ก่อน',
            ];
        }
        $message = trueMoneyByteIndevSafeText($gateway['message'], 240);
        if ($message === '') $message = 'ระบบรับซองปฏิเสธข้อมูลที่ส่งไป';
        return ['success' => false, 'code' => 'provider_' . $gateway['code'], 'message' => $message];
    }

    if ($debug !== null && function_exists('trueMoneyDebugError')) {
        trueMoneyDebugError($debug, 'provider_result_indeterminate', 'provider_indeterminate', 'Selected backend returned 2xx without recognizable TrueMoney status');
    }
    return [
        'success' => false,
        'code' => 'provider_indeterminate',
        'indeterminate' => true,
        'message' => 'ระบบรับซองตอบกลับไม่ชัดเจนหลังส่งคำขอ ระบบจะไม่ยิงซ้ำอัตโนมัติเพื่อป้องกันรายการซ้ำ',
    ];
}
