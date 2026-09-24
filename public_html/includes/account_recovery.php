<?php
/**
 * Gmail-only account recovery and one-time self-service email change support.
 *
 * Passwords remain one-way hashes. Recovery proves access to the Gmail inbox
 * by requiring a single-use HTTPS reset link.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/security.php';

if (!defined('ACCOUNT_RECOVERY_TOKEN_TTL')) define('ACCOUNT_RECOVERY_TOKEN_TTL', 1800);
if (!defined('ACCOUNT_RECOVERY_NOTICE_DAYS')) define('ACCOUNT_RECOVERY_NOTICE_DAYS', 7);
if (!defined('ACCOUNT_RECOVERY_MAIL_LOG_DAYS')) define('ACCOUNT_RECOVERY_MAIL_LOG_DAYS', 90);

function accountRecoveryColumnExists(string $table, string $column): bool
{
    global $conn;
    if (!preg_match('/^[a-z0-9_]+$/i', $table) || !preg_match('/^[a-z0-9_]+$/i', $column)) return false;
    $stmt = $conn->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    if (!$stmt) return false;
    $stmt->bind_param('ss', $table, $column);
    if (!$stmt->execute()) {
        $stmt->close();
        return false;
    }
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    return (int) $count > 0;
}

function accountRecoveryEnsureColumn(string $table, string $column, string $definition): bool
{
    global $conn;
    if (accountRecoveryColumnExists($table, $column)) return true;
    if (!preg_match('/^[a-z0-9_]+$/i', $table) || !preg_match('/^[a-z0-9_]+$/i', $column)) return false;
    return (bool) $conn->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
}

function accountRecoveryEnsureSchema(): bool
{
    global $conn;
    static $ready = null;
    if ($ready !== null) return $ready;
    if (!isset($conn) || !($conn instanceof mysqli)) return $ready = false;

    $version = (int) getSetting('account_recovery_schema_version', '0');
    if ($version >= 3) return $ready = true;

    $ok = true;
    $ok = accountRecoveryEnsureColumn('users', 'email_change_used', "TINYINT(1) NOT NULL DEFAULT 0") && $ok;
    $ok = accountRecoveryEnsureColumn('users', 'email_notice_started_at', "DATETIME NULL DEFAULT NULL") && $ok;
    $ok = accountRecoveryEnsureColumn('users', 'email_verified_at', "DATETIME NULL DEFAULT NULL") && $ok;
    $ok = accountRecoveryEnsureColumn('users', 'password_changed_at', "DATETIME NULL DEFAULT NULL") && $ok;

    $tokenSql = "CREATE TABLE IF NOT EXISTS auth_password_reset_tokens (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        token_hash CHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        used_at DATETIME NULL DEFAULT NULL,
        request_ip VARCHAR(45) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_auth_password_reset_hash (token_hash),
        KEY idx_auth_password_reset_user (user_id, created_at),
        KEY idx_auth_password_reset_expiry (expires_at, used_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$conn->query($tokenSql)) {
        error_log('Password reset schema error: ' . $conn->error);
        $ok = false;
    }

    $logSql = "CREATE TABLE IF NOT EXISTS account_recovery_mail_logs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NULL DEFAULT NULL,
        actor_id BIGINT UNSIGNED NULL DEFAULT NULL,
        event_type VARCHAR(40) NOT NULL DEFAULT 'mail',
        recipient VARCHAR(190) NOT NULL DEFAULT '',
        status VARCHAR(20) NOT NULL DEFAULT 'failed',
        transport VARCHAR(24) NOT NULL DEFAULT '',
        stage VARCHAR(40) NOT NULL DEFAULT '',
        error_code VARCHAR(80) NOT NULL DEFAULT '',
        smtp_code SMALLINT UNSIGNED NULL DEFAULT NULL,
        message VARCHAR(500) NOT NULL DEFAULT '',
        details MEDIUMTEXT NULL,
        duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
        request_ip VARCHAR(45) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_recovery_mail_created (created_at),
        KEY idx_recovery_mail_status (status, created_at),
        KEY idx_recovery_mail_event (event_type, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$conn->query($logSql)) {
        error_log('Recovery mail log schema error: ' . $conn->error);
        $ok = false;
    }

    if ($ok) {
        if (!$conn->query("UPDATE users SET email_notice_started_at = NOW() WHERE role IN ('user','reseller') AND email_notice_started_at IS NULL")) {
            error_log('Email notice migration error: ' . $conn->error);
            $ok = false;
        }
        if (!$conn->query("DELETE FROM auth_password_reset_tokens WHERE used_at IS NOT NULL OR expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY)")) {
            error_log('Password reset token cleanup error: ' . $conn->error);
        }
        if (!$conn->query('DELETE FROM account_recovery_mail_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ' . (int) ACCOUNT_RECOVERY_MAIL_LOG_DAYS . ' DAY)')) {
            error_log('Recovery mail log cleanup error: ' . $conn->error);
        }
        if ($ok && !upsertSetting('account_recovery_schema_version', '3')) {
            error_log('Unable to store account recovery schema version 3.');
            $ok = false;
        }
    }

    return $ready = $ok;
}

function accountRecoveryNormalizeGmail($email): string
{
    if (!is_scalar($email)) return '';
    $email = strtolower(trim((string) $email));
    if ($email === '' || strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) return '';
    $at = strrpos($email, '@');
    if ($at === false || substr($email, $at + 1) !== 'gmail.com') return '';
    return $email;
}

function accountRecoveryIsGmail($email): bool
{
    return accountRecoveryNormalizeGmail($email) !== '';
}

function accountRecoveryMaskEmail(string $email): string
{
    $email = strtolower(trim($email));
    $at = strrpos($email, '@');
    if ($at === false) return '';
    $local = substr($email, 0, $at);
    $domain = substr($email, $at + 1);
    if ($local === '') return '***@' . $domain;
    $visible = substr($local, 0, min(2, strlen($local)));
    return $visible . str_repeat('*', max(3, min(8, strlen($local) - strlen($visible)))) . '@' . $domain;
}

function accountRecoveryMailSecretPath(): string
{
    return dirname(__DIR__, 2) . '/private/recovery_mail_secret.php';
}

function accountRecoveryMailSecretInfo(bool $createIfMissing = true): array
{
    static $cache = [];
    $cacheKey = $createIfMissing ? 'create' : 'read';
    if (isset($cache[$cacheKey]) && is_array($cache[$cacheKey])) return $cache[$cacheKey];

    $raw = trim((string) (getenv('RECOVERY_MAIL_SECRET') ?: ''));
    $source = '';
    $keyId = trim((string) (getenv('RECOVERY_MAIL_KEY_ID') ?: ''));
    if ($raw !== '') {
        $source = 'environment';
        if ($keyId === '') $keyId = 'environment';
    } elseif (defined('RECOVERY_MAIL_MASTER_SECRET') && trim((string) RECOVERY_MAIL_MASTER_SECRET) !== '') {
        $raw = trim((string) RECOVERY_MAIL_MASTER_SECRET);
        $source = 'database_config';
        $keyId = defined('RECOVERY_MAIL_KEY_ID') ? trim((string) RECOVERY_MAIL_KEY_ID) : '';
    }

    if ($raw === '') {
        $path = accountRecoveryMailSecretPath();
        if (is_file($path) && is_readable($path)) {
            try {
                $loadedValue = include $path;
                if (is_string($loadedValue)) {
                    $raw = trim($loadedValue);
                    $source = 'legacy_file';
                    if ($keyId === '') $keyId = 'legacy-file';
                }
            } catch (Throwable $e) {
                error_log('Recovery mail secret file could not be read: ' . $e->getMessage());
            }
        }
    }

    if ($raw === '' && $createIfMissing) {
        // Backward-compatible first installation. Existing projects should keep
        // the generated value and move it into private/database.php afterwards.
        $path = accountRecoveryMailSecretPath();
        $directory = dirname($path);
        if (is_dir($directory) && is_writable($directory)) {
            try {
                $raw = bin2hex(random_bytes(32));
                $tmp = $path . '.tmp.' . bin2hex(random_bytes(6));
                $content = "<?php\n// Generated automatically. Move this value into private/database.php.\nreturn '" . $raw . "';\n";
                if (file_put_contents($tmp, $content, LOCK_EX) !== false && @rename($tmp, $path)) {
                    @chmod($path, 0600);
                    $source = 'generated_legacy_file';
                    $keyId = 'generated-legacy-file';
                } else {
                    @unlink($tmp);
                    $raw = '';
                }
            } catch (Throwable $e) {
                error_log('Recovery mail secret generation failed: ' . $e->getMessage());
                $raw = '';
            }
        }
    }

    $valid = strlen($raw) >= 32;
    $key = $valid ? hash('sha256', $raw, true) : null;
    $info = [
        'valid' => $valid,
        'key' => $key,
        'raw' => $valid ? $raw : '',
        'source' => $valid ? $source : '',
        'key_id' => $valid ? $keyId : '',
        'fingerprint' => $valid ? substr(hash('sha256', $raw), 0, 16) : '',
        'legacy_file_exists' => is_file(accountRecoveryMailSecretPath()),
        'legacy_file_readable' => is_readable(accountRecoveryMailSecretPath()),
        'site_id' => defined('APP_SITE_ID') ? (string) APP_SITE_ID : (defined('DB_NAME') ? (string) DB_NAME : ''),
    ];
    $cache['read'] = $info;
    $cache['create'] = $info;
    return $info;
}

function accountRecoveryGetEncryptionKey(bool $createIfMissing = true): ?string
{
    $info = accountRecoveryMailSecretInfo($createIfMissing);
    return !empty($info['valid']) && is_string($info['key']) ? $info['key'] : null;
}

function accountRecoveryEncryptSecret(string $plaintext): ?string
{
    if ($plaintext === '' || !function_exists('openssl_encrypt')) return null;
    $key = accountRecoveryGetEncryptionKey(true);
    if ($key === null) return null;
    try {
        $nonce = random_bytes(12);
    } catch (Throwable $e) {
        return null;
    }
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, 'recovery.smtp.password');
    if ($ciphertext === false || strlen($tag) !== 16) return null;
    return base64_encode($nonce . $tag . $ciphertext);
}

function accountRecoveryDecryptSecret(string $encoded): string
{
    if ($encoded === '' || !function_exists('openssl_decrypt')) return '';
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) < 29) return '';
    // Reading an existing password must never silently create a replacement key.
    // If the original secret file was lost, the administrator must enter a new
    // App Password so the configuration can be encrypted with a new secret.
    $key = accountRecoveryGetEncryptionKey(false);
    if ($key === null) return '';
    $nonce = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ciphertext = substr($raw, 28);
    $value = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, 'recovery.smtp.password');
    return is_string($value) ? $value : '';
}

function accountRecoveryNormalizeTransportMode($mode): string
{
    $mode = strtolower(trim((string) $mode));
    return in_array($mode, ['auto', 'starttls_587', 'smtps_465'], true) ? $mode : 'auto';
}

function accountRecoveryGetMailConfig(): array
{
    $username = accountRecoveryNormalizeGmail(getSetting('recovery_smtp_username', ''));
    $storedPassword = (string) getSetting('recovery_smtp_password', '');
    $password = accountRecoveryDecryptSecret($storedPassword);
    $fromName = trim((string) getSetting('recovery_from_name', getSetting('site_name', 'Store Support')));
    if ($fromName === '') $fromName = 'Store Support';
    if (strlen($fromName) > 120) $fromName = substr($fromName, 0, 120);
    $secretPath = accountRecoveryMailSecretPath();
    $secretInfo = accountRecoveryMailSecretInfo(false);
    $secretExists = !empty($secretInfo['valid']);
    $secretReadable = !empty($secretInfo['valid']);
    $configError = '';
    if ($storedPassword !== '' && !$secretExists) $configError = 'secret_missing';
    elseif ($storedPassword !== '' && $password === '') $configError = 'password_decrypt_failed';

    return [
        'enabled' => (string) getSetting('recovery_mail_enabled', '0') === '1',
        'host' => 'smtp.gmail.com',
        'mode' => accountRecoveryNormalizeTransportMode(getSetting('recovery_smtp_mode', 'auto')),
        'username' => $username,
        'password' => $password,
        'has_stored_password' => $storedPassword !== '',
        'from_name' => $fromName,
        'configured' => $username !== '' && $password !== '',
        'config_error' => $configError,
        'secret_path' => $secretPath,
        'secret_exists' => $secretExists,
        'secret_readable' => $secretReadable,
        'secret_source' => (string) ($secretInfo['source'] ?? ''),
        'secret_key_id' => (string) ($secretInfo['key_id'] ?? ''),
        'secret_fingerprint' => (string) ($secretInfo['fingerprint'] ?? ''),
        'site_id' => (string) ($secretInfo['site_id'] ?? ''),
        'last_test_status' => (string) getSetting('recovery_mail_last_test_status', ''),
        'last_test_at' => (string) getSetting('recovery_mail_last_test_at', ''),
        'last_test_transport' => (string) getSetting('recovery_mail_last_test_transport', ''),
        'last_test_message' => (string) getSetting('recovery_mail_last_test_message', ''),
        'credential_state' => (string) getSetting('recovery_smtp_credential_state', ''),
        'password_updated_at' => (string) getSetting('recovery_smtp_password_updated_at', ''),
        'last_verified_at' => (string) getSetting('recovery_smtp_last_verified_at', ''),
        'last_test_stage' => (string) getSetting('recovery_mail_last_test_stage', ''),
        'last_test_smtp_code' => (string) getSetting('recovery_mail_last_test_smtp_code', ''),
        'delivery_verified' => (string) getSetting('recovery_mail_delivery_verified', '0') === '1',
        'delivery_verified_at' => (string) getSetting('recovery_mail_delivery_verified_at', ''),
    ];
}

function accountRecoveryGetLatestSuccessfulSendTest(): ?array
{
    global $conn;
    if (!accountRecoveryEnsureSchema()) return null;
    $stmt = $conn->prepare(
        "SELECT id, transport, smtp_code, created_at
         FROM account_recovery_mail_logs
         WHERE event_type = 'smtp_test' AND status = 'success' AND stage = 'sent' AND smtp_code = 250
         ORDER BY id DESC LIMIT 1"
    );
    if (!$stmt || !$stmt->execute()) {
        if ($stmt) $stmt->close();
        return null;
    }
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

/**
 * Decide whether password recovery can safely be enabled. A successful SMTP
 * login is not enough: Gmail must have accepted an actual test message.
 */
function accountRecoveryActivationReadiness(): array
{
    $config = accountRecoveryGetMailConfig();
    if (!$config['configured']) {
        return ['ready' => false, 'code' => $config['config_error'] !== '' ? $config['config_error'] : 'mail_not_configured'];
    }
    if (getCanonicalBaseUrl() === '') {
        return ['ready' => false, 'code' => 'base_url_missing'];
    }
    if ((string) $config['credential_state'] !== 'valid') {
        return ['ready' => false, 'code' => 'credential_not_verified'];
    }

    // Once Gmail has accepted a real message for the current credentials, the
    // durable flag survives later diagnostics and log cleanup.
    if (!empty($config['delivery_verified'])) {
        return [
            'ready' => true,
            'code' => '',
            'test' => [
                'transport' => (string) $config['last_test_transport'],
                'smtp_code' => 250,
                'created_at' => (string) $config['delivery_verified_at'],
            ],
        ];
    }

    // Fallbacks migrate V8 installations without forcing another test message.
    if ((string) $config['last_test_status'] === 'success'
        && (string) $config['last_test_stage'] === 'sent'
        && (int) $config['last_test_smtp_code'] === 250) {
        return [
            'ready' => true,
            'code' => '',
            'test' => [
                'transport' => (string) $config['last_test_transport'],
                'smtp_code' => 250,
                'created_at' => (string) $config['last_test_at'],
            ],
        ];
    }

    $test = accountRecoveryGetLatestSuccessfulSendTest();
    if (!$test) {
        return ['ready' => false, 'code' => 'send_test_required'];
    }
    return ['ready' => true, 'code' => '', 'test' => $test, 'migrated_from_log' => true];
}

function accountRecoverySetMailEnabled(bool $enabled): array
{
    if (!$enabled) {
        return ['success' => upsertSetting('recovery_mail_enabled', '0')];
    }
    $readiness = accountRecoveryActivationReadiness();
    if (empty($readiness['ready'])) {
        return ['success' => false, 'code' => (string) ($readiness['code'] ?? 'not_ready')];
    }

    // Backfill V8's successful log into durable settings before activation.
    // The admin may then clear logs without affecting system availability.
    $test = is_array($readiness['test'] ?? null) ? $readiness['test'] : [];
    $writes = [
        ['recovery_mail_enabled', '1'],
        ['recovery_mail_last_test_status', 'success'],
        ['recovery_mail_last_test_stage', 'sent'],
        ['recovery_mail_last_test_smtp_code', '250'],
        ['recovery_mail_delivery_verified', '1'],
        ['recovery_mail_delivery_verified_at', !empty($test['created_at']) ? (string) $test['created_at'] : date('Y-m-d H:i:s')],
    ];
    if ((string) getSetting('recovery_mail_last_test_at', '') === '' && !empty($test['created_at'])) {
        $writes[] = ['recovery_mail_last_test_at', (string) $test['created_at']];
    }
    if ((string) getSetting('recovery_smtp_last_verified_at', '') === '') {
        $writes[] = ['recovery_smtp_last_verified_at', !empty($test['created_at']) ? (string) $test['created_at'] : date('Y-m-d H:i:s')];
    }
    foreach ($writes as $write) {
        if (!upsertSetting($write[0], $write[1])) {
            return ['success' => false, 'code' => 'settings_write_failed'];
        }
    }
    return ['success' => true, 'test' => $test];
}

function accountRecoverySaveMailConfig(string $username, string $appPassword, string $fromName, bool $enabled, string $mode = 'auto'): array
{
    global $conn;
    $username = accountRecoveryNormalizeGmail($username);
    $fromName = trim($fromName);
    $appPassword = preg_replace('/\s+/', '', $appPassword);
    $mode = accountRecoveryNormalizeTransportMode($mode);

    if ($username === '') return ['success' => false, 'message' => 'กรุณาระบุ Gmail ผู้ส่งให้ถูกต้อง'];
    if ($fromName === '' || strlen($fromName) > 120) return ['success' => false, 'message' => 'ชื่อผู้ส่งไม่ถูกต้อง'];
    if ($enabled && getCanonicalBaseUrl() === '') {
        return ['success' => false, 'message' => 'กรุณาตั้งค่า Site Base URL ให้เป็น HTTPS ก่อนเปิดระบบอีเมล'];
    }

    $currentUsername = accountRecoveryNormalizeGmail(getSetting('recovery_smtp_username', ''));
    $currentMode = accountRecoveryNormalizeTransportMode(getSetting('recovery_smtp_mode', 'auto'));
    if ($appPassword === '' && $currentUsername !== '' && $currentUsername !== $username) {
        return ['success' => false, 'message' => 'เมื่อเปลี่ยน Gmail ผู้ส่ง ต้องใส่ App Password ใหม่ที่สร้างจากบัญชี Gmail ใหม่นั้น'];
    }

    $credentialChanged = $appPassword !== '' || $currentUsername !== $username || $currentMode !== $mode;
    // New credentials or a transport change must be tested before customer-facing
    // recovery is re-enabled. This prevents a checked box from activating an
    // unverified password merely because the settings form was submitted.
    if ($credentialChanged) $enabled = false;

    if ($enabled) {
        $readiness = accountRecoveryActivationReadiness();
        if (empty($readiness['ready'])) {
            return [
                'success' => false,
                'message' => 'ยังเปิดระบบไม่ได้ กรุณาส่งอีเมลทดสอบให้สำเร็จก่อน',
                'code' => (string) ($readiness['code'] ?? 'not_ready'),
            ];
        }
    }

    $writes = [
        ['recovery_smtp_username', $username],
        ['recovery_from_name', $fromName],
        ['recovery_smtp_mode', $mode],
        ['recovery_mail_enabled', $enabled ? '1' : '0'],
    ];

    if ($appPassword !== '') {
        if (!preg_match('/^[A-Za-z0-9]{16}$/', $appPassword)) {
            return ['success' => false, 'message' => 'App Password ต้องเป็นรหัส 16 ตัวจาก Google ไม่ใช่รหัสผ่าน Gmail ปกติ'];
        }
        $encrypted = accountRecoveryEncryptSecret($appPassword);
        if ($encrypted === null) {
            return ['success' => false, 'message' => 'ไม่สามารถเข้ารหัส App Password ได้ กรุณาตรวจสิทธิ์เขียนโฟลเดอร์ private และส่วนขยาย OpenSSL'];
        }
        $writes[] = ['recovery_smtp_password', $encrypted];
        $writes[] = ['recovery_smtp_password_updated_at', date('Y-m-d H:i:s')];
        $writes[] = ['recovery_smtp_credential_state', 'untested'];
        $writes[] = ['recovery_mail_last_test_status', ''];
        $writes[] = ['recovery_mail_last_test_stage', ''];
        $writes[] = ['recovery_mail_last_test_smtp_code', ''];
        $writes[] = ['recovery_mail_delivery_verified', '0'];
        $writes[] = ['recovery_mail_delivery_verified_at', ''];
    } else {
        $storedPassword = (string) getSetting('recovery_smtp_password', '');
        if ($storedPassword === '') {
            return ['success' => false, 'message' => 'กรุณาระบุ App Password ของ Gmail'];
        }
        if (accountRecoveryDecryptSecret($storedPassword) === '') {
            return ['success' => false, 'message' => 'อ่าน App Password เดิมไม่ได้ ไฟล์ private/recovery_mail_secret.php อาจหายหรือถูกเปลี่ยน กรุณาใส่ App Password ใหม่'];
        }
        if ($credentialChanged) {
            $writes[] = ['recovery_smtp_credential_state', 'untested'];
            $writes[] = ['recovery_mail_last_test_status', ''];
            $writes[] = ['recovery_mail_last_test_stage', ''];
            $writes[] = ['recovery_mail_last_test_smtp_code', ''];
            $writes[] = ['recovery_mail_delivery_verified', '0'];
            $writes[] = ['recovery_mail_delivery_verified_at', ''];
        }
    }

    $conn->begin_transaction();
    try {
        foreach ($writes as $write) {
            if (!upsertSetting($write[0], $write[1])) {
                throw new RuntimeException('Unable to save setting: ' . $write[0]);
            }
        }
        $conn->commit();
        return ['success' => true, 'requires_test' => $credentialChanged, 'enabled' => $enabled];
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        error_log('Recovery mail settings update failed: ' . $e->getMessage());
        return ['success' => false, 'message' => 'ไม่สามารถบันทึกการตั้งค่าอีเมลได้'];
    }
}

function accountRecoveryClearStoredAppPassword(): bool
{
    global $conn;
    $conn->begin_transaction();
    try {
        $writes = [
            ['recovery_smtp_password', ''],
            ['recovery_smtp_password_updated_at', ''],
            ['recovery_smtp_credential_state', 'missing'],
            ['recovery_mail_enabled', '0'],
            ['recovery_mail_delivery_verified', '0'],
            ['recovery_mail_delivery_verified_at', ''],
        ];
        foreach ($writes as $write) {
            if (!upsertSetting($write[0], $write[1])) {
                throw new RuntimeException('Unable to clear setting: ' . $write[0]);
            }
        }
        $conn->commit();
        return true;
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        error_log('Recovery stored App Password clear failed: ' . $e->getMessage());
        return false;
    }
}

function accountRecoverySanitizeLogText($value, int $maxLength = 1000): string
{
    if (!is_scalar($value)) return '';
    $value = trim(str_replace(["\0", "\r"], ['', ''], (string) $value));
    $value = preg_replace('/[A-Za-z0-9+\/=]{80,}/', '[redacted]', $value);
    if (strlen($value) > $maxLength) $value = substr($value, 0, $maxLength) . '…';
    return $value;
}

function accountRecoveryLogMailEvent(array $entry): void
{
    global $conn;
    if (!accountRecoveryEnsureSchema()) return;

    $userId = !empty($entry['user_id']) ? (int) $entry['user_id'] : null;
    $actorId = !empty($entry['actor_id']) ? (int) $entry['actor_id'] : (isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null);
    $eventType = substr(preg_replace('/[^a-z0-9_.-]/i', '', (string) ($entry['event_type'] ?? 'mail')), 0, 40) ?: 'mail';
    $recipient = substr(accountRecoveryMaskEmail((string) ($entry['recipient'] ?? '')), 0, 190);
    $status = in_array((string) ($entry['status'] ?? ''), ['success', 'failed', 'warning'], true) ? (string) $entry['status'] : 'failed';
    $transport = substr(preg_replace('/[^a-z0-9_.-]/i', '', (string) ($entry['transport'] ?? '')), 0, 24);
    $stage = substr(preg_replace('/[^a-z0-9_.-]/i', '', (string) ($entry['stage'] ?? '')), 0, 40);
    $errorCode = substr(preg_replace('/[^a-z0-9_.-]/i', '', (string) ($entry['error_code'] ?? '')), 0, 80);
    $smtpCode = isset($entry['smtp_code']) && is_numeric($entry['smtp_code']) ? (int) $entry['smtp_code'] : null;
    $message = accountRecoverySanitizeLogText($entry['message'] ?? '', 500);
    $durationMs = max(0, min(2147483647, (int) ($entry['duration_ms'] ?? 0)));
    $requestIp = substr(function_exists('getClientIp') ? getClientIp() : (string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $detailsArray = is_array($entry['details'] ?? null) ? $entry['details'] : ['details' => $entry['details'] ?? ''];
    unset($detailsArray['password'], $detailsArray['app_password'], $detailsArray['token'], $detailsArray['body'], $detailsArray['html']);
    $details = json_encode($detailsArray, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    if (!is_string($details)) $details = '{}';
    if (strlen($details) > 16000) $details = substr($details, 0, 16000);

    $stmt = $conn->prepare('INSERT INTO account_recovery_mail_logs (user_id, actor_id, event_type, recipient, status, transport, stage, error_code, smtp_code, message, details, duration_ms, request_ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    if (!$stmt) {
        error_log('Recovery mail log prepare failed: ' . $conn->error);
        return;
    }
    $stmt->bind_param('iissssssissis', $userId, $actorId, $eventType, $recipient, $status, $transport, $stage, $errorCode, $smtpCode, $message, $details, $durationMs, $requestIp);
    if (!$stmt->execute()) error_log('Recovery mail log insert failed: ' . $stmt->error);
    $stmt->close();
}

function accountRecoveryGetMailLogs(int $limit = 100): array
{
    global $conn;
    if (!accountRecoveryEnsureSchema()) return [];
    $limit = max(1, min(200, $limit));
    $result = $conn->query('SELECT * FROM account_recovery_mail_logs ORDER BY id DESC LIMIT ' . $limit);
    if (!$result) return [];
    $rows = [];
    while ($row = $result->fetch_assoc()) $rows[] = $row;
    return $rows;
}

function accountRecoveryClearMailLogs(): bool
{
    global $conn;
    if (!accountRecoveryEnsureSchema()) return false;
    return (bool) $conn->query('DELETE FROM account_recovery_mail_logs');
}

function accountRecoveryReadSmtpResponse($socket): array
{
    $lines = [];
    $code = 0;
    $timedOut = false;
    while (is_resource($socket) && !feof($socket)) {
        $line = fgets($socket, 4096);
        if ($line === false) {
            $meta = stream_get_meta_data($socket);
            $timedOut = !empty($meta['timed_out']);
            break;
        }
        $lines[] = rtrim($line, "\r\n");
        if (preg_match('/^(\d{3})([ -])/', $line, $m)) {
            $code = (int) $m[1];
            if ($m[2] === ' ') break;
        } else {
            break;
        }
    }
    return [
        'code' => $code,
        'text' => accountRecoverySanitizeLogText(implode("\n", $lines), 2000),
        'timed_out' => $timedOut,
    ];
}

function accountRecoverySocketWriteAll($socket, string $data): bool
{
    $length = strlen($data);
    $offset = 0;
    while ($offset < $length) {
        $written = fwrite($socket, substr($data, $offset));
        if ($written === false || $written < 1) return false;
        $offset += $written;
    }
    return true;
}

function accountRecoverySmtpCommand($socket, string $command, array $expectedCodes): array
{
    if ($command !== '') {
        if (!accountRecoverySocketWriteAll($socket, $command . "\r\n")) {
            return ['success' => false, 'code' => 0, 'text' => 'write_failed', 'timed_out' => false];
        }
    }
    $response = accountRecoveryReadSmtpResponse($socket);
    $response['success'] = in_array((int) $response['code'], $expectedCodes, true);
    return $response;
}

function accountRecoveryEncodeHeader(string $value): string
{
    $value = str_replace(["\r", "\n"], '', $value);
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

function accountRecoveryCaptureWarning(callable $callback, string &$warning)
{
    $warning = '';
    set_error_handler(function ($severity, $message) use (&$warning) {
        $warning = accountRecoverySanitizeLogText($message, 1000);
        return true;
    });
    try {
        return $callback();
    } finally {
        restore_error_handler();
    }
}

function accountRecoveryTlsMethod(): int
{
    $method = 0;
    if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) $method |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
    if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) $method |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
    if ($method === 0 && defined('STREAM_CRYPTO_METHOD_TLS_CLIENT')) $method = STREAM_CRYPTO_METHOD_TLS_CLIENT;
    return $method;
}

function accountRecoveryFindCaFile(): string
{
    $candidates = [];
    $ini = trim((string) ini_get('openssl.cafile'));
    if ($ini !== '') $candidates[] = $ini;
    if (function_exists('openssl_get_cert_locations')) {
        $locations = openssl_get_cert_locations();
        if (!empty($locations['ini_cafile'])) $candidates[] = $locations['ini_cafile'];
        if (!empty($locations['default_cert_file'])) $candidates[] = $locations['default_cert_file'];
    }
    $candidates = array_merge($candidates, [
        '/etc/ssl/certs/ca-certificates.crt',
        '/etc/pki/tls/certs/ca-bundle.crt',
        '/etc/ssl/cert.pem',
    ]);
    foreach (array_unique($candidates) as $candidate) {
        if (is_string($candidate) && $candidate !== '' && is_file($candidate) && is_readable($candidate)) return $candidate;
    }
    return '';
}

function accountRecoveryBuildSslContext(string $host)
{
    $options = [
        'verify_peer' => true,
        'verify_peer_name' => true,
        'allow_self_signed' => false,
        'peer_name' => $host,
        'SNI_enabled' => true,
        'capture_peer_cert' => true,
        'capture_peer_cert_chain' => true,
    ];
    $caFile = accountRecoveryFindCaFile();
    if ($caFile !== '') $options['cafile'] = $caFile;
    return stream_context_create(['ssl' => $options]);
}

function accountRecoveryTransportPlan(string $mode): array
{
    $mode = accountRecoveryNormalizeTransportMode($mode);
    if ($mode === 'starttls_587') return ['starttls_587'];
    if ($mode === 'smtps_465') return ['smtps_465'];
    return ['starttls_587', 'smtps_465'];
}

function accountRecoverySmtpResponseForLog(array $response): array
{
    return [
        'code' => isset($response['code']) && is_numeric($response['code']) ? (int) $response['code'] : 0,
        'text' => accountRecoverySanitizeLogText($response['text'] ?? '', 2000),
        'timed_out' => !empty($response['timed_out']),
    ];
}

function accountRecoveryExtractGmailQueueId(string $responseText): string
{
    $responseText = trim($responseText);
    if (preg_match('/\s([A-Za-z0-9][A-Za-z0-9._-]{5,})\s+-\s+gsmtp\s*$/i', $responseText, $m) === 1) {
        return substr((string) $m[1], 0, 190);
    }
    return '';
}

function accountRecoveryExtractEnhancedStatusCode(string $responseText): string
{
    if (preg_match('/^\d{3}\s+([245]\.\d+\.\d+)/', trim($responseText), $m) === 1) {
        return (string) $m[1];
    }
    return '';
}

function accountRecoveryBuildMimeMessage(array $config, string $recipient, string $subject, string $html, string $text, string $helloHost, array &$messageMetadata = []): string
{
    $boundary = 'b_' . bin2hex(random_bytes(12));
    if ($text === '') $text = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html)));
    $messageIdDomain = preg_replace('/[^a-z0-9.-]/i', '', $helloHost) ?: 'localhost';
    $messageDate = date(DATE_RFC2822);
    $messageId = bin2hex(random_bytes(12)) . '@' . $messageIdDomain;
    $headers = [
        'Date: ' . $messageDate,
        'From: ' . accountRecoveryEncodeHeader((string) $config['from_name']) . ' <' . $config['username'] . '>',
        'To: <' . $recipient . '>',
        'Subject: ' . accountRecoveryEncodeHeader($subject),
        'Message-ID: <' . $messageId . '>',
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        'Auto-Submitted: auto-generated',
    ];
    $body = implode("\r\n", $headers) . "\r\n\r\n";
    $body .= '--' . $boundary . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode($text), 76, "\r\n") . "\r\n";
    $body .= '--' . $boundary . "\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode($html), 76, "\r\n") . "\r\n";
    $body .= '--' . $boundary . "--\r\n";
    $body = preg_replace('/(?m)^\./', '..', $body);
    $wireMessage = rtrim($body, "\r\n") . "\r\n.\r\n";

    $recipientDomain = '';
    $atPos = strrpos($recipient, '@');
    if ($atPos !== false) $recipientDomain = strtolower(substr($recipient, $atPos + 1));
    $messageMetadata = [
        'message_id' => '<' . $messageId . '>',
        'date_header' => $messageDate,
        'sender' => accountRecoveryMaskEmail((string) ($config['username'] ?? '')),
        'recipient' => accountRecoveryMaskEmail($recipient),
        'recipient_domain' => $recipientDomain,
        'mime_type' => 'multipart/alternative',
        'content_transfer_encoding' => 'base64',
        'auto_submitted' => 'auto-generated',
        'has_attachments' => false,
        'subject_bytes' => strlen($subject),
        'subject_chars' => function_exists('mb_strlen') ? mb_strlen($subject, 'UTF-8') : null,
        'text_bytes' => strlen($text),
        'html_bytes' => strlen($html),
        'wire_bytes' => strlen($wireMessage),
        'sensitive_content_logged' => false,
        'sensitive_content_note' => 'Subject/body/OTP are intentionally not stored in mail diagnostics',
    ];

    return $wireMessage;
}

function accountRecoveryExtractTlsDetails($socket): array
{
    $details = [];
    if (!is_resource($socket)) return $details;
    $meta = stream_get_meta_data($socket);
    if (!empty($meta['crypto']) && is_array($meta['crypto'])) {
        $details['tls'] = $meta['crypto'];
    }
    $params = stream_context_get_params($socket);
    $cert = $params['options']['ssl']['peer_certificate'] ?? null;
    if ($cert && function_exists('openssl_x509_parse')) {
        $parsed = openssl_x509_parse($cert, false);
        if (is_array($parsed)) {
            $details['certificate'] = [
                'subject' => $parsed['name'] ?? '',
                'issuer' => isset($parsed['issuer']) && is_array($parsed['issuer']) ? implode(', ', $parsed['issuer']) : '',
                'valid_from' => isset($parsed['validFrom_time_t']) ? date('c', (int) $parsed['validFrom_time_t']) : '',
                'valid_to' => isset($parsed['validTo_time_t']) ? date('c', (int) $parsed['validTo_time_t']) : '',
            ];
        }
    }
    return $details;
}

function accountRecoverySmtpFailure(string $transport, string $stage, string $errorCode, string $message, int $smtpCode, array $details, float $started): array
{
    $durationMs = (int) round((microtime(true) - $started) * 1000);
    $details['finished_at'] = date('c');
    if (!isset($details['timings_ms']) || !is_array($details['timings_ms'])) $details['timings_ms'] = [];
    $details['timings_ms']['total'] = $durationMs;
    $details['delivery_state'] = 'not_accepted';
    $details['final_mailbox_delivery_confirmed'] = false;
    return [
        'success' => false,
        'transport' => $transport,
        'stage' => $stage,
        'error_code' => $errorCode,
        'smtp_code' => $smtpCode > 0 ? $smtpCode : null,
        'message' => accountRecoverySanitizeLogText($message, 500),
        'details' => $details,
        'duration_ms' => $durationMs,
    ];
}

function accountRecoveryRunSmtpAttempt(array $config, string $transport, string $recipient, string $subject, string $html, string $text, bool $sendMessage): array
{
    $started = microtime(true);
    $host = (string) $config['host'];
    $port = $transport === 'smtps_465' ? 465 : 587;
    $scheme = $transport === 'smtps_465' ? 'ssl' : 'tcp';
    $details = [
        'attempt_id' => substr(hash('sha256', $started . '|' . $transport . '|' . $recipient . '|' . getmypid()), 0, 16),
        'started_at' => date('c'),
        'endpoint' => $host . ':' . $port,
        'transport' => $transport,
        'configured_mode' => (string) ($config['mode'] ?? ''),
        'send_message' => $sendMessage,
        'php_version' => PHP_VERSION,
        'openssl' => extension_loaded('openssl'),
        'ca_file' => accountRecoveryFindCaFile(),
        'sender' => accountRecoveryMaskEmail((string) ($config['username'] ?? '')),
        'recipient' => accountRecoveryMaskEmail($recipient),
        'credential_state' => (string) ($config['credential_state'] ?? ''),
        'password_updated_at' => (string) ($config['password_updated_at'] ?? ''),
        'connection' => [
            'host' => $host,
            'port' => $port,
            'scheme' => $scheme,
            'connect_timeout_seconds' => 15,
            'socket_timeout_seconds' => 20,
        ],
        'smtp_trace' => [],
        'timings_ms' => [],
    ];

    if (!function_exists('stream_socket_client')) {
        return accountRecoverySmtpFailure($transport, 'preflight', 'stream_socket_missing', 'PHP stream_socket_client is unavailable', 0, $details, $started);
    }
    if (!extension_loaded('openssl') || !function_exists('stream_socket_enable_crypto')) {
        return accountRecoverySmtpFailure($transport, 'preflight', 'openssl_missing', 'PHP OpenSSL extension is unavailable', 0, $details, $started);
    }

    $dns = @gethostbynamel($host);
    $details['dns_ipv4'] = is_array($dns) ? array_values(array_unique($dns)) : [];
    $details['timings_ms']['dns_complete'] = (int) round((microtime(true) - $started) * 1000);
    if (!is_array($dns) || count($dns) < 1) {
        return accountRecoverySmtpFailure($transport, 'dns', 'dns_failed', 'Unable to resolve smtp.gmail.com', 0, $details, $started);
    }

    $context = accountRecoveryBuildSslContext($host);
    $errno = 0;
    $errstr = '';
    $warning = '';
    $endpoint = $scheme . '://' . $host . ':' . $port;
    $socket = accountRecoveryCaptureWarning(function () use ($endpoint, &$errno, &$errstr, $context) {
        return stream_socket_client($endpoint, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
    }, $warning);
    if (!is_resource($socket)) {
        $details['php_warning'] = $warning;
        $details['socket_errno'] = $errno;
        return accountRecoverySmtpFailure($transport, 'connect', 'connect_failed', $errstr !== '' ? $errstr : ($warning !== '' ? $warning : 'SMTP connection failed'), 0, $details, $started);
    }
    stream_set_timeout($socket, 20);
    $details['connection']['local_socket'] = (string) (stream_socket_get_name($socket, false) ?: '');
    $details['connection']['remote_socket'] = (string) (stream_socket_get_name($socket, true) ?: '');
    $details['connection']['connected_at'] = date('c');
    $details['timings_ms']['connected'] = (int) round((microtime(true) - $started) * 1000);

    $close = function () use (&$socket): void {
        if (is_resource($socket)) {
            @accountRecoverySocketWriteAll($socket, "QUIT\r\n");
            @fclose($socket);
        }
    };

    try {
        $helloHost = parse_url(getCanonicalBaseUrl(), PHP_URL_HOST);
        if (!is_string($helloHost) || !preg_match('/^[a-z0-9.-]+$/i', $helloHost)) $helloHost = 'localhost';
        $details['ehlo_host'] = $helloHost;

        $response = accountRecoverySmtpCommand($socket, '', [220]);
        $details['smtp_trace']['greeting'] = accountRecoverySmtpResponseForLog($response);
        $details['timings_ms']['greeting'] = (int) round((microtime(true) - $started) * 1000);
        if (!$response['success']) {
            $close();
            return accountRecoverySmtpFailure($transport, 'greeting', $response['timed_out'] ? 'smtp_timeout' : 'greeting_failed', $response['text'], (int) $response['code'], $details, $started);
        }

        $response = accountRecoverySmtpCommand($socket, 'EHLO ' . $helloHost, [250]);
        $details['ehlo_before_tls'] = $response['text'];
        $details['smtp_trace']['ehlo_before_tls'] = accountRecoverySmtpResponseForLog($response);
        $details['timings_ms']['ehlo_before_tls'] = (int) round((microtime(true) - $started) * 1000);
        if (!$response['success']) {
            $close();
            return accountRecoverySmtpFailure($transport, 'ehlo', $response['timed_out'] ? 'smtp_timeout' : 'ehlo_failed', $response['text'], (int) $response['code'], $details, $started);
        }

        if ($transport === 'starttls_587') {
            if (stripos($response['text'], 'STARTTLS') === false) {
                $close();
                return accountRecoverySmtpFailure($transport, 'starttls', 'starttls_not_advertised', 'SMTP server did not advertise STARTTLS', (int) $response['code'], $details, $started);
            }
            $response = accountRecoverySmtpCommand($socket, 'STARTTLS', [220]);
            $details['smtp_trace']['starttls'] = accountRecoverySmtpResponseForLog($response);
            $details['timings_ms']['starttls_response'] = (int) round((microtime(true) - $started) * 1000);
            if (!$response['success']) {
                $close();
                return accountRecoverySmtpFailure($transport, 'starttls', $response['timed_out'] ? 'smtp_timeout' : 'starttls_rejected', $response['text'], (int) $response['code'], $details, $started);
            }
            $cryptoWarning = '';
            $method = accountRecoveryTlsMethod();
            $cryptoOk = accountRecoveryCaptureWarning(function () use ($socket, $method) {
                return stream_socket_enable_crypto($socket, true, $method);
            }, $cryptoWarning);
            if ($cryptoOk !== true) {
                $details['php_warning'] = $cryptoWarning;
                $close();
                return accountRecoverySmtpFailure($transport, 'tls', 'tls_failed', $cryptoWarning !== '' ? $cryptoWarning : 'TLS negotiation failed', 0, $details, $started);
            }
            $details = array_merge($details, accountRecoveryExtractTlsDetails($socket));
            $details['timings_ms']['tls_established'] = (int) round((microtime(true) - $started) * 1000);
            $response = accountRecoverySmtpCommand($socket, 'EHLO ' . $helloHost, [250]);
            $details['ehlo_after_tls'] = $response['text'];
            $details['smtp_trace']['ehlo_after_tls'] = accountRecoverySmtpResponseForLog($response);
            $details['timings_ms']['ehlo_after_tls'] = (int) round((microtime(true) - $started) * 1000);
            if (!$response['success']) {
                $close();
                return accountRecoverySmtpFailure($transport, 'ehlo_tls', $response['timed_out'] ? 'smtp_timeout' : 'ehlo_after_tls_failed', $response['text'], (int) $response['code'], $details, $started);
            }
        } else {
            $details = array_merge($details, accountRecoveryExtractTlsDetails($socket));
        }

        $response = accountRecoverySmtpCommand($socket, 'AUTH LOGIN', [334]);
        $details['smtp_trace']['auth_login'] = accountRecoverySmtpResponseForLog($response);
        $details['timings_ms']['auth_login'] = (int) round((microtime(true) - $started) * 1000);
        if (!$response['success']) {
            $close();
            return accountRecoverySmtpFailure($transport, 'auth', 'auth_method_rejected', $response['text'], (int) $response['code'], $details, $started);
        }
        $response = accountRecoverySmtpCommand($socket, base64_encode($config['username']), [334]);
        $details['smtp_trace']['auth_username'] = accountRecoverySmtpResponseForLog($response);
        $details['timings_ms']['auth_username'] = (int) round((microtime(true) - $started) * 1000);
        if (!$response['success']) {
            $close();
            return accountRecoverySmtpFailure($transport, 'auth', 'username_rejected', $response['text'], (int) $response['code'], $details, $started);
        }
        $response = accountRecoverySmtpCommand($socket, base64_encode($config['password']), [235]);
        $details['smtp_trace']['auth_result'] = accountRecoverySmtpResponseForLog($response);
        $details['timings_ms']['authenticated'] = (int) round((microtime(true) - $started) * 1000);
        if (!$response['success']) {
            $code = (int) $response['code'];
            $errorCode = $code === 534 ? 'app_password_required' : ($code === 535 ? 'auth_failed' : 'password_rejected');
            $close();
            return accountRecoverySmtpFailure($transport, 'auth', $errorCode, $response['text'], $code, $details, $started);
        }

        if (!$sendMessage) {
            $close();
            return [
                'success' => true,
                'transport' => $transport,
                'stage' => 'authenticated',
                'error_code' => '',
                'smtp_code' => 235,
                'message' => 'SMTP authentication succeeded',
                'details' => $details,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ];
        }

        $response = accountRecoverySmtpCommand($socket, 'MAIL FROM:<' . $config['username'] . '>', [250]);
        $details['smtp_trace']['mail_from'] = accountRecoverySmtpResponseForLog($response);
        $details['timings_ms']['mail_from'] = (int) round((microtime(true) - $started) * 1000);
        if (!$response['success']) {
            $close();
            return accountRecoverySmtpFailure($transport, 'sender', 'sender_rejected', $response['text'], (int) $response['code'], $details, $started);
        }
        $response = accountRecoverySmtpCommand($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
        $details['smtp_trace']['rcpt_to'] = accountRecoverySmtpResponseForLog($response);
        $details['timings_ms']['rcpt_to'] = (int) round((microtime(true) - $started) * 1000);
        if (!$response['success']) {
            $close();
            return accountRecoverySmtpFailure($transport, 'recipient', 'recipient_rejected', $response['text'], (int) $response['code'], $details, $started);
        }
        $response = accountRecoverySmtpCommand($socket, 'DATA', [354]);
        $details['smtp_trace']['data'] = accountRecoverySmtpResponseForLog($response);
        $details['timings_ms']['data_ready'] = (int) round((microtime(true) - $started) * 1000);
        if (!$response['success']) {
            $close();
            return accountRecoverySmtpFailure($transport, 'data', 'data_rejected', $response['text'], (int) $response['code'], $details, $started);
        }

        $messageMetadata = [];
        $wireMessage = accountRecoveryBuildMimeMessage($config, $recipient, $subject, $html, $text, $helloHost, $messageMetadata);
        $details['message'] = $messageMetadata;
        $details['timings_ms']['message_built'] = (int) round((microtime(true) - $started) * 1000);
        if (!accountRecoverySocketWriteAll($socket, $wireMessage)) {
            $close();
            return accountRecoverySmtpFailure($transport, 'message', 'message_write_failed', 'Unable to write the message body to the SMTP socket', 0, $details, $started);
        }
        $details['timings_ms']['message_written'] = (int) round((microtime(true) - $started) * 1000);
        $response = accountRecoveryReadSmtpResponse($socket);
        $details['smtp_trace']['queue_acceptance'] = accountRecoverySmtpResponseForLog($response);
        $details['timings_ms']['queue_response'] = (int) round((microtime(true) - $started) * 1000);
        if ((int) $response['code'] !== 250) {
            $close();
            return accountRecoverySmtpFailure($transport, 'message', $response['timed_out'] ? 'smtp_timeout' : 'message_rejected', $response['text'], (int) $response['code'], $details, $started);
        }
        $durationMs = (int) round((microtime(true) - $started) * 1000);
        $details['queue_response'] = $response['text'];
        $details['gmail_queue_id'] = accountRecoveryExtractGmailQueueId((string) $response['text']);
        $details['enhanced_status_code'] = accountRecoveryExtractEnhancedStatusCode((string) $response['text']);
        $details['accepted_at'] = date('c');
        $details['finished_at'] = $details['accepted_at'];
        $details['delivery_state'] = 'accepted_by_gmail_smtp';
        $details['final_mailbox_delivery_confirmed'] = false;
        $details['delivery_note'] = 'SMTP 250 confirms Gmail accepted the message for processing; it does not prove Inbox/Spam placement or final mailbox delivery';
        $details['timings_ms']['total'] = $durationMs;
        $close();
        return [
            'success' => true,
            'transport' => $transport,
            'stage' => 'sent',
            'error_code' => '',
            'smtp_code' => 250,
            'message' => 'Message accepted by Gmail SMTP',
            'details' => $details,
            'duration_ms' => $durationMs,
        ];
    } catch (Throwable $e) {
        $details['exception'] = get_class($e);
        $close();
        return accountRecoverySmtpFailure($transport, 'exception', 'smtp_exception', $e->getMessage(), 0, $details, $started);
    }
}

function accountRecoveryMayTryFallback(array $result): bool
{
    return in_array((string) ($result['stage'] ?? ''), ['preflight', 'dns', 'connect', 'greeting', 'ehlo', 'starttls', 'tls', 'ehlo_tls'], true);
}

function accountRecoveryRecordAttempt(array $result, string $recipient, array $context): void
{
    $details = is_array($result['details'] ?? null) ? $result['details'] : [];
    if (!empty($context['credential_source'])) {
        $details['credential_source'] = substr(preg_replace('/[^a-z0-9_.-]/i', '', (string) $context['credential_source']), 0, 40);
    }
    $requestPath = '';
    if (isset($_SERVER['REQUEST_URI']) && is_scalar($_SERVER['REQUEST_URI'])) {
        $parsedPath = parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
        if (is_string($parsedPath)) $requestPath = accountRecoverySanitizeLogText($parsedPath, 500);
    }
    $details['request_context'] = [
        'event_type' => substr(preg_replace('/[^a-z0-9_.-]/i', '', (string) ($context['event_type'] ?? 'mail')), 0, 40) ?: 'mail',
        'user_id' => !empty($context['user_id']) ? (int) $context['user_id'] : null,
        'actor_id' => !empty($context['actor_id']) ? (int) $context['actor_id'] : (isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null),
        'request_method' => isset($_SERVER['REQUEST_METHOD']) && is_scalar($_SERVER['REQUEST_METHOD']) ? substr((string) $_SERVER['REQUEST_METHOD'], 0, 16) : '',
        'request_path' => $requestPath,
    ];
    accountRecoveryLogMailEvent([
        'user_id' => $context['user_id'] ?? null,
        'actor_id' => $context['actor_id'] ?? null,
        'event_type' => $context['event_type'] ?? 'mail',
        'recipient' => $recipient,
        'status' => !empty($result['success']) ? 'success' : 'failed',
        'transport' => $result['transport'] ?? '',
        'stage' => $result['stage'] ?? '',
        'error_code' => $result['error_code'] ?? '',
        'smtp_code' => $result['smtp_code'] ?? null,
        'message' => $result['message'] ?? '',
        'details' => $details,
        'duration_ms' => $result['duration_ms'] ?? 0,
    ]);
}

function accountRecoveryPreflightFailure(string $errorCode, string $message, string $recipient, array $context): array
{
    $result = [
        'success' => false,
        'transport' => '',
        'stage' => 'preflight',
        'error_code' => $errorCode,
        'smtp_code' => null,
        'message' => $message,
        'details' => [],
        'duration_ms' => 0,
    ];
    accountRecoveryRecordAttempt($result, $recipient, $context);
    return $result;
}

function accountRecoverySendMail(string $recipient, string $subject, string $html, string $text = '', bool $ignoreEnabled = false, array $context = []): array
{
    $recipient = accountRecoveryNormalizeGmail($recipient);
    $context['event_type'] = $context['event_type'] ?? 'mail';
    if ($recipient === '') return accountRecoveryPreflightFailure('invalid_recipient', 'Recipient is not a valid Gmail address', '', $context);

    $config = accountRecoveryGetMailConfig();
    if (!$config['enabled'] && !$ignoreEnabled) {
        return accountRecoveryPreflightFailure('mail_disabled', 'Password recovery email is disabled', $recipient, $context);
    }
    if (!$config['configured']) {
        $code = $config['config_error'] !== '' ? $config['config_error'] : 'mail_not_configured';
        return accountRecoveryPreflightFailure($code, 'Gmail SMTP settings are incomplete or the encrypted password cannot be read', $recipient, $context);
    }

    $attempts = [];
    foreach (accountRecoveryTransportPlan((string) $config['mode']) as $transport) {
        $result = accountRecoveryRunSmtpAttempt($config, $transport, $recipient, $subject, $html, $text, true);
        $attempts[] = $result;
        accountRecoveryRecordAttempt($result, $recipient, $context);
        if (!empty($result['success'])) {
            $result['attempts'] = $attempts;
            return $result;
        }
        if (!accountRecoveryMayTryFallback($result)) break;
    }
    $final = end($attempts);
    if (!is_array($final)) $final = accountRecoveryPreflightFailure('smtp_send_failed', 'SMTP send failed', $recipient, $context);
    $final['attempts'] = $attempts;
    return $final;
}

function accountRecoveryTestConnection(array $context = []): array
{
    $config = accountRecoveryGetMailConfig();
    $recipient = $config['username'] ?? '';
    $context['event_type'] = $context['event_type'] ?? 'smtp_diagnostic';
    if (!$config['configured']) {
        $code = $config['config_error'] !== '' ? $config['config_error'] : 'mail_not_configured';
        return accountRecoveryPreflightFailure($code, 'Gmail SMTP settings are incomplete or the encrypted password cannot be read', $recipient, $context);
    }

    $attempts = [];
    foreach (accountRecoveryTransportPlan((string) $config['mode']) as $transport) {
        $result = accountRecoveryRunSmtpAttempt($config, $transport, $recipient, '', '', '', false);
        $attempts[] = $result;
        accountRecoveryRecordAttempt($result, $recipient, $context);
        if (!empty($result['success'])) {
            $result['attempts'] = $attempts;
            return $result;
        }
        if (!accountRecoveryMayTryFallback($result)) break;
    }
    $final = end($attempts);
    if (!is_array($final)) $final = accountRecoveryPreflightFailure('smtp_diagnostic_failed', 'SMTP diagnostic failed', $recipient, $context);
    $final['attempts'] = $attempts;
    return $final;
}

function accountRecoveryDescribeMailResult(array $result, bool $isTh = true): string
{
    $isSuccess = !empty($result['success']);
    $code = (string) ($result['error_code'] ?? '');
    $mapTh = [
        '' => 'สำเร็จ',
        'invalid_recipient' => 'Gmail ผู้รับไม่ถูกต้อง',
        'mail_disabled' => 'ระบบส่งอีเมลยังถูกปิด',
        'mail_not_configured' => 'การตั้งค่า Gmail SMTP ยังไม่ครบ',
        'secret_missing' => 'ไม่พบกุญแจระบบอีเมลใน private/database.php หรือไฟล์เดิม',
        'secret_file_missing' => 'ไฟล์ private/recovery_mail_secret.php หาย ทำให้ถอดรหัส App Password เดิมไม่ได้',
        'secret_file_unreadable' => 'เว็บเซิร์ฟเวอร์อ่านไฟล์ลับของระบบอีเมลไม่ได้',
        'password_decrypt_failed' => 'ถอดรหัส App Password ไม่สำเร็จ ไฟล์ลับอาจไม่ตรงกับข้อมูลในฐานข้อมูล',
        'stream_socket_missing' => 'PHP ไม่มีฟังก์ชันเชื่อมต่อ Socket',
        'openssl_missing' => 'PHP ยังไม่ได้เปิดส่วนขยาย OpenSSL',
        'dns_failed' => 'เซิร์ฟเวอร์หา IP ของ smtp.gmail.com ไม่สำเร็จ',
        'connect_failed' => 'โฮสติ้งเชื่อมต่อ Gmail SMTP ไม่ได้ พอร์ตอาจถูกบล็อก',
        'greeting_failed' => 'เชื่อมต่อได้แต่ไม่ได้รับคำทักทายจาก Gmail SMTP',
        'ehlo_failed' => 'Gmail SMTP ไม่ยอมรับคำสั่งเริ่มต้น EHLO',
        'starttls_not_advertised' => 'เซิร์ฟเวอร์ไม่รองรับ STARTTLS บนการเชื่อมต่อนี้',
        'starttls_rejected' => 'Gmail SMTP ปฏิเสธการเริ่ม STARTTLS',
        'tls_failed' => 'สร้างการเชื่อมต่อ TLS ไม่สำเร็จ มักเกี่ยวกับ OpenSSL หรือ CA Certificate ของโฮสติ้ง',
        'ehlo_after_tls_failed' => 'เริ่ม TLS แล้วแต่ EHLO รอบที่สองล้มเหลว',
        'auth_method_rejected' => 'Gmail ไม่ยอมรับวิธีเข้าสู่ระบบ SMTP',
        'username_rejected' => 'Gmail ปฏิเสธชื่อบัญชีผู้ส่ง',
        'app_password_required' => 'Google ต้องการ App Password และบัญชีต้องเปิดการยืนยันแบบ 2 ขั้นตอน',
        'auth_failed' => 'Gmail ปฏิเสธ App Password กรุณาสร้างรหัส 16 ตัวใหม่',
        'password_rejected' => 'Gmail ปฏิเสธข้อมูลเข้าสู่ระบบ',
        'sender_rejected' => 'Gmail ปฏิเสธอีเมลผู้ส่ง',
        'recipient_rejected' => 'Gmail ปฏิเสธอีเมลผู้รับ',
        'data_rejected' => 'Gmail ไม่อนุญาตให้ส่งเนื้อหาอีเมล',
        'message_write_failed' => 'การเชื่อมต่อขาดระหว่างส่งเนื้อหาอีเมล',
        'message_rejected' => 'Gmail รับการเชื่อมต่อแต่ปฏิเสธข้อความ',
        'smtp_timeout' => 'การเชื่อมต่อ SMTP หมดเวลา',
        'smtp_exception' => 'เกิดข้อผิดพลาดภายในระบบ SMTP',
    ];
    $mapEn = [
        '' => 'Success',
        'invalid_recipient' => 'The recipient Gmail address is invalid.',
        'mail_disabled' => 'Password recovery email is disabled.',
        'mail_not_configured' => 'Gmail SMTP settings are incomplete.',
        'secret_file_missing' => 'The recovery mail secret file is missing.',
        'secret_file_unreadable' => 'The recovery mail secret file is unreadable.',
        'password_decrypt_failed' => 'The stored App Password cannot be decrypted.',
        'stream_socket_missing' => 'PHP stream socket support is unavailable.',
        'openssl_missing' => 'The PHP OpenSSL extension is unavailable.',
        'dns_failed' => 'The server cannot resolve smtp.gmail.com.',
        'connect_failed' => 'The hosting server cannot connect to Gmail SMTP.',
        'tls_failed' => 'TLS negotiation failed.',
        'auth_failed' => 'Gmail rejected the App Password.',
        'app_password_required' => 'Google requires an App Password and 2-Step Verification.',
        'recipient_rejected' => 'Gmail rejected the recipient.',
        'message_rejected' => 'Gmail rejected the message.',
        'smtp_timeout' => 'The SMTP connection timed out.',
        'smtp_exception' => 'An internal SMTP error occurred.',
    ];
    $base = $isTh ? ($mapTh[$code] ?? 'ส่งอีเมลไม่สำเร็จ') : ($mapEn[$code] ?? 'Unable to send email.');
    if ($isSuccess) $base = $isTh ? 'เชื่อมต่อและยืนยันตัวตนกับ Gmail SMTP สำเร็จ' : 'Gmail SMTP connection and authentication succeeded.';
    $extras = [];
    if (!empty($result['transport'])) $extras[] = (string) $result['transport'];
    if (!empty($result['smtp_code'])) $extras[] = 'SMTP ' . (int) $result['smtp_code'];
    if (!empty($result['stage'])) $extras[] = 'ขั้นตอน ' . (string) $result['stage'];
    $raw = accountRecoverySanitizeLogText($result['message'] ?? '', 300);
    if (!$isSuccess && $raw !== '' && !in_array($raw, ['SMTP connection failed', 'Gmail SMTP settings are incomplete or the encrypted password cannot be read'], true)) {
        $extras[] = $raw;
    }
    return $base . ($extras ? ' (' . implode(' · ', $extras) . ')' : '');
}

function accountRecoveryStoreTestResult(array $result): bool
{
    $success = !empty($result['success']);
    $stage = (string) ($result['stage'] ?? '');
    $smtpCode = isset($result['smtp_code']) ? (string) (int) $result['smtp_code'] : '';
    $writes = [
        ['recovery_mail_last_test_status', $success ? 'success' : 'failed'],
        ['recovery_mail_last_test_stage', $stage],
        ['recovery_mail_last_test_smtp_code', $smtpCode],
        ['recovery_mail_last_test_at', date('Y-m-d H:i:s')],
        ['recovery_mail_last_test_transport', (string) ($result['transport'] ?? '')],
        ['recovery_mail_last_test_message', accountRecoveryDescribeMailResult($result, true)],
    ];

    $authErrors = ['auth_failed', 'password_rejected', 'username_rejected', 'app_password_required'];
    if ($success) {
        $writes[] = ['recovery_smtp_credential_state', 'valid'];
        $writes[] = ['recovery_smtp_last_verified_at', date('Y-m-d H:i:s')];
        if ($stage === 'sent' && (int) ($result['smtp_code'] ?? 0) === 250) {
            $writes[] = ['recovery_mail_delivery_verified', '1'];
            $writes[] = ['recovery_mail_delivery_verified_at', date('Y-m-d H:i:s')];
        }
    } elseif (in_array((string) ($result['error_code'] ?? ''), $authErrors, true)) {
        $writes[] = ['recovery_smtp_credential_state', 'invalid'];
        $writes[] = ['recovery_mail_delivery_verified', '0'];
        $writes[] = ['recovery_mail_delivery_verified_at', ''];
        $writes[] = ['recovery_mail_enabled', '0'];
    }

    $ok = true;
    foreach ($writes as $write) {
        if (!upsertSetting($write[0], $write[1])) {
            error_log('Unable to store recovery mail test setting: ' . $write[0]);
            $ok = false;
        }
    }
    return $ok;
}

function accountRecoveryBuildResetEmail(array $user, string $resetUrl): array
{
    $branding = getStoreBranding();
    $siteName = trim((string) getSetting('site_name', $branding['title_text'] ?? 'Store'));
    if ($siteName === '') $siteName = 'Store';
    $username = htmlspecialchars((string) ($user['username'] ?? ''), ENT_QUOTES, 'UTF-8');
    $safeUrl = htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8');
    $safeSite = htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8');
    $subject = 'รีเซ็ตรหัสผ่าน - ' . $siteName;
    $html = '<!doctype html><html><body style="margin:0;background:#0d0d10;color:#e5e7eb;font-family:Arial,sans-serif">'
        . '<div style="max-width:560px;margin:32px auto;padding:28px;background:#17171c;border:1px solid #2f3038;border-radius:16px">'
        . '<h2 style="margin-top:0;color:#ffffff">รีเซ็ตรหัสผ่าน</h2>'
        . '<p>สวัสดี <strong>' . $username . '</strong></p>'
        . '<p>มีคำขอตั้งรหัสผ่านใหม่สำหรับบัญชีของคุณที่ ' . $safeSite . '</p>'
        . '<p style="margin:28px 0"><a href="' . $safeUrl . '" style="display:inline-block;background:#2563eb;color:#fff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:bold">ตั้งรหัสผ่านใหม่</a></p>'
        . '<p style="color:#9ca3af;font-size:14px">ลิงก์นี้ใช้ได้ครั้งเดียวและหมดอายุภายใน 30 นาที หากคุณไม่ได้เป็นผู้ขอ สามารถละเว้นอีเมลฉบับนี้ได้</p>'
        . '<p style="color:#6b7280;font-size:12px;word-break:break-all">' . $safeUrl . '</p>'
        . '</div></body></html>';
    $text = "สวัสดี " . (string) ($user['username'] ?? '') . "\n\nตั้งรหัสผ่านใหม่ได้ที่:\n" . $resetUrl . "\n\nลิงก์หมดอายุภายใน 30 นาทีและใช้ได้ครั้งเดียว";
    return ['subject' => $subject, 'html' => $html, 'text' => $text];
}

function accountRecoveryLogResetDecision(string $email, string $status, string $code, string $message, ?int $userId = null, array $details = []): void
{
    accountRecoveryLogMailEvent([
        'user_id' => $userId,
        'event_type' => 'password_reset_request',
        'recipient' => $email,
        'status' => in_array($status, ['success', 'failed', 'warning'], true) ? $status : 'warning',
        'stage' => 'request',
        'error_code' => $code,
        'message' => $message,
        'details' => $details,
    ]);
}

function accountRecoveryRequestPasswordReset(string $email, bool $diagnostic = false): array
{
    global $conn;
    $generic = ['success' => true, 'message' => 'หาก Gmail นี้ตรงกับบัญชี ระบบจะส่งลิงก์ตั้งรหัสผ่านใหม่ให้'];
    $originalEmail = trim($email);
    $normalizedEmail = accountRecoveryNormalizeGmail($email);
    $finish = static function (
        bool $success,
        string $code,
        string $message,
        ?int $userId = null,
        array $extra = []
    ) use ($diagnostic, $generic, &$normalizedEmail, $originalEmail): array {
        if (!$diagnostic) return $generic;
        return array_merge([
            'success' => $success,
            'code' => $code,
            'message' => $message,
            'user_id' => $userId,
            'recipient' => accountRecoveryMaskEmail($normalizedEmail !== '' ? $normalizedEmail : $originalEmail),
        ], $extra);
    };

    if (!accountRecoveryEnsureSchema()) {
        accountRecoveryLogResetDecision($originalEmail, 'failed', 'schema_unavailable', 'Password reset schema is unavailable');
        return $finish(false, 'schema_unavailable', 'ตารางระบบรีเซ็ตรหัสผ่านยังไม่พร้อม');
    }
    $conn->query("DELETE FROM auth_password_reset_tokens WHERE used_at IS NOT NULL OR expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");

    if ($normalizedEmail === '') {
        accountRecoveryLogResetDecision($originalEmail, 'warning', 'invalid_gmail', 'The submitted address is not a valid Gmail address');
        return $finish(false, 'invalid_gmail', 'Gmail ไม่ถูกต้อง');
    }

    $rateKey = 'password_reset_' . hash('sha256', $normalizedEmail);
    if (!checkRateLimit($rateKey, 5, 3600)) {
        accountRecoveryLogResetDecision($normalizedEmail, 'warning', 'rate_limited', 'Password reset request rate limit reached');
        return $finish(false, 'rate_limited', 'คำขอมากเกินไป กรุณารอแล้วลองใหม่');
    }

    $stmt = $conn->prepare("SELECT id, username, email, role, status FROM users WHERE LOWER(email) = ? AND role IN ('user','reseller') LIMIT 1");
    if (!$stmt) {
        accountRecoveryLogResetDecision($normalizedEmail, 'failed', 'user_lookup_prepare_failed', 'User lookup could not be prepared', null, ['db_errno' => (int) $conn->errno]);
        return $finish(false, 'user_lookup_prepare_failed', 'ค้นหาบัญชีไม่สำเร็จ');
    }
    $stmt->bind_param('s', $normalizedEmail);
    if (!$stmt->execute()) {
        $errno = (int) $stmt->errno;
        $stmt->close();
        accountRecoveryLogResetDecision($normalizedEmail, 'failed', 'user_lookup_failed', 'User lookup failed', null, ['db_errno' => $errno]);
        return $finish(false, 'user_lookup_failed', 'ค้นหาบัญชีไม่สำเร็จ');
    }
    $result = $stmt->get_result();
    $user = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    if (!$user) {
        accountRecoveryLogResetDecision($normalizedEmail, 'warning', 'account_not_found', 'No user or reseller account matches the submitted Gmail address');
        return $finish(false, 'account_not_found', 'ไม่พบบัญชีที่ผูกกับ Gmail นี้');
    }

    $userId = (int) $user['id'];
    if ((string) ($user['status'] ?? '') !== 'active') {
        accountRecoveryLogResetDecision($normalizedEmail, 'warning', 'account_inactive', 'The matching account is not active', $userId);
        return $finish(false, 'account_inactive', 'บัญชีนี้ไม่ได้อยู่ในสถานะใช้งาน');
    }

    $mailConfig = accountRecoveryGetMailConfig();
    $readiness = accountRecoveryActivationReadiness();
    if (empty($mailConfig['enabled'])) {
        accountRecoveryLogResetDecision($normalizedEmail, 'failed', 'mail_disabled', 'Customer-facing password recovery is disabled', $userId);
        return $finish(false, 'mail_disabled', 'ระบบอีเมลรีเซ็ตรหัสผ่านยังปิดอยู่');
    }
    if (empty($readiness['ready'])) {
        $readinessCode = (string) ($readiness['code'] ?? 'mail_not_ready');
        accountRecoveryLogResetDecision($normalizedEmail, 'failed', $readinessCode, 'Password recovery mail is not ready', $userId);
        return $finish(false, $readinessCode, 'ระบบอีเมลยังไม่พร้อมใช้งาน');
    }

    $activeCooldown = $conn->prepare('SELECT COUNT(*) FROM auth_password_reset_tokens WHERE user_id = ? AND used_at IS NULL AND expires_at >= NOW() AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)');
    if (!$activeCooldown) {
        accountRecoveryLogResetDecision($normalizedEmail, 'failed', 'cooldown_lookup_prepare_failed', 'Cooldown lookup could not be prepared', $userId);
        return $finish(false, 'cooldown_lookup_prepare_failed', 'ตรวจสอบคำขอก่อนหน้าไม่สำเร็จ');
    }
    $activeCooldown->bind_param('i', $userId);
    if (!$activeCooldown->execute()) {
        $activeCooldown->close();
        accountRecoveryLogResetDecision($normalizedEmail, 'failed', 'cooldown_lookup_failed', 'Cooldown lookup failed', $userId);
        return $finish(false, 'cooldown_lookup_failed', 'ตรวจสอบคำขอก่อนหน้าไม่สำเร็จ');
    }
    $activeCooldown->bind_result($activeCooldownCount);
    $activeCooldown->fetch();
    $activeCooldown->close();
    if ((int) $activeCooldownCount > 0) {
        accountRecoveryLogResetDecision($normalizedEmail, 'warning', 'recent_link_exists', 'A reset link was already generated during the last minute', $userId);
        return $finish(false, 'recent_link_exists', 'มีลิงก์ที่เพิ่งส่งไปแล้ว กรุณารออย่างน้อย 1 นาทีและตรวจ Spam');
    }

    $recent = $conn->prepare('SELECT COUNT(*) FROM auth_password_reset_tokens WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)');
    if (!$recent) {
        accountRecoveryLogResetDecision($normalizedEmail, 'failed', 'recent_lookup_prepare_failed', 'Recent request lookup could not be prepared', $userId);
        return $finish(false, 'recent_lookup_prepare_failed', 'ตรวจสอบจำนวนคำขอไม่สำเร็จ');
    }
    $recent->bind_param('i', $userId);
    if (!$recent->execute()) {
        $recent->close();
        accountRecoveryLogResetDecision($normalizedEmail, 'failed', 'recent_lookup_failed', 'Recent request lookup failed', $userId);
        return $finish(false, 'recent_lookup_failed', 'ตรวจสอบจำนวนคำขอไม่สำเร็จ');
    }
    $recent->bind_result($recentCount);
    $recent->fetch();
    $recent->close();
    if ((int) $recentCount >= 3) {
        accountRecoveryLogResetDecision($normalizedEmail, 'warning', 'account_rate_limited', 'The account reached the reset-link limit for 15 minutes', $userId);
        return $finish(false, 'account_rate_limited', 'บัญชีนี้ขอลิงก์ครบขีดจำกัดแล้ว กรุณารอ 15 นาที');
    }

    try {
        $plainToken = bin2hex(random_bytes(32));
    } catch (Throwable $e) {
        accountRecoveryLogResetDecision($normalizedEmail, 'failed', 'token_generation_failed', 'Secure token generation failed', $userId);
        return $finish(false, 'token_generation_failed', 'สร้างลิงก์รีเซ็ตไม่สำเร็จ');
    }
    $tokenHash = hash('sha256', $plainToken);
    $expiresAt = date('Y-m-d H:i:s', time() + ACCOUNT_RECOVERY_TOKEN_TTL);
    $ip = getClientIp();
    $tokenId = 0;

    $conn->begin_transaction();
    try {
        $invalidate = $conn->prepare('UPDATE auth_password_reset_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL');
        if (!$invalidate) throw new RuntimeException('token_invalidation_prepare_failed');
        $invalidate->bind_param('i', $userId);
        if (!$invalidate->execute()) {
            $invalidate->close();
            throw new RuntimeException('token_invalidation_failed');
        }
        $invalidate->close();

        $insert = $conn->prepare('INSERT INTO auth_password_reset_tokens (user_id, token_hash, expires_at, request_ip) VALUES (?, ?, ?, ?)');
        if (!$insert) throw new RuntimeException('token_insert_prepare_failed');
        $insert->bind_param('isss', $userId, $tokenHash, $expiresAt, $ip);
        if (!$insert->execute()) {
            $insert->close();
            throw new RuntimeException('token_insert_failed');
        }
        $tokenId = (int) $conn->insert_id;
        $insert->close();
        $conn->commit();
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        $code = preg_replace('/[^a-z0-9_]/i', '', $e->getMessage()) ?: 'token_database_failed';
        accountRecoveryLogResetDecision($normalizedEmail, 'failed', $code, 'Password reset token database operation failed', $userId, ['db_errno' => (int) $conn->errno]);
        return $finish(false, $code, 'บันทึกลิงก์รีเซ็ตไม่สำเร็จ');
    }

    $base = getCanonicalBaseUrl();
    if ($base === '') {
        $delete = $conn->prepare('DELETE FROM auth_password_reset_tokens WHERE id = ?');
        if ($delete) {
            $delete->bind_param('i', $tokenId);
            $delete->execute();
            $delete->close();
        }
        accountRecoveryLogResetDecision($normalizedEmail, 'failed', 'base_url_missing', 'Site Base URL is not configured', $userId);
        return $finish(false, 'base_url_missing', 'ยังไม่ได้ตั้งค่า URL ของเว็บไซต์');
    }

    $resetUrl = rtrim($base, '/') . '/reset_password.php?token=' . rawurlencode($plainToken);
    $mail = accountRecoveryBuildResetEmail($user, $resetUrl);
    $sent = accountRecoverySendMail($normalizedEmail, $mail['subject'], $mail['html'], $mail['text'], false, [
        'event_type' => 'password_reset',
        'user_id' => $userId,
        'credential_source' => (string) ($mailConfig['secret_source'] ?? ''),
    ]);
    if (empty($sent['success'])) {
        $deliveryError = (string) ($sent['error_code'] ?? 'smtp_send_failed');
        if (in_array($deliveryError, ['auth_failed', 'password_rejected', 'username_rejected', 'app_password_required', 'secret_missing', 'secret_file_missing', 'secret_file_unreadable', 'password_decrypt_failed'], true)) {
            upsertSetting('recovery_smtp_credential_state', 'invalid');
            upsertSetting('recovery_mail_delivery_verified', '0');
            upsertSetting('recovery_mail_delivery_verified_at', '');
            upsertSetting('recovery_mail_enabled', '0');
        }
        $delete = $conn->prepare('DELETE FROM auth_password_reset_tokens WHERE id = ?');
        if ($delete) {
            $delete->bind_param('i', $tokenId);
            $delete->execute();
            $delete->close();
        }
        error_log('Password reset email could not be sent for user ID ' . $userId . ': ' . ($sent['message'] ?? 'unknown'));
        return $finish(false, $deliveryError, accountRecoveryDescribeMailResult($sent, true), $userId, [
            'transport' => (string) ($sent['transport'] ?? ''),
            'stage' => (string) ($sent['stage'] ?? ''),
            'smtp_code' => $sent['smtp_code'] ?? null,
        ]);
    }

    logHistory($userId, 'password_reset_requested', 'Password reset link sent to account Gmail address');
    return $finish(true, 'sent', 'Gmail accepted the password reset message', $userId, [
        'transport' => (string) ($sent['transport'] ?? ''),
        'stage' => (string) ($sent['stage'] ?? ''),
        'smtp_code' => $sent['smtp_code'] ?? null,
        'expires_at' => $expiresAt,
    ]);
}

function accountRecoveryFindValidToken(string $plainToken): ?array
{
    global $conn;
    if (!accountRecoveryEnsureSchema() || !preg_match('/^[a-f0-9]{64}$/', $plainToken)) return null;
    $hash = hash('sha256', $plainToken);
    $stmt = $conn->prepare(
        "SELECT t.id AS token_id, t.user_id, t.expires_at, u.username, u.email, u.role, u.status
         FROM auth_password_reset_tokens t
         INNER JOIN users u ON u.id = t.user_id
         WHERE t.token_hash = ? AND t.used_at IS NULL AND t.expires_at >= NOW()
           AND u.role IN ('user','reseller') AND u.status = 'active'
         LIMIT 1"
    );
    if (!$stmt) return null;
    $stmt->bind_param('s', $hash);
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

function accountRecoveryResetPassword(string $plainToken, string $newPassword, string $confirmPassword): array
{
    global $conn;
    if (!accountRecoveryEnsureSchema() || !preg_match('/^[a-f0-9]{64}$/', $plainToken)) {
        return ['success' => false, 'message' => 'ลิงก์ไม่ถูกต้องหรือหมดอายุแล้ว'];
    }
    if (strlen($newPassword) < 8 || strlen($newPassword) > 200) {
        return ['success' => false, 'message' => 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร'];
    }
    if (!hash_equals($newPassword, $confirmPassword)) {
        return ['success' => false, 'message' => 'ยืนยันรหัสผ่านไม่ตรงกัน'];
    }
    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    if ($newHash === false) return ['success' => false, 'message' => 'ไม่สามารถตั้งรหัสผ่านได้'];
    $tokenHash = hash('sha256', $plainToken);

    $conn->begin_transaction();
    try {
        $lock = $conn->prepare(
            "SELECT t.id AS token_id, t.user_id, u.username, u.status, u.role
             FROM auth_password_reset_tokens t
             INNER JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = ? AND t.used_at IS NULL AND t.expires_at >= NOW()
               AND u.role IN ('user','reseller')
             LIMIT 1 FOR UPDATE"
        );
        if (!$lock) throw new RuntimeException('token lock prepare failed');
        $lock->bind_param('s', $tokenHash);
        if (!$lock->execute()) {
            $lock->close();
            throw new RuntimeException('token lock failed');
        }
        $result = $lock->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $lock->close();
        if (!$row || (string) $row['status'] !== 'active') {
            $conn->rollback();
            return ['success' => false, 'message' => 'ลิงก์ไม่ถูกต้องหรือหมดอายุแล้ว'];
        }

        $userId = (int) $row['user_id'];
        $update = $conn->prepare('UPDATE users SET password = ?, password_changed_at = NOW(), email_verified_at = NOW() WHERE id = ?');
        if (!$update) throw new RuntimeException('password update prepare failed');
        $update->bind_param('si', $newHash, $userId);
        if (!$update->execute() || $update->affected_rows !== 1) {
            $update->close();
            throw new RuntimeException('password update failed');
        }
        $update->close();

        $mark = $conn->prepare('UPDATE auth_password_reset_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL');
        if (!$mark) throw new RuntimeException('token consume prepare failed');
        $mark->bind_param('i', $userId);
        if (!$mark->execute()) {
            $mark->close();
            throw new RuntimeException('token consume failed');
        }
        $mark->close();
        $conn->commit();

        if (function_exists('revokeRememberTokensForUser')) revokeRememberTokensForUser($userId);
        logHistory($userId, 'password_reset_completed', 'Password changed through one-time Gmail reset link');
        return ['success' => true, 'username' => (string) $row['username']];
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        error_log('Password reset completion failed: ' . $e->getMessage());
        return ['success' => false, 'message' => 'ไม่สามารถตั้งรหัสผ่านได้ กรุณาลองใหม่'];
    }
}

function accountRecoveryGetEmailNotice(int $userId): ?array
{
    global $conn;
    if ($userId < 1 || !accountRecoveryEnsureSchema()) return null;
    $stmt = $conn->prepare(
        "SELECT email, email_change_used, email_notice_started_at
         FROM users WHERE id = ? AND role IN ('user','reseller') LIMIT 1"
    );
    if (!$stmt) return null;
    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    if (!$row || (int) ($row['email_change_used'] ?? 0) === 1 || empty($row['email_notice_started_at'])) return null;
    $started = strtotime((string) $row['email_notice_started_at']);
    if ($started === false || time() >= $started + (ACCOUNT_RECOVERY_NOTICE_DAYS * 86400)) return null;
    return [
        'email' => (string) $row['email'],
        'gmail_valid' => accountRecoveryIsGmail((string) $row['email']),
        'days_left' => max(1, (int) ceil((($started + ACCOUNT_RECOVERY_NOTICE_DAYS * 86400) - time()) / 86400)),
    ];
}

function accountRecoveryRenderEmailNotice(int $userId, string $accountPath = 'account.php'): string
{
    $notice = accountRecoveryGetEmailNotice($userId);
    if ($notice === null) return '';
    $lang = getAppLang();
    $email = htmlspecialchars($notice['email'], ENT_QUOTES, 'UTF-8');
    $path = htmlspecialchars($accountPath, ENT_QUOTES, 'UTF-8');
    if ($lang === 'en') {
        $text = $notice['gmail_valid']
            ? 'Please make sure this Gmail address works. It will be used when you forget your password.'
            : 'Your current email is not a Gmail address, so password recovery cannot send a reset link.';
        $button = 'Review email';
        $once = 'You may change it yourself once at any time.';
    } else {
        $text = $notice['gmail_valid']
            ? 'ตรวจสอบว่า Gmail นี้ใช้งานได้จริง เพราะจะใช้ส่งลิงก์เมื่อลืมรหัสผ่าน'
            : 'อีเมลปัจจุบันไม่ใช่ Gmail จึงไม่สามารถรับลิงก์รีเซ็ตรหัสผ่านได้';
        $button = 'ตรวจสอบอีเมล';
        $once = 'คุณมีสิทธิ์เปลี่ยนอีเมลด้วยตัวเองได้ 1 ครั้งตลอดไป';
    }
    return '<div class="mx-4 md:mx-6 mt-3 rounded-xl border border-amber-400/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-100 flex flex-col md:flex-row md:items-center md:justify-between gap-3">'
        . '<div><div class="font-semibold"><i class="bi bi-envelope-exclamation mr-2"></i>' . $text . '</div>'
        . '<div class="mt-1 text-xs text-amber-200/80">' . $once . ' · ' . $email . '</div></div>'
        . '<a href="' . $path . '" class="shrink-0 inline-flex items-center justify-center rounded-lg bg-amber-400 px-3 py-2 font-semibold text-black hover:bg-amber-300">' . $button . '</a>'
        . '</div>';
}

function accountRecoveryAdminUpdateEmail(int $userId, string $expectedRole, string $newEmail): array
{
    global $conn;
    if (!accountRecoveryEnsureSchema() || $userId < 1 || !in_array($expectedRole, ['user', 'reseller'], true)) {
        return ['success' => false, 'message' => 'คำขอไม่ถูกต้อง'];
    }
    $newEmail = accountRecoveryNormalizeGmail($newEmail);
    if ($newEmail === '') return ['success' => false, 'message' => 'รองรับเฉพาะอีเมล @gmail.com เท่านั้น'];
    if (function_exists('accountVerificationEmailBlockState')) {
        $block = accountVerificationEmailBlockState($newEmail);
        if (!empty($block['blocked'])) {
            return ['success' => false, 'message' => 'อีเมลนี้ถูกระงับจากระบบความปลอดภัย'];
        }
    }

    $check = $conn->prepare('SELECT id FROM users WHERE LOWER(email) = ? AND id <> ? LIMIT 1');
    if (!$check) return ['success' => false, 'message' => 'ไม่สามารถตรวจสอบอีเมลได้'];
    $check->bind_param('si', $newEmail, $userId);
    $check->execute();
    $check->store_result();
    if ($check->num_rows > 0) {
        $check->close();
        return ['success' => false, 'message' => 'Gmail นี้ถูกใช้งานแล้ว'];
    }
    $check->close();

    $stmt = $conn->prepare('UPDATE users SET email = ?, email_verified_at = NULL WHERE id = ? AND role = ?');
    if (!$stmt) return ['success' => false, 'message' => 'ไม่สามารถเปลี่ยนอีเมลได้'];
    $stmt->bind_param('sis', $newEmail, $userId, $expectedRole);
    $ok = $stmt->execute() && $stmt->affected_rows === 1;
    $stmt->close();
    if (!$ok) return ['success' => false, 'message' => 'ไม่พบบัญชีหรืออีเมลไม่มีการเปลี่ยนแปลง'];

    $invalidate = $conn->prepare('UPDATE auth_password_reset_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL');
    if ($invalidate) {
        $invalidate->bind_param('i', $userId);
        $invalidate->execute();
        $invalidate->close();
    }
    return ['success' => true, 'email' => $newEmail];
}

accountRecoveryEnsureSchema();
