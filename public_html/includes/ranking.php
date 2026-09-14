<?php
/**
 * Monthly user ranks, deposit bonuses, and lifetime deposit leaderboards.
 *
 * Financial rules:
 * - Only completed transactions with type="deposit" are eligible.
 * - Monthly rank is available only to current role="user" accounts.
 * - Resellers appear only on the lifetime leaderboard and receive no rank bonus.
 * - Administrators are excluded from every ranking and bonus calculation.
 * - Rank periods follow Asia/Bangkok calendar months and reset automatically.
 * - Bonus percentage is based on the rank held before the current deposit. The
 *   current deposit may unlock a higher rank for the next deposit, never itself.
 * - A unique decision row is created for every eligible user deposit, including
 *   deposits that receive no bonus, so old deposits can never be rewarded later.
 */

require_once __DIR__ . '/wallet_ledger.php';

if (!defined('RANK_TIMEZONE')) define('RANK_TIMEZONE', 'Asia/Bangkok');
if (!defined('RANK_MONTHLY_BOARD_LIMIT')) define('RANK_MONTHLY_BOARD_LIMIT', 10);
if (!defined('RANK_LIFETIME_BOARD_LIMIT')) define('RANK_LIFETIME_BOARD_LIMIT', 50);

/** @return array<string,array{threshold:float,bonus_percent:float,priority:int}> */
function rankDefinitions(): array
{
    return [
        'unranked' => ['threshold' => 0.00, 'bonus_percent' => 0.00, 'priority' => 0],
        'bronze'   => ['threshold' => 500.00, 'bonus_percent' => 3.00, 'priority' => 1],
        'silver'   => ['threshold' => 1500.00, 'bonus_percent' => 5.00, 'priority' => 2],
        'gold'     => ['threshold' => 3000.00, 'bonus_percent' => 10.00, 'priority' => 3],
        'platinum' => ['threshold' => 5000.00, 'bonus_percent' => 20.00, 'priority' => 4],
    ];
}

function rankCodeForAmount(float $qualifyingAmountThb): string
{
    $safeAmount = is_finite($qualifyingAmountThb) ? max(0.0, $qualifyingAmountThb) : 0.0;
    $amountCents = (int) round($safeAmount * 100);
    $selectedCode = 'unranked';
    $selectedThresholdCents = -1;
    $selectedPriority = PHP_INT_MIN;

    // Derive the active rank from rankDefinitions() instead of duplicating
    // thresholds here. This keeps bonus decisions, boards and UI in sync when
    // a rank is added or its threshold changes later.
    foreach (rankDefinitions() as $code => $definition) {
        $thresholdCents = (int) round(max(0.0, (float) ($definition['threshold'] ?? 0.0)) * 100);
        $priority = (int) ($definition['priority'] ?? 0);
        if ($amountCents < $thresholdCents) continue;
        if ($thresholdCents > $selectedThresholdCents
            || ($thresholdCents === $selectedThresholdCents && $priority > $selectedPriority)) {
            $selectedCode = (string) $code;
            $selectedThresholdCents = $thresholdCents;
            $selectedPriority = $priority;
        }
    }
    return $selectedCode;
}

/**
 * Build user-facing monthly progress toward the next rank.
 * Progress intentionally uses total-this-month / next-rank threshold because
 * that is the value displayed to customers (for example ฿3,600 / ฿5,000 = 72%).
 *
 * @return array{current_rank_code:string,current_bonus_percent:float,next_rank_code:?string,next_rank_threshold_thb:?float,next_rank_bonus_percent:float,remaining_to_next_thb:float,progress_percent:float,remaining_percent:float,is_max_rank:bool}
 */
function rankProgressForAmount(float $qualifyingAmountThb): array
{
    $amount = is_finite($qualifyingAmountThb) ? max(0.0, round($qualifyingAmountThb, 2)) : 0.0;
    $definitions = rankDefinitions();
    $currentCode = rankCodeForAmount($amount);
    $currentBonus = isset($definitions[$currentCode]) ? (float) $definitions[$currentCode]['bonus_percent'] : 0.0;

    $nextCode = null;
    $nextThreshold = null;
    $nextBonus = 0.0;
    $nextPriority = PHP_INT_MIN;
    foreach ($definitions as $code => $definition) {
        if ($code === 'unranked') continue;
        $threshold = max(0.0, round((float) ($definition['threshold'] ?? 0.0), 2));
        $priority = (int) ($definition['priority'] ?? 0);
        if ($threshold <= $amount) continue;
        if ($nextThreshold === null
            || $threshold < $nextThreshold
            || ($threshold === $nextThreshold && $priority > $nextPriority)) {
            $nextCode = (string) $code;
            $nextThreshold = $threshold;
            $nextBonus = (float) ($definition['bonus_percent'] ?? 0.0);
            $nextPriority = $priority;
        }
    }

    if ($nextCode === null || $nextThreshold === null || $nextThreshold <= 0) {
        return [
            'current_rank_code' => $currentCode,
            'current_bonus_percent' => $currentBonus,
            'next_rank_code' => null,
            'next_rank_threshold_thb' => null,
            'next_rank_bonus_percent' => 0.0,
            'remaining_to_next_thb' => 0.0,
            'progress_percent' => 100.0,
            'remaining_percent' => 0.0,
            'is_max_rank' => true,
        ];
    }

    $progressPercent = round(min(100.0, max(0.0, ($amount / $nextThreshold) * 100.0)), 2);
    // A value below the threshold must never render as 100.00% merely because
    // of display rounding (for example ฿4,999.99 / ฿5,000.00).
    if ($amount < $nextThreshold && $progressPercent >= 100.0) {
        $progressPercent = 99.99;
    }
    return [
        'current_rank_code' => $currentCode,
        'current_bonus_percent' => $currentBonus,
        'next_rank_code' => $nextCode,
        'next_rank_threshold_thb' => $nextThreshold,
        'next_rank_bonus_percent' => $nextBonus,
        'remaining_to_next_thb' => max(0.0, round($nextThreshold - $amount, 2)),
        'progress_percent' => $progressPercent,
        'remaining_percent' => round(max(0.0, 100.0 - $progressPercent), 2),
        'is_max_rank' => false,
    ];
}

function rankBonusPercentForCode(string $rankCode): float
{
    $definitions = rankDefinitions();
    return isset($definitions[$rankCode]) ? (float) $definitions[$rankCode]['bonus_percent'] : 0.0;
}

/**
 * Pure financial decision helper. Keeping this calculation independent from
 * SQL makes boundary and rounding behaviour testable without touching money.
 *
 * @return array{benefit_rank_code:string,result_rank_code:string,bonus_percent:float,bonus_amount:float}
 */
function rankCalculateBonusDecision(float $monthlyTotalBeforeThb, float $monthlyTotalAfterThb, float $baseCreditAmount): array
{
    $before = is_finite($monthlyTotalBeforeThb) ? max(0.0, round($monthlyTotalBeforeThb, 2)) : 0.0;
    $after = is_finite($monthlyTotalAfterThb) ? max($before, round($monthlyTotalAfterThb, 2)) : $before;
    $baseCents = is_finite($baseCreditAmount) ? max(0, (int) round($baseCreditAmount * 100)) : 0;
    $benefitRankCode = rankCodeForAmount($before);
    $resultRankCode = rankCodeForAmount($after);
    $bonusPercent = rankBonusPercentForCode($benefitRankCode);
    $bonusCents = $bonusPercent > 0 ? (int) round($baseCents * ($bonusPercent / 100)) : 0;
    return [
        'benefit_rank_code' => $benefitRankCode,
        'result_rank_code' => $resultRankCode,
        'bonus_percent' => $bonusPercent,
        'bonus_amount' => $bonusCents / 100,
    ];
}

function rankLabel(string $rankCode): string
{
    $key = 'ranking.rank.' . (isset(rankDefinitions()[$rankCode]) ? $rankCode : 'unranked');
    return class_exists('Lang') ? Lang::t($key) : ucfirst($rankCode);
}

function rankRoleLabel(string $role): string
{
    if ($role === 'reseller') return class_exists('Lang') ? Lang::t('ranking.role.reseller') : 'Reseller';
    if ($role === 'admin') return class_exists('Lang') ? Lang::t('ranking.role.admin') : 'Administrator';
    if ($role === 'user') return class_exists('Lang') ? Lang::t('ranking.role.user') : 'User';
    return $role !== '' ? $role : '-';
}

/** Replace the last three Unicode characters with ***; short names stay intact. */
function rankMaskUsername(string $username): string
{
    $username = trim($username);
    if ($username === '') return '-';
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        $length = mb_strlen($username, 'UTF-8');
        return $length <= 3 ? $username : mb_substr($username, 0, $length - 3, 'UTF-8') . '***';
    }
    // preg_split keeps Thai and other UTF-8 usernames intact even on hosts
    // without mbstring. Byte-based substr() can cut a character in half and
    // produce malformed output, which is a surprisingly creative way for a
    // leaderboard to break the rest of a page.
    $characters = preg_split('//u', $username, -1, PREG_SPLIT_NO_EMPTY);
    if (is_array($characters)) {
        $length = count($characters);
        return $length <= 3 ? $username : implode('', array_slice($characters, 0, $length - 3)) . '***';
    }
    // Invalid UTF-8 should not normally reach this point because usernames are
    // stored in utf8mb4. Fall back to a fully masked value rather than emitting
    // broken bytes or accidentally exposing the complete name.
    return strlen($username) <= 3 ? $username : '***';
}

/** Format normalized ranking amounts. Ranking totals are always stored in THB. */
function rankFormatThb(float $amount): string
{
    $safeAmount = is_finite($amount) ? max(0.0, round($amount, 2)) : 0.0;
    return '฿' . number_format($safeAmount, 2);
}

/** @return array{start:string,next:string,label:string} */
function rankMonthPeriod(?string $dateTime = null): array
{
    $timezone = new DateTimeZone(RANK_TIMEZONE);
    try {
        $date = $dateTime !== null && trim($dateTime) !== ''
            ? new DateTimeImmutable($dateTime, $timezone)
            : new DateTimeImmutable('now', $timezone);
    } catch (Throwable $e) {
        $date = new DateTimeImmutable('now', $timezone);
    }
    $start = $date->modify('first day of this month')->setTime(0, 0, 0);
    $next = $start->modify('first day of next month');
    return [
        'start' => $start->format('Y-m-d H:i:s'),
        'next' => $next->format('Y-m-d H:i:s'),
        'label' => $start->format('Y-m'),
    ];
}

function rankSchemaIsReady(): bool
{
    return !empty($GLOBALS['__ranking_schema_ready']);
}

function rankTransactionTypeCanStoreBonus(string $columnType): bool
{
    $columnType = strtolower(trim($columnType));
    if ($columnType === '') return false;
    if (strpos($columnType, 'enum(') === 0 || strpos($columnType, 'set(') === 0) {
        return preg_match("/(?:^|\\(|,)\\s*'rank_bonus'\\s*(?:,|\\))/i", substr($columnType, strpos($columnType, '('))) === 1;
    }
    return preg_match('/^(?:var)?char\(|^(?:tiny|medium|long)?text\b/i', $columnType) === 1;
}

/**
 * The project has existed through several database revisions. Some older
 * installations use an ENUM for transactions.type, while newer ones use a
 * VARCHAR. The dedicated rank_bonus_awards table is always the authoritative
 * audit record; a matching generic transaction is added only when the current
 * schema can represent it without truncation or a failed deposit.
 */
function rankTransactionTypeSupportsBonus(): bool
{
    global $conn;
    if (array_key_exists('__ranking_tx_type_supported', $GLOBALS)) {
        return (bool) $GLOBALS['__ranking_tx_type_supported'];
    }
    $supported = false;
    if (isset($conn) && $conn instanceof mysqli) {
        try {
            $result = $conn->query("SHOW COLUMNS FROM transactions LIKE 'type'");
            $column = $result ? $result->fetch_assoc() : null;
            if ($result) $result->free();
            $columnType = strtolower(trim((string) ($column['Type'] ?? '')));
            $supported = rankTransactionTypeCanStoreBonus($columnType);
        } catch (Throwable $e) {
            error_log('Ranking transaction type inspection failed: ' . $e->getMessage());
        }
    }
    $GLOBALS['__ranking_tx_type_supported'] = $supported;
    return $supported;
}

/**
 * Create the isolated ranking tables. This must run before a financial SQL
 * transaction starts because MySQL DDL may implicitly commit a transaction.
 */
function ensureRankingSchema(): bool
{
    global $conn;
    if (rankSchemaIsReady()) return true;
    if (!isset($conn) || !($conn instanceof mysqli)) {
        error_log('Ranking schema unavailable: database connection missing.');
        return false;
    }

    $ledgerSql = "CREATE TABLE IF NOT EXISTS rank_deposit_ledger (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        deposit_transaction_id BIGINT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        role_at_deposit VARCHAR(20) NOT NULL,
        credited_amount DECIMAL(14,2) NOT NULL,
        qualifying_amount_thb DECIMAL(14,2) NOT NULL,
        source VARCHAR(32) NOT NULL DEFAULT 'deposit',
        qualification_method VARCHAR(32) NOT NULL DEFAULT 'native_thb',
        deposited_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_rank_deposit_transaction (deposit_transaction_id),
        KEY idx_rank_deposit_user_time (user_id, deposited_at),
        KEY idx_rank_deposit_role_time (role_at_deposit, deposited_at),
        KEY idx_rank_deposit_time (deposited_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $awardSql = "CREATE TABLE IF NOT EXISTS rank_bonus_awards (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        deposit_transaction_id BIGINT UNSIGNED NOT NULL,
        bonus_transaction_id BIGINT UNSIGNED NULL,
        user_id INT UNSIGNED NOT NULL,
        period_start DATE NOT NULL,
        rank_code VARCHAR(16) NOT NULL,
        bonus_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
        base_amount DECIMAL(14,2) NOT NULL,
        bonus_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
        qualifying_total_thb DECIMAL(14,2) NOT NULL,
        status VARCHAR(24) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_rank_bonus_deposit (deposit_transaction_id),
        UNIQUE KEY uq_rank_bonus_transaction (bonus_transaction_id),
        KEY idx_rank_bonus_user_period (user_id, period_start),
        KEY idx_rank_bonus_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $metaSql = "CREATE TABLE IF NOT EXISTS rank_system_meta (
        meta_key VARCHAR(64) NOT NULL,
        meta_value VARCHAR(255) NOT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (meta_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    foreach ([$ledgerSql, $awardSql, $metaSql] as $sql) {
        if (!$conn->query($sql)) {
            error_log('Ranking schema error: ' . $conn->error);
            return false;
        }
    }

    $GLOBALS['__ranking_schema_ready'] = true;
    if (!rankBackfillLegacyDeposits()) {
        $GLOBALS['__ranking_schema_ready'] = false;
        return false;
    }
    return true;
}

/**
 * Backfill historical completed deposits once. Existing transactions contain
 * exact THB values only when the store base currency is THB. For another base
 * currency, guessing an old exchange rate would corrupt rankings, so historical
 * backfill is deliberately skipped while new deposits are still recorded with
 * an explicit THB qualifying amount by their payment handler.
 */
function rankBackfillLegacyDeposits(): bool
{
    global $conn;
    if (!rankSchemaIsReady()) return false;

    $check = $conn->prepare("SELECT meta_value FROM rank_system_meta WHERE meta_key = 'legacy_deposit_backfill_v1' LIMIT 1");
    if (!$check) return false;
    if (!$check->execute()) {
        $check->close();
        return false;
    }
    $result = $check->get_result();
    $existing = $result ? $result->fetch_assoc() : null;
    $check->close();
    if ($existing) return true;

    $currency = strtoupper(trim((string) (function_exists('getSetting') ? getSetting('currency_name', 'THB') : 'THB')));
    $metaValue = 'skipped_non_thb';

    try {
        $conn->begin_transaction();
        if ($currency === 'THB') {
            $sql = "INSERT IGNORE INTO rank_deposit_ledger
                    (deposit_transaction_id, user_id, role_at_deposit, credited_amount, qualifying_amount_thb, source, qualification_method, deposited_at)
                    SELECT t.id, t.user_id, u.role, t.amount, t.amount, 'legacy', 'native_thb', t.created_at
                    FROM transactions t
                    INNER JOIN users u ON u.id = t.user_id
                    WHERE t.type = 'deposit'
                      AND t.status = 'completed'
                      AND t.amount > 0
                      AND u.role IN ('user', 'reseller')";
            if (!$conn->query($sql)) {
                throw new RuntimeException('legacy ledger insert failed: ' . $conn->error);
            }
            $metaValue = 'completed_thb';
        } else {
            error_log('Ranking legacy backfill skipped because store base currency is not THB; no historical exchange rate is stored.');
        }

        $meta = $conn->prepare(
            "INSERT INTO rank_system_meta (meta_key, meta_value)
             VALUES ('legacy_deposit_backfill_v1', ?)
             ON DUPLICATE KEY UPDATE meta_value = meta_value"
        );
        if (!$meta) throw new RuntimeException('legacy meta prepare failed');
        $meta->bind_param('s', $metaValue);
        if (!$meta->execute()) {
            $meta->close();
            throw new RuntimeException('legacy meta insert failed');
        }
        $meta->close();
        $conn->commit();
        return true;
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('Ranking legacy backfill error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Record one completed deposit and apply its monthly user-rank bonus.
 * Call only inside the payment handler's existing SQL transaction and only
 * after the base deposit transaction row has been created.
 *
 * @return array{success:bool,bonus_amount:float,total_credited:float,rank_code:string,bonus_percent:float,monthly_total_thb:float,status:string,message?:string}
 */
function rankRecordDepositAndApplyBonus(
    int $userId,
    int $depositTransactionId,
    float $qualifyingAmountThb,
    string $source,
    string $qualificationMethod = 'provider_thb',
    bool $applyBonus = true
): array
{
    global $conn;
    $failure = [
        'success' => false,
        'bonus_amount' => 0.0,
        'total_credited' => 0.0,
        'rank_code' => 'unranked',
        'bonus_percent' => 0.0,
        'monthly_total_thb' => 0.0,
        'status' => 'error',
    ];

    if (!rankSchemaIsReady()) {
        $failure['message'] = 'ranking_schema_not_prepared';
        return $failure;
    }
    $qualifyingAmountThb = round($qualifyingAmountThb, 2);
    $source = preg_replace('/[^a-z0-9_\-]/i', '', strtolower(trim($source))) ?: 'deposit';
    $source = substr($source, 0, 32);
    $qualificationMethod = preg_replace('/[^a-z0-9_\-]/i', '', strtolower(trim($qualificationMethod))) ?: 'provider_thb';
    $qualificationMethod = substr($qualificationMethod, 0, 32);
    if ($userId < 1 || $depositTransactionId < 1 || !is_finite($qualifyingAmountThb) || $qualifyingAmountThb <= 0 || $qualifyingAmountThb > 100000000) {
        $failure['message'] = 'invalid_ranking_deposit';
        return $failure;
    }

    $select = $conn->prepare(
        "SELECT t.amount, t.status, t.type, t.created_at, u.role, u.status AS user_status
         FROM transactions t
         INNER JOIN users u ON u.id = t.user_id
         WHERE t.id = ? AND t.user_id = ?
         LIMIT 1 FOR UPDATE"
    );
    if (!$select) {
        $failure['message'] = 'ranking_deposit_prepare_failed';
        return $failure;
    }
    $select->bind_param('ii', $depositTransactionId, $userId);
    if (!$select->execute()) {
        $select->close();
        $failure['message'] = 'ranking_deposit_read_failed';
        return $failure;
    }
    $result = $select->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $select->close();

    if (!$row || (string) $row['type'] !== 'deposit' || (string) $row['status'] !== 'completed' || (string) $row['user_status'] !== 'active') {
        $failure['message'] = 'ranking_deposit_not_eligible';
        return $failure;
    }

    $role = (string) $row['role'];
    $baseAmount = round((float) $row['amount'], 2);
    $failure['total_credited'] = $baseAmount;
    if (!is_finite($baseAmount) || $baseAmount <= 0 || !in_array($role, ['user', 'reseller'], true)) {
        // Admins and unsupported roles are intentionally invisible to rankings.
        return [
            'success' => true,
            'bonus_amount' => 0.0,
            'total_credited' => $baseAmount,
            'rank_code' => 'unranked',
            'bonus_percent' => 0.0,
            'monthly_total_thb' => 0.0,
            'status' => 'excluded',
        ];
    }

    $ledger = $conn->prepare(
        "INSERT INTO rank_deposit_ledger
         (deposit_transaction_id, user_id, role_at_deposit, credited_amount, qualifying_amount_thb, source, qualification_method, deposited_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE deposit_transaction_id = VALUES(deposit_transaction_id)"
    );
    if (!$ledger) {
        $failure['message'] = 'ranking_ledger_prepare_failed';
        return $failure;
    }
    $depositedAt = (string) $row['created_at'];
    $ledger->bind_param('iisddsss', $depositTransactionId, $userId, $role, $baseAmount, $qualifyingAmountThb, $source, $qualificationMethod, $depositedAt);
    if (!$ledger->execute()) {
        $ledger->close();
        $failure['message'] = 'ranking_ledger_write_failed';
        return $failure;
    }
    $ledger->close();

    // Do not silently accept an existing ledger row whose immutable financial
    // facts differ from the deposit being processed. This should never happen
    // in normal operation, but failing the surrounding transaction is safer
    // than building rankings or bonuses from corrupted historical data.
    $ledgerCheck = $conn->prepare(
        'SELECT user_id, role_at_deposit, credited_amount, qualifying_amount_thb, source, qualification_method
         FROM rank_deposit_ledger WHERE deposit_transaction_id = ? LIMIT 1 FOR UPDATE'
    );
    if (!$ledgerCheck) {
        $failure['message'] = 'ranking_ledger_check_prepare_failed';
        return $failure;
    }
    $ledgerCheck->bind_param('i', $depositTransactionId);
    if (!$ledgerCheck->execute()) {
        $ledgerCheck->close();
        $failure['message'] = 'ranking_ledger_check_failed';
        return $failure;
    }
    $ledgerResult = $ledgerCheck->get_result();
    $ledgerRow = $ledgerResult ? $ledgerResult->fetch_assoc() : null;
    $ledgerCheck->close();
    if (!$ledgerRow
        || (int) $ledgerRow['user_id'] !== $userId
        || !hash_equals((string) $ledgerRow['role_at_deposit'], $role)
        || abs((float) $ledgerRow['credited_amount'] - $baseAmount) > 0.009
        || abs((float) $ledgerRow['qualifying_amount_thb'] - $qualifyingAmountThb) > 0.009
        || !hash_equals((string) $ledgerRow['source'], $source)
        || !hash_equals((string) $ledgerRow['qualification_method'], $qualificationMethod)) {
        $failure['message'] = 'ranking_ledger_conflict';
        return $failure;
    }

    // Resellers are included in the lifetime board but have no monthly rank or bonus.
    if ($role !== 'user') {
        return [
            'success' => true,
            'bonus_amount' => 0.0,
            'total_credited' => $baseAmount,
            'rank_code' => 'unranked',
            'bonus_percent' => 0.0,
            'monthly_total_thb' => 0.0,
            'status' => 'reseller_no_bonus',
        ];
    }

    $existingAward = $conn->prepare('SELECT status, rank_code, bonus_percent, bonus_amount, qualifying_total_thb FROM rank_bonus_awards WHERE deposit_transaction_id = ? LIMIT 1 FOR UPDATE');
    if (!$existingAward) {
        $failure['message'] = 'ranking_award_read_prepare_failed';
        return $failure;
    }
    $existingAward->bind_param('i', $depositTransactionId);
    if (!$existingAward->execute()) {
        $existingAward->close();
        $failure['message'] = 'ranking_award_read_failed';
        return $failure;
    }
    $awardResult = $existingAward->get_result();
    $awardRow = $awardResult ? $awardResult->fetch_assoc() : null;
    $existingAward->close();
    if ($awardRow) {
        $bonus = round((float) $awardRow['bonus_amount'], 2);
        return [
            'success' => true,
            'bonus_amount' => $bonus,
            'total_credited' => round($baseAmount + $bonus, 2),
            'rank_code' => (string) $awardRow['rank_code'],
            'bonus_percent' => (float) $awardRow['bonus_percent'],
            'monthly_total_thb' => (float) $awardRow['qualifying_total_thb'],
            'status' => (string) $awardRow['status'],
        ];
    }

    $period = rankMonthPeriod($depositedAt);
    $sum = $conn->prepare(
        "SELECT COALESCE(SUM(qualifying_amount_thb), 0)
         FROM rank_deposit_ledger l
         INNER JOIN transactions t
                 ON t.id = l.deposit_transaction_id
                AND t.user_id = l.user_id
                AND t.type = 'deposit'
                AND t.status = 'completed'
         WHERE l.user_id = ? AND l.role_at_deposit = 'user'
           AND l.deposited_at >= ? AND l.deposited_at < ?"
    );
    if (!$sum) {
        $failure['message'] = 'ranking_sum_prepare_failed';
        return $failure;
    }
    $sum->bind_param('iss', $userId, $period['start'], $period['next']);
    if (!$sum->execute()) {
        $sum->close();
        $failure['message'] = 'ranking_sum_failed';
        return $failure;
    }
    $sum->bind_result($monthlyTotalRaw);
    $sum->fetch();
    $sum->close();
    $monthlyTotal = round((float) $monthlyTotalRaw, 2);
    // Benefits use the rank already held before this deposit. This prevents a
    // single large first deposit from receiving a high-tier bonus immediately;
    // the resulting rank becomes active for the next completed deposit.
    $monthlyTotalBefore = max(0.0, round($monthlyTotal - $qualifyingAmountThb, 2));
    $decision = rankCalculateBonusDecision($monthlyTotalBefore, $monthlyTotal, $baseAmount);
    $rankCode = (string) $decision['benefit_rank_code'];
    $resultRankCode = (string) $decision['result_rank_code'];
    $bonusPercent = (float) $decision['bonus_percent'];
    $bonusAmount = (float) $decision['bonus_amount'];
    $decisionStatus = $bonusAmount > 0 ? 'processing' : 'not_eligible';
    if (!$applyBonus) {
        // Record the decision so enabling bonuses later cannot retroactively pay
        // an old deposit that was intentionally configured as no-bonus.
        $bonusPercent = 0.0;
        $bonusAmount = 0.0;
        $decisionStatus = 'disabled';
    }
    $periodStart = substr($period['start'], 0, 10);

    // Claim the unique deposit decision before touching the balance.
    $claim = $conn->prepare(
        'INSERT INTO rank_bonus_awards
         (deposit_transaction_id, bonus_transaction_id, user_id, period_start, rank_code, bonus_percent, base_amount, bonus_amount, qualifying_total_thb, status)
         VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$claim) {
        $failure['message'] = 'ranking_award_claim_prepare_failed';
        return $failure;
    }
    $claim->bind_param('iissdddds', $depositTransactionId, $userId, $periodStart, $rankCode, $bonusPercent, $baseAmount, $bonusAmount, $monthlyTotal, $decisionStatus);
    if (!$claim->execute()) {
        $claim->close();
        $failure['message'] = 'ranking_award_claim_failed';
        return $failure;
    }
    $claim->close();

    if ($bonusAmount <= 0) {
        return [
            'success' => true,
            'bonus_amount' => 0.0,
            'total_credited' => $baseAmount,
            'rank_code' => $resultRankCode,
            'bonus_percent' => 0.0,
            'monthly_total_thb' => $monthlyTotal,
            'status' => $decisionStatus,
        ];
    }

    if (!function_exists('ensureWalletLedgerSchema') || !ensureWalletLedgerSchema()) {
        $failure['message'] = 'ranking_wallet_audit_unavailable';
        return $failure;
    }
    $walletBefore = walletLedgerReadBalance($userId, true);
    if ($walletBefore === null) {
        $failure['message'] = 'ranking_wallet_balance_read_failed';
        return $failure;
    }
    if (!function_exists('addBalance') || !addBalance($userId, $bonusAmount)) {
        $failure['message'] = 'ranking_bonus_balance_failed';
        return $failure;
    }

    $bonusTransactionId = null;
    if (rankTransactionTypeSupportsBonus()) {
        $description = sprintf('Monthly rank bonus %s %.2f%% for deposit #%d', strtoupper($rankCode), $bonusPercent, $depositTransactionId);
        $createdBonusTransactionId = function_exists('createTransaction')
            ? createTransaction($userId, 'rank_bonus', $bonusAmount, 'completed', $description, $depositTransactionId)
            : false;
        if (!$createdBonusTransactionId) {
            $failure['message'] = 'ranking_bonus_transaction_failed';
            return $failure;
        }
        $bonusTransactionId = (int) $createdBonusTransactionId;
    } else {
        if (empty($GLOBALS['__ranking_tx_type_warning_logged'])) {
            error_log('Ranking bonus applied without generic transactions row because transactions.type does not support rank_bonus; rank_bonus_awards remains authoritative.');
            $GLOBALS['__ranking_tx_type_warning_logged'] = true;
        }
    }

    $walletAfter = round((float) $walletBefore + $bonusAmount, 2);
    if (!walletLedgerRecordMovement(
        $userId,
        $bonusAmount,
        (float) $walletBefore,
        $walletAfter,
        'rank_bonus',
        'rank_bonus:deposit:' . $depositTransactionId,
        $depositTransactionId,
        $bonusTransactionId,
        null,
        'โบนัสแรงค์ ' . strtoupper($rankCode) . ' ' . number_format($bonusPercent, 2, '.', '') . '%',
        'Monthly rank bonus linked to deposit transaction #' . $depositTransactionId,
        null,
        true
    )) {
        $failure['message'] = 'ranking_wallet_audit_failed';
        return $failure;
    }

    $finishSql = $bonusTransactionId !== null
        ? "UPDATE rank_bonus_awards SET bonus_transaction_id = ?, status = 'applied' WHERE deposit_transaction_id = ? AND status = 'processing'"
        : "UPDATE rank_bonus_awards SET status = 'applied' WHERE deposit_transaction_id = ? AND status = 'processing'";
    $finish = $conn->prepare($finishSql);
    if (!$finish) {
        $failure['message'] = 'ranking_award_finish_prepare_failed';
        return $failure;
    }
    if ($bonusTransactionId !== null) {
        $finish->bind_param('ii', $bonusTransactionId, $depositTransactionId);
    } else {
        $finish->bind_param('i', $depositTransactionId);
    }
    $finished = $finish->execute() && $finish->affected_rows === 1;
    $finish->close();
    if (!$finished) {
        $failure['message'] = 'ranking_award_finish_failed';
        return $failure;
    }

    return [
        'success' => true,
        'bonus_amount' => $bonusAmount,
        'total_credited' => round($baseAmount + $bonusAmount, 2),
        'rank_code' => $resultRankCode,
        'bonus_percent' => $bonusPercent,
        'monthly_total_thb' => $monthlyTotal,
        'status' => 'applied',
    ];
}

/** @return array<int,array<string,mixed>> */
function rankMonthlyRows(?int $limit = null, int $offset = 0): array
{
    global $conn;
    if (!ensureRankingSchema()) return [];
    $period = rankMonthPeriod();
    $sql = "SELECT l.user_id, u.username,
                   SUM(l.qualifying_amount_thb) AS total_thb,
                   MAX(l.deposited_at) AS last_deposit_at
            FROM rank_deposit_ledger l
            INNER JOIN users u ON u.id = l.user_id
            INNER JOIN transactions t
                    ON t.id = l.deposit_transaction_id
                   AND t.user_id = l.user_id
                   AND t.type = 'deposit'
                   AND t.status = 'completed'
            WHERE l.deposited_at >= ? AND l.deposited_at < ?
              AND l.role_at_deposit = 'user'
              AND u.role = 'user' AND u.status = 'active'
            GROUP BY l.user_id, u.username
            HAVING total_thb > 0
            ORDER BY total_thb DESC, last_deposit_at ASC, l.user_id ASC";
    if ($limit !== null) $sql .= ' LIMIT ? OFFSET ?';
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    if ($limit !== null) {
        $limit = max(1, min(5000, $limit));
        $offset = max(0, $offset);
        $stmt->bind_param('ssii', $period['start'], $period['next'], $limit, $offset);
    } else {
        $offset = 0;
        $stmt->bind_param('ss', $period['start'], $period['next']);
    }
    if (!$stmt->execute()) {
        $stmt->close();
        return [];
    }
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    foreach ($rows as $index => &$row) {
        $total = round((float) ($row['total_thb'] ?? 0), 2);
        $row['position'] = $offset + $index + 1;
        $row['total_thb'] = $total;
        $row['rank_code'] = rankCodeForAmount($total);
        $row['masked_username'] = rankMaskUsername((string) ($row['username'] ?? ''));
    }
    unset($row);
    return $rows;
}

/** @return array<int,array<string,mixed>> */
function rankLifetimeRows(?int $limit = null, int $offset = 0): array
{
    global $conn;
    if (!ensureRankingSchema()) return [];
    $sql = "SELECT l.user_id, u.username, u.role,
                   SUM(l.qualifying_amount_thb) AS total_thb,
                   MAX(l.deposited_at) AS last_deposit_at
            FROM rank_deposit_ledger l
            INNER JOIN users u ON u.id = l.user_id
            INNER JOIN transactions t
                    ON t.id = l.deposit_transaction_id
                   AND t.user_id = l.user_id
                   AND t.type = 'deposit'
                   AND t.status = 'completed'
            WHERE u.role IN ('user', 'reseller') AND u.status = 'active'
            GROUP BY l.user_id, u.username, u.role
            HAVING total_thb > 0
            ORDER BY total_thb DESC, last_deposit_at ASC, l.user_id ASC";
    if ($limit !== null) $sql .= ' LIMIT ? OFFSET ?';
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    if ($limit !== null) {
        $limit = max(1, min(5000, $limit));
        $offset = max(0, $offset);
        $stmt->bind_param('ii', $limit, $offset);
    } else {
        $offset = 0;
    }
    if (!$stmt->execute()) {
        $stmt->close();
        return [];
    }
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    foreach ($rows as $index => &$row) {
        $total = round((float) ($row['total_thb'] ?? 0), 2);
        $position = $offset + $index + 1;
        // Retain the legacy position score for backward compatibility with any
        // older template that still reads it. Current public pages display the
        // exact normalized THB total requested by the store owner.
        $score = max(1.0, round(102.0 - ($position * 2.0), 1));
        $row['position'] = $position;
        $row['total_thb'] = $total;
        $row['score_percent'] = $score;
        $row['masked_username'] = rankMaskUsername((string) ($row['username'] ?? ''));
    }
    unset($row);
    return $rows;
}

/** @return array<string,mixed> */
function rankUserSnapshot(int $userId): array
{
    $snapshot = [
        'available' => false,
        'monthly_position' => null,
        'monthly_rank_code' => 'unranked',
        'monthly_total_thb' => 0.0,
        'monthly_bonus_percent' => 0.0,
        'next_rank_code' => null,
        'next_rank_threshold_thb' => null,
        'next_rank_bonus_percent' => 0.0,
        'remaining_to_next_thb' => 0.0,
        'progress_percent' => 0.0,
        'remaining_percent' => 100.0,
        'is_max_rank' => false,
        'lifetime_position' => null,
        'lifetime_score_percent' => 0.0,
        'lifetime_total_thb' => 0.0,
    ];
    if ($userId < 1 || !ensureRankingSchema()) return $snapshot;

    $monthlyRows = rankMonthlyRows(null);
    foreach ($monthlyRows as $row) {
        if ((int) $row['user_id'] === $userId) {
            $snapshot['monthly_position'] = (int) $row['position'];
            $snapshot['monthly_total_thb'] = round((float) ($row['total_thb'] ?? 0.0), 2);
            break;
        }
    }

    $progress = rankProgressForAmount((float) $snapshot['monthly_total_thb']);
    $snapshot['monthly_rank_code'] = (string) $progress['current_rank_code'];
    $snapshot['monthly_bonus_percent'] = (float) $progress['current_bonus_percent'];
    $snapshot['next_rank_code'] = $progress['next_rank_code'];
    $snapshot['next_rank_threshold_thb'] = $progress['next_rank_threshold_thb'];
    $snapshot['next_rank_bonus_percent'] = (float) $progress['next_rank_bonus_percent'];
    $snapshot['remaining_to_next_thb'] = (float) $progress['remaining_to_next_thb'];
    $snapshot['progress_percent'] = (float) $progress['progress_percent'];
    $snapshot['remaining_percent'] = (float) $progress['remaining_percent'];
    $snapshot['is_max_rank'] = !empty($progress['is_max_rank']);

    $lifetimeRows = rankLifetimeRows(null);
    foreach ($lifetimeRows as $row) {
        if ((int) $row['user_id'] === $userId) {
            $snapshot['lifetime_position'] = (int) $row['position'];
            $snapshot['lifetime_score_percent'] = (float) $row['score_percent'];
            $snapshot['lifetime_total_thb'] = round((float) $row['total_thb'], 2);
            break;
        }
    }
    $snapshot['available'] = true;
    return $snapshot;
}

/** @return array{applied_count:int,bonus_total:float} */
function rankCurrentMonthBonusSummary(): array
{
    global $conn;
    $summary = ['applied_count' => 0, 'bonus_total' => 0.0];
    if (!ensureRankingSchema()) return $summary;
    $period = rankMonthPeriod();
    $periodStart = substr($period['start'], 0, 10);
    $stmt = $conn->prepare("SELECT COUNT(*), COALESCE(SUM(bonus_amount), 0) FROM rank_bonus_awards WHERE period_start = ? AND status = 'applied'");
    if (!$stmt) return $summary;
    $stmt->bind_param('s', $periodStart);
    if (!$stmt->execute()) {
        $stmt->close();
        return $summary;
    }
    $stmt->bind_result($count, $total);
    if ($stmt->fetch()) {
        $summary['applied_count'] = (int) $count;
        $summary['bonus_total'] = round((float) $total, 2);
    }
    $stmt->close();
    return $summary;
}

/**
 * Admin-only aggregate for the current monthly user board.
 * Callers must enforce requireAdmin().
 *
 * @return array{entry_count:int,total_thb:float}
 */
function rankAdminMonthlySummary(): array
{
    global $conn;
    $summary = ['entry_count' => 0, 'total_thb' => 0.0];
    if (!ensureRankingSchema()) return $summary;
    $period = rankMonthPeriod();
    $stmt = $conn->prepare(
        "SELECT COUNT(*), COALESCE(SUM(r.total_thb), 0)
         FROM (
            SELECT l.user_id, SUM(l.qualifying_amount_thb) AS total_thb
            FROM rank_deposit_ledger l
            INNER JOIN users u ON u.id = l.user_id
            INNER JOIN transactions t
                    ON t.id = l.deposit_transaction_id
                   AND t.user_id = l.user_id
                   AND t.type = 'deposit'
                   AND t.status = 'completed'
            WHERE l.deposited_at >= ? AND l.deposited_at < ?
              AND l.role_at_deposit = 'user'
              AND u.role = 'user' AND u.status = 'active'
            GROUP BY l.user_id
            HAVING total_thb > 0
         ) r"
    );
    if (!$stmt) return $summary;
    $stmt->bind_param('ss', $period['start'], $period['next']);
    if ($stmt->execute()) {
        $stmt->bind_result($count, $total);
        if ($stmt->fetch()) {
            $summary['entry_count'] = (int) $count;
            $summary['total_thb'] = round((float) $total, 2);
        }
    }
    $stmt->close();
    return $summary;
}

/**
 * Admin-only aggregate for the all-time user/reseller board.
 * Callers must enforce requireAdmin().
 *
 * @return array{entry_count:int,total_thb:float}
 */
function rankAdminLifetimeSummary(): array
{
    global $conn;
    $summary = ['entry_count' => 0, 'total_thb' => 0.0];
    if (!ensureRankingSchema()) return $summary;
    $result = $conn->query(
        "SELECT COUNT(*), COALESCE(SUM(r.total_thb), 0)
         FROM (
            SELECT l.user_id, SUM(l.qualifying_amount_thb) AS total_thb
            FROM rank_deposit_ledger l
            INNER JOIN users u ON u.id = l.user_id
            INNER JOIN transactions t
                    ON t.id = l.deposit_transaction_id
                   AND t.user_id = l.user_id
                   AND t.type = 'deposit'
                   AND t.status = 'completed'
            WHERE u.role IN ('user', 'reseller') AND u.status = 'active'
            GROUP BY l.user_id
            HAVING total_thb > 0
         ) r"
    );
    if (!$result) return $summary;
    $row = $result->fetch_row();
    $result->free();
    if ($row) {
        $summary['entry_count'] = (int) $row[0];
        $summary['total_thb'] = round((float) $row[1], 2);
    }
    return $summary;
}

/** Admin-only count of every isolated deposit-ledger row. */
function rankAdminDepositLedgerCount(): int
{
    global $conn;
    if (!ensureRankingSchema()) return 0;
    $result = $conn->query('SELECT COUNT(*) FROM rank_deposit_ledger');
    if (!$result) return 0;
    $row = $result->fetch_row();
    $result->free();
    return $row ? (int) $row[0] : 0;
}

/**
 * Admin-only deposit audit rows; callers must enforce requireAdmin().
 * Includes records even when the source transaction was later changed, so an
 * administrator can detect and investigate inconsistencies instead of having
 * them silently disappear from the audit page.
 *
 * @return array<int,array<string,mixed>>
 */
function rankAdminDepositLedger(int $limit = 100, int $offset = 0): array
{
    global $conn;
    if (!ensureRankingSchema()) return [];
    $limit = max(1, min(500, $limit));
    $offset = max(0, $offset);
    $stmt = $conn->prepare(
        "SELECT l.*,
                COALESCE(u.username, CONCAT('User #', l.user_id)) AS username,
                COALESCE(u.role, l.role_at_deposit) AS account_role,
                COALESCE(u.status, 'missing') AS user_status,
                t.amount AS transaction_amount,
                t.status AS transaction_status,
                t.description AS transaction_description,
                t.created_at AS transaction_created_at
         FROM rank_deposit_ledger l
         LEFT JOIN users u ON u.id = l.user_id
         LEFT JOIN transactions t
                ON t.id = l.deposit_transaction_id
               AND t.user_id = l.user_id
         ORDER BY l.deposited_at DESC, l.id DESC
         LIMIT ? OFFSET ?"
    );
    if (!$stmt) return [];
    $stmt->bind_param('ii', $limit, $offset);
    if (!$stmt->execute()) {
        $stmt->close();
        return [];
    }
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

/** Admin-only count of every bonus decision, including no-bonus decisions. */
function rankAdminBonusAwardCount(): int
{
    global $conn;
    if (!ensureRankingSchema()) return 0;
    $result = $conn->query('SELECT COUNT(*) FROM rank_bonus_awards');
    if (!$result) return 0;
    $row = $result->fetch_row();
    $result->free();
    return $row ? (int) $row[0] : 0;
}

/**
 * Admin-only bonus audit data; callers must enforce requireAdmin().
 *
 * @return array<int,array<string,mixed>>
 */
function rankRecentBonusAwards(int $limit = 100, int $offset = 0): array
{
    global $conn;
    if (!ensureRankingSchema()) return [];
    $limit = max(1, min(500, $limit));
    $offset = max(0, $offset);
    $stmt = $conn->prepare(
        "SELECT a.*,
                COALESCE(u.username, CONCAT('User #', a.user_id)) AS username,
                COALESCE(u.role, 'user') AS role,
                COALESCE(u.status, 'missing') AS user_status,
                l.credited_amount,
                l.qualifying_amount_thb,
                l.source,
                l.qualification_method,
                l.deposited_at,
                t.amount AS transaction_amount,
                t.status AS deposit_status,
                t.description AS deposit_description,
                t.created_at AS transaction_created_at
         FROM rank_bonus_awards a
         LEFT JOIN users u ON u.id = a.user_id
         LEFT JOIN rank_deposit_ledger l
                ON l.deposit_transaction_id = a.deposit_transaction_id
               AND l.user_id = a.user_id
         LEFT JOIN transactions t
                ON t.id = a.deposit_transaction_id
               AND t.user_id = a.user_id
         ORDER BY a.id DESC LIMIT ? OFFSET ?"
    );
    if (!$stmt) return [];
    $stmt->bind_param('ii', $limit, $offset);
    if (!$stmt->execute()) {
        $stmt->close();
        return [];
    }
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}
