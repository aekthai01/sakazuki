<?php
ob_start();
require_once '../includes/auth.php';
require_once '../includes/ranking.php';
require_once '../includes/truemoney.php';

requireLogin();
requireActive();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Invalid request method']);
}
requireCsrfToken();

$userId = (int) ($_SESSION['user_id'] ?? 0);
$tmDebug = trueMoneyDebugStart($userId);
if (!headers_sent()) {
    header('X-Sakazuki-Debug-Request-ID: ' . (string) $tmDebug['request_id']);
}
register_shutdown_function(static function () use (&$tmDebug): void {
    $last = error_get_last();
    if (!$last || !in_array((int) ($last['type'] ?? 0), [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
        return;
    }
    trueMoneyDebugError(
        $tmDebug,
        'fatal_shutdown',
        'php_fatal_error',
        (string) ($last['message'] ?? 'Fatal PHP error')
    );
    $tmDebug['fatal'] = [
        'type' => (int) ($last['type'] ?? 0),
        'message' => trueMoneyDebugSafeText((string) ($last['message'] ?? ''), 800),
        'message_sha256' => hash('sha256', (string) ($last['message'] ?? '')),
        'file_basename' => basename((string) ($last['file'] ?? '')),
        'line' => (int) ($last['line'] ?? 0),
    ];
    trueMoneyDebugSetResult($tmDebug, false, 'fatal_shutdown', 'php_fatal_error');
    trueMoneyDebugPersist($tmDebug);
});

$lastDebugErrorCode = static function (array $debug, string $fallback): string {
    if (!empty($debug['errors']) && is_array($debug['errors'])) {
        $last = end($debug['errors']);
        if (is_array($last) && !empty($last['code'])) return (string) $last['code'];
    }
    return $fallback;
};
$debugRespond = static function (array &$debug, array $payload, string $stage, string $code, bool $success = false) use ($userId): void {
    if (!empty($debug['redemption']['id'])) {
        trueMoneyDebugCaptureState($debug, (int) $debug['redemption']['id'], $userId);
    }
    trueMoneyDebugSetResult($debug, $success, $stage, $code);
    trueMoneyDebugPersist($debug);
    jsonResponse($payload);
};

trueMoneyDebugEvent($tmDebug, 'csrf_validated');
if (!checkRateLimit('truemoney_redeem_user_' . $userId, 6, 600)) {
    trueMoneyDebugError($tmDebug, 'rate_limit_rejected', 'rate_limit_exceeded', 'TrueMoney redemption rate limit exceeded');
    $debugRespond($tmDebug, ['success' => false, 'message' => 'ลองรับซองบ่อยเกินไป กรุณารอสักครู่'], 'rate_limit_rejected', 'rate_limit_exceeded');
}
trueMoneyDebugEvent($tmDebug, 'rate_limit_passed');

if (getSetting('truemoney_enabled') !== '1') {
    trueMoneyDebugError($tmDebug, 'feature_disabled', 'truemoney_disabled', 'TrueMoney redemption feature is disabled');
    $debugRespond($tmDebug, ['success' => false, 'message' => 'ระบบ TrueMoney ถูกปิดใช้งาน'], 'feature_disabled', 'truemoney_disabled');
}
$tmPhone = preg_replace('/\D+/', '', (string) getSetting('truemoney_phone'));
$tmDebug['configuration'] = [
    'enabled' => true,
    'receiver_phone_masked' => trueMoneyDebugMaskedPhone($tmPhone),
    'receiver_phone_length' => strlen($tmPhone),
    'provider' => trueMoneyProviderName(),
    'provider_display_name' => trueMoneyProviderDisplayName(),
    'provider_target' => trueMoneyDebugProviderUrlSummary((string) TM_API_URL),
    'provider_authorization_configured' => trueMoneyProviderName() === 'legacy_vercel' && defined('TRUEMONEY_PROVIDER_TOKEN') && trim((string) TRUEMONEY_PROVIDER_TOKEN) !== '',
    'fee_rate' => (float) TM_FEE_RATE,
    'fee_cap_thb' => (float) TM_FEE_CAP_THB,
];
if (preg_match('/^0\d{9}$/', $tmPhone) !== 1) {
    trueMoneyDebugError($tmDebug, 'configuration_invalid', 'receiver_phone_invalid', 'Configured TrueMoney receiver phone is invalid');
    $debugRespond($tmDebug, ['success' => false, 'message' => 'ยังไม่ได้ตั้งค่าเบอร์รับ TrueMoney อย่างถูกต้อง'], 'configuration_invalid', 'receiver_phone_invalid');
}
trueMoneyDebugEvent($tmDebug, 'configuration_validated');

$normalized = normalizeTrueMoneyVoucherUrl($_POST['voucher_url'] ?? '');
if (empty($normalized['success'])) {
    trueMoneyDebugError($tmDebug, 'voucher_validation_failed', 'voucher_url_invalid', (string) ($normalized['message'] ?? 'Invalid voucher URL'));
    $debugRespond($tmDebug, ['success' => false, 'message' => $normalized['message']], 'voucher_validation_failed', 'voucher_url_invalid');
}
$voucherHash = hash('sha256', (string) $normalized['token']);
$tmDebug['redemption']['voucher_fingerprint'] = substr($voucherHash, 0, 16);
trueMoneyDebugEvent($tmDebug, 'voucher_validated', ['voucher_fingerprint' => substr($voucherHash, 0, 16)]);

$reservation = beginTrueMoneyRedemption($voucherHash, $userId, $tmDebug);
if (empty($reservation['success'])) {
    $code = $lastDebugErrorCode($tmDebug, 'reservation_failed');
    $debugRespond($tmDebug, ['success' => false, 'message' => $reservation['message']], 'reservation_failed', $code);
}

$redemptionId = (int) $reservation['id'];
$tmDebug['redemption']['id'] = $redemptionId;
$tmDebug['redemption']['reservation_mode'] = (string) ($reservation['mode'] ?? '');
trueMoneyDebugCaptureState($tmDebug, $redemptionId, $userId);
trueMoneyDebugPersist($tmDebug);

if (($reservation['mode'] ?? '') === 'resume') {
    $amountThb = (float) $reservation['amount_thb'];
    trueMoneyDebugEvent($tmDebug, 'provider_call_skipped_resume_mode', ['stored_amount_thb' => $amountThb]);
} else {
    $providerResult = redeemAngpao($normalized['url'], $tmPhone, $tmDebug);
    trueMoneyDebugPersist($tmDebug);
    if (empty($providerResult['success'])) {
        markTrueMoneyRedemptionFailed(
            $redemptionId,
            $userId,
            (string) ($providerResult['message'] ?? 'Provider rejected voucher'),
            $tmDebug
        );
        $code = $lastDebugErrorCode($tmDebug, 'provider_failed');
        $debugRespond(
            $tmDebug,
            ['success' => false, 'message' => $providerResult['message'] ?? 'ไม่สามารถรับซองของขวัญได้'],
            'provider_failed',
            $code
        );
    }
    $amountThb = (float) $providerResult['amount'];
    if (!markTrueMoneyProviderConfirmed($redemptionId, $userId, $amountThb, $tmDebug)) {
        trueMoneyDebugError($tmDebug, 'provider_confirmation_persist_failed', 'provider_confirmation_persist_failed', 'Provider accepted the voucher but local provider-confirmed state was not persisted');
        $debugRespond(
            $tmDebug,
            ['success' => false, 'message' => 'รับซองสำเร็จ แต่ยังบันทึกสถานะไม่เสร็จ กรุณาส่งลิงก์เดิมอีกครั้ง'],
            'provider_confirmation_persist_failed',
            'provider_confirmation_persist_failed'
        );
    }
    trueMoneyDebugPersist($tmDebug);
}

$settlement = calculateTrueMoneySettlement($amountThb);
if (empty($settlement['success'])) {
    trueMoneyDebugError($tmDebug, 'settlement_failed', 'settlement_calculation_failed', (string) ($settlement['message'] ?? 'TrueMoney settlement calculation failed'));
    $debugRespond(
        $tmDebug,
        ['success' => false, 'message' => $settlement['message'] ?? 'ไม่สามารถคำนวณค่าธรรมเนียม TrueMoney ได้'],
        'settlement_failed',
        'settlement_calculation_failed'
    );
}
$amountThb = (float) $settlement['gross_amount_thb'];
$feeAmountThb = (float) $settlement['fee_amount_thb'];
$netAmountThb = (float) $settlement['net_amount_thb'];
$tmDebug['settlement'] = [
    'gross_amount_thb' => $amountThb,
    'fee_amount_thb' => $feeAmountThb,
    'net_amount_thb' => $netAmountThb,
    'fee_rate' => (float) TM_FEE_RATE,
    'fee_cap_thb' => (float) TM_FEE_CAP_THB,
];
trueMoneyDebugEvent($tmDebug, 'settlement_calculated', $tmDebug['settlement']);

$appCurrency = strtoupper((string) (getSetting('currency_name') ?: 'THB'));
$creditAmount = $netAmountThb;
$conversionNote = '';
$tmDebug['settlement']['app_currency'] = $appCurrency;
if ($appCurrency === 'USD') {
    trueMoneyDebugEvent($tmDebug, 'exchange_rate_lookup_started');
    $rate = getExchangeRateThbToUsd();
    if (!$rate || !is_finite((float) $rate) || (float) $rate <= 0) {
        trueMoneyDebugError($tmDebug, 'exchange_rate_lookup_failed', 'exchange_rate_unavailable', 'Provider accepted the voucher but THB to USD exchange rate is unavailable');
        $debugRespond(
            $tmDebug,
            ['success' => false, 'message' => 'รับซองสำเร็จแล้ว แต่ยังดึงอัตราแลกเปลี่ยนไม่ได้ กรุณาส่งลิงก์เดิมอีกครั้ง'],
            'exchange_rate_lookup_failed',
            'exchange_rate_unavailable'
        );
    }
    $creditAmount = round($netAmountThb * (float) $rate, 2, PHP_ROUND_HALF_UP);
    $conversionNote = ' (Net converted from ' . number_format($netAmountThb, 2, '.', '') . ' THB)';
    $tmDebug['settlement']['exchange_rate_thb_to_usd'] = (float) $rate;
    trueMoneyDebugEvent($tmDebug, 'exchange_rate_applied', ['rate' => (float) $rate, 'credit_amount' => $creditAmount]);
} elseif ($appCurrency !== 'THB') {
    trueMoneyDebugError($tmDebug, 'currency_unsupported', 'unsupported_store_currency', 'TrueMoney supports store currency THB or USD only');
    $debugRespond(
        $tmDebug,
        ['success' => false, 'message' => 'TrueMoney รองรับสกุลเงินร้านค้า THB หรือ USD เท่านั้น'],
        'currency_unsupported',
        'unsupported_store_currency'
    );
}
if (!is_finite($creditAmount) || $creditAmount <= 0) {
    trueMoneyDebugError($tmDebug, 'credit_amount_invalid', 'net_credit_invalid', 'Calculated TrueMoney net credit is invalid');
    $debugRespond(
        $tmDebug,
        ['success' => false, 'message' => 'ยอดเครดิตสุทธิหลังหักค่าธรรมเนียมไม่ถูกต้อง'],
        'credit_amount_invalid',
        'net_credit_invalid'
    );
}
$tmDebug['settlement']['credit_amount'] = $creditAmount;
trueMoneyDebugPersist($tmDebug);

$completed = completeTrueMoneyRedemption(
    $redemptionId,
    $userId,
    $amountThb,
    $feeAmountThb,
    $netAmountThb,
    $creditAmount,
    $conversionNote,
    $tmDebug
);
if (empty($completed['success'])) {
    $code = $lastDebugErrorCode($tmDebug, 'wallet_completion_failed');
    $debugRespond($tmDebug, ['success' => false, 'message' => $completed['message']], 'wallet_completion_failed', $code);
}

trueMoneyDebugCaptureState($tmDebug, $redemptionId, $userId);
trueMoneyDebugEvent($tmDebug, 'response_ready', [
    'amount_thb' => $amountThb,
    'fee_thb' => $feeAmountThb,
    'net_amount_thb' => $netAmountThb,
    'amount_credit' => $creditAmount,
    'bonus_amount' => round((float) ($completed['bonus_amount'] ?? 0), 2),
    'total_credited' => round((float) ($completed['total_credited'] ?? $creditAmount), 2),
]);
trueMoneyDebugSetResult($tmDebug, true, 'completed', 'truemoney_credit_completed');
trueMoneyDebugPersist($tmDebug);

ob_clean();
jsonResponse([
    'success' => true,
    'message' => 'เติมเงินสำเร็จหลังหักค่าธรรมเนียม TrueMoney',
    'amount_thb' => $amountThb,
    'fee_thb' => $feeAmountThb,
    'net_amount_thb' => $netAmountThb,
    'fee_rate_percent' => (float) TM_FEE_RATE * 100,
    'fee_cap_thb' => (float) TM_FEE_CAP_THB,
    'amount_credit' => $creditAmount,
    'amount_formatted' => formatCurrency($creditAmount),
    'bonus_amount' => round((float) ($completed['bonus_amount'] ?? 0), 2),
    'total_credited' => round((float) ($completed['total_credited'] ?? $creditAmount), 2),
    'new_balance' => $completed['new_balance'],
    'new_balance_formatted' => formatCurrency($completed['new_balance']),
]);
