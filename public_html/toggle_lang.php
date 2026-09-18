<?php
require_once __DIR__ . '/includes/auth.php';

$lang = isset($_GET['lang']) && is_string($_GET['lang']) ? $_GET['lang'] : 'th';
setAppLang($lang === 'en' ? 'en' : 'th');

/** Accept only a local path. Never redirect to a scheme, host, or protocol-relative URL. */
function languageLocalReturnPath($value): string
{
    if (!is_scalar($value)) return '';
    $value = str_replace(["\r", "\n", "\0", '\\'], '', trim((string) $value));
    if ($value === '' || strlen($value) > 1024) return '';
    if (strpos($value, '//') === 0) return '';
    $parts = parse_url($value);
    if (!is_array($parts) || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
        return '';
    }
    $path = (string) ($parts['path'] ?? '');
    if ($path === '') return '';
    if ($path[0] !== '/') $path = '/' . ltrim($path, '/');
    if (strpos($path, '/../') !== false || substr($path, -3) === '/..') return '';
    $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
    return $path . $query;
}

$fallback = isLoggedIn() ? '/index.php' : '/login.php';
$redirectTo = languageLocalReturnPath($_GET['return'] ?? '');

if ($redirectTo === '') {
    $referer = str_replace(["\r", "\n", "\0"], '', (string) ($_SERVER['HTTP_REFERER'] ?? ''));
    if ($referer !== '') {
        $ref = parse_url($referer);
        if (is_array($ref)) {
            $candidate = (string) ($ref['path'] ?? '');
            if (isset($ref['query']) && $ref['query'] !== '') $candidate .= '?' . $ref['query'];
            $redirectTo = languageLocalReturnPath($candidate);
        }
    }
}

header('Location: ' . ($redirectTo !== '' ? $redirectTo : $fallback), true, 302);
exit();
