<?php
// RESELLER NAVBAR
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    require_once __DIR__ . '/../includes/auth.php';
    requireReseller();
}
// NAV_BALANCE_BADGE
$__navBalance = 0;
if (isset($_SESSION['user_id'])) {
    if (function_exists('getUserBalance')) {
        $__navBalance = getUserBalance((int)$_SESSION['user_id']);
    } elseif (isset($_SESSION['balance'])) {
        $__navBalance = $_SESSION['balance'];
    }
}
$__navBalanceFmt = number_format((float)$__navBalance, 2, '.', '');
$__resellerApiMode = function_exists('getSetting') ? strtolower(trim((string) getSetting('store_reseller_api_program_mode', 'off'))) : 'off';
if (!in_array($__resellerApiMode, ['off', 'pilot', 'all'], true)) $__resellerApiMode = 'off';
$__resellerApiMenuVisible = function_exists('getSetting') && getSetting('store_reseller_api_menu_visible', '0') === '1';
$__resellerApiUserId = (int) ($_SESSION['user_id'] ?? 0);
$__resellerApiAllowed = $__resellerApiMode === 'all';
if ($__resellerApiMode === 'pilot' && $__resellerApiUserId > 0 && function_exists('getSetting')) {
    $__pilotRaw = (string) getSetting('store_reseller_api_pilot_user_ids', '');
    foreach (preg_split('/[\s,;]+/', $__pilotRaw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $__pilotPiece) {
        if (ctype_digit($__pilotPiece) && (int) $__pilotPiece === $__resellerApiUserId) { $__resellerApiAllowed = true; break; }
    }
}
$__showResellerApiMenu = $__resellerApiMenuVisible && $__resellerApiAllowed;
$resellerTitleIconAsset = function_exists('getTitleIconAsset') ? getTitleIconAsset('reseller') : ['path' => '', 'version' => 0];
$resellerTitleIconPath = (string) ($resellerTitleIconAsset['path'] ?? '');
$resellerTitleIconUrl = $resellerTitleIconPath !== ''
    ? '../' . ltrim($resellerTitleIconPath, '/') . ((int) ($resellerTitleIconAsset['version'] ?? 0) > 0 ? '?v=' . (int) $resellerTitleIconAsset['version'] : '')
    : '';
$currentLang = function_exists('getAppLang') ? getAppLang() : 'th';
$langAssetVersion = (int) (@filemtime(__DIR__ . '/../assets/js/lang.js') ?: 1);
$shellBridgeVersion = (int) (@filemtime(__DIR__ . '/../assets/js/shell-bridge.js') ?: 1);
$navAssetVersion = (int) (@filemtime(__DIR__ . '/../assets/js/nav.js') ?: 1);
$securityAssetVersion = (int) (@filemtime(__DIR__ . '/../assets/js/security.js') ?: 1);
$shellBridgeEligible = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET');
?>
<script>window.SAKAZUKI_SHELL_BRIDGE={eligible:<?php echo $shellBridgeEligible ? 'true' : 'false'; ?>};</script>
<script src="../assets/js/shell-bridge.js?v=<?php echo $shellBridgeVersion; ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
.drawer-overlay{position:fixed;inset:0;background:rgba(0,0,0,.65);backdrop-filter:blur(2px);z-index:60;display:none;}
.drawer{position:fixed;top:0;left:0;height:100vh;height:100dvh;width:18rem;max-width:85vw;transform:translateX(-100%);transition:transform .15s ease;z-index:61;padding-bottom:env(safe-area-inset-bottom,0);}
.drawer.open{transform:translateX(0);}
.drawer-overlay.open{display:block;}
#site-loader{position:fixed;inset:0;background:rgba(15,15,19,0.7);backdrop-filter:blur(10px);z-index:9999;display:flex;flex-direction:column;align-items:center;justify-content:center;transition:opacity .15s cubic-bezier(0.4,0,0.2,1);pointer-events:all}
#site-loader.is-hiding{opacity:0;visibility:hidden;pointer-events:none}
#site-loader.is-hiding .loader-content{opacity:0;transform:scale(.9) translateY(-6px)}
.loader-content{position:relative;display:flex;flex-direction:column;align-items:center;transition:opacity .15s ease,transform .15s cubic-bezier(.22,1,.36,1);will-change:transform,opacity}
.loader-spinner{width:3.5rem;height:3.5rem;border:3px solid rgba(74,222,128,0.1);border-top-color:#4ade80;border-radius:50%;animation:premium-spin 0.8s cubic-bezier(0.4,0,0.2,1) infinite;filter:drop-shadow(0 0 8px rgba(74,222,128,0.4))}
.loader-text{margin-top:1.5rem;color:#4ade80;font-size:0.875rem;font-weight:600;letter-spacing:0.2em;text-transform:uppercase;animation:premium-pulse 1.5s ease-in-out infinite;text-shadow:0 0 10px rgba(74,222,128,0.3)}
@keyframes premium-spin{to{transform:rotate(360deg)}}
@keyframes premium-pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:0.6;transform:scale(0.95)}}
html.nav-drawer-open,body.nav-drawer-open{overflow:hidden;overscroll-behavior:none}
.nav-topbar-row{min-width:0}
.nav-primary-cluster{min-width:0;flex:1 1 auto}
.nav-actions{min-width:0;flex:0 0 auto}
.nav-dropdown-menu{max-height:min(72vh,34rem);overflow-x:hidden;overflow-y:auto;overscroll-behavior:contain;scrollbar-gutter:stable}
.nav-drawer-scroll{min-height:0;overflow-y:auto;overscroll-behavior:contain;scrollbar-gutter:stable}
.nav-drawer-footer{flex:0 0 auto;padding:.75rem;padding-bottom:calc(.75rem + env(safe-area-inset-bottom,0));border-top:1px solid rgba(255,255,255,.1);background:rgba(15,15,19,.88);backdrop-filter:blur(14px)}
.brand-home{min-width:0;max-width:clamp(8rem,24vw,18rem);flex:0 1 auto}
.brand-title-icon{width:1.75rem;height:1.75rem;max-width:1.75rem;max-height:1.75rem;object-fit:contain;flex:0 0 auto}
.brand-title-text{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
@media (min-width:1280px){.brand-home{max-width:15rem}.brand-title-icon{width:2rem;height:2rem;max-width:2rem;max-height:2rem}}
</style>

<div id="site-loader">
  <div class="loader-content">
    <div class="loader-spinner"></div>
    <span class="loader-text" data-lang="common.loading">Loading...</span>
  </div>
</div>

<div class="glass sticky top-0 z-50 border-b border-white/10">
  <div class="nav-topbar-row flex justify-between items-center gap-2 px-3 md:px-6 py-3 md:py-4">
    <div class="nav-primary-cluster flex items-center gap-2 md:gap-4">
      <button type="button" class="2xl:hidden shrink-0 text-green-400 focus:outline-none" data-nav-drawer-toggle aria-controls="drawer" aria-expanded="false" onclick="return openDrawer(event)" aria-label="Open menu">
        <i class="bi bi-list text-2xl"></i>
      </button>

      <?php $branding = function_exists('getStoreBranding') ? getStoreBranding() : ['title_text' => 'STORE', 'title_color' => '#4ade80', 'title_style' => 'font-extrabold']; ?>
      <a href="dashboard.php"
        class="brand-home text-xl md:text-2xl <?php echo htmlspecialchars((string) ($branding['title_style'] ?? 'font-extrabold'), ENT_QUOTES, 'UTF-8'); ?> hover:opacity-90 transition flex items-center px-3 py-1.5 rounded-lg hover:bg-green-400/10"
        style="color: <?php echo htmlspecialchars((string) ($branding['title_color'] ?? '#4ade80'), ENT_QUOTES, 'UTF-8'); ?>;">
        <?php if ($resellerTitleIconUrl !== ''): ?>
          <img src="<?php echo htmlspecialchars($resellerTitleIconUrl, ENT_QUOTES, 'UTF-8'); ?>"
               alt=""
               width="32"
               height="32"
               class="brand-title-icon mr-2 rounded"
               loading="eager"
               decoding="async"
               onerror="this.remove()">
        <?php endif; ?>
        <span class="brand-title-text"><?php echo htmlspecialchars((string) ($branding['title_text'] ?? 'STORE'), ENT_QUOTES, 'UTF-8'); ?></span>
      </a>

      <nav class="hidden 2xl:flex min-w-0 items-center space-x-1">
        <a href="dashboard.php" class="px-4 py-2 rounded-lg hover:bg-green-400/10 hover:text-green-400 transition flex items-center" data-lang="nav.dashboard"><i class="bi bi-speedometer2 mr-2"></i><?php echo Lang::t('nav.dashboard'); ?></a>
        <a href="buy.php" class="px-4 py-2 rounded-lg hover:bg-green-400/10 hover:text-green-400 transition flex items-center" data-lang="nav.buy"><i class="bi bi-bag-check mr-2"></i><?php echo Lang::t('nav.buy'); ?></a>
        <a href="mykeys.php" class="px-4 py-2 rounded-lg hover:bg-green-400/10 hover:text-green-400 transition flex items-center" data-lang="nav.my_keys"><i class="bi bi-key mr-2"></i><?php echo Lang::t('nav.my_keys'); ?></a>
        <a href="key_resets.php" class="px-4 py-2 rounded-lg hover:bg-green-400/10 hover:text-green-400 transition flex items-center" data-lang="nav.key_reset"><i class="bi bi-arrow-counterclockwise mr-2 text-orange-300"></i><?php echo Lang::t('nav.key_reset'); ?></a>
        
        <!-- More Dropdown -->
        <div class="relative" data-nav-dropdown-root>
          <button type="button" data-nav-dropdown-toggle="resellerDropdown" aria-controls="resellerDropdown" aria-expanded="false" class="px-4 py-2 rounded-lg hover:bg-green-400/10 hover:text-green-400 transition flex items-center">
            <i class="bi bi-three-dots mr-2"></i><span data-lang="nav.more"><?php echo Lang::t('nav.more'); ?></span>
            <i class="bi bi-chevron-down ml-1 text-xs"></i>
          </button>
          <div id="resellerDropdown" data-nav-dropdown-menu aria-hidden="true" class="nav-dropdown-menu absolute top-full left-0 mt-1 w-52 glass border border-white/10 rounded-lg shadow-xl hidden z-50">
            <a href="deposit.php" class="block px-4 py-2 rounded-lg hover:bg-green-400/10 hover:text-green-400 transition flex items-center" data-lang="nav.deposit"><i class="bi bi-wallet2 mr-2"></i><?php echo Lang::t('nav.deposit'); ?></a>
            <a href="history.php" class="block px-4 py-2 rounded-lg hover:bg-green-400/10 hover:text-green-400 transition flex items-center" data-lang="nav.history"><i class="bi bi-clock-history mr-2"></i><?php echo Lang::t('nav.history'); ?></a>
            <a href="rankings.php" class="block px-4 py-2 rounded-lg hover:bg-green-400/10 hover:text-green-400 transition flex items-center" data-lang="nav.rankings"><i class="bi bi-trophy mr-2"></i><?php echo Lang::t('nav.rankings'); ?></a>
            <?php if ($__showResellerApiMenu): ?><a href="api_store.php" class="block px-4 py-2 rounded-lg hover:bg-green-400/10 hover:text-green-400 transition flex items-center"><i class="bi bi-code-slash mr-2"></i>Developer API</a><?php endif; ?>
            <a href="account.php" class="block px-4 py-2 rounded-lg hover:bg-green-400/10 hover:text-green-400 transition flex items-center" data-lang="nav.account"><i class="bi bi-person-gear mr-2"></i><?php echo Lang::t('nav.account'); ?></a>
          </div>
        </div>
      </nav>
      
    </div>

    <div class="nav-actions flex items-center gap-1.5 md:gap-2">
      <div class="hidden 2xl:block max-w-36 truncate text-gray-400 text-sm md:text-base"><?php echo isset($_SESSION['username']) ? htmlspecialchars($_SESSION['username']) : ''; ?></div>
      <div class="hidden md:block px-2.5 py-1 rounded-lg bg-white/5 border border-white/10 text-xs md:text-sm text-white">
        <span class="inline-flex items-center gap-1"><svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-green-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-2m2-6h-6a2 2 0 000 4h6m0-4v4"/></svg><span data-lang="nav.balance"><?php echo Lang::t('nav.balance'); ?>:</span> <span class="font-semibold text-green-400"><?php echo formatCurrency($__navBalance); ?></span></span>
      </div>
      
      <!-- Language Switcher -->
      <div class="hidden sm:flex items-center gap-1.5 px-2 py-1 rounded-lg bg-white/5 border border-white/10">
        <a href="../toggle_lang.php?lang=th" class="hover:scale-110 transition-transform <?php echo $currentLang === 'th' ? 'brightness-110 drop-shadow-[0_0_5px_rgba(255,255,255,0.3)]' : 'opacity-50 grayscale-[0.5]'; ?>" title="<?php echo Lang::t('common.lang.th'); ?>">
          <span class="text-xl">🇹🇭</span>
        </a>
        <a href="../toggle_lang.php?lang=en" class="hover:scale-110 transition-transform <?php echo $currentLang === 'en' ? 'brightness-110 drop-shadow-[0_0_5px_rgba(255,255,255,0.3)]' : 'opacity-50 grayscale-[0.5]'; ?>" title="<?php echo Lang::t('common.lang.en'); ?>">
          <span class="text-xl">🇬🇧</span>
        </a>
      </div>
      
      <form method="POST" action="../logout.php" class="hidden 2xl:inline-flex m-0 shrink-0">
        <?php echo csrfField(); ?>
        <button type="submit" class="inline-flex items-center px-4 py-2 rounded-lg bg-blue-500 hover:bg-blue-600 text-white transition">
          <i class="bi bi-box-arrow-right mr-2"></i><span data-lang="nav.logout"><?php echo Lang::t('nav.logout'); ?></span>
        </button>
      </form>

    </div>
  </div>
</div>

<?php echo accountRecoveryRenderEmailNotice((int) ($_SESSION['user_id'] ?? 0), 'account.php'); ?>

<div id="drawerOverlay" class="drawer-overlay" aria-hidden="true"></div>
<aside id="drawer" class="drawer glass border-r border-white/10 flex flex-col" aria-hidden="true" aria-label="Reseller navigation">
  <div class="p-4 border-b border-white/10 flex items-center justify-between">
    <div class="flex items-center gap-2">
      <i class="bi bi-person-badge text-green-400"></i>
      <?php if ($resellerTitleIconUrl !== ''): ?>
        <img src="<?php echo htmlspecialchars($resellerTitleIconUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="" width="24" height="24" class="w-6 h-6 max-w-6 max-h-6 object-contain shrink-0 rounded bg-white/5 border border-white/10" onerror="this.remove()">
      <?php endif; ?>
      <span class="font-bold text-green-300" data-lang="nav.reseller"><?php echo Lang::t('nav.reseller'); ?></span>
    </div>
    <button type="button" class="text-gray-300 hover:text-white" onclick="return closeDrawer(event)" aria-label="Close menu">
      <i class="bi bi-x-lg"></i>
    </button>
  </div>
  <nav class="nav-drawer-scroll p-3 space-y-1 flex-1">
    <a href="dashboard.php" class="block px-4 py-2 rounded-lg hover:bg-green-400/10 hover:text-green-400 transition" data-lang="nav.dashboard"><i class="bi bi-speedometer2 mr-2"></i><?php echo Lang::t('nav.dashboard'); ?></a>
    <a href="deposit.php" class="block px-4 py-2 rounded-lg hover:bg-green-400/10 hover:text-green-400 transition" data-lang="nav.deposit"><i class="bi bi-wallet2 mr-2"></i><?php echo Lang::t('nav.deposit'); ?></a>
    <a href="buy.php" class="block px-4 py-2 rounded-lg hover:bg-green-400/10 hover:text-green-400 transition" data-lang="nav.buy"><i class="bi bi-bag-check mr-2"></i><?php echo Lang::t('nav.buy'); ?></a>
    <a href="mykeys.php" class="block px-4 py-2 rounded-lg hover:bg-green-400/10 hover:text-green-400 transition" data-lang="nav.my_keys"><i class="bi bi-key mr-2"></i><?php echo Lang::t('nav.my_keys'); ?></a>
    <a href="key_resets.php" class="block px-4 py-2 rounded-lg hover:bg-green-400/10 hover:text-green-400 transition" data-lang="nav.key_reset"><i class="bi bi-arrow-counterclockwise mr-2 text-orange-300"></i><?php echo Lang::t('nav.key_reset'); ?></a>
    <a href="history.php" class="block px-4 py-2 rounded-lg hover:bg-green-400/10 hover:text-green-400 transition" data-lang="nav.history"><i class="bi bi-clock-history mr-2"></i><?php echo Lang::t('nav.history'); ?></a>
    <a href="rankings.php" class="block px-4 py-2 rounded-lg hover:bg-green-400/10 hover:text-green-400 transition" data-lang="nav.rankings"><i class="bi bi-trophy mr-2"></i><?php echo Lang::t('nav.rankings'); ?></a>
    <?php if ($__showResellerApiMenu): ?><a href="api_store.php" class="block px-4 py-2 rounded-lg hover:bg-green-400/10 hover:text-green-400 transition"><i class="bi bi-code-slash mr-2"></i>Developer API</a><?php endif; ?>
    <a href="account.php" class="block px-4 py-2 rounded-lg hover:bg-green-400/10 hover:text-green-400 transition" data-lang="nav.account"><i class="bi bi-person-gear mr-2"></i><?php echo Lang::t('nav.account'); ?></a>
  </nav>
  <div class="nav-drawer-footer">
    <div class="mb-3 rounded-lg border border-white/10 bg-white/5 p-3">
      <div class="mb-2 truncate text-xs text-gray-400"><?php echo htmlspecialchars((string) ($_SESSION['username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
      <div class="flex items-center justify-between gap-3 text-sm">
        <span class="inline-flex items-center gap-2 text-gray-300"><i class="bi bi-wallet2 text-green-400"></i><span data-lang="nav.balance"><?php echo Lang::t('nav.balance'); ?></span></span>
        <span class="shrink-0 font-semibold text-green-400"><?php echo formatCurrency($__navBalance); ?></span>
      </div>
    </div>
    <div class="mb-3 flex items-center justify-between gap-3 rounded-lg border border-white/10 bg-white/5 px-3 py-2">
      <span class="text-sm text-gray-300"><i class="bi bi-translate mr-2 text-sky-300"></i><?php echo $currentLang === 'en' ? 'Language' : 'ภาษา'; ?></span>
      <div class="flex items-center gap-2">
        <a href="../toggle_lang.php?lang=th" class="rounded-md px-2 py-1 transition <?php echo $currentLang === 'th' ? 'bg-white/15 ring-1 ring-white/20' : 'opacity-55 hover:opacity-100'; ?>" title="<?php echo Lang::t('common.lang.th'); ?>" aria-label="<?php echo Lang::t('common.lang.th'); ?>"><span class="text-xl">🇹🇭</span></a>
        <a href="../toggle_lang.php?lang=en" class="rounded-md px-2 py-1 transition <?php echo $currentLang === 'en' ? 'bg-white/15 ring-1 ring-white/20' : 'opacity-55 hover:opacity-100'; ?>" title="<?php echo Lang::t('common.lang.en'); ?>" aria-label="<?php echo Lang::t('common.lang.en'); ?>"><span class="text-xl">🇬🇧</span></a>
      </div>
    </div>
    <form method="POST" action="../logout.php" class="m-0">
      <?php echo csrfField(); ?>
      <button type="submit" class="block w-full text-left px-4 py-2.5 rounded-lg bg-blue-500 hover:bg-blue-600 text-white transition"><i class="bi bi-box-arrow-right mr-2"></i><span data-lang="nav.logout"><?php echo Lang::t('nav.logout'); ?></span></button>
    </form>
  </div>
</aside>

<script src="../assets/js/nav.js?v=<?php echo $navAssetVersion; ?>"></script>


<script src="../assets/js/security.js?v=<?php echo $securityAssetVersion; ?>"></script>
<script>
// Define the server-selected language before lang.js executes. This prevents
// localStorage from temporarily winning the race on slower devices.
window.PHP_LANG = <?php echo json_encode($currentLang === 'en' ? 'en' : 'th'); ?>;
window.APP_CURRENCY_SYMBOL = <?php echo json_encode(getSetting('currency') ?: '฿'); ?>;
window.APP_CURRENCY_NAME = <?php echo json_encode(getSetting('currency_name') ?: 'THB'); ?>;
window.APP_EXCHANGE_RATE = <?php echo json_encode(getExchangeRateThbToUsd() ?: 0); ?>;
</script>
<script src="../assets/js/lang.js?v=<?php echo $langAssetVersion; ?>"></script>
<script>
window.addEventListener('DOMContentLoaded', function() {
    if (typeof Lang !== 'undefined') {
        Lang.init({ exchangeRate: window.APP_EXCHANGE_RATE });
    }

    let loaderClosing = false;
    const hideLoader = () => {
        if (loaderClosing) return;
        const loader = document.getElementById('site-loader');
        if (!loader) return;
        loaderClosing = true;
        if (window.AppPageLoader && typeof window.AppPageLoader.hide === 'function') {
            window.AppPageLoader.hide();
            return;
        }
        loader.classList.add('is-hiding');
        loader.setAttribute('aria-hidden', 'true');
        loader.style.pointerEvents = 'none';
        window.setTimeout(() => {
            if (loader.classList.contains('is-hiding')) loader.style.display = 'none';
        }, 180);
    };

    if (typeof Lang !== 'undefined' && Lang.initialized) {
        hideLoader();
    } else {
        document.addEventListener('langReady', hideLoader, { once: true });
        window.setTimeout(hideLoader, 3000);
    }
});
</script>

<?php
require_once __DIR__ . '/../includes/music_player.php';
echo renderMusicPlayer('reseller', '../');
?>
