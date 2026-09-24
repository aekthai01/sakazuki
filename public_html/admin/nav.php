<?php
// Admin Navbar (fixed + mobile drawer)
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    require_once __DIR__ . '/../includes/auth.php';
    requireAdmin();
}
$__navBalance = 0;
if (isset($_SESSION['user_id'])) {
  if (function_exists('getUserBalance')) {
    $__navBalance = getUserBalance((int) $_SESSION['user_id']);
  } elseif (isset($_SESSION['balance'])) {
    $__navBalance = $_SESSION['balance'];
  }
}
$__navBalanceFmt = number_format((float) $__navBalance, 2, '.', '');
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
.drawer-overlay{position:fixed;inset:0;background:rgba(0,0,0,.65);backdrop-filter:blur(2px);z-index:60;display:none}
.drawer{position:fixed;top:0;left:0;height:100vh;height:100dvh;width:18rem;max-width:85vw;transform:translateX(-100%);transition:transform .15s ease;z-index:61;padding-bottom:env(safe-area-inset-bottom,0)}
.drawer.open{transform:translateX(0)}
.drawer-overlay.open{display:block}
#site-loader{position:fixed;inset:0;background:rgba(15,15,19,0.7);backdrop-filter:blur(10px);z-index:9999;display:flex;flex-direction:column;align-items:center;justify-content:center;transition:opacity .15s cubic-bezier(0.4,0,0.2,1);pointer-events:all}
#site-loader.is-hiding{opacity:0;visibility:hidden;pointer-events:none}
#site-loader.is-hiding .loader-content{opacity:0;transform:scale(.9) translateY(-6px)}
.loader-content{position:relative;display:flex;flex-direction:column;align-items:center;transition:opacity .15s ease,transform .15s cubic-bezier(.22,1,.36,1);will-change:transform,opacity}
.loader-spinner{width:3.5rem;height:3.5rem;border:3px solid rgba(139,92,246,0.1);border-top-color:#8b5cf6;border-radius:50%;animation:premium-spin 0.8s cubic-bezier(0.4,0,0.2,1) infinite;filter:drop-shadow(0 0 8px rgba(139,92,246,0.4))}
.loader-text{margin-top:1.5rem;color:#8b5cf6;font-size:0.875rem;font-weight:600;letter-spacing:0.2em;text-transform:uppercase;animation:premium-pulse 1.5s ease-in-out infinite;text-shadow:0 0 10px rgba(139,92,246,0.3)}
@keyframes premium-spin{to{transform:rotate(360deg)}}
@keyframes premium-pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:0.6;transform:scale(0.95)}}
html.nav-drawer-open,body.nav-drawer-open{overflow:hidden;overscroll-behavior:none}
.nav-topbar-row{min-width:0}
.nav-primary-cluster{min-width:0;flex:1 1 auto}
.nav-actions{min-width:0;flex:0 0 auto}
.nav-dropdown-menu{max-height:min(72vh,34rem);overflow-x:hidden;overflow-y:auto;overscroll-behavior:contain;scrollbar-gutter:stable}
.nav-drawer-scroll{min-height:0;overflow-y:auto;overscroll-behavior:contain;scrollbar-gutter:stable}
.nav-drawer-footer{flex:0 0 auto;padding:.75rem;padding-bottom:calc(.75rem + env(safe-area-inset-bottom,0));border-top:1px solid rgba(255,255,255,.1);background:rgba(15,15,19,.88);backdrop-filter:blur(14px)}
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
      <button type="button" class="2xl:hidden shrink-0 text-accent focus:outline-none" data-nav-drawer-toggle aria-controls="drawer" aria-expanded="false" onclick="return openDrawer(event)">
        <i class="bi bi-list text-2xl"></i>
      </button>

      <a href="dashboard.php"
        class="text-xl md:text-2xl font-extrabold text-accent hover:opacity-80 transition flex items-center px-3 py-1.5 rounded-lg hover:bg-accent/10" data-lang="nav.admin">
        <i class="bi bi-box-seam mr-2"></i><?php echo Lang::t('nav.admin'); ?>
      </a>

      <nav class="hidden 2xl:flex min-w-0 items-center space-x-1">
        <a href="dashboard.php" class="px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition flex items-center" data-lang="nav.dashboard"><i class="bi bi-speedometer2 mr-2"></i><?php echo Lang::t('nav.dashboard'); ?></a>
        <a href="users.php" class="px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition flex items-center" data-lang="nav.users"><i class="bi bi-people mr-2"></i><?php echo Lang::t('nav.users'); ?></a>
        <a href="security.php" class="px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition flex items-center" data-lang="nav.security"><i class="bi bi-shield-lock mr-2"></i><?php echo Lang::t('nav.security'); ?></a>
        <a href="products.php" class="px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition flex items-center" data-lang="nav.products"><i class="bi bi-box-seam mr-2"></i><?php echo Lang::t('nav.products'); ?></a>
        <a href="transactions.php" class="px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition flex items-center" data-lang="nav.transactions"><i class="bi bi-wallet2 mr-2"></i><?php echo Lang::t('nav.transactions'); ?></a>
        <a href="email_settings.php" class="px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition flex items-center"><i class="bi bi-envelope-lock mr-2 text-blue-300"></i><?php echo $currentLang === 'en' ? 'Email Recovery' : 'กู้รหัสผ่าน'; ?></a>
        <a href="settings.php" class="px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition flex items-center" data-lang="nav.settings"><i class="bi bi-gear mr-2"></i><?php echo Lang::t('nav.settings'); ?></a>
        <a href="music.php" class="px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition flex items-center"><i class="bi bi-music-note-beamed mr-2 text-violet-300"></i><?php echo $currentLang === 'en' ? 'Music' : 'เพลง'; ?></a>
        
        <!-- More Dropdown -->
        <div class="relative" data-nav-dropdown-root>
          <button type="button" data-nav-dropdown-toggle="adminDropdown" aria-controls="adminDropdown" aria-expanded="false" class="px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition flex items-center">
            <i class="bi bi-three-dots mr-2"></i><span data-lang="nav.more"><?php echo Lang::t('nav.more'); ?></span>
            <i class="bi bi-chevron-down ml-1 text-xs"></i>
          </button>
          <div id="adminDropdown" data-nav-dropdown-menu aria-hidden="true" class="nav-dropdown-menu absolute top-full left-0 mt-1 w-56 glass border border-white/10 rounded-lg shadow-xl hidden z-50">
            <a href="resellers.php" class="block px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition flex items-center" data-lang="nav.resellers"><i class="bi bi-person-badge mr-2"></i><?php echo Lang::t('nav.resellers'); ?></a>
            <a href="reseller-prices.php" class="block px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition flex items-center" data-lang="nav.special_prices"><i class="bi bi-tags mr-2"></i><?php echo Lang::t('nav.special_prices'); ?></a>
            <a href="out_of_stock.php" class="block px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition flex items-center" data-lang="nav.out_of_stock"><i class="bi bi-exclamation-triangle mr-2"></i><?php echo Lang::t('nav.out_of_stock'); ?></a>
            <a href="keys.php" class="block px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition flex items-center" data-lang="nav.product_keys"><i class="bi bi-key mr-2"></i><?php echo Lang::t('nav.product_keys'); ?></a>
            <a href="deposit.php" class="block px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition flex items-center" data-lang="nav.deposit"><i class="bi bi-wallet2 mr-2"></i><?php echo Lang::t('nav.deposit'); ?></a>
            <a href="slip-debug.php" class="block px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition flex items-center"><i class="bi bi-bug mr-2 text-fuchsia-300"></i><?php echo $currentLang === 'th' ? 'Debug ตรวจสลิป' : 'Slip Debug'; ?></a>
            <a href="truemoney_debug.php" class="block px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition flex items-center"><i class="bi bi-wallet2 mr-2 text-orange-300"></i><?php echo $currentLang === 'th' ? 'TrueMoney / Wallet Debug' : 'TrueMoney / Wallet Debug'; ?></a>
            <a href="binance_deposits.php" class="block px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition flex items-center" data-lang="nav.binance"><i class="bi bi-currency-bitcoin mr-2 text-yellow-400"></i><?php echo Lang::t('nav.binance'); ?></a>
            <a href="binance_giftcards.php" class="block px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition flex items-center" data-lang="nav.binance_giftcards"><i class="bi bi-gift mr-2 text-amber-300"></i><?php echo Lang::t('nav.binance_giftcards'); ?></a>
            <a href="profit.php" class="block px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition flex items-center" data-lang="nav.profit"><i class="bi bi-graph-up-arrow mr-2"></i><?php echo Lang::t('nav.profit'); ?></a>
            <a href="rankings.php" class="block px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition flex items-center" data-lang="nav.rankings"><i class="bi bi-trophy mr-2"></i><?php echo Lang::t('nav.rankings'); ?></a>
            <a href="cheatgame.php" class="block px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition flex items-center" data-lang="nav.cheatgame_api"><i class="bi bi-cloud-arrow-down mr-2"></i><?php echo Lang::t('nav.cheatgame_api'); ?></a>
            <a href="api_hub.php" class="block px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition flex items-center"><i class="bi bi-diagram-3 mr-2 text-violet-300"></i><?php echo $currentLang === 'th' ? 'ศูนย์จัดการ API' : 'API Hub'; ?></a>
            <a href="commerce_center.php" class="block px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition flex items-center"><i class="bi bi-database-check mr-2 text-emerald-300"></i><?php echo $currentLang === 'th' ? 'ศูนย์ข้อมูลการค้า' : 'Commerce Center'; ?></a>
            <a href="commerce_ledger.php" class="block px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition flex items-center"><i class="bi bi-journal-text mr-2 text-sky-300"></i><?php echo $currentLang === 'th' ? 'บัญชีธุรกรรมกลาง' : 'Central Ledger'; ?></a>
            <a href="api_products.php" class="block px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition flex items-center"><i class="bi bi-box-seam mr-2 text-sky-300"></i><?php echo $currentLang === 'th' ? 'สินค้าและราคา API' : 'API Products & Pricing'; ?></a>
            <a href="key_resets.php" class="block px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition flex items-center" data-lang="nav.key_reset"><i class="bi bi-arrow-counterclockwise mr-2 text-orange-300"></i><?php echo Lang::t('nav.key_reset'); ?></a>
            <a href="commerce_consistency.php" class="block px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition flex items-center"><i class="bi bi-database-check mr-2 text-cyan-300"></i><?php echo $currentLang === 'th' ? 'ตรวจข้อมูลการขาย' : 'Commerce Check'; ?></a>
            <a href="transaction_integrity.php" class="block px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition flex items-center"><i class="bi bi-wrench-adjustable-circle mr-2 text-amber-300"></i><?php echo $currentLang === 'th' ? 'ซ่อมชนิด Transaction' : 'Transaction Repair'; ?></a>
            <a href="codes.php" class="block px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition flex items-center" data-lang="nav.codes"><i class="bi bi-ticket-perforated mr-2"></i><?php echo Lang::t('nav.codes'); ?></a>
          </div>
        </div>
      </nav>
      
    </div>

    <div class="nav-actions flex items-center gap-1.5 md:gap-2">
      <span class="hidden 2xl:block max-w-36 truncate text-gray-400 text-sm md:text-base">
        <?php echo isset($_SESSION["username"]) ? htmlspecialchars($_SESSION["username"]) : "Admin"; ?>
      </span>

      <div class="hidden md:block px-2.5 py-1 rounded-lg bg-white/5 border border-white/10 text-xs md:text-sm text-white">
        <span class="inline-flex items-center gap-1">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-green-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-2m2-6h-6a2 2 0 000 4h6m0-4v4" />
          </svg>
          <span data-lang="nav.balance"><?php echo Lang::t('nav.balance'); ?>:</span>
          <span class="font-semibold text-green-400"><?php echo formatCurrency($__navBalance); ?></span>
        </span>
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

<div id="drawerOverlay" class="drawer-overlay" aria-hidden="true"></div>

<aside id="drawer" class="drawer glass border-r border-white/10 flex flex-col" aria-hidden="true" aria-label="Admin navigation">
  <div class="p-4 border-b border-white/10 flex items-center justify-between">
    <div class="flex items-center gap-2">
      <i class="bi bi-box-seam text-accent"></i>
      <span class="font-bold text-white" data-lang="nav.admin"><?php echo Lang::t('nav.admin'); ?></span>
    </div>
    <button type="button" class="text-gray-300 hover:text-white" onclick="return closeDrawer(event)" aria-label="Close menu">
      <i class="bi bi-x-lg"></i>
    </button>
  </div>

  <nav class="nav-drawer-scroll p-3 space-y-1 flex-1">
    <a href="dashboard.php" class="block px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition" data-lang="nav.dashboard"><i class="bi bi-speedometer2 mr-2"></i><?php echo Lang::t('nav.dashboard'); ?></a>
    <a href="users.php" class="block px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition" data-lang="nav.users"><i class="bi bi-people mr-2"></i><?php echo Lang::t('nav.users'); ?></a>
    <a href="security.php" class="block px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition" data-lang="nav.security"><i class="bi bi-shield-lock mr-2"></i><?php echo Lang::t('nav.security'); ?></a>
    <a href="resellers.php" class="block px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition" data-lang="nav.resellers"><i class="bi bi-person-badge mr-2"></i><?php echo Lang::t('nav.resellers'); ?></a>
    <a href="reseller-prices.php" class="block px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition" data-lang="nav.special_prices"><i class="bi bi-tags mr-2"></i><?php echo Lang::t('nav.special_prices'); ?></a>
    <a href="products.php" class="block px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition" data-lang="nav.products"><i class="bi bi-box-seam mr-2"></i><?php echo Lang::t('nav.products'); ?></a>
    <a href="out_of_stock.php" class="block px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition" data-lang="nav.out_of_stock"><i class="bi bi-exclamation-triangle mr-2"></i><?php echo Lang::t('nav.out_of_stock'); ?></a>
    <a href="keys.php" class="block px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition" data-lang="nav.product_keys"><i class="bi bi-key mr-2"></i><?php echo Lang::t('nav.product_keys'); ?></a>
    <a href="transactions.php" class="block px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition" data-lang="nav.transactions"><i class="bi bi-wallet2 mr-2"></i><?php echo Lang::t('nav.transactions'); ?></a>
    <a href="deposit.php" class="block px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition" data-lang="nav.deposit"><i class="bi bi-wallet2 mr-2"></i><?php echo Lang::t('nav.deposit'); ?></a>
    <a href="slip-debug.php" class="block px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition"><i class="bi bi-bug mr-2 text-fuchsia-300"></i><?php echo $currentLang === 'th' ? 'Debug ตรวจสลิป' : 'Slip Debug'; ?></a>
    <a href="truemoney_debug.php" class="block px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition"><i class="bi bi-wallet2 mr-2 text-orange-300"></i><?php echo $currentLang === 'th' ? 'TrueMoney / Wallet Debug' : 'TrueMoney / Wallet Debug'; ?></a>
    <a href="binance_deposits.php" class="block px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition" data-lang="nav.binance"><i class="bi bi-currency-bitcoin mr-2 text-yellow-400"></i><?php echo Lang::t('nav.binance'); ?></a>
    <a href="binance_giftcards.php" class="block px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition" data-lang="nav.binance_giftcards"><i class="bi bi-gift mr-2 text-amber-300"></i><?php echo Lang::t('nav.binance_giftcards'); ?></a>
    <a href="profit.php" class="block px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition" data-lang="nav.profit"><i class="bi bi-graph-up-arrow mr-2"></i><?php echo Lang::t('nav.profit'); ?></a>
    <a href="rankings.php" class="block px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition" data-lang="nav.rankings"><i class="bi bi-trophy mr-2"></i><?php echo Lang::t('nav.rankings'); ?></a>
    <a href="cheatgame.php" class="block px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition" data-lang="nav.cheatgame_api"><i class="bi bi-cloud-arrow-down mr-2"></i><?php echo Lang::t('nav.cheatgame_api'); ?></a>
    <a href="api_hub.php" class="block px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition"><i class="bi bi-diagram-3 mr-2 text-violet-300"></i><?php echo $currentLang === 'th' ? 'ศูนย์จัดการ API' : 'API Hub'; ?></a>
    <a href="commerce_center.php" class="block px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition"><i class="bi bi-database-check mr-2 text-emerald-300"></i><?php echo $currentLang === 'th' ? 'ศูนย์ข้อมูลการค้า' : 'Commerce Center'; ?></a>
    <a href="commerce_ledger.php" class="block px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition"><i class="bi bi-journal-text mr-2 text-sky-300"></i><?php echo $currentLang === 'th' ? 'บัญชีธุรกรรมกลาง' : 'Central Ledger'; ?></a>
    <a href="api_products.php" class="block px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition"><i class="bi bi-box-seam mr-2 text-sky-300"></i><?php echo $currentLang === 'th' ? 'สินค้าและราคา API' : 'API Products & Pricing'; ?></a>
    <a href="key_resets.php" class="block px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition" data-lang="nav.key_reset"><i class="bi bi-arrow-counterclockwise mr-2 text-orange-300"></i><?php echo Lang::t('nav.key_reset'); ?></a>
    <a href="commerce_consistency.php" class="block px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition"><i class="bi bi-database-check mr-2 text-cyan-300"></i><?php echo $currentLang === 'th' ? 'ตรวจข้อมูลการขาย' : 'Commerce Check'; ?></a>
    <a href="transaction_integrity.php" class="block px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition"><i class="bi bi-wrench-adjustable-circle mr-2 text-amber-300"></i><?php echo $currentLang === 'th' ? 'ซ่อมชนิด Transaction' : 'Transaction Repair'; ?></a>
    <a href="codes.php" class="block px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition" data-lang="nav.codes"><i class="bi bi-ticket-perforated mr-2"></i><?php echo Lang::t('nav.codes'); ?></a>
    <a href="email_settings.php" class="block px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition"><i class="bi bi-envelope-lock mr-2 text-blue-300"></i><?php echo $currentLang === 'en' ? 'Email Recovery' : 'กู้รหัสผ่าน'; ?></a>
    <a href="settings.php" class="block px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition" data-lang="nav.settings"><i class="bi bi-gear mr-2"></i><?php echo Lang::t('nav.settings'); ?></a>
    <a href="music.php" class="block px-4 py-2 rounded-lg hover:bg-accent/10 hover:text-accent transition"><i class="bi bi-music-note-beamed mr-2 text-violet-300"></i><?php echo $currentLang === 'en' ? 'Music' : 'เพลง'; ?></a>
  </nav>
  <div class="nav-drawer-footer">
    <div class="mb-3 rounded-lg border border-white/10 bg-white/5 p-3">
      <div class="mb-2 truncate text-xs text-gray-400"><?php echo htmlspecialchars((string) ($_SESSION['username'] ?? 'Admin'), ENT_QUOTES, 'UTF-8'); ?></div>
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
echo renderMusicPlayer('admin', '../');
?>
