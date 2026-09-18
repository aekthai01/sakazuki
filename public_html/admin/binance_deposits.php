<?php
require_once '../includes/auth.php';
requireAdmin();
require_once '../includes/binance.php';

global $conn;
ensureBinanceDepositsTable();

// Pagination
$pageRaw = isset($_GET['page']) && is_scalar($_GET['page']) ? (int) $_GET['page'] : 1;
$page = max(1, min(100000, $pageRaw));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Search
$search = isset($_GET['search']) && is_scalar($_GET['search']) ? substr(trim((string) $_GET['search']), 0, 180) : '';
$whereClause = "WHERE bd.processing = 0";
if (!empty($search)) {
    $searchEsc = $conn->real_escape_string($search);
    $whereClause .= " AND (bd.tx_id LIKE '%$searchEsc%' OR u.username LIKE '%$searchEsc%')";
}

// Total count
$countResult = $conn->query("SELECT COUNT(*) as total FROM binance_deposits bd LEFT JOIN users u ON bd.user_id = u.id $whereClause");
$totalRows = $countResult ? $countResult->fetch_assoc()['total'] : 0;
$totalPages = max(1, ceil($totalRows / $perPage));

// Get records
$rows = [];
$q = $conn->query("SELECT bd.*, u.username 
    FROM binance_deposits bd 
    LEFT JOIN users u ON bd.user_id = u.id 
    $whereClause 
    ORDER BY bd.created_at DESC 
    LIMIT $perPage OFFSET $offset");
if ($q) {
    while ($row = $q->fetch_assoc()) {
        $rows[] = $row;
    }
}

// Stats
$statsQ = $conn->query("SELECT COUNT(*) as total_count, COALESCE(SUM(amount_usdt),0) as total_usdt, COALESCE(SUM(amount_thb),0) as total_thb FROM binance_deposits WHERE processing = 0");
$stats = $statsQ ? $statsQ->fetch_assoc() : ['total_count' => 0, 'total_usdt' => 0, 'total_thb' => 0];
?>
<!DOCTYPE html>
<html lang="<?php echo getAppLang(); ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title data-lang="admin.binance.title"><?php echo Lang::t('admin.binance.title'); ?></title>
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
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .animate-fade-in { animation: fadeInUp .15s ease-out; }
    </style>
</head>

<body class="bg-darkbg text-gray-100 min-h-screen">
    <?php include 'nav.php'; ?>

    <main class="flex-1 overflow-y-auto p-6 space-y-6 animate-fade-in">
        <div class="flex justify-between items-center mb-6">
            <h3 class="text-2xl font-bold text-white">
                <i class="bi bi-currency-bitcoin mr-2 text-yellow-400"></i><span data-lang="admin.binance.heading"><?php echo Lang::t('admin.binance.heading'); ?></span>
            </h3>
        </div>

        <!-- Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div class="glass p-4 rounded-xl border border-yellow-500/30">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full bg-yellow-500/20 flex items-center justify-center">
                        <i class="bi bi-hash text-yellow-400 text-xl"></i>
                    </div>
                    <div>
                        <div class="text-xs text-gray-400" data-lang="admin.binance.stats.total_records"><?php echo Lang::t('admin.binance.stats.total_records'); ?></div>
                        <div class="text-xl font-bold text-yellow-400"><?php echo number_format($stats['total_count']); ?></div>
                    </div>
                </div>
            </div>
            <div class="glass p-4 rounded-xl border border-green-500/30">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full bg-green-500/20 flex items-center justify-center">
                        <i class="bi bi-coin text-green-400 text-xl"></i>
                    </div>
                    <div>
                        <div class="text-xs text-gray-400" data-lang="admin.binance.stats.total_usdt"><?php echo Lang::t('admin.binance.stats.total_usdt'); ?></div>
                        <div class="text-xl font-bold text-green-400"><?php echo number_format($stats['total_usdt'], 2); ?> USDT</div>
                    </div>
                </div>
            </div>
            <div class="glass p-4 rounded-xl border border-blue-500/30">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full bg-blue-500/20 flex items-center justify-center">
                        <i class="bi bi-cash-stack text-blue-400 text-xl"></i>
                    </div>
                    <div>
                        <div class="text-xs text-gray-400" data-lang="admin.binance.stats.total_thb"><?php echo Lang::t('admin.binance.stats.total_thb'); ?></div>
                        <div class="text-xl font-bold text-blue-400"><?php echo formatCurrency($stats['total_thb']); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Search -->
        <div id="binanceDepositsLivePanel" data-instant-panel>
        <form method="GET" class="mb-4">
            <div class="flex gap-2">
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
                    class="flex-1 px-4 py-2 rounded-lg bg-white/5 border border-white/10 text-white focus:outline-none focus:border-accent"
                    data-lang-placeholder="admin.binance.search_placeholder" placeholder="<?php echo Lang::t('admin.binance.search_placeholder'); ?>">
                <button type="submit"
                    class="px-4 py-2 rounded-lg bg-accent/20 hover:bg-accent/30 border border-accent/30 transition text-accent">
                    <i class="bi bi-search"></i> <span data-lang="common.search"><?php echo Lang::t('common.search'); ?></span>
                </button>
                <?php if ($search): ?>
                    <a href="binance_deposits.php" class="px-4 py-2 rounded-lg bg-white/10 hover:bg-white/20 transition" data-lang="admin.binance.clear"><?php echo Lang::t('admin.binance.clear'); ?></a>
                <?php endif; ?>
            </div>
        </form>

        <!-- Table -->
        <div class="glass rounded-xl overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-white/10 text-gray-400 text-xs uppercase">
                        <th class="px-4 py-3 text-left">#</th>
                        <th class="px-4 py-3 text-left" data-lang="admin.binance.table.user"><?php echo Lang::t('admin.binance.table.user'); ?></th>
                        <th class="px-4 py-3 text-left" data-lang="admin.binance.table.txid"><?php echo Lang::t('admin.binance.table.txid'); ?></th>
                        <th class="px-4 py-3 text-right">USDT</th>
                        <th class="px-4 py-3 text-right" data-lang="admin.binance.table.rate"><?php echo Lang::t('admin.binance.table.rate'); ?></th>
                        <th class="px-4 py-3 text-right" data-lang="admin.binance.table.thb"><?php echo Lang::t('admin.binance.table.thb'); ?></th>
                        <th class="px-4 py-3 text-center" data-lang="admin.binance.table.network"><?php echo Lang::t('admin.binance.table.network'); ?></th>
                        <th class="px-4 py-3 text-left" data-lang="admin.binance.table.date"><?php echo Lang::t('admin.binance.table.date'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="8" class="px-4 py-8 text-center text-gray-500" data-lang="admin.binance.empty"><?php echo Lang::t('admin.binance.empty'); ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <tr class="border-b border-white/5 hover:bg-white/5 transition">
                                <td class="px-4 py-3 text-gray-500"><?php echo $row['id']; ?></td>
                                <td class="px-4 py-3">
                                    <span class="text-accent"><?php echo htmlspecialchars($row['username'] ?? 'N/A'); ?></span>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="font-mono text-xs text-gray-300" title="<?php echo htmlspecialchars($row['tx_id']); ?>">
                                        <?php echo htmlspecialchars(substr((string) $row['tx_id'], 0, 12) . '...' . substr((string) $row['tx_id'], -8), ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <span class="text-green-400 font-semibold"><?php echo number_format($row['amount_usdt'], 2); ?></span>
                                </td>
                                <td class="px-4 py-3 text-right text-gray-400">
                                    <?php echo number_format($row['exchange_rate'], 2); ?>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <span class="text-yellow-400 font-semibold"><?php echo formatCurrency($row['amount_thb']); ?></span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span class="px-2 py-0.5 rounded-full text-xs bg-green-500/20 text-green-400 border border-green-500/30">
                                        <?php echo htmlspecialchars($row['network']); ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-gray-400 text-xs">
                                    <?php echo date('d/m/Y H:i', strtotime($row['created_at'])); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="flex justify-center gap-2 mt-4">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>"
                        class="px-3 py-1 rounded-lg <?php echo $i === $page ? 'bg-accent text-white' : 'bg-white/10 hover:bg-white/20 text-gray-300'; ?> transition text-sm">
                        <?php echo $i; ?>
                    </a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
        </div>
    </main>
<script src="../assets/js/instant-filter.js?v=3.0"></script>
</body>
</html>
