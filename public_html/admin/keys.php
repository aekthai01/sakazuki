<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

global $conn;

$error = '';
$success = '';
$productId = filter_input(INPUT_GET, 'product_id', FILTER_VALIDATE_INT) ?: 0;
$search = isset($_GET['search']) && is_scalar($_GET['search']) ? substr(trim((string) $_GET['search']), 0, 120) : '';
$status = isset($_GET['status']) && is_scalar($_GET['status']) ? strtolower(trim((string) $_GET['status'])) : 'all';
if (!in_array($status, ['all', 'available', 'sold', 'deleted'], true)) {
    $status = 'all';
}
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
$perPage = 50;

/** Small prepared-statement helper local to this admin page. */
function adminKeysFetchAll(string $sql, string $types = '', array $params = []): array
{
    global $conn;
    $stmt = null;
    try {
        $stmt = $conn->prepare($sql);
        if (!$stmt) return [];

        if ($types !== '') {
            $refs = [];
            foreach ($params as $index => $_) {
                $refs[$index] = &$params[$index];
            }
            if (!$stmt->bind_param($types, ...$refs)) {
                $stmt->close();
                return [];
            }
        }

        if (!$stmt->execute()) {
            $stmt->close();
            return [];
        }
        $result = $stmt->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        return $rows;
    } catch (Throwable $e) {
        if ($stmt instanceof mysqli_stmt) {
            try { $stmt->close(); } catch (Throwable $ignored) {}
        }
        error_log('Admin keys query failed: ' . $e->getMessage());
        return [];
    }
}

function adminKeysFetchOne(string $sql, string $types = '', array $params = []): ?array
{
    $rows = adminKeysFetchAll($sql, $types, $params);
    return $rows[0] ?? null;
}

function adminKeysTableExists(string $table): bool
{
    global $conn;
    if (!preg_match('/\A[a-z0-9_]+\z/iD', $table)) return false;
    try {
        $stmt = $conn->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        if ($stmt) {
            $stmt->bind_param('s', $table);
            if ($stmt->execute()) {
                $stmt->bind_result($count);
                $stmt->fetch();
                $stmt->close();
                return (int) $count > 0;
            }
            $stmt->close();
        }
    } catch (Throwable $ignored) {}

    try {
        $result = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
        if ($result) {
            $exists = $result->num_rows > 0;
            $result->free();
            return $exists;
        }
    } catch (Throwable $ignored) {}
    return false;
}

function adminKeysColumnExists(string $table, string $column): bool
{
    global $conn;
    if (!preg_match('/\A[a-z0-9_]+\z/iD', $table) || !preg_match('/\A[a-z0-9_]+\z/iD', $column)) {
        return false;
    }
    if (!adminKeysTableExists($table)) return false;

    try {
        $stmt = $conn->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        if ($stmt) {
            $stmt->bind_param('ss', $table, $column);
            if ($stmt->execute()) {
                $stmt->bind_result($count);
                $stmt->fetch();
                $stmt->close();
                return (int) $count > 0;
            }
            $stmt->close();
        }
    } catch (Throwable $ignored) {}

    try {
        $result = $conn->query(
            'SHOW COLUMNS FROM `' . $table . '` LIKE \'' . $conn->real_escape_string($column) . '\''
        );
        if ($result) {
            $exists = $result->num_rows > 0;
            $result->free();
            return $exists;
        }
    } catch (Throwable $ignored) {}
    return false;
}

/**
 * Admin deletion journal.
 *
 * Available keys are physically removed from live stock after a snapshot is
 * written here. Sold/history-linked keys remain in `keys` so customer history
 * and transaction references continue to work; the journal only hides them
 * from the normal Admin inventory view.
 */
function adminKeysEnsureDeletionJournal(): bool
{
    global $conn;
    static $ready = null;
    if ($ready !== null) return $ready;
    if (adminKeysTableExists('admin_key_deletions')) return $ready = true;

    $sql = "CREATE TABLE IF NOT EXISTS admin_key_deletions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        key_id BIGINT UNSIGNED NOT NULL,
        key_code VARCHAR(500) NOT NULL,
        product_id BIGINT UNSIGNED NULL,
        product_name VARCHAR(255) NULL,
        duration VARCHAR(120) NULL,
        price_user DECIMAL(16,2) NULL,
        price_reseller DECIMAL(16,2) NULL,
        original_status VARCHAR(30) NOT NULL DEFAULT 'available',
        assigned_to BIGINT UNSIGNED NULL,
        purchased_by BIGINT UNSIGNED NULL,
        buyer_user_id BIGINT UNSIGNED NULL,
        buyer_username VARCHAR(190) NULL,
        buyer_email VARCHAR(255) NULL,
        buyer_role VARCHAR(30) NULL,
        key_created_at DATETIME NULL,
        sold_at DATETIME NULL,
        transaction_id BIGINT UNSIGNED NULL,
        transaction_amount DECIMAL(16,2) NULL,
        store_api_order_id BIGINT UNSIGNED NULL,
        store_api_external_ref VARCHAR(120) NULL,
        store_api_client_name VARCHAR(190) NULL,
        sale_source VARCHAR(40) NOT NULL DEFAULT 'inventory',
        delete_reason VARCHAR(80) NOT NULL DEFAULT 'other',
        preserved_live_row TINYINT(1) NOT NULL DEFAULT 0,
        deleted_by BIGINT UNSIGNED NOT NULL,
        deleted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_admin_key_deletion_key (key_id),
        KEY idx_admin_key_deletion_deleted (deleted_at),
        KEY idx_admin_key_deletion_product (product_id),
        KEY idx_admin_key_deletion_original_status (original_status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    try {
        $ready = (bool) $conn->query($sql);
    } catch (Throwable $e) {
        error_log('Admin key deletion journal setup failed: ' . $e->getMessage());
        $ready = false;
    }
    return $ready;
}

function adminKeysStoreApiReady(): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    return $ready = adminKeysTableExists('store_api_order_keys')
        && adminKeysTableExists('store_api_orders')
        && adminKeysTableExists('store_api_clients');
}

function adminKeysOwnerExpression(string $alias = 'k'): string
{
    $parts = [];
    if (adminKeysColumnExists('keys', 'purchased_by')) $parts[] = "NULLIF({$alias}.purchased_by,0)";
    if (adminKeysColumnExists('keys', 'assigned_to')) $parts[] = "NULLIF({$alias}.assigned_to,0)";
    return $parts ? ('COALESCE(' . implode(',', $parts) . ',0)') : '0';
}

/** Read one key plus the audit links required before deleting it. */
function adminKeysLoadAuditRow(int $keyId, bool $forUpdate = false): ?array
{
    if ($keyId < 1) return null;

    $ownerExpr = adminKeysOwnerExpression('k');
    $createdExpr = adminKeysColumnExists('keys', 'created_at') ? 'k.created_at' : 'NULL';
    $soldExpr = adminKeysColumnExists('keys', 'sold_at') ? 'k.sold_at' : 'NULL';
    $assignedExpr = adminKeysColumnExists('keys', 'assigned_to') ? 'k.assigned_to' : 'NULL';
    $purchasedExpr = adminKeysColumnExists('keys', 'purchased_by') ? 'k.purchased_by' : 'NULL';
    $priceUserExpr = adminKeysColumnExists('keys', 'price_user') ? 'k.price_user' : 'NULL';
    $priceResellerExpr = adminKeysColumnExists('keys', 'price_reseller') ? 'k.price_reseller' : 'NULL';

    $sql = "SELECT k.id,k.key_code,k.product_id,k.duration,k.status,
                   {$priceUserExpr} AS price_user,{$priceResellerExpr} AS price_reseller,
                   {$assignedExpr} AS assigned_to,{$purchasedExpr} AS purchased_by,
                   {$createdExpr} AS key_created_at,{$soldExpr} AS sold_at,
                   {$ownerExpr} AS buyer_user_id,p.name AS product_name,
                   u.username AS buyer_username,u.email AS buyer_email,u.role AS buyer_role
            FROM `keys` k
            LEFT JOIN products p ON p.id=k.product_id
            LEFT JOIN users u ON u.id={$ownerExpr}
            WHERE k.id=? LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : '');
    $row = adminKeysFetchOne($sql, 'i', [$keyId]);
    if (!$row) return null;

    $tx = adminKeysFetchOne(
        "SELECT id,user_id,amount,created_at
         FROM transactions
         WHERE reference_id=? AND type='purchase' AND status='completed'
         ORDER BY id DESC LIMIT 1",
        'i',
        [$keyId]
    );
    if ($tx) {
        $row['transaction_id'] = (int) ($tx['id'] ?? 0);
        $row['transaction_amount'] = (float) ($tx['amount'] ?? 0);
        $row['transaction_user_id'] = (int) ($tx['user_id'] ?? 0);
        $row['transaction_created_at'] = (string) ($tx['created_at'] ?? '');
        if ((int) ($row['buyer_user_id'] ?? 0) < 1 && (int) ($tx['user_id'] ?? 0) > 0) {
            $fallbackBuyer = adminKeysFetchOne('SELECT id,username,email,role FROM users WHERE id=? LIMIT 1', 'i', [(int) $tx['user_id']]);
            if ($fallbackBuyer) {
                $row['buyer_user_id'] = (int) $fallbackBuyer['id'];
                $row['buyer_username'] = (string) ($fallbackBuyer['username'] ?? '');
                $row['buyer_email'] = (string) ($fallbackBuyer['email'] ?? '');
                $row['buyer_role'] = (string) ($fallbackBuyer['role'] ?? '');
            }
        }
    } else {
        $row['transaction_id'] = 0;
        $row['transaction_amount'] = null;
        $row['transaction_user_id'] = 0;
        $row['transaction_created_at'] = '';
    }

    $row['store_api_order_id'] = 0;
    $row['store_api_external_ref'] = '';
    $row['store_api_client_name'] = '';
    $row['store_api_customer_name'] = '';
    $row['store_api_customer_email'] = '';
    $row['store_api_sold_at'] = '';
    if (adminKeysStoreApiReady()) {
        $store = adminKeysFetchOne(
            "SELECT so.id AS order_id,so.external_ref,so.customer_name,so.customer_email,
                    COALESCE(so.completed_at,so.updated_at,so.created_at) AS sold_at,
                    sc.name AS client_name
             FROM store_api_order_keys sok
             JOIN store_api_orders so ON so.id=sok.order_id
             LEFT JOIN store_api_clients sc ON sc.id=so.client_id
             WHERE sok.source_key_id=? LIMIT 1",
            'i',
            [$keyId]
        );
        if ($store) {
            $row['store_api_order_id'] = (int) ($store['order_id'] ?? 0);
            $row['store_api_external_ref'] = (string) ($store['external_ref'] ?? '');
            $row['store_api_client_name'] = (string) ($store['client_name'] ?? '');
            $row['store_api_customer_name'] = (string) ($store['customer_name'] ?? '');
            $row['store_api_customer_email'] = (string) ($store['customer_email'] ?? '');
            $row['store_api_sold_at'] = (string) ($store['sold_at'] ?? '');
        }
    }

    $statusValue = strtolower((string) ($row['status'] ?? ''));
    if ((int) $row['store_api_order_id'] > 0) {
        $row['sale_source'] = 'store_api';
    } elseif ((int) ($row['buyer_user_id'] ?? 0) > 0 || (int) $row['transaction_id'] > 0) {
        $row['sale_source'] = 'local';
    } elseif ($statusValue === 'sold') {
        $row['sale_source'] = 'legacy';
    } else {
        $row['sale_source'] = 'inventory';
    }

    if (trim((string) ($row['sold_at'] ?? '')) === '') {
        $row['sold_at'] = (string) ($row['store_api_sold_at'] ?: $row['transaction_created_at']);
    }

    return $row;
}

function adminKeysHasPurchaseHistory(array $row): bool
{
    return strtolower((string) ($row['status'] ?? '')) === 'sold'
        || (int) ($row['assigned_to'] ?? 0) > 0
        || (int) ($row['purchased_by'] ?? 0) > 0
        || (int) ($row['buyer_user_id'] ?? 0) > 0
        || (int) ($row['transaction_id'] ?? 0) > 0
        || (int) ($row['store_api_order_id'] ?? 0) > 0
        || trim((string) ($row['sold_at'] ?? '')) !== '';
}

function adminKeysArchiveSnapshot(array $row, string $reason, bool $preserveLiveRow): bool
{
    global $conn;

    $buyerUsername = (string) ($row['buyer_username'] ?? '');
    $buyerEmail = (string) ($row['buyer_email'] ?? '');
    if ((string) ($row['sale_source'] ?? '') === 'store_api') {
        if ($buyerUsername === '') $buyerUsername = (string) ($row['store_api_customer_name'] ?? '');
        if ($buyerEmail === '') $buyerEmail = (string) ($row['store_api_customer_email'] ?? '');
    }

    $sql = "INSERT INTO admin_key_deletions
            (key_id,key_code,product_id,product_name,duration,price_user,price_reseller,original_status,
             assigned_to,purchased_by,buyer_user_id,buyer_username,buyer_email,buyer_role,key_created_at,sold_at,
             transaction_id,transaction_amount,store_api_order_id,store_api_external_ref,store_api_client_name,
             sale_source,delete_reason,preserved_live_row,deleted_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;

    $values = [
        (int) ($row['id'] ?? 0),
        (string) ($row['key_code'] ?? ''),
        (int) ($row['product_id'] ?? 0) ?: null,
        (string) ($row['product_name'] ?? ''),
        (string) ($row['duration'] ?? ''),
        isset($row['price_user']) ? (string) $row['price_user'] : null,
        isset($row['price_reseller']) ? (string) $row['price_reseller'] : null,
        (string) ($row['status'] ?? 'available'),
        (int) ($row['assigned_to'] ?? 0) ?: null,
        (int) ($row['purchased_by'] ?? 0) ?: null,
        (int) ($row['buyer_user_id'] ?? 0) ?: null,
        $buyerUsername !== '' ? $buyerUsername : null,
        $buyerEmail !== '' ? $buyerEmail : null,
        trim((string) ($row['buyer_role'] ?? '')) !== '' ? (string) $row['buyer_role'] : null,
        trim((string) ($row['key_created_at'] ?? '')) !== '' ? (string) $row['key_created_at'] : null,
        trim((string) ($row['sold_at'] ?? '')) !== '' ? (string) $row['sold_at'] : null,
        (int) ($row['transaction_id'] ?? 0) ?: null,
        isset($row['transaction_amount']) ? (string) $row['transaction_amount'] : null,
        (int) ($row['store_api_order_id'] ?? 0) ?: null,
        trim((string) ($row['store_api_external_ref'] ?? '')) !== '' ? (string) $row['store_api_external_ref'] : null,
        trim((string) ($row['store_api_client_name'] ?? '')) !== '' ? (string) $row['store_api_client_name'] : null,
        (string) ($row['sale_source'] ?? 'inventory'),
        $reason,
        $preserveLiveRow ? 1 : 0,
        (int) ($_SESSION['user_id'] ?? 0),
    ];
    $types = str_repeat('s', count($values));
    $refs = [];
    foreach ($values as $index => $_) $refs[$index] = &$values[$index];
    $stmt->bind_param($types, ...$refs);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/**
 * Remove one key from the Admin inventory without breaking purchase history.
 * Returns deleted=true for an unsold physical delete and archived=true when a
 * history-linked row was preserved in `keys`.
 */
function adminKeysDeleteOne(int $keyId, string $reason): array
{
    global $conn;
    if ($keyId < 1) return ['ok' => false, 'message' => 'invalid'];
    if (!adminKeysEnsureDeletionJournal()) return ['ok' => false, 'message' => 'journal'];

    try {
        $conn->begin_transaction();

        $already = adminKeysFetchOne('SELECT id FROM admin_key_deletions WHERE key_id=? LIMIT 1', 'i', [$keyId]);
        if ($already) {
            $conn->rollback();
            return ['ok' => false, 'message' => 'already'];
        }

        $row = adminKeysLoadAuditRow($keyId, true);
        if (!$row) {
            $conn->rollback();
            return ['ok' => false, 'message' => 'missing'];
        }

        $preserve = adminKeysHasPurchaseHistory($row);
        if (!adminKeysArchiveSnapshot($row, $reason, $preserve)) {
            throw new RuntimeException('Unable to save key deletion journal.');
        }

        if (!$preserve) {
            $stmt = $conn->prepare('DELETE FROM `keys` WHERE id=?');
            if (!$stmt) throw new RuntimeException('Unable to prepare key deletion.');
            $stmt->bind_param('i', $keyId);
            $ok = $stmt->execute() && $stmt->affected_rows === 1;
            $stmt->close();
            if (!$ok) throw new RuntimeException('Unable to remove key from inventory.');
        } elseif (strtolower((string) ($row['status'] ?? '')) === 'available') {
            // A history-linked row must never remain purchasable merely because
            // legacy/inconsistent data still says "available". The Admin is
            // removing it from inventory, so mark it sold while preserving the
            // row and every transaction/customer reference.
            $stmt = $conn->prepare("UPDATE `keys` SET status='sold' WHERE id=? AND status='available'");
            if (!$stmt) throw new RuntimeException('Unable to protect history-linked key.');
            $stmt->bind_param('i', $keyId);
            $ok = $stmt->execute();
            $stmt->close();
            if (!$ok) throw new RuntimeException('Unable to protect history-linked key.');
        }

        $conn->commit();
        logHistory(
            (int) $_SESSION['user_id'],
            $preserve ? 'archive_sold_key' : 'delete_available_key',
            ($preserve ? 'Archived history-linked key ID: ' : 'Deleted available key ID: ') . $keyId . '; reason=' . $reason
        );
        return [
            'ok' => true,
            'deleted' => !$preserve,
            'archived' => $preserve,
            'key_code' => (string) ($row['key_code'] ?? ''),
        ];
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        error_log('Admin key deletion failed for key ' . $keyId . ': ' . $e->getMessage());
        return ['ok' => false, 'message' => 'failed'];
    }
}

function adminKeysNormalizeReason($value): string
{
    $reason = is_scalar($value) ? strtolower(trim((string) $value)) : 'other';
    return in_array($reason, ['wrong_entry', 'unusable', 'no_longer_needed', 'other', 'bulk_delete'], true)
        ? $reason
        : 'other';
}

function adminKeysDate($value): string
{
    $value = trim((string) $value);
    if ($value === '') return '—';
    $timestamp = strtotime($value);
    if ($timestamp === false) return $value;
    $format = Lang::t('common.date_format');
    if (!is_string($format) || trim($format) === '' || $format === 'common.date_format') $format = 'd/m/Y H:i';
    return date($format, $timestamp);
}

function adminKeysUrl(array $replace = []): string
{
    global $productId, $search, $status, $page;
    $params = [
        'product_id' => $productId > 0 ? $productId : null,
        'status' => $status !== 'all' ? $status : null,
        'search' => $search !== '' ? $search : null,
        'page' => $page > 1 ? $page : null,
    ];
    foreach ($replace as $key => $value) $params[$key] = $value;
    $params = array_filter($params, static fn($value) => $value !== null && $value !== '');
    return 'keys.php' . ($params ? ('?' . http_build_query($params)) : '');
}

// Viewing the inventory must never depend on CREATE TABLE permission.
// The deletion journal is created lazily only when an Admin actually deletes a key.
$journalReady = adminKeysTableExists('admin_key_deletions');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $action = isset($_POST['action']) && is_scalar($_POST['action'])
        ? substr((string) $_POST['action'], 0, 30)
        : '';
    $reason = adminKeysNormalizeReason($_POST['delete_reason'] ?? 'other');

    if ($action === 'delete') {
        $keyId = filter_var($_POST['key_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        $result = adminKeysDeleteOne($keyId, $reason);
        if (!empty($result['ok'])) {
            if (!empty($result['archived'])) {
                $success = Lang::t('admin.keys.success.sold_archived');
            } else {
                $success = Lang::t('admin.keys.success.available_deleted');
            }
        } else {
            $message = (string) ($result['message'] ?? 'failed');
            $error = $message === 'already'
                ? Lang::t('admin.keys.error.already_deleted')
                : ($message === 'missing' ? Lang::t('admin.keys.error.not_found') : Lang::t('admin.keys.error.delete_failed'));
        }
    } elseif ($action === 'bulk_delete') {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['key_ids'] ?? [])), static fn($id) => $id > 0)));
        sort($ids, SORT_NUMERIC);
        if (!$ids) {
            $error = Lang::t('admin.keys.select_at_least_one');
        } elseif (count($ids) > 100) {
            $error = Lang::t('admin.keys.error.bulk_limit');
        } else {
            $deleted = 0;
            $archived = 0;
            $failed = 0;
            foreach ($ids as $id) {
                $result = adminKeysDeleteOne((int) $id, $reason === 'other' ? 'bulk_delete' : $reason);
                if (!empty($result['deleted'])) $deleted++;
                elseif (!empty($result['archived'])) $archived++;
                else $failed++;
            }
            if ($deleted + $archived > 0) {
                $success = Lang::t('admin.keys.success.bulk', [
                    'deleted' => (string) $deleted,
                    'archived' => (string) $archived,
                ]);
                if ($failed > 0) {
                    $success .= ' ' . Lang::t('admin.keys.success.bulk_failed', ['failed' => (string) $failed]);
                }
            } else {
                $error = Lang::t('admin.keys.error.delete_failed');
            }
        }
    }
    // A delete action may have created the journal during this request.
    $journalReady = adminKeysTableExists('admin_key_deletions');
}

$ownerExpr = adminKeysOwnerExpression('k');
$createdExpr = adminKeysColumnExists('keys', 'created_at') ? 'k.created_at' : 'NULL';
$soldExpr = adminKeysColumnExists('keys', 'sold_at') ? 'k.sold_at' : 'NULL';
$storeReady = adminKeysStoreApiReady();
$keys = [];
$totalRows = 0;

if ($status === 'deleted' && !$journalReady) {
    $keys = [];
    $totalRows = 0;
} elseif ($status === 'deleted') {
    $conditions = ['1=1'];
    $types = '';
    $params = [];
    if ($productId > 0) {
        $conditions[] = 'd.product_id=?';
        $types .= 'i';
        $params[] = $productId;
    }
    if ($search !== '') {
        $like = '%' . $search . '%';
        $searchParts = [
            'd.key_code LIKE ?',
            'd.product_name LIKE ?',
            'd.buyer_username LIKE ?',
            'd.buyer_email LIKE ?',
            'd.store_api_external_ref LIKE ?',
            'd.store_api_client_name LIKE ?',
        ];
        foreach ($searchParts as $_) {
            $types .= 's';
            $params[] = $like;
        }
        if (ctype_digit($search)) {
            $numeric = (int) $search;
            $searchParts[] = 'd.key_id=?';
            $types .= 'i';
            $params[] = $numeric;
            $searchParts[] = 'd.buyer_user_id=?';
            $types .= 'i';
            $params[] = $numeric;
            $searchParts[] = 'd.transaction_id=?';
            $types .= 'i';
            $params[] = $numeric;
            $searchParts[] = 'd.store_api_order_id=?';
            $types .= 'i';
            $params[] = $numeric;
        }
        $conditions[] = '(' . implode(' OR ', $searchParts) . ')';
    }
    $where = implode(' AND ', $conditions);
    $countRow = adminKeysFetchOne("SELECT COUNT(*) AS total FROM admin_key_deletions d WHERE {$where}", $types, $params);
    $totalRows = (int) ($countRow['total'] ?? 0);
    $totalPages = max(1, (int) ceil($totalRows / $perPage));
    if ($page > $totalPages) $page = $totalPages;
    $offset = ($page - 1) * $perPage;

    $keys = adminKeysFetchAll(
        "SELECT d.id AS deletion_id,d.key_id AS id,d.key_code,d.product_id,d.product_name,d.duration,
                d.original_status AS status,d.buyer_user_id,d.buyer_username,d.buyer_email,d.buyer_role,
                d.key_created_at,d.sold_at,d.transaction_id,d.transaction_amount,d.store_api_order_id,
                d.store_api_external_ref,d.store_api_client_name,d.sale_source,d.delete_reason,d.deleted_by,
                d.deleted_at,d.preserved_live_row
         FROM admin_key_deletions d
         WHERE {$where}
         ORDER BY d.deleted_at DESC,d.id DESC
         LIMIT {$perPage} OFFSET {$offset}",
        $types,
        $params
    );
    foreach ($keys as &$row) {
        $row['is_deleted'] = true;
        $row['sale_source'] = (string) ($row['sale_source'] ?? 'inventory');
    }
    unset($row);
} else {
    $conditions = [$journalReady ? 'd.key_id IS NULL' : '1=1'];
    $types = '';
    $params = [];
    if ($productId > 0) {
        $conditions[] = 'k.product_id=?';
        $types .= 'i';
        $params[] = $productId;
    }
    if ($status === 'available' || $status === 'sold') {
        $conditions[] = 'k.status=?';
        $types .= 's';
        $params[] = $status;
    }
    if ($search !== '') {
        $like = '%' . $search . '%';
        $searchParts = ['k.key_code LIKE ?', 'p.name LIKE ?', 'u.username LIKE ?', 'u.email LIKE ?'];
        for ($i = 0; $i < 4; $i++) {
            $types .= 's';
            $params[] = $like;
        }
        // Legacy/inconsistent sold rows can have the buyer only on the purchase
        // transaction. Include that path so Admin search still finds them by
        // username/email even when assigned_to/purchased_by is missing.
        $searchParts[] = "EXISTS (
            SELECT 1 FROM transactions tx_b
            LEFT JOIN users tu_b ON tu_b.id=tx_b.user_id
            WHERE tx_b.reference_id=k.id AND tx_b.type='purchase' AND tx_b.status='completed'
              AND (tu_b.username LIKE ? OR tu_b.email LIKE ?)
        )";
        for ($i = 0; $i < 2; $i++) {
            $types .= 's';
            $params[] = $like;
        }
        if ($storeReady) {
            $searchParts[] = "EXISTS (
                SELECT 1 FROM store_api_order_keys sok_s
                JOIN store_api_orders so_s ON so_s.id=sok_s.order_id
                LEFT JOIN store_api_clients sc_s ON sc_s.id=so_s.client_id
                WHERE sok_s.source_key_id=k.id AND
                      (so_s.external_ref LIKE ? OR so_s.customer_name LIKE ? OR so_s.customer_email LIKE ? OR sc_s.name LIKE ?)
            )";
            for ($i = 0; $i < 4; $i++) {
                $types .= 's';
                $params[] = $like;
            }
        }
        if (ctype_digit($search)) {
            $numeric = (int) $search;
            $searchParts[] = 'k.id=?';
            $types .= 'i';
            $params[] = $numeric;
            $searchParts[] = "{$ownerExpr}=?";
            $types .= 'i';
            $params[] = $numeric;
            $searchParts[] = "EXISTS (SELECT 1 FROM transactions tx_s WHERE tx_s.reference_id=k.id AND tx_s.type='purchase' AND tx_s.status='completed' AND tx_s.id=?)";
            $types .= 'i';
            $params[] = $numeric;
            if ($storeReady) {
                $searchParts[] = "EXISTS (SELECT 1 FROM store_api_order_keys sok_n WHERE sok_n.source_key_id=k.id AND sok_n.order_id=?)";
                $types .= 'i';
                $params[] = $numeric;
            }
        }
        $conditions[] = '(' . implode(' OR ', $searchParts) . ')';
    }
    $where = implode(' AND ', $conditions);
    $deletionJoin = $journalReady ? 'LEFT JOIN admin_key_deletions d ON d.key_id=k.id' : '';
    $baseFrom = "FROM `keys` k
                 LEFT JOIN products p ON p.id=k.product_id
                 LEFT JOIN users u ON u.id={$ownerExpr}
                 {$deletionJoin}";
    $countRow = adminKeysFetchOne("SELECT COUNT(*) AS total {$baseFrom} WHERE {$where}", $types, $params);
    $totalRows = (int) ($countRow['total'] ?? 0);
    $totalPages = max(1, (int) ceil($totalRows / $perPage));
    if ($page > $totalPages) $page = $totalPages;
    $offset = ($page - 1) * $perPage;
    $orderBy = $status === 'sold' && adminKeysColumnExists('keys', 'sold_at')
        ? 'ORDER BY k.sold_at DESC,k.id DESC'
        : 'ORDER BY k.id DESC';

    $keys = adminKeysFetchAll(
        "SELECT k.id,k.key_code,k.product_id,k.duration,k.status,
                {$ownerExpr} AS buyer_user_id,u.username AS buyer_username,u.email AS buyer_email,u.role AS buyer_role,
                p.name AS product_name,{$createdExpr} AS key_created_at,{$soldExpr} AS sold_at
         {$baseFrom}
         WHERE {$where}
         {$orderBy}
         LIMIT {$perPage} OFFSET {$offset}",
        $types,
        $params
    );

    $ids = array_values(array_filter(array_map(static fn($row) => (int) ($row['id'] ?? 0), $keys), static fn($id) => $id > 0));
    $txMap = [];
    $storeMap = [];
    if ($ids) {
        $idList = implode(',', array_map('intval', $ids));
        $txRows = adminKeysFetchAll(
            "SELECT t.reference_id,t.id,t.user_id,t.amount,t.created_at,tu.username,tu.email,tu.role
             FROM transactions t
             LEFT JOIN users tu ON tu.id=t.user_id
             WHERE t.reference_id IN ({$idList}) AND t.type='purchase' AND t.status='completed'
             ORDER BY t.id DESC"
        );
        foreach ($txRows as $tx) {
            $keyId = (int) ($tx['reference_id'] ?? 0);
            if ($keyId > 0 && !isset($txMap[$keyId])) $txMap[$keyId] = $tx;
        }

        if ($storeReady) {
            $storeRows = adminKeysFetchAll(
                "SELECT sok.source_key_id,so.id AS order_id,so.external_ref,so.customer_name,so.customer_email,
                        COALESCE(so.completed_at,so.updated_at,so.created_at) AS sold_at,sc.name AS client_name
                 FROM store_api_order_keys sok
                 JOIN store_api_orders so ON so.id=sok.order_id
                 LEFT JOIN store_api_clients sc ON sc.id=so.client_id
                 WHERE sok.source_key_id IN ({$idList})"
            );
            foreach ($storeRows as $storeRow) {
                $keyId = (int) ($storeRow['source_key_id'] ?? 0);
                if ($keyId > 0) $storeMap[$keyId] = $storeRow;
            }
        }
    }

    foreach ($keys as &$row) {
        $keyId = (int) ($row['id'] ?? 0);
        $tx = $txMap[$keyId] ?? null;
        $store = $storeMap[$keyId] ?? null;
        if ((int) ($row['buyer_user_id'] ?? 0) < 1 && $tx) {
            $row['buyer_user_id'] = (int) ($tx['user_id'] ?? 0);
            $row['buyer_username'] = (string) ($tx['username'] ?? '');
            $row['buyer_email'] = (string) ($tx['email'] ?? '');
            $row['buyer_role'] = (string) ($tx['role'] ?? '');
        }
        $row['transaction_id'] = $tx ? (int) ($tx['id'] ?? 0) : 0;
        $row['transaction_amount'] = $tx ? (float) ($tx['amount'] ?? 0) : null;
        $row['store_api_order_id'] = $store ? (int) ($store['order_id'] ?? 0) : 0;
        $row['store_api_external_ref'] = $store ? (string) ($store['external_ref'] ?? '') : '';
        $row['store_api_client_name'] = $store ? (string) ($store['client_name'] ?? '') : '';
        $row['store_api_customer_name'] = $store ? (string) ($store['customer_name'] ?? '') : '';
        $row['store_api_customer_email'] = $store ? (string) ($store['customer_email'] ?? '') : '';
        if ($store) {
            $row['sale_source'] = 'store_api';
            if (trim((string) ($row['sold_at'] ?? '')) === '') $row['sold_at'] = (string) ($store['sold_at'] ?? '');
        } elseif ((int) ($row['buyer_user_id'] ?? 0) > 0 || $tx) {
            $row['sale_source'] = 'local';
            if (trim((string) ($row['sold_at'] ?? '')) === '' && $tx) $row['sold_at'] = (string) ($tx['created_at'] ?? '');
        } elseif (strtolower((string) ($row['status'] ?? '')) === 'sold') {
            $row['sale_source'] = 'legacy';
        } else {
            $row['sale_source'] = 'inventory';
        }
        $row['is_deleted'] = false;
    }
    unset($row);
}

$totalPages = max(1, (int) ceil($totalRows / $perPage));
if ($page > $totalPages) $page = $totalPages;
$fromRow = $totalRows > 0 ? (($page - 1) * $perPage + 1) : 0;
$toRow = min($totalRows, $page * $perPage);

$summary = ['available' => 0, 'sold' => 0, 'deleted' => 0];
if ($journalReady) {
    $summaryRow = adminKeysFetchOne(
        "SELECT
            SUM(CASE WHEN k.status='available' AND d.key_id IS NULL THEN 1 ELSE 0 END) AS available_count,
            SUM(CASE WHEN k.status='sold' AND d.key_id IS NULL THEN 1 ELSE 0 END) AS sold_count
         FROM `keys` k LEFT JOIN admin_key_deletions d ON d.key_id=k.id"
    );
    $deletedSummary = adminKeysFetchOne('SELECT COUNT(*) AS total FROM admin_key_deletions');
    $summary['deleted'] = (int) ($deletedSummary['total'] ?? 0);
} else {
    $summaryRow = adminKeysFetchOne(
        "SELECT
            SUM(CASE WHEN status='available' THEN 1 ELSE 0 END) AS available_count,
            SUM(CASE WHEN status='sold' THEN 1 ELSE 0 END) AS sold_count
         FROM `keys`"
    );
}
$summary['available'] = (int) ($summaryRow['available_count'] ?? 0);
$summary['sold'] = (int) ($summaryRow['sold_count'] ?? 0);

$products = getProducts('all');

function adminKeysBuyerLabel(array $key): string
{
    if ((string) ($key['sale_source'] ?? '') === 'store_api') {
        $customer = trim((string) ($key['buyer_username'] ?? ($key['store_api_customer_name'] ?? '')));
        if ($customer !== '') return $customer;
        $client = trim((string) ($key['store_api_client_name'] ?? ''));
        return $client !== '' ? $client : 'Store API';
    }
    $username = trim((string) ($key['buyer_username'] ?? ''));
    if ($username !== '') return $username;
    $buyerId = (int) ($key['buyer_user_id'] ?? 0);
    return $buyerId > 0 ? ('#' . $buyerId) : '—';
}

function adminKeysSourceLabel(array $key): string
{
    $source = (string) ($key['sale_source'] ?? 'inventory');
    switch ($source) {
        case 'local':
            return Lang::t('admin.keys.source.local');
        case 'store_api':
            return Lang::t('admin.keys.source.store_api');
        case 'legacy':
            return Lang::t('admin.keys.source.legacy');
        default:
            return Lang::t('admin.keys.source.inventory');
    }
}

function adminKeysDetailJson(array $key): string
{
    $buyerEmail = trim((string) ($key['buyer_email'] ?? ''));
    if ($buyerEmail === '' && (string) ($key['sale_source'] ?? '') === 'store_api') {
        $buyerEmail = trim((string) ($key['store_api_customer_email'] ?? ''));
    }
    $detail = [
        'id' => (int) ($key['id'] ?? 0),
        'key' => (string) ($key['key_code'] ?? ''),
        'product' => (string) ($key['product_name'] ?? ''),
        'duration' => (string) ($key['duration'] ?? ''),
        'status' => (string) ($key['status'] ?? ''),
        'source' => adminKeysSourceLabel($key),
        'buyer' => adminKeysBuyerLabel($key),
        'buyer_id' => (int) ($key['buyer_user_id'] ?? 0),
        'buyer_email' => $buyerEmail,
        'buyer_role' => (string) ($key['buyer_role'] ?? ''),
        'created_at' => adminKeysDate($key['key_created_at'] ?? ''),
        'sold_at' => adminKeysDate($key['sold_at'] ?? ''),
        'transaction_id' => (int) ($key['transaction_id'] ?? 0),
        'transaction_amount' => isset($key['transaction_amount']) && $key['transaction_amount'] !== null
            ? formatCurrency((float) $key['transaction_amount'])
            : '',
        'store_order_id' => (int) ($key['store_api_order_id'] ?? 0),
        'store_external_ref' => (string) ($key['store_api_external_ref'] ?? ''),
        'store_client' => (string) ($key['store_api_client_name'] ?? ''),
        'deleted' => !empty($key['is_deleted']),
        'deleted_at' => adminKeysDate($key['deleted_at'] ?? ''),
        'delete_reason' => (string) ($key['delete_reason'] ?? ''),
        'preserved_live_row' => !empty($key['preserved_live_row']),
    ];
    return htmlspecialchars((string) json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(getAppLang(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title data-lang="admin.keys.title"><?php echo htmlspecialchars(Lang::t('admin.keys.title'), ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>:root{--sakazuki-accent:#6366f1;--sakazuki-accent-rgb:99 102 241;--sakazuki-accent2:#8b5cf6;--sakazuki-accent2-rgb:139 92 246;--sakazuki-glow:0 0 25px rgba(99,102,241,0.35)}</style>
    <style>
        .glass {
            background: rgba(255,255,255,.05);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255,255,255,.08);
        }
        .key-code-wrap { overflow-wrap: anywhere; word-break: break-word; }
        .touch-btn { min-width: 42px; min-height: 42px; }
        @media (max-width: 767px) {
            .glass { backdrop-filter: none; -webkit-backdrop-filter: none; background: rgba(20,20,24,.94); }
        }
    </style>
</head>
<body class="bg-darkbg text-gray-100 min-h-screen">
<?php include 'nav.php'; ?>

<main class="flex-1 overflow-y-auto p-3 sm:p-4 md:p-6 space-y-4 md:space-y-6">
    <div class="flex items-start justify-between gap-3">
        <div>
            <h1 class="text-xl md:text-2xl font-bold text-white flex items-center gap-2">
                <i class="bi bi-key text-indigo-400"></i>
                <span data-lang="admin.keys.heading"><?php echo htmlspecialchars(Lang::t('admin.keys.heading'), ENT_QUOTES, 'UTF-8'); ?></span>
            </h1>
            <p class="mt-1 text-xs md:text-sm text-gray-400" data-lang="admin.keys.subtitle"><?php echo htmlspecialchars(Lang::t('admin.keys.subtitle'), ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="rounded-xl border border-red-500/40 bg-red-500/10 p-3 text-sm text-red-200" role="alert">
            <?php echo htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
        </div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="rounded-xl border border-green-500/40 bg-green-500/10 p-3 text-sm text-green-200" role="status">
            <?php echo htmlspecialchars($success, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <div id="adminKeysLivePanel" data-instant-panel class="space-y-4">
        <div class="grid grid-cols-3 gap-2 md:gap-3">
            <a href="<?php echo htmlspecialchars(adminKeysUrl(['status' => 'available', 'page' => null, 'product_id' => null, 'search' => null]), ENT_QUOTES, 'UTF-8'); ?>" class="glass rounded-xl p-3 md:p-4 hover:border-green-500/40 transition">
                <div class="text-[11px] md:text-sm text-gray-400" data-lang="admin.keys.summary.available"><?php echo htmlspecialchars(Lang::t('admin.keys.summary.available'), ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="mt-1 text-lg md:text-2xl font-bold text-green-400"><?php echo number_format($summary['available']); ?></div>
            </a>
            <a href="<?php echo htmlspecialchars(adminKeysUrl(['status' => 'sold', 'page' => null, 'product_id' => null, 'search' => null]), ENT_QUOTES, 'UTF-8'); ?>" class="glass rounded-xl p-3 md:p-4 hover:border-blue-500/40 transition">
                <div class="text-[11px] md:text-sm text-gray-400" data-lang="admin.keys.summary.sold"><?php echo htmlspecialchars(Lang::t('admin.keys.summary.sold'), ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="mt-1 text-lg md:text-2xl font-bold text-blue-400"><?php echo number_format($summary['sold']); ?></div>
            </a>
            <a href="<?php echo htmlspecialchars(adminKeysUrl(['status' => 'deleted', 'page' => null, 'product_id' => null, 'search' => null]), ENT_QUOTES, 'UTF-8'); ?>" class="glass rounded-xl p-3 md:p-4 hover:border-red-500/40 transition">
                <div class="text-[11px] md:text-sm text-gray-400" data-lang="admin.keys.summary.deleted"><?php echo htmlspecialchars(Lang::t('admin.keys.summary.deleted'), ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="mt-1 text-lg md:text-2xl font-bold text-red-300"><?php echo number_format($summary['deleted']); ?></div>
            </a>
        </div>

        <form method="GET" class="glass rounded-xl p-3 md:p-4 grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
            <div class="md:col-span-3">
                <label for="productFilter" class="block text-xs text-gray-400 mb-1.5" data-lang="admin.keys.filter_product"><?php echo htmlspecialchars(Lang::t('admin.keys.filter_product'), ENT_QUOTES, 'UTF-8'); ?></label>
                <select id="productFilter" name="product_id" class="w-full rounded-lg border border-white/10 bg-panel px-3 py-2.5 text-sm text-white">
                    <option value="" data-lang="admin.keys.all_products"><?php echo htmlspecialchars(Lang::t('admin.keys.all_products'), ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php foreach ($products as $product): ?>
                        <option value="<?php echo (int) $product['id']; ?>" <?php echo $productId === (int) $product['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) $product['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="md:col-span-2">
                <label for="statusFilter" class="block text-xs text-gray-400 mb-1.5" data-lang="admin.keys.filter_status"><?php echo htmlspecialchars(Lang::t('admin.keys.filter_status'), ENT_QUOTES, 'UTF-8'); ?></label>
                <select id="statusFilter" name="status" class="w-full rounded-lg border border-white/10 bg-panel px-3 py-2.5 text-sm text-white">
                    <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?> data-lang="common.all"><?php echo htmlspecialchars(Lang::t('common.all'), ENT_QUOTES, 'UTF-8'); ?></option>
                    <option value="available" <?php echo $status === 'available' ? 'selected' : ''; ?> data-lang="admin.keys.status.available"><?php echo htmlspecialchars(Lang::t('admin.keys.status.available'), ENT_QUOTES, 'UTF-8'); ?></option>
                    <option value="sold" <?php echo $status === 'sold' ? 'selected' : ''; ?> data-lang="admin.keys.status.sold"><?php echo htmlspecialchars(Lang::t('admin.keys.status.sold'), ENT_QUOTES, 'UTF-8'); ?></option>
                    <option value="deleted" <?php echo $status === 'deleted' ? 'selected' : ''; ?> data-lang="admin.keys.status.deleted"><?php echo htmlspecialchars(Lang::t('admin.keys.status.deleted'), ENT_QUOTES, 'UTF-8'); ?></option>
                </select>
            </div>
            <div class="md:col-span-5">
                <label for="keySearch" class="block text-xs text-gray-400 mb-1.5" data-lang="admin.keys.search_label"><?php echo htmlspecialchars(Lang::t('admin.keys.search_label'), ENT_QUOTES, 'UTF-8'); ?></label>
                <div class="relative">
                    <i class="bi bi-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-500"></i>
                    <input id="keySearch" type="search" name="search" value="<?php echo htmlspecialchars($search, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                           class="w-full rounded-lg border border-white/10 bg-panel pl-9 pr-3 py-2.5 text-sm text-white"
                           data-lang-placeholder="admin.keys.search_placeholder"
                           placeholder="<?php echo htmlspecialchars(Lang::t('admin.keys.search_placeholder'), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
            </div>
            <div class="md:col-span-2 flex gap-2">
                <button type="submit" class="touch-btn flex-1 rounded-lg bg-accent px-3 py-2 text-sm font-medium text-white hover:opacity-90">
                    <span data-lang="common.search"><?php echo htmlspecialchars(Lang::t('common.search'), ENT_QUOTES, 'UTF-8'); ?></span>
                </button>
                <?php if ($productId > 0 || $status !== 'all' || $search !== ''): ?>
                    <a href="keys.php" class="touch-btn inline-flex items-center justify-center rounded-lg bg-gray-700 px-3 py-2 text-white hover:bg-gray-600" title="<?php echo htmlspecialchars(Lang::t('common.clear_filters'), ENT_QUOTES, 'UTF-8'); ?>">
                        <i class="bi bi-x-lg"></i>
                    </a>
                <?php endif; ?>
            </div>
        </form>

        <div class="flex flex-wrap items-center justify-between gap-2 px-1 text-xs md:text-sm text-gray-400">
            <div><?php echo htmlspecialchars(Lang::t('admin.keys.results', ['from' => (string) $fromRow, 'to' => (string) $toRow, 'total' => number_format($totalRows)]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
            <?php if ($status === 'deleted'): ?>
                <div class="text-red-300/80" data-lang="admin.keys.deleted_hint"><?php echo htmlspecialchars(Lang::t('admin.keys.deleted_hint'), ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
        </div>

        <?php if (empty($keys)): ?>
            <div class="glass rounded-xl p-10 text-center text-gray-500">
                <i class="bi bi-key text-3xl"></i>
                <div class="mt-3 text-sm" data-lang="admin.keys.empty"><?php echo htmlspecialchars(Lang::t('admin.keys.empty'), ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
        <?php else: ?>
            <form method="POST" id="bulkForm" class="space-y-3">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="bulk_delete">
                <input type="hidden" name="delete_reason" value="bulk_delete">

                <!-- Mobile cards -->
                <div class="lg:hidden space-y-3">
                    <?php foreach ($keys as $key): ?>
                        <?php
                        $isDeleted = !empty($key['is_deleted']);
                        $isSold = strtolower((string) ($key['status'] ?? '')) === 'sold';
                        $buyerLabel = adminKeysBuyerLabel($key);
                        ?>
                        <article class="glass rounded-xl p-3.5 space-y-3">
                            <div class="flex items-start gap-2">
                                <?php if (!$isDeleted): ?>
                                    <input type="checkbox" name="key_ids[]" value="<?php echo (int) $key['id']; ?>" class="key-checkbox mobile-key-checkbox mt-1.5 h-4 w-4 rounded border-white/20 bg-transparent">
                                <?php endif; ?>
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-start gap-2">
                                        <code class="key-code-wrap min-w-0 flex-1 text-sm font-semibold text-indigo-300"><?php echo htmlspecialchars((string) $key['key_code'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></code>
                                        <button type="button" class="copy-key-btn touch-btn shrink-0 rounded-lg bg-indigo-500/15 text-indigo-300 hover:bg-indigo-500/25" data-key="<?php echo htmlspecialchars((string) $key['key_code'], ENT_QUOTES, 'UTF-8'); ?>" title="<?php echo htmlspecialchars(Lang::t('common.copy_key'), ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars(Lang::t('common.copy_key'), ENT_QUOTES, 'UTF-8'); ?>">
                                            <span class="text-base leading-none" aria-hidden="true">📋</span><span class="sr-only"><?php echo htmlspecialchars(Lang::t('common.copy_key'), ENT_QUOTES, 'UTF-8'); ?></span>
                                        </button>
                                    </div>
                                    <div class="mt-1 text-[11px] text-gray-500">#<?php echo (int) $key['id']; ?></div>
                                </div>
                            </div>

                            <div class="flex flex-wrap items-center gap-1.5">
                                <?php if ($isDeleted): ?>
                                    <span class="rounded-full bg-red-500/15 px-2 py-1 text-[11px] font-medium text-red-300" data-lang="admin.keys.status.deleted"><?php echo htmlspecialchars(Lang::t('admin.keys.status.deleted'), ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php elseif ($isSold): ?>
                                    <span class="rounded-full bg-blue-500/15 px-2 py-1 text-[11px] font-medium text-blue-300" data-lang="admin.keys.status.sold"><?php echo htmlspecialchars(Lang::t('admin.keys.status.sold'), ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php else: ?>
                                    <span class="rounded-full bg-green-500/15 px-2 py-1 text-[11px] font-medium text-green-300" data-lang="admin.keys.status.available"><?php echo htmlspecialchars(Lang::t('admin.keys.status.available'), ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php endif; ?>
                                <span class="rounded-full bg-white/5 px-2 py-1 text-[11px] text-gray-300"><?php echo htmlspecialchars((string) ($key['duration'] ?: '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                                <?php if ($isSold || ((string) ($key['sale_source'] ?? '') !== 'inventory')): ?>
                                    <span class="rounded-full bg-violet-500/15 px-2 py-1 text-[11px] text-violet-300"><?php echo htmlspecialchars(adminKeysSourceLabel($key), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                                <?php endif; ?>
                            </div>

                            <div class="grid grid-cols-2 gap-x-3 gap-y-2 text-xs">
                                <div class="col-span-2">
                                    <div class="text-gray-500" data-lang="admin.keys.table.product"><?php echo htmlspecialchars(Lang::t('admin.keys.table.product'), ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="mt-0.5 text-gray-200 break-words"><?php echo htmlspecialchars((string) ($key['product_name'] ?: '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                                </div>
                                <div>
                                    <div class="text-gray-500" data-lang="admin.keys.table.added_at"><?php echo htmlspecialchars(Lang::t('admin.keys.table.added_at'), ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="mt-0.5 text-gray-300"><?php echo htmlspecialchars(adminKeysDate($key['key_created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                </div>
                                <div>
                                    <div class="text-gray-500"><?php echo $isDeleted ? htmlspecialchars(Lang::t('admin.keys.table.deleted_at'), ENT_QUOTES, 'UTF-8') : htmlspecialchars(Lang::t('admin.keys.table.sold_at'), ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="mt-0.5 text-gray-300"><?php echo htmlspecialchars(adminKeysDate($isDeleted ? ($key['deleted_at'] ?? '') : ($key['sold_at'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></div>
                                </div>
                                <?php if ($isSold || $isDeleted): ?>
                                    <div class="col-span-2">
                                        <div class="text-gray-500" data-lang="admin.keys.table.buyer"><?php echo htmlspecialchars(Lang::t('admin.keys.table.buyer'), ENT_QUOTES, 'UTF-8'); ?></div>
                                        <div class="mt-0.5 text-gray-200 break-words"><?php echo htmlspecialchars($buyerLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="flex gap-2 border-t border-white/5 pt-3">
                                <button type="button" class="details-btn touch-btn flex-1 rounded-lg bg-white/5 px-3 py-2 text-xs font-medium text-gray-200 hover:bg-white/10" data-detail="<?php echo adminKeysDetailJson($key); ?>">
                                    <i class="bi bi-eye mr-1"></i><span data-lang="admin.keys.view_details"><?php echo htmlspecialchars(Lang::t('admin.keys.view_details'), ENT_QUOTES, 'UTF-8'); ?></span>
                                </button>
                                <?php if (!$isDeleted): ?>
                                    <button type="button" class="delete-key-btn touch-btn flex-1 rounded-lg bg-red-500/10 px-3 py-2 text-xs font-medium text-red-300 hover:bg-red-500/20"
                                            data-id="<?php echo (int) $key['id']; ?>"
                                            data-key="<?php echo htmlspecialchars((string) $key['key_code'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-history="<?php echo $isSold || (int) ($key['buyer_user_id'] ?? 0) > 0 || (int) ($key['transaction_id'] ?? 0) > 0 || (int) ($key['store_api_order_id'] ?? 0) > 0 ? '1' : '0'; ?>">
                                        <span class="mr-1" aria-hidden="true">🗑️</span><span data-lang="common.delete"><?php echo htmlspecialchars(Lang::t('common.delete'), ENT_QUOTES, 'UTF-8'); ?></span>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <!-- Desktop table -->
                <div class="hidden lg:block glass rounded-xl overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[1050px]">
                            <thead class="bg-white/[.03]">
                                <tr class="border-b border-white/10 text-left text-xs text-gray-400">
                                    <th class="p-3 w-10"><?php if ($status !== 'deleted'): ?><input type="checkbox" id="selectAll" class="h-4 w-4 rounded border-white/20 bg-transparent"><?php endif; ?></th>
                                    <th class="p-3" data-lang="admin.keys.table.key_code"><?php echo htmlspecialchars(Lang::t('admin.keys.table.key_code'), ENT_QUOTES, 'UTF-8'); ?></th>
                                    <th class="p-3" data-lang="admin.keys.table.product"><?php echo htmlspecialchars(Lang::t('admin.keys.table.product'), ENT_QUOTES, 'UTF-8'); ?></th>
                                    <th class="p-3" data-lang="admin.users.table.status"><?php echo htmlspecialchars(Lang::t('admin.users.table.status'), ENT_QUOTES, 'UTF-8'); ?></th>
                                    <th class="p-3" data-lang="admin.keys.table.buyer"><?php echo htmlspecialchars(Lang::t('admin.keys.table.buyer'), ENT_QUOTES, 'UTF-8'); ?></th>
                                    <th class="p-3" data-lang="admin.keys.table.added_at"><?php echo htmlspecialchars(Lang::t('admin.keys.table.added_at'), ENT_QUOTES, 'UTF-8'); ?></th>
                                    <th class="p-3"><?php echo $status === 'deleted' ? htmlspecialchars(Lang::t('admin.keys.table.deleted_at'), ENT_QUOTES, 'UTF-8') : htmlspecialchars(Lang::t('admin.keys.table.sold_at'), ENT_QUOTES, 'UTF-8'); ?></th>
                                    <th class="p-3 text-right" data-lang="common.action"><?php echo htmlspecialchars(Lang::t('common.action'), ENT_QUOTES, 'UTF-8'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($keys as $key): ?>
                                <?php
                                $isDeleted = !empty($key['is_deleted']);
                                $isSold = strtolower((string) ($key['status'] ?? '')) === 'sold';
                                ?>
                                <tr class="border-b border-white/5 hover:bg-white/[.025]">
                                    <td class="p-3"><?php if (!$isDeleted): ?><input type="checkbox" name="key_ids[]" value="<?php echo (int) $key['id']; ?>" class="key-checkbox desktop-key-checkbox h-4 w-4 rounded border-white/20 bg-transparent"><?php endif; ?></td>
                                    <td class="p-3 max-w-[310px]">
                                        <div class="flex items-center gap-2">
                                            <code class="key-code-wrap min-w-0 text-xs text-indigo-300"><?php echo htmlspecialchars((string) $key['key_code'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></code>
                                            <button type="button" class="copy-key-btn touch-btn shrink-0 rounded-lg text-indigo-300 hover:bg-indigo-500/15" data-key="<?php echo htmlspecialchars((string) $key['key_code'], ENT_QUOTES, 'UTF-8'); ?>" title="<?php echo htmlspecialchars(Lang::t('common.copy_key'), ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars(Lang::t('common.copy_key'), ENT_QUOTES, 'UTF-8'); ?>"><span class="text-base leading-none" aria-hidden="true">📋</span><span class="sr-only"><?php echo htmlspecialchars(Lang::t('common.copy_key'), ENT_QUOTES, 'UTF-8'); ?></span></button>
                                        </div>
                                        <div class="mt-1 text-[10px] text-gray-600">#<?php echo (int) $key['id']; ?> · <?php echo htmlspecialchars((string) ($key['duration'] ?: '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                                    </td>
                                    <td class="p-3 text-xs text-gray-200"><?php echo htmlspecialchars((string) ($key['product_name'] ?: '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
                                    <td class="p-3 text-xs">
                                        <?php if ($isDeleted): ?>
                                            <span class="rounded-full bg-red-500/15 px-2 py-1 text-red-300" data-lang="admin.keys.status.deleted"><?php echo htmlspecialchars(Lang::t('admin.keys.status.deleted'), ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php elseif ($isSold): ?>
                                            <span class="rounded-full bg-blue-500/15 px-2 py-1 text-blue-300" data-lang="admin.keys.status.sold"><?php echo htmlspecialchars(Lang::t('admin.keys.status.sold'), ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php else: ?>
                                            <span class="rounded-full bg-green-500/15 px-2 py-1 text-green-300" data-lang="admin.keys.status.available"><?php echo htmlspecialchars(Lang::t('admin.keys.status.available'), ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="p-3 text-xs">
                                        <div class="text-gray-200"><?php echo htmlspecialchars(adminKeysBuyerLabel($key), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                                        <?php if ($isSold || ((string) ($key['sale_source'] ?? '') !== 'inventory')): ?><div class="mt-1 text-[10px] text-violet-300"><?php echo htmlspecialchars(adminKeysSourceLabel($key), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div><?php endif; ?>
                                    </td>
                                    <td class="p-3 text-xs text-gray-400 whitespace-nowrap"><?php echo htmlspecialchars(adminKeysDate($key['key_created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="p-3 text-xs text-gray-400 whitespace-nowrap"><?php echo htmlspecialchars(adminKeysDate($isDeleted ? ($key['deleted_at'] ?? '') : ($key['sold_at'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="p-3">
                                        <div class="flex items-center justify-end gap-1">
                                            <button type="button" class="details-btn touch-btn rounded-lg text-gray-300 hover:bg-white/10" data-detail="<?php echo adminKeysDetailJson($key); ?>" title="<?php echo htmlspecialchars(Lang::t('admin.keys.view_details'), ENT_QUOTES, 'UTF-8'); ?>"><i class="bi bi-eye"></i></button>
                                            <?php if (!$isDeleted): ?>
                                                <button type="button" class="delete-key-btn touch-btn rounded-lg text-red-300 hover:bg-red-500/15"
                                                        data-id="<?php echo (int) $key['id']; ?>"
                                                        data-key="<?php echo htmlspecialchars((string) $key['key_code'], ENT_QUOTES, 'UTF-8'); ?>"
                                                        data-history="<?php echo $isSold || (int) ($key['buyer_user_id'] ?? 0) > 0 || (int) ($key['transaction_id'] ?? 0) > 0 || (int) ($key['store_api_order_id'] ?? 0) > 0 ? '1' : '0'; ?>"
                                                        title="<?php echo htmlspecialchars(Lang::t('common.delete'), ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars(Lang::t('common.delete'), ENT_QUOTES, 'UTF-8'); ?>"><span aria-hidden="true">🗑️</span><span class="sr-only"><?php echo htmlspecialchars(Lang::t('common.delete'), ENT_QUOTES, 'UTF-8'); ?></span></button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php if ($status !== 'deleted'): ?>
                    <div id="bulkActions" class="hidden sticky bottom-3 z-20 rounded-xl border border-red-500/25 bg-[#181318]/95 p-2.5 shadow-xl backdrop-blur-md flex items-center justify-between gap-3">
                        <div class="text-xs text-gray-300"><span id="selectedCount">0</span> <span data-lang="admin.keys.selected"><?php echo htmlspecialchars(Lang::t('admin.keys.selected'), ENT_QUOTES, 'UTF-8'); ?></span></div>
                        <button type="button" id="bulkDeleteBtn" class="touch-btn rounded-lg bg-red-500 px-3 py-2 text-xs font-semibold text-white hover:bg-red-400">
                            <span class="mr-1" aria-hidden="true">🗑️</span><span data-lang="admin.keys.bulk_delete"><?php echo htmlspecialchars(Lang::t('admin.keys.bulk_delete'), ENT_QUOTES, 'UTF-8'); ?></span>
                        </button>
                    </div>
                <?php endif; ?>
            </form>

            <?php if ($totalPages > 1): ?>
                <nav class="flex flex-wrap items-center justify-center gap-1.5 pt-2" aria-label="Pagination">
                    <?php if ($page > 1): ?>
                        <a href="<?php echo htmlspecialchars(adminKeysUrl(['page' => $page - 1]), ENT_QUOTES, 'UTF-8'); ?>" class="touch-btn inline-flex items-center justify-center rounded-lg border border-white/10 bg-white/5 px-3 text-sm text-gray-200 hover:bg-white/10" data-lang="common.previous"><?php echo htmlspecialchars(Lang::t('common.previous'), ENT_QUOTES, 'UTF-8'); ?></a>
                    <?php endif; ?>
                    <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    if ($startPage > 1): ?>
                        <a href="<?php echo htmlspecialchars(adminKeysUrl(['page' => 1]), ENT_QUOTES, 'UTF-8'); ?>" class="touch-btn inline-flex items-center justify-center rounded-lg border border-white/10 bg-white/5 px-3 text-sm text-gray-300">1</a>
                        <?php if ($startPage > 2): ?><span class="px-1 text-gray-600">…</span><?php endif; ?>
                    <?php endif; ?>
                    <?php for ($p = $startPage; $p <= $endPage; $p++): ?>
                        <a href="<?php echo htmlspecialchars(adminKeysUrl(['page' => $p]), ENT_QUOTES, 'UTF-8'); ?>" class="touch-btn inline-flex items-center justify-center rounded-lg border px-3 text-sm <?php echo $p === $page ? 'border-indigo-400 bg-indigo-500/20 text-indigo-200' : 'border-white/10 bg-white/5 text-gray-300 hover:bg-white/10'; ?>"><?php echo $p; ?></a>
                    <?php endfor; ?>
                    <?php if ($endPage < $totalPages): ?>
                        <?php if ($endPage < $totalPages - 1): ?><span class="px-1 text-gray-600">…</span><?php endif; ?>
                        <a href="<?php echo htmlspecialchars(adminKeysUrl(['page' => $totalPages]), ENT_QUOTES, 'UTF-8'); ?>" class="touch-btn inline-flex items-center justify-center rounded-lg border border-white/10 bg-white/5 px-3 text-sm text-gray-300"><?php echo $totalPages; ?></a>
                    <?php endif; ?>
                    <?php if ($page < $totalPages): ?>
                        <a href="<?php echo htmlspecialchars(adminKeysUrl(['page' => $page + 1]), ENT_QUOTES, 'UTF-8'); ?>" class="touch-btn inline-flex items-center justify-center rounded-lg border border-white/10 bg-white/5 px-3 text-sm text-gray-200 hover:bg-white/10" data-lang="common.next"><?php echo htmlspecialchars(Lang::t('common.next'), ENT_QUOTES, 'UTF-8'); ?></a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>

<!-- Details modal -->
<div id="detailsModal" class="fixed inset-0 z-[80] hidden items-end sm:items-center justify-center bg-black/70 p-0 sm:p-4" role="dialog" aria-modal="true" aria-labelledby="detailsTitle">
    <div class="w-full sm:max-w-lg rounded-t-2xl sm:rounded-2xl border border-white/10 bg-[#15151b] shadow-2xl max-h-[88vh] overflow-y-auto">
        <div class="sticky top-0 z-10 flex items-center justify-between border-b border-white/10 bg-[#15151b]/95 px-4 py-3 backdrop-blur-md">
            <h2 id="detailsTitle" class="font-semibold text-white" data-lang="admin.keys.details_title"><?php echo htmlspecialchars(Lang::t('admin.keys.details_title'), ENT_QUOTES, 'UTF-8'); ?></h2>
            <button type="button" class="modal-close touch-btn rounded-lg text-gray-400 hover:bg-white/10 hover:text-white" aria-label="<?php echo htmlspecialchars(Lang::t('common.close'), ENT_QUOTES, 'UTF-8'); ?>"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="p-4 space-y-4">
            <div>
                <div class="text-xs text-gray-500" data-lang="admin.keys.table.key_code"><?php echo htmlspecialchars(Lang::t('admin.keys.table.key_code'), ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="mt-1 flex items-start gap-2 rounded-xl bg-black/20 p-3">
                    <code id="detailKey" class="key-code-wrap flex-1 text-sm text-indigo-300"></code>
                    <button type="button" id="detailCopyBtn" class="touch-btn shrink-0 rounded-lg bg-indigo-500/15 text-indigo-300" aria-label="<?php echo htmlspecialchars(Lang::t('common.copy_key'), ENT_QUOTES, 'UTF-8'); ?>"><span class="text-base leading-none" aria-hidden="true">📋</span><span class="sr-only"><?php echo htmlspecialchars(Lang::t('common.copy_key'), ENT_QUOTES, 'UTF-8'); ?></span></button>
                </div>
            </div>
            <dl id="detailGrid" class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm"></dl>
        </div>
    </div>
</div>

<!-- Delete modal -->
<div id="deleteModal" class="fixed inset-0 z-[90] hidden items-end sm:items-center justify-center bg-black/75 p-0 sm:p-4" role="dialog" aria-modal="true" aria-labelledby="deleteTitle">
    <div class="w-full sm:max-w-md rounded-t-2xl sm:rounded-2xl border border-red-500/20 bg-[#181418] shadow-2xl">
        <form method="POST" id="deleteForm" class="p-4 sm:p-5">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="key_id" id="deleteKeyId">
            <div class="flex items-start gap-3">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-500/15 text-red-300" aria-hidden="true">🗑️</div>
                <div class="min-w-0 flex-1">
                    <h2 id="deleteTitle" class="font-semibold text-white" data-lang="admin.keys.delete_title"><?php echo htmlspecialchars(Lang::t('admin.keys.delete_title'), ENT_QUOTES, 'UTF-8'); ?></h2>
                    <code id="deleteKeyLabel" class="key-code-wrap mt-1 block text-xs text-red-200"></code>
                </div>
                <button type="button" class="modal-close touch-btn rounded-lg text-gray-400 hover:bg-white/10" aria-label="<?php echo htmlspecialchars(Lang::t('common.close'), ENT_QUOTES, 'UTF-8'); ?>"><i class="bi bi-x-lg"></i></button>
            </div>
            <div id="deleteHistoryWarning" class="mt-4 hidden rounded-xl border border-amber-500/25 bg-amber-500/10 p-3 text-xs leading-relaxed text-amber-200" data-lang="admin.keys.delete_sold_warning"><?php echo htmlspecialchars(Lang::t('admin.keys.delete_sold_warning'), ENT_QUOTES, 'UTF-8'); ?></div>
            <div id="deleteAvailableWarning" class="mt-4 rounded-xl border border-red-500/20 bg-red-500/5 p-3 text-xs leading-relaxed text-gray-300" data-lang="admin.keys.delete_available_warning"><?php echo htmlspecialchars(Lang::t('admin.keys.delete_available_warning'), ENT_QUOTES, 'UTF-8'); ?></div>
            <label for="deleteReason" class="mt-4 block text-xs text-gray-400" data-lang="admin.keys.delete_reason"><?php echo htmlspecialchars(Lang::t('admin.keys.delete_reason'), ENT_QUOTES, 'UTF-8'); ?></label>
            <select id="deleteReason" name="delete_reason" class="mt-1.5 w-full rounded-lg border border-white/10 bg-panel px-3 py-2.5 text-sm text-white">
                <option value="wrong_entry" data-lang="admin.keys.reason.wrong_entry"><?php echo htmlspecialchars(Lang::t('admin.keys.reason.wrong_entry'), ENT_QUOTES, 'UTF-8'); ?></option>
                <option value="unusable" data-lang="admin.keys.reason.unusable"><?php echo htmlspecialchars(Lang::t('admin.keys.reason.unusable'), ENT_QUOTES, 'UTF-8'); ?></option>
                <option value="no_longer_needed" data-lang="admin.keys.reason.no_longer_needed"><?php echo htmlspecialchars(Lang::t('admin.keys.reason.no_longer_needed'), ENT_QUOTES, 'UTF-8'); ?></option>
                <option value="other" data-lang="admin.keys.reason.other"><?php echo htmlspecialchars(Lang::t('admin.keys.reason.other'), ENT_QUOTES, 'UTF-8'); ?></option>
            </select>
            <div class="mt-5 flex gap-2">
                <button type="button" class="modal-close touch-btn flex-1 rounded-lg bg-gray-700 px-3 py-2 text-sm font-medium text-white hover:bg-gray-600" data-lang="common.cancel"><?php echo htmlspecialchars(Lang::t('common.cancel'), ENT_QUOTES, 'UTF-8'); ?></button>
                <button type="submit" class="touch-btn flex-1 rounded-lg bg-red-500 px-3 py-2 text-sm font-semibold text-white hover:bg-red-400" data-lang="admin.keys.confirm_delete_btn"><?php echo htmlspecialchars(Lang::t('admin.keys.confirm_delete_btn'), ENT_QUOTES, 'UTF-8'); ?></button>
            </div>
        </form>
    </div>
</div>

<div id="copyToast" class="pointer-events-none fixed bottom-5 left-1/2 z-[100] hidden -translate-x-1/2 rounded-full border border-green-500/25 bg-[#152019]/95 px-4 py-2 text-xs font-medium text-green-200 shadow-xl" role="status" aria-live="polite">
    <span data-lang="admin.keys.copied"><?php echo htmlspecialchars(Lang::t('admin.keys.copied'), ENT_QUOTES, 'UTF-8'); ?></span>
</div>

<script>
(() => {
    'use strict';

    const body = document.body;
    let lastFocused = null;
    let detailKeyValue = '';

    const text = (key, fallback) => (window.Lang && typeof Lang.t === 'function') ? Lang.t(key) : fallback;

    function openModal(modal) {
        if (!modal) return;
        lastFocused = document.activeElement;
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        body.classList.add('overflow-hidden');
        const focusable = modal.querySelector('button,select,input,a[href]');
        if (focusable) setTimeout(() => focusable.focus(), 0);
    }

    function closeModal(modal) {
        if (!modal) return;
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        if (!document.querySelector('#detailsModal.flex,#deleteModal.flex')) body.classList.remove('overflow-hidden');
        if (lastFocused && typeof lastFocused.focus === 'function') lastFocused.focus();
    }

    function showToast(message) {
        const toast = document.getElementById('copyToast');
        if (!toast) return;
        const span = toast.querySelector('span');
        if (span && message) span.textContent = message;
        toast.classList.remove('hidden');
        clearTimeout(showToast.timer);
        showToast.timer = setTimeout(() => toast.classList.add('hidden'), 1400);
    }

    async function copyText(value) {
        if (!value) return false;
        try {
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(value);
                return true;
            }
        } catch (_) {}
        const area = document.createElement('textarea');
        area.value = value;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        let ok = false;
        try { ok = document.execCommand('copy'); } catch (_) {}
        area.remove();
        return ok;
    }

    function addDetailItem(grid, label, value, options = {}) {
        if (value === '' || value === null || typeof value === 'undefined' || value === 0) return;
        const wrapper = document.createElement('div');
        if (options.full) wrapper.className = 'sm:col-span-2';
        const dt = document.createElement('dt');
        dt.className = 'text-xs text-gray-500';
        dt.textContent = label;
        const dd = document.createElement('dd');
        dd.className = 'mt-1 break-words text-gray-200';
        if (options.href) {
            const link = document.createElement('a');
            link.href = options.href;
            link.className = 'text-indigo-300 hover:text-indigo-200 hover:underline';
            link.textContent = String(value);
            dd.appendChild(link);
        } else {
            dd.textContent = String(value);
        }
        wrapper.append(dt, dd);
        grid.appendChild(wrapper);
    }

    function openDetails(button) {
        let data = {};
        try { data = JSON.parse(button.dataset.detail || '{}'); } catch (_) { return; }
        detailKeyValue = data.key || '';
        document.getElementById('detailKey').textContent = detailKeyValue;
        const grid = document.getElementById('detailGrid');
        grid.replaceChildren();

        addDetailItem(grid, text('admin.keys.table.product', 'Product'), data.product, { full: true });
        addDetailItem(grid, text('admin.reseller_prices.table.duration', 'Duration'), data.duration || '—');
        addDetailItem(grid, text('admin.users.table.status', 'Status'), data.deleted ? text('admin.keys.status.deleted', 'Deleted') : data.status);
        addDetailItem(grid, text('admin.keys.table.source', 'Source'), data.source);
        const buyerHref = data.buyer_id && data.buyer_role === 'reseller'
            ? ('resellers.php?search=' + encodeURIComponent(String(data.buyer_id)))
            : (data.buyer_id && data.buyer_role === 'user'
                ? ('users.php?search=' + encodeURIComponent(String(data.buyer_id)))
                : '');
        addDetailItem(grid, text('admin.keys.table.buyer', 'Buyer'), data.buyer, buyerHref ? { href: buyerHref } : {});
        addDetailItem(grid, text('admin.keys.table.buyer_id', 'Buyer ID'), data.buyer_id || '', buyerHref ? { href: buyerHref } : {});
        addDetailItem(grid, text('admin.users.table.email', 'Email'), data.buyer_email || '', { full: true });
        addDetailItem(grid, text('admin.keys.table.added_at', 'Added'), data.created_at);
        addDetailItem(grid, text('admin.keys.table.sold_at', 'Sold'), data.sold_at);
        addDetailItem(
            grid,
            text('admin.keys.table.transaction', 'Transaction'),
            data.transaction_id ? ('#' + data.transaction_id) : '',
            data.transaction_id ? { href: 'transactions.php?search=' + encodeURIComponent(String(data.transaction_id)) } : {}
        );
        addDetailItem(grid, text('admin.keys.table.paid', 'Paid'), data.transaction_amount || '');
        addDetailItem(grid, text('admin.keys.table.store_order', 'Store API Order'), data.store_order_id ? ('#' + data.store_order_id) : '');
        addDetailItem(grid, text('admin.keys.table.external_ref', 'External Ref'), data.store_external_ref || '');
        addDetailItem(grid, text('admin.keys.table.api_client', 'API Client'), data.store_client || '');
        if (data.deleted) {
            addDetailItem(grid, text('admin.keys.table.deleted_at', 'Deleted'), data.deleted_at);
            addDetailItem(grid, text('admin.keys.delete_reason', 'Reason'), text('admin.keys.reason.' + data.delete_reason, data.delete_reason || '—'), { full: true });
        }
        openModal(document.getElementById('detailsModal'));
    }

    function openDelete(button) {
        const modal = document.getElementById('deleteModal');
        document.getElementById('deleteKeyId').value = button.dataset.id || '';
        document.getElementById('deleteKeyLabel').textContent = button.dataset.key || '';
        const history = button.dataset.history === '1';
        document.getElementById('deleteHistoryWarning').classList.toggle('hidden', !history);
        document.getElementById('deleteAvailableWarning').classList.toggle('hidden', history);
        openModal(modal);
    }

    function updateSelection() {
        const checked = Array.from(document.querySelectorAll('.key-checkbox:checked'));
        const selectedIds = new Set(checked.map(cb => cb.value));
        const desktopAll = Array.from(document.querySelectorAll('.desktop-key-checkbox'));
        const desktopChecked = desktopAll.filter(cb => cb.checked);
        const bar = document.getElementById('bulkActions');
        const count = document.getElementById('selectedCount');
        const selectAll = document.getElementById('selectAll');
        if (count) count.textContent = String(selectedIds.size);
        if (bar) bar.classList.toggle('hidden', selectedIds.size === 0);
        if (selectAll) {
            selectAll.checked = desktopAll.length > 0 && desktopChecked.length === desktopAll.length;
            selectAll.indeterminate = desktopChecked.length > 0 && desktopChecked.length < desktopAll.length;
        }
    }

    document.addEventListener('click', async (event) => {
        const copyButton = event.target.closest('.copy-key-btn');
        if (copyButton) {
            const ok = await copyText(copyButton.dataset.key || '');
            showToast(ok ? text('admin.keys.copied', 'Copied') : text('common.copy_failed', 'Copy failed'));
            return;
        }
        const detailButton = event.target.closest('.details-btn');
        if (detailButton) { openDetails(detailButton); return; }
        const deleteButton = event.target.closest('.delete-key-btn');
        if (deleteButton) { openDelete(deleteButton); return; }
        const bulkButton = event.target.closest('#bulkDeleteBtn');
        if (bulkButton) {
            const selectedIds = new Set(Array.from(document.querySelectorAll('.key-checkbox:checked')).map(cb => cb.value));
            const count = selectedIds.size;
            if (!count) return;
            const message = text('admin.keys.confirm_bulk_delete', 'Delete selected keys?').replace('{count}', String(count));
            if (window.confirm(message)) document.getElementById('bulkForm')?.requestSubmit();
            return;
        }
        const closeButton = event.target.closest('.modal-close');
        if (closeButton) { closeModal(closeButton.closest('[role="dialog"]')); return; }
        if (event.target.matches('#detailsModal,#deleteModal')) closeModal(event.target);
    });

    document.addEventListener('change', (event) => {
        if (event.target.matches('.key-checkbox')) {
            const value = event.target.value;
            document.querySelectorAll('.key-checkbox').forEach(cb => {
                if (cb.value === value) cb.checked = event.target.checked;
            });
            updateSelection();
        }
        if (event.target.id === 'selectAll') {
            document.querySelectorAll('.key-checkbox').forEach(cb => { cb.checked = event.target.checked; });
            updateSelection();
        }
    });


    document.getElementById('detailCopyBtn')?.addEventListener('click', async () => {
        const ok = await copyText(detailKeyValue);
        showToast(ok ? text('admin.keys.copied', 'Copied') : text('common.copy_failed', 'Copy failed'));
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        const open = document.querySelector('#deleteModal.flex,#detailsModal.flex');
        if (open) closeModal(open);
    });

    document.addEventListener('instantfilter:updated', updateSelection);
    updateSelection();
})();
</script>
<script src="../assets/js/instant-filter.js?v=3.0"></script>
</body>
</html>
