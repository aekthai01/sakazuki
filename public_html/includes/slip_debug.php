<?php
/**
 * Durable diagnostic logging for bank-slip verification.
 *
 * The logger is deliberately best-effort: a logging/schema/encryption failure
 * must never block a customer's deposit. Sensitive provider payloads are kept
 * encrypted at rest whenever OpenSSL is available, and image Base64/API keys
 * are never stored.
 */

require_once __DIR__ . '/db.php';

if (!defined('SLIP_DEBUG_MAX_JSON_BYTES')) define('SLIP_DEBUG_MAX_JSON_BYTES', 900 * 1024);
if (!defined('SLIP_DEBUG_AAD')) define('SLIP_DEBUG_AAD', 'slip_verification_debug.v1');

function slipDebugEnabled(): bool
{
    $value = getenv('SLIP_DEBUG_ENABLED');
    if ($value === false || trim((string) $value) === '') return true;
    return !in_array(strtolower(trim((string) $value)), ['0', 'false', 'off', 'no'], true);
}

function slipDebugRetentionDays(): int
{
    $value = filter_var(getenv('SLIP_DEBUG_RETENTION_DAYS'), FILTER_VALIDATE_INT);
    return ($value !== false && $value >= 1 && $value <= 365) ? (int) $value : 30;
}

function ensureSlipVerificationDebugTable(): bool
{
    global $conn;
    static $ready = null;
    if ($ready !== null) return $ready;
    if (!isset($conn) || !($conn instanceof mysqli)) return $ready = false;

    $sql = "CREATE TABLE IF NOT EXISTS slip_verification_debug_logs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        event_uuid CHAR(36) NOT NULL,
        attempt_uuid CHAR(36) NOT NULL DEFAULT '',
        slip_hash CHAR(64) NOT NULL DEFAULT '',
        user_id INT NOT NULL DEFAULT 0,
        site_id VARCHAR(100) NOT NULL DEFAULT '',
        site_host VARCHAR(255) NOT NULL DEFAULT '',
        stage VARCHAR(80) NOT NULL,
        severity VARCHAR(16) NOT NULL DEFAULT 'info',
        event_message VARCHAR(500) NOT NULL DEFAULT '',
        error_code VARCHAR(100) NOT NULL DEFAULT '',
        http_code SMALLINT UNSIGNED NULL,
        duration_ms INT UNSIGNED NULL,
        request_ciphertext LONGTEXT NULL,
        response_ciphertext LONGTEXT NULL,
        context_ciphertext LONGTEXT NULL,
        created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        PRIMARY KEY (id),
        UNIQUE KEY uq_slip_debug_event (event_uuid),
        KEY idx_slip_debug_attempt (attempt_uuid, id),
        KEY idx_slip_debug_hash (slip_hash, id),
        KEY idx_slip_debug_user (user_id, created_at),
        KEY idx_slip_debug_stage (stage, created_at),
        KEY idx_slip_debug_error (error_code, created_at),
        KEY idx_slip_debug_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    try {
        if (!$conn->query($sql)) {
            error_log('Slip debug schema setup failed: ' . $conn->error);
            return $ready = false;
        }
        return $ready = true;
    } catch (Throwable $e) {
        error_log('Slip debug schema setup exception: ' . $e->getMessage());
        return $ready = false;
    }
}

function ensureSlipVerificationDebugControlTable(): bool
{
    global $conn;
    static $ready = null;
    if ($ready !== null) return $ready;
    if (!isset($conn) || !($conn instanceof mysqli)) return $ready = false;

    $sql = "CREATE TABLE IF NOT EXISTS slip_verification_debug_controls (
        slip_hash CHAR(64) NOT NULL,
        force_provider_refresh_once TINYINT(1) NOT NULL DEFAULT 0,
        requested_by INT NOT NULL DEFAULT 0,
        requested_at DATETIME(6) NULL,
        consumed_at DATETIME(6) NULL,
        updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
        PRIMARY KEY (slip_hash),
        KEY idx_slip_debug_control_pending (force_provider_refresh_once, requested_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    try {
        if (!$conn->query($sql)) {
            error_log('Slip debug control schema setup failed: ' . $conn->error);
            return $ready = false;
        }
        return $ready = true;
    } catch (Throwable $e) {
        error_log('Slip debug control schema setup exception: ' . $e->getMessage());
        return $ready = false;
    }
}

function slipDebugUuid(): string
{
    try {
        $bytes = random_bytes(16);
    } catch (Throwable $e) {
        $bytes = substr(hash('sha256', uniqid('', true) . microtime(true), true), 0, 16);
    }
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
        . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
}

function slipDebugNormalizeUuid($value): string
{
    if (!is_scalar($value)) return '';
    $value = strtolower(trim((string) $value));
    return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $value)
        ? $value
        : '';
}

function slipDebugNormalizeHash($value): string
{
    if (!is_scalar($value)) return '';
    $value = strtolower(trim((string) $value));
    return preg_match('/^[a-f0-9]{64}$/D', $value) ? $value : '';
}

function slipDebugBase64UrlEncode(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function slipDebugBase64UrlDecode(string $value)
{
    $value = strtr($value, '-_', '+/');
    $padding = strlen($value) % 4;
    if ($padding !== 0) $value .= str_repeat('=', 4 - $padding);
    return base64_decode($value, true);
}

/** @return array<string,string> */
function slipDebugEncryptionKeys(): array
{
    $keys = [];
    try {
        if (function_exists('storeBridgeDecryptionKeys')) {
            foreach ((array) storeBridgeDecryptionKeys() as $candidate) {
                if (is_string($candidate) && strlen($candidate) === 32) {
                    $keys[hash('sha256', $candidate)] = $candidate;
                }
            }
        }
        if (function_exists('storeBridgeEncryptionKey')) {
            $primary = storeBridgeEncryptionKey(false);
            if (is_string($primary) && strlen($primary) === 32) {
                $keys[hash('sha256', $primary)] = $primary;
            }
        }
    } catch (Throwable $e) {
        error_log('Slip debug primary encryption key lookup failed: ' . $e->getMessage());
    }

    // Deterministic compatibility key for installations where Store Bridge key
    // creation has not run yet. It is derived from secrets that stay outside the
    // public web root and is never exposed in logs or exports.
    $fallbackSeed = (defined('DB_NAME') ? DB_NAME : '') . "\0"
        . (defined('DB_USER') ? DB_USER : '') . "\0"
        . (defined('DB_PASS') ? DB_PASS : '') . "\0"
        . dirname(__DIR__, 2) . "\0slip-debug-v1";
    $fallback = hash('sha256', $fallbackSeed, true);
    $keys[hash('sha256', $fallback)] = $fallback;
    return $keys;
}

function slipDebugScrubValue($value, int $depth = 0, string $keyName = '')
{
    if ($depth > 16) return '[MAX_DEPTH]';
    $normalizedKey = strtolower($keyName);
    if ($normalizedKey !== '') {
        $sensitiveExact = [
            'api_key', 'apikey', 'authorization', 'password', 'passwd', 'secret',
            'csrf_token', 'cookie', 'set-cookie', 'session_id', 'sessionid',
            'access_token', 'refresh_token', 'bearer_token', 'lease',
        ];
        $isSensitive = in_array($normalizedKey, $sensitiveExact, true)
            || preg_match('/(?:^|_)(?:password|passwd|secret|private_key|encryption_key|ciphertext)(?:$|_)/i', $normalizedKey) === 1
            || (preg_match('/(?:^|_)api[_-]?key(?:$|_)/i', $normalizedKey) === 1
                && strpos($normalizedKey, 'configured') === false
                && strpos($normalizedKey, 'fingerprint') === false
                && strpos($normalizedKey, 'sha256_prefix') === false);
        if ($isSensitive) return '[REDACTED]';
        if (preg_match('/(?:^|_)(?:base64|slip_base64|image_bytes|raw_image)(?:$|_)/i', $normalizedKey)) {
            if (is_string($value)) {
                return ['redacted' => true, 'encoded_bytes' => strlen($value), 'sha256' => hash('sha256', $value)];
            }
            return '[IMAGE_DATA_REDACTED]';
        }
    }

    if (is_array($value)) {
        $result = [];
        $count = 0;
        foreach ($value as $key => $item) {
            if ($count++ >= 500) {
                $result['_truncated_items'] = count($value) - 500;
                break;
            }
            $safeKey = is_int($key) ? $key : substr((string) $key, 0, 200);
            $result[$safeKey] = slipDebugScrubValue($item, $depth + 1, (string) $key);
        }
        return $result;
    }
    if (is_object($value)) return slipDebugScrubValue(get_object_vars($value), $depth + 1, $keyName);
    if (is_resource($value)) return '[RESOURCE]';
    if (is_float($value) && !is_finite($value)) return (string) $value;
    if (is_string($value)) {
        $value = str_replace("\0", '', $value);
        if (strlen($value) > 200000) {
            return substr($value, 0, 200000) . '\n[TRUNCATED ' . (strlen($value) - 200000) . ' BYTES]';
        }
    }
    return $value;
}

function slipDebugEncodePayload($payload): ?string
{
    if ($payload === null) return null;
    $safe = slipDebugScrubValue($payload);
    $json = json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($json)) return null;
    if (strlen($json) > SLIP_DEBUG_MAX_JSON_BYTES) {
        $summary = [
            '_truncated' => true,
            '_original_json_bytes' => strlen($json),
            '_sha256' => hash('sha256', $json),
            '_preview' => substr($json, 0, 120000),
        ];
        $json = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($json)) return null;
    }

    $compressed = function_exists('gzencode') ? gzencode($json, 6) : false;
    $plain = is_string($compressed) && strlen($compressed) < strlen($json) ? $compressed : $json;
    $compressedFlag = $plain !== $json;

    if (function_exists('openssl_encrypt')) {
        $keys = slipDebugEncryptionKeys();
        $key = reset($keys);
        if (is_string($key) && strlen($key) === 32) {
            try {
                $nonce = random_bytes(12);
                $tag = '';
                $cipher = openssl_encrypt(
                    $plain,
                    'aes-256-gcm',
                    $key,
                    OPENSSL_RAW_DATA,
                    $nonce,
                    $tag,
                    SLIP_DEBUG_AAD
                );
                if (is_string($cipher) && strlen($tag) === 16) {
                    return ($compressedFlag ? 'v2z.' : 'v2.') . slipDebugBase64UrlEncode($nonce . $tag . $cipher);
                }
            } catch (Throwable $e) {
                error_log('Slip debug payload encryption failed: ' . $e->getMessage());
            }
        }
    }

    // Compatibility only. The admin page clearly reports whether a payload used
    // this fallback. Base64 is not encryption, but retaining diagnostics is still
    // preferable to silently losing the evidence on a host without OpenSSL.
    return ($compressedFlag ? 'j1z.' : 'j1.') . slipDebugBase64UrlEncode($plain);
}

function slipDebugDecodePayload($encoded)
{
    if (!is_string($encoded) || $encoded === '' || strlen($encoded) > 4 * 1024 * 1024) return null;
    $compressed = false;
    $json = null;

    if (strpos($encoded, 'v2z.') === 0 || strpos($encoded, 'v2.') === 0) {
        $compressed = strpos($encoded, 'v2z.') === 0;
        $offset = $compressed ? 4 : 3;
        $blob = slipDebugBase64UrlDecode(substr($encoded, $offset));
        if (!is_string($blob) || strlen($blob) < 29 || !function_exists('openssl_decrypt')) return null;
        $nonce = substr($blob, 0, 12);
        $tag = substr($blob, 12, 16);
        $cipher = substr($blob, 28);
        foreach (slipDebugEncryptionKeys() as $key) {
            $plain = openssl_decrypt(
                $cipher,
                'aes-256-gcm',
                $key,
                OPENSSL_RAW_DATA,
                $nonce,
                $tag,
                SLIP_DEBUG_AAD
            );
            if (is_string($plain)) {
                $json = $plain;
                break;
            }
        }
    } elseif (strpos($encoded, 'j1z.') === 0 || strpos($encoded, 'j1.') === 0) {
        $compressed = strpos($encoded, 'j1z.') === 0;
        $offset = $compressed ? 4 : 3;
        $plain = slipDebugBase64UrlDecode(substr($encoded, $offset));
        if (is_string($plain)) $json = $plain;
    }

    if (!is_string($json)) return null;
    if ($compressed) {
        if (!function_exists('gzdecode')) return null;
        $decoded = @gzdecode($json);
        if (!is_string($decoded)) return null;
        $json = $decoded;
    }
    $data = json_decode($json, true, 64);
    return is_array($data) || is_scalar($data) || $data === null ? $data : null;
}

function slipDebugPayloadStorageMode($encoded): string
{
    if (!is_string($encoded) || $encoded === '') return 'none';
    if (strpos($encoded, 'v2') === 0) return 'aes-256-gcm';
    if (strpos($encoded, 'j1') === 0) return 'base64-fallback';
    return 'unknown';
}

function slipDebugRequestContext(): array
{
    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    return [
        'request_method' => substr((string) ($_SERVER['REQUEST_METHOD'] ?? ''), 0, 16),
        'request_uri' => substr((string) ($_SERVER['REQUEST_URI'] ?? ''), 0, 1000),
        'request_time_float' => isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : null,
        'client_ip' => substr($ip, 0, 45),
        'client_ip_sha256' => $ip !== '' ? hash('sha256', $ip) : '',
        'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 1000),
        'content_length' => isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : null,
        'php_sapi' => PHP_SAPI,
    ];
}

function slipDebugClockSnapshot(): array
{
    global $conn;
    $nowFloat = microtime(true);
    $snapshot = [
        'php_unix_time' => time(),
        'php_microtime' => $nowFloat,
        'php_local_iso8601' => date('c', (int) $nowFloat),
        'php_utc_iso8601' => gmdate('c', (int) $nowFloat),
        'php_timezone' => date_default_timezone_get(),
        'php_ini_date_timezone' => (string) ini_get('date.timezone'),
        'system_timezone_env' => (string) getenv('TZ'),
    ];

    try {
        if (isset($conn) && $conn instanceof mysqli) {
            $result = $conn->query(
                "SELECT NOW(6) AS db_now, UTC_TIMESTAMP(6) AS db_utc_now, UNIX_TIMESTAMP(NOW(6)) AS db_unix_time, "
                . "@@session.time_zone AS db_session_timezone, @@global.time_zone AS db_global_timezone, @@system_time_zone AS db_system_timezone"
            );
            if ($result && ($row = $result->fetch_assoc())) {
                $snapshot['database'] = $row;
                if (isset($row['db_unix_time']) && is_numeric((string) $row['db_unix_time'])) {
                    $snapshot['db_minus_php_seconds'] = round((float) $row['db_unix_time'] - $nowFloat, 6);
                }
            }
        }
    } catch (Throwable $e) {
        $snapshot['database_clock_error'] = substr($e->getMessage(), 0, 500);
    }
    return $snapshot;
}

function slipDebugImageMetadataFromDataUri($imageBase64): array
{
    $metadata = [
        'input_type' => gettype($imageBase64),
        'encoded_bytes' => is_string($imageBase64) ? strlen($imageBase64) : 0,
        'data_uri' => false,
        'mime' => '',
        'decoded_bytes' => null,
        'decoded_sha256' => '',
    ];
    if (!is_string($imageBase64) || $imageBase64 === '') return $metadata;
    $encoded = $imageBase64;
    if (preg_match('/^data:image\/(jpeg|jpg|png|gif|webp);base64,/i', $imageBase64, $matches)) {
        $metadata['data_uri'] = true;
        $metadata['mime'] = strtolower($matches[1]) === 'jpg' ? 'image/jpeg' : 'image/' . strtolower($matches[1]);
        $comma = strpos($imageBase64, ',');
        if ($comma !== false) $encoded = substr($imageBase64, $comma + 1);
    }
    $decoded = base64_decode($encoded, true);
    if (is_string($decoded)) {
        $metadata['decoded_bytes'] = strlen($decoded);
        $metadata['decoded_sha256'] = hash('sha256', $decoded);
        if ($metadata['mime'] === '' && function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = $finfo ? finfo_buffer($finfo, $decoded) : false;
            if ($finfo) finfo_close($finfo);
            if (is_string($mime)) $metadata['mime'] = $mime;
        }
    }
    return $metadata;
}

function slipDebugLogEvent(string $stage, string $severity, string $message, array $details = []): bool
{
    global $conn;
    if (!slipDebugEnabled()) return true;
    try {
        if (!ensureSlipVerificationDebugTable()) return false;

        $stage = strtolower(trim($stage));
        $stage = preg_replace('/[^a-z0-9_.-]+/', '_', $stage) ?: 'unknown';
        $stage = substr($stage, 0, 80);
        $severity = strtolower(trim($severity));
        if (!in_array($severity, ['debug', 'info', 'warning', 'error', 'critical'], true)) $severity = 'info';
        $message = substr(trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $message)), 0, 500);
        $errorCode = substr(trim((string) ($details['error_code'] ?? '')), 0, 100);
        $attemptUuid = slipDebugNormalizeUuid($details['attempt_uuid'] ?? '');
        $slipHash = slipDebugNormalizeHash($details['slip_hash'] ?? '');
        $userId = max(0, (int) ($details['user_id'] ?? 0));
        $httpCode = isset($details['http_code']) && is_numeric((string) $details['http_code'])
            ? max(0, min(65535, (int) $details['http_code']))
            : null;
        $durationMs = isset($details['duration_ms']) && is_numeric((string) $details['duration_ms'])
            ? max(0, min(4294967295, (int) round((float) $details['duration_ms'])))
            : null;

        $siteId = substr((string) (defined('APP_SITE_ID') ? APP_SITE_ID : ''), 0, 100);
        $siteHost = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? (defined('APP_SITE_DOMAIN') ? APP_SITE_DOMAIN : ''))));
        $siteHost = substr(preg_replace('/[^a-z0-9.:-]+/i', '', $siteHost), 0, 255);
        $eventUuid = slipDebugUuid();

        $context = is_array($details['context'] ?? null) ? $details['context'] : [];
        if (!empty($details['include_request_context'])) {
            $context['http_request'] = slipDebugRequestContext();
        }
        $requestCipher = slipDebugEncodePayload($details['request'] ?? null);
        $responseCipher = slipDebugEncodePayload($details['response'] ?? null);
        $contextCipher = slipDebugEncodePayload($context ?: null);

        $stmt = $conn->prepare(
            'INSERT INTO slip_verification_debug_logs '
            . '(event_uuid, attempt_uuid, slip_hash, user_id, site_id, site_host, stage, severity, event_message, error_code, http_code, duration_ms, request_ciphertext, response_ciphertext, context_ciphertext) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) return false;
        $stmt->bind_param(
            'sssissssssiisss',
            $eventUuid,
            $attemptUuid,
            $slipHash,
            $userId,
            $siteId,
            $siteHost,
            $stage,
            $severity,
            $message,
            $errorCode,
            $httpCode,
            $durationMs,
            $requestCipher,
            $responseCipher,
            $contextCipher
        );
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    } catch (Throwable $e) {
        error_log('Slip debug event write failed: ' . $e->getMessage());
        return false;
    }
}

function slipDebugDecodeRow(array $row): array
{
    foreach (['request', 'response', 'context'] as $field) {
        $column = $field . '_ciphertext';
        $encoded = $row[$column] ?? null;
        $row[$field] = slipDebugDecodePayload($encoded);
        $row[$field . '_storage'] = slipDebugPayloadStorageMode($encoded);
        unset($row[$column]);
    }
    return $row;
}

function slipDebugReassignAttempt(string $fromAttemptUuid, string $toAttemptUuid, string $slipHash, int $userId): int
{
    global $conn;
    $fromAttemptUuid = slipDebugNormalizeUuid($fromAttemptUuid);
    $toAttemptUuid = slipDebugNormalizeUuid($toAttemptUuid);
    $slipHash = slipDebugNormalizeHash($slipHash);
    $userId = max(0, $userId);
    if ($fromAttemptUuid === '' || $toAttemptUuid === '' || $fromAttemptUuid === $toAttemptUuid
        || $slipHash === '' || $userId < 1 || !ensureSlipVerificationDebugTable()) return 0;

    try {
        // A request UUID is freshly generated by the endpoint. Restricting the
        // update by user, slip hash/empty pre-hash events, and a short time window
        // prevents an attacker-supplied UUID from merging unrelated diagnostics.
        $stmt = $conn->prepare(
            "UPDATE slip_verification_debug_logs SET attempt_uuid = ?, slip_hash = IF(slip_hash = '', ?, slip_hash) "
            . "WHERE attempt_uuid = ? AND user_id = ? AND (slip_hash = '' OR slip_hash = ?) "
            . "AND created_at >= DATE_SUB(NOW(6), INTERVAL 15 MINUTE)"
        );
        if (!$stmt) return 0;
        $stmt->bind_param('sssis', $toAttemptUuid, $slipHash, $fromAttemptUuid, $userId, $slipHash);
        if (!$stmt->execute()) { $stmt->close(); return 0; }
        $moved = (int) $stmt->affected_rows;
        $stmt->close();
        return max(0, $moved);
    } catch (Throwable $e) {
        error_log('Slip debug attempt reassignment failed: ' . $e->getMessage());
        return 0;
    }
}

function slipDebugArmProviderRefresh(string $slipHash, int $adminId): bool
{
    global $conn;
    $slipHash = slipDebugNormalizeHash($slipHash);
    $adminId = max(0, $adminId);
    if ($slipHash === '' || $adminId < 1 || !ensureSlipVerificationDebugControlTable()) return false;
    try {
        $stmt = $conn->prepare(
            "INSERT INTO slip_verification_debug_controls "
            . "(slip_hash, force_provider_refresh_once, requested_by, requested_at, consumed_at) "
            . "VALUES (?, 1, ?, NOW(6), NULL) ON DUPLICATE KEY UPDATE "
            . "force_provider_refresh_once=1, requested_by=VALUES(requested_by), requested_at=NOW(6), consumed_at=NULL"
        );
        if (!$stmt) return false;
        $stmt->bind_param('si', $slipHash, $adminId);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    } catch (Throwable $e) {
        error_log('Slip debug provider refresh arm failed: ' . $e->getMessage());
        return false;
    }
}

function slipDebugCancelProviderRefresh(string $slipHash): bool
{
    global $conn;
    $slipHash = slipDebugNormalizeHash($slipHash);
    if ($slipHash === '' || !ensureSlipVerificationDebugControlTable()) return false;
    try {
        $stmt = $conn->prepare(
            'UPDATE slip_verification_debug_controls SET force_provider_refresh_once=0, updated_at=NOW(6) WHERE slip_hash=?'
        );
        if (!$stmt) return false;
        $stmt->bind_param('s', $slipHash);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    } catch (Throwable $e) {
        error_log('Slip debug provider refresh cancellation failed: ' . $e->getMessage());
        return false;
    }
}

function slipDebugProviderRefreshStatus(string $slipHash): ?array
{
    global $conn;
    $slipHash = slipDebugNormalizeHash($slipHash);
    if ($slipHash === '' || !ensureSlipVerificationDebugControlTable()) return null;
    try {
        $stmt = $conn->prepare('SELECT * FROM slip_verification_debug_controls WHERE slip_hash=? LIMIT 1');
        if (!$stmt) return null;
        $stmt->bind_param('s', $slipHash);
        if (!$stmt->execute()) { $stmt->close(); return null; }
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        return $row ?: null;
    } catch (Throwable $e) {
        error_log('Slip debug provider refresh status read failed: ' . $e->getMessage());
        return null;
    }
}

function slipDebugConsumeProviderRefresh(string $slipHash): bool
{
    global $conn;
    $slipHash = slipDebugNormalizeHash($slipHash);
    if ($slipHash === '' || !ensureSlipVerificationDebugControlTable()) return false;
    try {
        // One atomic UPDATE makes the diagnostic refresh single-use even when two
        // retries arrive at the same moment.
        $stmt = $conn->prepare(
            'UPDATE slip_verification_debug_controls '
            . 'SET force_provider_refresh_once=0, consumed_at=NOW(6), updated_at=NOW(6) '
            . 'WHERE slip_hash=? AND force_provider_refresh_once=1'
        );
        if (!$stmt) return false;
        $stmt->bind_param('s', $slipHash);
        $ok = $stmt->execute() && $stmt->affected_rows === 1;
        $stmt->close();
        return $ok;
    } catch (Throwable $e) {
        error_log('Slip debug provider refresh consume failed: ' . $e->getMessage());
        return false;
    }
}

function slipDebugPurgeOlderThan(int $days): int
{
    global $conn;
    $days = max(1, min(365, $days));
    if (!ensureSlipVerificationDebugTable()) return -1;
    try {
        $stmt = $conn->prepare('DELETE FROM slip_verification_debug_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)');
        if (!$stmt) return -1;
        $stmt->bind_param('i', $days);
        if (!$stmt->execute()) { $stmt->close(); return -1; }
        $deleted = $stmt->affected_rows;
        $stmt->close();
        return (int) $deleted;
    } catch (Throwable $e) {
        error_log('Slip debug cleanup failed: ' . $e->getMessage());
        return -1;
    }
}
