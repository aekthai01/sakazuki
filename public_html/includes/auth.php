<?php
/**
 * Authentication and Authorization
 *
 * Responsibilities kept in this file:
 * - secure PHP sessions
 * - password login and authorization
 * - optional remembered-device login (7/14/30 days)
 * - logout and remembered-token revocation
 *
 * The remembered-device cookie never stores a username, password, user id, or
 * role. It contains only a random selector and validator. The database stores
 * only a SHA-256 hash of the validator.
 */

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/csrf.php';

/**
 * Store API calls are server-to-server and should not create browser sessions
 * or emit remember/session cookies. The reseller dashboard still uses the
 * normal authenticated session path.
 */
if (!function_exists('authIsStatelessStoreApiRequest')) {
    function authIsStatelessStoreApiRequest(): bool
    {
        $scriptPath = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? ''));
        if (preg_match('#/(?:api/store/v1|reseller/api_store)\.php$#i', $scriptPath) !== 1) return false;

        $xApiKey = trim((string) ($_SERVER['HTTP_X_API_KEY'] ?? ''));
        $authorization = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
        $hasCredential = $xApiKey !== '' || preg_match('/^Bearer\s+\S+/i', $authorization) === 1;
        if (!$hasCredential) return false;

        $method = strtoupper(trim((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')));
        if ($method === 'POST') return true; // Order requests carry action in JSON.

        $action = strtolower(trim((string) ($_GET['action'] ?? '')));
        return in_array($action, ['products', 'balance', 'diagnostic', 'inventory', 'order_status'], true);
    }
}

if (session_status() === PHP_SESSION_NONE && !authIsStatelessStoreApiRequest()) {
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/account_recovery.php';
require_once __DIR__ . '/account_verification.php';
require_once __DIR__ . '/key_history_cleanup.php';

if (!defined('AUTH_IDLE_TIMEOUT')) define('AUTH_IDLE_TIMEOUT', 3600);
if (!defined('AUTH_ABSOLUTE_TIMEOUT_ADMIN')) define('AUTH_ABSOLUTE_TIMEOUT_ADMIN', 28800);
if (!defined('AUTH_ABSOLUTE_TIMEOUT_USER')) define('AUTH_ABSOLUTE_TIMEOUT_USER', 43200);
if (!defined('AUTH_SESSION_RENEW_INTERVAL')) define('AUTH_SESSION_RENEW_INTERVAL', 1800);
if (!defined('AUTH_REMEMBER_COOKIE')) define('AUTH_REMEMBER_COOKIE', 'sakazuki_remember_device');
if (!defined('AUTH_REMEMBER_GRACE_SECONDS')) define('AUTH_REMEMBER_GRACE_SECONDS', 120);

/** Build a canonical absolute URL for authentication redirects. */
function authAppUrl(string $path = ''): string
{
    $path = '/' . ltrim($path, '/');
    $base = function_exists('normalizeCanonicalBaseUrl')
        ? normalizeCanonicalBaseUrl(getSetting('site_base_url', ''))
        : '';
    return $base !== '' ? $base . $path : $path;
}

function authRedirect(string $path): void
{
    header('Location: ' . authAppUrl($path), true, 302);
    exit();
}

/** Return true only for a structurally valid authenticated identity. */
function isLoggedIn(): bool
{
    return isset($_SESSION['user_id'])
        && is_numeric($_SESSION['user_id'])
        && (int) $_SESSION['user_id'] > 0;
}

function isAdmin(): bool
{
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function isReseller(): bool
{
    return isset($_SESSION['role']) && $_SESSION['role'] === 'reseller';
}

function isUser(): bool
{
    return isset($_SESSION['role']) && $_SESSION['role'] === 'user';
}

/** Cache a user row for the duration of this request only. */
function authLoadUser(int $userId, bool $refresh = false)
{
    static $cache = [];
    if ($userId < 1) return null;
    if (!$refresh && array_key_exists($userId, $cache)) return $cache[$userId];
    $cache[$userId] = getUserById($userId);
    return $cache[$userId];
}

function authRoleIsValid($role): bool
{
    return is_string($role) && in_array($role, ['admin', 'reseller', 'user'], true);
}

/**
 * Password/role/status fingerprint used only to invalidate remembered-device
 * tokens after a password, role, or account-status change.
 */
function authCredentialFingerprint(array $user): string
{
    return hash('sha256',
        (string) ($user['password'] ?? '') . "\0" .
        (string) ($user['role'] ?? '') . "\0" .
        (string) ($user['status'] ?? '')
    );
}

function authPasswordFingerprint(array $user): string
{
    return hash('sha256', (string) ($user['password'] ?? ''));
}

/**
 * Preserve remembered devices across a transparent password-hash upgrade.
 * This does not run for an actual password change; those flows keep their
 * existing token-revocation/fingerprint invalidation behavior.
 */
function authRefreshRememberCredentialFingerprintAfterRehash(
    int $userId,
    string $oldFingerprint,
    string $newFingerprint
): void {
    global $conn;
    if ($userId < 1 || $oldFingerprint === '' || $newFingerprint === '' || hash_equals($oldFingerprint, $newFingerprint)) {
        return;
    }
    if (!isset($conn) || !($conn instanceof mysqli)) return;

    // A password-only login must not depend on the remember-device table.
    // Check for the table without creating/migrating it, and fail open if this
    // best-effort preservation step cannot run.
    $tableCheck = $conn->query(
        "SELECT 1
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'auth_remember_tokens'
         LIMIT 1"
    );
    if (!$tableCheck) {
        error_log('Remember-device rehash preservation check failed.');
        return;
    }
    $tableExists = $tableCheck->num_rows > 0;
    $tableCheck->free();
    if (!$tableExists) return;

    $stmt = $conn->prepare(
        'UPDATE auth_remember_tokens
         SET credential_fingerprint = ?
         WHERE user_id = ? AND credential_fingerprint = ?'
    );
    if (!$stmt) {
        error_log('Remember-device rehash preservation prepare failed.');
        return;
    }
    $stmt->bind_param('sis', $newFingerprint, $userId, $oldFingerprint);
    if (!$stmt->execute()) {
        error_log('Remember-device rehash preservation update failed.');
    }
    $stmt->close();
}

function authNormalizeRememberDays($days, string $role = ''): int
{
    $days = is_numeric($days) ? (int) $days : 0;
    if (!in_array($days, [7, 14, 30], true)) return 0;
    // Administrator cookies are intentionally shorter because their account can
    // change prices, users, and settings. Normal users/resellers keep the chosen duration.
    if ($role === 'admin') return 7;
    return $days;
}

function authCookieOptions(int $expires): array
{
    return [
        'expires' => $expires,
        'path' => '/',
        'domain' => '',
        'secure' => requestIsHttps(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function authSetRememberCookie(string $value, int $expires): bool
{
    if (headers_sent()) {
        error_log('Remember-device cookie could not be set because headers were already sent.');
        return false;
    }
    return setcookie(AUTH_REMEMBER_COOKIE, $value, authCookieOptions($expires));
}

function authClearRememberCookie(): void
{
    if (!headers_sent()) {
        setcookie(AUTH_REMEMBER_COOKIE, '', authCookieOptions(time() - 42000));
    }
    unset($_COOKIE[AUTH_REMEMBER_COOKIE]);
}

function authParseRememberCookie($raw): ?array
{
    if (!is_string($raw) || strlen($raw) !== 97) return null;
    if (!preg_match('/\A([a-f0-9]{32})\.([a-f0-9]{64})\z/D', $raw, $matches)) return null;
    return ['selector' => $matches[1], 'validator' => $matches[2]];
}

/** Return null when the schema check itself cannot be completed. */
function authRememberColumnExists(string $column): ?bool
{
    global $conn;
    if (!isset($conn) || !($conn instanceof mysqli)) return null;

    $check = $conn->prepare(
        "SELECT 1
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'auth_remember_tokens'
           AND COLUMN_NAME = ?
         LIMIT 1"
    );
    if (!$check) return null;
    $check->bind_param('s', $column);
    if (!$check->execute()) {
        $check->close();
        return null;
    }
    $check->store_result();
    $exists = $check->num_rows > 0;
    $check->close();
    return $exists;
}

/** Create the token table lazily, so ordinary password login never depends on it. */
function ensureAuthRememberTokenSchema(): bool
{
    global $conn;
    static $state = null;
    if ($state !== null) return $state;
    if (!isset($conn) || !($conn instanceof mysqli)) return $state = false;

    $sql = "CREATE TABLE IF NOT EXISTS auth_remember_tokens (
        selector CHAR(32) NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        token_hash CHAR(64) NOT NULL,
        previous_token_hash CHAR(64) NULL DEFAULT NULL,
        previous_valid_until DATETIME NULL DEFAULT NULL,
        credential_fingerprint CHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL,
        last_used_at DATETIME NULL DEFAULT NULL,
        user_agent_hash CHAR(64) NOT NULL,
        last_ip VARCHAR(45) NOT NULL DEFAULT '',
        PRIMARY KEY (selector),
        KEY idx_auth_remember_user (user_id),
        KEY idx_auth_remember_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    if (!$conn->query($sql)) {
        error_log('Remember-device schema initialization failed.');
        return $state = false;
    }

    // Older deployments may already have the table but not the two columns used
    // by token-rotation grace handling. CREATE TABLE IF NOT EXISTS does not
    // upgrade an existing table, so reconcile these columns explicitly using
    // INFORMATION_SCHEMA for broad MySQL/MariaDB compatibility.
    $requiredColumns = [
        'previous_token_hash' => 'CHAR(64) NULL DEFAULT NULL AFTER token_hash',
        'previous_valid_until' => 'DATETIME NULL DEFAULT NULL AFTER previous_token_hash',
    ];
    foreach ($requiredColumns as $column => $definition) {
        $exists = authRememberColumnExists($column);
        if ($exists === null) {
            error_log('Remember-device schema verification failed.');
            return $state = false;
        }
        if ($exists) continue;

        // Column names/definitions come only from the hard-coded allowlist above.
        if (!$conn->query("ALTER TABLE auth_remember_tokens ADD COLUMN {$column} {$definition}")) {
            // A concurrent login may have completed the same migration between
            // our check and ALTER. Re-check before treating that race as failure.
            if (authRememberColumnExists($column) !== true) {
                error_log('Remember-device schema migration failed.');
                return $state = false;
            }
        }
    }

    $state = true;

    // Cleanup is non-critical. A cleanup failure must never block a normal login.
    if (!$conn->query('DELETE FROM auth_remember_tokens WHERE expires_at < NOW()')) {
        error_log('Remember-device cleanup failed.');
    }
    return true;
}

function authDeleteRememberSelector(string $selector): void
{
    global $conn;
    if ($selector === '' || !ensureAuthRememberTokenSchema()) return;
    $stmt = $conn->prepare('DELETE FROM auth_remember_tokens WHERE selector = ?');
    if (!$stmt) return;
    $stmt->bind_param('s', $selector);
    $stmt->execute();
    $stmt->close();
}

function revokeRememberTokensForUser(int $userId): void
{
    global $conn;
    if ($userId < 1 || !ensureAuthRememberTokenSchema()) return;
    $stmt = $conn->prepare('DELETE FROM auth_remember_tokens WHERE user_id = ?');
    if (!$stmt) return;
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();
}

/** Revoke only the browser's current remembered-device token. */
function forgetCurrentRememberedDevice(): void
{
    $parsed = authParseRememberCookie($_COOKIE[AUTH_REMEMBER_COOKIE] ?? '');
    $selector = $parsed['selector'] ?? (string) ($_SESSION['remember_selector'] ?? '');
    if ($selector !== '') authDeleteRememberSelector($selector);
    authClearRememberCookie();
    unset($_SESSION['remember_selector'], $_SESSION['remember_expires_at'], $_SESSION['remember_days']);
}

/** Set session identity after password or remembered-device authentication. */
function authStartUserSession(array $user, string $source = 'password'): void
{
    regenerateSession();
    // Rotate an anonymous CSRF token after an explicit password login. During
    // transparent remembered-device renewal, keep the existing token so a form
    // submitted after an idle timeout does not fail for no useful reason.
    if ($source === 'password') {
        unset($_SESSION['csrf_token'], $_SESSION['csrf_token_time']);
    }
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['username'] = (string) $user['username'];
    $_SESSION['role'] = (string) $user['role'];
    $_SESSION['balance'] = $user['balance'];
    $_SESSION['login_time'] = time();
    $_SESSION['last_activity'] = time();
    $_SESSION['auth_regenerated_at'] = time();
    $_SESSION['login_ip'] = getClientIp();
    $_SESSION['auth_source'] = $source;
    $_SESSION['auth_password_fingerprint'] = authPasswordFingerprint($user);
    unset($_SESSION['auth_failure_reason']);
    $GLOBALS['auth_current_user'] = $user;
    accountVerificationTouchDevice((int) $user['id'], true);
}

function authIssueRememberToken(array $user, int $requestedDays): bool
{
    global $conn;
    $days = authNormalizeRememberDays($requestedDays, (string) ($user['role'] ?? ''));
    if ($days < 1 || !ensureAuthRememberTokenSchema()) return false;

    // Replace only this browser's old token. Other remembered devices remain active.
    // Keep the old token valid until the new cookie has actually been issued so
    // a transient insert/cookie failure does not silently destroy persistence
    // for the current browser.
    $old = authParseRememberCookie($_COOKIE[AUTH_REMEMBER_COOKIE] ?? '');

    $expiresEpoch = time() + ($days * 86400);
    $expiresAt = date('Y-m-d H:i:s', $expiresEpoch);
    $fingerprint = authCredentialFingerprint($user);
    $uaHash = hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $ip = getClientIp();
    $userId = (int) $user['id'];

    for ($attempt = 0; $attempt < 3; $attempt++) {
        try {
            $selector = bin2hex(random_bytes(16));
            $validator = bin2hex(random_bytes(32));
        } catch (Throwable $e) {
            error_log('Remember-device random generation failed: ' . $e->getMessage());
            return false;
        }
        $tokenHash = hash('sha256', $validator);
        $stmt = $conn->prepare(
            'INSERT INTO auth_remember_tokens
             (selector, user_id, token_hash, credential_fingerprint, expires_at, created_at, last_used_at, user_agent_hash, last_ip)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW(), ?, ?)'
        );
        if (!$stmt) return false;
        $stmt->bind_param('sisssss', $selector, $userId, $tokenHash, $fingerprint, $expiresAt, $uaHash, $ip);
        $inserted = $stmt->execute();
        $stmt->close();
        if (!$inserted) continue;

        if (!authSetRememberCookie($selector . '.' . $validator, $expiresEpoch)) {
            authDeleteRememberSelector($selector);
            return false;
        }
        $_COOKIE[AUTH_REMEMBER_COOKIE] = $selector . '.' . $validator;
        $_SESSION['remember_selector'] = $selector;
        $_SESSION['remember_expires_at'] = $expiresEpoch;
        $_SESSION['remember_days'] = $days;

        if ($old && $old['selector'] !== $selector) {
            authDeleteRememberSelector($old['selector']);
        }
        return true;
    }
    error_log('Remember-device token could not be created after retries.');
    return false;
}

/**
 * Attempt automatic sign-in from the remembered-device cookie.
 * Token rotation includes a short previous-token grace period to avoid false
 * failures when two mobile tabs wake up at nearly the same time.
 */
function attemptRememberedLogin(): bool
{
    global $conn;
    if (isLoggedIn()) return true;

    $parsed = authParseRememberCookie($_COOKIE[AUTH_REMEMBER_COOKIE] ?? '');
    if (!$parsed) {
        if (!empty($_COOKIE[AUTH_REMEMBER_COOKIE])) authClearRememberCookie();
        return false;
    }
    if (!ensureAuthRememberTokenSchema()) {
        // Keep the cookie on a temporary DB/schema problem so the user can retry later.
        return false;
    }

    $selector = $parsed['selector'];
    $candidateHash = hash('sha256', $parsed['validator']);
    $transactionStarted = false;

    try {
        $conn->begin_transaction();
        $transactionStarted = true;
        $stmt = $conn->prepare(
            'SELECT t.user_id, t.token_hash, t.previous_token_hash, t.previous_valid_until,
                    t.credential_fingerprint, t.expires_at,
                    u.id, u.username, u.password, u.role, u.balance, u.status
             FROM auth_remember_tokens t
             INNER JOIN users u ON u.id = t.user_id
             WHERE t.selector = ? LIMIT 1 FOR UPDATE'
        );
        if (!$stmt) throw new RuntimeException('remember lookup prepare failed');
        $stmt->bind_param('s', $selector);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('remember lookup failed');
        }
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$row) {
            $conn->rollback();
            authClearRememberCookie();
            return false;
        }

        $user = [
            'id' => (int) $row['id'],
            'username' => (string) $row['username'],
            'password' => (string) $row['password'],
            'role' => (string) $row['role'],
            'balance' => $row['balance'],
            'status' => (string) $row['status'],
        ];
        $expiresEpoch = strtotime((string) $row['expires_at']);
        $fingerprintValid = hash_equals(
            (string) $row['credential_fingerprint'],
            authCredentialFingerprint($user)
        );
        $accountValid = $user['status'] === 'active' && authRoleIsValid($user['role']);

        if ($expiresEpoch === false || $expiresEpoch <= time() || !$fingerprintValid || !$accountValid) {
            $delete = $conn->prepare('DELETE FROM auth_remember_tokens WHERE user_id = ?');
            if ($delete) {
                $delete->bind_param('i', $user['id']);
                $delete->execute();
                $delete->close();
            }
            $conn->commit();
            authClearRememberCookie();
            return false;
        }

        $accessBlock = accountVerificationAccessBlock((int) $user['id'], (string) $user['role']);
        if (!empty($accessBlock['blocked'])) {
            $delete = $conn->prepare('DELETE FROM auth_remember_tokens WHERE selector = ?');
            if ($delete) {
                $delete->bind_param('s', $selector);
                $delete->execute();
                $delete->close();
            }
            $conn->commit();
            $transactionStarted = false;
            $_SESSION['auth_failure_reason'] = 'security_blocked';
            authClearRememberCookie();
            return false;
        }

        $matchesCurrent = hash_equals((string) $row['token_hash'], $candidateHash);
        $previousUntil = !empty($row['previous_valid_until'])
            ? strtotime((string) $row['previous_valid_until'])
            : false;
        $matchesPrevious = !empty($row['previous_token_hash'])
            && $previousUntil !== false
            && $previousUntil >= time()
            && hash_equals((string) $row['previous_token_hash'], $candidateHash);

        if (!$matchesCurrent && !$matchesPrevious) {
            $delete = $conn->prepare('DELETE FROM auth_remember_tokens WHERE selector = ?');
            if ($delete) {
                $delete->bind_param('s', $selector);
                $delete->execute();
                $delete->close();
            }
            $conn->commit();
            authClearRememberCookie();
            logSuspiciousActivity('remember_token_mismatch', ['selector_hash' => hash('sha256', $selector)]);
            return false;
        }

        $newValidator = bin2hex(random_bytes(32));
        $newTokenHash = hash('sha256', $newValidator);
        $previousHash = (string) $row['token_hash'];
        $previousValidUntil = date('Y-m-d H:i:s', time() + AUTH_REMEMBER_GRACE_SECONDS);
        $uaHash = hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $ip = getClientIp();
        $update = $conn->prepare(
            'UPDATE auth_remember_tokens
             SET token_hash = ?, previous_token_hash = ?, previous_valid_until = ?,
                 last_used_at = NOW(), user_agent_hash = ?, last_ip = ?
             WHERE selector = ?'
        );
        if (!$update) throw new RuntimeException('remember rotation prepare failed');
        $update->bind_param('ssssss', $newTokenHash, $previousHash, $previousValidUntil, $uaHash, $ip, $selector);
        if (!$update->execute() || $update->affected_rows !== 1) {
            $update->close();
            throw new RuntimeException('remember rotation failed');
        }
        $update->close();
        $conn->commit();
        $transactionStarted = false;

        if (!authSetRememberCookie($selector . '.' . $newValidator, $expiresEpoch)) {
            authDeleteRememberSelector($selector);
            return false;
        }
        $_COOKIE[AUTH_REMEMBER_COOKIE] = $selector . '.' . $newValidator;
        authStartUserSession($user, 'remember');
        $_SESSION['remember_selector'] = $selector;
        $_SESSION['remember_expires_at'] = $expiresEpoch;
        $_SESSION['remember_days'] = max(1, (int) ceil(($expiresEpoch - time()) / 86400));
        logHistory($user['id'], 'auto_login', 'Remembered device login from IP: ' . getClientIp());
        return true;
    } catch (Throwable $e) {
        if ($transactionStarted) {
            try { $conn->rollback(); } catch (Throwable $ignored) {}
        }
        error_log('Remember-device login error: ' . $e->getMessage());
        return false;
    }
}

function authClearSessionIdentity(bool $regenerate = true): void
{
    foreach ([
        'user_id', 'username', 'role', 'balance', 'login_time', 'last_activity',
        'login_ip', 'auth_source', 'auth_regenerated_at', 'auth_password_fingerprint', 'remember_selector',
        'remember_expires_at', 'remember_days', 'account_device_touch_at',
        'shared_security_block_cache'
    ] as $key) {
        unset($_SESSION[$key]);
    }
    unset($GLOBALS['auth_current_user']);
    if ($regenerate && session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
        session_regenerate_id(true);
    }
}

function authSessionIsExpired(): bool
{
    if (!isLoggedIn()) return false;
    $now = time();
    $lastActivity = (int) ($_SESSION['last_activity'] ?? 0);
    $loginTime = (int) ($_SESSION['login_time'] ?? 0);
    if ($lastActivity < 1 || $loginTime < 1) return true;
    if (($now - $lastActivity) > AUTH_IDLE_TIMEOUT) return true;
    $absolute = isAdmin() ? AUTH_ABSOLUTE_TIMEOUT_ADMIN : AUTH_ABSOLUTE_TIMEOUT_USER;
    return ($now - $loginTime) > $absolute;
}

/** Validate a live session and keep role/status synchronized with the database. */
function authValidateCurrentSession(bool $touchActivity = true): bool
{
    if (!isLoggedIn()) return false;
    $userId = (int) $_SESSION['user_id'];
    $user = authLoadUser($userId);
    if (!$user || ($user['status'] ?? '') !== 'active' || !authRoleIsValid($user['role'] ?? null)) {
        $_SESSION['auth_failure_reason'] = 'banned';
        if ($userId > 0) revokeRememberTokensForUser($userId);
        forgetCurrentRememberedDevice();
        authClearSessionIdentity();
        return false;
    }

    $accessBlock = accountVerificationAccessBlock($userId, (string) ($user['role'] ?? ''));
    if (!empty($accessBlock['blocked'])) {
        $_SESSION['auth_failure_reason'] = 'security_blocked';
        forgetCurrentRememberedDevice();
        authClearSessionIdentity();
        return false;
    }

    $currentPasswordFingerprint = authPasswordFingerprint($user);
    $sessionPasswordFingerprint = (string) ($_SESSION['auth_password_fingerprint'] ?? '');
    if ($sessionPasswordFingerprint !== '' && !hash_equals($sessionPasswordFingerprint, $currentPasswordFingerprint)) {
        $_SESSION['auth_failure_reason'] = 'credentials_changed';
        revokeRememberTokensForUser($userId);
        forgetCurrentRememberedDevice();
        authClearSessionIdentity();
        return false;
    }
    // Existing sessions created before this deployment receive the fingerprint
    // once, avoiding a needless mass logout during the update.
    $_SESSION['auth_password_fingerprint'] = $currentPasswordFingerprint;

    $_SESSION['username'] = (string) $user['username'];
    $_SESSION['role'] = (string) $user['role'];
    $_SESSION['balance'] = $user['balance'];
    $GLOBALS['auth_current_user'] = $user;
    accountVerificationTouchDevice($userId);

    if ($touchActivity) $_SESSION['last_activity'] = time();
    $lastRenewal = (int) ($_SESSION['auth_regenerated_at'] ?? $_SESSION['login_time'] ?? 0);
    if ($lastRenewal < 1 || (time() - $lastRenewal) >= AUTH_SESSION_RENEW_INTERVAL) {
        regenerateSession();
        $_SESSION['auth_regenerated_at'] = time();
    }
    return true;
}

/** Called once when this file is loaded. */
function authBootstrapAuthentication(): void
{
    if (isLoggedIn() && authSessionIsExpired()) {
        $_SESSION['auth_failure_reason'] = 'expired';
        authClearSessionIdentity();
    }
    if (isLoggedIn()) {
        authValidateCurrentSession(false);
    }
    if (!isLoggedIn()) {
        attemptRememberedLogin();
    }
}

function requireLogin(bool $allowUnverified = false): void
{
    if (!isLoggedIn() && !attemptRememberedLogin()) {
        $reason = (string) ($_SESSION['auth_failure_reason'] ?? '');
        unset($_SESSION['auth_failure_reason']);
        if (in_array($reason, ['expired', 'banned', 'credentials_changed', 'security_blocked'], true)) {
            authRedirect('login.php?error=' . rawurlencode($reason));
        }
        authRedirect('login.php');
    }
    if (!authValidateCurrentSession(true)) {
        $reason = (string) ($_SESSION['auth_failure_reason'] ?? 'banned');
        unset($_SESSION['auth_failure_reason']);
        authRedirect('login.php?error=' . rawurlencode($reason));
    }
    if (!$allowUnverified) {
        accountVerificationRequireComplete();
    }
}

function requireAdmin(): void
{
    requireLogin();
    if (!isAdmin()) authRedirect('index.php');
}

function requireReseller(): void
{
    requireLogin();
    if (!isReseller() && !isAdmin()) authRedirect('index.php');
}

function isUserActive($userId = null): bool
{
    $userId = $userId ?? ($_SESSION['user_id'] ?? null);
    if (!is_numeric($userId) || (int) $userId < 1) return false;
    $user = ((int) $userId === (int) ($_SESSION['user_id'] ?? 0) && isset($GLOBALS['auth_current_user']))
        ? $GLOBALS['auth_current_user']
        : authLoadUser((int) $userId);
    return $user && ($user['status'] ?? '') === 'active';
}

function requireActive(bool $allowUnverified = false): void
{
    requireLogin($allowUnverified);
    if (!isUserActive()) authRedirect('login.php?error=banned');
}

function getCurrentUser()
{
    if (!isLoggedIn()) return null;
    if (isset($GLOBALS['auth_current_user'])) return $GLOBALS['auth_current_user'];
    return authLoadUser((int) $_SESSION['user_id']);
}

/**
 * Password login.
 * $rememberDays accepts 0, 7, 14, or 30. Administrator tokens are capped at 7.
 */
function login($username, $password, $rememberDays = 0): array
{
    global $conn;
    $username = cleanInput($username);
    $password = is_string($password) ? $password : '';
    if ($username === '' || strlen($username) > 60 || strlen($password) > 4096) {
        return ['success' => false, 'code' => 'invalid_credentials', 'message' => 'Invalid username or password'];
    }

    $loginRateKey = 'login_' . hash('sha256', strtolower((string) $username));
    if (!checkRateLimit($loginRateKey, 5, 300)) {
        $remaining = getRateLimitReset($loginRateKey);
        logSuspiciousActivity('login_rate_limited', ['username_hash' => hash('sha256', strtolower((string) $username))]);
        return [
            'success' => false,
            'code' => 'rate_limited',
            'retry_after' => $remaining,
            'message' => "Too many login attempts. Please wait {$remaining} seconds.",
        ];
    }

    $stmt = $conn->prepare('SELECT id, username, password, role, balance, status FROM users WHERE username = ? LIMIT 1');
    if (!$stmt) {
        error_log('Login prepare failed: ' . $conn->error);
        return ['success' => false, 'code' => 'unavailable', 'message' => 'Unable to process login'];
    }
    $stmt->bind_param('s', $username);
    if (!$stmt->execute()) {
        error_log('Login query failed: ' . $stmt->error);
        $stmt->close();
        return ['success' => false, 'code' => 'unavailable', 'message' => 'Unable to process login'];
    }
    $stmt->bind_result($rowId, $rowUsername, $rowPassword, $rowRole, $rowBalance, $rowStatus);
    $foundUser = $stmt->fetch();
    $stmt->close();

    // Always perform a password verification to reduce username timing differences.
    $dummyHash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
    $hashToVerify = $foundUser ? (string) $rowPassword : $dummyHash;
    $passwordValid = password_verify($password, $hashToVerify);

    if ($foundUser && $passwordValid) {
        $user = [
            'id' => (int) $rowId,
            'username' => (string) $rowUsername,
            'password' => (string) $rowPassword,
            'role' => (string) $rowRole,
            'balance' => $rowBalance,
            'status' => (string) $rowStatus,
        ];
        if ($user['status'] !== 'active' || !authRoleIsValid($user['role'])) {
            return ['success' => false, 'code' => 'inactive', 'message' => 'Your account is not active'];
        }

        $accessBlock = accountVerificationAccessBlock((int) $user['id'], (string) $user['role']);
        if (!empty($accessBlock['blocked'])) {
            logSuspiciousActivity('blocked_login_attempt', [
                'user_id' => (int) $user['id'],
                'block_code' => (string) ($accessBlock['code'] ?? 'blocked'),
            ]);
            return ['success' => false, 'code' => 'security_blocked', 'message' => 'Access denied'];
        }

        // Transparently update old hashes when PHP's configured default changes.
        // A technical rehash is not a password change, so preserve remembered
        // devices that were valid under the previous hash/fingerprint.
        if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
            $oldCredentialFingerprint = authCredentialFingerprint($user);
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            if ($newHash !== false) {
                $rehash = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
                if ($rehash) {
                    $rehash->bind_param('si', $newHash, $user['id']);
                    if ($rehash->execute()) {
                        $user['password'] = $newHash;
                        authRefreshRememberCredentialFingerprintAfterRehash(
                            (int) $user['id'],
                            $oldCredentialFingerprint,
                            authCredentialFingerprint($user)
                        );
                    }
                    $rehash->close();
                }
            }
        }

        authStartUserSession($user, 'password');
        clearRateLimit($loginRateKey);
        $normalizedDays = authNormalizeRememberDays($rememberDays, $user['role']);
        if ($normalizedDays > 0) {
            $remembered = authIssueRememberToken($user, $normalizedDays);
            if (!$remembered) {
                // Never pretend "remember this device" succeeded. Keep normal
                // sign-in available by letting the user retry without the option.
                authClearSessionIdentity();
                return [
                    'success' => false,
                    'code' => 'remember_unavailable',
                    'message' => 'Remember-device setup is temporarily unavailable',
                ];
            }
        } else {
            // An explicit sign-in without “remember this device” must also revoke
            // any older remembered token already stored in this browser.
            forgetCurrentRememberedDevice();
            $remembered = false;
        }
        logHistory($user['id'], 'login', 'User logged in from IP: ' . getClientIp());
        return [
            'success' => true,
            'role' => $user['role'],
            'remembered' => $remembered,
            'remember_days' => $remembered ? $normalizedDays : 0,
        ];
    }

    logSuspiciousActivity('login_failed', ['username_hash' => hash('sha256', strtolower((string) $username))]);
    return ['success' => false, 'code' => 'invalid_credentials', 'message' => 'Invalid username or password'];
}

function authDeleteSessionCookie(): void
{
    if (!ini_get('session.use_cookies') || headers_sent()) return;
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $params['path'] ?? '/',
        'domain' => $params['domain'] ?? '',
        'secure' => !empty($params['secure']),
        'httponly' => !empty($params['httponly']),
        'samesite' => $params['samesite'] ?? 'Lax',
    ]);
}

/** Logout this device by default. Pass true to revoke every remembered device. */
function logout(bool $allDevices = false): void
{
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId > 0) {
        logHistory($userId, 'logout', $allDevices ? 'User logged out all devices' : 'User logged out');
        if ($allDevices) revokeRememberTokensForUser($userId);
        else forgetCurrentRememberedDevice();
    } else {
        forgetCurrentRememberedDevice();
    }

    session_unset();
    authDeleteSessionCookie();
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
    authRedirect('login.php?logged_out=1');
}

function register($username, $email, $password, $role = 'user'): array
{
    global $conn;
    $username = cleanInput($username);
    $rawEmail = is_scalar($email) ? trim((string) $email) : '';
    $email = accountRecoveryNormalizeGmail($rawEmail);
    $password = is_string($password) ? $password : '';
    $role = cleanInput($role);

    $creatorIsAdmin = isLoggedIn() && isAdmin() && isUserActive();
    if ($creatorIsAdmin) {
        if (!in_array($role, ['user', 'reseller'], true)) $role = 'user';
    } else {
        $role = 'user';
    }

    if ($username === '' || $rawEmail === '' || $password === '') {
        return ['success' => false, 'message' => 'All fields are required'];
    }
    if (strlen($username) > 60 || strlen($email) > 190 || strlen($password) > 4096) {
        return ['success' => false, 'message' => 'One or more fields are too long'];
    }
    if (!preg_match('/^[A-Za-z0-9_.-]{3,60}$/', $username)) {
        return ['success' => false, 'message' => 'Username must be 3-60 characters and use only letters, numbers, dot, underscore, or dash'];
    }
    if ($email === '') {
        return ['success' => false, 'message' => 'รองรับเฉพาะอีเมล @gmail.com เท่านั้น'];
    }
    if (strlen($password) < 8) {
        return ['success' => false, 'message' => 'Password must be at least 8 characters'];
    }

    if (!$creatorIsAdmin) {
        $emailBlock = accountVerificationEmailBlockState($email);
        if (!empty($emailBlock['blocked'])) {
            return ['success' => false, 'message' => 'อีเมลนี้ถูกระงับการสมัครบัญชี'];
        }
    }

    $check = $conn->prepare('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1');
    if (!$check) return ['success' => false, 'message' => 'Registration failed'];
    $check->bind_param('ss', $username, $email);
    if (!$check->execute()) {
        $check->close();
        return ['success' => false, 'message' => 'Registration failed'];
    }
    $check->store_result();
    if ($check->num_rows > 0) {
        $check->close();
        return ['success' => false, 'message' => 'Username or email already exists'];
    }
    $check->close();

    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    if ($hashedPassword === false) return ['success' => false, 'message' => 'Registration failed'];
    if (!accountRecoveryEnsureSchema()) return ['success' => false, 'message' => 'Registration failed'];
    $stmt = $conn->prepare("INSERT INTO users (username, email, password, role, balance, status) VALUES (?, ?, ?, ?, 0.00, 'active')");
    if (!$stmt) return ['success' => false, 'message' => 'Registration failed'];
    $stmt->bind_param('ssss', $username, $email, $hashedPassword, $role);
    if ($stmt->execute()) {
        $userId = (int) $conn->insert_id;
        $stmt->close();
        logHistory($userId, 'register', 'User registered');
        return ['success' => true, 'role' => $role, 'user_id' => $userId];
    }
    $stmt->close();
    return ['success' => false, 'message' => 'Registration failed'];
}

function redirectByRole(): void
{
    if (!isLoggedIn() || !authValidateCurrentSession(false)) {
        authRedirect('login.php');
    }
    if (isAdmin()) authRedirect('admin/dashboard.php');
    accountVerificationRequireComplete();
    if (isReseller()) authRedirect('reseller/buy.php');
    if (isUser()) authRedirect('user/buy.php');
    authClearSessionIdentity();
    authRedirect('login.php');
}

authBootstrapAuthentication();
keyHistoryScheduleAutoCleanup();
