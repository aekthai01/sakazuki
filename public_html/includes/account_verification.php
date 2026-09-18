<?php
/**
 * Account verification and manual cross-site access blocking.
 *
 * Scope is intentionally narrow:
 * - six-digit email OTP using the existing Gmail SMTP transport
 * - server-issued device cookie with best-effort device model labelling
 * - manual email / IP / device block lists shared by SAK-010 and ONL-005
 *
 * No behavioural/risk scoring is performed here.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/account_recovery.php';
require_once __DIR__ . '/shared_security.php';

if (!defined('ACCOUNT_VERIFICATION_OTP_TTL')) define('ACCOUNT_VERIFICATION_OTP_TTL', 600);
if (!defined('ACCOUNT_VERIFICATION_OTP_MAX_ATTEMPTS')) define('ACCOUNT_VERIFICATION_OTP_MAX_ATTEMPTS', 5);
if (!defined('ACCOUNT_VERIFICATION_RESEND_SECONDS')) define('ACCOUNT_VERIFICATION_RESEND_SECONDS', 60);
if (!defined('ACCOUNT_DEVICE_COOKIE')) define('ACCOUNT_DEVICE_COOKIE', 'sakazuki_shared_device_id_v2');
if (!defined('ACCOUNT_DEVICE_COOKIE_DAYS')) define('ACCOUNT_DEVICE_COOKIE_DAYS', 365);
if (!defined('ACCOUNT_SHARED_SECURITY_CACHE_SECONDS')) define('ACCOUNT_SHARED_SECURITY_CACHE_SECONDS', 30);

if (!headers_sent()) {
    // Chromium can return an Android model on subsequent requests. Browsers that
    // do not support this simply ignore the hint; iOS normally exposes only
    // "iPhone"/"iPad", not an exact hardware model.
    header('Accept-CH: Sec-CH-UA-Model, Sec-CH-UA-Platform, Sec-CH-UA-Mobile', false);
}

function accountVerificationSchemaMigrationsAllowed(): bool
{
    if (function_exists('sakazukiSchemaMigrationsAllowed')) return (bool) sakazukiSchemaMigrationsAllowed();
    // Older ONL005 snapshots do not have the central migration gate. Keep DDL
    // out of browser traffic there as well and permit it only from the CLI worker.
    return PHP_SAPI === 'cli';
}

function accountVerificationTableReady(string $table, bool $refresh = false): bool
{
    global $conn;
    if (function_exists('sakazukiTableReady')) return (bool) sakazukiTableReady($table, $refresh);
    if (!isset($conn) || !($conn instanceof mysqli) || preg_match('/^[a-z0-9_]+$/iD', $table) !== 1) return false;
    static $cache = [];
    if (!$refresh && array_key_exists($table, $cache)) return $cache[$table];
    $stmt = $conn->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    if (!$stmt) return $cache[$table] = false;
    $stmt->bind_param('s', $table);
    if (!$stmt->execute()) { $stmt->close(); return $cache[$table] = false; }
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    return $cache[$table] = ((int) $count > 0);
}

function accountVerificationTableColumnsReady(string $table, array $columns, bool $refresh = false): bool
{
    global $conn;
    if (function_exists('sakazukiTableColumnsReady')) return (bool) sakazukiTableColumnsReady($table, $columns, $refresh);
    if (!accountVerificationTableReady($table, $refresh) || $columns === []) return $columns === [];
    $normalized = [];
    foreach ($columns as $column) {
        $column = is_scalar($column) ? trim((string) $column) : '';
        if (preg_match('/^[a-z0-9_]+$/iD', $column) !== 1) return false;
        $normalized[$column] = true;
    }
    $stmt = $conn->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    if (!$stmt) return false;
    $stmt->bind_param('s', $table);
    if (!$stmt->execute()) { $stmt->close(); return false; }
    $result = $stmt->get_result();
    $found = [];
    while ($row = $result ? $result->fetch_assoc() : null) {
        if (!$row) break;
        $found[(string) ($row['COLUMN_NAME'] ?? '')] = true;
    }
    $stmt->close();
    foreach (array_keys($normalized) as $column) if (!isset($found[$column])) return false;
    return true;
}

function accountVerificationRuntimeSchemaReady(bool $refresh = false): bool
{
    return accountVerificationTableColumnsReady('users', ['email_verified_at'], $refresh)
        && accountVerificationTableReady('auth_contact_otps', $refresh)
        && accountVerificationTableColumnsReady('auth_devices', ['device_label', 'browser_label'], $refresh)
        && sharedSecurityCentralSchemaReady($refresh);
}

function accountVerificationEnsureColumn(string $table, string $column, string $definition): bool
{
    global $conn;
    if (preg_match('/^[a-z0-9_]+$/iD', $table) !== 1 || preg_match('/^[a-z0-9_]+$/iD', $column) !== 1) return false;
    if (accountVerificationTableColumnsReady($table, [$column], true)) return true;
    if (!accountVerificationSchemaMigrationsAllowed()) return false;
    try {
        if ($conn->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}")) return true;
    } catch (Throwable $e) {
        // A concurrent migration may have won the race; verify below.
    }
    return accountVerificationTableColumnsReady($table, [$column], true);
}

function accountVerificationClearRetiredProviderSettings(): bool
{
    global $conn;
    static $done = null;
    if ($done !== null) return $done;
    if (!isset($conn) || !($conn instanceof mysqli)) return false;

    $retired = [
        'account_line_required' => '0',
        'line_login_channel_id' => '',
        'line_login_channel_secret_enc' => '',
        'account_phone_otp_required' => '0',
        'phone_otp_twilio_account_sid' => '',
        'phone_otp_twilio_auth_token_enc' => '',
        'phone_otp_twilio_messaging_service_sid' => '',
        'phone_otp_twilio_from' => '',
    ];
    $stmt = $conn->prepare('UPDATE settings SET setting_value=? WHERE setting_key=?');
    if (!$stmt) return false;
    $done = true;
    foreach ($retired as $key => $value) {
        $stmt->bind_param('ss', $value, $key);
        if (!$stmt->execute()) $done = false;
        if (isset($GLOBALS['__settings_request_cache']) && is_array($GLOBALS['__settings_request_cache'])) {
            unset($GLOBALS['__settings_request_cache'][$key]);
        }
    }
    $stmt->close();
    if (!$done) error_log('Retired verification provider settings could not be fully cleared');
    return $done;
}

function accountVerificationEnsureSchema(): bool
{
    global $conn;
    if (accountVerificationRuntimeSchemaReady()) {
        return accountVerificationClearRetiredProviderSettings();
    }
    if (!isset($conn) || !($conn instanceof mysqli) || !accountVerificationSchemaMigrationsAllowed()) return false;
    if (function_exists('accountRecoveryEnsureSchema') && !accountRecoveryEnsureSchema()) return false;

    $ok = true;
    $statements = [
        "CREATE TABLE IF NOT EXISTS auth_contact_otps (
            user_id BIGINT UNSIGNED NOT NULL,
            channel ENUM('email') NOT NULL,
            destination_hash CHAR(64) NOT NULL,
            code_hash CHAR(64) NOT NULL,
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            expires_at DATETIME NOT NULL,
            sent_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, channel),
            KEY idx_contact_otp_expiry (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS auth_devices (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            device_hash CHAR(64) NOT NULL,
            user_agent_hash CHAR(64) NOT NULL DEFAULT '',
            device_label VARCHAR(160) NOT NULL DEFAULT '',
            browser_label VARCHAR(96) NOT NULL DEFAULT '',
            first_ip VARCHAR(45) NOT NULL DEFAULT '',
            last_ip VARCHAR(45) NOT NULL DEFAULT '',
            first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_auth_device_user_hash (user_id, device_hash),
            KEY idx_auth_device_hash (device_hash),
            KEY idx_auth_device_last_seen (last_seen_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS shared_security_blocks (
            subject_type VARCHAR(16) NOT NULL,
            subject_hash CHAR(64) NOT NULL,
            subject_display VARCHAR(190) NOT NULL DEFAULT '',
            reason VARCHAR(255) NOT NULL DEFAULT '',
            blocked_by_site_id VARCHAR(64) NOT NULL DEFAULT '',
            blocked_by_user_id BIGINT UNSIGNED NULL DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (subject_type, subject_hash),
            KEY idx_shared_security_block_updated (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS shared_security_devices (
            site_id VARCHAR(64) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            username VARCHAR(190) NOT NULL DEFAULT '',
            role VARCHAR(32) NOT NULL DEFAULT '',
            device_hash CHAR(64) NOT NULL,
            device_label VARCHAR(160) NOT NULL DEFAULT '',
            browser_label VARCHAR(96) NOT NULL DEFAULT '',
            first_ip VARCHAR(45) NOT NULL DEFAULT '',
            last_ip VARCHAR(45) NOT NULL DEFAULT '',
            first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (site_id, user_id, device_hash),
            KEY idx_shared_device_hash (device_hash),
            KEY idx_shared_device_ip (last_ip),
            KEY idx_shared_device_seen (last_seen_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    foreach ($statements as $sql) {
        try {
            if (!$conn->query($sql)) $ok = false;
        } catch (Throwable $e) {
            $ok = false;
            error_log('Account verification schema migration failed: ' . $e->getMessage());
        }
    }
    $ok = accountVerificationEnsureColumn('auth_devices', 'device_label', "VARCHAR(160) NOT NULL DEFAULT ''") && $ok;
    $ok = accountVerificationEnsureColumn('auth_devices', 'browser_label', "VARCHAR(96) NOT NULL DEFAULT ''") && $ok;
    $ok = accountVerificationEnsureColumn('shared_security_devices', 'browser_label', "VARCHAR(96) NOT NULL DEFAULT ''") && $ok;
    $ok = accountVerificationClearRetiredProviderSettings() && $ok;

    return $ok && accountVerificationRuntimeSchemaReady(true);
}

function accountVerificationEmailRequired(): bool
{
    return (string) getSetting('account_email_otp_required', '1') === '1';
}


function accountVerificationSecretKey(): ?string
{
    if (!function_exists('accountRecoveryGetEncryptionKey')) return null;
    $key = accountRecoveryGetEncryptionKey(true);
    return is_string($key) && $key !== '' ? $key : null;
}

function accountVerificationDestinationHash(string $channel, string $destination): string
{
    $key = accountVerificationSecretKey();
    if ($key === '' || $key === null) return '';
    return hash_hmac('sha256', strtolower($channel) . "\0" . $destination, $key);
}

function accountVerificationCodeHash(int $userId, string $channel, string $destinationHash, string $code): string
{
    $key = accountVerificationSecretKey();
    if ($key === '' || $key === null) return '';
    return hash_hmac('sha256', $userId . "\0" . $channel . "\0" . $destinationHash . "\0" . $code, $key);
}

function accountVerificationGenerateOtp(): string
{
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function accountVerificationDeviceCookieDomain(): string
{
    $host = defined('APP_SITE_DOMAIN') ? (string) APP_SITE_DOMAIN : (string) ($_SERVER['HTTP_HOST'] ?? '');
    $host = strtolower(trim(preg_replace('/:\\d+$/', '', $host) ?? ''));
    if (in_array($host, ['sakazuki.spwz.online', 'online.spwz.online'], true)) {
        // The value is a pseudonymous device token, not an authentication credential.
        // Sharing it at the parent domain lets SAK010 and ONL005 recognise/block the
        // same browser without using invasive browser fingerprinting.
        return '.spwz.online';
    }
    return '';
}

function accountVerificationDeviceCookieOptions(int $expires): array
{
    $domain = accountVerificationDeviceCookieDomain();
    return [
        'expires' => $expires,
        'path' => '/',
        'domain' => $domain,
        'secure' => $domain !== '' || requestIsHttps(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function accountVerificationCurrentDeviceToken(bool $issueIfMissing = true): string
{
    $raw = isset($_COOKIE[ACCOUNT_DEVICE_COOKIE]) && is_string($_COOKIE[ACCOUNT_DEVICE_COOKIE])
        ? strtolower(trim($_COOKIE[ACCOUNT_DEVICE_COOKIE]))
        : '';
    if (preg_match('/^[a-f0-9]{64}$/D', $raw) === 1) return $raw;
    if (!$issueIfMissing || headers_sent()) return '';
    try {
        $raw = bin2hex(random_bytes(32));
    } catch (Throwable $e) {
        error_log('Device token generation failed');
        return '';
    }
    $expires = time() + (ACCOUNT_DEVICE_COOKIE_DAYS * 86400);
    if (!setcookie(ACCOUNT_DEVICE_COOKIE, $raw, accountVerificationDeviceCookieOptions($expires))) return '';
    $_COOKIE[ACCOUNT_DEVICE_COOKIE] = $raw;
    return $raw;
}

function accountVerificationCurrentDeviceHash(bool $issueIfMissing = true): string
{
    $token = accountVerificationCurrentDeviceToken($issueIfMissing);
    return $token !== '' ? hash('sha256', $token) : '';
}

function accountVerificationCleanDeviceLabel(string $value): string
{
    $value = trim($value, " \t\n\r\0\x0B\"'");
    $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);
    if (!is_string($value)) return '';
    $value = preg_replace('/\s+/u', ' ', $value);
    if (!is_string($value)) return '';
    return function_exists('mb_substr') ? mb_substr($value, 0, 160, 'UTF-8') : substr($value, 0, 160);
}

function accountVerificationBrowserLabel(): string
{
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $brands = accountVerificationCleanDeviceLabel((string) ($_SERVER['HTTP_SEC_CH_UA'] ?? ''));

    $patterns = [
        ['/(?:EdgA|EdgiOS|Edg)\/([0-9.]+)/i', 'Edge'],
        ['/SamsungBrowser\/([0-9.]+)/i', 'Samsung Internet'],
        ['/OPR\/([0-9.]+)/i', 'Opera'],
        ['/CriOS\/([0-9.]+)/i', 'Chrome'],
        ['/Chrome\/([0-9.]+)/i', 'Chrome'],
        ['/FxiOS\/([0-9.]+)/i', 'Firefox'],
        ['/Firefox\/([0-9.]+)/i', 'Firefox'],
        ['/Version\/([0-9.]+).*Safari\//i', 'Safari'],
    ];
    foreach ($patterns as [$pattern, $name]) {
        if (preg_match($pattern, $ua, $m) === 1) {
            $version = preg_replace('/[^0-9.].*$/', '', (string) ($m[1] ?? ''));
            $major = explode('.', (string) $version)[0] ?? '';
            return $major !== '' ? $name . ' ' . $major : $name;
        }
    }

    if ($brands !== '') {
        if (stripos($brands, 'Google Chrome') !== false) return 'Chrome';
        if (stripos($brands, 'Microsoft Edge') !== false) return 'Edge';
        if (stripos($brands, 'Chromium') !== false) return 'Chromium';
    }
    return 'ไม่ทราบเบราว์เซอร์';
}

function accountVerificationDeviceLabel(): string
{
    $model = accountVerificationCleanDeviceLabel((string) ($_SERVER['HTTP_SEC_CH_UA_MODEL'] ?? ''));
    $platform = accountVerificationCleanDeviceLabel((string) ($_SERVER['HTTP_SEC_CH_UA_PLATFORM'] ?? ''));
    if ($model !== '') return $platform !== '' ? $model . ' (' . $platform . ')' : $model;

    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (stripos($ua, 'Android') !== false) {
        $inside = '';
        if (preg_match('/\(([^)]*Android[^)]*)\)/i', $ua, $m) === 1) $inside = (string) $m[1];
        if ($inside !== '') {
            $parts = array_reverse(array_map('trim', explode(';', $inside)));
            foreach ($parts as $part) {
                $part = preg_replace('/\s+Build\/.*$/i', '', $part);
                $part = accountVerificationCleanDeviceLabel(is_string($part) ? $part : '');
                if ($part === '' || stripos($part, 'Android') === 0 || preg_match('/^[a-z]{2}(?:[-_][A-Z]{2})?$/', $part) === 1 || strtolower($part) === 'wv') continue;
                return $part . ' (Android)';
            }
        }
        return 'Android';
    }
    if (preg_match('/\biPhone\b/i', $ua) === 1) return 'iPhone';
    if (preg_match('/\biPad\b/i', $ua) === 1) return 'iPad';
    if (stripos($ua, 'Windows NT') !== false) return 'Windows';
    if (stripos($ua, 'Macintosh') !== false) return 'Mac';
    if (stripos($ua, 'CrOS') !== false) return 'ChromeOS';
    if (stripos($ua, 'Linux') !== false) return 'Linux';
    return 'อุปกรณ์ไม่ทราบรุ่น';
}

function accountVerificationTouchDevice(int $userId, bool $force = false): void
{
    global $conn;
    if ($userId < 1 || !accountVerificationRuntimeSchemaReady()) return;
    $now = time();
    if (!$force && isset($_SESSION['account_device_touch_at']) && ($now - (int) $_SESSION['account_device_touch_at']) < 300) return;

    $deviceHash = accountVerificationCurrentDeviceHash(true);
    if ($deviceHash === '') return;
    $uaHash = hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $label = accountVerificationDeviceLabel();
    $browserLabel = accountVerificationBrowserLabel();
    $ip = getClientIp();

    $stmt = $conn->prepare(
        "INSERT INTO auth_devices (user_id,device_hash,user_agent_hash,device_label,browser_label,first_ip,last_ip,first_seen_at,last_seen_at)
         VALUES (?,?,?,?,?,?,?,NOW(),NOW())
         ON DUPLICATE KEY UPDATE user_agent_hash=VALUES(user_agent_hash),device_label=VALUES(device_label),browser_label=VALUES(browser_label),last_ip=VALUES(last_ip),last_seen_at=NOW()"
    );
    if ($stmt) {
        $stmt->bind_param('issssss', $userId, $deviceHash, $uaHash, $label, $browserLabel, $ip, $ip);
        $stmt->execute();
        $stmt->close();
    }

    $username = (string) ($_SESSION['username'] ?? '');
    $role = (string) ($_SESSION['role'] ?? '');
    $sync = sharedSecurityTouchDevice($userId, $username, $role, $deviceHash, $label, $browserLabel, $ip);
    if (empty($sync['success']) && empty($sync['unavailable'])) {
        error_log('Shared device observation sync failed');
    }
    if (session_status() === PHP_SESSION_ACTIVE) $_SESSION['account_device_touch_at'] = $now;
}

function accountVerificationGetLocalAccountDevices(int $userId, int $limit = 20): array
{
    global $conn;
    $userId = max(0, $userId);
    $limit = max(1, min(50, $limit));
    if ($userId < 1 || !accountVerificationRuntimeSchemaReady()) return [];

    $stmt = $conn->prepare(
        'SELECT device_hash,user_agent_hash,device_label,browser_label,first_ip,last_ip,first_seen_at,last_seen_at
         FROM auth_devices WHERE user_id=? ORDER BY last_seen_at DESC LIMIT ?'
    );
    if (!$stmt) return [];
    $stmt->bind_param('ii', $userId, $limit);
    if (!$stmt->execute()) { $stmt->close(); return []; }
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

/**
 * Read remembered-device metadata without exposing selectors or token hashes.
 * This is admin-only diagnostic context; it does not change remember-device behaviour.
 */
function accountVerificationGetRememberedDeviceSummary(int $userId, int $limit = 10): array
{
    global $conn;
    $userId = max(0, $userId);
    $limit = max(1, min(20, $limit));
    if ($userId < 1 || !function_exists('sakazukiTableColumnsReady')) return [];
    if (!sakazukiTableColumnsReady('auth_remember_tokens', ['user_id','expires_at','created_at','last_used_at','last_ip','user_agent_hash'])) return [];

    $stmt = $conn->prepare(
        'SELECT created_at,last_used_at,expires_at,last_ip,user_agent_hash
         FROM auth_remember_tokens
         WHERE user_id=? AND expires_at > NOW()
         ORDER BY COALESCE(last_used_at,created_at) DESC
         LIMIT ?'
    );
    if (!$stmt) return [];
    $stmt->bind_param('ii', $userId, $limit);
    if (!$stmt->execute()) { $stmt->close(); return []; }
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    foreach ($rows as &$row) {
        $expires = strtotime((string) ($row['expires_at'] ?? ''));
        $row['remaining_days'] = $expires === false ? null : max(0, (int) ceil(($expires - time()) / 86400));
        $row['user_agent_hash_prefix'] = substr((string) ($row['user_agent_hash'] ?? ''), 0, 16);
        unset($row['user_agent_hash']);
    }
    unset($row);
    return $rows;
}

/**
 * Search local user/reseller accounts for the admin Security page.
 * The resulting block action still writes Email/Device/IP subjects to the
 * shared cross-site block list.
 */
function accountVerificationAdminSearchAccounts(string $query, int $limit = 20): array
{
    global $conn;
    $query = trim($query);
    $limit = max(1, min(50, $limit));
    if ($query === '') return [];
    if (function_exists('mb_substr')) $query = mb_substr($query, 0, 120, 'UTF-8');
    else $query = substr($query, 0, 120);

    $escaped = strtr($query, ['=' => '==', '%' => '=%', '_' => '=_']);
    $like = '%' . $escaped . '%';
    $exactId = ctype_digit($query) ? ltrim($query, '0') : '';
    if ($exactId === '') $exactId = $query === '0' ? '0' : '-1';

    $stmt = $conn->prepare(
        "SELECT u.id,u.username,u.email,u.role,u.status,u.email_verified_at,u.created_at,
                (SELECT COUNT(*) FROM auth_devices d WHERE d.user_id=u.id) AS known_devices,
                (SELECT COUNT(DISTINCT NULLIF(d2.last_ip,'')) FROM auth_devices d2 WHERE d2.user_id=u.id) AS known_ips,
                (SELECT MAX(d3.last_seen_at) FROM auth_devices d3 WHERE d3.user_id=u.id) AS last_seen_at
         FROM users u
         WHERE u.role IN ('user','reseller')
           AND (u.username LIKE ? ESCAPE '=' OR u.email LIKE ? ESCAPE '=' OR CAST(u.id AS CHAR)=?)
         ORDER BY (CAST(u.id AS CHAR)=?) DESC, u.created_at DESC, u.id DESC
         LIMIT ?"
    );
    if (!$stmt) return [];
    $stmt->bind_param('ssssi', $like, $like, $exactId, $exactId, $limit);
    if (!$stmt->execute()) { $stmt->close(); return []; }
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

function accountVerificationAdminAccountSecurityDetails(int $userId, string $expectedRole): array
{
    global $conn;
    $userId = max(0, $userId);
    $expectedRole = strtolower(trim($expectedRole));
    if ($userId < 1 || !in_array($expectedRole, ['user', 'reseller'], true)) {
        return ['success' => false, 'code' => 'invalid_account'];
    }

    $stmt = $conn->prepare('SELECT id,username,email,role,status,email_verified_at,created_at FROM users WHERE id=? AND role=? LIMIT 1');
    if (!$stmt) return ['success' => false, 'code' => 'query_failed'];
    $stmt->bind_param('is', $userId, $expectedRole);
    if (!$stmt->execute()) { $stmt->close(); return ['success' => false, 'code' => 'query_failed']; }
    $result = $stmt->get_result();
    $account = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    if (!$account) return ['success' => false, 'code' => 'account_not_found'];

    $siteId = sharedSecurityCurrentSiteId();
    $shared = $siteId !== '' ? sharedSecurityAccountDevices($siteId, $userId, 20) : ['success' => false];
    $devices = !empty($shared['success']) && is_array($shared['devices'] ?? null)
        ? $shared['devices']
        : accountVerificationGetLocalAccountDevices($userId, 20);

    $emailState = accountVerificationEmailBlockState((string) ($account['email'] ?? ''));
    return [
        'success' => true,
        'account' => $account,
        'site_id' => $siteId,
        'devices' => $devices,
        'remembered_devices' => accountVerificationGetRememberedDeviceSummary($userId, 10),
        'email_blocked' => !empty($emailState['blocked']),
        'shared_available' => !empty($shared['success']),
    ];
}

function accountVerificationSharedBlockState(string $ip, string $deviceHash = '', string $email = ''): array
{
    $subjects = [];
    $ipSubject = sharedSecuritySubject('ip', $ip);
    if ($ipSubject) $subjects[] = ['type' => 'ip', 'hash' => $ipSubject['hash']];
    if (sharedSecurityNormalizeHash($deviceHash) !== '') $subjects[] = ['type' => 'device', 'hash' => $deviceHash];
    $emailSubject = sharedSecuritySubject('email', $email);
    if ($emailSubject) $subjects[] = ['type' => 'email', 'hash' => $emailSubject['hash']];

    $cacheKey = hash('sha256', json_encode($subjects, JSON_UNESCAPED_SLASHES) ?: '');
    if (session_status() === PHP_SESSION_ACTIVE) {
        $cache = $_SESSION['shared_security_block_cache'] ?? null;
        if (is_array($cache)
            && (string) ($cache['key'] ?? '') === $cacheKey
            && (time() - (int) ($cache['at'] ?? 0)) < ACCOUNT_SHARED_SECURITY_CACHE_SECONDS
            && isset($cache['result']) && is_array($cache['result'])) {
            return $cache['result'];
        }
    }

    $result = sharedSecurityCheckSubjects($subjects);
    if (empty($result['success'])) return ['blocked' => false, 'code' => '', 'unavailable' => true];

    foreach ((array) ($result['blocked'] ?? []) as $row) {
        if (!is_array($row)) continue;
        $type = strtolower((string) ($row['subject_type'] ?? ''));
        if (in_array($type, ['email', 'device', 'ip'], true)) {
            $final = ['blocked' => true, 'code' => $type . '_blocked', 'unavailable' => false];
            if (session_status() === PHP_SESSION_ACTIVE) $_SESSION['shared_security_block_cache'] = ['key' => $cacheKey, 'at' => time(), 'result' => $final];
            return $final;
        }
    }
    $final = ['blocked' => false, 'code' => '', 'unavailable' => false];
    if (session_status() === PHP_SESSION_ACTIVE) $_SESSION['shared_security_block_cache'] = ['key' => $cacheKey, 'at' => time(), 'result' => $final];
    return $final;
}

function accountVerificationPreAuthBlock(string $email = ''): array
{
    if (!accountVerificationRuntimeSchemaReady()) return ['blocked' => false, 'code' => ''];
    $shared = accountVerificationSharedBlockState(getClientIp(), accountVerificationCurrentDeviceHash(true), $email);
    return !empty($shared['blocked']) ? ['blocked' => true, 'code' => (string) $shared['code']] : ['blocked' => false, 'code' => ''];
}

function accountVerificationEmailBlockState(string $email): array
{
    if (!accountVerificationRuntimeSchemaReady()) return ['blocked' => false, 'code' => ''];
    $subject = sharedSecuritySubject('email', $email);
    if (!$subject) return ['blocked' => false, 'code' => ''];
    $result = sharedSecurityCheckSubjects([['type' => 'email', 'hash' => $subject['hash']]]);
    if (empty($result['success'])) return ['blocked' => false, 'code' => '', 'unavailable' => true];
    return !empty($result['blocked'])
        ? ['blocked' => true, 'code' => 'email_blocked', 'unavailable' => false]
        : ['blocked' => false, 'code' => '', 'unavailable' => false];
}

function accountVerificationAccessBlock(int $userId, string $role = ''): array
{
    global $conn;
    if ($role === 'admin' || $userId < 1 || !accountVerificationRuntimeSchemaReady()) return ['blocked' => false, 'code' => ''];

    $email = '';
    $stmt = $conn->prepare('SELECT email FROM users WHERE id=? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        if ($stmt->execute()) {
            $stmt->bind_result($dbEmail);
            if ($stmt->fetch() && is_string($dbEmail)) $email = $dbEmail;
        }
        $stmt->close();
    }

    $shared = accountVerificationSharedBlockState(getClientIp(), accountVerificationCurrentDeviceHash(true), $email);
    return !empty($shared['blocked']) ? ['blocked' => true, 'code' => (string) $shared['code']] : ['blocked' => false, 'code' => ''];
}

function accountVerificationApiAccessBlock(int $userId, string $ip): array
{
    global $conn;
    if ($userId < 1 || !accountVerificationRuntimeSchemaReady()) return ['blocked' => false, 'code' => ''];
    $ip = trim($ip);
    if (!filter_var($ip, FILTER_VALIDATE_IP)) $ip = '';
    $email = '';
    $stmt = $conn->prepare('SELECT email FROM users WHERE id=? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        if ($stmt->execute()) {
            $stmt->bind_result($dbEmail);
            if ($stmt->fetch() && is_string($dbEmail)) $email = $dbEmail;
        }
        $stmt->close();
    }
    $shared = accountVerificationSharedBlockState($ip, '', $email);
    return !empty($shared['blocked']) ? ['blocked' => true, 'code' => (string) $shared['code']] : ['blocked' => false, 'code' => ''];
}

function accountVerificationStateForUserId(int $userId): array
{
    global $conn;
    $state = [
        'schema_ready' => false,
        'email_required' => accountVerificationEmailRequired(),
        'email_verified' => false,
        'email' => '',
        'role' => '',
    ];
    if ($userId < 1 || !accountVerificationRuntimeSchemaReady()) return $state;
    $state['schema_ready'] = true;

    $stmt = $conn->prepare('SELECT email,email_verified_at,role FROM users WHERE id=? LIMIT 1');
    if (!$stmt) return $state;
    $stmt->bind_param('i', $userId);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        if ($row) {
            $state['email'] = (string) ($row['email'] ?? '');
            $state['email_verified'] = !empty($row['email_verified_at']);
            $state['role'] = (string) ($row['role'] ?? '');
        }
    }
    $stmt->close();
    if ($state['role'] === 'admin') {
        $state['email_verified'] = true;
    }
    return $state;
}

function accountVerificationIsComplete(int $userId): bool
{
    $state = accountVerificationStateForUserId($userId);
    if (!$state['schema_ready']) return false;
    if ($state['role'] === 'admin') return true;
    if ($state['email_required'] && !$state['email_verified']) return false;
    return true;
}

function accountVerificationRequireComplete(bool $json = false): void
{
    if (!isset($_SESSION['user_id']) || !is_numeric($_SESSION['user_id'])) return;
    $userId = (int) $_SESSION['user_id'];
    $role = (string) ($_SESSION['role'] ?? '');
    if ($role === 'admin') return;
    if (accountVerificationIsComplete($userId)) return;

    if ($json) {
        $payload = [
            'success' => false,
            'code' => 'account_verification_required',
            'message' => 'กรุณายืนยันอีเมลด้วยรหัส OTP 6 หลักให้เรียบร้อยก่อนทำรายการ',
        ];
        if (function_exists('jsonResponse')) jsonResponse($payload, 403);
        if (!headers_sent()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }
    $base = function_exists('authAppUrl') ? authAppUrl('verify_account.php') : '/verify_account.php';
    header('Location: ' . $base, true, 302);
    exit();
}

function accountVerificationOtpRow(int $userId, string $channel): ?array
{
    global $conn;
    if ($channel !== 'email' || !accountVerificationRuntimeSchemaReady()) return null;
    $stmt = $conn->prepare('SELECT destination_hash,code_hash,attempts,expires_at,sent_at FROM auth_contact_otps WHERE user_id=? AND channel=? LIMIT 1');
    if (!$stmt) return null;
    $stmt->bind_param('is', $userId, $channel);
    if (!$stmt->execute()) { $stmt->close(); return null; }
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

function accountVerificationOtpCooldown(int $userId, string $channel): int
{
    if ($channel !== 'email') return 0;
    $row = accountVerificationOtpRow($userId, $channel);
    if (!$row || empty($row['sent_at'])) return 0;
    $sent = strtotime((string) $row['sent_at']);
    if ($sent === false) return 0;
    return max(0, ACCOUNT_VERIFICATION_RESEND_SECONDS - (time() - $sent));
}

function accountVerificationStoreOtp(int $userId, string $channel, string $destinationHash, string $code): bool
{
    global $conn;
    if ($channel !== 'email') return false;
    $codeHash = accountVerificationCodeHash($userId, $channel, $destinationHash, $code);
    if ($codeHash === '') return false;
    $expiresAt = date('Y-m-d H:i:s', time() + ACCOUNT_VERIFICATION_OTP_TTL);
    $stmt = $conn->prepare(
        "INSERT INTO auth_contact_otps (user_id,channel,destination_hash,code_hash,attempts,expires_at,sent_at,created_at)
         VALUES (?,?,?,?,0,?,NOW(),NOW())
         ON DUPLICATE KEY UPDATE destination_hash=VALUES(destination_hash),code_hash=VALUES(code_hash),attempts=0,expires_at=VALUES(expires_at),sent_at=NOW()"
    );
    if (!$stmt) return false;
    $stmt->bind_param('issss', $userId, $channel, $destinationHash, $codeHash, $expiresAt);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function accountVerificationDeleteOtp(int $userId, string $channel): void
{
    global $conn;
    if ($channel !== 'email' || !accountVerificationRuntimeSchemaReady()) return;
    $stmt = $conn->prepare('DELETE FROM auth_contact_otps WHERE user_id=? AND channel=?');
    if (!$stmt) return;
    $stmt->bind_param('is', $userId, $channel);
    $stmt->execute();
    $stmt->close();
}

function accountVerificationRequestEmailOtp(int $userId): array
{
    global $conn;
    if ($userId < 1 || !accountVerificationRuntimeSchemaReady()) return ['success' => false, 'code' => 'unavailable', 'message' => 'ระบบยืนยันตัวตนยังไม่พร้อม'];
    if (!checkRateLimit('verify_email_send_' . $userId, 5, 3600)) return ['success' => false, 'code' => 'rate_limited', 'message' => 'ขอรหัสบ่อยเกินไป กรุณารอสักครู่'];
    $cooldown = accountVerificationOtpCooldown($userId, 'email');
    if ($cooldown > 0) return ['success' => false, 'code' => 'cooldown', 'retry_after' => $cooldown, 'message' => 'กรุณารอก่อนขอรหัสใหม่'];

    $stmt = $conn->prepare('SELECT email,email_verified_at FROM users WHERE id=? LIMIT 1');
    if (!$stmt) return ['success' => false, 'code' => 'unavailable', 'message' => 'ระบบยืนยันตัวตนยังไม่พร้อม'];
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    if (!$user) return ['success' => false, 'code' => 'not_found', 'message' => 'ไม่พบบัญชี'];
    if (!empty($user['email_verified_at'])) return ['success' => true, 'code' => 'already_verified', 'message' => 'อีเมลยืนยันแล้ว'];

    $email = accountRecoveryNormalizeGmail((string) ($user['email'] ?? ''));
    if ($email === '') return ['success' => false, 'code' => 'invalid_email', 'message' => 'อีเมลของบัญชีไม่ถูกต้อง'];
    $emailBlock = accountVerificationEmailBlockState($email);
    if (!empty($emailBlock['blocked'])) return ['success' => false, 'code' => 'email_blocked', 'message' => 'อีเมลนี้ถูกระงับการใช้งาน'];
    $destinationHash = accountVerificationDestinationHash('email', $email);
    $code = accountVerificationGenerateOtp();
    if ($destinationHash === '' || !accountVerificationStoreOtp($userId, 'email', $destinationHash, $code)) {
        return ['success' => false, 'code' => 'unavailable', 'message' => 'ไม่สามารถสร้างรหัสยืนยันได้'];
    }

    $brand = trim((string) (getStoreBranding()['title_text'] ?? 'SAKAZUKI')) ?: 'SAKAZUKI';
    $safeBrand = htmlspecialchars($brand, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
    $subject = 'รหัสยืนยัน ' . $code . ' - ' . $brand;
    $html = '<div style="font-family:Arial,sans-serif;max-width:480px;margin:auto;color:#111827">'
        . '<h2 style="margin:0 0 16px">' . $safeBrand . '</h2>'
        . '<p>รหัสยืนยันอีเมลของคุณ</p>'
        . '<div style="font-size:32px;font-weight:700;letter-spacing:8px;margin:18px 0">' . $safeCode . '</div>'
        . '<p>รหัสนี้มีอายุ 10 นาที หากคุณไม่ได้ขอรหัสนี้ ให้ละเว้นข้อความนี้</p>'
        . '</div>';
    $text = "{$brand}\nรหัสยืนยันอีเมล: {$code}\nรหัสมีอายุ 10 นาที\nหากคุณไม่ได้ขอรหัสนี้ ให้ละเว้นข้อความนี้";

    $sent = accountRecoverySendMail($email, $subject, $html, $text, true, [
        'event_type' => 'email_verification_otp',
        'user_id' => $userId,
    ]);
    if (empty($sent['success'])) {
        accountVerificationDeleteOtp($userId, 'email');
        return ['success' => false, 'code' => 'delivery_failed', 'message' => 'ส่ง OTP ทางอีเมลไม่สำเร็จ กรุณาตรวจการตั้งค่า Gmail SMTP'];
    }
    logHistory($userId, 'email_otp_sent', 'Email verification OTP sent');
    return ['success' => true, 'code' => 'sent', 'message' => 'ส่งรหัส 6 หลักไปยังอีเมลแล้ว'];
}

function accountVerificationVerifyEmailOtp(int $userId, $rawCode): array
{
    global $conn;
    $code = is_scalar($rawCode) ? trim((string) $rawCode) : '';
    if (preg_match('/^\d{6}$/D', $code) !== 1) return ['success' => false, 'code' => 'invalid_code', 'message' => 'กรุณากรอกรหัส OTP 6 หลัก'];
    if (!checkRateLimit('verify_email_code_' . $userId, 10, 900)) return ['success' => false, 'code' => 'rate_limited', 'message' => 'ลองรหัสบ่อยเกินไป กรุณารอสักครู่'];
    $row = accountVerificationOtpRow($userId, 'email');
    if (!$row) return ['success' => false, 'code' => 'missing', 'message' => 'กรุณาขอรหัส OTP ใหม่'];
    if ((int) ($row['attempts'] ?? 0) >= ACCOUNT_VERIFICATION_OTP_MAX_ATTEMPTS || strtotime((string) $row['expires_at']) < time()) {
        accountVerificationDeleteOtp($userId, 'email');
        return ['success' => false, 'code' => 'expired', 'message' => 'รหัสหมดอายุหรือถูกลองเกินกำหนด กรุณาขอรหัสใหม่'];
    }
    $expected = accountVerificationCodeHash($userId, 'email', (string) $row['destination_hash'], $code);
    if ($expected === '' || !hash_equals((string) $row['code_hash'], $expected)) {
        $stmt = $conn->prepare("UPDATE auth_contact_otps SET attempts=attempts+1 WHERE user_id=? AND channel='email'");
        if ($stmt) { $stmt->bind_param('i', $userId); $stmt->execute(); $stmt->close(); }
        return ['success' => false, 'code' => 'invalid_code', 'message' => 'รหัส OTP ไม่ถูกต้อง'];
    }
    $stmt = $conn->prepare('UPDATE users SET email_verified_at=NOW() WHERE id=?');
    if (!$stmt) return ['success' => false, 'code' => 'unavailable', 'message' => 'ยืนยันอีเมลไม่สำเร็จ'];
    $stmt->bind_param('i', $userId);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) return ['success' => false, 'code' => 'unavailable', 'message' => 'ยืนยันอีเมลไม่สำเร็จ'];
    accountVerificationDeleteOtp($userId, 'email');
    clearRateLimit('verify_email_code_' . $userId);
    logHistory($userId, 'email_verified', 'Email verified by six-digit OTP');
    return ['success' => true, 'code' => 'verified', 'message' => 'ยืนยันอีเมลเรียบร้อยแล้ว'];
}

function accountVerificationBlockEmail(string $email, string $reason, int $adminId): array
{
    $email = sharedSecurityNormalizeEmail($email);
    if ($email === '' || !accountVerificationRuntimeSchemaReady()) {
        return ['success' => false, 'message' => 'อีเมลไม่ถูกต้องหรือระบบยังไม่พร้อม'];
    }
    $shared = sharedSecurityBlock('email', $email, $reason, $adminId);
    return !empty($shared['success'])
        ? ['success' => true, 'message' => 'บล็อคอีเมลทั้ง SAK010 และ ONL005 แล้ว']
        : ['success' => false, 'message' => 'บล็อคอีเมลไม่สำเร็จ เพราะ Shared Security Hub ไม่พร้อม'];
}

function accountVerificationBlockIp(string $ip, string $reason, int $adminId): array
{
    $ip = trim($ip);
    if (!filter_var($ip, FILTER_VALIDATE_IP) || !accountVerificationRuntimeSchemaReady()) {
        return ['success' => false, 'message' => 'IP ไม่ถูกต้องหรือระบบยังไม่พร้อม'];
    }
    $shared = sharedSecurityBlock('ip', $ip, $reason, $adminId);
    return !empty($shared['success'])
        ? ['success' => true, 'message' => 'บล็อค IP ทั้ง SAK010 และ ONL005 แล้ว']
        : ['success' => false, 'message' => 'บล็อค IP ไม่สำเร็จ เพราะ Shared Security Hub ไม่พร้อม'];
}

function accountVerificationBlockDevice(string $deviceHash, string $reason, int $adminId): array
{
    $deviceHash = sharedSecurityNormalizeHash($deviceHash);
    if ($deviceHash === '' || !accountVerificationRuntimeSchemaReady()) {
        return ['success' => false, 'message' => 'Device ID ไม่ถูกต้องหรือระบบยังไม่พร้อม'];
    }
    $shared = sharedSecurityBlock('device', $deviceHash, $reason, $adminId);
    return !empty($shared['success'])
        ? ['success' => true, 'message' => 'บล็อคอุปกรณ์ทั้ง SAK010 และ ONL005 แล้ว']
        : ['success' => false, 'message' => 'บล็อคอุปกรณ์ไม่สำเร็จ เพราะ Shared Security Hub ไม่พร้อม'];
}


function accountVerificationBlockKnownUserSignals(int $userId, string $reason, int $adminId): array
{
    global $conn;
    if ($userId < 1 || !accountVerificationRuntimeSchemaReady()) {
        return ['success' => false, 'message' => 'ระบบบล็อคร่วมยังไม่พร้อม'];
    }

    $stmt = $conn->prepare("SELECT email,username,role FROM users WHERE id=? AND role IN ('user','reseller') LIMIT 1");
    if (!$stmt) return ['success' => false, 'message' => 'ไม่พบบัญชีที่ต้องการบล็อค'];
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    if (!$user) return ['success' => false, 'message' => 'ไม่พบบัญชีที่ต้องการบล็อค'];

    $reason = trim($reason) !== '' ? $reason : 'บล็อคพร้อมบัญชีโดยผู้ดูแลระบบ';
    $subjects = [];
    $email = sharedSecurityNormalizeEmail((string) ($user['email'] ?? ''));
    if ($email !== '') $subjects['email:' . $email] = ['type' => 'email', 'value' => $email];

    $devices = $conn->prepare('SELECT device_hash,last_ip FROM auth_devices WHERE user_id=? ORDER BY last_seen_at DESC LIMIT 50');
    if ($devices) {
        $devices->bind_param('i', $userId);
        if ($devices->execute()) {
            $rows = $devices->get_result();
            while ($row = $rows ? $rows->fetch_assoc() : null) {
                if (!$row) break;
                $deviceHash = sharedSecurityNormalizeHash($row['device_hash'] ?? '');
                if ($deviceHash !== '') $subjects['device:' . $deviceHash] = ['type' => 'device', 'value' => $deviceHash];
                $ip = trim((string) ($row['last_ip'] ?? ''));
                if (filter_var($ip, FILTER_VALIDATE_IP)) $subjects['ip:' . $ip] = ['type' => 'ip', 'value' => $ip];
            }
        }
        $devices->close();
    }

    if (function_exists('sakazukiTableColumnsReady')
        && sakazukiTableColumnsReady('auth_remember_tokens', ['user_id','expires_at','last_ip','last_used_at'])) {
        $remembered = $conn->prepare(
            "SELECT last_ip, MAX(last_used_at) AS latest_used_at
             FROM auth_remember_tokens
             WHERE user_id=? AND expires_at > NOW() AND last_ip <> ''
             GROUP BY last_ip
             ORDER BY latest_used_at DESC LIMIT 20"
        );
        if ($remembered) {
            $remembered->bind_param('i', $userId);
            if ($remembered->execute()) {
                $rows = $remembered->get_result();
                while ($row = $rows ? $rows->fetch_assoc() : null) {
                    if (!$row) break;
                    $ip = trim((string) ($row['last_ip'] ?? ''));
                    if (filter_var($ip, FILTER_VALIDATE_IP)) $subjects['ip:' . $ip] = ['type' => 'ip', 'value' => $ip];
                }
            }
            $remembered->close();
        }
    }

    $blockedCounts = ['email' => 0, 'device' => 0, 'ip' => 0];
    $failed = [];
    foreach ($subjects as $subject) {
        $type = (string) $subject['type'];
        $result = sharedSecurityBlock($type, $subject['value'], $reason, $adminId);
        if (!empty($result['success'])) $blockedCounts[$type]++;
        else $failed[] = $type;
    }

    if ($failed !== []) {
        return [
            'success' => false,
            'message' => 'บัญชีถูกแบนแล้ว แต่บล็อคร่วมบางรายการไม่สำเร็จ: ' . implode(', ', array_values(array_unique($failed))),
            'counts' => $blockedCounts,
        ];
    }

    return [
        'success' => true,
        'message' => 'บล็อคอีเมล อุปกรณ์ และ IP ที่รู้จักไว้ทั้ง SAK010 และ ONL005 แล้ว',
        'counts' => $blockedCounts,
    ];
}

/**
 * Remove the shared blocks currently associated with one local account.
 * This mirrors accountVerificationBlockKnownUserSignals().
 */
function accountVerificationUnblockKnownUserSignals(int $userId): array
{
    global $conn;
    if ($userId < 1 || !accountVerificationRuntimeSchemaReady()) {
        return ['success' => false, 'message' => 'ระบบบล็อคร่วมยังไม่พร้อม'];
    }

    $stmt = $conn->prepare("SELECT email,username,role FROM users WHERE id=? AND role IN ('user','reseller') LIMIT 1");
    if (!$stmt) return ['success' => false, 'message' => 'ไม่พบบัญชีที่ต้องการปลดบล็อค'];
    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) { $stmt->close(); return ['success' => false, 'message' => 'ไม่พบบัญชีที่ต้องการปลดบล็อค']; }
    $result = $stmt->get_result();
    $user = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    if (!$user) return ['success' => false, 'message' => 'ไม่พบบัญชีที่ต้องการปลดบล็อค'];

    $subjects = [];
    $email = sharedSecurityNormalizeEmail((string) ($user['email'] ?? ''));
    if ($email !== '') $subjects['email:' . $email] = ['type' => 'email', 'value' => $email];

    $devices = $conn->prepare('SELECT device_hash,last_ip FROM auth_devices WHERE user_id=? ORDER BY last_seen_at DESC LIMIT 50');
    if ($devices) {
        $devices->bind_param('i', $userId);
        if ($devices->execute()) {
            $rows = $devices->get_result();
            while ($row = $rows ? $rows->fetch_assoc() : null) {
                if (!$row) break;
                $deviceHash = sharedSecurityNormalizeHash($row['device_hash'] ?? '');
                if ($deviceHash !== '') $subjects['device:' . $deviceHash] = ['type' => 'device', 'value' => $deviceHash];
                $ip = trim((string) ($row['last_ip'] ?? ''));
                if (filter_var($ip, FILTER_VALIDATE_IP)) $subjects['ip:' . $ip] = ['type' => 'ip', 'value' => $ip];
            }
        }
        $devices->close();
    }

    if (function_exists('sakazukiTableColumnsReady')
        && sakazukiTableColumnsReady('auth_remember_tokens', ['user_id','expires_at','last_ip','last_used_at'])) {
        $remembered = $conn->prepare(
            "SELECT last_ip, MAX(last_used_at) AS latest_used_at
             FROM auth_remember_tokens
             WHERE user_id=? AND expires_at > NOW() AND last_ip <> ''
             GROUP BY last_ip
             ORDER BY latest_used_at DESC LIMIT 20"
        );
        if ($remembered) {
            $remembered->bind_param('i', $userId);
            if ($remembered->execute()) {
                $rows = $remembered->get_result();
                while ($row = $rows ? $rows->fetch_assoc() : null) {
                    if (!$row) break;
                    $ip = trim((string) ($row['last_ip'] ?? ''));
                    if (filter_var($ip, FILTER_VALIDATE_IP)) $subjects['ip:' . $ip] = ['type' => 'ip', 'value' => $ip];
                }
            }
            $remembered->close();
        }
    }

    $counts = ['email' => 0, 'device' => 0, 'ip' => 0];
    $failed = [];
    foreach ($subjects as $subject) {
        $type = (string) $subject['type'];
        $unblocked = sharedSecurityUnblock($type, $subject['value']);
        if (!empty($unblocked['success'])) $counts[$type]++;
        else $failed[] = $type;
    }

    if ($failed !== []) {
        return [
            'success' => false,
            'message' => 'ปลดบัญชีแล้ว แต่รายการ Shared Security บางส่วนปลดไม่สำเร็จ: ' . implode(', ', array_values(array_unique($failed))),
            'counts' => $counts,
        ];
    }

    return [
        'success' => true,
        'message' => 'ปลด Email อุปกรณ์ และ IP ที่รู้จักของบัญชีนี้จาก Shared Security แล้ว',
        'counts' => $counts,
    ];
}

function accountVerificationUnblockEmail(string $email): bool
{
    return !empty(sharedSecurityUnblock('email', $email)['success']);
}

function accountVerificationUnblockIp(string $ip): bool
{
    return !empty(sharedSecurityUnblock('ip', $ip)['success']);
}

function accountVerificationUnblockDevice(string $deviceHash): bool
{
    return !empty(sharedSecurityUnblock('device', $deviceHash)['success']);
}
