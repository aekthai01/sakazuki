<?php
date_default_timezone_set('Asia/Bangkok');
/**
 * Database Connection
 *
 * Recommended configuration:
 *   1. Set DB_HOST, DB_USER, DB_PASS and DB_NAME as environment variables, or
 *   2. Set DB_CONFIG_FILE to an absolute PHP file outside public_html that returns:
 *      ['host' => 'localhost', 'user' => '...', 'pass' => '...', 'name' => '...']
 *
 * For DirectAdmin-style layouts this file also checks:
 *   <domain-root>/private/database.php
 * where <domain-root> is the parent directory of public_html.
 */

$dbPrivateConfig = [];
$configCandidates = [];
$explicitConfig = trim((string) getenv('DB_CONFIG_FILE'));
if ($explicitConfig !== '') {
    $configCandidates[] = $explicitConfig;
}
$configCandidates[] = dirname(__DIR__, 2) . '/private/database.php';

foreach (array_unique($configCandidates) as $candidate) {
    if (!is_string($candidate) || $candidate === '' || !is_file($candidate) || !is_readable($candidate)) {
        continue;
    }
    $loadedConfig = require $candidate;
    if (is_array($loadedConfig)) {
        $dbPrivateConfig = $loadedConfig;
        break;
    }
}

$dbHost = trim((string) (getenv('DB_HOST') ?: ($dbPrivateConfig['host'] ?? '')));
$dbUser = trim((string) (getenv('DB_USER') ?: ($dbPrivateConfig['user'] ?? '')));
$dbPassEnv = getenv('DB_PASS');
$dbPass = $dbPassEnv !== false ? (string) $dbPassEnv : (string) ($dbPrivateConfig['pass'] ?? '');
$dbName = trim((string) (getenv('DB_NAME') ?: ($dbPrivateConfig['name'] ?? '')));

$siteConfig = is_array($dbPrivateConfig['site'] ?? null) ? $dbPrivateConfig['site'] : [];
$secretConfig = is_array($dbPrivateConfig['secrets'] ?? null) ? $dbPrivateConfig['secrets'] : [];
$storeBridgeConfig = is_array($secretConfig['store_bridge'] ?? null) ? $secretConfig['store_bridge'] : [];
$recoveryMailConfig = is_array($secretConfig['recovery_mail'] ?? null) ? $secretConfig['recovery_mail'] : [];

$siteId = trim((string) (getenv('APP_SITE_ID') ?: ($siteConfig['id'] ?? '')));
$siteDomain = strtolower(trim((string) ($siteConfig['domain'] ?? '')));
$siteLabel = trim((string) ($siteConfig['label'] ?? ''));
$storeBridgeKeyId = trim((string) ($storeBridgeConfig['key_id'] ?? ''));
if ($storeBridgeKeyId === '') $storeBridgeKeyId = trim((string) (getenv('STORE_BRIDGE_KEY_ID') ?: ''));
$storeBridgeKeyB64 = trim((string) ($storeBridgeConfig['encryption_key_b64'] ?? ''));
if ($storeBridgeKeyB64 === '') $storeBridgeKeyB64 = trim((string) (getenv('STORE_BRIDGE_ENCRYPTION_KEY') ?: ''));
$storeBridgeLegacyKeys = is_array($storeBridgeConfig['legacy_keys_b64'] ?? null) ? $storeBridgeConfig['legacy_keys_b64'] : [];
$recoveryMailKeyId = trim((string) ($recoveryMailConfig['key_id'] ?? ''));
if ($recoveryMailKeyId === '') $recoveryMailKeyId = trim((string) (getenv('RECOVERY_MAIL_KEY_ID') ?: ''));
$recoveryMailSecret = trim((string) ($recoveryMailConfig['master_secret'] ?? ''));
if ($recoveryMailSecret === '') $recoveryMailSecret = trim((string) (getenv('RECOVERY_MAIL_SECRET') ?: ''));

if ($dbHost === '' || $dbUser === '' || $dbName === '') {
    error_log('Database configuration is missing. Configure DB_CONFIG_FILE or DB_HOST/DB_USER/DB_PASS/DB_NAME.');
    http_response_code(500);
    die('Database configuration error. Please contact the administrator.');
}

if (!defined('DB_HOST')) define('DB_HOST', $dbHost);
if (!defined('DB_USER')) define('DB_USER', $dbUser);
if (!defined('DB_PASS')) define('DB_PASS', $dbPass);
if (!defined('DB_NAME')) define('DB_NAME', $dbName);
if (!defined('APP_SITE_ID')) define('APP_SITE_ID', $siteId !== '' ? $siteId : $dbName);
if (!defined('APP_SITE_DOMAIN')) define('APP_SITE_DOMAIN', $siteDomain);
if (!defined('APP_SITE_LABEL')) define('APP_SITE_LABEL', $siteLabel);
if (!defined('STORE_BRIDGE_KEY_ID')) define('STORE_BRIDGE_KEY_ID', $storeBridgeKeyId);
if (!defined('STORE_BRIDGE_ENCRYPTION_KEY_B64')) define('STORE_BRIDGE_ENCRYPTION_KEY_B64', $storeBridgeKeyB64);
if (!defined('STORE_BRIDGE_LEGACY_KEYS_B64')) define('STORE_BRIDGE_LEGACY_KEYS_B64', $storeBridgeLegacyKeys);
if (!defined('RECOVERY_MAIL_KEY_ID')) define('RECOVERY_MAIL_KEY_ID', $recoveryMailKeyId);
if (!defined('RECOVERY_MAIL_MASTER_SECRET')) define('RECOVERY_MAIL_MASTER_SECRET', $recoveryMailSecret);
unset(
    $dbPrivateConfig, $configCandidates, $explicitConfig, $loadedConfig, $candidate,
    $dbHost, $dbUser, $dbPassEnv, $dbPass, $dbName,
    $siteConfig, $secretConfig, $storeBridgeConfig, $recoveryMailConfig,
    $siteId, $siteDomain, $siteLabel, $storeBridgeKeyId, $storeBridgeKeyB64,
    $storeBridgeLegacyKeys, $recoveryMailKeyId, $recoveryMailSecret
);

try {
    if (function_exists('mysqli_report')) {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    }

    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        throw new RuntimeException('Database connection failed');
    }

    if (!$conn->set_charset('utf8mb4')) {
        throw new RuntimeException('Unable to set database character set');
    }
    $conn->query("SET time_zone = '+07:00'");

    if (function_exists('mysqli_report')) {
        mysqli_report(MYSQLI_REPORT_OFF);
    }
} catch (Throwable $e) {
    error_log('Database connection error: ' . $e->getMessage());
    http_response_code(500);
    die('Database connection error. Please contact the administrator.');
}
