<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/cheatgame.php';
requireAdmin();

global $conn;
$error = '';
$success = '';
$pageLang = getAppLang() === 'en' ? 'en' : 'th';

function specialPriceText(string $th, string $en): string
{
    return getAppLang() === 'en' ? $en : $th;
}

function specialPriceRedirect(int $accountId = 0, string $accountQuery = '', string $anchor = ''): void
{
    $params = [];
    if ($accountId > 0) $params['account_id'] = $accountId;
    if ($accountQuery !== '') $params['account_q'] = $accountQuery;

    $roleFilter = $_POST['account_role'] ?? $_GET['account_role'] ?? 'all';
    $priceState = $_POST['account_price_state'] ?? $_GET['account_price_state'] ?? 'all';
    if (is_scalar($roleFilter) && in_array((string) $roleFilter, ['all', 'user', 'reseller'], true) && (string) $roleFilter !== 'all') {
        $params['account_role'] = (string) $roleFilter;
    }
    if (is_scalar($priceState) && in_array((string) $priceState, ['all', 'configured', 'unconfigured'], true) && (string) $priceState !== 'all') {
        $params['account_price_state'] = (string) $priceState;
    }

    $url = 'reseller-prices.php' . ($params ? '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986) : '');
    if ($anchor !== '') $url .= '#' . rawurlencode($anchor);
    header('Location: ' . $url, true, 303);
    exit();
}

function specialPriceValidAccount(?array $account): bool
{
    if (!$account) return false;
    return in_array((string) ($account['role'] ?? ''), ['user', 'reseller'], true);
}

function specialPriceParseAmount($raw): ?float
{
    if (!is_scalar($raw)) return null;
    $value = str_replace(',', '.', trim((string) $raw));
    if ($value === '' || !is_numeric($value)) return null;
    $amount = round((float) $value, 2);
    return is_finite($amount) && $amount > 0 && $amount <= 10000000 ? $amount : null;
}

function specialPriceBelowCostConfirmed(): bool
{
    $value = $_POST['confirm_below_cost'] ?? '';
    return is_scalar($value) && hash_equals('1', (string) $value);
}

function specialPriceCostLookupSql(bool $cgoReady): string
{
    if (!$cgoReady) {
        return "SELECT pv.id, pv.price_user, pv.price_reseller, COALESCE(pv.cost_price, 0) AS local_cost, 0 AS api_cost, COALESCE(pv.cost_price, 0) AS effective_cost
                FROM product_variants pv
                WHERE pv.id = ? AND pv.status = 'active' LIMIT 1";
    }

    return "SELECT pv.id, pv.price_user, pv.price_reseller,
                   COALESCE(pv.cost_price, 0) AS local_cost,
                   COALESCE(api.api_cost, 0) AS api_cost,
                   GREATEST(COALESCE(pv.cost_price, 0), COALESCE(api.api_cost, 0)) AS effective_cost
            FROM product_variants pv
            LEFT JOIN (
                SELECT l.local_variant_id, MAX(cp.cost_base) AS api_cost
                FROM cgo_catalog_links l
                JOIN cgo_products cp ON cp.id = l.cgo_product_id
                WHERE cp.supplier_removed_at IS NULL
                GROUP BY l.local_variant_id
            ) api ON api.local_variant_id = pv.id
            WHERE pv.id = ? AND pv.status = 'active' LIMIT 1";
}

if (isset($_SESSION['success_message'])) {
    $success = (string) $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    $error = (string) $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

$tableReady = ensureResellerVariantPricesTable();
if (!$tableReady && $error === '') {
    $error = specialPriceText('ไม่สามารถเตรียมตารางราคาพิเศษได้', 'The special-price table could not be prepared.');
}

$cgoReady = function_exists('cgoEnsureTables') && cgoEnsureTables();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    requireCsrfToken();

    $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
    if ($action === 'set_reseller_prices') $action = 'save_prices'; // Backward compatibility.
    $accountIdRaw = $_POST['account_id'] ?? $_POST['reseller_id'] ?? 0;
    $accountId = is_scalar($accountIdRaw) ? (int) $accountIdRaw : 0;
    $accountQuery = isset($_POST['account_q']) && is_scalar($_POST['account_q'])
        ? substr(trim((string) $_POST['account_q']), 0, 100)
        : '';
    $account = $accountId > 0 ? getUserById($accountId) : null;

    if (!$tableReady) {
        $_SESSION['error_message'] = specialPriceText('ตารางราคาพิเศษยังไม่พร้อมใช้งาน', 'The special-price table is not ready.');
        specialPriceRedirect($accountId, $accountQuery);
    }

    if ($action === 'sync_api_costs') {
        if (!$cgoReady) {
            $_SESSION['error_message'] = specialPriceText('ไม่สามารถเชื่อมต่อระบบสินค้า API เพื่อซิงค์ต้นทุนได้', 'The API product system is unavailable, so costs could not be synchronized.');
            specialPriceRedirect($accountId, $accountQuery, 'price-editor');
        }
        try {
            $syncResult = cgoSyncProducts();
        } catch (Throwable $e) {
            error_log('Special-price API cost sync failed: ' . $e->getMessage());
            $syncResult = ['success' => false, 'message' => 'Unexpected API synchronization failure'];
        }
        if (!empty($syncResult['success'])) {
            $synced = (int) ($syncResult['synced'] ?? 0);
            $linked = (int) ($syncResult['linked_synced'] ?? 0);
            $_SESSION['success_message'] = specialPriceText(
                "ซิงค์ต้นทุน API ล่าสุดแล้ว {$synced} รายการ และอัปเดตสินค้าที่เชื่อมไว้ {$linked} รายการ",
                "Synchronized {$synced} API product cost(s) and refreshed {$linked} linked catalogue item(s)."
            );
            logHistory((int) $_SESSION['user_id'], 'sync_special_price_api_costs', 'Synced API costs before account special-price review: products=' . $synced . ', linked=' . $linked);
        } else {
            $_SESSION['error_message'] = specialPriceText(
                'ซิงค์ต้นทุน API ไม่สำเร็จ ระบบยังคงใช้ต้นทุนล่าสุดที่บันทึกไว้',
                'API cost synchronization failed. The last stored cost will remain in use.'
            );
        }
        specialPriceRedirect($accountId, $accountQuery, 'price-editor');
    }

    // Preserve the balance-adjustment action supported by the old page.
    if ($action === 'deposit_balance') {
        if (!specialPriceValidAccount($account)) {
            $_SESSION['error_message'] = specialPriceText('บัญชีที่เลือกไม่ถูกต้อง', 'The selected account is invalid.');
            specialPriceRedirect($accountId, $accountQuery);
        }
        $amount = isset($_POST['amount']) && is_scalar($_POST['amount']) ? (float) $_POST['amount'] : 0.0;
        $type = isset($_POST['deposit_type']) && is_string($_POST['deposit_type']) ? $_POST['deposit_type'] : 'deposit';
        $operation = $type === 'withdraw' ? 'deduct' : 'add';
        $managedRole = (string) ($account['role'] ?? 'user');
        $result = adjustManagedAccountBalance($accountId, $managedRole, $operation, $amount);
        if (!empty($result['success'])) {
            $_SESSION['success_message'] = formatCurrency($amount)
                . ($operation === 'add' ? specialPriceText(' เพิ่มให้ ', ' added to ') : specialPriceText(' ถอนจาก ', ' withdrawn from '))
                . (string) ($result['username'] ?? $account['username'])
                . specialPriceText(' ยอดใหม่: ', '. New balance: ')
                . formatCurrency((float) ($result['new_balance'] ?? 0));
            logHistory((int) $_SESSION['user_id'], $operation === 'add' ? 'deposit_balance' : 'withdraw_balance', 'Updated account balance: ' . (string) ($result['username'] ?? $account['username']));
        } else {
            $_SESSION['error_message'] = (string) ($result['message'] ?? specialPriceText('ปรับยอดเงินไม่สำเร็จ', 'Balance adjustment failed.'));
        }
        specialPriceRedirect($accountId, $accountQuery);
    }

    if ($action === 'save_prices') {
        $prices = $_POST['prices'] ?? [];
        if (!specialPriceValidAccount($account) || !is_array($prices) || count($prices) > 2000) {
            $_SESSION['error_message'] = specialPriceText('ข้อมูลบัญชีหรือราคาที่ส่งมาไม่ถูกต้อง', 'The submitted account or price data is invalid.');
            specialPriceRedirect($accountId, $accountQuery);
        }

        $variantCheck = $conn->prepare(specialPriceCostLookupSql($cgoReady));
        $upsert = $conn->prepare(
            "INSERT INTO reseller_variant_prices
                (reseller_id, variant_id, custom_price, status, below_cost_confirmed, confirmed_cost, confirmed_at)
             VALUES (?, ?, ?, 'active', ?, NULLIF(?, 0), IF(? = 1, NOW(), NULL))
             ON DUPLICATE KEY UPDATE
                confirmed_at = CASE
                    WHEN VALUES(below_cost_confirmed) = 0 THEN NULL
                    WHEN below_cost_confirmed = 1
                         AND confirmed_cost + 0.00001 >= VALUES(confirmed_cost)
                         AND ABS(custom_price - VALUES(custom_price)) < 0.00001
                    THEN confirmed_at
                    ELSE NOW()
                END,
                confirmed_cost = VALUES(confirmed_cost),
                below_cost_confirmed = VALUES(below_cost_confirmed),
                custom_price = VALUES(custom_price),
                updated_at = NOW()"
        );
        $delete = $conn->prepare('DELETE FROM reseller_variant_prices WHERE reseller_id = ? AND variant_id = ?');
        $existingOverride = $conn->prepare(
            'SELECT custom_price, below_cost_confirmed, confirmed_cost
             FROM reseller_variant_prices
             WHERE reseller_id = ? AND variant_id = ? LIMIT 1'
        );

        if (!$variantCheck || !$upsert || !$delete || !$existingOverride) {
            $_SESSION['error_message'] = specialPriceText('ไม่สามารถเตรียมคำสั่งบันทึกราคาได้', 'Unable to prepare price update statements.');
            specialPriceRedirect($accountId, $accountQuery);
        }

        $confirmedBelowCost = specialPriceBelowCostConfirmed();
        $saved = 0;
        $removed = 0;
        $belowCostSaved = 0;
        $conn->begin_transaction();
        try {
            foreach ($prices as $variantRaw => $priceRaw) {
                if (!is_scalar($variantRaw) || !is_scalar($priceRaw)) continue;
                $variantId = (int) $variantRaw;
                if ($variantId < 1) continue;

                $variantCheck->bind_param('i', $variantId);
                if (!$variantCheck->execute()) throw new RuntimeException('variant lookup failed');
                $variantResult = $variantCheck->get_result();
                $variant = $variantResult ? $variantResult->fetch_assoc() : null;
                if (!$variant) continue;

                $raw = trim((string) $priceRaw);
                if ($raw === '') {
                    $delete->bind_param('ii', $accountId, $variantId);
                    if (!$delete->execute()) throw new RuntimeException('price delete failed');
                    $removed += max(0, (int) $delete->affected_rows);
                    continue;
                }

                $customPrice = specialPriceParseAmount($raw);
                if ($customPrice === null) {
                    throw new InvalidArgumentException(specialPriceText('พบราคาที่ไม่ถูกต้อง ราคาต้องมากกว่า 0', 'An invalid price was found. Prices must be greater than zero.'));
                }

                $effectiveCost = round(max(0.0, (float) ($variant['effective_cost'] ?? 0)), 2);
                $belowCostFlag = 0;
                $confirmedCost = 0.0;
                if ($effectiveCost > 0 && $customPrice + 0.00001 < $effectiveCost) {
                    $existingOverride->bind_param('ii', $accountId, $variantId);
                    if (!$existingOverride->execute()) throw new RuntimeException('existing override lookup failed');
                    $existingResult = $existingOverride->get_result();
                    $existingRow = $existingResult ? $existingResult->fetch_assoc() : null;
                    $existingPrice = $existingRow ? round((float) ($existingRow['custom_price'] ?? 0), 2) : 0.0;
                    $existingConfirmedCost = $existingRow && is_numeric($existingRow['confirmed_cost'] ?? null)
                        ? round((float) $existingRow['confirmed_cost'], 2)
                        : 0.0;
                    $existingConfirmationValid = $existingRow
                        && (int) ($existingRow['below_cost_confirmed'] ?? 0) === 1
                        && abs($existingPrice - $customPrice) < 0.00001
                        && $existingConfirmedCost + 0.00001 >= $effectiveCost;
                    if (!$existingConfirmationValid && !$confirmedBelowCost) {
                        throw new DomainException('below_cost_confirmation_required');
                    }
                    $belowCostFlag = 1;
                    $confirmedCost = $existingConfirmationValid
                        ? max($effectiveCost, $existingConfirmedCost)
                        : $effectiveCost;
                    if (!$existingConfirmationValid) $belowCostSaved++;
                }

                $upsert->bind_param('iididi', $accountId, $variantId, $customPrice, $belowCostFlag, $confirmedCost, $belowCostFlag);
                if (!$upsert->execute()) throw new RuntimeException('price upsert failed');
                $saved++;
            }

            $conn->commit();
            $_SESSION['success_message'] = specialPriceText(
                "บันทึกราคาพิเศษแล้ว {$saved} รายการ และล้าง {$removed} รายการ" . ($belowCostSaved > 0 ? " (ยืนยันราคาต่ำกว่าต้นทุน {$belowCostSaved} รายการ)" : ''),
                "Saved {$saved} special price(s) and cleared {$removed}." . ($belowCostSaved > 0 ? " {$belowCostSaved} below-cost price(s) were explicitly confirmed." : '')
            );
            logHistory((int) $_SESSION['user_id'], 'set_account_special_prices', 'Account ID ' . $accountId . ': saved ' . $saved . ', removed ' . $removed . ', below_cost_confirmed=' . $belowCostSaved);
        } catch (Throwable $e) {
            try { $conn->rollback(); } catch (Throwable $ignored) {}
            error_log('Special price save failed: ' . $e->getMessage());
            if ($e instanceof DomainException && $e->getMessage() === 'below_cost_confirmation_required') {
                $_SESSION['error_message'] = specialPriceText(
                    'พบราคาที่ต่ำกว่าต้นทุนล่าสุด กรุณากดยืนยันในหน้าต่างเตือนก่อนบันทึกอีกครั้ง',
                    'A price is below the latest known cost. Confirm the warning before saving again.'
                );
            } else {
                $_SESSION['error_message'] = $e instanceof InvalidArgumentException
                    ? $e->getMessage()
                    : specialPriceText('บันทึกราคาพิเศษไม่สำเร็จ และไม่มีการเปลี่ยนแปลงข้อมูล', 'Special prices could not be saved. No changes were applied.');
            }
        } finally {
            $variantCheck->close();
            $upsert->close();
            $delete->close();
            $existingOverride->close();
        }
        specialPriceRedirect($accountId, $accountQuery, 'price-editor');
    }

    if ($action === 'apply_bulk') {
        $variantIdsRaw = $_POST['variant_ids'] ?? [];
        $mode = isset($_POST['bulk_mode']) && is_string($_POST['bulk_mode']) ? $_POST['bulk_mode'] : 'discount';
        $discount = isset($_POST['discount_percent']) && is_scalar($_POST['discount_percent'])
            ? (float) str_replace(',', '.', trim((string) $_POST['discount_percent']))
            : 0.0;
        $fixedPrice = specialPriceParseAmount($_POST['fixed_price'] ?? null);

        if (!specialPriceValidAccount($account) || !is_array($variantIdsRaw) || count($variantIdsRaw) < 1 || count($variantIdsRaw) > 1000) {
            $_SESSION['error_message'] = specialPriceText('กรุณาเลือกสินค้าอย่างน้อย 1 รายการ', 'Select at least one product variant.');
            specialPriceRedirect($accountId, $accountQuery, 'price-editor');
        }
        if ($mode === 'discount' && (!is_finite($discount) || $discount <= 0 || $discount >= 100)) {
            $_SESSION['error_message'] = specialPriceText('เปอร์เซ็นต์ส่วนลดต้องมากกว่า 0 และน้อยกว่า 100', 'The discount must be greater than 0 and less than 100.');
            specialPriceRedirect($accountId, $accountQuery, 'price-editor');
        }
        if ($mode === 'fixed' && $fixedPrice === null) {
            $_SESSION['error_message'] = specialPriceText('ราคาคงที่ไม่ถูกต้อง', 'The fixed price is invalid.');
            specialPriceRedirect($accountId, $accountQuery, 'price-editor');
        }

        $variantIds = [];
        foreach ($variantIdsRaw as $raw) {
            if (!is_scalar($raw)) continue;
            $id = (int) $raw;
            if ($id > 0) $variantIds[$id] = $id;
        }
        if (!$variantIds) {
            $_SESSION['error_message'] = specialPriceText('ไม่พบรูปแบบสินค้าที่เลือก', 'No valid product variants were selected.');
            specialPriceRedirect($accountId, $accountQuery, 'price-editor');
        }

        $variantStmt = $conn->prepare(specialPriceCostLookupSql($cgoReady));
        $upsert = $conn->prepare(
            "INSERT INTO reseller_variant_prices
                (reseller_id, variant_id, custom_price, status, below_cost_confirmed, confirmed_cost, confirmed_at)
             VALUES (?, ?, ?, 'active', ?, NULLIF(?, 0), IF(? = 1, NOW(), NULL))
             ON DUPLICATE KEY UPDATE
                confirmed_at = CASE
                    WHEN VALUES(below_cost_confirmed) = 0 THEN NULL
                    WHEN below_cost_confirmed = 1
                         AND confirmed_cost + 0.00001 >= VALUES(confirmed_cost)
                         AND ABS(custom_price - VALUES(custom_price)) < 0.00001
                    THEN confirmed_at
                    ELSE NOW()
                END,
                confirmed_cost = VALUES(confirmed_cost),
                below_cost_confirmed = VALUES(below_cost_confirmed),
                custom_price = VALUES(custom_price),
                status = 'active',
                updated_at = NOW()"
        );
        if (!$variantStmt || !$upsert) {
            $_SESSION['error_message'] = specialPriceText('ไม่สามารถเตรียมการปรับราคาแบบกลุ่มได้', 'Unable to prepare the bulk-price update.');
            specialPriceRedirect($accountId, $accountQuery, 'price-editor');
        }

        $role = (string) ($account['role'] ?? 'user');
        $confirmedBelowCost = specialPriceBelowCostConfirmed();
        $updated = 0;
        $belowCostSaved = 0;
        $conn->begin_transaction();
        try {
            foreach ($variantIds as $variantId) {
                $variantStmt->bind_param('i', $variantId);
                if (!$variantStmt->execute()) throw new RuntimeException('variant price lookup failed');
                $result = $variantStmt->get_result();
                $variant = $result ? $result->fetch_assoc() : null;
                if (!$variant) continue;

                $base = $role === 'reseller' ? (float) $variant['price_reseller'] : (float) $variant['price_user'];
                $customPrice = $mode === 'fixed'
                    ? (float) $fixedPrice
                    : round($base * (100 - $discount) / 100, 2);
                if (!is_finite($customPrice) || $customPrice <= 0 || $customPrice > 10000000) continue;

                $effectiveCost = round(max(0.0, (float) ($variant['effective_cost'] ?? 0)), 2);
                $belowCostFlag = 0;
                $confirmedCost = 0.0;
                if ($effectiveCost > 0 && $customPrice + 0.00001 < $effectiveCost) {
                    if (!$confirmedBelowCost) throw new DomainException('below_cost_confirmation_required');
                    $belowCostFlag = 1;
                    $confirmedCost = $effectiveCost;
                    $belowCostSaved++;
                }

                $upsert->bind_param('iididi', $accountId, $variantId, $customPrice, $belowCostFlag, $confirmedCost, $belowCostFlag);
                if (!$upsert->execute()) throw new RuntimeException('bulk price upsert failed');
                $updated++;
            }
            $conn->commit();
            $_SESSION['success_message'] = specialPriceText(
                "ปรับราคาพิเศษแบบกลุ่มแล้ว {$updated} รายการ" . ($belowCostSaved > 0 ? " (ยืนยันราคาต่ำกว่าต้นทุน {$belowCostSaved} รายการ)" : ''),
                "Updated {$updated} special price(s)." . ($belowCostSaved > 0 ? " {$belowCostSaved} below-cost price(s) were explicitly confirmed." : '')
            );
            logHistory((int) $_SESSION['user_id'], 'bulk_account_special_prices', 'Account ID ' . $accountId . ': updated ' . $updated . ', below_cost_confirmed=' . $belowCostSaved);
        } catch (Throwable $e) {
            try { $conn->rollback(); } catch (Throwable $ignored) {}
            error_log('Bulk special price update failed: ' . $e->getMessage());
            $_SESSION['error_message'] = $e instanceof DomainException && $e->getMessage() === 'below_cost_confirmation_required'
                ? specialPriceText('ราคาที่คำนวณบางรายการต่ำกว่าต้นทุน กรุณายืนยันคำเตือนก่อนนำไปใช้', 'Some calculated prices are below cost. Confirm the warning before applying them.')
                : specialPriceText('ปรับราคาแบบกลุ่มไม่สำเร็จ และไม่มีการเปลี่ยนแปลงข้อมูล', 'The bulk-price update failed. No changes were applied.');
        } finally {
            $variantStmt->close();
            $upsert->close();
        }
        specialPriceRedirect($accountId, $accountQuery, 'price-editor');
    }

    if ($action === 'toggle_price') {
        $variantId = isset($_POST['variant_id']) && is_scalar($_POST['variant_id']) ? (int) $_POST['variant_id'] : 0;
        if (!specialPriceValidAccount($account) || $variantId < 1) {
            $_SESSION['error_message'] = specialPriceText('คำขอเปิดหรือปิดราคาไม่ถูกต้อง', 'The price status request is invalid.');
            specialPriceRedirect($accountId, $accountQuery, 'price-editor');
        }
        $stmt = $conn->prepare(
            "UPDATE reseller_variant_prices
             SET status = CASE WHEN status = 'active' THEN 'inactive' ELSE 'active' END, updated_at = NOW()
             WHERE reseller_id = ? AND variant_id = ?"
        );
        if ($stmt) {
            $stmt->bind_param('ii', $accountId, $variantId);
            $ok = $stmt->execute() && $stmt->affected_rows === 1;
            $stmt->close();
        } else {
            $ok = false;
        }
        $_SESSION[$ok ? 'success_message' : 'error_message'] = $ok
            ? specialPriceText('เปลี่ยนสถานะราคาพิเศษแล้ว', 'The special-price status was changed.')
            : specialPriceText('ไม่พบราคาพิเศษที่ต้องการเปลี่ยนสถานะ', 'The requested special price was not found.');
        if ($ok) logHistory((int) $_SESSION['user_id'], 'toggle_account_special_price', 'Account ID ' . $accountId . ', variant ID ' . $variantId);
        specialPriceRedirect($accountId, $accountQuery, 'variant-' . $variantId);
    }

    if ($action === 'delete_price') {
        $variantId = isset($_POST['variant_id']) && is_scalar($_POST['variant_id']) ? (int) $_POST['variant_id'] : 0;
        if (!specialPriceValidAccount($account) || $variantId < 1) {
            $_SESSION['error_message'] = specialPriceText('คำขอลบราคาไม่ถูกต้อง', 'The delete request is invalid.');
            specialPriceRedirect($accountId, $accountQuery, 'price-editor');
        }
        $stmt = $conn->prepare('DELETE FROM reseller_variant_prices WHERE reseller_id = ? AND variant_id = ?');
        if ($stmt) {
            $stmt->bind_param('ii', $accountId, $variantId);
            $ok = $stmt->execute() && $stmt->affected_rows === 1;
            $stmt->close();
        } else {
            $ok = false;
        }
        $_SESSION[$ok ? 'success_message' : 'error_message'] = $ok
            ? specialPriceText('ลบราคาพิเศษรายการนี้แล้ว', 'The special price was removed.')
            : specialPriceText('ไม่พบราคาพิเศษที่ต้องการลบ', 'The requested special price was not found.');
        if ($ok) logHistory((int) $_SESSION['user_id'], 'delete_account_special_price', 'Account ID ' . $accountId . ', variant ID ' . $variantId);
        specialPriceRedirect($accountId, $accountQuery, 'price-editor');
    }

    if ($action === 'clear_all') {
        if (!specialPriceValidAccount($account)) {
            $_SESSION['error_message'] = specialPriceText('บัญชีที่เลือกไม่ถูกต้อง', 'The selected account is invalid.');
            specialPriceRedirect($accountId, $accountQuery);
        }
        $stmt = $conn->prepare('DELETE FROM reseller_variant_prices WHERE reseller_id = ?');
        if ($stmt) {
            $stmt->bind_param('i', $accountId);
            $ok = $stmt->execute();
            $removed = $ok ? max(0, (int) $stmt->affected_rows) : 0;
            $stmt->close();
        } else {
            $ok = false;
            $removed = 0;
        }
        $_SESSION[$ok ? 'success_message' : 'error_message'] = $ok
            ? specialPriceText("ล้างราคาพิเศษแล้ว {$removed} รายการ", "Cleared {$removed} special price(s).")
            : specialPriceText('ล้างราคาพิเศษไม่สำเร็จ', 'Special prices could not be cleared.');
        if ($ok) logHistory((int) $_SESSION['user_id'], 'clear_account_special_prices', 'Account ID ' . $accountId . ', removed ' . $removed);
        specialPriceRedirect($accountId, $accountQuery);
    }

    $_SESSION['error_message'] = specialPriceText('ไม่รู้จักคำสั่งที่ส่งมา', 'Unknown action.');
    specialPriceRedirect($accountId, $accountQuery);
}

$accountQuery = isset($_GET['account_q']) && is_scalar($_GET['account_q'])
    ? substr(trim((string) $_GET['account_q']), 0, 100)
    : '';
$accountRoleFilter = isset($_GET['account_role']) && is_scalar($_GET['account_role']) ? (string) $_GET['account_role'] : 'all';
$accountPriceState = isset($_GET['account_price_state']) && is_scalar($_GET['account_price_state']) ? (string) $_GET['account_price_state'] : 'all';
if (!in_array($accountRoleFilter, ['all', 'user', 'reseller'], true)) $accountRoleFilter = 'all';
if (!in_array($accountPriceState, ['all', 'configured', 'unconfigured'], true)) $accountPriceState = 'all';

$selectedAccountRaw = $_GET['account_id'] ?? $_GET['reseller_id'] ?? 0;
$selectedAccountId = is_scalar($selectedAccountRaw) ? max(0, (int) $selectedAccountRaw) : 0;
$selectedAccount = $selectedAccountId > 0 ? getUserById($selectedAccountId) : null;
if ($selectedAccountId > 0 && !specialPriceValidAccount($selectedAccount)) {
    $selectedAccountId = 0;
    $selectedAccount = null;
    if ($error === '') $error = specialPriceText('บัญชีที่เลือกต้องเป็นผู้ใช้งานหรือตัวแทน', 'The selected account must be a user or reseller.');
}

$accountWhere = ["u.role IN ('user', 'reseller')"];
if ($accountRoleFilter !== 'all') {
    $accountWhere[] = "u.role = '" . $conn->real_escape_string($accountRoleFilter) . "'";
}
if ($accountPriceState === 'configured') {
    $accountWhere[] = 'COALESCE(sp.price_count, 0) > 0';
} elseif ($accountPriceState === 'unconfigured') {
    $accountWhere[] = 'COALESCE(sp.price_count, 0) = 0';
}

$accountBaseSql = "SELECT u.id, u.username, u.email, u.role, u.balance, u.status,
                          COALESCE(sp.price_count, 0) AS price_count,
                          COALESCE(sp.active_price_count, 0) AS active_price_count,
                          sp.last_price_update
                   FROM users u
                   LEFT JOIN (
                       SELECT reseller_id, COUNT(*) AS price_count,
                              SUM(status = 'active') AS active_price_count,
                              MAX(updated_at) AS last_price_update
                       FROM reseller_variant_prices
                       GROUP BY reseller_id
                   ) sp ON sp.reseller_id = u.id
                   WHERE " . implode(' AND ', $accountWhere);

$accountResults = [];
if ($accountQuery !== '') {
    $accountSql = $accountBaseSql . " AND (u.username LIKE ? OR u.email LIKE ? OR CAST(u.id AS CHAR) = ?)
                                  ORDER BY CASE WHEN u.username = ? THEN 0 ELSE 1 END,
                                           COALESCE(sp.last_price_update, '1970-01-01') DESC,
                                           u.username ASC LIMIT 80";
    $stmt = $conn->prepare($accountSql);
    if ($stmt) {
        $like = '%' . $accountQuery . '%';
        $stmt->bind_param('ssss', $like, $like, $accountQuery, $accountQuery);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            $accountResults = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        }
        $stmt->close();
    }
} elseif ($selectedAccountId === 0) {
    $result = $conn->query($accountBaseSql . " ORDER BY
        CASE u.role WHEN 'user' THEN 0 ELSE 1 END,
        CASE WHEN COALESCE(sp.price_count, 0) > 0 THEN 0 ELSE 1 END,
        COALESCE(sp.last_price_update, '1970-01-01') DESC,
        u.id DESC LIMIT 60");
    $accountResults = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

$accountGroups = ['user' => [], 'reseller' => []];
foreach ($accountResults as $accountRow) {
    $role = (string) ($accountRow['role'] ?? 'user');
    if (isset($accountGroups[$role])) $accountGroups[$role][] = $accountRow;
}

$stats = ['user_accounts' => 0, 'reseller_accounts' => 0, 'active_prices' => 0, 'inactive_prices' => 0];
$statsResult = $conn->query(
    "SELECT
        COUNT(DISTINCT CASE WHEN u.role = 'user' THEN rvp.reseller_id END) AS user_accounts,
        COUNT(DISTINCT CASE WHEN u.role = 'reseller' THEN rvp.reseller_id END) AS reseller_accounts,
        SUM(rvp.status = 'active') AS active_prices,
        SUM(rvp.status = 'inactive') AS inactive_prices
     FROM reseller_variant_prices rvp
     JOIN users u ON u.id = rvp.reseller_id AND u.role IN ('user', 'reseller')"
);
if ($statsResult && ($row = $statsResult->fetch_assoc())) {
    $stats = [
        'user_accounts' => (int) ($row['user_accounts'] ?? 0),
        'reseller_accounts' => (int) ($row['reseller_accounts'] ?? 0),
        'active_prices' => (int) ($row['active_prices'] ?? 0),
        'inactive_prices' => (int) ($row['inactive_prices'] ?? 0),
    ];
}

$apiLastSyncAt = null;
if ($cgoReady) {
    $syncResult = $conn->query('SELECT MAX(last_synced_at) AS last_sync FROM cgo_products WHERE supplier_removed_at IS NULL');
    if ($syncResult && ($syncRow = $syncResult->fetch_assoc())) {
        $apiLastSyncAt = $syncRow['last_sync'] ?? null;
    }
}

$priceRows = [];
$selectedPriceCount = 0;
$selectedActivePriceCount = 0;
if ($selectedAccount) {
    if ($cgoReady) {
        $costFields = ", COALESCE(pv.cost_price, 0) AS local_cost,
                         COALESCE(api.api_cost, 0) AS api_cost,
                         GREATEST(COALESCE(pv.cost_price, 0), COALESCE(api.api_cost, 0)) AS effective_cost,
                         api.api_last_synced_at";
        $costJoin = "LEFT JOIN (
                SELECT l.local_variant_id, MAX(cp.cost_base) AS api_cost, MAX(cp.last_synced_at) AS api_last_synced_at
                FROM cgo_catalog_links l
                JOIN cgo_products cp ON cp.id = l.cgo_product_id
                WHERE cp.supplier_removed_at IS NULL
                GROUP BY l.local_variant_id
            ) api ON api.local_variant_id = pv.id";
    } else {
        $costFields = ", COALESCE(pv.cost_price, 0) AS local_cost, 0 AS api_cost,
                         COALESCE(pv.cost_price, 0) AS effective_cost, NULL AS api_last_synced_at";
        $costJoin = '';
    }

    $stmt = $conn->prepare(
        "SELECT
            pv.id AS variant_id,
            pv.product_id,
            p.name AS product_name,
            pv.duration,
            pv.price_user,
            pv.price_reseller,
            rvp.custom_price,
            rvp.status AS custom_status,
            rvp.below_cost_confirmed,
            rvp.confirmed_cost,
            rvp.confirmed_at,
            rvp.updated_at AS custom_updated_at
            {$costFields}
         FROM product_variants pv
         JOIN products p ON p.id = pv.product_id
         {$costJoin}
         LEFT JOIN reseller_variant_prices rvp
            ON rvp.variant_id = pv.id AND rvp.reseller_id = ?
         WHERE pv.status = 'active' AND p.status = 'active'
         ORDER BY p.name ASC, pv.duration ASC, pv.id ASC"
    );
    if ($stmt) {
        $stmt->bind_param('i', $selectedAccountId);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            $priceRows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        }
        $stmt->close();
    }
    foreach ($priceRows as $row) {
        if ($row['custom_price'] !== null) {
            $selectedPriceCount++;
            if ((string) ($row['custom_status'] ?? '') === 'active') $selectedActivePriceCount++;
        }
    }
}

$selectedRole = (string) ($selectedAccount['role'] ?? 'user');
$roleLabel = $selectedRole === 'reseller'
    ? specialPriceText('ตัวแทน', 'Reseller')
    : specialPriceText('ผู้ใช้งาน', 'User');
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($pageLang, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(specialPriceText('ราคาพิเศษรายบัญชี', 'Account Special Prices'), ENT_QUOTES, 'UTF-8'); ?> - Admin Panel</title>
    <link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3">
    <script src="https://unpkg.com/lucide@latest"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>:root{--sakazuki-accent:#6366f1;--sakazuki-accent-rgb:99 102 241;--sakazuki-accent2:#8b5cf6;--sakazuki-accent2-rgb:139 92 246;--sakazuki-glow:0 0 25px rgba(99,102,241,0.25)}</style>
    <style>
        .glass { background: rgba(255,255,255,.05); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); border: 1px solid rgba(255,255,255,.08); }
        .soft-scrollbar { scrollbar-width: thin; scrollbar-color: rgba(129,140,248,.7) transparent; }
        .soft-scrollbar::-webkit-scrollbar { height: 5px; width: 5px; }
        .soft-scrollbar::-webkit-scrollbar-thumb { background: rgba(129,140,248,.7); border-radius: 999px; }
        .price-row[hidden] { display: none !important; }
        @keyframes fadeInUp { from { opacity:0; transform:translateY(14px); } to { opacity:1; transform:translateY(0); } }
        .animate-fade-in { animation: fadeInUp .15s ease-out both; }
    </style>
</head>
<body class="bg-darkbg text-gray-100 min-h-screen">
<?php include 'nav.php'; ?>

<main class="flex-1 overflow-y-auto p-4 md:p-6 space-y-5">
    <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
        <div>
            <h1 class="text-xl md:text-2xl font-bold text-white flex items-center gap-2">
                <i class="bi bi-person-gear text-indigo-400"></i>
                <?php echo htmlspecialchars(specialPriceText('ราคาพิเศษรายบัญชี', 'Account Special Prices'), ENT_QUOTES, 'UTF-8'); ?>
            </h1>
            <p class="text-xs md:text-sm text-gray-400 mt-1">
                <?php echo htmlspecialchars(specialPriceText('ลดเฉพาะสินค้าที่เลือกให้ผู้ใช้หรือตัวแทน โดยไม่ต้องเปลี่ยนประเภทบัญชี', 'Discount selected products for a user or reseller without changing the account role.'), ENT_QUOTES, 'UTF-8'); ?>
            </p>
        </div>
        <?php if ($selectedAccount): ?>
            <?php
            $chooseAccountParams = [];
            if ($accountRoleFilter !== 'all') $chooseAccountParams['account_role'] = $accountRoleFilter;
            if ($accountPriceState !== 'all') $chooseAccountParams['account_price_state'] = $accountPriceState;
            if ($accountQuery !== '') $chooseAccountParams['account_q'] = $accountQuery;
            $chooseAccountUrl = 'reseller-prices.php' . ($chooseAccountParams ? '?' . http_build_query($chooseAccountParams, '', '&', PHP_QUERY_RFC3986) : '');
            ?>
            <a href="<?php echo htmlspecialchars($chooseAccountUrl, ENT_QUOTES, 'UTF-8'); ?>" class="glass hover:bg-white/10 rounded-lg px-3 py-2 text-sm text-gray-300 transition">
                <i class="bi bi-arrow-left mr-1"></i><?php echo htmlspecialchars(specialPriceText('เลือกบัญชีอื่น', 'Choose another account'), ENT_QUOTES, 'UTF-8'); ?>
            </a>
        <?php endif; ?>
    </div>

    <?php if ($error !== ''): ?>
        <div class="glass border-red-500/40 bg-red-950/30 rounded-xl p-4 text-red-300 text-sm">
            <i class="bi bi-exclamation-triangle mr-2"></i><?php echo htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
        </div>
    <?php endif; ?>
    <?php if ($success !== ''): ?>
        <div class="glass border-green-500/40 bg-green-950/30 rounded-xl p-4 text-green-300 text-sm">
            <i class="bi bi-check-circle mr-2"></i><?php echo htmlspecialchars($success, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <section class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <div class="glass rounded-xl p-4">
            <div class="text-xs text-gray-400"><?php echo htmlspecialchars(specialPriceText('ผู้ใช้ที่ตั้งราคาแล้ว', 'Configured users'), ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="text-2xl font-bold mt-1 text-blue-300"><?php echo number_format($stats['user_accounts']); ?></div>
        </div>
        <div class="glass rounded-xl p-4">
            <div class="text-xs text-gray-400"><?php echo htmlspecialchars(specialPriceText('ตัวแทนที่ตั้งราคาแล้ว', 'Configured resellers'), ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="text-2xl font-bold mt-1 text-purple-300"><?php echo number_format($stats['reseller_accounts']); ?></div>
        </div>
        <div class="glass rounded-xl p-4">
            <div class="text-xs text-gray-400"><?php echo htmlspecialchars(specialPriceText('ราคาที่เปิดใช้งาน', 'Active special prices'), ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="text-2xl font-bold mt-1 text-green-400"><?php echo number_format($stats['active_prices']); ?></div>
        </div>
        <div class="glass rounded-xl p-4">
            <div class="text-xs text-gray-400"><?php echo htmlspecialchars(specialPriceText('ราคาที่พักไว้', 'Paused special prices'), ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="text-2xl font-bold mt-1 text-yellow-400"><?php echo number_format($stats['inactive_prices']); ?></div>
        </div>
    </section>

    <section class="glass rounded-xl p-4 md:p-6 animate-fade-in">
        <div class="flex items-center gap-2 mb-4">
            <div class="w-9 h-9 rounded-lg bg-indigo-500/20 text-indigo-300 grid place-items-center"><i class="bi bi-search"></i></div>
            <div>
                <h2 class="font-semibold text-white"><?php echo htmlspecialchars(specialPriceText('เลือกบัญชีและแยกประเภท', 'Choose and separate accounts'), ENT_QUOTES, 'UTF-8'); ?></h2>
                <p class="text-xs text-gray-500"><?php echo htmlspecialchars(specialPriceText('กรองผู้ใช้ ตัวแทน และบัญชีที่เคยปรับราคาได้โดยตรง', 'Filter users, resellers, and accounts with existing price overrides.'), ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
        </div>
        <form method="GET" class="grid grid-cols-1 md:grid-cols-[1fr_180px_200px_auto] gap-2">
            <div class="relative">
                <i class="bi bi-person absolute left-3 top-1/2 -translate-y-1/2 text-gray-500"></i>
                <input type="search" name="account_q" value="<?php echo htmlspecialchars($accountQuery, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                       class="glass w-full rounded-lg pl-10 pr-3 py-2.5 bg-transparent text-white outline-none focus:border-indigo-400"
                       placeholder="<?php echo htmlspecialchars(specialPriceText('ชื่อผู้ใช้ อีเมล หรือ ID', 'Username, email, or ID'), ENT_QUOTES, 'UTF-8'); ?>" maxlength="100" autocomplete="off">
            </div>
            <select name="account_role" class="glass rounded-lg px-3 py-2.5 bg-panel text-sm outline-none">
                <option value="all" <?php echo $accountRoleFilter === 'all' ? 'selected' : ''; ?>><?php echo htmlspecialchars(specialPriceText('ผู้ใช้และตัวแทน', 'Users and resellers'), ENT_QUOTES, 'UTF-8'); ?></option>
                <option value="user" <?php echo $accountRoleFilter === 'user' ? 'selected' : ''; ?>><?php echo htmlspecialchars(specialPriceText('เฉพาะผู้ใช้', 'Users only'), ENT_QUOTES, 'UTF-8'); ?></option>
                <option value="reseller" <?php echo $accountRoleFilter === 'reseller' ? 'selected' : ''; ?>><?php echo htmlspecialchars(specialPriceText('เฉพาะตัวแทน', 'Resellers only'), ENT_QUOTES, 'UTF-8'); ?></option>
            </select>
            <select name="account_price_state" class="glass rounded-lg px-3 py-2.5 bg-panel text-sm outline-none">
                <option value="all" <?php echo $accountPriceState === 'all' ? 'selected' : ''; ?>><?php echo htmlspecialchars(specialPriceText('ทุกสถานะราคา', 'All price states'), ENT_QUOTES, 'UTF-8'); ?></option>
                <option value="configured" <?php echo $accountPriceState === 'configured' ? 'selected' : ''; ?>><?php echo htmlspecialchars(specialPriceText('ปรับราคาแล้ว', 'Configured'), ENT_QUOTES, 'UTF-8'); ?></option>
                <option value="unconfigured" <?php echo $accountPriceState === 'unconfigured' ? 'selected' : ''; ?>><?php echo htmlspecialchars(specialPriceText('ยังไม่ปรับราคา', 'Not configured'), ENT_QUOTES, 'UTF-8'); ?></option>
            </select>
            <button type="submit" class="bg-indigo-500 hover:bg-indigo-400 rounded-lg px-5 py-2.5 font-medium text-sm transition"><i class="bi bi-funnel mr-1"></i><?php echo htmlspecialchars(specialPriceText('กรอง', 'Filter'), ENT_QUOTES, 'UTF-8'); ?></button>
        </form>

        <?php if (!$selectedAccount && $accountResults): ?>
            <div class="mt-5 grid grid-cols-1 xl:grid-cols-2 gap-5">
                <?php foreach (['user' => specialPriceText('ผู้ใช้งาน', 'Users'), 'reseller' => specialPriceText('ตัวแทน', 'Resellers')] as $groupRole => $groupLabel): ?>
                    <?php if ($accountGroups[$groupRole]): ?>
                        <div>
                            <div class="flex items-center justify-between mb-2">
                                <h3 class="font-semibold <?php echo $groupRole === 'reseller' ? 'text-purple-300' : 'text-blue-300'; ?>"><i class="bi <?php echo $groupRole === 'reseller' ? 'bi-shop' : 'bi-people'; ?> mr-1"></i><?php echo htmlspecialchars($groupLabel, ENT_QUOTES, 'UTF-8'); ?></h3>
                                <span class="text-xs text-gray-500"><?php echo number_format(count($accountGroups[$groupRole])); ?></span>
                            </div>
                            <div class="space-y-2">
                                <?php foreach ($accountGroups[$groupRole] as $accountRow): ?>
                                    <?php
                                    $isActive = (string) ($accountRow['status'] ?? '') === 'active';
                                    $priceCount = (int) ($accountRow['price_count'] ?? 0);
                                    $activePriceCount = (int) ($accountRow['active_price_count'] ?? 0);
                                    $accountUrl = '?' . http_build_query([
                                        'account_id' => (int) $accountRow['id'],
                                        'account_q' => $accountQuery,
                                        'account_role' => $accountRoleFilter,
                                        'account_price_state' => $accountPriceState,
                                    ], '', '&', PHP_QUERY_RFC3986);
                                    ?>
                                    <a href="<?php echo htmlspecialchars($accountUrl, ENT_QUOTES, 'UTF-8'); ?>" class="group glass rounded-xl p-4 hover:border-indigo-400/70 hover:bg-indigo-500/10 transition block">
                                        <div class="flex items-start justify-between gap-3">
                                            <div class="min-w-0"><div class="font-semibold text-white truncate"><?php echo htmlspecialchars((string) $accountRow['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div><div class="text-xs text-gray-500 truncate mt-0.5"><?php echo htmlspecialchars((string) ($accountRow['email'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div></div>
                                            <?php if ($priceCount > 0): ?>
                                                <span class="px-2 py-1 rounded-full text-[10px] font-bold bg-green-500/15 text-green-300 whitespace-nowrap"><i class="bi bi-tags mr-1"></i><?php echo $priceCount; ?> <?php echo htmlspecialchars(specialPriceText('ราคา', 'prices'), ENT_QUOTES, 'UTF-8'); ?></span>
                                            <?php else: ?>
                                                <span class="px-2 py-1 rounded-full text-[10px] bg-gray-500/10 text-gray-500 whitespace-nowrap"><?php echo htmlspecialchars(specialPriceText('ยังไม่ปรับราคา', 'Not configured'), ENT_QUOTES, 'UTF-8'); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="mt-3 flex items-center justify-between text-xs"><span class="text-gray-400">ID #<?php echo (int) $accountRow['id']; ?><?php if ($priceCount > 0): ?> · <?php echo htmlspecialchars(specialPriceText('เปิดใช้', 'active'), ENT_QUOTES, 'UTF-8'); ?> <?php echo $activePriceCount; ?><?php endif; ?></span><span class="<?php echo $isActive ? 'text-green-400' : 'text-red-400'; ?>"><i class="bi bi-circle-fill text-[6px] mr-1"></i><?php echo htmlspecialchars($isActive ? specialPriceText('ใช้งาน', 'Active') : specialPriceText('ปิดใช้งาน', 'Inactive'), ENT_QUOTES, 'UTF-8'); ?></span></div>
                                        <?php if (!empty($accountRow['last_price_update'])): ?><div class="text-[10px] text-gray-600 mt-1"><i class="bi bi-clock-history mr-1"></i><?php echo htmlspecialchars(specialPriceText('แก้ราคาล่าสุด ', 'Last price edit ') . date('Y-m-d H:i', strtotime((string) $accountRow['last_price_update'])), ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php elseif (!$selectedAccount): ?>
            <div class="mt-4 rounded-lg border border-dashed border-white/10 p-6 text-center text-sm text-gray-500"><i class="bi bi-person-x text-2xl block mb-2"></i><?php echo htmlspecialchars(specialPriceText('ไม่พบบัญชีตามตัวกรอง', 'No account matched the filters.'), ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
    </section>

    <?php if ($selectedAccount): ?>
        <section class="glass rounded-xl p-4 md:p-6 animate-fade-in">
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                <div class="flex items-start gap-3 min-w-0">
                    <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-indigo-500/30 to-purple-500/30 text-indigo-200 grid place-items-center text-xl shrink-0"><i class="bi bi-person-check"></i></div>
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-lg font-bold text-white truncate"><?php echo htmlspecialchars((string) $selectedAccount['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></h2>
                            <span class="px-2 py-1 rounded-full text-[10px] font-bold <?php echo $selectedRole === 'reseller' ? 'bg-purple-500/20 text-purple-300' : 'bg-blue-500/20 text-blue-300'; ?>"><?php echo htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="px-2 py-1 rounded-full text-[10px] <?php echo (string) ($selectedAccount['status'] ?? '') === 'active' ? 'bg-green-500/20 text-green-300' : 'bg-red-500/20 text-red-300'; ?>">
                                <?php echo htmlspecialchars((string) ($selectedAccount['status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </div>
                        <div class="text-xs text-gray-500 mt-1 truncate">
                            ID #<?php echo $selectedAccountId; ?> · <?php echo htmlspecialchars((string) ($selectedAccount['email'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                        </div>
                    </div>
                </div>
                <div class="grid grid-cols-3 gap-2 text-center min-w-full lg:min-w-[360px]">
                    <div class="rounded-lg bg-white/5 p-3">
                        <div class="text-[10px] text-gray-500"><?php echo htmlspecialchars(specialPriceText('ยอดเงิน', 'Balance'), ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="font-bold text-sm mt-1"><?php echo formatCurrency((float) ($selectedAccount['balance'] ?? 0)); ?></div>
                    </div>
                    <div class="rounded-lg bg-white/5 p-3">
                        <div class="text-[10px] text-gray-500"><?php echo htmlspecialchars(specialPriceText('ตั้งราคาแล้ว', 'Configured'), ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="font-bold text-sm mt-1"><?php echo number_format($selectedPriceCount); ?></div>
                    </div>
                    <div class="rounded-lg bg-white/5 p-3">
                        <div class="text-[10px] text-gray-500"><?php echo htmlspecialchars(specialPriceText('กำลังใช้', 'Active'), ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="font-bold text-sm mt-1 text-green-400"><?php echo number_format($selectedActivePriceCount); ?></div>
                    </div>
                </div>
            </div>
            <div class="mt-4 rounded-lg bg-indigo-500/10 border border-indigo-400/20 p-3 text-xs text-indigo-200">
                <i class="bi bi-info-circle mr-1"></i>
                <?php echo htmlspecialchars(
                    $selectedRole === 'reseller'
                        ? specialPriceText('ราคาพิเศษจะทับราคาตัวแทนเฉพาะสินค้าที่กำหนด ส่วนสินค้าอื่นยังใช้ราคาตัวแทนปกติ', 'Special prices override the reseller price only for selected products. Other products keep the normal reseller price.')
                        : specialPriceText('ราคาพิเศษจะทับราคาผู้ใช้เฉพาะสินค้าที่กำหนด บัญชียังคงเป็นผู้ใช้ธรรมดา ไม่ได้รับราคาตัวแทนทั้งร้าน', 'Special prices override the user price only for selected products. The account remains a normal user and does not receive reseller pricing storewide.'),
                    ENT_QUOTES | ENT_SUBSTITUTE,
                    'UTF-8'
                ); ?>
            </div>
        </section>

        <section id="price-editor" class="glass rounded-xl overflow-hidden animate-fade-in">
            <div class="p-4 md:p-6 border-b border-white/10 space-y-4">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                    <div>
                        <h2 class="font-bold text-white flex items-center gap-2"><i class="bi bi-tags text-indigo-400"></i><?php echo htmlspecialchars(specialPriceText('เลือกสินค้าและกำหนดราคา', 'Select products and set prices'), ENT_QUOTES, 'UTF-8'); ?></h2>
                        <p class="text-xs text-gray-500 mt-1"><?php echo htmlspecialchars(specialPriceText('เว้นราคาว่างแล้วบันทึก เพื่อล้างราคาพิเศษของรายการนั้น', 'Leave a price blank and save to remove that override.'), ENT_QUOTES, 'UTF-8'); ?></p>
                        <p class="text-[11px] text-orange-300 mt-1"><i class="bi bi-shield-exclamation mr-1"></i><?php echo htmlspecialchars(specialPriceText('ราคาต่ำกว่าต้นทุนจะต้องยืนยันก่อนบันทึก ระบบไม่ปรับราคาให้เอง', 'Below-cost prices require explicit confirmation and are never applied silently.'), ENT_QUOTES, 'UTF-8'); ?></p>
                    </div>
                    <div class="flex flex-col sm:flex-row gap-2 shrink-0">
                        <form method="POST">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="sync_api_costs">
                            <input type="hidden" name="account_id" value="<?php echo $selectedAccountId; ?>">
                            <input type="hidden" name="account_q" value="<?php echo htmlspecialchars($accountQuery, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                            <input type="hidden" name="account_role" value="<?php echo htmlspecialchars($accountRoleFilter, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="account_price_state" value="<?php echo htmlspecialchars($accountPriceState, ENT_QUOTES, 'UTF-8'); ?>">
                            <button type="submit" class="w-full rounded-lg border border-cyan-500/30 text-cyan-300 hover:bg-cyan-500/10 px-3 py-2 text-xs transition disabled:opacity-40" <?php echo !$cgoReady ? 'disabled' : ''; ?>>
                                <i class="bi bi-arrow-repeat mr-1"></i><?php echo htmlspecialchars(specialPriceText('ซิงค์ต้นทุน API', 'Sync API costs'), ENT_QUOTES, 'UTF-8'); ?>
                            </button>
                            <?php if ($apiLastSyncAt): ?><div class="text-[9px] text-gray-600 mt-1 text-center"><?php echo htmlspecialchars(specialPriceText('ล่าสุด ', 'Last ') . date('Y-m-d H:i', strtotime((string) $apiLastSyncAt)), ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
                        </form>
                        <form method="POST" onsubmit="return confirmClearAll(event)">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="clear_all">
                            <input type="hidden" name="account_id" value="<?php echo $selectedAccountId; ?>">
                            <input type="hidden" name="account_q" value="<?php echo htmlspecialchars($accountQuery, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                            <input type="hidden" name="account_role" value="<?php echo htmlspecialchars($accountRoleFilter, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="account_price_state" value="<?php echo htmlspecialchars($accountPriceState, ENT_QUOTES, 'UTF-8'); ?>">
                            <button type="submit" class="w-full rounded-lg border border-red-500/30 text-red-300 hover:bg-red-500/10 px-3 py-2 text-xs transition disabled:opacity-40" <?php echo $selectedPriceCount < 1 ? 'disabled' : ''; ?>>
                                <i class="bi bi-trash3 mr-1"></i><?php echo htmlspecialchars(specialPriceText('ล้างราคาพิเศษทั้งหมด', 'Clear all special prices'), ENT_QUOTES, 'UTF-8'); ?>
                            </button>
                        </form>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-[1fr_auto_auto] gap-2">
                    <div class="relative">
                        <i class="bi bi-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-500"></i>
                        <input id="productSearch" type="search" class="glass w-full rounded-lg pl-10 pr-3 py-2 bg-transparent text-sm outline-none focus:border-indigo-400"
                               placeholder="<?php echo htmlspecialchars(specialPriceText('ค้นหาชื่อสินค้า หรือระยะเวลา...', 'Search product name or duration...'), ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <select id="priceFilter" class="glass rounded-lg px-3 py-2 bg-panel text-sm outline-none">
                        <option value="all"><?php echo htmlspecialchars(specialPriceText('แสดงทั้งหมด', 'Show all'), ENT_QUOTES, 'UTF-8'); ?></option>
                        <option value="configured"><?php echo htmlspecialchars(specialPriceText('ตั้งราคาแล้ว', 'Configured only'), ENT_QUOTES, 'UTF-8'); ?></option>
                        <option value="unset"><?php echo htmlspecialchars(specialPriceText('ยังไม่ตั้งราคา', 'Not configured'), ENT_QUOTES, 'UTF-8'); ?></option>
                        <option value="inactive"><?php echo htmlspecialchars(specialPriceText('พักการใช้งาน', 'Paused only'), ENT_QUOTES, 'UTF-8'); ?></option>
                    </select>
                    <button type="button" id="selectVisibleBtn" class="glass hover:bg-white/10 rounded-lg px-3 py-2 text-sm transition whitespace-nowrap">
                        <i class="bi bi-check2-square mr-1"></i><?php echo htmlspecialchars(specialPriceText('เลือกที่มองเห็น', 'Select visible'), ENT_QUOTES, 'UTF-8'); ?>
                    </button>
                </div>

                <form method="POST" id="bulkForm" class="rounded-xl bg-white/[.03] border border-white/10 p-3">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="apply_bulk">
                    <input type="hidden" name="account_id" value="<?php echo $selectedAccountId; ?>">
                    <input type="hidden" name="account_q" value="<?php echo htmlspecialchars($accountQuery, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                    <input type="hidden" name="account_role" value="<?php echo htmlspecialchars($accountRoleFilter, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="account_price_state" value="<?php echo htmlspecialchars($accountPriceState, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="confirm_below_cost" id="bulkConfirmBelowCost" value="0">
                    <div id="bulkVariantContainer"></div>
                    <div class="flex flex-col xl:flex-row xl:items-end gap-3">
                        <div class="flex-1">
                            <div class="text-xs font-medium text-gray-300 mb-1"><?php echo htmlspecialchars(specialPriceText('ปรับรายการที่เลือกแบบกลุ่ม', 'Bulk update selected items'), ENT_QUOTES, 'UTF-8'); ?> <span id="selectedCount" class="text-indigo-300">0</span></div>
                            <div class="flex flex-wrap gap-1.5">
                                <?php foreach ([5, 10, 15, 20, 25, 30] as $quickDiscount): ?>
                                    <button type="button" class="quick-discount rounded-md bg-indigo-500/10 hover:bg-indigo-500/20 text-indigo-300 px-2.5 py-1.5 text-xs transition" data-discount="<?php echo $quickDiscount; ?>">-<?php echo $quickDiscount; ?>%</button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-[140px_150px_auto] gap-2 w-full xl:w-auto">
                            <select name="bulk_mode" id="bulkMode" class="glass rounded-lg px-3 py-2 bg-panel text-sm outline-none">
                                <option value="discount"><?php echo htmlspecialchars(specialPriceText('ลดเป็นเปอร์เซ็นต์', 'Percentage discount'), ENT_QUOTES, 'UTF-8'); ?></option>
                                <option value="fixed"><?php echo htmlspecialchars(specialPriceText('กำหนดราคาเดียว', 'Set one fixed price'), ENT_QUOTES, 'UTF-8'); ?></option>
                            </select>
                            <div>
                                <input name="discount_percent" id="discountPercent" type="number" min="0.01" max="99.99" step="0.01" inputmode="decimal" class="glass rounded-lg px-3 py-2 bg-transparent text-sm w-full" placeholder="10%">
                                <input name="fixed_price" id="fixedPrice" type="number" min="0.01" max="10000000" step="0.01" inputmode="decimal" class="glass rounded-lg px-3 py-2 bg-transparent text-sm w-full hidden" placeholder="<?php echo htmlspecialchars(specialPriceText('ราคาใหม่', 'New price'), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <button type="submit" class="bg-purple-500 hover:bg-purple-400 rounded-lg px-4 py-2 text-sm font-medium transition whitespace-nowrap">
                                <i class="bi bi-lightning-charge mr-1"></i><?php echo htmlspecialchars(specialPriceText('นำไปใช้', 'Apply'), ENT_QUOTES, 'UTF-8'); ?>
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <form method="POST" id="savePricesForm">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="save_prices">
                <input type="hidden" name="account_id" value="<?php echo $selectedAccountId; ?>">
                <input type="hidden" name="account_q" value="<?php echo htmlspecialchars($accountQuery, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                <input type="hidden" name="account_role" value="<?php echo htmlspecialchars($accountRoleFilter, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="account_price_state" value="<?php echo htmlspecialchars($accountPriceState, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="confirm_below_cost" id="saveConfirmBelowCost" value="0">

                <div class="overflow-x-auto soft-scrollbar">
                    <table class="w-full min-w-[1080px]">
                        <thead class="bg-white/[.03] sticky top-0 z-10">
                        <tr class="text-xs text-gray-400 border-b border-white/10">
                            <th class="px-4 py-3 text-left w-10"><input id="selectAll" type="checkbox" class="accent-indigo-500"></th>
                            <th class="px-4 py-3 text-left"><?php echo htmlspecialchars(specialPriceText('สินค้า', 'Product'), ENT_QUOTES, 'UTF-8'); ?></th>
                            <th class="px-4 py-3 text-left"><?php echo htmlspecialchars(specialPriceText('ระยะเวลา', 'Duration'), ENT_QUOTES, 'UTF-8'); ?></th>
                            <th class="px-4 py-3 text-right"><?php echo htmlspecialchars(specialPriceText('ราคาผู้ใช้', 'User price'), ENT_QUOTES, 'UTF-8'); ?></th>
                            <th class="px-4 py-3 text-right"><?php echo htmlspecialchars(specialPriceText('ราคาตัวแทน', 'Reseller price'), ENT_QUOTES, 'UTF-8'); ?></th>
                            <th class="px-4 py-3 text-right"><?php echo htmlspecialchars(specialPriceText('ต้นทุนตรวจสอบ', 'Verified cost'), ENT_QUOTES, 'UTF-8'); ?></th>
                            <th class="px-4 py-3 text-center"><?php echo htmlspecialchars(specialPriceText('ราคาพิเศษ', 'Special price'), ENT_QUOTES, 'UTF-8'); ?></th>
                            <th class="px-4 py-3 text-center"><?php echo htmlspecialchars(specialPriceText('สถานะ', 'Status'), ENT_QUOTES, 'UTF-8'); ?></th>
                            <th class="px-4 py-3 text-center"><?php echo htmlspecialchars(specialPriceText('จัดการ', 'Actions'), ENT_QUOTES, 'UTF-8'); ?></th>
                        </tr>
                        </thead>
                        <tbody id="priceRows">
                        <?php foreach ($priceRows as $index => $row): ?>
                            <?php
                            $variantId = (int) $row['variant_id'];
                            $priceUser = (float) $row['price_user'];
                            $priceReseller = (float) $row['price_reseller'];
                            $basePrice = $selectedRole === 'reseller' ? $priceReseller : $priceUser;
                            $localCost = round(max(0.0, (float) ($row['local_cost'] ?? 0)), 2);
                            $apiCost = round(max(0.0, (float) ($row['api_cost'] ?? 0)), 2);
                            $effectiveCost = round(max($localCost, $apiCost), 2);
                            $hasCustom = $row['custom_price'] !== null;
                            $customPrice = $hasCustom ? (float) $row['custom_price'] : 0.0;
                            $customStatus = $hasCustom ? (string) ($row['custom_status'] ?? 'active') : 'unset';
                            $confirmedCost = $hasCustom && is_numeric($row['confirmed_cost'] ?? null)
                                ? round(max(0.0, (float) $row['confirmed_cost']), 2)
                                : 0.0;
                            $belowCostConfirmed = $hasCustom && (int) ($row['below_cost_confirmed'] ?? 0) === 1;
                            $saving = $hasCustom && $basePrice > 0 ? round((1 - ($customPrice / $basePrice)) * 100, 1) : 0;
                            $isBelowCost = $hasCustom && $effectiveCost > 0 && $customPrice + 0.00001 < $effectiveCost;
                            $belowCostAuthorizationValid = $isBelowCost
                                && $belowCostConfirmed
                                && $confirmedCost + 0.00001 >= $effectiveCost;
                            $searchText = strtolower((string) $row['product_name'] . ' ' . (string) $row['duration']);
                            ?>
                            <tr id="variant-<?php echo $variantId; ?>" class="price-row border-b border-white/5 hover:bg-white/[.025] transition" data-search="<?php echo htmlspecialchars($searchText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" data-state="<?php echo htmlspecialchars($customStatus, ENT_QUOTES, 'UTF-8'); ?>">
                                <td class="px-4 py-3 align-middle"><input type="checkbox" class="variant-checkbox accent-indigo-500" value="<?php echo $variantId; ?>" data-base="<?php echo number_format($basePrice, 2, '.', ''); ?>" data-cost="<?php echo number_format($effectiveCost, 2, '.', ''); ?>"></td>
                                <td class="px-4 py-3">
                                    <div class="font-medium text-sm text-white max-w-[260px] truncate" title="<?php echo htmlspecialchars((string) $row['product_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) $row['product_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                                    <div class="text-[10px] text-gray-600 mt-0.5">Variant #<?php echo $variantId; ?></div>
                                </td>
                                <td class="px-4 py-3"><span class="inline-flex rounded-full bg-blue-500/15 text-blue-300 px-2 py-1 text-xs"><?php echo htmlspecialchars((string) ($row['duration'] ?: specialPriceText('ไม่มีระยะเวลา', 'No duration')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span></td>
                                <td class="px-4 py-3 text-right text-sm <?php echo $selectedRole === 'user' ? 'text-blue-300 font-semibold' : 'text-gray-500'; ?>"><?php echo formatCurrency($priceUser); ?></td>
                                <td class="px-4 py-3 text-right text-sm <?php echo $selectedRole === 'reseller' ? 'text-purple-300 font-semibold' : 'text-gray-500'; ?>"><?php echo formatCurrency($priceReseller); ?></td>
                                <td class="px-4 py-3 text-right text-sm">
                                    <div class="font-semibold <?php echo $effectiveCost > 0 ? 'text-orange-300' : 'text-gray-600'; ?>"><?php echo $effectiveCost > 0 ? formatCurrency($effectiveCost) : '—'; ?></div>
                                    <?php if ($apiCost > 0): ?><div class="text-[9px] text-cyan-400 mt-0.5"><i class="bi bi-cloud-arrow-down mr-0.5"></i>API <?php echo formatCurrency($apiCost); ?></div><?php elseif ($localCost > 0): ?><div class="text-[9px] text-gray-600 mt-0.5"><?php echo htmlspecialchars(specialPriceText('ต้นทุนสินค้า', 'Local cost'), ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-center gap-2">
                                        <input type="text" inputmode="decimal" autocomplete="off"
                                               name="prices[<?php echo $variantId; ?>]"
                                               value="<?php echo $hasCustom ? number_format($customPrice, 2, '.', '') : ''; ?>"
                                               data-base="<?php echo number_format($basePrice, 2, '.', ''); ?>"
                                               data-cost="<?php echo number_format($effectiveCost, 2, '.', ''); ?>"
                                               data-saved-price="<?php echo $hasCustom ? number_format($customPrice, 2, '.', '') : ''; ?>"
                                               data-below-confirmed="<?php echo $belowCostConfirmed ? '1' : '0'; ?>"
                                               data-confirmed-cost="<?php echo number_format($confirmedCost, 2, '.', ''); ?>"
                                               class="price-input glass rounded-lg px-2 py-2 bg-transparent text-center text-sm w-28 outline-none focus:border-indigo-400 <?php echo $customStatus === 'inactive' ? 'opacity-50' : ''; ?> <?php echo $isBelowCost ? ($belowCostAuthorizationValid ? 'border-orange-500/60' : 'border-red-500/60') : ''; ?>"
                                               placeholder="<?php echo number_format($basePrice, 2, '.', ''); ?>">
                                        <div class="flex flex-col gap-1">
                                            <button type="button" class="row-discount text-[10px] rounded bg-white/5 hover:bg-white/10 px-1.5 py-0.5 text-gray-400" data-percent="10">-10%</button>
                                            <button type="button" class="clear-price text-[10px] rounded bg-white/5 hover:bg-red-500/10 px-1.5 py-0.5 text-gray-400 hover:text-red-300">×</button>
                                        </div>
                                    </div>
                                    <div class="price-difference text-center text-[10px] mt-1 <?php echo $isBelowCost ? ($belowCostAuthorizationValid ? 'text-orange-300 font-semibold' : 'text-red-300 font-semibold') : ($saving > 0 ? 'text-green-400' : ($saving < 0 ? 'text-red-400' : 'text-gray-600')); ?>">
                                        <?php if ($isBelowCost): ?><i class="bi bi-exclamation-triangle mr-0.5"></i><?php echo htmlspecialchars($belowCostAuthorizationValid ? specialPriceText('ต่ำกว่าต้นทุน · ยืนยันแล้ว', 'Below cost · confirmed') : specialPriceText('ต่ำกว่าต้นทุน · ต้องยืนยัน', 'Below cost · confirmation required'), ENT_QUOTES, 'UTF-8'); ?><?php elseif ($hasCustom && $saving > 0): ?>-<?php echo rtrim(rtrim(number_format($saving, 1), '0'), '.'); ?>%<?php elseif ($hasCustom && $saving < 0): ?>+<?php echo rtrim(rtrim(number_format(abs($saving), 1), '0'), '.'); ?>%<?php else: ?><?php echo htmlspecialchars(specialPriceText('ใช้ราคาปกติ', 'Default price'), ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <?php if (!$hasCustom): ?>
                                        <span class="inline-flex rounded-full bg-gray-500/10 text-gray-500 px-2 py-1 text-[10px]"><?php echo htmlspecialchars(specialPriceText('ยังไม่ตั้ง', 'Unset'), ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php elseif ($customStatus === 'active'): ?>
                                        <span class="inline-flex rounded-full bg-green-500/15 text-green-300 px-2 py-1 text-[10px]"><i class="bi bi-circle-fill text-[6px] mr-1.5"></i><?php echo htmlspecialchars(specialPriceText('ใช้งาน', 'Active'), ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php else: ?>
                                        <span class="inline-flex rounded-full bg-yellow-500/15 text-yellow-300 px-2 py-1 text-[10px]"><i class="bi bi-pause-fill mr-1"></i><?php echo htmlspecialchars(specialPriceText('พักไว้', 'Paused'), ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex justify-center gap-1.5">
                                        <?php if ($hasCustom): ?>
                                            <button type="button" class="toggle-price-btn rounded-lg bg-yellow-500/10 hover:bg-yellow-500/20 text-yellow-300 px-2 py-1.5 text-xs transition" data-variant="<?php echo $variantId; ?>" title="<?php echo htmlspecialchars(specialPriceText('เปิดหรือพักราคา', 'Activate or pause price'), ENT_QUOTES, 'UTF-8'); ?>"><i class="bi bi-power"></i></button>
                                            <button type="button" class="delete-price-btn rounded-lg bg-red-500/10 hover:bg-red-500/20 text-red-300 px-2 py-1.5 text-xs transition" data-variant="<?php echo $variantId; ?>" title="<?php echo htmlspecialchars(specialPriceText('ลบราคาพิเศษ', 'Delete special price'), ENT_QUOTES, 'UTF-8'); ?>"><i class="bi bi-trash3"></i></button>
                                        <?php else: ?>
                                            <span class="text-gray-700">—</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (!$priceRows): ?>
                    <div class="p-10 text-center text-gray-500 text-sm"><i class="bi bi-box-seam text-3xl block mb-2"></i><?php echo htmlspecialchars(specialPriceText('ไม่พบสินค้าที่เปิดใช้งาน', 'No active products were found.'), ENT_QUOTES, 'UTF-8'); ?></div>
                <?php else: ?>
                    <div class="sticky bottom-0 bg-[#111116]/95 backdrop-blur border-t border-white/10 p-4 flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                        <div class="text-xs text-gray-500"><span id="visibleCount"><?php echo count($priceRows); ?></span> / <?php echo count($priceRows); ?> <?php echo htmlspecialchars(specialPriceText('รายการ', 'items'), ENT_QUOTES, 'UTF-8'); ?></div>
                        <button type="submit" class="bg-indigo-500 hover:bg-indigo-400 rounded-lg px-6 py-2.5 font-medium text-sm transition shadow-glow">
                            <i class="bi bi-save mr-1"></i><?php echo htmlspecialchars(specialPriceText('บันทึกราคาทั้งหมด', 'Save all prices'), ENT_QUOTES, 'UTF-8'); ?>
                        </button>
                    </div>
                <?php endif; ?>
            </form>
        </section>

        <form method="POST" id="rowActionForm" class="hidden">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" id="rowAction">
            <input type="hidden" name="account_id" value="<?php echo $selectedAccountId; ?>">
            <input type="hidden" name="account_q" value="<?php echo htmlspecialchars($accountQuery, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
            <input type="hidden" name="account_role" value="<?php echo htmlspecialchars($accountRoleFilter, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="account_price_state" value="<?php echo htmlspecialchars($accountPriceState, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="variant_id" id="rowVariantId">
        </form>
    <?php endif; ?>
</main>

<script>
(function () {
    'use strict';

    const text = {
        defaultPrice: <?php echo json_encode(specialPriceText('ใช้ราคาปกติ', 'Default price'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        chooseItems: <?php echo json_encode(specialPriceText('กรุณาเลือกสินค้าอย่างน้อย 1 รายการ', 'Select at least one item.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        clearAll: <?php echo json_encode(specialPriceText('ยืนยันล้างราคาพิเศษทั้งหมดของบัญชีนี้หรือไม่?', 'Clear every special price for this account?'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        deleteOne: <?php echo json_encode(specialPriceText('ลบราคาพิเศษรายการนี้หรือไม่?', 'Delete this special price?'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        belowCost: <?php echo json_encode(specialPriceText('ราคาที่กำลังบันทึกต่ำกว่าต้นทุนล่าสุด {count} รายการ หากยืนยันอาจขายขาดทุน ต้องการดำเนินการต่อหรือไม่?', '{count} price(s) are below the latest verified cost and may cause a loss. Continue?'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        belowCostLabel: <?php echo json_encode(specialPriceText('ต่ำกว่าต้นทุน · ต้องยืนยัน', 'Below cost · confirmation required'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        belowCostConfirmedLabel: <?php echo json_encode(specialPriceText('ต่ำกว่าต้นทุน · ยืนยันแล้ว', 'Below cost · confirmed'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
    };

    window.confirmClearAll = function () {
        return window.confirm(text.clearAll);
    };

    const rows = Array.from(document.querySelectorAll('.price-row'));
    const search = document.getElementById('productSearch');
    const filter = document.getElementById('priceFilter');
    const visibleCount = document.getElementById('visibleCount');
    const selectAll = document.getElementById('selectAll');
    const selectVisibleBtn = document.getElementById('selectVisibleBtn');
    const checkboxes = Array.from(document.querySelectorAll('.variant-checkbox'));
    const selectedCount = document.getElementById('selectedCount');
    const bulkContainer = document.getElementById('bulkVariantContainer');
    const bulkForm = document.getElementById('bulkForm');
    const bulkMode = document.getElementById('bulkMode');
    const discountInput = document.getElementById('discountPercent');
    const fixedInput = document.getElementById('fixedPrice');

    function normalize(value) {
        return String(value || '').toLocaleLowerCase();
    }

    function applyFilters() {
        const query = normalize(search ? search.value.trim() : '');
        const stateFilter = filter ? filter.value : 'all';
        let shown = 0;
        rows.forEach(function (row) {
            const matchesText = !query || normalize(row.dataset.search).includes(query);
            const state = row.dataset.state || 'unset';
            let matchesState = true;
            if (stateFilter === 'configured') matchesState = state === 'active' || state === 'inactive';
            if (stateFilter === 'unset') matchesState = state === 'unset';
            if (stateFilter === 'inactive') matchesState = state === 'inactive';
            row.hidden = !(matchesText && matchesState);
            if (!row.hidden) shown++;
        });
        if (visibleCount) visibleCount.textContent = String(shown);
        syncSelectAll();
    }

    function visibleCheckboxes() {
        return checkboxes.filter(function (checkbox) {
            const row = checkbox.closest('.price-row');
            return row && !row.hidden;
        });
    }

    function syncSelectAll() {
        if (!selectAll) return;
        const visible = visibleCheckboxes();
        const checked = visible.filter(function (checkbox) { return checkbox.checked; }).length;
        selectAll.checked = visible.length > 0 && checked === visible.length;
        selectAll.indeterminate = checked > 0 && checked < visible.length;
        syncBulkSelection();
    }

    function syncBulkSelection() {
        if (!bulkContainer) return;
        bulkContainer.replaceChildren();
        const selected = checkboxes.filter(function (checkbox) { return checkbox.checked; });
        selected.forEach(function (checkbox) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'variant_ids[]';
            input.value = checkbox.value;
            bulkContainer.appendChild(input);
        });
        if (selectedCount) selectedCount.textContent = String(selected.length);
    }

    function existingBelowCostConfirmationValid(input, value, cost) {
        const savedPrice = Number(input.dataset.savedPrice || 0);
        const confirmedCost = Number(input.dataset.confirmedCost || 0);
        return input.dataset.belowConfirmed === '1'
            && Number.isFinite(savedPrice)
            && Math.abs(savedPrice - value) < 0.00001
            && confirmedCost + 0.00001 >= cost;
    }

    function updateDifference(input) {
        const base = Number(input.dataset.base || 0);
        const cost = Number(input.dataset.cost || 0);
        const value = Number(String(input.value).replace(',', '.'));
        const label = input.closest('td').querySelector('.price-difference');
        if (!label) return;
        label.className = 'price-difference text-center text-[10px] mt-1';
        input.classList.remove('border-red-500/60', 'border-orange-500/60');
        if (!input.value.trim() || !Number.isFinite(value) || value <= 0) {
            label.textContent = text.defaultPrice;
            label.classList.add('text-gray-600');
            return;
        }
        if (cost > 0 && value + 0.00001 < cost) {
            const alreadyConfirmed = existingBelowCostConfirmationValid(input, value, cost);
            label.textContent = '⚠ ' + (alreadyConfirmed ? text.belowCostConfirmedLabel : text.belowCostLabel);
            label.classList.add(alreadyConfirmed ? 'text-orange-300' : 'text-red-300', 'font-semibold');
            input.classList.add(alreadyConfirmed ? 'border-orange-500/60' : 'border-red-500/60');
            return;
        }
        if (base <= 0) {
            label.textContent = text.defaultPrice;
            label.classList.add('text-gray-600');
            return;
        }
        const percent = (1 - value / base) * 100;
        if (Math.abs(percent) < 0.05) {
            label.textContent = '0%';
            label.classList.add('text-gray-500');
        } else if (percent > 0) {
            label.textContent = '-' + percent.toFixed(1).replace(/\.0$/, '') + '%';
            label.classList.add('text-green-400');
        } else {
            label.textContent = '+' + Math.abs(percent).toFixed(1).replace(/\.0$/, '') + '%';
            label.classList.add('text-red-400');
        }
    }

    if (search) search.addEventListener('input', applyFilters);
    if (filter) filter.addEventListener('change', applyFilters);

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            visibleCheckboxes().forEach(function (checkbox) { checkbox.checked = selectAll.checked; });
            syncSelectAll();
        });
    }

    if (selectVisibleBtn) {
        selectVisibleBtn.addEventListener('click', function () {
            const visible = visibleCheckboxes();
            const shouldCheck = visible.some(function (checkbox) { return !checkbox.checked; });
            visible.forEach(function (checkbox) { checkbox.checked = shouldCheck; });
            syncSelectAll();
        });
    }

    checkboxes.forEach(function (checkbox) {
        checkbox.addEventListener('change', syncSelectAll);
    });

    document.querySelectorAll('.price-input').forEach(function (input) {
        input.addEventListener('input', function () {
            const confirmField = document.getElementById('saveConfirmBelowCost');
            if (confirmField) confirmField.value = '0';
            updateDifference(input);
        });
        updateDifference(input);
    });

    document.querySelectorAll('.row-discount').forEach(function (button) {
        button.addEventListener('click', function () {
            const input = button.closest('td').querySelector('.price-input');
            const base = Number(input.dataset.base || 0);
            const percent = Number(button.dataset.percent || 0);
            if (base > 0 && percent > 0) {
                input.value = (base * (100 - percent) / 100).toFixed(2);
                input.dispatchEvent(new Event('input', { bubbles: true }));
            }
        });
    });

    document.querySelectorAll('.clear-price').forEach(function (button) {
        button.addEventListener('click', function () {
            const input = button.closest('td').querySelector('.price-input');
            input.value = '';
            input.dispatchEvent(new Event('input', { bubbles: true }));
        });
    });

    document.querySelectorAll('.quick-discount').forEach(function (button) {
        button.addEventListener('click', function () {
            if (bulkMode) bulkMode.value = 'discount';
            if (discountInput) discountInput.value = button.dataset.discount || '';
            if (bulkMode) bulkMode.dispatchEvent(new Event('change'));
        });
    });

    if (bulkMode) {
        bulkMode.addEventListener('change', function () {
            const fixed = bulkMode.value === 'fixed';
            if (discountInput) discountInput.classList.toggle('hidden', fixed);
            if (fixedInput) fixedInput.classList.toggle('hidden', !fixed);
        });
    }

    function bulkBelowCostCount(selected) {
        const fixedMode = bulkMode && bulkMode.value === 'fixed';
        const fixedValue = Number(fixedInput ? fixedInput.value : 0);
        const discountValue = Number(discountInput ? discountInput.value : 0);
        let count = 0;
        selected.forEach(function (checkbox) {
            const base = Number(checkbox.dataset.base || 0);
            const cost = Number(checkbox.dataset.cost || 0);
            const candidate = fixedMode ? fixedValue : base * (100 - discountValue) / 100;
            if (cost > 0 && Number.isFinite(candidate) && candidate > 0 && candidate + 0.00001 < cost) count++;
        });
        return count;
    }

    if (bulkForm) {
        bulkForm.addEventListener('submit', function (event) {
            const selected = checkboxes.filter(function (checkbox) { return checkbox.checked; });
            if (!selected.length) {
                event.preventDefault();
                window.alert(text.chooseItems);
                return;
            }
            const confirmField = document.getElementById('bulkConfirmBelowCost');
            const belowCount = bulkBelowCostCount(selected);
            if (belowCount > 0 && (!confirmField || confirmField.value !== '1')) {
                if (!window.confirm(text.belowCost.replace('{count}', String(belowCount)))) {
                    event.preventDefault();
                    return;
                }
                if (confirmField) confirmField.value = '1';
            } else if (belowCount === 0 && confirmField) {
                confirmField.value = '0';
            }
            syncBulkSelection();
        });
        [bulkMode, discountInput, fixedInput].forEach(function (element) {
            if (!element) return;
            element.addEventListener('input', function () {
                const confirmField = document.getElementById('bulkConfirmBelowCost');
                if (confirmField) confirmField.value = '0';
            });
            element.addEventListener('change', function () {
                const confirmField = document.getElementById('bulkConfirmBelowCost');
                if (confirmField) confirmField.value = '0';
            });
        });
    }

    const rowActionForm = document.getElementById('rowActionForm');
    const rowAction = document.getElementById('rowAction');
    const rowVariantId = document.getElementById('rowVariantId');

    document.querySelectorAll('.toggle-price-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            rowAction.value = 'toggle_price';
            rowVariantId.value = button.dataset.variant;
            rowActionForm.submit();
        });
    });

    document.querySelectorAll('.delete-price-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            if (!window.confirm(text.deleteOne)) return;
            rowAction.value = 'delete_price';
            rowVariantId.value = button.dataset.variant;
            rowActionForm.submit();
        });
    });

    const saveForm = document.getElementById('savePricesForm');
    if (saveForm) {
        saveForm.addEventListener('submit', function (event) {
            const priceInputs = Array.from(saveForm.querySelectorAll('.price-input'));
            const belowCount = priceInputs.filter(function (input) {
                const raw = input.value.trim();
                if (!raw) return false;
                const value = Number(raw.replace(',', '.'));
                const cost = Number(input.dataset.cost || 0);
                return cost > 0
                    && Number.isFinite(value)
                    && value > 0
                    && value + 0.00001 < cost
                    && !existingBelowCostConfirmationValid(input, value, cost);
            }).length;
            const confirmField = document.getElementById('saveConfirmBelowCost');
            if (belowCount > 0 && (!confirmField || confirmField.value !== '1')) {
                if (!window.confirm(text.belowCost.replace('{count}', String(belowCount)))) {
                    event.preventDefault();
                    return;
                }
                if (confirmField) confirmField.value = '1';
            } else if (belowCount === 0 && confirmField) {
                confirmField.value = '0';
            }

            const submit = saveForm.querySelector('button[type="submit"]');
            if (submit) {
                submit.disabled = true;
                submit.classList.add('opacity-60', 'cursor-not-allowed');
            }
        });
    }

    applyFilters();
    if (window.lucide) window.lucide.createIcons();
})();
</script>
</body>
</html>
