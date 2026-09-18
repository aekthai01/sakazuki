<?php
// Receiver and Bank details from settings
$lang = getAppLang();
$receiverTh = trim((string) getSetting('easyslip_receiver_name'));
$receiverEn = trim((string) getSetting('easyslip_receiver_name_en'));
$bankNameTh = trim((string) getSetting('easyslip_bank_name'));
$bankNameEn = trim((string) getSetting('easyslip_bank_name_en'));
$accountNum = trim((string) getSetting('easyslip_account_number'));

$displayReceiver = ($lang === 'en' ? $receiverEn : $receiverTh) ?: (($lang === 'en' ? $receiverTh : $receiverEn) ?: 'ยังไม่ได้ตั้งค่า');
$displayBank = ($lang === 'en' ? $bankNameEn : $bankNameTh) ?: (($lang === 'en' ? $bankNameTh : $bankNameEn) ?: 'ยังไม่ได้ตั้งค่า');
$displayAccountNum = $accountNum !== '' ? $accountNum : 'ยังไม่ได้ตั้งค่า';

// Binance Settings
require_once __DIR__ . '/binance.php';
require_once __DIR__ . '/binance_giftcard.php';
$bnbSettings = getBinanceSettings();
$giftCardSettings = getBinanceGiftCardPublicSettings();
$currentDepositRole = (string) ($_SESSION['role'] ?? '');
$giftCardVisible = !empty($giftCardSettings['enabled'])
    && (($currentDepositRole === 'user' && !empty($giftCardSettings['user_enabled']))
        || ($currentDepositRole === 'reseller' && !empty($giftCardSettings['reseller_enabled'])));

// Determine base path for assets based on script location
$assetPath = '../assets/image/';

// Redeem code top-up
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['redeem_submit'])) {
    requireCsrfToken();
    $code = $_POST['redeem_code'] ?? '';
    $res = redeemTopupCode($_SESSION['user_id'], $code);
    if ($res['success']) {
        $success = $res['message'];
    } else {
        $error = $res['message'] ?? Lang::t('common.error.invalid_request');
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(getAppLang(), ENT_QUOTES, 'UTF-8'); ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title data-lang="deposit.title"><?php echo Lang::t('deposit.title'); ?></title>
    <link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>:root{--sakazuki-accent:#3b82f6;--sakazuki-accent-rgb:59 130 246;--sakazuki-accent2:#60a5fa;--sakazuki-accent2-rgb:96 165 250;--sakazuki-glow:0 0 25px rgba(59,130,246,0.35)}</style>
    <style>
        .glass {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }

        .payment-card {
            transition: all .15s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
        }

        .payment-card:hover {
            transform: translateY(-5px);
            background: rgba(255, 255, 255, 0.08);
            border-color: rgba(59, 130, 246, 0.5);
            box-shadow: 0 10px 30px -10px rgba(0, 0, 0, 0.5);
        }

        .modal-backdrop {
            background: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(8px);
            transition: opacity .15s ease;
        }

        .modal-content {
            transition: all .15s cubic-bezier(0.4, 0, 0.2, 1);
            transform: scale(0.95);
            opacity: 0;
        }

        .modal-open .modal-content {
            transform: scale(1);
            opacity: 1;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .animate-fade-in {
            animation: fadeInUp .15s ease-out forwards;
        }

        .animate-delay-1 {
            animation-delay: 0.1s;
        }

        .animate-delay-2 {
            animation-delay: 0.2s;
        }

        .animate-delay-3 {
            animation-delay: 0.3s;
        }

        .animate-delay-4 {
            animation-delay: 0.4s;
        }
    </style>
</head>

<body class="bg-darkbg text-gray-100 min-h-screen flex flex-col">
    <?php include 'nav.php'; ?>

    <main class="flex-1 p-6 space-y-6">
        <!-- Header -->
        <div class="flex justify-between items-center mb-6">
            <h3 class="text-2xl font-bold text-white"><i class="bi bi-wallet2 mr-2"></i><span
                    data-lang="deposit.title"><?php echo Lang::t('deposit.title'); ?></span></h3>
            <span
                class="px-3 py-1 rounded-full text-sm font-medium bg-blue-500/20 text-blue-400 border border-blue-500/30">
                <span data-lang="deposit.my_balance"><?php echo Lang::t('deposit.my_balance'); ?></span>
                <?php echo formatCurrency(getUserBalance($_SESSION['user_id'])); ?>
            </span>
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


        <!-- Card Selection Grid -->
        <div class="max-w-4xl mx-auto">
            <div class="text-center mb-10">
                <h4 class="text-3xl font-extrabold text-white mb-2" data-lang="deposit.add_balance">
                    <?php echo Lang::t('deposit.add_balance'); ?>
                </h4>
                <p class="text-gray-400" data-lang="deposit.select_method">
                    <?php echo Lang::t('deposit.select_method') ?: 'โปรดเลือกช่องทางการเติมเงินของคุณ'; ?>
                </p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 pb-20">
                <!-- TrueMoney Angpao Card -->
                <?php if (getSetting('truemoney_enabled') === '1'): ?>
                    <button type="button" onclick="openModal('modal_angpao', this)" aria-haspopup="dialog" aria-controls="modal_angpao"
                        class="glass payment-card rounded-2xl p-6 flex flex-col items-center text-center animate-fade-in animate-delay-1 w-full">
                        <span
                            class="w-24 h-24 mb-4 flex items-center justify-center rounded-2xl bg-orange-500/10 border border-orange-500/20">
                            <img src="<?php echo $assetPath; ?>angpao.png" alt="Angpao" class="w-16 h-16 object-contain">
                        </span>
                        <span class="block text-lg font-bold text-white mb-1" data-lang="deposit.angpao.title">
                            <?php echo Lang::t('deposit.angpao.title'); ?></span>
                        <span class="block text-xs text-orange-400 font-medium bg-orange-500/10 px-3 py-1 rounded-full border border-orange-500/20"
                            data-lang="deposit.angpao.fee">
                            <?php echo Lang::t('deposit.angpao.fee') ?: 'ค่าธรรมเนียม 2.9% สูงสุด 20฿'; ?>
                        </span>
                    </button>
                <?php endif; ?>

                <!-- Bank Transfer Card -->
                <?php if (getSetting('easyslip_enabled') === '1'): ?>
                    <button type="button" onclick="openModal('modal_slip', this)" aria-haspopup="dialog" aria-controls="modal_slip"
                        class="glass payment-card rounded-2xl p-6 flex flex-col items-center text-center animate-fade-in animate-delay-2 w-full">
                        <span
                            class="w-24 h-24 mb-4 flex items-center justify-center rounded-2xl bg-emerald-500/10 border border-emerald-500/20">
                            <img src="<?php echo $assetPath; ?>slip.png" alt="Slip" class="w-16 h-16 object-contain">
                        </span>
                        <span class="block text-lg font-bold text-white mb-1" data-lang="deposit.slip.banking">
                            <?php echo Lang::t('deposit.slip.banking'); ?></span>
                        <span class="block text-xs text-emerald-400 font-medium bg-emerald-500/10 px-3 py-1 rounded-full border border-emerald-500/20"
                            data-lang="deposit.slip.fee_free">
                            <?php echo Lang::t('deposit.slip.fee_free'); ?>
                        </span>
                    </button>
                <?php endif; ?>

                <!-- Binance USDT Card -->
                <?php if ($bnbSettings['enabled']): ?>
                    <button type="button" onclick="openModal('modal_binance', this)" aria-haspopup="dialog" aria-controls="modal_binance"
                        class="glass payment-card rounded-2xl p-6 flex flex-col items-center text-center animate-fade-in animate-delay-3 w-full">
                        <span
                            class="w-24 h-24 mb-4 flex items-center justify-center rounded-2xl bg-yellow-500/10 border border-yellow-500/20">
                            <img src="<?php echo $assetPath; ?>binance.png" alt="Binance" class="w-16 h-16 object-contain">
                        </span>
                        <span class="block text-lg font-bold text-white mb-1" data-lang="deposit.binance.title">
                            <?php echo Lang::t('deposit.binance.title'); ?></span>
                        <span
                            class="block text-xs text-yellow-400 font-medium bg-yellow-500/10 px-3 py-1 rounded-full border border-yellow-500/20">
                            USDT (TRC20)
                        </span>
                    </button>
                <?php endif; ?>

                <!-- Binance Gift Card Card -->
                <?php if ($giftCardVisible): ?>
                    <button type="button" onclick="openModal('modal_binance_giftcard', this)" aria-haspopup="dialog" aria-controls="modal_binance_giftcard"
                        class="glass payment-card rounded-2xl p-6 flex flex-col items-center text-center animate-fade-in animate-delay-4 w-full">
                        <span class="w-24 h-24 mb-4 flex items-center justify-center rounded-2xl bg-amber-500/10 border border-amber-500/20">
                            <img src="<?php echo $assetPath; ?>binance.png" alt="Binance Gift Card" class="w-16 h-16 object-contain">
                        </span>
                        <span class="block text-lg font-bold text-white mb-1" data-lang="giftcard.title"><?php echo Lang::t('giftcard.title'); ?></span>
                        <span class="block text-xs text-amber-300 font-medium bg-amber-500/10 px-3 py-1 rounded-full border border-amber-500/20">USDT Gift Card</span>
                    </button>
                <?php endif; ?>

                <!-- Redeem Code Card -->
                <button type="button" onclick="openModal('modal_redeem', this)" aria-haspopup="dialog" aria-controls="modal_redeem"
                    class="glass payment-card rounded-2xl p-6 flex flex-col items-center text-center animate-fade-in animate-delay-4 w-full">
                    <span
                        class="w-24 h-24 mb-4 flex items-center justify-center rounded-2xl bg-blue-500/10 border border-blue-500/20">
                        <img src="<?php echo $assetPath; ?>code.png" alt="Code" class="w-16 h-16 object-contain">
                    </span>
                    <span class="block text-lg font-bold text-white mb-1" data-lang="deposit.redeem.title">
                        <?php echo Lang::t('deposit.redeem.title'); ?></span>
                    <span class="block text-xs text-blue-400 font-medium bg-blue-500/10 px-3 py-1 rounded-full border border-blue-500/20"
                        data-lang="deposit.redeem.instant">
                        <?php echo Lang::t('deposit.redeem.instant') ?: 'แแลกรางวัลทันที'; ?>
                    </span>
                </button>
            </div>
        </div>

    </main>

    <!-- Modals -->

    <!-- Modal: TrueMoney Angpao -->
    <div id="modal_angpao" class="fixed inset-0 z-50 hidden flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-hidden="true" tabindex="-1">
        <div class="fixed inset-0 modal-backdrop" onclick="closeModal('modal_angpao')"></div>
        <div class="glass modal-content w-full max-w-md rounded-[2.5rem] overflow-hidden relative z-10 flex flex-col">
            <div class="p-6 flex justify-between items-center relative">
                <div class="flex items-center justify-center w-full">
                    <div class="absolute left-6 text-gray-400">
                        <i class="bi bi-coin text-xl"></i>
                    </div>
                    <div class="text-center">
                        <h3 class="text-lg font-bold text-white leading-none">ซองอั่งเปา</h3>
                        <p class="text-xs text-gray-400 mt-1">True Money Wallet</p>
                    </div>
                </div>
                <button type="button" aria-label="Close" onclick="closeModal('modal_angpao')"
                    class="absolute right-6 text-gray-400 hover:text-white transition">
                    <i class="bi bi-x-lg text-xl"></i>
                </button>
            </div>
            <div class="px-8 pb-8 space-y-5 overflow-y-auto max-h-[85vh]">
                <div class="rounded-2xl overflow-hidden border border-white/5 shadow-2xl bg-black/20">
                    <img src="<?php echo $assetPath; ?>True_Money_Wallet.jpeg" alt="Instruction"
                        class="max-h-[250px] w-auto object-contain mx-auto">
                </div>

                <div class="flex justify-center">
                    <div class="bg-white/5 border border-white/10 rounded-full px-4 py-1 flex items-center gap-2">
                        <span class="text-red-500 text-xs font-bold" data-lang="deposit.angpao.fee">
                            <?php echo Lang::t('deposit.angpao.fee') ?: 'ค่าธรรมเนียม 2.9% สูงสุด 20฿'; ?>
                        </span>
                    </div>
                </div>

                <div class="space-y-2">
                    <label class="block text-sm font-bold text-gray-400 px-1" data-lang="deposit.angpao.label">
                        ลิงก์ซองอั่งเปา
                    </label>
                    <input type="text" id="angpao_url"
                        class="w-full px-6 py-4 rounded-2xl bg-white/5 border border-white/10 text-white placeholder-gray-500 focus:outline-none focus:border-purple-500/50 transition text-sm shadow-inner"
                        placeholder="https://gift.truemoney.com/campaign/?v=xxxxxxxxx">

                    <button onclick="redeemAngpao()" id="btn_redeem_angpao"
                        class="w-full py-4 rounded-2xl bg-purple-600 text-white font-bold hover:bg-purple-700 transition flex items-center justify-center gap-2 mt-4 shadow-lg shadow-purple-900/40">
                        <i class="bi bi-wallet2"></i>
                        <span data-lang="deposit.angpao.button">เติมเงิน</span>
                    </button>
                </div>
                <div id="angpao_status" class="hidden p-4 rounded-2xl text-sm font-medium"></div>
            </div>
        </div>
    </div>

    <!-- Modal: Mobile Banking (Slip) -->
    <div id="modal_slip" class="fixed inset-0 z-50 hidden flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-hidden="true" tabindex="-1">
        <div class="fixed inset-0 modal-backdrop" onclick="closeModal('modal_slip')"></div>
        <div class="glass modal-content w-full max-w-md rounded-3xl overflow-hidden relative z-10 flex flex-col">
            <div class="p-6 border-b border-white/5 flex justify-between items-center bg-white/5">
                <div class="flex items-center gap-3">
                    <div class="p-2 rounded-xl bg-emerald-500/20 border border-emerald-500/30">
                        <i class="bi bi-bank text-emerald-400"></i>
                    </div>
                    <div>
                        <h3 class="font-bold text-white" data-lang="deposit.slip.banking">
                            <?php echo Lang::t('deposit.slip.banking'); ?></h3>
                        <p class="text-xs text-emerald-400">Mobile Banking</p>
                    </div>
                </div>
                <button type="button" aria-label="Close" onclick="closeModal('modal_slip')" class="text-gray-400 hover:text-white transition">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
            <div class="p-6 space-y-6 overflow-y-auto max-h-[85vh]">
                <div
                    class="rounded-2xl overflow-hidden border border-white/5 max-h-[220px] flex items-center justify-center bg-black/20">
                    <img src="<?php echo $assetPath; ?>slip.png" alt="Slip"
                        class="h-full w-auto max-h-[220px] object-contain">
                </div>

                <div class="text-center py-2">
                    <p class="text-emerald-400 text-sm font-bold bg-emerald-500/10 inline-block px-4 py-1 rounded-full border border-emerald-500/20"
                        data-lang="deposit.slip.fee_free">
                        <?php echo Lang::t('deposit.slip.fee_free'); ?>
                    </p>
                </div>

                <div class="grid grid-cols-1 gap-4 text-center bg-white/5 p-4 rounded-2xl border border-white/5">
                    <div class="space-y-1">
                        <p class="text-[10px] text-gray-400 uppercase tracking-wider"
                            data-lang="deposit.slip.account_name_label">
                            <?php echo Lang::t('deposit.slip.account_name_label'); ?></p>
                        <p class="text-base font-bold text-emerald-400">
                            <?php echo htmlspecialchars($displayReceiver); ?></p>
                    </div>
                    <div class="grid grid-cols-2 gap-4 border-t border-white/5 pt-4">
                        <div class="space-y-1">
                            <p class="text-[10px] text-gray-400 uppercase tracking-wider"
                                data-lang="deposit.slip.bank_label"><?php echo Lang::t('deposit.slip.bank_label'); ?>
                            </p>
                            <p class="text-sm font-bold text-gray-300"><?php echo htmlspecialchars($displayBank); ?></p>
                        </div>
                        <div class="space-y-1">
                            <p class="text-[10px] text-gray-400 uppercase tracking-wider"
                                data-lang="deposit.slip.account_number_label">
                                <?php echo Lang::t('deposit.slip.account_number_label'); ?></p>
                            <div class="flex items-center justify-center gap-2">
                                <p class="text-sm font-black text-emerald-400 font-mono">
                                    <?php echo htmlspecialchars($displayAccountNum, ENT_QUOTES, 'UTF-8'); ?></p>
                                <?php if ($accountNum !== ''): ?>
                                    <button onclick='Lang.copy(<?php echo htmlspecialchars(json_encode($accountNum, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS), ENT_QUOTES, 'UTF-8'); ?>)'
                                        class="p-1 px-2 rounded-lg bg-emerald-500/10 text-emerald-400 hover:bg-emerald-500/20 transition">
                                        <i class="bi bi-files text-xs"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="space-y-3">
                    <input type="file" id="slip_file" class="hidden" accept="image/jpeg,image/png,image/webp,image/gif" onchange="verifySlip()">
                    <label for="slip_file"
                        class="w-full py-8 rounded-2xl border-2 border-dashed border-white/10 hover:border-emerald-500/50 hover:bg-emerald-500/5 transition cursor-pointer flex flex-col items-center justify-center gap-3">
                        <div class="p-3 rounded-full bg-white/5">
                            <i class="bi bi-cloud-arrow-up text-2xl text-gray-400"></i>
                        </div>
                        <div class="text-center">
                            <p class="text-xs font-bold text-white" data-lang="deposit.slip.upload_hint">
                                <?php echo Lang::t('deposit.slip.upload_hint'); ?></p>
                            <p class="text-[10px] text-gray-500 mt-1" data-lang="deposit.slip.or">
                                <?php echo Lang::t('deposit.slip.or'); ?></p>
                        </div>
                        <span class="px-6 py-2 rounded-xl bg-gray-800 text-white text-[10px] font-bold"
                            data-lang="deposit.slip.upload_btn"><?php echo Lang::t('deposit.slip.upload_btn'); ?></span>
                    </label>
                </div>

                <div id="slip_status_container" class="hidden p-4 rounded-2xl text-sm font-medium">
                    <div id="slip_status"></div>
                </div>

                <p class="text-center text-[10px] text-red-500/80 font-bold" data-lang="deposit.slip.warning_mobile">
                    <?php echo Lang::t('deposit.slip.warning_mobile') ?: 'กรุณาโอนผ่านแอปธนาคารเท่านั้น ระบบไม่รองรับการโอนด้วยทรูมันนี่'; ?>
                </p>
            </div>
        </div>
    </div>

    <!-- Modal: Binance -->
    <div id="modal_binance" class="fixed inset-0 z-50 hidden flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-hidden="true" tabindex="-1">
        <div class="fixed inset-0 modal-backdrop" onclick="closeModal('modal_binance')"></div>
        <div class="glass modal-content w-full max-w-md rounded-3xl overflow-hidden relative z-10 flex flex-col">
            <div class="p-6 border-b border-white/5 flex justify-between items-center bg-white/5">
                <div class="flex items-center gap-3">
                    <div class="p-2 rounded-xl bg-yellow-500/20 border border-yellow-500/30">
                        <i class="bi bi-currency-bitcoin text-yellow-400"></i>
                    </div>
                    <div>
                        <h3 class="font-bold text-white" data-lang="deposit.binance.title">
                            <?php echo Lang::t('deposit.binance.title'); ?></h3>
                        <p class="text-xs text-yellow-400">Binance Pay (Crypto)</p>
                    </div>
                </div>
                <button type="button" aria-label="Close" onclick="closeModal('modal_binance')" class="text-gray-400 hover:text-white transition">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
            <div class="p-6 space-y-4 overflow-y-auto max-h-[85vh]">
                <div class="bg-red-500/10 border border-red-500/20 p-4 rounded-2xl">
                    <p class="text-red-400 font-bold text-xs text-center" data-lang="deposit.binance.network_warning">
                        <?php echo Lang::t('deposit.binance.network_warning'); ?>
                    </p>
                </div>

                <div class="grid grid-cols-1 gap-2">
                    <div class="flex items-center gap-2 text-xs text-gray-400">
                        <i class="bi bi-check2-circle text-emerald-400"></i>
                        <span
                            data-lang="deposit.binance.support_usdt"><?php echo Lang::t('deposit.binance.support_usdt'); ?></span>
                    </div>
                    <div class="flex items-center gap-2 text-xs text-gray-400">
                        <i class="bi bi-diagram-2 text-blue-400"></i>
                        <span
                            data-lang="deposit.binance.support_network"><?php echo Lang::t('deposit.binance.support_network'); ?></span>
                    </div>
                    <div class="flex items-center gap-2 text-xs text-gray-400">
                        <i class="bi bi-arrow-repeat text-yellow-500"></i>
                        <span
                            data-lang="deposit.binance.support_auto_convert"><?php echo Lang::t('deposit.binance.support_auto_convert'); ?></span>
                    </div>
                    <div class="flex items-center gap-2 text-xs text-gray-400">
                        <i class="bi bi-clock text-gray-400"></i>
                        <span
                            data-lang="deposit.binance.support_wait"><?php echo Lang::t('deposit.binance.support_wait'); ?></span>
                    </div>
                </div>

                <div class="glass p-5 rounded-2xl border border-white/5 space-y-3 bg-white/5">
                    <p class="text-[10px] text-gray-400 uppercase tracking-wider font-bold"
                        data-lang="deposit.binance.wallet_label"><?php echo Lang::t('deposit.binance.wallet_label'); ?>
                    </p>
                    <div class="flex items-center gap-3">
                        <code
                            class="text-xs font-mono text-yellow-300 break-all flex-1"><?php echo htmlspecialchars($bnbSettings['wallet']); ?></code>
                        <button onclick='Lang.copy(<?php echo htmlspecialchars(json_encode($bnbSettings['wallet'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS), ENT_QUOTES, 'UTF-8'); ?>)' 
                            class="p-2 rounded-xl bg-yellow-500/20 text-yellow-400">
                            <i class="bi bi-files text-xs"></i>
                        </button>
                    </div>
                </div>

                <div class="space-y-4">
                    <div class="space-y-3">
                        <input type="text" id="binance_txid"
                            class="w-full px-5 py-4 rounded-2xl bg-white/5 border border-white/10 text-white placeholder-white/20 focus:outline-none focus:ring-2 focus:ring-yellow-500/30 transition text-sm font-mono"
                            placeholder="<?php echo Lang::t('deposit.binance.txid_placeholder'); ?>">
                        <button onclick="verifyBinance()" id="btn_verify_binance"
                            class="w-full py-4 rounded-2xl bg-yellow-500 text-black font-bold hover:bg-yellow-600 transition shadow-lg shadow-yellow-500/20 flex items-center justify-center gap-2">
                            <i class="bi bi-shield-check"></i>
                            <span
                                data-lang="deposit.binance.verify_btn"><?php echo Lang::t('deposit.binance.verify_btn'); ?></span>
                        </button>
                    </div>
                </div>

                <div id="binance_status_container" class="hidden p-4 rounded-2xl text-sm font-medium">
                    <div id="binance_status"></div>
                </div>
            </div>
        </div>
    </div>


    <?php if ($giftCardVisible): ?>
    <!-- Modal: Binance Gift Card -->
    <div id="modal_binance_giftcard" class="fixed inset-0 z-50 hidden flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-hidden="true" tabindex="-1">
        <div class="fixed inset-0 modal-backdrop" onclick="closeModal('modal_binance_giftcard')"></div>
        <div class="glass modal-content w-full max-w-md rounded-3xl overflow-hidden relative z-10 flex flex-col">
            <div class="p-6 border-b border-white/5 flex justify-between items-center bg-white/5">
                <div class="flex items-center gap-3">
                    <div class="p-2 rounded-xl bg-amber-500/20 border border-amber-500/30">
                        <i class="bi bi-gift text-amber-300"></i>
                    </div>
                    <div>
                        <h3 class="font-bold text-white" data-lang="giftcard.title"><?php echo Lang::t('giftcard.title'); ?></h3>
                        <p class="text-xs text-amber-300">Binance USDT Gift Card</p>
                    </div>
                </div>
                <button type="button" onclick="closeModal('modal_binance_giftcard')" class="text-gray-400 hover:text-white transition" aria-label="<?php echo htmlspecialchars(Lang::t('common.close'), ENT_QUOTES, 'UTF-8'); ?>">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
            <div class="p-6 space-y-5 overflow-y-auto max-h-[85vh]">
                <div class="bg-amber-500/10 border border-amber-500/20 p-4 rounded-2xl space-y-2">
                    <p class="text-xs text-amber-200 font-semibold" data-lang="giftcard.usdt_only"><?php echo Lang::t('giftcard.usdt_only'); ?></p>
                    <p class="text-[11px] leading-relaxed text-gray-300" data-lang="giftcard.code_only_warning"><?php echo Lang::t('giftcard.code_only_warning'); ?></p>
                </div>

                <div class="space-y-2">
                    <label for="binance_giftcard_code" class="block text-xs font-bold text-gray-400 px-1 uppercase tracking-wider" data-lang="giftcard.code_label"><?php echo Lang::t('giftcard.code_label'); ?></label>
                    <div class="relative">
                        <input type="password" id="binance_giftcard_code" maxlength="32" inputmode="text"
                            autocomplete="off" autocapitalize="characters" spellcheck="false"
                            class="w-full px-5 py-4 pr-14 rounded-2xl bg-white/5 border border-white/10 text-white placeholder-white/20 focus:outline-none focus:ring-2 focus:ring-amber-500/30 transition text-sm font-mono tracking-widest uppercase"
                            placeholder="XXXXXXXXXXXXXXXX">
                        <button type="button" onclick="toggleGiftCardCode()" class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 hover:text-white" aria-label="<?php echo htmlspecialchars(Lang::t('giftcard.toggle_code'), ENT_QUOTES, 'UTF-8'); ?>">
                            <i id="giftcard_code_icon" class="bi bi-eye"></i>
                        </button>
                    </div>
                    <p class="text-[11px] text-gray-500" data-lang="giftcard.code_hint"><?php echo Lang::t('giftcard.code_hint'); ?></p>
                </div>

                <button type="button" onclick="redeemBinanceGiftCard()" id="btn_redeem_binance_giftcard"
                    class="w-full py-4 rounded-2xl bg-amber-400 text-black font-bold hover:bg-amber-300 disabled:opacity-50 disabled:cursor-not-allowed transition shadow-lg shadow-amber-500/20 flex items-center justify-center gap-2">
                    <i class="bi bi-shield-check"></i>
                    <span data-lang="giftcard.redeem_button"><?php echo Lang::t('giftcard.redeem_button'); ?></span>
                </button>

                <div id="giftcard_status_container" class="hidden p-4 rounded-2xl text-sm font-medium">
                    <div id="giftcard_status"></div>
                </div>

                <div class="bg-red-500/10 border border-red-500/20 p-4 rounded-2xl">
                    <p class="text-[11px] leading-relaxed text-red-200" data-lang="giftcard.secret_warning"><?php echo Lang::t('giftcard.secret_warning'); ?></p>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Modal: Redeem Code -->
    <div id="modal_redeem" class="fixed inset-0 z-50 hidden flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-hidden="true" tabindex="-1">
        <div class="fixed inset-0 modal-backdrop" onclick="closeModal('modal_redeem')"></div>
        <div class="glass modal-content w-full max-w-md rounded-3xl overflow-hidden relative z-10 flex flex-col">
            <div class="p-6 border-b border-white/5 flex justify-between items-center bg-white/5">
                <div class="flex items-center gap-3">
                    <div class="p-2 rounded-xl bg-blue-500/20 border border-blue-500/30">
                        <i class="bi bi-ticket-perforated text-blue-400"></i>
                    </div>
                    <div>
                        <h3 class="font-bold text-white" data-lang="deposit.redeem.title">
                            <?php echo Lang::t('deposit.redeem.title'); ?></h3>
                        <p class="text-xs text-blue-400">Coupon / Gift Card</p>
                    </div>
                </div>
                <button type="button" aria-label="Close" onclick="closeModal('modal_redeem')" class="text-gray-400 hover:text-white transition">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
            <div class="p-6 space-y-4 overflow-y-auto max-h-[85vh]">
                <div
                    class="rounded-2xl overflow-hidden border border-white/5 max-h-[150px] flex items-center justify-center bg-black/20">
                    <img src="<?php echo $assetPath; ?>code.png" alt="Code"
                        class="h-full w-auto max-h-[150px] object-contain p-4 opacity-50">
                </div>

                <form method="POST" class="space-y-4">
                    <?php echo csrfField(); ?>
                    <div class="space-y-3">
                        <label class="block text-xs font-bold text-gray-400 px-1 uppercase tracking-wider"
                            data-lang="deposit.redeem.label">
                            <?php echo Lang::t('deposit.redeem.label') ?: 'รหัสเติมเงิน'; ?>
                        </label>
                        <input type="text" name="redeem_code"
                            class="w-full px-5 py-4 rounded-2xl bg-white/5 border border-white/10 text-white placeholder-white/20 focus:outline-none focus:ring-2 focus:ring-blue-500/30 transition text-sm font-mono tracking-widest uppercase"
                            placeholder="<?php echo Lang::t('deposit.redeem.placeholder'); ?>" required>
                        <button type="submit" name="redeem_submit" value="1"
                            class="w-full py-4 rounded-2xl bg-blue-500 text-white font-bold hover:bg-blue-600 transition shadow-lg shadow-blue-500/20 flex items-center justify-center gap-2">
                            <i class="bi bi-check-circle"></i>
                            <span data-lang="deposit.redeem.btn"><?php echo Lang::t('deposit.redeem.btn'); ?></span>
                        </button>
                    </div>
                </form>

                <div class="bg-blue-500/10 border border-blue-500/20 p-4 rounded-2xl text-center">
                    <p class="text-[10px] text-blue-300 font-medium" data-lang="deposit.redeem.warning">
                        <?php echo Lang::t('deposit.redeem.warning'); ?>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <script>
        let activeDepositModal = null;
        let depositModalReturnFocus = null;

        function getModalFocusable(modal) {
            return Array.from(modal.querySelectorAll('button:not([disabled]), a[href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'))
                .filter((node) => node.offsetParent !== null);
        }

        function openModal(id, trigger = null) {
            const modal = document.getElementById(id);
            if (!modal) return;
            if (activeDepositModal && activeDepositModal !== modal) closeModal(activeDepositModal.id, false);
            depositModalReturnFocus = trigger instanceof HTMLElement ? trigger : document.activeElement;
            activeDepositModal = modal;
            modal.classList.remove('hidden');
            modal.setAttribute('aria-hidden', 'false');
            window.requestAnimationFrame(() => {
                modal.classList.add('modal-open');
                document.body.style.overflow = 'hidden';
                const focusable = getModalFocusable(modal);
                (focusable[0] || modal).focus({preventScroll: true});
            });
        }

        function closeModal(id, restoreFocus = true) {
            const modal = document.getElementById(id);
            if (!modal) return;
            modal.classList.remove('modal-open');
            modal.setAttribute('aria-hidden', 'true');
            window.setTimeout(() => {
                modal.classList.add('hidden');
                if (activeDepositModal === modal) activeDepositModal = null;
                document.body.style.overflow = activeDepositModal ? 'hidden' : '';
                if (restoreFocus && depositModalReturnFocus instanceof HTMLElement && depositModalReturnFocus.isConnected) {
                    depositModalReturnFocus.focus({preventScroll: true});
                }
            }, 300);
        }

        document.addEventListener('keydown', (event) => {
            const modal = activeDepositModal;
            if (!modal || modal.classList.contains('hidden')) return;
            if (event.key === 'Escape') {
                event.preventDefault();
                closeModal(modal.id);
                return;
            }
            if (event.key !== 'Tab') return;
            const focusable = getModalFocusable(modal);
            if (focusable.length === 0) {
                event.preventDefault();
                modal.focus();
                return;
            }
            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        });

        function userFacingDepositError(kind) {
            if (kind === 'invalid_account') return Lang.t('deposit.error.invalid_account');
            if (kind === 'invalid_txid') return Lang.t('deposit.error.invalid_txid');
            if (kind === 'server') return Lang.t('deposit.error.server');
            if (kind === 'used_slip') return Lang.t('deposit.error.used_slip');
            if (kind === 'used_txid') return Lang.t('deposit.error.used_txid');
            return Lang.t('deposit.error.failed');
        }

        function setDepositStatus(element, iconClass, textClass, message) {
            element.replaceChildren();
            const icon = document.createElement('i');
            icon.className = iconClass;
            const text = document.createElement('span');
            text.className = textClass;
            text.textContent = String(message || '');
            element.append(icon, text);
        }

        function safeApiMessage(payload, fallback) {
            if (!payload || typeof payload.message !== 'string') return fallback;
            const message = payload.message.trim().replace(/[\u0000-\u001F\u007F]/g, ' ');
            return message && message.length <= 240 ? message : fallback;
        }


        function depositCreditSummary(payload, baseAmount) {
            const base = Number(baseAmount || 0);
            const bonus = Math.max(0, Number(payload && payload.bonus_amount || 0));
            const totalRaw = Number(payload && payload.total_credited);
            const total = Number.isFinite(totalRaw) && totalRaw > 0 ? totalRaw : base + bonus;
            const totalText = Lang.formatCurrency(total);
            if (bonus > 0) {
                const bonusLabel = Lang.t('ranking.bonus_received') || 'Rank bonus';
                return totalText + ' (' + bonusLabel + ' +' + Lang.formatCurrency(bonus) + ')';
            }
            return totalText;
        }

        function classifySlipError(payload) {
            const msg = String(payload?.message || '').toLowerCase();
            if (msg.includes('ใช้งานไปแล้ว') || msg.includes('used') || msg.includes('ซ้ำ') || msg.includes('already')) return 'used_slip';
            if (msg.includes('บัญชี') || msg.includes('ปลายทาง') || msg.includes('account') || msg.includes('receiver')) return 'invalid_account';
            if (msg.includes('เกิดข้อผิดพลาดในระบบ') || msg.includes('api') || msg.includes('server') || msg.includes('system')) return 'server';
            return 'failed';
        }

        function classifyBinanceError(payload) {
            const msg = String(payload?.message || '').toLowerCase();
            if (msg.includes('ใช้งานไปแล้ว') || msg.includes('used') || msg.includes('claimed') || msg.includes('ซ้ำ')) return 'used_txid';
            if (msg.includes('transaction id') || msg.includes('txid') || msg.includes('enter') || msg.includes('กรุณากรอก')) return 'invalid_txid';
            if (msg.includes('disabled') || msg.includes('enabled') || msg.includes('ระบบ') || msg.includes('api') || msg.includes('server')) return 'server';
            return 'failed';
        }

        // Bridge PHP settings to Lang.js
        document.addEventListener('DOMContentLoaded', function () {
            Lang.translations['deposit.receiver_name'] = {
                th: <?php echo json_encode($receiverTh, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
                en: <?php echo json_encode($receiverEn, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
            };
            Lang.updatePage(); // Initial apply
        });

        // Slip Verification
        let slipVerificationBusy = false;
        const SLIP_ATTEMPT_STORAGE_KEY = 'sakazuki_slip_attempt_v2';
        const SLIP_UPLOAD_TIMEOUT_MS = 15000;
        const SLIP_SERVER_TIMEOUT_MS = 54000;
        const SLIP_TOTAL_BUDGET_MS = 60000;
        const SLIP_RECOVERY_WINDOW_MS = 18000;
        const SLIP_IMAGE_TARGET_BYTES = 1500 * 1024;
        const SLIP_IMAGE_MAX_DIMENSION = 2000;

        function createSlipAttemptId() {
            if (window.crypto && typeof window.crypto.randomUUID === 'function') {
                return window.crypto.randomUUID();
            }
            const bytes = new Uint8Array(16);
            if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
                window.crypto.getRandomValues(bytes);
            } else {
                for (let i = 0; i < bytes.length; i++) bytes[i] = Math.floor(Math.random() * 256);
            }
            bytes[6] = (bytes[6] & 0x0f) | 0x40;
            bytes[8] = (bytes[8] & 0x3f) | 0x80;
            const hex = Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
            return hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-' + hex.slice(12, 16)
                + '-' + hex.slice(16, 20) + '-' + hex.slice(20);
        }

        function readStoredSlipAttempt() {
            try {
                const raw = sessionStorage.getItem(SLIP_ATTEMPT_STORAGE_KEY);
                if (!raw) return null;
                const value = JSON.parse(raw);
                if (!value || !/^[a-f0-9-]{36}$/i.test(String(value.id || ''))) return null;
                if (Date.now() - Number(value.createdAt || 0) > 15 * 60 * 1000) {
                    sessionStorage.removeItem(SLIP_ATTEMPT_STORAGE_KEY);
                    return null;
                }
                return value;
            } catch (e) {
                return null;
            }
        }

        function saveStoredSlipAttempt(attemptId, clientHash, uploadCompleted) {
            try {
                const previous = readStoredSlipAttempt();
                const sameAttempt = !!(previous && String(previous.id || '') === String(attemptId || ''));
                sessionStorage.setItem(SLIP_ATTEMPT_STORAGE_KEY, JSON.stringify({
                    id: String(attemptId || ''),
                    clientHash: String(clientHash || (sameAttempt ? previous?.clientHash : '') || ''),
                    uploadCompleted: !!uploadCompleted,
                    createdAt: sameAttempt ? Number(previous.createdAt || Date.now()) : Date.now(),
                    updatedAt: Date.now()
                }));
            } catch (e) {}
        }

        function clearStoredSlipAttempt() {
            try { sessionStorage.removeItem(SLIP_ATTEMPT_STORAGE_KEY); } catch (e) {}
        }

        async function sha256File(file) {
            if (!window.crypto || !window.crypto.subtle || !file || typeof file.arrayBuffer !== 'function') return '';
            try {
                const digest = await window.crypto.subtle.digest('SHA-256', await file.arrayBuffer());
                return Array.from(new Uint8Array(digest), b => b.toString(16).padStart(2, '0')).join('');
            } catch (e) {
                return '';
            }
        }

        async function detectSlipQrPayload(file) {
            // Chrome/Android and some modern browsers expose the native Barcode
            // Detector API. EasySlip documents QR payload verification as its
            // fastest bank-slip path because no server-side image processing is
            // required. This is only a performance hint; the server still uploads
            // and hashes the original image and falls back safely when unsupported.
            if (!file || typeof window.BarcodeDetector !== 'function') return '';
            let supported = [];
            try {
                if (typeof window.BarcodeDetector.getSupportedFormats === 'function') {
                    supported = await window.BarcodeDetector.getSupportedFormats();
                    if (Array.isArray(supported) && !supported.includes('qr_code')) return '';
                }
                const detector = new window.BarcodeDetector({ formats: ['qr_code'] });
                let bitmap = null;
                try {
                    if (typeof createImageBitmap === 'function') bitmap = await createImageBitmap(file);
                    if (!bitmap) return '';
                    const codes = await detector.detect(bitmap);
                    for (const code of Array.isArray(codes) ? codes : []) {
                        const raw = String(code?.rawValue || '').trim();
                        if (raw.length >= 1 && raw.length <= 128 && /^[\x21-\x7E]+$/.test(raw)) return raw;
                    }
                } finally {
                    try { if (bitmap && typeof bitmap.close === 'function') bitmap.close(); } catch (e) {}
                }
            } catch (e) {
                return '';
            }
            return '';
        }

        function canvasToBlob(canvas, type, quality) {
            return new Promise(resolve => {
                if (!canvas || typeof canvas.toBlob !== 'function') return resolve(null);
                canvas.toBlob(blob => resolve(blob || null), type, quality);
            });
        }

        async function loadSlipImageSource(file) {
            if (typeof createImageBitmap === 'function') {
                try {
                    const bitmap = await createImageBitmap(file);
                    return { source: bitmap, width: bitmap.width, height: bitmap.height, close: () => bitmap.close && bitmap.close() };
                } catch (e) {}
            }
            return new Promise((resolve, reject) => {
                const url = URL.createObjectURL(file);
                const img = new Image();
                img.onload = () => resolve({
                    source: img,
                    width: img.naturalWidth || img.width,
                    height: img.naturalHeight || img.height,
                    close: () => URL.revokeObjectURL(url)
                });
                img.onerror = () => {
                    URL.revokeObjectURL(url);
                    reject(new Error('image_decode_failed'));
                };
                img.src = url;
            });
        }

        async function prepareSlipUploadFile(file) {
            const allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
            const type = String(file?.type || '').toLowerCase();
            if (!file || !allowed.includes(type)) throw new Error('unsupported_image');
            if (file.size < 1 || file.size > 4 * 1024 * 1024) throw new Error('invalid_size');

            // Small files already upload quickly. Do not recompress them because
            // preserving the original QR pixels is better than saving a few KB.
            if (file.size <= SLIP_IMAGE_TARGET_BYTES) return file;

            let loaded;
            try {
                loaded = await loadSlipImageSource(file);
            } catch (e) {
                // If the browser cannot decode a provider-supported image, keep
                // the original. The server still performs authoritative checks.
                return file;
            }

            try {
                let maxDimension = SLIP_IMAGE_MAX_DIMENSION;
                const qualities = [0.92, 0.88, 0.84];
                let bestBlob = null;
                for (let round = 0; round < qualities.length; round++) {
                    const scale = Math.min(1, maxDimension / Math.max(loaded.width, loaded.height));
                    const width = Math.max(1, Math.round(loaded.width * scale));
                    const height = Math.max(1, Math.round(loaded.height * scale));
                    const canvas = document.createElement('canvas');
                    canvas.width = width;
                    canvas.height = height;
                    const ctx = canvas.getContext('2d', { alpha: false });
                    if (!ctx) break;
                    ctx.fillStyle = '#ffffff';
                    ctx.fillRect(0, 0, width, height);
                    ctx.drawImage(loaded.source, 0, 0, width, height);
                    const blob = await canvasToBlob(canvas, 'image/jpeg', qualities[round]);
                    canvas.width = 1;
                    canvas.height = 1;
                    if (blob && (!bestBlob || blob.size < bestBlob.size)) bestBlob = blob;
                    if (blob && blob.size <= SLIP_IMAGE_TARGET_BYTES) break;
                    maxDimension = Math.max(1300, Math.round(maxDimension * 0.86));
                }
                if (bestBlob && bestBlob.size > 0 && bestBlob.size < file.size && bestBlob.size <= 4 * 1024 * 1024) {
                    const baseName = String(file.name || 'slip').replace(/\.[^.]+$/, '').slice(0, 80) || 'slip';
                    return new File([bestBlob], baseName + '.jpg', { type: 'image/jpeg', lastModified: file.lastModified || Date.now() });
                }
                return file;
            } finally {
                try { loaded.close(); } catch (e) {}
            }
        }

        function showSlipVerificationResult(data, input, statusCont, status) {
            if (data && data.success) {
                clearStoredSlipAttempt();
                statusCont.className = 'mt-4 p-3 rounded-lg border border-green-500/50 bg-green-900/20 text-left';
                const creditSummary = depositCreditSummary(data, data.amount);
                status.innerHTML = '<i class="bi bi-check-circle-fill mr-2 text-green-400"></i><span class="text-green-400">'
                    + (Lang.t('deposit.status.success') || 'Deposit successful') + ' +' + creditSummary + '</span>';
                input.value = '';
                input.disabled = false;
                slipVerificationBusy = false;
                Swal.fire({
                    icon: 'success',
                    title: Lang.t('common.success') || 'Success',
                    text: (Lang.t('deposit.status.success') || 'Deposit successful') + ': ' + creditSummary,
                    background: '#141418', color: '#fff', confirmButtonColor: '#3b82f6'
                }).then(() => location.reload());
                return true;
            }

            if (data && data.pending) {
                statusCont.className = 'mt-4 p-3 rounded-lg border border-yellow-500/50 bg-yellow-900/20 text-left';
                setDepositStatus(
                    status,
                    'bi bi-hourglass-split animate-spin mr-2 text-yellow-400',
                    'text-yellow-300',
                    safeApiMessage(data, 'ระบบกำลังตรวจสอบรายการนี้ กรุณาอย่าส่งสลิปซ้ำทันที')
                );
                return false;
            }

            if (data && data.retryable) {
                // Preserve the durable attempt in sessionStorage. The next upload
                // of the same image reuses the existing provider snapshot/cooldown
                // instead of creating a client-side retry storm.
                const retryAfter = Math.max(0, Number(data.retry_after_seconds || 0));
                const baseMessage = safeApiMessage(data, 'ระบบภายนอกยังไม่พร้อม รายการนี้ยังไม่มีการเติมเงิน');
                const suffix = retryAfter > 0 && !/\d+\s*วินาที/.test(baseMessage)
                    ? ' ลองใหม่ได้ในประมาณ ' + Math.ceil(retryAfter) + ' วินาที' : '';
                statusCont.className = 'mt-4 p-3 rounded-lg border border-yellow-500/50 bg-yellow-900/20 text-left';
                setDepositStatus(
                    status,
                    'bi bi-clock-history mr-2 text-yellow-400',
                    'text-yellow-300',
                    baseMessage + suffix
                );
                input.disabled = false;
                input.value = '';
                slipVerificationBusy = false;
                return true;
            }

            clearStoredSlipAttempt();
            statusCont.className = 'mt-4 p-3 rounded-lg border border-red-500/50 bg-red-900/20 text-left';
            const kind = classifySlipError(data || {});
            setDepositStatus(
                status,
                'bi bi-x-circle-fill mr-2 text-red-400',
                'text-red-400',
                safeApiMessage(data, userFacingDepositError(kind))
            );
            input.disabled = false;
            input.value = '';
            slipVerificationBusy = false;
            return true;
        }

        async function fetchSlipVerificationStatus(attemptId, slipHash = '') {
            const formData = new FormData();
            formData.append('action', 'status');
            formData.append('attempt_id', attemptId);
            if (/^[a-f0-9]{64}$/i.test(String(slipHash || ''))) formData.append('slip_hash', String(slipHash).toLowerCase());
            formData.append('csrf_token', '<?php echo getCsrfToken(); ?>');
            const controller = typeof AbortController === 'function' ? new AbortController() : null;
            const timeout = controller ? setTimeout(() => controller.abort(), 4000) : null;
            try {
                const response = await fetch('../verify_slip.php', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': '<?php echo getCsrfToken(); ?>' },
                    body: formData,
                    signal: controller ? controller.signal : undefined,
                    cache: 'no-store'
                });
                const text = await response.text();
                try { return JSON.parse(text); }
                catch (e) { return { success: false, pending: true, code: 'invalid_status_response' }; }
            } catch (e) {
                return { success: false, pending: true, code: 'status_network_error' };
            } finally {
                if (timeout) clearTimeout(timeout);
            }
        }

        async function pollSlipVerificationStatus(attemptId, input, statusCont, status, deadlineMs, clientHash = '') {
            let lastData = null;
            let notFoundCount = 0;
            const localDeadline = Math.min(Number(deadlineMs || Infinity), Date.now() + SLIP_RECOVERY_WINDOW_MS);
            while (Date.now() < localDeadline) {
                const remainingBeforeSleep = localDeadline - Date.now();
                if (remainingBeforeSleep <= 0) break;
                await new Promise(resolve => setTimeout(resolve, Math.min(900, remainingBeforeSleep)));
                if (Date.now() >= localDeadline) break;
                const data = await fetchSlipVerificationStatus(attemptId, clientHash);
                lastData = data;
                const canonicalAttemptId = String(data?.attempt_id || '');
                if (/^[a-f0-9-]{36}$/i.test(canonicalAttemptId) && canonicalAttemptId !== attemptId) {
                    attemptId = canonicalAttemptId;
                    saveStoredSlipAttempt(attemptId, clientHash, true);
                }
                if (String(data?.code || '') === 'attempt_not_found') {
                    notFoundCount++;
                    if (notFoundCount >= 2) break;
                    continue;
                }
                notFoundCount = 0;
                if (showSlipVerificationResult(data, input, statusCont, status)) return true;
            }

            input.disabled = false;
            slipVerificationBusy = false;
            if (String(lastData?.code || '') === 'attempt_not_found') {
                statusCont.className = 'mt-4 p-3 rounded-lg border border-yellow-500/50 bg-yellow-900/20 text-left';
                setDepositStatus(
                    status,
                    'bi bi-arrow-repeat mr-2 text-yellow-400',
                    'text-yellow-300',
                    'ยังเชื่อมรายการนี้กับสถานะบนเซิร์ฟเวอร์ไม่ได้ กรุณาใช้สลิปเดิมส่งใหม่ ระบบจะตรวจจากสลิปเดิมก่อนและจะไม่เติมยอดซ้ำ'
                );
                return false;
            }

            statusCont.className = 'mt-4 p-3 rounded-lg border border-yellow-500/50 bg-yellow-900/20 text-left';
            setDepositStatus(
                status,
                'bi bi-clock-history mr-2 text-yellow-400',
                'text-yellow-300',
                'ยังยืนยันผลไม่ได้ภายในขีดจำกัดประมาณ 1 นาที รายการนี้ยังไม่มีการเติมเงิน กรุณาใช้สลิปเดิมส่งอีกครั้ง ระบบจะใช้รายการเดิมและตรวจป้องกันยอดซ้ำให้'
            );
            return false;
        }

        function submitSlipRequest(formData, attemptId, clientHash, overallDeadline) {
            return new Promise((resolve, reject) => {
                const xhr = new XMLHttpRequest();
                let settled = false;
                let uploadCompleted = false;
                let abortReason = '';
                let uploadTimer = null;
                let serverTimer = null;

                const finishReject = reason => {
                    if (settled) return;
                    settled = true;
                    if (uploadTimer) clearTimeout(uploadTimer);
                    if (serverTimer) clearTimeout(serverTimer);
                    reject({ reason, uploadCompleted });
                };
                const finishResolve = data => {
                    if (settled) return;
                    settled = true;
                    if (uploadTimer) clearTimeout(uploadTimer);
                    if (serverTimer) clearTimeout(serverTimer);
                    resolve({ data, uploadCompleted });
                };

                xhr.open('POST', '../verify_slip.php', true);
                xhr.setRequestHeader('X-CSRF-TOKEN', '<?php echo getCsrfToken(); ?>');
                xhr.setRequestHeader('Cache-Control', 'no-store');

                uploadTimer = setTimeout(() => {
                    abortReason = 'upload_timeout';
                    xhr.abort();
                }, Math.min(SLIP_UPLOAD_TIMEOUT_MS, Math.max(1000, overallDeadline - Date.now())));

                xhr.upload.onload = () => {
                    uploadCompleted = true;
                    saveStoredSlipAttempt(attemptId, clientHash, true);
                    if (uploadTimer) clearTimeout(uploadTimer);
                    const liveStatus = document.getElementById('slip_status');
                    if (liveStatus) {
                        setDepositStatus(
                            liveStatus,
                            'bi bi-shield-check mr-2 text-yellow-400',
                            'text-yellow-300',
                            'อัปโหลดสลิปครบแล้ว กำลังรอผลตรวจจากธนาคาร ระบบจะรอได้สูงสุดประมาณ 1 นาทีและจะไม่ส่งสลิปซ้ำอัตโนมัติ...'
                        );
                    }
                    const remaining = Math.max(1000, overallDeadline - Date.now());
                    serverTimer = setTimeout(() => {
                        abortReason = 'server_timeout';
                        xhr.abort();
                    }, Math.min(SLIP_SERVER_TIMEOUT_MS, remaining));
                };

                xhr.onload = () => {
                    let data;
                    try { data = JSON.parse(xhr.responseText || ''); }
                    catch (e) { data = { success: false, pending: true, attempt_id: attemptId, code: 'invalid_verify_response' }; }
                    finishResolve(data);
                };
                xhr.onerror = () => finishReject(uploadCompleted ? 'network_after_upload' : 'network_during_upload');
                xhr.onabort = () => finishReject(abortReason || (uploadCompleted ? 'aborted_after_upload' : 'aborted_during_upload'));
                xhr.send(formData);
            });
        }

        async function verifySlip() {
            const input = document.getElementById('slip_file');
            const originalFile = input.files[0];
            if (!originalFile || slipVerificationBusy) return;
            const startedAt = Date.now();
            const overallDeadline = startedAt + SLIP_TOTAL_BUDGET_MS;
            const statusCont = document.getElementById('slip_status_container');
            const status = document.getElementById('slip_status');

            slipVerificationBusy = true;
            input.disabled = true;
            statusCont.classList.remove('hidden');
            statusCont.className = 'mt-4 p-3 rounded-lg border border-yellow-500/50 bg-yellow-900/20 text-left';
            setDepositStatus(status, 'bi bi-image mr-2 text-yellow-400', 'text-yellow-300', 'กำลังเตรียมรูปสลิปให้ส่งได้เร็วขึ้น...');

            let file;
            try {
                file = await prepareSlipUploadFile(originalFile);
            } catch (e) {
                clearStoredSlipAttempt();
                input.disabled = false;
                input.value = '';
                slipVerificationBusy = false;
                const message = e && e.message === 'unsupported_image'
                    ? 'รองรับเฉพาะรูป JPEG, PNG, GIF และ WebP'
                    : 'รูปภาพต้องมีขนาดไม่เกิน 4MB';
                Swal.fire({ icon: 'error', text: message, background: '#141418', color: '#fff' });
                return;
            }

            const [clientHash, qrPayload] = await Promise.all([
                sha256File(file),
                detectSlipQrPayload(file)
            ]);
            const storedAttempt = readStoredSlipAttempt();
            const canReuseAttempt = !!(storedAttempt && clientHash && storedAttempt.clientHash === clientHash);
            const attemptId = canReuseAttempt ? String(storedAttempt.id) : createSlipAttemptId();
            saveStoredSlipAttempt(attemptId, clientHash, false);

            if (Date.now() >= overallDeadline - 3000) {
                input.disabled = false;
                slipVerificationBusy = false;
                setDepositStatus(status, 'bi bi-x-circle-fill mr-2 text-red-400', 'text-red-400', 'โทรศัพท์ใช้เวลาเตรียมรูปนานเกินไป กรุณาใช้สลิปเดิมลองใหม่');
                return;
            }

            setDepositStatus(status, 'bi bi-cloud-arrow-up mr-2 text-yellow-400', 'text-yellow-300', 'กำลังอัปโหลดสลิป...');
            const formData = new FormData();
            formData.append('action', 'verify');
            formData.append('attempt_id', attemptId);
            formData.append('slip_image', file, file.name || 'slip.jpg');
            if (qrPayload) formData.append('slip_qr_payload', qrPayload);
            formData.append('csrf_token', '<?php echo getCsrfToken(); ?>');

            try {
                const result = await submitSlipRequest(formData, attemptId, clientHash, overallDeadline);
                const data = result.data || {};
                const finalAttemptId = String(data.attempt_id || attemptId);
                if (finalAttemptId !== attemptId) saveStoredSlipAttempt(finalAttemptId, clientHash, true);
                if (showSlipVerificationResult(data, input, statusCont, status)) return;
                await pollSlipVerificationStatus(finalAttemptId, input, statusCont, status, overallDeadline, clientHash);
            } catch (failure) {
                const uploadCompleted = !!failure?.uploadCompleted;
                if (!uploadCompleted) {
                    setDepositStatus(status, 'bi bi-wifi-off mr-2 text-yellow-400', 'text-yellow-300', 'การเชื่อมต่อขาดระหว่างอัปโหลด กำลังเช็กว่าเซิร์ฟเวอร์ได้รับสลิปหรือยัง...');
                } else {
                    setDepositStatus(status, 'bi bi-hourglass-split mr-2 text-yellow-400', 'text-yellow-300', 'อัปโหลดสลิปครบแล้ว แต่การเชื่อมต่อสะดุด กำลังเช็กผลรายการเดิม...');
                }
                await pollSlipVerificationStatus(attemptId, input, statusCont, status, overallDeadline, clientHash);
            }
        }

        async function recoverStoredSlipAttempt() {
            if (slipVerificationBusy) return;
            const stored = readStoredSlipAttempt();
            if (!stored) return;
            const input = document.getElementById('slip_file');
            const statusCont = document.getElementById('slip_status_container');
            const status = document.getElementById('slip_status');
            if (!input || !statusCont || !status) return;

            slipVerificationBusy = true;
            input.disabled = true;
            statusCont.classList.remove('hidden');
            statusCont.className = 'mt-4 p-3 rounded-lg border border-yellow-500/50 bg-yellow-900/20 text-left';
            setDepositStatus(status, 'bi bi-arrow-repeat mr-2 text-yellow-400', 'text-yellow-300', 'กำลังตรวจสถานะสลิปเดิมก่อน เพื่อป้องกันการส่งรายการซ้ำ...');
            const deadline = Date.now() + SLIP_RECOVERY_WINDOW_MS;
            await pollSlipVerificationStatus(String(stored.id), input, statusCont, status, deadline, String(stored.clientHash || ''));
        }

        window.addEventListener('online', () => { recoverStoredSlipAttempt(); });
        async function kickSlipHistoryMaintenance() {
            if (navigator.onLine === false) return;
            const formData = new FormData();
            formData.append('csrf_token', '<?php echo getCsrfToken(); ?>');
            try {
                await fetch('../slip_maintenance.php', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': '<?php echo getCsrfToken(); ?>' },
                    body: formData,
                    cache: 'no-store',
                    keepalive: true
                });
            } catch (e) {
                // Maintenance is opportunistic. Verification remains fail-closed
                // and the hosting cron/admin drain are the authoritative runners.
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            if (navigator.onLine !== false) {
                recoverStoredSlipAttempt();
                kickSlipHistoryMaintenance();
            }
        });

        // Binance USDT Verification
        function verifyBinance() {
            const input = document.getElementById('binance_txid');
            const txId = input.value.trim();
            if (!txId) {
                Swal.fire({ icon: 'warning', title: Lang.t('deposit.error.invalid_txid'), background: '#141418', color: '#fff', confirmButtonColor: '#3b82f6' });
                return;
            }
            const btn = document.getElementById('btn_verify_binance');
            const statusCont = document.getElementById('binance_status_container');
            const status = document.getElementById('binance_status');
            const originalBtnHtml = btn.innerHTML;

            btn.disabled = true;
            btn.innerHTML = '<i class="bi bi-hourglass-split animate-spin"></i> ' + (Lang.t('common.verifying') || 'Verifying...');

            statusCont.classList.remove('hidden');
            statusCont.className = 'mt-4 p-3 rounded-lg border border-yellow-500/50 bg-yellow-900/20 text-left';
            status.innerHTML = '<i class="bi bi-hourglass-split animate-spin mr-2 text-yellow-400"></i><span class="text-yellow-400">' + (Lang.t('deposit.binance.verifying') || 'Verifying...') + '</span>';

            const formData = new FormData();
            formData.append('tx_id', txId);
            formData.append('csrf_token', '<?php echo getCsrfToken(); ?>');
            fetch('../verify_binance.php', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '<?php echo getCsrfToken(); ?>'
                },
                body: formData
            })
                .then(async response => {
                    const text = await response.text();
                    try { return JSON.parse(text); }
                    catch (e) { throw new Error(userFacingDepositError('server')); }
                })
                .then(data => {
                    if (data.success) {
                        statusCont.className = 'mt-4 p-3 rounded-lg border border-green-500/50 bg-green-900/20 text-left';
                        const creditSummary = depositCreditSummary(data, data.amount_thb);
                        status.innerHTML = '<i class="bi bi-check-circle-fill mr-2 text-green-400"></i><span class="text-green-400">' + (Lang.t('deposit.status.success') || 'Deposit successful') + ' +' + creditSummary + '</span>';
                        input.value = '';
                        Swal.fire({
                            icon: 'success', title: Lang.t('common.success') || 'Success',
                            text: data.amount_usdt + ' USDT ≈ ' + creditSummary,
                            background: '#141418', color: '#fff', confirmButtonColor: '#3b82f6'
                        }).then(() => location.reload());
                    } else {
                        statusCont.className = 'mt-4 p-3 rounded-lg border border-red-500/50 bg-red-900/20 text-left';
                        const kind = classifyBinanceError(data);
                        const fallbackMessage = userFacingDepositError(kind);
                        setDepositStatus(
                            status,
                            'bi bi-x-circle-fill mr-2 text-red-400',
                            'text-red-400',
                            safeApiMessage(data, fallbackMessage)
                        );
                        btn.disabled = false; btn.innerHTML = originalBtnHtml;
                    }
                })
                .catch(err => {
                    statusCont.className = 'mt-4 p-3 rounded-lg border border-red-500/50 bg-red-900/20 text-left';
                    status.innerHTML = '<i class="bi bi-x-circle-fill mr-2 text-red-400"></i><span class="text-red-400">' + userFacingDepositError('server') + '</span>';
                    btn.disabled = false; btn.innerHTML = originalBtnHtml;
                });
        }

        function toggleGiftCardCode() {
            const input = document.getElementById('binance_giftcard_code');
            const icon = document.getElementById('giftcard_code_icon');
            if (!input || !icon) return;
            const reveal = input.type === 'password';
            input.type = reveal ? 'text' : 'password';
            icon.className = reveal ? 'bi bi-eye-slash' : 'bi bi-eye';
        }

        function redeemBinanceGiftCard() {
            const input = document.getElementById('binance_giftcard_code');
            const button = document.getElementById('btn_redeem_binance_giftcard');
            const statusCont = document.getElementById('giftcard_status_container');
            const status = document.getElementById('giftcard_status');
            if (!input || !button || !statusCont || !status) return;

            const code = String(input.value || '').toUpperCase().replace(/[\s-]+/g, '');
            if (!/^[A-Z0-9]{16}$/.test(code)) {
                Swal.fire({
                    icon: 'warning',
                    title: Lang.t('giftcard.error.format'),
                    background: '#141418', color: '#fff', confirmButtonColor: '#f59e0b'
                });
                return;
            }

            const originalHtml = button.innerHTML;
            button.disabled = true;
            button.innerHTML = '<i class="bi bi-hourglass-split animate-spin"></i><span>' + Lang.t('giftcard.processing') + '</span>';
            statusCont.classList.remove('hidden');
            statusCont.className = 'p-4 rounded-2xl text-sm font-medium border border-yellow-500/40 bg-yellow-900/20';
            setDepositStatus(status, 'bi bi-hourglass-split animate-spin mr-2 text-yellow-300', 'text-yellow-200', Lang.t('giftcard.processing'));

            const formData = new FormData();
            formData.append('giftcard_code', code);
            formData.append('csrf_token', '<?php echo getCsrfToken(); ?>');
            // Remove the secret from the visible DOM as soon as the request body exists.
            input.value = '';
            input.type = 'password';
            const icon = document.getElementById('giftcard_code_icon');
            if (icon) icon.className = 'bi bi-eye';

            fetch('../redeem_binance_giftcard.php', {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'X-CSRF-TOKEN': '<?php echo getCsrfToken(); ?>' },
                body: formData
            })
                .then(async response => {
                    const text = await response.text();
                    try { return JSON.parse(text); }
                    catch (e) { throw new Error('invalid_response'); }
                })
                .then(data => {
                    if (data && data.success) {
                        statusCont.className = 'p-4 rounded-2xl text-sm font-medium border border-green-500/40 bg-green-900/20';
                        const summary = depositCreditSummary(data, data.amount_store || data.amount);
                        setDepositStatus(status, 'bi bi-check-circle-fill mr-2 text-green-400', 'text-green-300', Lang.t('giftcard.success') + ' +' + summary);
                        Swal.fire({
                            icon: 'success',
                            title: Lang.t('common.success'),
                            text: String(data.amount_usdt || '') + ' USDT ≈ ' + summary,
                            background: '#141418', color: '#fff', confirmButtonColor: '#f59e0b'
                        }).then(() => location.reload());
                        return;
                    }

                    const pending = Boolean(data && data.pending);
                    statusCont.className = pending
                        ? 'p-4 rounded-2xl text-sm font-medium border border-amber-500/40 bg-amber-900/20'
                        : 'p-4 rounded-2xl text-sm font-medium border border-red-500/40 bg-red-900/20';
                    setDepositStatus(
                        status,
                        pending ? 'bi bi-clock-history mr-2 text-amber-300' : 'bi bi-x-circle-fill mr-2 text-red-400',
                        pending ? 'text-amber-200' : 'text-red-300',
                        safeApiMessage(data || {}, pending ? Lang.t('giftcard.error.review') : Lang.t('giftcard.error.used_or_invalid'))
                    );
                    button.disabled = false;
                    button.innerHTML = originalHtml;
                })
                .catch(() => {
                    statusCont.className = 'p-4 rounded-2xl text-sm font-medium border border-amber-500/40 bg-amber-900/20';
                    setDepositStatus(status, 'bi bi-clock-history mr-2 text-amber-300', 'text-amber-200', Lang.t('giftcard.error.review'));
                    button.disabled = false;
                    button.innerHTML = originalHtml;
                });
        }

        // TrueMoney Angpao Redemption
        function redeemAngpao() {
            const urlInput = document.getElementById('angpao_url');
            const url = urlInput.value.trim();
            if (!url) return;

            const btn = document.getElementById('btn_redeem_angpao');
            const status = document.getElementById('angpao_status');
            const originalText = btn.innerHTML;

            btn.disabled = true;
            btn.innerHTML = '<i class="bi bi-hourglass-split animate-spin"></i>';
            status.textContent = Lang.t('deposit.angpao.verifying');
            status.className = 'text-xs text-yellow-400 mt-2 block text-left';
            status.classList.remove('hidden');

            const formData = new FormData();
            formData.append('voucher_url', url);
            formData.append('csrf_token', '<?php echo getCsrfToken(); ?>');

            fetch('../user/redeem_angpao.php', { 
                method: 'POST', 
                headers: {
                    'X-CSRF-TOKEN': '<?php echo getCsrfToken(); ?>'
                },
                body: formData 
            })
                .then(async response => {
                    const text = await response.text();
                    try { return JSON.parse(text); } catch (e) { throw new Error('Invalid response'); }
                })
                .then(data => {
                    if (data.success) {
                        const creditSummary = depositCreditSummary(data, data.amount_credit);
                        const formatThb = value => '฿' + Number(value || 0).toLocaleString('en-US', {
                            minimumFractionDigits: 2,
                            maximumFractionDigits: 2
                        });
                        const grossText = formatThb(data.amount_thb);
                        const feeText = formatThb(data.fee_thb);
                        const netText = formatThb(data.net_amount_thb);
                        const feeSummary = Lang.current === 'en'
                            ? 'Gift ' + grossText + ' - fee ' + feeText + ' = net ' + netText
                            : 'ยอดซอง ' + grossText + ' - ค่าธรรมเนียม ' + feeText + ' = ยอดสุทธิ ' + netText;
                        const creditedLabel = Lang.current === 'en' ? 'Credited' : 'เครดิตเข้าบัญชี';

                        status.textContent = feeSummary + ' | ' + Lang.t('common.balance') + ': ' + data.new_balance_formatted;
                        status.className = 'text-xs text-green-400 mt-2 block text-left';
                        btn.innerHTML = '<i class="bi bi-check-lg"></i>';
                        urlInput.value = '';
                        Swal.fire({
                            icon: 'success', title: Lang.t('common.success'),
                            text: feeSummary + '\n' + creditedLabel + ': ' + creditSummary,
                            background: '#141418', color: '#fff', confirmButtonColor: '#3b82f6'
                        }).then(() => location.reload());
                    } else {
                        status.textContent = Lang.t('common.error') + ': ' + safeApiMessage(data, userFacingDepositError('failed'));
                        status.className = 'text-xs text-red-400 mt-2 block text-left';
                        btn.disabled = false; btn.innerHTML = originalText;
                    }
                })
                .catch(err => {
                    status.textContent = userFacingDepositError('server');
                    status.className = 'text-xs text-red-400 mt-2 block text-left';
                    btn.disabled = false; btn.innerHTML = originalText;
                });
        }
    </script>
</body>

</html>