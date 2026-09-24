<?php
/**
 * xNearby SlipVerify v2 provider adapter.
 *
 * Authentication uses X-API-Key. Legacy Bearer JWT auth is intentionally not
 * supported because xNearby retired it. This adapter only verifies/normalizes
 * provider data; wallet crediting and duplicate enforcement remain in the
 * existing Sakazuki deposit pipeline and cross-site shared ledger.
 */

function nearbySlipValidateApiKey($apiKey): string
{
    if (!is_scalar($apiKey)) return '';
    $apiKey = trim((string) $apiKey);
    if ($apiKey === '' || strlen($apiKey) < 12 || strlen($apiKey) > 512) return '';
    if (preg_match('/[\x00-\x20\x7F]/', $apiKey)) return '';
    return $apiKey;
}

function nearbySlipAuthHeaders(string $apiKey): array
{
    $apiKey = nearbySlipValidateApiKey($apiKey);
    if ($apiKey === '') return [];
    return [
        'X-API-Key: ' . $apiKey,
        'Accept: application/json',
        'User-Agent: Sakazuki-SlipProvider/3.0',
    ];
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

/**
 * Decode the same base64/data-URI shape accepted by the existing deposit flow.
 * MIME is derived from the decoded bytes, never trusted from the data URI.
 *
 * @return array{success:bool,bytes?:string,mime?:string,error_code?:string,message?:string}
 */
function nearbySlipDecodeBase64Image(string $imageBase64): array
{
    $imageBase64 = trim($imageBase64);
    if ($imageBase64 === '' || strlen($imageBase64) > 6 * 1024 * 1024) {
        return ['success' => false, 'error_code' => 'invalid_image_payload', 'message' => 'Slip image is missing or too large'];
    }
    if (preg_match('#^data:[^;,]+;base64,(.+)$#s', $imageBase64, $m)) {
        $imageBase64 = $m[1];
    }
    $bytes = base64_decode($imageBase64, true);
    if (!is_string($bytes) || $bytes === '' || strlen($bytes) > 4 * 1024 * 1024) {
        return ['success' => false, 'error_code' => 'invalid_image_payload', 'message' => 'Slip image base64 is invalid or too large'];
    }

    $mime = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detected = finfo_buffer($finfo, $bytes);
            finfo_close($finfo);
            if (is_string($detected)) $mime = strtolower(trim($detected));
        }
    }
    if ($mime === '' && function_exists('getimagesizefromstring')) {
        $info = @getimagesizefromstring($bytes);
        if (is_array($info) && isset($info['mime'])) $mime = strtolower(trim((string) $info['mime']));
    }
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        return ['success' => false, 'error_code' => 'invalid_image_mime', 'message' => 'SlipVerify supports JPEG, PNG, and WebP'];
    }
    return ['success' => true, 'bytes' => $bytes, 'mime' => $mime];
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
        'bank_name_th' => $string($party['bank_name_th'] ?? ($party['bank'] ?? '')),
        'bank_name_en' => $string($party['bank_name_en'] ?? ''),
        'account_no' => $account,
    ];
}

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
            // Existing slip_deposits.bank_code stores the sending bank code.
            'bank_code' => $sender['bank_code'] !== '' ? $sender['bank_code'] : $sender['bank_abbr'],
            'sender_bank_code' => $sender['bank_code'],
            'sender_bank_abbr' => $sender['bank_abbr'],
            'receiver_name' => $receiver['name'],
            'receiver_account' => $receiver['account_no'],
            'receiver_bank_code' => $receiver['bank_code'],
            'receiver_bank_abbr' => $receiver['bank_abbr'],
            // Optional EasySlip-only fields intentionally remain absent/empty.
            // Local validation already treats missing country/currency as unknown.
            'verification_remark' => '',
            'is_duplicate' => false,
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
        if ($isTimeout) {
            // A timeout can happen after the multipart body reached xNearby. Do
            // not automatically resend an ambiguous financial verification.
            return [
                'success' => false,
                'pending' => true,
                'error_code' => 'provider_outcome_unknown',
                'provider_code' => '',
                'retryable' => false,
                'provider_http_code' => $httpCode,
                'message' => 'SlipVerify request timed out after submission; outcome is unknown',
                'transport_error' => substr($curlError, 0, 200),
            ];
        }
        return [
            'success' => false,
            'error_code' => 'provider_connection',
            'provider_code' => '',
            'retryable' => true,
            'provider_http_code' => 0,
            'message' => 'SlipVerify connection failed',
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
            'message' => $providerMessage !== '' ? $providerMessage : 'SlipVerify API key authentication failed',
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

function nearbySlipHttpV2(
    string $apiKey,
    string $imageBytes,
    string $mime,
    array $receiverOptions = [],
    int $timeoutMs = 45000
): array {
    if (!function_exists('curl_init') || !class_exists('CURLFile')) {
        return ['executed' => false, 'http_code' => 0, 'curl_errno' => -1, 'curl_error' => 'PHP cURL/CURLFile unavailable', 'body' => ''];
    }
    $apiKey = nearbySlipValidateApiKey($apiKey);
    if ($apiKey === '') {
        return ['executed' => false, 'http_code' => 0, 'curl_errno' => -2, 'curl_error' => 'Invalid SlipVerify API key', 'body' => ''];
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
            CURLOPT_HTTPHEADER => nearbySlipAuthHeaders($apiKey),
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

function nearbySlipVerifyV2(
    string $apiKey,
    string $imageBytes,
    string $mime,
    array $receiverOptions = [],
    int $timeoutMs = 45000,
    ?callable $transport = null
): array {
    $apiKey = nearbySlipValidateApiKey($apiKey);
    if ($apiKey === '') {
        return ['success' => false, 'error_code' => 'missing_api_key', 'retryable' => false, 'message' => 'SlipVerify API key is not configured'];
    }
    if ($imageBytes === '' || strlen($imageBytes) > 4 * 1024 * 1024) {
        return ['success' => false, 'error_code' => 'invalid_image_payload', 'retryable' => false, 'message' => 'Slip image is missing or too large'];
    }
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        return ['success' => false, 'error_code' => 'invalid_image_mime', 'retryable' => false, 'message' => 'SlipVerify supports JPEG, PNG, and WebP'];
    }

    $transport = $transport ?? 'nearbySlipHttpV2';
    $network = $transport($apiKey, $imageBytes, $mime, nearbySlipValidateReceiverOptions($receiverOptions), $timeoutMs);
    if (!is_array($network)) {
        return ['success' => false, 'error_code' => 'transport_contract', 'retryable' => false, 'message' => 'SlipVerify transport returned invalid data'];
    }
    if (!empty($network['response_too_large'])) {
        return [
            'success' => false,
            'pending' => true,
            'error_code' => 'provider_outcome_unknown',
            'retryable' => false,
            'message' => 'SlipVerify response exceeded the safety limit after request submission',
        ];
    }

    $httpCode = max(0, (int) ($network['http_code'] ?? 0));
    $curlErrno = (int) ($network['curl_errno'] ?? 0);
    $curlError = (string) ($network['curl_error'] ?? '');
    if ($curlErrno !== 0) return nearbySlipClassifyError($httpCode, [], $curlErrno, $curlError);

    $body = (string) ($network['body'] ?? '');
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        $ambiguous = $httpCode === 200 || $httpCode >= 500 || $httpCode === 0;
        return [
            'success' => false,
            'pending' => $ambiguous,
            'error_code' => $ambiguous ? 'provider_outcome_unknown' : 'provider_invalid_response',
            'retryable' => !$ambiguous,
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

/** Verify a base64/data-URI slip through the v2 adapter used by the main flow. */
function nearbySlipVerifyBase64(
    string $apiKey,
    string $imageBase64,
    array $receiverOptions = [],
    int $timeoutMs = 45000,
    ?callable $transport = null
): array {
    $decoded = nearbySlipDecodeBase64Image($imageBase64);
    if (empty($decoded['success'])) {
        return [
            'success' => false,
            'error_code' => (string) ($decoded['error_code'] ?? 'invalid_image_payload'),
            'retryable' => false,
            'message' => (string) ($decoded['message'] ?? 'Slip image is invalid'),
        ];
    }
    return nearbySlipVerifyV2(
        $apiKey,
        (string) $decoded['bytes'],
        (string) $decoded['mime'],
        $receiverOptions,
        $timeoutMs,
        $transport
    );
}

/** Return the configured live bank-slip provider. Unknown values fail back to EasySlip. */
function slipVerificationConfiguredProvider(): string
{
    if (!function_exists('getSetting')) return 'easyslip';
    $provider = strtolower(trim((string) getSetting('slip_verification_provider', 'easyslip')));
    return $provider === 'nearby' ? 'nearby' : 'easyslip';
}

/** Build xNearby receiver validation from the existing single store account source. */
function nearbySlipConfiguredReceiverOptions(): array
{
    if (!function_exists('getSetting')) return [];
    $nameTh = trim((string) getSetting('easyslip_receiver_name', ''));
    $nameEn = trim((string) getSetting('easyslip_receiver_name_en', ''));
    $account = trim((string) getSetting('easyslip_account_number', ''));
    $options = [];
    $name = $nameTh !== '' ? $nameTh : $nameEn;
    if ($name !== '') $options['expected_receiver_name'] = $name;
    if ($account !== '') $options['expected_account_no'] = $account;
    // Store settings currently keep a bank name, not an authoritative bank code.
    // Do not guess expected_bank_code from a display name.
    return nearbySlipValidateReceiverOptions($options);
}

/**
 * Provider router used by processSlipDeposit(). Both providers must return the
 * same normalized contract. All wallet/shared-ledger decisions stay outside.
 */
function verifySlipWithConfiguredProvider($imageBase64, string $remark = '', array $debugContext = []): array
{
    $provider = slipVerificationConfiguredProvider();
    if ($provider !== 'nearby') {
        $result = verifySlipWithEasyslip($imageBase64, $remark, $debugContext);
        if (is_array($result)) $result['provider'] = 'easyslip';
        return is_array($result) ? $result : [
            'success' => false,
            'error_code' => 'provider_contract',
            'retryable' => false,
            'message' => 'EasySlip provider returned invalid data',
        ];
    }

    if (!function_exists('getSetting')) {
        return ['success' => false, 'error_code' => 'provider_config', 'retryable' => false, 'message' => 'Slip provider settings are unavailable'];
    }
    $apiKey = nearbySlipValidateApiKey((string) getSetting('slipverify_nearby_api_key', ''));
    if ($apiKey === '') {
        return ['success' => false, 'error_code' => 'missing_api_key', 'retryable' => false, 'message' => 'SlipVerify API key is not configured'];
    }

    $diagnosticOnly = !empty($debugContext['diagnostic_only']);
    $slipHash = isset($debugContext['slip_hash']) && is_scalar($debugContext['slip_hash'])
        ? strtolower(trim((string) $debugContext['slip_hash'])) : '';
    if (!$diagnosticOnly && preg_match('/^[a-f0-9]{64}$/D', $slipHash) === 1 && function_exists('slipVerificationMarkProviderRequest')) {
        slipVerificationMarkProviderRequest($slipHash);
    }

    $timeoutMs = 45000;
    $deadline = isset($debugContext['deadline']) && is_numeric($debugContext['deadline'])
        ? (float) $debugContext['deadline'] : 0.0;
    if ($deadline > 0.0 && function_exists('slipVerificationDeadlineRemainingMs')) {
        $remaining = (int) slipVerificationDeadlineRemainingMs($deadline);
        // Leave room for durable snapshot storage and the existing completion path.
        $timeoutMs = max(1000, min(45000, $remaining - 1500));
        if ($remaining <= 2500) {
            return [
                'success' => false,
                'error_code' => 'customer_deadline_exceeded',
                'retryable' => true,
                'retry_after_seconds' => 3,
                'message' => 'Not enough request time remains to start SlipVerify safely',
            ];
        }
    }

    $slotName = '';
    if (function_exists('slipProviderAcquireConcurrencySlot')) {
        $slot = slipProviderAcquireConcurrencySlot($apiKey);
        if (empty($slot['success'])) {
            return [
                'success' => false,
                'error_code' => 'provider_busy',
                'retryable' => true,
                'retry_after_seconds' => max(1, (int) ($slot['retry_after_seconds'] ?? 2)),
                'message' => 'Slip verification is busy; retry the same slip shortly',
            ];
        }
        $slotName = (string) ($slot['slot_name'] ?? '');
    }

    try {
        $result = nearbySlipVerifyBase64(
            $apiKey,
            (string) $imageBase64,
            nearbySlipConfiguredReceiverOptions(),
            $timeoutMs
        );
    } finally {
        if ($slotName !== '' && function_exists('slipProviderReleaseConcurrencySlot')) {
            slipProviderReleaseConcurrencySlot($slotName);
        }
    }

    if (!is_array($result)) {
        return ['success' => false, 'error_code' => 'provider_contract', 'retryable' => false, 'message' => 'SlipVerify provider returned invalid data'];
    }
    $result['provider'] = 'nearby_slipverify';
    if (!empty($result['success']) && is_array($result['data'] ?? null)) {
        $result['data']['_provider'] = 'nearby_slipverify';
    }
    return $result;
}
