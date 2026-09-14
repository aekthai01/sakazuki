<?php
/** Redeem a Binance Gift Card code for the authenticated user/reseller. */
ob_start();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ranking.php';
require_once __DIR__ . '/includes/binance_giftcard.php';
requireLogin();
requireActive();

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    ob_clean();
    jsonResponse(['success' => false, 'message' => Lang::t('deposit.error.method_not_allowed')], 405);
}
requireCsrfToken();

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId < 1 || (!isUser() && !isReseller())) {
    ob_clean();
    jsonResponse(['success' => false, 'message' => Lang::t('common.error.invalid_request')], 403);
}

// A short burst guard complements the daily limits in the redemption ledger.
if (!checkRateLimit('binance_giftcard_burst_' . $userId, 2, 60)) {
    ob_clean();
    jsonResponse(['success' => false, 'message' => Lang::t('giftcard.error.wait')], 429);
}

$code = isset($_POST['giftcard_code']) && is_scalar($_POST['giftcard_code'])
    ? (string) $_POST['giftcard_code']
    : '';

try {
    $result = processBinanceGiftCardRedemption($userId, $code);
    unset($code);
    ob_clean();
    jsonResponse(is_array($result) ? $result : ['success' => false, 'message' => Lang::t('giftcard.error.unavailable')]);
} catch (Throwable $e) {
    unset($code);
    error_log('Binance Gift Card endpoint failed without code disclosure: ' . $e->getMessage());
    ob_clean();
    jsonResponse(['success' => false, 'message' => Lang::t('giftcard.error.unavailable')], 500);
}
