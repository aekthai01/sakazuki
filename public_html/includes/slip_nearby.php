<?php
/**
 * xNearby SlipVerify API adapter used for isolated evaluation before production integration.
 *
 * This module deliberately has no database or wallet dependencies. It validates the
 * documented SlipVerify v2 response and exposes a small canonical payload that can be
 * compared against the existing EasySlip integration without crediting customer funds.
 */

function nearbySlipValidateToken($token): string
{
    if (!is_scalar($token)) return '';
    $token = trim((string) $token);
    $token = preg_replace('/^Bearer\s+/i', '', $token) ?: '';
    if ($token === '' || strlen($token) > 4096 || preg_match('/[\x00-\x20\x7F]/', $token)) return '';
    return $token;
}

function nearbySlipValidateReceiverOptions(array $options): array
{
    $clean = [];
    foreach (['expected_receiver_name', 'expected_bank_code', 'expected_account_no'] as $key) {
        if (!array_key_exists($key, $options) || !is_scalar($options[$key])) continue;
        $value = trim((string) $options[$key]);
        if ($value === '') continue;
        if (strlen($value) > 255 || preg_match('/[\x00-\x1F\x7F]/', $value)) continue;
        $clean[$key] = $value;
    }
    return $clean;
}

function nearbySlipNormalizeParty($party): array
{
    if (!is_array($party)) return [];

    $string = static function ($value, int $limit = 255): string {
        if (!is_scalar($value)) return '';
        $value = trim((string) $value);
        if ($value === '' || preg_match('/[\x00-\x1F\x7F]/', $value)) return '';
        return substr($value, 0, $limit);
    };

    $details = isset($party['details']) && is_array($party['details']) ? $party['details'] : [];
    $account = $string($details['account_no'] ?? ($party['account_no'] ?? ''), 100);

    return [
        'name' => $string($party['name'] ?? ''),
        'bank_code' => $string($party['bank_code'] ?? '', 32),
        'bank_abbr' => $string($party['bank_abbr'] ?? '', 32),
        'bank_name_th' => $string($party['bank_name_th'] ?? ''),
        'bank_name_en' => $string($party['bank_name_en'] ?? ''),
        'account_no' => $account,
    ];
}

/**
 * Normalize only fields documented by the official SlipVerify SDK types.
 * Country/currency are intentionally not invented here because the published v2
 * response contract does not expose those fields.
 */
function nearbySlipNormalizeSuccessResponse(array $response): array
{
    if (($response['status'] ?? null) !== 'success') {
        return ['success' => false, 'error_code' => 'provider_not_success', 'message' => 'SlipVerify response is not successful'];
    }

    $data = isset($response['data']) && is_array($response['data']) ? $response['data'] : null;
    if ($data === null) {
        return ['success' => false, 'error_code' => 'missing_data', 'message' => 'SlipVerify response is missing data'];
    }

    $transRefRaw = $data['trans_id'] ?? null;
    $transRef = is_scalar($transRefRaw) ? trim((string) $transRefRaw) : '';
    if ($transRef === '' || strlen($transRef) > 100 || preg_match('/[\x00-\x20\x7F]/', $transRef)) {
        return ['success' => false, 'error_code' => 'invalid_transaction_ref', 'message' => 'SlipVerify transaction reference is invalid'];
    }

    $amountRaw = $data['amount'] ?? null;
    if (!is_scalar($amountRaw) || !is_numeric((string) $amountRaw)) {
        return ['success' => false, 'error_code' => 'invalid_amount', 'message' => 'SlipVerify amount is invalid'];
    }
    $amount = round((float) $amountRaw, 2);
    if (!is_finite($amount) || $amount <= 0 || $amount > 10000000) {
        return ['success' => false, 'error_code' => 'invalid_amount', 'message' => 'SlipVerify amount is outside the accepted range'];
    }

    $dateRaw = $data['date_time'] ?? null;
    $date = is_scalar($dateRaw) ? trim((string) $dateRaw) : '';
    if ($date === '' || strlen($date) > 100 || preg_match('/[\x00-\x1F\x7F]/', $date)) {
        return ['success' => false, 'error_code' => 'invalid_transfer_date', 'message' => 'SlipVerify transfer timestamp is invalid'];
    }
    try {
        $parsedDate = new DateTimeImmutable($date);
        $parseErrors = DateTimeImmutable::getLastErrors();
        if (is_array($parseErrors)
            && (((int) ($parseErrors['warning_count'] ?? 0)) > 0 || ((int) ($parseErrors['error_count'] ?? 0)) > 0)) {
            throw new RuntimeException('date parse warning');
        }
        $normalizedDate = $parsedDate->format('c');
    } catch (Throwable $e) {
        return ['success' => false, 'error_code' => 'invalid_transfer_date', 'message' => 'SlipVerify transfer timestamp cannot be parsed'];
    }

    $sender = nearbySlipNormalizeParty($data['sender'] ?? null);
    $receiver = nearbySlipNormalizeParty($data['receiver'] ?? null);
    if (($sender['name'] ?? '') === '' || ($receiver['name'] ?? '') === '') {
        return ['success' => false, 'error_code' => 'missing_party_identity', 'message' => 'SlipVerify response is missing sender or receiver identity'];
    }

    return [
        'success' => true,
        'provider' => 'nearby_slipverify',
        'data' => [
            'transaction_ref' => $transRef,
            'amount' => $amount,
            'transfer_date' => $normalizedDate,
            'sender_name' => $sender['name'],
            'sender_account' => $sender['account_no'],
            'sender_bank_code' => $sender['bank_code'],
            'sender_bank_abbr' => $sender['bank_abbr'],
            'receiver_name' => $receiver['name'],
            'receiver_account' => $receiver['account_no'],
            'receiver_bank_code' => $receiver['bank_code'],
            'receiver_bank_abbr' => $receiver['bank_abbr'],
        ],
    ];
}

function nearbySlipClassifyError(int $httpCode, array $response = [], int $curlErrno = 0, string $curlError = ''): array
{
    $providerCodeRaw = $response['code'] ?? '';
    $providerCode = is_scalar($providerCodeRaw) ? substr(trim((string) $providerCodeRaw), 0, 80) : '';
    $providerMessageRaw = $response['message'] ?? '';
    $providerMessage = is_scalar($providerMessageRaw) ? substr(trim((string) $providerMessageRaw), 0, 300) : '';

    if ($curlErrno !== 0) {
        $timeoutErrno = defined('CURLE_OPERATION_TIMEDOUT') ? (int) CURLE_OPERATION_TIMEDOUT : 28;
        $isTimeout = $curlErrno === $timeoutErrno || $curlErrno === 28;
        return [
            'success' => false,
            'error_code' => $isTimeout ? 'provider_timeout' : 'provider_connection',
            'provider_code' => '',
            'retryable' => true,
            'provider_http_code' => 0,
            'message' => $isTimeout ? 'SlipVerify timed out' : 'SlipVerify connection failed',
            'transport_error' => substr($curlError, 0, 200),
        ];
    }

    if ($httpCode === 401 || $httpCode === 403) {
        return [
            'success' => false,
            'error_code' => 'provider_auth',
            'provider_code' => $providerCode,
            'retryable' => false,
            'provider_http_code' => $httpCode,
            'message' => $providerMessage !== '' ? $providerMessage : 'SlipVerify authentication failed',
        ];
    }
    if ($httpCode === 429 || ($httpCode >= 500 && $httpCode <= 599)) {
        return [
            'success' => false,
            'error_code' => $httpCode === 429 ? 'provider_rate_limited' : 'provider_http_unavailable',
            'provider_code' => $providerCode,
            'retryable' => true,
            'provider_http_code' => $httpCode,
            'message' => $providerMessage !== '' ? $providerMessage : 'SlipVerify is temporarily unavailable',
        ];
    }

    return [
        'success' => false,
        'error_code' => $providerCode !== '' ? strtolower($providerCode) : 'provider_rejected',
        'provider_code' => $providerCode,
        'retryable' => false,
        'provider_http_code' => $httpCode,
        'message' => $providerMessage !== '' ? $providerMessage : 'SlipVerify rejected the request',
    ];
}

/**
 * Low-level HTTPS transport. The response body is capped at 1 MiB and redirects
 * are disabled so credentials cannot be forwarded to another origin.
 */
function nearbySlipHttpV2(
    string $token,
    string $imageBytes,
    string $mime,
    array $receiverOptions = [],
    int $timeoutMs = 45000
): array {
    if (!function_exists('curl_init') || !class_exists('CURLFile')) {
        return ['executed' => false, 'http_code' => 0, 'curl_errno' => -1, 'curl_error' => 'PHP cURL/CURLFile unavailable', 'body' => ''];
    }
    $token = nearbySlipValidateToken($token);
    if ($token === '') {
        return ['executed' => false, 'http_code' => 0, 'curl_errno' => -2, 'curl_error' => 'Invalid SlipVerify token', 'body' => ''];
    }
    if ($imageBytes === '' || strlen($imageBytes) > 4 * 1024 * 1024) {
        return ['executed' => false, 'http_code' => 0, 'curl_errno' => -3, 'curl_error' => 'Invalid image payload', 'body' => ''];
    }
    $allowedMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($allowedMimes[$mime])) {
        return ['executed' => false, 'http_code' => 0, 'curl_errno' => -4, 'curl_error' => 'Unsupported image MIME', 'body' => ''];
    }

    $timeoutMs = max(1000, min(45000, $timeoutMs));
    $temp = tempnam(sys_get_temp_dir(), 'sakazuki_slip_');
    if (!is_string($temp) || $temp === '') {
        return ['executed' => false, 'http_code' => 0, 'curl_errno' => -5, 'curl_error' => 'Unable to create temporary file', 'body' => ''];
    }

    try {
        @chmod($temp, 0600);
        if (file_put_contents($temp, $imageBytes, LOCK_EX) !== strlen($imageBytes)) {
            return ['executed' => false, 'http_code' => 0, 'curl_errno' => -6, 'curl_error' => 'Unable to write temporary image', 'body' => ''];
        }

        $post = ['slip' => new CURLFile($temp, $mime, 'slip.' . $allowedMimes[$mime])];
        foreach (nearbySlipValidateReceiverOptions($receiverOptions) as $key => $value) {
            $post[$key] = $value;
        }

        $body = '';
        $tooLarge = false;
        $ch = curl_init('https://api.nearbyshop.xyz/slipVerify/v2');
        if ($ch === false) {
            return ['executed' => false, 'http_code' => 0, 'curl_errno' => -7, 'curl_error' => 'Unable to initialize cURL', 'body' => ''];
        }
        $options = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
                'User-Agent: Sakazuki-SlipVerify-Probe/1.0',
            ],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => min(3000, $timeoutMs),
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > 1024 * 1024) {
                    $tooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ];
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTPS')) $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
        curl_setopt_array($ch, $options);
        $started = microtime(true);
        $ok = curl_exec($ch);
        $durationMs = (int) round((microtime(true) - $started) * 1000);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        return [
            'executed' => $ok !== false || $httpCode > 0,
            'http_code' => $httpCode,
            'curl_errno' => $curlErrno,
            'curl_error' => $curlError,
            'body' => $body,
            'response_too_large' => $tooLarge,
            'duration_ms' => $durationMs,
        ];
    } finally {
        @unlink($temp);
    }
}

/**
 * Verify one image with SlipVerify v2. Tests can inject transport and therefore
 * validate request/result behavior without a live token or paid EasySlip call.
 */
function nearbySlipVerifyV2(
    string $token,
    string $imageBytes,
    string $mime,
    array $receiverOptions = [],
    int $timeoutMs = 45000,
    ?callable $transport = null
): array {
    $token = nearbySlipValidateToken($token);
    if ($token === '') {
        return ['success' => false, 'error_code' => 'missing_token', 'retryable' => false, 'message' => 'SlipVerify token is not configured'];
    }
    if ($imageBytes === '' || strlen($imageBytes) > 4 * 1024 * 1024) {
        return ['success' => false, 'error_code' => 'invalid_image_payload', 'retryable' => false, 'message' => 'Slip image is missing or too large'];
    }
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        return ['success' => false, 'error_code' => 'invalid_image_mime', 'retryable' => false, 'message' => 'SlipVerify v2 probe supports JPEG, PNG, and WebP'];
    }

    $transport = $transport ?? 'nearbySlipHttpV2';
    $network = $transport($token, $imageBytes, $mime, nearbySlipValidateReceiverOptions($receiverOptions), $timeoutMs);
    if (!is_array($network)) {
        return ['success' => false, 'error_code' => 'transport_contract', 'retryable' => false, 'message' => 'SlipVerify transport returned invalid data'];
    }
    if (!empty($network['response_too_large'])) {
        return ['success' => false, 'error_code' => 'provider_response_too_large', 'retryable' => true, 'message' => 'SlipVerify response exceeded the safety limit'];
    }

    $httpCode = max(0, (int) ($network['http_code'] ?? 0));
    $curlErrno = (int) ($network['curl_errno'] ?? 0);
    $curlError = (string) ($network['curl_error'] ?? '');
    if ($curlErrno !== 0) return nearbySlipClassifyError($httpCode, [], $curlErrno, $curlError);

    $body = (string) ($network['body'] ?? '');
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return [
            'success' => false,
            'error_code' => 'provider_invalid_response',
            'retryable' => $httpCode >= 500 || $httpCode === 0,
            'provider_http_code' => $httpCode,
            'message' => 'SlipVerify returned invalid JSON',
        ];
    }

    if ($httpCode !== 200 || ($decoded['status'] ?? null) !== 'success') {
        return nearbySlipClassifyError($httpCode, $decoded);
    }

    $normalized = nearbySlipNormalizeSuccessResponse($decoded);
    $normalized['provider_http_code'] = $httpCode;
    $normalized['provider_duration_ms'] = max(0, (int) ($network['duration_ms'] ?? 0));
    return $normalized;
}
