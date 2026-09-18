<?php
/**
 * Live purchase activity component.
 *
 * Expected variables:
 * - $recentPurchaseActivity: rows from getPublicRecentPurchaseActivity()
 * - $purchaseActivityAccent: blue, green, or violet
 * - $purchaseActivityEndpoint: optional URL for live refreshes
 */
$activityRows = isset($recentPurchaseActivity) && is_array($recentPurchaseActivity)
    ? array_values($recentPurchaseActivity)
    : [];
$activityAccent = isset($purchaseActivityAccent) && is_string($purchaseActivityAccent)
    ? strtolower($purchaseActivityAccent)
    : 'blue';
$activityEndpoint = isset($purchaseActivityEndpoint) && is_string($purchaseActivityEndpoint)
    ? trim($purchaseActivityEndpoint)
    : '../purchase_activity.php';
$activityPalette = [
    'blue' => [
        'rgb' => '96, 165, 250',
        'icon' => 'text-blue-300',
        'badge' => 'bg-blue-500/10 border-blue-400/20 text-blue-200',
    ],
    'green' => [
        'rgb' => '74, 222, 128',
        'icon' => 'text-green-300',
        'badge' => 'bg-green-500/10 border-green-400/20 text-green-200',
    ],
    'violet' => [
        'rgb' => '167, 139, 250',
        'icon' => 'text-violet-300',
        'badge' => 'bg-violet-500/10 border-violet-400/20 text-violet-200',
    ],
];
if (!isset($activityPalette[$activityAccent])) $activityAccent = 'blue';
$activityColors = $activityPalette[$activityAccent];
$activityEnglish = getAppLang() === 'en';
$activityPayload = json_encode(
    $activityRows,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
);
$activitySignature = hash('sha256', $activityPayload === false ? '[]' : $activityPayload);
$activityScriptPath = __DIR__ . '/../assets/js/purchase-activity.js';
$activityScriptVersion = is_file($activityScriptPath) ? (string) filemtime($activityScriptPath) : '5';

$activityTimeOnly = static function ($value): string {
    $value = trim((string) $value);
    return preg_match('/(?:^|[ T])(\d{2}):(\d{2})(?::\d{2})?$/', $value, $matches) === 1
        ? $matches[1] . ':' . $matches[2]
        : '--:--';
};
?>
<style>
    [data-purchase-activity].hidden,
    [data-purchase-activity] .hidden { display: none !important; }

    .purchase-activity-card {
        --activity-rgb: <?php echo $activityColors['rgb']; ?>;
        position: relative;
        isolation: isolate;
        overflow: hidden;
        border: 1px solid rgba(var(--activity-rgb), .22);
        background:
            radial-gradient(circle at 8% 0%, rgba(var(--activity-rgb), .13), transparent 38%),
            linear-gradient(135deg, rgba(18, 24, 38, .94), rgba(11, 14, 24, .92));
        box-shadow:
            inset 0 1px 0 rgba(255, 255, 255, .045),
            0 12px 30px rgba(0, 0, 0, .18);
    }
    .purchase-activity-card::before {
        content: '';
        position: absolute;
        z-index: -1;
        inset: 0 auto auto 0;
        width: 42%;
        height: 1px;
        background: linear-gradient(90deg, rgba(var(--activity-rgb), .9), transparent);
    }
    .purchase-activity-card::after {
        content: '';
        position: absolute;
        z-index: -1;
        width: 120px;
        height: 120px;
        right: -62px;
        bottom: -86px;
        border-radius: 999px;
        background: rgba(var(--activity-rgb), .12);
        filter: blur(25px);
        pointer-events: none;
    }
    .purchase-activity-header {
        display: flex;
        align-items: center;
        min-width: 0;
        gap: .5rem;
        margin-bottom: .5rem;
    }
    .purchase-activity-live-dot {
        width: .45rem;
        height: .45rem;
        flex: 0 0 auto;
        border-radius: 999px;
        background: rgb(var(--activity-rgb));
        box-shadow: 0 0 0 0 rgba(var(--activity-rgb), .48);
        animation: purchase-activity-live 2.1s ease-out infinite;
    }
    .purchase-activity-stage {
        display: grid;
        align-items: center;
        min-width: 0;
        min-height: 3.25rem;
        overflow: hidden;
    }
    .purchase-activity-rows { display: contents; }
    .purchase-activity-row {
        grid-area: 1 / 1;
        display: grid;
        grid-template-columns: 2.75rem minmax(0, 1fr);
        align-items: center;
        gap: .7rem;
        min-width: 0;
        width: 100%;
        opacity: 0;
        visibility: hidden;
        pointer-events: none;
        transform: translate3d(12px, 0, 0) scale(.985);
        transform-origin: center right;
        backface-visibility: hidden;
        transition:
            opacity .15s cubic-bezier(.22, 1, .36, 1),
            transform .15s cubic-bezier(.22, 1, .36, 1),
            visibility 0s linear .15s;
    }
    .purchase-activity-row.is-active {
        opacity: 1;
        visibility: visible;
        pointer-events: auto;
        transform: translate3d(0, 0, 0) scale(1);
        transition-delay: 0s;
    }
    .purchase-activity-row.is-leaving {
        opacity: 0;
        visibility: visible;
        transform: translate3d(-12px, 0, 0) scale(.985);
    }
    .purchase-activity-thumb {
        position: relative;
        width: 2.75rem;
        height: 2.75rem;
        overflow: hidden;
        border-radius: .85rem;
        border: 1px solid rgba(var(--activity-rgb), .32);
        background:
            linear-gradient(145deg, rgba(var(--activity-rgb), .20), rgba(255, 255, 255, .035));
        box-shadow:
            inset 0 0 0 1px rgba(255, 255, 255, .035),
            0 5px 16px rgba(0, 0, 0, .22);
    }
    .purchase-activity-thumb::after {
        content: '';
        position: absolute;
        inset: 0;
        border-radius: inherit;
        box-shadow: inset 0 0 16px rgba(var(--activity-rgb), .10);
        pointer-events: none;
    }
    .purchase-activity-thumb img {
        position: relative;
        z-index: 2;
        display: block;
        width: 100%;
        height: 100%;
        object-fit: cover;
        opacity: 1;
        transform: scale(1);
        transition: opacity .15s ease, transform .15s cubic-bezier(.22, 1, .36, 1);
    }
    .purchase-activity-row.is-active .purchase-activity-thumb img {
        transform: scale(1.055);
    }
    .purchase-activity-thumb img.is-error { display: none; }
    .purchase-activity-thumb-fallback {
        position: absolute;
        z-index: 1;
        inset: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        color: rgba(var(--activity-rgb), .96);
        font-size: 1.05rem;
    }
    .purchase-activity-body { min-width: 0; }
    .purchase-activity-meta {
        display: flex;
        align-items: center;
        min-width: 0;
        gap: .42rem;
        margin-bottom: .16rem;
        line-height: 1.15rem;
    }
    .purchase-activity-account {
        min-width: 0;
        max-width: 48%;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        color: #fff;
        font-weight: 700;
    }
    .purchase-activity-action {
        flex: 0 0 auto;
        border: 1px solid rgba(var(--activity-rgb), .23);
        border-radius: 999px;
        padding: .05rem .38rem;
        color: rgba(var(--activity-rgb), .98);
        background: rgba(var(--activity-rgb), .10);
        font-size: .66rem;
        line-height: 1.05rem;
    }
    .purchase-activity-time {
        flex: 0 0 auto;
        margin-left: auto;
        color: rgba(156, 163, 175, .92);
        font-size: .7rem;
        font-variant-numeric: tabular-nums;
        white-space: nowrap;
    }
    .purchase-activity-product-line {
        display: flex;
        align-items: center;
        min-width: 0;
        gap: .4rem;
    }
    .purchase-activity-product {
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        color: rgba(var(--activity-rgb), .98);
        font-weight: 600;
        line-height: 1.2rem;
    }
    .purchase-activity-quantity {
        flex: 0 0 auto;
        border-radius: .45rem;
        padding: .05rem .35rem;
        color: #e5e7eb;
        background: rgba(255, 255, 255, .075);
        font-size: .68rem;
        font-variant-numeric: tabular-nums;
    }
    .purchase-activity-counter {
        margin-left: auto;
        min-width: 2.45rem;
        border: 1px solid rgba(255, 255, 255, .07);
        border-radius: 999px;
        padding: .08rem .42rem;
        color: rgba(156, 163, 175, .88);
        background: rgba(255, 255, 255, .035);
        text-align: center;
        font-size: .65rem;
        font-variant-numeric: tabular-nums;
    }
    [data-purchase-activity].activity-updated {
        animation: purchase-activity-update .15s cubic-bezier(.22, 1, .36, 1);
    }
    @keyframes purchase-activity-live {
        0% { box-shadow: 0 0 0 0 rgba(var(--activity-rgb), .46); }
        68%, 100% { box-shadow: 0 0 0 7px rgba(var(--activity-rgb), 0); }
    }
    @keyframes purchase-activity-update {
        0% { border-color: rgba(var(--activity-rgb), .78); transform: translate3d(0, -3px, 0); }
        100% { border-color: rgba(var(--activity-rgb), .22); transform: translate3d(0, 0, 0); }
    }
    @media (max-width: 380px) {
        .purchase-activity-card { padding-left: .75rem !important; padding-right: .75rem !important; }
        .purchase-activity-row { grid-template-columns: 2.5rem minmax(0, 1fr); gap: .58rem; }
        .purchase-activity-thumb { width: 2.5rem; height: 2.5rem; border-radius: .72rem; }
        .purchase-activity-account { max-width: 42%; }
        .purchase-activity-action { padding-inline: .3rem; }
    }
    </style>

<section
    class="purchase-activity-card glass rounded-xl px-4 py-3<?php echo $activityRows === [] ? ' hidden' : ''; ?>"
    data-purchase-activity
    data-purchase-activity-endpoint="<?php echo htmlspecialchars($activityEndpoint, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
    data-purchase-activity-signature="<?php echo htmlspecialchars($activitySignature, ENT_QUOTES, 'UTF-8'); ?>"
    data-purchase-label="<?php echo $activityEnglish ? 'purchased' : 'ซื้อ'; ?>"
    data-empty-product="<?php echo $activityEnglish ? 'Product' : 'สินค้า'; ?>"
    aria-label="<?php echo $activityEnglish ? 'Recent purchases' : 'รายการสั่งซื้อล่าสุด'; ?>">
    <div class="purchase-activity-header">
        <span class="purchase-activity-live-dot" aria-hidden="true"></span>
        <p class="min-w-0 truncate text-[11px] font-medium text-gray-400">
            <?php echo $activityEnglish ? 'Today’s purchases' : 'รายการสั่งซื้อวันนี้'; ?>
        </p>
        <span class="purchase-activity-counter<?php echo count($activityRows) > 1 ? '' : ' hidden'; ?>" data-purchase-activity-count>
            <?php echo $activityRows === [] ? '0/0' : '1/' . count($activityRows); ?>
        </span>
    </div>

    <div class="purchase-activity-stage" aria-live="polite" aria-atomic="true">
        <div class="purchase-activity-rows" data-purchase-activity-rows>
            <?php foreach ($activityRows as $activityIndex => $activity): ?>
                <?php
                $activityQuantity = max(1, (int) ($activity['quantity'] ?? 1));
                $activityAccount = trim((string) ($activity['account_name'] ?? '')) ?: 'User';
                $activityProduct = trim((string) ($activity['product_name'] ?? ''));
                $activityImage = trim((string) ($activity['image_url'] ?? ''));
                ?>
                <div
                    class="purchase-activity-row<?php echo $activityIndex === 0 ? ' is-active' : ''; ?>"
                    data-purchase-activity-row
                    aria-hidden="<?php echo $activityIndex === 0 ? 'false' : 'true'; ?>">
                    <span class="purchase-activity-thumb" aria-hidden="true">
                        <span class="purchase-activity-thumb-fallback"><i class="bi bi-controller"></i></span>
                        <?php if ($activityImage !== ''): ?>
                            <img
                                src="<?php echo htmlspecialchars($activityImage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                alt=""
                                width="44"
                                height="44"
                                decoding="async"
                                referrerpolicy="no-referrer"
                                data-activity-image>
                        <?php endif; ?>
                    </span>

                    <span class="purchase-activity-body">
                        <span class="purchase-activity-meta">
                            <span
                                class="purchase-activity-account"
                                data-activity-account
                                title="<?php echo htmlspecialchars($activityAccount, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars($activityAccount, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                            </span>
                            <span class="purchase-activity-action"><?php echo $activityEnglish ? 'purchased' : 'ซื้อ'; ?></span>
                            <span class="purchase-activity-time" data-activity-time>
                                <i class="bi bi-clock mr-0.5"></i><?php echo htmlspecialchars($activityTimeOnly($activity['purchased_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </span>
                        <span class="purchase-activity-product-line">
                            <span
                                class="purchase-activity-product"
                                data-activity-product
                                title="<?php echo htmlspecialchars($activityProduct, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars($activityProduct, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                            </span>
                            <span class="purchase-activity-quantity<?php echo $activityQuantity > 1 ? '' : ' hidden'; ?>" data-activity-quantity>×<?php echo $activityQuantity; ?></span>
                        </span>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<script defer src="../assets/js/purchase-activity.js?v=<?php echo rawurlencode($activityScriptVersion); ?>"></script>
