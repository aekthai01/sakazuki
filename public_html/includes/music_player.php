<?php
/**
 * Sakazuki floating YouTube music player helpers.
 *
 * Design goals:
 * - Never touch checkout/order/wallet code paths.
 * - Store only validated YouTube video IDs + display titles in settings.
 * - Keep playback state in the browser so page navigation can resume near
 *   the previous position without writing per-user playback data to MySQL.
 */

if (!function_exists('musicPlayerExtractYoutubeId')) {
    function musicPlayerExtractYoutubeId(string $input): ?string
    {
        $input = trim($input);
        if ($input === '') return null;

        if (preg_match('/^[A-Za-z0-9_-]{11}$/D', $input) === 1) {
            return $input;
        }

        $candidateUrl = $input;
        if (!preg_match('#^https?://#i', $candidateUrl)) {
            $candidateUrl = 'https://' . ltrim($candidateUrl, '/');
        }

        $parts = @parse_url($candidateUrl);
        if (!is_array($parts)) return null;

        $host = strtolower((string) ($parts['host'] ?? ''));
        $host = preg_replace('/^www\./', '', $host);
        if ($host === 'm.youtube.com') $host = 'youtube.com';

        $id = null;
        if ($host === 'youtu.be') {
            $path = trim((string) ($parts['path'] ?? ''), '/');
            $id = explode('/', $path, 2)[0] ?? null;
        } elseif ($host === 'youtube.com' || (strlen($host) > 12 && substr($host, -12) === '.youtube.com')) {
            $path = trim((string) ($parts['path'] ?? ''), '/');
            parse_str((string) ($parts['query'] ?? ''), $query);

            if ($path === 'watch') {
                $id = isset($query['v']) && is_scalar($query['v']) ? (string) $query['v'] : null;
            } elseif (preg_match('#^(?:embed|shorts|live)/([A-Za-z0-9_-]{11})(?:/|$)#', $path, $m)) {
                $id = $m[1];
            }
        }

        $id = trim((string) $id);
        return preg_match('/^[A-Za-z0-9_-]{11}$/D', $id) === 1 ? $id : null;
    }
}

if (!function_exists('musicPlayerDefaultPlaylist')) {
    function musicPlayerDefaultPlaylist(): array
    {
        return [[
            'id' => 'VY0GY4jxCj8',
            'title' => 'Collide - (Speed Up)',
            'url' => 'https://youtu.be/VY0GY4jxCj8',
        ]];
    }
}

if (!function_exists('musicPlayerSanitizePlaylist')) {
    function musicPlayerSanitizePlaylist($raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) return [];

        $result = [];
        foreach ($raw as $item) {
            if (count($result) >= 50) break;
            if (!is_array($item)) continue;

            $source = trim((string) ($item['url'] ?? $item['id'] ?? ''));
            $id = musicPlayerExtractYoutubeId((string) ($item['id'] ?? $source));
            if ($id === null && $source !== '') {
                $id = musicPlayerExtractYoutubeId($source);
            }
            if ($id === null) continue;

            $title = trim((string) ($item['title'] ?? ''));
            if ($title === '') $title = 'YouTube ' . $id;
            if (function_exists('mb_substr')) {
                $title = mb_substr($title, 0, 120, 'UTF-8');
            } else {
                $title = substr($title, 0, 120);
            }

            $result[] = [
                'id' => $id,
                'title' => $title,
                'url' => 'https://youtu.be/' . $id,
            ];
        }
        return $result;
    }
}

if (!function_exists('musicPlayerGetPlaylist')) {
    function musicPlayerGetPlaylist(): array
    {
        $stored = (string) getSetting('music_playlist_json', '');
        if ($stored === '') return musicPlayerDefaultPlaylist();
        return musicPlayerSanitizePlaylist($stored);
    }
}

if (!function_exists('musicPlayerGetConfig')) {
    function musicPlayerGetConfig(string $role = 'user'): array
    {
        $role = in_array($role, ['admin', 'reseller', 'user'], true) ? $role : 'user';
        $volume = (int) getSetting('music_default_volume', '30');
        $volume = max(0, min(100, $volume));

        $roleEnabled = (string) getSetting('music_' . $role . '_enabled', '1') !== '0';
        return [
            'enabled' => (string) getSetting('music_enabled', '1') !== '0' && $roleEnabled,
            'role' => $role,
            'loop' => (string) getSetting('music_loop', '1') !== '0',
            'defaultVolume' => $volume,
            'playlist' => musicPlayerGetPlaylist(),
        ];
    }
}

if (!function_exists('musicPlayerEncodeConfig')) {
    function musicPlayerEncodeConfig(array $config): string
    {
        return (string) json_encode(
            $config,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
    }
}

if (!function_exists('renderMusicPlayer')) {
    function renderMusicPlayer(string $role = 'user', string $assetPrefix = '../'): string
    {
        $config = musicPlayerGetConfig($role);
        if (empty($config['enabled']) || empty($config['playlist'])) return '';

        $cssVersion = (int) (@filemtime(__DIR__ . '/../assets/css/music-player.css') ?: 1);
        $jsVersion = (int) (@filemtime(__DIR__ . '/../assets/js/music-player.js') ?: 1);
        $assetPrefix = rtrim($assetPrefix, '/') . '/';
        $configJson = musicPlayerEncodeConfig($config);

        ob_start();
        ?>
<link rel="preconnect" href="https://www.youtube.com">
<link rel="dns-prefetch" href="//www.youtube.com">
<link rel="preconnect" href="https://i.ytimg.com">
<link rel="dns-prefetch" href="//i.ytimg.com">
<link rel="stylesheet" href="<?php echo htmlspecialchars($assetPrefix . 'assets/css/music-player.css?v=' . $cssVersion, ENT_QUOTES, 'UTF-8'); ?>">
<div id="sakazuki-music-player" class="sz-music" data-role="<?php echo htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?>">
    <div class="sz-music-panel" data-music-panel aria-hidden="true">
        <div class="sz-music-panel-head">
            <div class="sz-music-heading-wrap">
                <span class="sz-music-eyebrow">MUSIC</span>
                <div class="sz-music-title" data-music-title>Music</div>
            </div>
            <button type="button" class="sz-music-icon-btn" data-music-collapse aria-label="ย่อเครื่องเล่น" title="ย่อเครื่องเล่น">
                <i class="bi bi-chevron-right"></i>
            </button>
        </div>

        <div class="sz-music-video-wrap" data-music-video-wrap>
            <div id="sakazuki-youtube-player" class="sz-music-video"></div>
            <div class="sz-music-video-placeholder" data-music-placeholder>
                <i class="bi bi-music-note-beamed"></i>
                <span>พร้อมเล่นเพลง</span>
            </div>
        </div>

        <div class="sz-music-status" data-music-status>แตะปุ่มเล่นเพื่อเริ่มเพลง</div>

        <div class="sz-music-controls" aria-label="ควบคุมเพลง">
            <button type="button" class="sz-music-control-btn" data-music-prev aria-label="เพลงก่อนหน้า" title="เพลงก่อนหน้า">
                <i class="bi bi-skip-backward-fill"></i>
            </button>
            <button type="button" class="sz-music-control-btn sz-music-control-primary" data-music-play aria-label="เล่นเพลง" title="เล่น/หยุด">
                <i class="bi bi-play-fill" data-music-play-icon></i>
            </button>
            <button type="button" class="sz-music-control-btn" data-music-next aria-label="เพลงถัดไป" title="เพลงถัดไป">
                <i class="bi bi-skip-forward-fill"></i>
            </button>
        </div>

        <div class="sz-music-volume-row">
            <i class="bi bi-volume-down-fill" aria-hidden="true"></i>
            <input type="range" min="0" max="100" step="1" data-music-volume aria-label="ระดับเสียง">
            <span data-music-volume-label>30%</span>
        </div>
    </div>

    <div class="sz-music-fab-wrap">
        <button type="button" class="sz-music-fab sz-music-fab-main" data-music-main aria-label="เล่นเพลง" title="เล่น/หยุดเพลง">
            <i class="bi bi-music-note-beamed" data-music-fab-icon></i>
            <span class="sz-music-pulse" data-music-pulse aria-hidden="true"></span>
        </button>
        <button type="button" class="sz-music-fab sz-music-fab-expand" data-music-expand aria-label="เปิดเครื่องเล่นเพลง" title="เปิดเครื่องเล่นเพลง">
            <i class="bi bi-chevron-left"></i>
        </button>
    </div>
</div>
<script>
window.SAKAZUKI_MUSIC_CONFIG = <?php echo $configJson; ?>;
</script>
<script src="<?php echo htmlspecialchars($assetPrefix . 'assets/js/music-player.js?v=' . $jsVersion, ENT_QUOTES, 'UTF-8'); ?>"></script>
        <?php
        return (string) ob_get_clean();
    }
}
