<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/store_bridge.php';

requireReseller();

$userId = (int) ($_SESSION['user_id'] ?? 0);
$schemaReady = storeBridgeEnsureSchema();
$program = storeBridgeResellerApiSettings();
$allowed = $schemaReady && storeBridgeResellerApiUserAllowed($userId, $program);

if (!$schemaReady || !$allowed) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo getAppLang() === 'th'
        ? "บัญชีนี้ยังไม่สามารถดาวน์โหลดเครื่องมือ Store API ได้\n"
        : "This account cannot download Store API tools yet.\n";
    exit;
}

$file = isset($_GET['file']) && is_scalar($_GET['file'])
    ? strtolower(trim((string) $_GET['file']))
    : '';
$endpoint = supplierBridgeCurrentEndpoint();
$isTh = getAppLang() === 'th';

if (!function_exists('resellerApiDownloadText')) {
    function resellerApiDownloadText(string $filename, string $content, string $contentType = 'text/plain; charset=UTF-8'): void
    {
        if (!headers_sent()) {
            header('Content-Type: ' . $contentType);
            header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', $filename) . '"');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Content-Length: ' . strlen($content));
        }
        echo $content;
        exit;
    }
}

if ($file === 'guide') {
    if ($isTh) {
        $guide = <<<TXT
SAKAZUKI STORE API - คู่มือแบบสั้น
=================================

Endpoint
{$endpoint}

สิ่งที่ต้องมี
1. API Key จากหน้า Store API ของบัญชีตัวแทน
2. เว็บไซต์ของคุณต้องเรียก API จากฝั่ง Server/Hosting ไม่ใช่ JavaScript หน้าเว็บ
3. Request ที่ยืนยันตัวตนให้ส่ง Header: X-API-Key: API_KEY_ของคุณ
4. ใช้ HTTPS และ JSON UTF-8

ขั้นตอนพื้นฐาน
1. GET ?action=products เพื่อดึงรายการสินค้า
2. GET ?action=balance เพื่อตรวจยอดคงเหลือ
3. POST Order เป็น JSON ไปที่ Endpoint เดิม
4. ทุก Order ต้องมี external_ref ที่ไม่ซ้ำ เช่น MYSHOP-10001
5. ถ้าได้ HTTP 202 / status=processing ให้เช็ก order_status ด้วย external_ref เดิม
6. ห้ามสร้าง external_ref ใหม่เพื่อยิงซื้อซ้ำ เพราะ Order เดิมอาจถูกสร้างไปแล้ว

ตัวอย่าง JSON ตอนสั่งซื้อ
{
  "action": "order",
  "external_ref": "MYSHOP-10001",
  "product_id": "variant-103",
  "quantity": 1
}

ตัวอย่างสำเร็จ
HTTP 200
{
  "success": true,
  "data": {
    "status": "success",
    "keys": ["KEY-EXAMPLE"]
  },
  "request_id": "req_..."
}

ตัวอย่างยังประมวลผล
HTTP 202
{
  "success": true,
  "code": "processing",
  "data": {
    "status": "processing",
    "keys": []
  },
  "request_id": "req_..."
}

HTTP หมายถึงอะไร
200       สำเร็จ อ่าน JSON แล้วใช้งานผลลัพธ์
202       รับ Order แล้วแต่ยัง Processing ให้เช็ก Ref เดิม ห้ามซื้อซ้ำ
400       JSON หรือ Request ผิดรูปแบบ
401       API Key ไม่มี/ผิด/เป็นคีย์เก่า
402       ยอดคงเหลือไม่พอ
403       บัญชี/API/IP/วงเงินไม่อนุญาต ให้ดู code ใน JSON
404       ไม่พบ Product, Order หรือ Action
409       สถานะทางธุรกิจชนกัน เช่นของหมด หรือ external_ref เดิมแต่ข้อมูลไม่ตรง
413/422   Request ใหญ่เกินไป หรือข้อมูลไม่ผ่าน Validation
429       เรียก API ถี่เกิน Rate Limit
500       Server Error ให้เก็บ request_id และ JSON Log
503       ระบบ/ฐานข้อมูล/API ต้นทางไม่พร้อมชั่วคราว
ไม่มี HTTP หรือ HTTP 0  ยังไม่ได้ Response มักเป็น DNS/TLS/Network/Timeout

เวลาแจ้งปัญหา
ส่ง HTTP status, code, message, request_id, external_ref และ JSON Log ให้ผู้ดูแล
ไม่ต้องส่ง API Key

ทดสอบจาก Shared Hosting
ดาวน์โหลดไฟล์ sakazuki_host_test.php จากหน้า Store API, อัปโหลดไปยัง Hosting ของร้าน,
เปิดผ่าน Browser แล้วใส่ API Key เพื่อทดสอบจริงจาก Server ของร้านคุณ
เครื่องมือนี้เป็น Read-only: ไม่สร้าง Order และไม่หักเงิน
หลังทดสอบเสร็จควรลบไฟล์ Tester ออกจาก Hosting
TXT;
    } else {
        $guide = <<<TXT
SAKAZUKI STORE API - QUICK GUIDE
================================

Endpoint
{$endpoint}

What you need
1. Your reseller Store API key.
2. Call the API from your server/hosting backend, not frontend JavaScript.
3. Authenticated requests send: X-API-Key: YOUR_API_KEY
4. Use HTTPS and UTF-8 JSON.

Basic flow
1. GET ?action=products to fetch products.
2. GET ?action=balance to check balance.
3. POST orders as JSON to the same endpoint.
4. Every order needs your unique external_ref, e.g. MYSHOP-10001.
5. If you receive HTTP 202 / status=processing, query order_status using the SAME external_ref.
6. Never create a new external_ref just to retry the same order. The original order may already exist.

Order JSON example
{
  "action": "order",
  "external_ref": "MYSHOP-10001",
  "product_id": "variant-103",
  "quantity": 1
}

Successful response
HTTP 200
{
  "success": true,
  "data": {
    "status": "success",
    "keys": ["KEY-EXAMPLE"]
  },
  "request_id": "req_..."
}

Processing response
HTTP 202
{
  "success": true,
  "code": "processing",
  "data": {
    "status": "processing",
    "keys": []
  },
  "request_id": "req_..."
}

HTTP status summary
200       Success. Use the returned JSON.
202       Order accepted and still processing. Check the SAME reference; do not duplicate it.
400       Invalid JSON/request.
401       Missing, invalid, or old API key.
402       Insufficient balance.
403       Account/API/IP/limit denied. Read the JSON code.
404       Product, order, or action not found.
409       Business conflict, out of stock, or idempotency mismatch.
413/422   Request too large or validation failed.
429       Rate limited.
500       Server error. Keep request_id and the JSON log.
503       Service/database/upstream temporarily unavailable.
No HTTP / HTTP 0  No response was received; usually DNS/TLS/network/timeout.

When reporting a problem
Send HTTP status, code, message, request_id, external_ref, and the JSON log.
Do not send your API key.

Shared-hosting test
Download sakazuki_host_test.php from the Store API page, upload it to your storefront hosting,
open it in a browser, and enter the API key. The test runs from your actual hosting server.
It is read-only: it never creates orders or debits balance.
Delete the tester from your hosting after testing.
TXT;
    }
    resellerApiDownloadText('sakazuki-store-api-guide.txt', $guide . "\n");
}

if ($file === 'hosting_tester') {
    $endpointLiteral = var_export($endpoint, true);
    $tester = <<<'TESTER'
<?php
/**
 * Sakazuki Store API - Shared Hosting Tester
 * Read-only diagnostic: diagnostic, balance, products.
 * It never sends an order request and never debits balance.
 * Delete this file from your hosting after testing.
 */

declare(strict_types=1);

const SAKAZUKI_API_ENDPOINT = __SAKAZUKI_ENDPOINT__;

header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function jsonPretty($value): string
{
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    return is_string($json) ? $json : '{}';
}

function requestReadOnly(string $action, string $apiKey): array
{
    $result = [
        'action' => $action,
        'success' => false,
        'http_code' => 0,
        'duration_ms' => 0,
        'curl_errno' => 0,
        'network_error' => '',
        'response' => null,
    ];

    if (!function_exists('curl_init')) {
        $result['network_error'] = 'PHP cURL extension is not enabled on this hosting.';
        return $result;
    }

    $url = SAKAZUKI_API_ENDPOINT . '?action=' . rawurlencode($action);
    $ch = curl_init($url);
    if ($ch === false) {
        $result['network_error'] = 'Unable to initialize PHP cURL.';
        return $result;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'X-API-Key: ' . $apiKey,
        ],
        CURLOPT_USERAGENT => 'Sakazuki-SharedHosting-Tester/1.0',
        CURLOPT_ENCODING => '',
    ]);

    $started = microtime(true);
    $body = curl_exec($ch);
    $info = curl_getinfo($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    curl_close($ch);

    $result['duration_ms'] = (int) round((microtime(true) - $started) * 1000);
    $result['http_code'] = max(0, (int) ($info['http_code'] ?? 0));
    $result['curl_errno'] = max(0, (int) $errno);
    $result['network_error'] = substr(trim((string) $error), 0, 500);

    if (is_string($body) && $body !== '') {
        $decoded = json_decode($body, true);
        if (is_array($decoded) && json_last_error() === JSON_ERROR_NONE) {
            if ($action === 'products') {
                $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
                $products = is_array($data['products'] ?? null) ? $data['products'] : [];
                $sample = [];
                foreach (array_slice($products, 0, 3) as $item) {
                    if (!is_array($item)) continue;
                    $sample[] = [
                        'id' => $item['id'] ?? $item['product_id'] ?? null,
                        'name' => $item['name'] ?? $item['product_name'] ?? null,
                        'status' => $item['status'] ?? null,
                        'stock' => $item['stock'] ?? $item['available_stock'] ?? null,
                    ];
                }
                $result['response'] = [
                    'success' => $decoded['success'] ?? null,
                    'code' => $decoded['code'] ?? null,
                    'message' => $decoded['message'] ?? null,
                    'request_id' => $decoded['request_id'] ?? null,
                    'data' => [
                        'count' => isset($data['count']) ? (int) $data['count'] : count($products),
                        'sample' => $sample,
                    ],
                ];
            } else {
                $result['response'] = $decoded;
            }
        } else {
            $result['response_preview'] = substr(trim(strip_tags($body)), 0, 1500);
        }
    }

    $result['success'] = $errno === 0
        && $result['http_code'] === 200
        && is_array($result['response'])
        && !empty($result['response']['success']);

    return $result;
}

function explainHttp(int $http): string
{
    $map = [
        200 => 'Success',
        202 => 'Accepted / processing. For orders, check the same external_ref; do not duplicate the order.',
        400 => 'Invalid JSON or request',
        401 => 'Missing or invalid API key',
        402 => 'Insufficient balance',
        403 => 'API/account/IP/limit denied; inspect the JSON code',
        404 => 'Product/order/action not found',
        409 => 'Business conflict or idempotency mismatch',
        413 => 'Request too large',
        422 => 'Validation failed',
        429 => 'Rate limited',
        500 => 'Server error',
        503 => 'Service/database/upstream temporarily unavailable',
    ];
    return $http === 0 ? 'No HTTP response: usually DNS/TLS/network/timeout' : ($map[$http] ?? 'Read the JSON code/message for details');
}

$report = null;
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $apiKey = isset($_POST['api_key']) && is_scalar($_POST['api_key']) ? trim((string) $_POST['api_key']) : '';
    if ($apiKey === '') {
        $error = 'กรุณาใส่ API Key / Enter your API key.';
    } elseif (strlen($apiKey) > 500) {
        $error = 'API Key ยาวผิดปกติ / API key is unexpectedly long.';
    } else {
        $tests = [];
        foreach (['diagnostic', 'balance', 'products'] as $action) {
            $tests[$action] = requestReadOnly($action, $apiKey);
            $tests[$action]['http_meaning'] = explainHttp((int) ($tests[$action]['http_code'] ?? 0));
        }
        $allOk = true;
        foreach ($tests as $test) {
            if (empty($test['success'])) { $allOk = false; break; }
        }
        $report = [
            'success' => $allOk,
            'generated_at' => date('c'),
            'tester' => 'sakazuki_shared_hosting_tester_v1',
            'endpoint' => SAKAZUKI_API_ENDPOINT,
            'hosting' => [
                'hostname' => function_exists('gethostname') ? (gethostname() ?: null) : null,
                'server_addr' => $_SERVER['SERVER_ADDR'] ?? null,
                'php_version' => PHP_VERSION,
                'curl_available' => function_exists('curl_init'),
            ],
            'security' => [
                'api_key_included_in_report' => false,
                'order_request_performed' => false,
                'balance_debit_performed_by_tester' => false,
            ],
            'tests' => $tests,
        ];
        unset($apiKey);
    }
}

if (isset($_GET['download']) && $_GET['download'] === 'json' && isset($_POST['report_json']) && is_scalar($_POST['report_json'])) {
    $candidate = (string) $_POST['report_json'];
    $decoded = json_decode($candidate, true);
    if (is_array($decoded) && json_last_error() === JSON_ERROR_NONE) {
        $out = jsonPretty($decoded) . "\n";
        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Disposition: attachment; filename="sakazuki-hosting-test.json"');
        header('X-Content-Type-Options: nosniff');
        echo $out;
        exit;
    }
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sakazuki Shared Hosting API Test</title>
<style>
body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#0f172a;color:#e5e7eb;margin:0;padding:24px}.wrap{max-width:900px;margin:auto}.card{background:#111827;border:1px solid #334155;border-radius:14px;padding:20px;margin-bottom:16px}input,button{font:inherit}input{width:100%;box-sizing:border-box;background:#0b1220;color:#fff;border:1px solid #475569;border-radius:10px;padding:12px}button{background:#2563eb;color:#fff;border:0;border-radius:10px;padding:11px 16px;cursor:pointer}.muted{color:#94a3b8}.ok{color:#86efac}.bad{color:#fca5a5}.warn{color:#fde68a}pre{white-space:pre-wrap;word-break:break-word;background:#020617;border-radius:10px;padding:14px;max-height:520px;overflow:auto}.row{display:flex;gap:10px;flex-wrap:wrap}.row form{margin:0}.small{font-size:13px;line-height:1.6}code{word-break:break-all}h1{font-size:24px}
</style>
</head>
<body><div class="wrap">
<div class="card">
<h1>Sakazuki Store API - Shared Hosting Tester</h1>
<p>ใช้หน้านี้เพื่อทดสอบจาก Hosting จริงของร้านคุณ ไม่ต้องใช้ SSH หรือ Terminal</p>
<p class="muted">This test runs from your actual hosting server. No SSH or terminal is required.</p>
<p class="warn"><strong>Read-only:</strong> ทดสอบเฉพาะ diagnostic, balance และ products เท่านั้น ไม่มีการสร้าง Order และไม่มีการหักเงิน</p>
<p class="bad"><strong>หลังทดสอบเสร็จ ให้ลบไฟล์นี้ออกจาก Hosting</strong> / Delete this tester from your hosting after testing.</p>
<p class="small muted">Endpoint: <code><?=h(SAKAZUKI_API_ENDPOINT)?></code><br>ตัวสคริปต์นี้ไม่เขียน API Key ลงไฟล์/ฐานข้อมูล และจะไม่ใส่ API Key ลงใน JSON report.</p>
</div>
<div class="card">
<form method="post" autocomplete="off">
<label for="api_key"><strong>API Key</strong></label>
<input id="api_key" name="api_key" type="password" autocomplete="off" required placeholder="วาง API Key ที่นี่ / Paste API key here">
<p><button type="submit">ทดสอบ API อัตโนมัติ / Run API test</button></p>
</form>
<?php if ($error !== ''): ?><p class="bad"><?=h($error)?></p><?php endif; ?>
</div>
<?php if (is_array($report)): $reportJson = jsonPretty($report); ?>
<div class="card">
<h2 class="<?=$report['success'] ? 'ok' : 'bad'?>"><?=$report['success'] ? 'ผ่าน / PASS' : 'พบปัญหา / ISSUE FOUND'?></h2>
<?php foreach ($report['tests'] as $name => $test): ?>
<p><strong><?=h($name)?></strong>: <span class="<?=!empty($test['success']) ? 'ok' : 'bad'?>">HTTP <?=h((string)($test['http_code'] ?? 0))?> - <?=h((string)($test['http_meaning'] ?? ''))?></span> (<?=h((string)($test['duration_ms'] ?? 0))?> ms)</p>
<?php endforeach; ?>
<div class="row">
<button type="button" onclick="copyReport()">คัดลอก JSON / Copy JSON</button>
<form method="post" action="?download=json"><input type="hidden" name="report_json" value="<?=h($reportJson)?>"><button type="submit">ดาวน์โหลด JSON / Download JSON</button></form>
</div>
<pre id="report"><?=h($reportJson)?></pre>
</div>
<script>async function copyReport(){const t=document.getElementById('report').textContent;try{await navigator.clipboard.writeText(t)}catch(e){const a=document.createElement('textarea');a.value=t;document.body.appendChild(a);a.select();document.execCommand('copy');a.remove()}}</script>
<?php endif; ?>
</div></body></html>
TESTER;
    $tester = str_replace('__SAKAZUKI_ENDPOINT__', $endpointLiteral, $tester);
    resellerApiDownloadText('sakazuki_host_test.php', $tester . "\n", 'application/octet-stream');
}

http_response_code(404);
header('Content-Type: text/plain; charset=UTF-8');
echo $isTh ? "ไม่พบไฟล์ที่ขอ\n" : "Requested file was not found.\n";
