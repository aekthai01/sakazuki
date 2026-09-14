<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/cheatgame.php';
requireLogin();

$productId = filter_input(INPUT_GET, 'product_id', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
if (!$productId) {
    http_response_code(400);
    echo '<div class="text-center py-6 text-gray-400">Invalid request</div>';
    exit;
}



global $conn;
$stmt = $conn->prepare("SELECT name FROM products WHERE id = ? AND status = 'active' LIMIT 1");
if (!$stmt) {
    http_response_code(500);
    echo '<div class="text-center py-6 text-gray-400">Unable to load product</div>';
    exit;
}
$stmt->bind_param('i', $productId);
$stmt->execute();
$productResult = $stmt->get_result();
$product = $productResult ? $productResult->fetch_assoc() : null;
$stmt->close();

if (!$product) {
    http_response_code(404);
    echo '<div class="text-center py-6 text-gray-400">Product not found</div>';
    exit;
}

$productName = (string)$product['name'];
$grouped = cgoGetUnifiedStoreVariants((int) $productId, 'user', (int) ($_SESSION['user_id'] ?? 0));


function variantJsLiteral($value): string
{
    $json = json_encode(
        $value,
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE
    );
    return htmlspecialchars($json === false ? 'null' : $json, ENT_QUOTES, 'UTF-8');
}

if (!$grouped): ?>
    <div class="text-center py-6 text-gray-400">
        <i class="bi bi-x-circle text-2xl mb-2"></i>
        <p class="text-sm mb-3">No variants available</p>
        <button type="button" onclick="closeVariantModal()" class="px-3 py-1.5 bg-blue-500 hover:bg-blue-600 text-white rounded text-sm transition">Close</button>
    </div>
<?php else: ?>
    <div class="space-y-2">
        <?php foreach ($grouped as $data):
            $duration = (string)$data['duration'];
            $variantId = (int) ($data['variant_id'] ?? 0);
            $displayDuration = $duration === 'Standard' ? '1 day' : $duration;
            $price = (float)$data['price'];
            $count = (int)$data['count'];
        ?>
            <button type="button"
                    onclick="selectVariant(<?php echo variantJsLiteral($duration); ?>, <?php echo $variantId; ?>, <?php echo (int)$productId; ?>, <?php echo variantJsLiteral($productName); ?>, <?php echo $count; ?>, <?php echo json_encode($price); ?>)"
                    class="w-full text-left glass border border-white/10 rounded p-3 hover:border-blue-500/50 hover:bg-blue-500/5 transition flex justify-between items-center group">
                <div class="flex-grow">
                    <div class="font-medium text-white text-sm mb-0.5"><?php echo htmlspecialchars($productName, ENT_QUOTES, 'UTF-8'); ?> - <?php echo htmlspecialchars($displayDuration, ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="text-xs text-gray-400">Ready to use • Instant delivery</div>
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-blue-400 font-bold text-sm"><?php echo formatCurrency($price); ?></span>
                    <i class="bi bi-chevron-right text-gray-400 group-hover:text-blue-400 transition"></i>
                </div>
            </button>
        <?php endforeach; ?>
    </div>
    <div class="mt-4 pt-3 border-t border-white/10">
        <button type="button" onclick="closeVariantModal()" class="w-full bg-gray-700 hover:bg-gray-600 text-white py-2 rounded font-medium transition text-sm flex items-center justify-center gap-2">
            <i class="bi bi-x-circle"></i> Cancel
        </button>
    </div>
<?php endif; ?>
