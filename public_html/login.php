<?php
require_once __DIR__ . '/includes/auth.php';

$currentLang = getAppLang();
$langAssetVersion = (int) (@filemtime(__DIR__ . '/assets/js/lang.js') ?: 1);

$storeBranding = getStoreBranding();
$storeTitle = trim((string) ($storeBranding['title_text'] ?? 'STORE')) ?: 'STORE';

$logoRelativePath = 'assets/img/logo.png';
$logoFilePath = __DIR__ . '/' . $logoRelativePath;
$logoAssetVersion = (int) (@filemtime($logoFilePath) ?: 1);
$logoUrl = './' . $logoRelativePath . '?v=' . $logoAssetVersion;

if (isLoggedIn()) {
    redirectByRole();
}

$authUi = [
    'th' => [
        'remember' => 'จดจำอุปกรณ์นี้',
        'remember_hint' => 'ใช้เฉพาะโทรศัพท์หรือเครื่องส่วนตัว ระบบจะไม่เก็บรหัสผ่านในคุกกี้',
        'duration' => 'ระยะเวลา',
        'days_7' => '7 วัน',
        'days_14' => '14 วัน',
        'days_30' => '30 วัน',
        'admin_cap' => 'บัญชีผู้ดูแลระบบจะจดจำสูงสุด 7 วัน',
        'show_password' => 'แสดงรหัสผ่าน',
        'hide_password' => 'ซ่อนรหัสผ่าน',
        'loading' => 'กำลังเข้าสู่ระบบ...',
        'rate_limited' => 'ลองเข้าสู่ระบบบ่อยเกินไป กรุณารออีก {seconds} วินาที',
        'inactive' => 'บัญชีนี้ถูกระงับหรือยังไม่พร้อมใช้งาน',
        'unavailable' => 'ระบบเข้าสู่ระบบไม่พร้อมชั่วคราว กรุณาลองใหม่',
        'remember_unavailable' => 'ไม่สามารถเปิดการจดจำอุปกรณ์ได้ในขณะนี้ กรุณาลองอีกครั้ง หรือเอาเครื่องหมายจดจำอุปกรณ์ออกเพื่อเข้าสู่ระบบแบบปกติ',
        'expired' => 'เซสชันหมดอายุ กรุณาเข้าสู่ระบบอีกครั้ง',
        'credentials_changed' => 'รหัสผ่านของบัญชีถูกเปลี่ยน กรุณาเข้าสู่ระบบใหม่',
        'logged_out' => 'ออกจากระบบเรียบร้อยแล้ว',
    ],
    'en' => [
        'remember' => 'Remember this device',
        'remember_hint' => 'Use only on your personal phone or device. No password is stored in the cookie.',
        'duration' => 'Duration',
        'days_7' => '7 days',
        'days_14' => '14 days',
        'days_30' => '30 days',
        'admin_cap' => 'Administrator accounts are remembered for a maximum of 7 days',
        'show_password' => 'Show password',
        'hide_password' => 'Hide password',
        'loading' => 'Signing in...',
        'rate_limited' => 'Too many sign-in attempts. Please wait {seconds} seconds.',
        'inactive' => 'This account is suspended or unavailable',
        'unavailable' => 'Sign-in is temporarily unavailable. Please try again.',
        'remember_unavailable' => 'Remember this device could not be enabled right now. Please try again, or turn it off to sign in normally.',
        'expired' => 'Your session expired. Please sign in again.',
        'credentials_changed' => 'The account password changed. Please sign in again.',
        'logged_out' => 'You have signed out successfully.',
    ],
];

$ui = $authUi[$currentLang] ?? $authUi['th'];

$error = '';
$success = '';
$username = '';
$rememberChecked = false;
$rememberDays = 30;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    requireCsrfToken('login.php');

    $username = isset($_POST['username']) && is_scalar($_POST['username'])
        ? trim((string) $_POST['username'])
        : '';

    $password = isset($_POST['password']) && is_string($_POST['password'])
        ? $_POST['password']
        : '';

    $rememberChecked = isset($_POST['remember_device'])
        && is_scalar($_POST['remember_device'])
        && (string) $_POST['remember_device'] === '1';

    $rememberDays = isset($_POST['remember_days']) && is_scalar($_POST['remember_days'])
        ? (int) $_POST['remember_days']
        : 30;

    if (!in_array($rememberDays, [7, 14, 30], true)) {
        $rememberDays = 30;
    }

    $result = login($username, $password, $rememberChecked ? $rememberDays : 0);

    if (!empty($result['success'])) {
        redirectByRole();
    }

    $code = (string) ($result['code'] ?? 'invalid_credentials');

    if ($code === 'rate_limited') {
        $error = str_replace(
            '{seconds}',
            (string) max(1, (int) ($result['retry_after'] ?? 300)),
            $ui['rate_limited']
        );
    } elseif ($code === 'inactive') {
        $error = $ui['inactive'];
    } elseif ($code === 'unavailable') {
        $error = $ui['unavailable'];
    } elseif ($code === 'remember_unavailable') {
        $error = $ui['remember_unavailable'];
    } else {
        $error = Lang::t('login.error');
    }
}

if (isset($_GET['error'])) {
    $err = is_scalar($_GET['error']) ? (string) $_GET['error'] : '';

    if ($err === 'banned') {
        $error = Lang::t('login.banned');
    } elseif ($err === 'expired') {
        $error = $ui['expired'];
    } elseif ($err === 'credentials_changed') {
        $error = $ui['credentials_changed'];
    } else {
        $error = Lang::t('login.error');
    }
}

if (isset($_GET['registered']) && is_scalar($_GET['registered']) && (string) $_GET['registered'] === '1') {
    $success = Lang::t('register.success.login');
}

if (isset($_GET['logged_out']) && is_scalar($_GET['logged_out']) && (string) $_GET['logged_out'] === '1') {
    $success = $ui['logged_out'];
}

if (isset($_GET['reset']) && is_scalar($_GET['reset']) && (string) $_GET['reset'] === '1') {
    $success = $currentLang === 'en'
        ? 'Your password has been changed. Please sign in with the new password.'
        : 'เปลี่ยนรหัสผ่านเรียบร้อยแล้ว กรุณาเข้าสู่ระบบด้วยรหัสผ่านใหม่';
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($currentLang, ENT_QUOTES, 'UTF-8'); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#0b0f17">
<meta name="color-scheme" content="dark">
<title data-lang="login.title"><?php echo Lang::t('login.title'); ?> - <?php echo htmlspecialchars($storeTitle, ENT_QUOTES, 'UTF-8'); ?></title>
<link rel="preload" as="image" href="<?php echo htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8'); ?>" fetchpriority="high">
<style>
:root {
    --bg: #0b0f17;
    --panel: #111827;
    --panel-soft: #0f1727;
    --input: #0b1120;
    --border: rgba(255,255,255,.09);
    --border-focus: rgba(59,130,246,.75);
    --text: #f3f4f6;
    --muted: #94a3b8;
    --muted-2: #64748b;
    --accent: #2563eb;
    --accent-2: #1d4ed8;
    --accent-light: #60a5fa;
    --success: #34d399;
    --danger: #f87171;
    --warning: #fbbf24;
}

* {
    box-sizing: border-box;
}

html, body {
    min-height: 100%;
}

body {
    margin: 0;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    background:
        radial-gradient(circle at 50% 0%, rgba(37, 99, 235, .16), transparent 32%),
        radial-gradient(circle at 85% 100%, rgba(30, 64, 175, .10), transparent 28%),
        var(--bg);
    color: var(--text);
    -webkit-font-smoothing: antialiased;
    text-rendering: optimizeLegibility;
    -webkit-tap-highlight-color: transparent;
}

body.login-page {
    min-height: 100vh;
    min-height: 100dvh;
    display: flex;
    flex-direction: column;
    padding:
        max(16px, env(safe-area-inset-top))
        max(16px, env(safe-area-inset-right))
        max(16px, env(safe-area-inset-bottom))
        max(16px, env(safe-area-inset-left));
}

.login-viewport {
    width: 100%;
    max-width: 24rem;
    margin: auto;
}

.login-card {
    position: relative;
    background: linear-gradient(180deg, rgba(17,24,39,.98), rgba(11,17,30,.98));
    border: 1px solid var(--border);
    border-radius: 18px;
    padding: 20px;
    box-shadow: 0 14px 34px rgba(0,0,0,.30);
}

.brand {
    text-align: center;
    margin-bottom: 16px;
}

.brand-logo {
    display: block;
    width: 64px;
    height: 64px;
    object-fit: contain;
    margin: 0 auto 10px;
    border-radius: 16px;
    background: rgba(255,255,255,.03);
    border: 1px solid rgba(255,255,255,.06);
}

.brand-title {
    margin: 0 0 4px;
    font-size: 1.25rem;
    line-height: 1.2;
    font-weight: 800;
    color: #f9fafb;
}

.brand-desc {
    margin: 0;
    color: var(--muted);
    font-size: .82rem;
    line-height: 1.35;
}

.message {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    padding: 10px 12px;
    border-radius: 12px;
    border: 1px solid transparent;
    font-size: .84rem;
    line-height: 1.35;
    margin-bottom: 14px;
}

.message svg {
    width: 16px;
    height: 16px;
    flex: 0 0 auto;
    margin-top: 1px;
}

.message-success {
    color: #d1fae5;
    background: rgba(16, 185, 129, .10);
    border-color: rgba(52, 211, 153, .22);
}

.message-error {
    color: #fee2e2;
    background: rgba(239, 68, 68, .10);
    border-color: rgba(248, 113, 113, .22);
}

form.login-form {
    display: grid;
    gap: 14px;
}

.field {
    display: block;
}

.label {
    display: block;
    margin-bottom: 6px;
    color: var(--muted);
    font-size: .8rem;
}

.field:focus-within .label {
    color: var(--accent-light);
}

.input-wrap {
    position: relative;
}

.input {
    width: 100%;
    min-height: 44px;
    border-radius: 12px;
    border: 1px solid rgba(255,255,255,.09);
    background: var(--input);
    color: var(--text);
    padding: 11px 12px 11px 38px;
    font-size: 16px;
    outline: none;
    transition: border-color .15s ease, box-shadow .15s ease;
}

.input::placeholder {
    color: var(--muted-2);
}

.input:focus {
    border-color: var(--border-focus);
    box-shadow: 0 0 0 3px rgba(37, 99, 235, .14);
}

.input-icon {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    width: 16px;
    height: 16px;
    color: var(--muted-2);
    pointer-events: none;
}

.field:focus-within .input-icon {
    color: var(--accent-light);
}

.input-wrap.has-toggle .input {
    padding-right: 46px;
}

.password-toggle {
    position: absolute;
    right: 7px;
    top: 50%;
    transform: translateY(-50%);
    width: 32px;
    height: 32px;
    border: 0;
    border-radius: 8px;
    background: transparent;
    color: var(--muted);
    display: grid;
    place-items: center;
    cursor: pointer;
}

.password-toggle:hover {
    background: rgba(255,255,255,.05);
    color: #fff;
}

.password-toggle svg {
    width: 17px;
    height: 17px;
}

.forgot-row {
    text-align: right;
    margin-top: -4px;
}

.link {
    color: var(--accent-light);
    text-decoration: none;
    font-size: .82rem;
}

.link:hover {
    color: #fff;
}

.remember-card {
    background: var(--panel-soft);
    border: 1px solid rgba(255,255,255,.07);
    border-radius: 14px;
    padding: 12px;
    display: grid;
    gap: 10px;
}

.remember-row {
    display: flex;
    align-items: flex-start;
    gap: 9px;
    cursor: pointer;
}

.remember-row input {
    width: 16px;
    height: 16px;
    margin-top: 2px;
    accent-color: var(--accent);
    flex: 0 0 auto;
}

.remember-title {
    display: block;
    font-size: .84rem;
    color: #e5e7eb;
    font-weight: 600;
    line-height: 1.25;
}

.remember-hint {
    display: block;
    margin-top: 3px;
    font-size: .72rem;
    line-height: 1.35;
    color: var(--muted);
}

.duration-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}

.duration-row.is-disabled {
    opacity: .5;
}

.duration-label {
    font-size: .78rem;
    color: var(--muted);
    white-space: nowrap;
}

.select {
    min-width: 110px;
    border-radius: 10px;
    border: 1px solid rgba(255,255,255,.09);
    background: var(--input);
    color: var(--text);
    padding: 8px 10px;
    font-size: 14px;
    outline: none;
}

.select:focus {
    border-color: var(--border-focus);
    box-shadow: 0 0 0 3px rgba(37, 99, 235, .14);
}

.select:disabled {
    opacity: .55;
}

.admin-note {
    margin: 0;
    font-size: .72rem;
    line-height: 1.35;
    color: rgba(251, 191, 36, .82);
}

.btn {
    position: relative;
    width: 100%;
    min-height: 46px;
    border: 0;
    border-radius: 12px;
    color: #fff;
    font-size: .95rem;
    font-weight: 700;
    background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 55%, #3b82f6 100%);
    box-shadow: 0 8px 18px rgba(29, 78, 216, .18);
    cursor: pointer;
    overflow: hidden;
    transition: filter .15s ease, transform .15s ease;
}

.btn:hover {
    filter: brightness(1.07);
}

.btn:active {
    transform: scale(.99);
}

.btn:disabled {
    opacity: .65;
    cursor: wait;
}

.btn-content {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.btn-icon {
    width: 16px;
    height: 16px;
}

.spinner {
    display: none;
    width: 14px;
    height: 14px;
    border: 2px solid rgba(255,255,255,.35);
    border-top-color: #fff;
    border-radius: 50%;
    animation: spin .75s linear infinite;
}

.btn.is-loading .btn-icon {
    display: none;
}

.btn.is-loading .spinner {
    display: inline-block;
}

.bottom-text {
    text-align: center;
    margin-top: 16px;
    color: var(--muted);
    font-size: .84rem;
}

.lang-switch {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    margin-top: 14px;
}

.lang-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 34px;
    padding: 7px 14px;
    border-radius: 10px;
    border: 1px solid transparent;
    color: var(--muted);
    text-decoration: none;
    font-size: .82rem;
}

.lang-link:hover {
    background: rgba(255,255,255,.04);
    color: #fff;
}

.lang-link.active {
    background: rgba(37, 99, 235, .18);
    border-color: rgba(96, 165, 250, .22);
    color: #dbeafe;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

@media (max-width: 480px) {
    body.login-page {
        padding:
            max(12px, env(safe-area-inset-top))
            max(12px, env(safe-area-inset-right))
            max(12px, env(safe-area-inset-bottom))
            max(12px, env(safe-area-inset-left));
    }

    .login-card {
        padding: 16px;
        border-radius: 16px;
    }

    .brand-logo {
        width: 56px;
        height: 56px;
    }
}

@media (prefers-reduced-motion: reduce) {
    *,
    *::before,
    *::after {
        animation: none !important;
        transition: none !important;
    }
}
</style>
<script defer src="assets/js/security.js?v=3.4"></script>
</head>
<body class="login-page">
<main class="login-viewport">
    <div class="login-card">
        <div class="brand">
            <img src="<?php echo htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8'); ?>"
                 alt="Logo"
                 class="brand-logo"
                 width="64"
                 height="64"
                 loading="eager"
                 decoding="async"
                 onerror="this.style.display='none'">
            <h1 class="brand-title"><?php echo htmlspecialchars($storeTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
            <p class="brand-desc" data-lang="login.desc"><?php echo Lang::t('login.desc'); ?></p>
        </div>

        <?php if ($success): ?>
        <div class="message message-success" data-login-message>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M20 6 9 17l-5-5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <span><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div class="message message-error" data-login-message>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M12 9v4m0 4h.01" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <span><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
        <?php endif; ?>

        <form method="POST" autocomplete="on" class="login-form" id="loginForm" data-no-page-loader>
            <?php echo csrfField(); ?>

            <div class="field">
                <label class="label" for="username" data-lang="login.username"><?php echo Lang::t('login.username'); ?></label>
                <div class="input-wrap">
                    <input type="text"
                           class="input"
                           id="username"
                           name="username"
                           maxlength="60"
                           autocomplete="username"
                           autocapitalize="none"
                           spellcheck="false"
                           value="<?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?>"
                           placeholder="Enter username"
                           data-lang-placeholder="login.placeholder.username"
                           required>
                    <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" stroke-linecap="round" stroke-linejoin="round"/>
                        <circle cx="12" cy="7" r="4"/>
                    </svg>
                </div>
            </div>

            <div class="field">
                <label class="label" for="password" data-lang="login.password"><?php echo Lang::t('login.password'); ?></label>
                <div class="input-wrap has-toggle">
                    <input type="password"
                           class="input"
                           id="password"
                           name="password"
                           maxlength="4096"
                           autocomplete="current-password"
                           placeholder="Enter password"
                           data-lang-placeholder="login.placeholder.password"
                           required>
                    <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <rect x="3" y="11" width="18" height="11" rx="2"/>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>

                    <button type="button"
                            id="togglePassword"
                            class="password-toggle"
                            aria-label="<?php echo htmlspecialchars($ui['show_password'], ENT_QUOTES, 'UTF-8'); ?>">
                        <svg id="eyeIcon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z" stroke-linecap="round" stroke-linejoin="round"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                        <svg id="eyeOffIcon" style="display:none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M17.94 17.94A10.94 10.94 0 0 1 12 19c-6.5 0-10-7-10-7a19.77 19.77 0 0 1 5.06-5.94M9.9 4.24A10.94 10.94 0 0 1 12 4c6.5 0 10 7 10 7a19.72 19.72 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="m1 1 22 22" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </button>
                </div>
            </div>

            <div class="forgot-row">
                <a href="forgot_password.php" class="link">
                    <?php echo $currentLang === 'en' ? 'Forgot password?' : 'ลืมรหัสผ่าน?'; ?>
                </a>
            </div>

            <div class="remember-card">
                <label class="remember-row">
                    <input type="checkbox"
                           name="remember_device"
                           id="rememberDevice"
                           value="1"
                           <?php echo $rememberChecked ? 'checked' : ''; ?>>
                    <span>
                        <span class="remember-title"><?php echo htmlspecialchars($ui['remember'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="remember-hint"><?php echo htmlspecialchars($ui['remember_hint'], ENT_QUOTES, 'UTF-8'); ?></span>
                    </span>
                </label>

                <div class="duration-row <?php echo $rememberChecked ? '' : 'is-disabled'; ?>" id="rememberDurationRow">
                    <label class="duration-label" for="rememberDays"><?php echo htmlspecialchars($ui['duration'], ENT_QUOTES, 'UTF-8'); ?></label>
                    <select class="select"
                            name="remember_days"
                            id="rememberDays"
                            <?php echo $rememberChecked ? '' : 'disabled'; ?>>
                        <option value="7" <?php echo $rememberDays === 7 ? 'selected' : ''; ?>><?php echo htmlspecialchars($ui['days_7'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <option value="14" <?php echo $rememberDays === 14 ? 'selected' : ''; ?>><?php echo htmlspecialchars($ui['days_14'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <option value="30" <?php echo $rememberDays === 30 ? 'selected' : ''; ?>><?php echo htmlspecialchars($ui['days_30'], ENT_QUOTES, 'UTF-8'); ?></option>
                    </select>
                </div>

                <p class="admin-note"><?php echo htmlspecialchars($ui['admin_cap'], ENT_QUOTES, 'UTF-8'); ?></p>
            </div>

            <button type="submit" id="loginSubmit" class="btn">
                <span class="btn-content">
                    <svg class="btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M10 17l5-5-5-5" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M15 12H3" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span id="loginSubmitLabel"
                          data-default-text="<?php echo htmlspecialchars(Lang::t('login.btn'), ENT_QUOTES, 'UTF-8'); ?>"
                          data-loading-text="<?php echo htmlspecialchars($ui['loading'], ENT_QUOTES, 'UTF-8'); ?>"
                          data-lang="login.btn"><?php echo Lang::t('login.btn'); ?></span>
                    <span class="spinner" aria-hidden="true"></span>
                </span>
            </button>
        </form>

        <div class="bottom-text">
            <span data-lang="login.no_account"><?php echo Lang::t('login.no_account'); ?></span>
            <a href="register.php" class="link" data-lang="register.btn"><?php echo Lang::t('register.btn'); ?></a>
        </div>

        <div class="lang-switch" aria-label="Language">
            <a href="toggle_lang.php?lang=th&amp;return=login.php"
               class="lang-link <?php echo $currentLang === 'th' ? 'active' : ''; ?>"
               lang="th"
               hreflang="th">ไทย</a>

            <a href="toggle_lang.php?lang=en&amp;return=login.php"
               class="lang-link <?php echo $currentLang === 'en' ? 'active' : ''; ?>"
               lang="en"
               hreflang="en">English</a>
        </div>
    </div>
</main>

<script>
window.PHP_LANG = <?php echo json_encode($currentLang); ?>;
</script>
<script src="assets/js/lang.js?v=<?php echo $langAssetVersion; ?>"></script>
<script>
if (window.Lang && typeof window.Lang.init === 'function') {
    window.Lang.init();
}
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const remember = document.getElementById('rememberDevice');
    const days = document.getElementById('rememberDays');
    const row = document.getElementById('rememberDurationRow');
    const password = document.getElementById('password');
    const togglePassword = document.getElementById('togglePassword');
    const eyeIcon = document.getElementById('eyeIcon');
    const eyeOffIcon = document.getElementById('eyeOffIcon');
    const form = document.getElementById('loginForm');
    const submit = document.getElementById('loginSubmit');
    const submitLabel = document.getElementById('loginSubmitLabel');
    const usernameInput = document.getElementById('username');

    function syncRememberState() {
        if (!remember || !days || !row) return;
        days.disabled = !remember.checked;
        row.classList.toggle('is-disabled', !remember.checked);
    }

    if (remember) {
        remember.addEventListener('change', syncRememberState);
        syncRememberState();
    }

    if (togglePassword && password) {
        togglePassword.addEventListener('click', function () {
            const showing = password.type === 'text';
            password.type = showing ? 'password' : 'text';

            if (eyeIcon && eyeOffIcon) {
                eyeIcon.style.display = showing ? 'block' : 'none';
                eyeOffIcon.style.display = showing ? 'none' : 'block';
            }

            togglePassword.setAttribute(
                'aria-label',
                showing
                    ? <?php echo json_encode($ui['show_password']); ?>
                    : <?php echo json_encode($ui['hide_password']); ?>
            );
        });
    }

    function resetSubmitState() {
        if (!form || !submit || !submitLabel) return;
        form.dataset.submitting = '0';
        submit.disabled = false;
        submit.classList.remove('is-loading');
        submit.removeAttribute('aria-busy');
        submitLabel.textContent = submitLabel.getAttribute('data-default-text') || submitLabel.textContent;
    }

    if (form && submit && submitLabel) {
        form.addEventListener('submit', function (event) {
            if (form.dataset.submitting === '1') {
                event.preventDefault();
                return;
            }

            form.dataset.submitting = '1';

            if (days && remember && remember.checked) {
                days.disabled = false;
            }

            submit.disabled = true;
            submit.classList.add('is-loading');
            submit.setAttribute('aria-busy', 'true');
            submitLabel.textContent = submitLabel.getAttribute('data-loading-text') || '';
        });

        window.addEventListener('pageshow', function (event) {
            if (event.persisted) {
                resetSubmitState();
            }
        });
    }

    if (
        usernameInput &&
        !document.querySelector('[data-login-message]') &&
        window.matchMedia &&
        window.matchMedia('(min-width: 768px) and (pointer: fine)').matches
    ) {
        window.setTimeout(function () {
            usernameInput.focus({ preventScroll: true });
        }, 80);
    }

    if (window.matchMedia && window.matchMedia('(max-width: 767px)').matches) {
        [usernameInput, password].forEach(function (input) {
            if (!input) return;

            input.addEventListener('focus', function () {
                window.setTimeout(function () {
                    input.scrollIntoView({
                        block: 'center',
                        behavior: 'smooth'
                    });
                }, 220);
            });
        });
    }
});
</script>
</body>
</html>