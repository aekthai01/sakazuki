<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

global $conn;

$flash = $_SESSION['out_of_stock_flash'] ?? null;
unset($_SESSION['out_of_stock_flash']);

$postedAction = isset($_POST['action']) && is_scalar($_POST['action'])
    ? substr((string) $_POST['action'], 0, 40)
    : '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $postedAction === 'add_variant_keys') {
    requireCsrfToken();
    $variantId = filter_var($_POST['variant_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
    $keyCodes = isset($_POST['key_codes']) && is_scalar($_POST['key_codes'])
        ? (string) $_POST['key_codes']
        : '';
    $added = 0;
    $skipped = 0;
    $message = '';
    $type = 'error';

    if ($variantId < 1 || trim($keyCodes) === '') {
        $message = 'Please select a variant and enter at least one key.';
    } elseif (strlen($keyCodes) > 1024 * 1024) {
        $message = 'The submitted key list is too large.';
    } else {
        $rawLines = preg_split('/\R/u', $keyCodes) ?: [];
        $unique = [];
        foreach ($rawLines as $line) {
            $key = trim((string)$line);
            if ($key === '') {
                continue;
            }
            if (strlen($key) > 500) {
                $skipped++;
                continue;
            }
            if (isset($unique[$key])) {
                $skipped++;
                continue;
            }
            $unique[$key] = true;
            if (count($unique) > 1000) {
                break;
            }
        }
        $keys = array_keys($unique);

        if (count($unique) > 1000 || count($rawLines) > 5000) {
            $message = 'A maximum of 1,000 unique keys can be added at one time.';
        } elseif (!$keys) {
            $message = 'No valid keys were found.';
        } else {
            $variantStmt = $conn->prepare('SELECT pv.product_id, pv.duration, pv.price_user, pv.price_reseller, pv.cost_price FROM product_variants pv JOIN products p ON p.id = pv.product_id WHERE pv.id = ? LIMIT 1');
            if ($variantStmt) {
                $variantStmt->bind_param('i', $variantId);
                $variantStmt->execute();
                $variant = $variantStmt->get_result()->fetch_assoc();
                $variantStmt->close();
            } else {
                $variant = null;
            }

            if (!$variant) {
                $message = 'Variant not found.';
            } else {
                try {
                    $conn->begin_transaction();
                    $check = $conn->prepare('SELECT id FROM `keys` WHERE key_code = ? LIMIT 1');
                    $insert = $conn->prepare("INSERT INTO `keys` (product_id, variant_id, key_code, duration, price_user, price_reseller, cost_price, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'available')");
                    if (!$check || !$insert) {
                        throw new RuntimeException('Unable to prepare key operation.');
                    }
                    $productId = (int)$variant['product_id'];
                    $duration = (string)$variant['duration'];
                    $priceUser = max(0.0, (float)$variant['price_user']);
                    $priceReseller = max(0.0, (float)$variant['price_reseller']);
                    $costPrice = max(0.0, (float)($variant['cost_price'] ?? 0));

                    foreach ($keys as $key) {
                        $check->bind_param('s', $key);
                        $check->execute();
                        if ($check->get_result()->fetch_assoc()) {
                            $skipped++;
                            continue;
                        }
                        $insert->bind_param('iissddd', $productId, $variantId, $key, $duration, $priceUser, $priceReseller, $costPrice);
                        if (!$insert->execute()) {
                            if ((int)$insert->errno === 1062) {
                                $skipped++;
                                continue;
                            }
                            throw new RuntimeException('Unable to save one or more keys.');
                        }
                        $added++;
                    }
                    $check->close();
                    $insert->close();
                    $conn->commit();

                    if ($added > 0) {
                        $type = 'success';
                        $message = $added . ' key(s) added successfully.';
                        if ($skipped > 0) {
                            $message .= ' ' . $skipped . ' duplicate or invalid key(s) skipped.';
                        }
                        logHistory((int)$_SESSION['user_id'], 'add_variant_keys', "Added {$added} keys to variant ID: {$variantId}");
                    } else {
                        $message = 'No new keys were added. The submitted keys may already exist.';
                    }
                } catch (Throwable $e) {
                    $conn->rollback();
                    error_log('Out-of-stock key insert failed: ' . $e->getMessage());
                    $message = 'Unable to add keys. No changes were saved.';
                }
            }
        }
    }

    $_SESSION['out_of_stock_flash'] = ['type' => $type, 'message' => $message];
    header('Location: out_of_stock.php', true, 303);
    exit;
}

// Out of stock list (available = 0)
$sql = "
    SELECT
        pv.id AS variant_id,
        pv.product_id,
        pv.duration,
        pv.price_user,
        pv.price_reseller,
        p.name AS product_name,
        COALESCE(t.total_keys, 0) AS total_keys,
        COALESCE(a.available_keys, 0) AS available_keys
    FROM product_variants pv
    INNER JOIN products p ON p.id = pv.product_id
    LEFT JOIN (
        SELECT variant_id, COUNT(*) AS total_keys
        FROM `keys`
        GROUP BY variant_id
    ) t ON t.variant_id = pv.id
    LEFT JOIN (
        SELECT variant_id, COUNT(*) AS available_keys
        FROM `keys`
        WHERE status = 'available'
        GROUP BY variant_id
    ) a ON a.variant_id = pv.id
    WHERE COALESCE(a.available_keys, 0) = 0
    ORDER BY p.name ASC, pv.price_user ASC, pv.price_reseller ASC, pv.duration ASC
";

$result = $conn->query($sql);
$rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
?>
<!DOCTYPE html>
<html lang="<?php echo getAppLang(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo Lang::t('admin.out_of_stock.heading'); ?></title>
    <link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-[#0b0f17] text-gray-100 min-h-screen">
<?php include 'nav.php'; ?>

<main class="flex-1 overflow-y-auto p-4 md:p-6 space-y-4 md:space-y-6">
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-2">
        <h3 class="text-xl md:text-2xl font-bold text-white">
            <i class="bi bi-box-seam mr-2 text-red-400"></i><span data-lang="admin.out_of_stock.heading"><?php echo Lang::t('admin.out_of_stock.heading'); ?></span>
        </h3>
        <div class="text-xs md:text-sm text-gray-400" data-lang="admin.out_of_stock.desc">
            <?php echo Lang::t('admin.out_of_stock.desc'); ?>
        </div>
    </div>

    <?php if (is_array($flash) && !empty($flash['message'])): ?>
        <div class="rounded-lg px-4 py-3 <?php echo ($flash['type'] ?? '') === 'success' ? 'bg-green-500/15 border border-green-500/30 text-green-200' : 'bg-red-500/15 border border-red-500/30 text-red-200'; ?>">
            <?php echo htmlspecialchars((string)$flash['message'], ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <div class="rounded-xl p-4 md:p-5" style="background: rgba(255,255,255,0.04); backdrop-filter: blur(10px);">
        <?php if (empty($rows)): ?>
            <div class="text-center text-gray-400 py-8">
                <i class="bi bi-check-circle text-3xl text-green-400"></i>
                <div class="mt-2" data-lang="admin.out_of_stock.empty"><?php echo Lang::t('admin.out_of_stock.empty'); ?></div>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-gray-300 border-b border-white/10">
                            <th class="text-left py-2 pr-3" data-lang="admin.out_of_stock.table.product"><?php echo Lang::t('admin.out_of_stock.table.product'); ?></th>
                            <th class="text-left py-2 pr-3" data-lang="admin.out_of_stock.table.variant"><?php echo Lang::t('admin.out_of_stock.table.variant'); ?></th>
                            <th class="text-right py-2 pr-3" data-lang="admin.out_of_stock.table.user_price"><?php echo Lang::t('admin.out_of_stock.table.user_price'); ?></th>
                            <th class="text-right py-2 pr-3" data-lang="admin.out_of_stock.table.reseller_price"><?php echo Lang::t('admin.out_of_stock.table.reseller_price'); ?></th>
                            <th class="text-right py-2 pr-3" data-lang="admin.out_of_stock.table.total_keys"><?php echo Lang::t('admin.out_of_stock.table.total_keys'); ?></th>
                            <th class="text-right py-2 pr-3" data-lang="admin.out_of_stock.table.available"><?php echo Lang::t('admin.out_of_stock.table.available'); ?></th>
                            <th class="text-right py-2" data-lang="admin.out_of_stock.table.action"><?php echo Lang::t('admin.out_of_stock.table.action'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <?php
                                $productName = (string)$r['product_name'];
                                $rawDuration = (string)$r['duration'];
                                $variantName = $rawDuration . ' ' . Lang::t('common.days');
                            ?>
                            <tr class="border-b border-white/5 hover:bg-white/5 transition">
                                <td class="py-2 pr-3">
                                    <div class="font-medium text-white text-sm leading-tight"><?php echo htmlspecialchars($productName); ?></div>
                                    <div class="text-xs text-gray-500">ID: <?php echo (int)$r['product_id']; ?> • VarID: <?php echo (int)$r['variant_id']; ?></div>
                                </td>
                                <td class="py-2 pr-3 text-gray-200">
                                    <?php echo htmlspecialchars($rawDuration); ?> <span data-lang="common.days"><?php echo Lang::t('common.days'); ?></span>
                                </td>
                                <td class="py-2 pr-3 text-right text-gray-200">
                                    <?php echo formatCurrency($r['price_user']); ?>
                                </td>
                                <td class="py-2 pr-3 text-right text-gray-200">
                                    <?php echo formatCurrency($r['price_reseller']); ?>
                                </td>
                                <td class="py-2 pr-3 text-right text-gray-200">
                                    <?php echo (int)$r['total_keys']; ?>
                                </td>
                                <td class="py-2 pr-3 text-right">
                                    <span class="px-2 py-0.5 rounded bg-red-500/20 text-red-300 text-xs font-semibold">
                                        <?php echo (int)$r['available_keys']; ?>
                                    </span>
                                </td>

                                <!-- ONLY CHANGE: tombol Add Keys buka modal overlay -->
                                <td class="py-2 text-right">
                                    <button type="button"
                                            class="js-add-keys inline-flex items-center gap-1 text-xs px-2 py-1 rounded bg-blue-500/20 text-blue-300 hover:bg-blue-500/30 transition"
                                            data-variant-id="<?php echo (int)$r['variant_id']; ?>"
                                            data-product-name="<?php echo htmlspecialchars($productName, ENT_QUOTES); ?>"
                                            data-variant-name="<?php echo htmlspecialchars($variantName, ENT_QUOTES); ?>">
                                        <i class="bi bi-plus-circle"></i><span data-lang="admin.out_of_stock.add_keys"><?php echo Lang::t('admin.out_of_stock.add_keys'); ?></span>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</main>

<!-- Modal Add Keys to Variant -->
<div id="addVariantKeysModal" class="fixed inset-0 hidden items-center justify-center z-50">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm"></div>

    <div class="relative w-full max-w-md mx-3 md:mx-4 rounded-xl p-4 md:p-6"
         style="background: rgba(255,255,255,0.06); backdrop-filter: blur(10px);">
        <div class="flex justify-between items-center mb-4">
            <h5 class="text-lg font-bold text-white">
                <i class="bi bi-key text-pink-400 mr-2"></i><span data-lang="admin.out_of_stock.modal.title"><?php echo Lang::t('admin.out_of_stock.modal.title'); ?></span>
            </h5>
            <button type="button" id="closeAddKeys" class="text-gray-300 hover:text-white text-xl">✕</button>
        </div>

        <form method="POST">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="add_variant_keys">
            <input type="hidden" name="variant_id" id="modal_variant_id">

            <div class="mb-4">
                <div class="text-gray-400 text-sm mb-1"><span data-lang="admin.out_of_stock.modal.product"><?php echo Lang::t('admin.out_of_stock.modal.product'); ?>:</span> <span class="text-white font-semibold" id="modal_product_name"></span></div>
                <div class="text-gray-400 text-sm"><span data-lang="admin.out_of_stock.modal.variant"><?php echo Lang::t('admin.out_of_stock.modal.variant'); ?>:</span> <span class="text-white font-semibold" id="modal_variant_name"></span></div>
            </div>

            <div class="mb-4">
                <label class="block text-gray-300 text-sm mb-2" data-lang="admin.out_of_stock.modal.key_placeholder"><?php echo Lang::t('admin.out_of_stock.modal.key_placeholder'); ?></label>
                <textarea name="key_codes" rows="8" required
                          class="w-full bg-black/30 border border-white/10 rounded-lg px-3 py-2 text-gray-200 focus:outline-none focus:ring-2 focus:ring-pink-500/50 text-sm"
                          data-lang-placeholder="admin.out_of_stock.modal.key_placeholder"
                          placeholder="<?php echo Lang::t('admin.out_of_stock.modal.key_placeholder'); ?>"></textarea>
            </div>

            <div class="flex gap-2">
                <button type="button" id="cancelAddKeys"
                        class="flex-1 bg-gray-700 hover:bg-gray-600 text-white py-2 rounded-lg font-medium transition text-sm" data-lang="admin.out_of_stock.modal.cancel">
                    <?php echo Lang::t('admin.out_of_stock.modal.cancel'); ?>
                </button>
                <button type="submit"
                        class="flex-1 bg-pink-500 hover:opacity-90 text-white py-2 rounded-lg font-medium transition text-sm" data-lang="admin.out_of_stock.modal.add">
                    <?php echo Lang::t('admin.out_of_stock.modal.add'); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var modal = document.getElementById('addVariantKeysModal');
    var closeBtn = document.getElementById('closeAddKeys');
    var cancelBtn = document.getElementById('cancelAddKeys');

    function openModal(variantId, productName, variantName) {
        document.getElementById('modal_variant_id').value = variantId;
        document.getElementById('modal_product_name').textContent = productName;
        document.getElementById('modal_variant_name').textContent = variantName;

        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }

    function closeModal() {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    document.querySelectorAll('.js-add-keys').forEach(function (btn) {
        btn.addEventListener('click', function () {
            openModal(
                btn.getAttribute('data-variant-id'),
                btn.getAttribute('data-product-name'),
                btn.getAttribute('data-variant-name')
            );
        });
    });

    closeBtn.addEventListener('click', closeModal);
    cancelBtn.addEventListener('click', closeModal);

    // close when clicking the dark overlay
    modal.addEventListener('click', function (e) {
        if (e.target === modal || e.target.classList.contains('absolute')) {
            closeModal();
        }
    });
})();
</script>

</body>
</html>
