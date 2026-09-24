<?php
require_once '../includes/auth.php';
require_once '../includes/music_player.php';
requireAdmin();

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

global $conn;
$currentLang = function_exists('getAppLang') ? getAppLang() : 'th';
$error = '';
$success = '';

function musicAdminPostString(string $key, string $default = ''): string
{
    if (!array_key_exists($key, $_POST) || !is_scalar($_POST[$key])) return $default;
    return (string) $_POST[$key];
}

function musicAdminCleanTitle(string $title, string $fallback): string
{
    $title = trim(strip_tags($title));
    if ($title === '') $title = $fallback;
    if (function_exists('mb_substr')) return mb_substr($title, 0, 120, 'UTF-8');
    return substr($title, 0, 120);
}

function musicAdminSavePlaylist(array $playlist): bool
{
    $playlist = musicPlayerSanitizePlaylist($playlist);
    $json = json_encode($playlist, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($json) && upsertSetting('music_playlist_json', $json);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $action = musicAdminPostString('action');

    if ($action === 'save_preferences') {
        $volumeRaw = musicAdminPostString('music_default_volume', '30');
        $volume = is_numeric($volumeRaw) ? (int) $volumeRaw : -1;
        if ($volume < 0 || $volume > 100) {
            $error = $currentLang === 'en' ? 'Default volume must be between 0 and 100.' : 'ระดับเสียงเริ่มต้นต้องอยู่ระหว่าง 0 ถึง 100';
        } else {
            $writes = [
                ['music_enabled', isset($_POST['music_enabled']) ? '1' : '0'],
                ['music_loop', isset($_POST['music_loop']) ? '1' : '0'],
                ['music_admin_enabled', isset($_POST['music_admin_enabled']) ? '1' : '0'],
                ['music_reseller_enabled', isset($_POST['music_reseller_enabled']) ? '1' : '0'],
                ['music_user_enabled', isset($_POST['music_user_enabled']) ? '1' : '0'],
                ['music_default_volume', (string) $volume],
            ];
            $conn->begin_transaction();
            try {
                foreach ($writes as $write) {
                    if (!upsertSetting($write[0], $write[1])) throw new RuntimeException('music setting write failed');
                }
                $conn->commit();
                $success = $currentLang === 'en' ? 'Music settings saved.' : 'บันทึกการตั้งค่าเพลงแล้ว';
                logHistory((int) $_SESSION['user_id'], 'music_settings_updated', 'Updated storefront music preferences');
            } catch (Throwable $e) {
                $conn->rollback();
                error_log('Music settings save failed: ' . $e->getMessage());
                $error = $currentLang === 'en' ? 'Unable to save music settings.' : 'ไม่สามารถบันทึกการตั้งค่าเพลงได้';
            }
        }
    } elseif ($action === 'add_track') {
        $url = trim(musicAdminPostString('track_url'));
        $id = musicPlayerExtractYoutubeId($url);
        if ($id === null) {
            $error = $currentLang === 'en' ? 'Please enter a valid YouTube URL.' : 'กรุณาใส่ลิงก์ YouTube ที่ถูกต้อง';
        } else {
            $playlist = musicPlayerGetPlaylist();
            foreach ($playlist as $track) {
                if (($track['id'] ?? '') === $id) {
                    $error = $currentLang === 'en' ? 'This song is already in the playlist.' : 'เพลงนี้มีอยู่ในรายการแล้ว';
                    break;
                }
            }
            if ($error === '') {
                if (count($playlist) >= 50) {
                    $error = $currentLang === 'en' ? 'The playlist supports up to 50 songs.' : 'Playlist รองรับสูงสุด 50 เพลง';
                } else {
                    $title = musicAdminCleanTitle(musicAdminPostString('track_title'), 'YouTube ' . $id);
                    $playlist[] = ['id' => $id, 'title' => $title, 'url' => 'https://youtu.be/' . $id];
                    if (musicAdminSavePlaylist($playlist)) {
                        $success = $currentLang === 'en' ? 'Song added.' : 'เพิ่มเพลงแล้ว';
                        logHistory((int) $_SESSION['user_id'], 'music_track_added', 'Added YouTube track ' . $id);
                    } else {
                        $error = $currentLang === 'en' ? 'Unable to save the playlist.' : 'ไม่สามารถบันทึก Playlist ได้';
                    }
                }
            }
        }
    } elseif ($action === 'save_playlist') {
        $ids = isset($_POST['track_id']) && is_array($_POST['track_id']) ? $_POST['track_id'] : [];
        $titles = isset($_POST['track_title']) && is_array($_POST['track_title']) ? $_POST['track_title'] : [];
        $playlist = [];
        foreach ($ids as $i => $rawId) {
            if (count($playlist) >= 50) break;
            if (!is_scalar($rawId)) continue;
            $id = musicPlayerExtractYoutubeId((string) $rawId);
            if ($id === null) continue;
            $rawTitle = isset($titles[$i]) && is_scalar($titles[$i]) ? (string) $titles[$i] : '';
            $playlist[] = [
                'id' => $id,
                'title' => musicAdminCleanTitle($rawTitle, 'YouTube ' . $id),
                'url' => 'https://youtu.be/' . $id,
            ];
        }

        $command = musicAdminPostString('playlist_command', 'save');
        if (preg_match('/^(delete|up|down):(\d+)$/D', $command, $m)) {
            $index = (int) $m[2];
            if ($index >= 0 && $index < count($playlist)) {
                if ($m[1] === 'delete') {
                    array_splice($playlist, $index, 1);
                } elseif ($m[1] === 'up' && $index > 0) {
                    [$playlist[$index - 1], $playlist[$index]] = [$playlist[$index], $playlist[$index - 1]];
                } elseif ($m[1] === 'down' && $index < count($playlist) - 1) {
                    [$playlist[$index + 1], $playlist[$index]] = [$playlist[$index], $playlist[$index + 1]];
                }
            }
        }

        if (musicAdminSavePlaylist($playlist)) {
            $success = $currentLang === 'en' ? 'Playlist saved.' : 'บันทึก Playlist แล้ว';
            logHistory((int) $_SESSION['user_id'], 'music_playlist_updated', 'Updated music playlist (' . count($playlist) . ' tracks)');
        } else {
            $error = $currentLang === 'en' ? 'Unable to save the playlist.' : 'ไม่สามารถบันทึก Playlist ได้';
        }
    } else {
        $error = $currentLang === 'en' ? 'Invalid request.' : 'คำขอไม่ถูกต้อง';
    }
}

$playlist = musicPlayerGetPlaylist();
$musicEnabled = (string) getSetting('music_enabled', '1') !== '0';
$musicLoop = (string) getSetting('music_loop', '1') !== '0';
$musicAdminEnabled = (string) getSetting('music_admin_enabled', '1') !== '0';
$musicResellerEnabled = (string) getSetting('music_reseller_enabled', '1') !== '0';
$musicUserEnabled = (string) getSetting('music_user_enabled', '1') !== '0';
$musicDefaultVolume = max(0, min(100, (int) getSetting('music_default_volume', '30')));
$csrf = csrfField();
?>
<!doctype html>
<html lang="<?php echo htmlspecialchars($currentLang, ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $currentLang === 'en' ? 'Music Player' : 'เพลงพื้นหลัง'; ?> - Admin</title>
    <link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>:root{--sakazuki-accent:#6366f1;--sakazuki-accent-rgb:99 102 241;--sakazuki-accent2:#8b5cf6;--sakazuki-accent2-rgb:139 92 246;--sakazuki-glow:0 0 25px rgba(99,102,241,0.35)}.glass{background:rgba(255,255,255,.05);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);border:1px solid rgba(255,255,255,.08)}body{background:#080b12;color:#fff}.music-card{background:linear-gradient(145deg,rgba(99,102,241,.07),rgba(255,255,255,.025))}</style>
</head>
<body class="min-h-screen">
<?php include 'nav.php'; ?>

<main class="max-w-6xl mx-auto px-3 sm:px-5 py-6 sm:py-8 pb-28">
    <div class="mb-6 flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
        <div>
            <div class="text-xs uppercase tracking-[.22em] text-violet-300 mb-2">Music Player</div>
            <h1 class="text-2xl sm:text-3xl font-bold"><?php echo $currentLang === 'en' ? 'Background music' : 'เพลงพื้นหลัง'; ?></h1>
            <p class="mt-2 text-sm text-gray-400 max-w-2xl"><?php echo $currentLang === 'en' ? 'The floating button is available to Admin, Reseller and User. Tap the main button to play/pause, or expand it for previous/next and volume.' : 'ปุ่มลอยใช้ได้ทั้ง Admin, Reseller และ User แตะปุ่มหลักเพื่อเล่น/หยุด หรือกดขยายเพื่อเลื่อนเพลงและปรับเสียง'; ?></p>
        </div>
        <button type="button" onclick="window.SakazukiMusic && window.SakazukiMusic.expand()" class="inline-flex items-center justify-center gap-2 rounded-xl border border-violet-400/20 bg-violet-500/10 px-4 py-2.5 text-sm text-violet-200 hover:bg-violet-500/20 transition">
            <i class="bi bi-music-note-beamed"></i><?php echo $currentLang === 'en' ? 'Open floating player' : 'เปิดเครื่องเล่นลอย'; ?>
        </button>
    </div>

    <?php if ($error !== ''): ?><div class="mb-5 rounded-xl border border-red-400/20 bg-red-500/10 px-4 py-3 text-sm text-red-200"><i class="bi bi-exclamation-triangle mr-2"></i><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <?php if ($success !== ''): ?><div class="mb-5 rounded-xl border border-emerald-400/20 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-200"><i class="bi bi-check-circle mr-2"></i><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

    <div class="grid lg:grid-cols-[.9fr_1.4fr] gap-5">
        <section class="glass rounded-2xl p-5 sm:p-6 h-fit">
            <div class="flex items-center gap-3 mb-5"><div class="w-10 h-10 rounded-xl bg-violet-500/10 grid place-items-center text-violet-300"><i class="bi bi-sliders"></i></div><div><h2 class="font-bold"><?php echo $currentLang === 'en' ? 'Player settings' : 'ตั้งค่าเครื่องเล่น'; ?></h2><p class="text-xs text-gray-500"><?php echo $currentLang === 'en' ? 'Applies site-wide.' : 'มีผลทั้งเว็บไซต์'; ?></p></div></div>
            <form method="post" class="space-y-4">
                <?php echo $csrf; ?>
                <input type="hidden" name="action" value="save_preferences">
                <label class="flex items-center justify-between gap-4 rounded-xl bg-white/5 border border-white/10 p-3"><span><span class="block text-sm font-medium"><?php echo $currentLang === 'en' ? 'Enable music system' : 'เปิดระบบเพลง'; ?></span><span class="text-xs text-gray-500"><?php echo $currentLang === 'en' ? 'Master switch for the whole site' : 'สวิตช์หลักของทั้งเว็บไซต์'; ?></span></span><input type="checkbox" name="music_enabled" value="1" class="w-5 h-5 accent-violet-500" <?php echo $musicEnabled ? 'checked' : ''; ?>></label>
                <label class="flex items-center justify-between gap-4 rounded-xl bg-white/5 border border-white/10 p-3"><span class="text-sm"><?php echo $currentLang === 'en' ? 'Admin' : 'แอดมิน'; ?></span><input type="checkbox" name="music_admin_enabled" value="1" class="w-5 h-5 accent-violet-500" <?php echo $musicAdminEnabled ? 'checked' : ''; ?>></label>
                <label class="flex items-center justify-between gap-4 rounded-xl bg-white/5 border border-white/10 p-3"><span class="text-sm">Reseller</span><input type="checkbox" name="music_reseller_enabled" value="1" class="w-5 h-5 accent-violet-500" <?php echo $musicResellerEnabled ? 'checked' : ''; ?>></label>
                <label class="flex items-center justify-between gap-4 rounded-xl bg-white/5 border border-white/10 p-3"><span class="text-sm">User</span><input type="checkbox" name="music_user_enabled" value="1" class="w-5 h-5 accent-violet-500" <?php echo $musicUserEnabled ? 'checked' : ''; ?>></label>
                <label class="flex items-center justify-between gap-4 rounded-xl bg-white/5 border border-white/10 p-3"><span><span class="block text-sm"><?php echo $currentLang === 'en' ? 'Loop playlist' : 'วน Playlist'; ?></span><span class="text-xs text-gray-500"><?php echo $currentLang === 'en' ? 'After the last song, return to the first.' : 'เพลงสุดท้ายจบแล้วกลับไปเพลงแรก'; ?></span></span><input type="checkbox" name="music_loop" value="1" class="w-5 h-5 accent-violet-500" <?php echo $musicLoop ? 'checked' : ''; ?>></label>
                <div class="rounded-xl bg-white/5 border border-white/10 p-3">
                    <div class="flex items-center justify-between gap-3 mb-2"><label for="music_default_volume" class="text-sm"><?php echo $currentLang === 'en' ? 'Default volume' : 'ระดับเสียงเริ่มต้น'; ?></label><span id="music-volume-preview" class="text-xs text-violet-300"><?php echo $musicDefaultVolume; ?>%</span></div>
                    <input id="music_default_volume" type="range" min="0" max="100" step="1" name="music_default_volume" value="<?php echo $musicDefaultVolume; ?>" class="w-full accent-violet-500">
                    <p class="mt-2 text-xs text-gray-500"><?php echo $currentLang === 'en' ? 'Each browser remembers the user\'s own volume after they change it.' : 'หลังผู้ใช้ปรับเสียง เบราว์เซอร์นั้นจะจำระดับเสียงของตัวเอง'; ?></p>
                </div>
                <button type="submit" class="w-full rounded-xl bg-violet-600 hover:bg-violet-500 px-4 py-2.5 font-semibold transition"><i class="bi bi-save mr-2"></i><?php echo $currentLang === 'en' ? 'Save settings' : 'บันทึกการตั้งค่า'; ?></button>
            </form>
        </section>

        <div class="space-y-5">
            <section class="glass rounded-2xl p-5 sm:p-6">
                <div class="flex items-center gap-3 mb-5"><div class="w-10 h-10 rounded-xl bg-cyan-500/10 grid place-items-center text-cyan-300"><i class="bi bi-plus-lg"></i></div><div><h2 class="font-bold"><?php echo $currentLang === 'en' ? 'Add YouTube song' : 'เพิ่มเพลง YouTube'; ?></h2><p class="text-xs text-gray-500"><?php echo $currentLang === 'en' ? 'Supports youtu.be, watch, shorts and embed URLs.' : 'รองรับ youtu.be, watch, shorts และ embed'; ?></p></div></div>
                <form method="post" class="grid sm:grid-cols-[1fr_.7fr_auto] gap-3 items-end">
                    <?php echo $csrf; ?>
                    <input type="hidden" name="action" value="add_track">
                    <div><label class="text-xs text-gray-400 block mb-1.5">YouTube URL</label><input type="url" name="track_url" required placeholder="https://youtu.be/..." class="w-full rounded-xl border border-white/10 bg-black/20 px-3 py-2.5 text-sm text-white outline-none focus:border-violet-400/40"></div>
                    <div><label class="text-xs text-gray-400 block mb-1.5"><?php echo $currentLang === 'en' ? 'Title (optional)' : 'ชื่อเพลง (ไม่ใส่ก็ได้)'; ?></label><input type="text" name="track_title" maxlength="120" placeholder="Song name" class="w-full rounded-xl border border-white/10 bg-black/20 px-3 py-2.5 text-sm text-white outline-none focus:border-violet-400/40"></div>
                    <button type="submit" class="rounded-xl bg-cyan-600 hover:bg-cyan-500 px-4 py-2.5 font-semibold whitespace-nowrap transition"><i class="bi bi-plus-lg mr-1"></i><?php echo $currentLang === 'en' ? 'Add' : 'เพิ่ม'; ?></button>
                </form>
            </section>

            <section class="glass rounded-2xl p-5 sm:p-6">
                <div class="flex items-center justify-between gap-3 mb-5"><div><h2 class="font-bold"><?php echo $currentLang === 'en' ? 'Playlist' : 'รายการเพลง'; ?> <span class="text-violet-300">(<?php echo count($playlist); ?>)</span></h2><p class="text-xs text-gray-500 mt-1"><?php echo $currentLang === 'en' ? 'Reorder, rename, preview or delete songs.' : 'เรียงลำดับ เปลี่ยนชื่อ ทดลองฟัง หรือลบเพลง'; ?></p></div></div>
                <?php if (!$playlist): ?>
                    <div class="rounded-xl border border-dashed border-white/10 p-8 text-center text-sm text-gray-500"><i class="bi bi-music-note-list text-3xl block mb-3"></i><?php echo $currentLang === 'en' ? 'No songs yet.' : 'ยังไม่มีเพลงใน Playlist'; ?></div>
                <?php else: ?>
                <form method="post" id="playlist-form" class="space-y-3">
                    <?php echo $csrf; ?>
                    <input type="hidden" name="action" value="save_playlist">
                    <input type="hidden" name="playlist_command" id="playlist-command" value="save">
                    <?php foreach ($playlist as $index => $track): ?>
                    <article class="music-card rounded-xl border border-white/10 p-3 sm:p-4">
                        <input type="hidden" name="track_id[]" value="<?php echo htmlspecialchars($track['id'], ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="flex gap-3">
                            <div class="shrink-0 w-9 h-9 rounded-lg bg-violet-500/10 text-violet-300 grid place-items-center text-xs font-bold"><?php echo $index + 1; ?></div>
                            <div class="min-w-0 flex-1">
                                <input type="text" name="track_title[]" maxlength="120" value="<?php echo htmlspecialchars($track['title'], ENT_QUOTES, 'UTF-8'); ?>" class="w-full rounded-lg border border-white/10 bg-black/20 px-3 py-2 text-sm text-white outline-none focus:border-violet-400/40">
                                <div class="mt-2 flex flex-wrap items-center gap-2 text-[11px] text-gray-500"><span class="truncate max-w-[210px]">youtu.be/<?php echo htmlspecialchars($track['id'], ENT_QUOTES, 'UTF-8'); ?></span><button type="button" data-preview-track="<?php echo $index; ?>" class="text-cyan-300 hover:text-cyan-200"><i class="bi bi-play-circle mr-1"></i><?php echo $currentLang === 'en' ? 'Preview' : 'ทดลองฟัง'; ?></button></div>
                            </div>
                            <div class="shrink-0 flex flex-col sm:flex-row gap-1.5">
                                <button type="button" data-playlist-command="up:<?php echo $index; ?>" class="w-9 h-9 rounded-lg border border-white/10 bg-white/5 hover:bg-white/10" title="ขึ้น"><i class="bi bi-arrow-up"></i></button>
                                <button type="button" data-playlist-command="down:<?php echo $index; ?>" class="w-9 h-9 rounded-lg border border-white/10 bg-white/5 hover:bg-white/10" title="ลง"><i class="bi bi-arrow-down"></i></button>
                                <button type="button" data-playlist-command="delete:<?php echo $index; ?>" data-delete-track class="w-9 h-9 rounded-lg border border-red-400/20 bg-red-500/10 text-red-300 hover:bg-red-500/20" title="ลบ"><i class="bi bi-trash3"></i></button>
                            </div>
                        </div>
                    </article>
                    <?php endforeach; ?>
                    <button type="submit" class="w-full rounded-xl border border-violet-400/20 bg-violet-500/10 hover:bg-violet-500/20 px-4 py-2.5 font-semibold text-violet-200 transition"><i class="bi bi-save mr-2"></i><?php echo $currentLang === 'en' ? 'Save playlist names' : 'บันทึกชื่อเพลงใน Playlist'; ?></button>
                </form>
                <?php endif; ?>
            </section>

            <div class="rounded-2xl border border-amber-400/15 bg-amber-500/5 p-4 text-xs leading-6 text-amber-100/80"><i class="bi bi-info-circle mr-2 text-amber-300"></i><?php echo $currentLang === 'en' ? 'Some YouTube videos may be private, removed, region-restricted or disallow embedding. The player will skip a failed song instead of breaking the website.' : 'บางวิดีโออาจเป็น Private, ถูกลบ, จำกัดประเทศ หรือไม่อนุญาต Embed ตัว Player จะข้ามเพลงที่เล่นไม่ได้แทนที่จะทำให้หน้าเว็บมีปัญหา'; ?></div>
        </div>
    </div>
</main>

<script>
(function(){
  var volume = document.getElementById('music_default_volume');
  var label = document.getElementById('music-volume-preview');
  if (volume && label) volume.addEventListener('input', function(){ label.textContent = volume.value + '%'; });

  var form = document.getElementById('playlist-form');
  var command = document.getElementById('playlist-command');
  if (form && command) {
    form.querySelectorAll('[data-playlist-command]').forEach(function(button){
      button.addEventListener('click', function(){
        var value = button.getAttribute('data-playlist-command') || 'save';
        if (button.hasAttribute('data-delete-track') && !window.confirm(<?php echo json_encode($currentLang === 'en' ? 'Delete this song from the playlist?' : 'ลบเพลงนี้ออกจาก Playlist หรือไม่?', JSON_UNESCAPED_UNICODE); ?>)) return;
        command.value = value;
        form.submit();
      });
    });
  }

  document.querySelectorAll('[data-preview-track]').forEach(function(button){
    button.addEventListener('click', function(){
      var index = parseInt(button.getAttribute('data-preview-track'), 10);
      if (window.SakazukiMusic && typeof window.SakazukiMusic.playIndex === 'function') {
        window.SakazukiMusic.playIndex(index);
      }
    });
  });
})();
</script>
</body>
</html>
