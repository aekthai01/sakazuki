<?php
/** Verify Binance deposit transaction id and credit the authenticated account. */
ob_start();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ranking.php';
require_once __DIR__ . '/includes/binance.php';
require_once __DIR__ . '/includes/store_bridge.php';
requireLogin();
requireActive();
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    ob_clean();
    jsonResponse(['success' => false, 'message' => Lang::t('deposit.error.method_not_allowed')]);
}
requireCsrfToken();

$userId = (int) ($_SESSION['user_id'] ?? 0);
if (!checkRateLimit('verify_binance_user_' . $userId, 5, 600)) {
    http_response_code(429);
    ob_clean();
    jsonResponse(['success' => false, 'message' => 'Too many requests. Please wait a few minutes.']);
}

$txIdRaw = isset($_POST['tx_id']) && is_string($_POST['tx_id']) ? $_POST['tx_id'] : '';
$txId = normalizeBinanceTxId($txIdRaw);
if ($txId === '') {
    ob_clean();
    jsonResponse(['success' => false, 'message' => Lang::t('deposit.error.enter_txid')]);
}
if (!isValidBinanceTxIdFormat($txId)) {
    ob_clean();
    jsonResponse([
        'success' => false,
        'message' => 'รูปแบบ TxID ไม่ถูกต้อง กรุณาใช้ TxID ของ TRC20 จำนวน 64 ตัวอักษร หรือ Off-chain transfer พร้อมเลขอ้างอิง',
    ]);
}

$settings = getBinanceSettings();
if (empty($settings['enabled'])) {
    ob_clean();
    jsonResponse(['success' => false, 'message' => Lang::t('deposit.error.binance_disabled')]);
}

try {
    $result = processBinanceDeposit($userId, $txId);
    ob_clean();
    jsonResponse(is_array($result) ? $result : ['success' => false, 'message' => 'System error']);
} catch (Throwable $e) {
    error_log('Binance endpoint error: ' . $e->getMessage());
    ob_clean();
    jsonResponse(['success' => false, 'message' => 'System error. Please contact the administrator.']);
}
