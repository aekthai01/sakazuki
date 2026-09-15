<?php
require_once '../includes/auth.php';
require_once '../includes/binance_giftcard.php';
require_once '../includes/announcement_marquee.php';
require_once '../includes/automation.php';
requireAdmin();

// Administrative settings may contain financial configuration. Prevent browsers
// and intermediary caches from storing the page.
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

global $conn;

$error = '';
$success = '';
$automationTokenJustGenerated = '';




/**
 * Read a scalar POST value without allowing an array/object to trigger a PHP
 * TypeError in trim(), preg_replace(), or other scalar-only functions.
 */
function settingsPostString(string $key, string $default = ''): string
{
    if (!array_key_exists($key, $_POST) || !is_scalar($_POST[$key])) {
        return $default;
    }
    return (string) $_POST[$key];
}

/**
 * Create a high-entropy token used only by the optional HTTPS background
 * runner. This is a shared-hosting fallback for panels that cannot execute a
 * private PHP CLI script from cron.
 */
function settingsGenerateAutomationWebToken(): string
{
    return bin2hex(random_bytes(32));
}

function settingsStoreAutomationWebToken(string $token): bool
{
    $token = strtolower(trim($token));
    if (!preg_match('/^[a-f0-9]{64}$/D', $token)) return false;

    $hash = hash('sha256', $token);
    $encrypted = function_exists('automationEncryptSchedulerToken')
        ? automationEncryptSchedulerToken($token)
        : null;

    global $conn;
    $conn->begin_transaction();
    try {
        if (!upsertSetting('automation_web_token_hash', $hash)) {
            throw new RuntimeException('Unable to store scheduler token hash');
        }
        // The encrypted copy is Admin-only convenience for ready-to-copy URLs.
        // If encryption is unavailable the token still works; it will simply be
        // shown only on the response immediately after regeneration.
        if (is_string($encrypted) && $encrypted !== '') {
            if (!upsertSetting('automation_web_token_encrypted', $encrypted)) {
                throw new RuntimeException('Unable to store encrypted scheduler token');
            }
        } else {
            upsertSetting('automation_web_token_encrypted', '');
        }
        if (!upsertSetting('automation_web_token', '')) {
            throw new RuntimeException('Unable to clear legacy plaintext scheduler token');
        }
        $conn->commit();
        return true;
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('Automation scheduler token storage failed');
        return false;
    }
}

/**
 * Return the scheduler token only to the authenticated Admin settings page.
 * Validation uses a one-way hash; the optional encrypted copy exists solely to
 * rebuild the two ready-to-copy cron URLs without storing plaintext in MySQL.
 */
function settingsEnsureAutomationWebToken(): string
{
    $storedHash = strtolower(trim((string) getSetting('automation_web_token_hash', '')));
    $encrypted = trim((string) getSetting('automation_web_token_encrypted', ''));
    if (preg_match('/^[a-f0-9]{64}$/D', $storedHash) === 1 && $encrypted !== ''
        && function_exists('automationDecryptSchedulerToken')) {
        $decrypted = automationDecryptSchedulerToken($encrypted);
        if (is_string($decrypted)
            && preg_match('/^[a-f0-9]{64}$/D', $decrypted) === 1
            && hash_equals($storedHash, hash('sha256', $decrypted))) {
            return $decrypted;
        }
    }

    // Migrate old plaintext deployments to hash + encrypted-at-rest storage.
    $legacy = strtolower(trim((string) getSetting('automation_web_token', '')));
    if (preg_match('/^[a-f0-9]{64}$/D', $legacy) === 1) {
        if (settingsStoreAutomationWebToken($legacy)) return $legacy;
        return $legacy;
    }

    // A hash-only token from the previous release remains valid. Do NOT rotate
    // it silently because that would break an already configured external job.
    if (preg_match('/^[a-f0-9]{64}$/D', $storedHash) === 1) return '';

    try {
        $token = settingsGenerateAutomationWebToken();
    } catch (Throwable $e) {
        error_log('Unable to generate automation web token');
        return '';
    }
    return settingsStoreAutomationWebToken($token) ? $token : '';
}

/** Remove settings left by the abandoned one-link/two-site coordinator. */
function settingsCleanupLegacyAutomationCoordinator(): void
{
    global $conn;
    $keys = [
        'automation_coordinator_enabled',
        'automation_coordinator_self_site_id',
        'automation_coordinator_peer_site_id',
        'automation_coordinator_peer_url',
        'automation_coordinator_peer_token_encrypted',
    ];
    // Fixed internal keys only; no user input is interpolated here.
    $sql = "DELETE FROM settings WHERE setting_key IN ("
        . "'automation_coordinator_enabled',"
        . "'automation_coordinator_self_site_id',"
        . "'automation_coordinator_peer_site_id',"
        . "'automation_coordinator_peer_url',"
        . "'automation_coordinator_peer_token_encrypted')";
    try {
        if (!$conn->query($sql)) {
            error_log('Unable to remove legacy automation coordinator settings');
        }
    } catch (Throwable $e) {
        error_log('Unable to remove legacy automation coordinator settings');
    }
    if (isset($GLOBALS['__settings_request_cache']) && is_array($GLOBALS['__settings_request_cache'])) {
        foreach ($keys as $key) unset($GLOBALS['__settings_request_cache'][$key]);
    }
}

function settingsAutomationWebCredentialConfigured(): bool
{
    $hash = strtolower(trim((string) getSetting('automation_web_token_hash', '')));
    if (preg_match('/^[a-f0-9]{64}$/D', $hash)) return true;
    $legacy = strtolower(trim((string) getSetting('automation_web_token', '')));
    return preg_match('/^[a-f0-9]{64}$/D', $legacy) === 1;
}

/**
 * Handle icon upload for navbar (stores in /assets/uploads/icons).
 * Returns relative path (e.g., assets/uploads/icons/xxx.png) or existing.
 */
function handleIconUpload(string $fileField, string $existing = ''): string
{
    if (!isset($_FILES[$fileField]) || !is_array($_FILES[$fileField])) {
        return $existing;
    }

    $file = $_FILES[$fileField];
    $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($errorCode === UPLOAD_ERR_NO_FILE) {
        return $existing;
    }
    if ($errorCode !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Icon upload failed with error code ' . $errorCode);
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    $size = (int) ($file['size'] ?? 0);
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Invalid uploaded icon file');
    }
    if ($size < 1 || $size > 2 * 1024 * 1024) {
        throw new RuntimeException('Icon must be smaller than 2 MB');
    }

    $imageInfo = @getimagesize($tmp);
    if ($imageInfo === false || ($imageInfo[2] ?? null) !== IMAGETYPE_PNG) {
        throw new RuntimeException('Only genuine PNG images are allowed');
    }
    $width = (int) ($imageInfo[0] ?? 0);
    $height = (int) ($imageInfo[1] ?? 0);
    if ($width < 1 || $height < 1 || $width > 2048 || $height > 2048) {
        throw new RuntimeException('Icon dimensions are invalid');
    }

    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? finfo_file($finfo, $tmp) : false;
        if ($finfo) {
            finfo_close($finfo);
        }
        if ($mime !== false && $mime !== 'image/png') {
            throw new RuntimeException('Uploaded icon MIME type is invalid');
        }
    }

    // Re-encode the image to remove appended PHP/HTML payloads from polyglot files.
    if (!function_exists('imagecreatefrompng') || !function_exists('imagepng')) {
        throw new RuntimeException('The PHP GD extension is required for secure icon uploads');
    }
    $image = @imagecreatefrompng($tmp);
    if ($image === false) {
        throw new RuntimeException('Unable to decode PNG icon');
    }
    imagesavealpha($image, true);

    $uploadDirFs = __DIR__ . '/../assets/uploads/icons/';
    $uploadDirRel = 'assets/uploads/icons/';
    if (!is_dir($uploadDirFs) && !mkdir($uploadDirFs, 0755, true) && !is_dir($uploadDirFs)) {
        imagedestroy($image);
        throw new RuntimeException('Unable to create icon upload directory');
    }

    $filename = 'ico_' . bin2hex(random_bytes(16)) . '.png';
    $destination = $uploadDirFs . $filename;
    $saved = imagepng($image, $destination, 9);
    imagedestroy($image);
    if (!$saved) {
        @unlink($destination);
        throw new RuntimeException('Unable to save icon');
    }
    @chmod($destination, 0644);

    return $uploadDirRel . $filename;
}


/**
 * Check whether an upload field contains a selected file.
 */
function settingsUploadProvided(string $fileField): bool
{
    return isset($_FILES[$fileField])
        && is_array($_FILES[$fileField])
        && (int) ($_FILES[$fileField]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
}

/**
 * Delete only PNG files managed by this settings page.
 */
function deleteManagedIconFile(string $relativePath): void
{
    $relativePath = ltrim(trim($relativePath), '/');
    if ($relativePath === '' || !preg_match('#^assets/uploads/icons/[A-Za-z0-9_-]+\.png$#', $relativePath)) {
        return;
    }

    $iconsRoot = realpath(__DIR__ . '/../assets/uploads/icons');
    $fullPath = realpath(__DIR__ . '/../' . $relativePath);
    if ($iconsRoot === false || $fullPath === false || !is_file($fullPath)) {
        return;
    }

    $prefix = rtrim($iconsRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (strpos($fullPath, $prefix) === 0) {
        @unlink($fullPath);
    }
}

/**
 * Changing the storage currency without converting existing balances, prices,
 * and transactions would silently reinterpret every stored number. Block that
 * unsafe operation once financial/catalog data exists.
 */
function settingsHasCurrencySensitiveData(): bool
{
    global $conn;
    $queries = [
        "SELECT 1 FROM users WHERE ABS(COALESCE(balance, 0)) > 0.0001 LIMIT 1",
        "SELECT 1 FROM transactions LIMIT 1",
        "SELECT 1 FROM `keys` LIMIT 1",
        "SELECT 1 FROM product_variants LIMIT 1",
        "SELECT 1 FROM cgo_products LIMIT 1",
    ];
    foreach ($queries as $sql) {
        try {
            $result = $conn->query($sql);
            if ($result && $result->num_rows > 0) return true;
        } catch (Throwable $ignored) {
            // Optional tables may not exist yet. Continue with the remaining checks.
        }
    }
    return false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $action = settingsPostString('action');

    if ($action === 'regenerate_automation_token') {
        try {
            $newToken = settingsGenerateAutomationWebToken();
            if (!settingsStoreAutomationWebToken($newToken)) {
                throw new RuntimeException('Unable to save background runner token hash');
            }
            $automationTokenJustGenerated = $newToken;
            $success = getAppLang() === 'en'
                ? 'New scheduler secret created. Copy it now; the plain secret is not stored.'
                : 'สร้างรหัสลับใหม่แล้ว กรุณาคัดลอกตอนนี้ ระบบจะไม่เก็บรหัสจริงไว้';
            logHistory($_SESSION['user_id'], 'automation_token_regenerated', 'Regenerated background runner URL token');
        } catch (Throwable $e) {
            error_log('Automation token regeneration failed: ' . $e->getMessage());
            $error = getAppLang() === 'en'
                ? 'Unable to regenerate the background runner token.'
                : 'ไม่สามารถสร้างรหัสลับสำหรับระบบทำงานเบื้องหลังได้';
        }
    }
    elseif ($action === 'run_automation_test') {
        // This proves supplier/database work can execute from the website, but
        // deliberately does NOT record a cron heartbeat. A manual admin click
        // must never make the site believe that a scheduler is healthy.
        if (!defined('SAKAZUKI_ALLOW_SCHEMA_MIGRATIONS')) {
            define('SAKAZUKI_ALLOW_SCHEMA_MIGRATIONS', true);
        }
        $testSchema = automationPrepareStorefrontSchemas();
        $testResults = ($testSchema['success'] ?? false) !== false
            ? automationRunDueJobs(
                'admin_test',
                ['pending_orders', 'slip_reconciliation', 'cgo_inventory', 'supplier_catalog'],
                20
            )
            : ['schema' => ['success' => false, 'message' => (string) ($testSchema['message'] ?? 'Schema preparation failed')]];
        $testFailed = [];
        foreach ($testResults as $jobName => $jobResult) {
            if (is_array($jobResult) && ($jobResult['success'] ?? true) === false) {
                $testFailed[] = (string) $jobName;
            }
        }
        if ($testFailed === []) {
            $success = getAppLang() === 'en'
                ? 'Critical stock/order jobs can run from PHP. This does not prove that cron-job.org is scheduled yet.'
                : 'ทดสอบงานสำคัญด้านสต๊อก/ออเดอร์ผ่าน PHP ได้ แต่ปุ่มนี้ยังไม่ได้แปลว่า cron-job.org ตั้งเวลาเรียบร้อยแล้ว';
        } else {
            $error = (getAppLang() === 'en' ? 'Background test failed: ' : 'ทดสอบงานเบื้องหลังไม่ผ่าน: ')
                . implode(', ', $testFailed);
        }
        logHistory($_SESSION['user_id'], 'automation_manual_test', 'Tested background automation jobs from admin settings');
    }
    elseif ($action === 'update_settings') {
        $allowedCurrencies = ['THB|฿', 'USD|$'];
        $currencyCombined = settingsPostString('currency_combined', 'THB|฿');
        if (!in_array($currencyCombined, $allowedCurrencies, true)) {
            $error = Lang::t('admin.settings.error.invalid_currency');
        } else {
            $parts = explode('|', $currencyCombined, 2);
            $currencyName = $parts[0];
            $currency = $parts[1];
            $siteName = trim(cleanInput(settingsPostString('site_name', 'Key Selling System')));
            $defaultLanguage = cleanInput(settingsPostString('default_language', 'th'));
            $hasResellerDiscount = array_key_exists('reseller_discount', $_POST);
            $resellerDiscount = $hasResellerDiscount ? (float) settingsPostString('reseller_discount') : null;
            $usdThbRateRaw = trim(settingsPostString('usd_thb_rate', '35'));
            $usdThbRate = is_numeric($usdThbRateRaw) ? (float) $usdThbRateRaw : 0.0;

            $siteNameLength = function_exists('mb_strlen') ? mb_strlen($siteName, 'UTF-8') : strlen($siteName);
            $currentCurrencyName = strtoupper(trim((string) (getSetting('currency_name') ?: 'THB')));
            $currencyIsChanging = $currentCurrencyName !== $currencyName;
            if ($siteNameLength < 1 || $siteNameLength > 100) {
                $error = Lang::t('admin.settings.error.site_name');
            } elseif ($currencyIsChanging && settingsHasCurrencySensitiveData()) {
                $error = Lang::t('admin.settings.error.currency_change_requires_migration');
            } elseif (!in_array($defaultLanguage, ['th', 'en'], true)) {
                $error = Lang::t('admin.settings.error.default_language');
            } elseif ($hasResellerDiscount && ($resellerDiscount < 0 || $resellerDiscount > 100)) {
                $error = Lang::t('admin.settings.error.discount');
            } elseif (!is_finite($usdThbRate) || $usdThbRate < 1 || $usdThbRate > 1000) {
                $error = getAppLang() === 'en'
                    ? 'USD exchange rate must be between 1 and 1,000 THB per USD.'
                    : 'อัตราแลกเปลี่ยนต้องอยู่ระหว่าง 1 ถึง 1,000 บาท ต่อ 1 ดอลลาร์';
            } else {
                $conn->begin_transaction();
                try {
                    $writes = [
                        ['currency', $currency],
                        ['currency_name', $currencyName],
                        ['site_name', $siteName],
                        ['default_language', $defaultLanguage],
                        ['usd_thb_rate', rtrim(rtrim(number_format($usdThbRate, 4, '.', ''), '0'), '.')],
                    ];
                    if ($hasResellerDiscount) {
                        $writes[] = ['reseller_discount', (string) $resellerDiscount];
                    }
                    foreach ($writes as $write) {
                        if (!upsertSetting($write[0], $write[1])) {
                            throw new RuntimeException('Unable to save setting: ' . $write[0]);
                        }
                    }
                    $conn->commit();
                    $success = Lang::t('admin.settings.success.general');
                    logHistory($_SESSION['user_id'], 'update_settings', 'Updated system settings');
                } catch (Throwable $e) {
                    $conn->rollback();
                    error_log('General settings update failed: ' . $e->getMessage());
                    $error = Lang::t('common.error.operation');
                }
            }
        }
    }
    // Handle announcement update
    elseif ($action === 'update_announcement') {
        // updateAnnouncementText() is the single sanitization boundary. Keeping
        // it in one place prevents valid icon classes/backslashes being cleaned twice.
        $announcementText = settingsPostString('announcement_text');
        $announcementStatus = cleanInput(settingsPostString('announcement_status', 'active'));
        $announcementColorRaw = strtoupper(trim(settingsPostString('announcement_text_color', '#93C5FD')));
        if (!in_array($announcementStatus, ['active', 'inactive'], true)) {
            $announcementStatus = 'inactive';
        }

        if (!preg_match('/^#[0-9A-F]{6}$/', $announcementColorRaw)) {
            $error = getAppLang() === 'en'
                ? 'Announcement color must use a 6-digit HEX value.'
                : 'สีข้อความประกาศต้องเป็นรหัส HEX 6 หลัก';
        } else {
            $previousAnnouncementColor = getAnnouncementTextColor();
            if (!saveAnnouncementTextColor($announcementColorRaw)) {
                $error = Lang::t('common.error.operation');
            } elseif (updateAnnouncementText($announcementText, $announcementStatus)) {
                $success = Lang::t('admin.settings.success.announcement');
                logHistory(
                    $_SESSION['user_id'],
                    'update_announcement',
                    'Updated announcement text and color ' . $announcementColorRaw
                );
            } else {
                // Presentation settings are not financial data, but restore the
                // previous color so a failed text save does not leave a partial UI update.
                saveAnnouncementTextColor($previousAnnouncementColor);
                $error = Lang::t('common.error.operation');
            }
        }
    }
    // Handle store branding update
    elseif ($action === 'update_store_branding') {
        $titleText = trim(cleanInput(settingsPostString('store_title_text', 'STORE')));
        $titleColor = trim(cleanInput(settingsPostString('store_title_color', '#60a5fa')));
        $titleStyle = trim(cleanInput(settingsPostString('store_title_style', 'font-extrabold')));

        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $titleColor)) {
            $error = Lang::t('admin.settings.error.title_color');
        } else {
            $existingUserIcon = (string) (getSetting('user_title_icon') ?? '');
            $existingResellerIcon = (string) (getSetting('reseller_title_icon') ?? '');
            $userIconPath = $existingUserIcon;
            $resellerIconPath = $existingResellerIcon;

            $removeUserIcon = settingsPostString('remove_user_title_icon') === '1';
            $removeResellerIcon = settingsPostString('remove_reseller_title_icon') === '1';
            $userUploadProvided = settingsUploadProvided('user_title_icon_file');
            $resellerUploadProvided = settingsUploadProvided('reseller_title_icon_file');

            // Run table setup before starting the transaction because MySQL DDL
            // can implicitly commit an active transaction.
            ensureStoreBrandingTable();
            $conn->begin_transaction();
            try {
                if ($userUploadProvided) {
                    $userIconPath = handleIconUpload('user_title_icon_file', $existingUserIcon);
                } elseif ($removeUserIcon) {
                    $userIconPath = '';
                }

                if ($resellerUploadProvided) {
                    $resellerIconPath = handleIconUpload('reseller_title_icon_file', $existingResellerIcon);
                } elseif ($removeResellerIcon) {
                    $resellerIconPath = '';
                }

                if (!updateStoreBranding($titleText, $titleColor, $titleStyle)) {
                    throw new RuntimeException('Unable to update store branding');
                }
                if ($userIconPath !== $existingUserIcon && !upsertSetting('user_title_icon', $userIconPath)) {
                    throw new RuntimeException('Unable to update user icon');
                }
                if ($resellerIconPath !== $existingResellerIcon && !upsertSetting('reseller_title_icon', $resellerIconPath)) {
                    throw new RuntimeException('Unable to update reseller icon');
                }

                $conn->commit();

                // Remove superseded files only after the database commit succeeds.
                if ($userIconPath !== $existingUserIcon) {
                    deleteManagedIconFile($existingUserIcon);
                }
                if ($resellerIconPath !== $existingResellerIcon) {
                    deleteManagedIconFile($existingResellerIcon);
                }

                $success = Lang::t('admin.settings.success.branding');
                logHistory($_SESSION['user_id'], 'update_store_branding', 'Updated store navbar title and icons');
            } catch (Throwable $e) {
                $conn->rollback();

                // A newly uploaded file is not referenced if the transaction failed.
                if ($userUploadProvided && $userIconPath !== $existingUserIcon) {
                    deleteManagedIconFile($userIconPath);
                }
                if ($resellerUploadProvided && $resellerIconPath !== $existingResellerIcon) {
                    deleteManagedIconFile($resellerIconPath);
                }

                error_log('Store branding update failed: ' . $e->getMessage());
                $error = Lang::t('common.error.operation');
            }
        }
    }
    // Handle TrueMoney Angpao settings update
    elseif ($action === 'update_truemoney') {
        $tmEnabled = settingsPostString('truemoney_enabled') === '1';
        $tmPhone = trim(cleanInput(settingsPostString('truemoney_phone')));

        $tmPhoneDigits = preg_replace('/\D+/', '', $tmPhone);
        if ($tmEnabled && (strlen($tmPhoneDigits) < 9 || strlen($tmPhoneDigits) > 15)) {
            $error = Lang::t('admin.settings.error.truemoney_phone');
        } else {
            $conn->begin_transaction();
            try {
                if (!upsertSetting('truemoney_enabled', $tmEnabled ? '1' : '0') ||
                    !upsertSetting('truemoney_phone', $tmPhoneDigits)) {
                    throw new RuntimeException('Unable to save TrueMoney settings');
                }
                $conn->commit();
                $success = Lang::t('admin.settings.success.truemoney');
                logHistory($_SESSION['user_id'], 'update_truemoney', 'Updated TrueMoney Angpao settings');
            } catch (Throwable $e) {
                $conn->rollback();
                error_log('TrueMoney settings update failed: ' . $e->getMessage());
                $error = Lang::t('common.error.operation');
            }
        }
    }
    // Handle EasySlip settings update
    elseif ($action === 'update_easyslip') {
        $esEnabled = settingsPostString('easyslip_enabled') === '1';
        $esApiKey = trim(settingsPostString('easyslip_api_key'));
        $esReceiverNameTh = trim(cleanInput(settingsPostString('easyslip_receiver_name_th')));
        $esReceiverNameEn = trim(cleanInput(settingsPostString('easyslip_receiver_name_en')));
        $esPhone = trim(cleanInput(settingsPostString('easyslip_phone')));
        $esAccountNumber = trim(cleanInput(settingsPostString('easyslip_account_number')));
        $esBankNameTh = trim(cleanInput(settingsPostString('easyslip_bank_name_th')));
        $esBankNameEn = trim(cleanInput(settingsPostString('easyslip_bank_name_en')));
        $esMaxAgeMinutes = filter_var(settingsPostString('easyslip_max_age_minutes', '1440'), FILTER_VALIDATE_INT);
        $existingEasySlipApiKey = trim((string) (getSetting('easyslip_api_key') ?? ''));

        $esPhoneDigits = preg_replace('/\D+/', '', $esPhone);
        $esAccountStored = trim(preg_replace('/[^0-9\s-]+/', '', $esAccountNumber));
        $esAccountDigits = preg_replace('/\D+/', '', $esAccountStored);

        $receiverNameThLength = function_exists('mb_strlen') ? mb_strlen($esReceiverNameTh, 'UTF-8') : strlen($esReceiverNameTh);
        $receiverNameEnLength = function_exists('mb_strlen') ? mb_strlen($esReceiverNameEn, 'UTF-8') : strlen($esReceiverNameEn);
        $bankNameThLength = function_exists('mb_strlen') ? mb_strlen($esBankNameTh, 'UTF-8') : strlen($esBankNameTh);
        $bankNameEnLength = function_exists('mb_strlen') ? mb_strlen($esBankNameEn, 'UTF-8') : strlen($esBankNameEn);

        if ($esEnabled && $esApiKey === '' && $existingEasySlipApiKey === '') {
            $error = Lang::t('admin.settings.error.easyslip_key_required');
        } elseif ($esApiKey !== '' && (strlen($esApiKey) < 10 || strlen($esApiKey) > 512 || preg_match('/[\x00-\x1F\x7F]/', $esApiKey))) {
            $error = Lang::t('admin.settings.error.easyslip_key');
        } elseif ($esEnabled && $esPhoneDigits === '' && $esAccountDigits === '') {
            $error = Lang::t('admin.settings.error.receiver_account');
        } elseif ($esEnabled && $esReceiverNameTh === '' && $esReceiverNameEn === '') {
            $error = Lang::t('admin.settings.error.receiver_name');
        } elseif ($esEnabled && $esBankNameTh === '' && $esBankNameEn === '') {
            $error = Lang::t('admin.settings.error.bank_name');
        } elseif ($receiverNameThLength > 150 || $receiverNameEnLength > 150 || $bankNameThLength > 100 || $bankNameEnLength > 100) {
            $error = Lang::t('admin.settings.error.receiver_too_long');
        } elseif ($esPhoneDigits !== '' && (strlen($esPhoneDigits) < 9 || strlen($esPhoneDigits) > 15)) {
            $error = Lang::t('admin.settings.error.receiver_phone');
        } elseif ($esAccountDigits !== '' && (strlen($esAccountDigits) < 6 || strlen($esAccountDigits) > 20)) {
            $error = Lang::t('admin.settings.error.receiver_account_number');
        } elseif ($esMaxAgeMinutes === false || $esMaxAgeMinutes < 5 || $esMaxAgeMinutes > 10080) {
            $error = Lang::t('admin.settings.error.slip_age');
        } else {
            $conn->begin_transaction();
            try {
                $writes = [
                    ['easyslip_enabled', $esEnabled ? '1' : '0'],
                    ['easyslip_receiver_name', $esReceiverNameTh],
                    ['easyslip_receiver_name_en', $esReceiverNameEn],
                    ['easyslip_phone', $esPhoneDigits],
                    ['easyslip_account_number', $esAccountStored],
                    ['easyslip_bank_name', $esBankNameTh],
                    ['easyslip_bank_name_en', $esBankNameEn],
                    ['easyslip_max_age_minutes', (string) $esMaxAgeMinutes],
                ];
                if ($esApiKey !== '') {
                    $writes[] = ['easyslip_api_key', $esApiKey];
                }
                foreach ($writes as $write) {
                    if (!upsertSetting($write[0], $write[1])) {
                        throw new RuntimeException('Unable to save setting: ' . $write[0]);
                    }
                }
                $conn->commit();
                $success = Lang::t('admin.settings.success.easyslip');
                logHistory($_SESSION['user_id'], 'update_easyslip', 'Updated EasySlip API and receiver settings');
            } catch (Throwable $e) {
                $conn->rollback();
                error_log('EasySlip settings update failed: ' . $e->getMessage());
                $error = Lang::t('common.error.operation');
            }
        }
    }
    // Handle Binance settings update
    elseif ($action === 'update_binance') {
        $bnEnabled = settingsPostString('binance_enabled') === '1';
        $bnApiKey = trim(settingsPostString('binance_api_key'));
        $bnSecretKey = trim(settingsPostString('binance_secret_key'));
        $bnWallet = trim(settingsPostString('binance_wallet'));
        $existingBnApiKey = trim((string) (getSetting('binance_api_key') ?? ''));
        $existingBnSecretKey = trim((string) (getSetting('binance_secret_key') ?? ''));
        $existingBnWallet = trim((string) (getSetting('binance_wallet') ?? ''));
        $effectiveBnApiKey = $bnApiKey !== '' ? $bnApiKey : $existingBnApiKey;
        $effectiveBnSecretKey = $bnSecretKey !== '' ? $bnSecretKey : $existingBnSecretKey;
        $effectiveBnWallet = $bnWallet !== '' ? $bnWallet : $existingBnWallet;

        if ($bnEnabled && ($effectiveBnApiKey === '' || $effectiveBnSecretKey === '' || $effectiveBnWallet === '')) {
            $error = Lang::t('admin.settings.error.binance_required');
        } elseif (($bnApiKey !== '' && (strlen($bnApiKey) < 16 || strlen($bnApiKey) > 256 || preg_match('/[\x00-\x20\x7F]/', $bnApiKey))) ||
                  ($bnSecretKey !== '' && (strlen($bnSecretKey) < 16 || strlen($bnSecretKey) > 256 || preg_match('/[\x00-\x20\x7F]/', $bnSecretKey)))) {
            $error = Lang::t('admin.settings.error.binance_credentials');
        } elseif ($effectiveBnWallet !== '' && !preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $effectiveBnWallet)) {
            $error = Lang::t('admin.settings.error.binance_wallet');
        } else {
        $conn->begin_transaction();
        try {
            $writes = [['binance_enabled', $bnEnabled ? '1' : '0']];
            if ($bnApiKey !== '') $writes[] = ['binance_api_key', $bnApiKey];
            if ($bnSecretKey !== '') $writes[] = ['binance_secret_key', $bnSecretKey];
            if ($bnWallet !== '') $writes[] = ['binance_wallet', $bnWallet];
            foreach ($writes as $write) {
                if (!upsertSetting($write[0], $write[1])) {
                    throw new RuntimeException('Unable to save Binance setting');
                }
            }
            $conn->commit();
            $success = Lang::t('admin.settings.success.binance');
            logHistory($_SESSION['user_id'], 'update_binance', 'Updated Binance USDT settings');
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('Binance settings update failed: ' . $e->getMessage());
            $error = Lang::t('common.error.operation');
        }
        }
    }

    // Handle Binance Gift Card settings update
    elseif ($action === 'update_binance_giftcard') {
        $gcEnabled = settingsPostString('binance_giftcard_enabled') === '1';
        $gcUserEnabled = settingsPostString('binance_giftcard_user_enabled') === '1';
        $gcResellerEnabled = settingsPostString('binance_giftcard_reseller_enabled') === '1';
        $gcCredentialMode = strtolower(trim(settingsPostString('binance_giftcard_credentials_mode', 'separate')));
        $gcApiKey = trim(settingsPostString('binance_giftcard_api_key'));
        $gcSecretKey = trim(settingsPostString('binance_giftcard_secret_key'));
        $gcMinUsdt = (float) settingsPostString('binance_giftcard_min_usdt', '1');
        $gcMaxUsdt = (float) settingsPostString('binance_giftcard_max_usdt', '1000');
        $gcDailyUsdt = (float) settingsPostString('binance_giftcard_daily_usdt', '2000');
        $gcCreditPercent = (float) settingsPostString('binance_giftcard_credit_percent', '100');
        $gcUserAttempts = (int) settingsPostString('binance_giftcard_user_attempts_per_day', '2');
        $gcGlobalInvalid = (int) settingsPostString('binance_giftcard_global_invalid_limit', '4');
        $gcAccountAgeDays = (int) settingsPostString('binance_giftcard_account_age_days', '0');
        $gcCountForRanking = settingsPostString('binance_giftcard_count_for_ranking') === '1';
        $gcRankBonusEnabled = settingsPostString('binance_giftcard_rank_bonus_enabled') === '1';

        $existingGcApiKey = trim((string) (getSetting('binance_giftcard_api_key') ?: ''));
        $existingGcSecretKey = trim((string) (getSetting('binance_giftcard_secret_key') ?: ''));
        $effectiveGcApiKey = $gcApiKey !== '' ? $gcApiKey : $existingGcApiKey;
        $effectiveGcSecretKey = $gcSecretKey !== '' ? $gcSecretKey : $existingGcSecretKey;
        $sharedBinance = getBinanceSettings();

        if (!in_array($gcCredentialMode, ['shared', 'separate'], true)) {
            $error = Lang::t('admin.settings.giftcard_error_mode');
        } elseif ($gcEnabled && !$gcUserEnabled && !$gcResellerEnabled) {
            $error = Lang::t('admin.settings.giftcard_error_roles');
        } elseif ($gcEnabled && $gcCredentialMode === 'shared' && (empty($sharedBinance['api_key']) || empty($sharedBinance['secret_key']))) {
            $error = Lang::t('admin.settings.giftcard_error_shared_credentials');
        } elseif ($gcEnabled && $gcCredentialMode === 'separate' && ($effectiveGcApiKey === '' || $effectiveGcSecretKey === '')) {
            $error = Lang::t('admin.settings.giftcard_error_credentials');
        } elseif (($gcApiKey !== '' && (strlen($gcApiKey) < 16 || strlen($gcApiKey) > 256 || preg_match('/[\x00-\x20\x7F]/', $gcApiKey))) ||
                  ($gcSecretKey !== '' && (strlen($gcSecretKey) < 16 || strlen($gcSecretKey) > 256 || preg_match('/[\x00-\x20\x7F]/', $gcSecretKey)))) {
            $error = Lang::t('admin.settings.giftcard_error_credentials');
        } elseif (!is_finite($gcMinUsdt) || !is_finite($gcMaxUsdt) || !is_finite($gcDailyUsdt)
            || $gcMinUsdt < 0.01 || $gcMaxUsdt < $gcMinUsdt || $gcMaxUsdt > 1000000
            || $gcDailyUsdt < $gcMaxUsdt || $gcDailyUsdt > 10000000) {
            $error = Lang::t('admin.settings.giftcard_error_limits');
        } elseif (!is_finite($gcCreditPercent) || $gcCreditPercent < 1 || $gcCreditPercent > 100) {
            $error = Lang::t('admin.settings.giftcard_error_credit_percent');
        } elseif ($gcUserAttempts < 1 || $gcUserAttempts > 4 || $gcGlobalInvalid < 1 || $gcGlobalInvalid > 4) {
            $error = Lang::t('admin.settings.giftcard_error_attempts');
        } elseif ($gcAccountAgeDays < 0 || $gcAccountAgeDays > 365) {
            $error = Lang::t('admin.settings.giftcard_error_account_age');
        } else {
            if ($gcRankBonusEnabled) {
                $gcCountForRanking = true;
            }
            if ($gcEnabled && !ensureBinanceGiftCardSchema()) {
                $error = Lang::t('admin.settings.giftcard_error_schema');
            } else {
                $conn->begin_transaction();
                try {
                    $writes = [
                        ['binance_giftcard_enabled', $gcEnabled ? '1' : '0'],
                        ['binance_giftcard_user_enabled', $gcUserEnabled ? '1' : '0'],
                        ['binance_giftcard_reseller_enabled', $gcResellerEnabled ? '1' : '0'],
                        ['binance_giftcard_credentials_mode', $gcCredentialMode],
                        ['binance_giftcard_min_usdt', number_format($gcMinUsdt, 8, '.', '')],
                        ['binance_giftcard_max_usdt', number_format($gcMaxUsdt, 8, '.', '')],
                        ['binance_giftcard_daily_usdt', number_format($gcDailyUsdt, 8, '.', '')],
                        ['binance_giftcard_credit_percent', number_format($gcCreditPercent, 2, '.', '')],
                        ['binance_giftcard_user_attempts_per_day', (string) $gcUserAttempts],
                        ['binance_giftcard_global_invalid_limit', (string) $gcGlobalInvalid],
                        ['binance_giftcard_account_age_days', (string) $gcAccountAgeDays],
                        ['binance_giftcard_count_for_ranking', $gcCountForRanking ? '1' : '0'],
                        ['binance_giftcard_rank_bonus_enabled', $gcRankBonusEnabled ? '1' : '0'],
                    ];
                    if ($gcApiKey !== '') $writes[] = ['binance_giftcard_api_key', $gcApiKey];
                    if ($gcSecretKey !== '') $writes[] = ['binance_giftcard_secret_key', $gcSecretKey];
                    foreach ($writes as $write) {
                        if (!upsertSetting($write[0], $write[1])) {
                            throw new RuntimeException('Unable to save Binance Gift Card setting');
                        }
                    }
                    $conn->commit();
                    $success = Lang::t('admin.settings.giftcard_success');
                    logHistory((int) $_SESSION['user_id'], 'update_binance_giftcard', 'Updated Binance Gift Card policy and credentials mode');
                } catch (Throwable $e) {
                    $conn->rollback();
                    error_log('Binance Gift Card settings update failed: ' . $e->getMessage());
                    $error = Lang::t('common.error.operation');
                }
            }
        }
    }
    elseif ($action === 'test_binance_giftcard') {
        $testResult = testBinanceGiftCardApi();
        if (!empty($testResult['success'])) {
            $success = (string) $testResult['message'];
            logHistory((int) $_SESSION['user_id'], 'test_binance_giftcard', 'Tested Binance Gift Card API credentials');
        } else {
            $error = (string) ($testResult['message'] ?? Lang::t('admin.settings.giftcard_test_failed'));
        }
    }

    // Handle admin account update
    elseif ($action === 'update_account') {
        $newUsername = trim(cleanInput(settingsPostString('account_username')));
        $newEmail = trim(cleanInput(settingsPostString('account_email')));
        $currentPassword = settingsPostString('current_password');
        $newPassword = settingsPostString('new_password');
        $confirmPassword = settingsPostString('confirm_password');

        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $me = $userId ? getUserById($userId) : null;

        if (!$me) {
            $error = Lang::t('common.error.user_not_found');
        } elseif ($currentPassword === '' || !password_verify($currentPassword, $me['password'])) {
            $error = Lang::t('account.error.password');
        } else {
            if ($newUsername === '') $newUsername = $me['username'];
            if ($newEmail === '') $newEmail = $me['email'];
            $usernameLength = function_exists('mb_strlen') ? mb_strlen($newUsername, 'UTF-8') : strlen($newUsername);

            if ($usernameLength < 3 || $usernameLength > 50 || !preg_match('/^[A-Za-z0-9_.-]+$/', $newUsername)) {
                $error = Lang::t('admin.settings.error.username');
            } elseif (!filter_var($newEmail, FILTER_VALIDATE_EMAIL) || strlen($newEmail) > 254) {
                $error = Lang::t('common.error.invalid_email');
            } elseif ($newPassword !== '' && strlen($newPassword) < 8) {
                $error = Lang::t('admin.settings.error.password_short');
            } elseif ($newPassword !== '' && $newPassword !== $confirmPassword) {
                $error = Lang::t('common.error.password_mismatch');
            } else {
                $check = $conn->prepare('SELECT id FROM users WHERE (username = ? OR email = ?) AND id <> ? LIMIT 1');
                if (!$check) {
                    $error = Lang::t('common.error.update_failed');
                } else {
                    $check->bind_param('ssi', $newUsername, $newEmail, $userId);
                    if (!$check->execute() || !$check->store_result()) {
                        error_log('Account duplicate check failed: ' . $check->error);
                        $check->close();
                        $error = Lang::t('common.error.update_failed');
                    } elseif ($check->num_rows > 0) {
                        $check->close();
                        $error = Lang::t('account.error.email_used');
                    } else {
                        $check->close();
                        $stmt = null;
                        if ($newPassword !== '') {
                            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                            if ($hash === false) {
                                $error = Lang::t('common.error.update_failed');
                            } else {
                                $stmt = $conn->prepare('UPDATE users SET username = ?, email = ?, password = ? WHERE id = ?');
                                if ($stmt) $stmt->bind_param('sssi', $newUsername, $newEmail, $hash, $userId);
                            }
                        } else {
                            $stmt = $conn->prepare('UPDATE users SET username = ?, email = ? WHERE id = ?');
                            if ($stmt) $stmt->bind_param('ssi', $newUsername, $newEmail, $userId);
                        }

                        if ($stmt && $stmt->execute()) {
                            $stmt->close();
                            $_SESSION['username'] = $newUsername;
                            regenerateSession();
                            $success = Lang::t('admin.settings.success.account');
                            logHistory($_SESSION['user_id'], 'update_account', 'Updated account settings');
                        } elseif ($error === '') {
                            if ($stmt) $stmt->close();
                            $error = Lang::t('common.error.update_failed');
                        }
                    }
                }
            }
        }
    }
    else {
        $error = Lang::t('common.error.invalid_request');
    }
}

settingsCleanupLegacyAutomationCoordinator();

$settings = [
    'currency' => getSetting('currency'),
    'currency_name' => getSetting('currency_name'),
    'site_name' => getSetting('site_name'),
    'default_language' => getSetting('default_language') ?: 'th',
    'reseller_discount' => getSetting('reseller_discount') ?: 50,
    'usd_thb_rate' => getSetting('usd_thb_rate', '35'),
    'site_base_url' => normalizeCanonicalBaseUrl(getSetting('site_base_url', ''))
];

$automationTokenMigratedForDisplay = settingsEnsureAutomationWebToken();
$automationWebToken = $automationTokenJustGenerated !== ''
    ? $automationTokenJustGenerated
    : $automationTokenMigratedForDisplay;
$automationWebCredentialConfigured = settingsAutomationWebCredentialConfigured();
$automationCronState = automationReadJobState('cron_heartbeat');
$automationInventoryState = automationReadJobState('cgo_inventory');
$automationSupplierState = automationReadJobState('supplier_catalog');
$automationSharedHistoryState = automationReadJobState('shared_history');
$automationCommerceCenterState = automationReadJobState('commerce_center');
$automationHealthy = automationCronIsHealthy(180);
$automationLastHeartbeat = trim((string) ($automationCronState['last_finished_at'] ?? ''));
$automationLastRunner = trim((string) ($automationCronState['last_runner'] ?? ''));

// Prefer the current authenticated Admin host so a stale Base URL from the
// abandoned coordinator can never generate a cron URL for the wrong website.
$automationBaseUrl = '';
$host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
if (preg_match('/^[a-z0-9.-]+(?::[0-9]{1,5})?$/D', $host) === 1) {
    $candidate = 'https://' . $host;
    $automationBaseUrl = function_exists('normalizeCanonicalBaseUrl')
        ? normalizeCanonicalBaseUrl($candidate)
        : '';
}
if ($automationBaseUrl === '') {
    $automationBaseUrl = trim((string) ($settings['site_base_url'] ?? ''));
}
$automationRunnerUrl = $automationBaseUrl !== ''
    ? rtrim($automationBaseUrl, '/') . '/automation_runner.php'
    : '';
$automationCriticalUrl = ($automationRunnerUrl !== '' && preg_match('/^[a-f0-9]{64}$/D', $automationWebToken) === 1)
    ? $automationRunnerUrl . '?token=' . rawurlencode($automationWebToken) . '&mode=critical'
    : '';

// Maintenance uses a mode-bound HMAC derived from the stored scheduler hash
// plus the site's private encryption key. The one-minute critical URL remains
// exactly as before and is not exposed as a maintenance credential.
$automationSchedulerHash = strtolower(trim((string) getSetting('automation_web_token_hash', '')));
if (preg_match('/^[a-f0-9]{64}$/D', $automationSchedulerHash) !== 1
    && preg_match('/^[a-f0-9]{64}$/D', $automationWebToken) === 1) {
    $automationSchedulerHash = hash('sha256', $automationWebToken);
}
$automationMaintenanceToken = ($automationSchedulerHash !== '' && function_exists('automationDeriveMaintenanceWebToken'))
    ? automationDeriveMaintenanceWebToken($automationSchedulerHash)
    : null;
$automationMaintenanceUrl = ($automationRunnerUrl !== ''
        && is_string($automationMaintenanceToken)
        && preg_match('/^[a-f0-9]{64}$/D', $automationMaintenanceToken) === 1)
    ? $automationRunnerUrl . '?token=' . rawurlencode($automationMaintenanceToken) . '&mode=maintenance'
    : '';
$automationTokenNeedsRegeneration = $automationWebCredentialConfigured && $automationWebToken === '';

// Get the saved text and its visibility separately. This preserves the text
// while an administrator temporarily hides the announcement.
$announcementSettings = getAnnouncementSettings();
$announcement = (string) ($announcementSettings['text'] ?? '');
$announcementStatus = (string) ($announcementSettings['status'] ?? '') === 'active'
    ? 'active'
    : 'inactive';
$announcementTextColor = getAnnouncementTextColor();

// Store branding (USER navbar title)
$storeBranding = getStoreBranding();
$meAccount = getCurrentUser();

// Resolve these once per request. Reusing the same CSRF field prevents
// incompatibility with implementations that rotate the token on generation.
$currentLang = getAppLang();
$csrfFieldHtml = csrfField();
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($currentLang, ENT_QUOTES, 'UTF-8'); ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo Lang::t('nav.settings'); ?> - <?php echo htmlspecialchars((string) ($storeBranding['title_text'] ?? 'STORE'), ENT_QUOTES, 'UTF-8'); ?>
    </title>
    <link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>:root{--sakazuki-accent:#6366f1;--sakazuki-accent-rgb:99 102 241;--sakazuki-accent2:#8b5cf6;--sakazuki-accent2-rgb:139 92 246;--sakazuki-glow:0 0 25px rgba(99,102,241,0.35)}</style>
    <style>
        .glass {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .animate-fade-in {
            animation: fadeInUp .15s ease-out;
        }

        @keyframes float {

            0%,
            100% {
                transform: translateY(0px);
            }

            50% {
                transform: translateY(-10px);
            }
        }

        .animate-float {
            animation: float 3s ease-in-out infinite;
        }
        /* Announcement marquee */
        .marquee-container {
            overflow: hidden;
            white-space: nowrap;
            position: relative;
            min-height: 1.75rem;
            display: flex;
            align-items: center;
            contain: layout paint;
        }

        .marquee-content {
            display: inline-flex;
            align-items: center;
            width: max-content;
            max-width: none;
            white-space: nowrap;
            color: var(--announcement-text-color, #93C5FD);
            will-change: transform;
            transform: translate3d(0, 0, 0);
        }

        .marquee-container[data-marquee-state="waiting"] .marquee-content {
            transform: translate3d(0, 0, 0);
        }

        @keyframes announcementMarqueeFallback {
            from { transform: translate3d(var(--marquee-start, 100vw), 0, 0); }
            to { transform: translate3d(var(--marquee-end, -100%), 0, 0); }
        }

        .marquee-content.announcement-marquee-fallback {
            animation: announcementMarqueeFallback var(--marquee-duration, 20s) linear infinite !important;
        }

        .marquee-container:hover .marquee-content.announcement-marquee-fallback,
        .marquee-container:focus-within .marquee-content.announcement-marquee-fallback {
            animation-play-state: paused !important;
        }


        select option {
            background-color: #141418; /* สีเดียวกับ bg-panel */
            color: #f3f4f6; /* สีเทาสว่างให้อ่านง่าย */
            padding: 10px;
        }

        select:focus {
            outline: none;
            border-color: #6366f1 !important; /* เปลี่ยนขอบเป็นสี accent ตอนคลิก */
        }
    </style>
</head>

<body class="bg-darkbg text-gray-100 min-h-screen">
    <?php include 'nav.php'; ?>

    <main class="flex-1 overflow-y-auto p-6 space-y-6">
        <!-- Header -->
        <div class="flex justify-between items-center mb-6">
            <h3 class="text-2xl font-bold text-white"><i class="bi bi-gear mr-2"></i><span
                    data-lang="admin.settings.title"><?php echo Lang::t('admin.settings.title'); ?></span></h3>
        </div>

        <!-- Alerts -->
        <?php if ($error): ?>
            <div class="glass border border-red-500/50 p-4 rounded-lg bg-red-900/20 text-red-300">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="glass border border-green-500/50 p-4 rounded-lg bg-green-900/20 text-green-300">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Main Settings -->
            <div class="lg:col-span-2 space-y-6">
                <!-- General Settings -->
                <!-- Store Navbar Title (User) -->
                <div class="glass rounded-xl overflow-hidden animate-fade-in" style="animation-delay: 0.15s;">
                    <div class="p-6 border-b border-white/10">
                        <h5 class="font-bold text-white flex items-center gap-2" data-lang="admin.settings.store_title">
                            <i class="bi bi-type"></i>
                            <?php echo Lang::t('admin.settings.store_title'); ?>
                        </h5>
                        <p class="text-gray-400 text-sm mt-1" data-lang="admin.settings.store_title_desc">
                            <?php echo Lang::t('admin.settings.store_title_desc'); ?></p>
                    </div>
                    <div class="p-6">
                        <form method="POST" enctype="multipart/form-data" class="space-y-4">
                            <?php echo $csrfFieldHtml; ?>
                            <input type="hidden" name="action" value="update_store_branding">

                            <div>
                                <label class="text-gray-400 text-sm mb-2 block"
                                    data-lang="admin.settings.store_title_text"><?php echo Lang::t('admin.settings.store_title_text'); ?></label>
                                <input type="text" name="store_title_text"
                                    class="glass border border-white/10 rounded-lg p-2 w-full bg-transparent text-white"
                                    value="<?php echo htmlspecialchars($storeBranding['title_text'] ?? 'STORE'); ?>"
                                    maxlength="100" required>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="text-gray-400 text-sm mb-2 block"
                                        data-lang="admin.settings.store_title_color"><?php echo Lang::t('admin.settings.store_title_color'); ?></label>
                                    <div class="flex gap-3 items-center">
                                        <input type="color" id="storeTitleColorPicker"
                                            value="<?php echo htmlspecialchars($storeBranding['title_color'] ?? '#60a5fa'); ?>"
                                            class="h-10 w-14 rounded border border-white/10 bg-transparent">
                                        <input type="text" name="store_title_color" id="storeTitleColorText"
                                            class="glass border border-white/10 rounded-lg p-2 w-full bg-transparent text-white"
                                            value="<?php echo htmlspecialchars($storeBranding['title_color'] ?? '#60a5fa', ENT_QUOTES, 'UTF-8'); ?>"
                                            pattern="^#[0-9a-fA-F]{6}$" maxlength="7" required
                                            placeholder="#60a5fa">
                                    </div>
                                    <small class="text-gray-500 text-xs"
                                        data-lang="admin.settings.store_title_color_hint"><?php echo Lang::t('admin.settings.store_title_color_hint'); ?></small>
                                </div>
                                <div>
                                    <label class="text-gray-400 text-sm mb-2 block"
                                        data-lang="admin.settings.store_title_style"><?php echo Lang::t('admin.settings.store_title_style'); ?></label>
                                    <select name="store_title_style"
                                        class="glass border border-white/10 rounded-lg p-2 w-full bg-transparent text-white">
                                        <?php
                                        $currentStyle = trim($storeBranding['title_style'] ?? 'font-extrabold');
                                        $options = [
                                            'font-normal' => ['en' => 'Normal', 'th' => 'ปกติ'],
                                            'font-medium' => ['en' => 'Medium', 'th' => 'ปานกลาง'],
                                            'font-semibold' => ['en' => 'Semi Bold', 'th' => 'กึ่งหนา'],
                                            'font-bold' => ['en' => 'Bold', 'th' => 'หนา'],
                                            'font-extrabold' => ['en' => 'Extra Bold', 'th' => 'หนามาก'],
                                            'italic' => ['en' => 'Italic', 'th' => 'ตัวเอียง'],
                                            'uppercase' => ['en' => 'Uppercase', 'th' => 'ตัวพิมพ์ใหญ่'],
                                            'underline' => ['en' => 'Underline', 'th' => 'ขีดเส้นใต้']
                                        ];
                                        foreach ($options as $val => $lbls) {
                                            $sel = ($currentStyle === $val) ? 'selected' : '';
                                            $label = ($currentLang === 'en') ? $lbls['en'] : $lbls['th'];
                                            echo "<option value=\"$val\" $sel>$label</option>";
                                        }
                                        ?>
                                    </select>
                                    <small class="text-gray-500 text-xs"
                                        data-lang="admin.settings.store_title_style_hint"><?php echo Lang::t('admin.settings.store_title_style_hint'); ?></small>
                                </div>
                            </div>

                            <div class="glass border border-white/10 rounded-lg p-4">
                                <div class="text-gray-400 text-sm mb-2" data-lang="common.preview">
                                    <?php echo Lang::t('common.preview'); ?>:</div>
                                <div class="text-2xl <?php echo htmlspecialchars($storeBranding['title_style'] ?? 'font-extrabold'); ?>"
                                    style="color: <?php echo htmlspecialchars($storeBranding['title_color'] ?? '#60a5fa'); ?>;">
                                    <i
                                        class="bi bi-cart mr-2"></i><?php echo htmlspecialchars($storeBranding['title_text'] ?? 'STORE'); ?>
                                </div>
                            </div>



                            <div class="border-t border-white/10 my-6"></div>
                            <h6 class="mb-4 text-white font-semibold flex items-center gap-2">
                                <i class="bi bi-image text-accent"></i>
                                <span
                                    data-lang="admin.settings.title_image"><?php echo Lang::t('admin.settings.title_image'); ?></span>
                            </h6>

                            <?php
                            $userIconAsset = getTitleIconAsset('user');
                            $resellerIconAsset = getTitleIconAsset('reseller');
                            ?>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                                <?php
                                $iconEditors = [
                                    [
                                        'scope' => 'user',
                                        'label_key' => 'admin.settings.user_title_image',
                                        'file_name' => 'user_title_icon_file',
                                        'remove_name' => 'remove_user_title_icon',
                                        'asset' => $userIconAsset,
                                    ],
                                    [
                                        'scope' => 'reseller',
                                        'label_key' => 'admin.settings.reseller_title_image',
                                        'file_name' => 'reseller_title_icon_file',
                                        'remove_name' => 'remove_reseller_title_icon',
                                        'asset' => $resellerIconAsset,
                                    ],
                                ];
                                foreach ($iconEditors as $editor):
                                    $assetPath = (string) ($editor['asset']['path'] ?? '');
                                    $storedPath = (string) ($editor['asset']['stored_path'] ?? $assetPath);
                                    $hasStoredIcon = $storedPath !== '';
                                    $assetExists = !empty($editor['asset']['exists']) && $assetPath !== '';
                                    $assetVersion = (int) ($editor['asset']['version'] ?? 0);
                                    $assetUrl = $assetExists
                                        ? '../' . ltrim($assetPath, '/') . ($assetVersion > 0 ? '?v=' . $assetVersion : '')
                                        : '';
                                    $currentName = $hasStoredIcon ? basename($storedPath) : '';
                                ?>
                                    <div class="rounded-xl border border-white/10 bg-white/[0.025] p-4"
                                         data-icon-editor
                                         data-has-current="<?php echo $hasStoredIcon ? '1' : '0'; ?>"
                                         data-current-src="<?php echo htmlspecialchars($assetUrl, ENT_QUOTES, 'UTF-8'); ?>"
                                         data-current-name="<?php echo htmlspecialchars($currentName, ENT_QUOTES, 'UTF-8'); ?>">
                                        <label class="text-gray-300 text-sm mb-3 block font-medium"
                                            data-lang="<?php echo htmlspecialchars($editor['label_key'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo Lang::t($editor['label_key']); ?></label>

                                        <input type="hidden" name="<?php echo htmlspecialchars($editor['remove_name'], ENT_QUOTES, 'UTF-8'); ?>" value="0" data-icon-remove-input>

                                        <div class="flex items-center gap-3 rounded-lg border border-white/10 bg-black/10 p-3 min-h-[76px]">
                                            <div class="w-14 h-14 rounded-xl border border-white/10 bg-white/5 flex items-center justify-center overflow-hidden shrink-0">
                                                <img data-icon-preview
                                                    src="<?php echo htmlspecialchars($assetUrl, ENT_QUOTES, 'UTF-8'); ?>"
                                                    alt="<?php echo htmlspecialchars(Lang::t('admin.settings.image_preview_alt'), ENT_QUOTES, 'UTF-8'); ?>"
                                                    class="w-full h-full object-contain p-1 <?php echo !$assetExists ? 'hidden' : ''; ?>"
                                                    onerror="this.classList.add('hidden'); this.nextElementSibling.classList.remove('hidden');">
                                                <i data-icon-empty class="bi bi-image text-2xl text-gray-500 <?php echo $assetExists ? 'hidden' : ''; ?>"></i>
                                            </div>

                                            <div class="min-w-0 flex-1">
                                                <p class="text-xs text-gray-400" data-lang="admin.settings.current_image"><?php echo Lang::t('admin.settings.current_image'); ?></p>
                                                <p data-icon-filename class="text-sm text-gray-200 truncate mt-1">
                                                    <?php
                                                    if ($assetExists) {
                                                        echo htmlspecialchars($currentName, ENT_QUOTES, 'UTF-8');
                                                    } elseif ($hasStoredIcon) {
                                                        echo Lang::t('admin.settings.image_file_missing');
                                                    } else {
                                                        echo Lang::t('admin.settings.no_image');
                                                    }
                                                    ?>
                                                </p>
                                            </div>

                                            <button type="button"
                                                data-icon-remove-button
                                                class="shrink-0 inline-flex items-center gap-1.5 rounded-lg border border-red-400/30 bg-red-500/10 px-3 py-2 text-xs font-semibold text-red-300 hover:bg-red-500/20 transition <?php echo !$hasStoredIcon ? 'hidden' : ''; ?>">
                                                <i class="bi bi-x-lg"></i>
                                                <span data-icon-remove-label data-lang="admin.settings.remove_image"><?php echo Lang::t('admin.settings.remove_image'); ?></span>
                                            </button>
                                        </div>

                                        <p data-icon-pending class="hidden mt-2 text-xs text-amber-300">
                                            <i class="bi bi-exclamation-circle mr-1"></i>
                                            <span data-lang="admin.settings.image_remove_pending"><?php echo Lang::t('admin.settings.image_remove_pending'); ?></span>
                                        </p>

                                        <input type="file"
                                            name="<?php echo htmlspecialchars($editor['file_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                            accept="image/png,.png"
                                            data-icon-file
                                            class="mt-3 glass border border-white/10 rounded-lg p-2 w-full bg-transparent text-white">
                                        <small class="text-gray-500 text-xs mt-2 block"
                                            data-lang="admin.settings.image_hint_full"><?php echo Lang::t('admin.settings.image_hint_full'); ?></small>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="submit"
                                class="bg-accent hover:opacity-90 text-white px-6 py-2 rounded-lg font-medium transition flex items-center gap-2">
                                <i class="bi bi-save"></i>
                                <span
                                    data-lang="admin.settings.save_store_title"><?php echo Lang::t('admin.settings.save_store_title'); ?></span>
                            </button>
                        </form>
                    </div>
                </div>

                <script>
                    // Sync color picker <-> text
                    (function () {
                        const picker = document.getElementById('storeTitleColorPicker');
                        const text = document.getElementById('storeTitleColorText');
                        if (!picker || !text) return;
                        picker.addEventListener('input', () => text.value = picker.value);
                        text.addEventListener('input', () => {
                            if (/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(text.value)) {
                                picker.value = text.value;
                            }
                        });
                    })();

                    // Preview, remove, and restore navbar title images without
                    // relying on viewport size. The database is updated only
                    // after the form is submitted successfully.
                    (function () {
                        const translate = (key) => (typeof Lang !== 'undefined' ? Lang.t(key) : key);

                        document.querySelectorAll('[data-icon-editor]').forEach((editor) => {
                            const fileInput = editor.querySelector('[data-icon-file]');
                            const removeInput = editor.querySelector('[data-icon-remove-input]');
                            const removeButton = editor.querySelector('[data-icon-remove-button]');
                            const removeLabel = editor.querySelector('[data-icon-remove-label]');
                            const preview = editor.querySelector('[data-icon-preview]');
                            const emptyIcon = editor.querySelector('[data-icon-empty]');
                            const fileName = editor.querySelector('[data-icon-filename]');
                            const pending = editor.querySelector('[data-icon-pending]');

                            const hasCurrent = editor.dataset.hasCurrent === '1';
                            const currentSrc = editor.dataset.currentSrc || '';
                            const currentName = editor.dataset.currentName || '';
                            let previewObjectUrl = '';

                            const revokePreviewUrl = () => {
                                if (previewObjectUrl) {
                                    URL.revokeObjectURL(previewObjectUrl);
                                    previewObjectUrl = '';
                                }
                            };

                            const showImage = (src, name) => {
                                preview.src = src;
                                preview.classList.remove('hidden', 'opacity-40', 'grayscale');
                                emptyIcon.classList.add('hidden');
                                fileName.removeAttribute('data-lang');
                                fileName.textContent = name || translate('admin.settings.image_preview_alt');
                            };

                            const showEmpty = () => {
                                preview.removeAttribute('src');
                                preview.classList.add('hidden');
                                emptyIcon.classList.remove('hidden');
                                fileName.dataset.lang = 'admin.settings.no_image';
                                fileName.textContent = translate('admin.settings.no_image');
                            };

                            const restoreCurrent = () => {
                                if (!hasCurrent) {
                                    showEmpty();
                                    return;
                                }
                                if (currentSrc) {
                                    showImage(currentSrc, currentName);
                                } else {
                                    showEmpty();
                                    fileName.dataset.lang = 'admin.settings.image_file_missing';
                                    fileName.textContent = translate('admin.settings.image_file_missing');
                                }
                            };

                            const setRemoveState = (enabled) => {
                                removeInput.value = enabled ? '1' : '0';
                                pending.classList.toggle('hidden', !enabled);
                                if (hasCurrent) {
                                    const labelKey = enabled ? 'admin.settings.undo_remove' : 'admin.settings.remove_image';
                                    removeLabel.dataset.lang = labelKey;
                                    if (enabled) {
                                        preview.classList.add('opacity-40', 'grayscale');
                                    } else {
                                        preview.classList.remove('opacity-40', 'grayscale');
                                    }
                                    removeLabel.textContent = translate(labelKey);
                                }
                            };

                            if (removeButton) {
                                removeButton.addEventListener('click', () => {
                                    if (fileInput.files && fileInput.files.length > 0) {
                                        fileInput.value = '';
                                        revokePreviewUrl();
                                        if (hasCurrent) {
                                            restoreCurrent();
                                            setRemoveState(false);
                                        } else {
                                            showEmpty();
                                            removeButton.classList.add('hidden');
                                        }
                                        return;
                                    }

                                    if (hasCurrent) {
                                        setRemoveState(removeInput.value !== '1');
                                    }
                                });
                            }

                            fileInput.addEventListener('change', () => {
                                revokePreviewUrl();
                                const file = fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;
                                if (!file) {
                                    if (hasCurrent) {
                                        restoreCurrent();
                                        removeButton.classList.remove('hidden');
                                    } else {
                                        showEmpty();
                                        removeButton.classList.add('hidden');
                                    }
                                    setRemoveState(false);
                                    return;
                                }

                                previewObjectUrl = URL.createObjectURL(file);
                                showImage(previewObjectUrl, file.name);
                                removeButton.classList.remove('hidden');
                                setRemoveState(false);
                            });

                            window.addEventListener('beforeunload', revokePreviewUrl, { once: true });
                        });
                    })();
                </script>

                <div class="glass rounded-xl overflow-hidden animate-fade-in">
                    <div class="p-6 border-b border-white/10">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h5 class="font-bold text-white flex items-center gap-2">
                                    <i class="bi bi-arrow-repeat text-cyan-300"></i>
                                    <?php echo $currentLang === 'en' ? 'Background Automation' : 'ระบบทำงานเบื้องหลังอัตโนมัติ'; ?>
                                </h5>
                                <p class="mt-1 text-xs text-gray-400">
                                    <?php echo $currentLang === 'en'
                                        ? 'Reliable mode: each website runs itself independently using the two scheduler URLs below.'
                                        : 'โหมดเสถียร: แต่ละเว็บไซต์ดูแลตัวเองแยกกัน โดยใช้ URL ตั้งเวลา 2 งานด้านล่าง'; ?>
                                </p>
                            </div>
                            <span class="inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-semibold <?php echo $automationHealthy ? 'border-emerald-400/30 bg-emerald-500/10 text-emerald-300' : 'border-red-400/30 bg-red-500/10 text-red-300'; ?>">
                                <span class="h-2 w-2 rounded-full <?php echo $automationHealthy ? 'bg-emerald-400' : 'bg-red-400'; ?>"></span>
                                <?php echo $automationHealthy
                                    ? ($currentLang === 'en' ? 'External scheduler running' : 'ตัวตั้งเวลาภายนอกกำลังทำงาน')
                                    : ($currentLang === 'en' ? 'Scheduler not detected' : 'ยังไม่พบตัวตั้งเวลาทำงาน'); ?>
                            </span>
                        </div>
                    </div>

                    <div class="p-6 space-y-5">
                        <div class="grid gap-3 md:grid-cols-3">
                            <div class="rounded-xl border border-white/10 bg-black/10 p-4">
                                <div class="text-xs text-gray-500"><?php echo $currentLang === 'en' ? 'Last scheduler heartbeat' : 'ตัวตั้งเวลาเรียกล่าสุด'; ?></div>
                                <div class="mt-1 text-sm font-semibold text-white break-words">
                                    <?php echo htmlspecialchars($automationLastHeartbeat !== '' ? $automationLastHeartbeat : '-', ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                                <div class="mt-1 text-xs text-gray-500">
                                    <?php echo $currentLang === 'en' ? 'Runner: ' : 'ชนิด: '; ?><?php echo htmlspecialchars($automationLastRunner !== '' ? $automationLastRunner : '-', ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                            </div>
                            <div class="rounded-xl border border-white/10 bg-black/10 p-4">
                                <div class="text-xs text-gray-500">CGO Stock</div>
                                <div class="mt-1 text-sm font-semibold text-white break-words">
                                    <?php echo htmlspecialchars((string) ($automationInventoryState['last_success_at'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                                <div class="mt-1 text-xs text-gray-500">
                                    <?php echo htmlspecialchars((string) ($automationInventoryState['last_message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                            </div>
                            <div class="rounded-xl border border-white/10 bg-black/10 p-4">
                                <div class="text-xs text-gray-500">Supplier Stock</div>
                                <div class="mt-1 text-sm font-semibold text-white break-words">
                                    <?php echo htmlspecialchars((string) ($automationSupplierState['last_success_at'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                                <div class="mt-1 text-xs text-gray-500">
                                    <?php echo htmlspecialchars((string) ($automationSupplierState['last_message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                            </div>
                        </div>

                        <?php
                        $sharedHistoryFailures = (int) ($automationSharedHistoryState['consecutive_failures'] ?? 0);
                        $commerceCenterFailures = (int) ($automationCommerceCenterState['consecutive_failures'] ?? 0);
                        ?>
                        <?php if ($sharedHistoryFailures > 0 || $commerceCenterFailures > 0): ?>
                            <div class="rounded-xl border border-amber-400/20 bg-amber-500/5 p-4 text-xs text-amber-100">
                                <div class="font-semibold mb-2"><?php echo $currentLang === 'en' ? 'Maintenance warnings' : 'คำเตือนงานดูแลระบบ'; ?></div>
                                <div class="grid gap-2 md:grid-cols-2">
                                    <div>Shared History: <?php echo $sharedHistoryFailures > 0 ? ('warning (' . $sharedHistoryFailures . ')') : 'OK'; ?></div>
                                    <div>Commerce Center: <?php echo $commerceCenterFailures > 0 ? ('warning (' . $commerceCenterFailures . ')') : 'OK'; ?></div>
                                </div>
                                <div class="mt-2 text-amber-200/80">
                                    <?php echo $currentLang === 'en'
                                        ? 'These are maintenance warnings. They do not stop the every-minute stock job.'
                                        : 'สองตัวนี้เป็นงานดูแลระบบ หากเตือนจะไม่ทำให้งานสต๊อกทุก 1 นาทีหยุดตาม'; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="rounded-xl border border-violet-400/20 bg-violet-500/5 p-4">
                            <div class="font-semibold text-violet-200">
                                <?php echo $currentLang === 'en' ? 'cron-job.org — ready-to-copy URLs' : 'cron-job.org — URL พร้อมคัดลอก'; ?>
                            </div>
                            <p class="mt-1 text-xs text-gray-400">
                                <?php echo $currentLang === 'en'
                                    ? 'Create these two jobs on EACH website. The token is already included; no cURL command or custom header is required.'
                                    : 'สร้าง 2 งานนี้แยกกันในแต่ละเว็บไซต์ รหัส Token รวมอยู่ใน URL แล้ว ไม่ต้องต่อ URL เอง ไม่ต้องตั้ง Header'; ?>
                            </p>

                            <?php if ($automationCriticalUrl !== '' && $automationMaintenanceUrl !== ''): ?>
                                <div class="mt-4 grid gap-4">
                                    <div class="rounded-xl border border-emerald-400/20 bg-black/20 p-4">
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <div>
                                                <div class="text-sm font-semibold text-emerald-300"><?php echo $currentLang === 'en' ? '1) Critical / Stock' : '1) งานสำคัญ / เช็กสต๊อก'; ?></div>
                                                <div class="mt-1 text-xs text-gray-400"><?php echo $currentLang === 'en' ? 'Schedule: every 1 minute' : 'ตั้งเวลา: ทุก 1 นาที'; ?> • <code>* * * * *</code></div>
                                            </div>
                                            <button type="button" data-copy-automation-target="automation-critical-url"
                                                class="inline-flex items-center gap-2 rounded-lg border border-emerald-400/30 bg-emerald-500/10 px-4 py-2 text-sm font-semibold text-emerald-200 hover:bg-white/10 transition">
                                                <i class="bi bi-copy"></i><?php echo $currentLang === 'en' ? 'Copy full URL' : 'คัดลอก URL ทั้งหมด'; ?>
                                            </button>
                                        </div>
                                        <input id="automation-critical-url" type="password" readonly value="<?php echo htmlspecialchars($automationCriticalUrl, ENT_QUOTES, 'UTF-8'); ?>"
                                            class="mt-3 glass border border-white/10 rounded-lg p-3 w-full bg-transparent text-xs text-white font-mono">
                                    </div>

                                    <div class="rounded-xl border border-cyan-400/20 bg-black/20 p-4">
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <div>
                                                <div class="text-sm font-semibold text-cyan-200"><?php echo $currentLang === 'en' ? '2) Maintenance' : '2) งานดูแลระบบ'; ?></div>
                                                <div class="mt-1 text-xs text-gray-400"><?php echo $currentLang === 'en' ? 'Schedule: every 15 minutes' : 'ตั้งเวลา: ทุก 15 นาที'; ?> • <code>*/15 * * * *</code></div>
                                                <div class="mt-1 text-[11px] text-cyan-200/70"><?php echo $currentLang === 'en'
                                                    ? 'Protected by a maintenance-only HMAC credential; the 1-minute stock credential is unchanged.'
                                                    : 'ใช้รหัส HMAC แยกเฉพาะ Maintenance โดยรหัสงานสต๊อกทุก 1 นาทีไม่เปลี่ยน'; ?></div>
                                            </div>
                                            <button type="button" data-copy-automation-target="automation-maintenance-url"
                                                class="inline-flex items-center gap-2 rounded-lg border border-cyan-400/20 bg-cyan-500/10 px-4 py-2 text-sm font-semibold text-cyan-200 hover:bg-cyan-500/10 transition">
                                                <i class="bi bi-copy"></i><?php echo $currentLang === 'en' ? 'Copy full URL' : 'คัดลอก URL ทั้งหมด'; ?>
                                            </button>
                                        </div>
                                        <input id="automation-maintenance-url" type="password" readonly value="<?php echo htmlspecialchars($automationMaintenanceUrl, ENT_QUOTES, 'UTF-8'); ?>"
                                            class="mt-3 glass border border-white/10 rounded-lg p-3 w-full bg-transparent text-xs text-white font-mono">
                                    </div>
                                </div>
                                <div id="automation-copy-status" class="mt-3 text-xs text-emerald-200" aria-live="polite"></div>
                            <?php elseif ($automationTokenNeedsRegeneration): ?>
                                <div class="mt-4 rounded-lg border border-amber-400/20 bg-amber-500/5 p-3 text-xs text-amber-200">
                                    <?php echo $currentLang === 'en'
                                        ? 'Your current scheduler token is hash-only from the previous release, so it cannot be displayed again. Regenerate it once below; the old cron URL will stop working.'
                                        : 'รหัส Cron รุ่นก่อนถูกเก็บแบบ Hash อย่างเดียว จึงดึงรหัสเดิมกลับมาแสดงไม่ได้ ให้กด “สร้าง URL Cron ใหม่” 1 ครั้ง แล้ว URL เก่าจะหยุดทำงาน'; ?>
                                </div>
                            <?php else: ?>
                                <div class="mt-4 rounded-lg border border-amber-400/20 bg-amber-500/5 p-3 text-xs text-amber-200">
                                    <?php echo $currentLang === 'en'
                                        ? 'Unable to build the scheduler URLs. Open this page through the real HTTPS domain, then regenerate the scheduler URL.'
                                        : 'ยังสร้าง URL ไม่ได้ กรุณาเปิดหน้า Admin ผ่านโดเมน HTTPS จริงของเว็บ แล้วกดสร้าง URL Cron ใหม่'; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="flex flex-wrap gap-3">
                            <form method="POST">
                                <?php echo $csrfFieldHtml; ?>
                                <input type="hidden" name="action" value="run_automation_test">
                                <button type="submit" class="inline-flex items-center gap-2 rounded-lg border border-cyan-400/20 bg-cyan-500/10 px-4 py-2 text-sm font-semibold text-cyan-200 hover:bg-cyan-500/10 transition">
                                    <i class="bi bi-play-circle"></i>
                                    <?php echo $currentLang === 'en' ? 'Test critical jobs now' : 'ทดสอบงานสำคัญตอนนี้'; ?>
                                </button>
                            </form>
                            <form method="POST" onsubmit="return confirm('<?php echo $currentLang === 'en' ? 'The old scheduler URLs will stop working. Continue?' : 'URL Cron เดิมจะหยุดทำงาน ต้องการสร้างรหัสใหม่หรือไม่?'; ?>');">
                                <?php echo $csrfFieldHtml; ?>
                                <input type="hidden" name="action" value="regenerate_automation_token">
                                <button type="submit" class="inline-flex items-center gap-2 rounded-lg border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold text-gray-300 hover:bg-white/10 transition">
                                    <i class="bi bi-key"></i>
                                    <?php echo $currentLang === 'en' ? 'Regenerate scheduler URLs' : 'สร้าง URL Cron ใหม่'; ?>
                                </button>
                            </form>
                        </div>

                        <div class="rounded-xl border border-amber-400/20 bg-amber-500/5 p-4 text-xs text-amber-200">
                            <?php echo $currentLang === 'en'
                                ? 'For two websites, repeat the same setup on each website. Use separate tokens. Do not reuse one website token on the other website.'
                                : 'ถ้ามี 2 เว็บไซต์ ให้ตั้งแบบนี้แยกเว็บละชุด และใช้ Token คนละรหัส ห้ามเอารหัสเว็บหนึ่งไปใช้กับอีกเว็บ'; ?>
                        </div>
                    </div>
                </div>

                <script>
                (() => {
                    const root = document.currentScript && document.currentScript.previousElementSibling;
                    if (!root) return;
                    const status = root.querySelector('#automation-copy-status');
                    root.querySelectorAll('[data-copy-automation-target]').forEach((button) => {
                        button.addEventListener('click', async () => {
                            const targetId = button.getAttribute('data-copy-automation-target');
                            const input = targetId ? root.querySelector('#' + targetId) : null;
                            if (!input || !input.value) return;
                            let copied = false;
                            try {
                                if (navigator.clipboard && window.isSecureContext) {
                                    await navigator.clipboard.writeText(input.value);
                                    copied = true;
                                }
                            } catch (_) {}
                            if (!copied) {
                                input.type = 'text';
                                input.select();
                                try { copied = document.execCommand('copy'); } catch (_) { copied = false; }
                                input.type = 'password';
                                window.getSelection && window.getSelection().removeAllRanges();
                            }
                            if (status) {
                                status.textContent = copied
                                    ? <?php echo json_encode($currentLang === 'en' ? 'Copied. Paste it directly into cron-job.org.' : 'คัดลอกแล้ว นำไปวางใน cron-job.org ได้เลย', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
                                    : <?php echo json_encode($currentLang === 'en' ? 'Copy failed. Tap the field, select all, and copy manually.' : 'คัดลอกอัตโนมัติไม่สำเร็จ แตะช่อง URL แล้วเลือกทั้งหมดเพื่อคัดลอกเอง', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
                            }
                        });
                    });
                })();
                </script>

                <div class="glass rounded-xl overflow-hidden animate-fade-in">
                    <div class="p-6 border-b border-white/10">
                        <h5 class="font-bold text-white flex items-center gap-2"
                            data-lang="admin.settings.general_title">
                            <?php echo Lang::t('admin.settings.general_title'); ?>
                        </h5>
                    </div>
                    <div class="p-6">
                        <form method="POST" enctype="multipart/form-data">
                            <?php echo $csrfFieldHtml; ?>
                            <input type="hidden" name="action" value="update_settings">

                            <div class="mb-4">
                                <label class="text-gray-400 text-sm mb-2 block"
                                    data-lang="admin.settings.site_name"><?php echo Lang::t('admin.settings.site_name'); ?></label>
                                <input type="text"
                                    class="glass border border-white/10 rounded-lg p-2 w-full bg-transparent text-white"
                                    name="site_name" value="<?php echo htmlspecialchars($settings['site_name']); ?>"
                                    required>
                            </div>

                            <div class="mb-4">
                                <label class="text-gray-400 text-sm mb-2 block"
                                    data-lang="admin.settings.currency_selection"><?php echo Lang::t('admin.settings.currency_selection'); ?></label>
                                <select name="currency_combined"
                                    class="glass border border-white/10 rounded-lg p-2 w-full bg-transparent text-white">
                                    <?php
                                    $currentCur = $settings['currency_name'] . '|' . $settings['currency'];
                                    $currencies = [
                                        'THB|฿' => 'admin.settings.currency.thb',
                                        'USD|$' => 'admin.settings.currency.usd',
                                    ];

                                    // If current is not in list, add it as custom (to prevent losing data)
                                    if (!isset($currencies[$currentCur]) && !empty($settings['currency_name'])) {
                                        echo '<option value="' . htmlspecialchars($currentCur) . '" selected>' . htmlspecialchars($settings['currency_name'] . ' (' . $settings['currency'] . ')') . '</option>';
                                    }

                                    foreach ($currencies as $val => $lbl) {
                                        $sel = ($currentCur === $val) ? 'selected' : '';
                                        echo "<option value=\"".htmlspecialchars($val)."\" $sel data-lang=\"$lbl\">" . Lang::t($lbl) . "</option>";
                                    }
                                    ?>
                                </select>
                                <small class="text-gray-500 text-xs" data-lang="admin.settings.currency_hint">
                                    <?php echo Lang::t('admin.settings.currency_hint'); ?>
                                </small>
                            </div>

                            <div class="mb-4">
                                <label class="text-gray-400 text-sm mb-2 block">
                                    <?php echo $currentLang === 'en' ? 'Fixed USD exchange rate' : 'อัตราแลกเปลี่ยนดอลลาร์แบบกำหนดเอง'; ?>
                                </label>
                                <div class="flex items-center gap-2">
                                    <span class="shrink-0 rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-gray-300">1 USD =</span>
                                    <input type="number" name="usd_thb_rate" min="1" max="1000" step="0.0001"
                                        class="glass border border-white/10 rounded-lg p-2 w-full bg-transparent text-white"
                                        value="<?php echo htmlspecialchars((string) ($settings['usd_thb_rate'] ?: '35'), ENT_QUOTES, 'UTF-8'); ?>" required>
                                    <span class="shrink-0 text-sm text-gray-400">THB</span>
                                </div>
                                <small class="text-gray-500 text-xs">
                                    <?php echo $currentLang === 'en'
                                        ? 'Default is 35 THB per USD. The whole site uses this value and does not call an external exchange-rate API.'
                                        : 'ค่าเริ่มต้น 35 บาทต่อ 1 ดอลลาร์ ทั้งเว็บไซต์จะใช้เลขนี้ และไม่เรียก API ค่าเงินภายนอก'; ?>
                                </small>
                            </div>

                            <div class="mb-4">
                                <label class="text-gray-400 text-sm mb-2 block" data-lang="admin.settings.default_language">
                                    <?php echo Lang::t('admin.settings.default_language'); ?>
                                </label>
                                <select name="default_language"
                                    class="glass border border-white/10 rounded-lg p-2 w-full bg-transparent text-white">
                                    <option value="th" <?php echo ($settings['default_language'] === 'th') ? 'selected' : ''; ?>>
                                        ไทย (TH)
                                    </option>
                                    <option value="en" <?php echo ($settings['default_language'] === 'en') ? 'selected' : ''; ?>>
                                        English (EN)
                                    </option>
                                </select>
                                <small class="text-gray-500 text-xs" data-lang="admin.settings.default_language_hint">
                                    <?php echo Lang::t('admin.settings.default_language_hint'); ?>
                                </small>
                            </div>

                            <div class="mt-6">
                                <button type="submit" class="bg-accent hover:opacity-90 text-white px-6 py-2 rounded-lg font-medium transition flex items-center gap-2">
                                    <i class="bi bi-save"></i>
                                    <span data-lang="admin.settings.save_general"><?php echo Lang::t('admin.settings.save_general'); ?></span>
                                </button>
                            </div>
                        </form>

                        <!-- TrueMoney Angpao Settings -->
                        <div class="border-t border-orange-500/30 my-6"></div>
                        <h6 class="mb-4 text-orange-300 font-semibold flex items-center gap-2" data-lang="admin.settings.truemoney_title">
                            <i class="bi bi-gift text-orange-400"></i>
                            <?php echo Lang::t('admin.settings.truemoney_title'); ?>
                        </h6>
                        <form method="POST" class="space-y-4">
                            <?php echo $csrfFieldHtml; ?>
                            <input type="hidden" name="action" value="update_truemoney">
                            
                            <div class="flex items-center gap-3 mb-4">
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" name="truemoney_enabled" value="1" 
                                           <?php echo getSetting('truemoney_enabled') === '1' ? 'checked' : ''; ?>
                                           class="sr-only peer">
                                    <div class="w-11 h-6 bg-gray-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-orange-500"></div>
                                </label>
                                <span class="text-gray-300" data-lang="admin.settings.enable_truemoney"><?php echo Lang::t('admin.settings.enable_truemoney'); ?></span>
                            </div>

                            <div>
                                <label class="text-gray-400 text-sm mb-2 block" data-lang="admin.settings.wallet_number"><?php echo Lang::t('admin.settings.wallet_number'); ?></label>
                                <input type="text" name="truemoney_phone"
                                       class="glass border border-orange-500/30 rounded-lg p-2 w-full bg-transparent text-white"
                                       value="<?php echo htmlspecialchars(getSetting('truemoney_phone') ?? ''); ?>"
                                       data-lang-placeholder="admin.settings.wallet_hint"
                                       placeholder="<?php echo Lang::t('admin.settings.wallet_hint'); ?>" inputmode="tel" autocomplete="tel" maxlength="15">
                                <small class="text-gray-500 text-xs" data-lang="admin.settings.wallet_hint"><?php echo Lang::t('admin.settings.wallet_hint'); ?></small>
                            </div>

                            <button type="submit" class="bg-orange-500 hover:bg-orange-600 text-white px-6 py-2 rounded-lg font-medium transition flex items-center gap-2">
                                <i class="bi bi-save"></i>
                                <span data-lang="admin.settings.save_truemoney"><?php echo Lang::t('admin.settings.save_truemoney'); ?></span>
                            </button>
                        </form>

                        <!-- EasySlip API Settings -->
                        <div class="border-t border-green-500/30 my-6"></div>
                        <h6 class="mb-4 text-green-300 font-semibold flex items-center gap-2" data-lang="admin.settings.easyslip_title">
                            <i class="bi bi-receipt-cutoff text-green-400"></i>
                            <?php echo Lang::t('admin.settings.easyslip_title'); ?>
                        </h6>
                        <form method="POST" class="space-y-4">
                            <?php echo $csrfFieldHtml; ?>
                            <input type="hidden" name="action" value="update_easyslip">

                            <div class="flex items-center gap-3 mb-4">
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" name="easyslip_enabled" value="1" 
                                           <?php echo getSetting('easyslip_enabled') === '1' ? 'checked' : ''; ?>
                                           class="sr-only peer">
                                    <div class="w-11 h-6 bg-gray-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-green-500"></div>
                                </label>
                                <span class="text-gray-300" data-lang="admin.settings.easyslip_enabled"><?php echo Lang::t('admin.settings.easyslip_enabled'); ?></span>
                            </div>

                            <div>
                                <label class="text-gray-400 text-sm mb-2 block" data-lang="admin.settings.api_key"><?php echo Lang::t('admin.settings.api_key'); ?></label>
                                <input type="password" name="easyslip_api_key"
                                       class="glass border border-green-500/30 rounded-lg p-2 w-full bg-transparent text-white font-mono text-sm"
                                       value="" autocomplete="new-password"
                                       placeholder="<?php echo htmlspecialchars(Lang::t('admin.settings.keep_api_key_placeholder'), ENT_QUOTES, 'UTF-8'); ?>" data-lang-placeholder="admin.settings.keep_api_key_placeholder">
                                <small class="text-gray-500 text-xs" data-lang="admin.settings.easyslip_api_hint"><?php echo Lang::t('admin.settings.easyslip_api_hint'); ?></small>
                            </div>

                            <div>
                                <label class="text-gray-400 text-sm mb-2 block" data-lang="admin.settings.easyslip_phone"><?php echo Lang::t('admin.settings.easyslip_phone'); ?></label>
                                <input type="text" name="easyslip_phone"
                                       class="glass border border-green-500/30 rounded-lg p-2 w-full bg-transparent text-white"
                                       value="<?php echo htmlspecialchars(getSetting('easyslip_phone') ?? ''); ?>"
                                       data-lang-placeholder="admin.settings.easyslip_phone_placeholder"
                                       placeholder="<?php echo Lang::t('admin.settings.easyslip_phone_placeholder'); ?>" inputmode="tel" autocomplete="tel" maxlength="15">
                                <small class="text-gray-500 text-xs" data-lang="admin.settings.easyslip_phone_hint"><?php echo Lang::t('admin.settings.easyslip_phone_hint'); ?></small>
                            </div>
                            <div>
                                <label class="text-gray-400 text-sm mb-2 block" data-lang="admin.settings.easyslip_account"><?php echo Lang::t('admin.settings.easyslip_account'); ?></label>
                                <input type="text" name="easyslip_account_number"
                                       class="glass border border-green-500/30 rounded-lg p-2 w-full bg-transparent text-white"
                                       value="<?php echo htmlspecialchars(getSetting('easyslip_account_number') ?? ''); ?>"
                                       placeholder="<?php echo Lang::t('admin.settings.account_number_placeholder'); ?>" data-lang-placeholder="admin.settings.account_number_placeholder">
                                <small class="text-gray-500 text-xs" data-lang="admin.settings.easyslip_account_hint"><?php echo Lang::t('admin.settings.easyslip_account_hint'); ?></small>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="text-gray-400 text-sm mb-2 block" data-lang="admin.settings.bank_name_th"><?php echo Lang::t('admin.settings.bank_name_th'); ?></label>
                                    <input type="text" name="easyslip_bank_name_th"
                                           class="glass border border-green-500/30 rounded-lg p-2 w-full bg-transparent text-white"
                                           value="<?php echo htmlspecialchars(getSetting('easyslip_bank_name') ?? ''); ?>"
                                           placeholder="<?php echo htmlspecialchars(Lang::t('admin.settings.bank_name_th_placeholder'), ENT_QUOTES, 'UTF-8'); ?>" data-lang-placeholder="admin.settings.bank_name_th_placeholder">
                                    <small class="text-gray-500 text-xs" data-lang="admin.settings.bank_name_th_hint"><?php echo Lang::t('admin.settings.bank_name_th_hint'); ?></small>
                                </div>
                                <div>
                                    <label class="text-gray-400 text-sm mb-2 block" data-lang="admin.settings.bank_name_en"><?php echo Lang::t('admin.settings.bank_name_en'); ?></label>
                                    <input type="text" name="easyslip_bank_name_en"
                                           class="glass border border-green-500/30 rounded-lg p-2 w-full bg-transparent text-white"
                                           value="<?php echo htmlspecialchars(getSetting('easyslip_bank_name_en') ?? ''); ?>"
                                           placeholder="<?php echo htmlspecialchars(Lang::t('admin.settings.bank_name_en_placeholder'), ENT_QUOTES, 'UTF-8'); ?>" data-lang-placeholder="admin.settings.bank_name_en_placeholder">
                                    <small class="text-gray-500 text-xs" data-lang="admin.settings.bank_name_en_hint"><?php echo Lang::t('admin.settings.bank_name_en_hint'); ?></small>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="text-gray-400 text-sm mb-2 block" data-lang="admin.settings.receiver_name_th"><?php echo Lang::t('admin.settings.receiver_name_th'); ?></label>
                                    <input type="text" name="easyslip_receiver_name_th"
                                           class="glass border border-green-500/30 rounded-lg p-2 w-full bg-transparent text-white"
                                           value="<?php echo htmlspecialchars(getSetting('easyslip_receiver_name') ?? ''); ?>"
                                           data-lang-placeholder="admin.settings.receiver_name_placeholder"
                                           placeholder="<?php echo Lang::t('admin.settings.receiver_name_placeholder'); ?>">
                                    <small class="text-gray-500 text-xs"><?php echo Lang::t('admin.settings.receiver_name_th_hint'); ?></small>
                                </div>
                                <div>
                                    <label class="text-gray-400 text-sm mb-2 block" data-lang="admin.settings.receiver_name_en"><?php echo Lang::t('admin.settings.receiver_name_en'); ?></label>
                                    <input type="text" name="easyslip_receiver_name_en"
                                           class="glass border border-green-500/30 rounded-lg p-2 w-full bg-transparent text-white"
                                           value="<?php echo htmlspecialchars(getSetting('easyslip_receiver_name_en') ?? ''); ?>"
                                           data-lang-placeholder="admin.settings.receiver_name_placeholder"
                                           placeholder="<?php echo Lang::t('admin.settings.receiver_name_placeholder'); ?>">
                                    <small class="text-gray-500 text-xs"><?php echo Lang::t('admin.settings.receiver_name_en_hint'); ?></small>
                                </div>
                            </div>

                            <div>
                                <label class="text-gray-400 text-sm mb-2 block" data-lang="admin.settings.slip_max_age"><?php echo Lang::t('admin.settings.slip_max_age'); ?></label>
                                <input type="number" name="easyslip_max_age_minutes" min="5" max="10080" step="1"
                                       class="glass border border-green-500/30 rounded-lg p-2 w-full bg-transparent text-white"
                                       value="<?php echo htmlspecialchars((string) (getSetting('easyslip_max_age_minutes') ?: '1440')); ?>" required>
                                <small class="text-gray-500 text-xs" data-lang="admin.settings.slip_max_age_hint"><?php echo Lang::t('admin.settings.slip_max_age_hint'); ?></small>
                            </div>

                            <div class="bg-green-500/10 border border-green-500/30 rounded-lg p-3 text-sm text-green-300">
                                <i class="bi bi-info-circle mr-1"></i>
                                <span data-lang="admin.settings.easyslip_info"><?php echo Lang::t('admin.settings.easyslip_info'); ?></span>
                            </div>

                            <button type="submit" class="bg-green-500 hover:bg-green-600 text-white px-6 py-2 rounded-lg font-medium transition flex items-center gap-2">
                                <i class="bi bi-save"></i>
                                <span data-lang="admin.settings.save_easyslip"><?php echo Lang::t('admin.settings.save_easyslip'); ?></span>
                            </button>
                        </form>

                        <!-- Binance USDT Settings -->
                        <div class="border-t border-yellow-500/30 my-6"></div>
                        <h6 class="mb-4 text-yellow-300 font-semibold flex items-center gap-2" data-lang="admin.settings.binance_title">
                            <i class="bi bi-currency-bitcoin text-yellow-400"></i>
                            <?php echo Lang::t('admin.settings.binance_title'); ?>
                        </h6>
                        <form method="POST" class="space-y-4">
                            <?php echo $csrfFieldHtml; ?>
                            <input type="hidden" name="action" value="update_binance">

                            <div class="flex items-center gap-3 mb-4">
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" name="binance_enabled" value="1" 
                                           <?php echo getSetting('binance_enabled') === '1' ? 'checked' : ''; ?>
                                           class="sr-only peer">
                                    <div class="w-11 h-6 bg-gray-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-yellow-500"></div>
                                </label>
                                <span class="text-gray-300" data-lang="admin.settings.binance_enabled"><?php echo Lang::t('admin.settings.binance_enabled'); ?></span>
                            </div>

                            <div>
                                <label class="text-gray-400 text-sm mb-2 block" data-lang="admin.settings.binance_wallet"><?php echo Lang::t('admin.settings.binance_wallet'); ?></label>
                                <input type="text" name="binance_wallet"
                                       class="glass border border-yellow-500/30 rounded-lg p-2 w-full bg-transparent text-white font-mono text-sm"
                                       value="<?php echo htmlspecialchars(getSetting('binance_wallet') ?? ''); ?>"
                                       placeholder="<?php echo Lang::t('admin.settings.placeholder.wallet'); ?>" data-lang-placeholder="admin.settings.placeholder.wallet">
                                <small class="text-gray-500 text-xs" data-lang="admin.settings.binance_wallet_hint"><?php echo Lang::t('admin.settings.binance_wallet_hint'); ?></small>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="text-gray-400 text-sm mb-2 block" data-lang="admin.settings.binance_api_key"><?php echo Lang::t('admin.settings.binance_api_key'); ?></label>
                                    <input type="password" name="binance_api_key"
                                           class="glass border border-yellow-500/30 rounded-lg p-2 w-full bg-transparent text-white font-mono text-sm"
                                           value="" autocomplete="new-password"
                                           placeholder="<?php echo htmlspecialchars(Lang::t('admin.settings.keep_api_key_placeholder'), ENT_QUOTES, 'UTF-8'); ?>" data-lang-placeholder="admin.settings.keep_api_key_placeholder">
                                </div>
                                <div>
                                    <label class="text-gray-400 text-sm mb-2 block" data-lang="admin.settings.binance_secret_key"><?php echo Lang::t('admin.settings.binance_secret_key'); ?></label>
                                    <input type="password" name="binance_secret_key"
                                           class="glass border border-yellow-500/30 rounded-lg p-2 w-full bg-transparent text-white font-mono text-sm"
                                           value="" autocomplete="new-password"
                                           placeholder="<?php echo htmlspecialchars(Lang::t('admin.settings.keep_secret_key_placeholder'), ENT_QUOTES, 'UTF-8'); ?>" data-lang-placeholder="admin.settings.keep_secret_key_placeholder">
                                    <small class="text-gray-500 text-xs" data-lang="admin.settings.secret_replace_hint"><?php echo Lang::t('admin.settings.secret_replace_hint'); ?></small>
                                </div>
                            </div>

                            <div class="bg-yellow-500/10 border border-yellow-500/30 rounded-lg p-3 text-sm text-yellow-300">
                                <i class="bi bi-exclamation-triangle mr-1"></i>
                                <span data-lang="admin.settings.binance_info"><?php echo Lang::t('admin.settings.binance_info'); ?></span>
                            </div>

                            <button type="submit" class="bg-yellow-500 hover:bg-yellow-600 text-white px-6 py-2 rounded-lg font-medium transition flex items-center gap-2">
                                <i class="bi bi-save"></i>
                                <span data-lang="admin.settings.save_binance"><?php echo Lang::t('admin.settings.save_binance'); ?></span>
                            </button>
                        </form>

                        <!-- Binance Gift Card Settings -->
                        <div class="border-t border-amber-400/30 my-8"></div>
                        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-4">
                            <div>
                                <h6 class="text-amber-300 font-semibold flex items-center gap-2" data-lang="admin.settings.giftcard_title">
                                    <i class="bi bi-gift-fill text-amber-400"></i>
                                    <?php echo Lang::t('admin.settings.giftcard_title'); ?>
                                </h6>
                                <p class="text-xs text-gray-500 mt-1" data-lang="admin.settings.giftcard_subtitle"><?php echo Lang::t('admin.settings.giftcard_subtitle'); ?></p>
                            </div>
                            <a href="binance_giftcards.php" class="inline-flex items-center justify-center gap-2 px-4 py-2 rounded-lg border border-amber-400/30 bg-amber-400/10 text-amber-300 hover:bg-amber-400/20 transition text-sm">
                                <i class="bi bi-clipboard-data"></i>
                                <span data-lang="admin.settings.giftcard_audit"><?php echo Lang::t('admin.settings.giftcard_audit'); ?></span>
                            </a>
                        </div>

                        <form method="POST" class="space-y-5" autocomplete="off">
                            <?php echo $csrfFieldHtml; ?>
                            <input type="hidden" name="action" value="update_binance_giftcard">

                            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                <label class="glass rounded-xl border border-amber-400/20 p-3 flex items-center gap-3 cursor-pointer">
                                    <input type="checkbox" name="binance_giftcard_enabled" value="1" class="h-4 w-4 accent-amber-400" <?php echo getSetting('binance_giftcard_enabled') === '1' ? 'checked' : ''; ?>>
                                    <span class="text-sm text-gray-200" data-lang="admin.settings.giftcard_enabled"><?php echo Lang::t('admin.settings.giftcard_enabled'); ?></span>
                                </label>
                                <label class="glass rounded-xl border border-blue-400/20 p-3 flex items-center gap-3 cursor-pointer">
                                    <input type="checkbox" name="binance_giftcard_user_enabled" value="1" class="h-4 w-4 accent-blue-400" <?php echo getSetting('binance_giftcard_user_enabled', '1') !== '0' ? 'checked' : ''; ?>>
                                    <span class="text-sm text-gray-200" data-lang="admin.settings.giftcard_users"><?php echo Lang::t('admin.settings.giftcard_users'); ?></span>
                                </label>
                                <label class="glass rounded-xl border border-green-400/20 p-3 flex items-center gap-3 cursor-pointer">
                                    <input type="checkbox" name="binance_giftcard_reseller_enabled" value="1" class="h-4 w-4 accent-green-400" <?php echo getSetting('binance_giftcard_reseller_enabled', '1') !== '0' ? 'checked' : ''; ?>>
                                    <span class="text-sm text-gray-200" data-lang="admin.settings.giftcard_resellers"><?php echo Lang::t('admin.settings.giftcard_resellers'); ?></span>
                                </label>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="text-gray-400 text-sm mb-2 block" data-lang="admin.settings.giftcard_credentials_mode"><?php echo Lang::t('admin.settings.giftcard_credentials_mode'); ?></label>
                                    <?php $gcCredentialMode = (string) (getSetting('binance_giftcard_credentials_mode') ?: 'separate'); ?>
                                    <select name="binance_giftcard_credentials_mode" class="glass border border-amber-400/30 rounded-lg p-2.5 w-full bg-[#111116] text-white">
                                        <option value="separate" <?php echo $gcCredentialMode === 'separate' ? 'selected' : ''; ?> data-lang="admin.settings.giftcard_credentials_separate"><?php echo Lang::t('admin.settings.giftcard_credentials_separate'); ?></option>
                                        <option value="shared" <?php echo $gcCredentialMode === 'shared' ? 'selected' : ''; ?> data-lang="admin.settings.giftcard_credentials_shared"><?php echo Lang::t('admin.settings.giftcard_credentials_shared'); ?></option>
                                    </select>
                                    <small class="text-gray-500 text-xs" data-lang="admin.settings.giftcard_credentials_hint"><?php echo Lang::t('admin.settings.giftcard_credentials_hint'); ?></small>
                                </div>
                                <div>
                                    <label class="text-gray-400 text-sm mb-2 block" data-lang="admin.settings.giftcard_token"><?php echo Lang::t('admin.settings.giftcard_token'); ?></label>
                                    <input type="text" value="USDT" readonly class="glass border border-amber-400/30 rounded-lg p-2.5 w-full bg-white/5 text-amber-300 font-bold">
                                    <small class="text-gray-500 text-xs" data-lang="admin.settings.giftcard_token_hint"><?php echo Lang::t('admin.settings.giftcard_token_hint'); ?></small>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="text-gray-400 text-sm mb-2 block" data-lang="admin.settings.giftcard_api_key"><?php echo Lang::t('admin.settings.giftcard_api_key'); ?></label>
                                    <input type="password" name="binance_giftcard_api_key" value="" autocomplete="new-password"
                                           class="glass border border-amber-400/30 rounded-lg p-2.5 w-full bg-transparent text-white font-mono text-sm"
                                           data-lang-placeholder="admin.settings.keep_api_key_placeholder" placeholder="<?php echo htmlspecialchars(Lang::t('admin.settings.keep_api_key_placeholder'), ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                                <div>
                                    <label class="text-gray-400 text-sm mb-2 block" data-lang="admin.settings.giftcard_secret_key"><?php echo Lang::t('admin.settings.giftcard_secret_key'); ?></label>
                                    <input type="password" name="binance_giftcard_secret_key" value="" autocomplete="new-password"
                                           class="glass border border-amber-400/30 rounded-lg p-2.5 w-full bg-transparent text-white font-mono text-sm"
                                           data-lang-placeholder="admin.settings.keep_secret_key_placeholder" placeholder="<?php echo htmlspecialchars(Lang::t('admin.settings.keep_secret_key_placeholder'), ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                            </div>
                            <div class="rounded-xl border border-red-400/20 bg-red-400/10 p-3 text-xs text-red-200 flex gap-2">
                                <i class="bi bi-shield-lock-fill mt-0.5"></i>
                                <span data-lang="admin.settings.giftcard_secret_notice"><?php echo Lang::t('admin.settings.giftcard_secret_notice'); ?></span>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                                <div>
                                    <label class="text-gray-400 text-sm mb-2 block" data-lang="admin.settings.giftcard_min"><?php echo Lang::t('admin.settings.giftcard_min'); ?></label>
                                    <input type="number" name="binance_giftcard_min_usdt" min="0.01" max="1000000" step="0.01" required
                                           value="<?php echo htmlspecialchars((string) (getSetting('binance_giftcard_min_usdt') ?: '1')); ?>"
                                           class="glass border border-white/10 rounded-lg p-2.5 w-full bg-transparent text-white">
                                </div>
                                <div>
                                    <label class="text-gray-400 text-sm mb-2 block" data-lang="admin.settings.giftcard_max"><?php echo Lang::t('admin.settings.giftcard_max'); ?></label>
                                    <input type="number" name="binance_giftcard_max_usdt" min="0.01" max="1000000" step="0.01" required
                                           value="<?php echo htmlspecialchars((string) (getSetting('binance_giftcard_max_usdt') ?: '1000')); ?>"
                                           class="glass border border-white/10 rounded-lg p-2.5 w-full bg-transparent text-white">
                                </div>
                                <div>
                                    <label class="text-gray-400 text-sm mb-2 block" data-lang="admin.settings.giftcard_daily"><?php echo Lang::t('admin.settings.giftcard_daily'); ?></label>
                                    <input type="number" name="binance_giftcard_daily_usdt" min="0.01" max="10000000" step="0.01" required
                                           value="<?php echo htmlspecialchars((string) (getSetting('binance_giftcard_daily_usdt') ?: '2000')); ?>"
                                           class="glass border border-white/10 rounded-lg p-2.5 w-full bg-transparent text-white">
                                </div>
                                <div>
                                    <label class="text-gray-400 text-sm mb-2 block" data-lang="admin.settings.giftcard_credit_percent"><?php echo Lang::t('admin.settings.giftcard_credit_percent'); ?></label>
                                    <input type="number" name="binance_giftcard_credit_percent" min="1" max="100" step="0.01" required
                                           value="<?php echo htmlspecialchars((string) (getSetting('binance_giftcard_credit_percent') ?: '100')); ?>"
                                           class="glass border border-white/10 rounded-lg p-2.5 w-full bg-transparent text-white">
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <label class="text-gray-400 text-sm mb-2 block" data-lang="admin.settings.giftcard_user_attempts"><?php echo Lang::t('admin.settings.giftcard_user_attempts'); ?></label>
                                    <input type="number" name="binance_giftcard_user_attempts_per_day" min="1" max="4" step="1" required
                                           value="<?php echo htmlspecialchars((string) (getSetting('binance_giftcard_user_attempts_per_day') ?: '2')); ?>"
                                           class="glass border border-white/10 rounded-lg p-2.5 w-full bg-transparent text-white">
                                </div>
                                <div>
                                    <label class="text-gray-400 text-sm mb-2 block" data-lang="admin.settings.giftcard_global_invalid"><?php echo Lang::t('admin.settings.giftcard_global_invalid'); ?></label>
                                    <input type="number" name="binance_giftcard_global_invalid_limit" min="1" max="4" step="1" required
                                           value="<?php echo htmlspecialchars((string) (getSetting('binance_giftcard_global_invalid_limit') ?: '4')); ?>"
                                           class="glass border border-white/10 rounded-lg p-2.5 w-full bg-transparent text-white">
                                </div>
                                <div>
                                    <label class="text-gray-400 text-sm mb-2 block" data-lang="admin.settings.giftcard_account_age"><?php echo Lang::t('admin.settings.giftcard_account_age'); ?></label>
                                    <input type="number" name="binance_giftcard_account_age_days" min="0" max="365" step="1" required
                                           value="<?php echo htmlspecialchars((string) (getSetting('binance_giftcard_account_age_days') ?: '0')); ?>"
                                           class="glass border border-white/10 rounded-lg p-2.5 w-full bg-transparent text-white">
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                <label class="glass rounded-xl border border-violet-400/20 p-3 flex items-start gap-3 cursor-pointer">
                                    <input type="checkbox" name="binance_giftcard_count_for_ranking" value="1" class="h-4 w-4 mt-0.5 accent-violet-400" <?php echo getSetting('binance_giftcard_count_for_ranking', '1') !== '0' ? 'checked' : ''; ?>>
                                    <span><span class="block text-sm text-gray-200" data-lang="admin.settings.giftcard_count_ranking"><?php echo Lang::t('admin.settings.giftcard_count_ranking'); ?></span><small class="text-gray-500" data-lang="admin.settings.giftcard_count_ranking_hint"><?php echo Lang::t('admin.settings.giftcard_count_ranking_hint'); ?></small></span>
                                </label>
                                <label class="glass rounded-xl border border-orange-400/20 p-3 flex items-start gap-3 cursor-pointer">
                                    <input type="checkbox" name="binance_giftcard_rank_bonus_enabled" value="1" class="h-4 w-4 mt-0.5 accent-orange-400" <?php echo getSetting('binance_giftcard_rank_bonus_enabled') === '1' ? 'checked' : ''; ?>>
                                    <span><span class="block text-sm text-gray-200" data-lang="admin.settings.giftcard_rank_bonus"><?php echo Lang::t('admin.settings.giftcard_rank_bonus'); ?></span><small class="text-gray-500" data-lang="admin.settings.giftcard_rank_bonus_hint"><?php echo Lang::t('admin.settings.giftcard_rank_bonus_hint'); ?></small></span>
                                </label>
                            </div>

                            <div class="rounded-xl border border-amber-400/30 bg-amber-400/10 p-4 text-sm text-amber-200">
                                <div class="font-semibold mb-1 flex items-center gap-2"><i class="bi bi-exclamation-triangle-fill"></i><span data-lang="admin.settings.giftcard_code_only_title"><?php echo Lang::t('admin.settings.giftcard_code_only_title'); ?></span></div>
                                <p class="text-xs text-amber-100/80" data-lang="admin.settings.giftcard_code_only_desc"><?php echo Lang::t('admin.settings.giftcard_code_only_desc'); ?></p>
                            </div>

                            <button type="submit" class="bg-amber-500 hover:bg-amber-600 text-black px-6 py-2.5 rounded-lg font-bold transition inline-flex items-center gap-2">
                                <i class="bi bi-save"></i>
                                <span data-lang="admin.settings.giftcard_save"><?php echo Lang::t('admin.settings.giftcard_save'); ?></span>
                            </button>
                        </form>

                        <form method="POST" class="mt-3">
                            <?php echo $csrfFieldHtml; ?>
                            <input type="hidden" name="action" value="test_binance_giftcard">
                            <button type="submit" class="border border-amber-400/30 bg-amber-400/10 hover:bg-amber-400/20 text-amber-300 px-5 py-2 rounded-lg font-medium transition inline-flex items-center gap-2">
                                <i class="bi bi-plug"></i>
                                <span data-lang="admin.settings.giftcard_test"><?php echo Lang::t('admin.settings.giftcard_test'); ?></span>
                            </button>
                        </form>
                    </div>
         
                <!-- Account Settings -->
                <div class="glass rounded-xl overflow-hidden animate-fade-in">
                    <div class="p-6 border-b border-white/10">
                        <h5 class="font-bold text-white flex items-center gap-2" data-lang="account.title">
                            <i class="bi bi-person-gear"></i>
                            <?php echo Lang::t('account.title'); ?>
                        </h5>
                    </div>
                    <div class="p-6">
                        <form method="POST" class="space-y-4">
                            <?php echo $csrfFieldHtml; ?>
                            <input type="hidden" name="action" value="update_account">

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="text-gray-400 text-sm mb-2 block" data-lang="register.username"><?php echo Lang::t('register.username'); ?></label>
                                    <input type="text" name="account_username"
                                           class="glass border border-white/10 rounded-lg p-2 w-full bg-transparent text-white"
                                           value="<?php echo htmlspecialchars($meAccount['username'] ?? ''); ?>"
                                           data-lang-placeholder="admin.settings.username_placeholder"
                                           maxlength="60" required>
                                </div>
                                <div>
                                    <label class="text-gray-400 text-sm mb-2 block" data-lang="register.email"><?php echo Lang::t('register.email'); ?></label>
                                    <input type="email" name="account_email"
                                           class="glass border border-white/10 rounded-lg p-2 w-full bg-transparent text-white"
                                           value="<?php echo htmlspecialchars($meAccount['email'] ?? ''); ?>"
                                           data-lang-placeholder="admin.settings.email_placeholder"
                                           maxlength="120" required>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="text-gray-400 text-sm mb-2 block" data-lang="admin.settings.new_password"><?php echo Lang::t('admin.settings.new_password'); ?></label>
                                    <input type="password" name="new_password"
                                           class="glass border border-white/10 rounded-lg p-2 w-full bg-transparent text-white"
                                           data-lang-placeholder="admin.settings.password_placeholder"
                                           placeholder="<?php echo Lang::t('admin.settings.placeholder.leave_blank'); ?>">
                                </div>
                                <div>
                                    <label class="text-gray-400 text-sm mb-2 block" data-lang="register.confirm_password"><?php echo Lang::t('register.confirm_password'); ?></label>
                                    <input type="password" name="confirm_password"
                                           class="glass border border-white/10 rounded-lg p-2 w-full bg-transparent text-white"
                                           data-lang-placeholder="admin.settings.confirm_password_placeholder"
                                           placeholder="<?php echo Lang::t('admin.settings.placeholder.confirm_password'); ?>">
                                </div>
                            </div>

                            <div>
                                <label class="text-gray-400 text-sm mb-2 block" data-lang="admin.settings.current_password"><?php echo Lang::t('admin.settings.current_password'); ?></label>
                                <input type="password" name="current_password"
                                       class="glass border border-white/10 rounded-lg p-2 w-full bg-transparent text-white"
                                       required>
                                <small class="text-gray-500 text-xs" data-lang="admin.settings.current_password_hint"><?php echo Lang::t('admin.settings.current_password_hint'); ?></small>
                            </div>

                            <button type="submit" class="bg-accent hover:opacity-90 text-white px-6 py-2 rounded-lg font-medium transition flex items-center gap-2">
                                <i class="bi bi-save"></i>
                                <span data-lang="account.success.update"><?php echo Lang::t('account.success.update'); ?></span>
                            </button>
                        </form>
                    </div>
                </div>

       </div>

                <!-- Announcement Settings -->
                <div class="glass rounded-xl overflow-hidden animate-fade-in">
                    <div class="p-6 border-b border-white/10">
                        <h5 class="font-bold text-white flex items-center gap-2" data-lang="admin.settings.announcement_title">
                            <i class="bi bi-megaphone text-yellow-400"></i>
                            <?php echo Lang::t('admin.settings.announcement_title'); ?>
                        </h5>
                        <p class="text-gray-400 text-sm mt-1" data-lang="admin.settings.announcement_desc"><?php echo Lang::t('admin.settings.announcement_desc'); ?></p>
                    </div>
                    <div class="p-6">
                        <form method="POST">
                            <?php echo $csrfFieldHtml; ?>
                            <input type="hidden" name="action" value="update_announcement">
                            
                            <div class="mb-6">
                                <label class="text-gray-400 text-sm mb-2 block" data-lang="admin.settings.announcement_text"><?php echo Lang::t('admin.settings.announcement_text'); ?></label>
                                <textarea name="announcement_text" rows="4" maxlength="1000"
                                          class="glass border border-white/10 rounded-lg p-3 w-full bg-transparent text-white focus:outline-none focus:border-accent"
                                          data-lang-placeholder="admin.settings.announcement_placeholder"
                                          placeholder="<?php echo Lang::t('admin.settings.announcement_placeholder'); ?>"><?php echo htmlspecialchars((string) ($announcement ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                                <div class="flex justify-between mt-2">
                                    <small class="text-gray-500 text-xs" data-lang="admin.settings.announcement_tip"><?php echo Lang::t('admin.settings.announcement_tip'); ?></small>
                                    <small class="text-gray-500 text-xs" id="charCount">0 <?php echo Lang::t('admin.settings.characters'); ?></small>
                                </div>
                            </div>

                            <div class="mb-6">
                                <label for="announcementTextColor" class="text-gray-400 text-sm mb-2 block">
                                    <?php echo $currentLang === 'en' ? 'Announcement text color' : 'สีข้อความประกาศ'; ?>
                                </label>
                                <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                                    <input type="color"
                                           id="announcementTextColor"
                                           name="announcement_text_color"
                                           value="<?php echo htmlspecialchars($announcementTextColor, ENT_QUOTES, 'UTF-8'); ?>"
                                           class="h-11 w-full sm:w-20 rounded-lg border border-white/10 bg-transparent p-1 cursor-pointer">
                                    <input type="text"
                                           id="announcementTextColorHex"
                                           value="<?php echo htmlspecialchars($announcementTextColor, ENT_QUOTES, 'UTF-8'); ?>"
                                           maxlength="7"
                                           inputmode="text"
                                           autocomplete="off"
                                           spellcheck="false"
                                           class="glass border border-white/10 rounded-lg px-3 py-2.5 w-full sm:w-32 font-mono uppercase text-white focus:outline-none focus:border-accent"
                                           aria-label="<?php echo $currentLang === 'en' ? 'Announcement color HEX value' : 'รหัสสีข้อความประกาศ'; ?>">
                                    <div class="flex flex-wrap gap-2" aria-label="<?php echo $currentLang === 'en' ? 'Color presets' : 'สีแนะนำ'; ?>">
                                        <?php foreach (['#93C5FD', '#67E8F9', '#86EFAC', '#FDE047', '#FDBA74', '#F0ABFC', '#FFFFFF'] as $presetColor): ?>
                                            <button type="button"
                                                    class="h-8 w-8 rounded-full border border-white/20 shadow-sm hover:scale-110 transition-transform"
                                                    style="background:<?php echo $presetColor; ?>"
                                                    data-announcement-color-preset="<?php echo $presetColor; ?>"
                                                    title="<?php echo $presetColor; ?>"
                                                    aria-label="<?php echo $presetColor; ?>"></button>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <p class="text-xs text-gray-500 mt-2">
                                    <?php echo $currentLang === 'en'
                                        ? 'The selected color is shared by the user, reseller and admin announcement bars.'
                                        : 'สีนี้จะใช้ร่วมกันในแถบประกาศของลูกค้า ตัวแทน และแอดมิน'; ?>
                                </p>
                            </div>
                            
                            <div class="mb-6">
                                <label class="text-gray-400 text-sm mb-2 block" data-lang="admin.settings.status"><?php echo Lang::t('admin.settings.status'); ?></label>
                                <div class="flex gap-4">
                                    <label class="flex items-center cursor-pointer">
                                        <input type="radio" name="announcement_status" value="active" <?php echo $announcementStatus === 'active' ? 'checked' : ''; ?> class="mr-2 text-accent">
                                        <span class="text-gray-300 flex items-center gap-1">
                                            <i class="bi bi-eye"></i>
                                            <span data-lang="products.status.active"><?php echo Lang::t('products.status.active'); ?></span>
                                        </span>
                                    </label>
                                    <label class="flex items-center cursor-pointer">
                                        <input type="radio" name="announcement_status" value="inactive" <?php echo $announcementStatus !== 'active' ? 'checked' : ''; ?> class="mr-2 text-accent">
                                        <span class="text-gray-300 flex items-center gap-1">
                                            <i class="bi bi-eye-slash"></i>
                                            <span data-lang="admin.settings.inactive"><?php echo Lang::t('admin.settings.inactive'); ?></span>
                                        </span>
                                    </label>
                                </div>
                            </div>
                            
                            <!-- Preview Section -->
                            <div class="mb-6 glass border border-blue-500/20 rounded-lg p-4 bg-blue-500/5">
                                <div class="flex items-center gap-2 mb-3">
                                    <i class="bi bi-eye text-blue-400"></i>
                                    <span class="text-sm font-medium text-gray-300" data-lang="common.preview"><?php echo Lang::t('common.preview'); ?></span>
                                </div>
                                <div class="marquee-container" data-announcement-marquee data-marquee-speed="52">
                                    <div class="marquee-content text-sm" data-announcement-track style="--announcement-text-color: <?php echo htmlspecialchars($announcementTextColor, ENT_QUOTES, 'UTF-8'); ?>; color: <?php echo htmlspecialchars($announcementTextColor, ENT_QUOTES, 'UTF-8'); ?>;">
                                        <i class="bi bi-megaphone-fill mr-2 text-yellow-400"></i>
                                        <span id="announcementPreview"><?php echo htmlspecialchars($announcement !== '' ? $announcement : Lang::t('admin.settings.announcement_default'), ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                </div>
                                <p class="text-xs text-gray-400 mt-2 text-center" data-lang="admin.settings.announcement_scroll_tip"><?php echo Lang::t('admin.settings.announcement_scroll_tip'); ?></p>
                            </div>
                            
                            <div class="flex gap-3">
                                <button type="submit" class="bg-accent hover:opacity-90 text-white px-6 py-2 rounded-lg font-medium transition flex items-center gap-2">
                                    <i class="bi bi-save"></i>
                                    <span data-lang="admin.settings.save_announcement"><?php echo Lang::t('admin.settings.save_announcement'); ?></span>
                                </button>
                                <button type="button" onclick="clearAnnouncement()" class="bg-gray-700 hover:bg-gray-600 text-white px-6 py-2 rounded-lg font-medium transition flex items-center gap-2">
                                    <i class="bi bi-trash"></i>
                                    <span data-lang="common.clear"><?php echo Lang::t('common.clear'); ?></span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            </div>
        </div>
    </main>

    <script src="../assets/js/announcement-marquee.js?v=<?php echo (int) (@filemtime(__DIR__ . '/../assets/js/announcement-marquee.js') ?: 1); ?>"></script>
    <script>
        // Update preview in real-time
        document.querySelector('textarea[name="announcement_text"]').addEventListener('input', function(e) {
            const preview = document.getElementById('announcementPreview');
            const text = e.target.value.trim();
            preview.textContent = text || Lang.t('admin.settings.announcement_default');
            
            // Update preview styling if empty
            if (!text) {
                preview.classList.add('text-gray-400');
            } else {
                preview.classList.remove('text-gray-400');
            }
        });

        const announcementColorPicker = document.getElementById('announcementTextColor');
        const announcementColorHex = document.getElementById('announcementTextColorHex');
        const announcementPreviewTrack = document.querySelector('[data-announcement-track]');

        function normalizeAnnouncementHex(value) {
            const normalized = String(value || '').trim().toUpperCase();
            return /^#[0-9A-F]{6}$/.test(normalized) ? normalized : '';
        }

        function applyAnnouncementColor(value, syncPicker = true) {
            const normalized = normalizeAnnouncementHex(value);
            if (!normalized) return false;
            announcementColorHex.value = normalized;
            if (syncPicker) announcementColorPicker.value = normalized;
            if (announcementPreviewTrack) {
                announcementPreviewTrack.style.setProperty('--announcement-text-color', normalized);
                announcementPreviewTrack.style.color = normalized;
            }
            return true;
        }

        announcementColorPicker.addEventListener('input', function () {
            applyAnnouncementColor(this.value, false);
        });
        announcementColorHex.addEventListener('input', function () {
            const compact = this.value.replace(/[^#0-9a-f]/gi, '').slice(0, 7).toUpperCase();
            this.value = compact.startsWith('#') ? compact : (compact ? '#' + compact : '');
            applyAnnouncementColor(this.value);
        });
        announcementColorHex.addEventListener('blur', function () {
            if (!applyAnnouncementColor(this.value)) {
                this.value = announcementColorPicker.value.toUpperCase();
            }
        });
        document.querySelectorAll('[data-announcement-color-preset]').forEach(function (button) {
            button.addEventListener('click', function () {
                applyAnnouncementColor(this.getAttribute('data-announcement-color-preset'));
            });
        });
        applyAnnouncementColor(announcementColorPicker.value, false);
        
        // Clear announcement function
        function clearAnnouncement() {
            if (confirm(Lang.t('admin.settings.announcement_clear_confirm'))) {
                document.querySelector('textarea[name="announcement_text"]').value = '';
                document.getElementById('announcementPreview').textContent = Lang.t('admin.settings.announcement_default');
                document.getElementById('announcementPreview').classList.add('text-gray-400');
                document.querySelector('input[name="announcement_status"][value="inactive"]').checked = true;
                document.querySelector('textarea[name="announcement_text"]').dispatchEvent(new Event('input'));
            }
        }
        
        // Character counter
        const textarea = document.querySelector('textarea[name="announcement_text"]');
        const charCounter = document.getElementById('charCount');
        
        function updateCharCount() {
            const length = textarea.value.length;
            charCounter.textContent = `${length} ` + Lang.t('admin.settings.characters');
            charCounter.className = `text-xs ${length > 900 ? 'text-yellow-400' : 'text-gray-500'}`;
        }
        
        textarea.addEventListener('input', updateCharCount);
        updateCharCount(); // Initial count
        
        // Add help tooltips
        document.addEventListener('DOMContentLoaded', function() {
            const tips = [
                {selector: 'textarea[name="announcement_text"]', text: Lang.t('admin.settings.help.announcement_text')},
                {selector: 'input[name="announcement_status"][value="active"]', text: Lang.t('admin.settings.help.announcement_active')},
                {selector: 'input[name="announcement_status"][value="inactive"]', text: Lang.t('admin.settings.help.announcement_inactive')}
            ];
            
            tips.forEach(tip => {
                const element = document.querySelector(tip.selector);
                if (element) {
                    element.title = tip.text;
                }
            });
        });
    </script>
</body>
</html>
