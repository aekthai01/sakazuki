<?php
/**
 * Direct TrueMoney Angpao transport for SAKAZUKI.
 *
 * This adapter talks to gift.truemoney.com directly. It intentionally does not
 * auto-retry a redeem POST after an ambiguous network/server failure. Instead it
 * performs a read-only verify request and, when the recipient ticket can be
 * matched exactly, reconciles the successful redemption. Otherwise the local
 * redemption is parked as indeterminate so a duplicate redeem is not fired.
 */

if (!defined('TM_DIRECT_API_URL')) {
    define('TM_DIRECT_API_URL', 'https://gift.truemoney.com');
}
if (!defined('TM_DIRECT_CONNECT_TIMEOUT')) {
    define('TM_DIRECT_CONNECT_TIMEOUT', 10);
}
if (!defined('TM_DIRECT_TOTAL_TIMEOUT')) {
    define('TM_DIRECT_TOTAL_TIMEOUT', 20);
}
if (!defined('TM_DIRECT_MAX_RESPONSE_BYTES')) {
    define('TM_DIRECT_MAX_RESPONSE_BYTES', 1048576);
}

function trueMoneyDirectProviderName(): string
{
    return 'truemoney_direct';
}

function trueMoneyDirectProviderDisplayName(): string
{
    return 'TrueMoney Direct / gift.truemoney.com';
}

function trueMoneyDirectApiUrl(string $path): string
{
    return rtrim((string) TM_DIRECT_API_URL, '/') . '/' . ltrim($path, '/');
}

function trueMoneyDirectNormalizeVoucher($voucherUrl): array
{
    if (function_exists('normalizeTrueMoneyVoucherUrl')) {
        $normalized = normalizeTrueMoneyVoucherUrl($voucherUrl);
        if (!empty($normalized['success'])) {
            return $normalized;
        }
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
    if ($token === '' || preg_match('/^[A-Za-z0-9]+$/D', $token) !== 1) {
        return ['success' => false, 'message' => 'รหัสซองของขวัญไม่ถูกต้อง'];
    }
    return [
        'success' => true,
        'url' => 'https://gift.truemoney.com/campaign/?v=' . rawurlencode($token),
        'token' => $token,
    ];
}

function trueMoneyDirectSafeMessage($value, int $max = 500): string
{
    $text = is_scalar($value) ? trim((string) $value) : '';
    $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? '';
    if (function_exists('trueMoneyDebugSafeText')) {
        return trueMoneyDebugSafeText($text, $max);
    }
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $max, 'UTF-8');
    }
    return substr($text, 0, $max);
}

function trueMoneyDirectErrorMessage(string $code, string $fallback = ''): string
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
        'INTERNAL_ERROR' => 'TrueMoney ขัดข้องชั่วคราว กรุณาลองใหม่ภายหลัง',
        'MAINTENANCE' => 'TrueMoney อยู่ระหว่างปิดปรับปรุง กรุณาลองใหม่ภายหลัง',
        'REQUEST_BLOCKED' => 'คำขอจากเซิร์ฟเวอร์ถูกปฏิเสธโดยระบบ TrueMoney',
        'RATE_LIMITED' => 'TrueMoney จำกัดการเรียกใช้งานชั่วคราว กรุณารอสักครู่',
        'INVALID_RESPONSE' => 'TrueMoney ตอบกลับในรูปแบบที่ระบบไม่รู้จัก',
        'NETWORK_ERROR' => 'ไม่สามารถเชื่อมต่อ TrueMoney ได้ชั่วคราว',
        'TIMEOUT' => 'TrueMoney ตอบกลับช้ากว่าเวลาที่กำหนด',
    ];
    if (isset($messages[$code])) {
        return $messages[$code];
    }
    return $fallback !== '' ? trueMoneyDirectSafeMessage($fallback) : 'ไม่สามารถรับซองของขวัญได้';
}

function trueMoneyDirectInitializeDebug(array &$debug): void
{
    $target = [
        'scheme' => 'https',
        'host' => 'gift.truemoney.com',
        'port' => 443,
        'path' => '',
        'query_present' => false,
        'path_segment_count' => 0,
        'sensitive_path_redacted' => false,
    ];
    $debug['provider'] = [
        'name' => trueMoneyDirectProviderName(),
        'target' => $target,
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

function trueMoneyDirectCurlRequest(string $method, string $url, ?array $jsonBody = null, array $headers = []): array
{
    $method = strtoupper($method);
    $responseHeaders = [];
    $headerNames = [];
    $ch = curl_init();
    if ($ch === false) {
        return ['executed' => false, 'http_code' => 0, 'curl_errno' => -1, 'curl_error' => 'curl_init failed', 'body' => '', 'headers' => [], 'header_names' => [], 'timing' => [], 'response_too_large' => false];
    }

    $requestHeaders = array_values(array_filter(array_merge([
        'Accept: application/json',
        'Cache-Control: no-cache, no-store, max-age=0',
        'Pragma: no-cache',
    ], $headers)));
    $encodedBody = null;
    if ($jsonBody !== null) {
        $encodedBody = json_encode($jsonBody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encodedBody)) {
            curl_close($ch);
            return ['executed' => false, 'http_code' => 0, 'curl_errno' => -2, 'curl_error' => 'json_encode failed', 'body' => '', 'headers' => [], 'header_names' => [], 'timing' => [], 'response_too_large' => false];
        }
        $requestHeaders[] = 'Content-Type: application/json';
    }

    $options = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => (int) TM_DIRECT_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT => (int) TM_DIRECT_TOTAL_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $requestHeaders,
        CURLOPT_USERAGENT => 'SakazukiTrueMoneyDirect/3.0',
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders, &$headerNames): int {
            $length = strlen($line);
            $trimmed = trim($line);
            if ($trimmed === '' || strpos($trimmed, ':') === false) {
                if (stripos($trimmed, 'HTTP/') === 0) $responseHeaders['status_line'] = $trimmed;
                return $length;
            }
            [$name, $value] = explode(':', $trimmed, 2);
            $key = strtolower(trim($name));
            $value = trim($value);
            if ($key === '') return $length;
            $headerNames[$key] = true;
            if (isset($responseHeaders[$key])) {
                $responseHeaders[$key] = is_array($responseHeaders[$key]) ? array_merge($responseHeaders[$key], [$value]) : [$responseHeaders[$key], $value];
            } else {
                $responseHeaders[$key] = $value;
            }
            return $length;
        },
    ];
    if ($encodedBody !== null) $options[CURLOPT_POSTFIELDS] = $encodedBody;
    curl_setopt_array($ch, $options);

    $wallStart = microtime(true);
    $body = curl_exec($ch);
    $wallMs = (int) round((microtime(true) - $wallStart) * 1000);
    $curlErrno = curl_errno($ch);
    $curlError = curl_error($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);

    $bodyString = is_string($body) ? $body : '';
    $tooLarge = strlen($bodyString) > (int) TM_DIRECT_MAX_RESPONSE_BYTES;
    if ($tooLarge) $bodyString = '';

    return [
        'executed' => $body !== false,
        'http_code' => (int) ($info['http_code'] ?? 0),
        'curl_errno' => (int) $curlErrno,
        'curl_error' => trueMoneyDirectSafeMessage($curlError, 300),
        'body' => $bodyString,
        'headers' => $responseHeaders,
        'header_names' => array_keys($headerNames),
        'response_too_large' => $tooLarge,
        'timing' => [
            'dns_complete_ms' => isset($info['namelookup_time']) ? (int) round(((float) $info['namelookup_time']) * 1000) : null,
            'tcp_connect_complete_ms' => isset($info['connect_time']) ? (int) round(((float) $info['connect_time']) * 1000) : null,
            'tls_complete_ms' => isset($info['appconnect_time']) ? (int) round(((float) $info['appconnect_time']) * 1000) : null,
            'ttfb_complete_ms' => isset($info['starttransfer_time']) ? (int) round(((float) $info['starttransfer_time']) * 1000) : null,
            'total_ms' => isset($info['total_time']) ? (int) round(((float) $info['total_time']) * 1000) : $wallMs,
            'wall_clock_ms' => $wallMs,
        ],
        'primary_ip' => trueMoneyDirectSafeMessage((string) ($info['primary_ip'] ?? ''), 80),
        'ssl_verify_result' => isset($info['ssl_verifyresult']) ? (int) $info['ssl_verifyresult'] : null,
        'download_bytes_curl' => isset($info['size_download']) ? (int) round((float) $info['size_download']) : strlen($bodyString),
    ];
}

function trueMoneyDirectRunTransport(callable $transport, string $method, string $url, ?array $jsonBody = null, array $headers = []): array
{
    try {
        $result = $transport($method, $url, $jsonBody, $headers);
        if (!is_array($result)) throw new RuntimeException('transport returned invalid result');
        return array_merge(['executed' => true, 'http_code' => 0, 'curl_errno' => 0, 'curl_error' => '', 'body' => '', 'headers' => [], 'header_names' => [], 'timing' => [], 'response_too_large' => false, 'primary_ip' => '', 'ssl_verify_result' => null, 'download_bytes_curl' => 0], $result);
    } catch (Throwable $e) {
        return ['executed' => false, 'http_code' => 0, 'curl_errno' => -4, 'curl_error' => trueMoneyDirectSafeMessage($e->getMessage(), 300), 'body' => '', 'headers' => [], 'header_names' => [], 'timing' => [], 'response_too_large' => false, 'primary_ip' => '', 'ssl_verify_result' => null, 'download_bytes_curl' => 0];
    }
}

function trueMoneyDirectDecode(array $transport): array
{
    $body = (string) ($transport['body'] ?? '');
    if ($body === '') return ['valid' => false, 'json' => null, 'error' => 'empty response body'];
    $json = json_decode($body, true);
    if (!is_array($json) || json_last_error() !== JSON_ERROR_NONE) return ['valid' => false, 'json' => null, 'error' => json_last_error_msg()];
    return ['valid' => true, 'json' => $json, 'error' => null];
}

function trueMoneyDirectStatus(array $json): array
{
    $status = isset($json['status']) && is_array($json['status']) ? $json['status'] : [];
    $code = isset($status['code']) && is_scalar($status['code']) ? strtoupper(trim((string) $status['code'])) : '';
    $message = isset($status['message']) && is_scalar($status['message']) ? trim((string) $status['message']) : '';
    return ['code' => $code, 'message' => $message];
}

function trueMoneyDirectAmountFromRedeem(array $json): ?float
{
    $amount = $json['data']['my_ticket']['amount_baht'] ?? null;
    if (!is_scalar($amount) || !is_numeric((string) $amount)) return null;
    $value = round((float) $amount, 2, PHP_ROUND_HALF_UP);
    if (!is_finite($value) || $value <= 0 || $value > 1000000) return null;
    return $value;
}

function trueMoneyDirectIsMaintenance(array $json): bool
{
    $status = trueMoneyDirectStatus($json);
    if ($status['code'] === 'MAINTENANCE') return true;
    $ma = $json['data']['ma'] ?? null;
    if (!is_array($ma)) return false;
    foreach (['title_th', 'title_en', 'message_th', 'message_en'] as $key) {
        if (isset($ma[$key]) && is_scalar($ma[$key]) && trim((string) $ma[$key]) !== '') return true;
    }
    return false;
}

function trueMoneyDirectFindReceiverTicket(array $json, string $phone): ?array
{
    $phone = preg_replace('/\D+/', '', $phone) ?? '';
    if ($phone === '') return null;
    $candidates = [];
    if (isset($json['data']['my_ticket']) && is_array($json['data']['my_ticket'])) $candidates[] = $json['data']['my_ticket'];
    if (isset($json['data']['tickets']) && is_array($json['data']['tickets'])) {
        foreach ($json['data']['tickets'] as $ticket) if (is_array($ticket)) $candidates[] = $ticket;
    }
    foreach ($candidates as $ticket) {
        $ticketPhoneRaw = $ticket['mobile'] ?? null;
        $ticketPhone = is_scalar($ticketPhoneRaw) ? (preg_replace('/\D+/', '', (string) $ticketPhoneRaw) ?? '') : '';
        if ($ticketPhone === '' || !hash_equals($phone, $ticketPhone)) continue;
        $amountRaw = $ticket['amount_baht'] ?? null;
        if (!is_scalar($amountRaw) || !is_numeric((string) $amountRaw)) continue;
        $amount = round((float) $amountRaw, 2, PHP_ROUND_HALF_UP);
        if (!is_finite($amount) || $amount <= 0 || $amount > 1000000) continue;
        return ['amount' => $amount, 'matched_by' => 'ticket.mobile'];
    }
    $redeemerPhoneRaw = $json['data']['redeemer_profile']['mobile_number'] ?? null;
    $myTicketAmount = $json['data']['my_ticket']['amount_baht'] ?? null;
    if (is_scalar($redeemerPhoneRaw) && is_scalar($myTicketAmount) && is_numeric((string) $myTicketAmount)) {
        $redeemerPhone = preg_replace('/\D+/', '', (string) $redeemerPhoneRaw) ?? '';
        $amount = round((float) $myTicketAmount, 2, PHP_ROUND_HALF_UP);
        if ($redeemerPhone !== '' && hash_equals($phone, $redeemerPhone) && is_finite($amount) && $amount > 0 && $amount <= 1000000) return ['amount' => $amount, 'matched_by' => 'redeemer_profile.mobile_number'];
    }
    return null;
}

function trueMoneyDirectDebugRequest(array &$debug, string $phase, string $method, string $token, string $phone): void
{
    if (!isset($debug['provider']) || !is_array($debug['provider'])) trueMoneyDirectInitializeDebug($debug);
    $debug['provider']['request'] = [
        'provider' => trueMoneyDirectProviderName(), 'phase' => $phase, 'method' => strtoupper($method),
        'receiver_phone_masked' => function_exists('trueMoneyDebugMaskedPhone') ? trueMoneyDebugMaskedPhone($phone) : str_repeat('*', 6) . substr($phone, -4),
        'voucher_fingerprint' => substr(hash('sha256', $token), 0, 16), 'voucher_token_length' => strlen($token),
        'authorization_configured' => false, 'connect_timeout_seconds' => (int) TM_DIRECT_CONNECT_TIMEOUT,
        'total_timeout_seconds' => (int) TM_DIRECT_TOTAL_TIMEOUT, 'tls_verify_peer' => true, 'tls_verify_host' => true,
        'follow_redirects' => false, 'automatic_retry' => false,
        'request_url' => ['scheme' => 'https', 'host' => 'gift.truemoney.com', 'port' => 443, 'path' => '/campaign/vouchers/' . ($phase === 'configuration' ? 'configuration' : '[VOUCHER_REDACTED]/' . $phase), 'query_present' => false, 'sensitive_path_redacted' => $phase !== 'configuration'],
        'full_request_url_stored' => false,
    ];
    if (function_exists('trueMoneyDebugEvent')) trueMoneyDebugEvent($debug, 'provider_request_started', ['provider' => trueMoneyDirectProviderName(), 'phase' => $phase, 'method' => strtoupper($method)]);
}

function trueMoneyDirectDebugTransport(array &$debug, array $transport, string $phase): void
{
    $decoded = trueMoneyDirectDecode($transport);
    $json = $decoded['valid'] ? $decoded['json'] : null;
    $status = is_array($json) ? trueMoneyDirectStatus($json) : ['code' => '', 'message' => ''];
    $amount = is_array($json) ? trueMoneyDirectAmountFromRedeem($json) : null;
    $headers = isset($transport['headers']) && is_array($transport['headers']) ? $transport['headers'] : [];
    $debug['provider']['transport'] = ['executed' => !empty($transport['executed']), 'curl_errno' => (int) ($transport['curl_errno'] ?? 0), 'curl_error' => trueMoneyDirectSafeMessage((string) ($transport['curl_error'] ?? ''), 300), 'response_too_large' => !empty($transport['response_too_large']), 'http_code' => (int) ($transport['http_code'] ?? 0), 'primary_ip' => trueMoneyDirectSafeMessage((string) ($transport['primary_ip'] ?? ''), 80), 'ssl_verify_result' => $transport['ssl_verify_result'] ?? null, 'timing' => isset($transport['timing']) && is_array($transport['timing']) ? $transport['timing'] : [], 'download_bytes_curl' => (int) ($transport['download_bytes_curl'] ?? strlen((string) ($transport['body'] ?? '')))];
    $debug['provider']['response'] = [
        'phase' => $phase,
        'headers' => [
            'status_line' => isset($headers['status_line']) ? trueMoneyDirectSafeMessage((string) $headers['status_line'], 160) : null,
            'content-type' => isset($headers['content-type']) ? trueMoneyDirectSafeMessage(is_array($headers['content-type']) ? implode(', ', $headers['content-type']) : (string) $headers['content-type'], 160) : null,
            'retry-after' => isset($headers['retry-after']) ? trueMoneyDirectSafeMessage(is_array($headers['retry-after']) ? implode(', ', $headers['retry-after']) : (string) $headers['retry-after'], 80) : null,
            'server' => isset($headers['server']) ? trueMoneyDirectSafeMessage(is_array($headers['server']) ? implode(', ', $headers['server']) : (string) $headers['server'], 100) : null,
            'cf-ray' => isset($headers['cf-ray']) ? trueMoneyDirectSafeMessage(is_array($headers['cf-ray']) ? implode(', ', $headers['cf-ray']) : (string) $headers['cf-ray'], 128) : null,
        ],
        'body_bytes' => strlen((string) ($transport['body'] ?? '')), 'body_sha256' => (string) ($transport['body'] ?? '') !== '' ? hash('sha256', (string) $transport['body']) : null,
        'json_valid' => (bool) $decoded['valid'], 'json_error' => $decoded['valid'] ? null : trueMoneyDirectSafeMessage((string) $decoded['error'], 200),
        'top_level_keys' => is_array($json) ? array_values(array_slice(array_map('strval', array_keys($json)), 0, 30)) : [],
        'provider_status_code' => $status['code'] !== '' ? $status['code'] : null, 'provider_message' => $status['message'] !== '' ? trueMoneyDirectSafeMessage($status['message'], 300) : null,
        'amount_thb' => $amount, 'raw_provider_body_stored' => false,
    ];
    if (function_exists('trueMoneyDebugEvent')) trueMoneyDebugEvent($debug, 'provider_response_received', ['provider' => trueMoneyDirectProviderName(), 'phase' => $phase, 'http_code' => (int) ($transport['http_code'] ?? 0), 'curl_errno' => (int) ($transport['curl_errno'] ?? 0), 'json_valid' => (bool) $decoded['valid'], 'provider_status_code' => $status['code'] !== '' ? $status['code'] : null]);
}

function trueMoneyDirectVerifyReceiver(string $token, string $phone, callable $transport, ?array &$debug = null): array
{
    $url = trueMoneyDirectApiUrl('/campaign/vouchers/' . rawurlencode($token) . '/verify');
    if ($debug !== null) trueMoneyDirectDebugRequest($debug, 'verify', 'GET', $token, $phone);
    $response = trueMoneyDirectRunTransport($transport, 'GET', $url, null, []);
    if ($debug !== null) trueMoneyDirectDebugTransport($debug, $response, 'verify');
    if (empty($response['executed']) || (int) ($response['curl_errno'] ?? 0) !== 0 || (int) ($response['http_code'] ?? 0) === 0 || !empty($response['response_too_large'])) return ['confirmed' => false, 'reason' => 'verify_transport_failed'];
    $decoded = trueMoneyDirectDecode($response);
    if (empty($decoded['valid']) || !is_array($decoded['json'])) return ['confirmed' => false, 'reason' => 'verify_invalid_response'];
    $ticket = trueMoneyDirectFindReceiverTicket($decoded['json'], $phone);
    if (!$ticket) return ['confirmed' => false, 'reason' => 'receiver_ticket_not_found'];
    return ['confirmed' => true, 'amount' => (float) $ticket['amount'], 'matched_by' => (string) $ticket['matched_by']];
}

function trueMoneyDirectMarkIndeterminate(int $redemptionId, int $userId, string $message, ?array &$debug = null): bool
{
    global $conn;
    if ($redemptionId < 1 || $userId < 1 || !isset($conn) || !($conn instanceof mysqli)) return false;
    $message = trueMoneyDirectSafeMessage($message, 240);
    if ($message === '') $message = 'TrueMoney redemption result is indeterminate';
    $status = 'indeterminate';
    $stmt = $conn->prepare("UPDATE truemoney_redemptions SET status=?, provider_message=?, updated_at=NOW() WHERE id=? AND user_id=? AND status<>'completed'");
    if (!$stmt) return false;
    $stmt->bind_param('ssii', $status, $message, $redemptionId, $userId);
    $ok = $stmt->execute();
    $affected = (int) $stmt->affected_rows;
    $stmt->close();
    if ($debug !== null && function_exists('trueMoneyDebugEvent')) {
        trueMoneyDebugEvent($debug, 'redemption_marked_indeterminate', ['affected_rows' => $affected, 'provider_message' => $message]);
        if (function_exists('trueMoneyDebugCaptureState')) trueMoneyDebugCaptureState($debug, $redemptionId, $userId);
    }
    return $ok;
}

function trueMoneyDirectReconcileIndeterminate(string $voucherHash, int $userId, string $voucherUrl, string $phone, ?array &$debug = null, ?callable $transport = null): array
{
    global $conn;
    if ($userId < 1 || !preg_match('/^[a-f0-9]{64}$/D', $voucherHash) || !isset($conn) || !($conn instanceof mysqli)) return ['state' => 'none'];
    $stmt = $conn->prepare('SELECT id,status FROM truemoney_redemptions WHERE voucher_hash=? AND user_id=? LIMIT 1');
    if (!$stmt) return ['state' => 'none'];
    $stmt->bind_param('si', $voucherHash, $userId);
    if (!$stmt->execute()) { $stmt->close(); return ['state' => 'none']; }
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    if (!$row || (string) ($row['status'] ?? '') !== 'indeterminate') return ['state' => 'none'];

    $normalized = trueMoneyDirectNormalizeVoucher($voucherUrl);
    if (empty($normalized['success'])) return ['state' => 'pending', 'message' => 'รายการก่อนหน้ายังรอตรวจสอบสถานะ'];
    $transport = $transport ?? 'trueMoneyDirectCurlRequest';
    if ($debug !== null && function_exists('trueMoneyDebugEvent')) trueMoneyDebugEvent($debug, 'indeterminate_reconciliation_started', ['redemption_id' => (int) $row['id']]);
    $verified = trueMoneyDirectVerifyReceiver((string) $normalized['token'], $phone, $transport, $debug);
    if (!empty($verified['confirmed']) && isset($verified['amount']) && function_exists('markTrueMoneyProviderConfirmed')) {
        $amount = (float) $verified['amount'];
        if (markTrueMoneyProviderConfirmed((int) $row['id'], $userId, $amount, $debug)) {
            if ($debug !== null && function_exists('trueMoneyDebugEvent')) trueMoneyDebugEvent($debug, 'indeterminate_reconciled', ['redemption_id' => (int) $row['id'], 'amount_thb' => $amount, 'matched_by' => (string) ($verified['matched_by'] ?? '')]);
            return ['state' => 'reconciled', 'amount' => $amount, 'redemption_id' => (int) $row['id']];
        }
    }
    if ($debug !== null && function_exists('trueMoneyDebugEvent')) trueMoneyDebugEvent($debug, 'indeterminate_still_pending', ['redemption_id' => (int) $row['id'], 'reason' => (string) ($verified['reason'] ?? 'not_confirmed')]);
    return ['state' => 'pending', 'message' => 'รายการรับซองก่อนหน้ายังยืนยันผลไม่ได้ เพื่อป้องกันรับซ้ำระบบจะไม่ยิงซองเดิมอีก กรุณารอสักครู่แล้วลองตรวจสอบลิงก์เดิมอีกครั้ง'];
}

function redeemAngpaoDirect(string $voucherUrl, string $mobile, ?array &$debug = null, ?callable $transport = null): array
{
    $normalized = trueMoneyDirectNormalizeVoucher($voucherUrl);
    if (empty($normalized['success'])) {
        if ($debug !== null && function_exists('trueMoneyDebugError')) trueMoneyDebugError($debug, 'provider_preflight_failed', 'voucher_url_invalid', (string) ($normalized['message'] ?? 'Invalid voucher URL'));
        return ['success' => false, 'code' => 'INVALID_INPUT', 'message' => (string) ($normalized['message'] ?? 'ลิงก์ซองไม่ถูกต้อง')];
    }
    $mobile = preg_replace('/\D+/', '', $mobile) ?? '';
    if (preg_match('/^0\d{9}$/D', $mobile) !== 1) {
        if ($debug !== null && function_exists('trueMoneyDebugError')) trueMoneyDebugError($debug, 'provider_preflight_failed', 'receiver_phone_invalid', 'Configured TrueMoney receiver phone is invalid');
        return ['success' => false, 'code' => 'INVALID_INPUT', 'message' => 'เบอร์รับเงิน TrueMoney ไม่ถูกต้อง'];
    }

    $token = (string) $normalized['token'];
    $transport = $transport ?? 'trueMoneyDirectCurlRequest';
    if ($debug !== null) {
        trueMoneyDirectInitializeDebug($debug);
        if (function_exists('trueMoneyDebugEvent')) trueMoneyDebugEvent($debug, 'provider_preflight_started', ['provider' => trueMoneyDirectProviderName()]);
    }

    $configUrl = trueMoneyDirectApiUrl('/campaign/vouchers/configuration');
    if ($debug !== null) trueMoneyDirectDebugRequest($debug, 'configuration', 'GET', $token, $mobile);
    $configResponse = trueMoneyDirectRunTransport($transport, 'GET', $configUrl, null, []);
    if ($debug !== null) trueMoneyDirectDebugTransport($debug, $configResponse, 'configuration');
    if (empty($configResponse['executed']) || (int) ($configResponse['curl_errno'] ?? 0) !== 0 || (int) ($configResponse['http_code'] ?? 0) === 0 || !empty($configResponse['response_too_large'])) {
        if ($debug !== null && function_exists('trueMoneyDebugError')) trueMoneyDebugError($debug, 'provider_preflight_failed', 'direct_preflight_transport_failed', 'Unable to reach TrueMoney configuration endpoint');
        return ['success' => false, 'code' => 'NETWORK_ERROR', 'message' => trueMoneyDirectErrorMessage('NETWORK_ERROR')];
    }
    $configDecoded = trueMoneyDirectDecode($configResponse);
    if (empty($configDecoded['valid']) || !is_array($configDecoded['json'])) {
        if ($debug !== null && function_exists('trueMoneyDebugError')) trueMoneyDebugError($debug, 'provider_preflight_failed', 'direct_preflight_invalid_response', 'TrueMoney configuration response was not valid JSON');
        return ['success' => false, 'code' => 'INVALID_RESPONSE', 'message' => trueMoneyDirectErrorMessage('INVALID_RESPONSE')];
    }
    if (trueMoneyDirectIsMaintenance($configDecoded['json'])) {
        if ($debug !== null && function_exists('trueMoneyDebugError')) trueMoneyDebugError($debug, 'provider_preflight_failed', 'truemoney_maintenance', 'TrueMoney voucher service reports maintenance');
        return ['success' => false, 'code' => 'MAINTENANCE', 'message' => trueMoneyDirectErrorMessage('MAINTENANCE')];
    }
    if ($debug !== null && function_exists('trueMoneyDebugEvent')) trueMoneyDebugEvent($debug, 'provider_preflight_passed', ['provider' => trueMoneyDirectProviderName(), 'endpoint_matches_direct_contract' => true]);

    $redeemUrl = trueMoneyDirectApiUrl('/campaign/vouchers/' . rawurlencode($token) . '/redeem');
    if ($debug !== null) trueMoneyDirectDebugRequest($debug, 'redeem', 'POST', $token, $mobile);
    $redeemResponse = trueMoneyDirectRunTransport($transport, 'POST', $redeemUrl, ['mobile' => $mobile, 'voucher_hash' => $token], []);
    if ($debug !== null) trueMoneyDirectDebugTransport($debug, $redeemResponse, 'redeem');
    $httpCode = (int) ($redeemResponse['http_code'] ?? 0);
    $transportAmbiguous = empty($redeemResponse['executed']) || (int) ($redeemResponse['curl_errno'] ?? 0) !== 0 || $httpCode === 0 || !empty($redeemResponse['response_too_large']);
    if ($transportAmbiguous) {
        if ($debug !== null && function_exists('trueMoneyDebugEvent')) trueMoneyDebugEvent($debug, 'provider_result_indeterminate', ['reason' => 'redeem_transport_failed']);
        $verified = trueMoneyDirectVerifyReceiver($token, $mobile, $transport, $debug);
        if (!empty($verified['confirmed'])) {
            $amount = (float) $verified['amount'];
            if ($debug !== null && function_exists('trueMoneyDebugEvent')) trueMoneyDebugEvent($debug, 'provider_voucher_confirmed_by_reconciliation', ['amount_thb' => $amount, 'matched_by' => (string) ($verified['matched_by'] ?? '')]);
            return ['success' => true, 'amount' => $amount, 'code' => 'SUCCESS_RECONCILED', 'reconciled' => true];
        }
        if ($debug !== null && function_exists('trueMoneyDebugError')) trueMoneyDebugError($debug, 'provider_result_indeterminate', 'provider_indeterminate', 'Redeem request may have reached TrueMoney but no definitive result was received');
        return ['success' => false, 'code' => 'provider_indeterminate', 'indeterminate' => true, 'message' => 'ส่งคำขอรับซองไปยัง TrueMoney แล้ว แต่ยังยืนยันผลไม่ได้ ระบบจะไม่ยิงซ้ำอัตโนมัติเพื่อป้องกันรับเงินซ้ำ กรุณาส่งลิงก์เดิมอีกครั้งเพื่อให้ระบบตรวจสอบสถานะ'];
    }

    $decoded = trueMoneyDirectDecode($redeemResponse);
    if (empty($decoded['valid']) || !is_array($decoded['json'])) {
        if ($httpCode === 403) {
            if ($debug !== null && function_exists('trueMoneyDebugError')) trueMoneyDebugError($debug, 'provider_failed', 'direct_request_blocked', 'TrueMoney edge returned HTTP 403 without a valid API JSON response');
            return ['success' => false, 'code' => 'REQUEST_BLOCKED', 'message' => trueMoneyDirectErrorMessage('REQUEST_BLOCKED')];
        }
        if ($httpCode === 429) {
            if ($debug !== null && function_exists('trueMoneyDebugError')) trueMoneyDebugError($debug, 'provider_failed', 'direct_rate_limited', 'TrueMoney edge returned HTTP 429');
            return ['success' => false, 'code' => 'RATE_LIMITED', 'message' => trueMoneyDirectErrorMessage('RATE_LIMITED')];
        }
        if ($httpCode >= 500) {
            $verified = trueMoneyDirectVerifyReceiver($token, $mobile, $transport, $debug);
            if (!empty($verified['confirmed'])) return ['success' => true, 'amount' => (float) $verified['amount'], 'code' => 'SUCCESS_RECONCILED', 'reconciled' => true];
            if ($debug !== null && function_exists('trueMoneyDebugError')) trueMoneyDebugError($debug, 'provider_result_indeterminate', 'provider_indeterminate', 'TrueMoney returned a 5xx response without a recognizable API body');
            return ['success' => false, 'code' => 'provider_indeterminate', 'indeterminate' => true, 'message' => 'TrueMoney ขัดข้องระหว่างรับซองและยังยืนยันผลไม่ได้ ระบบหยุดการยิงซ้ำไว้เพื่อป้องกันรายการซ้ำ'];
        }
        if ($debug !== null && function_exists('trueMoneyDebugError')) trueMoneyDebugError($debug, 'provider_failed', 'direct_invalid_response', 'TrueMoney response was not valid JSON');
        return ['success' => false, 'code' => 'INVALID_RESPONSE', 'message' => trueMoneyDirectErrorMessage('INVALID_RESPONSE')];
    }

    $status = trueMoneyDirectStatus($decoded['json']);
    if ($status['code'] === 'SUCCESS') {
        $amount = trueMoneyDirectAmountFromRedeem($decoded['json']);
        if ($amount === null) {
            if ($debug !== null && function_exists('trueMoneyDebugError')) trueMoneyDebugError($debug, 'provider_failed', 'direct_success_amount_missing', 'TrueMoney reported SUCCESS without a valid my_ticket.amount_baht');
            return ['success' => false, 'code' => 'INVALID_RESPONSE', 'message' => 'TrueMoney ยืนยันรับซองสำเร็จ แต่ไม่พบยอดเงินที่ถูกต้อง กรุณาติดต่อผู้ดูแล'];
        }
        if ($debug !== null && function_exists('trueMoneyDebugEvent')) trueMoneyDebugEvent($debug, 'provider_voucher_confirmed', ['provider' => trueMoneyDirectProviderName(), 'amount_thb' => $amount]);
        return ['success' => true, 'amount' => $amount, 'code' => 'SUCCESS'];
    }

    if ($status['code'] === '' && $httpCode >= 500) {
        $verified = trueMoneyDirectVerifyReceiver($token, $mobile, $transport, $debug);
        if (!empty($verified['confirmed'])) return ['success' => true, 'amount' => (float) $verified['amount'], 'code' => 'SUCCESS_RECONCILED', 'reconciled' => true];
        if ($debug !== null && function_exists('trueMoneyDebugError')) trueMoneyDebugError($debug, 'provider_result_indeterminate', 'provider_indeterminate', 'TrueMoney returned 5xx with no definitive API status');
        return ['success' => false, 'code' => 'provider_indeterminate', 'indeterminate' => true, 'message' => 'TrueMoney ขัดข้องระหว่างรับซองและยังยืนยันผลไม่ได้ ระบบหยุดการยิงซ้ำไว้เพื่อป้องกันรายการซ้ำ'];
    }

    $code = $status['code'] !== '' ? $status['code'] : 'INVALID_RESPONSE';
    $message = trueMoneyDirectErrorMessage($code, $status['message']);
    if ($debug !== null && function_exists('trueMoneyDebugError')) trueMoneyDebugError($debug, 'provider_failed', 'direct_' . strtolower($code), $status['message'] !== '' ? $status['message'] : $message);
    return ['success' => false, 'code' => $code, 'message' => $message, 'provider_message' => trueMoneyDirectSafeMessage($status['message'], 300)];
}
