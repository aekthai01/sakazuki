<?php
/**
 * TrueMoney gift-link redemption through the shop's configured third-party provider.
 *
 * IMPORTANT: The provider is enabled because the shop owner explicitly requires it.
 * The application still validates the URL, enables TLS verification, rate-limits
 * requests, and records a local idempotency key so one voucher cannot credit twice.
 */

require_once __DIR__ . '/wallet_ledger.php';

if (!defined('TM_PROVIDER')) {
    $configuredProvider = defined('TRUEMONEY_PROVIDER') ? strtolower(trim((string) TRUEMONEY_PROVIDER)) : 'xpluem';
    if (in_array($configuredProvider, ['legacy', 'legacy_vercel', 'vercel'], true)) {
        $configuredProvider = 'legacy_vercel';
    } else {
        $configuredProvider = 'xpluem';
    }
    define('TM_PROVIDER', $configuredProvider);
}
if (!defined('TM_API_URL')) {
    if (TM_PROVIDER === 'legacy_vercel') {
        $configuredUrl = defined('TRUEMONEY_PROVIDER_URL') ? trim((string) TRUEMONEY_PROVIDER_URL) : '';
        if ($configuredUrl === '') $configuredUrl = 'https://truemoneyapi.vercel.app/api/truewallet';
        define('TM_API_URL', $configuredUrl);
    } else {
        // xpluem uses sensitive path parameters, therefore this constant is intentionally the base URL only.
        define('TM_API_URL', 'https://api.xpluem.com');
    }
}
if (!defined('TM_FEE_RATE')) {
    define('TM_FEE_RATE', 0.029);
}
if (!defined('TM_FEE_CAP_THB')) {
    define('TM_FEE_CAP_THB', 20.00);
}


function trueMoneyProviderName(): string
{
    return TM_PROVIDER === 'legacy_vercel' ? 'legacy_vercel' : 'xpluem';
}

function trueMoneyProviderDisplayName(): string
{
    return trueMoneyProviderName() === 'xpluem' ? 'xpluem / api.xpluem.com' : 'Legacy Vercel Provider';
}

/** Build a request plan without executing HTTP. Sensitive values remain only in the returned url/body. */
function trueMoneyProviderRequestPlan(array $normalizedVoucher, string $mobile): array
{
    $provider = trueMoneyProviderName();
    if ($provider === 'xpluem') {
        $base = rtrim((string) TM_API_URL, '/');
        $token = (string) ($normalizedVoucher['token'] ?? '');
        return [
            'provider' => 'xpluem',
            'method' => 'GET',
            'url' => $base . '/' . rawurlencode($token) . '/' . rawurlencode($mobile),
            'headers' => ['Accept: application/json', 'Cache-Control: no-cache, no-store, max-age=0', 'Pragma: no-cache'],
            'body' => null,
            'authorization_configured' => false,
        ];
    }

    $payload = json_encode([
        'phone' => $mobile,
        'gift_link' => (string) ($normalizedVoucher['url'] ?? ''),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return [
        'provider' => 'legacy_vercel',
        'method' => 'POST',
        'url' => (string) TM_API_URL,
        'headers' => array_values(array_filter([
            'Content-Type: application/json',
            'Accept: application/json',
            (defined('TRUEMONEY_PROVIDER_TOKEN') && trim((string) TRUEMONEY_PROVIDER_TOKEN) !== '')
                ? 'Authorization: Bearer ' . trim((string) TRUEMONEY_PROVIDER_TOKEN) : null,
        ])),
        'body' => $payload,
        'authorization_configured' => defined('TRUEMONEY_PROVIDER_TOKEN') && trim((string) TRUEMONEY_PROVIDER_TOKEN) !== '',
    ];
}

/** Normalize provider-specific JSON into the stable application shape used by the wallet flow. */
function trueMoneyNormalizeProviderResponse($result, int $httpCode): array
{
    $provider = trueMoneyProviderName();
    if (!is_array($result)) {
        return [
            'provider' => $provider,
            'contract_recognized' => false,
            'success' => false,
            'application_status' => null,
            'provider_status_code' => null,
            'message' => '',
            'amount' => null,
            'provider_code' => null,
        ];
    }

    if ($provider === 'xpluem') {
        $successRaw = $result['success'] ?? null;
        $success = $successRaw === true || $successRaw === 1 || $successRaw === '1' || $successRaw === 'true';
        $failure = $successRaw === false || $successRaw === 0 || $successRaw === '0' || $successRaw === 'false';
        $statusCode = isset($result['status']) && is_scalar($result['status']) && is_numeric((string) $result['status'])
            ? (int) $result['status'] : null;
        $message = isset($result['message']) && is_scalar($result['message']) ? trim((string) $result['message']) : '';
        $amount = null;
        if (isset($result['data']) && is_array($result['data']) && isset($result['data']['amount'])
            && is_scalar($result['data']['amount']) && is_numeric((string) $result['data']['amount'])) {
            $amount = round((float) $result['data']['amount'], 2);
        }
        return [
            'provider' => 'xpluem',
            'contract_recognized' => ($success || $failure) && $statusCode !== null,
            'success' => $success,
            'application_status' => $success ? 'success' : ($failure ? 'error' : null),
            'provider_status_code' => $statusCode,
            'message' => $message,
            'amount' => $amount,
            'provider_code' => null,
            'http_status_consistent' => $success ? ($httpCode >= 200 && $httpCode < 300 && $statusCode === 200) : null,
        ];
    }

    $statusRaw = $result['status'] ?? null;
    $applicationStatus = is_scalar($statusRaw) ? strtolower(trim((string) $statusRaw)) : null;
    $message = isset($result['message']) && is_scalar($result['message']) ? trim((string) $result['message']) : '';
    $amount = isset($result['amount']) && is_scalar($result['amount']) && is_numeric((string) $result['amount'])
        ? round((float) $result['amount'], 2) : null;
    return [
        'provider' => 'legacy_vercel',
        'contract_recognized' => in_array($applicationStatus, ['success', 'error'], true),
        'success' => $applicationStatus === 'success',
        'application_status' => $applicationStatus,
        'provider_status_code' => null,
        'message' => $message,
        'amount' => $amount,
        'provider_code' => trueMoneyProviderCodeFromResponse($result),
        'http_status_consistent' => $applicationStatus === 'success' ? ($httpCode >= 200 && $httpCode < 300) : null,
    ];
}

/** Keep useful provider evidence while excluding sender identity and any path secrets. */
function trueMoneyProviderSanitizedResponseForDebug($result)
{
    if (!is_array($result)) return $result;
    if (trueMoneyProviderName() === 'xpluem') {
        $safe = [
            'success' => $result['success'] ?? null,
            'status' => $result['status'] ?? null,
            'message' => $result['message'] ?? null,
            'data' => null,
        ];
        if (isset($result['data']) && is_array($result['data'])) {
            $safe['data'] = [
                'name_present' => isset($result['data']['name']) && is_scalar($result['data']['name']) && trim((string) $result['data']['name']) !== '',
                'amount' => isset($result['data']['amount']) && is_scalar($result['data']['amount']) ? $result['data']['amount'] : null,
            ];
        }
        return $safe;
    }
    return trueMoneyDebugSanitize($result);
}


/**
 * Public API contract for the active provider. This is diagnostic metadata only;
 * live runtime responses remain the source of truth when documentation and behaviour differ.
 */
function trueMoneyDocumentedApiContract(): array
{
    if (trueMoneyProviderName() === 'xpluem') {
        return [
            'provider' => 'xpluem',
            'documentation_source' => 'xpluem/truemoneyAngpao README',
            'endpoint' => 'https://api.xpluem.com',
            'endpoint_template' => 'https://api.xpluem.com/:link/:phone',
            'method' => 'GET',
            'request_encoding' => 'path_parameters',
            'required_request_fields' => ['link', 'phone'],
            'link_rule' => 'voucher token after ?v= only',
            'phone_rule' => '10 digits',
            'success' => [
                'success' => true,
                'status' => 200,
                'amount_path' => 'data.amount',
            ],
            'documented_error_codes' => [],
            'documented_error_messages' => [
                'ลิงค์ซองของขวัญถูกใช้งานแล้ว',
                'ลิงค์ซองของขวัญไม่ถูกต้อง',
                'ไม่สามารถใช้อั่งเปาของตัวเองได้',
                'ลิงค์ซองของขวัญหมดอายุ',
                'เบอร์รับเงินนี้ รับเงินไปแล้ว',
                'เบอร์รับเงิน ไม่ถูกต้อง',
                'กรุณาเลือกให้รับซองได้เพียวคนเดียว',
                '404 Not Found',
                '405 Method Not Allowed',
                'เกิดข้อผิดพลาดเซิฟเวอร์',
            ],
            'rate_limit' => null,
            'privacy_note' => 'Provider contract places voucher token and phone in the URL path; SAKAZUKI diagnostics must never store the raw effective URL.',
        ];
    }

    return [
        'provider' => 'legacy_vercel',
        'documentation_version' => 'v1.0.0 Stable',
        'endpoint' => 'https://truemoneyapi.vercel.app/api/truewallet',
        'method' => 'POST',
        'content_type' => 'application/json',
        'required_request_fields' => ['phone', 'gift_link'],
        'phone_rule' => '10 digits and starts with 0',
        'gift_link_rule' => 'full https://gift.truemoney.com/... link',
        'rate_limit' => [
            'requests' => 10,
            'window' => '1 minute',
            'scope' => 'per IP address',
        ],
        'success' => [
            'http_code' => 200,
            'status' => 'success',
            'required_fields' => ['status', 'message', 'amount'],
        ],
        'documented_error_codes' => [
            'VOUCHER_OUT_OF_STOCK',
            'VOUCHER_EXPIRED',
            'VOUCHER_NOT_FOUND',
            'TARGET_USER_NOT_FOUND',
        ],
        'documented_error_messages' => [],
    ];
}

function trueMoneyPhoneContractCheck(string $value): array
{
    $digits = preg_replace('/\\D+/', '', $value);
    return [
        'digits_length' => strlen($digits),
        'starts_with_zero' => $digits !== '' && $digits[0] === '0',
        'exact_10_digits' => preg_match('/^0\\d{9}$/', $digits) === 1,
        'masked' => trueMoneyDebugMaskedPhone($digits),
    ];
}

function trueMoneyProviderCodeFromResponse($result): ?string
{
    if (!is_array($result)) return null;
    foreach (['code', 'error_code', 'errorCode'] as $key) {
        if (!array_key_exists($key, $result) || !is_scalar($result[$key])) continue;
        $value = strtoupper(trim((string) $result[$key]));
        if ($value !== '') return substr($value, 0, 120);
    }
    return null;
}

function trueMoneyProviderEvidenceClassification(
    int $httpCode,
    int $curlErrno,
    bool $jsonValid,
    string $providerStatus,
    string $providerMessage,
    ?string $providerCode,
    array $responseHeaders,
    string $bodyPreview
): array {
    $haystack = strtolower($bodyPreview . ' ' . $providerMessage . ' ' . (string) json_encode($responseHeaders));
    $confirmed = [];
    if ($curlErrno === 0 && $httpCode > 0) {
        $confirmed[] = 'provider_http_response_received';
    }
    if ($jsonValid) {
        $confirmed[] = 'provider_response_is_valid_json';
    }
    if ($providerStatus === 'success' || $providerStatus === 'error') {
        $confirmed[] = 'provider_application_status_present';
    }

    $platformBlock = null;
    $platformBlockEvidence = null;
    if (strpos($haystack, 'imunify360') !== false) {
        $platformBlock = 'imunify360';
        $platformBlockEvidence = 'response contains Imunify360 identifier';
    } elseif (isset($responseHeaders['x-vercel-error']) && trim((string) (is_array($responseHeaders['x-vercel-error']) ? end($responseHeaders['x-vercel-error']) : $responseHeaders['x-vercel-error'])) !== '') {
        $platformBlock = 'vercel_platform_error';
        $platformBlockEvidence = 'x-vercel-error response header is present';
    } elseif ((isset($responseHeaders['cf-ray']) || strpos($haystack, 'cloudflare') !== false)
        && preg_match('/access denied|forbidden|challenge|blocked|captcha/i', $haystack) === 1) {
        $platformBlock = 'cloudflare_or_edge';
        $platformBlockEvidence = 'Cloudflare/edge identifier plus explicit block/challenge text';
    }

    $providerReportsTrueMoneyUpstreamProblem = false;
    if ($providerMessage !== '') {
        $providerReportsTrueMoneyUpstreamProblem =
            (stripos($providerMessage, 'เซิร์ฟเวอร์ TrueMoney') !== false)
            || (stripos($providerMessage, 'TrueMoney server') !== false)
            || (stripos($providerMessage, 'TrueMoney ตอบกลับ') !== false);
    }

    $failureDomain = null;
    $failureBasis = null;
    if ($curlErrno !== 0 || $httpCode === 0) {
        $failureDomain = 'sakazuki_to_provider_transport';
        $failureBasis = 'cURL transport did not obtain an HTTP response';
    } elseif ($platformBlock !== null) {
        $failureDomain = 'provider_platform_or_security_layer';
        $failureBasis = $platformBlockEvidence;
    } elseif ($providerReportsTrueMoneyUpstreamProblem) {
        $failureDomain = 'provider_reported_truemoney_upstream';
        $failureBasis = 'provider application message explicitly reports a TrueMoney server response problem';
    } elseif ($providerStatus === 'error') {
        $failureDomain = 'provider_application_error';
        $failureBasis = 'provider application returned status=error';
    } elseif ($httpCode < 200 || $httpCode >= 300) {
        $failureDomain = 'provider_http_error_unclassified';
        $failureBasis = 'non-2xx HTTP response without stronger layer evidence';
    }

    $knownCodes = trueMoneyDocumentedApiContract()['documented_error_codes'];
    $documentedCode = $providerCode !== null && in_array($providerCode, $knownCodes, true);

    return [
        'confirmed_facts' => $confirmed,
        'platform_block_evidence' => $platformBlock,
        'platform_block_basis' => $platformBlockEvidence,
        'provider_statement' => $providerMessage !== '' ? $providerMessage : null,
        'provider_code' => $providerCode,
        'provider_code_is_documented' => $providerCode !== null ? $documentedCode : null,
        'provider_reports_truemoney_upstream_problem' => $providerReportsTrueMoneyUpstreamProblem,
        'failure_domain' => $failureDomain,
        'failure_basis' => $failureBasis,
        'provider_to_truemoney_connection_independently_observed' => false,
        'exact_root_cause_confirmed' => false,
        'exact_root_cause_limitation' => $providerReportsTrueMoneyUpstreamProblem
            ? 'SAKAZUKI can prove what the provider reported, but cannot see the provider-to-TrueMoney upstream response without provider-side logs.'
            : 'The current evidence does not expose the provider-to-TrueMoney internal request/response.',
    ];
}

/**
 * TrueMoney diagnostic evidence is deliberately optional/fail-open. A failure to
 * create or persist diagnostics must never block a legitimate redemption.
 */
function ensureTrueMoneyDebugTable(): bool
{
    global $conn;
    static $state = null;
    if ($state !== null) {
        return $state;
    }
    if (!isset($conn) || !($conn instanceof mysqli)) {
        return $state = false;
    }

    $sql = "CREATE TABLE IF NOT EXISTS truemoney_debug_logs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        request_id VARCHAR(64) NOT NULL,
        attempt_id VARCHAR(64) NULL,
        redemption_id BIGINT UNSIGNED NULL,
        user_id INT NULL,
        stage VARCHAR(80) NOT NULL DEFAULT 'request_received',
        result_code VARCHAR(96) NULL,
        success TINYINT(1) NULL,
        http_code INT NULL,
        curl_errno INT NULL,
        duration_ms INT UNSIGNED NULL,
        provider_host VARCHAR(255) NULL,
        debug_json MEDIUMTEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_truemoney_debug_request (request_id),
        KEY idx_truemoney_debug_redemption (redemption_id),
        KEY idx_truemoney_debug_user (user_id),
        KEY idx_truemoney_debug_created (created_at),
        KEY idx_truemoney_debug_result (result_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    try {
        if (!$conn->query($sql)) {
            error_log('TrueMoney diagnostic schema unavailable: ' . (int) $conn->errno);
            return $state = false;
        }
    } catch (Throwable $e) {
        error_log('TrueMoney diagnostic schema unavailable; exception=' . get_class($e) . '; code=' . (int) $e->getCode());
        return $state = false;
    }
    return $state = true;
}

function trueMoneyDebugId(string $prefix): string
{
    try {
        return $prefix . bin2hex(random_bytes(12));
    } catch (Throwable $e) {
        return $prefix . str_replace('.', '', uniqid('', true));
    }
}

function trueMoneyDebugIsoNow(): string
{
    $now = microtime(true);
    $date = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $now), new DateTimeZone('UTC'));
    if (!$date) {
        return gmdate('Y-m-d\\TH:i:s\\Z');
    }
    return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.u\\Z');
}

function trueMoneyDebugElapsedMs(array $debug): int
{
    $started = isset($debug['_started_at']) ? (float) $debug['_started_at'] : microtime(true);
    return max(0, (int) round((microtime(true) - $started) * 1000));
}

function trueMoneyDebugSafeText($value, int $maxLength = 1500): string
{
    if (!is_string($value) && !is_scalar($value)) {
        return '';
    }
    $text = (string) $value;
    if ($text !== '' && preg_match('//u', $text) !== 1) {
        $preview = bin2hex(substr($text, 0, 512));
        return '[INVALID_UTF8_HEX] ' . (strlen($text) > 512 ? $preview . '…' : $preview);
    }
    $text = preg_replace('/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F\\x7F]+/u', ' ', $text);
    if ($text === null) $text = '';
    $text = preg_replace('/(gift\\.truemoney\\.com\\/campaign\\/?\\?[^\\s"\'<>]*?\\bv=)[A-Za-z0-9_-]{8,200}/i', '$1[REDACTED]', $text);
    $text = preg_replace('#(https://api\\.xpluem\\.com/)[^/\\s"\'<>]+/[^/\\s"\'<>]+#i', '$1[VOUCHER_REDACTED]/[PHONE_REDACTED]', $text);
    $text = preg_replace('/(Authorization\\s*:\\s*Bearer\\s+)[^\\s,;]+/i', '$1[REDACTED]', $text);
    $text = preg_replace('/\\b(Bearer)\\s+[A-Za-z0-9._~+\\/-]{12,}/i', '$1 [REDACTED]', $text);
    $text = preg_replace('/(["\']?(?:api[_-]?key|token|secret|password|authorization)["\']?\\s*[:=]\\s*["\']?)[^"\'\\s,;}]+/i', '$1[REDACTED]', $text);
    $text = preg_replace_callback('/(?<!\\d)\\d{9,15}(?!\\d)/', static function ($m) {
        $digits = (string) $m[0];
        return str_repeat('*', max(0, strlen($digits) - 4)) . substr($digits, -4);
    }, $text);
    $text = trim($text);
    if ($maxLength < 1) {
        return '';
    }
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($text, 'UTF-8') > $maxLength) {
            return mb_substr($text, 0, $maxLength, 'UTF-8') . '…';
        }
        return $text;
    }
    return strlen($text) > $maxLength ? substr($text, 0, $maxLength) . '…' : $text;
}

function trueMoneyDebugSanitize($value, int $depth = 0)
{
    if ($depth > 8) return '[MAX_DEPTH]';
    if (is_array($value)) {
        $clean = [];
        $count = 0;
        foreach ($value as $key => $item) {
            if (++$count > 200) {
                $clean['_truncated'] = true;
                break;
            }
            $keyString = strtolower(is_string($key) ? $key : (string) $key);
            $sensitiveExact = in_array($keyString, [
                'password','passwd','secret','token','authorization','cookie','csrf',
                'gift_link','voucher_url','raw_body','response_body','owner_profile','redeemer_profile',
                'effective_url_raw','request_url_raw'
            ], true);
            $sensitiveSuffix = preg_match('/(?:^|_)(?:password|passwd|secret|token|authorization|cookie|csrf)$/i', $keyString) === 1;
            if ($sensitiveExact || $sensitiveSuffix) {
                $clean[$key] = (is_bool($item) || $item === null) ? $item : '[REDACTED]';
                continue;
            }
            $clean[$key] = trueMoneyDebugSanitize($item, $depth + 1);
        }
        return $clean;
    }
    if (is_string($value)) return trueMoneyDebugSafeText($value, 2500);
    if (is_int($value) || is_float($value) || is_bool($value) || $value === null) return $value;
    return trueMoneyDebugSafeText((string) $value, 500);
}

function trueMoneyDebugMaskedPhone(string $digits): string
{
    $digits = preg_replace('/\\D+/', '', $digits);
    if ($digits === '') return '';
    return str_repeat('*', max(0, strlen($digits) - 4)) . substr($digits, -4);
}

function trueMoneyDebugUrlSummary(string $url): array
{
    $parts = parse_url($url);
    return [
        'scheme' => strtolower((string) ($parts['scheme'] ?? '')),
        'host' => strtolower((string) ($parts['host'] ?? '')),
        'port' => isset($parts['port']) ? (int) $parts['port'] : 443,
        'path' => (string) ($parts['path'] ?? ''),
        'query_present' => isset($parts['query']) && (string) $parts['query'] !== '',
    ];
}


function trueMoneyDebugProviderUrlSummary(string $url): array
{
    $summary = trueMoneyDebugUrlSummary($url);
    if (trueMoneyProviderName() === 'xpluem' && ($summary['host'] ?? '') === 'api.xpluem.com') {
        $path = trim((string) ($summary['path'] ?? ''), '/');
        $segments = $path === '' ? [] : explode('/', $path);
        $summary['path_segment_count'] = count($segments);
        $summary['path'] = count($segments) >= 2 ? '/[VOUCHER_REDACTED]/[PHONE_REDACTED]' : ($path === '' ? '' : '/[REDACTED]');
        $summary['sensitive_path_redacted'] = count($segments) >= 2;
    }
    return $summary;
}

function trueMoneyDebugStart(int $userId): array
{
    $started = microtime(true);
    $requestStarted = isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : $started;
    $detectedIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if (function_exists('getClientIp')) {
        try {
            $detectedIp = (string) getClientIp();
        } catch (Throwable $e) {
            $detectedIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        }
    }
    $contentLength = isset($_SERVER['CONTENT_LENGTH']) && is_numeric((string) $_SERVER['CONTENT_LENGTH'])
        ? (int) $_SERVER['CONTENT_LENGTH'] : null;
    $ajax = null;
    if (function_exists('isAjaxRequest')) {
        try {
            $ajax = (bool) isAjaxRequest();
        } catch (Throwable $e) {
            $ajax = null;
        }
    }

    return [
        '_started_at' => $started,
        'schema' => 'sakazuki.debug',
        'version' => 1,
        'type' => 'truemoney.redemption',
        'request_id' => trueMoneyDebugId('tmreq_'),
        'attempt_id' => null,
        'generated_at' => trueMoneyDebugIsoNow(),
        'request' => [
            'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
            'path' => (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH),
            'server_protocol' => (string) ($_SERVER['SERVER_PROTOCOL'] ?? ''),
            'content_type' => trueMoneyDebugSafeText((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 160),
            'content_length' => $contentLength,
            'ajax' => $ajax,
            'request_started_at_unix_ms' => (int) round($requestStarted * 1000),
        ],
        'client' => [
            'user_id' => $userId > 0 ? $userId : null,
            'remote_addr' => trueMoneyDebugSafeText((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 64),
            'detected_ip' => trueMoneyDebugSafeText($detectedIp, 64),
            'cf_ray' => trueMoneyDebugSafeText((string) ($_SERVER['HTTP_CF_RAY'] ?? ''), 128),
            'user_agent' => trueMoneyDebugSafeText((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 300),
        ],
        'redemption' => [
            'id' => null,
            'voucher_fingerprint' => null,
            'reservation_mode' => null,
        ],
        'provider' => [
            'name' => trueMoneyProviderName(),
            'target' => trueMoneyDebugProviderUrlSummary((string) TM_API_URL),
            'request' => [],
            'transport' => [],
            'response' => [],
        ],
        'settlement' => [],
        'wallet' => [],
        'integrity' => [],
        'timeline' => [[
            'stage' => 'request_received',
            'elapsed_ms' => 0,
            'at' => trueMoneyDebugIsoNow(),
        ]],
        'result' => [
            'success' => null,
            'stage' => 'request_received',
            'code' => null,
        ],
        'errors' => [],
        'privacy' => [
            'voucher_token_stored' => false,
            'provider_authorization_stored' => false,
            'raw_provider_body_stored' => false,
            'raw_provider_effective_url_stored' => false,
            'xpluem_path_secrets_stored' => false,
            'credentials_redacted' => true,
        ],
    ];
}

function trueMoneyDebugEvent(array &$debug, string $stage, array $details = []): void
{
    $event = [
        'stage' => substr($stage, 0, 96),
        'elapsed_ms' => trueMoneyDebugElapsedMs($debug),
        'at' => trueMoneyDebugIsoNow(),
    ];
    $details = trueMoneyDebugSanitize($details);
    if (is_array($details) && $details) {
        $event['details'] = $details;
    }
    $debug['timeline'][] = $event;
    if (count($debug['timeline']) > 120) {
        $debug['timeline'] = array_merge(array_slice($debug['timeline'], 0, 10), array_slice($debug['timeline'], -110));
    }
    $debug['result']['stage'] = $event['stage'];
    $debug['generated_at'] = trueMoneyDebugIsoNow();
}

function trueMoneyDebugError(array &$debug, string $stage, string $code, string $message, ?Throwable $exception = null): void
{
    $safeMessage = trueMoneyDebugSafeText($message, 800);
    $rawFingerprintSource = $message;
    $error = [
        'stage' => substr($stage, 0, 96),
        'code' => substr($code, 0, 96),
        'message' => $safeMessage,
        'message_sha256' => hash('sha256', $rawFingerprintSource),
    ];
    if ($exception !== null) {
        $error['exception_class'] = get_class($exception);
        $error['exception_code'] = is_int($exception->getCode()) || is_string($exception->getCode()) ? $exception->getCode() : null;
    }
    $debug['errors'][] = $error;
    if (count($debug['errors']) > 30) {
        $debug['errors'] = array_slice($debug['errors'], -30);
    }
    trueMoneyDebugEvent($debug, $stage, ['error_code' => $code]);
}

function trueMoneyDebugSetResult(array &$debug, bool $success, string $stage, string $code): void
{
    $debug['result'] = [
        'success' => $success,
        'stage' => substr($stage, 0, 96),
        'code' => substr($code, 0, 96),
    ];
    $debug['duration_ms'] = trueMoneyDebugElapsedMs($debug);
    $debug['generated_at'] = trueMoneyDebugIsoNow();
}

function trueMoneyDebugPublicJson(array $debug): string
{
    unset($debug['_started_at']);
    $clean = trueMoneyDebugSanitize($debug);
    $json = json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
    return $json === false ? '{"schema":"sakazuki.debug","type":"truemoney.redemption","error":"json_encode_failed"}' : $json;
}

function trueMoneyDebugPersist(array &$debug): bool
{
    global $conn;
    if (!isset($debug['request_id']) || !is_string($debug['request_id']) || $debug['request_id'] === '') {
        return false;
    }
    if (!ensureTrueMoneyDebugTable()) {
        return false;
    }

    $debug['duration_ms'] = trueMoneyDebugElapsedMs($debug);
    $debug['generated_at'] = trueMoneyDebugIsoNow();
    $json = trueMoneyDebugPublicJson($debug);
    $requestId = substr((string) $debug['request_id'], 0, 64);
    $attemptId = isset($debug['attempt_id']) && is_string($debug['attempt_id']) && $debug['attempt_id'] !== ''
        ? substr($debug['attempt_id'], 0, 64) : null;
    $redemptionId = isset($debug['redemption']['id']) && is_numeric((string) $debug['redemption']['id'])
        ? (int) $debug['redemption']['id'] : null;
    if ($redemptionId !== null && $redemptionId < 1) $redemptionId = null;
    $userId = isset($debug['client']['user_id']) && is_numeric((string) $debug['client']['user_id'])
        ? (int) $debug['client']['user_id'] : null;
    if ($userId !== null && $userId < 1) $userId = null;
    $stage = substr((string) ($debug['result']['stage'] ?? 'unknown'), 0, 80);
    $resultCode = isset($debug['result']['code']) && $debug['result']['code'] !== null
        ? substr((string) $debug['result']['code'], 0, 96) : null;
    $success = isset($debug['result']['success']) && is_bool($debug['result']['success'])
        ? ($debug['result']['success'] ? 1 : 0) : null;
    $httpCode = isset($debug['provider']['transport']['http_code']) && is_numeric((string) $debug['provider']['transport']['http_code'])
        ? (int) $debug['provider']['transport']['http_code'] : null;
    $curlErrno = isset($debug['provider']['transport']['curl_errno']) && is_numeric((string) $debug['provider']['transport']['curl_errno'])
        ? (int) $debug['provider']['transport']['curl_errno'] : null;
    $durationMs = isset($debug['duration_ms']) ? max(0, (int) $debug['duration_ms']) : null;
    $providerHost = substr((string) ($debug['provider']['target']['host'] ?? ''), 0, 255);
    if ($providerHost === '') $providerHost = null;

    $sql = "INSERT INTO truemoney_debug_logs
        (request_id,attempt_id,redemption_id,user_id,stage,result_code,success,http_code,curl_errno,duration_ms,provider_host,debug_json)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            attempt_id=VALUES(attempt_id), redemption_id=VALUES(redemption_id), user_id=VALUES(user_id),
            stage=VALUES(stage), result_code=VALUES(result_code), success=VALUES(success), http_code=VALUES(http_code),
            curl_errno=VALUES(curl_errno), duration_ms=VALUES(duration_ms), provider_host=VALUES(provider_host),
            debug_json=VALUES(debug_json), updated_at=CURRENT_TIMESTAMP";
    try {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log('TrueMoney diagnostic persist prepare failed: ' . (int) $conn->errno);
            return false;
        }
        $stmt->bind_param(
            'ssiissiiiiss',
            $requestId,
            $attemptId,
            $redemptionId,
            $userId,
            $stage,
            $resultCode,
            $success,
            $httpCode,
            $curlErrno,
            $durationMs,
            $providerHost,
            $json
        );
        $ok = $stmt->execute();
        if (!$ok) {
            error_log('TrueMoney diagnostic persist failed: ' . (int) $stmt->errno);
        }
        $stmt->close();
        return $ok;
    } catch (Throwable $e) {
        error_log('TrueMoney diagnostic persist exception=' . get_class($e) . '; code=' . (int) $e->getCode());
        return false;
    }
}

function trueMoneyDebugCaptureState(array &$debug, int $redemptionId, int $userId): void
{
    global $conn;
    if ($redemptionId < 1 || $userId < 1 || !isset($conn) || !($conn instanceof mysqli)) {
        return;
    }

    $snapshot = [];
    try {
        $stmt = $conn->prepare('SELECT id,user_id,status,amount_thb,fee_amount_thb,net_amount_thb,credited_amount,attempt_count,provider_message,created_at,updated_at,completed_at FROM truemoney_redemptions WHERE id=? AND user_id=? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('ii', $redemptionId, $userId);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                $row = $result ? $result->fetch_assoc() : null;
                if ($row) {
                    $snapshot['redemption'] = [
                        'id' => (int) $row['id'],
                        'user_id' => (int) $row['user_id'],
                        'status' => (string) $row['status'],
                        'amount_thb' => $row['amount_thb'] !== null ? (float) $row['amount_thb'] : null,
                        'fee_amount_thb' => $row['fee_amount_thb'] !== null ? (float) $row['fee_amount_thb'] : null,
                        'net_amount_thb' => $row['net_amount_thb'] !== null ? (float) $row['net_amount_thb'] : null,
                        'credited_amount' => $row['credited_amount'] !== null ? (float) $row['credited_amount'] : null,
                        'attempt_count' => (int) $row['attempt_count'],
                        'provider_message' => trueMoneyDebugSafeText((string) ($row['provider_message'] ?? ''), 500),
                        'created_at' => (string) $row['created_at'],
                        'updated_at' => (string) $row['updated_at'],
                        'completed_at' => $row['completed_at'] !== null ? (string) $row['completed_at'] : null,
                    ];
                }
            }
            $stmt->close();
        }

        $balanceStmt = $conn->prepare('SELECT balance FROM users WHERE id=? LIMIT 1');
        if ($balanceStmt) {
            $balanceStmt->bind_param('i', $userId);
            if ($balanceStmt->execute()) {
                $balanceStmt->bind_result($currentBalance);
                if ($balanceStmt->fetch()) {
                    $snapshot['wallet_current_balance'] = (float) $currentBalance;
                }
            }
            $balanceStmt->close();
        }

        if (function_exists('walletLedgerTableExists') && walletLedgerTableExists('wallet_balance_ledger')) {
            $ledgerStmt = $conn->prepare("SELECT id,event_key,direction,amount,delta_amount,balance_before,balance_after,source_type,source_id,transaction_id,created_at FROM wallet_balance_ledger WHERE source_type='truemoney_deposit' AND source_id=? AND user_id=? ORDER BY id DESC LIMIT 1");
            if ($ledgerStmt) {
                $ledgerStmt->bind_param('ii', $redemptionId, $userId);
                if ($ledgerStmt->execute()) {
                    $result = $ledgerStmt->get_result();
                    $ledger = $result ? $result->fetch_assoc() : null;
                    if ($ledger) {
                        $transactionId = $ledger['transaction_id'] !== null ? (int) $ledger['transaction_id'] : null;
                        $snapshot['ledger'] = [
                            'id' => (int) $ledger['id'],
                            'event_key' => trueMoneyDebugSafeText((string) $ledger['event_key'], 191),
                            'direction' => (string) $ledger['direction'],
                            'amount' => (float) $ledger['amount'],
                            'delta_amount' => (float) $ledger['delta_amount'],
                            'balance_before' => (float) $ledger['balance_before'],
                            'balance_after' => (float) $ledger['balance_after'],
                            'source_type' => (string) $ledger['source_type'],
                            'source_id' => (int) $ledger['source_id'],
                            'transaction_id' => $transactionId,
                            'created_at' => (string) $ledger['created_at'],
                        ];
                        if ($transactionId !== null && $transactionId > 0) {
                            $txStmt = $conn->prepare('SELECT id,user_id,type,amount,status,description,reference_id,created_at FROM transactions WHERE id=? AND user_id=? LIMIT 1');
                            if ($txStmt) {
                                $txStmt->bind_param('ii', $transactionId, $userId);
                                if ($txStmt->execute()) {
                                    $txResult = $txStmt->get_result();
                                    $tx = $txResult ? $txResult->fetch_assoc() : null;
                                    if ($tx) {
                                        $snapshot['transaction'] = [
                                            'id' => (int) $tx['id'],
                                            'user_id' => (int) $tx['user_id'],
                                            'type' => (string) $tx['type'],
                                            'amount' => (float) $tx['amount'],
                                            'status' => (string) $tx['status'],
                                            'description' => trueMoneyDebugSafeText((string) ($tx['description'] ?? ''), 800),
                                            'reference_id' => $tx['reference_id'] !== null ? (int) $tx['reference_id'] : null,
                                            'created_at' => (string) $tx['created_at'],
                                        ];
                                    }
                                }
                                $txStmt->close();
                            }
                        }
                    }
                }
                $ledgerStmt->close();
            }
        }
    } catch (Throwable $e) {
        $snapshot['snapshot_error'] = [
            'message' => trueMoneyDebugSafeText($e->getMessage(), 500),
            'fingerprint' => hash('sha256', $e->getMessage()),
        ];
    }

    $debug['database_state'] = trueMoneyDebugSanitize($snapshot);
}

function trueMoneyDebugFetchRecent(int $limit = 50): array
{
    global $conn;
    $limit = max(1, min(100, $limit));
    if (!ensureTrueMoneyDebugTable()) return [];
    try {
        $result = $conn->query(
            "SELECT d.id,d.request_id,d.attempt_id,d.redemption_id,d.user_id,d.stage,d.result_code,d.success,d.http_code,d.curl_errno,d.duration_ms,d.provider_host,d.created_at,d.updated_at,u.username,u.role
             FROM truemoney_debug_logs d
             LEFT JOIN users u ON u.id=d.user_id
             ORDER BY d.id DESC LIMIT " . $limit
        );
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    } catch (Throwable $e) {
        error_log('TrueMoney diagnostic list unavailable; code=' . (int) $e->getCode());
        return [];
    }
}

function trueMoneyDebugFetchById(int $id): ?array
{
    global $conn;
    if ($id < 1 || !ensureTrueMoneyDebugTable()) return null;
    try {
        $stmt = $conn->prepare('SELECT id,request_id,debug_json,created_at,updated_at FROM truemoney_debug_logs WHERE id=? LIMIT 1');
        if (!$stmt) return null;
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        return $row ?: null;
    } catch (Throwable $e) {
        error_log('TrueMoney diagnostic read unavailable; code=' . (int) $e->getCode());
        return null;
    }
}

/**
 * Calculate the wallet settlement from the voucher face value.
 * TrueMoney records the voucher amount and the receiver fee as separate wallet
 * transactions, so the shop must credit only the net amount actually received.
 *
 * @return array{success:bool,gross_amount_thb?:float,fee_amount_thb?:float,net_amount_thb?:float,message?:string}
 */
function calculateTrueMoneySettlement(float $amountThb): array
{
    $grossAmount = round($amountThb, 2, PHP_ROUND_HALF_UP);
    if (!is_finite($grossAmount) || $grossAmount <= 0 || $grossAmount > 1000000) {
        return ['success' => false, 'message' => 'จำนวนเงินจากซองของขวัญไม่ถูกต้อง'];
    }

    $rawFee = $grossAmount * (float) TM_FEE_RATE;
    $feeAmount = round(min($rawFee, (float) TM_FEE_CAP_THB), 2, PHP_ROUND_HALF_UP);
    $feeAmount = min($feeAmount, $grossAmount);
    $netAmount = round($grossAmount - $feeAmount, 2, PHP_ROUND_HALF_UP);
    if (!is_finite($feeAmount) || !is_finite($netAmount) || $feeAmount < 0 || $netAmount <= 0) {
        return ['success' => false, 'message' => 'ยอดสุทธิหลังหักค่าธรรมเนียมไม่ถูกต้อง'];
    }

    return [
        'success' => true,
        'gross_amount_thb' => $grossAmount,
        'fee_amount_thb' => $feeAmount,
        'net_amount_thb' => $netAmount,
    ];
}

/**
 * Validate and canonicalize a TrueMoney gift URL.
 *
 * @return array{success:bool,url?:string,token?:string,message?:string}
 */
function normalizeTrueMoneyVoucherUrl($voucherUrl)
{
    if (!is_string($voucherUrl) && !is_scalar($voucherUrl)) {
        return ['success' => false, 'message' => 'ลิงก์ซองของขวัญไม่ถูกต้อง'];
    }
    $voucherUrl = trim((string) $voucherUrl);
    if ($voucherUrl === '' || strlen($voucherUrl) > 500 || !filter_var($voucherUrl, FILTER_VALIDATE_URL)) {
        return ['success' => false, 'message' => 'ลิงก์ซองของขวัญไม่ถูกต้อง'];
    }

    $parts = parse_url($voucherUrl);
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));
    $path = (string) ($parts['path'] ?? '');
    if ($scheme !== 'https' || $host !== 'gift.truemoney.com' || preg_match('#^/campaign/?$#', $path) !== 1) {
        return ['success' => false, 'message' => 'รองรับเฉพาะลิงก์ https://gift.truemoney.com/campaign เท่านั้น'];
    }

    parse_str((string) ($parts['query'] ?? ''), $query);
    $token = isset($query['v']) && is_string($query['v']) ? trim($query['v']) : '';
    if (!preg_match('/^[A-Za-z0-9_-]{8,200}$/', $token)) {
        return ['success' => false, 'message' => 'รหัสซองของขวัญไม่ถูกต้อง'];
    }

    return [
        'success' => true,
        'url' => 'https://gift.truemoney.com/campaign/?v=' . rawurlencode($token),
        'token' => $token,
    ];
}

function ensureTrueMoneyRedemptionsTable(): bool
{
    global $conn;
    static $ready = false;
    if ($ready) {
        return true;
    }
    if (!isset($conn) || !($conn instanceof mysqli)) {
        return false;
    }

    $sql = "CREATE TABLE IF NOT EXISTS truemoney_redemptions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        voucher_hash CHAR(64) NOT NULL,
        user_id INT NOT NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'processing',
        amount_thb DECIMAL(12,2) NULL,
        fee_amount_thb DECIMAL(12,2) NULL,
        net_amount_thb DECIMAL(12,2) NULL,
        credited_amount DECIMAL(12,2) NULL,
        attempt_count INT UNSIGNED NOT NULL DEFAULT 1,
        provider_message VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        completed_at DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_truemoney_voucher_hash (voucher_hash),
        KEY idx_truemoney_user (user_id),
        KEY idx_truemoney_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if (!$conn->query($sql)) {
        error_log('TrueMoney schema error: ' . $conn->error);
        return false;
    }

    // Repeatable migration for sites that created this table before fee tracking
    // was added. Keeping gross, fee, net, and credited currency separately makes
    // retries and later financial audits unambiguous.
    $requiredColumns = [
        'fee_amount_thb' => 'DECIMAL(12,2) NULL AFTER amount_thb',
        'net_amount_thb' => 'DECIMAL(12,2) NULL AFTER fee_amount_thb',
    ];
    $columnsResult = $conn->query('SHOW COLUMNS FROM truemoney_redemptions');
    if (!$columnsResult) {
        error_log('TrueMoney schema inspection error: ' . $conn->error);
        return false;
    }
    $existingColumns = [];
    while ($column = $columnsResult->fetch_assoc()) {
        $existingColumns[(string) ($column['Field'] ?? '')] = true;
    }
    $columnsResult->free();
    foreach ($requiredColumns as $columnName => $definition) {
        if (isset($existingColumns[$columnName])) {
            continue;
        }
        if (!$conn->query("ALTER TABLE truemoney_redemptions ADD COLUMN `{$columnName}` {$definition}")) {
            // A concurrent request may have completed the same migration first.
            if ((int) $conn->errno !== 1060) {
                error_log('TrueMoney schema migration error for ' . $columnName . ': ' . $conn->error);
                return false;
            }
        }
    }

    $ready = true;
    return true;
}

/**
 * Reserve a voucher before contacting the provider.
 * Returns mode=provider for a new/retry attempt or mode=resume when the provider
 * previously confirmed the voucher but local balance credit did not finish.
 */
function beginTrueMoneyRedemption(string $voucherHash, int $userId, ?array &$debug = null): array
{
    global $conn;
    if ($debug !== null) {
        $debug['redemption']['voucher_fingerprint'] = substr($voucherHash, 0, 16);
        trueMoneyDebugEvent($debug, 'reservation_started');
    }
    if (!ensureTrueMoneyRedemptionsTable() || $userId < 1 || !preg_match('/^[a-f0-9]{64}$/', $voucherHash)) {
        if ($debug !== null) {
            trueMoneyDebugError($debug, 'reservation_validation_failed', 'reservation_not_ready', 'Unable to prepare TrueMoney redemption reservation');
        }
        return ['success' => false, 'message' => 'ไม่สามารถเตรียมรายการเติมเงินได้'];
    }

    $conn->begin_transaction();
    try {
        if ($debug !== null) trueMoneyDebugEvent($debug, 'reservation_lookup_started');
        $stmt = $conn->prepare('SELECT id, user_id, status, amount_thb, updated_at FROM truemoney_redemptions WHERE voucher_hash = ? LIMIT 1 FOR UPDATE');
        if (!$stmt) {
            throw new RuntimeException('prepare redemption select failed');
        }
        $stmt->bind_param('s', $voucherHash);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('redemption select failed');
        }
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$row) {
            $status = 'processing';
            $insert = $conn->prepare('INSERT INTO truemoney_redemptions (voucher_hash, user_id, status) VALUES (?, ?, ?)');
            if (!$insert) {
                throw new RuntimeException('prepare redemption insert failed');
            }
            $insert->bind_param('sis', $voucherHash, $userId, $status);
            if (!$insert->execute()) {
                $insert->close();
                throw new RuntimeException('redemption insert failed');
            }
            $id = (int) $conn->insert_id;
            $insert->close();
            $conn->commit();
            if ($debug !== null) {
                $debug['redemption']['id'] = $id;
                $debug['redemption']['reservation_mode'] = 'provider';
                trueMoneyDebugEvent($debug, 'reservation_created', ['redemption_id' => $id, 'status' => 'processing']);
                trueMoneyDebugCaptureState($debug, $id, $userId);
            }
            return ['success' => true, 'mode' => 'provider', 'id' => $id];
        }

        $id = (int) $row['id'];
        $ownerId = (int) $row['user_id'];
        $status = (string) $row['status'];
        if ($debug !== null) {
            $debug['redemption']['id'] = $id;
            trueMoneyDebugEvent($debug, 'reservation_existing_found', [
                'redemption_id' => $id,
                'status' => $status,
                'owner_matches' => $ownerId === $userId,
            ]);
        }
        if ($ownerId !== $userId) {
            $conn->rollback();
            if ($debug !== null) trueMoneyDebugError($debug, 'reservation_rejected', 'voucher_owned_by_other_user', 'Voucher is already associated with another account');
            return ['success' => false, 'message' => 'ซองนี้ถูกส่งเข้าระบบโดยบัญชีอื่นแล้ว'];
        }
        if ($status === 'completed') {
            $conn->rollback();
            if ($debug !== null) trueMoneyDebugError($debug, 'reservation_rejected', 'voucher_already_completed', 'Voucher redemption is already completed');
            return ['success' => false, 'message' => 'ซองนี้ถูกเติมเงินแล้ว'];
        }
        if ($status === 'provider_confirmed' && (float) $row['amount_thb'] > 0) {
            $conn->commit();
            if ($debug !== null) {
                $debug['redemption']['reservation_mode'] = 'resume';
                trueMoneyDebugEvent($debug, 'reservation_resumed', [
                    'redemption_id' => $id,
                    'stored_amount_thb' => (float) $row['amount_thb'],
                ]);
                trueMoneyDebugCaptureState($debug, $id, $userId);
            }
            return ['success' => true, 'mode' => 'resume', 'id' => $id, 'amount_thb' => (float) $row['amount_thb']];
        }

        $updatedAt = strtotime((string) ($row['updated_at'] ?? '')) ?: 0;
        if ($status === 'processing' && $updatedAt > (time() - 120)) {
            $conn->rollback();
            if ($debug !== null) trueMoneyDebugError($debug, 'reservation_rejected', 'redemption_already_processing', 'Redemption is still within the processing lock window');
            return ['success' => false, 'message' => 'รายการนี้กำลังประมวลผล กรุณารอสักครู่'];
        }

        $processing = 'processing';
        $reset = $conn->prepare('UPDATE truemoney_redemptions SET status = ?, provider_message = NULL, attempt_count = attempt_count + 1, updated_at = NOW() WHERE id = ?');
        if (!$reset) {
            throw new RuntimeException('prepare redemption reset failed');
        }
        $reset->bind_param('si', $processing, $id);
        if (!$reset->execute()) {
            $reset->close();
            throw new RuntimeException('redemption reset failed');
        }
        $reset->close();
        $conn->commit();
        if ($debug !== null) {
            $debug['redemption']['reservation_mode'] = 'provider';
            trueMoneyDebugEvent($debug, 'reservation_retried', ['redemption_id' => $id, 'previous_status' => $status]);
            trueMoneyDebugCaptureState($debug, $id, $userId);
        }
        return ['success' => true, 'mode' => 'provider', 'id' => $id];
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('TrueMoney reservation error: ' . $e->getMessage());
        if ($debug !== null) {
            trueMoneyDebugError($debug, 'reservation_exception', 'reservation_exception', $e->getMessage(), $e);
            $debug['database_error'] = ['errno' => (int) $conn->errno];
        }
        return ['success' => false, 'message' => 'ไม่สามารถเตรียมรายการเติมเงินได้'];
    }
}

function markTrueMoneyProviderConfirmed(int $redemptionId, int $userId, float $amountThb, ?array &$debug = null): bool
{
    global $conn;
    if ($debug !== null) trueMoneyDebugEvent($debug, 'provider_confirmation_persist_started', ['amount_thb' => round($amountThb, 2)]);
    $settlement = calculateTrueMoneySettlement($amountThb);
    if ($redemptionId < 1 || $userId < 1 || empty($settlement['success'])) {
        if ($debug !== null) trueMoneyDebugError($debug, 'provider_confirmation_persist_failed', 'provider_confirmation_invalid_input', 'Provider-confirmed amount could not be persisted because the input was invalid');
        return false;
    }

    $grossAmount = (float) $settlement['gross_amount_thb'];
    $feeAmount = (float) $settlement['fee_amount_thb'];
    $netAmount = (float) $settlement['net_amount_thb'];
    $status = 'provider_confirmed';
    $stmt = $conn->prepare("UPDATE truemoney_redemptions SET status = ?, amount_thb = ?, fee_amount_thb = ?, net_amount_thb = ?, provider_message = NULL, updated_at = NOW() WHERE id = ? AND user_id = ? AND status <> 'completed'");
    if (!$stmt) {
        if ($debug !== null) {
            trueMoneyDebugError($debug, 'provider_confirmation_persist_failed', 'provider_confirmation_prepare_failed', 'Unable to prepare provider confirmation update');
            $debug['database_error'] = ['errno' => (int) $conn->errno];
        }
        return false;
    }
    $stmt->bind_param('sdddii', $status, $grossAmount, $feeAmount, $netAmount, $redemptionId, $userId);
    $ok = $stmt->execute() && $stmt->affected_rows >= 0;
    $affectedRows = (int) $stmt->affected_rows;
    $stmtErrno = (int) $stmt->errno;
    $stmt->close();
    if ($debug !== null) {
        if ($ok) {
            trueMoneyDebugEvent($debug, 'provider_confirmation_persisted', [
                'affected_rows' => $affectedRows,
                'gross_amount_thb' => $grossAmount,
                'fee_amount_thb' => $feeAmount,
                'net_amount_thb' => $netAmount,
            ]);
            trueMoneyDebugCaptureState($debug, $redemptionId, $userId);
        } else {
            trueMoneyDebugError($debug, 'provider_confirmation_persist_failed', 'provider_confirmation_update_failed', 'Provider confirmation update failed');
            $debug['database_error'] = ['errno' => $stmtErrno];
        }
    }
    return $ok;
}

function markTrueMoneyRedemptionFailed(int $redemptionId, int $userId, string $message, ?array &$debug = null): void
{
    global $conn;
    $message = trim(strip_tags($message));
    $message = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $message);
    if ($message === '') {
        $message = 'Provider rejected the voucher';
    }
    $message = function_exists('mb_substr') ? mb_substr($message, 0, 240, 'UTF-8') : substr($message, 0, 240);
    $status = 'failed';
    $stmt = $conn->prepare("UPDATE truemoney_redemptions SET status = ?, provider_message = ?, updated_at = NOW() WHERE id = ? AND user_id = ? AND status <> 'completed'");
    if ($stmt) {
        $stmt->bind_param('ssii', $status, $message, $redemptionId, $userId);
        $stmt->execute();
        $affectedRows = (int) $stmt->affected_rows;
        $stmtErrno = (int) $stmt->errno;
        $stmt->close();
        if ($debug !== null) {
            trueMoneyDebugEvent($debug, 'redemption_marked_failed', [
                'affected_rows' => $affectedRows,
                'provider_message' => trueMoneyDebugSafeText($message, 500),
                'db_errno' => $stmtErrno,
            ]);
            trueMoneyDebugCaptureState($debug, $redemptionId, $userId);
        }
    } elseif ($debug !== null) {
        trueMoneyDebugError($debug, 'redemption_mark_failed_error', 'redemption_failed_update_prepare_failed', 'Unable to prepare failed-redemption update');
        $debug['database_error'] = ['errno' => (int) $conn->errno];
    }
}

/**
 * Credit a provider-confirmed voucher exactly once.
 */
function completeTrueMoneyRedemption(
    int $redemptionId,
    int $userId,
    float $amountThb,
    float $feeAmountThb,
    float $netAmountThb,
    float $creditAmount,
    string $conversionNote = '',
    ?array &$debug = null
): array
{
    global $conn;
    $completionStage = 'completion_validation';
    if ($debug !== null) {
        trueMoneyDebugEvent($debug, 'completion_started', [
            'redemption_id' => $redemptionId,
            'gross_amount_thb' => round($amountThb, 2),
            'fee_amount_thb' => round($feeAmountThb, 2),
            'net_amount_thb' => round($netAmountThb, 2),
            'credit_amount' => round($creditAmount, 2),
        ]);
    }

    $settlement = calculateTrueMoneySettlement($amountThb);
    if ($redemptionId < 1 || $userId < 1 || empty($settlement['success']) || !is_finite($feeAmountThb) ||
        !is_finite($netAmountThb) || !is_finite($creditAmount) || $creditAmount <= 0 || $creditAmount > 10000000 ||
        abs((float) $settlement['fee_amount_thb'] - $feeAmountThb) > 0.009 ||
        abs((float) $settlement['net_amount_thb'] - $netAmountThb) > 0.009) {
        if ($debug !== null) trueMoneyDebugError($debug, 'completion_validation_failed', 'invalid_settlement_input', 'TrueMoney completion settlement validation failed');
        return ['success' => false, 'message' => 'จำนวนเงินหรือค่าธรรมเนียมไม่ถูกต้อง'];
    }

    $amountThb = (float) $settlement['gross_amount_thb'];
    $feeAmountThb = (float) $settlement['fee_amount_thb'];
    $netAmountThb = (float) $settlement['net_amount_thb'];
    $creditAmount = round($creditAmount, 2, PHP_ROUND_HALF_UP);
    if ($debug !== null) {
        $debug['settlement'] = [
            'gross_amount_thb' => $amountThb,
            'fee_amount_thb' => $feeAmountThb,
            'net_amount_thb' => $netAmountThb,
            'credit_amount' => $creditAmount,
            'conversion_applied' => $conversionNote !== '',
        ];
        trueMoneyDebugEvent($debug, 'completion_validation_passed');
    }

    $completionStage = 'ranking_schema_check';
    if (!function_exists('ensureRankingSchema') || !ensureRankingSchema()) {
        if ($debug !== null) trueMoneyDebugError($debug, 'ranking_schema_unavailable', 'ranking_schema_unavailable', 'Ranking schema is unavailable during TrueMoney completion');
        return ['success' => false, 'message' => Lang::t('ranking.error.unavailable')];
    }
    if ($debug !== null) trueMoneyDebugEvent($debug, 'ranking_schema_ready');

    $completionStage = 'wallet_ledger_schema_check';
    if (!ensureWalletLedgerSchema()) {
        if ($debug !== null) trueMoneyDebugError($debug, 'wallet_ledger_schema_unavailable', 'wallet_ledger_schema_unavailable', 'Wallet ledger schema is unavailable during TrueMoney completion');
        return ['success' => false, 'message' => 'ระบบบันทึกหลักฐานยอดเงินยังไม่พร้อม กรุณาลองใหม่อีกครั้ง'];
    }
    if ($debug !== null) trueMoneyDebugEvent($debug, 'wallet_ledger_schema_ready');

    $conn->begin_transaction();
    if ($debug !== null) trueMoneyDebugEvent($debug, 'wallet_transaction_started');
    try {
        $completionStage = 'redemption_row_lock';
        $select = $conn->prepare('SELECT status, amount_thb, fee_amount_thb, net_amount_thb FROM truemoney_redemptions WHERE id = ? AND user_id = ? LIMIT 1 FOR UPDATE');
        if (!$select) {
            throw new RuntimeException('prepare completion select failed');
        }
        $select->bind_param('ii', $redemptionId, $userId);
        if (!$select->execute()) {
            $select->close();
            throw new RuntimeException('completion select failed');
        }
        $result = $select->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $select->close();
        if (!$row) {
            throw new RuntimeException('redemption record missing');
        }
        if ($debug !== null) trueMoneyDebugEvent($debug, 'redemption_row_locked', ['status' => (string) $row['status']]);
        if ((string) $row['status'] === 'completed') {
            $conn->rollback();
            if ($debug !== null) trueMoneyDebugError($debug, 'completion_rejected', 'redemption_already_completed', 'TrueMoney redemption was already completed before wallet credit');
            return ['success' => false, 'message' => 'ซองนี้ถูกเติมเงินแล้ว'];
        }
        if ((string) $row['status'] !== 'provider_confirmed' || abs((float) $row['amount_thb'] - $amountThb) > 0.009) {
            throw new RuntimeException('provider confirmation mismatch');
        }

        $completionStage = 'settlement_state_verification';
        $storedFee = $row['fee_amount_thb'];
        $storedNet = $row['net_amount_thb'];
        if ($storedFee === null || $storedNet === null) {
            $backfill = $conn->prepare('UPDATE truemoney_redemptions SET fee_amount_thb = ?, net_amount_thb = ?, updated_at = NOW() WHERE id = ? AND user_id = ?');
            if (!$backfill) {
                throw new RuntimeException('prepare settlement backfill failed');
            }
            $backfill->bind_param('ddii', $feeAmountThb, $netAmountThb, $redemptionId, $userId);
            if (!$backfill->execute() || $backfill->affected_rows !== 1) {
                $backfill->close();
                throw new RuntimeException('settlement backfill failed');
            }
            $backfill->close();
            if ($debug !== null) trueMoneyDebugEvent($debug, 'settlement_backfilled_for_legacy_row');
        } elseif (abs((float) $storedFee - $feeAmountThb) > 0.009 || abs((float) $storedNet - $netAmountThb) > 0.009) {
            throw new RuntimeException('stored settlement mismatch');
        } elseif ($debug !== null) {
            trueMoneyDebugEvent($debug, 'settlement_state_verified');
        }

        $completionStage = 'user_row_lock';
        $lockUser = $conn->prepare("SELECT id FROM users WHERE id = ? AND status = 'active' LIMIT 1 FOR UPDATE");
        if (!$lockUser) {
            throw new RuntimeException('prepare user lock failed');
        }
        $lockUser->bind_param('i', $userId);
        if (!$lockUser->execute()) {
            $lockUser->close();
            throw new RuntimeException('user lock failed');
        }
        $lockUser->store_result();
        if ($lockUser->num_rows !== 1) {
            $lockUser->close();
            throw new RuntimeException('active user missing');
        }
        $lockUser->close();
        if ($debug !== null) trueMoneyDebugEvent($debug, 'user_row_locked');

        $completionStage = 'wallet_balance_read_before';
        $walletBefore = walletLedgerReadBalance($userId, true);
        if ($walletBefore === null) throw new RuntimeException('wallet balance read failed');
        if ($debug !== null) {
            $debug['wallet']['balance_before'] = (float) $walletBefore;
            $debug['wallet']['credit_requested'] = $creditAmount;
            trueMoneyDebugEvent($debug, 'wallet_balance_read_before', ['balance_before' => (float) $walletBefore]);
        }

        $completionStage = 'wallet_balance_credit';
        $balance = $conn->prepare("UPDATE users SET balance = balance + ? WHERE id = ? AND status = 'active'");
        if (!$balance) {
            throw new RuntimeException('prepare balance update failed');
        }
        $balance->bind_param('di', $creditAmount, $userId);
        if (!$balance->execute() || $balance->affected_rows !== 1) {
            $balance->close();
            throw new RuntimeException('balance update failed');
        }
        $balance->close();
        $walletAfterBase = round((float) $walletBefore + $creditAmount, 2);
        if ($debug !== null) trueMoneyDebugEvent($debug, 'wallet_base_credit_applied', ['expected_balance_after_base_credit' => $walletAfterBase]);

        $completionStage = 'deposit_transaction_create';
        $description = 'TrueMoney Angpao gross ' . number_format($amountThb, 2, '.', '')
            . ' THB; fee ' . number_format($feeAmountThb, 2, '.', '')
            . ' THB; net ' . number_format($netAmountThb, 2, '.', '') . ' THB' . $conversionNote;
        $depositTransactionId = createTransaction($userId, 'deposit', $creditAmount, 'completed', $description, null);
        if (!$depositTransactionId) {
            throw new RuntimeException('transaction record failed');
        }
        if ($debug !== null) {
            $debug['wallet']['transaction_id'] = (int) $depositTransactionId;
            trueMoneyDebugEvent($debug, 'deposit_transaction_created', ['transaction_id' => (int) $depositTransactionId]);
        }

        $completionStage = 'wallet_ledger_record';
        if (!walletLedgerRecordMovement(
            $userId, $creditAmount, (float) $walletBefore, $walletAfterBase,
            'truemoney_deposit', 'transaction:' . (int) $depositTransactionId,
            $redemptionId, (int) $depositTransactionId, null,
            'เติมเงินผ่าน TrueMoney',
            'Provider-confirmed TrueMoney credit after receiver fee settlement.',
            null, true
        )) throw new RuntimeException('wallet audit failed');
        if ($debug !== null) {
            $debug['wallet']['base_balance_after'] = $walletAfterBase;
            try {
                $ledgerStmt = $conn->prepare("SELECT id,event_key,delta_amount,balance_before,balance_after,transaction_id FROM wallet_balance_ledger WHERE event_key=? LIMIT 1");
                if ($ledgerStmt) {
                    $eventKey = 'transaction:' . (int) $depositTransactionId;
                    $ledgerStmt->bind_param('s', $eventKey);
                    if ($ledgerStmt->execute()) {
                        $ledgerResult = $ledgerStmt->get_result();
                        $ledgerRow = $ledgerResult ? $ledgerResult->fetch_assoc() : null;
                        if ($ledgerRow) {
                            $debug['wallet']['ledger'] = [
                                'id' => (int) $ledgerRow['id'],
                                'event_key' => (string) $ledgerRow['event_key'],
                                'delta_amount' => (float) $ledgerRow['delta_amount'],
                                'balance_before' => (float) $ledgerRow['balance_before'],
                                'balance_after' => (float) $ledgerRow['balance_after'],
                                'transaction_id' => $ledgerRow['transaction_id'] !== null ? (int) $ledgerRow['transaction_id'] : null,
                            ];
                        }
                    }
                    $ledgerStmt->close();
                }
            } catch (Throwable $debugLedgerError) {
                $debug['wallet']['ledger_debug_read_error'] = [
                    'class' => get_class($debugLedgerError),
                    'code' => (int) $debugLedgerError->getCode(),
                ];
            }
            trueMoneyDebugEvent($debug, 'wallet_ledger_recorded');
        }

        $completionStage = 'ranking_bonus_apply';
        $rankResult = rankRecordDepositAndApplyBonus(
            $userId,
            (int) $depositTransactionId,
            $netAmountThb,
            'truemoney',
            'net_after_fee'
        );
        if (empty($rankResult['success'])) {
            throw new RuntimeException((string) ($rankResult['message'] ?? 'ranking bonus failed'));
        }
        if ($debug !== null) {
            $debug['wallet']['ranking'] = [
                'bonus_amount' => round((float) ($rankResult['bonus_amount'] ?? 0), 2),
                'total_credited' => round((float) ($rankResult['total_credited'] ?? $creditAmount), 2),
                'rank_code' => (string) ($rankResult['rank_code'] ?? 'unranked'),
                'bonus_percent' => (float) ($rankResult['bonus_percent'] ?? 0),
            ];
            trueMoneyDebugEvent($debug, 'ranking_bonus_applied', $debug['wallet']['ranking']);
        }

        $completionStage = 'redemption_completion_update';
        $completed = 'completed';
        $finish = $conn->prepare('UPDATE truemoney_redemptions SET status = ?, credited_amount = ?, completed_at = NOW(), updated_at = NOW() WHERE id = ? AND user_id = ?');
        if (!$finish) {
            throw new RuntimeException('prepare completion update failed');
        }
        $finish->bind_param('sdii', $completed, $creditAmount, $redemptionId, $userId);
        if (!$finish->execute() || $finish->affected_rows !== 1) {
            $finish->close();
            throw new RuntimeException('completion update failed');
        }
        $finish->close();
        if ($debug !== null) trueMoneyDebugEvent($debug, 'redemption_marked_completed');

        $completionStage = 'wallet_balance_read_after';
        $balanceRead = $conn->prepare('SELECT balance FROM users WHERE id = ? LIMIT 1');
        if (!$balanceRead) {
            throw new RuntimeException('prepare balance read failed');
        }
        $balanceRead->bind_param('i', $userId);
        $balanceRead->execute();
        $balanceRead->bind_result($newBalance);
        $balanceRead->fetch();
        $balanceRead->close();
        if ($debug !== null) {
            $totalCredited = round((float) ($rankResult['total_credited'] ?? $creditAmount), 2);
            $expectedFinalBalance = round((float) $walletBefore + $totalCredited, 2);
            $debug['wallet']['balance_after'] = (float) $newBalance;
            $debug['wallet']['expected_balance_after_total_credit'] = $expectedFinalBalance;
            $debug['integrity']['base_balance_math_valid'] = abs($walletAfterBase - ((float) $walletBefore + $creditAmount)) <= 0.011;
            $debug['integrity']['final_balance_math_valid'] = abs((float) $newBalance - $expectedFinalBalance) <= 0.011;
            $debug['integrity']['transaction_created'] = (int) $depositTransactionId > 0;
            $debug['integrity']['ledger_linked_to_transaction'] = isset($debug['wallet']['ledger']['transaction_id'])
                ? ((int) $debug['wallet']['ledger']['transaction_id'] === (int) $depositTransactionId) : null;
            trueMoneyDebugEvent($debug, 'wallet_balance_read_after', ['balance_after' => (float) $newBalance]);
        }

        $completionStage = 'wallet_transaction_commit';
        $conn->commit();
        $_SESSION['balance'] = (float) $newBalance;
        if ($debug !== null) {
            trueMoneyDebugEvent($debug, 'wallet_transaction_committed');
            trueMoneyDebugCaptureState($debug, $redemptionId, $userId);
        }
        if (!logHistory($userId, 'truemoney_redeem', $description)) {
            error_log('TrueMoney credit committed but audit history could not be written for redemption ' . $redemptionId);
            if ($debug !== null) trueMoneyDebugError($debug, 'history_write_warning', 'history_write_failed', 'Wallet credit committed but history audit write failed');
        } elseif ($debug !== null) {
            trueMoneyDebugEvent($debug, 'history_written');
        }
        return [
            'success' => true,
            'new_balance' => (float) $newBalance,
            'gross_amount_thb' => $amountThb,
            'fee_amount_thb' => $feeAmountThb,
            'net_amount_thb' => $netAmountThb,
            'bonus_amount' => round((float) ($rankResult['bonus_amount'] ?? 0), 2),
            'total_credited' => round((float) ($rankResult['total_credited'] ?? $creditAmount), 2),
            'rank_code' => (string) ($rankResult['rank_code'] ?? 'unranked'),
            'bonus_percent' => (float) ($rankResult['bonus_percent'] ?? 0),
        ];
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('TrueMoney credit transaction failed: ' . $e->getMessage());
        if ($debug !== null) {
            trueMoneyDebugError($debug, 'wallet_transaction_rolled_back', 'wallet_completion_exception', $e->getMessage(), $e);
            $debug['wallet']['rollback_stage'] = $completionStage;
            $debug['database_error'] = ['errno' => (int) $conn->errno];
            trueMoneyDebugCaptureState($debug, $redemptionId, $userId);
        }
        return ['success' => false, 'message' => 'รับซองสำเร็จแล้ว แต่ระบบยังบันทึกเครดิตไม่เสร็จ กรุณากดส่งลิงก์เดิมอีกครั้ง'];
    }
}


/**
 * Resolve provider DNS for an admin-only diagnostic probe. This never changes
 * redemption state and is intentionally separated from the live redemption path.
 */
function trueMoneyProviderDnsProbe(string $host): array
{
    $host = strtolower(trim($host));
    $started = microtime(true);
    $addresses = [];
    $method = null;
    $error = null;

    if ($host === '' || preg_match('/^[a-z0-9.-]+$/i', $host) !== 1) {
        return [
            'executed' => false,
            'method' => null,
            'host' => trueMoneyDebugSafeText($host, 255),
            'addresses' => [],
            'duration_ms' => 0,
            'error' => 'invalid_host',
        ];
    }

    try {
        if (function_exists('dns_get_record') && defined('DNS_A') && defined('DNS_AAAA')) {
            $method = 'dns_get_record';
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (!is_array($record)) continue;
                    $ip = isset($record['ip']) ? (string) $record['ip'] : (isset($record['ipv6']) ? (string) $record['ipv6'] : '');
                    if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) $addresses[] = $ip;
                }
            }
        }
        if (!$addresses && function_exists('gethostbynamel')) {
            $method = $method ?: 'gethostbynamel';
            $records = @gethostbynamel($host);
            if (is_array($records)) {
                foreach ($records as $ip) {
                    if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP)) $addresses[] = $ip;
                }
            }
        }
    } catch (Throwable $e) {
        $error = get_class($e) . ':' . (string) $e->getCode();
    }

    $addresses = array_values(array_unique(array_slice($addresses, 0, 20)));
    return [
        'executed' => $method !== null,
        'method' => $method,
        'host' => $host,
        'addresses' => $addresses,
        'duration_ms' => max(0, (int) round((microtime(true) - $started) * 1000)),
        'error' => $error,
    ];
}

/**
 * Admin-only safe HTTP probe for the documented TrueMoney provider API.
 *
 * The probe deliberately uses a cryptographically-random synthetic voucher token.
 * It does NOT create a truemoney_redemptions row, does NOT call wallet settlement,
 * and does NOT write a wallet ledger entry. The same redeemAngpao() HTTP code path
 * is used so the transport evidence represents the production provider client.
 */
function trueMoneyRunSafeProviderProbe(int $adminUserId): array
{
    $debug = trueMoneyDebugStart($adminUserId);
    $debug['type'] = 'truemoney.provider_probe';
    $debug['request_id'] = trueMoneyDebugId('tmprobe_');
    $debug['redemption'] = ['id' => null, 'voucher_fingerprint' => null, 'reservation_mode' => 'none_safe_probe'];
    $debug['settlement'] = [];
    $debug['wallet'] = [];
    $debug['integrity'] = [];

    $contract = trueMoneyDocumentedApiContract();
    $providerName = trueMoneyProviderName();
    $phone = function_exists('getSetting') ? preg_replace('/\\D+/', '', (string) getSetting('truemoney_phone')) : '';
    $phoneCheck = trueMoneyPhoneContractCheck($phone);
    $target = trueMoneyDebugProviderUrlSummary((string) TM_API_URL);
    $documentedTarget = trueMoneyDebugUrlSummary((string) ($contract['endpoint'] ?? ''));

    $debug['configuration'] = [
        'feature_enabled' => function_exists('getSetting') ? getSetting('truemoney_enabled') === '1' : null,
        'provider' => $providerName,
        'provider_display_name' => trueMoneyProviderDisplayName(),
        'receiver_phone_masked' => trueMoneyDebugMaskedPhone($phone),
        'receiver_phone_contract' => $phoneCheck,
        'provider_target' => $target,
        'documented_target' => $documentedTarget,
        'endpoint_matches_documentation' => ($target['scheme'] ?? '') === ($documentedTarget['scheme'] ?? '')
            && ($target['host'] ?? '') === ($documentedTarget['host'] ?? ''),
        'provider_authorization_configured' => $providerName === 'legacy_vercel'
            && defined('TRUEMONEY_PROVIDER_TOKEN') && trim((string) TRUEMONEY_PROVIDER_TOKEN) !== '',
    ];

    $curlRuntime = function_exists('curl_version') ? curl_version() : [];
    $curlProtocols = is_array($curlRuntime) && isset($curlRuntime['protocols']) && is_array($curlRuntime['protocols'])
        ? array_values(array_slice(array_map('strval', $curlRuntime['protocols']), 0, 50)) : [];
    $debug['probe'] = [
        'mode' => 'safe_synthetic_voucher_http_probe',
        'provider' => $providerName,
        'real_voucher_used' => false,
        'redemption_row_created' => false,
        'wallet_credit_attempted' => false,
        'wallet_ledger_write_attempted' => false,
        'purpose' => 'Verify the active provider HTTP/application contract without redeeming a real voucher or mutating wallet state.',
        'documented_contract' => $contract,
        'runtime' => [
            'php_version' => PHP_VERSION,
            'php_sapi' => PHP_SAPI,
            'curl_available' => function_exists('curl_init'),
            'curl_version' => is_array($curlRuntime) ? trueMoneyDebugSafeText((string) ($curlRuntime['version'] ?? ''), 120) : null,
            'curl_ssl_version' => is_array($curlRuntime) ? trueMoneyDebugSafeText((string) ($curlRuntime['ssl_version'] ?? ''), 180) : null,
            'curl_https_protocol_available' => in_array('https', $curlProtocols, true),
            'openssl_version' => defined('OPENSSL_VERSION_TEXT') ? trueMoneyDebugSafeText((string) OPENSSL_VERSION_TEXT, 180) : null,
            'dns_get_record_available' => function_exists('dns_get_record'),
            'json_extension_available' => function_exists('json_encode') && function_exists('json_decode'),
        ],
    ];
    trueMoneyDebugEvent($debug, 'probe_configuration_checked', [
        'provider' => $providerName,
        'phone_contract_valid' => (bool) $phoneCheck['exact_10_digits'],
        'endpoint_matches_documentation' => $debug['configuration']['endpoint_matches_documentation'],
    ]);

    if (!$phoneCheck['exact_10_digits']) {
        trueMoneyDebugError($debug, 'probe_configuration_invalid', 'receiver_phone_contract_invalid', 'Configured TrueMoney receiver phone does not match the required 10-digit format beginning with 0');
        $debug['probe']['assessment'] = ['status' => 'failed_before_http', 'http_test_executed' => false, 'confirmed_problem' => 'local_receiver_phone_configuration_invalid'];
        trueMoneyDebugSetResult($debug, false, 'probe_configuration_invalid', 'receiver_phone_contract_invalid');
        trueMoneyDebugPersist($debug);
        return $debug;
    }

    $host = (string) ($target['host'] ?? '');
    trueMoneyDebugEvent($debug, 'probe_dns_started');
    $debug['probe']['dns'] = trueMoneyProviderDnsProbe($host);
    trueMoneyDebugEvent($debug, 'probe_dns_finished', [
        'address_count' => count((array) ($debug['probe']['dns']['addresses'] ?? [])),
        'duration_ms' => (int) ($debug['probe']['dns']['duration_ms'] ?? 0),
        'error' => $debug['probe']['dns']['error'] ?? null,
    ]);

    // The README does not document voucher-token shape. Use a hex token with no special prefix so the probe
    // exercises path parsing while remaining cryptographically random and impossible to intentionally redeem.
    try { $syntheticToken = bin2hex(random_bytes(20)); }
    catch (Throwable $e) { $syntheticToken = hash('sha256', uniqid('', true) . microtime(true)); }
    $syntheticUrl = 'https://gift.truemoney.com/campaign/?v=' . rawurlencode($syntheticToken);
    $debug['redemption']['voucher_fingerprint'] = substr(hash('sha256', $syntheticToken), 0, 16);
    $debug['probe']['synthetic_voucher'] = [
        'token_stored' => false,
        'token_length' => strlen($syntheticToken),
        'charset' => 'hexadecimal',
        'fingerprint' => $debug['redemption']['voucher_fingerprint'],
        'collision_probability_note' => 'Cryptographically-random synthetic token; no real voucher is supplied by the operator.',
    ];

    trueMoneyDebugEvent($debug, 'probe_http_started');
    $callResult = redeemAngpao($syntheticUrl, $phone, $debug);
    trueMoneyDebugEvent($debug, 'probe_http_finished', ['redeem_client_success' => !empty($callResult['success'])]);

    $transport = (array) ($debug['provider']['transport'] ?? []);
    $response = (array) ($debug['provider']['response'] ?? []);
    $httpCode = isset($transport['http_code']) ? (int) $transport['http_code'] : 0;
    $curlErrno = isset($transport['curl_errno']) ? (int) $transport['curl_errno'] : -1;
    $providerStatus = strtolower((string) ($response['provider_status'] ?? ''));
    $providerMessage = (string) ($response['provider_message'] ?? '');
    $jsonValid = !empty($response['json_valid']);
    $httpReachable = $curlErrno === 0 && $httpCode > 0;
    $providerApplicationReached = $httpReachable && $jsonValid && in_array($providerStatus, ['success', 'error'], true);
    $documentedMessages = (array) ($contract['documented_error_messages'] ?? []);
    $documentedMessageObserved = $providerMessage !== '' && in_array($providerMessage, $documentedMessages, true);
    $expectedSyntheticRejection = $providerName === 'xpluem'
        ? ($documentedMessageObserved && in_array($providerMessage, ['ลิงค์ซองของขวัญไม่ถูกต้อง', '404 Not Found'], true))
        : (($response['provider_code'] ?? null) === 'VOUCHER_NOT_FOUND' || preg_match('/ไม่พบซอง|ลิงก์ผิด|voucher[^a-z]+not[^a-z]+found/i', $providerMessage) === 1);
    $unexpectedSuccess = $providerStatus === 'success' || !empty($callResult['success']);

    if ($unexpectedSuccess) {
        $probeStatus = 'critical_unexpected_success';
        $resultCode = 'probe_synthetic_voucher_unexpected_success';
        $resultSuccess = false;
        $conclusion = 'Provider reported success for a synthetic voucher. No local wallet credit was attempted; provider behaviour requires investigation.';
    } elseif ($expectedSyntheticRejection && $providerApplicationReached) {
        $probeStatus = 'passed_expected_rejection';
        $resultCode = 'probe_expected_voucher_rejection';
        $resultSuccess = true;
        $conclusion = 'Provider HTTP/application path is reachable and rejected the synthetic voucher with the expected documented invalid/not-found result.';
    } elseif ($documentedMessageObserved && $providerApplicationReached) {
        $probeStatus = 'passed_documented_error_response';
        $resultCode = 'probe_documented_provider_error_response';
        $resultSuccess = true;
        $conclusion = 'Provider returned a documented application error. HTTP and JSON application handling are reachable; inspect the provider message for the exact condition.';
    } elseif ($providerApplicationReached) {
        $probeStatus = 'provider_application_reached_unclassified_error';
        $resultCode = 'probe_provider_application_error_unclassified';
        $resultSuccess = false;
        $conclusion = 'Provider returned structured JSON, but the result does not match the documented safe-probe outcomes closely enough to claim provider health.';
    } elseif ($httpReachable) {
        $probeStatus = 'http_reached_response_contract_failed';
        $resultCode = 'probe_http_reached_invalid_provider_contract';
        $resultSuccess = false;
        $conclusion = 'HTTP reached the provider, but the response did not satisfy the active provider JSON contract.';
    } else {
        $probeStatus = 'transport_failed';
        $resultCode = 'probe_transport_failed';
        $resultSuccess = false;
        $conclusion = 'SAKAZUKI did not obtain a usable HTTP response from the active provider.';
    }

    $debug['probe']['assessment'] = [
        'status' => $probeStatus,
        'provider' => $providerName,
        'http_test_executed' => true,
        'http_reachable' => $httpReachable,
        'provider_application_reached' => $providerApplicationReached,
        'provider_status' => $providerStatus !== '' ? $providerStatus : null,
        'provider_status_code' => $response['provider_status_code'] ?? null,
        'provider_message' => $providerMessage !== '' ? $providerMessage : null,
        'documented_error_message_observed' => $documentedMessageObserved,
        'expected_synthetic_voucher_rejection_observed' => $expectedSyntheticRejection,
        'platform_block_evidence' => $response['blocking_layer_hint'] ?? null,
        'exact_provider_to_truemoney_root_cause_visible' => false,
        'conclusion' => $conclusion,
    ];
    trueMoneyDebugEvent($debug, 'probe_assessment_completed', [
        'status' => $probeStatus,
        'provider' => $providerName,
        'http_reachable' => $httpReachable,
        'provider_application_reached' => $providerApplicationReached,
    ]);
    trueMoneyDebugSetResult($debug, $resultSuccess, 'probe_completed', $resultCode);
    trueMoneyDebugPersist($debug);
    return $debug;
}

/**
 * Contact the configured third-party provider.
 */
function redeemAngpao($voucherUrl, $mobile, ?array &$debug = null)
{
    $providerName = trueMoneyProviderName();
    if ($debug !== null) trueMoneyDebugEvent($debug, 'provider_preflight_started', ['provider' => $providerName]);

    $normalized = normalizeTrueMoneyVoucherUrl($voucherUrl);
    if (empty($normalized['success'])) {
        if ($debug !== null) trueMoneyDebugError($debug, 'provider_preflight_failed', 'invalid_voucher_url', (string) ($normalized['message'] ?? 'Invalid voucher URL'));
        return $normalized;
    }
    $mobile = (is_string($mobile) || is_scalar($mobile)) ? preg_replace('/\\D+/', '', (string) $mobile) : '';
    if (preg_match('/^0\\d{9}$/', $mobile) !== 1) {
        if ($debug !== null) trueMoneyDebugError($debug, 'provider_preflight_failed', 'invalid_receiver_phone', 'Configured TrueMoney receiver phone must contain exactly 10 digits and begin with 0');
        return ['success' => false, 'message' => 'หมายเลขโทรศัพท์ผู้รับไม่ถูกต้อง'];
    }

    $plan = trueMoneyProviderRequestPlan($normalized, $mobile);
    $requestUrl = (string) ($plan['url'] ?? '');
    if (!function_exists('isSafeHttpsUrl') || !isSafeHttpsUrl($requestUrl)) {
        if ($debug !== null) trueMoneyDebugError($debug, 'provider_preflight_failed', 'unsafe_provider_url', 'Configured TrueMoney provider request URL did not pass HTTPS safety validation');
        return ['success' => false, 'message' => 'ไม่ได้ตั้งค่าผู้ให้บริการ TrueMoney อย่างปลอดภัย'];
    }
    if ($providerName === 'legacy_vercel' && ($plan['body'] ?? null) === false) {
        if ($debug !== null) trueMoneyDebugError($debug, 'provider_preflight_failed', 'provider_payload_encode_failed', json_last_error_msg());
        return ['success' => false, 'message' => 'ไม่สามารถเตรียมข้อมูลซองของขวัญได้'];
    }

    $contract = trueMoneyDocumentedApiContract();
    $phoneContract = trueMoneyPhoneContractCheck($mobile);
    $configuredTarget = trueMoneyDebugProviderUrlSummary((string) TM_API_URL);
    $documentedTarget = trueMoneyDebugUrlSummary((string) ($contract['endpoint'] ?? ''));
    $endpointMatches = ($configuredTarget['scheme'] ?? '') === ($documentedTarget['scheme'] ?? '')
        && ($configuredTarget['host'] ?? '') === ($documentedTarget['host'] ?? '');

    if ($debug !== null) {
        $debug['attempt_id'] = trueMoneyDebugId('tmattempt_');
        $debug['provider']['name'] = $providerName;
        $debug['provider']['target'] = trueMoneyDebugProviderUrlSummary((string) TM_API_URL);
        $debug['provider']['documented_contract'] = $contract;
        $requestMetadata = [
            'provider' => $providerName,
            'method' => (string) ($plan['method'] ?? ''),
            'attempt' => 1,
            'attempt_id' => $debug['attempt_id'],
            'receiver_phone_masked' => trueMoneyDebugMaskedPhone($mobile),
            'voucher_fingerprint' => substr(hash('sha256', (string) $normalized['token']), 0, 16),
            'voucher_token_length' => strlen((string) $normalized['token']),
            'request_fingerprint_sha256' => hash('sha256', $providerName . "\n" . (string) $normalized['token'] . "\n" . $mobile),
            'header_names' => array_values(array_map(static function ($header) {
                $pos = strpos((string) $header, ':');
                return $pos === false ? trueMoneyDebugSafeText((string) $header, 80) : trueMoneyDebugSafeText(substr((string) $header, 0, $pos), 80);
            }, (array) ($plan['headers'] ?? []))),
            'authorization_configured' => !empty($plan['authorization_configured']),
            'connect_timeout_seconds' => 10,
            'total_timeout_seconds' => 30,
            'tls_verify_peer' => true,
            'tls_verify_host' => true,
            'follow_redirects' => false,
            'automatic_retry' => false,
            'cache_bypass_request_headers' => $providerName === 'xpluem',
            'response_limit_bytes' => 1048576,
            'user_agent' => 'SakazukiTrueMoney/2.0',
            'request_url' => trueMoneyDebugProviderUrlSummary($requestUrl),
            'full_request_url_stored' => false,
            'contract_check' => [
                'endpoint_matches_documentation' => $endpointMatches,
                'method_matches_documentation' => strtoupper((string) ($plan['method'] ?? '')) === strtoupper((string) ($contract['method'] ?? '')),
                'phone' => $phoneContract,
                'gift_link_is_canonical_truemoney_url' => !empty($normalized['success']),
            ],
        ];
        if ($providerName === 'xpluem') {
            $requestMetadata['request_encoding'] = 'url_path_parameters';
            $requestMetadata['path_parameters'] = [
                'voucher_token_stored' => false,
                'voucher_token_length' => strlen((string) $normalized['token']),
                'phone_stored' => false,
                'phone_masked' => trueMoneyDebugMaskedPhone($mobile),
            ];
            $requestMetadata['payload_bytes'] = 0;
        } else {
            $body = is_string($plan['body'] ?? null) ? (string) $plan['body'] : '';
            $requestMetadata['request_encoding'] = 'json_body';
            $requestMetadata['content_type'] = 'application/json';
            $requestMetadata['payload_bytes'] = strlen($body);
            $requestMetadata['payload_sha256'] = hash('sha256', $body);
        }
        $debug['provider']['request'] = $requestMetadata;
        trueMoneyDebugEvent($debug, 'provider_preflight_passed', [
            'provider' => $providerName,
            'api_contract_phone_valid' => (bool) $phoneContract['exact_10_digits'],
            'endpoint_matches_documentation' => $endpointMatches,
            'method' => $requestMetadata['method'],
        ]);
    }

    if (!function_exists('curl_init')) {
        if ($debug !== null) trueMoneyDebugError($debug, 'provider_transport_unavailable', 'curl_extension_missing', 'PHP cURL extension is unavailable');
        return ['success' => false, 'message' => 'เซิร์ฟเวอร์ยังไม่รองรับการเชื่อมต่อ TrueMoney'];
    }

    $response = '';
    $tooLarge = false;
    $responseHeaders = [];
    $responseHeaderBytes = 0;
    $ch = curl_init($requestUrl);
    $options = [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_HTTPHEADER => (array) ($plan['headers'] ?? []),
        CURLOPT_ENCODING => '',
        CURLOPT_FAILONERROR => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_USERAGENT => 'SakazukiTrueMoney/2.0',
        CURLOPT_WRITEFUNCTION => static function ($curl, $chunk) use (&$response, &$tooLarge) {
            if (strlen($response) + strlen($chunk) > 1048576) { $tooLarge = true; return 0; }
            $response .= $chunk;
            return strlen($chunk);
        },
        CURLOPT_HEADERFUNCTION => static function ($curl, $line) use (&$responseHeaders, &$responseHeaderBytes) {
            $len = strlen($line); $responseHeaderBytes += $len; $trimmed = trim($line);
            if ($trimmed === '') return $len;
            if (stripos($trimmed, 'HTTP/') === 0) { $responseHeaders = ['status_line' => trueMoneyDebugSafeText($trimmed, 200)]; return $len; }
            $pos = strpos($trimmed, ':'); if ($pos === false) return $len;
            $name = strtolower(trim(substr($trimmed, 0, $pos))); $value = trim(substr($trimmed, $pos + 1));
            $allowed = ['server','content-type','content-length','date','retry-after','cf-ray','x-request-id','x-vercel-id','x-vercel-error','via','x-cache','x-powered-by'];
            if (in_array($name, $allowed, true)) {
                $safeValue = trueMoneyDebugSafeText($value, 500);
                if (isset($responseHeaders[$name])) {
                    $responseHeaders[$name] = (array) $responseHeaders[$name];
                    if (count($responseHeaders[$name]) < 5) $responseHeaders[$name][] = $safeValue;
                } else $responseHeaders[$name] = $safeValue;
            }
            return $len;
        },
    ];
    if (($plan['method'] ?? '') === 'GET') {
        $options[CURLOPT_HTTPGET] = true;
    } else {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = (string) ($plan['body'] ?? '');
    }
    if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
    if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTPS')) $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
    $isProviderProbe = $debug !== null && (($debug['type'] ?? '') === 'truemoney.provider_probe');
    if ($isProviderProbe && defined('CURLOPT_CERTINFO')) $options[CURLOPT_CERTINFO] = true;
    curl_setopt_array($ch, $options);

    if ($debug !== null) trueMoneyDebugEvent($debug, 'provider_request_started', ['provider' => $providerName, 'method' => (string) ($plan['method'] ?? '')]);
    $providerStartedAt = microtime(true);
    $executed = curl_exec($ch);
    $providerFinishedAt = microtime(true);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErrno = curl_errno($ch);
    $curlError = curl_error($ch);
    $info = curl_getinfo($ch);
    $appConnectTime = null;
    if (defined('CURLINFO_APPCONNECT_TIME')) {
        $appConnectValue = curl_getinfo($ch, CURLINFO_APPCONNECT_TIME);
        if (is_numeric($appConnectValue)) $appConnectTime = (float) $appConnectValue;
    }
    if (($appConnectTime === null || $appConnectTime <= 0) && isset($info['appconnect_time']) && is_numeric($info['appconnect_time'])) $appConnectTime = (float) $info['appconnect_time'];
    elseif (($appConnectTime === null || $appConnectTime <= 0) && isset($info['appconnect_time_us']) && is_numeric($info['appconnect_time_us'])) $appConnectTime = ((float) $info['appconnect_time_us']) / 1000000;

    $certificateInfo = [];
    if ($isProviderProbe && defined('CURLINFO_CERTINFO')) {
        $rawCertInfo = curl_getinfo($ch, CURLINFO_CERTINFO);
        if (is_array($rawCertInfo)) foreach (array_slice($rawCertInfo, 0, 5) as $certificate) {
            if (!is_array($certificate)) continue;
            $certificateInfo[] = [
                'subject' => trueMoneyDebugSafeText((string) ($certificate['Subject'] ?? ''), 500),
                'issuer' => trueMoneyDebugSafeText((string) ($certificate['Issuer'] ?? ''), 500),
                'start_date' => trueMoneyDebugSafeText((string) ($certificate['Start date'] ?? ''), 120),
                'expire_date' => trueMoneyDebugSafeText((string) ($certificate['Expire date'] ?? ''), 120),
                'serial_number' => trueMoneyDebugSafeText((string) ($certificate['Serial Number'] ?? ''), 160),
                'signature_algorithm' => trueMoneyDebugSafeText((string) ($certificate['Signature Algorithm'] ?? ''), 120),
            ];
        }
    }
    curl_close($ch);

    $secondsToMs = static function ($value): ?int { return is_numeric($value) ? max(0, (int) round((float) $value * 1000)) : null; };
    $nameLookup = isset($info['namelookup_time']) ? (float) $info['namelookup_time'] : null;
    $connectTime = isset($info['connect_time']) ? (float) $info['connect_time'] : null;
    $preTransfer = isset($info['pretransfer_time']) ? (float) $info['pretransfer_time'] : null;
    $startTransfer = isset($info['starttransfer_time']) ? (float) $info['starttransfer_time'] : null;
    $totalTime = isset($info['total_time']) ? (float) $info['total_time'] : ($providerFinishedAt - $providerStartedAt);
    $tlsComplete = $appConnectTime !== null && $appConnectTime > 0 ? $appConnectTime : null;
    $httpVersionCode = isset($info['http_version']) ? (int) $info['http_version'] : null;
    $httpVersion = $httpVersionCode !== null ? (string) $httpVersionCode : null;
    if ($httpVersionCode !== null) {
        $map = [];
        foreach (['CURL_HTTP_VERSION_1_0'=>'HTTP/1.0','CURL_HTTP_VERSION_1_1'=>'HTTP/1.1','CURL_HTTP_VERSION_2_0'=>'HTTP/2','CURL_HTTP_VERSION_2TLS'=>'HTTP/2','CURL_HTTP_VERSION_3'=>'HTTP/3'] as $constant=>$label) if (defined($constant)) $map[(int) constant($constant)] = $label;
        if (isset($map[$httpVersionCode])) $httpVersion = $map[$httpVersionCode];
    }
    $transport = [
        'executed' => $executed !== false,
        'curl_errno' => $curlErrno,
        'curl_error' => trueMoneyDebugSafeText($curlError, 700),
        'response_too_large' => $tooLarge,
        'http_code' => $httpCode,
        'http_version' => $httpVersion,
        'primary_ip' => trueMoneyDebugSafeText((string) ($info['primary_ip'] ?? ''), 64),
        'primary_port' => isset($info['primary_port']) ? (int) $info['primary_port'] : null,
        'local_ip' => trueMoneyDebugSafeText((string) ($info['local_ip'] ?? ''), 64),
        'local_port' => isset($info['local_port']) ? (int) $info['local_port'] : null,
        'effective_url' => trueMoneyDebugProviderUrlSummary((string) ($info['url'] ?? $requestUrl)),
        'redirect_count' => isset($info['redirect_count']) ? (int) $info['redirect_count'] : null,
        'redirect_url' => isset($info['redirect_url']) && is_scalar($info['redirect_url']) && (string) $info['redirect_url'] !== '' ? trueMoneyDebugProviderUrlSummary((string) $info['redirect_url']) : null,
        'ssl_verify_result' => isset($info['ssl_verify_result']) ? (int) $info['ssl_verify_result'] : (isset($info['ssl_verifyresult']) ? (int) $info['ssl_verifyresult'] : null),
        'timing' => [
            'dns_complete_ms' => $secondsToMs($nameLookup),
            'tcp_connect_complete_ms' => $secondsToMs($connectTime),
            'tcp_connect_phase_ms' => ($connectTime !== null && $nameLookup !== null) ? $secondsToMs(max(0, $connectTime - $nameLookup)) : null,
            'tls_complete_ms' => $secondsToMs($tlsComplete),
            'tls_handshake_phase_ms' => ($tlsComplete !== null && $connectTime !== null) ? $secondsToMs(max(0, $tlsComplete - $connectTime)) : null,
            'pretransfer_complete_ms' => $secondsToMs($preTransfer),
            'ttfb_complete_ms' => $secondsToMs($startTransfer),
            'server_wait_after_pretransfer_ms' => ($startTransfer !== null && $preTransfer !== null) ? $secondsToMs(max(0, $startTransfer - $preTransfer)) : null,
            'response_body_phase_ms' => ($totalTime !== null && $startTransfer !== null) ? $secondsToMs(max(0, $totalTime - $startTransfer)) : null,
            'total_ms' => $secondsToMs($totalTime),
            'wall_clock_ms' => max(0, (int) round(($providerFinishedAt - $providerStartedAt) * 1000)),
        ],
        'transfer' => [
            'request_bytes' => isset($info['request_size']) ? (int) $info['request_size'] : null,
            'upload_bytes' => isset($info['size_upload']) ? (int) round((float) $info['size_upload']) : null,
            'download_bytes_curl' => isset($info['size_download']) ? (int) round((float) $info['size_download']) : null,
            'response_header_bytes' => $responseHeaderBytes,
        ],
        'certificate_chain' => $certificateInfo,
    ];

    $result = json_decode($response, true, 32);
    $jsonErrorCode = json_last_error();
    $jsonError = json_last_error_msg();
    $jsonValid = $jsonErrorCode === JSON_ERROR_NONE && is_array($result);
    $normalizedResponse = trueMoneyNormalizeProviderResponse($result, $httpCode);
    $providerStatus = (string) ($normalizedResponse['application_status'] ?? '');
    $providerMessageForDebug = trueMoneyDebugSafeText((string) ($normalizedResponse['message'] ?? ''), 800);
    $providerCode = isset($normalizedResponse['provider_code']) && is_string($normalizedResponse['provider_code']) ? $normalizedResponse['provider_code'] : null;
    $amountForDebug = isset($normalizedResponse['amount']) && is_numeric((string) $normalizedResponse['amount']) ? round((float) $normalizedResponse['amount'], 2) : null;
    $safeProviderResponse = $jsonValid ? trueMoneyProviderSanitizedResponseForDebug($result) : null;
    if ($jsonValid) {
        $safeJson = json_encode($safeProviderResponse, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $bodyPreview = trueMoneyDebugSafeText(is_string($safeJson) ? $safeJson : '', 1800);
    } else $bodyPreview = trueMoneyDebugSafeText($response, 1800);

    $classificationHaystack = strtolower($bodyPreview . ' ' . (string) json_encode($responseHeaders));
    $infrastructureHint = null;
    if (strpos($classificationHaystack, 'imunify360') !== false) $infrastructureHint = 'imunify360';
    elseif (strpos($classificationHaystack, 'cloudflare') !== false || isset($responseHeaders['cf-ray'])) $infrastructureHint = 'cloudflare_or_edge';
    elseif (strpos($classificationHaystack, 'vercel') !== false || isset($responseHeaders['x-vercel-id']) || isset($responseHeaders['x-vercel-error'])) $infrastructureHint = 'vercel';
    elseif (isset($responseHeaders['server'])) $infrastructureHint = trueMoneyDebugSafeText((string) (is_array($responseHeaders['server']) ? end($responseHeaders['server']) : $responseHeaders['server']), 120);
    $evidenceClassification = trueMoneyProviderEvidenceClassification($httpCode, $curlErrno, $jsonValid, $providerStatus, $providerMessageForDebug, $providerCode, $responseHeaders, $bodyPreview);
    $blockingLayer = $evidenceClassification['platform_block_evidence'] ?? null;

    if ($debug !== null) {
        $headerContentType = $responseHeaders['content-type'] ?? '';
        if (is_array($headerContentType)) $headerContentType = (string) end($headerContentType);
        $contentTypeValue = isset($info['content_type']) && is_scalar($info['content_type']) ? (string) $info['content_type'] : (string) $headerContentType;
        $documentedMessages = (array) ($contract['documented_error_messages'] ?? []);
        $debug['provider']['transport'] = $transport;
        $debug['provider']['response'] = [
            'headers' => trueMoneyDebugSanitize($responseHeaders),
            'content_type' => trueMoneyDebugSafeText($contentTypeValue, 300),
            'body_bytes' => strlen($response),
            'body_sha256' => hash('sha256', $response),
            'body_preview_redacted' => $bodyPreview,
            'json_valid' => $jsonValid,
            'json_error_code' => $jsonErrorCode,
            'json_error' => $jsonErrorCode === JSON_ERROR_NONE ? null : trueMoneyDebugSafeText($jsonError, 300),
            'top_level_keys' => is_array($result) ? array_slice(array_map('strval', array_keys($result)), 0, 50) : [],
            'provider_status' => $providerStatus !== '' ? $providerStatus : null,
            'provider_status_code' => $normalizedResponse['provider_status_code'] ?? null,
            'provider_message' => $providerMessageForDebug !== '' ? $providerMessageForDebug : null,
            'provider_code' => $providerCode,
            'amount_thb' => $amountForDebug,
            'infrastructure_hint' => $infrastructureHint,
            'blocking_layer_hint' => $blockingLayer,
            'contract_analysis' => [
                'provider' => $providerName,
                'valid_json_object' => $jsonValid,
                'contract_recognized' => !empty($normalizedResponse['contract_recognized']),
                'status_present' => $providerStatus !== '',
                'status_supported' => $providerStatus !== '' ? in_array($providerStatus, ['success','error'], true) : false,
                'message_present' => $providerMessageForDebug !== '',
                'provider_status_code' => $normalizedResponse['provider_status_code'] ?? null,
                'documented_error_message' => $providerMessageForDebug !== '' ? in_array($providerMessageForDebug, $documentedMessages, true) : null,
                'success_amount_present_and_numeric' => $providerStatus === 'success' ? $amountForDebug !== null : null,
                'success_http_consistent' => $providerStatus === 'success' ? !empty($normalizedResponse['http_status_consistent']) : null,
            ],
            'evidence_classification' => $evidenceClassification,
        ];
        trueMoneyDebugEvent($debug, 'provider_response_received', [
            'provider' => $providerName,
            'http_code' => $httpCode,
            'curl_errno' => $curlErrno,
            'json_valid' => $jsonValid,
            'response_bytes' => strlen($response),
            'provider_status' => $providerStatus !== '' ? $providerStatus : null,
            'provider_status_code' => $normalizedResponse['provider_status_code'] ?? null,
            'infrastructure_hint' => $infrastructureHint,
            'blocking_layer_hint' => $blockingLayer,
            'failure_domain' => $evidenceClassification['failure_domain'] ?? null,
        ]);
    }

    if ($executed === false || $curlErrno !== 0 || $tooLarge) {
        error_log('TrueMoney provider connection failed; provider=' . $providerName . '; curl=' . $curlErrno . '; oversized=' . ($tooLarge ? '1' : '0'));
        if ($debug !== null) {
            $code = $tooLarge ? 'provider_response_too_large' : ($curlErrno !== 0 ? 'provider_transport_error' : 'provider_execution_failed');
            trueMoneyDebugError($debug, 'provider_transport_failed', $code, $curlError !== '' ? $curlError : 'TrueMoney provider transport failed');
        }
        return ['success' => false, 'message' => 'ไม่สามารถเชื่อมต่อผู้ให้บริการ TrueMoney ได้'];
    }
    if (!$jsonValid || empty($normalizedResponse['contract_recognized'])) {
        error_log('TrueMoney provider invalid response contract; provider=' . $providerName . '; HTTP=' . $httpCode);
        if ($debug !== null) trueMoneyDebugError($debug, 'provider_response_invalid', $jsonValid ? 'provider_contract_unrecognized' : 'provider_invalid_json', $jsonValid ? 'Provider JSON did not match the active provider contract' : ('Provider response was not valid JSON: ' . $jsonError));
        return ['success' => false, 'message' => 'ผู้ให้บริการ TrueMoney ตอบกลับไม่ถูกต้อง'];
    }

    if (!empty($normalizedResponse['success'])) {
        if ($httpCode < 200 || $httpCode >= 300 || empty($normalizedResponse['http_status_consistent'])) {
            if ($debug !== null) trueMoneyDebugError($debug, 'provider_success_inconsistent', 'provider_success_http_inconsistent', 'Provider body reported success but HTTP/status fields were inconsistent with the documented success contract');
            return ['success' => false, 'message' => 'ผู้ให้บริการ TrueMoney ตอบกลับสถานะไม่สอดคล้องกัน'];
        }
        $amount = $amountForDebug;
        if ($amount === null || !is_finite((float) $amount) || (float) $amount <= 0 || (float) $amount > 1000000) {
            if ($debug !== null) trueMoneyDebugError($debug, 'provider_amount_invalid', 'provider_amount_invalid', 'TrueMoney provider returned an invalid or missing amount');
            return ['success' => false, 'message' => 'จำนวนเงินจากซองของขวัญไม่ถูกต้อง'];
        }
        if ($debug !== null) trueMoneyDebugEvent($debug, 'provider_voucher_confirmed', ['provider' => $providerName, 'amount_thb' => (float) $amount]);
        return ['success' => true, 'amount' => (float) $amount];
    }

    $providerMessage = trim(strip_tags((string) ($normalizedResponse['message'] ?? '')));
    $providerMessage = preg_replace('/[\\x00-\\x1F\\x7F]+/u', ' ', $providerMessage);
    if ($providerMessage === '' || strlen($providerMessage) > 200) $providerMessage = 'ไม่สามารถรับซองของขวัญได้ กรุณาตรวจสอบลิงก์และลองใหม่';
    if ($debug !== null) trueMoneyDebugError($debug, 'provider_rejected_voucher', 'provider_rejected', $providerMessage);
    return ['success' => false, 'message' => $providerMessage];
}
