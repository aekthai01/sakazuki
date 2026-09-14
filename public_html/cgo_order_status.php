<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/cheatgame.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

requireLogin();
if (!isUser() && !isReseller() && !isAdmin()) {
    jsonResponse(['success' => false, 'message' => 'Access denied'], 403);
}
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}
requireCsrfToken();

$orderId = isset($_POST['order_id']) && is_scalar($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
$source = isset($_POST['source']) && is_string($_POST['source']) ? strtolower(trim($_POST['source'])) : '';
$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($orderId < 1 || $userId < 1 || !in_array($source, ['cgo', 'supplier'], true)) {
    jsonResponse(['success' => false, 'message' => 'Invalid order request'], 400);
}

// A status lookup can make a short read-only supplier request. Release PHP's
// session lock first so it never freezes the customer's other tabs.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
ignore_user_abort(true);

$state = $source === 'supplier'
    ? supplierBridgeGetStorefrontOrderState($orderId, $userId, true)
    : cgoGetStorefrontOrderState($orderId, $userId, true);

if (empty($state['success'])) {
    $http = (string) ($state['code'] ?? '') === 'order_not_found' ? 404 : 400;
    jsonResponse([
        'success' => false,
        'code' => (string) ($state['code'] ?? 'status_unavailable'),
        'message' => $http === 404 ? 'Order not found' : 'Unable to check order status',
    ], $http);
}

$isThai = function_exists('getAppLang') && getAppLang() === 'th';
if (!empty($state['completed'])) {
    $state['message'] = $isThai
        ? 'คำสั่งซื้อสำเร็จแล้ว สามารถดูคีย์ได้ทันที'
        : 'The order is complete. Your key is available now.';
} elseif (!empty($state['conflict'])) {
    $state['message'] = $isThai
        ? 'รายการนี้ถูกคืนยอดไปแล้ว แต่ภายหลังต้นทางแจ้งว่ารายการสำเร็จ ระบบหยุดการทำงานอัตโนมัติเพื่อป้องกันยอดหรือคีย์ซ้ำ กรุณาให้แอดมินตรวจสอบรายการนี้'
        : 'This order was refunded, but the supplier later reported success. Automatic handling stopped to prevent a duplicate charge or delivery; administrator review is required.';
} elseif (!empty($state['refunded'])) {
    $state['message'] = $isThai
        ? 'ต้นทางยืนยันว่าไม่พบออเดอร์ ระบบคืนยอดให้แล้ว สามารถสั่งซื้อใหม่ได้ทันที'
        : 'The supplier confirmed that no order exists. Your balance was refunded and you can order again now.';
} elseif (!empty($state['deadline_exceeded'])) {
    // Do not invent a failure when the provider itself is unreachable. Keeping
    // this rare ambiguous case blocked is what prevents a late duplicate charge.
    $state['message'] = $isThai
        ? 'ครบเวลารอหน้าร้านแล้ว แต่ต้นทางยังตอบไม่ชัดเจน ระบบจะตรวจต่อเบื้องหลังและจะไม่ส่งคำสั่งซื้อซ้ำจนกว่าจะยืนยันผลได้'
        : 'The storefront wait window has ended, but the supplier result is still ambiguous. Verification continues in the background and the order will not be submitted twice.';
} else {
    $remaining = max(0, (int) ($state['deadline_remaining_seconds'] ?? 0));
    $state['message'] = $isThai
        ? 'กำลังตรวจสอบคำสั่งซื้ออัตโนมัติ เหลือเวลารอประมาณ ' . $remaining . ' วินาที'
        : 'Checking the order automatically. About ' . $remaining . ' seconds remain in the storefront wait window.';
}

jsonResponse($state, 200);
