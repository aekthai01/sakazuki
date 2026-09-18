<?php
/**
 * Security Middleware
 * Include this file at the top of every page for comprehensive protection
 */

// ──────────────────────────────────────────────────────────────
// PHP RUNTIME SECURITY
// ──────────────────────────────────────────────────────────────

// Disable error display in production (log instead)
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// Expose as little info as possible
ini_set('expose_php', '0');


/**
 * Return the trusted reverse-proxy rules used by request IP detection.
 *
 * Configuration priority:
 *   1. TRUSTED_PROXY_IPS environment variable (comma/space/newline separated)
 *   2. <domain-root>/private/trusted_proxies.php returning an array of IP/CIDR rules
 *
 * The private-file fallback exists because many shared-hosting PHP-FPM setups do
 * not make custom environment variables pleasant to manage. It is intentionally
 * outside public_html and contains no secrets.
 */
function trustedProxyRules(): array
{
    static $rules = null;
    if (is_array($rules)) return $rules;

    $rawRules = [];
    $environment = trim((string) getenv('TRUSTED_PROXY_IPS'));
    if ($environment !== '') {
        foreach (preg_split('/[\s,;]+/', $environment, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $rule) {
            $rawRules[] = trim((string) $rule);
        }
    } else {
        $file = dirname(__DIR__, 2) . '/private/trusted_proxies.php';
        if (is_file($file) && is_readable($file)) {
            $loaded = require $file;
            if (is_array($loaded)) {
                $candidateRules = isset($loaded['trusted_proxy_ips']) && is_array($loaded['trusted_proxy_ips'])
                    ? $loaded['trusted_proxy_ips']
                    : $loaded;
                foreach ($candidateRules as $rule) {
                    if (is_scalar($rule)) $rawRules[] = trim((string) $rule);
                }
            }
        }
    }

    $validated = [];
    foreach ($rawRules as $entry) {
        if ($entry === '') continue;
        if (strpos($entry, '/') === false) {
            if (!filter_var($entry, FILTER_VALIDATE_IP)) continue;
            $packed = @inet_pton($entry);
            $canonical = $packed === false ? false : @inet_ntop($packed);
            if (is_string($canonical) && $canonical !== '') $validated[strtolower($canonical)] = true;
            continue;
        }

        [$network, $prefixText] = array_pad(explode('/', $entry, 2), 2, '');
        if (!filter_var($network, FILTER_VALIDATE_IP) || !ctype_digit($prefixText)) continue;
        $networkBin = @inet_pton($network);
        if ($networkBin === false) continue;
        $prefix = (int) $prefixText;
        $maxBits = strlen($networkBin) * 8;
        if ($prefix < 0 || $prefix > $maxBits) continue;
        $canonicalNetwork = @inet_ntop($networkBin);
        if (is_string($canonicalNetwork) && $canonicalNetwork !== '') {
            $validated[strtolower($canonicalNetwork) . '/' . $prefix] = true;
        }
    }

    return $rules = array_keys($validated);
}

function trustedProxyConfigurationSource(): string
{
    if (trim((string) getenv('TRUSTED_PROXY_IPS')) !== '') return 'environment';
    $file = dirname(__DIR__, 2) . '/private/trusted_proxies.php';
    if (is_file($file) && is_readable($file) && trustedProxyRules() !== []) return 'private_file';
    return 'none';
}

/** Check whether an IP address belongs to an explicitly trusted proxy. */
function isTrustedProxyAddress(string $ip): bool
{
    if (!filter_var($ip, FILTER_VALIDATE_IP)) return false;
    $ipBin = @inet_pton($ip);
    if ($ipBin === false) return false;
    $canonicalIp = @inet_ntop($ipBin);
    if (!is_string($canonicalIp) || $canonicalIp === '') return false;
    $canonicalIp = strtolower($canonicalIp);

    foreach (trustedProxyRules() as $entry) {
        if (strpos($entry, '/') === false) {
            if (hash_equals($entry, $canonicalIp)) return true;
            continue;
        }

        [$network, $prefixText] = array_pad(explode('/', $entry, 2), 2, '');
        $networkBin = @inet_pton($network);
        if ($networkBin === false || strlen($ipBin) !== strlen($networkBin)) continue;
        $prefix = (int) $prefixText;
        $wholeBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;
        if ($wholeBytes > 0 && substr($ipBin, 0, $wholeBytes) !== substr($networkBin, 0, $wholeBytes)) continue;
        if ($remainingBits > 0) {
            $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
            if ((ord($ipBin[$wholeBytes]) & $mask) !== (ord($networkBin[$wholeBytes]) & $mask)) continue;
        }
        return true;
    }
    return false;
}

function requestIsHttps(): bool
{
    $forceHttps = strtolower(trim((string) getenv('FORCE_HTTPS')));
    if (in_array($forceHttps, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }

    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        return true;
    }

    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if (isTrustedProxyAddress($remote) && isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $proto = strtolower(trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]));
        return $proto === 'https';
    }
    return false;
}

// ──────────────────────────────────────────────────────────────
// SESSION SECURITY
// ──────────────────────────────────────────────────────────────

/**
 * Configure secure session parameters BEFORE session_start()
 */
function configureSecureSession(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return; // Session already started
    }

    // Trust forwarded HTTPS only from a proxy explicitly listed in trusted proxy configuration.
    $isHttps = requestIsHttps();

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    // Use strict session mode (reject uninitialized session IDs)
    ini_set('session.use_strict_mode', '1');

    // Only allow session IDs via cookies (not URL parameters)
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');

    // Session garbage collection
    ini_set('session.gc_maxlifetime', '3600'); // 1 hour max lifetime

    // PHP 8.4 deprecates these knobs; older supported versions still use them.
    if (PHP_VERSION_ID < 80400) {
        ini_set('session.sid_length', '48');
        ini_set('session.sid_bits_per_character', '6');
    }
}

/**
 * Regenerate session ID securely (call after login/privilege change)
 */
function regenerateSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

// ──────────────────────────────────────────────────────────────
// RATE LIMITING
// ──────────────────────────────────────────────────────────────

/**
 * Shared database-backed rate limiter
 * @param string $action The action being rate-limited (e.g., 'login', 'api')
 * @param int $maxAttempts Maximum attempts within the window
 * @param int $windowSeconds Time window in seconds
 * @return bool True if within limit, False if rate-limited
 */
function ensureRateLimitSchema(): bool
{
    global $conn;
    static $ready = null;

    if ($ready !== null) return $ready;
    if (!isset($conn) || !($conn instanceof mysqli)) {
        error_log('Rate limiting unavailable: database connection is missing.');
        return $ready = false;
    }

    try {
        $probe = @$conn->query('SELECT 1 FROM security_rate_limits LIMIT 0');
        if ($probe instanceof mysqli_result) $probe->free();
        if ($probe !== false) return $ready = true;
    } catch (Throwable $e) {
        // Missing table is handled by the CLI-only migration path below.
    }

    $migrationsAllowed = function_exists('sakazukiSchemaMigrationsAllowed')
        ? sakazukiSchemaMigrationsAllowed()
        : PHP_SAPI === 'cli';
    if (!$migrationsAllowed) {
        error_log('Rate limiting schema is unavailable; deployment migration is required.');
        return $ready = false;
    }

    $sql = "CREATE TABLE IF NOT EXISTS security_rate_limits (
        rate_key CHAR(64) NOT NULL,
        attempts INT UNSIGNED NOT NULL DEFAULT 0,
        first_attempt DATETIME NOT NULL,
        last_attempt DATETIME NOT NULL,
        PRIMARY KEY (rate_key),
        KEY idx_last_attempt (last_attempt)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    if (!$conn->query($sql)) {
        error_log('Rate limiting schema error: ' . $conn->error);
        return $ready = false;
    }

    return $ready = true;
}

/**
 * Delete expired limiter rows from the private automation worker, never from a
 * customer request. The bounded delete keeps maintenance work predictable.
 */
function cleanupRateLimitRows(int $retentionDays = 2, int $limit = 5000): bool
{
    global $conn;
    $retentionDays = max(1, min(30, $retentionDays));
    $limit = max(100, min(20000, $limit));
    if (!ensureRateLimitSchema()) return false;

    $sql = "DELETE FROM security_rate_limits
            WHERE last_attempt < DATE_SUB(NOW(), INTERVAL {$retentionDays} DAY)
            LIMIT {$limit}";
    if (!$conn->query($sql)) {
        error_log('Rate limiting cleanup error: ' . $conn->error);
        return false;
    }
    return true;
}

/**
 * Atomically record an attempt and decide whether it is allowed.
 * The current attempt is counted. For example, maxAttempts=5 permits attempts
 * 1 through 5 and rejects attempt 6 until the window expires.
 */
function checkRateLimit(string $action, int $maxAttempts = 5, int $windowSeconds = 300): bool
{
    global $conn;

    $maxAttempts = max(1, $maxAttempts);
    $windowSeconds = max(1, $windowSeconds);
    if (!ensureRateLimitSchema()) {
        // Authentication throttling must fail closed. A broken limiter should not
        // silently turn password guessing protection off.
        return false;
    }

    $key = hash('sha256', $action . '_' . getClientIp());
    $stmt = $conn->prepare(
        "INSERT INTO security_rate_limits (rate_key, attempts, first_attempt, last_attempt)
         VALUES (?, 1, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            attempts = IF(TIMESTAMPDIFF(SECOND, first_attempt, NOW()) >= ?, 1, attempts + 1),
            first_attempt = IF(TIMESTAMPDIFF(SECOND, first_attempt, NOW()) >= ?, NOW(), first_attempt),
            last_attempt = NOW()"
    );
    if (!$stmt) {
        error_log('Rate limiting prepare error: ' . $conn->error);
        return false;
    }
    $stmt->bind_param('sii', $key, $windowSeconds, $windowSeconds);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) {
        error_log('Rate limiting update error: ' . $conn->error);
        return false;
    }

    $read = $conn->prepare('SELECT attempts FROM security_rate_limits WHERE rate_key = ? LIMIT 1');
    if (!$read) {
        error_log('Rate limiting read prepare error: ' . $conn->error);
        return false;
    }
    $read->bind_param('s', $key);
    if (!$read->execute()) {
        error_log('Rate limiting read error: ' . $read->error);
        $read->close();
        return false;
    }
    $read->bind_result($attempts);
    $found = $read->fetch();
    $read->close();

    return $found && (int) $attempts <= $maxAttempts;
}

/**
 * Clear attempts after a successful authentication.
 */
function clearRateLimit(string $action): void
{
    global $conn;
    if (!ensureRateLimitSchema()) {
        return;
    }

    $key = hash('sha256', $action . '_' . getClientIp());
    $stmt = $conn->prepare('DELETE FROM security_rate_limits WHERE rate_key = ?');
    if (!$stmt) {
        error_log('Rate limiting clear prepare error: ' . $conn->error);
        return;
    }
    $stmt->bind_param('s', $key);
    if (!$stmt->execute()) {
        error_log('Rate limiting clear error: ' . $stmt->error);
    }
    $stmt->close();
}

/**
 * Get remaining seconds before a rate limit window resets.
 */
function getRateLimitReset(string $action, int $windowSeconds = 300): int
{
    global $conn;
    $windowSeconds = max(1, $windowSeconds);
    if (!ensureRateLimitSchema()) {
        return $windowSeconds;
    }

    $key = hash('sha256', $action . '_' . getClientIp());
    $stmt = $conn->prepare('SELECT first_attempt FROM security_rate_limits WHERE rate_key = ? LIMIT 1');
    if (!$stmt) {
        return $windowSeconds;
    }
    $stmt->bind_param('s', $key);
    if (!$stmt->execute()) {
        $stmt->close();
        return $windowSeconds;
    }
    $stmt->bind_result($firstAttempt);
    $found = $stmt->fetch();
    $stmt->close();
    if (!$found) {
        return 0;
    }

    $timestamp = strtotime((string) $firstAttempt);
    if ($timestamp === false) {
        return $windowSeconds;
    }
    return max(0, $windowSeconds - (time() - $timestamp));
}

// ──────────────────────────────────────────────────────────────
// IP & REQUEST UTILITIES
// ──────────────────────────────────────────────────────────────

/**
 * Get the real client IP address
 */
function getClientIp(): string
{
    $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if (!filter_var($remote, FILTER_VALIDATE_IP)) {
        $remote = '0.0.0.0';
    }

    // Forwarded headers are user-controlled unless they arrive through a proxy
    // explicitly listed in trusted proxy configuration.
    if (!isTrustedProxyAddress($remote)) {
        return $remote;
    }

    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $header) {
        if (empty($_SERVER[$header])) {
            continue;
        }
        $candidate = trim(explode(',', (string) $_SERVER[$header])[0]);
        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            return $candidate;
        }
    }

    return $remote;
}

/**
 * Check if request is from a known bad bot/scanner
 */
function isBlockedUserAgent(): bool
{
    $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
    $blocked = [
        'sqlmap', 'nikto', 'nessus', 'openvas', 'w3af',
        'acunetix', 'havij', 'appscan', 'webscarab', 'wpscan',
        'dirbuster', 'gobuster', 'masscan', 'zgrab',
    ];
    
    foreach ($blocked as $bot) {
        if (strpos($ua, $bot) !== false) {
            return true;
        }
    }
    
    return false;
}

/**
 * Log suspicious activity
 */
function logSuspiciousActivity(string $reason, array $extra = []): void
{
    $data = [
        'time'       => date('Y-m-d H:i:s'),
        'ip'         => getClientIp(),
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'uri'        => $_SERVER['REQUEST_URI'] ?? '',
        'method'     => $_SERVER['REQUEST_METHOD'] ?? '',
        'reason'     => $reason,
        'extra'      => $extra,
    ];
    
    // Send security events to PHP's configured error log. This avoids creating
    // a JSON log file inside public_html that could be downloaded if web rules fail.
    $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded !== false) {
        error_log('[SECURITY] ' . $encoded);
    }
}

// ──────────────────────────────────────────────────────────────
// INPUT VALIDATION
// ──────────────────────────────────────────────────────────────

/**
 * Deep sanitize input — removes null bytes and excessive whitespace
 */
function deepSanitize($input)
{
    if (is_array($input)) {
        return array_map('deepSanitize', $input);
    }
    
    if (is_string($input)) {
        // Remove null bytes
        $input = str_replace("\0", '', $input);
        // Remove non-printable characters (except newlines and tabs)
        $input = preg_replace('/[^\P{C}\n\t]+/u', '', $input);
        return trim($input);
    }
    
    return $input;
}

// ──────────────────────────────────────────────────────────────
// SECURITY CHECK ON EVERY REQUEST
// ──────────────────────────────────────────────────────────────

/**
 * Run security checks (call at the top of every page)
 */
function runSecurityChecks(): void
{
    // Block known bad bots
    if (isBlockedUserAgent()) {
        logSuspiciousActivity('blocked_user_agent');
        http_response_code(403);
        exit('Access denied.');
    }
    
    // Do not rewrite superglobals globally. Passwords, CSRF tokens, signed payloads,
    // and Base64 images must remain byte-for-byte intact. Validate each field
    // explicitly where it is used instead.

    // Set security headers via PHP (backup for .htaccess)
    if (!headers_sent()) {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        if (requestIsHttps()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
        header_remove('X-Powered-By');
    }
}

// Auto-run security checks when this file is included
configureSecureSession();
runSecurityChecks();
