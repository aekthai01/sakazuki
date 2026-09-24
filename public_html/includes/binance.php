<?php
/**
 * =======================================
 * Binance USDT Deposit System (TRC20)
 * =======================================
 * Verify deposits via Binance API (HMAC signed)
 * Convert USD → THB, prevent duplicates, race conditions
 */

/**
 * Binance API Config
 */
require_once __DIR__ . '/wallet_ledger.php';

if (!defined('BINANCE_API_BASE')) {
    define('BINANCE_API_BASE', 'https://api.binance.com');
}
require_once __DIR__ . '/binance_time.php';

// ─────────────────────────────────────────
// Settings
// ─────────────────────────────────────────

function getBinanceSettings()
{
    return [
        'api_key'    => getSetting('binance_api_key') ?: '',
        'secret_key' => getSetting('binance_secret_key') ?: '',
        'wallet'     => getSetting('binance_wallet') ?: '',
        'enabled'    => getSetting('binance_enabled') === '1',
    ];
}

// ─────────────────────────────────────────
// DB Schema
// ─────────────────────────────────────────

function ensureBinanceDepositsTable()
{
    global $conn;
    return (bool) $conn->query("CREATE TABLE IF NOT EXISTS binance_deposits (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        tx_id VARCHAR(128) NOT NULL UNIQUE,
        coin VARCHAR(20) NOT NULL DEFAULT 'USDT',
        network VARCHAR(20) NOT NULL DEFAULT 'TRX',
        amount_usdt DECIMAL(16,8) NOT NULL,
        exchange_rate DECIMAL(16,6) NOT NULL DEFAULT 0,
        amount_thb DECIMAL(16,2) NOT NULL DEFAULT 0,
        binance_status INT NOT NULL DEFAULT 0,
        address VARCHAR(128) NOT NULL DEFAULT '',
        api_response TEXT,
        processing TINYINT NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_id (user_id),
        INDEX idx_tx_id (tx_id),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

// ─────────────────────────────────────────
// Duplicate / Lock checks
// ─────────────────────────────────────────

/**
 * Check if TxID has already been processed
 */
function isBinanceTxAlreadyUsed($txId)
{
    global $conn;
    $txId = trim((string) $txId);
    $stmt = $conn->prepare('SELECT id FROM binance_deposits WHERE tx_id = ? LIMIT 1');
    if (!$stmt) return true;
    $stmt->bind_param('s', $txId);
    if (!$stmt->execute() || !$stmt->store_result()) {
        $stmt->close();
        return true;
    }
    $used = $stmt->num_rows > 0;
    $stmt->close();
    return $used;
}

/**
 * Try to acquire processing lock (race condition guard)
 * Returns true if lock acquired, false if already being processed
 */
function acquireBinanceLock($txId)
{
    global $conn;
    ensureBinanceDepositsTable();
    $txId = trim((string) $txId);

    $stmt = $conn->prepare('INSERT INTO binance_deposits (user_id, tx_id, amount_usdt, processing) VALUES (0, ?, 0, 1)');
    if (!$stmt) return false;
    $stmt->bind_param('s', $txId);
    $ok = $stmt->execute(); // Duplicate key means already processing/used.
    $stmt->close();
    return $ok;
}

/**
 * Release processing lock (delete placeholder row on failure)
 */
function releaseBinanceLock($txId)
{
    global $conn;
    $txId = trim((string) $txId);
    $stmt = $conn->prepare('DELETE FROM binance_deposits WHERE tx_id = ? AND processing = 1 AND user_id = 0');
    if (!$stmt) return false;
    $stmt->bind_param('s', $txId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function cleanupStaleBinanceLocks(): void
{
    global $conn;
    // A crashed request must not block a valid transaction forever.
    $conn->query("DELETE FROM binance_deposits WHERE processing = 1 AND user_id = 0 AND created_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
}

// ─────────────────────────────────────────
// Binance API (HMAC Signed Request)
// ─────────────────────────────────────────

/**
 * Make a signed GET request to Binance API
 */
function binanceSignedRequest($endpoint, $params = [])
{
    $settings = getBinanceSettings();
    $apiKey = $settings['api_key'];
    $secretKey = $settings['secret_key'];

    if (empty($apiKey) || empty($secretKey)) {
        return ['error' => true, 'message' => 'ยังไม่ได้ตั้งค่า Binance API Key หรือ Secret Key'];
    }
    if (!is_string($endpoint) || strpos($endpoint, '/sapi/') !== 0) {
        return ['error' => true, 'message' => 'Binance endpoint ไม่ถูกต้อง'];
    }
    if (!function_exists('curl_init') || !function_exists('configureBoundedCurlResponse')) {
        return ['error' => true, 'message' => 'ระบบ Binance ไม่พร้อมใช้งาน'];
    }

    $baseParams = is_array($params) ? $params : [];
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $requestParams = $baseParams;
        $clock = binanceClockTimestamp($attempt === 1);
        $requestParams['recvWindow'] = 5000;
        $requestParams['timestamp'] = $clock['timestamp'];

        // Build query string and sign using RFC3986 (spaces as %20), as Binance expects.
        $queryString = http_build_query($requestParams, '', '&', PHP_QUERY_RFC3986);
        $signature = hash_hmac('sha256', $queryString, $secretKey);
        $queryString .= '&signature=' . $signature;
        $url = BINANCE_API_BASE . $endpoint . '?' . $queryString;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ['X-MBX-APIKEY: ' . $apiKey, 'Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'SakazukiBinance/1.1',
        ]);
        $response = '';
        $tooLarge = false;
        configureBoundedCurlResponse($ch, $response, $tooLarge, 1024 * 1024);
        $executed = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrorNo = curl_errno($ch);
        curl_close($ch);

        if ($executed === false || $curlErrorNo !== 0 || $tooLarge) {
            error_log('Binance connection failed; curl=' . $curlErrorNo . '; oversized=' . ($tooLarge ? '1' : '0'));
            return ['error' => true, 'message' => 'การเชื่อมต่อ Binance ล้มเหลว'];
        }

        $result = json_decode($response, true, 64);
        if ($httpCode === 200) {
            return ['error' => false, 'data' => $result];
        }

        $code = is_array($result) ? (string) ($result['code'] ?? $httpCode) : (string) $httpCode;
        if ($code === '-1021' && $attempt === 0) {
            error_log(
                'Binance timestamp rejected; retrying after forced clock sync; offset_ms=' . (int) ($clock['offset_ms'] ?? 0)
                . '; rtt_ms=' . (int) ($clock['rtt_ms'] ?? 0)
                . '; synced=' . (!empty($clock['synced']) ? '1' : '0')
            );
            continue;
        }

        error_log('Binance request rejected; http=' . $httpCode . '; code=' . substr($code, 0, 32));
        if ($code === '-1021') {
            return ['error' => true, 'message' => 'Binance ปฏิเสธเวลา request แม้ระบบซิงก์เวลาอัตโนมัติแล้ว กรุณาลองใหม่อีกครั้ง'];
        }
        return ['error' => true, 'message' => 'Binance ปฏิเสธคำขอ กรุณาตรวจสอบ API Key และสิทธิ์ของ API'];
    }

    return ['error' => true, 'message' => 'Binance ปฏิเสธคำขอ'];
}

/**
 * Normalize Binance TxID to one canonical form.
 *
 * Binance changed internal-transfer TxIDs to use the prefix
 * "Off-chain transfer". Users may paste different capitalization,
 * extra whitespace, a non-breaking space, or "off-chain" with a hyphen.
 */
function normalizeBinanceTxId($txId)
{
    if (!is_string($txId) && !is_scalar($txId)) {
        return '';
    }

    $txId = trim((string) $txId);
    if ($txId === '') {
        return '';
    }

    // Convert Unicode space characters commonly introduced by mobile copy/paste.
    $txId = preg_replace('/[\x{00A0}\x{1680}\x{2000}-\x{200B}\x{202F}\x{205F}\x{3000}]+/u', ' ', $txId);
    if (!is_string($txId)) {
        return '';
    }
    $txId = preg_replace('/\s+/u', ' ', trim($txId));
    if (!is_string($txId)) {
        return '';
    }

    // Internal Binance transfer. Keep the opaque reference exactly as supplied,
    // but canonicalize the human-readable prefix so duplicate checks are stable.
    if (preg_match('/^off[\s-]*chain[\s-]*transfer[\s:]+([A-Za-z0-9][A-Za-z0-9_-]{5,100})$/i', $txId, $matches)) {
        return 'Off-chain transfer ' . $matches[1];
    }

    // A TRON transaction hash is exactly 64 hexadecimal characters.
    if (preg_match('/^[a-fA-F0-9]{64}$/', $txId)) {
        return strtolower($txId);
    }

    return $txId;
}

/**
 * Validate a normalized Binance TxID.
 */
function isValidBinanceTxIdFormat($txId)
{
    $txId = normalizeBinanceTxId($txId);
    if ($txId === '' || strlen($txId) > 128) {
        return false;
    }

    return (bool) (
        preg_match('/^[a-f0-9]{64}$/', $txId)
        || preg_match('/^Off-chain transfer [A-Za-z0-9][A-Za-z0-9_-]{5,100}$/', $txId)
    );
}

/**
 * Extract the opaque reference from an internal Binance transfer TxID.
 */
function getBinanceOffChainReference($txId)
{
    $txId = normalizeBinanceTxId($txId);
    if (preg_match('/^Off-chain transfer ([A-Za-z0-9][A-Za-z0-9_-]{5,100})$/', $txId, $matches)) {
        return $matches[1];
    }
    return null;
}

/**
 * Compare Binance TxIDs after canonicalization.
 * The suffix-only fallback covers older/history representations while still
 * requiring the complete internal reference to match.
 */
function binanceTxIdsMatch($left, $right)
{
    $leftNormalized = normalizeBinanceTxId($left);
    $rightNormalized = normalizeBinanceTxId($right);

    if ($leftNormalized === '' || $rightNormalized === '') {
        return false;
    }
    if (strcasecmp($leftNormalized, $rightNormalized) === 0) {
        return true;
    }

    $leftRef = getBinanceOffChainReference($leftNormalized);
    $rightRef = getBinanceOffChainReference($rightNormalized);
    if ($leftRef !== null && $rightRef !== null) {
        return strcasecmp($leftRef, $rightRef) === 0;
    }

    // Some historical responses may contain only the internal reference.
    if ($leftRef !== null && preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{5,100}$/', $rightNormalized)) {
        return strcasecmp($leftRef, $rightNormalized) === 0;
    }
    if ($rightRef !== null && preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{5,100}$/', $leftNormalized)) {
        return strcasecmp($rightRef, $leftNormalized) === 0;
    }

    return false;
}

/**
 * Find one exact deposit record in a Binance history response.
 */
function findBinanceDepositByTxId($deposits, $txId)
{
    if (!is_array($deposits)) {
        return null;
    }

    foreach ($deposits as $deposit) {
        if (!is_array($deposit)) {
            continue;
        }
        $candidate = $deposit['txId'] ?? '';
        if (is_scalar($candidate) && binanceTxIdsMatch((string) $candidate, $txId)) {
            return $deposit;
        }
    }

    return null;
}

// ─────────────────────────────────────────
// Verify Deposit via Binance API
// ─────────────────────────────────────────

/**
 * Verify a deposit transaction on Binance
 * @param string $txId Transaction hash
 * @return array [success, data, message]
 */
function verifyBinanceDeposit($txId)
{
    $txId = normalizeBinanceTxId($txId);
    if ($txId === '') {
        return ['success' => false, 'message' => 'กรุณากรอก Transaction ID'];
    }
    if (!isValidBinanceTxIdFormat($txId)) {
        return ['success' => false, 'message' => 'รูปแบบ Transaction ID ไม่ถูกต้อง กรุณาใช้ TxID ของ TRC20 จำนวน 64 ตัวอักษร หรือข้อความ Off-chain transfer พร้อมเลขอ้างอิง'];
    }

    $settings = getBinanceSettings();
    $isOffChain = getBinanceOffChainReference($txId) !== null;

    /*
     * Binance officially supports the txId filter on this endpoint, including
     * internal transfers whose TxID starts with "Off-chain transfer". Query the
     * exact ID first. A bounded history scan is kept as a compatibility fallback
     * for accounts/API responses that do not apply the filter consistently.
     */
    $result = binanceSignedRequest('/sapi/v1/capital/deposit/hisrec', [
        'coin' => 'USDT',
        'txId' => $txId,
        'limit' => 1000,
    ]);

    if ($result['error'] && !$isOffChain) {
        return ['success' => false, 'message' => $result['message']];
    }

    $deposits = !$result['error'] && is_array($result['data'] ?? null) ? $result['data'] : [];
    $deposit = findBinanceDepositByTxId($deposits, $txId);

    if (!$deposit && $isOffChain) {
        $clockNow = binanceClockTimestamp();
        $nowMs = (float) $clockNow['timestamp'];
        $ninetyDaysMs = 90 * 24 * 60 * 60 * 1000;
        $fallback = binanceSignedRequest('/sapi/v1/capital/deposit/hisrec', [
            'coin' => 'USDT',
            'startTime' => sprintf('%.0f', $nowMs - $ninetyDaysMs),
            'endTime' => sprintf('%.0f', $nowMs),
            'offset' => 0,
            'limit' => 1000,
        ]);

        if ($fallback['error']) {
            return ['success' => false, 'message' => $fallback['message']];
        }

        $fallbackDeposits = is_array($fallback['data'] ?? null) ? $fallback['data'] : [];
        $deposit = findBinanceDepositByTxId($fallbackDeposits, $txId);
    }

    if (!$deposit) {
        return [
            'success' => false,
            'message' => 'ไม่พบรายการฝากเงินที่ตรงกับ TxID นี้ในบัญชี Binance ของระบบ กรุณาตรวจสอบว่าโอนเข้าที่อยู่ที่แสดงบนเว็บไซต์ รายการขึ้น Completed แล้ว และรอให้ประวัติฝากเงินของ Binance อัปเดต',
        ];
    }

    // Binance deposit status 1 means success. Status 6 is credited but locked,
    // therefore it must not be credited to the website balance yet.
    $depositStatusRaw = $deposit['status'] ?? null;
    $depositStatus = is_scalar($depositStatusRaw) && is_numeric((string) $depositStatusRaw)
        ? (int) $depositStatusRaw
        : -1;
    if ($depositStatus !== 1) {
        $statusMap = [
            0 => 'รอดำเนินการ',
            2 => 'ถูกปฏิเสธ',
            6 => 'เครดิตแล้วแต่ยังถูกล็อก',
            7 => 'รายการฝากผิดเงื่อนไข',
            8 => 'รอผู้ใช้ยืนยัน',
        ];
        $statusText = $statusMap[$depositStatus] ?? 'ไม่ทราบสถานะ';
        return ['success' => false, 'message' => "Transaction ยังไม่พร้อมใช้งาน (สถานะ: $statusText) กรุณารอแล้วลองใหม่"];
    }

    $coinRaw = $deposit['coin'] ?? '';
    $coin = is_scalar($coinRaw) ? strtoupper(trim((string) $coinRaw)) : '';
    if ($coin !== 'USDT') {
        return ['success' => false, 'message' => 'เหรียญที่โอนไม่ใช่ USDT ระบบรองรับเฉพาะ USDT เท่านั้น'];
    }

    $networkRaw = $deposit['network'] ?? '';
    $network = is_scalar($networkRaw) ? strtoupper(trim((string) $networkRaw)) : '';
    if ($network !== '' && $network !== 'TRX' && $network !== 'TRC20') {
        return ['success' => false, 'message' => 'เครือข่ายที่โอนไม่ใช่ TRC20 กรุณาโอนผ่าน TRC20 เท่านั้น'];
    }
    if (!$isOffChain && $network === '') {
        return ['success' => false, 'message' => 'Binance ไม่ส่งข้อมูลเครือข่ายกลับมา จึงยังไม่สามารถยืนยันรายการนี้ได้'];
    }

    $expectedWallet = strtolower(trim((string) ($settings['wallet'] ?? '')));
    $addressRaw = $deposit['address'] ?? '';
    $depositAddress = is_scalar($addressRaw) ? strtolower(trim((string) $addressRaw)) : '';

    if ($expectedWallet === '') {
        return ['success' => false, 'message' => 'ยังไม่ได้ตั้งค่าที่อยู่กระเป๋า Binance ของระบบ'];
    }
    if ($depositAddress !== '' && $depositAddress !== $expectedWallet) {
        return ['success' => false, 'message' => 'ที่อยู่ปลายทางของรายการไม่ตรงกับ Wallet ของระบบ'];
    }
    if (!$isOffChain && $depositAddress === '') {
        return ['success' => false, 'message' => 'Binance ไม่ส่งข้อมูลที่อยู่ปลายทางกลับมา จึงยังไม่สามารถยืนยันรายการนี้ได้'];
    }

    $amountRaw = $deposit['amount'] ?? null;
    if (!is_scalar($amountRaw) || !is_numeric((string) $amountRaw)) {
        return ['success' => false, 'message' => 'จำนวนเงินไม่ถูกต้อง'];
    }
    $amount = (float) $amountRaw;
    if (!is_finite($amount) || $amount <= 0 || $amount > 1000000) {
        return ['success' => false, 'message' => 'จำนวนเงินไม่ถูกต้อง'];
    }

    return [
        'success' => true,
        'data' => [
            'tx_id'   => $txId,
            'coin'    => $coin,
            'network' => $isOffChain ? 'OFFCHAIN' : $network,
            'amount'  => $amount,
            'address' => $depositAddress,
            'status'  => $depositStatus,
        ],
    ];
}

// ─────────────────────────────────────────
// Exchange Rate USD → THB
// ─────────────────────────────────────────

/**
 * Get USD to THB exchange rate
 */
function getExchangeRateUsdToThb()
{
    // Use the administrator-controlled accounting rate for every USD/THB flow.
    // This keeps Binance/USDT deposits consistent with catalogue pricing and
    // removes a third-party network dependency from a money-crediting path.
    if (function_exists('getUsdToThbRate')) {
        $rate = (float) getUsdToThbRate();
        return is_finite($rate) && $rate >= 1.0 && $rate <= 1000.0 ? $rate : 0.0;
    }
    return 35.0;
}

// ─────────────────────────────────────────
// Process Deposit (Full Flow)
// ─────────────────────────────────────────

/**
 * Full deposit processing: verify → convert → credit balance
 * @param int $userId
 * @param string $txId
 * @return array [success, amount_usdt, amount_thb, rate, message]
 */
function processBinanceDeposit($userId, $txId)
{
    global $conn;
    $txId = normalizeBinanceTxId($txId);
    $userId = (int) $userId;
    $depositUser = $userId > 0 ? getUserById($userId) : null;
    if (!$depositUser || ($depositUser['status'] ?? '') !== 'active') {
        return ['success' => false, 'message' => Lang::t('common.error.invalid_request')];
    }
    $appCurrencyName = strtoupper((string) (getSetting('currency_name') ?: 'THB'));
    if (!in_array($appCurrencyName, ['THB', 'USD'], true)) {
        return ['success' => false, 'message' => 'ระบบเติมเงิน Binance รองรับสกุลเงินร้านค้า THB หรือ USD เท่านั้น'];
    }

    if (!ensureBinanceDepositsTable()) {
        error_log('Unable to prepare binance_deposits table');
        return ['success' => false, 'message' => Lang::t('common.error.operation')];
    }
    cleanupStaleBinanceLocks();

    // Step 1: Quick duplicate check (before API call to save quota)
    if (isBinanceTxAlreadyUsed($txId)) {
        // Best-effort migration of an older local record into the shared ledger.
        $historicClaim = sharedLedgerBegin('binance_tx', $txId, 300);
        if (!empty($historicClaim['success'])) {
            sharedLedgerReserve($historicClaim['lease']);
            sharedLedgerComplete($historicClaim['lease']);
        }
        return ['success' => false, 'message' => Lang::t('deposit.error.binance_tx_used')];
    }

    // Step 2: Acquire lock (atomic — prevents race condition from double-click)
    if (!acquireBinanceLock($txId)) {
        return ['success' => false, 'message' => Lang::t('deposit.error.binance_tx_processing')];
    }

    $sharedClaim = sharedLedgerBegin('binance_tx', $txId, 300);
    if (empty($sharedClaim['success'])) {
        releaseBinanceLock($txId);
        return [
            'success' => false,
            'message' => !empty($sharedClaim['duplicate'])
                ? ((string) ($sharedClaim['status'] ?? '') === 'processing'
                    ? Lang::t('deposit.error.binance_tx_processing')
                    : Lang::t('deposit.error.binance_tx_used'))
                : 'ระบบเชื่อมข้อมูลระหว่างสองเว็บไซต์ขัดข้องชั่วคราว กรุณาลองใหม่อีกครั้ง',
        ];
    }
    $sharedLease = $sharedClaim['lease'];
    $releaseDepositLocks = static function () use (&$sharedLease, $txId): void {
        if (is_array($sharedLease)) {
            $released = sharedLedgerRelease($sharedLease);
            if (empty($released['success'])) error_log('Unable to release shared Binance claim');
            $sharedLease = null;
        }
        releaseBinanceLock($txId);
    };

    try {
        // Step 3: Verify with Binance API
        $verification = verifyBinanceDeposit($txId);
        if (!$verification['success']) {
            $releaseDepositLocks();
            return ['success' => false, 'message' => $verification['message']];
        }

        $depositData = $verification['data'];
        $amountUsdt = $depositData['amount'];

        // Step 4: Credit amount in configured currency
        // - If app currency is THB: convert USDT(≈USD) → THB
        // - Otherwise: credit as USDT amount (treated as USD-like) without converting
        $rate = 0.0;
        $rankAmountThb = 0.0;
        if ($appCurrencyName === 'THB') {
            $rate = (float) getExchangeRateUsdToThb();
            $creditAmount = round($amountUsdt * $rate, 2);
            $rankAmountThb = $creditAmount;
        } elseif ($appCurrencyName === 'USD') {
            $creditAmount = round($amountUsdt, 2);
            $rankRate = (float) getExchangeRateUsdToThb();
            $rankAmountThb = round($amountUsdt * $rankRate, 2);
            if (!is_finite($rankRate) || $rankRate <= 0 || $rankAmountThb <= 0) {
                $releaseDepositLocks();
                return ['success' => false, 'message' => Lang::t('deposit.error.exchange_rate_failed')];
            }
        } else {
            $releaseDepositLocks();
            return ['success' => false, 'message' => Lang::t('common.error.operation')];
        }

        if (!is_finite($creditAmount) || $creditAmount <= 0 || $creditAmount > 10000000) {
            $releaseDepositLocks();
            return ['success' => false, 'message' => Lang::t('deposit.error.exchange_rate_failed')];
        }
        if (!function_exists('ensureRankingSchema') || !ensureRankingSchema()) {
            $releaseDepositLocks();
            return ['success' => false, 'message' => Lang::t('ranking.error.unavailable')];
        }
        if (!ensureWalletLedgerSchema()) {
            $releaseDepositLocks();
            return ['success' => false, 'message' => 'ระบบบันทึกหลักฐานยอดเงินยังไม่พร้อม กรุณาลองใหม่อีกครั้ง'];
        }

        $reserved = sharedLedgerReserve($sharedLease);
        if (empty($reserved['success'])) {
            $releaseDepositLocks();
            return ['success' => false, 'message' => 'ระบบล็อกรายการเติมเงินขัดข้องชั่วคราว กรุณาลองใหม่อีกครั้ง'];
        }

        // Step 5: Update the lock row, credit balance, and decide rank bonus atomically.
        $conn->begin_transaction();

        try {
            $coin = (string) $depositData['coin'];
            $network = (string) $depositData['network'];
            $address = (string) $depositData['address'];
            $apiResp = json_encode(['provider' => 'binance', 'verified' => true, 'stored_at' => date('c')], JSON_UNESCAPED_SLASHES);

            $amountUsdtFmt = number_format($amountUsdt, 8, '.', '');
            $rateFmt = number_format($rate, 6, '.', '');
            $amountThbFmt = number_format($creditAmount, 2, '.', '');

            $stmt = $conn->prepare('UPDATE binance_deposits SET user_id = ?, coin = ?, network = ?, amount_usdt = ?, exchange_rate = ?, amount_thb = ?, binance_status = 1, address = ?, api_response = ?, processing = 0 WHERE tx_id = ? AND processing = 1');
            if (!$stmt) {
                throw new Exception(Lang::t('deposit.error.save_failed'));
            }
            $stmt->bind_param('issdddsss', $userId, $coin, $network, $amountUsdtFmt, $rateFmt, $amountThbFmt, $address, $apiResp, $txId);
            $stmtOk = $stmt->execute();
            $stmtAffected = $stmt->affected_rows;
            $stmt->close();
            if (!$stmtOk || $stmtAffected === 0) {
                throw new Exception(Lang::t('deposit.error.save_failed'));
            }

            // Add balance (in configured currency) and capture the exact
            // before/after chain inside this same transaction.
            $walletBefore = walletLedgerReadBalance($userId, true);
            if ($walletBefore === null) {
                throw new Exception(Lang::t('deposit.error.add_balance_failed'));
            }
            if (!addBalance($userId, $creditAmount)) {
                throw new Exception(Lang::t('deposit.error.add_balance_failed'));
            }

            $desc = ($appCurrencyName === 'THB')
                ? "Binance USDT deposit: $amountUsdt USDT (Rate: $rateFmt) TxID: $txId"
                : "Binance USDT deposit: $amountUsdt USDT TxID: $txId";
            $depositTransactionId = createTransaction($userId, 'deposit', $creditAmount, 'completed', $desc);
            if (!$depositTransactionId) {
                throw new Exception(Lang::t('deposit.error.save_failed'));
            }
            $walletAfter = round((float) $walletBefore + $creditAmount, 2);
            if (!walletLedgerRecordMovement(
                $userId, $creditAmount, (float) $walletBefore, $walletAfter,
                'binance_deposit', 'transaction:' . (int) $depositTransactionId,
                null, (int) $depositTransactionId, null,
                'เติมเงินผ่าน Binance USDT',
                'Verified Binance deposit credit.',
                $txId, true
            )) {
                throw new Exception(Lang::t('deposit.error.save_failed'));
            }

            $rankResult = rankRecordDepositAndApplyBonus($userId, (int) $depositTransactionId, $rankAmountThb, 'binance', 'usd_to_thb_rate');
            if (empty($rankResult['success'])) {
                throw new Exception((string) ($rankResult['message'] ?? Lang::t('deposit.error.save_failed')));
            }

            $logTail = ($appCurrencyName === 'THB')
                ? (" (Rate: $rateFmt)")
                : (" (Credited as $appCurrencyName)");

            if (!$conn->commit()) {
                throw new Exception(Lang::t('deposit.error.save_failed'));
            }

            // Audit history must not be able to reverse a verified Binance
            // credit after all financial integrity checks have succeeded.
            if (!logHistory($userId, 'binance_deposit', "Binance deposit: $amountUsdt USDT = " . formatCurrency($creditAmount) . $logTail . " - TxID: $txId")) {
                error_log('Binance deposit committed but audit history could not be written; tx_id=' . $txId . '; transaction=' . (int) $depositTransactionId);
            }

            $completedClaim = sharedLedgerComplete($sharedLease);
            if (empty($completedClaim['success'])) {
                // Keep the central reservation blocked if acknowledgement is
                // lost after the local financial transaction was committed.
                error_log('Unable to finalize shared Binance claim');
            }
            $sharedLease = null;

            $bonusAmount = round((float) ($rankResult['bonus_amount'] ?? 0), 2);
            $totalCredited = round((float) ($rankResult['total_credited'] ?? $creditAmount), 2);
            return [
                'success'       => true,
                'amount_usdt'   => $amountUsdt,
                'amount_thb'    => $creditAmount,
                'rate'          => $rate,
                'bonus_amount'  => $bonusAmount,
                'total_credited'=> $totalCredited,
                'new_balance'   => (float) getUserBalance($userId),
                'message'       => ($appCurrencyName === 'THB')
                    ? ('เติมเงินสำเร็จ ' . number_format($amountUsdt, 2) . ' USDT = ' . formatCurrency($totalCredited) . ' THB')
                    : ('เติมเงินสำเร็จ ' . number_format($amountUsdt, 2) . ' USDT = ' . formatCurrency($totalCredited) . ' ' . $appCurrencyName),
            ];

        } catch (Throwable $e) {
            $conn->rollback();
            $releaseDepositLocks();
            error_log('Binance deposit transaction failed: ' . $e->getMessage());
            return ['success' => false, 'message' => Lang::t('common.error.operation')];
        }

    } catch (Throwable $e) {
        $releaseDepositLocks();
        error_log('Binance deposit processing failed: ' . $e->getMessage());
        return ['success' => false, 'message' => Lang::t('common.error.operation')];
    }
}
