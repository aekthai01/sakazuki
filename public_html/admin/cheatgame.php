<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/cheatgame.php';
requireAdmin();

$isTh = getAppLang() === 'th';
$t = static function (string $th, string $en) use ($isTh): string {
    return $isTh ? $th : $en;
};


function cgoHandleAdminProductImageUpload(string $field): array
{
    $result = ['provided' => false, 'path' => '', 'error' => ''];
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) return $result;
    $file = $_FILES[$field];
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) return $result;
    $result['provided'] = true;
    if ($error !== UPLOAD_ERR_OK) {
        $result['error'] = 'The product image upload did not complete.';
        return $result;
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    $size = (int) ($file['size'] ?? 0);
    if ($tmp === '' || !is_uploaded_file($tmp) || $size < 1 || $size > 4 * 1024 * 1024) {
        $result['error'] = 'The product image must be a valid upload no larger than 4 MB.';
        return $result;
    }
    $bytes = file_get_contents($tmp);
    if (!is_string($bytes) || $bytes === '' || strlen($bytes) > 4 * 1024 * 1024) {
        $result['error'] = 'The product image could not be read safely.';
        return $result;
    }
    $info = @getimagesizefromstring($bytes);
    if (!is_array($info) || empty($info[0]) || empty($info[1]) || (int) $info[0] > 6000 || (int) $info[1] > 6000 || ((int) $info[0] * (int) $info[1]) > 24000000) {
        $result['error'] = 'The product image dimensions are invalid or too large.';
        return $result;
    }
    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
    $mime = $finfo ? finfo_buffer($finfo, $bytes) : false;
    if ($finfo) finfo_close($finfo);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if (!is_string($mime) || !isset($extensions[$mime])) {
        $result['error'] = 'Only JPEG, PNG, WebP, or GIF product images are accepted.';
        return $result;
    }
    $dir = __DIR__ . '/../assets/uploads/products/';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        $result['error'] = 'The product image directory is not writable.';
        return $result;
    }

    if (function_exists('sakazukiWriteOptimizedProductImage')) {
        $optimized = sakazukiWriteOptimizedProductImage($bytes, $dir, 'cgo_', 1024);
        if (!empty($optimized['success']) && !empty($optimized['filename'])) {
            $result['path'] = 'assets/uploads/products/' . $optimized['filename'];
            return $result;
        }
    }

    // Safe compatibility fallback when PHP GD is unavailable on rented hosting.
    try {
        $name = 'cgo_' . bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    } catch (Throwable $e) {
        $result['error'] = 'A secure product image filename could not be generated.';
        return $result;
    }
    $destination = $dir . $name;
    $written = file_put_contents($destination, $bytes, LOCK_EX) === strlen($bytes);
    if (!$written) {
        @unlink($destination);
        $result['error'] = 'The product image could not be saved.';
        return $result;
    }
    @chmod($destination, 0644);
    $result['path'] = 'assets/uploads/products/' . $name;
    return $result;
}

$message = '';
$error = '';
$testData = null;
$outboundDiagnostic = null;
$orderStatusTestData = null;
$orderCancelTestData = null;
$syncPriceWarnings = [];
$syncPriceCorrections = [];

if (!function_exists('cgoAdminReturnQuery')) {
    function cgoAdminReturnQuery(): string
    {
        $raw = isset($_POST['return_query']) && is_string($_POST['return_query']) ? $_POST['return_query'] : '';
        $parsed = [];
        parse_str($raw, $parsed);
        $allowed = ['q', 'status', 'link', 'category', 'platform', 'stock', 'enabled', 'pricing', 'warning', 'sort', 'per_page', 'page'];
        $clean = [];
        foreach ($allowed as $key) {
            if (!isset($parsed[$key]) || !is_scalar($parsed[$key])) continue;
            $value = trim((string) $parsed[$key]);
            if ($value === '' || strlen($value) > 160) continue;
            $clean[$key] = $value;
        }
        return http_build_query($clean, '', '&', PHP_QUERY_RFC3986);
    }
}

if (!function_exists('cgoAdminFinishPost')) {
    function cgoAdminFinishPost(string $message, string $error, array $priceCorrections = []): void
    {
        $_SESSION['cgo_admin_flash'] = [
            'message' => $message,
            'error' => $error,
            'price_corrections' => $priceCorrections,
        ];
        $query = cgoAdminReturnQuery();
        $anchor = isset($_POST['return_anchor']) && is_string($_POST['return_anchor'])
            ? trim($_POST['return_anchor']) : 'cgo-products';
        if (!preg_match('/^[A-Za-z0-9_-]{1,80}$/', $anchor)) $anchor = 'cgo-products';
        header('Location: cheatgame.php' . ($query !== '' ? '?' . $query : '') . '#' . rawurlencode($anchor), true, 303);
        exit;
    }
}

if (isset($_SESSION['cgo_admin_flash']) && is_array($_SESSION['cgo_admin_flash'])) {
    $flash = $_SESSION['cgo_admin_flash'];
    unset($_SESSION['cgo_admin_flash']);
    $message = isset($flash['message']) && is_string($flash['message']) ? $flash['message'] : '';
    $error = isset($flash['error']) && is_string($flash['error']) ? $flash['error'] : '';
    $syncPriceCorrections = isset($flash['price_corrections']) && is_array($flash['price_corrections'])
        ? $flash['price_corrections'] : [];
}

if (!cgoEnsureTables() && $error === '') {
    $error = $t('ไม่สามารถเตรียมตารางฐานข้อมูล CHEATGAME ได้', 'Unable to prepare CHEATGAME database tables.');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    requireCsrfToken();
    $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'save_markup') {
        $userMarkup = isset($_POST['user_markup']) && is_numeric($_POST['user_markup']) ? (float) $_POST['user_markup'] : -1;
        $resellerMarkup = isset($_POST['reseller_markup']) && is_numeric($_POST['reseller_markup']) ? (float) $_POST['reseller_markup'] : -1;
        $applyNow = isset($_POST['apply_markup_now']) && $_POST['apply_markup_now'] === '1';
        if (!cgoSetMarkupSettings($userMarkup, $resellerMarkup)) {
            $error = $t('มาร์กอัปต้องเป็นตัวเลขตั้งแต่ 0 ถึง 1000', 'Markup must be a number from 0 to 1000.');
        } elseif ($applyNow) {
            $applyResult = cgoApplyMarkupPricing(true);
            if (empty($applyResult['success'])) {
                $error = $t('บันทึกเปอร์เซ็นต์แล้ว แต่ปรับราคาสินค้าไม่สำเร็จ: ', 'The percentages were saved, but product prices could not be updated: ')
                    . (string) ($applyResult['message'] ?? $t('ไม่ทราบสาเหตุ', 'Unknown error'));
            } else {
                $message = $t('บันทึกมาร์กอัปและปรับราคาสินค้าทั้งหมดทันทีแล้ว ', 'Markup saved and applied immediately to all products: ')
                    . (int) ($applyResult['updated'] ?? 0)
                    . $t(' รายการ; อัปเดตราคาสินค้าที่เชื่อมหน้าหลัก ', ' products; linked main-catalogue prices updated: ')
                    . (int) ($applyResult['linked_synced'] ?? 0);
            }
        } else {
            $message = $t('บันทึกมาร์กอัปแล้ว สินค้าอัตโนมัติและสินค้าใหม่จะใช้เปอร์เซ็นต์นี้ในการซิงค์ครั้งถัดไป', 'Markup saved. Automatic and new products will use it on the next sync.');
        }
    } elseif ($action === 'sync_products') {
        $result = cgoSyncProducts();
        if (!empty($result['success'])) {
            $message = $t('ซิงค์สินค้าแล้ว ', 'Products synchronized: ') . (int) ($result['synced'] ?? 0)
                . $t(' รายการ; ข้ามข้อมูลไม่สมบูรณ์ ', '; incomplete records skipped: ') . (int) ($result['invalid'] ?? 0)
                . $t('; รักษาราคาที่บันทึกไว้ ', '; saved prices preserved: ') . (int) ($result['preserved_prices'] ?? 0)
                . $t('; นำสินค้าที่ Supplier เอาออกออกจากแค็ตตาล็อก ', '; supplier-removed products archived: ') . (int) ($result['removed'] ?? 0)
                . $t('; อัปเดตสินค้าที่เชื่อมกับหน้าหลัก ', '; linked main-catalogue items reconciled: ') . (int) ($result['linked_synced'] ?? 0);
            if (!empty($result['removal_skipped'])) {
                $message .= $t('; ข้ามการนำออกเพื่อความปลอดภัย เพราะยืนยันรายการ API รอบสองไม่สำเร็จ ', '; removal skipped for safety because the second API catalogue check failed: ')
                    . (int) $result['removal_skipped'];
            }
            $syncPriceWarnings = isset($result['price_warnings']) && is_array($result['price_warnings'])
                ? $result['price_warnings'] : [];
            $syncPriceCorrections = isset($result['price_corrections']) && is_array($result['price_corrections'])
                ? $result['price_corrections'] : [];
            if ($syncPriceCorrections !== []) {
                $message .= $t('; ปรับราคาที่ต่ำกว่าทุนขึ้นเท่าทุนอัตโนมัติ ', '; prices raised to supplier cost automatically: ')
                    . count($syncPriceCorrections);
            }
        } else {
            $error = (string) ($result['message'] ?? $t('ซิงค์สินค้าไม่สำเร็จ', 'Product sync failed.'));
        }
    } elseif ($action === 'sync_stock') {
        $result = cgoRefreshRemoteInventory(true, 10);
        if (!empty($result['success'])) {
            $catalogResult = null;
            if (!empty($result['catalog_refresh_needed'])) {
                $catalogResult = cgoSyncProducts();
            }
            $message = $t('อัปเดตสต็อก API แล้ว โดยไม่แตะราคาที่บันทึกเอง: ', 'API inventory refreshed without overwriting saved manual prices: ')
                . (int) ($result['updated'] ?? 0)
                . $t(' รายการ; สินค้าที่หายจากคำตอบ ', ' records; products missing from the response: ')
                . (int) ($result['missing'] ?? 0)
                . $t('; ข้อมูลสต็อกไม่สมบูรณ์ ', '; invalid inventory records: ')
                . (int) ($result['invalid'] ?? 0)
                . $t('; จำนวนครั้งที่เรียก API ', '; API attempts: ')
                . (int) ($result['attempts'] ?? 1);
            if (!empty($result['partial'])) {
                $message .= $t('; คำตอบยังมาไม่ครบ ระบบจะลองใหม่อัตโนมัติ ความครอบคลุม ', '; response remained partial and will retry automatically; coverage ')
                    . (string) ($result['coverage_percent'] ?? '?') . '%';
            }
            if (is_array($catalogResult)) {
                $message .= !empty($catalogResult['success'])
                    ? $t('; พบสินค้าใหม่และซิงค์แค็ตตาล็อกต่อให้อัตโนมัติ ', '; new products were detected and the catalogue was synchronized automatically: ')
                        . (int) ($catalogResult['synced'] ?? 0)
                    : $t('; พบสินค้าใหม่แต่ซิงค์แค็ตตาล็อกต่อไม่สำเร็จ', '; new products were detected, but the catalogue follow-up failed');
            }
        } else {
            $error = (string) ($result['message'] ?? $t('อัปเดตสต็อก API ไม่สำเร็จ', 'API inventory refresh failed.'));
        }
    } elseif ($action === 'test_api') {
        $outboundDiagnostic = cgoDetectPublicOutboundIp();
        $productsResult = cgoApiRequest('products');
        $balanceResult = cgoApiRequest('balance');
        $rateResult = cgoApiRequest('exchange_rate');

        $productList = !empty($productsResult['ok']) && is_array($productsResult['data'])
            ? cgoExtractProductList($productsResult['data']) : [];
        $balance = !empty($balanceResult['ok']) && is_array($balanceResult['data'])
            ? cgoExtractBalance($balanceResult['data']) : null;
        $rate = !empty($rateResult['ok']) && is_array($rateResult['data'])
            ? cgoExtractExchangeRate($rateResult['data']) : null;

        $makeDiagnostic = static function (array $result, bool $schemaOk, string $schemaError = ''): array {
            $data = isset($result['data']) && is_array($result['data']) ? $result['data'] : [];
            $errorText = trim((string) ($result['error'] ?? ''));
            if (!empty($result['ok']) && !$schemaOk && $errorText === '') {
                $errorText = $schemaError !== '' ? $schemaError : 'CHEATGAME returned an unexpected response format';
            }
            return [
                'ok' => !empty($result['ok']) && $schemaOk,
                'http_code' => (int) ($result['http_code'] ?? 0),
                'error' => $errorText,
                'excerpt' => cgoDiagnosticExcerpt($result),
                'primary_ip' => (string) ($result['primary_ip'] ?? ''),
                'local_ip' => (string) ($result['local_ip'] ?? ''),
                'observed_ip' => $data !== [] ? cgoExtractObservedClientIp($data) : '',
                'total_time_ms' => (int) ($result['total_time_ms'] ?? 0),
                'transport_error' => !empty($result['transport_error']),
                'ip_mode' => (string) ($result['ip_mode'] ?? ''),
                'provider_error_code' => (string) ($result['provider_error_code'] ?? ''),
                'provider_error_class' => (string) ($result['provider_error_class'] ?? ''),
                'cf_ray' => (string) ($result['cf_ray'] ?? ''),
                'request_id' => (string) ($result['request_id'] ?? ''),
            ];
        };

        $testData = [
            'products' => array_merge(
                $makeDiagnostic($productsResult, $productList !== [], 'The products response did not contain a recognized product list'),
                ['summary' => $productList !== [] ? ($t('จำนวนสินค้า: ', 'Products: ') . count($productList)) : '']
            ),
            'balance' => array_merge(
                $makeDiagnostic($balanceResult, $balance !== null, 'The balance response did not contain a numeric balance'),
                ['summary' => $balance !== null ? number_format((float) $balance, 6, '.', '') : '']
            ),
            'exchange_rate' => array_merge(
                $makeDiagnostic($rateResult, is_array($rate), 'The exchange-rate response did not contain a numeric rate'),
                ['summary' => is_array($rate) ? (number_format((float) $rate['rate'], 6, '.', '') . ' IDR/USDT · ' . (string) $rate['status']) : '']
            ),
        ];
        if ($testData['products']['ok'] && $testData['balance']['ok'] && $testData['exchange_rate']['ok']) {
            $message = $t('ทดสอบ API สำเร็จทั้ง products, balance และ exchange_rate', 'API tests passed for products, balance, and exchange_rate.');
        } else {
            $error = $t('การทดสอบ API บางรายการไม่ผ่าน ระบบแสดง HTTP และคำตอบจริงจากปลายทางไว้ด้านล่างแล้ว', 'One or more API tests failed. The HTTP status and provider response are shown below.');
        }
    } elseif ($action === 'clear_api_logs') {
        $clearResult = cgoClearOrderApiAttemptLogs();
        if (!empty($clearResult['success'])) {
            cgoAdminFinishPost(
                $t('ล้าง API diagnostic logs เก่าแล้ว ', 'Historical API diagnostic logs cleared: ') . (int) ($clearResult['deleted'] ?? 0)
                    . $t(' รายการ; เก็บ log ของ Order ที่ยังไม่จบไว้ ', ' rows; unresolved-order logs preserved: ') . (int) ($clearResult['preserved_pending'] ?? 0)
                    . $t(' รายการ โดยไม่แตะ Order, Transaction, Wallet Ledger, Key หรือ Webhook dedup', ' rows. Orders, transactions, wallet ledger, keys, and webhook de-duplication records were preserved.'),
                '',
                []
            );
        }
        cgoAdminFinishPost('', (string) ($clearResult['message'] ?? $t('ล้าง API logs ไม่สำเร็จ', 'Unable to clear API logs.')), []);
    } elseif ($action === 'test_order_cancel_seal') {
        try {
            $probePrefix = cgoSitePrefix();
            if ($probePrefix === '') $probePrefix = 'SAK-DIAG';
            $probeRef = $probePrefix . '-SEAL-DIAG-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(8)));
            $preStatus = cgoApiRequest('order_status', 'GET', ['external_ref' => $probeRef], cgoOrderStatusTimeoutSeconds());
            $cancelApi = cgoApiRequest('order_cancel', 'POST', ['external_ref' => $probeRef], cgoOrderCancelTimeoutSeconds());
            $cancelData = isset($cancelApi['data']) && is_array($cancelApi['data']) ? $cancelApi['data'] : null;
            $classification = cgoOrderCancelClassification($cancelApi, $cancelData, $probeRef);
            $postStatus = cgoApiRequest('order_status', 'GET', ['external_ref' => $probeRef], cgoOrderStatusTimeoutSeconds());
            $orderCancelTestData = [
                'synthetic_external_ref' => $probeRef,
                'note' => 'This diagnostic creates only a provider-side cancellation seal/tombstone for a random external_ref. It creates no order and charges no balance.',
                'pre_status' => [
                    'http_code' => (int) ($preStatus['http_code'] ?? 0),
                    'provider_error_code' => (string) ($preStatus['provider_error_code'] ?? ''),
                    'nonfinal_not_found' => isset($preStatus['data']) && is_array($preStatus['data'])
                        ? cgoOrderStatusNonFinalNotFound($preStatus, $preStatus['data'], $probeRef, true) : false,
                    'total_time_ms' => (int) ($preStatus['total_time_ms'] ?? 0),
                    'sanitized_response' => isset($preStatus['data']) && is_array($preStatus['data'])
                        ? cgoSanitizeOrderAttemptValue($preStatus['data'], 0, cgoExtractKeys($preStatus['data'])) : null,
                ],
                'order_cancel' => [
                    'ok' => !empty($cancelApi['ok']),
                    'http_code' => (int) ($cancelApi['http_code'] ?? 0),
                    'transport_error' => !empty($cancelApi['transport_error']),
                    'curl_errno' => (int) ($cancelApi['curl_errno'] ?? 0),
                    'error' => (string) ($cancelApi['error'] ?? ''),
                    'provider_error_code' => (string) ($cancelApi['provider_error_code'] ?? ''),
                    'request_started_at_ms' => (int) ($cancelApi['request_started_at_ms'] ?? 0),
                    'request_finished_at_ms' => (int) ($cancelApi['request_finished_at_ms'] ?? 0),
                    'total_time_ms' => (int) ($cancelApi['total_time_ms'] ?? 0),
                    'classification' => [
                        'state' => (string) ($classification['state'] ?? 'unknown'),
                        'safe_to_refund' => !empty($classification['safe_to_refund']),
                        'reason' => (string) ($classification['reason'] ?? ''),
                        'supplier_order_id' => (string) ($classification['supplier_order_id'] ?? ''),
                    ],
                    'sanitized_response' => $cancelData !== null
                        ? cgoSanitizeOrderAttemptValue($cancelData, 0, cgoExtractKeys($cancelData)) : null,
                ],
                'post_status' => [
                    'http_code' => (int) ($postStatus['http_code'] ?? 0),
                    'provider_error_code' => (string) ($postStatus['provider_error_code'] ?? ''),
                    'total_time_ms' => (int) ($postStatus['total_time_ms'] ?? 0),
                    'sanitized_response' => isset($postStatus['data']) && is_array($postStatus['data'])
                        ? cgoSanitizeOrderAttemptValue($postStatus['data'], 0, cgoExtractKeys($postStatus['data'])) : null,
                ],
                '_classifier_self_test' => cgoOrderStatusClassifierSelfTest(),
            ];
            if (!empty($classification['safe_to_refund'])) {
                $message = $t('ทดสอบ order_cancel สำเร็จ: synthetic external_ref ถูก seal และ classifier ยืนยันว่า safe_to_refund', 'order_cancel test passed: the synthetic external_ref was sealed and the classifier marked it safe_to_refund.');
            } else {
                $error = $t('order_cancel ตอบกลับแล้ว แต่ยังไม่ยืนยัน seal ที่ปลอดภัย ดู JSON ด้านล่างก่อนเปิดใช้ Fast Refund', 'order_cancel responded, but a safe seal was not verified. Review the JSON below before relying on fast refunds.');
            }
        } catch (Throwable $e) {
            $error = $t('ทดสอบ order_cancel ไม่สำเร็จ: ', 'order_cancel diagnostic failed: ') . $e->getMessage();
        }
    } elseif ($action === 'test_order_status') {
        $localOrderId = isset($_POST['local_order_id']) && is_numeric($_POST['local_order_id']) ? (int) $_POST['local_order_id'] : 0;
        $externalRef = isset($_POST['external_ref']) && is_string($_POST['external_ref']) ? trim($_POST['external_ref']) : '';
        $supplierOrderId = isset($_POST['supplier_order_id']) && is_string($_POST['supplier_order_id']) ? trim($_POST['supplier_order_id']) : '';
        $useLatest = isset($_POST['use_latest']) && $_POST['use_latest'] === '1';

        if ($useLatest || $localOrderId > 0) {
            if ($useLatest) {
                $lookup = $conn->query("SELECT id,external_ref,supplier_order_id,status,created_at FROM cgo_orders ORDER BY id DESC LIMIT 1");
                $local = $lookup ? $lookup->fetch_assoc() : null;
                if ($lookup) $lookup->free();
            } else {
                $stmt = $conn->prepare("SELECT id,external_ref,supplier_order_id,status,created_at FROM cgo_orders WHERE id=? LIMIT 1");
                $local = null;
                if ($stmt) {
                    $stmt->bind_param('i', $localOrderId);
                    if ($stmt->execute()) {
                        $result = $stmt->get_result();
                        $local = $result ? $result->fetch_assoc() : null;
                    }
                    $stmt->close();
                }
            }
            if ($local) {
                $localOrderId = (int) $local['id'];
                if ($externalRef === '') $externalRef = trim((string) ($local['external_ref'] ?? ''));
                if ($supplierOrderId === '') $supplierOrderId = trim((string) ($local['supplier_order_id'] ?? ''));
            }
        }

        $externalRef = substr($externalRef, 0, 120);
        $supplierOrderId = substr($supplierOrderId, 0, 190);
        $orderStatusTestData = [];
        $makeStatusDiagnostic = static function (array $result, string $mode, string $externalRef): array {
            $data = isset($result['data']) && is_array($result['data']) ? $result['data'] : [];
            $providerStatus = $data !== [] ? strtolower(trim((string) (cgoFindRecursiveValue($data, ['order_status', 'status']) ?? ''))) : '';
            $echoedRef = $data !== [] ? trim((string) (cgoFindRecursiveValue($data, ['external_ref']) ?? '')) : '';
            $supplierId = $data !== [] ? trim((string) (cgoExtractSupplierOrderId($data) ?? '')) : '';
            $keyCount = $data !== [] ? count(cgoExtractKeys($data)) : 0;
            return [
                'mode' => $mode,
                'ok' => !empty($result['ok']),
                'http_code' => (int) ($result['http_code'] ?? 0),
                'transport_error' => !empty($result['transport_error']),
                'curl_errno' => (int) ($result['curl_errno'] ?? 0),
                'error' => trim((string) ($result['error'] ?? '')),
                'provider_error_code' => trim((string) ($result['provider_error_code'] ?? '')),
                'provider_status' => $providerStatus,
                'provider_final' => $data !== [] ? cgoOrderStatusFinalFlag($data) : null,
                'echoed_external_ref' => $echoedRef,
                'supplier_order_id' => $supplierId,
                'key_count' => $keyCount,
                'nonfinal_not_found' => $data !== [] && cgoOrderStatusNonFinalNotFound($result, $data, $externalRef, $mode === 'external_ref'),
                'direct_refund_allowed' => $data !== [] && cgoOrderStatusTerminalFailure($data),
                'strict_not_found' => false,
                'primary_ip' => (string) ($result['primary_ip'] ?? ''),
                'cf_ray' => (string) ($result['cf_ray'] ?? ''),
                'request_id' => (string) ($result['request_id'] ?? ''),
                'namelookup_time_ms' => (int) ($result['namelookup_time_ms'] ?? 0),
                'connect_time_ms' => (int) ($result['connect_time_ms'] ?? 0),
                'appconnect_time_ms' => (int) ($result['appconnect_time_ms'] ?? 0),
                'starttransfer_time_ms' => (int) ($result['starttransfer_time_ms'] ?? 0),
                'total_time_ms' => (int) ($result['total_time_ms'] ?? 0),
                'request_started_at_ms' => (int) ($result['request_started_at_ms'] ?? 0),
                'request_finished_at_ms' => (int) ($result['request_finished_at_ms'] ?? 0),
                'sanitized_response' => $data !== [] ? cgoSanitizeOrderAttemptValue($data, 0, cgoExtractKeys($data)) : null,
            ];
        };

        if ($externalRef !== '') {
            $result = cgoApiRequest('order_status', 'GET', ['external_ref' => $externalRef], cgoOrderStatusTimeoutSeconds());
            $orderStatusTestData['external_ref'] = $makeStatusDiagnostic($result, 'external_ref', $externalRef);
            if ($localOrderId > 0) {
                cgoRecordOrderApiAttempt($localOrderId, 'admin_status_probe', $result, [
                    'lookup_mode' => 'external_ref',
                    'external_ref' => $externalRef,
                    'supplier_order_id' => $supplierOrderId,
                ], 'admin_read_only_probe');
            }
        }
        if ($supplierOrderId !== '') {
            $result = cgoApiRequest('order_status', 'GET', ['order_id' => $supplierOrderId], cgoOrderStatusTimeoutSeconds());
            $orderStatusTestData['order_id'] = $makeStatusDiagnostic($result, 'order_id', $externalRef);
            if ($localOrderId > 0) {
                cgoRecordOrderApiAttempt($localOrderId, 'admin_status_probe', $result, [
                    'lookup_mode' => 'order_id',
                    'external_ref' => $externalRef,
                    'supplier_order_id' => $supplierOrderId,
                ], 'admin_read_only_probe');
            }
        }

        // Probe a cryptographically random reference that cannot correspond to a
        // real local checkout. This is read-only and reveals whether the provider
        // actually supports external_ref lookup and whether a 404 is machine-bound
        // to the queried reference, without spending balance or creating an order.
        if ($useLatest) {
            try {
                $probePrefix = cgoSitePrefix();
                if ($probePrefix === '') $probePrefix = 'SAK-DIAG';
                $probeRef = $probePrefix . '-DIAG-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(8)));
                $probeResult = cgoApiRequest('order_status', 'GET', ['external_ref' => $probeRef], cgoOrderStatusTimeoutSeconds());
                $orderStatusTestData['synthetic_missing_external_ref'] = $makeStatusDiagnostic($probeResult, 'external_ref', $probeRef);
                $orderStatusTestData['synthetic_missing_external_ref']['query_external_ref'] = $probeRef;
            } catch (Throwable $e) {
                $orderStatusTestData['synthetic_missing_external_ref'] = [
                    'mode' => 'external_ref', 'ok' => false, 'http_code' => 0,
                    'transport_error' => false, 'curl_errno' => 0,
                    'error' => 'Unable to generate diagnostic reference: ' . $e->getMessage(),
                    'provider_error_code' => '', 'provider_status' => '', 'provider_final' => null,
                    'echoed_external_ref' => '', 'supplier_order_id' => '', 'key_count' => 0,
                    'nonfinal_not_found' => false, 'direct_refund_allowed' => false, 'strict_not_found' => false,
                    'primary_ip' => '', 'cf_ray' => '', 'request_id' => '',
                    'namelookup_time_ms' => 0, 'connect_time_ms' => 0, 'appconnect_time_ms' => 0,
                    'starttransfer_time_ms' => 0, 'total_time_ms' => 0,
                ];
            }
        }

        $orderStatusTestData['_classifier_self_test'] = cgoOrderStatusClassifierSelfTest();
        $orderStatusTestData['_meta'] = [
            'local_order_id' => $localOrderId,
            'external_ref' => $externalRef,
            'supplier_order_id' => $supplierOrderId,
        ];
        if (!isset($orderStatusTestData['external_ref']) && !isset($orderStatusTestData['order_id']) && !isset($orderStatusTestData['synthetic_missing_external_ref'])) {
            $error = $t('ไม่พบ external_ref หรือ supplier order_id สำหรับทดสอบ', 'No external_ref or supplier order ID was available for the test.');
        } else {
            $message = $t('ทดสอบ order_status แบบ Read-only แล้ว ไม่มีการสร้างคำสั่งซื้อหรือหักเงินเพิ่ม', 'Read-only order_status test completed. No order was created and no balance was charged.');
        }
    } elseif ($action === 'test_webhook_route') {
        $routeStatus = cgoConfigurationStatus();
        $hubUrl = trim((string) ($routeStatus['webhook_public_url'] ?? ''));
        $sitePrefix = trim((string) ($routeStatus['site_prefix'] ?? ''));
        if ($hubUrl === '' || $sitePrefix === '' || empty($routeStatus['webhook_ready'])) {
            $error = $t('การตั้งค่า Webhook หรือรหัสเว็บไซต์ยังไม่พร้อม', 'Webhook configuration or website identity is not ready.');
        } else {
            try {
                $date = (new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok')))->format('Ymd');
                $eventId = 'admin-preflight-' . strtolower(str_replace('-', '', $sitePrefix)) . '-' . bin2hex(random_bytes(12));
                $externalRef = $sitePrefix . '-' . $date . '-' . strtoupper(bin2hex(random_bytes(8)));
                $rawBody = json_encode([
                    'external_ref' => $externalRef,
                    'test' => true,
                    'source' => 'admin_webhook_route_preflight',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                if (!is_string($rawBody)) throw new RuntimeException('Unable to encode webhook preflight payload');
                $routeResult = cgoForwardWebhook($hubUrl, 'webhook.test', $eventId, (string) time(), '', $rawBody);
                $routeHttp = (int) ($routeResult['status'] ?? 0);
                if (!empty($routeResult['success']) && $routeHttp >= 200 && $routeHttp < 300) {
                    $message = $t(
                        'ทดสอบ Webhook ครบเส้นทางสำเร็จ: เว็บไซต์นี้ส่งไป Hub และถูกส่งกลับมาที่ฐานข้อมูลตาม prefix แล้ว โดยไม่มีการสร้างคำสั่งซื้อจริง',
                        'End-to-end webhook routing passed. This site reached the hub and was routed back to the database selected by its prefix without creating a real order.'
                    );
                } else {
                    $error = $t('ทดสอบเส้นทาง Webhook ไม่ผ่าน: ', 'Webhook route preflight failed: ')
                        . (string) ($routeResult['message'] ?? ('HTTP ' . $routeHttp));
                }
            } catch (Throwable $e) {
                error_log('CGO webhook route preflight failed: ' . $e->getMessage());
                $error = $t('ไม่สามารถสร้างคำขอทดสอบ Webhook ได้', 'Unable to create the webhook route preflight request.');
            }
        }
    } elseif ($action === 'update_product') {
        $id = isset($_POST['product_id']) && is_numeric($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
        $userPrice = isset($_POST['user_price']) && is_numeric($_POST['user_price']) ? (float) $_POST['user_price'] : -1;
        $resellerPrice = isset($_POST['reseller_price']) && is_numeric($_POST['reseller_price']) ? (float) $_POST['reseller_price'] : -1;
        $enabled = isset($_POST['enabled']) && $_POST['enabled'] === '1';
        $automaticMode = isset($_POST['automatic_price_mode']) && $_POST['automatic_price_mode'] === '1';
        $saveResult = cgoSaveProductPricing($id, $userPrice, $resellerPrice, $enabled, $automaticMode);
        if (empty($saveResult['success'])) {
            $error = (string) ($saveResult['message'] ?? $t('บันทึกราคาสินค้าไม่สำเร็จ', 'Unable to save product pricing.'));
            if (isset($saveResult['cost_base']) && is_numeric($saveResult['cost_base'])) {
                $error .= $t(' ต้นทุนปัจจุบันคือ ', ' Current supplier cost is ') . number_format((float) $saveResult['cost_base'], 2);
            }
        } else {
            $link = cgoGetCatalogLinkByProduct($id);
            if ($link) cgoApplyLinkedCatalogueSync($id);
            $message = !empty($saveResult['automatic_mode'])
                ? $t('บันทึกแล้ว สินค้านี้จะใช้ราคาอัตโนมัติจากมาร์กอัปในการซิงค์ครั้งต่อไป', 'Saved. This product will use automatic markup pricing on the next sync.')
                : $t('บันทึกราคาแบบกำหนดเองแล้ว การซิงค์จะรักษาราคานี้ไว้ แต่ถ้าต้นทุนใหม่สูงกว่า ระบบจะยกราคาขายขึ้นเท่าต้นทุนอัตโนมัติ', 'Manual prices saved. Sync preserves them, but automatically raises any selling price that falls below the new supplier cost.');
        }
    } elseif ($action === 'publish_catalog') {
        $cgoProductId = isset($_POST['product_id']) && is_numeric($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
        $mode = isset($_POST['catalog_mode']) && $_POST['catalog_mode'] === 'create' ? 'create' : 'existing';
        $localProductId = isset($_POST['local_product_id']) && is_numeric($_POST['local_product_id']) ? (int) $_POST['local_product_id'] : 0;
        $localVariantId = isset($_POST['local_variant_id']) && is_numeric($_POST['local_variant_id']) ? (int) $_POST['local_variant_id'] : 0;
        $categories = cgoNormalizeCategoryInput($_POST['categories'] ?? '');
        $imageUpload = cgoHandleAdminProductImageUpload('catalog_image');
        $manualImage = (string) ($imageUpload['path'] ?? '');
        $syncDetails = isset($_POST['sync_details']) && $_POST['sync_details'] === '1';
        $syncPrice = isset($_POST['sync_price']) && $_POST['sync_price'] === '1';
        $apiFallback = isset($_POST['api_fallback_enabled']) && $_POST['api_fallback_enabled'] === '1';
        $initialUserPrice = isset($_POST['initial_user_price']) && is_numeric($_POST['initial_user_price'])
            ? (float) $_POST['initial_user_price'] : null;
        $initialResellerPrice = isset($_POST['initial_reseller_price']) && is_numeric($_POST['initial_reseller_price'])
            ? (float) $_POST['initial_reseller_price'] : null;
        if ($mode === 'create') {
            // A newly created main product cannot reuse a variant that belongs to
            // another product. Ignore stale/hidden select values from the browser.
            $localProductId = 0;
            $localVariantId = 0;
        }
        if ((string) ($imageUpload['error'] ?? '') !== '') {
            $error = $t('อัปโหลดรูปสินค้าไม่สำเร็จ: ', 'Product image upload failed: ') . (string) $imageUpload['error'];
        } elseif ($mode === 'existing' && $localProductId < 1) {
            if ($manualImage !== '') @unlink(__DIR__ . '/../' . $manualImage);
            $error = $t('กรุณาเลือกสินค้าหลักที่จะเชื่อม', 'Select the local product to link.');
        } else {
            $result = cgoPublishCatalogProduct($cgoProductId, $mode, $localProductId, $localVariantId, $categories, $manualImage, $syncDetails, $syncPrice, $apiFallback, $initialUserPrice, $initialResellerPrice);
            if (!empty($result['success'])) {
                $message = $t('รวมสินค้า API เข้ากับหน้าหลักแล้ว', 'The API product is now connected to the main catalogue.');
            } else {
                if ($manualImage !== '') @unlink(__DIR__ . '/../' . $manualImage);
                $error = (string) ($result['message'] ?? $t('รวมสินค้าไม่สำเร็จ', 'Unable to connect the product.'));
            }
        }
    } elseif ($action === 'unlink_catalog') {
        $cgoProductId = isset($_POST['product_id']) && is_numeric($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
        if (!cgoUnlinkCatalogProduct($cgoProductId)) {
            $error = $t('ยกเลิกการเชื่อมสินค้าไม่สำเร็จ', 'Unable to unlink the product.');
        } else {
            $message = $t('ยกเลิกการเชื่อมแล้ว สินค้าหลักและคีย์เดิมยังคงอยู่', 'The mapping was removed. The local product and existing keys were kept.');
        }
    } elseif ($action === 'reconcile_order') {
        $orderId = isset($_POST['order_id']) && is_numeric($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
        $result = cgoReconcileOrder($orderId);
        if (!empty($result['success'])) {
            $message = (string) ($result['message'] ?? $t('ตรวจสอบคำสั่งซื้อแล้ว', 'Order checked.'));
        } else {
            $error = (string) ($result['message'] ?? $t('ตรวจสอบคำสั่งซื้อไม่สำเร็จ', 'Unable to check order.'));
        }
    }

    $redirectActions = ['save_markup', 'sync_products', 'sync_stock', 'update_product', 'publish_catalog', 'unlink_catalog', 'reconcile_order'];
    if (in_array($action, $redirectActions, true)) {
        cgoAdminFinishPost($message, $error, $syncPriceCorrections);
    }
}

$config = cgoConfig();
$configStatus = cgoConfigurationStatus();
$markups = cgoGetMarkupSettings();
$inventoryState = cgoInventoryState(true);
$inventoryTtlSeconds = cgoInventoryCacheTtlSeconds();
$inventoryAgeSeconds = is_int($inventoryState['age_seconds'] ?? null)
    ? max(0, (int) $inventoryState['age_seconds'])
    : $inventoryTtlSeconds;
$inventoryInitialDelayMs = $inventoryAgeSeconds >= $inventoryTtlSeconds
    ? 0
    : max(1000, ($inventoryTtlSeconds - $inventoryAgeSeconds) * 1000);
$inventoryCsrfToken = getCsrfToken();
$recentOrderApiAttempts = cgoGetRecentOrderApiAttempts(30);
$catalogLinks = cgoGetCatalogLinks();
$allProducts = cgoGetProducts(false);

$searchQuery = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
$statusFilter = isset($_GET['status']) && is_string($_GET['status']) ? trim(strtolower($_GET['status'])) : '';
$linkFilter = isset($_GET['link']) && is_string($_GET['link']) ? trim(strtolower($_GET['link'])) : '';
$categoryFilter = isset($_GET['category']) && is_string($_GET['category']) ? trim($_GET['category']) : '';
$platformFilter = isset($_GET['platform']) && is_string($_GET['platform']) ? trim(strtolower($_GET['platform'])) : '';
$stockFilter = isset($_GET['stock']) && is_string($_GET['stock']) ? trim(strtolower($_GET['stock'])) : '';
$enabledFilter = isset($_GET['enabled']) && is_string($_GET['enabled']) ? trim(strtolower($_GET['enabled'])) : '';
$pricingFilter = isset($_GET['pricing']) && is_string($_GET['pricing']) ? trim(strtolower($_GET['pricing'])) : '';
$warningFilter = isset($_GET['warning']) && is_string($_GET['warning']) ? trim(strtolower($_GET['warning'])) : '';
$sortFilter = isset($_GET['sort']) && is_string($_GET['sort']) ? trim(strtolower($_GET['sort'])) : 'name_asc';
$allowedSorts = ['name_asc', 'name_desc', 'cost_asc', 'cost_desc', 'user_asc', 'user_desc', 'reseller_asc', 'reseller_desc', 'stock_desc', 'stock_asc', 'newest'];
if (!in_array($sortFilter, $allowedSorts, true)) $sortFilter = 'name_asc';
$allowedPerPage = [12, 24, 48];
$perPage = isset($_GET['per_page']) && is_numeric($_GET['per_page']) ? (int) $_GET['per_page'] : 24;
if (!in_array($perPage, $allowedPerPage, true)) $perPage = 24;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int) $_GET['page']) : 1;

$lowerText = static function (string $value): string {
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
};
$needle = $lowerText($searchQuery);
$availableStatuses = [];
$availableCategories = [];
$availablePlatforms = [];
foreach ($allProducts as $filterProduct) {
    $statusValue = trim(strtolower((string) ($filterProduct['remote_status'] ?? '')));
    if ($statusValue !== '') $availableStatuses[$statusValue] = true;
    $categoryValue = trim((string) ($filterProduct['category'] ?? ''));
    if ($categoryValue !== '') $availableCategories[$categoryValue] = true;
    $platformValue = trim(strtolower((string) ($filterProduct['platform'] ?? '')));
    if ($platformValue !== '') $availablePlatforms[$platformValue] = true;
}
ksort($availableStatuses, SORT_NATURAL | SORT_FLAG_CASE);
ksort($availableCategories, SORT_NATURAL | SORT_FLAG_CASE);
ksort($availablePlatforms, SORT_NATURAL | SORT_FLAG_CASE);

$filteredProducts = array_values(array_filter($allProducts, static function (array $product) use (
    $needle,
    $statusFilter,
    $linkFilter,
    $categoryFilter,
    $platformFilter,
    $stockFilter,
    $enabledFilter,
    $pricingFilter,
    $warningFilter,
    $catalogLinks,
    $lowerText
): bool {
    if ($needle !== '') {
        $haystack = implode("\n", [
            (string) ($product['remote_product_id'] ?? ''),
            (string) ($product['brand'] ?? ''),
            (string) ($product['name'] ?? ''),
            (string) ($product['duration'] ?? ''),
            (string) ($product['platform'] ?? ''),
            (string) ($product['category'] ?? ''),
            (string) ($product['description'] ?? ''),
        ]);
        if (strpos($lowerText($haystack), $needle) === false) return false;
    }
    if ($statusFilter !== '' && strtolower((string) ($product['remote_status'] ?? '')) !== $statusFilter) return false;
    if ($categoryFilter !== '' && $lowerText(trim((string) ($product['category'] ?? ''))) !== $lowerText($categoryFilter)) return false;
    if ($platformFilter !== '' && strtolower(trim((string) ($product['platform'] ?? ''))) !== $platformFilter) return false;

    $stock = $product['remote_stock'] ?? null;
    if ($stockFilter === 'in' && (!is_numeric($stock) || (int) $stock <= 0)) return false;
    if ($stockFilter === 'out' && (!is_numeric($stock) || (int) $stock > 0)) return false;
    if ($stockFilter === 'unknown' && $stock !== null && $stock !== '') return false;

    $enabled = (int) ($product['enabled'] ?? 0) === 1;
    if ($enabledFilter === 'enabled' && !$enabled) return false;
    if ($enabledFilter === 'disabled' && $enabled) return false;

    $manual = (int) ($product['manual_price_saved'] ?? 0) === 1;
    if ($pricingFilter === 'manual' && !$manual) return false;
    if ($pricingFilter === 'automatic' && $manual) return false;

    $belowCost = (float) ($product['user_price_base'] ?? 0) + 0.00001 < (float) ($product['cost_base'] ?? 0)
        || (float) ($product['reseller_price_base'] ?? 0) + 0.00001 < (float) ($product['cost_base'] ?? 0)
        || (string) ($product['price_warning_code'] ?? '') === 'saved_price_below_cost';
    if ($warningFilter === 'below_cost' && !$belowCost) return false;
    if ($warningFilter === 'safe' && $belowCost) return false;

    $isLinked = isset($catalogLinks[(int) ($product['id'] ?? 0)]);
    if ($linkFilter === 'linked' && !$isLinked) return false;
    if ($linkFilter === 'unlinked' && $isLinked) return false;
    return true;
}));

usort($filteredProducts, static function (array $a, array $b) use ($sortFilter, $lowerText): int {
    $idCompare = (int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0);
    $nameA = $lowerText(trim((string) (($a['brand'] ?? '') . ' ' . ($a['name'] ?? '') . ' ' . ($a['duration'] ?? ''))));
    $nameB = $lowerText(trim((string) (($b['brand'] ?? '') . ' ' . ($b['name'] ?? '') . ' ' . ($b['duration'] ?? ''))));
    switch ($sortFilter) {
        case 'name_desc': $result = strnatcmp($nameB, $nameA); break;
        case 'cost_asc': $result = (float) ($a['cost_base'] ?? 0) <=> (float) ($b['cost_base'] ?? 0); break;
        case 'cost_desc': $result = (float) ($b['cost_base'] ?? 0) <=> (float) ($a['cost_base'] ?? 0); break;
        case 'user_asc': $result = (float) ($a['user_price_base'] ?? 0) <=> (float) ($b['user_price_base'] ?? 0); break;
        case 'user_desc': $result = (float) ($b['user_price_base'] ?? 0) <=> (float) ($a['user_price_base'] ?? 0); break;
        case 'reseller_asc': $result = (float) ($a['reseller_price_base'] ?? 0) <=> (float) ($b['reseller_price_base'] ?? 0); break;
        case 'reseller_desc': $result = (float) ($b['reseller_price_base'] ?? 0) <=> (float) ($a['reseller_price_base'] ?? 0); break;
        case 'stock_desc': $result = (int) ($b['remote_stock'] ?? -1) <=> (int) ($a['remote_stock'] ?? -1); break;
        case 'stock_asc': $result = (int) ($a['remote_stock'] ?? -1) <=> (int) ($b['remote_stock'] ?? -1); break;
        case 'newest': $result = (int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0); break;
        case 'name_asc':
        default: $result = strnatcmp($nameA, $nameB); break;
    }
    return $result !== 0 ? $result : $idCompare;
});

$totalProducts = count($allProducts);
$filteredProductCount = count($filteredProducts);
$totalPages = max(1, (int) ceil($filteredProductCount / $perPage));
if ($page > $totalPages) $page = $totalPages;
$products = array_slice($filteredProducts, ($page - 1) * $perPage, $perPage);

$catalogQueryParams = static function (array $overrides = []) use (
    $searchQuery,
    $statusFilter,
    $linkFilter,
    $categoryFilter,
    $platformFilter,
    $stockFilter,
    $enabledFilter,
    $pricingFilter,
    $warningFilter,
    $sortFilter,
    $perPage
): string {
    $params = [
        'q' => $searchQuery,
        'status' => $statusFilter,
        'link' => $linkFilter,
        'category' => $categoryFilter,
        'platform' => $platformFilter,
        'stock' => $stockFilter,
        'enabled' => $enabledFilter,
        'pricing' => $pricingFilter,
        'warning' => $warningFilter,
        'sort' => $sortFilter,
        'per_page' => $perPage,
    ];
    foreach ($overrides as $key => $value) $params[$key] = $value;
    $params = array_filter($params, static function ($value): bool {
        return $value !== '' && $value !== null;
    });
    return http_build_query($params, '', '&', PHP_QUERY_RFC3986);
};

$localProducts = getProducts('all');
$localProductMap = [];
$localProductOptions = [];
foreach ($localProducts as $localProduct) {
    $localId = (int) ($localProduct['id'] ?? 0);
    if ($localId < 1) continue;
    $localProductMap[$localId] = $localProduct;
    $categories = isset($localProduct['categories']) && is_array($localProduct['categories'])
        ? array_values(array_filter(array_map('strval', $localProduct['categories']), static function (string $value): bool { return trim($value) !== ''; }))
        : [];
    if ($categories === [] && trim((string) ($localProduct['category'] ?? '')) !== '') {
        $categories[] = trim((string) $localProduct['category']);
    }
    $localProductOptions[] = [
        'id' => $localId,
        'name' => trim((string) ($localProduct['name'] ?? '')),
        'categories' => $categories,
        'status' => trim((string) ($localProduct['status'] ?? '')),
    ];
}
$localVariants = [];
ensureProductArchiveTables();
$localVariantResult = $conn->query("SELECT pv.id, pv.product_id, pv.duration, pv.status, p.name AS product_name
    FROM product_variants pv
    JOIN products p ON p.id = pv.product_id
    LEFT JOIN product_variant_admin_archives va ON va.variant_id = pv.id
    LEFT JOIN product_admin_archives pa ON pa.product_id = p.id
    WHERE va.variant_id IS NULL AND pa.product_id IS NULL
    ORDER BY p.name ASC, pv.duration ASC, pv.id ASC");
if ($localVariantResult) $localVariants = $localVariantResult->fetch_all(MYSQLI_ASSOC);
$localVariantOptions = [];
foreach ($localVariants as $localVariant) {
    $variantId = (int) ($localVariant['id'] ?? 0);
    $variantProductId = (int) ($localVariant['product_id'] ?? 0);
    if ($variantId < 1 || $variantProductId < 1) continue;
    $localVariantOptions[] = [
        'id' => $variantId,
        'product_id' => $variantProductId,
        'name' => trim((string) ($localVariant['product_name'] ?? '')),
        'duration' => trim((string) ($localVariant['duration'] ?? '')),
        'status' => trim((string) ($localVariant['status'] ?? '')),
    ];
}
$existingCategoryRows = getAllCategoriesWithLinks();
$existingCategoryNames = [];
foreach ($existingCategoryRows as $categoryRow) {
    $categoryName = trim((string) ($categoryRow['name'] ?? ''));
    if ($categoryName !== '') $existingCategoryNames[] = $categoryName;
}
$unresolvedImageCount = 0;
foreach ($allProducts as $imageProduct) {
    if (trim((string) ($imageProduct['image_path'] ?? '')) !== '' && cgoProductImageUrl($imageProduct) === '') {
        $unresolvedImageCount++;
    }
}
$orders = cgoGetAllOrders(100);
$baseCurrency = cgoGetBaseCurrency();
$csrf = csrfField();
$formatBase = static function ($amount) use ($baseCurrency): string {
    if (!is_numeric($amount) || $baseCurrency === null) return 'N/A';
    $symbol = $baseCurrency === 'THB' ? '฿' : '$';
    return $symbol . number_format((float) $amount, 2) . ' ' . $baseCurrency;
};
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(getAppLang(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($t('CHEATGAME Reseller API', 'CHEATGAME Reseller API'), ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        html{scroll-behavior:smooth}
        [id]{scroll-margin-top:88px}
        .glass{background:rgba(255,255,255,.05);backdrop-filter:blur(16px);border:1px solid rgba(255,255,255,.08)}
        .cgo-admin-description{white-space:pre-wrap;overflow-wrap:anywhere;line-height:1.55}
        .cgo-mobile-nav{display:none}
        .cgo-product-row.cgo-highlight{animation:cgoFlash .15s ease-out}
        @keyframes cgoFlash{0%,35%{box-shadow:0 0 0 3px rgba(139,92,246,.8)}100%{box-shadow:none}}
        @media (max-width:767px){
            body{padding-bottom:76px}
            main{padding-left:10px!important;padding-right:10px!important}
            .cgo-mobile-nav{position:fixed;display:grid;grid-template-columns:repeat(3,1fr);left:10px;right:10px;bottom:10px;z-index:70;padding:7px;border-radius:16px;background:rgba(18,18,23,.96);border:1px solid rgba(255,255,255,.14);box-shadow:0 14px 40px rgba(0,0,0,.45);backdrop-filter:blur(18px)}
            .cgo-mobile-nav a{display:flex;align-items:center;justify-content:center;gap:5px;min-height:44px;border-radius:11px;font-size:12px;color:#e5e7eb}
            .cgo-mobile-nav a:active{background:rgba(139,92,246,.25)}
            .cgo-tools-summary{position:sticky;top:72px;z-index:20}
            .cgo-products-table{display:block!important;min-width:0!important;width:100%!important}
            .cgo-products-table thead{display:none}
            .cgo-products-table tbody{display:grid;grid-template-columns:minmax(0,1fr);gap:12px;padding:10px;background:transparent}
            .cgo-products-table tr.cgo-product-row{display:grid;grid-template-columns:76px minmax(0,1fr);gap:8px;padding:10px;border:1px solid rgba(255,255,255,.1);border-radius:14px;background:rgba(255,255,255,.025);overflow:hidden}
            .cgo-products-table td{padding:0!important;min-width:0}
            .cgo-col-id{grid-column:1/-1;color:#9ca3af;padding-bottom:7px!important;border-bottom:1px solid rgba(255,255,255,.08)}
            .cgo-col-image{grid-column:1;width:76px!important}
            .cgo-col-info{grid-column:2;max-width:none!important}
            .cgo-col-usd,.cgo-col-idr,.cgo-col-cost{grid-column:span 1;padding:8px!important;border-radius:9px;background:rgba(0,0,0,.2);text-align:left!important;font-size:12px}
            .cgo-col-usd{grid-column:1}
            .cgo-col-idr{grid-column:2}
            .cgo-col-cost{grid-column:1/-1}
            .cgo-col-usd:before,.cgo-col-idr:before,.cgo-col-cost:before{content:attr(data-label);display:block;margin-bottom:2px;color:#6b7280;font-size:10px;text-transform:uppercase}
            .cgo-col-pricing{grid-column:1/-1;padding-top:4px!important}
            .cgo-price-form{grid-template-columns:minmax(0,1fr) minmax(0,1fr)!important}
            .cgo-price-actions{grid-column:1/-1}
            .cgo-product-image{width:72px!important;height:56px!important}
            .cgo-product-image-placeholder{width:72px!important;height:56px!important}
            .cgo-filter-shell{position:sticky;top:72px;z-index:25;margin:-1px -1px 0;background:rgba(13,13,16,.96);backdrop-filter:blur(14px)}
            .cgo-filter-shell input,.cgo-filter-shell select{min-height:44px}
            .cgo-catalog-details[open]{grid-column:1/-1}
            .cgo-catalog-details summary{min-height:42px;display:flex;align-items:center}
            .cgo-orders-table{display:block!important;min-width:0!important;width:100%!important}
            .cgo-orders-table thead{display:none}
            .cgo-orders-table tbody{display:grid;gap:10px;padding:10px}
            .cgo-orders-table tr.cgo-order-row{display:grid;grid-template-columns:1fr 1fr;gap:8px;padding:10px;border:1px solid rgba(255,255,255,.1);border-radius:14px;background:rgba(255,255,255,.025)}
            .cgo-orders-table td{display:block;padding:7px!important;text-align:left!important;border-radius:8px;background:rgba(0,0,0,.16);min-width:0}
            .cgo-orders-table td:before{content:attr(data-label);display:block;margin-bottom:2px;color:#6b7280;font-size:10px}
            .cgo-orders-table td:nth-child(2),.cgo-orders-table td:nth-child(3),.cgo-orders-table td:nth-child(4),.cgo-orders-table td:nth-child(6){grid-column:1/-1}
            .cgo-local-picker-grid{grid-template-columns:1fr!important}
        }
    </style>
</head>
<body class="bg-[#0d0d10] text-gray-100 min-h-screen">
<?php include __DIR__ . '/nav.php'; ?>
<nav class="cgo-mobile-nav" aria-label="<?php echo htmlspecialchars($t('เมนูลัด CHEATGAME', 'CHEATGAME shortcuts'), ENT_QUOTES, 'UTF-8'); ?>">
    <a href="#cgo-products"><i class="bi bi-box-seam"></i><?php echo $t('สินค้า', 'Products'); ?></a>
    <a href="#cgo-product-filters"><i class="bi bi-funnel"></i><?php echo $t('ตัวกรอง', 'Filters'); ?></a>
    <a href="#cgo-admin-tools"><i class="bi bi-sliders"></i><?php echo $t('ตั้งค่า', 'Tools'); ?></a>
</nav>
<main class="p-4 md:p-6 space-y-5 max-w-[1500px] mx-auto">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-white flex items-center gap-2"><i class="bi bi-cloud-arrow-down text-violet-400"></i>CHEATGAME Reseller API</h1>
            <p class="text-sm text-gray-400 mt-1"><?php echo htmlspecialchars($t('คีย์ API อยู่ฝั่งเซิร์ฟเวอร์เท่านั้น หน้าเว็บลูกค้าไม่เห็นคีย์', 'The API key stays on the server and is never exposed to customers.'), ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
        <div class="text-sm px-3 py-2 rounded-lg <?php echo $configStatus['ready'] ? 'bg-green-500/10 text-green-300 border border-green-500/30' : 'bg-red-500/10 text-red-300 border border-red-500/30'; ?>">
            <?php echo $configStatus['ready'] ? $t('พร้อมเชื่อมต่อ', 'Configuration ready') : $t('การตั้งค่ายังไม่ครบ', 'Configuration incomplete'); ?>
        </div>
    </div>

    <?php if ($message !== ''): ?><div class="glass border-green-500/30 text-green-200 rounded-xl p-4"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="glass border-red-500/30 text-red-200 rounded-xl p-4"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <?php if (empty($configStatus['site_identity_ready'])): ?>
        <div class="glass border-red-500/30 bg-red-500/10 text-red-100 rounded-xl p-4">
            <?php echo $t('ระบบไม่สามารถระบุรหัสเว็บไซต์จากฐานข้อมูลที่เชื่อมอยู่ได้ จึงปิดการสั่งซื้อ CHEATGAME อัตโนมัติ', 'The system could not identify this website from the connected database, so CHEATGAME ordering is disabled automatically.'); ?>
        </div>
    <?php elseif (empty($configStatus['live_order_enabled'])): ?>
        <div class="glass border-amber-500/30 bg-amber-500/10 text-amber-100 rounded-xl p-4">
            <span class="font-semibold"><?php echo htmlspecialchars((string) $configStatus['site_prefix'], ENT_QUOTES, 'UTF-8'); ?>:</span>
            <?php echo $t('ปิดคำสั่งซื้อจริงอยู่ สามารถทดสอบ products, balance, exchange_rate และซิงก์สินค้าได้ แต่ระบบจะไม่ส่ง action=order', 'Live ordering is disabled. Products, balance, exchange_rate, and product sync remain available, but action=order will not be sent.'); ?>
        </div>
    <?php endif; ?>
    <?php if ($syncPriceWarnings): ?>
        <div class="glass border-amber-500/30 bg-amber-500/10 text-amber-100 rounded-xl p-4">
            <div class="font-semibold"><?php echo $t('เก็บราคาที่บันทึกไว้แล้ว แต่ระงับ API fallback บางรายการเพราะต้นทุนใหม่สูงกว่าราคาขาย', 'Saved prices were preserved, but API fallback was suspended for items whose new supplier cost exceeds the selling price.'); ?></div>
            <div class="mt-2 grid grid-cols-1 md:grid-cols-2 gap-2 text-sm">
                <?php foreach (array_slice($syncPriceWarnings, 0, 20) as $warning): ?>
                    <div class="rounded border border-amber-500/20 bg-black/20 p-2">
                        <div class="font-medium"><?php echo htmlspecialchars((string) ($warning['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?> <span class="font-mono text-xs text-amber-300">#<?php echo htmlspecialchars((string) ($warning['remote_product_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span></div>
                        <div class="text-xs mt-1"><?php echo $t('ต้นทุนใหม่', 'New cost'); ?>: <?php echo htmlspecialchars($formatBase($warning['cost_base'] ?? null), ENT_QUOTES, 'UTF-8'); ?> · <?php echo $t('ราคาลูกค้าเดิม', 'Saved customer'); ?>: <?php echo htmlspecialchars($formatBase($warning['saved_user_price_base'] ?? null), ENT_QUOTES, 'UTF-8'); ?> · <?php echo $t('ราคาตัวแทนเดิม', 'Saved reseller'); ?>: <?php echo htmlspecialchars($formatBase($warning['saved_reseller_price_base'] ?? null), ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if (count($syncPriceWarnings) > 20): ?><div class="text-xs mt-2"><?php echo $t('แสดง 20 รายการแรกจากทั้งหมด ', 'Showing the first 20 of '); ?><?php echo count($syncPriceWarnings); ?></div><?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if ($syncPriceCorrections): ?>
        <div class="glass border-emerald-500/30 bg-emerald-500/10 text-emerald-100 rounded-xl p-4">
            <div class="font-semibold"><?php echo $t('ระบบปรับราคาที่ต่ำกว่าต้นทุนขึ้นเท่าต้นทุนให้อัตโนมัติแล้ว', 'Prices below supplier cost were raised to supplier cost automatically.'); ?></div>
            <div class="mt-2 grid grid-cols-1 md:grid-cols-2 gap-2 text-sm">
                <?php foreach (array_slice($syncPriceCorrections, 0, 20) as $correction): ?>
                    <div class="rounded border border-emerald-500/20 bg-black/20 p-2">
                        <div class="font-medium"><?php echo htmlspecialchars((string) ($correction['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?> <span class="font-mono text-xs text-emerald-300">#<?php echo htmlspecialchars((string) ($correction['remote_product_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span></div>
                        <div class="text-xs mt-1"><?php echo $t('ต้นทุน/ราคาใหม่', 'Cost/new price'); ?>: <?php echo htmlspecialchars($formatBase($correction['cost_base'] ?? null), ENT_QUOTES, 'UTF-8'); ?> · <?php echo $t('เดิม ลูกค้า/ตัวแทน', 'Old customer/reseller'); ?>: <?php echo htmlspecialchars($formatBase($correction['old_user_price_base'] ?? null), ENT_QUOTES, 'UTF-8'); ?> / <?php echo htmlspecialchars($formatBase($correction['old_reseller_price_base'] ?? null), ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if (count($syncPriceCorrections) > 20): ?><div class="text-xs mt-2"><?php echo $t('แสดง 20 รายการแรกจากทั้งหมด ', 'Showing the first 20 of '); ?><?php echo count($syncPriceCorrections); ?></div><?php endif; ?>
        </div>
    <?php endif; ?>

    <datalist id="cgo-category-list">
        <?php foreach ($existingCategoryNames as $categoryOption): ?><option value="<?php echo htmlspecialchars($categoryOption, ENT_QUOTES, 'UTF-8'); ?>"><?php endforeach; ?>
    </datalist>

    <section id="cgo-products" data-instant-panel class="glass rounded-xl overflow-hidden">
        <div class="p-3 md:p-5 border-b border-white/10 space-y-4">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
                <h2 class="font-semibold text-white">
                    <?php echo $t('สินค้าจาก CHEATGAME', 'CHEATGAME products'); ?>
                    <span class="text-gray-500 text-sm">(<?php echo $filteredProductCount; ?> / <?php echo $totalProducts; ?>)</span>
                </h2>
                <div class="text-xs <?php echo $unresolvedImageCount > 0 ? 'text-amber-300' : 'text-green-300'; ?>">
                    <?php if ($unresolvedImageCount > 0): ?>
                        <?php echo $t('ยังมีข้อมูลรูปที่แปลงเป็น URL ไม่ได้: ', 'Some image values still cannot be resolved to a URL: '); ?><?php echo $unresolvedImageCount; ?>
                    <?php else: ?>
                        <?php echo $t('ระบบรูปภาพพร้อมใช้งาน รูปในหน้านี้โหลดผ่านแคชของเว็บไซต์เรา', 'Image data is ready. This page loads supplier images through the local cache.'); ?>
                    <?php endif; ?>
                </div>
            </div>

            <form method="GET" action="cheatgame.php#cgo-products" id="cgo-product-filters" data-instant-submit-only class="cgo-filter-shell rounded-xl border border-white/10 p-3 space-y-3">
                <div class="grid grid-cols-1 md:grid-cols-[minmax(0,1fr)_auto_auto] gap-2 items-end">
                    <label>
                        <span class="text-xs text-gray-400"><?php echo $t('ค้นหาชื่อ แบรนด์ รหัส รุ่น ระยะเวลา หรือรายละเอียด', 'Search name, brand, ID, variant, duration, or description'); ?></span>
                        <input type="search" name="q" value="<?php echo htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>" placeholder="<?php echo htmlspecialchars($t('เช่น AORUS, KEY 1 DAY, 114', 'For example: AORUS, KEY 1 DAY, 114'), ENT_QUOTES, 'UTF-8'); ?>" class="mt-1 w-full bg-black/20 border border-white/10 rounded-lg px-3 py-2">
                    </label>
                    <button type="submit" class="rounded-lg bg-blue-500 hover:bg-blue-600 px-5 py-2 text-white min-h-11"><i class="bi bi-search mr-1"></i><?php echo $t('ค้นหา', 'Search'); ?></button>
                    <a href="cheatgame.php#cgo-products" class="rounded-lg bg-white/10 hover:bg-white/15 px-4 py-2 text-center text-gray-200 min-h-11 flex items-center justify-center"><?php echo $t('ล้าง', 'Clear'); ?></a>
                </div>
                <p class="text-[11px] text-gray-500"><i class="bi bi-keyboard mr-1"></i><?php echo $t('พิมพ์คำค้นหาให้ครบ แล้วกดค้นหาหรือ Enter ระบบจะไม่ค้นหาทันทีจากตัวอักษรแรก', 'Finish typing, then press Search or Enter. The page will not search from the first character.'); ?></p>
                <details class="rounded-lg border border-white/10 bg-black/10" <?php echo ($statusFilter !== '' || $linkFilter !== '' || $categoryFilter !== '' || $platformFilter !== '' || $stockFilter !== '' || $enabledFilter !== '' || $pricingFilter !== '' || $warningFilter !== '' || $sortFilter !== 'name_asc' || $perPage !== 24) ? 'open' : ''; ?>>
                    <summary class="cursor-pointer px-3 py-2 text-sm text-violet-200"><i class="bi bi-funnel mr-1"></i><?php echo $t('ตัวกรองเพิ่มเติมและการเรียงลำดับ', 'More filters and sorting'); ?></summary>
                    <div class="border-t border-white/10 p-3 grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-2">
                        <label><span class="text-xs text-gray-400"><?php echo $t('หมวดหมู่ API', 'API category'); ?></span><select name="category" class="mt-1 w-full bg-[#17171c] border border-white/10 rounded-lg px-3 py-2"><option value=""><?php echo $t('ทุกหมวด', 'All categories'); ?></option><?php foreach (array_keys($availableCategories) as $categoryOption): ?><option value="<?php echo htmlspecialchars($categoryOption, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $categoryFilter === $categoryOption ? 'selected' : ''; ?>><?php echo htmlspecialchars($categoryOption, ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></label>
                        <label><span class="text-xs text-gray-400"><?php echo $t('แพลตฟอร์ม', 'Platform'); ?></span><select name="platform" class="mt-1 w-full bg-[#17171c] border border-white/10 rounded-lg px-3 py-2"><option value=""><?php echo $t('ทุกแพลตฟอร์ม', 'All platforms'); ?></option><?php foreach (array_keys($availablePlatforms) as $platformOption): ?><option value="<?php echo htmlspecialchars($platformOption, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $platformFilter === $platformOption ? 'selected' : ''; ?>><?php echo htmlspecialchars(strtoupper($platformOption), ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></label>
                        <label><span class="text-xs text-gray-400"><?php echo $t('สถานะ API', 'API status'); ?></span><select name="status" class="mt-1 w-full bg-[#17171c] border border-white/10 rounded-lg px-3 py-2"><option value=""><?php echo $t('ทุกสถานะ', 'All statuses'); ?></option><?php foreach (array_keys($availableStatuses) as $statusOption): ?><option value="<?php echo htmlspecialchars($statusOption, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $statusFilter === $statusOption ? 'selected' : ''; ?>><?php echo htmlspecialchars($statusOption, ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></label>
                        <label><span class="text-xs text-gray-400"><?php echo $t('สต็อก', 'Stock'); ?></span><select name="stock" class="mt-1 w-full bg-[#17171c] border border-white/10 rounded-lg px-3 py-2"><option value=""><?php echo $t('ทุกสต็อก', 'All stock'); ?></option><option value="in" <?php echo $stockFilter === 'in' ? 'selected' : ''; ?>><?php echo $t('มีสต็อก', 'In stock'); ?></option><option value="out" <?php echo $stockFilter === 'out' ? 'selected' : ''; ?>><?php echo $t('หมดสต็อก', 'Out of stock'); ?></option><option value="unknown" <?php echo $stockFilter === 'unknown' ? 'selected' : ''; ?>><?php echo $t('ไม่ทราบสต็อก', 'Unknown'); ?></option></select></label>
                        <label><span class="text-xs text-gray-400"><?php echo $t('การรวมหน้าหลัก', 'Main catalogue mapping'); ?></span><select name="link" class="mt-1 w-full bg-[#17171c] border border-white/10 rounded-lg px-3 py-2"><option value=""><?php echo $t('ทั้งหมด', 'All'); ?></option><option value="linked" <?php echo $linkFilter === 'linked' ? 'selected' : ''; ?>><?php echo $t('รวมแล้ว', 'Linked'); ?></option><option value="unlinked" <?php echo $linkFilter === 'unlinked' ? 'selected' : ''; ?>><?php echo $t('ยังไม่รวม', 'Unlinked'); ?></option></select></label>
                        <label><span class="text-xs text-gray-400"><?php echo $t('เปิดขาย', 'Selling status'); ?></span><select name="enabled" class="mt-1 w-full bg-[#17171c] border border-white/10 rounded-lg px-3 py-2"><option value=""><?php echo $t('ทั้งหมด', 'All'); ?></option><option value="enabled" <?php echo $enabledFilter === 'enabled' ? 'selected' : ''; ?>><?php echo $t('เปิดขาย', 'Enabled'); ?></option><option value="disabled" <?php echo $enabledFilter === 'disabled' ? 'selected' : ''; ?>><?php echo $t('ปิดขาย', 'Disabled'); ?></option></select></label>
                        <label><span class="text-xs text-gray-400"><?php echo $t('โหมดราคา', 'Pricing mode'); ?></span><select name="pricing" class="mt-1 w-full bg-[#17171c] border border-white/10 rounded-lg px-3 py-2"><option value=""><?php echo $t('ทั้งหมด', 'All'); ?></option><option value="automatic" <?php echo $pricingFilter === 'automatic' ? 'selected' : ''; ?>><?php echo $t('อัตโนมัติ', 'Automatic'); ?></option><option value="manual" <?php echo $pricingFilter === 'manual' ? 'selected' : ''; ?>><?php echo $t('กำหนดเอง', 'Manual'); ?></option></select></label>
                        <label><span class="text-xs text-gray-400"><?php echo $t('ความปลอดภัยราคา', 'Price safety'); ?></span><select name="warning" class="mt-1 w-full bg-[#17171c] border border-white/10 rounded-lg px-3 py-2"><option value=""><?php echo $t('ทั้งหมด', 'All'); ?></option><option value="safe" <?php echo $warningFilter === 'safe' ? 'selected' : ''; ?>><?php echo $t('ราคาไม่ต่ำกว่าทุน', 'At or above cost'); ?></option><option value="below_cost" <?php echo $warningFilter === 'below_cost' ? 'selected' : ''; ?>><?php echo $t('ราคาต่ำกว่าทุน', 'Below cost'); ?></option></select></label>
                        <label class="sm:col-span-2"><span class="text-xs text-gray-400"><?php echo $t('เรียงตาม', 'Sort by'); ?></span><select name="sort" class="mt-1 w-full bg-[#17171c] border border-white/10 rounded-lg px-3 py-2"><option value="name_asc" <?php echo $sortFilter === 'name_asc' ? 'selected' : ''; ?>><?php echo $t('ชื่อ A-Z', 'Name A-Z'); ?></option><option value="name_desc" <?php echo $sortFilter === 'name_desc' ? 'selected' : ''; ?>><?php echo $t('ชื่อ Z-A', 'Name Z-A'); ?></option><option value="cost_asc" <?php echo $sortFilter === 'cost_asc' ? 'selected' : ''; ?>><?php echo $t('ต้นทุนต่ำไปสูง', 'Cost low to high'); ?></option><option value="cost_desc" <?php echo $sortFilter === 'cost_desc' ? 'selected' : ''; ?>><?php echo $t('ต้นทุนสูงไปต่ำ', 'Cost high to low'); ?></option><option value="user_asc" <?php echo $sortFilter === 'user_asc' ? 'selected' : ''; ?>><?php echo $t('ราคาลูกค้าต่ำไปสูง', 'Customer price low to high'); ?></option><option value="user_desc" <?php echo $sortFilter === 'user_desc' ? 'selected' : ''; ?>><?php echo $t('ราคาลูกค้าสูงไปต่ำ', 'Customer price high to low'); ?></option><option value="reseller_asc" <?php echo $sortFilter === 'reseller_asc' ? 'selected' : ''; ?>><?php echo $t('ราคาตัวแทนต่ำไปสูง', 'Reseller price low to high'); ?></option><option value="reseller_desc" <?php echo $sortFilter === 'reseller_desc' ? 'selected' : ''; ?>><?php echo $t('ราคาตัวแทนสูงไปต่ำ', 'Reseller price high to low'); ?></option><option value="stock_desc" <?php echo $sortFilter === 'stock_desc' ? 'selected' : ''; ?>><?php echo $t('สต็อกมากไปน้อย', 'Stock high to low'); ?></option><option value="stock_asc" <?php echo $sortFilter === 'stock_asc' ? 'selected' : ''; ?>><?php echo $t('สต็อกน้อยไปมาก', 'Stock low to high'); ?></option><option value="newest" <?php echo $sortFilter === 'newest' ? 'selected' : ''; ?>><?php echo $t('เพิ่มล่าสุด', 'Newest first'); ?></option></select></label>
                        <label><span class="text-xs text-gray-400"><?php echo $t('ต่อหน้า', 'Per page'); ?></span><select name="per_page" class="mt-1 w-full bg-[#17171c] border border-white/10 rounded-lg px-3 py-2"><?php foreach ($allowedPerPage as $pageSize): ?><option value="<?php echo $pageSize; ?>" <?php echo $perPage === $pageSize ? 'selected' : ''; ?>><?php echo $pageSize; ?></option><?php endforeach; ?></select></label>
                        <div class="flex items-end"><button type="submit" class="w-full rounded-lg bg-violet-500 hover:bg-violet-600 px-4 py-2 text-white min-h-11"><?php echo $t('ใช้ตัวกรอง', 'Apply filters'); ?></button></div>
                    </div>
                </details>
            </form>

            <div class="rounded-lg border border-blue-500/20 bg-blue-500/5 p-3 text-xs text-blue-100">
                <?php echo $t('บนโทรศัพท์รายการสินค้าจะแสดงเป็นการ์ด ไม่ต้องเลื่อนซ้ายขวา รูปถูกย่อให้พอดี และเมื่อซิงค์ หากราคาขายต่ำกว่าต้นทุน ระบบจะปรับขึ้นเท่าต้นทุนให้อัตโนมัติ', 'On phones, products are displayed as cards with no horizontal scrolling. Images are compact, and sync automatically raises any selling price below supplier cost.'); ?>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="cgo-products-table w-full text-sm min-w-[1500px]">
                <thead class="bg-white/5 text-gray-400"><tr><th class="text-left p-3">ID</th><th class="text-left p-3"><?php echo $t('รูป', 'Image'); ?></th><th class="text-left p-3"><?php echo $t('สินค้าและรายละเอียด', 'Product and details'); ?></th><th class="text-right p-3">USD</th><th class="text-right p-3">IDR</th><th class="text-right p-3"><?php echo $t('ต้นทุนฐาน', 'Base cost'); ?></th><th class="text-left p-3"><?php echo $t('ราคา/สถานะ', 'Pricing/status'); ?></th></tr></thead>
                <tbody class="divide-y divide-white/5">
                <?php if (!$products): ?><tr><td colspan="7" class="p-8 text-center text-gray-500"><?php echo $totalProducts > 0 ? $t('ไม่พบสินค้าที่ตรงกับคำค้นหาหรือตัวกรอง', 'No products match the current search or filters.') : $t('ยังไม่มีสินค้า กดซิงค์หลังทดสอบ API ผ่าน', 'No products yet. Test the API, then sync.'); ?></td></tr><?php endif; ?>
                <?php foreach ($products as $product):
                    $displayTitle = cgoProductDisplayTitle($product);
                    $variantLabel = cgoProductVariantLabel($product);
                    $imagePath = trim((string) ($product['image_path'] ?? ''));
                    $sourceImageUrl = cgoProductImageUrl($product);
                    $cachedImagePath = ltrim(str_replace('\\', '/', trim((string) ($product['cached_image_path'] ?? ''))), '/');
                    $cachedImageReady = preg_match('#^assets/uploads/products/cgo_[a-f0-9]{64}\\.(?:jpe?g|png|webp|gif)$#i', $cachedImagePath) === 1
                        && is_file(__DIR__ . '/../' . $cachedImagePath);
                    $imageUrl = $cachedImageReady
                        ? '../' . $cachedImagePath
                        : ($sourceImageUrl !== ''
                            ? 'cheatgame_image.php?id=' . (int) $product['id'] . '&v=' . rawurlencode((string) ($product['last_synced_at'] ?? ''))
                            : '');
                    $description = trim((string) ($product['description'] ?? ''));
                    $platform = trim((string) ($product['platform'] ?? ''));
                    $catalogLink = $catalogLinks[(int) $product['id']] ?? null;
                    $linkedProductId = $catalogLink ? (int) $catalogLink['local_product_id'] : 0;
                    $linkedVariantId = $catalogLink ? (int) $catalogLink['local_variant_id'] : 0;
                    $linkedLocalProduct = $linkedProductId > 0 ? ($localProductMap[$linkedProductId] ?? null) : null;
                    $linkedCategories = $linkedLocalProduct && isset($linkedLocalProduct['categories']) && is_array($linkedLocalProduct['categories'])
                        ? implode(', ', $linkedLocalProduct['categories']) : '';
                ?>
                    <tr id="cgo-product-<?php echo (int) $product['id']; ?>" class="cgo-product-row align-top" data-cgo-product-card data-cgo-product-id="<?php echo (int) $product['id']; ?>">
                        <td class="cgo-col-id p-3 font-mono text-xs"><?php echo htmlspecialchars((string) $product['remote_product_id'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="cgo-col-image p-3 w-28">
                            <?php if ($imageUrl !== ''): ?>
                                <img src="<?php echo htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($displayTitle, ENT_QUOTES, 'UTF-8'); ?>" class="cgo-product-image w-24 h-16 rounded-lg object-cover bg-black/20" loading="lazy" onerror="this.onerror=null;this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22112%22 height=%2280%22 viewBox=%220 0 112 80%22%3E%3Crect width=%22112%22 height=%2280%22 fill=%22%2317171c%22/%3E%3Ctext x=%2256%22 y=%2244%22 text-anchor=%22middle%22 fill=%22%236b7280%22 font-size=%2211%22%3ENo image%3C/text%3E%3C/svg%3E';">
                                <?php if ($sourceImageUrl !== ''): ?><a href="<?php echo htmlspecialchars($sourceImageUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="mt-1 block text-[10px] text-blue-300 hover:text-blue-200"><?php echo $t('เปิดรูปต้นทาง', 'Open source image'); ?></a><?php endif; ?>
                            <?php else: ?>
                                <div class="cgo-product-image-placeholder w-24 h-16 rounded-lg bg-black/20 flex items-center justify-center text-gray-600"><i class="bi bi-image text-xl"></i></div>
                            <?php endif; ?>
                        </td>
                        <td class="cgo-col-info p-3 max-w-2xl">
                            <div class="font-semibold text-white"><?php echo htmlspecialchars($displayTitle, ENT_QUOTES, 'UTF-8'); ?></div>
                            <?php if ($variantLabel !== ''): ?><div class="text-violet-300 mt-1"><?php echo htmlspecialchars($variantLabel, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
                            <div class="flex flex-wrap gap-2 mt-2 text-xs text-gray-400">
                                <?php if ($platform !== ''): ?><span class="rounded bg-blue-500/10 border border-blue-500/20 px-2 py-1"><?php echo htmlspecialchars(strtoupper($platform), ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
                                <span class="rounded bg-white/5 border border-white/10 px-2 py-1" data-cgo-admin-product-id="<?php echo (int) $product['id']; ?>"><?php echo $t('สต็อก', 'Stock'); ?>: <span data-cgo-admin-stock><?php echo $product['remote_stock'] === null ? 'N/A' : (int) $product['remote_stock']; ?></span></span>
                                <span class="rounded bg-white/5 border border-white/10 px-2 py-1" data-cgo-admin-status-for="<?php echo (int) $product['id']; ?>"><?php echo htmlspecialchars((string) $product['remote_status'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php if (!empty($product['currency'])): ?><span class="rounded bg-white/5 border border-white/10 px-2 py-1"><?php echo htmlspecialchars((string) $product['currency'], ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
                                <?php if ((string) ($product['price_warning_code'] ?? '') === 'saved_price_below_cost'): ?><span class="rounded bg-amber-500/10 border border-amber-500/30 px-2 py-1 text-amber-200"><?php echo $t('ราคาเดิมต่ำกว่าต้นทุนใหม่', 'Saved price below new cost'); ?></span><?php endif; ?>
                            </div>
                            <?php if ((string) ($product['price_warning_code'] ?? '') === 'saved_price_below_cost'): ?>
                                <div class="mt-2 rounded border border-amber-500/20 bg-amber-500/10 p-2 text-xs text-amber-100">
                                    <?php echo $t('ระบบเก็บราคาเดิมไว้โดยไม่เขียนทับ แต่จะไม่แสดงหรือขายสต็อก API ของสินค้านี้จนกว่าราคาลูกค้าและราคาตัวแทนที่ใช้จริงจะไม่ต่ำกว่าต้นทุนปัจจุบัน', 'The saved number was preserved, but this API stock will not be shown or sold until the effective customer and reseller prices are no lower than the current supplier cost.'); ?>
                                    <div class="mt-1"><?php echo $t('ราคาเดิม', 'Saved'); ?>: <?php echo htmlspecialchars($formatBase($product['saved_user_price_base'] ?? null), ENT_QUOTES, 'UTF-8'); ?> / <?php echo htmlspecialchars($formatBase($product['saved_reseller_price_base'] ?? null), ENT_QUOTES, 'UTF-8'); ?></div>
                                </div>
                            <?php endif; ?>
                            <?php if ($description !== ''): ?>
                                <details class="mt-3 rounded bg-black/20 border border-white/5">
                                    <summary class="cursor-pointer px-3 py-2 text-xs text-gray-300"><?php echo $t('ดูรายละเอียดเต็มจาก API', 'View full API description'); ?></summary>
                                    <div class="cgo-admin-description border-t border-white/5 px-3 py-3 text-xs text-gray-400"><?php echo htmlspecialchars($description, ENT_QUOTES, 'UTF-8'); ?></div>
                                </details>
                            <?php endif; ?>
                            <details class="cgo-catalog-details mt-3 rounded-lg border <?php echo $catalogLink ? 'border-green-500/30 bg-green-500/5' : 'border-violet-500/20 bg-violet-500/5'; ?>">
                                <summary class="cursor-pointer px-3 py-2 text-xs font-medium <?php echo $catalogLink ? 'text-green-300' : 'text-violet-300'; ?>">
                                    <?php if ($catalogLink): ?>
                                        <?php echo $t('รวมกับหน้าหลักแล้ว: ', 'Linked to main catalogue: '); ?><?php echo htmlspecialchars((string) ($catalogLink['local_product_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars((string) ($catalogLink['local_variant_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                    <?php else: ?>
                                        <?php echo $t('เพิ่มหรือรวมสินค้านี้เข้าหน้าหลัก', 'Add or merge this product into the main catalogue'); ?>
                                    <?php endif; ?>
                                </summary>
                                <div class="border-t border-white/5 p-3 space-y-3">
                                    <form method="POST" enctype="multipart/form-data" class="js-cgo-catalog-form grid grid-cols-1 md:grid-cols-2 gap-3">
                                        <?php echo $csrf; ?>
                                        <input type="hidden" name="action" value="publish_catalog">
                                        <input type="hidden" name="product_id" value="<?php echo (int) $product['id']; ?>">
                                        <label class="text-xs text-gray-400">
                                            <?php echo $t('วิธีรวมสินค้า', 'Catalogue action'); ?>
                                            <select name="catalog_mode" class="js-cgo-mode mt-1 w-full bg-black/30 border border-white/10 rounded px-2 py-2 text-white">
                                                <option value="create" <?php echo !$catalogLink ? 'selected' : ''; ?>><?php echo $t('สร้างสินค้าใหม่ในหน้าหลัก', 'Create a new main product'); ?></option>
                                                <option value="existing" <?php echo $catalogLink ? 'selected' : ''; ?>><?php echo $t('เชื่อมกับสินค้าที่มีอยู่', 'Link to an existing product'); ?></option>
                                            </select>
                                        </label>
                                        <div class="cgo-local-picker-grid md:col-span-2 grid grid-cols-1 md:grid-cols-2 gap-2 rounded-lg border border-white/10 bg-black/15 p-3">
                                            <label class="text-xs text-gray-400">
                                                <?php echo $t('ค้นหาสินค้าหลัก', 'Search local products'); ?>
                                                <input type="search" class="js-cgo-product-search mt-1 w-full bg-black/30 border border-white/10 rounded px-2 py-2 text-white" placeholder="<?php echo htmlspecialchars($t('พิมพ์อย่างน้อย 2 ตัวอักษร หรือเลข ID', 'Type at least 2 characters or an ID'), ENT_QUOTES, 'UTF-8'); ?>">
                                            </label>
                                            <label class="text-xs text-gray-400">
                                                <?php echo $t('กรองหมวดหมู่สินค้าหลัก', 'Filter local category'); ?>
                                                <select class="js-cgo-product-category mt-1 w-full bg-black/30 border border-white/10 rounded px-2 py-2 text-white">
                                                    <option value=""><?php echo $t('ทุกหมวดหมู่', 'All categories'); ?></option>
                                                    <?php foreach ($existingCategoryNames as $categoryOption): ?><option value="<?php echo htmlspecialchars($categoryOption, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($categoryOption, ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?>
                                                </select>
                                            </label>
                                            <label class="text-xs text-gray-400 md:col-span-2">
                                                <?php echo $t('สินค้าหลัก', 'Local product'); ?>
                                                <select name="local_product_id" data-selected-id="<?php echo $linkedProductId; ?>" class="js-cgo-product mt-1 w-full bg-black/30 border border-white/10 rounded px-2 py-2 text-white">
                                                    <option value="0"><?php echo $t('เลือกเมื่อใช้โหมดเชื่อมสินค้าเดิม', 'Select when linking an existing product'); ?></option>
                                                    <?php if ($linkedProductId > 0 && $linkedLocalProduct): ?><option value="<?php echo $linkedProductId; ?>" selected>#<?php echo $linkedProductId; ?> · <?php echo htmlspecialchars((string) ($linkedLocalProduct['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></option><?php endif; ?>
                                                </select>
                                            </label>
                                            <label class="text-xs text-gray-400 md:col-span-2">
                                                <?php echo $t('ค้นหาตัวเลือกสินค้าเดิม', 'Search local variants'); ?>
                                                <input type="search" class="js-cgo-variant-search mt-1 w-full bg-black/30 border border-white/10 rounded px-2 py-2 text-white" placeholder="<?php echo htmlspecialchars($t('พิมพ์อย่างน้อย 2 ตัวอักษร ชื่อ ระยะเวลา หรือ ID', 'Type at least 2 characters, a duration, or an ID'), ENT_QUOTES, 'UTF-8'); ?>">
                                            </label>
                                            <label class="text-xs text-gray-400 md:col-span-2">
                                                <?php echo $t('ตัวเลือกสินค้าเดิม', 'Existing local variant'); ?>
                                                <select name="local_variant_id" data-selected-id="<?php echo $linkedVariantId; ?>" class="js-cgo-variant mt-1 w-full bg-black/30 border border-white/10 rounded px-2 py-2 text-white">
                                                    <option value="0"><?php echo $t('สร้างตัวเลือกใหม่จากชื่อ API เช่น KEY 1 DAY', 'Create a new variant from the API name, such as KEY 1 DAY'); ?></option>
                                                    <?php if ($linkedVariantId > 0): ?><option value="<?php echo $linkedVariantId; ?>" selected>#<?php echo $linkedVariantId; ?> · <?php echo htmlspecialchars((string) ($catalogLink['local_product_name'] ?? '') . ' — ' . (string) ($catalogLink['local_variant_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></option><?php endif; ?>
                                                </select>
                                            </label>
                                            <div class="md:col-span-2 text-[11px] text-gray-500"><?php echo $t('รายการทั้งหมดจะโหลดเมื่อเปิดส่วนนี้เท่านั้น จึงไม่ทำให้โทรศัพท์สร้างตัวเลือกซ้ำหลายหมื่นรายการตั้งแต่เปิดหน้า', 'The full lists load only when this section is opened, avoiding tens of thousands of duplicated options on phones.'); ?></div>
                                        </div>
                                        <label class="text-xs text-gray-400 md:col-span-2">
                                            <?php echo $t('หมวดหมู่หน้าหลัก สูงสุด 4 หมวด คั่นด้วยจุลภาค', 'Main-store categories, up to 4, separated by commas'); ?>
                                            <input type="text" name="categories" value="<?php echo htmlspecialchars($linkedCategories !== '' ? $linkedCategories : (string) ($product['category'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" list="cgo-category-list" class="mt-1 w-full bg-black/30 border border-white/10 rounded px-2 py-2 text-white" placeholder="Android, AORUS">
                                        </label>
                                        <label class="text-xs text-gray-400 md:col-span-2">
                                            <?php echo $t('รูปภาพสำรองหรือรูปใหม่', 'Fallback or replacement image'); ?>
                                            <input type="file" name="catalog_image" accept="image/jpeg,image/png,image/webp,image/gif" class="mt-1 block w-full text-xs text-gray-300 file:mr-3 file:rounded file:border-0 file:bg-violet-500 file:px-3 file:py-2 file:text-white">
                                            <span class="block mt-1 text-[11px] text-gray-500"><?php echo $t('ถ้ารูปสินค้าหลักมีอยู่แล้ว ระบบจะเก็บรูปเดิมไว้ ถ้ารูปว่าง ระบบจะใช้ image_url จาก API และพยายามแคชไว้ใน assets/uploads/products', 'An existing main-product image is preserved. If it is empty, the system uses the API image_url and attempts to cache it under assets/uploads/products.'); ?></span>
                                        </label>
                                        <div class="js-cgo-initial-prices md:col-span-2 rounded-lg border border-amber-500/20 bg-amber-500/5 p-3">
                                            <div class="text-xs text-amber-100 mb-2"><?php echo $t('ราคาตั้งต้นสำหรับตัวเลือกใหม่ ใช้เฉพาะเมื่อเลือก “สร้างตัวเลือกใหม่” และไม่ได้เปิดซิงก์ราคา', 'Initial prices for a new variant. Used only when “Create a new variant” is selected and price sync is off.'); ?></div>
                                            <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                                                <label class="text-xs text-gray-300"><?php echo $t('ราคาลูกค้าเริ่มต้น', 'Initial customer price'); ?><input type="number" min="0.01" max="10000000" step="0.01" name="initial_user_price" value="<?php echo htmlspecialchars(number_format((float) ($product['user_price_base'] ?? 0), 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?>" class="mt-1 w-full bg-black/30 border border-white/10 rounded px-2 py-2 text-white"></label>
                                                <label class="text-xs text-gray-300"><?php echo $t('ราคาตัวแทนเริ่มต้น', 'Initial reseller price'); ?><input type="number" min="0.01" max="10000000" step="0.01" name="initial_reseller_price" value="<?php echo htmlspecialchars(number_format((float) ($product['reseller_price_base'] ?? 0), 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?>" class="mt-1 w-full bg-black/30 border border-white/10 rounded px-2 py-2 text-white"></label>
                                            </div>
                                            <div class="mt-2 text-[11px] text-gray-400"><?php echo $t('ระบบจะไม่แอบแทนราคานี้ด้วยต้นทุน API และไม่อนุญาตราคาต่ำกว่าต้นทุนปัจจุบัน', 'The system will not silently replace these values with API cost and will reject prices below current cost.'); ?></div>
                                        </div>
                                        <div class="md:col-span-2 grid grid-cols-1 md:grid-cols-3 gap-2 text-xs">
                                            <label class="flex items-start gap-2 rounded border border-white/10 bg-black/20 p-2"><input type="checkbox" name="sync_details" value="1" class="mt-0.5" <?php echo ($catalogLink && !empty($catalogLink['sync_details'])) ? 'checked' : ''; ?>><span><?php echo $t('ซิงก์ชื่อ รายละเอียด หมวดหมู่ แพลตฟอร์ม และชื่อตัวเลือกจาก API ในการซิงก์ครั้งต่อไป', 'Sync name, description, category, platform, and variant name on future API syncs'); ?></span></label>
                                            <label class="flex items-start gap-2 rounded border border-white/10 bg-black/20 p-2"><input type="checkbox" name="sync_price" value="1" class="js-cgo-sync-price mt-0.5" <?php echo ($catalogLink && !empty($catalogLink['sync_price'])) ? 'checked' : ''; ?>><span><?php echo $t('ซิงก์ราคาขายไปยังตัวเลือกหน้าหลักในอนาคต (ต้นทุน API จะซิงก์เสมอ)', 'Sync selling prices to the main variant on future syncs (supplier cost always syncs)'); ?></span></label>
                                            <label class="flex items-start gap-2 rounded border border-white/10 bg-black/20 p-2"><input type="checkbox" name="api_fallback_enabled" value="1" class="mt-0.5" <?php echo (!$catalogLink || !empty($catalogLink['api_fallback_enabled'])) ? 'checked' : ''; ?>><span><?php echo $t('ใช้ API เมื่อคีย์ของร้านหมด', 'Use the API only when local keys are exhausted'); ?></span></label>
                                        </div>
                                        <div class="md:col-span-2 flex flex-wrap gap-2">
                                            <button class="rounded bg-violet-500 hover:bg-violet-600 px-4 py-2 text-white"><?php echo $catalogLink ? $t('อัปเดตการรวมสินค้า', 'Update catalogue mapping') : $t('เพิ่มเข้าหน้าหลัก', 'Add to main catalogue'); ?></button>
                                            <?php if ($linkedProductId > 0): ?><a href="products.php?edit=<?php echo $linkedProductId; ?>#product-<?php echo $linkedProductId; ?>" class="rounded bg-white/10 hover:bg-white/15 px-4 py-2 text-gray-200"><?php echo $t('แก้สินค้าและรูปในหน้าหลัก', 'Edit main product and image'); ?></a><?php endif; ?>
                                        </div>
                                    </form>
                                    <?php if ($catalogLink): ?>
                                        <form method="POST" onsubmit="return confirm('<?php echo htmlspecialchars($t('ยกเลิกการเชื่อมเท่านั้น สินค้าหลักและคีย์เดิมจะไม่ถูกลบ ยืนยันหรือไม่?', 'Remove only the mapping? The local product and existing keys will not be deleted.'), ENT_QUOTES, 'UTF-8'); ?>');">
                                            <?php echo $csrf; ?><input type="hidden" name="action" value="unlink_catalog"><input type="hidden" name="product_id" value="<?php echo (int) $product['id']; ?>">
                                            <button class="text-xs rounded bg-red-500/15 hover:bg-red-500/25 border border-red-500/20 px-3 py-2 text-red-300"><?php echo $t('ยกเลิกการเชื่อม', 'Unlink'); ?></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </details>
                            <div class="text-xs mt-2 <?php echo (int) $product['enabled'] === 1 ? 'text-green-400' : 'text-gray-500'; ?>"><?php echo (int) $product['enabled'] === 1 ? $t('เปิดขาย', 'Enabled') : $t('ปิดขาย', 'Disabled'); ?></div>
                        </td>
                        <td class="cgo-col-usd p-3 text-right" data-label="USD">$<?php echo number_format((float) $product['price_usd'], 6); ?></td>
                        <td class="cgo-col-idr p-3 text-right" data-label="IDR"><?php echo $product['price_idr'] === null ? 'N/A' : 'Rp ' . number_format((float) $product['price_idr'], 2); ?></td>
                        <td class="cgo-col-cost p-3 text-right" data-label="<?php echo htmlspecialchars($t('ต้นทุนฐาน', 'Base cost'), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($formatBase($product['cost_base']), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="cgo-col-pricing p-3">
                            <form method="POST" class="cgo-price-form grid grid-cols-3 gap-2 items-end">
                                <?php echo $csrf; ?><input type="hidden" name="action" value="update_product"><input type="hidden" name="product_id" value="<?php echo (int) $product['id']; ?>">
                                <label><span class="text-xs text-gray-500"><?php echo $t('ราคาลูกค้า', 'Customer price'); ?></span><input type="number" min="0" max="10000000" step="0.01" name="user_price" value="<?php echo htmlspecialchars(number_format((float) $product['user_price_base'], 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?>" class="mt-1 w-full bg-black/20 border border-white/10 rounded px-2 py-2" required></label>
                                <label><span class="text-xs text-gray-500"><?php echo $t('ราคาตัวแทน', 'Reseller price'); ?></span><input type="number" min="0" max="10000000" step="0.01" name="reseller_price" value="<?php echo htmlspecialchars(number_format((float) $product['reseller_price_base'], 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?>" class="mt-1 w-full bg-black/20 border border-white/10 rounded px-2 py-2" required></label>
                                <div class="cgo-price-actions">
                                    <label class="flex items-center gap-2 text-xs mb-1"><input type="checkbox" name="enabled" value="1" <?php echo (int) $product['enabled'] === 1 ? 'checked' : ''; ?>><?php echo $t('เปิดขาย', 'Enable'); ?></label>
                                    <label class="flex items-start gap-2 text-xs mb-2"><input type="checkbox" name="automatic_price_mode" value="1" class="mt-0.5" <?php echo (int) ($product['manual_price_saved'] ?? 0) === 0 ? 'checked' : ''; ?>><span><?php echo $t('ใช้ราคาอัตโนมัติจากมาร์กอัปเมื่อซิงก์ (ติ๊กแล้วราคาที่กรอกอาจเปลี่ยน)', 'Use automatic markup pricing on sync (entered prices may change)'); ?></span></label>
                                    <div class="text-[10px] mb-2 <?php echo (int) ($product['manual_price_saved'] ?? 0) === 1 ? 'text-green-300' : 'text-gray-500'; ?>">
                                        <?php echo (int) ($product['manual_price_saved'] ?? 0) === 1 ? $t('โหมดราคาที่บันทึกเอง', 'Manual price saved') : $t('โหมดราคาอัตโนมัติ', 'Automatic pricing'); ?>
                                    </div>
                                    <button class="w-full bg-blue-500 hover:bg-blue-600 rounded px-3 py-2 text-white"><?php echo $t('บันทึก', 'Save'); ?></button>
                                </div>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalPages > 1): ?>
            <div class="p-4 border-t border-white/10 flex flex-wrap items-center justify-between gap-3 text-sm">
                <div class="text-gray-400"><?php echo $t('หน้า', 'Page'); ?> <?php echo $page; ?> / <?php echo $totalPages; ?></div>
                <div class="flex flex-wrap gap-2">
                    <?php if ($page > 1): ?>
                        <a class="rounded bg-white/10 hover:bg-white/15 px-3 py-2" href="cheatgame.php?<?php echo htmlspecialchars($catalogQueryParams(['page' => $page - 1]), ENT_QUOTES, 'UTF-8'); ?>#cgo-products"><?php echo $t('ก่อนหน้า', 'Previous'); ?></a>
                    <?php endif; ?>
                    <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    for ($pageNumber = $startPage; $pageNumber <= $endPage; $pageNumber++):
                    ?>
                        <a class="rounded px-3 py-2 <?php echo $pageNumber === $page ? 'bg-violet-500 text-white' : 'bg-white/10 hover:bg-white/15'; ?>" href="cheatgame.php?<?php echo htmlspecialchars($catalogQueryParams(['page' => $pageNumber]), ENT_QUOTES, 'UTF-8'); ?>#cgo-products"><?php echo $pageNumber; ?></a>
                    <?php endfor; ?>
                    <?php if ($page < $totalPages): ?>
                        <a class="rounded bg-white/10 hover:bg-white/15 px-3 py-2" href="cheatgame.php?<?php echo htmlspecialchars($catalogQueryParams(['page' => $page + 1]), ENT_QUOTES, 'UTF-8'); ?>#cgo-products"><?php echo $t('ถัดไป', 'Next'); ?></a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </section>


    <details id="cgo-admin-tools" class="rounded-xl border border-white/10 bg-white/[.02]" open>
        <summary class="cgo-tools-summary cursor-pointer rounded-t-xl bg-[#141419] px-4 py-3 font-semibold text-white flex items-center gap-2"><i class="bi bi-sliders text-violet-300"></i><?php echo $t('ตั้งค่า มาร์กอัป และคำสั่งซิงค์', 'Configuration, markup, and sync tools'); ?></summary>
        <div class="p-3 md:p-4">
    <section class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div class="glass rounded-xl p-5 lg:col-span-2">
            <h2 class="font-semibold text-white mb-4 flex items-center gap-2"><i class="bi bi-shield-lock text-blue-400"></i><?php echo $t('สถานะการตั้งค่า', 'Configuration status'); ?></h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                <div class="bg-black/20 rounded-lg p-3"><div class="text-gray-500">Endpoint</div><div class="break-all text-white mt-1"><?php echo htmlspecialchars((string) $configStatus['endpoint'], ENT_QUOTES, 'UTF-8'); ?></div></div>
                <div class="bg-black/20 rounded-lg p-3"><div class="text-gray-500">API Key</div><div class="font-mono text-white mt-1"><?php echo htmlspecialchars(cgoMaskSecret((string) $config['api_key']), ENT_QUOTES, 'UTF-8'); ?></div><div class="text-xs text-gray-500 mt-2"><?php echo $t('แหล่งที่มา', 'Source'); ?>: <?php echo htmlspecialchars((string) $configStatus['api_key_source'], ENT_QUOTES, 'UTF-8'); ?> · SHA-256: <?php echo htmlspecialchars((string) $configStatus['api_key_fingerprint'], ENT_QUOTES, 'UTF-8'); ?></div></div>
                <div class="bg-black/20 rounded-lg p-3"><div class="text-gray-500"><?php echo $t('IP เซิร์ฟเวอร์ที่ต้องอนุญาต', 'Server IP to allow'); ?></div><div class="font-mono text-white mt-1"><?php echo htmlspecialchars((string) $configStatus['allowed_server_ip'], ENT_QUOTES, 'UTF-8'); ?></div><div class="text-xs text-gray-500 mt-2"><?php echo $t('แหล่งที่มา', 'Source'); ?>: <?php echo htmlspecialchars((string) $configStatus['allowed_server_ip_source'], ENT_QUOTES, 'UTF-8'); ?></div></div>
                <div class="bg-black/20 rounded-lg p-3"><div class="text-gray-500"><?php echo $t('โหมดเครือข่าย API', 'API network mode'); ?></div><div class="font-mono text-white mt-1"><?php echo !empty($configStatus['force_ipv4']) ? 'IPv4 forced' : 'Automatic IPv4/IPv6'; ?></div><div class="text-xs text-gray-500 mt-2"><?php echo $t('บังคับ IPv4 เพื่อให้ตรงกับ Allowed Server IP', 'IPv4 is forced to match the Allowed Server IP'); ?> · <?php echo htmlspecialchars((string) $configStatus['force_ipv4_source'], ENT_QUOTES, 'UTF-8'); ?></div></div>
                <div class="bg-black/20 rounded-lg p-3"><div class="text-gray-500"><?php echo $t('รหัสเว็บไซต์และฐานข้อมูล', 'Website and database identity'); ?></div><div class="font-mono text-white mt-1"><?php echo htmlspecialchars((string) ($configStatus['site_prefix'] ?: 'UNRESOLVED'), ENT_QUOTES, 'UTF-8'); ?></div><div class="text-xs text-gray-500 mt-2"><?php echo $t('โปรไฟล์', 'Profile'); ?>: <?php echo htmlspecialchars((string) ($configStatus['site_profile'] ?: 'none'), ENT_QUOTES, 'UTF-8'); ?></div></div>
                <div class="bg-black/20 rounded-lg p-3">
                    <div class="text-gray-500"><?php echo $t('การสั่งซื้อ CHEATGAME จริง', 'CHEATGAME live ordering'); ?></div>
                    <div class="mt-1 <?php echo !empty($configStatus['live_order_enabled']) ? 'text-green-300' : 'text-amber-300'; ?>">
                        <?php
                        if (empty($configStatus['live_order_enabled'])) echo $t('ปิดใช้งาน', 'Disabled');
                        elseif (!empty($configStatus['live_order_admin_only'])) echo $t('โหมดทดสอบเฉพาะผู้ดูแล', 'Administrator-only test mode');
                        else echo $t('เปิดใช้งาน', 'Enabled');
                        ?>
                    </div>
                    <div class="text-xs text-gray-500 mt-2"><?php echo !empty($configStatus['live_order_admin_only'])
                        ? $t('ลูกค้าและตัวแทนยังสั่งผ่าน API ไม่ได้ในช่วงทดสอบ', 'Customers and resellers cannot place API orders during this test mode.')
                        : $t('การซิงก์สินค้าและทดสอบ API ไม่ได้รับผลกระทบ', 'Product sync and safe API tests are unaffected.'); ?></div>
                </div>
                <div class="bg-black/20 rounded-lg p-3"><div class="text-gray-500">Webhook URL</div><div class="break-all text-white mt-1"><?php echo htmlspecialchars((string) ($configStatus['webhook_public_url'] ?: 'NOT CONFIGURED'), ENT_QUOTES, 'UTF-8'); ?></div><div class="text-xs text-gray-500 mt-2"><?php echo $t('บทบาทของเว็บนี้', 'This website role'); ?>: <?php echo htmlspecialchars((string) $configStatus['webhook_role'], ENT_QUOTES, 'UTF-8'); ?></div></div>
                <div class="bg-black/20 rounded-lg p-3"><div class="text-gray-500"><?php echo $t('Webhook Secret', 'Webhook secret'); ?></div><div class="text-white mt-1"><?php echo !empty($configStatus['webhook_secret_configured']) ? $t('ตั้งค่าแล้ว', 'Configured') : $t('ยังไม่ได้ตั้งค่า', 'Not configured'); ?></div><div class="text-xs text-gray-500 mt-2"><?php echo $t('แหล่งที่มา', 'Source'); ?>: <?php echo htmlspecialchars((string) $configStatus['webhook_secret_source'], ENT_QUOTES, 'UTF-8'); ?></div></div>
                <div class="bg-black/20 rounded-lg p-3"><div class="text-gray-500"><?php echo $t('สกุลเงินฐานของระบบ', 'Store base currency'); ?></div><div class="text-white mt-1"><?php echo htmlspecialchars($baseCurrency ?? 'INVALID', ENT_QUOTES, 'UTF-8'); ?></div></div>
            </div>
            <div class="mt-4 rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-sm text-amber-100">
                <?php echo $t('HTTP 202 จากปุ่ม Send Test ใน CHEATGAME เป็นการทดสอบ webhook เท่านั้น ไม่ได้ตรวจ API Key สำหรับ products, balance หรือ exchange_rate', 'HTTP 202 from CHEATGAME Send Test checks only the webhook. It does not authenticate the API key used by products, balance, or exchange_rate.'); ?>
            </div>
            <div class="mt-4 flex flex-col sm:flex-row gap-3">
                <form method="POST">
                    <?php echo $csrf; ?><input type="hidden" name="action" value="test_api">
                    <button class="px-4 py-2 rounded-lg bg-blue-500 hover:bg-blue-600 text-white font-medium"><i class="bi bi-activity mr-2"></i><?php echo $t('ทดสอบ API แบบปลอดภัย', 'Run safe API tests'); ?></button>
                </form>
                <form method="POST">
                    <?php echo $csrf; ?><input type="hidden" name="action" value="test_webhook_route">
                    <button class="px-4 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-medium"><i class="bi bi-diagram-3 mr-2"></i><?php echo $t('ทดสอบ Webhook ครบเส้นทาง', 'Test end-to-end webhook route'); ?></button>
                </form>
                <form method="POST">
                    <?php echo $csrf; ?><input type="hidden" name="action" value="test_order_status"><input type="hidden" name="use_latest" value="1">
                    <button class="px-4 py-2 rounded-lg bg-fuchsia-600 hover:bg-fuchsia-700 text-white font-medium"><i class="bi bi-search mr-2"></i><?php echo $t('ตรวจ Order ล่าสุดแบบ Read-only', 'Read-only check latest order'); ?></button>
                </form>
                <form method="POST">
                    <?php echo $csrf; ?><input type="hidden" name="action" value="test_order_cancel_seal">
                    <button class="px-4 py-2 rounded-lg bg-orange-600 hover:bg-orange-700 text-white font-medium"><i class="bi bi-shield-lock mr-2"></i><?php echo $t('ทดสอบ order_cancel Seal', 'Test order_cancel seal'); ?></button>
                </form>
            </div>
        </div>

        <div class="glass rounded-xl p-5">
            <h2 class="font-semibold text-white mb-4 flex items-center gap-2"><i class="bi bi-percent text-yellow-400"></i><?php echo $t('มาร์กอัปราคาขาย', 'Selling markup'); ?></h2>
            <form method="POST" class="space-y-3">
                <?php echo $csrf; ?><input type="hidden" name="action" value="save_markup">
                <label class="block"><span class="text-sm text-gray-400"><?php echo $t('ลูกค้าทั่วไป (%)', 'Customer markup (%)'); ?></span><input type="number" min="0" max="1000" step="0.0001" name="user_markup" value="<?php echo htmlspecialchars((string) $markups['user'], ENT_QUOTES, 'UTF-8'); ?>" class="mt-1 w-full bg-black/20 border border-white/10 rounded-lg px-3 py-2" required></label>
                <label class="block"><span class="text-sm text-gray-400"><?php echo $t('ตัวแทนขาย (%)', 'Reseller markup (%)'); ?></span><input type="number" min="0" max="1000" step="0.0001" name="reseller_markup" value="<?php echo htmlspecialchars((string) $markups['reseller'], ENT_QUOTES, 'UTF-8'); ?>" class="mt-1 w-full bg-black/20 border border-white/10 rounded-lg px-3 py-2" required></label>
                <label class="flex items-start gap-2 rounded-lg border border-yellow-500/20 bg-yellow-500/5 p-3 text-xs text-yellow-100"><input type="checkbox" name="apply_markup_now" value="1" class="mt-0.5" checked><span><?php echo $t('ปรับราคาสินค้าทั้งหมดทันที และเปลี่ยนสินค้าที่เคยตั้งราคาเองกลับเป็นราคาอัตโนมัติ', 'Apply to all products immediately and return manually priced products to automatic pricing.'); ?></span></label>
                <button class="w-full px-4 py-3 rounded-lg bg-yellow-500 hover:bg-yellow-600 text-black font-semibold"><?php echo $t('บันทึกและปรับราคาทั้งหมด', 'Save and apply to all prices'); ?></button>
            </form>
            <form method="POST" class="mt-3">
                <?php echo $csrf; ?><input type="hidden" name="action" value="sync_products">
                <button class="w-full px-4 py-2 rounded-lg bg-violet-500 hover:bg-violet-600 text-white font-semibold"><i class="bi bi-arrow-repeat mr-2"></i><?php echo $t('ซิงค์สินค้าและคำนวณราคา', 'Sync products and prices'); ?></button>
            </form>
            <form method="POST" class="mt-3">
                <?php echo $csrf; ?><input type="hidden" name="action" value="sync_stock">
                <button class="w-full px-4 py-2 rounded-lg bg-cyan-600 hover:bg-cyan-700 text-white font-semibold"><i class="bi bi-box-seam mr-2"></i><?php echo $t('อัปเดตเฉพาะสต็อก API', 'Refresh API stock only'); ?></button>
            </form>
            <div class="mt-3 rounded-lg border border-cyan-500/20 bg-cyan-500/5 p-3 text-xs text-cyan-100 space-y-1">
                <div><?php echo $t('ปุ่มนี้เปลี่ยนเฉพาะสต็อกและสถานะจาก CHEATGAME ไม่แตะราคา รายละเอียด หมวดหมู่ รูป หรือการผูกสินค้า', 'This button changes only CHEATGAME stock/status. It does not touch prices, details, categories, images, or mappings.'); ?></div>
                <div class="text-cyan-200/70"><?php echo $t('ระบบนับเวลา ', 'The timer starts from the last successful inventory refresh. After '); ?><?php echo (int) $inventoryTtlSeconds; ?><?php echo $t(' วินาทีจากการอัปเดตสำเร็จล่าสุด เมื่อครบแล้วหน้าที่เปิดอยู่จะเรียกซิงก์สต็อกเบื้องหลัง โดยไม่รีโหลดหน้าและไม่ซ่อนสินค้าระหว่างตรวจสอบ', ' seconds, an open page refreshes stock in the background without reloading the page or hiding products during the check.'); ?></div>
                <div class="text-cyan-200/70"><?php echo $t('เส้นทางซื้อแบบเร็วไม่รอ GET products สดก่อนสั่งซื้อ ใช้ cache สำหรับหน้าร้าน และให้ action=order เป็นตัวตัดสินสต็อกจริง', 'Fast checkout does not wait for a live GET products call. The storefront uses cache while action=order is the final stock authority.'); ?></div>
                <div id="cgoInventoryAutoStatus" class="text-cyan-200/80"><?php echo $t('ระบบอัปเดตอัตโนมัติพร้อมทำงาน', 'Automatic inventory refresh is ready.'); ?></div>
                <div id="cgoInventoryLastSuccess" class="text-gray-400"><?php echo $t('อัปเดตสต็อกสำเร็จล่าสุด', 'Last successful inventory refresh'); ?>: <span data-cgo-last-success><?php echo htmlspecialchars((string) ($inventoryState['last_success_at'] ?? $t('ยังไม่มี', 'never')), ENT_QUOTES, 'UTF-8'); ?></span><?php if (is_int($inventoryState['age_seconds'] ?? null)): ?> · <span data-cgo-age><?php echo (int) $inventoryState['age_seconds']; ?></span>s <?php echo $t('ที่ผ่านมา', 'ago'); ?><?php endif; ?></div>
                <?php if (!empty($inventoryState['last_error'])): ?><div id="cgoInventoryLastError" class="text-amber-200"><?php echo $t('ข้อผิดพลาดล่าสุด', 'Last error'); ?>: <?php echo htmlspecialchars((string) $inventoryState['last_error'], ENT_QUOTES, 'UTF-8'); ?></div><?php else: ?><div id="cgoInventoryLastError" class="hidden text-amber-200"></div><?php endif; ?>
            </div>
            <p class="text-xs text-gray-500 mt-3"><?php echo $t('สินค้าใหม่จะถูกปิดไว้ก่อน ต้องตรวจราคาแล้วเปิดขายทีละรายการ', 'New products stay disabled until you review pricing and enable them.'); ?></p>
        </div>
    </section>
        </div>
    </details>

    <section class="glass rounded-xl p-5">
        <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
            <div>
                <h2 class="font-semibold text-white flex items-center gap-2"><i class="bi bi-search-heart text-fuchsia-400"></i><?php echo $t('ทดสอบ Order Status แบบ Read-only', 'Read-only order status diagnostics'); ?></h2>
                <p class="text-xs text-gray-400 mt-2 max-w-3xl"><?php echo $t('ใช้ตรวจว่า CHEATGAME รองรับ external_ref จริงหรือไม่ และเปรียบเทียบกับ order_id โดยไม่สร้างคำสั่งซื้อใหม่ ไม่หักเงิน และไม่แก้สถานะ Local Order', 'Checks whether CHEATGAME really supports external_ref lookup and compares it with order_id. It creates no order, charges no balance, and does not mutate the local order.'); ?></p>
            </div>
            <div class="text-xs text-gray-400 rounded-lg bg-black/20 px-3 py-2">
                POST ≤ <?php echo (int) cgoOrderSubmitTimeoutSeconds(); ?>s · status ≤ <?php echo (int) cgoOrderStatusTimeoutSeconds(); ?>s · cancel ≤ <?php echo (int) cgoOrderCancelTimeoutSeconds(); ?>s · UX deadline <?php echo (int) cgoOrderCustomerDeadlineSeconds(); ?>s
            </div>
        </div>
        <form method="POST" class="mt-4 grid grid-cols-1 md:grid-cols-4 gap-3">
            <?php echo $csrf; ?><input type="hidden" name="action" value="test_order_status">
            <label class="block"><span class="text-xs text-gray-400">Local Order ID</span><input type="number" min="1" name="local_order_id" class="mt-1 w-full bg-black/20 border border-white/10 rounded-lg px-3 py-2" placeholder="179"></label>
            <label class="block"><span class="text-xs text-gray-400">external_ref</span><input type="text" maxlength="120" name="external_ref" class="mt-1 w-full bg-black/20 border border-white/10 rounded-lg px-3 py-2 font-mono text-xs" placeholder="SAK-010-..."></label>
            <label class="block"><span class="text-xs text-gray-400">Supplier order_id</span><input type="text" maxlength="190" name="supplier_order_id" class="mt-1 w-full bg-black/20 border border-white/10 rounded-lg px-3 py-2 font-mono text-xs" placeholder="RSAPI-..."></label>
            <div class="flex items-end"><button class="w-full px-4 py-2 rounded-lg bg-fuchsia-600 hover:bg-fuchsia-700 text-white font-semibold"><i class="bi bi-shield-check mr-2"></i><?php echo $t('ตรวจแบบไม่แก้ข้อมูล', 'Run read-only check'); ?></button></div>
        </form>
    </section>

    <?php if (is_array($orderStatusTestData)): $statusMeta = $orderStatusTestData['_meta'] ?? []; $statusFullJson = json_encode($orderStatusTestData, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE); if (!is_string($statusFullJson)) $statusFullJson='{}'; ?>
    <section class="glass rounded-xl p-5">
        <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-3 mb-2">
            <div>
                <h2 class="font-semibold"><?php echo $t('ผลตรวจ Order Status', 'Order status diagnostic results'); ?></h2>
                <div class="text-xs text-gray-400 mt-1">Local #<?php echo (int) ($statusMeta['local_order_id'] ?? 0); ?> · external_ref <span class="font-mono text-gray-200 break-all"><?php echo htmlspecialchars((string) ($statusMeta['external_ref'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span> · supplier <span class="font-mono text-gray-200 break-all"><?php echo htmlspecialchars((string) ($statusMeta['supplier_order_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span></div>
            </div>
            <button type="button" class="shrink-0 px-3 py-2 rounded-lg bg-violet-600 hover:bg-violet-700 text-white text-xs font-semibold" data-copy-json-target="orderStatusFullJson" data-copy-label="<?php echo htmlspecialchars($t('คัดลอก JSON ทั้งหมด', 'Copy all JSON'), ENT_QUOTES, 'UTF-8'); ?>">
                <i class="bi bi-clipboard-check mr-1"></i><?php echo $t('คัดลอก JSON ทั้งหมด', 'Copy all JSON'); ?>
            </button>
        </div>
        <details class="mb-4 rounded-lg border border-white/10 bg-black/20">
            <summary class="cursor-pointer p-3 text-xs text-violet-300"><?php echo $t('ดู JSON ทั้งหมด', 'View full JSON'); ?></summary>
            <pre id="orderStatusFullJson" class="max-h-80 overflow-auto whitespace-pre-wrap break-words p-3 pt-0 text-[10px] text-gray-300"><?php echo htmlspecialchars($statusFullJson, ENT_QUOTES, 'UTF-8'); ?></pre>
        </details>
        <div class="grid grid-cols-1 xl:grid-cols-3 gap-3">
        <?php foreach (['external_ref','order_id','synthetic_missing_external_ref'] as $mode): if (!isset($orderStatusTestData[$mode]) || !is_array($orderStatusTestData[$mode])) continue; $item=$orderStatusTestData[$mode]; $displayMode=$mode === 'synthetic_missing_external_ref' ? 'synthetic external_ref' : $mode; $itemJson=json_encode($item, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE); if(!is_string($itemJson))$itemJson='{}'; $itemJsonId='orderStatusJson_'.preg_replace('/[^A-Za-z0-9_-]/','_',$mode); ?>
            <div class="rounded-lg p-4 <?php echo !empty($item['nonfinal_not_found']) ? 'border border-amber-500/30 bg-amber-500/10' : (!empty($item['ok']) ? 'border border-green-500/30 bg-green-500/10' : 'border border-red-500/30 bg-red-500/10'); ?>">
                <div class="flex items-center justify-between gap-3"><div class="font-semibold font-mono"><?php echo htmlspecialchars($displayMode, ENT_QUOTES, 'UTF-8'); ?></div><div class="text-xs font-mono">HTTP <?php echo (int) ($item['http_code'] ?? 0); ?> · <?php echo (int) ($item['total_time_ms'] ?? 0); ?> ms</div></div>
                <div class="grid grid-cols-2 gap-x-3 gap-y-1 mt-3 text-xs text-gray-300">
                    <div>curl errno</div><div class="font-mono"><?php echo (int) $item['curl_errno']; ?></div>
                    <div>provider code</div><div class="font-mono break-all"><?php echo htmlspecialchars((string) $item['provider_error_code'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div>provider status</div><div class="font-mono break-all"><?php echo htmlspecialchars((string) $item['provider_status'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div>echo external_ref</div><div class="font-mono break-all"><?php echo htmlspecialchars((string) $item['echoed_external_ref'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div>supplier order</div><div class="font-mono break-all"><?php echo htmlspecialchars((string) $item['supplier_order_id'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div>key count</div><div class="font-mono"><?php echo (int) $item['key_count']; ?></div>
                    <div>provider final</div><div class="font-mono"><?php echo $item['provider_final'] === null ? 'n/a' : (!empty($item['provider_final']) ? 'YES' : 'NO'); ?></div>
                    <div>non-final not-found</div><div class="font-mono"><?php echo !empty($item['nonfinal_not_found']) ? 'YES' : 'NO'; ?></div>
                    <div>direct refund allowed</div><div class="font-mono"><?php echo !empty($item['direct_refund_allowed']) ? 'YES' : 'NO'; ?></div>
                    <div>DNS / Connect / TLS / TTFB</div><div class="font-mono"><?php echo (int) $item['namelookup_time_ms']; ?>/<?php echo (int) $item['connect_time_ms']; ?>/<?php echo (int) $item['appconnect_time_ms']; ?>/<?php echo (int) $item['starttransfer_time_ms']; ?> ms</div>
                </div>
                <?php if ((string) $item['error'] !== ''): ?><div class="mt-3 text-xs text-red-200 break-words"><?php echo htmlspecialchars((string) $item['error'], ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
                <?php if ($mode !== 'order_id' && !empty($item['nonfinal_not_found'])): ?><div class="mt-3 rounded bg-amber-500/10 p-2 text-xs text-amber-100"><?php echo $t('CHEATGAME ยืนยันว่า order_not_found เป็นสถานะ non-final ห้ามคืนเงินจากผลนี้โดยตรง ระบบจะต้องใช้ order_cancel เพื่อ seal external_ref ก่อน', 'CHEATGAME defines order_not_found as non-final. It must never trigger a direct refund; order_cancel must seal the external_ref first.'); ?></div><?php endif; ?>
                <div class="mt-3 flex items-center gap-2">
                    <button type="button" class="px-2.5 py-1.5 rounded bg-violet-600/80 hover:bg-violet-600 text-white text-xs" data-copy-json-target="<?php echo htmlspecialchars($itemJsonId, ENT_QUOTES, 'UTF-8'); ?>" data-copy-label="<?php echo htmlspecialchars($t('คัดลอก JSON', 'Copy JSON'), ENT_QUOTES, 'UTF-8'); ?>"><i class="bi bi-clipboard mr-1"></i><?php echo $t('คัดลอก JSON', 'Copy JSON'); ?></button>
                    <details class="flex-1"><summary class="cursor-pointer text-xs text-violet-300"><?php echo $t('ดู JSON', 'View JSON'); ?></summary><pre id="<?php echo htmlspecialchars($itemJsonId, ENT_QUOTES, 'UTF-8'); ?>" class="mt-2 max-h-64 overflow-auto whitespace-pre-wrap break-words rounded bg-black/30 p-2 text-[10px] text-gray-300"><?php echo htmlspecialchars($itemJson, ENT_QUOTES, 'UTF-8'); ?></pre></details>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
        <?php $classifierSelfTest = $orderStatusTestData['_classifier_self_test'] ?? null; if (is_array($classifierSelfTest)): ?>
        <div class="mt-4 rounded-lg border <?php echo !empty($classifierSelfTest['passed']) ? 'border-green-500/30 bg-green-500/10' : 'border-red-500/30 bg-red-500/10'; ?> p-3 text-xs">
            <div class="font-semibold <?php echo !empty($classifierSelfTest['passed']) ? 'text-green-100' : 'text-red-100'; ?>">
                <?php echo $t('Regression test ตัวตัดสิน Refund', 'Refund-classifier regression test'); ?>: <?php echo (int) ($classifierSelfTest['passed_count'] ?? 0); ?>/<?php echo (int) ($classifierSelfTest['total_count'] ?? 0); ?>
            </div>
            <div class="mt-1 text-gray-400"><?php echo $t('ทดสอบในหน่วยความจำเท่านั้น: order_not_found ต้องเป็น non-final, failed ต้องมี final=true, และ refund หลัง not-found ต้องผ่าน order_cancel seal เท่านั้น', 'In-memory only: order_not_found must remain non-final, failed requires final=true, and a not-found refund requires a successful order_cancel seal.'); ?></div>
        </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if (is_array($orderCancelTestData)): $orderCancelJson=json_encode($orderCancelTestData, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE); if(!is_string($orderCancelJson))$orderCancelJson='{}'; $cancelClass=is_array($orderCancelTestData['order_cancel']['classification'] ?? null)?$orderCancelTestData['order_cancel']['classification']:[]; ?>
    <section class="glass rounded-xl p-5 border <?php echo !empty($cancelClass['safe_to_refund']) ? 'border-green-500/30' : 'border-amber-500/30'; ?>">
        <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-3">
            <div>
                <h2 class="font-semibold text-white flex items-center gap-2"><i class="bi bi-shield-lock text-orange-400"></i><?php echo $t('ผลทดสอบ order_cancel / Seal', 'order_cancel / seal diagnostic result'); ?></h2>
                <div class="text-xs text-gray-400 mt-2"><?php echo $t('ใช้ external_ref สุ่มที่ไม่มี Order จริง การทดสอบนี้ไม่หักเงินและไม่สร้าง Order แต่จะสร้าง provider-side seal/tombstone สำหรับ ref ทดสอบนั้น', 'Uses a random external_ref with no real order. It charges no balance and creates no order, but it does create a provider-side seal/tombstone for that diagnostic ref.'); ?></div>
                <div class="mt-3 text-sm">state: <span class="font-mono text-white"><?php echo htmlspecialchars((string)($cancelClass['state'] ?? 'unknown'), ENT_QUOTES, 'UTF-8'); ?></span> · safe_to_refund: <span class="font-mono <?php echo !empty($cancelClass['safe_to_refund']) ? 'text-green-300' : 'text-amber-300'; ?>"><?php echo !empty($cancelClass['safe_to_refund']) ? 'YES' : 'NO'; ?></span></div>
                <div class="text-xs text-gray-400 mt-1 font-mono break-all"><?php echo htmlspecialchars((string)($orderCancelTestData['synthetic_external_ref'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <button type="button" class="shrink-0 px-3 py-2 rounded-lg bg-orange-600 hover:bg-orange-700 text-white text-xs font-semibold" data-copy-json-target="orderCancelDiagnosticJson" data-copy-label="<?php echo htmlspecialchars($t('คัดลอก JSON', 'Copy JSON'), ENT_QUOTES, 'UTF-8'); ?>"><i class="bi bi-clipboard mr-1"></i><?php echo $t('คัดลอก JSON', 'Copy JSON'); ?></button>
        </div>
        <details class="mt-4 rounded-lg border border-white/10 bg-black/20">
            <summary class="cursor-pointer p-3 text-xs text-orange-300"><?php echo $t('ดู JSON ทั้งหมด', 'View full JSON'); ?></summary>
            <pre id="orderCancelDiagnosticJson" class="max-h-96 overflow-auto whitespace-pre-wrap break-words p-3 pt-0 text-[10px] text-gray-300"><?php echo htmlspecialchars($orderCancelJson, ENT_QUOTES, 'UTF-8'); ?></pre>
        </details>
    </section>
    <?php endif; ?>

    <?php if (is_array($testData)): ?>
    <section class="glass rounded-xl p-5">
        <h2 class="font-semibold mb-4"><?php echo $t('ผลทดสอบ API', 'API test results'); ?></h2>
        <?php if (is_array($outboundDiagnostic)): ?>
        <div class="mb-4 rounded-lg border <?php echo !empty($outboundDiagnostic['ok']) && hash_equals((string) $configStatus['allowed_server_ip'], (string) $outboundDiagnostic['ip']) ? 'border-green-500/30 bg-green-500/10' : 'border-amber-500/30 bg-amber-500/10'; ?> p-3 text-sm">
            <div class="font-semibold"><?php echo $t('ตรวจ IPv4 ขาออกจริงของ PHP', 'PHP public outbound IPv4 check'); ?></div>
            <?php if (!empty($outboundDiagnostic['ok'])): ?>
                <div class="mt-1"><?php echo $t('IP ที่ตรวจพบ', 'Detected IP'); ?>: <span class="font-mono text-white"><?php echo htmlspecialchars((string) $outboundDiagnostic['ip'], ENT_QUOTES, 'UTF-8'); ?></span> · <?php echo $t('IP ที่ตั้งใน CHEATGAME', 'Configured CHEATGAME IP'); ?>: <span class="font-mono text-white"><?php echo htmlspecialchars((string) $configStatus['allowed_server_ip'], ENT_QUOTES, 'UTF-8'); ?></span></div>
                <?php if (!hash_equals((string) $configStatus['allowed_server_ip'], (string) $outboundDiagnostic['ip'])): ?><div class="mt-2 text-amber-100"><?php echo $t('IP ไม่ตรงกัน ต้องเปลี่ยน Allowed Server IP ใน CHEATGAME เป็น IP ที่ตรวจพบ', 'The IPs do not match. Set CHEATGAME Allowed Server IP to the detected address.'); ?></div><?php endif; ?>
            <?php else: ?>
                <div class="mt-1 text-amber-100"><?php echo htmlspecialchars((string) ($outboundDiagnostic['error'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="grid grid-cols-1 xl:grid-cols-3 gap-3 text-sm">
            <?php foreach (['products', 'balance', 'exchange_rate'] as $testName):
                $item = $testData[$testName];
                $hint = '';
                if (!$item['ok']) {
                    if (($item['provider_error_class'] ?? '') === 'provider_auth_internal') {
                        $hint = $t('คำขอถึง CHEATGAME แล้ว แต่ระบบยืนยันตัวตนภายในของ CHEATGAME ล้มเหลวก่อนตรวจสิทธิ์สำเร็จ โค้ดนี้บังคับ IPv4 ให้ตรงกับ whitelist แล้ว หากยังเป็น auth_prepare_failed ต้องให้ CHEATGAME ตรวจฐานข้อมูลหรือ auth middleware ฝั่งเขา', 'The request reached CHEATGAME, but its internal authentication layer failed before authorization completed. This build already forces IPv4 to match the whitelist. If auth_prepare_failed remains, CHEATGAME must inspect its database or authentication middleware.');
                    } elseif (in_array((int) $item['http_code'], [401, 403], true)) {
                        $hint = $t('ตรวจ API Key ที่ PHP ใช้จริงและ Allowed Server IP ก่อน จุดนี้ไม่เกี่ยวกับ Webhook Secret', 'Verify the API key actually loaded by PHP and the Allowed Server IP. This is unrelated to the webhook secret.');
                    } elseif (!empty($item['transport_error'])) {
                        $hint = $t('เป็นปัญหาเครือข่าย, DNS, TLS หรือ PHP cURL ยังไปไม่ถึงระบบตรวจสิทธิ์ของ CHEATGAME', 'This is a network, DNS, TLS, or PHP cURL problem before CHEATGAME authentication.');
                    } elseif ((int) $item['http_code'] >= 200 && (int) $item['http_code'] < 300) {
                        $hint = $t('HTTP ผ่าน แต่รูปแบบ JSON หรือสถานะในคำตอบไม่ตรงตาม API ที่แจ้งไว้', 'HTTP succeeded, but the JSON shape or provider status did not match the documented API response.');
                    }
                }
            ?>
            <div class="rounded-lg p-4 <?php echo $item['ok'] ? 'bg-green-500/10 border border-green-500/30' : 'bg-red-500/10 border border-red-500/30'; ?>">
                <div class="flex items-center justify-between gap-2"><div class="font-semibold"><?php echo htmlspecialchars($testName, ENT_QUOTES, 'UTF-8'); ?></div><div class="text-xs font-mono">HTTP <?php echo (int) $item['http_code']; ?> · <?php echo (int) $item['total_time_ms']; ?> ms</div></div>
                <?php if ($item['ok']): ?>
                    <div class="mt-2 text-green-100"><?php echo htmlspecialchars((string) $item['summary'], ENT_QUOTES, 'UTF-8'); ?></div>
                <?php else: ?>
                    <div class="mt-2 font-medium text-red-100"><?php echo htmlspecialchars((string) ($item['error'] !== '' ? $item['error'] : $t('ไม่พบข้อความผิดพลาดจากปลายทาง', 'The provider returned no error message.')), ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php if ($hint !== ''): ?><div class="mt-2 text-xs text-amber-100"><?php echo htmlspecialchars($hint, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
                <?php endif; ?>
                <div class="mt-3 space-y-1 text-xs text-gray-400">
                    <?php if ($item['ip_mode'] !== ''): ?><div><?php echo $t('โหมดการเชื่อมต่อ', 'Connection mode'); ?>: <span class="font-mono text-white"><?php echo htmlspecialchars($item['ip_mode'], ENT_QUOTES, 'UTF-8'); ?></span></div><?php endif; ?>
                    <?php if ($item['provider_error_code'] !== ''): ?><div><?php echo $t('รหัสผิดพลาดจาก CHEATGAME', 'CHEATGAME error code'); ?>: <span class="font-mono text-white"><?php echo htmlspecialchars($item['provider_error_code'], ENT_QUOTES, 'UTF-8'); ?></span></div><?php endif; ?>
                    <?php if ($item['cf_ray'] !== ''): ?><div>Cloudflare Ray ID: <span class="font-mono text-white"><?php echo htmlspecialchars($item['cf_ray'], ENT_QUOTES, 'UTF-8'); ?></span></div><?php endif; ?>
                    <?php if ($item['request_id'] !== ''): ?><div>Request ID: <span class="font-mono text-white"><?php echo htmlspecialchars($item['request_id'], ENT_QUOTES, 'UTF-8'); ?></span></div><?php endif; ?>
                    <?php if ($item['observed_ip'] !== ''): ?><div><?php echo $t('IP ที่ CHEATGAME รายงาน', 'IP reported by CHEATGAME'); ?>: <span class="font-mono text-white"><?php echo htmlspecialchars($item['observed_ip'], ENT_QUOTES, 'UTF-8'); ?></span></div><?php endif; ?>
                    <?php if ($item['primary_ip'] !== ''): ?><div><?php echo $t('IP ปลายทางที่เชื่อมต่อ', 'Connected provider IP'); ?>: <span class="font-mono text-white"><?php echo htmlspecialchars($item['primary_ip'], ENT_QUOTES, 'UTF-8'); ?></span></div><?php endif; ?>
                    <?php if ($item['local_ip'] !== ''): ?><div><?php echo $t('IP interface ฝั่งเซิร์ฟเวอร์', 'Server interface IP'); ?>: <span class="font-mono text-white"><?php echo htmlspecialchars($item['local_ip'], ENT_QUOTES, 'UTF-8'); ?></span></div><?php endif; ?>
                </div>
                <?php if (!$item['ok'] && $item['excerpt'] !== ''): ?><pre class="mt-3 max-h-40 overflow-auto whitespace-pre-wrap break-words rounded bg-black/30 p-2 text-xs text-gray-300"><?php echo htmlspecialchars($item['excerpt'], ENT_QUOTES, 'UTF-8'); ?></pre><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <section id="cgo-api-logs" class="glass rounded-xl overflow-hidden">
        <div class="p-4 md:p-5 border-b border-white/10 flex flex-col lg:flex-row lg:items-start lg:justify-between gap-3">
            <div>
                <h2 class="font-semibold text-white"><?php echo $t('Timeline การเรียก API ของ Order ล่าสุด', 'Recent order API timeline'); ?></h2>
                <p class="text-xs text-gray-500 mt-1 max-w-4xl"><?php echo $t('ไม่เก็บคีย์สินค้าในตารางนี้ เก็บเฉพาะหลักฐาน transport/status เพื่อหาว่าเวลาหายที่ DNS, connect, TLS, TTFB หรือ provider processing', 'This table does not duplicate product keys. It stores transport/status evidence to locate delays in DNS, connect, TLS, TTFB, or provider processing.'); ?></p>
                <p class="text-[11px] text-amber-300/80 mt-1"><?php echo $t('ปุ่มล้างด้านขวาลบเฉพาะ diagnostic API logs ของ Order ที่จบแล้วเท่านั้น Log ของ Order ที่ยัง pending/unknown/processing/manual_review จะเก็บไว้เพราะใช้เป็นหลักฐาน recovery; ไม่ลบ Order, Transaction, Wallet Ledger, Key หรือ Webhook event-id', 'The clear button removes diagnostic API logs only for terminal orders. Logs for pending/unknown/processing/manual-review orders are retained because recovery depends on them. Orders, transactions, wallet ledger rows, keys, and webhook event IDs are never deleted.'); ?></p>
            </div>
            <form method="POST" class="shrink-0" onsubmit="return confirm('<?php echo htmlspecialchars($t('ยืนยันล้าง API diagnostic logs ของ Order ที่จบแล้ว? Log ของ Order ที่ยังไม่จบและข้อมูลการเงินจริงจะถูกเก็บไว้', 'Clear diagnostic logs for terminal orders? Unresolved-order recovery logs and all financial/business records will be preserved.'), ENT_QUOTES, 'UTF-8'); ?>');">
                <?php echo $csrf; ?><input type="hidden" name="action" value="clear_api_logs"><input type="hidden" name="return_anchor" value="cgo-api-logs">
                <button class="px-3 py-2 rounded-lg border border-red-500/30 bg-red-500/10 hover:bg-red-500/20 text-red-200 text-xs font-semibold"><i class="bi bi-trash3 mr-1"></i><?php echo $t('ล้าง Logs', 'Clear logs'); ?></button>
            </form>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-xs min-w-[1300px]">
                <thead class="bg-white/5 text-gray-400"><tr><th class="text-left p-3">Time</th><th class="text-left p-3">Order</th><th class="text-left p-3">Phase</th><th class="text-left p-3">HTTP/cURL</th><th class="text-left p-3">Timing ms</th><th class="text-left p-3">Provider</th><th class="text-left p-3">Identity</th><th class="text-left p-3">Decision</th></tr></thead>
                <tbody class="divide-y divide-white/5">
                <?php if (!$recentOrderApiAttempts): ?><tr><td colspan="8" class="p-6 text-center text-gray-500"><?php echo $t('ยังไม่มี API attempt จาก build ใหม่นี้', 'No API attempts have been recorded by this build yet.'); ?></td></tr><?php endif; ?>
                <?php foreach ($recentOrderApiAttempts as $attempt):
                    $diag=is_array($attempt['diagnostics'] ?? null)?$attempt['diagnostics']:[];
                    $transportDiag=is_array($diag['transport'] ?? null)?$diag['transport']:$diag;
                    $diagJson=json_encode($diag, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
                    if (!is_string($diagJson)) $diagJson='{}';
                ?>
                    <tr>
                        <td class="p-3 whitespace-nowrap"><?php echo htmlspecialchars((string) ($attempt['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="p-3"><div>#<?php echo (int) ($attempt['order_id'] ?? 0); ?></div><div class="font-mono text-[10px] max-w-[220px] break-all text-gray-500"><?php echo htmlspecialchars((string) ($attempt['external_ref'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div></td>
                        <td class="p-3"><div><?php echo htmlspecialchars((string) ($attempt['phase'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div><div class="text-gray-500"><?php echo htmlspecialchars((string) ($attempt['lookup_mode'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div></td>
                        <td class="p-3 font-mono">HTTP <?php echo (int) ($attempt['http_code'] ?? 0); ?> / <?php echo (int) ($attempt['curl_errno'] ?? 0); ?><?php if (!empty($attempt['transport_error'])): ?><div class="text-red-300">transport</div><?php endif; ?></td>
                        <td class="p-3 font-mono">DNS <?php echo (int) ($attempt['namelookup_time_ms'] ?? $transportDiag['namelookup_time_ms'] ?? 0); ?> · CON <?php echo (int) ($attempt['connect_time_ms'] ?? $transportDiag['connect_time_ms'] ?? 0); ?> · TLS <?php echo (int) ($attempt['appconnect_time_ms'] ?? $transportDiag['appconnect_time_ms'] ?? 0); ?> · TTFB <?php echo (int) ($attempt['starttransfer_time_ms'] ?? $transportDiag['starttransfer_time_ms'] ?? 0); ?> · ALL <?php echo (int) ($attempt['total_time_ms'] ?? $transportDiag['total_time_ms'] ?? 0); ?></td>
                        <td class="p-3"><div class="font-mono"><?php echo htmlspecialchars((string) ($attempt['provider_error_code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div><div class="text-gray-500"><?php echo htmlspecialchars((string) ($attempt['provider_status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div></td>
                        <td class="p-3"><div class="font-mono max-w-[220px] break-all"><?php echo htmlspecialchars((string) ($attempt['discovered_supplier_order_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div><div class="text-gray-500 max-w-[220px] break-all"><?php echo htmlspecialchars((string) ($attempt['echoed_external_ref'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div><div><?php echo (int) ($attempt['delivered_key_count'] ?? 0); ?> key(s)</div></td>
                        <td class="p-3"><div class="font-mono"><?php echo htmlspecialchars((string) ($attempt['decision'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div><?php if (!empty($attempt['error_message'])): ?><div class="text-red-300 mt-1 max-w-[300px] break-words"><?php echo htmlspecialchars((string) $attempt['error_message'], ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?><?php $attemptJsonId='apiAttemptJson_'.(int)($attempt['id']??0); ?><div class="mt-2 flex items-start gap-2"><button type="button" class="px-2 py-1 rounded bg-violet-600/80 hover:bg-violet-600 text-white text-[10px]" data-copy-json-target="<?php echo $attemptJsonId; ?>" data-copy-label="<?php echo htmlspecialchars($t('คัดลอก JSON', 'Copy JSON'), ENT_QUOTES, 'UTF-8'); ?>"><i class="bi bi-clipboard mr-1"></i><?php echo $t('คัดลอก JSON', 'Copy JSON'); ?></button><details class="flex-1"><summary class="cursor-pointer text-violet-300"><?php echo $t('ดู JSON log', 'View JSON log'); ?></summary><pre id="<?php echo $attemptJsonId; ?>" class="mt-2 max-h-64 max-w-[460px] overflow-auto whitespace-pre-wrap break-words rounded bg-black/30 p-2 text-[10px] text-gray-300"><?php echo htmlspecialchars($diagJson, ENT_QUOTES, 'UTF-8'); ?></pre></details></div></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section id="cgo-orders" class="glass rounded-xl overflow-hidden">
        <div class="p-4 md:p-5 border-b border-white/10"><h2 class="font-semibold text-white"><?php echo $t('คำสั่งซื้อล่าสุด', 'Recent orders'); ?></h2></div>
        <div class="overflow-x-auto">
            <table class="cgo-orders-table w-full text-sm min-w-[1000px]">
                <thead class="bg-white/5 text-gray-400"><tr><th class="text-left p-3">#</th><th class="text-left p-3"><?php echo $t('ลูกค้า', 'Customer'); ?></th><th class="text-left p-3"><?php echo $t('สินค้า', 'Product'); ?></th><th class="text-left p-3"><?php echo $t('สถานะ', 'Status'); ?></th><th class="text-right p-3"><?php echo $t('ยอด', 'Amount'); ?></th><th class="text-left p-3"><?php echo $t('จัดการ', 'Action'); ?></th></tr></thead>
                <tbody class="divide-y divide-white/5">
                <?php if (!$orders): ?><tr><td colspan="6" class="p-8 text-center text-gray-500"><?php echo $t('ยังไม่มีคำสั่งซื้อ', 'No orders yet.'); ?></td></tr><?php endif; ?>
                <?php foreach ($orders as $order): ?>
                    <tr class="cgo-order-row">
                        <td class="p-3 font-mono" data-label="#"><?php echo (int) $order['id']; ?></td>
                        <td class="p-3" data-label="<?php echo htmlspecialchars($t('ลูกค้า', 'Customer'), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) $order['username'], ENT_QUOTES, 'UTF-8'); ?><div class="text-xs text-gray-500 break-all"><?php echo htmlspecialchars((string) $order['external_ref'], ENT_QUOTES, 'UTF-8'); ?></div></td>
                        <td class="p-3" data-label="<?php echo htmlspecialchars($t('สินค้า', 'Product'), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) $order['product_name'], ENT_QUOTES, 'UTF-8'); ?><div class="text-xs text-gray-500 break-all"><?php echo htmlspecialchars((string) ($order['supplier_order_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div></td>
                        <td class="p-3" data-label="<?php echo htmlspecialchars($t('สถานะ', 'Status'), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) $order['status'], ENT_QUOTES, 'UTF-8'); ?><?php if (!empty($order['error_message'])): ?><div class="text-xs text-red-300 mt-1 max-w-md"><?php echo htmlspecialchars((string) $order['error_message'], ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?></td>
                        <td class="p-3 text-right" data-label="<?php echo htmlspecialchars($t('ยอด', 'Amount'), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($formatBase($order['total_price_base']), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="p-3" data-label="<?php echo htmlspecialchars($t('จัดการ', 'Action'), ENT_QUOTES, 'UTF-8'); ?>"><?php if (in_array((string) $order['status'], ['unknown','processing','pending','manual_review'], true)): ?><form method="POST"><?php echo $csrf; ?><input type="hidden" name="action" value="reconcile_order"><input type="hidden" name="order_id" value="<?php echo (int) $order['id']; ?>"><button class="w-full bg-violet-500 hover:bg-violet-600 px-3 py-2 rounded text-white"><?php echo $t('ตรวจสถานะ', 'Check status'); ?></button></form><?php else: ?><span class="text-gray-500">—</span><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<script>
(function () {
    const localProducts = <?php echo json_encode($localProductOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE); ?> || [];
    const localVariants = <?php echo json_encode($localVariantOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE); ?> || [];
    const labels = {
        productPlaceholder: <?php echo json_encode($t('เลือกเมื่อใช้โหมดเชื่อมสินค้าเดิม', 'Select when linking an existing product'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        variantPlaceholder: <?php echo json_encode($t('สร้างตัวเลือกใหม่จากชื่อ API เช่น KEY 1 DAY', 'Create a new variant from the API name, such as KEY 1 DAY'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        noProducts: <?php echo json_encode($t('ไม่พบสินค้าหลักที่ตรงกับตัวกรอง', 'No local products match the filters'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        noVariants: <?php echo json_encode($t('ไม่พบตัวเลือกของสินค้านี้', 'No variants match this product'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        submitting: <?php echo json_encode($t('กำลังบันทึก...', 'Saving...'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
    };

    function normalized(value) {
        return String(value || '').trim().toLocaleLowerCase();
    }

    function appendOption(select, value, text, selected) {
        const option = document.createElement('option');
        option.value = String(value);
        option.textContent = text;
        option.selected = Boolean(selected);
        select.appendChild(option);
    }

    function initCatalogueForm(form) {
        if (!form || form.dataset.cgoInitialized === '1') return;
        form.dataset.cgoInitialized = '1';
        const mode = form.querySelector('.js-cgo-mode');
        const product = form.querySelector('.js-cgo-product');
        const variant = form.querySelector('.js-cgo-variant');
        const productSearch = form.querySelector('.js-cgo-product-search');
        const productCategory = form.querySelector('.js-cgo-product-category');
        const variantSearch = form.querySelector('.js-cgo-variant-search');
        const syncPrice = form.querySelector('.js-cgo-sync-price');
        const initialPrices = form.querySelector('.js-cgo-initial-prices');
        if (!mode || !product || !variant) return;

        let selectedProductId = parseInt(product.dataset.selectedId || product.value || '0', 10) || 0;
        let selectedVariantId = parseInt(variant.dataset.selectedId || variant.value || '0', 10) || 0;

        function refreshInitialPrices() {
            if (!initialPrices || !syncPrice) return;
            const needsInitialPrices = variant.value === '0' && !syncPrice.checked;
            initialPrices.classList.toggle('hidden', !needsInitialPrices);
            initialPrices.querySelectorAll('input').forEach(function (input) {
                input.disabled = !needsInitialPrices;
                input.required = needsInitialPrices;
            });
        }

        function populateVariants(preferredId) {
            const productId = parseInt(product.value || '0', 10) || 0;
            const rawQuery = normalized(variantSearch ? variantSearch.value : '');
            const query = rawQuery.length >= 2 ? rawQuery : '';
            const previous = parseInt(preferredId || variant.value || selectedVariantId || '0', 10) || 0;
            variant.textContent = '';
            appendOption(variant, 0, labels.variantPlaceholder, previous === 0);

            if (mode.value === 'create' || productId < 1) {
                variant.disabled = true;
                variant.value = '0';
                refreshInitialPrices();
                return;
            }

            const matches = localVariants.filter(function (item) {
                if (Number(item.product_id) !== productId) return false;
                if (!query) return true;
                const haystack = normalized('#' + item.id + ' ' + item.name + ' ' + item.duration + ' ' + item.status);
                return haystack.includes(query);
            });
            matches.forEach(function (item) {
                appendOption(variant, item.id, '#' + item.id + ' · ' + item.name + ' — ' + item.duration, Number(item.id) === previous);
            });
            if (matches.length === 0 && query) appendOption(variant, '', labels.noVariants, false);
            variant.disabled = false;
            if (!Array.from(variant.options).some(function (option) { return option.value === String(previous); })) {
                variant.value = '0';
            }
            selectedVariantId = parseInt(variant.value || '0', 10) || 0;
            refreshInitialPrices();
        }

        function populateProducts(preferredId) {
            const creating = mode.value === 'create';
            const rawQuery = normalized(productSearch ? productSearch.value : '');
            const query = rawQuery.length >= 2 ? rawQuery : '';
            const category = normalized(productCategory ? productCategory.value : '');
            const previous = parseInt(preferredId || product.value || selectedProductId || '0', 10) || 0;
            product.textContent = '';
            appendOption(product, 0, labels.productPlaceholder, previous === 0);

            if (creating) {
                product.disabled = true;
                product.value = '0';
                selectedProductId = 0;
                populateVariants(0);
                return;
            }

            const matches = localProducts.filter(function (item) {
                const categories = Array.isArray(item.categories) ? item.categories.map(normalized) : [];
                if (category && !categories.includes(category)) return false;
                if (!query) return true;
                const haystack = normalized('#' + item.id + ' ' + item.name + ' ' + categories.join(' ') + ' ' + item.status);
                return haystack.includes(query);
            });
            matches.forEach(function (item) {
                const categoryText = Array.isArray(item.categories) && item.categories.length ? ' · ' + item.categories.join(', ') : '';
                appendOption(product, item.id, '#' + item.id + ' · ' + item.name + categoryText, Number(item.id) === previous);
            });
            if (matches.length === 0 && (query || category)) appendOption(product, '', labels.noProducts, false);
            product.disabled = false;
            if (!Array.from(product.options).some(function (option) { return option.value === String(previous); })) {
                product.value = '0';
            }
            selectedProductId = parseInt(product.value || '0', 10) || 0;
            populateVariants(selectedVariantId);
        }

        mode.addEventListener('change', function () {
            if (mode.value === 'create') {
                selectedProductId = 0;
                selectedVariantId = 0;
            }
            populateProducts(selectedProductId);
        });
        product.addEventListener('change', function () {
            selectedProductId = parseInt(product.value || '0', 10) || 0;
            selectedVariantId = 0;
            populateVariants(0);
        });
        variant.addEventListener('change', function () {
            selectedVariantId = parseInt(variant.value || '0', 10) || 0;
            refreshInitialPrices();
        });
        const localSearchTimers = new WeakMap();
        function scheduleLocalSearch(field, callback) {
            const previousTimer = localSearchTimers.get(field);
            if (previousTimer) window.clearTimeout(previousTimer);
            localSearchTimers.delete(field);

            const timer = window.setTimeout(function () {
                localSearchTimers.delete(field);
                if (!field.isConnected || !form.isConnected) return;
                callback();
            }, 280);
            localSearchTimers.set(field, timer);
        }

        if (productSearch) productSearch.addEventListener('input', function () {
            scheduleLocalSearch(productSearch, function () { populateProducts(selectedProductId); });
        });
        if (productCategory) productCategory.addEventListener('change', function () { populateProducts(selectedProductId); });
        if (variantSearch) variantSearch.addEventListener('input', function () {
            scheduleLocalSearch(variantSearch, function () { populateVariants(selectedVariantId); });
        });
        if (syncPrice) syncPrice.addEventListener('change', refreshInitialPrices);

        populateProducts(selectedProductId);
        refreshInitialPrices();
    }

    function bindCatalogueDetails(root) {
        (root || document).querySelectorAll('.cgo-catalog-details').forEach(function (details) {
            if (details.dataset.cgoToggleBound === '1') return;
            details.dataset.cgoToggleBound = '1';
            details.addEventListener('toggle', function () {
                if (details.open) initCatalogueForm(details.querySelector('.js-cgo-catalog-form'));
            });
            if (details.open) initCatalogueForm(details.querySelector('.js-cgo-catalog-form'));
        });
    }

    bindCatalogueDetails(document);
    document.addEventListener('instantfilter:updated', function () {
        bindCatalogueDetails(document);
    });

    const tools = document.getElementById('cgo-admin-tools');
    if (tools && window.matchMedia('(max-width: 767px)').matches && window.location.hash !== '#cgo-admin-tools') {
        tools.open = false;
    }

    document.addEventListener('submit', function (event) {
        if (event.defaultPrevented) return;
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || String(form.method).toLowerCase() !== 'post') return;
        const row = form.closest('[data-cgo-product-card]');
        const actionInput = form.querySelector('input[name="action"]');
        const action = actionInput ? actionInput.value : '';
        const nearestSection = form.closest('[id]');
        let anchor = row && row.id
            ? row.id
            : (nearestSection && nearestSection.id
                ? nearestSection.id
                : (action === 'reconcile_order' ? 'cgo-orders' : 'cgo-products'));

        function setHidden(name, value) {
            let input = form.querySelector('input[name="' + name + '"]');
            if (!input) {
                input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                form.appendChild(input);
            }
            input.value = value;
        }
        setHidden('return_query', window.location.search.replace(/^\?/, ''));
        setHidden('return_anchor', anchor);

        const anchorNode = document.getElementById(anchor);
        try {
            sessionStorage.setItem('cgoAdminReturnState', JSON.stringify({
                path: window.location.pathname,
                query: window.location.search,
                anchor: anchor,
                rowTop: anchorNode ? anchorNode.getBoundingClientRect().top : 80,
                scrollY: window.scrollY,
                action: action,
                expires: Date.now() + 120000
            }));
        } catch (ignored) {}

        form.querySelectorAll('button[type="submit"], button:not([type])').forEach(function (button) {
            button.disabled = true;
            button.dataset.originalText = button.textContent;
            button.textContent = labels.submitting;
            button.classList.add('opacity-60', 'cursor-wait');
        });
    });

    function restorePosition() {
        let state = null;
        try { state = JSON.parse(sessionStorage.getItem('cgoAdminReturnState') || 'null'); } catch (ignored) {}
        if (!state || state.expires < Date.now() || state.path !== window.location.pathname || state.query !== window.location.search) return;
        let target = document.getElementById(state.anchor);
        if (!target) target = document.getElementById('cgo-products');
        if (!target) {
            window.scrollTo({ top: Math.max(0, Number(state.scrollY) || 0), behavior: 'auto' });
            try { sessionStorage.removeItem('cgoAdminReturnState'); } catch (ignored) {}
            return;
        }
        if (state.anchor === 'cgo-admin-tools' && target instanceof HTMLDetailsElement) {
            target.open = true;
        }
        if (state.action === 'publish_catalog') {
            const details = target.querySelector('.cgo-catalog-details');
            if (details) {
                details.open = true;
                initCatalogueForm(details.querySelector('.js-cgo-catalog-form'));
            }
        }
        window.setTimeout(function () {
            const originalTargetFound = target.id === state.anchor;
            const top = originalTargetFound
                ? target.getBoundingClientRect().top + window.scrollY - Math.max(12, Number(state.rowTop) || 80)
                : Math.max(0, Number(state.scrollY) || target.offsetTop || 0);
            window.scrollTo({ top: Math.max(0, top), behavior: 'auto' });
            target.classList.add('cgo-highlight');
            window.setTimeout(function () { target.classList.remove('cgo-highlight'); }, 2200);
            try { sessionStorage.removeItem('cgoAdminReturnState'); } catch (ignored) {}
        }, 160);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', restorePosition);
    else restorePosition();
})();
</script>

<script>
(function () {
    const ttlSeconds = <?php echo (int) $inventoryTtlSeconds; ?>;
    const initialAgeSeconds = <?php echo (int) $inventoryAgeSeconds; ?>;
    const initialDelayMs = <?php echo (int) $inventoryInitialDelayMs; ?>;
    let csrfToken = <?php echo json_encode($inventoryCsrfToken, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const statusNode = document.getElementById('cgoInventoryAutoStatus');
    const lastSuccessNode = document.querySelector('[data-cgo-last-success]');
    const ageNode = document.querySelector('[data-cgo-age]');
    const lastErrorNode = document.getElementById('cgoInventoryLastError');
    const text = {
        ready: <?php echo json_encode($t('ระบบอัปเดตอัตโนมัติพร้อมทำงาน', 'Automatic inventory refresh is ready.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        checking: <?php echo json_encode($t('กำลังซิงก์สต็อก CHEATGAME เบื้องหลัง...', 'Refreshing CHEATGAME inventory in the background...'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        success: <?php echo json_encode($t('ซิงก์สต็อกอัตโนมัติสำเร็จแล้ว', 'Automatic inventory refresh completed.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        failed: <?php echo json_encode($t('ซิงก์สต็อกอัตโนมัติไม่สำเร็จ ระบบจะลองใหม่ภายใน 30 วินาที', 'Automatic inventory refresh failed. The system will retry within 30 seconds.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        busy: <?php echo json_encode($t('มีคำขออื่นกำลังซิงก์สต็อกอยู่ ระบบจะตรวจผลอีกครั้ง', 'Another request is refreshing inventory. The system will check again shortly.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
    };

    let refreshing = false;
    let timer = null;
    let nextRefreshAt = Date.now() + initialDelayMs;
    let lastSuccessAt = Date.now() - (initialAgeSeconds * 1000);

    function setStatus(kind, message) {
        if (!statusNode) return;
        statusNode.textContent = message;
        statusNode.classList.remove('text-cyan-200/80', 'text-green-300', 'text-amber-200');
        if (kind === 'success') statusNode.classList.add('text-green-300');
        else if (kind === 'error') statusNode.classList.add('text-amber-200');
        else statusNode.classList.add('text-cyan-200/80');
    }

    function schedule(delayMs) {
        if (timer) window.clearTimeout(timer);
        const safeDelay = Math.max(500, Number(delayMs) || 0);
        nextRefreshAt = Date.now() + safeDelay;
        timer = window.setTimeout(checkDue, safeDelay);
    }

    function updateAge() {
        if (!ageNode) return;
        ageNode.textContent = String(Math.max(0, Math.floor((Date.now() - lastSuccessAt) / 1000)));
    }

    function applySnapshot(snapshot) {
        const rows = snapshot && typeof snapshot === 'object' ? snapshot : {};
        document.querySelectorAll('[data-cgo-admin-product-id]').forEach(function (holder) {
            const id = String(parseInt(holder.getAttribute('data-cgo-admin-product-id') || '0', 10) || 0);
            const row = rows[id];
            if (!row) return;
            const stockNode = holder.querySelector('[data-cgo-admin-stock]');
            if (stockNode) stockNode.textContent = row.stock === null || typeof row.stock === 'undefined' ? 'N/A' : String(Math.max(0, parseInt(row.stock, 10) || 0));
            const status = document.querySelector('[data-cgo-admin-status-for="' + id + '"]');
            if (status && typeof row.status === 'string') status.textContent = row.status;
        });
    }

    async function renewCsrfToken() {
        try {
            const response = await fetch('../cgo_inventory.php?action=token', {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await response.json();
            if (response.ok && data && data.success && typeof data.csrf_token === 'string') {
                csrfToken = data.csrf_token;
                return true;
            }
        } catch (ignored) {}
        return false;
    }

    async function requestInventory(allowTokenRenewal) {
        const response = await fetch('../cgo_inventory.php', {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': csrfToken
            }
        });
        if (response.status === 403 && allowTokenRenewal && await renewCsrfToken()) {
            return requestInventory(false);
        }
        let data = null;
        try { data = await response.json(); } catch (ignored) {}
        return { response, data };
    }

    async function refreshInventory() {
        if (refreshing) return;
        if (document.visibilityState === 'hidden') {
            schedule(5000);
            return;
        }
        refreshing = true;
        setStatus('checking', text.checking);
        try {
            const result = await requestInventory(true);
            const response = result.response;
            const data = result.data;

            if (response.ok && data && data.success) {
                applySnapshot(data.snapshot || {});
                const state = data.state && typeof data.state === 'object' ? data.state : {};
                if (lastSuccessNode && state.last_success_at) lastSuccessNode.textContent = String(state.last_success_at);
                const age = Number.isFinite(Number(state.age_seconds)) ? Math.max(0, Number(state.age_seconds)) : 0;
                lastSuccessAt = Date.now() - (age * 1000);
                updateAge();
                if (lastErrorNode) {
                    lastErrorNode.textContent = '';
                    lastErrorNode.classList.add('hidden');
                }
                setStatus('success', text.success);
                window.setTimeout(function () { setStatus('ready', text.ready); }, 3000);
                schedule(Math.max(30, Number(data.ttl_seconds || ttlSeconds)) * 1000);
                return;
            }

            if (response.status === 202 && data && data.busy) {
                setStatus('ready', text.busy);
                schedule(Math.max(1, Math.min(5, Number(data.retry_after || 2))) * 1000);
                return;
            }

            setStatus('error', text.failed);
            if (lastErrorNode) {
                lastErrorNode.textContent = data && data.message ? String(data.message) : text.failed;
                lastErrorNode.classList.remove('hidden');
            }
            schedule(30000);
        } catch (error) {
            setStatus('error', text.failed);
            if (lastErrorNode) {
                lastErrorNode.textContent = text.failed;
                lastErrorNode.classList.remove('hidden');
            }
            schedule(30000);
        } finally {
            refreshing = false;
        }
    }

    function checkDue() {
        if (Date.now() >= nextRefreshAt - 250) refreshInventory();
        else schedule(nextRefreshAt - Date.now());
    }

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible' && Date.now() >= nextRefreshAt) refreshInventory();
    });
    window.addEventListener('focus', function () {
        if (Date.now() >= nextRefreshAt) refreshInventory();
    });
    window.setInterval(updateAge, 1000);
    updateAge();
    schedule(initialDelayMs);
})();
</script>
<script>
(function () {
    async function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
            return;
        }
        const area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', 'readonly');
        area.style.position = 'fixed';
        area.style.left = '-9999px';
        document.body.appendChild(area);
        area.select();
        const ok = document.execCommand('copy');
        document.body.removeChild(area);
        if (!ok) throw new Error('copy failed');
    }

    document.addEventListener('click', async function (event) {
        const button = event.target.closest('[data-copy-json-target]');
        if (!button) return;
        const targetId = String(button.getAttribute('data-copy-json-target') || '');
        const target = targetId ? document.getElementById(targetId) : null;
        if (!target) return;
        const original = String(button.getAttribute('data-copy-label') || button.textContent || 'Copy JSON').trim();
        try {
            await copyText(target.textContent || '');
            button.textContent = '<?php echo addslashes($t('คัดลอกแล้ว ✓', 'Copied ✓')); ?>';
        } catch (error) {
            button.textContent = '<?php echo addslashes($t('คัดลอกไม่สำเร็จ', 'Copy failed')); ?>';
        }
        window.setTimeout(function () { button.textContent = original; }, 1600);
    });
})();
</script>
<script src="../assets/js/instant-filter.js?v=3.0"></script>
</body>
</html>
