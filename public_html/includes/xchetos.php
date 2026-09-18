<?php
/**
 * Server-side xChetos HWID reset integration.
 *
 * Confirmed provider flow from the supplied network capture:
 * - POST /api/auth/login with JSON username/password
 * - GET  /api/licenses for server-side ownership/product verification
 * - POST /api/license/reset-hwid with Bearer token and JSON license_key
 *
 * Secrets, access tokens, full provider inventories, HWIDs, and raw provider
 * responses are never returned to a reseller browser. Full license keys entered
 * for reset are stored only as AES-256-GCM ciphertext for administrator audit;
 * reseller pages and JSON responses receive masked keys only.
 */

require_once __DIR__ . '/commerce_context.php';

if (!function_exists('xchetosConfig')) {
    function xchetosConfig(): array
    {
        static $config = null;
        if (is_array($config)) return $config;

        $loaded = [];
        $configSource = 'defaults';
        $explicit = trim((string) getenv('XCHETOS_CONFIG_FILE'));
        $candidates = [];
        if ($explicit !== '') $candidates[] = $explicit;
        $defaultFile = dirname(__DIR__, 2) . '/private/xchetos.php';
        $candidates[] = $defaultFile;

        foreach (array_unique($candidates) as $candidate) {
            if (!is_file($candidate) || !is_readable($candidate)) continue;
            $value = require $candidate;
            if (is_array($value)) {
                $loaded = $value;
                $configSource = $candidate === $defaultFile ? 'private_file' : 'explicit_file';
                break;
            }
        }

        $parseBoolean = static function ($value, bool $default): bool {
            if (is_bool($value)) return $value;
            if (is_int($value)) return $value === 1;
            if (is_string($value)) {
                $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($parsed !== null) return $parsed;
            }
            return $default;
        };

        $fileUsername = trim((string) ($loaded['username'] ?? ''));
        $filePassword = (string) ($loaded['password'] ?? '');
        $envUsername = trim((string) (getenv('XCHETOS_USERNAME') ?: ''));
        $envPasswordRaw = getenv('XCHETOS_PASSWORD');
        $envPassword = $envPasswordRaw === false ? '' : (string) $envPasswordRaw;
        if ($fileUsername === '' && $envUsername !== '') $configSource = 'environment';

        $baseUrl = rtrim(trim((string) ($loaded['base_url'] ?? 'https://xchetos.online')), '/');
        $parts = parse_url($baseUrl);
        $validBaseUrl = is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && strtolower((string) ($parts['host'] ?? '')) === 'xchetos.online'
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && (!isset($parts['port']) || (int) $parts['port'] === 443)
            && in_array((string) ($parts['path'] ?? ''), ['', '/'], true)
            && trim((string) ($parts['query'] ?? '')) === ''
            && trim((string) ($parts['fragment'] ?? '')) === '';
        if (!$validBaseUrl) $baseUrl = '';

        $prefixes = $loaded['allowed_key_prefixes'] ?? ['22-'];
        if (!is_array($prefixes)) $prefixes = [];
        $normalizedPrefixes = [];
        foreach ($prefixes as $prefix) {
            if (!is_scalar($prefix)) continue;
            $prefix = trim((string) $prefix);
            if ($prefix === '' || strlen($prefix) > 64 || preg_match('/[\x00-\x20\x7F]/', $prefix)) continue;
            $normalizedPrefixes[$prefix] = true;
        }

        // This format is based on every xChetos key present in the supplied
        // license and reset-log captures. It remains configurable in case the
        // provider changes its format later.
        $keyPattern = trim((string) ($loaded['allowed_key_pattern'] ?? '/^[0-9]{1,10}-[0-9]{1,10}-[A-Za-z0-9]{1,16}-[A-Fa-f0-9]{40}$/D'));
        $patternValid = $keyPattern !== '' && @preg_match($keyPattern, '') !== false;
        if (!$patternValid) $keyPattern = '/^[0-9]{1,10}-[0-9]{1,10}-[A-Za-z0-9]{1,16}-[A-Fa-f0-9]{40}$/D';

        $settingInt = static function (string $key, int $fallback, int $min, int $max): int {
            $value = $fallback;
            if (function_exists('getSetting')) {
                $stored = getSetting($key, null);
                if ($stored !== null && is_numeric($stored)) $value = (int) $stored;
            }
            return max($min, min($max, $value));
        };

        $fileDailyLimit = (int) ($loaded['reseller_daily_limit'] ?? $loaded['max_attempts'] ?? 100);
        $fileKeyLimit = (int) ($loaded['reseller_key_limit'] ?? 2);

        $config = [
            'provider_code' => 'xchetos',
            'provider_label' => 'xChetos',
            'enabled' => $parseBoolean($loaded['enabled'] ?? true, true),
            'admin_enabled' => $parseBoolean($loaded['admin_enabled'] ?? true, true),
            'verify_provider_ownership' => false,
            'base_url' => $baseUrl,
            'username' => $fileUsername !== '' ? $fileUsername : $envUsername,
            'password' => $filePassword !== '' ? $filePassword : $envPassword,
            'allowed_key_prefixes' => array_keys($normalizedPrefixes),
            'allowed_key_pattern' => $keyPattern,
            'cooldown_seconds' => max(10, min(86400, (int) ($loaded['cooldown_seconds'] ?? 60))),
            'unknown_cooldown_seconds' => max(60, min(86400, (int) ($loaded['unknown_cooldown_seconds'] ?? 300))),
            'reseller_daily_limit' => $settingInt('key_reset_xchetos_reseller_daily_limit', $fileDailyLimit, 1, 5000),
            'reseller_key_limit' => $settingInt('key_reset_xchetos_reseller_key_limit', $fileKeyLimit, 1, 20),
            // A rolling 24-hour window is intentional. Keeping it fixed makes
            // the reset time predictable and prevents a setting from silently
            // weakening the anti-abuse policy.
            'daily_window_seconds' => 86400,
            'burst_max_attempts' => max(2, min(100, (int) ($loaded['burst_max_attempts'] ?? 12))),
            'burst_window_seconds' => max(10, min(3600, (int) ($loaded['burst_window_seconds'] ?? 60))),
            // Backward-compatible aliases for code that still reads the old names.
            'max_attempts' => $settingInt('key_reset_xchetos_reseller_daily_limit', $fileDailyLimit, 1, 5000),
            'rate_window_seconds' => 86400,
            'diagnostic_max_attempts' => max(1, min(50, (int) ($loaded['diagnostic_max_attempts'] ?? 10))),
            'connect_timeout' => max(2, min(30, (int) ($loaded['connect_timeout'] ?? 8))),
            'request_timeout' => max(5, min(60, (int) ($loaded['request_timeout'] ?? 20))),
            'provider_page_size' => max(20, min(500, (int) ($loaded['provider_page_size'] ?? 100))),
            'provider_max_pages' => max(1, min(500, (int) ($loaded['provider_max_pages'] ?? 250))),
            'provider_max_records' => max(100, min(100000, (int) ($loaded['provider_max_records'] ?? 25000))),
            'force_ipv4' => $parseBoolean($loaded['force_ipv4'] ?? false, false),
            '_config_source' => $configSource,
            '_default_config_exists' => is_file($defaultFile),
            '_default_config_readable' => is_readable($defaultFile),
        ];
        return $config;
    }
}

if (!function_exists('xchetosBindParams')) {
    /** Safely bind a dynamic parameter array by reference for mysqli. */
    function xchetosBindParams(mysqli_stmt $stmt, string $types, array &$params): bool
    {
        if ($types === '' || $params === []) return true;
        $arguments = [$types];
        foreach ($params as &$value) $arguments[] = &$value;
        unset($value);
        return $stmt->bind_param(...$arguments);
    }
}

if (!function_exists('xchetosRequestId')) {
    function xchetosRequestId(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (Throwable $e) {
            return substr(hash('sha256', uniqid('', true) . microtime(true)), 0, 32);
        }
    }
}


if (!function_exists('xchetosNormalizeRequestId')) {
    /**
     * Accept only a 32-character hexadecimal client request id. Invalid input
     * receives a fresh server-generated id, so the value is safe for logs and
     * status lookups.
     */
    function xchetosNormalizeRequestId(string $requestId = ''): string
    {
        $requestId = strtolower(trim($requestId));
        return preg_match('/^[a-f0-9]{32}$/D', $requestId) === 1
            ? $requestId
            : xchetosRequestId();
    }
}

if (!function_exists('xchetosIsConfigured')) {
    function xchetosIsConfigured(): bool
    {
        $config = xchetosConfig();
        return !empty($config['enabled'])
            && (string) ($config['base_url'] ?? '') !== ''
            && trim((string) ($config['username'] ?? '')) !== ''
            && (string) ($config['password'] ?? '') !== '';
    }
}

if (!function_exists('xchetosSystemStatus')) {
    function xchetosSystemStatus(): array
    {
        $config = xchetosConfig();
        $curlVersion = function_exists('curl_version') ? curl_version() : [];
        $keyVaultPath = xchetosKeyVaultSecretPath();
        $keyVaultAvailable = xchetosGetKeyVaultSecret(false) !== null;
        $keyVaultSecretExists = is_file($keyVaultPath);
        $keyVaultDirectory = dirname($keyVaultPath);
        return [
            'enabled' => !empty($config['enabled']),
            'admin_enabled' => !empty($config['admin_enabled']),
            'configured' => xchetosIsConfigured(),
            'base_url_valid' => (string) ($config['base_url'] ?? '') !== '',
            'username_configured' => trim((string) ($config['username'] ?? '')) !== '',
            'password_configured' => (string) ($config['password'] ?? '') !== '',
            'config_source' => (string) ($config['_config_source'] ?? 'unknown'),
            'private_config_exists' => !empty($config['_default_config_exists']),
            'private_config_readable' => !empty($config['_default_config_readable']),
            'curl_available' => function_exists('curl_init'),
            'curl_version' => is_array($curlVersion) ? (string) ($curlVersion['version'] ?? '') : '',
            'ssl_version' => is_array($curlVersion) ? (string) ($curlVersion['ssl_version'] ?? '') : '',
            'php_version' => PHP_VERSION,
            'mysqli_available' => class_exists('mysqli', false) || extension_loaded('mysqli'),
            'key_vault_available' => $keyVaultAvailable,
            'key_vault_secret_exists' => $keyVaultSecretExists,
            'key_vault_secret_invalid' => $keyVaultSecretExists && !$keyVaultAvailable,
            'key_vault_directory_writable' => is_dir($keyVaultDirectory) && is_writable($keyVaultDirectory),
            'key_vault_locked' => !$keyVaultSecretExists && xchetosKeyVaultHasEncryptedRows(),
            'allowed_key_prefixes' => array_values((array) ($config['allowed_key_prefixes'] ?? [])),
            'allowed_key_pattern' => (string) ($config['allowed_key_pattern'] ?? ''),
            'verify_provider_ownership' => !empty($config['verify_provider_ownership']),
            'reseller_daily_limit' => (int) ($config['reseller_daily_limit'] ?? 100),
            'reseller_key_limit' => (int) ($config['reseller_key_limit'] ?? 2),
            'daily_window_seconds' => (int) ($config['daily_window_seconds'] ?? 86400),
            'provider_page_size' => (int) ($config['provider_page_size'] ?? 100),
            'provider_max_pages' => (int) ($config['provider_max_pages'] ?? 250),
            'provider_max_records' => (int) ($config['provider_max_records'] ?? 25000),
        ];
    }
}

if (!function_exists('xchetosMaskKey')) {
    function xchetosMaskKey(string $key): string
    {
        $key = xchetosNormalizeLicenseKey($key);
        $length = strlen($key);
        if ($length === 0) return '-';
        if ($length <= 8) return str_repeat('*', max(4, $length));
        if ($length <= 16) return substr($key, 0, 4) . '****' . substr($key, -2);
        return substr($key, 0, 8) . '****' . substr($key, -6);
    }
}

if (!function_exists('xchetosSanitizeText')) {
    function xchetosSanitizeText(string $value, string $licenseKey = '', int $maxLength = 500): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value) ?? '';
        if ($licenseKey !== '') $value = str_ireplace($licenseKey, '[KEY]', $value);
        // Redact any token or key shaped like the captured xChetos credentials.
        $value = preg_replace('/\bBearer\s+[A-Za-z0-9._-]{20,}\b/i', 'Bearer [REDACTED]', $value) ?? $value;
        $value = preg_replace('/\b[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b/', '[TOKEN]', $value) ?? $value;
        $value = preg_replace('/\b[0-9]{1,10}-[0-9]{1,10}-[A-Za-z0-9]{1,16}-[A-Fa-f0-9]{40}\b/', '[KEY]', $value) ?? $value;
        return substr(trim($value), 0, max(1, min(5000, $maxLength)));
    }
}

if (!function_exists('xchetosNormalizeLicenseKey')) {
    /** Remove accidental whitespace from copied license keys without changing key characters. */
    function xchetosNormalizeLicenseKey(string $key): string
    {
        $key = trim($key);
        $normalized = preg_replace('/[\s\x{200B}\x{FEFF}]+/u', '', $key);
        return is_string($normalized) ? $normalized : $key;
    }
}

if (!function_exists('xchetosKeyDurationDays')) {
    /** Parse the duration segment from keys such as 22-4-3D-.... */
    function xchetosKeyDurationDays(string $key): int
    {
        $key = xchetosNormalizeLicenseKey($key);
        $parts = explode('-', $key);
        if (!isset($parts[2]) || preg_match('/^([0-9]{1,5})D$/i', (string) $parts[2], $matches) !== 1) return 0;
        return max(0, min(65535, (int) $matches[1]));
    }
}

if (!function_exists('xchetosKeyResetPolicy')) {
    /**
     * Automatic reseller reset allowance by duration.
     * 1 day = 2, 2-3 days = 4, 4 days or longer = 5.
     * Unknown/non-day formats use the legacy fallback, capped at 5.
     */
    function xchetosKeyResetPolicy(string $key): array
    {
        $days = xchetosKeyDurationDays($key);
        if ($days === 1) {
            $limit = 2;
        } elseif ($days >= 2 && $days <= 3) {
            $limit = 4;
        } elseif ($days >= 4) {
            $limit = 5;
        } else {
            $config = xchetosConfig();
            $limit = max(1, min(5, (int) ($config['reseller_key_limit'] ?? 2)));
        }
        return ['days' => $days, 'limit' => $limit];
    }
}

if (!function_exists('xchetosKeyEligibility')) {
    function xchetosKeyEligibility(string $key): array
    {
        $key = xchetosNormalizeLicenseKey($key);
        if ($key === '' || strlen($key) < 12 || strlen($key) > 255 || preg_match('/[\x00-\x20\x7F]/', $key)) {
            return ['eligible' => false, 'code' => 'key_format_invalid'];
        }

        $config = xchetosConfig();
        $prefixes = (array) ($config['allowed_key_prefixes'] ?? []);
        if ($prefixes !== []) {
            $prefixMatched = false;
            foreach ($prefixes as $prefix) {
                $prefix = (string) $prefix;
                if ($prefix !== '' && strncmp($key, $prefix, strlen($prefix)) === 0) {
                    $prefixMatched = true;
                    break;
                }
            }
            if (!$prefixMatched) return ['eligible' => false, 'code' => 'key_prefix_not_allowed'];
        }

        $pattern = (string) ($config['allowed_key_pattern'] ?? '');
        if ($pattern !== '' && @preg_match($pattern, $key) !== 1) {
            return ['eligible' => false, 'code' => 'key_format_not_xchetos'];
        }
        return ['eligible' => true, 'code' => 'eligible'];
    }
}

if (!function_exists('xchetosKeyIsEligible')) {
    function xchetosKeyIsEligible(string $key): bool
    {
        return !empty(xchetosKeyEligibility($key)['eligible']);
    }
}

if (!function_exists('xchetosKeyVaultSecretPath')) {
    function xchetosKeyVaultSecretPath(): string
    {
        return dirname(__DIR__, 2) . '/private/key_reset_vault_secret.php';
    }
}

if (!function_exists('xchetosReadKeyVaultSecret')) {
    function xchetosReadKeyVaultSecret(string $path): ?string
    {
        if (!is_file($path) || !is_readable($path)) return null;
        try {
            $encoded = require $path;
        } catch (Throwable $error) {
            error_log('Key reset vault secret read failed: ' . $error->getMessage());
            return null;
        }
        if (!is_string($encoded)) return null;
        $secret = base64_decode(trim($encoded), true);
        return is_string($secret) && strlen($secret) === 32 ? $secret : null;
    }
}

if (!function_exists('xchetosKeyVaultHasEncryptedRows')) {
    function xchetosKeyVaultHasEncryptedRows(): bool
    {
        global $conn;
        if (!isset($conn) || !($conn instanceof mysqli)) return false;
        $table = $conn->query("SHOW TABLES LIKE 'xchetos_hwid_reset_logs'");
        if (!$table) return false;
        $exists = $table->num_rows > 0;
        $table->free();
        if (!$exists) return false;
        $column = $conn->query("SHOW COLUMNS FROM xchetos_hwid_reset_logs LIKE 'key_ciphertext'");
        if (!$column) return false;
        $hasColumn = $column->num_rows > 0;
        $column->free();
        if (!$hasColumn) return false;
        $result = $conn->query(
            "SELECT 1 FROM xchetos_hwid_reset_logs
             WHERE key_ciphertext IS NOT NULL AND key_ciphertext <> '' LIMIT 1"
        );
        if (!$result) return false;
        $hasRows = $result->num_rows > 0;
        $result->free();
        return $hasRows;
    }
}

if (!function_exists('xchetosGetKeyVaultSecret')) {
    /**
     * Load or atomically create the 256-bit key used only for reset-log keys.
     * The file lives outside public_html and must be backed up with the database.
     */
    function xchetosGetKeyVaultSecret(bool $create = true): ?string
    {
        static $cachedSecret = null;
        if (is_string($cachedSecret) && strlen($cachedSecret) === 32) return $cachedSecret;

        $path = xchetosKeyVaultSecretPath();
        $existing = xchetosReadKeyVaultSecret($path);
        if ($existing !== null) {
            $cachedSecret = $existing;
            return $cachedSecret;
        }
        if (!$create || !function_exists('openssl_encrypt') || !function_exists('openssl_decrypt')) return null;
        if (xchetosKeyVaultHasEncryptedRows()) {
            error_log('Key reset vault secret is missing while encrypted audit rows already exist. Restore private/key_reset_vault_secret.php from backup.');
            return null;
        }

        $directory = dirname($path);
        if (!is_dir($directory) || !is_writable($directory)) {
            error_log('Key reset vault directory is not writable: ' . $directory);
            return null;
        }

        try {
            $newSecret = random_bytes(32);
            $temporary = $path . '.tmp.' . bin2hex(random_bytes(8));
        } catch (Throwable $error) {
            error_log('Key reset vault random generation failed: ' . $error->getMessage());
            return null;
        }

        $payload = "<?php\n// Auto-generated. Keep this file private and back it up.\nreturn '" . base64_encode($newSecret) . "';\n";
        $written = @file_put_contents($temporary, $payload, LOCK_EX);
        if ($written !== strlen($payload)) {
            @unlink($temporary);
            error_log('Key reset vault temporary file could not be written.');
            return null;
        }
        @chmod($temporary, 0600);

        // link() publishes the complete temporary file atomically and does not
        // overwrite a secret created by another concurrent request. Some shared
        // hosts disable link(), so an exclusive-create fallback is provided.
        $published = function_exists('link') ? @link($temporary, $path) : false;
        if (!$published && !is_file($path)) {
            $handle = @fopen($path, 'x');
            if (is_resource($handle)) {
                $bytes = 0;
                $payloadLength = strlen($payload);
                while ($bytes < $payloadLength) {
                    $writtenChunk = @fwrite($handle, substr($payload, $bytes));
                    if (!is_int($writtenChunk) || $writtenChunk < 1) break;
                    $bytes += $writtenChunk;
                }
                @fflush($handle);
                @fclose($handle);
                $published = $bytes === $payloadLength;
                if (!$published) @unlink($path);
            }
        }
        @unlink($temporary);
        if (!$published) {
            $existing = xchetosReadKeyVaultSecret($path);
            if ($existing !== null) {
                $cachedSecret = $existing;
                return $cachedSecret;
            }
            error_log('Key reset vault secret could not be published.');
            return null;
        }
        @chmod($path, 0600);
        $cachedSecret = $newSecret;
        return $cachedSecret;
    }
}

if (!function_exists('xchetosEncryptAuditKey')) {
    function xchetosEncryptAuditKey(string $licenseKey, string $keyHash): string
    {
        $licenseKey = xchetosNormalizeLicenseKey($licenseKey);
        $keyHash = strtolower(trim($keyHash));
        if ($licenseKey === '' || preg_match('/^[a-f0-9]{64}$/D', $keyHash) !== 1) return '';
        $secret = xchetosGetKeyVaultSecret(true);
        if ($secret === null) return '';
        try {
            $iv = random_bytes(12);
        } catch (Throwable $error) {
            return '';
        }
        $tag = '';
        $aad = 'xchetos-reset-key:v1:' . $keyHash;
        $ciphertext = openssl_encrypt(
            $licenseKey,
            'aes-256-gcm',
            $secret,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $aad,
            16
        );
        if (!is_string($ciphertext) || strlen($tag) !== 16) return '';
        return 'v1:' . base64_encode($iv . $tag . $ciphertext);
    }
}

if (!function_exists('xchetosDecryptAuditKey')) {
    function xchetosDecryptAuditKey(string $encrypted, string $keyHash): string
    {
        $encrypted = trim($encrypted);
        $keyHash = strtolower(trim($keyHash));
        if (strpos($encrypted, 'v1:') !== 0 || preg_match('/^[a-f0-9]{64}$/D', $keyHash) !== 1) return '';
        $packed = base64_decode(substr($encrypted, 3), true);
        if (!is_string($packed) || strlen($packed) < 29) return '';
        $secret = xchetosGetKeyVaultSecret(false);
        if ($secret === null) return '';
        $iv = substr($packed, 0, 12);
        $tag = substr($packed, 12, 16);
        $ciphertext = substr($packed, 28);
        $aad = 'xchetos-reset-key:v1:' . $keyHash;
        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $secret,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $aad
        );
        if (!is_string($plaintext)) return '';
        $plaintext = xchetosNormalizeLicenseKey($plaintext);
        if ($plaintext === '' || strlen($plaintext) > 255) return '';
        return hash_equals($keyHash, hash('sha256', $plaintext)) ? $plaintext : '';
    }
}

if (!function_exists('xchetosCanonicalKeySearchText')) {
    /**
     * Build a case-insensitive, separator-insensitive representation used only
     * for searching. The original key and quota hash remain unchanged.
     */
    function xchetosCanonicalKeySearchText(string $value): string
    {
        $value = trim($value);
        $value = str_replace(
            ["\xE2\x80\x90", "\xE2\x80\x91", "\xE2\x80\x92", "\xE2\x80\x93", "\xE2\x80\x94", "\xE2\x88\x92"],
            '-',
            $value
        );
        $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        $compact = preg_replace('/[^a-z0-9]+/i', '', $value);
        return is_string($compact) ? substr($compact, 0, 255) : '';
    }
}

if (!function_exists('xchetosKeySearchSecret')) {
    /** Derive a domain-separated HMAC key from the audit vault secret. */
    function xchetosKeySearchSecret(): ?string
    {
        static $derived = null;
        if (is_string($derived) && strlen($derived) === 32) return $derived;
        $vaultSecret = xchetosGetKeyVaultSecret(false);
        if (!is_string($vaultSecret) || strlen($vaultSecret) !== 32) return null;
        $derived = hash_hmac('sha256', 'xchetos-key-search-index:v2', $vaultSecret, true);
        return $derived;
    }
}

if (!function_exists('xchetosKeySearchToken')) {
    /** Prefix token type so exact-key and trigram lookups cannot collide. */
    function xchetosKeySearchToken(string $type, string $fragment): string
    {
        $secret = xchetosKeySearchSecret();
        if ($secret === null || $fragment === '' || !in_array($type, ['e', 'g'], true)) return '';
        return $type . substr(hash_hmac('sha256', $type . ':' . $fragment, $secret), 0, 24);
    }
}

if (!function_exists('xchetosBuildKeySearchTokens')) {
    /** Exact HMAC plus HMAC trigrams; no plaintext key fragment is stored. */
    function xchetosBuildKeySearchTokens(string $licenseKey): array
    {
        $canonical = xchetosCanonicalKeySearchText($licenseKey);
        $length = strlen($canonical);
        if ($length < 3) return [];
        $tokens = [];
        $exact = xchetosKeySearchToken('e', $canonical);
        if ($exact !== '') $tokens[$exact] = true;
        for ($index = 0; $index <= $length - 3; $index++) {
            $token = xchetosKeySearchToken('g', substr($canonical, $index, 3));
            if ($token !== '') $tokens[$token] = true;
        }
        return array_keys($tokens);
    }
}

if (!function_exists('xchetosKeySearchFragments')) {
    /**
     * Accept full keys and masked shorthand such as PREFIX****SUFFIX or
     * PREFIX...SUFFIX. Every visible fragment must occur in the same key.
     */
    function xchetosKeySearchFragments(string $query): array
    {
        $query = trim($query);
        if ($query === '') return [];
        $hasMaskSeparator = preg_match('/(?:\*{2,}|\.{2,}|…+|•+|_{3,})/u', $query) === 1;
        $parts = $hasMaskSeparator
            ? preg_split('/(?:\*{2,}|\.{2,}|…+|•+|_{3,})/u', $query, -1, PREG_SPLIT_NO_EMPTY)
            : [$query];
        if (!is_array($parts)) $parts = [$query];
        $fragments = [];
        foreach ($parts as $part) {
            $canonical = xchetosCanonicalKeySearchText((string) $part);
            if (strlen($canonical) >= 3) $fragments[$canonical] = true;
            if (count($fragments) >= 4) break;
        }
        return array_keys($fragments);
    }
}

if (!function_exists('xchetosKeySearchQueryTokens')) {
    /** Limit prepared parameters while sampling the whole fragment, including both ends. */
    function xchetosKeySearchQueryTokens(string $fragment, int $maxTokens = 10): array
    {
        $fragment = xchetosCanonicalKeySearchText($fragment);
        $length = strlen($fragment);
        if ($length < 3) return [];
        $grams = [];
        for ($index = 0; $index <= $length - 3; $index++) $grams[] = substr($fragment, $index, 3);
        $grams = array_values(array_unique($grams));
        $maxTokens = max(3, min(16, $maxTokens));
        if (count($grams) > $maxTokens) {
            $sampled = [];
            $last = count($grams) - 1;
            for ($slot = 0; $slot < $maxTokens; $slot++) {
                $position = (int) round(($slot * $last) / max(1, $maxTokens - 1));
                $sampled[$grams[$position]] = true;
            }
            $grams = array_keys($sampled);
        }
        $tokens = [];
        foreach ($grams as $gram) {
            $token = xchetosKeySearchToken('g', $gram);
            if ($token !== '') $tokens[$token] = true;
        }
        return array_keys($tokens);
    }
}

if (!function_exists('xchetosFindEncryptedKeyHashesForSearch')) {
    /**
     * Bounded self-healing fallback for administrator key searches.
     *
     * The normal HMAC index is fast. If an older row missed that index (or the
     * key was entered with different separators/case), decrypt recent audit keys
     * on the server, compare their canonical form, and rebuild the missing index.
     * Plaintext keys never enter SQL search parameters or browser responses here.
     */
    function xchetosFindEncryptedKeyHashesForSearch(string $query, int $limit = 2500): array
    {
        global $conn;
        $query = trim($query);
        $canonical = xchetosCanonicalKeySearchText($query);
        $masked = preg_match('/(?:\*{2,}|\.{2,}|…+|•+|_{3,})/u', $query) === 1;
        $looksLikeKey = $masked
            || substr_count($query, '-') >= 2
            || strlen($canonical) >= 8
            || (strlen($canonical) >= 3
                && preg_match('/^[a-z0-9_-]+$/iD', $query) === 1);
        if (!$looksLikeKey || !isset($conn) || !($conn instanceof mysqli)) return [];

        $fragments = xchetosKeySearchFragments($query);
        if ($fragments === [] && strlen($canonical) >= 3) $fragments = [$canonical];
        if ($fragments === []) return [];

        $limit = max(100, min(5000, $limit));
        $zeroHash = str_repeat('0', 64);
        $sql = "SELECT latest.key_hash, latest.key_ciphertext
                FROM xchetos_hwid_reset_logs latest
                INNER JOIN (
                    SELECT key_hash, MAX(id) AS max_id
                    FROM xchetos_hwid_reset_logs
                    WHERE key_hash <> '{$zeroHash}'
                      AND key_ciphertext IS NOT NULL AND key_ciphertext <> ''
                    GROUP BY key_hash
                    ORDER BY max_id DESC
                    LIMIT {$limit}
                ) recent ON recent.max_id = latest.id";
        $result = $conn->query($sql);
        if (!$result) return [];

        $matches = [];
        while ($row = $result->fetch_assoc()) {
            $keyHash = strtolower(trim((string) ($row['key_hash'] ?? '')));
            if (preg_match('/^[a-f0-9]{64}$/D', $keyHash) !== 1) continue;
            $licenseKey = xchetosDecryptAuditKey((string) ($row['key_ciphertext'] ?? ''), $keyHash);
            if ($licenseKey === '') continue;
            $candidate = xchetosCanonicalKeySearchText($licenseKey);
            $matched = $candidate !== '';
            foreach ($fragments as $fragment) {
                if (strpos($candidate, $fragment) === false) {
                    $matched = false;
                    break;
                }
            }
            if (!$matched) continue;
            $matches[$keyHash] = true;
            // Repair the fast path so the same search does not need this scan again.
            xchetosStoreKeySearchIndex($licenseKey, $keyHash);
            if (count($matches) >= 250) break;
        }
        $result->free();
        return array_keys($matches);
    }
}

if (!function_exists('xchetosEscapeLikeValue')) {
    function xchetosEscapeLikeValue(string $value): string
    {
        return strtr($value, ['=' => '==', '%' => '=%', '_' => '=_']);
    }
}

if (!function_exists('xchetosInsertKeySearchPairs')) {
    /** Insert token/key pairs in large batches so historical indexing stays fast. */
    function xchetosInsertKeySearchPairs(array $pairs): bool
    {
        global $conn;
        if ($pairs === [] || !isset($conn) || !($conn instanceof mysqli)) return $pairs === [];
        if (!xchetosDbTableExists('xchetos_hwid_reset_key_search')) return false;
        $deduplicated = [];
        foreach ($pairs as $pair) {
            $token = (string) ($pair[0] ?? '');
            $keyHash = strtolower((string) ($pair[1] ?? ''));
            if (preg_match('/^[eg][a-f0-9]{24}$/D', $token) !== 1
                || preg_match('/^[a-f0-9]{64}$/D', $keyHash) !== 1) continue;
            $deduplicated[$token . ':' . $keyHash] = [$token, $keyHash];
        }
        if ($deduplicated === []) return false;
        $all = array_values($deduplicated);
        foreach (array_chunk($all, 4000) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '(?, ?)'));
            $stmt = $conn->prepare(
                "INSERT IGNORE INTO xchetos_hwid_reset_key_search (token, key_hash) VALUES {$placeholders}"
            );
            if (!$stmt) return false;
            $params = [];
            foreach ($chunk as $pair) {
                $params[] = $pair[0];
                $params[] = $pair[1];
            }
            $types = str_repeat('ss', count($chunk));
            if (!xchetosBindParams($stmt, $types, $params) || !$stmt->execute()) {
                $stmt->close();
                return false;
            }
            $stmt->close();
        }
        return true;
    }
}

if (!function_exists('xchetosStoreKeySearchIndex')) {
    function xchetosStoreKeySearchIndex(string $licenseKey, string $keyHash): bool
    {
        $licenseKey = xchetosNormalizeLicenseKey($licenseKey);
        $keyHash = strtolower(trim($keyHash));
        if ($licenseKey === '' || preg_match('/^[a-f0-9]{64}$/D', $keyHash) !== 1
            || !hash_equals($keyHash, hash('sha256', $licenseKey))) return false;
        $pairs = [];
        foreach (xchetosBuildKeySearchTokens($licenseKey) as $token) $pairs[] = [$token, $keyHash];
        return $pairs !== [] && xchetosInsertKeySearchPairs($pairs);
    }
}

if (!function_exists('xchetosBackfillEncryptedAuditKey')) {
    /** Store encrypted key for historic rows and add its secure search index. */
    function xchetosBackfillEncryptedAuditKey(string $licenseKey, string $keyHash): bool
    {
        global $conn;
        $licenseKey = xchetosNormalizeLicenseKey($licenseKey);
        $keyHash = strtolower(trim($keyHash));
        if ($licenseKey === '' || preg_match('/^[a-f0-9]{64}$/D', $keyHash) !== 1
            || !hash_equals($keyHash, hash('sha256', $licenseKey))) return false;
        $encrypted = xchetosEncryptAuditKey($licenseKey, $keyHash);
        if ($encrypted === '' || !isset($conn) || !($conn instanceof mysqli)) return false;
        $stmt = $conn->prepare(
            "UPDATE xchetos_hwid_reset_logs SET key_ciphertext = ? WHERE key_hash = ?"
        );
        if (!$stmt) return false;
        $stmt->bind_param('ss', $encrypted, $keyHash);
        $ok = $stmt->execute();
        $stmt->close();
        if ($ok && !xchetosStoreKeySearchIndex($licenseKey, $keyHash)) {
            error_log('xChetos key search index update failed for key_hash=' . $keyHash);
        }
        return $ok;
    }
}

if (!function_exists('xchetosBackfillKeySearchIndex')) {
    /** Upgrade historic encrypted keys using bounded reads and batched inserts. */
    function xchetosBackfillKeySearchIndex(int $limit = 500): array
    {
        global $conn;
        static $running = false;
        if ($running || !isset($conn) || !($conn instanceof mysqli)) {
            return ['processed' => 0, 'updated' => 0, 'pending' => false, 'error' => 'database_unavailable'];
        }
        if (!xchetosDbTableExists('xchetos_hwid_reset_logs') || !xchetosDbTableExists('xchetos_hwid_reset_key_search')) {
            return ['processed' => 0, 'updated' => 0, 'pending' => false, 'error' => 'search_storage_missing'];
        }
        $limit = max(1, min(5000, $limit));
        $running = true;
        $processed = 0;
        $updated = 0;
        $pairs = [];
        try {
            $sql = "SELECT l.key_hash, l.key_ciphertext
                    FROM xchetos_hwid_reset_logs l
                    INNER JOIN (
                        SELECT base.key_hash, MAX(base.id) AS max_id
                        FROM xchetos_hwid_reset_logs base
                        WHERE base.key_hash <> '" . str_repeat('0', 64) . "'
                          AND base.key_ciphertext IS NOT NULL AND base.key_ciphertext <> ''
                          AND NOT EXISTS (
                              SELECT 1 FROM xchetos_hwid_reset_key_search ksi
                              WHERE CAST(ksi.key_hash AS BINARY) = CAST(base.key_hash AS BINARY)
                                AND LEFT(CAST(ksi.token AS BINARY), 1) = CAST('e' AS BINARY)
                          )
                        GROUP BY base.key_hash
                        ORDER BY max_id DESC
                        LIMIT {$limit}
                    ) pending_keys ON pending_keys.max_id = l.id";
            $result = $conn->query($sql);
            if (!$result) return ['processed' => 0, 'updated' => 0, 'pending' => false];
            while ($row = $result->fetch_assoc()) {
                $keyHash = strtolower((string) ($row['key_hash'] ?? ''));
                $licenseKey = xchetosDecryptAuditKey((string) ($row['key_ciphertext'] ?? ''), $keyHash);
                $processed++;
                if ($licenseKey === '') continue;
                foreach (xchetosBuildKeySearchTokens($licenseKey) as $token) $pairs[] = [$token, $keyHash];
                $updated++;
                // Flush periodically to keep memory stable even when thousands
                // of historic keys are indexed in one request.
                if (count($pairs) >= 4000) {
                    if (!xchetosInsertKeySearchPairs($pairs)) {
                        $updated = 0;
                        $pairs = [];
                        break;
                    }
                    $pairs = [];
                }
            }
            $result->free();
            if ($pairs !== [] && !xchetosInsertKeySearchPairs($pairs)) $updated = 0;

            $pending = false;
            $pendingResult = $conn->query(
                "SELECT 1
                 FROM xchetos_hwid_reset_logs base
                 WHERE base.key_hash <> '" . str_repeat('0', 64) . "'
                   AND base.key_ciphertext IS NOT NULL AND base.key_ciphertext <> ''
                   AND NOT EXISTS (
                       SELECT 1 FROM xchetos_hwid_reset_key_search ksi
                       WHERE CAST(ksi.key_hash AS BINARY) = CAST(base.key_hash AS BINARY)
                                AND LEFT(CAST(ksi.token AS BINARY), 1) = CAST('e' AS BINARY)
                   ) LIMIT 1"
            );
            if ($pendingResult) {
                $pending = $pendingResult->num_rows > 0;
                $pendingResult->free();
            }
            return ['processed' => $processed, 'updated' => $updated, 'pending' => $pending];
        } finally {
            $running = false;
        }
    }
}

if (!function_exists('xchetosDbTableExists')) {
    function xchetosDbTableExists(string $table): bool
    {
        global $conn;
        if (!isset($conn) || !($conn instanceof mysqli)
            || preg_match('/^[a-z0-9_]{1,64}$/iD', $table) !== 1) return false;
        $escaped = $conn->real_escape_string($table);
        $result = $conn->query("SHOW TABLES LIKE '{$escaped}'");
        if (!$result) return false;
        $exists = $result->num_rows > 0;
        $result->free();
        return $exists;
    }
}

if (!function_exists('xchetosEnsureSystemDebugTable')) {
    /**
     * Minimal SQL debug sink. It is intentionally independent from the main
     * audit table so schema/migration failures can still be diagnosed.
     */
    function xchetosEnsureSystemDebugTable(bool $force = false): bool
    {
        global $conn;
        if (!$force && array_key_exists('__xchetos_debug_table_ready', $GLOBALS)) {
            return $GLOBALS['__xchetos_debug_table_ready'] === true;
        }
        if (!isset($conn) || !($conn instanceof mysqli)) {
            $GLOBALS['__xchetos_debug_table_ready'] = false;
            return false;
        }
        $sql = "CREATE TABLE IF NOT EXISTS xchetos_hwid_reset_system_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            request_id CHAR(32) NULL,
            level VARCHAR(16) NOT NULL DEFAULT 'info',
            event_code VARCHAR(64) NOT NULL,
            stage VARCHAR(64) NULL,
            actor_user_id BIGINT UNSIGNED NULL,
            actor_role VARCHAR(16) NULL,
            message VARCHAR(500) NULL,
            context_json LONGTEXT NULL,
            request_ip VARCHAR(45) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_xchetos_system_created (created_at),
            KEY idx_xchetos_system_request (request_id),
            KEY idx_xchetos_system_event (event_code, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $ok = (bool) $conn->query($sql);
        $GLOBALS['__xchetos_debug_table_ready'] = $ok;
        if (!$ok) {
            $GLOBALS['__xchetos_last_storage_error'] = [
                'code' => 'debug_table_create_failed',
                'errno' => (int) $conn->errno,
                'message' => substr((string) $conn->error, 0, 500),
            ];
            error_log('xChetos debug table creation failed errno=' . (int) $conn->errno . ' message=' . substr((string) $conn->error, 0, 500));
        }
        return $ok;
    }
}

if (!function_exists('xchetosWriteSystemLog')) {
    /** Write a sanitized diagnostic event to SQL, then fall back to history/PHP log. */
    function xchetosWriteSystemLog(
        string $eventCode,
        array $context = [],
        string $level = 'info',
        string $stage = '',
        string $requestId = '',
        int $actorUserId = 0,
        string $actorRole = 'system'
    ): bool {
        global $conn;
        $eventCode = substr(preg_replace('/[^a-z0-9_.:-]+/i', '_', trim($eventCode)) ?: 'unknown', 0, 64);
        $level = in_array($level, ['debug', 'info', 'warning', 'error'], true) ? $level : 'info';
        $stage = substr(trim($stage), 0, 64);
        $requestId = preg_match('/^[a-f0-9]{32}$/iD', $requestId) === 1 ? strtolower($requestId) : '';
        $actorRole = substr(trim($actorRole), 0, 16);
        $message = '';
        if (isset($context['message']) && is_scalar($context['message'])) {
            $message = function_exists('xchetosSanitizeText')
                ? xchetosSanitizeText((string) $context['message'], '', 500)
                : substr((string) $context['message'], 0, 500);
            unset($context['message']);
        }
        $safeContext = function_exists('xchetosSafeDebugJson')
            ? xchetosSafeDebugJson($context)
            : (json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}');
        $ip = function_exists('getClientIp') ? getClientIp() : (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $ip = substr($ip, 0, 45);

        $stored = false;
        if (xchetosEnsureSystemDebugTable() && isset($conn) && $conn instanceof mysqli) {
            $requestValue = $requestId !== '' ? $requestId : null;
            $stageValue = $stage !== '' ? $stage : null;
            $actorValue = $actorUserId > 0 ? $actorUserId : null;
            $roleValue = $actorRole !== '' ? $actorRole : null;
            $messageValue = $message !== '' ? $message : null;
            $stmt = $conn->prepare(
                'INSERT INTO xchetos_hwid_reset_system_logs
                 (request_id, level, event_code, stage, actor_user_id, actor_role, message, context_json, request_ip)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if ($stmt) {
                $stmt->bind_param(
                    'ssssissss',
                    $requestValue, $level, $eventCode, $stageValue, $actorValue,
                    $roleValue, $messageValue, $safeContext, $ip
                );
                $stored = $stmt->execute();
                $stmt->close();
            }
        }

        $line = 'key_reset_debug event=' . $eventCode . ' level=' . $level
            . ($stage !== '' ? ' stage=' . $stage : '')
            . ($requestId !== '' ? ' ref=' . $requestId : '')
            . ' context_sha256=' . hash('sha256', $safeContext);
        if (!$stored && function_exists('logHistory') && $actorUserId > 0) {
            @logHistory($actorUserId, 'key_reset_debug', substr($line, 0, 500));
        }
        if (!$stored || $level === 'error') error_log($line);
        return $stored;
    }
}

if (!function_exists('xchetosEnsureResetTable')) {
    function xchetosEnsureResetTable(bool $force = false): bool
    {
        global $conn;
        if (!$force && array_key_exists('__xchetos_reset_table_ready', $GLOBALS)) {
            return $GLOBALS['__xchetos_reset_table_ready'] === true;
        }
        if (!isset($conn) || !($conn instanceof mysqli)) {
            $GLOBALS['__xchetos_reset_table_ready'] = false;
            $GLOBALS['__xchetos_last_storage_error'] = ['code' => 'database_unavailable', 'errno' => 0, 'message' => 'mysqli connection unavailable'];
            return false;
        }

        // Create the independent debug sink first so any following migration
        // failure has somewhere visible to land.
        xchetosEnsureSystemDebugTable($force);

        $sql = "CREATE TABLE IF NOT EXISTS xchetos_hwid_reset_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            request_id CHAR(32) NULL,
            actor_role VARCHAR(16) NOT NULL DEFAULT 'reseller',
            operation_type VARCHAR(32) NOT NULL DEFAULT 'reset_owned',
            provider_code VARCHAR(32) NOT NULL DEFAULT 'xchetos',
            target_user_id BIGINT UNSIGNED NULL,
            owner_kind VARCHAR(24) NULL,
            owner_user_id BIGINT UNSIGNED NULL,
            owner_username VARCHAR(190) NULL,
            owner_email VARCHAR(190) NULL,
            owner_role VARCHAR(32) NULL,
            owner_source VARCHAR(32) NULL,
            related_transaction_id BIGINT UNSIGNED NULL,
            related_order_id BIGINT UNSIGNED NULL,
            purchase_created_at DATETIME NULL,
            product_duration VARCHAR(120) NULL,
            external_ref VARCHAR(190) NULL,
            key_source VARCHAR(16) NOT NULL,
            key_record_id VARCHAR(64) NOT NULL,
            key_hash CHAR(64) NOT NULL,
            key_masked VARCHAR(128) NOT NULL,
            key_ciphertext TEXT NULL,
            product_name VARCHAR(255) NULL,
            key_duration_days SMALLINT UNSIGNED NULL,
            key_reset_limit SMALLINT UNSIGNED NULL,
            status ENUM('processing','success','failed','unknown') NOT NULL DEFAULT 'processing',
            result_code VARCHAR(64) NOT NULL DEFAULT 'processing',
            request_stage VARCHAR(64) NOT NULL DEFAULT 'created',
            provider_attempted TINYINT(1) NOT NULL DEFAULT 0,
            endpoint VARCHAR(190) NULL,
            http_method VARCHAR(10) NULL,
            provider_http_status SMALLINT UNSIGNED NULL,
            provider_message VARCHAR(500) NULL,
            transport_code VARCHAR(64) NULL,
            curl_errno INT UNSIGNED NULL,
            curl_error VARCHAR(255) NULL,
            duration_ms INT UNSIGNED NULL,
            connect_ms INT UNSIGNED NULL,
            primary_ip VARCHAR(45) NULL,
            token_retry TINYINT(1) NOT NULL DEFAULT 0,
            provider_license_id BIGINT UNSIGNED NULL,
            provider_product_id BIGINT UNSIGNED NULL,
            provider_license_status VARCHAR(32) NULL,
            debug_json LONGTEXT NULL,
            request_ip VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY idx_xchetos_user_created (user_id, created_at),
            KEY idx_xchetos_key_created (key_hash, created_at),
            KEY idx_xchetos_status_created (status, created_at),
            KEY idx_xchetos_request_id (request_id),
            KEY idx_xchetos_operation_created (operation_type, created_at),
            KEY idx_xchetos_provider_created (provider_code, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        if (!$conn->query($sql)) {
            $GLOBALS['__xchetos_reset_table_ready'] = false;
            $GLOBALS['__xchetos_last_storage_error'] = [
                'code' => 'audit_table_create_failed',
                'errno' => (int) $conn->errno,
                'message' => substr((string) $conn->error, 0, 500),
            ];
            xchetosWriteSystemLog('audit_table_create_failed', [
                'db_errno' => (int) $conn->errno,
                'message' => substr((string) $conn->error, 0, 500),
            ], 'error', 'schema');
            return false;
        }

        $searchIndexSql = "CREATE TABLE IF NOT EXISTS xchetos_hwid_reset_key_search (
            token CHAR(25) NOT NULL,
            key_hash CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (token, key_hash),
            KEY idx_xchetos_search_key_hash (key_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=ascii";
        $searchIndexReady = (bool) $conn->query($searchIndexSql);
        $optionalStorageError = [];
        if (!$searchIndexReady) {
            // The audit log is safety-critical; the partial-key index is not.
            // Do not disable resets merely because the database user cannot create
            // the optional search table. Exact-key/hash and metadata search remain
            // available, and the admin diagnostics page exposes the SQL error.
            $optionalStorageError = [
                'code' => 'search_table_create_failed',
                'errno' => (int) $conn->errno,
                'message' => substr((string) $conn->error, 0, 500),
            ];
            $GLOBALS['__xchetos_last_storage_error'] = $optionalStorageError;
            xchetosWriteSystemLog('search_table_create_failed', [
                'db_errno' => (int) $conn->errno,
                'message' => substr((string) $conn->error, 0, 500),
                'audit_logging_continues' => true,
            ], 'warning', 'schema');
        }

        $requiredColumns = [
            'request_id' => "CHAR(32) NULL AFTER user_id",
            'actor_role' => "VARCHAR(16) NOT NULL DEFAULT 'reseller' AFTER request_id",
            'operation_type' => "VARCHAR(32) NOT NULL DEFAULT 'reset_owned' AFTER actor_role",
            'provider_code' => "VARCHAR(32) NOT NULL DEFAULT 'xchetos' AFTER operation_type",
            'target_user_id' => "BIGINT UNSIGNED NULL AFTER provider_code",
            'owner_kind' => "VARCHAR(24) NULL AFTER target_user_id",
            'owner_user_id' => "BIGINT UNSIGNED NULL AFTER owner_kind",
            'owner_username' => "VARCHAR(190) NULL AFTER owner_user_id",
            'owner_email' => "VARCHAR(190) NULL AFTER owner_username",
            'owner_role' => "VARCHAR(32) NULL AFTER owner_email",
            'owner_source' => "VARCHAR(32) NULL AFTER owner_role",
            'related_transaction_id' => "BIGINT UNSIGNED NULL AFTER owner_source",
            'related_order_id' => "BIGINT UNSIGNED NULL AFTER related_transaction_id",
            'purchase_created_at' => "DATETIME NULL AFTER related_order_id",
            'product_duration' => "VARCHAR(120) NULL AFTER purchase_created_at",
            'external_ref' => "VARCHAR(190) NULL AFTER product_duration",
            'key_ciphertext' => "TEXT NULL AFTER key_masked",
            'key_duration_days' => "SMALLINT UNSIGNED NULL AFTER product_name",
            'key_reset_limit' => "SMALLINT UNSIGNED NULL AFTER key_duration_days",
            'request_stage' => "VARCHAR(64) NOT NULL DEFAULT 'created' AFTER result_code",
            'provider_attempted' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER request_stage",
            'endpoint' => "VARCHAR(190) NULL AFTER provider_attempted",
            'http_method' => "VARCHAR(10) NULL AFTER endpoint",
            'transport_code' => "VARCHAR(64) NULL AFTER provider_message",
            'curl_errno' => "INT UNSIGNED NULL AFTER transport_code",
            'curl_error' => "VARCHAR(255) NULL AFTER curl_errno",
            'duration_ms' => "INT UNSIGNED NULL AFTER curl_error",
            'connect_ms' => "INT UNSIGNED NULL AFTER duration_ms",
            'primary_ip' => "VARCHAR(45) NULL AFTER connect_ms",
            'token_retry' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER primary_ip",
            'provider_license_id' => "BIGINT UNSIGNED NULL AFTER token_retry",
            'provider_product_id' => "BIGINT UNSIGNED NULL AFTER provider_license_id",
            'provider_license_status' => "VARCHAR(32) NULL AFTER provider_product_id",
            'debug_json' => "LONGTEXT NULL AFTER provider_license_status",
            'user_agent' => "VARCHAR(255) NULL AFTER request_ip",
        ];

        $existing = [];
        $columnResult = $conn->query('SHOW COLUMNS FROM xchetos_hwid_reset_logs');
        if (!$columnResult) {
            $GLOBALS['__xchetos_reset_table_ready'] = false;
            $GLOBALS['__xchetos_last_storage_error'] = [
                'code' => 'audit_schema_inspection_failed',
                'errno' => (int) $conn->errno,
                'message' => substr((string) $conn->error, 0, 500),
            ];
            xchetosWriteSystemLog('audit_schema_inspection_failed', [
                'db_errno' => (int) $conn->errno,
                'message' => substr((string) $conn->error, 0, 500),
            ], 'error', 'schema');
            return false;
        }
        while ($row = $columnResult->fetch_assoc()) $existing[(string) $row['Field']] = true;
        $columnResult->free();

        foreach ($requiredColumns as $column => $definition) {
            if (isset($existing[$column])) continue;
            if (!$conn->query("ALTER TABLE xchetos_hwid_reset_logs ADD COLUMN `{$column}` {$definition}")) {
                $GLOBALS['__xchetos_reset_table_ready'] = false;
                $GLOBALS['__xchetos_last_storage_error'] = [
                    'code' => 'audit_schema_migration_failed',
                    'column' => $column,
                    'errno' => (int) $conn->errno,
                    'message' => substr((string) $conn->error, 0, 500),
                ];
                xchetosWriteSystemLog('audit_schema_migration_failed', [
                    'column' => $column,
                    'db_errno' => (int) $conn->errno,
                    'message' => substr((string) $conn->error, 0, 500),
                ], 'error', 'schema');
                return false;
            }
        }

        $indexes = [];
        $indexResult = $conn->query('SHOW INDEX FROM xchetos_hwid_reset_logs');
        if ($indexResult) {
            while ($row = $indexResult->fetch_assoc()) $indexes[(string) $row['Key_name']] = true;
            $indexResult->free();
        }
        $indexSql = [
            'idx_xchetos_request_id' => 'ALTER TABLE xchetos_hwid_reset_logs ADD KEY idx_xchetos_request_id (request_id)',
            'idx_xchetos_operation_created' => 'ALTER TABLE xchetos_hwid_reset_logs ADD KEY idx_xchetos_operation_created (operation_type, created_at)',
            'idx_xchetos_provider_created' => 'ALTER TABLE xchetos_hwid_reset_logs ADD KEY idx_xchetos_provider_created (provider_code, created_at)',
            'idx_xchetos_owner_created' => 'ALTER TABLE xchetos_hwid_reset_logs ADD KEY idx_xchetos_owner_created (owner_user_id, created_at)',
            'idx_xchetos_related_tx' => 'ALTER TABLE xchetos_hwid_reset_logs ADD KEY idx_xchetos_related_tx (related_transaction_id)',
            'idx_xchetos_related_order' => 'ALTER TABLE xchetos_hwid_reset_logs ADD KEY idx_xchetos_related_order (related_order_id)',
        ];
        foreach ($indexSql as $name => $statement) {
            if (isset($indexes[$name])) continue;
            if (!$conn->query($statement)) {
                xchetosWriteSystemLog('audit_index_migration_failed', [
                    'index' => $name,
                    'db_errno' => (int) $conn->errno,
                    'message' => substr((string) $conn->error, 0, 500),
                ], 'warning', 'schema');
            }
        }

        $config = xchetosConfig();
        $staleSeconds = max(60, (int) ($config['request_timeout'] ?? 20) + 60);
        $cleanupSql = "UPDATE xchetos_hwid_reset_logs
            SET status = 'unknown', result_code = 'stale_processing', request_stage = 'stale_processing',
                completed_at = COALESCE(completed_at, NOW())
            WHERE status = 'processing' AND created_at < DATE_SUB(NOW(), INTERVAL {$staleSeconds} SECOND)";
        if (!$conn->query($cleanupSql)) {
            xchetosWriteSystemLog('stale_cleanup_failed', [
                'db_errno' => (int) $conn->errno,
                'message' => substr((string) $conn->error, 0, 500),
            ], 'warning', 'maintenance');
        }

        $GLOBALS['__xchetos_reset_table_ready'] = true;
        if ($optionalStorageError === []) $GLOBALS['__xchetos_last_storage_error'] = [];
        return true;
    }
}

if (!function_exists('xchetosGetStorageDiagnostics')) {
    function xchetosGetStorageDiagnostics(bool $repair = false): array
    {
        global $conn;
        $report = [
            'checked_at' => date('Y-m-d H:i:s'),
            'database_connected' => isset($conn) && $conn instanceof mysqli,
            'database_name' => '',
            'server_version' => '',
            'repair_requested' => $repair,
            'audit_table' => ['exists' => false, 'rows' => 0, 'columns' => 0],
            'search_table' => ['exists' => false, 'rows' => 0],
            'debug_table' => ['exists' => false, 'rows' => 0],
            'vault_ready' => false,
            'core_ready' => false,
            'ready' => false,
            'last_error' => (array) ($GLOBALS['__xchetos_last_storage_error'] ?? []),
        ];
        if (!isset($conn) || !($conn instanceof mysqli)) return $report;

        $dbResult = $conn->query('SELECT DATABASE() AS db_name, VERSION() AS version_name');
        if ($dbResult) {
            $row = $dbResult->fetch_assoc() ?: [];
            $report['database_name'] = substr((string) ($row['db_name'] ?? ''), 0, 190);
            $report['server_version'] = substr((string) ($row['version_name'] ?? ''), 0, 190);
            $dbResult->free();
        }

        if ($repair) {
            xchetosEnsureSystemDebugTable(true);
            xchetosEnsureResetTable(true);
        }
        foreach ([
            'audit_table' => 'xchetos_hwid_reset_logs',
            'search_table' => 'xchetos_hwid_reset_key_search',
            'debug_table' => 'xchetos_hwid_reset_system_logs',
        ] as $reportKey => $tableName) {
            $exists = xchetosDbTableExists($tableName);
            $report[$reportKey]['exists'] = $exists;
            if ($exists) {
                $countResult = $conn->query("SELECT COUNT(*) AS c FROM `{$tableName}`");
                if ($countResult) {
                    $countRow = $countResult->fetch_assoc() ?: [];
                    $report[$reportKey]['rows'] = max(0, (int) ($countRow['c'] ?? 0));
                    $countResult->free();
                }
            }
        }
        if ($report['audit_table']['exists']) {
            $columns = $conn->query('SHOW COLUMNS FROM xchetos_hwid_reset_logs');
            if ($columns) {
                $report['audit_table']['columns'] = $columns->num_rows;
                $columns->free();
            }
        }
        $secret = function_exists('xchetosGetKeyVaultSecret') ? xchetosGetKeyVaultSecret(false) : '';
        $report['vault_ready'] = is_string($secret) && strlen($secret) >= 32;
        $report['core_ready'] = $report['audit_table']['exists']
            && $report['audit_table']['columns'] >= 30
            && $report['vault_ready'];
        $report['ready'] = $report['core_ready']
            && $report['search_table']['exists']
            && $report['debug_table']['exists'];
        $report['last_error'] = (array) ($GLOBALS['__xchetos_last_storage_error'] ?? []);
        return $report;
    }
}

if (!function_exists('xchetosGetSystemDebugLogs')) {
    function xchetosGetSystemDebugLogs(int $limit = 50): array
    {
        global $conn;
        if (!function_exists('isAdmin') || !isAdmin() || !xchetosDbTableExists('xchetos_hwid_reset_system_logs')) return [];
        $limit = max(1, min(200, $limit));
        $result = $conn->query(
            "SELECT id, request_id, level, event_code, stage, actor_user_id, actor_role,
                    message, context_json, request_ip, created_at
             FROM xchetos_hwid_reset_system_logs ORDER BY id DESC LIMIT {$limit}"
        );
        if (!$result) return [];
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
        return $rows;
    }
}

if (!function_exists('xchetosRebuildSearchIndex')) {
    /** Explicit admin-triggered indexing. Never run this automatically while typing. */
    function xchetosRebuildSearchIndex(int $limit = 1000): array
    {
        $limit = max(50, min(5000, $limit));
        if (!xchetosEnsureResetTable(true)) {
            return [
                'success' => false,
                'code' => 'storage_repair_failed',
                'processed' => 0,
                'updated' => 0,
                'pending' => false,
                'diagnostics' => xchetosGetStorageDiagnostics(false),
            ];
        }
        $result = xchetosBackfillKeySearchIndex($limit);
        $success = empty($result['error']);
        xchetosWriteSystemLog('search_index_rebuild', [
            'limit' => $limit,
            'processed' => (int) ($result['processed'] ?? 0),
            'updated' => (int) ($result['updated'] ?? 0),
            'pending' => !empty($result['pending']),
            'error' => (string) ($result['error'] ?? ''),
        ], $success ? 'info' : 'error', 'search_index');
        return [
            'success' => $success,
            'code' => $success ? 'search_index_rebuilt' : 'search_index_rebuild_failed',
            'processed' => max(0, (int) ($result['processed'] ?? 0)),
            'updated' => max(0, (int) ($result['updated'] ?? 0)),
            'pending' => !empty($result['pending']),
            'error' => (string) ($result['error'] ?? ''),
            'diagnostics' => xchetosGetStorageDiagnostics(false),
        ];
    }
}

if (!function_exists('xchetosNormalizeRecordId')) {
    function xchetosNormalizeRecordId(string $source, $recordId): int
    {
        $value = trim((string) $recordId);
        $canonical = function_exists('commerceNormalizeSource')
            ? commerceNormalizeSource($source, $value)
            : strtolower(trim($source));
        $prefixes = [
            'cgo' => 'cgo-',
            'supplier' => 'supplier-',
            'store_api_client' => 'store-api-',
        ];
        if (isset($prefixes[$canonical]) && stripos($value, $prefixes[$canonical]) === 0) {
            $value = substr($value, strlen($prefixes[$canonical]));
        }
        if ($value === '' || !ctype_digit($value)) return 0;
        $id = (int) $value;
        return $id > 0 ? $id : 0;
    }
}

if (!function_exists('xchetosResolveOwnedKey')) {
    function xchetosResolveOwnedKey(int $userId, string $source, $recordId): ?array
    {
        global $conn;
        if ($userId < 1 || !isset($conn) || !($conn instanceof mysqli)) return null;
        $source = function_exists('commerceNormalizeSource')
            ? commerceNormalizeSource($source, (string) $recordId)
            : strtolower(trim($source));
        if (!in_array($source, ['local', 'cgo', 'supplier'], true)) return null;
        $id = xchetosNormalizeRecordId($source, $recordId);
        if ($id < 1) return null;

        if ($source === 'local') {
            $stmt = $conn->prepare(
                "SELECT k.id,k.key_code,p.name AS product_name,
                        COALESCE(NULLIF(pv.duration,''),NULLIF(k.duration,''),'') AS duration
                 FROM `keys` k
                 JOIN products p ON p.id=k.product_id
                 LEFT JOIN product_variants pv ON pv.id=k.variant_id
                 WHERE k.id=? AND (k.assigned_to=? OR k.purchased_by=?)
                 LIMIT 1"
            );
            if (!$stmt) return null;
            $stmt->bind_param('iii', $id, $userId, $userId);
        } elseif ($source === 'cgo') {
            if (function_exists('cgoEnsureTables') && !cgoEnsureTables()) return null;
            $statusSql = function_exists('commerceSuccessfulStatusSql')
                ? commerceSuccessfulStatusSql('o')
                : "LOWER(TRIM(COALESCE(o.status,''))) IN ('success','completed')";
            $stmt = $conn->prepare(
                "SELECT ok.id,ok.key_code,
                        COALESCE(NULLIF(lp.name,''),
                            CASE WHEN COALESCE(NULLIF(cp.brand,''),'')<>'' THEN CONCAT(cp.brand,' - ',cp.name) ELSE cp.name END,
                            '') AS product_name,
                        COALESCE(NULLIF(pv.duration,''),NULLIF(cp.duration,''),'') AS duration
                 FROM cgo_order_keys ok
                 JOIN cgo_orders o ON o.id=ok.order_id
                 JOIN cgo_products cp ON cp.id=o.cgo_product_id
                 LEFT JOIN products lp ON lp.id=o.local_product_id
                 LEFT JOIN product_variants pv ON pv.id=o.local_variant_id
                 WHERE ok.id=? AND o.user_id=? AND {$statusSql}
                 LIMIT 1"
            );
            if (!$stmt) return null;
            $stmt->bind_param('ii', $id, $userId);
        } else {
            if (function_exists('storeBridgeEnsureSchema') && !storeBridgeEnsureSchema()) return null;
            $statusSql = function_exists('commerceSuccessfulStatusSql')
                ? commerceSuccessfulStatusSql('o')
                : "LOWER(TRIM(COALESCE(o.status,''))) IN ('success','completed')";
            $stmt = $conn->prepare(
                "SELECT ok.id,ok.key_code,
                        COALESCE(NULLIF(p.name,''),NULLIF(sp.name,''),'') AS product_name,
                        COALESCE(NULLIF(pv.duration,''),NULLIF(sp.duration,''),'') AS duration
                 FROM supplier_order_keys ok
                 JOIN supplier_orders o ON o.id=ok.order_id
                 LEFT JOIN products p ON p.id=o.local_product_id
                 LEFT JOIN product_variants pv ON pv.id=o.local_variant_id
                 LEFT JOIN supplier_products sp ON sp.id=o.supplier_product_id
                 WHERE ok.id=? AND o.user_id=? AND {$statusSql}
                 LIMIT 1"
            );
            if (!$stmt) return null;
            $stmt->bind_param('ii', $id, $userId);
        }

        if (!$stmt->execute()) { $stmt->close(); return null; }
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if (!$row) return null;
        $key = trim((string) ($row['key_code'] ?? ''));
        if ($key === '') return null;
        $name = trim((string) ($row['product_name'] ?? ''));
        $duration = trim((string) ($row['duration'] ?? ''));
        return [
            'source' => $source,
            'record_id' => $id,
            'key_code' => $key,
            'product_name' => function_exists('commerceComposeProductLabel')
                ? commerceComposeProductLabel($name, $duration)
                : trim($name . ($duration !== '' ? ' - ' . $duration : '')),
        ];
    }
}

if (!function_exists('xchetosTokenIsSafe')) {
    function xchetosTokenIsSafe(string $token): bool
    {
        return strlen($token) >= 20
            && strlen($token) <= 8192
            && preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $token) === 1;
    }
}

if (!function_exists('xchetosJwtExpiry')) {
    function xchetosJwtExpiry(string $token): int
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return 0;
        $payload = strtr($parts[1], '-_', '+/');
        $padding = strlen($payload) % 4;
        if ($padding !== 0) $payload .= str_repeat('=', 4 - $padding);
        $decoded = base64_decode($payload, true);
        if ($decoded === false) return 0;
        $json = json_decode($decoded, true);
        return is_array($json) && is_numeric($json['exp'] ?? null) ? (int) $json['exp'] : 0;
    }
}

if (!function_exists('xchetosClearCachedToken')) {
    function xchetosClearCachedToken(): void
    {
        unset($_SESSION['xchetos_access_token'], $_SESSION['xchetos_access_token_exp']);
    }
}

if (!function_exists('xchetosCachedToken')) {
    function xchetosCachedToken(): string
    {
        $token = isset($_SESSION['xchetos_access_token']) && is_string($_SESSION['xchetos_access_token'])
            ? $_SESSION['xchetos_access_token'] : '';
        $expires = (int) ($_SESSION['xchetos_access_token_exp'] ?? 0);
        if (!xchetosTokenIsSafe($token) || $expires <= time() + 60) {
            xchetosClearCachedToken();
            return '';
        }
        return $token;
    }
}

if (!function_exists('xchetosStoreCachedToken')) {
    function xchetosStoreCachedToken(string $token): void
    {
        if (!xchetosTokenIsSafe($token)) {
            xchetosClearCachedToken();
            return;
        }
        $expiry = xchetosJwtExpiry($token);
        if ($expiry <= time() + 60) $expiry = time() + 600;
        $_SESSION['xchetos_access_token'] = $token;
        $_SESSION['xchetos_access_token_exp'] = $expiry;
    }
}

if (!function_exists('xchetosHttpJson')) {
    function xchetosHttpJson(string $path, string $method, ?array $payload = null, string $bearerToken = ''): array
    {
        $started = microtime(true);
        if (!function_exists('curl_init')) {
            return [
                'ok' => false, 'code' => 'curl_missing', 'http_status' => 0, 'json' => null,
                'transport_error' => true, 'curl_errno' => 0, 'curl_error' => 'PHP cURL extension is unavailable',
                'duration_ms' => 0, 'connect_ms' => 0, 'primary_ip' => '', 'content_type' => '',
                'response_bytes' => 0, 'request_sent_likely' => false,
            ];
        }

        $config = xchetosConfig();
        $baseUrl = (string) ($config['base_url'] ?? '');
        $method = strtoupper(trim($method));
        if ($baseUrl === '' || $path === '' || $path[0] !== '/' || strpos($path, '//') !== false || !in_array($method, ['GET', 'POST'], true)) {
            return [
                'ok' => false, 'code' => 'invalid_endpoint', 'http_status' => 0, 'json' => null,
                'transport_error' => false, 'curl_errno' => 0, 'curl_error' => '', 'duration_ms' => 0,
                'connect_ms' => 0, 'primary_ip' => '', 'content_type' => '', 'response_bytes' => 0,
                'request_sent_likely' => false,
            ];
        }
        $url = $baseUrl . $path;

        $headers = [
            'Accept: application/json, text/plain, */*',
            'Content-Type: application/json',
            'Cache-Control: no-cache',
            'Expect:',
            'Origin: ' . $baseUrl,
            'Referer: ' . ($path === '/api/auth/login' ? $baseUrl . '/' : $baseUrl . '/dashboard'),
        ];
        if ($bearerToken !== '') {
            $headers[] = 'Authorization: Bearer ' . $bearerToken;
            $headers[] = 'Cookie: jwt_token=' . $bearerToken;
        }

        $body = null;
        if ($payload !== null) {
            $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if (!is_string($body)) {
                return [
                    'ok' => false, 'code' => 'json_encode_failed', 'http_status' => 0, 'json' => null,
                    'transport_error' => false, 'curl_errno' => 0, 'curl_error' => '', 'duration_ms' => 0,
                    'connect_ms' => 0, 'primary_ip' => '', 'content_type' => '', 'response_bytes' => 0,
                    'request_sent_likely' => false,
                ];
            }
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return [
                'ok' => false, 'code' => 'curl_init_failed', 'http_status' => 0, 'json' => null,
                'transport_error' => true, 'curl_errno' => 0, 'curl_error' => 'curl_init failed',
                'duration_ms' => 0, 'connect_ms' => 0, 'primary_ip' => '', 'content_type' => '',
                'response_bytes' => 0, 'request_sent_likely' => false,
            ];
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => (int) $config['connect_timeout'],
            CURLOPT_TIMEOUT => (int) $config['request_timeout'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'Sakazuki-xChetos-Reset/2.0',
            CURLOPT_ENCODING => '',
            CURLOPT_NOSIGNAL => true,
        ];
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $body ?? '{}';
        } else {
            $options[CURLOPT_HTTPGET] = true;
        }
        if (defined('CURLOPT_HTTP_VERSION') && defined('CURL_HTTP_VERSION_1_1')) $options[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_1_1;
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTPS')) $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
        if (!empty($config['force_ipv4']) && defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) {
            $options[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
        }
        curl_setopt_array($ch, $options);

        $raw = curl_exec($ch);
        $curlErrorNumber = curl_errno($ch);
        $curlError = xchetosSanitizeText((string) curl_error($ch), '', 255);
        $httpStatus = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $contentType = xchetosSanitizeText((string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE), '', 120);
        $primaryIp = xchetosSanitizeText((string) curl_getinfo($ch, CURLINFO_PRIMARY_IP), '', 45);
        $connectTime = (float) curl_getinfo($ch, CURLINFO_CONNECT_TIME);
        $totalTime = (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        curl_close($ch);

        $durationMs = (int) round(max($totalTime, microtime(true) - $started) * 1000);
        $connectMs = (int) round(max(0, $connectTime) * 1000);
        $requestSentLikely = $connectMs > 0 || $primaryIp !== '' || $httpStatus > 0;

        if ($raw === false || $curlErrorNumber !== 0) {
            return [
                'ok' => false, 'code' => 'transport_error', 'http_status' => $httpStatus, 'json' => null,
                'transport_error' => true, 'curl_errno' => $curlErrorNumber, 'curl_error' => $curlError,
                'duration_ms' => max(0, $durationMs), 'connect_ms' => max(0, $connectMs),
                'primary_ip' => $primaryIp, 'content_type' => $contentType, 'response_bytes' => 0,
                'request_sent_likely' => $requestSentLikely,
            ];
        }
        if (!is_string($raw) || strlen($raw) > 4194304) {
            return [
                'ok' => false, 'code' => 'invalid_response_size', 'http_status' => $httpStatus, 'json' => null,
                'transport_error' => false, 'curl_errno' => 0, 'curl_error' => '',
                'duration_ms' => max(0, $durationMs), 'connect_ms' => max(0, $connectMs),
                'primary_ip' => $primaryIp, 'content_type' => $contentType,
                'response_bytes' => is_string($raw) ? strlen($raw) : 0, 'request_sent_likely' => true,
            ];
        }

        $decoded = json_decode($raw, true);
        return [
            'ok' => $httpStatus >= 200 && $httpStatus < 300,
            'code' => is_array($decoded) ? 'response_received' : 'invalid_json',
            'http_status' => $httpStatus,
            'json' => is_array($decoded) ? $decoded : null,
            'transport_error' => false,
            'curl_errno' => 0,
            'curl_error' => '',
            'duration_ms' => max(0, $durationMs),
            'connect_ms' => max(0, $connectMs),
            'primary_ip' => $primaryIp,
            'content_type' => $contentType,
            'response_bytes' => strlen($raw),
            'request_sent_likely' => true,
        ];
    }
}

if (!function_exists('xchetosSafeHttpDebug')) {
    function xchetosSafeHttpDebug(array $response): array
    {
        $json = is_array($response['json'] ?? null) ? $response['json'] : [];
        $keys = array_values(array_slice(array_map('strval', array_keys($json)), 0, 30));
        // Never expose login token values. Only the top-level field names are kept.
        return [
            'http_status' => (int) ($response['http_status'] ?? 0),
            'transport_error' => !empty($response['transport_error']),
            'transport_code' => (string) ($response['code'] ?? ''),
            'curl_errno' => (int) ($response['curl_errno'] ?? 0),
            'curl_error' => xchetosSanitizeText((string) ($response['curl_error'] ?? ''), '', 255),
            'duration_ms' => max(0, (int) ($response['duration_ms'] ?? 0)),
            'connect_ms' => max(0, (int) ($response['connect_ms'] ?? 0)),
            'primary_ip' => xchetosSanitizeText((string) ($response['primary_ip'] ?? ''), '', 45),
            'content_type' => xchetosSanitizeText((string) ($response['content_type'] ?? ''), '', 120),
            'response_bytes' => max(0, (int) ($response['response_bytes'] ?? 0)),
            'response_keys' => $keys,
            'request_sent_likely' => !empty($response['request_sent_likely']),
        ];
    }
}

if (!function_exists('xchetosLogin')) {
    function xchetosLogin(bool $force = false): array
    {
        if (!xchetosIsConfigured()) return ['success' => false, 'code' => 'not_configured', 'http' => []];
        if (!$force) {
            $cached = xchetosCachedToken();
            if ($cached !== '') return ['success' => true, 'token' => $cached, 'cached' => true, 'http' => []];
        } else {
            xchetosClearCachedToken();
        }

        $config = xchetosConfig();
        $response = xchetosHttpJson('/api/auth/login', 'POST', [
            'username' => (string) $config['username'],
            'password' => (string) $config['password'],
        ]);
        $httpDebug = xchetosSafeHttpDebug($response);

        if (!empty($response['transport_error'])) {
            $transportCode = (string) ($response['code'] ?? '');
            return [
                'success' => false,
                'code' => $transportCode === 'curl_missing' ? 'curl_missing' : 'provider_unavailable',
                'http' => $httpDebug,
            ];
        }
        $httpStatus = (int) ($response['http_status'] ?? 0);
        $json = is_array($response['json'] ?? null) ? $response['json'] : [];
        $token = isset($json['access_token']) && is_string($json['access_token']) ? trim($json['access_token']) : '';
        $role = strtolower(trim((string) ($json['user']['role'] ?? '')));

        if ($httpStatus === 200 && xchetosTokenIsSafe($token) && $role === 'reseller') {
            xchetosStoreCachedToken($token);
            return ['success' => true, 'token' => $token, 'cached' => false, 'http' => $httpDebug];
        }
        xchetosClearCachedToken();
        if ($httpStatus === 429) return ['success' => false, 'code' => 'provider_rate_limited', 'http' => $httpDebug];
        if ($httpStatus === 401 || $httpStatus === 403 || $httpStatus === 200) {
            return ['success' => false, 'code' => 'login_failed', 'http' => $httpDebug];
        }
        return ['success' => false, 'code' => 'provider_unavailable', 'http' => $httpDebug];
    }
}

if (!function_exists('xchetosAuthenticatedRequest')) {
    function xchetosAuthenticatedRequest(string $path, string $method, ?array $payload = null): array
    {
        $login = xchetosLogin(false);
        if (empty($login['success'])) {
            return [
                'auth_failed' => true,
                'auth_code' => (string) ($login['code'] ?? 'login_failed'),
                'token_retry' => false,
                'http' => is_array($login['http'] ?? null) ? $login['http'] : [],
                'response' => null,
            ];
        }

        $response = xchetosHttpJson($path, $method, $payload, (string) $login['token']);
        $retry = false;
        $httpStatus = (int) ($response['http_status'] ?? 0);
        if (($httpStatus === 401 || $httpStatus === 403) && empty($response['transport_error'])) {
            $retry = true;
            $login = xchetosLogin(true);
            if (empty($login['success'])) {
                return [
                    'auth_failed' => true,
                    'auth_code' => (string) ($login['code'] ?? 'login_failed'),
                    'token_retry' => true,
                    'http' => is_array($login['http'] ?? null) ? $login['http'] : xchetosSafeHttpDebug($response),
                    'response' => null,
                ];
            }
            $response = xchetosHttpJson($path, $method, $payload, (string) $login['token']);
        }
        return [
            'auth_failed' => false,
            'auth_code' => '',
            'token_retry' => $retry,
            'http' => xchetosSafeHttpDebug($response),
            'response' => $response,
        ];
    }
}

if (!function_exists('xchetosProviderMessage')) {
    function xchetosProviderMessage(array $json, string $licenseKey = ''): string
    {
        foreach (['message', 'error', 'detail'] as $field) {
            if (isset($json[$field]) && is_scalar($json[$field])) {
                $message = trim((string) $json[$field]);
                if ($message !== '') return xchetosSanitizeText($message, $licenseKey, 500);
            }
        }
        return '';
    }
}

if (!function_exists('xchetosTransportDefinitelyNotSent')) {
    function xchetosTransportDefinitelyNotSent(array $response): bool
    {
        $errno = (int) ($response['curl_errno'] ?? 0);
        $definite = [5, 6, 7, 35, 51, 58, 60, 77, 83, 90];
        if (in_array($errno, $definite, true)) return true;
        return empty($response['request_sent_likely']) && (int) ($response['http_status'] ?? 0) === 0;
    }
}

if (!function_exists('xchetosVerifyProviderLicense')) {
    function xchetosVerifyProviderLicense(string $licenseKey): array
    {
        $config = xchetosConfig();
        if (empty($config['verify_provider_ownership'])) {
            return [
                'success' => true,
                'code' => 'verification_disabled',
                'license' => ['product_name' => '', 'id' => 0, 'product_id' => 0, 'status' => ''],
                'provider_attempted' => false,
                'token_retry' => false,
                'debug' => ['verification_disabled' => true],
            ];
        }

        $pageSize = (int) ($config['provider_page_size'] ?? 100);
        $maxPages = (int) ($config['provider_max_pages'] ?? 250);
        $maxRecords = (int) ($config['provider_max_records'] ?? 25000);
        $recordsChecked = 0;
        $offset = 0;
        $reportedTotal = null;
        $tokenRetry = false;
        $lastHttp = [];
        $lastCount = null;
        $pagesChecked = 0;
        $seenPages = [];
        $stopReason = 'page_limit';

        for ($page = 0; $page < $maxPages && $recordsChecked < $maxRecords; $page++) {
            $path = '/api/licenses?limit=' . $pageSize . '&offset=' . $offset . '&only_inactive=false';
            $request = xchetosAuthenticatedRequest($path, 'GET');
            $tokenRetry = $tokenRetry || !empty($request['token_retry']);
            $lastHttp = is_array($request['http'] ?? null) ? $request['http'] : [];
            $pagesChecked++;

            if (!empty($request['auth_failed'])) {
                return [
                    'success' => false,
                    'status' => 'failed',
                    'code' => (string) ($request['auth_code'] ?? 'login_failed'),
                    'provider_attempted' => false,
                    'token_retry' => $tokenRetry,
                    'endpoint' => '/api/auth/login',
                    'http_method' => 'POST',
                    'http' => $lastHttp,
                    'debug' => ['phase' => 'provider_login'],
                ];
            }

            $response = is_array($request['response'] ?? null) ? $request['response'] : [];
            if (!empty($response['transport_error'])) {
                $unknown = !xchetosTransportDefinitelyNotSent($response);
                return [
                    'success' => false,
                    'status' => $unknown ? 'unknown' : 'failed',
                    'code' => $unknown ? 'provider_verification_unknown' : 'provider_unavailable',
                    'provider_attempted' => true,
                    'token_retry' => $tokenRetry,
                    'endpoint' => '/api/licenses',
                    'http_method' => 'GET',
                    'http' => xchetosSafeHttpDebug($response),
                    'debug' => [
                        'phase' => 'provider_inventory',
                        'page' => $page + 1,
                        'offset' => $offset,
                        'records_checked' => $recordsChecked,
                    ],
                ];
            }

            $httpStatus = (int) ($response['http_status'] ?? 0);
            $json = is_array($response['json'] ?? null) ? $response['json'] : [];
            if ($httpStatus !== 200 || !isset($json['data']) || !is_array($json['data'])) {
                $invalidCode = ($httpStatus === 401 || $httpStatus === 403)
                    ? 'login_failed'
                    : ($httpStatus === 429 ? 'provider_rate_limited' : 'provider_inventory_invalid');
                return [
                    'success' => false,
                    'status' => $httpStatus >= 500 || ($httpStatus >= 200 && $httpStatus < 300) ? 'unknown' : 'failed',
                    'code' => $invalidCode,
                    'provider_attempted' => true,
                    'token_retry' => $tokenRetry,
                    'endpoint' => '/api/licenses',
                    'http_method' => 'GET',
                    'http' => xchetosSafeHttpDebug($response),
                    'provider_message' => xchetosProviderMessage($json, $licenseKey),
                    'debug' => [
                        'phase' => 'provider_inventory',
                        'page' => $page + 1,
                        'offset' => $offset,
                        'records_checked' => $recordsChecked,
                    ],
                ];
            }

            if (is_numeric($json['total'] ?? null)) $reportedTotal = max(0, (int) $json['total']);
            $rows = $json['data'];
            $count = count($rows);
            $lastCount = $count;

            if ($count === 0) {
                $stopReason = 'empty_page';
                break;
            }

            // Some providers silently cap page size below the requested limit.
            // Advance by the rows actually returned, not by the requested limit,
            // otherwise valid licenses are skipped between pages.
            $fingerprintParts = [];
            foreach ($rows as $item) {
                if (!is_array($item)) continue;
                $candidate = isset($item['license_key']) && is_string($item['license_key']) ? trim($item['license_key']) : '';
                $fingerprintParts[] = (string) ($item['id'] ?? '') . ':' . ($candidate !== '' ? hash('sha256', $candidate) : '-');
                if ($candidate === '' || strlen($candidate) !== strlen($licenseKey) || !hash_equals($candidate, $licenseKey)) continue;
                return [
                    'success' => true,
                    'code' => 'provider_license_verified',
                    'license' => [
                        'id' => is_numeric($item['id'] ?? null) ? (int) $item['id'] : 0,
                        'product_id' => is_numeric($item['product_id'] ?? null) ? (int) $item['product_id'] : 0,
                        'product_name' => xchetosSanitizeText((string) ($item['product_name'] ?? ''), $licenseKey, 255),
                        'status' => xchetosSanitizeText((string) ($item['status'] ?? ''), '', 32),
                        'has_hwid' => isset($item['hwid']) && trim((string) $item['hwid']) !== '',
                        'is_banned' => !empty($item['is_banned']),
                    ],
                    'provider_attempted' => true,
                    'token_retry' => $tokenRetry,
                    'endpoint' => '/api/licenses',
                    'http_method' => 'GET',
                    'http' => xchetosSafeHttpDebug($response),
                    'debug' => [
                        'phase' => 'provider_inventory',
                        'pages_checked' => $pagesChecked,
                        'records_checked' => $recordsChecked + $count,
                        'reported_total' => $reportedTotal,
                        'last_offset' => $offset,
                        'returned_count' => $count,
                    ],
                ];
            }

            $pageFingerprint = hash('sha256', implode('|', $fingerprintParts));
            if (isset($seenPages[$pageFingerprint])) {
                return [
                    'success' => false,
                    'status' => 'failed',
                    'code' => 'provider_inventory_incomplete',
                    'provider_attempted' => true,
                    'token_retry' => $tokenRetry,
                    'endpoint' => '/api/licenses',
                    'http_method' => 'GET',
                    'http' => xchetosSafeHttpDebug($response),
                    'provider_message' => '',
                    'debug' => [
                        'phase' => 'provider_inventory',
                        'reason' => 'pagination_repeated_page',
                        'pages_checked' => $pagesChecked,
                        'records_checked' => $recordsChecked,
                        'reported_total' => $reportedTotal,
                        'offset' => $offset,
                        'returned_count' => $count,
                    ],
                ];
            }
            $seenPages[$pageFingerprint] = true;

            $recordsChecked += $count;
            $offset += $count;

            if ($reportedTotal !== null && $offset >= $reportedTotal) {
                $stopReason = 'reported_total_reached';
                break;
            }
            if ($recordsChecked >= $maxRecords) {
                $stopReason = 'record_limit';
                break;
            }
        }

        $complete = $reportedTotal !== null
            ? $offset >= $reportedTotal
            : $lastCount === 0;

        return [
            'success' => false,
            'status' => 'failed',
            'code' => $complete ? 'provider_not_owned' : 'provider_inventory_incomplete',
            'provider_attempted' => true,
            'token_retry' => $tokenRetry,
            'endpoint' => '/api/licenses',
            'http_method' => 'GET',
            'http' => $lastHttp,
            'provider_message' => '',
            'debug' => [
                'phase' => 'provider_inventory',
                'pages_checked' => $pagesChecked,
                'records_checked' => $recordsChecked,
                'reported_total' => $reportedTotal,
                'last_offset' => $offset,
                'last_returned_count' => $lastCount,
                'complete_scan' => $complete,
                'stop_reason' => $stopReason,
                'configured_max_pages' => $maxPages,
                'configured_max_records' => $maxRecords,
            ],
        ];
    }
}

if (!function_exists('xchetosInterpretResetResponse')) {
    function xchetosInterpretResetResponse(array $response, string $licenseKey = ''): array
    {
        $http = xchetosSafeHttpDebug($response);
        $httpStatus = (int) ($response['http_status'] ?? 0);
        if (!empty($response['transport_error'])) {
            $definitelyNotSent = xchetosTransportDefinitelyNotSent($response);
            return [
                'success' => false,
                'status' => $definitelyNotSent ? 'failed' : 'unknown',
                'code' => $definitelyNotSent ? ((string) ($response['code'] ?? '') === 'curl_missing' ? 'curl_missing' : 'provider_unavailable') : 'provider_unknown',
                'provider_message' => '',
                'http' => $http,
            ];
        }

        $json = is_array($response['json'] ?? null) ? $response['json'] : [];
        $providerMessage = xchetosProviderMessage($json, $licenseKey);
        $providerStatus = strtolower(trim((string) ($json['status'] ?? '')));
        if ($httpStatus === 200 && $providerStatus === 'ok') {
            return ['success' => true, 'status' => 'success', 'code' => 'success', 'provider_message' => $providerMessage, 'http' => $http];
        }

        $normalized = strtolower($providerMessage);
        if (strpos($normalized, 'only reset hwid for your own licenses') !== false) {
            return ['success' => false, 'status' => 'failed', 'code' => 'provider_not_owned', 'provider_message' => $providerMessage, 'http' => $http];
        }
        if ($httpStatus === 429) {
            return ['success' => false, 'status' => 'failed', 'code' => 'provider_rate_limited', 'provider_message' => $providerMessage, 'http' => $http];
        }
        if ($httpStatus === 401 || $httpStatus === 403) {
            return ['success' => false, 'status' => 'failed', 'code' => 'login_failed', 'provider_message' => $providerMessage, 'http' => $http];
        }
        if ($httpStatus >= 400 && $httpStatus < 500) {
            return ['success' => false, 'status' => 'failed', 'code' => 'provider_rejected', 'provider_message' => $providerMessage, 'http' => $http];
        }
        return ['success' => false, 'status' => 'unknown', 'code' => 'provider_unknown', 'provider_message' => $providerMessage, 'http' => $http];
    }
}

if (!function_exists('xchetosCallReset')) {
    /** Send the reset directly. The provider reset endpoint remains the source of truth. */
    function xchetosCallReset(string $licenseKey): array
    {
        $licenseKey = xchetosNormalizeLicenseKey($licenseKey);
        $request = xchetosAuthenticatedRequest('/api/license/reset-hwid', 'POST', ['license_key' => $licenseKey]);
        if (!empty($request['auth_failed'])) {
            $http = is_array($request['http'] ?? null) ? $request['http'] : [];
            return [
                'success' => false,
                'status' => 'failed',
                'code' => (string) ($request['auth_code'] ?? 'login_failed'),
                'provider_message' => '',
                'provider_attempted' => true,
                'token_retry' => !empty($request['token_retry']),
                'endpoint' => '/api/auth/login',
                'http_method' => 'POST',
                'http_status' => (int) ($http['http_status'] ?? 0),
                'transport_code' => (string) ($http['transport_code'] ?? ''),
                'curl_errno' => (int) ($http['curl_errno'] ?? 0),
                'curl_error' => (string) ($http['curl_error'] ?? ''),
                'duration_ms' => (int) ($http['duration_ms'] ?? 0),
                'connect_ms' => (int) ($http['connect_ms'] ?? 0),
                'primary_ip' => (string) ($http['primary_ip'] ?? ''),
                'request_stage' => 'provider_login',
                'provider_license' => [],
                'debug' => ['phase' => 'provider_login', 'direct_reset' => true, 'http' => $http],
            ];
        }

        $response = is_array($request['response'] ?? null) ? $request['response'] : [];
        $interpreted = xchetosInterpretResetResponse($response, $licenseKey);
        $http = is_array($interpreted['http'] ?? null) ? $interpreted['http'] : xchetosSafeHttpDebug($response);
        return [
            'success' => !empty($interpreted['success']),
            'status' => (string) ($interpreted['status'] ?? 'unknown'),
            'code' => (string) ($interpreted['code'] ?? 'provider_unknown'),
            'provider_message' => (string) ($interpreted['provider_message'] ?? ''),
            'provider_attempted' => true,
            'token_retry' => !empty($request['token_retry']),
            'endpoint' => '/api/license/reset-hwid',
            'http_method' => 'POST',
            'http_status' => (int) ($http['http_status'] ?? 0),
            'transport_code' => (string) ($http['transport_code'] ?? ''),
            'curl_errno' => (int) ($http['curl_errno'] ?? 0),
            'curl_error' => (string) ($http['curl_error'] ?? ''),
            'duration_ms' => (int) ($http['duration_ms'] ?? 0),
            'connect_ms' => (int) ($http['connect_ms'] ?? 0),
            'primary_ip' => (string) ($http['primary_ip'] ?? ''),
            'request_stage' => 'provider_response',
            'provider_license' => [],
            'debug' => ['phase' => 'provider_reset', 'direct_reset' => true, 'http' => $http],
        ];
    }
}

if (!function_exists('xchetosAcquireNamedLock')) {
    function xchetosAcquireNamedLock(string $name, int $timeoutSeconds = 5): bool
    {
        global $conn;
        if (!isset($conn) || !($conn instanceof mysqli)) return false;
        $name = substr(preg_replace('/[^A-Za-z0-9_.:-]/', '_', $name) ?? '', 0, 64);
        if ($name === '') return false;
        $timeoutSeconds = max(0, min(10, $timeoutSeconds));
        $stmt = $conn->prepare('SELECT GET_LOCK(?, ?)');
        if (!$stmt) return false;
        $stmt->bind_param('si', $name, $timeoutSeconds);
        if (!$stmt->execute()) {
            $stmt->close();
            return false;
        }
        $stmt->bind_result($locked);
        $found = $stmt->fetch();
        $stmt->close();
        return $found && (int) $locked === 1;
    }
}

if (!function_exists('xchetosReleaseNamedLock')) {
    function xchetosReleaseNamedLock(string $name): void
    {
        global $conn;
        if (!isset($conn) || !($conn instanceof mysqli)) return;
        $name = substr(preg_replace('/[^A-Za-z0-9_.:-]/', '_', $name) ?? '', 0, 64);
        if ($name === '') return;
        $stmt = $conn->prepare('SELECT RELEASE_LOCK(?)');
        if (!$stmt) return;
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('xchetosRedactDebugValue')) {
    function xchetosRedactDebugValue($value, int $depth = 0)
    {
        if ($depth > 6) return '[DEPTH_LIMIT]';
        if (is_array($value)) {
            $safe = [];
            $count = 0;
            foreach ($value as $key => $item) {
                if (++$count > 100) {
                    $safe['_truncated_items'] = true;
                    break;
                }
                $keyText = is_int($key) ? $key : substr((string) $key, 0, 100);
                if (!is_int($key) && preg_match('/token|password|secret|authorization|cookie|license_key|refresh|hwid/i', $keyText)) {
                    $safe[$keyText] = '[REDACTED]';
                    continue;
                }
                $safe[$keyText] = xchetosRedactDebugValue($item, $depth + 1);
            }
            return $safe;
        }
        if (is_string($value)) return xchetosSanitizeText($value, '', 2000);
        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) return $value;
        return xchetosSanitizeText((string) $value, '', 500);
    }
}

if (!function_exists('xchetosSafeDebugJson')) {
    function xchetosSafeDebugJson(array $debug): string
    {
        $safeDebug = xchetosRedactDebugValue($debug);
        $json = json_encode($safeDebug, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($json)) return '{}';
        if (strlen($json) > 16384) {
            $json = json_encode(['truncated' => true, 'sha256' => hash('sha256', $json), 'bytes' => strlen($json)]);
        }
        return is_string($json) ? $json : '{}';
    }
}

if (!function_exists('xchetosBackfillCommerceContext')) {
    /** Snapshot a resolved owner/order/product context without storing a plaintext key. */
    function xchetosBackfillCommerceContext(int $logId, array $context): bool
    {
        global $conn;
        if ($logId < 1 || empty($context['resolved']) || !isset($conn) || !($conn instanceof mysqli)) return false;

        $fields = [];
        $types = '';
        $params = [];
        $addString = static function (string $column, $value, int $maxLength) use (&$fields, &$types, &$params): void {
            $value = trim((string) $value);
            if ($value === '') return;
            if (function_exists('mb_substr')) $value = mb_substr($value, 0, $maxLength, 'UTF-8');
            else $value = substr($value, 0, $maxLength);
            $fields[] = "`{$column}` = ?";
            $types .= 's';
            $params[] = $value;
        };
        $addInt = static function (string $column, $value) use (&$fields, &$types, &$params): void {
            $value = (int) $value;
            if ($value < 1) return;
            $fields[] = "`{$column}` = ?";
            $types .= 'i';
            $params[] = $value;
        };

        $addString('owner_kind', $context['owner_kind'] ?? '', 24);
        $addInt('owner_user_id', $context['owner_user_id'] ?? 0);
        $addString('owner_username', $context['owner_username'] ?? '', 190);
        $addString('owner_email', $context['owner_email'] ?? '', 190);
        $addString('owner_role', $context['owner_role'] ?? '', 32);
        $addString('owner_source', $context['source'] ?? '', 32);
        $addInt('related_transaction_id', $context['transaction_id'] ?? 0);
        $addInt('related_order_id', $context['order_id'] ?? 0);
        $purchaseAt = trim((string) ($context['purchased_at'] ?? ''));
        if ($purchaseAt !== '' && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $purchaseAt)) {
            $addString('purchase_created_at', $purchaseAt, 19);
        }
        $addString('product_duration', $context['product_duration'] ?? '', 120);
        $addString('external_ref', $context['external_ref'] ?? '', 190);
        $addString('product_name', $context['product_name'] ?? '', 255);
        if ($fields === []) return false;

        $types .= 'i';
        $params[] = $logId;
        try {
            $stmt = $conn->prepare('UPDATE xchetos_hwid_reset_logs SET ' . implode(', ', $fields) . ' WHERE id = ?');
            if (!$stmt) return false;
            if (!xchetosBindParams($stmt, $types, $params)) {
                $stmt->close();
                return false;
            }
            $ok = $stmt->execute();
            $stmt->close();
            return $ok;
        } catch (Throwable $e) {
            error_log('xChetos commerce-context backfill failed: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('xchetosCreateLog')) {
    function xchetosCreateLog(array $context): int
    {
        global $conn;
        if (!xchetosEnsureResetTable()) {
            xchetosWriteSystemLog('audit_create_blocked', ['message' => 'Audit storage is unavailable'], 'error', 'audit_create', (string) ($context['request_id'] ?? ''), (int) ($context['actor_user_id'] ?? 0), (string) ($context['actor_role'] ?? 'unknown'));
            return 0;
        }
        $userId = (int) ($context['actor_user_id'] ?? 0);
        if ($userId < 1) return 0;
        $requestId = substr((string) ($context['request_id'] ?? xchetosRequestId()), 0, 32);
        $actorRole = substr((string) ($context['actor_role'] ?? 'unknown'), 0, 16);
        $operation = substr((string) ($context['operation_type'] ?? 'reset_owned'), 0, 32);
        $providerCode = substr((string) ($context['provider_code'] ?? 'xchetos'), 0, 32);
        if ($providerCode === '') $providerCode = 'xchetos';
        $targetUserId = (int) ($context['target_user_id'] ?? 0);
        $targetUserIdValue = $targetUserId > 0 ? $targetUserId : null;
        $source = substr((string) ($context['key_source'] ?? 'unknown'), 0, 16);
        $recordId = substr((string) ($context['key_record_id'] ?? '-'), 0, 64);
        $key = xchetosNormalizeLicenseKey((string) ($context['key_code'] ?? ''));
        $keyHash = $key !== '' ? hash('sha256', $key) : str_repeat('0', 64);
        $masked = $key !== '' ? xchetosMaskKey($key) : '-';
        $encryptedKey = $key !== '' ? xchetosEncryptAuditKey($key, $keyHash) : '';
        if ($key !== '' && $encryptedKey === '') {
            xchetosWriteSystemLog('audit_key_encrypt_failed', ['key_hash' => $keyHash], 'error', 'audit_encrypt', $requestId, $userId, $actorRole);
            return 0;
        }
        $encryptedKeyValue = $encryptedKey !== '' ? $encryptedKey : null;
        $product = xchetosSanitizeText((string) ($context['product_name'] ?? ''), $key, 255);
        $policy = xchetosKeyResetPolicy($key);
        $keyDays = max(0, (int) ($context['key_duration_days'] ?? $policy['days'] ?? 0));
        $keyDaysValue = $keyDays > 0 ? $keyDays : null;
        $keyLimit = max(1, min(20, (int) ($context['key_reset_limit'] ?? $policy['limit'] ?? 2)));
        $status = 'processing';
        $code = 'processing';
        $stage = substr((string) ($context['request_stage'] ?? 'created'), 0, 64);
        $ip = function_exists('getClientIp') ? getClientIp() : (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $ip = xchetosSanitizeText($ip, '', 45);
        $ua = xchetosSanitizeText((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), '', 255);

        $stmt = $conn->prepare(
            'INSERT INTO xchetos_hwid_reset_logs
             (user_id, request_id, actor_role, operation_type, provider_code, target_user_id, key_source, key_record_id,
              key_hash, key_masked, key_ciphertext, product_name, key_duration_days, key_reset_limit,
              status, result_code, request_stage, request_ip, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            xchetosWriteSystemLog('audit_insert_prepare_failed', [
                'db_errno' => (int) $conn->errno,
                'message' => substr((string) $conn->error, 0, 500),
            ], 'error', 'audit_insert', $requestId, $userId, $actorRole);
            return 0;
        }
        $stmt->bind_param(
            'issssissssssiisssss',
            $userId, $requestId, $actorRole, $operation, $providerCode, $targetUserIdValue, $source, $recordId,
            $keyHash, $masked, $encryptedKeyValue, $product, $keyDaysValue, $keyLimit, $status, $code, $stage, $ip, $ua
        );
        $ok = $stmt->execute();
        $stmtErrno = (int) $stmt->errno;
        $stmtError = substr((string) $stmt->error, 0, 500);
        $id = $ok ? (int) $conn->insert_id : 0;
        $stmt->close();
        if (!$ok || $id < 1) {
            xchetosWriteSystemLog('audit_insert_execute_failed', [
                'db_errno' => $stmtErrno,
                'message' => $stmtError,
                'operation_type' => $operation,
                'key_source' => $source,
            ], 'error', 'audit_insert', $requestId, $userId, $actorRole);
        }
        if ($id > 0 && $key !== '') {
            // This also upgrades older masked-only rows for the same key.
            xchetosBackfillEncryptedAuditKey($key, $keyHash);
        }
        if ($id > 0 && function_exists('commerceResolveKeyContext')) {
            $commerceContext = commerceResolveKeyContext([
                'key_source' => $source,
                'key_record_id' => $recordId,
                'key_code' => $key,
                'key_hash' => $keyHash,
            ]);
            if (!empty($commerceContext['resolved'])) xchetosBackfillCommerceContext($id, $commerceContext);
        }
        return $id;
    }
}

if (!function_exists('xchetosFinishLog')) {
    function xchetosFinishLog(int $logId, array $result): bool
    {
        global $conn;
        if ($logId < 1) return false;
        $status = in_array((string) ($result['status'] ?? ''), ['success', 'failed', 'unknown'], true)
            ? (string) $result['status'] : 'unknown';
        $code = substr((string) ($result['code'] ?? 'provider_unknown'), 0, 64);
        $stage = substr((string) ($result['request_stage'] ?? 'complete'), 0, 64);
        $providerAttempted = !empty($result['provider_attempted']) ? 1 : 0;
        $httpStatus = (int) ($result['http_status'] ?? 0);
        $httpStatusValue = $httpStatus > 0 && $httpStatus <= 65535 ? $httpStatus : null;
        $message = xchetosSanitizeText((string) ($result['provider_message'] ?? ''), '', 500);
        $endpoint = substr((string) ($result['endpoint'] ?? ''), 0, 190);
        $method = substr(strtoupper((string) ($result['http_method'] ?? '')), 0, 10);
        $transportCode = substr((string) ($result['transport_code'] ?? ''), 0, 64);
        $curlErrno = max(0, (int) ($result['curl_errno'] ?? 0));
        $curlErrnoValue = $curlErrno > 0 ? $curlErrno : null;
        $curlError = xchetosSanitizeText((string) ($result['curl_error'] ?? ''), '', 255);
        $durationMs = max(0, (int) ($result['duration_ms'] ?? 0));
        $durationValue = $durationMs > 0 ? $durationMs : null;
        $connectMs = max(0, (int) ($result['connect_ms'] ?? 0));
        $connectValue = $connectMs > 0 ? $connectMs : null;
        $primaryIp = xchetosSanitizeText((string) ($result['primary_ip'] ?? ''), '', 45);
        $tokenRetry = !empty($result['token_retry']) ? 1 : 0;
        $license = is_array($result['provider_license'] ?? null) ? $result['provider_license'] : [];
        $productName = xchetosSanitizeText((string) ($license['product_name'] ?? $result['product_name'] ?? ''), '', 255);
        $providerLicenseId = max(0, (int) ($license['id'] ?? 0));
        $providerLicenseIdValue = $providerLicenseId > 0 ? $providerLicenseId : null;
        $providerProductId = max(0, (int) ($license['product_id'] ?? 0));
        $providerProductIdValue = $providerProductId > 0 ? $providerProductId : null;
        $providerLicenseStatus = xchetosSanitizeText((string) ($license['status'] ?? ''), '', 32);
        $debugJson = xchetosSafeDebugJson((array) ($result['debug'] ?? []));

        $stmt = $conn->prepare(
            'UPDATE xchetos_hwid_reset_logs
             SET status = ?, result_code = ?, request_stage = ?, provider_attempted = ?,
                 provider_http_status = ?, provider_message = ?, endpoint = ?, http_method = ?,
                 transport_code = ?, curl_errno = ?, curl_error = ?, duration_ms = ?, connect_ms = ?,
                 primary_ip = ?, token_retry = ?,
                 product_name = CASE WHEN ? <> \'\' THEN ? ELSE product_name END,
                 provider_license_id = ?, provider_product_id = ?, provider_license_status = ?,
                 debug_json = ?, completed_at = NOW()
             WHERE id = ?'
        );
        if (!$stmt) {
            xchetosWriteSystemLog('audit_update_prepare_failed', [
                'log_id' => $logId,
                'db_errno' => (int) $conn->errno,
                'message' => substr((string) $conn->error, 0, 500),
            ], 'error', 'audit_update', (string) ($result['request_id'] ?? ''));
            return false;
        }
        $stmt->bind_param(
            'sssiissssisiisissiissi',
            $status, $code, $stage, $providerAttempted, $httpStatusValue, $message, $endpoint, $method,
            $transportCode, $curlErrnoValue, $curlError, $durationValue, $connectValue, $primaryIp,
            $tokenRetry, $productName, $productName, $providerLicenseIdValue, $providerProductIdValue,
            $providerLicenseStatus, $debugJson, $logId
        );
        $executed = $stmt->execute();
        $affected = (int) $stmt->affected_rows;
        $stmtErrno = (int) $stmt->errno;
        $stmtError = substr((string) $stmt->error, 0, 500);
        $ok = $executed && $affected === 1;
        $stmt->close();
        if (!$ok) {
            xchetosWriteSystemLog('audit_update_execute_failed', [
                'log_id' => $logId,
                'affected_rows' => $affected,
                'db_errno' => $stmtErrno,
                'message' => $stmtError,
                'result_code' => $code,
            ], 'error', 'audit_update', (string) ($result['request_id'] ?? ''));
        }
        return $ok;
    }
}

if (!function_exists('xchetosImmediateResult')) {
    function xchetosImmediateResult(array $context, string $code, string $status = 'failed', array $extra = []): array
    {
        $requestId = (string) ($context['request_id'] ?? xchetosRequestId());
        $context['request_id'] = $requestId;
        $key = xchetosNormalizeLicenseKey((string) ($context['key_code'] ?? ''));
        $policy = xchetosKeyResetPolicy($key);
        $base = [
            'success' => $status === 'success',
            'status' => in_array($status, ['success', 'failed', 'unknown'], true) ? $status : 'failed',
            'code' => $code,
            'request_id' => $requestId,
            'request_stage' => (string) ($extra['request_stage'] ?? 'validation'),
            'provider_attempted' => false,
            'key_masked' => $key !== '' ? xchetosMaskKey($key) : '',
            'product_name' => xchetosSanitizeText((string) ($context['product_name'] ?? ''), $key, 255),
            'key_duration_days' => max(0, (int) ($context['key_duration_days'] ?? $policy['days'] ?? 0)),
            'key_reset_limit' => max(1, (int) ($context['key_reset_limit'] ?? $policy['limit'] ?? 2)),
            'key_reset_count' => 0,
            'key_reset_remaining' => max(1, (int) ($context['key_reset_limit'] ?? $policy['limit'] ?? 2)),
            'debug' => (array) ($extra['debug'] ?? []),
        ];

        if ($key !== '') {
            $usage = xchetosGetKeyResetUsage(hash('sha256', $key), true, (int) $base['key_reset_limit']);
            if (!empty($usage['available'])) {
                $base['key_reset_count'] = max(0, (int) ($usage['used'] ?? 0));
                $base['key_reset_limit'] = max(1, (int) ($usage['limit'] ?? $base['key_reset_limit']));
                $base['key_reset_remaining'] = max(0, (int) ($usage['remaining'] ?? 0));
            }
        }

        if ((string) ($context['actor_role'] ?? '') === 'reseller') {
            $quota = xchetosGetResellerDailyQuota((int) ($context['actor_user_id'] ?? 0));
            if (!empty($quota['available'])) {
                $base['daily_used'] = max(0, (int) ($quota['used'] ?? 0));
                $base['daily_limit'] = max(1, (int) ($quota['limit'] ?? 1));
                $base['daily_remaining'] = max(0, (int) ($quota['remaining'] ?? 0));
                $base['daily_reset_at'] = (string) ($quota['reset_at'] ?? '');
            }
        }

        $logId = xchetosCreateLog($context);
        $result = array_merge($base, $extra);
        if ($logId > 0 && !xchetosFinishLog($logId, $result)) {
            xchetosWriteSystemLog('immediate_audit_finish_failed', [
                'log_id' => $logId,
                'result_code' => $code,
            ], 'error', (string) ($result['request_stage'] ?? 'validation'), $requestId,
                (int) ($context['actor_user_id'] ?? 0), (string) ($context['actor_role'] ?? 'unknown'));
        }
        xchetosWriteSystemLog('reset_immediate_result', [
            'result_code' => $code,
            'status' => (string) ($result['status'] ?? 'failed'),
            'provider_attempted' => !empty($result['provider_attempted']),
            'wait_seconds' => max(0, (int) ($result['wait_seconds'] ?? 0)),
            'key_masked' => (string) ($result['key_masked'] ?? ''),
        ], $status === 'success' ? 'info' : 'warning', (string) ($result['request_stage'] ?? 'validation'),
            $requestId, (int) ($context['actor_user_id'] ?? 0), (string) ($context['actor_role'] ?? 'unknown'));
        return $result;
    }
}

if (!function_exists('xchetosRecentProviderAttemptCount')) {
    /** Count provider reset calls that were actually sent, not validation failures. */
    function xchetosRecentProviderAttemptCount(int $userId, int $windowSeconds): int
    {
        global $conn;
        if ($userId < 1 || !xchetosEnsureResetTable()) return -1;
        $windowSeconds = max(60, min(86400, $windowSeconds));
        $sql = "SELECT COUNT(*) FROM xchetos_hwid_reset_logs
                WHERE user_id = ? AND provider_code = 'xchetos'
                  AND provider_attempted = 1
                  AND endpoint = '/api/license/reset-hwid'
                  AND operation_type IN ('reset_owned','reset_admin')
                  AND created_at >= DATE_SUB(NOW(), INTERVAL {$windowSeconds} SECOND)";
        $stmt = $conn->prepare($sql);
        if (!$stmt) return -1;
        $stmt->bind_param('i', $userId);
        if (!$stmt->execute()) {
            $stmt->close();
            return -1;
        }
        $stmt->bind_result($count);
        $found = $stmt->fetch();
        $stmt->close();
        return $found ? (int) $count : -1;
    }
}

if (!function_exists('xchetosGetResellerDailyQuota')) {
    /** Rolling 24-hour allowance for one reseller. */
    function xchetosGetResellerDailyQuota(int $userId): array
    {
        global $conn;
        $config = xchetosConfig();
        $limit = max(1, (int) ($config['reseller_daily_limit'] ?? 100));
        $window = 86400;
        $result = [
            'used' => 0,
            'limit' => $limit,
            'remaining' => $limit,
            'window_seconds' => $window,
            'reset_at' => null,
            'wait_seconds' => 0,
            'available' => true,
        ];
        if ($userId < 1 || !xchetosEnsureResetTable()) {
            $result['available'] = false;
            return $result;
        }

        $sql = "SELECT COUNT(*), MIN(UNIX_TIMESTAMP(created_at))
                FROM xchetos_hwid_reset_logs
                WHERE user_id = ? AND actor_role = 'reseller'
                  AND provider_code = 'xchetos'
                  AND operation_type = 'reset_owned'
                  AND provider_attempted = 1
                  AND endpoint = '/api/license/reset-hwid'
                  AND created_at >= DATE_SUB(NOW(), INTERVAL {$window} SECOND)";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $result['available'] = false;
            return $result;
        }
        $stmt->bind_param('i', $userId);
        if (!$stmt->execute()) {
            $stmt->close();
            $result['available'] = false;
            return $result;
        }
        $stmt->bind_result($count, $oldestTimestamp);
        $found = $stmt->fetch();
        $stmt->close();
        if (!$found) {
            $result['available'] = false;
            return $result;
        }

        $used = max(0, (int) $count);
        $remaining = max(0, $limit - $used);
        $resetTimestamp = is_numeric($oldestTimestamp) && (int) $oldestTimestamp > 0
            ? (int) $oldestTimestamp + $window
            : 0;
        $wait = $remaining <= 0 && $resetTimestamp > 0 ? max(1, $resetTimestamp - time()) : 0;
        $result['used'] = $used;
        $result['remaining'] = $remaining;
        $result['reset_at'] = $resetTimestamp > 0 ? date('Y-m-d H:i:s', $resetTimestamp) : null;
        $result['wait_seconds'] = $wait;
        return $result;
    }
}

if (!function_exists('xchetosGetKeyResetUsage')) {
    /** Count reseller resets consumed globally for one key. */
    function xchetosGetKeyResetUsage(string $keyOrHash, bool $isHash = false, ?int $limitOverride = null): array
    {
        global $conn;
        $policy = $isHash ? ['days' => 0, 'limit' => ($limitOverride ?? 2)] : xchetosKeyResetPolicy($keyOrHash);
        $limit = max(1, min(20, (int) ($limitOverride ?? $policy['limit'] ?? 2)));
        $days = max(0, (int) ($policy['days'] ?? 0));
        $hash = $isHash ? strtolower(trim($keyOrHash)) : hash('sha256', xchetosNormalizeLicenseKey($keyOrHash));
        $result = ['used' => 0, 'limit' => $limit, 'remaining' => $limit, 'days' => $days, 'available' => true];
        if (!preg_match('/^[a-f0-9]{64}$/', $hash) || !xchetosEnsureResetTable()) {
            $result['available'] = false;
            return $result;
        }

        $stmt = $conn->prepare(
            "SELECT COUNT(*) FROM xchetos_hwid_reset_logs
             WHERE key_hash = ? AND provider_code = 'xchetos'
               AND operation_type = 'reset_owned'
               AND endpoint = '/api/license/reset-hwid'
               AND status IN ('success','unknown')"
        );
        if (!$stmt) {
            $result['available'] = false;
            return $result;
        }
        $stmt->bind_param('s', $hash);
        if (!$stmt->execute()) {
            $stmt->close();
            $result['available'] = false;
            return $result;
        }
        $stmt->bind_result($count);
        $found = $stmt->fetch();
        $stmt->close();
        if (!$found) {
            $result['available'] = false;
            return $result;
        }
        $used = max(0, (int) $count);
        $result['used'] = $used;
        $result['remaining'] = max(0, $limit - $used);
        return $result;
    }
}

if (!function_exists('xchetosGetKeyResetUsageMap')) {
    /** @return array<string,array{used:int,limit:int,remaining:int,days:int,available:bool}> */
    function xchetosGetKeyResetUsageMap(array $licenseKeys): array
    {
        global $conn;
        $map = [];
        foreach ($licenseKeys as $licenseKey) {
            if (!is_scalar($licenseKey)) continue;
            $key = xchetosNormalizeLicenseKey((string) $licenseKey);
            if ($key === '') continue;
            $hash = hash('sha256', $key);
            $policy = xchetosKeyResetPolicy($key);
            $limit = max(1, (int) ($policy['limit'] ?? 2));
            $map[$hash] = [
                'used' => 0,
                'limit' => $limit,
                'remaining' => $limit,
                'days' => max(0, (int) ($policy['days'] ?? 0)),
                'available' => true,
            ];
        }
        if ($map === []) return $map;
        if (!xchetosEnsureResetTable()) {
            foreach ($map as &$usage) $usage['available'] = false;
            unset($usage);
            return $map;
        }

        $hashList = array_keys($map);
        foreach (array_chunk($hashList, 400) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $types = str_repeat('s', count($chunk));
            $sql = "SELECT key_hash, COUNT(*) AS used_count
                    FROM xchetos_hwid_reset_logs
                    WHERE provider_code = 'xchetos'
                      AND operation_type = 'reset_owned'
                      AND endpoint = '/api/license/reset-hwid'
                      AND status IN ('success','unknown')
                      AND key_hash IN ({$placeholders})
                    GROUP BY key_hash";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                foreach ($chunk as $hash) $map[$hash]['available'] = false;
                continue;
            }
            if (!xchetosBindParams($stmt, $types, $chunk) || !$stmt->execute()) {
                foreach ($chunk as $hash) $map[$hash]['available'] = false;
                $stmt->close();
                continue;
            }
            $rows = $stmt->get_result();
            if (!$rows) {
                foreach ($chunk as $hash) $map[$hash]['available'] = false;
                $stmt->close();
                continue;
            }
            while ($row = $rows->fetch_assoc()) {
                $hash = (string) ($row['key_hash'] ?? '');
                if (!isset($map[$hash])) continue;
                $used = max(0, (int) ($row['used_count'] ?? 0));
                $map[$hash]['used'] = $used;
                $map[$hash]['remaining'] = max(0, (int) $map[$hash]['limit'] - $used);
            }
            $stmt->close();
        }
        return $map;
    }
}

if (!function_exists('xchetosGetAdminResellerQuotaRows')) {
    function xchetosGetAdminResellerQuotaRows(int $limitRows = 30): array
    {
        global $conn;
        if (!xchetosEnsureResetTable()) return [];
        $config = xchetosConfig();
        $dailyLimit = max(1, (int) ($config['reseller_daily_limit'] ?? 100));
        $limitRows = max(1, min(100, $limitRows));
        $sql = "SELECT l.user_id, COALESCE(u.username, CONCAT('user#', l.user_id)) AS username,
                       COUNT(*) AS used_count, MIN(UNIX_TIMESTAMP(l.created_at)) AS oldest_ts,
                       MAX(l.created_at) AS last_reset_at
                FROM xchetos_hwid_reset_logs l
                LEFT JOIN users u ON u.id = l.user_id
                WHERE l.actor_role = 'reseller' AND l.provider_code = 'xchetos'
                  AND l.operation_type = 'reset_owned'
                  AND l.provider_attempted = 1
                  AND l.endpoint = '/api/license/reset-hwid'
                  AND l.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                GROUP BY l.user_id, u.username
                ORDER BY used_count DESC, last_reset_at DESC
                LIMIT ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) return [];
        $stmt->bind_param('i', $limitRows);
        if (!$stmt->execute()) {
            $stmt->close();
            return [];
        }
        $result = $stmt->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        foreach ($rows as &$row) {
            $used = max(0, (int) ($row['used_count'] ?? 0));
            $oldest = max(0, (int) ($row['oldest_ts'] ?? 0));
            $row['limit'] = $dailyLimit;
            $row['remaining'] = max(0, $dailyLimit - $used);
            $row['reset_at'] = $oldest > 0 ? date('Y-m-d H:i:s', $oldest + 86400) : null;
        }
        unset($row);
        return $rows;
    }
}

if (!function_exists('xchetosLatestResetBlock')) {
    function xchetosLatestResetBlock(string $keyHash, array $config): array
    {
        global $conn;
        $stmt = $conn->prepare(
            "SELECT status,
                    CASE WHEN status = 'processing' THEN TIMESTAMPDIFF(SECOND, created_at, NOW())
                         ELSE TIMESTAMPDIFF(SECOND, COALESCE(completed_at, created_at), NOW()) END AS age_seconds
             FROM xchetos_hwid_reset_logs
             WHERE key_hash = ? AND provider_code = 'xchetos'
               AND operation_type IN ('reset_owned','reset_admin')
               AND status IN ('processing','success','unknown')
             ORDER BY id DESC LIMIT 1"
        );
        if (!$stmt) return ['error' => true];
        $stmt->bind_param('s', $keyHash);
        if (!$stmt->execute()) {
            $stmt->close();
            return ['error' => true];
        }
        $stmt->bind_result($status, $ageSeconds);
        $found = $stmt->fetch();
        $stmt->close();
        if (!$found) return ['blocked' => false, 'wait_seconds' => 0, 'code' => ''];
        if (!is_numeric($ageSeconds)) return ['error' => true];

        $status = (string) $status;
        if ($status === 'success') {
            $window = (int) ($config['cooldown_seconds'] ?? 60);
            $code = 'cooldown';
        } elseif ($status === 'unknown') {
            $window = (int) ($config['unknown_cooldown_seconds'] ?? 300);
            $code = 'verification_pending';
        } else {
            $window = (int) ($config['request_timeout'] ?? 20) + 60;
            $code = 'busy';
        }
        $remaining = max(0, $window - max(0, (int) $ageSeconds));
        return ['blocked' => $remaining > 0, 'wait_seconds' => $remaining, 'code' => $code];
    }
}

if (!function_exists('xchetosPerformReset')) {
    function xchetosPerformReset(array $context): array
    {
        $requestId = (string) ($context['request_id'] ?? xchetosRequestId());
        $context['request_id'] = $requestId;
        $context['provider_code'] = 'xchetos';
        $actorId = (int) ($context['actor_user_id'] ?? 0);
        $actorRole = (string) ($context['actor_role'] ?? 'unknown');
        $isResellerActor = $actorRole === 'reseller';
        $key = xchetosNormalizeLicenseKey((string) ($context['key_code'] ?? ''));
        $context['key_code'] = $key;
        $keyPolicy = xchetosKeyResetPolicy($key);
        $context['key_duration_days'] = (int) ($keyPolicy['days'] ?? 0);
        $context['key_reset_limit'] = (int) ($keyPolicy['limit'] ?? 2);
        $masked = xchetosMaskKey($key);
        $productName = (string) ($context['product_name'] ?? '');
        $config = xchetosConfig();
        xchetosWriteSystemLog('reset_flow_started', [
            'operation_type' => (string) ($context['operation_type'] ?? ''),
            'key_source' => (string) ($context['key_source'] ?? ''),
            'key_masked' => $masked,
            'key_duration_days' => (int) ($keyPolicy['days'] ?? 0),
            'key_reset_limit' => (int) ($keyPolicy['limit'] ?? 2),
        ], 'info', 'start', $requestId, $actorId, $actorRole);

        if (!xchetosIsConfigured()) {
            return xchetosImmediateResult($context, 'not_configured', 'failed', ['request_stage' => 'configuration']);
        }
        $eligibility = xchetosKeyEligibility($key);
        if (empty($eligibility['eligible'])) {
            return xchetosImmediateResult($context, 'key_not_supported', 'failed', [
                'request_stage' => 'validation',
                'debug' => ['eligibility_code' => (string) ($eligibility['code'] ?? 'unknown')],
            ]);
        }

        // Short burst protection is deliberately separate from the rolling
        // 24-hour reseller allowance. A burst failure does not consume quota.
        $burstMax = $isResellerActor ? (int) ($config['burst_max_attempts'] ?? 12) : 30;
        $burstWindow = $isResellerActor ? (int) ($config['burst_window_seconds'] ?? 60) : 60;
        $rateKey = 'xchetos_hwid_burst_' . $actorRole . '_' . $actorId;
        if (function_exists('checkRateLimit') && !checkRateLimit($rateKey, $burstMax, $burstWindow)) {
            $wait = function_exists('getRateLimitReset')
                ? getRateLimitReset($rateKey, $burstWindow)
                : $burstWindow;
            return xchetosImmediateResult($context, 'rate_limited', 'failed', [
                'request_stage' => 'burst_rate_limit',
                'wait_seconds' => max(1, (int) $wait),
            ]);
        }

        $keyHash = hash('sha256', $key);

        // Fast idempotency check before waiting for a named lock. This covers a
        // browser that repeats the same POST after losing the response.
        if ($isResellerActor) {
            $existingRequest = xchetosGetResellerResetStatus($actorId, $requestId);
            if (!empty($existingRequest['found'])) {
                unset($existingRequest['found']);
                return $existingRequest;
            }
        }

        $actorLock = 'xchetos_actor_' . $actorRole . '_' . $actorId;
        $keyLock = 'xchetos_key_' . substr($keyHash, 0, 40);
        if (!xchetosAcquireNamedLock($actorLock, 5)) {
            if ($isResellerActor) {
                $existingRequest = xchetosGetResellerResetStatus($actorId, $requestId);
                if (!empty($existingRequest['found'])) {
                    unset($existingRequest['found']);
                    return $existingRequest;
                }
            }
            return [
                'success' => false,
                'status' => 'failed',
                'code' => 'busy',
                'request_id' => $requestId,
                'request_stage' => 'actor_lock',
                'provider_attempted' => false,
                'wait_seconds' => 2,
            ];
        }
        $keyLocked = false;
        try {
            // A browser may repeat a POST after a connection interruption.
            // Reuse the result for the same client request id instead of
            // calling the provider twice.
            if ($isResellerActor) {
                $existingRequest = xchetosGetResellerResetStatus($actorId, $requestId);
                if (!empty($existingRequest['found'])) {
                    unset($existingRequest['found']);
                    return $existingRequest;
                }
            }

            if (!xchetosAcquireNamedLock($keyLock, 5)) {
                if ($isResellerActor) {
                    $existingRequest = xchetosGetResellerResetStatus($actorId, $requestId);
                    if (!empty($existingRequest['found'])) {
                        unset($existingRequest['found']);
                        return $existingRequest;
                    }
                }
                return [
                    'success' => false,
                    'status' => 'failed',
                    'code' => 'busy',
                    'request_id' => $requestId,
                    'request_stage' => 'key_lock',
                    'provider_attempted' => false,
                    'wait_seconds' => 2,
                ];
            }
            $keyLocked = true;

            if ($isResellerActor) {
                $quota = xchetosGetResellerDailyQuota($actorId);
                if (empty($quota['available'])) {
                    return xchetosImmediateResult($context, 'audit_unavailable', 'failed', ['request_stage' => 'daily_quota_check']);
                }
                if ((int) ($quota['remaining'] ?? 0) <= 0) {
                    return xchetosImmediateResult($context, 'daily_limit_reached', 'failed', [
                        'request_stage' => 'daily_quota',
                        'wait_seconds' => max(1, (int) ($quota['wait_seconds'] ?? 86400)),
                        'daily_used' => (int) ($quota['used'] ?? 0),
                        'daily_limit' => (int) ($quota['limit'] ?? 0),
                        'daily_reset_at' => (string) ($quota['reset_at'] ?? ''),
                        'debug' => [
                            'daily_used' => (int) ($quota['used'] ?? 0),
                            'daily_limit' => (int) ($quota['limit'] ?? 0),
                            'daily_reset_at' => (string) ($quota['reset_at'] ?? ''),
                        ],
                    ]);
                }

                // The limit is global by key hash, not per reseller. A customer
                // therefore cannot ask a different reseller to obtain extra
                // resets for the same license.
                $keyUsage = xchetosGetKeyResetUsage($keyHash, true, (int) ($keyPolicy['limit'] ?? 2));
                if (empty($keyUsage['available'])) {
                    return xchetosImmediateResult($context, 'audit_unavailable', 'failed', ['request_stage' => 'key_quota_check']);
                }
                if ((int) ($keyUsage['remaining'] ?? 0) <= 0) {
                    return xchetosImmediateResult($context, 'key_reset_limit_reached', 'failed', [
                        'request_stage' => 'key_quota',
                        'key_reset_count' => (int) ($keyUsage['used'] ?? 0),
                        'key_reset_limit' => (int) ($keyUsage['limit'] ?? 0),
                        'debug' => [
                            'key_reset_count' => (int) ($keyUsage['used'] ?? 0),
                            'key_reset_limit' => (int) ($keyUsage['limit'] ?? 0),
                            'scope' => 'global_across_resellers',
                        ],
                    ]);
                }
            }

            $block = xchetosLatestResetBlock($keyHash, $config);
            if (!empty($block['error'])) {
                return xchetosImmediateResult($context, 'audit_unavailable', 'failed', ['request_stage' => 'cooldown_check']);
            }
            if (!empty($block['blocked'])) {
                return xchetosImmediateResult($context, (string) ($block['code'] ?? 'busy'), 'failed', [
                    'request_stage' => 'cooldown',
                    'wait_seconds' => max(1, (int) ($block['wait_seconds'] ?? 1)),
                ]);
            }

            $context['request_stage'] = 'provider_reset';
            $logId = xchetosCreateLog($context);
            if ($logId < 1) {
                return ['success' => false, 'status' => 'failed', 'code' => 'audit_unavailable', 'request_id' => $requestId];
            }

            $result = xchetosCallReset($key);
            $result['request_id'] = $requestId;
            $result['key_masked'] = $masked;
            $result['provider_code'] = 'xchetos';
            $providerProductName = trim((string) (($result['provider_license']['product_name'] ?? '') ?: $productName));
            $result['product_name'] = $providerProductName;
            if (!xchetosFinishLog($logId, $result)) {
                xchetosWriteSystemLog('provider_result_audit_finish_failed', [
                    'log_id' => $logId,
                    'result_code' => (string) ($result['code'] ?? ''),
                ], 'error', 'provider_result', $requestId, $actorId, $actorRole);
            }
            xchetosWriteSystemLog('provider_reset_result', [
                'success' => !empty($result['success']),
                'status' => (string) ($result['status'] ?? 'unknown'),
                'result_code' => (string) ($result['code'] ?? 'provider_unknown'),
                'http_status' => max(0, (int) ($result['http_status'] ?? 0)),
                'transport_code' => (string) ($result['transport_code'] ?? ''),
                'duration_ms' => max(0, (int) ($result['duration_ms'] ?? 0)),
                'key_masked' => $masked,
            ], !empty($result['success']) ? 'info' : ((string) ($result['status'] ?? '') === 'unknown' ? 'warning' : 'error'),
                'provider_result', $requestId, $actorId, $actorRole);

            $latestUsage = xchetosGetKeyResetUsage($keyHash, true, (int) ($keyPolicy['limit'] ?? 2));
            $result['key_duration_days'] = (int) ($keyPolicy['days'] ?? 0);
            $result['key_reset_count'] = max(0, (int) ($latestUsage['used'] ?? 0));
            $result['key_reset_limit'] = max(1, (int) ($latestUsage['limit'] ?? $keyPolicy['limit'] ?? 2));
            $result['key_reset_remaining'] = max(0, (int) ($latestUsage['remaining'] ?? 0));
            if ($isResellerActor) {
                $latestQuota = xchetosGetResellerDailyQuota($actorId);
                $result['daily_used'] = max(0, (int) ($latestQuota['used'] ?? 0));
                $result['daily_limit'] = max(1, (int) ($latestQuota['limit'] ?? 1));
                $result['daily_remaining'] = max(0, (int) ($latestQuota['remaining'] ?? 0));
                $result['daily_reset_at'] = (string) ($latestQuota['reset_at'] ?? '');
            }

            if (!empty($result['success']) && function_exists('logHistory')) {
                logHistory($actorId, 'xchetos_hwid_reset', 'xChetos HWID reset completed for ' . $masked . ' ref=' . $requestId);
            }
            return $result;
        } finally {
            if ($keyLocked) xchetosReleaseNamedLock($keyLock);
            xchetosReleaseNamedLock($actorLock);
        }
    }
}

if (!function_exists('xchetosResetResellerKey')) {
    /** Allow a reseller to reset any correctly formatted provider key. */
    function xchetosResetResellerKey(int $userId, string $licenseKey, string $clientRequestId = ''): array
    {
        $requestId = xchetosNormalizeRequestId($clientRequestId);
        $licenseKey = xchetosNormalizeLicenseKey($licenseKey);
        $context = [
            'request_id' => $requestId,
            'actor_user_id' => $userId,
            'actor_role' => 'reseller',
            // Keep the historic operation code so quotas and existing reports continue to work.
            'operation_type' => 'reset_owned',
            'target_user_id' => null,
            'key_source' => 'reseller_manual',
            'key_record_id' => 'manual',
            'key_code' => $licenseKey,
            'product_name' => 'Reseller manual reset',
        ];
        if ($userId < 1 || !function_exists('isReseller') || !isReseller()) {
            return ['success' => false, 'status' => 'failed', 'code' => 'forbidden', 'request_id' => $requestId];
        }
        if (!xchetosEnsureResetTable()) return ['success' => false, 'status' => 'failed', 'code' => 'audit_unavailable', 'request_id' => $requestId];
        return xchetosPerformReset($context);
    }
}

if (!function_exists('xchetosResetOwnedKey')) {
    function xchetosResetOwnedKey(int $userId, string $source, $recordId): array
    {
        $requestId = xchetosRequestId();
        $context = [
            'request_id' => $requestId,
            'actor_user_id' => $userId,
            'actor_role' => 'reseller',
            'operation_type' => 'reset_owned',
            'target_user_id' => $userId,
            'key_source' => $source,
            'key_record_id' => is_scalar($recordId) ? (string) $recordId : '-',
        ];
        if ($userId < 1 || !function_exists('isReseller') || !isReseller()) {
            return ['success' => false, 'status' => 'failed', 'code' => 'forbidden', 'request_id' => $requestId];
        }
        if (!xchetosEnsureResetTable()) return ['success' => false, 'status' => 'failed', 'code' => 'audit_unavailable', 'request_id' => $requestId];

        $ownedKey = xchetosResolveOwnedKey($userId, $source, $recordId);
        if (!$ownedKey) return xchetosImmediateResult($context, 'key_not_found', 'failed', ['request_stage' => 'ownership']);
        $context['key_source'] = (string) $ownedKey['source'];
        $context['key_record_id'] = (string) $ownedKey['record_id'];
        $context['key_code'] = (string) $ownedKey['key_code'];
        $context['product_name'] = (string) ($ownedKey['product_name'] ?? '');
        return xchetosPerformReset($context);
    }
}

if (!function_exists('xchetosResetAdminKey')) {
    function xchetosResetAdminKey(int $adminId, string $licenseKey, string $productName = 'Admin manual test'): array
    {
        $requestId = xchetosRequestId();
        $context = [
            'request_id' => $requestId,
            'actor_user_id' => $adminId,
            'actor_role' => 'admin',
            'operation_type' => 'reset_admin',
            'target_user_id' => null,
            'key_source' => 'admin_manual',
            'key_record_id' => 'manual',
            'key_code' => xchetosNormalizeLicenseKey($licenseKey),
            'product_name' => $productName,
        ];
        $config = xchetosConfig();
        if ($adminId < 1 || !function_exists('isAdmin') || !isAdmin() || empty($config['admin_enabled'])) {
            return ['success' => false, 'status' => 'failed', 'code' => 'forbidden', 'request_id' => $requestId];
        }
        if (!xchetosEnsureResetTable()) return ['success' => false, 'status' => 'failed', 'code' => 'audit_unavailable', 'request_id' => $requestId];
        return xchetosPerformReset($context);
    }
}

if (!function_exists('xchetosRunAdminDiagnostic')) {
    function xchetosRunAdminDiagnostic(int $adminId): array
    {
        $requestId = xchetosRequestId();
        $context = [
            'request_id' => $requestId,
            'actor_user_id' => $adminId,
            'actor_role' => 'admin',
            'operation_type' => 'diagnostic_login',
            'key_source' => 'system',
            'key_record_id' => 'diagnostic',
            'product_name' => 'xChetos connection diagnostic',
        ];
        if ($adminId < 1 || !function_exists('isAdmin') || !isAdmin()) {
            return ['success' => false, 'status' => 'failed', 'code' => 'forbidden', 'request_id' => $requestId];
        }
        if (!xchetosEnsureResetTable()) return ['success' => false, 'status' => 'failed', 'code' => 'audit_unavailable', 'request_id' => $requestId];

        $config = xchetosConfig();
        $rateKey = 'xchetos_diagnostic_admin_' . $adminId;
        if (function_exists('checkRateLimit') && !checkRateLimit($rateKey, (int) $config['diagnostic_max_attempts'], (int) $config['rate_window_seconds'])) {
            return xchetosImmediateResult($context, 'rate_limited', 'failed', ['request_stage' => 'rate_limit']);
        }
        if (!xchetosIsConfigured()) return xchetosImmediateResult($context, 'not_configured', 'failed', [
            'request_stage' => 'configuration', 'debug' => ['system' => xchetosSystemStatus()],
        ]);

        $logId = xchetosCreateLog($context);
        if ($logId < 1) return ['success' => false, 'status' => 'failed', 'code' => 'audit_unavailable', 'request_id' => $requestId];
        // A diagnostic must test the configured credentials, not merely reuse a
        // token left from an older successful request.
        xchetosClearCachedToken();
        $request = xchetosAuthenticatedRequest('/api/licenses?limit=1&offset=0&only_inactive=false', 'GET');
        $http = is_array($request['http'] ?? null) ? $request['http'] : [];
        if (!empty($request['auth_failed'])) {
            $result = [
                'success' => false, 'status' => 'failed', 'code' => (string) ($request['auth_code'] ?? 'login_failed'),
                'request_id' => $requestId, 'request_stage' => 'diagnostic_login',
                'provider_attempted' => !empty($http['request_sent_likely']) || (int) ($http['http_status'] ?? 0) > 0,
                'token_retry' => !empty($request['token_retry']), 'endpoint' => '/api/auth/login', 'http_method' => 'POST',
                'http_status' => (int) ($http['http_status'] ?? 0), 'transport_code' => (string) ($http['transport_code'] ?? ''),
                'curl_errno' => (int) ($http['curl_errno'] ?? 0), 'curl_error' => (string) ($http['curl_error'] ?? ''),
                'duration_ms' => (int) ($http['duration_ms'] ?? 0), 'connect_ms' => (int) ($http['connect_ms'] ?? 0),
                'primary_ip' => (string) ($http['primary_ip'] ?? ''),
                'debug' => ['system' => xchetosSystemStatus(), 'http' => $http],
            ];
            xchetosFinishLog($logId, $result);
            return $result;
        }

        $response = is_array($request['response'] ?? null) ? $request['response'] : [];
        $json = is_array($response['json'] ?? null) ? $response['json'] : [];
        $diagnosticHttpStatus = (int) ($response['http_status'] ?? 0);
        $valid = $diagnosticHttpStatus === 200 && isset($json['data']) && is_array($json['data']);
        $transportUnknown = !empty($response['transport_error']) && !xchetosTransportDefinitelyNotSent($response);
        if ($valid) {
            $diagnosticCode = 'diagnostic_success';
        } elseif ($transportUnknown) {
            $diagnosticCode = 'provider_unknown';
        } elseif (!empty($response['transport_error'])) {
            $diagnosticCode = ((string) ($response['code'] ?? '') === 'curl_missing') ? 'curl_missing' : 'provider_unavailable';
        } elseif ($diagnosticHttpStatus === 401 || $diagnosticHttpStatus === 403) {
            $diagnosticCode = 'login_failed';
        } elseif ($diagnosticHttpStatus === 429) {
            $diagnosticCode = 'provider_rate_limited';
        } else {
            $diagnosticCode = 'provider_inventory_invalid';
        }
        $result = [
            'success' => $valid,
            'status' => $valid ? 'success' : ($transportUnknown ? 'unknown' : 'failed'),
            'code' => $diagnosticCode,
            'request_id' => $requestId,
            'request_stage' => 'diagnostic_inventory',
            'provider_attempted' => true,
            'token_retry' => !empty($request['token_retry']),
            'endpoint' => '/api/licenses',
            'http_method' => 'GET',
            'http_status' => (int) ($http['http_status'] ?? 0),
            'transport_code' => (string) ($http['transport_code'] ?? ''),
            'curl_errno' => (int) ($http['curl_errno'] ?? 0),
            'curl_error' => (string) ($http['curl_error'] ?? ''),
            'duration_ms' => (int) ($http['duration_ms'] ?? 0),
            'connect_ms' => (int) ($http['connect_ms'] ?? 0),
            'primary_ip' => (string) ($http['primary_ip'] ?? ''),
            'provider_message' => xchetosProviderMessage($json),
            'debug' => [
                'system' => xchetosSystemStatus(),
                'http' => $http,
                'inventory_shape_valid' => $valid,
                'reported_total' => is_numeric($json['total'] ?? null) ? (int) $json['total'] : null,
                'returned_records' => isset($json['data']) && is_array($json['data']) ? count($json['data']) : null,
            ],
        ];
        xchetosFinishLog($logId, $result);
        return $result;
    }
}

if (!function_exists('xchetosAdminClearToken')) {
    function xchetosAdminClearToken(int $adminId): array
    {
        $requestId = xchetosRequestId();
        if ($adminId < 1 || !function_exists('isAdmin') || !isAdmin()) {
            return ['success' => false, 'status' => 'failed', 'code' => 'forbidden', 'request_id' => $requestId];
        }
        xchetosClearCachedToken();
        $context = [
            'request_id' => $requestId, 'actor_user_id' => $adminId, 'actor_role' => 'admin',
            'operation_type' => 'clear_token', 'key_source' => 'system', 'key_record_id' => 'token_cache',
            'product_name' => 'xChetos token cache',
        ];
        if (!xchetosEnsureResetTable()) return ['success' => true, 'status' => 'success', 'code' => 'token_cleared', 'request_id' => $requestId];
        return xchetosImmediateResult($context, 'token_cleared', 'success', ['request_stage' => 'token_cache']);
    }
}


if (!function_exists('xchetosGetResellerResetStatus')) {
    /**
     * Read one reseller reset result by its opaque request id. The user id and
     * actor role are part of the query, so one reseller cannot inspect another
     * reseller's request.
     */
    function xchetosGetResellerResetStatus(int $userId, string $requestId): array
    {
        global $conn;
        $requestId = strtolower(trim($requestId));
        if ($userId < 1 || preg_match('/^[a-f0-9]{32}$/D', $requestId) !== 1 || !xchetosEnsureResetTable()) {
            return ['found' => false, 'success' => false, 'status' => 'failed', 'code' => 'request_not_found', 'request_id' => $requestId];
        }

        $stmt = $conn->prepare(
            "SELECT request_id, key_hash, key_masked, product_name, key_duration_days, key_reset_limit,
                    status, result_code, request_stage, provider_attempted, provider_http_status,
                    created_at, completed_at
             FROM xchetos_hwid_reset_logs
             WHERE request_id = ? AND user_id = ? AND actor_role = 'reseller'
               AND provider_code = 'xchetos' AND operation_type = 'reset_owned'
             ORDER BY id DESC LIMIT 1"
        );
        if (!$stmt) {
            return ['found' => false, 'success' => false, 'status' => 'failed', 'code' => 'audit_unavailable', 'request_id' => $requestId];
        }
        $stmt->bind_param('si', $requestId, $userId);
        if (!$stmt->execute()) {
            $stmt->close();
            return ['found' => false, 'success' => false, 'status' => 'failed', 'code' => 'audit_unavailable', 'request_id' => $requestId];
        }
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if (!$row) {
            return ['found' => false, 'success' => false, 'status' => 'failed', 'code' => 'request_not_found', 'request_id' => $requestId];
        }

        $status = in_array((string) ($row['status'] ?? ''), ['processing', 'success', 'failed', 'unknown'], true)
            ? (string) $row['status'] : 'unknown';
        $limit = max(1, (int) ($row['key_reset_limit'] ?? 2));
        $keyHash = strtolower((string) ($row['key_hash'] ?? ''));
        $usage = preg_match('/^[a-f0-9]{64}$/D', $keyHash) === 1
            ? xchetosGetKeyResetUsage($keyHash, true, $limit)
            : ['used' => 0, 'limit' => $limit, 'remaining' => $limit, 'available' => false];
        $quota = xchetosGetResellerDailyQuota($userId);

        return [
            'found' => true,
            'success' => $status === 'success',
            'status' => $status,
            'code' => (string) (($row['result_code'] ?? '') ?: ($status === 'processing' ? 'processing' : 'provider_unknown')),
            'request_id' => (string) $row['request_id'],
            'request_stage' => (string) ($row['request_stage'] ?? ''),
            'provider_attempted' => !empty($row['provider_attempted']),
            'http_status' => max(0, (int) ($row['provider_http_status'] ?? 0)),
            'key_masked' => (string) ($row['key_masked'] ?? ''),
            'product_name' => (string) ($row['product_name'] ?? ''),
            'key_duration_days' => max(0, (int) ($row['key_duration_days'] ?? 0)),
            'key_reset_count' => max(0, (int) ($usage['used'] ?? 0)),
            'key_reset_limit' => max(1, (int) ($usage['limit'] ?? $limit)),
            'key_reset_remaining' => max(0, (int) ($usage['remaining'] ?? 0)),
            'daily_used' => max(0, (int) ($quota['used'] ?? 0)),
            'daily_limit' => max(1, (int) ($quota['limit'] ?? 1)),
            'daily_remaining' => max(0, (int) ($quota['remaining'] ?? 0)),
            'daily_reset_at' => (string) ($quota['reset_at'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'completed_at' => (string) ($row['completed_at'] ?? ''),
        ];
    }
}

if (!function_exists('xchetosGetResetHistory')) {
    function xchetosGetResetHistory(int $userId, int $limit = 20): array
    {
        global $conn;
        if ($userId < 1 || !xchetosEnsureResetTable()) return [];
        $limit = max(1, min(100, $limit));
        $stmt = $conn->prepare(
            "SELECT request_id, provider_code, key_masked, product_name, key_duration_days, key_reset_limit, status, result_code,
                    created_at, completed_at
             FROM xchetos_hwid_reset_logs
             WHERE user_id = ? AND provider_code = 'xchetos' AND operation_type = 'reset_owned'
             ORDER BY id DESC LIMIT ?"
        );
        if (!$stmt) return [];
        $stmt->bind_param('ii', $userId, $limit);
        if (!$stmt->execute()) {
            $stmt->close();
            return [];
        }
        $result = $stmt->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('xchetosGetAdminLogStats')) {
    function xchetosGetAdminLogStats(): array
    {
        global $conn;
        $defaults = [
            'total_24h' => 0,
            'success_24h' => 0,
            'failed_24h' => 0,
            'unknown_24h' => 0,
            'processing' => 0,
            'reseller_reset_calls_24h' => 0,
            'unique_keys_24h' => 0,
        ];
        if (!xchetosEnsureResetTable()) return $defaults;
        $sql = "SELECT
                    SUM(created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS total_24h,
                    SUM(status = 'success' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS success_24h,
                    SUM(status = 'failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS failed_24h,
                    SUM(status = 'unknown' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS unknown_24h,
                    SUM(status = 'processing') AS processing,
                    SUM(actor_role = 'reseller' AND operation_type = 'reset_owned'
                        AND provider_attempted = 1 AND endpoint = '/api/license/reset-hwid'
                        AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS reseller_reset_calls_24h,
                    COUNT(DISTINCT CASE WHEN actor_role = 'reseller' AND operation_type = 'reset_owned'
                        AND provider_attempted = 1 AND endpoint = '/api/license/reset-hwid'
                        AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN key_hash END) AS unique_keys_24h
                FROM xchetos_hwid_reset_logs
                WHERE provider_code = 'xchetos'";
        $result = $conn->query($sql);
        if (!$result) return $defaults;
        $row = $result->fetch_assoc() ?: [];
        $result->free();
        foreach ($defaults as $key => $value) $defaults[$key] = max(0, (int) ($row[$key] ?? 0));
        return $defaults;
    }
}

if (!function_exists('xchetosGetAdminLogs')) {
    function xchetosGetAdminLogs(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        global $conn;
        $queryStartedAt = microtime(true);
        if (!function_exists('isAdmin') || !isAdmin() || !xchetosEnsureResetTable()) {
            return ['rows' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage, 'pages' => 1];
        }
        $page = max(1, $page);
        $perPage = max(10, min(100, $perPage));
        $queryFailure = static function (string $code, int $currentPage, int $currentPerPage, int $total = 0) use ($conn): array {
            xchetosWriteSystemLog('admin_log_query_failed', [
                'query_code' => $code,
                'db_errno' => (int) $conn->errno,
                'message' => substr((string) $conn->error, 0, 500),
                'page' => $currentPage,
                'per_page' => $currentPerPage,
            ], 'error', 'admin_search');
            $total = max(0, $total);
            return [
                'rows' => [],
                'total' => $total,
                'page' => max(1, $currentPage),
                'per_page' => $currentPerPage,
                'pages' => max(1, (int) ceil($total / max(1, $currentPerPage))),
                'error' => $code,
                'search_debug' => [
                    'query_code' => $code,
                    'db_errno' => (int) $conn->errno,
                    'db_message' => substr((string) $conn->error, 0, 500),
                    'comparison_mode' => 'binary_safe_v2',
                ],
            ];
        };
        $where = ["l.provider_code = 'xchetos'"];
        $params = [];
        $types = '';

        $availableTables = [];
        $tableCheck = $conn->query(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN
             ('cgo_order_keys','cgo_orders','supplier_order_keys','supplier_orders')"
        );
        if ($tableCheck) {
            while ($tableRow = $tableCheck->fetch_assoc()) {
                $availableTables[(string) ($tableRow['TABLE_NAME'] ?? '')] = true;
            }
            $tableCheck->free();
        }
        $hasApiKeyTables = isset($availableTables['cgo_order_keys'], $availableTables['cgo_orders']);
        $hasSupplierKeyTables = isset($availableTables['supplier_order_keys'], $availableTables['supplier_orders']);

        $localJoin = "LEFT JOIN `keys` lk
                      ON l.key_source='local'
                     AND lk.id=CAST(l.key_record_id AS UNSIGNED)";
        $apiJoins = $hasApiKeyTables
            ? "LEFT JOIN cgo_order_keys ak
                   ON l.key_source IN ('api','cgo')
                  AND ak.id=CAST(l.key_record_id AS UNSIGNED)
               LEFT JOIN cgo_orders ao ON ao.id=ak.order_id"
            : '';
        $supplierJoins = $hasSupplierKeyTables
            ? "LEFT JOIN supplier_order_keys sk
                   ON l.key_source IN ('supplier','store_api')
                  AND sk.id=CAST(l.key_record_id AS UNSIGNED)
               LEFT JOIN supplier_orders so ON so.id=sk.order_id"
            : '';

        // Keep every key source in a separate selected column. Mixing TEXT
        // columns with different collations in CASE/COALESCE caused MariaDB 1267.
        $apiKeySelect = $hasApiKeyTables ? 'ak.key_code AS api_key_code' : 'NULL AS api_key_code';
        $supplierKeySelect = $hasSupplierKeyTables ? 'sk.key_code AS supplier_key_code' : 'NULL AS supplier_key_code';

        $status = (string) ($filters['status'] ?? '');
        if (in_array($status, ['processing', 'success', 'failed', 'unknown'], true)) {
            $where[] = 'l.status = ?';
            $types .= 's';
            $params[] = $status;
        }
        $operation = (string) ($filters['operation'] ?? '');
        $allowedOperations = ['reset_owned', 'reset_admin', 'diagnostic_login', 'clear_token', 'settings_update'];
        if (in_array($operation, $allowedOperations, true)) {
            $where[] = 'l.operation_type = ?';
            $types .= 's';
            $params[] = $operation;
        }
        $search = trim((string) ($filters['search'] ?? ''));
        $searchDebug = [
            'query' => '',
            'metadata_terms' => 0,
            'search_index_available' => xchetosDbTableExists('xchetos_hwid_reset_key_search'),
            'exact_hashes' => 0,
            'recovered_hashes' => 0,
            'comparison_mode' => 'binary_safe_v2',
        ];
        if ($search !== '') {
            $search = preg_replace('/[\s\x{200B}\x{FEFF}]+/u', ' ', $search) ?? $search;
            $search = substr(trim($search), 0, 255);
            $searchDebug['query'] = $search;

            $searchClauses = [];
            $metadataFields = [
                "COALESCE(l.request_id, '')",
                "COALESCE(l.key_masked, '')",
                "COALESCE(l.product_name, '')",
                "COALESCE(l.result_code, '')",
                "COALESCE(l.request_stage, '')",
                "COALESCE(l.key_record_id, '')",
                "COALESCE(l.key_source, '')",
                "COALESCE(l.owner_username, '')",
                "COALESCE(l.owner_email, '')",
                "COALESCE(l.owner_role, '')",
                "COALESCE(l.owner_source, '')",
                "COALESCE(l.product_duration, '')",
                "COALESCE(l.external_ref, '')",
                "CAST(COALESCE(l.related_transaction_id, 0) AS CHAR)",
                "CAST(COALESCE(l.related_order_id, 0) AS CHAR)",
                "COALESCE(actor.username, '')",
                "COALESCE(actor.email, '')",
                "COALESCE(target.username, '')",
                "COALESCE(target.email, '')",
            ];

            // Keep the normal search cheap and deterministic. Index rebuilding is
            // now an explicit admin action instead of decrypting thousands of rows
            // during every keypress.
            $terms = preg_split('/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY);
            if (!is_array($terms) || $terms === []) $terms = [$search];
            $terms = array_slice(array_values(array_unique($terms)), 0, 4);
            $searchDebug['metadata_terms'] = count($terms);
            $metadataGroups = [];
            foreach ($terms as $term) {
                $termClauses = [];
                $like = '%' . substr((string) $term, 0, 255) . '%';
                foreach ($metadataFields as $fieldSql) {
                    // Normalize every metadata field and the bound search term
                    // to one explicit charset/collation. This prevents errno 1267
                    // when users, logs, and legacy product tables were created
                    // under different utf8mb4 collations.
                    $termClauses[] = "CONVERT({$fieldSql} USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci";
                    $types .= 's';
                    $params[] = $like;
                }
                $metadataGroups[] = '(' . implode(' OR ', $termClauses) . ')';
            }
            if ($metadataGroups !== []) $searchClauses[] = '(' . implode(' AND ', $metadataGroups) . ')';

            // Full license-key search never needs the HMAC search-index table.
            $normalized = xchetosNormalizeLicenseKey($search);
            $exactHashes = [];
            foreach ([$normalized, strtolower($normalized), strtoupper($normalized)] as $candidate) {
                if ($candidate !== '') $exactHashes[hash('sha256', $candidate)] = true;
            }
            if ($exactHashes !== []) {
                $placeholders = implode(',', array_fill(0, count($exactHashes), 'CAST(? AS BINARY)'));
                $searchClauses[] = "CAST(l.key_hash AS BINARY) IN ({$placeholders})";
                $types .= str_repeat('s', count($exactHashes));
                foreach (array_keys($exactHashes) as $hash) $params[] = $hash;
                $searchDebug['exact_hashes'] = count($exactHashes);
            }

            // Use the fast partial-key index only when the SQL table really exists.
            if ($searchDebug['search_index_available']) {
                $canonicalSearch = xchetosCanonicalKeySearchText($search);
                if (strlen($canonicalSearch) >= 3) {
                    $exactToken = xchetosKeySearchToken('e', $canonicalSearch);
                    if ($exactToken !== '') {
                        $searchClauses[] = 'EXISTS (
                            SELECT 1
                            FROM xchetos_hwid_reset_key_search exact_index
                            WHERE CAST(exact_index.key_hash AS BINARY) = CAST(l.key_hash AS BINARY)
                              AND CAST(exact_index.token AS BINARY) = CAST(? AS BINARY)
                        )';
                        $types .= 's';
                        $params[] = $exactToken;
                    }
                }

                $partialTokens = [];
                foreach (xchetosKeySearchFragments($search) as $fragment) {
                    foreach (xchetosKeySearchQueryTokens($fragment, 6) as $token) $partialTokens[$token] = true;
                }
                if ($partialTokens !== []) {
                    $partialTokenValues = array_keys($partialTokens);
                    $placeholders = implode(',', array_fill(0, count($partialTokenValues), 'CAST(? AS BINARY)'));
                    $searchClauses[] = "CAST(l.key_hash AS BINARY) IN (
                        SELECT CAST(partial_index.key_hash AS BINARY)
                        FROM xchetos_hwid_reset_key_search partial_index
                        WHERE CAST(partial_index.token AS BINARY) IN ({$placeholders})
                        GROUP BY CAST(partial_index.key_hash AS BINARY)
                        HAVING COUNT(DISTINCT CAST(partial_index.token AS BINARY)) = ?
                    )";
                    $types .= str_repeat('s', count($partialTokenValues)) . 'i';
                    foreach ($partialTokenValues as $token) $params[] = $token;
                    $params[] = count($partialTokenValues);
                }
            }

            // Bounded server-side decrypt fallback for partial legacy keys. This
            // scans at most 500 recent distinct keys and never blocks page loads by
            // trying to rebuild the entire historical index.
            $recoveredHashes = xchetosFindEncryptedKeyHashesForSearch($search, 120);
            if ($recoveredHashes !== []) {
                $placeholders = implode(',', array_fill(0, count($recoveredHashes), 'CAST(? AS BINARY)'));
                $searchClauses[] = "CAST(l.key_hash AS BINARY) IN ({$placeholders})";
                $types .= str_repeat('s', count($recoveredHashes));
                foreach ($recoveredHashes as $hash) $params[] = $hash;
                $searchDebug['recovered_hashes'] = count($recoveredHashes);
            }

            if ($searchClauses !== []) $where[] = '(' . implode(' OR ', $searchClauses) . ')';
        }
        $whereSql = ' WHERE ' . implode(' AND ', $where);

        $identityJoins = "
            LEFT JOIN users actor ON actor.id = l.user_id
            LEFT JOIN users target ON target.id = l.target_user_id";
        $keyLookupJoins = "
            {$localJoin}
            {$apiJoins}
            {$supplierJoins}";

        // Counting/filtering uses the encrypted audit hash/search index only.
        // Key inventory joins are reserved for rendering legacy rows, so a
        // schema difference in an old key table cannot break the search count.
        $countSql = "SELECT COUNT(*) FROM xchetos_hwid_reset_logs l {$identityJoins} {$whereSql}";
        $countStmt = $conn->prepare($countSql);
        if (!$countStmt) {
            return $queryFailure('count_prepare_failed', $page, $perPage);
        }
        if ($params && !xchetosBindParams($countStmt, $types, $params)) {
            $countStmt->close();
            return $queryFailure('count_bind_failed', $page, $perPage);
        }
        if (!$countStmt->execute()) {
            $countStmt->close();
            return $queryFailure('count_execute_failed', $page, $perPage);
        }
        $countStmt->bind_result($total);
        $countStmt->fetch();
        $countStmt->close();
        $total = max(0, (int) $total);
        $pages = max(1, (int) ceil($total / $perPage));
        if ($page > $pages) $page = $pages;
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT l.*,
                       actor.username AS actor_username,
                       target.username AS target_username,
                       lk.key_code AS local_key_code,
                       {$apiKeySelect},
                       {$supplierKeySelect},
                       COALESCE(usage_count.used_count, 0) AS key_reset_count
                FROM xchetos_hwid_reset_logs l
                {$identityJoins}
                {$keyLookupJoins}
                LEFT JOIN (
                    SELECT key_hash, COUNT(*) AS used_count
                    FROM xchetos_hwid_reset_logs
                    WHERE provider_code = 'xchetos'
                      AND operation_type = 'reset_owned'
                      AND endpoint = '/api/license/reset-hwid'
                      AND status IN ('success','unknown')
                    GROUP BY key_hash
                ) usage_count ON CAST(usage_count.key_hash AS BINARY) = CAST(l.key_hash AS BINARY)
                {$whereSql}
                ORDER BY l.id DESC LIMIT ? OFFSET ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return $queryFailure('rows_prepare_failed', $page, $perPage, $total);
        }
        $rowTypes = $types . 'ii';
        $rowParams = array_merge($params, [$perPage, $offset]);
        if (!xchetosBindParams($stmt, $rowTypes, $rowParams)) {
            $stmt->close();
            return $queryFailure('rows_bind_failed', $page, $perPage, $total);
        }
        if (!$stmt->execute()) {
            $stmt->close();
            return $queryFailure('rows_execute_failed', $page, $perPage, $total);
        }
        $result = $stmt->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        foreach ($rows as &$row) {
            $keySource = (string) ($row['key_source'] ?? '');
            $canonicalSource = function_exists('commerceNormalizeSource')
                ? commerceNormalizeSource($keySource, (string) ($row['key_record_id'] ?? ''))
                : $keySource;
            if ($canonicalSource === 'cgo') {
                $resolved = trim((string) ($row['api_key_code'] ?? ''));
            } elseif ($canonicalSource === 'supplier') {
                $resolved = trim((string) ($row['supplier_key_code'] ?? ''));
            } else {
                $resolved = trim((string) ($row['local_key_code'] ?? ''));
            }
            $storedHash = strtolower((string) ($row['key_hash'] ?? ''));
            $encryptedFullKey = xchetosDecryptAuditKey((string) ($row['key_ciphertext'] ?? ''), $storedHash);
            $databaseFullKey = $resolved !== '' && preg_match('/^[a-f0-9]{64}$/D', $storedHash) === 1
                && hash_equals($storedHash, hash('sha256', $resolved))
                ? $resolved
                : '';
            if ($encryptedFullKey !== '') {
                $row['full_key'] = $encryptedFullKey;
                $row['full_key_source'] = 'encrypted_log';
            } elseif ($databaseFullKey !== '') {
                $row['full_key'] = $databaseFullKey;
                $row['full_key_source'] = 'shop_database';
                // Preserve a key resolved from an old local/API order so later
                // viewing no longer depends on that order table remaining intact.
                xchetosBackfillEncryptedAuditKey($databaseFullKey, $storedHash);
            } else {
                $row['full_key'] = '';
                $row['full_key_source'] = 'unavailable';
            }
            unset($row['local_key_code'], $row['api_key_code'], $row['supplier_key_code'], $row['key_ciphertext']);
            $policyKey = $row['full_key'] !== '' ? $row['full_key'] : $resolved;
            $fallbackPolicy = $policyKey !== '' ? xchetosKeyResetPolicy($policyKey) : ['days' => 0, 'limit' => 2];
            $row['key_duration_days'] = max(0, (int) ($row['key_duration_days'] ?? $fallbackPolicy['days'] ?? 0));
            $row['key_reset_limit'] = max(1, (int) ($row['key_reset_limit'] ?? $fallbackPolicy['limit'] ?? 2));
            $row['key_reset_count'] = max(0, (int) ($row['key_reset_count'] ?? 0));

            // New audit rows already contain an immutable commerce snapshot.
            // Resolve only legacy/incomplete rows instead of issuing up to one
            // multi-join query per visible log on every page load.
            $hasOwnerSnapshot = trim((string) ($row['owner_username'] ?? '')) !== ''
                || trim((string) ($row['owner_email'] ?? '')) !== ''
                || (int) ($row['owner_user_id'] ?? 0) > 0;
            $needsCommerceContext = trim((string) ($row['owner_source'] ?? '')) === ''
                || !$hasOwnerSnapshot
                || trim((string) ($row['product_name'] ?? '')) === '';
            if ($canonicalSource === 'local' && (int) ($row['related_transaction_id'] ?? 0) < 1) {
                $needsCommerceContext = true;
            } elseif (in_array($canonicalSource, ['cgo', 'supplier'], true)
                && (int) ($row['related_order_id'] ?? 0) < 1) {
                $needsCommerceContext = true;
            }

            $commerceContext = $needsCommerceContext && function_exists('commerceResolveKeyContext')
                ? commerceResolveKeyContext([
                    'key_source' => $keySource,
                    'key_record_id' => (string) ($row['key_record_id'] ?? ''),
                    'full_key' => $row['full_key'],
                    'key_hash' => $storedHash,
                ])
                : commerceContextEmpty();
            if (!empty($commerceContext['resolved'])) {
                $storedContextMissing = trim((string) ($row['owner_username'] ?? '')) === ''
                    || trim((string) ($row['owner_source'] ?? '')) === ''
                    || ((int) ($row['related_transaction_id'] ?? 0) < 1 && (int) ($commerceContext['transaction_id'] ?? 0) > 0)
                    || ((int) ($row['related_order_id'] ?? 0) < 1 && (int) ($commerceContext['order_id'] ?? 0) > 0)
                    || (trim((string) ($row['product_duration'] ?? '')) === '' && trim((string) ($commerceContext['product_duration'] ?? '')) !== '');
                if ($storedContextMissing) xchetosBackfillCommerceContext((int) ($row['id'] ?? 0), $commerceContext);
                foreach ([
                    'owner_kind' => 'owner_kind',
                    'owner_user_id' => 'owner_user_id',
                    'owner_username' => 'owner_username',
                    'owner_email' => 'owner_email',
                    'owner_role' => 'owner_role',
                    'source' => 'owner_source',
                    'transaction_id' => 'related_transaction_id',
                    'order_id' => 'related_order_id',
                    'purchased_at' => 'purchase_created_at',
                    'product_duration' => 'product_duration',
                    'external_ref' => 'external_ref',
                ] as $contextKey => $rowKey) {
                    $current = $row[$rowKey] ?? null;
                    $resolvedValue = $commerceContext[$contextKey] ?? null;
                    if (($current === null || $current === '' || (is_numeric($current) && (int) $current < 1))
                        && $resolvedValue !== null && $resolvedValue !== '' && (!is_numeric($resolvedValue) || (int) $resolvedValue > 0)) {
                        $row[$rowKey] = $resolvedValue;
                    }
                }
                if (trim((string) ($row['product_name'] ?? '')) === '' && trim((string) ($commerceContext['product_name'] ?? '')) !== '') {
                    $row['product_name'] = (string) $commerceContext['product_name'];
                }
            }
            $row['owner_display'] = trim((string) ($row['owner_username'] ?? ''));
            if ($row['owner_display'] === '') $row['owner_display'] = trim((string) ($row['owner_email'] ?? ''));
            if ($row['owner_display'] === '' && (int) ($row['owner_user_id'] ?? 0) > 0) $row['owner_display'] = 'user#' . (int) $row['owner_user_id'];
            $row['product_display_name'] = function_exists('commerceComposeProductLabel')
                ? commerceComposeProductLabel((string) ($row['product_name'] ?? ''), (string) ($row['product_duration'] ?? ''))
                : (string) ($row['product_name'] ?? '');
        }
        unset($row);

        if ($search !== '') {
            xchetosWriteSystemLog('admin_log_search', [
                'query_sha256' => hash('sha256', $search),
                'query_length' => strlen($search),
                'result_count' => $total,
                'search_index_available' => !empty($searchDebug['search_index_available']),
                'metadata_terms' => (int) ($searchDebug['metadata_terms'] ?? 0),
                'exact_hashes' => (int) ($searchDebug['exact_hashes'] ?? 0),
                'recovered_hashes' => (int) ($searchDebug['recovered_hashes'] ?? 0),
                'duration_ms' => (int) round((microtime(true) - $queryStartedAt) * 1000),
            ], 'info', 'admin_search', '', (int) ($_SESSION['user_id'] ?? 0), 'admin');
        }
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => $pages, 'search_debug' => $searchDebug];
    }
}
