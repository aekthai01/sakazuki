<?php
/**
 * Binance Gift Card redemption module.
 *
 * Security rules:
 * - Accept only a 16-character redemption code from the user.
 * - Never persist or log the full redemption code.
 * - Encrypt the code with Binance daily RSA key using OAEP SHA-256 before sending.
 * - Persist a keyed fingerprint and the final four characters for auditing.
 * - Credit website balance only after Binance returns a successful redemption
 *   and the returned token/amount satisfy the configured policy.
 */

require_once __DIR__ . '/binance.php';

if (!defined('BINANCE_GIFTCARD_API_BASE')) {
    define('BINANCE_GIFTCARD_API_BASE', 'https://api.binance.com');
}

/** @return array<string,mixed> */
function getBinanceGiftCardSettings(): array
{
    $credentialMode = strtolower(trim((string) (getSetting('binance_giftcard_credentials_mode') ?: 'separate')));
    if (!in_array($credentialMode, ['shared', 'separate'], true)) {
        $credentialMode = 'separate';
    }

    $perUserAttempts = (int) (getSetting('binance_giftcard_user_attempts_per_day') ?: 2);
    $globalInvalidLimit = (int) (getSetting('binance_giftcard_global_invalid_limit') ?: 4);
    $accountAgeDays = (int) (getSetting('binance_giftcard_account_age_days') ?: 0);
    $minAmount = (float) (getSetting('binance_giftcard_min_usdt') ?: 1);
    $maxAmount = (float) (getSetting('binance_giftcard_max_usdt') ?: 1000);
    $dailyAmount = (float) (getSetting('binance_giftcard_daily_usdt') ?: 2000);
    $creditPercent = (float) (getSetting('binance_giftcard_credit_percent') ?: 100);

    return [
        'enabled' => getSetting('binance_giftcard_enabled') === '1',
        'user_enabled' => getSetting('binance_giftcard_user_enabled', '1') !== '0',
        'reseller_enabled' => getSetting('binance_giftcard_reseller_enabled', '1') !== '0',
        'credentials_mode' => $credentialMode,
        'api_key' => trim((string) (getSetting('binance_giftcard_api_key') ?: '')),
        'secret_key' => trim((string) (getSetting('binance_giftcard_secret_key') ?: '')),
        'allowed_token' => 'USDT',
        'min_usdt' => max(0.01, min(1000000, $minAmount)),
        'max_usdt' => max(0.01, min(1000000, $maxAmount)),
        'daily_usdt' => max(0.01, min(10000000, $dailyAmount)),
        'credit_percent' => max(1, min(100, $creditPercent)),
        'user_attempts_per_day' => max(1, min(4, $perUserAttempts)),
        'global_invalid_limit' => max(1, min(4, $globalInvalidLimit)),
        'account_age_days' => max(0, min(365, $accountAgeDays)),
        'count_for_ranking' => getSetting('binance_giftcard_count_for_ranking', '1') !== '0',
        'rank_bonus_enabled' => getSetting('binance_giftcard_rank_bonus_enabled') === '1',
    ];
}

/**
 * Non-sensitive visibility settings safe for user/reseller page composition.
 * API credentials and financial controls are intentionally excluded.
 *
 * @return array{enabled:bool,user_enabled:bool,reseller_enabled:bool}
 */
function getBinanceGiftCardPublicSettings(): array
{
    return [
        'enabled' => getSetting('binance_giftcard_enabled') === '1',
        'user_enabled' => getSetting('binance_giftcard_user_enabled', '1') !== '0',
        'reseller_enabled' => getSetting('binance_giftcard_reseller_enabled', '1') !== '0',
    ];
}

/** @return array{api_key:string,secret_key:string,mode:string} */
function binanceGiftCardCredentials(?array $settings = null): array
{
    $settings = $settings ?: getBinanceGiftCardSettings();
    if (($settings['credentials_mode'] ?? '') === 'shared') {
        $shared = getBinanceSettings();
        return [
            'api_key' => trim((string) ($shared['api_key'] ?? '')),
            'secret_key' => trim((string) ($shared['secret_key'] ?? '')),
            'mode' => 'shared',
        ];
    }
    return [
        'api_key' => trim((string) ($settings['api_key'] ?? '')),
        'secret_key' => trim((string) ($settings['secret_key'] ?? '')),
        'mode' => 'separate',
    ];
}

function ensureBinanceGiftCardSchema(): bool
{
    global $conn;
    static $schemaReady = null;
    if ($schemaReady !== null) return $schemaReady;

    if (!isset($conn) || !($conn instanceof mysqli)) {
        error_log('Binance Gift Card schema unavailable: database connection missing.');
        return $schemaReady = false;
    }

    // One database-level lock serializes first installation, migrations, and
    // creation of the keyed code fingerprint secret across concurrent requests.
    $lockResult = $conn->query("SELECT GET_LOCK('sakazuki_binance_giftcard_schema', 10)");
    $lockRow = $lockResult ? $lockResult->fetch_row() : null;
    if ((int) ($lockRow[0] ?? 0) !== 1) {
        error_log('Binance Gift Card schema lock unavailable.');
        return $schemaReady = false;
    }

    try {
        $sql = "CREATE TABLE IF NOT EXISTS binance_giftcard_redemptions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            request_id VARCHAR(24) NOT NULL DEFAULT '',
            user_id INT UNSIGNED NOT NULL,
            role_at_redeem VARCHAR(20) NOT NULL,
            code_fingerprint CHAR(64) NOT NULL,
            code_last4 CHAR(4) NOT NULL,
            external_uid VARCHAR(128) NOT NULL,
            reference_no VARCHAR(64) NULL,
            identity_no VARCHAR(64) NULL,
            token VARCHAR(32) NULL,
            token_amount DECIMAL(24,8) NOT NULL DEFAULT 0,
            exchange_rate DECIMAL(18,8) NOT NULL DEFAULT 0,
            credit_currency CHAR(3) NOT NULL DEFAULT 'THB',
            credit_percent DECIMAL(6,2) NOT NULL DEFAULT 100,
            count_for_ranking TINYINT(1) NOT NULL DEFAULT 1,
            rank_bonus_enabled TINYINT(1) NOT NULL DEFAULT 0,
            credit_amount DECIMAL(16,2) NOT NULL DEFAULT 0,
            status VARCHAR(40) NOT NULL DEFAULT 'received',
            request_stage VARCHAR(40) NOT NULL DEFAULT 'received',
            api_code VARCHAR(32) NULL,
            api_message_safe VARCHAR(255) NULL,
            http_code SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            provider_message_safe VARCHAR(255) NULL,
            provider_requested_at DATETIME NULL,
            transaction_id BIGINT UNSIGNED NULL,
            attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            redeemed_at DATETIME NULL,
            credited_at DATETIME NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_giftcard_request_id (request_id),
            UNIQUE KEY uq_giftcard_code_fingerprint (code_fingerprint),
            UNIQUE KEY uq_giftcard_reference_no (reference_no),
            UNIQUE KEY uq_giftcard_identity_no (identity_no),
            UNIQUE KEY uq_giftcard_transaction (transaction_id),
            KEY idx_giftcard_user_time (user_id, requested_at),
            KEY idx_giftcard_status_time (status, requested_at),
            KEY idx_giftcard_requested (requested_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if (!$conn->query($sql)) {
            error_log('Binance Gift Card schema creation failed.');
            return $schemaReady = false;
        }

        // Safe, repeatable migration for installations that received an earlier
        // development build before these policy-snapshot columns were added.
        $requiredColumns = [
            'request_id' => "VARCHAR(24) NOT NULL DEFAULT '' AFTER id",
            'credit_currency' => "CHAR(3) NOT NULL DEFAULT 'THB' AFTER exchange_rate",
            'count_for_ranking' => 'TINYINT(1) NOT NULL DEFAULT 1 AFTER credit_percent',
            'rank_bonus_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER count_for_ranking',
            'request_stage' => "VARCHAR(40) NOT NULL DEFAULT 'received' AFTER status",
            'http_code' => 'SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER api_message_safe',
            'provider_message_safe' => 'VARCHAR(255) NULL AFTER http_code',
            'provider_requested_at' => 'DATETIME NULL AFTER provider_message_safe',
        ];
        $columnsResult = $conn->query('SHOW COLUMNS FROM binance_giftcard_redemptions');
        if (!$columnsResult) {
            error_log('Binance Gift Card schema inspection failed.');
            return $schemaReady = false;
        }
        $existingColumns = [];
        while ($column = $columnsResult->fetch_assoc()) {
            $existingColumns[(string) ($column['Field'] ?? '')] = true;
        }
        foreach ($requiredColumns as $columnName => $definition) {
            if (isset($existingColumns[$columnName])) continue;
            if (!$conn->query("ALTER TABLE binance_giftcard_redemptions ADD COLUMN `{$columnName}` {$definition}")) {
                error_log('Binance Gift Card schema migration failed for ' . $columnName . '.');
                return $schemaReady = false;
            }
        }

        // The request identifier is deliberately non-secret and searchable by
        // administrators. A non-unique index remains compatible with legacy rows
        // that received an empty default during migration.
        $indexResult = $conn->query("SHOW INDEX FROM binance_giftcard_redemptions WHERE Key_name = 'idx_giftcard_request_id'");
        if ($indexResult && $indexResult->num_rows === 0) {
            if (!$conn->query('ALTER TABLE binance_giftcard_redemptions ADD KEY idx_giftcard_request_id (request_id)')) {
                error_log('Binance Gift Card request-id index migration failed.');
            }
        }

        $fingerprintKey = trim((string) (getSetting('binance_giftcard_fingerprint_key') ?: ''));
        if (strlen($fingerprintKey) < 32) {
            try {
                $fingerprintKey = bin2hex(random_bytes(32));
            } catch (Throwable $e) {
                error_log('Binance Gift Card fingerprint key generation failed.');
                return $schemaReady = false;
            }
            if (!upsertSetting('binance_giftcard_fingerprint_key', $fingerprintKey)) {
                error_log('Binance Gift Card fingerprint key save failed.');
                return $schemaReady = false;
            }
            $savedKey = trim((string) (getSetting('binance_giftcard_fingerprint_key') ?: ''));
            if (!hash_equals($fingerprintKey, $savedKey)) {
                error_log('Binance Gift Card fingerprint key verification failed.');
                return $schemaReady = false;
            }
        }

        return $schemaReady = true;
    } finally {
        $conn->query("SELECT RELEASE_LOCK('sakazuki_binance_giftcard_schema')");
    }
}

function normalizeBinanceGiftCardCode($code): string
{
    if (!is_scalar($code)) return '';
    $code = strtoupper(trim((string) $code));
    $code = preg_replace('/[\s\-]+/u', '', $code);
    if (!is_string($code)) return '';
    return $code;
}

function isValidBinanceGiftCardCode(string $code): bool
{
    return (bool) preg_match('/^[A-Z0-9]{16}$/D', $code);
}

function binanceGiftCardFingerprint(string $code): string
{
    $key = (string) (getSetting('binance_giftcard_fingerprint_key') ?: '');
    if (strlen($key) < 32) return '';
    return hash_hmac('sha256', $code, $key);
}

function binanceGiftCardExternalUid(int $userId): string
{
    $key = (string) (getSetting('binance_giftcard_fingerprint_key') ?: '');
    return 'sak_' . substr(hash_hmac('sha256', 'giftcard-user:' . $userId, $key), 0, 48);
}

/** A public support identifier. It is not a credential and cannot redeem a card. */
function binanceGiftCardRequestId(): string
{
    try {
        return 'GC-' . strtoupper(bin2hex(random_bytes(8)));
    } catch (Throwable $e) {
        return 'GC-' . strtoupper(substr(hash('sha256', uniqid('', true) . mt_rand()), 0, 16));
    }
}

/**
 * Remove secrets and control characters from a provider message before it is
 * stored for administrator diagnostics. The original response is never logged.
 */
function binanceGiftCardSanitizeProviderMessage($message): string
{
    if (!is_scalar($message)) return '';
    $message = strip_tags((string) $message);
    $message = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $message);
    if (!is_string($message)) return '';
    $message = preg_replace('/\b[A-Z0-9]{16}\b/i', '[REDACTED_CODE]', $message);
    $message = preg_replace('/\b[A-Za-z0-9_\-]{32,}\b/', '[REDACTED_SECRET]', $message);
    $message = preg_replace('/\s+/u', ' ', trim($message));
    if (!is_string($message)) return '';
    return substr($message, 0, 255);
}

/** Add a harmless support code to a public result without exposing diagnostics. */
function binanceGiftCardPublicResult(string $message, string $requestId = '', bool $pending = false): array
{
    $requestId = preg_match('/^GC-[A-F0-9]{16}$/D', $requestId) ? $requestId : '';
    if ($requestId !== '') {
        $message .= ' (' . Lang::t('giftcard.support_code') . ': ' . $requestId . ')';
    }
    $result = ['success' => false, 'message' => $message];
    if ($pending) $result['pending'] = true;
    if ($requestId !== '') $result['support_code'] = $requestId;
    return $result;
}

/**
 * Signed Binance Gift Card request. Sensitive request values are never logged.
 *
 * @return array<string,mixed>
 */
function binanceGiftCardSignedRequest(string $method, string $endpoint, array $params = []): array
{
    $settings = getBinanceGiftCardSettings();
    $credentials = binanceGiftCardCredentials($settings);
    $apiKey = $credentials['api_key'];
    $secretKey = $credentials['secret_key'];
    $method = strtoupper($method);

    if ($apiKey === '' || $secretKey === '') {
        return ['success' => false, 'unknown' => false, 'http_code' => 0, 'api_code' => 'CONFIG', 'message' => 'api_credentials_missing'];
    }
    if (!in_array($method, ['GET', 'POST'], true) || strpos($endpoint, '/sapi/v1/giftcard/') !== 0) {
        return ['success' => false, 'unknown' => false, 'http_code' => 0, 'api_code' => 'ENDPOINT', 'message' => 'invalid_endpoint'];
    }
    if (!function_exists('curl_init') || !function_exists('configureBoundedCurlResponse')) {
        return ['success' => false, 'unknown' => false, 'http_code' => 0, 'api_code' => 'CURL', 'message' => 'transport_unavailable'];
    }

    $baseParams = $params;
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $requestParams = $baseParams;
        $clock = binanceClockTimestamp($attempt === 1);
        $requestParams['recvWindow'] = 5000;
        $requestParams['timestamp'] = $clock['timestamp'];
        $payload = http_build_query($requestParams, '', '&', PHP_QUERY_RFC3986);
        $signature = hash_hmac('sha256', $payload, $secretKey);
        $signedPayload = $payload . '&signature=' . rawurlencode($signature);

        $url = BINANCE_GIFTCARD_API_BASE . $endpoint;
        if ($method === 'GET') {
            $url .= '?' . $signedPayload;
        }

        $headers = [
            'X-MBX-APIKEY: ' . $apiKey,
            'Accept: application/json',
        ];
        if ($method === 'POST') {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }

        $ch = curl_init($url);
        $curlOptions = [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'SakazukiGiftCard/1.2',
        ];
        if ($method === 'POST') {
            $curlOptions[CURLOPT_POST] = true;
            $curlOptions[CURLOPT_POSTFIELDS] = $signedPayload;
        }
        curl_setopt_array($ch, $curlOptions);

        $response = '';
        $tooLarge = false;
        configureBoundedCurlResponse($ch, $response, $tooLarge, 512 * 1024);
        $executed = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrorNo = (int) curl_errno($ch);
        curl_close($ch);

        if ($executed === false || $curlErrorNo !== 0 || $tooLarge) {
            error_log('Binance Gift Card transport failure; curl=' . $curlErrorNo . '; oversized=' . ($tooLarge ? '1' : '0'));
            return [
                'success' => false,
                'unknown' => $method === 'POST',
                'http_code' => $httpCode,
                'api_code' => 'TRANSPORT',
                'message' => 'transport_failure',
            ];
        }

        $decoded = json_decode($response, true, 64);
        if (!is_array($decoded)) {
            error_log('Binance Gift Card returned invalid JSON; http=' . $httpCode);
            return [
                'success' => false,
                // A malformed response after a state-changing POST cannot prove
                // the redemption failed, even when an intermediary returned HTTP 200.
                'unknown' => $method === 'POST',
                'http_code' => $httpCode,
                'api_code' => 'INVALID_JSON',
                'message' => 'invalid_response',
            ];
        }

        $apiCode = (string) ($decoded['code'] ?? $httpCode);
        $apiMessage = (string) ($decoded['message'] ?? ($decoded['msg'] ?? ''));
        $isSuccess = $httpCode === 200
            && (($decoded['success'] ?? null) === true)
            && isset($decoded['data']);

        if ($isSuccess) {
            return [
                'success' => true,
                'unknown' => false,
                'http_code' => $httpCode,
                'api_code' => $apiCode,
                'message' => 'success',
                'data' => $decoded['data'],
            ];
        }

        // Binance rejected the request before processing because its timestamp
        // validation failed. Rebuild the signature from a forced fresh clock sync
        // and retry once; never loop a state-changing Gift Card request.
        if ($apiCode === '-1021' && $attempt === 0) {
            error_log(
                'Binance Gift Card timestamp rejected; retrying after forced clock sync; offset_ms=' . (int) ($clock['offset_ms'] ?? 0)
                . '; rtt_ms=' . (int) ($clock['rtt_ms'] ?? 0)
                . '; synced=' . (!empty($clock['synced']) ? '1' : '0')
            );
            continue;
        }

        $unknownCodes = ['-1000', '-1006', '-1007'];
        $unknown = $method === 'POST' && ($httpCode >= 500 || in_array($apiCode, $unknownCodes, true));
        error_log('Binance Gift Card request rejected; http=' . $httpCode . '; code=' . substr($apiCode, 0, 32));

        return [
            'success' => false,
            'unknown' => $unknown,
            'http_code' => $httpCode,
            'api_code' => $apiCode,
            'message' => substr($apiMessage, 0, 255),
        ];
    }

    return [
        'success' => false,
        'unknown' => false,
        'http_code' => 0,
        'api_code' => 'RETRY_EXHAUSTED',
        'message' => 'request_retry_exhausted',
    ];
}

/**
 * MGF1 used by RSA OAEP SHA-256. Kept private to this module so the Gift Card
 * code can be encrypted exactly as required by Binance without adding a new
 * Composer dependency to the existing project.
 */
function binanceGiftCardMgf1(string $seed, int $length): string
{
    if ($length < 0 || $length > 16384) return '';
    $mask = '';
    for ($counter = 0; strlen($mask) < $length; $counter++) {
        $mask .= hash('sha256', $seed . pack('N', $counter), true);
    }
    return substr($mask, 0, $length);
}

/**
 * Encrypt one redemption code with RSA OAEP SHA-256 and return base64 text.
 */
function binanceGiftCardRsaOaepSha256Encrypt(string $plaintext, string $publicKeyBase64): string
{
    if ($plaintext === '' || $publicKeyBase64 === '' || !function_exists('openssl_pkey_get_public')) return '';
    if (!preg_match('/^[A-Za-z0-9+\/=\r\n]+$/D', $publicKeyBase64)) return '';

    $publicKeyBase64 = preg_replace('/\s+/', '', $publicKeyBase64);
    if (!is_string($publicKeyBase64) || base64_decode($publicKeyBase64, true) === false) return '';
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split($publicKeyBase64, 64, "\n") . "-----END PUBLIC KEY-----\n";
    $key = openssl_pkey_get_public($pem);
    if ($key === false) return '';
    $details = openssl_pkey_get_details($key);
    if (!is_array($details) || empty($details['bits'])) return '';

    $keyBytes = (int) ceil(((int) $details['bits']) / 8);
    $hashLength = 32;
    $messageLength = strlen($plaintext);
    if ($keyBytes < 128 || $messageLength > $keyBytes - (2 * $hashLength) - 2) return '';

    $labelHash = hash('sha256', '', true);
    $padding = str_repeat("\0", $keyBytes - $messageLength - (2 * $hashLength) - 2);
    $dataBlock = $labelHash . $padding . "\x01" . $plaintext;
    try {
        $seed = random_bytes($hashLength);
    } catch (Throwable $e) {
        return '';
    }
    $dbMask = binanceGiftCardMgf1($seed, $keyBytes - $hashLength - 1);
    if (strlen($dbMask) !== strlen($dataBlock)) return '';
    $maskedDb = $dataBlock ^ $dbMask;
    $seedMask = binanceGiftCardMgf1($maskedDb, $hashLength);
    if (strlen($seedMask) !== $hashLength) return '';
    $encodedMessage = "\0" . ($seed ^ $seedMask) . $maskedDb;

    $encrypted = '';
    if (!openssl_public_encrypt($encodedMessage, $encrypted, $key, OPENSSL_NO_PADDING)) return '';
    // Binance's official sample uses URL-safe Base64 without padding. Standard
    // Base64 can be parsed differently after form encoding by some gateways.
    return rtrim(strtr(base64_encode($encrypted), '+/', '-_'), '=');
}

/** @return array<string,mixed> */
function binanceGiftCardPrepareEncryptedCode(string $code): array
{
    $keyResult = binanceGiftCardSignedRequest('GET', '/sapi/v1/giftcard/cryptography/rsa-public-key');
    $publicKey = $keyResult['data'] ?? null;
    if (empty($keyResult['success']) || !is_string($publicKey) || strlen($publicKey) < 100) {
        error_log('Binance Gift Card RSA public-key request failed; code=' . substr((string) ($keyResult['api_code'] ?? ''), 0, 32));
        return [
            'success' => false,
            'message' => Lang::t('giftcard.error.unavailable'),
            'stage' => 'rsa_public_key',
            'api_result' => $keyResult,
        ];
    }
    $encrypted = binanceGiftCardRsaOaepSha256Encrypt($code, $publicKey);
    if ($encrypted === '' || !preg_match('/^[A-Za-z0-9_-]+$/D', $encrypted)) {
        error_log('Binance Gift Card local RSA encryption failed.');
        return [
            'success' => false,
            'message' => Lang::t('giftcard.error.unavailable'),
            'stage' => 'rsa_encrypt',
            'api_result' => [
                'success' => false,
                'unknown' => false,
                'http_code' => 0,
                'api_code' => 'LOCAL_RSA',
                'message' => 'local_rsa_encryption_failed',
            ],
        ];
    }
    return ['success' => true, 'code' => $encrypted, 'stage' => 'rsa_ready'];
}

/** @return array{success:bool,message:string} */
function testBinanceGiftCardApi(): array
{
    $result = binanceGiftCardSignedRequest('GET', '/sapi/v1/giftcard/cryptography/rsa-public-key');
    $publicKey = $result['data'] ?? null;
    if (empty($result['success']) || !is_string($publicKey) || strlen($publicKey) < 100) {
        return ['success' => false, 'message' => binanceGiftCardAdminApiError($result)];
    }

    // This is a local-only self-test. The dummy value is never sent to Binance,
    // so it does not consume an invalid redemption attempt.
    $encrypted = binanceGiftCardRsaOaepSha256Encrypt('TEST000000000000', $publicKey);
    if ($encrypted === '' || !preg_match('/^[A-Za-z0-9_-]+$/D', $encrypted)) {
        return ['success' => false, 'message' => Lang::t('admin.settings.giftcard_error_local_rsa')];
    }

    return ['success' => true, 'message' => Lang::t('admin.settings.giftcard_test_success')];
}

/** @param array<string,mixed> $result */
function binanceGiftCardAdminApiError(array $result): string
{
    $code = (string) ($result['api_code'] ?? '');
    if ($code === '-1021') return Lang::t('admin.settings.giftcard_error_clock');
    if ($code === '-1022') return Lang::t('admin.settings.giftcard_error_signature');
    if ($code === '-2015' || $code === '-1002' || $code === 'CONFIG') return Lang::t('admin.settings.giftcard_error_credentials');
    if ($code === '-18004' || $code === '-18005') return Lang::t('admin.settings.giftcard_error_provider_limit');
    return Lang::t('admin.settings.giftcard_test_failed');
}

/**
 * Classify a failed Binance request without exposing provider details publicly.
 *
 * Binance does not document stable, distinct status codes for every invalid,
 * expired, or previously redeemed Gift Card. A real redemption test confirmed
 * that valid codes succeed while some unusable codes return a generic provider
 * rejection. For the redeem endpoint, every deterministic provider rejection
 * that is not clearly a transport, rate-limit, or credential problem is treated
 * as an unusable code. This prevents a bad/used code from being reported as a
 * temporary website outage.
 *
 * @param array<string,mixed> $result
 */
function binanceGiftCardFailureStatus(array $result, string $context = 'redeem'): string
{
    $code = strtoupper(trim((string) ($result['api_code'] ?? '')));
    $httpCode = (int) ($result['http_code'] ?? 0);
    $message = strtolower(trim((string) ($result['message'] ?? '')));
    $context = $context === 'preflight' ? 'preflight' : 'redeem';

    // A state-changing POST with a transport/5xx/malformed response has an
    // unknown outcome. Never invite the user to submit the same code again.
    if (!empty($result['unknown']) || in_array($code, ['-1000', '-1001', '-1006', '-1007', 'TRANSPORT', 'INVALID_JSON'], true)) {
        return 'unknown_requires_review';
    }

    if ($httpCode === 418 || $httpCode === 429 || in_array($code, ['-1003', '-1008', '-1015', '-18004', '-18005'], true)) {
        return 'provider_limit';
    }

    // Exact infrastructure/credential/signature failures must be detected
    // before searching for generic words such as "invalid" in the message.
    $configurationCodes = [
        '-1021', '-1022', '-2014', '-2015', '-1002',
        '-1100', '-1101', '-1102', '-1103', '-1104', '-1105', '-1106',
        'CONFIG', 'ENDPOINT', 'CURL', 'LOCAL_RSA',
    ];
    $configurationText = strpos($message, 'api key') !== false
        || strpos($message, 'api-key') !== false
        || strpos($message, 'signature') !== false
        || strpos($message, 'timestamp') !== false
        || strpos($message, 'permission') !== false
        || strpos($message, 'not authorized') !== false
        || strpos($message, 'unauthorized') !== false
        || strpos($message, 'access denied') !== false
        || strpos($message, 'ip address') !== false
        || strpos($message, 'whitelist') !== false
        || strpos($message, 'forbidden') !== false
        || strpos($message, 'waf') !== false
        || strpos($message, 'security block') !== false;

    if ($httpCode === 401 || $httpCode === 404 || $httpCode === 405
        || in_array($code, $configurationCodes, true) || $configurationText) {
        return 'configuration_error';
    }

    // Gift-card-specific text takes precedence over an otherwise generic HTTP
    // response. These internal statuses all share one concise public message.
    if (strpos($message, 'already') !== false || strpos($message, 'redeemed') !== false || strpos($message, 'used') !== false) return 'already_redeemed';
    if (strpos($message, 'expired') !== false || strpos($message, 'expiration') !== false) return 'expired';
    if (strpos($message, 'invalid') !== false || strpos($message, 'incorrect') !== false
        || strpos($message, 'not found') !== false || strpos($message, 'does not exist') !== false
        || strpos($message, 'redeem code error') !== false || strpos($message, 'redemption code error') !== false
        || strpos($message, 'code error') !== false || strpos($message, 'wrong code') !== false) {
        return 'invalid';
    }

    // Preflight calls do not consume a Gift Card. Any unrecognised preflight
    // failure is therefore an integration/configuration problem, not a bad code.
    if ($context === 'preflight') {
        return 'configuration_error';
    }

    // The redeem request reached Binance and returned a deterministic response,
    // but Binance did not provide a stable public reason. Treat it as an
    // unusable code instead of reporting a false website outage.
    return 'invalid';
}

function binanceGiftCardSafeStatusMessage(string $status): string
{
    $map = [
        'already_redeemed' => 'giftcard.error.used_or_invalid',
        'expired' => 'giftcard.error.used_or_invalid',
        'invalid' => 'giftcard.error.used_or_invalid',
        'provider_limit' => 'giftcard.error.provider_limit',
        'configuration_error' => 'giftcard.error.unavailable',
        'preflight_error' => 'giftcard.error.unavailable',
        'unknown_requires_review' => 'giftcard.error.review',
        'received' => 'giftcard.error.processing',
        'redeeming' => 'giftcard.error.processing',
        'redeemed_pending_credit' => 'giftcard.error.review',
        'unsupported_token' => 'giftcard.error.not_usdt',
        'amount_out_of_range' => 'giftcard.error.amount_review',
        'rejected' => 'giftcard.error.used_or_invalid',
    ];
    return Lang::t($map[$status] ?? 'giftcard.error.unavailable');
}

/** Invalid/used codes need a concise public message, not a diagnostic suffix. */
function binanceGiftCardPublicRequestIdForStatus(string $status, string $requestId): string
{
    if (in_array($status, ['invalid', 'expired', 'already_redeemed', 'rejected'], true)) {
        return '';
    }
    return $requestId;
}

function binanceGiftCardInvalidAttemptsToday(): int
{
    global $conn;
    // Binance documents the protection window as a rolling 24 hours, not a
    // website-calendar day. Keep a safety margin below the provider threshold.
    $sql = "SELECT COUNT(*) FROM binance_giftcard_redemptions
            WHERE requested_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
              AND status IN ('invalid','already_redeemed','expired','rejected')";
    $result = $conn->query($sql);
    if (!$result) return 999;
    $row = $result->fetch_row();
    return (int) ($row[0] ?? 0);
}

function binanceGiftCardUserAttemptsToday(int $userId): int
{
    global $conn;
    $stmt = $conn->prepare('SELECT COUNT(*) FROM binance_giftcard_redemptions WHERE user_id = ? AND requested_at >= CURDATE()');
    if (!$stmt) return 999;
    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) {
        $stmt->close();
        return 999;
    }
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    return (int) $count;
}

function binanceGiftCardUserRedeemedToday(int $userId): float
{
    global $conn;
    $stmt = $conn->prepare("SELECT COALESCE(SUM(token_amount),0)
                            FROM binance_giftcard_redemptions
                            WHERE user_id = ? AND requested_at >= CURDATE()
                              AND token = 'USDT'
                              AND status IN ('completed','redeemed_pending_credit','amount_out_of_range','unknown_requires_review')");
    if (!$stmt) return 1000000000.0;
    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) {
        $stmt->close();
        return 1000000000.0;
    }
    $stmt->bind_result($amount);
    $stmt->fetch();
    $stmt->close();
    return (float) $amount;
}

/** @return array<string,mixed>|null */
function binanceGiftCardFindByFingerprint(string $fingerprint): ?array
{
    global $conn;
    $stmt = $conn->prepare('SELECT id, request_id, user_id, status, request_stage, provider_requested_at, token, token_amount, credit_amount, transaction_id, requested_at, api_code, http_code, provider_message_safe FROM binance_giftcard_redemptions WHERE code_fingerprint = ? LIMIT 1');
    if (!$stmt) return null;
    $stmt->bind_param('s', $fingerprint);
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

/** Persist a safe correction for V3/V4 provider failures when re-evaluated. */
function binanceGiftCardPersistReclassifiedStatus(int $id, string $status): void
{
    global $conn;
    if ($id < 1 || !in_array($status, ['invalid', 'expired', 'already_redeemed'], true)) return;
    if (!isset($conn) || !($conn instanceof mysqli)) return;

    $stmt = $conn->prepare(
        "UPDATE binance_giftcard_redemptions
         SET status = ?, api_message_safe = 'legacy_provider_rejection_reclassified'
         WHERE id = ? AND status = 'configuration_error' AND request_stage = 'provider_response'"
    );
    if (!$stmt) return;
    $stmt->bind_param('si', $status, $id);
    try {
        $stmt->execute();
    } catch (Throwable $e) {
        // Public behaviour remains correct even if the audit correction cannot
        // be persisted on an older database schema.
    }
    $stmt->close();
}

function binanceGiftCardExistingMessage(array $row, int $userId): array
{
    $sameUser = (int) ($row['user_id'] ?? 0) === $userId;
    $status = (string) ($row['status'] ?? '');
    $requestId = $sameUser ? (string) ($row['request_id'] ?? '') : '';

    // V3/V4 could classify a generic provider rejection as configuration_error,
    // particularly when Binance returned HTTP 403 without a stable Gift Card
    // reason. Re-evaluate those existing provider responses with the stricter V5
    // classifier so resubmitting the same unusable code shows the correct public
    // message instead of pretending the whole service is down.
    if ($status === 'configuration_error'
        && (string) ($row['request_stage'] ?? '') === 'provider_response'
        && !empty($row['provider_requested_at'])) {
        $reclassified = binanceGiftCardFailureStatus([
            'unknown' => false,
            'http_code' => (int) ($row['http_code'] ?? 0),
            'api_code' => (string) ($row['api_code'] ?? ''),
            'message' => (string) ($row['provider_message_safe'] ?? ''),
        ], 'redeem');
        if (in_array($reclassified, ['invalid', 'expired', 'already_redeemed'], true)) {
            $status = $reclassified;
            binanceGiftCardPersistReclassifiedStatus((int) ($row['id'] ?? 0), $status);
        }
    }

    if ($sameUser && $status === 'completed') {
        return binanceGiftCardPublicResult(Lang::t('giftcard.error.already_credited'), $requestId);
    }
    if ($sameUser && $status !== '') {
        $pending = in_array($status, ['received','redeeming','redeemed_pending_credit','unknown_requires_review','amount_out_of_range','unsupported_token'], true);
        $publicRequestId = binanceGiftCardPublicRequestIdForStatus($status, $requestId);
        return binanceGiftCardPublicResult(binanceGiftCardSafeStatusMessage($status), $publicRequestId, $pending);
    }
    // Never reveal another account's support identifier or redemption state.
    return ['success' => false, 'message' => Lang::t('giftcard.error.already_submitted')];
}

function binanceGiftCardSetStatus(
    int $id,
    string $status,
    string $apiCode = '',
    string $safeMessage = '',
    string $stage = '',
    int $httpCode = 0,
    string $providerMessage = ''
): bool {
    global $conn;
    $providerMessage = binanceGiftCardSanitizeProviderMessage($providerMessage);
    $stmt = $conn->prepare(
        "UPDATE binance_giftcard_redemptions
         SET status = ?, api_code = ?, api_message_safe = ?,
             request_stage = IF(? = '', request_stage, ?),
             http_code = IF(? = 0, http_code, ?),
             provider_message_safe = IF(? = '', provider_message_safe, ?)
         WHERE id = ?"
    );
    if (!$stmt) return false;
    $stmt->bind_param(
        'sssssiissi',
        $status,
        $apiCode,
        $safeMessage,
        $stage,
        $stage,
        $httpCode,
        $httpCode,
        $providerMessage,
        $providerMessage,
        $id
    );
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function binanceGiftCardMarkProviderRequested(int $id): bool
{
    global $conn;
    $stmt = $conn->prepare(
        "UPDATE binance_giftcard_redemptions
         SET status = 'redeeming', request_stage = 'provider_request', provider_requested_at = NOW()
         WHERE id = ? AND status IN ('received','preflight_error','configuration_error')"
    );
    if (!$stmt) return false;
    $stmt->bind_param('i', $id);
    $ok = $stmt->execute() && $stmt->affected_rows === 1;
    $stmt->close();
    return $ok;
}

/**
 * A redeem request that remains in-flight for too long has an unknown provider
 * outcome. Never retry the code automatically because Binance may already have
 * consumed it even when the response was lost.
 */
function binanceGiftCardMarkStaleRedeeming(int $minutes = 10): int
{
    global $conn;
    $minutes = max(5, min(120, $minutes));
    $cutoff = date('Y-m-d H:i:s', time() - ($minutes * 60));
    $stmt = $conn->prepare(
        "UPDATE binance_giftcard_redemptions
         SET status = 'unknown_requires_review', api_message_safe = 'stale_redeem_unknown'
         WHERE status = 'redeeming' AND requested_at < ?"
    );
    if (!$stmt) return 0;
    $stmt->bind_param('s', $cutoff);
    $ok = $stmt->execute();
    $affected = $ok ? (int) $stmt->affected_rows : 0;
    $stmt->close();
    return $affected;
}

/**
 * Credit a redemption that Binance has already confirmed.
 * This function never calls the redeem endpoint and is safe to retry.
 *
 * @return array<string,mixed>
 */
function creditBinanceGiftCardRedemption(int $redemptionId): array
{
    global $conn;
    $settings = getBinanceGiftCardSettings();

    $stmt = $conn->prepare('SELECT id, user_id, token, token_amount, status, transaction_id, reference_no, exchange_rate, credit_currency, credit_percent, count_for_ranking, rank_bonus_enabled FROM binance_giftcard_redemptions WHERE id = ? LIMIT 1');
    if (!$stmt) return ['success' => false, 'message' => Lang::t('giftcard.error.unavailable')];
    $stmt->bind_param('i', $redemptionId);
    if (!$stmt->execute()) {
        $stmt->close();
        return ['success' => false, 'message' => Lang::t('giftcard.error.unavailable')];
    }
    $readResult = $stmt->get_result();
    $initial = $readResult ? $readResult->fetch_assoc() : null;
    $stmt->close();

    if (!$initial) return ['success' => false, 'message' => Lang::t('giftcard.error.unavailable')];
    if (!empty($initial['transaction_id']) || (string) $initial['status'] === 'completed') {
        return ['success' => false, 'message' => Lang::t('giftcard.error.already_credited')];
    }
    if ((string) $initial['token'] !== 'USDT') {
        return ['success' => false, 'pending' => true, 'message' => Lang::t('giftcard.error.not_usdt')];
    }
    if (!in_array((string) $initial['status'], ['redeemed_pending_credit', 'amount_out_of_range'], true)) {
        return ['success' => false, 'pending' => true, 'message' => Lang::t('giftcard.error.review')];
    }

    $amountUsdt = (float) $initial['token_amount'];
    if (!is_finite($amountUsdt) || $amountUsdt <= 0) {
        return ['success' => false, 'pending' => true, 'message' => Lang::t('giftcard.error.amount_review')];
    }
    if ($amountUsdt < (float) $settings['min_usdt'] || $amountUsdt > (float) $settings['max_usdt']) {
        binanceGiftCardSetStatus($redemptionId, 'amount_out_of_range', '', 'amount_out_of_range');
        return ['success' => false, 'pending' => true, 'message' => Lang::t('giftcard.error.amount_review')];
    }

    $todayTotal = binanceGiftCardUserRedeemedToday((int) $initial['user_id']);
    if ($todayTotal > (float) $settings['daily_usdt'] + 0.00000001) {
        binanceGiftCardSetStatus($redemptionId, 'amount_out_of_range', '', 'daily_amount_limit');
        return ['success' => false, 'pending' => true, 'message' => Lang::t('giftcard.error.amount_review')];
    }

    // Monetary policy is snapshotted before the provider redemption request.
    // A later admin setting change must not retroactively alter an accepted card.
    $appCurrency = strtoupper((string) ($initial['credit_currency'] ?? 'THB'));
    if (!in_array($appCurrency, ['THB', 'USD'], true)) {
        return ['success' => false, 'pending' => true, 'message' => Lang::t('giftcard.error.currency')];
    }
    $countForRanking = !empty($initial['count_for_ranking']);
    $rankBonusEnabled = !empty($initial['rank_bonus_enabled']);
    $needRate = $appCurrency === 'THB' || $countForRanking;
    $rate = (float) ($initial['exchange_rate'] ?? 0);
    if ($needRate && (!is_finite($rate) || $rate <= 0)) {
        return ['success' => false, 'pending' => true, 'message' => Lang::t('deposit.error.exchange_rate_failed')];
    }

    $creditPercent = max(1.0, min(100.0, (float) ($initial['credit_percent'] ?? 100)));
    $netUsdt = round($amountUsdt * ($creditPercent / 100), 8);
    $creditAmount = $appCurrency === 'THB'
        ? round($netUsdt * $rate, 2)
        : round($netUsdt, 2);
    $rankAmountThb = round($amountUsdt * $rate, 2);

    if (!is_finite($creditAmount) || $creditAmount <= 0 || $creditAmount > 10000000) {
        return ['success' => false, 'pending' => true, 'message' => Lang::t('giftcard.error.amount_review')];
    }
    if ($countForRanking && (!function_exists('ensureRankingSchema') || !ensureRankingSchema())) {
        return ['success' => false, 'pending' => true, 'message' => Lang::t('ranking.error.unavailable')];
    }
    if (!function_exists('ensureWalletLedgerSchema') || !ensureWalletLedgerSchema()) {
        return ['success' => false, 'pending' => true, 'message' => 'ระบบบันทึกหลักฐานยอดเงินยังไม่พร้อม กรุณาลองใหม่อีกครั้ง'];
    }

    $conn->begin_transaction();
    try {
        $lock = $conn->prepare(
            "SELECT g.user_id, g.token, g.token_amount, g.status, g.transaction_id, g.reference_no,
                    g.exchange_rate, g.credit_currency, g.credit_percent,
                    g.count_for_ranking, g.rank_bonus_enabled,
                    u.role, u.status AS user_status
             FROM binance_giftcard_redemptions g
             INNER JOIN users u ON u.id = g.user_id
             WHERE g.id = ? LIMIT 1 FOR UPDATE"
        );
        if (!$lock) throw new RuntimeException('giftcard_lock_prepare');
        $lock->bind_param('i', $redemptionId);
        if (!$lock->execute()) {
            $lock->close();
            throw new RuntimeException('giftcard_lock_read');
        }
        $lockResult = $lock->get_result();
        $row = $lockResult ? $lockResult->fetch_assoc() : null;
        $lock->close();

        if (!$row || !empty($row['transaction_id']) || (string) $row['status'] === 'completed') {
            throw new RuntimeException('giftcard_already_credited');
        }
        if ((string) $row['user_status'] !== 'active' || !in_array((string) $row['role'], ['user', 'reseller'], true)) {
            throw new RuntimeException('giftcard_user_ineligible');
        }
        if (!in_array((string) $row['status'], ['redeemed_pending_credit', 'amount_out_of_range'], true)) {
            throw new RuntimeException('giftcard_status_not_creditable');
        }
        if ((string) $row['token'] !== 'USDT' || abs((float) $row['token_amount'] - $amountUsdt) > 0.00000001) {
            throw new RuntimeException('giftcard_financial_conflict');
        }
        if (strtoupper((string) $row['credit_currency']) !== $appCurrency
            || abs((float) $row['exchange_rate'] - $rate) > 0.00000001
            || abs((float) $row['credit_percent'] - $creditPercent) > 0.0001
            || (!empty($row['count_for_ranking'])) !== $countForRanking
            || (!empty($row['rank_bonus_enabled'])) !== $rankBonusEnabled) {
            throw new RuntimeException('giftcard_policy_conflict');
        }

        $userId = (int) $row['user_id'];
        // The user row is locked by the joined FOR UPDATE query above. Recheck
        // the daily total now so concurrent cards for one account cannot both
        // pass a stale pre-transaction limit check.
        $daily = $conn->prepare(
            "SELECT COALESCE(SUM(token_amount),0)
             FROM binance_giftcard_redemptions
             WHERE user_id = ? AND id <> ? AND requested_at >= CURDATE()
               AND token = 'USDT'
               AND status IN ('completed','redeemed_pending_credit','amount_out_of_range','unknown_requires_review')"
        );
        if (!$daily) throw new RuntimeException('giftcard_daily_prepare');
        $daily->bind_param('ii', $userId, $redemptionId);
        if (!$daily->execute()) {
            $daily->close();
            throw new RuntimeException('giftcard_daily_read');
        }
        $daily->bind_result($dailyAmountRaw);
        $daily->fetch();
        $daily->close();
        if ((float) $dailyAmountRaw + $amountUsdt > (float) $settings['daily_usdt'] + 0.00000001) {
            throw new RuntimeException('giftcard_daily_limit');
        }
        $walletBefore = walletLedgerReadBalance($userId, true);
        if ($walletBefore === null) throw new RuntimeException('giftcard_wallet_read_failed');
        if (!addBalance($userId, $creditAmount)) {
            throw new RuntimeException('giftcard_balance_failed');
        }

        $desc = 'Binance Gift Card: ' . number_format($amountUsdt, 8, '.', '') . ' USDT';
        if ($creditPercent < 100) $desc .= ' Credit: ' . number_format($creditPercent, 2, '.', '') . '%';

        $transactionId = createTransaction($userId, 'deposit', $creditAmount, 'completed', $desc);
        if (!$transactionId) throw new RuntimeException('giftcard_transaction_failed');
        $walletAfter = round((float) $walletBefore + $creditAmount, 2);
        $safeReference = trim((string) ($row['reference_no'] ?? ''));
        if (!walletLedgerRecordMovement(
            $userId, $creditAmount, (float) $walletBefore, $walletAfter,
            'binance_giftcard_deposit', 'transaction:' . (int) $transactionId,
            $redemptionId, (int) $transactionId, null,
            'เติมเงินผ่าน Binance Gift Card',
            'Gift Card credit; the redemption code is intentionally excluded from wallet audit.',
            $safeReference !== '' ? $safeReference : null, true
        )) throw new RuntimeException('giftcard_wallet_audit_failed');

        $bonusAmount = 0.0;
        $totalCredited = $creditAmount;
        if ($countForRanking) {
            $rankResult = rankRecordDepositAndApplyBonus(
                $userId,
                (int) $transactionId,
                $rankAmountThb,
                'binance_giftcard',
                'usd_to_thb_rate',
                $rankBonusEnabled
            );
            if (empty($rankResult['success'])) {
                throw new RuntimeException('giftcard_ranking_failed');
            }
            $bonusAmount = round((float) ($rankResult['bonus_amount'] ?? 0), 2);
            $totalCredited = round((float) ($rankResult['total_credited'] ?? $creditAmount), 2);
        }

        $finish = $conn->prepare(
            "UPDATE binance_giftcard_redemptions
             SET exchange_rate = ?, credit_percent = ?, credit_amount = ?, transaction_id = ?,
                 status = 'completed', api_message_safe = 'completed', credited_at = NOW()
             WHERE id = ? AND transaction_id IS NULL"
        );
        if (!$finish) throw new RuntimeException('giftcard_finish_prepare');
        $finish->bind_param('dddii', $rate, $creditPercent, $creditAmount, $transactionId, $redemptionId);
        $finished = $finish->execute() && $finish->affected_rows === 1;
        $finish->close();
        if (!$finished) throw new RuntimeException('giftcard_finish_failed');

        if (!$conn->commit()) {
            throw new RuntimeException('giftcard_commit_failed');
        }

        // History is secondary audit data. Never roll back a financial credit
        // that has already passed wallet/ranking/redemption integrity checks.
        if (!logHistory($userId, 'binance_giftcard_deposit', 'Binance Gift Card credited: ' . number_format($amountUsdt, 8, '.', '') . ' USDT; redemption #' . $redemptionId)) {
            error_log('Binance Gift Card committed but audit history could not be written; redemption=' . $redemptionId . '; transaction=' . (int) $transactionId);
        }
        return [
            'success' => true,
            'amount_usdt' => $amountUsdt,
            'amount' => $creditAmount,
            'amount_store' => $creditAmount,
            'amount_thb' => $appCurrency === 'THB' ? $creditAmount : $rankAmountThb,
            'currency' => $appCurrency,
            'bonus_amount' => $bonusAmount,
            'total_credited' => $totalCredited,
            'new_balance' => (float) getUserBalance($userId),
            'message' => Lang::t('giftcard.success'),
        ];
    } catch (Throwable $e) {
        $conn->rollback();
        if ($e->getMessage() === 'giftcard_already_credited') {
            // A concurrent request completed the credit first. Do not move a
            // completed row backwards to a pending state.
            return ['success' => false, 'message' => Lang::t('giftcard.error.already_credited')];
        }
        if ($e->getMessage() === 'giftcard_daily_limit') {
            binanceGiftCardSetStatus($redemptionId, 'amount_out_of_range', '', 'daily_amount_limit');
            return ['success' => false, 'pending' => true, 'message' => Lang::t('giftcard.error.amount_review')];
        }
        error_log('Binance Gift Card credit failed; redemption=' . $redemptionId . '; reason=' . $e->getMessage());
        binanceGiftCardSetStatus($redemptionId, 'redeemed_pending_credit', '', 'credit_pending');
        return ['success' => false, 'pending' => true, 'message' => Lang::t('giftcard.error.review')];
    }
}

/**
 * Redeem a 16-character Binance Gift Card code and credit the user if eligible.
 *
 * @return array<string,mixed>
 */
function processBinanceGiftCardRedemption(int $userId, $rawCode): array
{
    global $conn;
    $settings = getBinanceGiftCardSettings();
    if (empty($settings['enabled'])) {
        return ['success' => false, 'message' => Lang::t('giftcard.error.disabled')];
    }
    if (!ensureBinanceGiftCardSchema()) {
        return ['success' => false, 'message' => Lang::t('giftcard.error.unavailable')];
    }

    $code = normalizeBinanceGiftCardCode($rawCode);
    if (!isValidBinanceGiftCardCode($code)) {
        return ['success' => false, 'message' => Lang::t('giftcard.error.format')];
    }

    $user = getUserById($userId);
    if (!$user || (string) ($user['status'] ?? '') !== 'active') {
        return ['success' => false, 'message' => Lang::t('common.error.invalid_request')];
    }
    $role = (string) ($user['role'] ?? '');
    if (($role === 'user' && empty($settings['user_enabled'])) || ($role === 'reseller' && empty($settings['reseller_enabled'])) || !in_array($role, ['user', 'reseller'], true)) {
        return ['success' => false, 'message' => Lang::t('giftcard.error.role_disabled')];
    }

    $accountAgeDays = (int) $settings['account_age_days'];
    if ($accountAgeDays > 0) {
        $createdAt = strtotime((string) ($user['created_at'] ?? ''));
        if (!$createdAt || $createdAt > time() - ($accountAgeDays * 86400)) {
            return ['success' => false, 'message' => Lang::t('giftcard.error.account_age')];
        }
    }

    if (binanceGiftCardInvalidAttemptsToday() >= (int) $settings['global_invalid_limit']) {
        return ['success' => false, 'message' => Lang::t('giftcard.error.provider_limit')];
    }
    if (binanceGiftCardUserAttemptsToday($userId) >= (int) $settings['user_attempts_per_day']) {
        return ['success' => false, 'message' => Lang::t('giftcard.error.user_limit')];
    }

    binanceGiftCardMarkStaleRedeeming();

    $fingerprint = binanceGiftCardFingerprint($code);
    if ($fingerprint === '') {
        return ['success' => false, 'message' => Lang::t('giftcard.error.unavailable')];
    }
    $existing = binanceGiftCardFindByFingerprint($fingerprint);
    if ($existing) {
        return binanceGiftCardExistingMessage($existing, $userId);
    }

    $appCurrency = strtoupper((string) (getSetting('currency_name') ?: 'THB'));
    if (!in_array($appCurrency, ['THB', 'USD'], true)) {
        unset($code, $rawCode);
        return ['success' => false, 'message' => Lang::t('giftcard.error.currency')];
    }

    $requestId = binanceGiftCardRequestId();
    $last4 = substr($code, -4);
    $externalUid = binanceGiftCardExternalUid($userId);
    $creditPercent = (float) $settings['credit_percent'];
    $countForRanking = !empty($settings['count_for_ranking']);
    $rankBonusEnabled = !empty($settings['rank_bonus_enabled']);
    $countForRankingInt = $countForRanking ? 1 : 0;
    $rankBonusEnabledInt = $rankBonusEnabled ? 1 : 0;

    // Create the audit row before exchange-rate, RSA, or provider work. Every
    // syntactically valid unique request therefore remains traceable even when a
    // preflight dependency fails.
    $insert = $conn->prepare(
        "INSERT INTO binance_giftcard_redemptions
         (request_id, user_id, role_at_redeem, code_fingerprint, code_last4, external_uid,
          exchange_rate, credit_currency, credit_percent, count_for_ranking, rank_bonus_enabled,
          status, request_stage, api_message_safe)
         VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, 'received', 'received', 'received')"
    );
    if (!$insert) {
        error_log('Binance Gift Card audit insert prepare failed; request=' . $requestId . '; errno=' . (int) $conn->errno);
        return binanceGiftCardPublicResult(Lang::t('giftcard.error.unavailable'), $requestId);
    }
    $insert->bind_param(
        'sisssssdii',
        $requestId,
        $userId,
        $role,
        $fingerprint,
        $last4,
        $externalUid,
        $appCurrency,
        $creditPercent,
        $countForRankingInt,
        $rankBonusEnabledInt
    );
    try {
        $insertOk = $insert->execute();
    } catch (Throwable $e) {
        $insertOk = false;
    }
    $redemptionId = $insertOk ? (int) $conn->insert_id : 0;
    $insertErrno = (int) $insert->errno;
    $insert->close();
    if (!$insertOk || $redemptionId < 1) {
        $existing = binanceGiftCardFindByFingerprint($fingerprint);
        if ($existing) return binanceGiftCardExistingMessage($existing, $userId);
        error_log('Binance Gift Card audit insert failed; request=' . $requestId . '; errno=' . $insertErrno);
        return binanceGiftCardPublicResult(Lang::t('giftcard.error.unavailable'), $requestId);
    }

    $needRate = $appCurrency === 'THB' || $countForRanking;
    $snapshotRate = $needRate ? (float) getExchangeRateUsdToThb() : 0.0;
    if ($needRate && (!is_finite($snapshotRate) || $snapshotRate <= 0)) {
        binanceGiftCardSetStatus($redemptionId, 'preflight_error', 'EXCHANGE_RATE', 'exchange_rate_failed', 'exchange_rate');
        unset($code, $rawCode);
        return binanceGiftCardPublicResult(Lang::t('deposit.error.exchange_rate_failed'), $requestId);
    }

    $snapshot = $conn->prepare(
        "UPDATE binance_giftcard_redemptions
         SET exchange_rate = ?, request_stage = 'policy_ready', api_message_safe = 'policy_ready'
         WHERE id = ? AND status = 'received'"
    );
    if (!$snapshot) {
        binanceGiftCardSetStatus($redemptionId, 'preflight_error', 'DB_PREPARE', 'policy_snapshot_prepare_failed', 'policy_snapshot');
        unset($code, $rawCode);
        return binanceGiftCardPublicResult(Lang::t('giftcard.error.unavailable'), $requestId);
    }
    $snapshot->bind_param('di', $snapshotRate, $redemptionId);
    $snapshotOk = $snapshot->execute() && $snapshot->affected_rows === 1;
    $snapshot->close();
    if (!$snapshotOk) {
        binanceGiftCardSetStatus($redemptionId, 'preflight_error', 'DB_WRITE', 'policy_snapshot_failed', 'policy_snapshot');
        unset($code, $rawCode);
        return binanceGiftCardPublicResult(Lang::t('giftcard.error.unavailable'), $requestId);
    }

    $encryptedResult = binanceGiftCardPrepareEncryptedCode($code);
    if (empty($encryptedResult['success']) || empty($encryptedResult['code'])) {
        $diagnostic = is_array($encryptedResult['api_result'] ?? null) ? $encryptedResult['api_result'] : [];
        $stage = (string) ($encryptedResult['stage'] ?? 'rsa_prepare');
        $status = binanceGiftCardFailureStatus($diagnostic, 'preflight');
        if ($status === 'unknown_requires_review') $status = 'configuration_error'; // GET did not consume a card.
        $apiCode = substr((string) ($diagnostic['api_code'] ?? 'LOCAL_RSA'), 0, 32);
        $httpCode = (int) ($diagnostic['http_code'] ?? 0);
        $providerMessage = (string) ($diagnostic['message'] ?? '');
        binanceGiftCardSetStatus($redemptionId, $status, $apiCode, $stage, $stage, $httpCode, $providerMessage);
        unset($code, $rawCode);
        return binanceGiftCardPublicResult((string) ($encryptedResult['message'] ?? Lang::t('giftcard.error.unavailable')), $requestId);
    }
    $encryptedCode = (string) $encryptedResult['code'];

    if (!binanceGiftCardMarkProviderRequested($redemptionId)) {
        binanceGiftCardSetStatus($redemptionId, 'preflight_error', 'DB_STATE', 'provider_state_failed', 'provider_request');
        unset($code, $rawCode, $encryptedCode, $encryptedResult);
        return binanceGiftCardPublicResult(Lang::t('giftcard.error.unavailable'), $requestId);
    }

    // Only the RSA OAEP SHA-256 encrypted, URL-safe Base64 code is sent.
    $apiResult = binanceGiftCardSignedRequest('POST', '/sapi/v1/giftcard/redeemCode', [
        'code' => $encryptedCode,
        'externalUid' => $externalUid,
    ]);
    unset($code, $rawCode, $encryptedCode, $encryptedResult);

    if (empty($apiResult['success'])) {
        $status = binanceGiftCardFailureStatus($apiResult);
        $apiCode = substr((string) ($apiResult['api_code'] ?? ''), 0, 32);
        $httpCode = (int) ($apiResult['http_code'] ?? 0);
        $providerMessage = binanceGiftCardSanitizeProviderMessage((string) ($apiResult['message'] ?? ''));
        binanceGiftCardSetStatus($redemptionId, $status, $apiCode, $status, 'provider_response', $httpCode, $providerMessage);
        $publicRequestId = binanceGiftCardPublicRequestIdForStatus($status, $requestId);
        return binanceGiftCardPublicResult(
            binanceGiftCardSafeStatusMessage($status),
            $publicRequestId,
            $status === 'unknown_requires_review'
        );
    }

    $data = is_array($apiResult['data'] ?? null) ? $apiResult['data'] : [];
    $referenceNo = trim((string) ($data['referenceNo'] ?? ''));
    $identityNo = trim((string) ($data['identityNo'] ?? ''));
    $token = strtoupper(trim((string) ($data['token'] ?? '')));
    $amountRaw = $data['amount'] ?? null;
    $amount = is_scalar($amountRaw) && is_numeric((string) $amountRaw) ? (float) $amountRaw : 0.0;
    $httpCode = (int) ($apiResult['http_code'] ?? 200);

    if ($referenceNo === '' || strlen($referenceNo) > 64 || $identityNo === '' || strlen($identityNo) > 64 || !preg_match('/^[A-Z0-9]{2,32}$/', $token) || !is_finite($amount) || $amount <= 0 || $amount > 100000000) {
        binanceGiftCardSetStatus($redemptionId, 'unknown_requires_review', (string) ($apiResult['api_code'] ?? ''), 'invalid_success_payload', 'provider_payload', $httpCode, 'invalid_success_payload');
        return binanceGiftCardPublicResult(Lang::t('giftcard.error.review'), $requestId, true);
    }

    $nextStatus = 'redeemed_pending_credit';
    if ($token !== 'USDT') {
        $nextStatus = 'unsupported_token';
    } elseif ($amount < (float) $settings['min_usdt'] || $amount > (float) $settings['max_usdt']) {
        $nextStatus = 'amount_out_of_range';
    } elseif (binanceGiftCardUserRedeemedToday($userId) + $amount > (float) $settings['daily_usdt'] + 0.00000001) {
        $nextStatus = 'amount_out_of_range';
    }

    $save = $conn->prepare(
        "UPDATE binance_giftcard_redemptions
         SET reference_no = ?, identity_no = ?, token = ?, token_amount = ?, status = ?,
             request_stage = 'provider_accepted', api_code = ?, api_message_safe = ?,
             http_code = ?, provider_message_safe = 'success', redeemed_at = NOW()
         WHERE id = ? AND status = 'redeeming'"
    );
    if (!$save) {
        error_log('Binance Gift Card success could not be persisted; redemption=' . $redemptionId . '; request=' . $requestId);
        return binanceGiftCardPublicResult(Lang::t('giftcard.error.review'), $requestId, true);
    }
    $apiCode = substr((string) ($apiResult['api_code'] ?? '000000'), 0, 32);
    $safeStatus = $nextStatus;
    $save->bind_param('sssdsssii', $referenceNo, $identityNo, $token, $amount, $nextStatus, $apiCode, $safeStatus, $httpCode, $redemptionId);
    try {
        $saved = $save->execute() && $save->affected_rows === 1;
    } catch (Throwable $e) {
        $saved = false;
    }
    $save->close();
    if (!$saved) {
        error_log('Binance Gift Card provider result conflict; redemption=' . $redemptionId . '; request=' . $requestId);
        binanceGiftCardSetStatus($redemptionId, 'unknown_requires_review', $apiCode, 'provider_result_conflict', 'provider_persist', $httpCode, 'provider_result_conflict');
        return binanceGiftCardPublicResult(Lang::t('giftcard.error.review'), $requestId, true);
    }

    if ($nextStatus !== 'redeemed_pending_credit') {
        return binanceGiftCardPublicResult(binanceGiftCardSafeStatusMessage($nextStatus), $requestId, true);
    }

    $creditResult = creditBinanceGiftCardRedemption($redemptionId);
    if (empty($creditResult['success'])) {
        $creditResult['support_code'] = $requestId;
        $creditResult['message'] = (string) ($creditResult['message'] ?? Lang::t('giftcard.error.review'))
            . ' (' . Lang::t('giftcard.support_code') . ': ' . $requestId . ')';
    }
    return $creditResult;
}
