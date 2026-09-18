<?php
/**
 * Persistent application shell.
 *
 * The authenticated site continues to use ordinary full-page PHP navigation
 * inside a same-origin iframe. Only the music player lives in this parent
 * document, so YouTube playback is not destroyed when the child page changes.
 */
require_once __DIR__ . '/includes/auth.php';
requireLogin();
$role = isAdmin() ? 'admin' : (isReseller() ? 'reseller' : 'user');
$defaultRoute = $role === 'admin'
    ? 'admin/dashboard.php'
    : ($role === 'reseller' ? 'reseller/buy.php' : 'user/buy.php');

/**
 * Accept only a PHP page directly inside one of the authenticated application
 * role directories. The target page still performs its existing authorization
 * check, preserving legitimate cross-role pages without granting new access.
 * Query strings/fragments are preserved; schemes/hosts/traversal are rejected.
 */
function sakazukiShellNormalizeRoute($value, string $fallback): string
{
    if (!is_scalar($value)) return $fallback;
    $value = trim(str_replace(["\r", "\n", "\0", '\\'], '', (string) $value));
    if ($value === '' || strlen($value) > 3072) return $fallback;
    if (strpos($value, '//') === 0) return $fallback;

    $parts = parse_url($value);
    if (!is_array($parts)
        || isset($parts['scheme'])
        || isset($parts['host'])
        || isset($parts['user'])
        || isset($parts['pass'])) {
        return $fallback;
    }

    $path = ltrim((string) ($parts['path'] ?? ''), '/');
    if ($path === ''
        || strpos($path, '..') !== false
        || strpos($path, '%') !== false
        || strpos($path, '//') !== false
        || preg_match('#^(?:user|reseller|admin)/[A-Za-z0-9_-]+\.php$#D', $path) !== 1) {
        return $fallback;
    }

    $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
    $fragment = isset($parts['fragment']) && $parts['fragment'] !== '' ? '#' . $parts['fragment'] : '';
    return $path . $query . $fragment;
}

$frameRoute = sakazukiShellNormalizeRoute($_GET['path'] ?? '', $defaultRoute);
$frameSrc = '/' . $frameRoute;
$currentLang = function_exists('getAppLang') ? getAppLang() : 'th';
$shellTitle = 'STORE';
try {
    if (function_exists('getStoreBranding')) {
        $shellBranding = getStoreBranding();
        if (is_array($shellBranding)) {
            $candidateTitle = trim((string) ($shellBranding['title_text'] ?? ''));
            if ($candidateTitle !== '') $shellTitle = $candidateTitle;
        }
    }
} catch (Throwable $e) {
    error_log('[APP_SHELL] branding failed: ' . $e->getMessage());
}

// The shell itself must never take the authenticated site down merely because
// the optional music UI cannot be rendered. Keep the site usable and log the
// exact server-side error for diagnosis.
$musicHtml = '';
try {
    require_once __DIR__ . '/includes/music_player.php';
    if (function_exists('renderMusicPlayer')) {
        $musicHtml = renderMusicPlayer($role, './');
    }
} catch (Throwable $e) {
    error_log('[APP_SHELL] music render failed: ' . $e->getMessage());
}

// The shell only needs the role after authentication. Releasing the session
// lock before the child iframe starts avoids needless blocking on some hosts.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($currentLang, ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#0b101e">
    <meta name="color-scheme" content="dark">
    <title><?php echo htmlspecialchars($shellTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        html,body{width:100%;height:100%;margin:0;overflow:hidden;background:#0b101e;color:#f3f4f6}
        #sakazuki-app-frame{position:fixed;inset:0;width:100%;height:100%;border:0;background:#0b101e;display:block}
        .shell-fallback{position:fixed;inset:0;display:grid;place-items:center;padding:24px;background:#0b101e;color:#e5e7eb;font:500 14px/1.6 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;z-index:1}
        .shell-fallback a{color:#60a5fa}
    </style>
</head>
<body>
    <noscript>
        <div class="shell-fallback">
            <div>JavaScript is required for the persistent player. <a href="<?php echo htmlspecialchars($frameSrc, ENT_QUOTES, 'UTF-8'); ?>">Open the page normally</a>.</div>
        </div>
    </noscript>

    <iframe
        id="sakazuki-app-frame"
        name="sakazuki-app-frame"
        src="<?php echo htmlspecialchars($frameSrc, ENT_QUOTES, 'UTF-8'); ?>"
        title="Sakazuki"
        referrerpolicy="strict-origin-when-cross-origin"
        allow="autoplay; clipboard-read; clipboard-write"
    ></iframe>

    <script>
    window.SAKAZUKI_APP_SHELL_CONFIG = <?php echo json_encode([
        'role' => $role,
        'defaultRoute' => $defaultRoute,
        'initialRoute' => $frameRoute,
        'shellPath' => '/app.php',
    ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    </script>
    <script src="/assets/js/app-shell.js?v=<?php echo (int) (@filemtime(__DIR__ . '/assets/js/app-shell.js') ?: 1); ?>"></script>

    <?php echo $musicHtml; ?>
</body>
</html>
