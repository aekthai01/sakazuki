# Route inventory

This is a regenerated inventory of every PHP route candidate outside `/includes`, not a menu list or a claim that every file executes. The repository denies `/includes` and uploaded PHP in `.htaccess`; actual Apache/FPM configuration is UNKNOWN. All browser/DB execution remains UNTESTED. Includes and private workers are indexed in SOURCE_EVIDENCE.md.

Each entry retains literal guards, includes, forms, endpoints, and special states. A filename mention is only a candidate caller until its control flow is traced. Directory names do not establish role authorization. “No direct match” does not mean no inherited behavior. HTTP entry for fragments and nav files remains UNKNOWN.

## Confirmed source-level entry relationships

- `index.php` -> `redirectByRole`; User/Reseller -> buy.php; Admin -> dashboard.php.
- Role nav on eligible direct GET -> shell bridge -> `/app.php?path=...`; iframe pages continue normal navigation. `__shell=0` preserves intentional direct mode. POST is ineligible for direct bridge redirection.
- `user/dashboard.php` requires exact User role, whereas `user/buy.php` accepts User/Reseller/Admin. `requireReseller()` also accepts Admin.
- `user/api_store.php` redirects to buy.php. `reseller/api_store.php` serves API credential requests before browser role guard; it is not only a reseller HTML page.
- `line_callback.php` returns 404; it is not an active OAuth login screen.
- `user/dashboard_dynamic.php` is authenticated, but its only discovered client is dormant dashboard-live.js. Its content fragment is included there, not in dashboard.php.
- `health.php` and uploaded PHP are denied by the checked server rules. Production enforcement has not been observed.

## All 94 entry candidates

### `admin/api_client_products.php`

- Scope: admin; risk: **High**; runtime: **UNKNOWN / UNTESTED**.
- Shell: role nav bridge if rendered; actual request branch must be observed.
- Inline CSS: yes; inline JS: yes.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/admin/api_client_products.php:4](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_client_products.php#L4) requireAdmin(); |
| Includes / render ownership | [public_html/admin/api_client_products.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_client_products.php#L2) require_once __DIR__ . '/../includes/auth.php';<br>[public_html/admin/api_client_products.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_client_products.php#L3) require_once __DIR__ . '/../includes/store_bridge.php';<br>[public_html/admin/api_client_products.php:144](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_client_products.php#L144) &lt;?php include 'nav.php'; ?&gt; |
| Entry / redirect | [public_html/admin/api_client_products.php:46](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_client_products.php#L46) header('Location: api_client_products.php?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986), true, 303); |
| Direct CSS / JS (also inherit nav/includes above) | [public_html/admin/api_client_products.php:137](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_client_products.php#L137) &lt;link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3"&gt;<br>[public_html/admin/api_client_products.php:138](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_client_products.php#L138) &lt;link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css"&gt;<br>[public_html/admin/api_client_products.php:194](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_client_products.php#L194) &lt;script src="../assets/js/instant-filter.js?v=3.0"&gt;&lt;/script&gt; |
| Forms / actions | [public_html/admin/api_client_products.php:60](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_client_products.php#L60) $query = storeBridgeClientProductQuery(($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? $_POST : $_GET);<br>[public_html/admin/api_client_products.php:62](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_client_products.php#L62) if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {<br>[public_html/admin/api_client_products.php:64](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_client_products.php#L64) $action = isset($_POST['action']) && is_scalar($_POST['action']) ? (string) $_POST['action'] : '';<br>[public_html/admin/api_client_products.php:175](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_client_products.php#L175) &lt;form method="get" class="glass rounded-xl p-4 grid grid-cols-1 md:grid-cols-[minmax(260px,2fr)_minmax(200px,1fr)_minmax(170px,1fr)_auto] gap-3 items-end"&gt;<br>[public_html/admin/api_client_products.php:187](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_client_products.php#L187) &lt;tr class="align-top &lt;?php echo $hasStock ? '' : 'bg-red-950/10'; ?&gt;"&gt;&lt;td class="p-3"&gt;&lt;div class="font-semibold"&gt;&lt;?php echo $h($row['name']); ?&gt;&lt;/div&gt;&lt;div class="text-xs text-gray-400"&gt;#&lt;?php echo (int) $row['source_variant_id']; ?&gt; · &lt;?php |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | [public_html/admin/api_client_products.php:157](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_client_products.php#L157) &lt;div id="apiClientProductsLivePanel" data-instant-panel class="space-y-5"&gt; |

### `admin/api_hub.php`

- Scope: admin; risk: **High**; runtime: **UNKNOWN / UNTESTED**.
- Shell: role nav bridge if rendered; actual request branch must be observed.
- Inline CSS: yes; inline JS: yes.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/admin/api_hub.php:4](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_hub.php#L4) requireAdmin(); |
| Includes / render ownership | [public_html/admin/api_hub.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_hub.php#L2) require_once __DIR__ . '/../includes/auth.php';<br>[public_html/admin/api_hub.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_hub.php#L3) require_once __DIR__ . '/../includes/store_bridge.php';<br>[public_html/admin/api_hub.php:720](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_hub.php#L720) &lt;?php include 'nav.php'; ?&gt; |
| Entry / redirect | [public_html/admin/api_hub.php:30](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_hub.php#L30) header('Location: api_hub.php', true, 303); |
| Direct CSS / JS (also inherit nav/includes above) | [public_html/admin/api_hub.php:702](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_hub.php#L702) &lt;link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3"&gt;<br>[public_html/admin/api_hub.php:703](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_hub.php#L703) &lt;link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css"&gt; |
| Forms / actions | [public_html/admin/api_hub.php:58](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_hub.php#L58) if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {<br>[public_html/admin/api_hub.php:851](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_hub.php#L851) &lt;form method="post" class="grid grid-cols-1 lg:grid-cols-4 gap-4 mt-5"&gt;<br>[public_html/admin/api_hub.php:880](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_hub.php#L880) &lt;form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-4"&gt;<br>[public_html/admin/api_hub.php:916](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_hub.php#L916) &lt;form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-4"&gt;<br>[public_html/admin/api_hub.php:1020](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_hub.php#L1020) &lt;form method="post"&gt;&lt;?php echo csrfField(); ?&gt;&lt;input type="hidden" name="action" value="test_client_webhook"&gt;&lt;input type="hidden" name="client_id" value="&lt;?php echo (int) $client['id']; ?&gt;"&gt;&lt;button class="btn btn-primary !py-2" type="submit<br>[public_html/admin/api_hub.php:1032](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_hub.php#L1032) &lt;form method="post" class="p-3 grid grid-cols-2 gap-2"&gt;<br>[public_html/admin/api_hub.php:1077](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_hub.php#L1077) &lt;form method="post" class="flex gap-2 items-center"&gt;&lt;?php echo csrfField(); ?&gt;&lt;input type="hidden" name="action" value="adjust_balance"&gt;&lt;input type="hidden" name="client_id" value="&lt;?php echo (int) $client['id']; ?&gt;"&gt;&lt;input class="field !py<br>[public_html/admin/api_hub.php:1081](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_hub.php#L1081) &lt;div class="flex flex-wrap gap-2"&gt;&lt;form method="post"&gt;&lt;?php echo csrfField(); ?&gt;&lt;input type="hidden" name="action" value="set_client_status"&gt;&lt;input type="hidden" name="client_id" value="&lt;?php echo (int) $client['id']; ?&gt;"&gt;&lt;input type="hidde<br>[public_html/admin/api_hub.php:1190](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_hub.php#L1190) &lt;form method="post" class="grid grid-cols-1 md:grid-cols-3 gap-3"&gt;<br>[public_html/admin/api_hub.php:1205](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_hub.php#L1205) &lt;form method="post"&gt;&lt;?php echo csrfField(); ?&gt;&lt;input type="hidden" name="action" value="sync_connection"&gt;&lt;input type="hidden" name="connection_id" value="&lt;?php echo (int) $connection['id']; ?&gt;"&gt;&lt;button class="btn btn-soft w-full" type="subm<br>[public_html/admin/api_hub.php:1206](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_hub.php#L1206) &lt;form method="post"&gt;&lt;?php echo csrfField(); ?&gt;&lt;input type="hidden" name="action" value="set_connection_status"&gt;&lt;input type="hidden" name="connection_id" value="&lt;?php echo (int) $connection['id']; ?&gt;"&gt;&lt;input type="hidden" name="status" value<br>[public_html/admin/api_hub.php:1294](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_hub.php#L1294) &lt;form method="post" class="space-y-2"&gt;<br>[public_html/admin/api_hub.php:1299](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_hub.php#L1299) &lt;form method="post" class="space-y-2" onsubmit="return confirm('&lt;?php echo $h($t('ยืนยันว่าตรวจ Supplier แล้วและไม่มีออเดอร์จริง? การคืนยอดผิดจะทำให้ลูกค้าได้เงินคืนทั้งที่ Supplier อาจส่ง Key แล้ว', 'Confirm that the supplier was checked a<br>[public_html/admin/api_hub.php:1307](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_hub.php#L1307) &lt;form method="post"&gt;&lt;?php echo csrfField(); ?&gt;&lt;input type="hidden" name="action" value="reconcile_order"&gt;&lt;input type="hidden" name="order_id" value="&lt;?php echo (int) $order['id']; ?&gt;"&gt;&lt;button class="btn btn-soft !py-2" type="submit"&gt;&lt;i clas |
| Dynamic endpoints | [public_html/admin/api_hub.php:340](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_hub.php#L340) $providerEndpoint = supplierBridgeCurrentEndpoint();<br>[public_html/admin/api_hub.php:341](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_hub.php#L341) $providerPingEndpoint = storeBridgeEndpointSibling('ping.php');<br>[public_html/admin/api_hub.php:342](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_hub.php#L342) $providerProbeEndpoint = storeBridgeEndpointSibling('diagnostic.php');<br>[public_html/admin/api_hub.php:588](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_hub.php#L588) $endpoint = '{{PROVIDER_ENDPOINT}}';<br>[public_html/admin/api_hub.php:618](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_hub.php#L618) const endpoint = '{{PROVIDER_ENDPOINT}}';<br>[public_html/admin/api_hub.php:621](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_hub.php#L621) const response = await fetch(endpoint, { |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `admin/api_products.php`

- Scope: admin; risk: **High**; runtime: **UNKNOWN / UNTESTED**.
- Shell: role nav bridge if rendered; actual request branch must be observed.
- Inline CSS: yes; inline JS: yes.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/admin/api_products.php:4](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_products.php#L4) requireAdmin(); |
| Includes / render ownership | [public_html/admin/api_products.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_products.php#L2) require_once __DIR__ . '/../includes/auth.php';<br>[public_html/admin/api_products.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_products.php#L3) require_once __DIR__ . '/../includes/store_bridge.php';<br>[public_html/admin/api_products.php:631](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_products.php#L631) &lt;?php include 'nav.php'; ?&gt; |
| Entry / redirect | [public_html/admin/api_products.php:58](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_products.php#L58) header('Location: api_products.php' . ($returnQuery !== '' ? '?' . $returnQuery : ''), true, 303); |
| Direct CSS / JS (also inherit nav/includes above) | [public_html/admin/api_products.php:614](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_products.php#L614) &lt;link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3"&gt;<br>[public_html/admin/api_products.php:615](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_products.php#L615) &lt;link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css"&gt;<br>[public_html/admin/api_products.php:1592](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_products.php#L1592) &lt;script src="../assets/js/instant-filter.js?v=3.0"&gt;&lt;/script&gt; |
| Forms / actions | [public_html/admin/api_products.php:95](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_products.php#L95) if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {<br>[public_html/admin/api_products.php:97](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_products.php#L97) $action = isset($_POST['action']) && is_scalar($_POST['action']) ? trim((string) $_POST['action']) : '';<br>[public_html/admin/api_products.php:679](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_products.php#L679) &lt;form method="post" class="rounded-xl border border-white/10 bg-black/20 p-4 space-y-3"&gt;<br>[public_html/admin/api_products.php:713](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_products.php#L713) &lt;form method="post"&gt;<br>[public_html/admin/api_products.php:734](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_products.php#L734) &lt;form method="post" onsubmit="return confirm('&lt;?php echo $h($t('ลบกฎหมวดหมู่นี้หรือไม่?', 'Delete this category rule?')); ?&gt;')"&gt;<br>[public_html/admin/api_products.php:777](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_products.php#L777) &lt;form method="get" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-5 gap-3"&gt;<br>[public_html/admin/api_products.php:851](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_products.php#L851) &lt;form id="bulkPublishForm" method="post" onsubmit="return confirm('&lt;?php echo $h($t('เผยแพร่รายการที่เลือกเข้าหน้าร้านหรือไม่?', 'Publish selected items to the storefront?')); ?&gt;')"&gt;<br>[public_html/admin/api_products.php:859](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_products.php#L859) &lt;form method="post"&gt;<br>[public_html/admin/api_products.php:868](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_products.php#L868) &lt;form method="post" onsubmit="return confirm('&lt;?php echo $h($t('เติมเฉพาะรายการที่ Max Supplier Cost ยังว่าง โดยใช้ทุนปัจจุบัน + 2 บาท?', 'Fill only missing Max Supplier Cost values with current cost + 2?')); ?&gt;')"&gt;<br>[public_html/admin/api_products.php:875](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_products.php#L875) &lt;form method="post"&gt;<br>[public_html/admin/api_products.php:884](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_products.php#L884) &lt;form method="post" onsubmit="return confirm('&lt;?php echo $h($t(<br>[public_html/admin/api_products.php:1060](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_products.php#L1060) &lt;form method="post" class="shrink-0"&gt;<br>[public_html/admin/api_products.php:1073](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_products.php#L1073) &lt;form method="post" class="rounded-xl border border-white/10 bg-black/20 p-4 grid grid-cols-1 md:grid-cols-2 gap-3"&gt;<br>[public_html/admin/api_products.php:1168](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_products.php#L1168) &lt;form method="post" class="rounded-lg border border-violet-500/25 bg-violet-950/20 p-3 flex flex-col md:flex-row md:items-center md:justify-between gap-3"<br>[public_html/admin/api_products.php:1198](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_products.php#L1198) &lt;form method="post" class="rounded-lg border border-white/10 bg-black/20 p-3 space-y-2"&gt;<br>[public_html/admin/api_products.php:1236](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_products.php#L1236) &lt;form method="post" class="grid grid-cols-1 md:grid-cols-[minmax(0,1fr)_140px_auto] gap-2 items-end"&gt;<br>[public_html/admin/api_products.php:1253](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_products.php#L1253) &lt;form method="post"&gt;<br>[public_html/admin/api_products.php:1267](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_products.php#L1267) &lt;form method="post" class="space-y-3 catalog-picker"<br>[public_html/admin/api_products.php:1308](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_products.php#L1308) &lt;form method="post" class="space-y-3 catalog-picker"<br>[public_html/admin/api_products.php:1370](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_products.php#L1370) &lt;form method="post" onsubmit="return confirm('&lt;?php echo $h($t( |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | [public_html/admin/api_products.php:756](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_products.php#L756) &lt;div id="apiProductsLivePanel" data-instant-panel class="space-y-5"&gt;<br>[public_html/admin/api_products.php:1569](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/api_products.php#L1569) select.focus(); |

### `admin/binance_deposits.php`

- Scope: admin; risk: **Critical**; runtime: **UNKNOWN / UNTESTED**.
- Shell: role nav bridge if rendered; actual request branch must be observed.
- Inline CSS: yes; inline JS: yes.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/admin/binance_deposits.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/binance_deposits.php#L3) requireAdmin(); |
| Includes / render ownership | [public_html/admin/binance_deposits.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/binance_deposits.php#L2) require_once '../includes/auth.php';<br>[public_html/admin/binance_deposits.php:4](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/binance_deposits.php#L4) require_once '../includes/binance.php';<br>[public_html/admin/binance_deposits.php:72](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/binance_deposits.php#L72) &lt;?php include 'nav.php'; ?&gt; |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | [public_html/admin/binance_deposits.php:53](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/binance_deposits.php#L53) &lt;link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3"&gt;<br>[public_html/admin/binance_deposits.php:54](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/binance_deposits.php#L54) &lt;link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css"&gt;<br>[public_html/admin/binance_deposits.php:204](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/binance_deposits.php#L204) &lt;script src="../assets/js/instant-filter.js?v=3.0"&gt;&lt;/script&gt; |
| Forms / actions | [public_html/admin/binance_deposits.php:120](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/binance_deposits.php#L120) &lt;form method="GET" class="mb-4"&gt; |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | [public_html/admin/binance_deposits.php:119](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/binance_deposits.php#L119) &lt;div id="binanceDepositsLivePanel" data-instant-panel&gt; |

### `admin/binance_giftcards.php`

- Scope: admin; risk: **High**; runtime: **UNKNOWN / UNTESTED**.
- Shell: role nav bridge if rendered; actual request branch must be observed.
- Inline CSS: yes; inline JS: yes.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/admin/binance_giftcards.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/binance_giftcards.php#L3) requireAdmin(); |
| Includes / render ownership | [public_html/admin/binance_giftcards.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/binance_giftcards.php#L2) require_once '../includes/auth.php';<br>[public_html/admin/binance_giftcards.php:4](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/binance_giftcards.php#L4) require_once '../includes/ranking.php';<br>[public_html/admin/binance_giftcards.php:5](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/binance_giftcards.php#L5) require_once '../includes/binance_giftcard.php';<br>[public_html/admin/binance_giftcards.php:228](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/binance_giftcards.php#L228) &lt;?php include 'nav.php'; ?&gt; |
| Entry / redirect | [public_html/admin/binance_giftcards.php:37](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/binance_giftcards.php#L37) header('Location: binance_giftcards.php', true, 303); |
| Direct CSS / JS (also inherit nav/includes above) | [public_html/admin/binance_giftcards.php:222](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/binance_giftcards.php#L222) &lt;link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3"&gt;<br>[public_html/admin/binance_giftcards.php:223](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/binance_giftcards.php#L223) &lt;link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css"&gt;<br>[public_html/admin/binance_giftcards.php:313](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/binance_giftcards.php#L313) &lt;script src="../assets/js/lang.js"&gt;&lt;/script&gt;<br>[public_html/admin/binance_giftcards.php:319](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/binance_giftcards.php#L319) &lt;script src="../assets/js/instant-filter.js?v=3.0"&gt;&lt;/script&gt; |
| Forms / actions | [public_html/admin/binance_giftcards.php:25](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/binance_giftcards.php#L25) if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {<br>[public_html/admin/binance_giftcards.php:27](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/binance_giftcards.php#L27) $action = isset($_POST['action']) && is_scalar($_POST['action']) ? (string) $_POST['action'] : '';<br>[public_html/admin/binance_giftcards.php:266](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/binance_giftcards.php#L266) &lt;form method="GET" class="glass rounded-xl p-4 grid grid-cols-1 gap-3 md:grid-cols-[1fr_240px_auto]"&gt;<br>[public_html/admin/binance_giftcards.php:305](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/binance_giftcards.php#L305) &lt;?php if ($canRetry): ?&gt;&lt;form method="POST" class="mt-4" onsubmit="return confirm('&lt;?php echo htmlspecialchars(Lang::t('admin.giftcard.retry_confirm'), ENT_QUOTES, 'UTF-8'); ?&gt;')"&gt;&lt;?php echo csrfField(); ?&gt;&lt;input type="hidden" name="action" |
| Dynamic endpoints | [public_html/admin/binance_giftcards.php:114](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/binance_giftcards.php#L114) $count-&gt;fetch(); |
| State / interactions | [public_html/admin/binance_giftcards.php:253](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/binance_giftcards.php#L253) &lt;div id="binanceGiftcardsLivePanel" data-instant-panel class="space-y-5"&gt; |

### `admin/categories.php`

- Scope: admin; risk: **High**; runtime: **UNKNOWN / UNTESTED**.
- Shell: role nav bridge if rendered; actual request branch must be observed.
- Inline CSS: no direct block; inline JS: no direct block.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/admin/categories.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/categories.php#L3) requireAdmin(); |
| Includes / render ownership | [public_html/admin/categories.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/categories.php#L2) require_once '../includes/auth.php';<br>[public_html/admin/categories.php:176](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/categories.php#L176) &lt;?php include 'nav.php'; ?&gt; |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | [public_html/admin/categories.php:171](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/categories.php#L171) &lt;link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3"&gt;<br>[public_html/admin/categories.php:172](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/categories.php#L172) &lt;link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css"&gt; |
| Forms / actions | [public_html/admin/categories.php:84](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/categories.php#L84) if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {<br>[public_html/admin/categories.php:87](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/categories.php#L87) $action = isset($_POST['action']) && is_scalar($_POST['action'])<br>[public_html/admin/categories.php:88](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/categories.php#L88) ? (string) $_POST['action']<br>[public_html/admin/categories.php:264](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/categories.php#L264) &lt;form action="" method="POST" id="&lt;?php echo $escape($formId); ?&gt;" class="contents"&gt; |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `admin/cheatgame.php`

- Scope: admin; risk: **High**; runtime: **UNKNOWN / UNTESTED**.
- Shell: role nav bridge if rendered; actual request branch must be observed.
- Inline CSS: yes; inline JS: yes.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/admin/cheatgame.php:4](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/cheatgame.php#L4) requireAdmin(); |
| Includes / render ownership | [public_html/admin/cheatgame.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/cheatgame.php#L2) require_once __DIR__ . '/../includes/auth.php';<br>[public_html/admin/cheatgame.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/cheatgame.php#L3) require_once __DIR__ . '/../includes/cheatgame.php';<br>[public_html/admin/cheatgame.php:866](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/cheatgame.php#L866) &lt;?php include __DIR__ . '/nav.php'; ?&gt; |
| Entry / redirect | [public_html/admin/cheatgame.php:120](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/cheatgame.php#L120) header('Location: cheatgame.php' . ($query !== '' ? '?' . $query : '') . '#' . rawurlencode($anchor), true, 303); |
| Direct CSS / JS (also inherit nav/includes above) | [public_html/admin/cheatgame.php:815](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/cheatgame.php#L815) &lt;link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3"&gt;<br>[public_html/admin/cheatgame.php:816](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/cheatgame.php#L816) &lt;link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css"&gt;<br>[public_html/admin/cheatgame.php:1925](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/cheatgame.php#L1925) &lt;script src="../assets/js/instant-filter.js?v=3.0"&gt;&lt;/script&gt; |
| Forms / actions | [public_html/admin/cheatgame.php:138](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/cheatgame.php#L138) if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {<br>[public_html/admin/cheatgame.php:140](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/cheatgame.php#L140) $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';<br>[public_html/admin/cheatgame.php:944](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/cheatgame.php#L944) &lt;form method="GET" action="cheatgame.php#cgo-products" id="cgo-product-filters" data-instant-submit-only class="cgo-filter-shell rounded-xl border border-white/10 p-3 space-y-3"&gt;<br>[public_html/admin/cheatgame.php:1044](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/cheatgame.php#L1044) &lt;form method="POST" enctype="multipart/form-data" class="js-cgo-catalog-form grid grid-cols-1 md:grid-cols-2 gap-3"&gt;<br>[public_html/admin/cheatgame.php:1115](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/cheatgame.php#L1115) &lt;form method="POST" onsubmit="return confirm('&lt;?php echo htmlspecialchars($t('ยกเลิกการเชื่อมเท่านั้น สินค้าหลักและคีย์เดิมจะไม่ถูกลบ ยืนยันหรือไม่?', 'Remove only the mapping? The local product and existing keys will not be deleted.'), ENT<br>[public_html/admin/cheatgame.php:1128](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/cheatgame.php#L1128) &lt;form method="POST" class="cgo-price-form grid grid-cols-3 gap-2 items-end"&gt;<br>[public_html/admin/cheatgame.php:1203](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/cheatgame.php#L1203) &lt;form method="POST"&gt;<br>[public_html/admin/cheatgame.php:1207](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/cheatgame.php#L1207) &lt;form method="POST"&gt;<br>[public_html/admin/cheatgame.php:1211](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/cheatgame.php#L1211) &lt;form method="POST"&gt;<br>[public_html/admin/cheatgame.php:1215](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/cheatgame.php#L1215) &lt;form method="POST"&gt;<br>[public_html/admin/cheatgame.php:1224](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/cheatgame.php#L1224) &lt;form method="POST" class="space-y-3"&gt;<br>[public_html/admin/cheatgame.php:1231](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/cheatgame.php#L1231) &lt;form method="POST" class="mt-3"&gt;<br>[public_html/admin/cheatgame.php:1235](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/cheatgame.php#L1235) &lt;form method="POST" class="mt-3"&gt;<br>[public_html/admin/cheatgame.php:1263](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/cheatgame.php#L1263) &lt;form method="POST" class="mt-4 grid grid-cols-1 md:grid-cols-4 gap-3"&gt;<br>[public_html/admin/cheatgame.php:1402](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/cheatgame.php#L1402) &lt;form method="POST" class="shrink-0" onsubmit="return confirm('&lt;?php echo htmlspecialchars($t('ยืนยันล้าง API diagnostic logs ของ Order ที่จบแล้ว? Log ของ Order ที่ยังไม่จบและข้อมูลการเงินจริงจะถูกเก็บไว้', 'Clear diagnostic logs for termin<br>[public_html/admin/cheatgame.php:1448](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/cheatgame.php#L1448) &lt;td class="p-3" data-label="&lt;?php echo htmlspecialchars($t('จัดการ', 'Action'), ENT_QUOTES, 'UTF-8'); ?&gt;"&gt;&lt;?php if (in_array((string) $order['status'], ['unknown','processing','pending','manual_review'], true)): ?&gt;&lt;form method="POST"&gt;&lt;?php  |
| Dynamic endpoints | [public_html/admin/cheatgame.php:1785](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/cheatgame.php#L1785) const response = await fetch('../cgo_inventory.php?action=token', {<br>[public_html/admin/cheatgame.php:1789](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/cheatgame.php#L1789) headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }<br>[public_html/admin/cheatgame.php:1801](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/cheatgame.php#L1801) const response = await fetch('../cgo_inventory.php', {<br>[public_html/admin/cheatgame.php:1807](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/cheatgame.php#L1807) 'X-Requested-With': 'XMLHttpRequest', |
| State / interactions | [public_html/admin/cheatgame.php:928](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/cheatgame.php#L928) &lt;section id="cgo-products" data-instant-panel class="glass rounded-xl overflow-hidden"&gt;<br>[public_html/admin/cheatgame.php:1884](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/cheatgame.php#L1884) window.setInterval(updateAge, 1000); |

### `admin/cheatgame_image.php`

- Scope: admin; risk: **High**; runtime: **UNKNOWN / UNTESTED**.
- Shell: No direct nav include; wrapper/redirect/dynamic includes below govern.
- Inline CSS: no direct block; inline JS: no direct block.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/admin/cheatgame_image.php:4](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/cheatgame_image.php#L4) requireAdmin(); |
| Includes / render ownership | [public_html/admin/cheatgame_image.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/cheatgame_image.php#L2) require_once __DIR__ . '/../includes/auth.php';<br>[public_html/admin/cheatgame_image.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/cheatgame_image.php#L3) require_once __DIR__ . '/../includes/cheatgame.php'; |
| Entry / redirect | [public_html/admin/cheatgame_image.php:90](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/cheatgame_image.php#L90) header('Location: ' . $remoteUrl, true, 302); |
| Direct CSS / JS (also inherit nav/includes above) | No direct match (inherited/dynamic behavior must be traced) |
| Forms / actions | No direct match (inherited/dynamic behavior must be traced) |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `admin/codes.php`

- Scope: admin; risk: **Critical**; runtime: **UNKNOWN / UNTESTED**.
- Shell: role nav bridge if rendered; actual request branch must be observed.
- Inline CSS: yes; inline JS: yes.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/admin/codes.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/codes.php#L3) requireAdmin(); |
| Includes / render ownership | [public_html/admin/codes.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/codes.php#L2) require_once '../includes/auth.php';<br>[public_html/admin/codes.php:153](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/codes.php#L153) &lt;?php include 'nav.php'; ?&gt; |
| Entry / redirect | [public_html/admin/codes.php:56](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/codes.php#L56) header('Location: codes.php');<br>[public_html/admin/codes.php:78](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/codes.php#L78) header('Location: codes.php');<br>[public_html/admin/codes.php:101](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/codes.php#L101) header('Location: codes.php'); |
| Direct CSS / JS (also inherit nav/includes above) | [public_html/admin/codes.php:124](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/codes.php#L124) &lt;link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3"&gt;<br>[public_html/admin/codes.php:125](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/codes.php#L125) &lt;link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"&gt; |
| Forms / actions | [public_html/admin/codes.php:41](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/codes.php#L41) if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_code'])) {<br>[public_html/admin/codes.php:83](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/codes.php#L83) if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['redeem_code'])) {<br>[public_html/admin/codes.php:171](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/codes.php#L171) &lt;form method="post" class="space-y-3 js-submit-once"&gt;<br>[public_html/admin/codes.php:224](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/codes.php#L224) &lt;form method="post" class="space-y-3 js-submit-once"&gt; |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | [public_html/admin/codes.php:350](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/codes.php#L350) textarea.focus(); |

### `admin/commerce_center.php`

- Scope: admin; risk: **High**; runtime: **UNKNOWN / UNTESTED**.
- Shell: role nav bridge if rendered; actual request branch must be observed.
- Inline CSS: yes; inline JS: no direct block.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/admin/commerce_center.php:5](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/commerce_center.php#L5) requireAdmin(); |
| Includes / render ownership | [public_html/admin/commerce_center.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/commerce_center.php#L2) require_once __DIR__ . '/../includes/auth.php';<br>[public_html/admin/commerce_center.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/commerce_center.php#L3) require_once __DIR__ . '/../includes/commerce_center.php';<br>[public_html/admin/commerce_center.php:4](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/commerce_center.php#L4) require_once __DIR__ . '/../includes/commerce_context.php';<br>[public_html/admin/commerce_center.php:102](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/commerce_center.php#L102) &lt;?php include 'nav.php'; ?&gt; |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | [public_html/admin/commerce_center.php:89](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/commerce_center.php#L89) &lt;link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3"&gt;<br>[public_html/admin/commerce_center.php:90](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/commerce_center.php#L90) &lt;link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css"&gt; |
| Forms / actions | [public_html/admin/commerce_center.php:27](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/commerce_center.php#L27) if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {<br>[public_html/admin/commerce_center.php:29](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/commerce_center.php#L29) $action = isset($_POST['action']) && is_scalar($_POST['action']) ? trim((string) $_POST['action']) : '';<br>[public_html/admin/commerce_center.php:130](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/commerce_center.php#L130) &lt;form method="post" class="mt-4"&gt;&lt;?php echo csrfField(); ?&gt;&lt;input type="hidden" name="action" value="reconcile"&gt;&lt;button class="btn btn-primary" type="submit"&gt;&lt;i class="bi bi-arrow-clockwise"&gt;&lt;/i&gt;&lt;?php echo $h($t('ซิงก์ส่วนกลางตอนนี้', 'Reco<br>[public_html/admin/commerce_center.php:134](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/commerce_center.php#L134) &lt;form method="post" class="mt-3 flex flex-col sm:flex-row gap-2"&gt;&lt;?php echo csrfField(); ?&gt;&lt;input type="hidden" name="action" value="lookup_key"&gt;&lt;input class="field flex-1 font-mono" name="license_key" autocomplete="off" maxlength="5000" re<br>[public_html/admin/commerce_center.php:156](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/commerce_center.php#L156) &lt;form method="get" class="grid sm:grid-cols-3 gap-2 w-full lg:w-auto"&gt; |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `admin/commerce_consistency.php`

- Scope: admin; risk: **High**; runtime: **UNKNOWN / UNTESTED**.
- Shell: role nav bridge if rendered; actual request branch must be observed.
- Inline CSS: yes; inline JS: yes.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/admin/commerce_consistency.php:4](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/commerce_consistency.php#L4) requireAdmin(); |
| Includes / render ownership | [public_html/admin/commerce_consistency.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/commerce_consistency.php#L2) require_once __DIR__ . '/../includes/auth.php';<br>[public_html/admin/commerce_consistency.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/commerce_consistency.php#L3) require_once __DIR__ . '/../includes/commerce_consistency.php';<br>[public_html/admin/commerce_consistency.php:50](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/commerce_consistency.php#L50) &lt;?php include __DIR__ . '/nav.php'; ?&gt; |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | [public_html/admin/commerce_consistency.php:40](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/commerce_consistency.php#L40) &lt;link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3"&gt;<br>[public_html/admin/commerce_consistency.php:41](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/commerce_consistency.php#L41) &lt;link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css"&gt; |
| Forms / actions | [public_html/admin/commerce_consistency.php:12](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/commerce_consistency.php#L12) if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'initialize_optional_tables') {<br>[public_html/admin/commerce_consistency.php:58](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/commerce_consistency.php#L58) &lt;form method="POST" class="inline" onsubmit="return confirm('&lt;?php echo $e($t('สร้างเฉพาะตารางและดัชนีเสริมที่ขาด โดยไม่แก้ยอดเงินหรือคำสั่งซื้อ ใช่หรือไม่?', 'Create only missing optional tables/indexes without changing balances or orders?<br>[public_html/admin/commerce_consistency.php:97](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/commerce_consistency.php#L97) &lt;form method="get" class="mt-4 flex flex-wrap items-end gap-2 border-t border-white/10 pt-4"&gt; |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `admin/commerce_ledger.php`

- Scope: admin; risk: **High**; runtime: **UNKNOWN / UNTESTED**.
- Shell: role nav bridge if rendered; actual request branch must be observed.
- Inline CSS: yes; inline JS: no direct block.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/admin/commerce_ledger.php:4](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/commerce_ledger.php#L4) requireAdmin(); |
| Includes / render ownership | [public_html/admin/commerce_ledger.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/commerce_ledger.php#L2) require_once __DIR__ . '/../includes/auth.php';<br>[public_html/admin/commerce_ledger.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/commerce_ledger.php#L3) require_once __DIR__ . '/../includes/commerce_center.php';<br>[public_html/admin/commerce_ledger.php:165](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/commerce_ledger.php#L165) &lt;?php include 'nav.php'; ?&gt; |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | [public_html/admin/commerce_ledger.php:152](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/commerce_ledger.php#L152) &lt;link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3"&gt;<br>[public_html/admin/commerce_ledger.php:153](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/commerce_ledger.php#L153) &lt;link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css"&gt; |
| Forms / actions | [public_html/admin/commerce_ledger.php:25](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/commerce_ledger.php#L25) if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {<br>[public_html/admin/commerce_ledger.php:27](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/commerce_ledger.php#L27) $action = isset($_POST['action']) && is_scalar($_POST['action']) ? trim((string) $_POST['action']) : '';<br>[public_html/admin/commerce_ledger.php:179](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/commerce_ledger.php#L179) &lt;form method="post"&gt;&lt;?php echo csrfField(); ?&gt;&lt;input type="hidden" name="action" value="reconcile"&gt;&lt;button type="submit" class="btn btn-primary"&gt;&lt;i class="bi bi-arrow-repeat"&gt;&lt;/i&gt;&lt;?php echo $h($t('ซิงก์ตอนนี้', 'Reconcile now')); ?&gt;&lt;/button<br>[public_html/admin/commerce_ledger.php:200](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/commerce_ledger.php#L200) &lt;form method="get" class="grid sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7 gap-2"&gt; |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `admin/dashboard.php`

- Scope: admin; risk: **High**; runtime: **UNKNOWN / UNTESTED**.
- Shell: role nav bridge if rendered; actual request branch must be observed.
- Inline CSS: yes; inline JS: yes.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/admin/dashboard.php:9](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/dashboard.php#L9) requireAdmin(); |
| Includes / render ownership | [public_html/admin/dashboard.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/dashboard.php#L3) require_once '../includes/auth.php';<br>[public_html/admin/dashboard.php:5](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/dashboard.php#L5) require_once '../includes/db.php';<br>[public_html/admin/dashboard.php:6](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/dashboard.php#L6) require_once '../includes/cheatgame.php';<br>[public_html/admin/dashboard.php:7](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/dashboard.php#L7) require_once '../includes/announcement_marquee.php';<br>[public_html/admin/dashboard.php:584](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/dashboard.php#L584) &lt;?php include 'nav.php'; ?&gt;<br>[public_html/admin/dashboard.php:642](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/dashboard.php#L642) include __DIR__ . '/../includes/purchase_activity.php'; |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | [public_html/admin/dashboard.php:172](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/dashboard.php#L172) &lt;link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3"&gt;<br>[public_html/admin/dashboard.php:174](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/dashboard.php#L174) &lt;link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css"&gt;<br>[public_html/admin/dashboard.php:1289](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/dashboard.php#L1289) &lt;script src="../assets/js/announcement-marquee.js?v=&lt;?php echo (int) (@filemtime(__DIR__ . '/../assets/js/announcement-marquee.js') ?: 1); ?&gt;"&gt;&lt;/script&gt;<br>[public_html/admin/dashboard.php:1290](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/dashboard.php#L1290) &lt;script src="../assets/js/instant-filter.js?v=3.0"&gt;&lt;/script&gt; |
| Forms / actions | [public_html/admin/dashboard.php:102](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/dashboard.php#L102) if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['purchase_duration'])) {<br>[public_html/admin/dashboard.php:653](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/dashboard.php#L653) &lt;form method="GET" action="" data-instant-submit-only class="flex flex-col sm:flex-row gap-3"&gt;<br>[public_html/admin/dashboard.php:976](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/dashboard.php#L976) &lt;form method="POST" id="quantityPurchaseForm" style="display:none;"&gt; |
| Dynamic endpoints | [public_html/admin/dashboard.php:641](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/dashboard.php#L641) $purchaseActivityEndpoint = '../purchase_activity.php'; |
| State / interactions | [public_html/admin/dashboard.php:650](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/dashboard.php#L650) &lt;div id="adminStorefrontLiveCatalog" data-instant-panel class="space-y-4"&gt;<br>[public_html/admin/dashboard.php:905](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/dashboard.php#L905) &lt;div class="overlay" id="overlay" onclick="closeQuantityModal()"&gt;&lt;/div&gt;<br>[public_html/admin/dashboard.php:987](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/dashboard.php#L987) &lt;div class="overlay" id="successOverlay" style="display: flex;"&gt;&lt;/div&gt; |

### `admin/deposit.php`

- Scope: admin; risk: **Critical**; runtime: **UNKNOWN / UNTESTED**.
- Shell: No direct nav include; wrapper/redirect/dynamic includes below govern.
- Inline CSS: no direct block; inline JS: no direct block.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/admin/deposit.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/deposit.php#L3) requireAdmin(); |
| Includes / render ownership | [public_html/admin/deposit.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/deposit.php#L2) require_once '../includes/auth.php';<br>[public_html/admin/deposit.php:10](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/deposit.php#L10) require_once '../includes/deposit.php'; |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | No direct match (inherited/dynamic behavior must be traced) |
| Forms / actions | No direct match (inherited/dynamic behavior must be traced) |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `admin/email_settings.php`

- Scope: admin; risk: **High**; runtime: **UNKNOWN / UNTESTED**.
- Shell: role nav bridge if rendered; actual request branch must be observed.
- Inline CSS: yes; inline JS: yes.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/admin/email_settings.php:5](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/email_settings.php#L5) requireAdmin(); |
| Includes / render ownership | [public_html/admin/email_settings.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/email_settings.php#L2) require_once '../includes/auth.php';<br>[public_html/admin/email_settings.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/email_settings.php#L3) require_once '../includes/account_recovery.php';<br>[public_html/admin/email_settings.php:4](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/email_settings.php#L4) require_once '../includes/key_history_cleanup.php';<br>[public_html/admin/email_settings.php:223](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/email_settings.php#L223) &lt;?php include 'nav.php'; ?&gt; |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | [public_html/admin/email_settings.php:213](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/email_settings.php#L213) &lt;link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3"&gt;<br>[public_html/admin/email_settings.php:214](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/email_settings.php#L214) &lt;link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css"&gt; |
| Forms / actions | [public_html/admin/email_settings.php:26](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/email_settings.php#L26) if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {<br>[public_html/admin/email_settings.php:28](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/email_settings.php#L28) $action = isset($_POST['action']) && is_scalar($_POST['action']) ? (string) $_POST['action'] : '';<br>[public_html/admin/email_settings.php:290](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/email_settings.php#L290) &lt;form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-4"&gt;<br>[public_html/admin/email_settings.php:338](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/email_settings.php#L338) &lt;form method="post" class="mt-3"&gt;<br>[public_html/admin/email_settings.php:345](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/email_settings.php#L345) &lt;form method="post" class="mt-3" onsubmit="return confirm('&lt;?php echo $isTh ? 'ล้าง App Password ที่บันทึกไว้หรือไม่? ระบบลืมรหัสผ่านจะถูกปิดจนกว่าจะใส่รหัสใหม่' : 'Clear the stored App Password? Password recovery will remain disabled until<br>[public_html/admin/email_settings.php:389](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/email_settings.php#L389) &lt;form method="post" onsubmit="return confirm('&lt;?php echo $isTh ? 'ต้องการล้าง Mail Log ทั้งหมดหรือไม่?' : 'Clear all mail logs?'; ?&gt;')"&gt;<br>[public_html/admin/email_settings.php:476](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/email_settings.php#L476) &lt;form method="post" onsubmit="return confirm('&lt;?php echo $isTh ? 'ต้องการเริ่มล้างประวัติคีย์เก่าตอนนี้หรือไม่?' : 'Run old key-history cleanup now?'; ?&gt;')"&gt; |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `admin/get_discount.php`

- Scope: admin; risk: **High**; runtime: **UNKNOWN / UNTESTED**.
- Shell: No direct nav include; wrapper/redirect/dynamic includes below govern.
- Inline CSS: no direct block; inline JS: no direct block.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/admin/get_discount.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/get_discount.php#L3) requireAdmin(); |
| Includes / render ownership | [public_html/admin/get_discount.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/get_discount.php#L2) require_once '../includes/auth.php'; |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | No direct match (inherited/dynamic behavior must be traced) |
| Forms / actions | No direct match (inherited/dynamic behavior must be traced) |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `admin/get_variants.php`

- Scope: admin; risk: **High**; runtime: **UNKNOWN / UNTESTED**.
- Shell: No direct nav include; wrapper/redirect/dynamic includes below govern.
- Inline CSS: no direct block; inline JS: no direct block.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/admin/get_variants.php:5](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/get_variants.php#L5) requireAdmin(); |
| Includes / render ownership | [public_html/admin/get_variants.php:4](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/get_variants.php#L4) require_once __DIR__ . '/../includes/auth.php'; |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | No direct match (inherited/dynamic behavior must be traced) |
| Forms / actions | No direct match (inherited/dynamic behavior must be traced) |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `admin/key_reset_action.php`

- Scope: admin; risk: **Critical**; runtime: **UNKNOWN / UNTESTED**.
- Shell: No direct nav include; wrapper/redirect/dynamic includes below govern.
- Inline CSS: no direct block; inline JS: no direct block.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/admin/key_reset_action.php:5](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/key_reset_action.php#L5) requireAdmin(); |
| Includes / render ownership | [public_html/admin/key_reset_action.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/key_reset_action.php#L2) require_once __DIR__ . '/../includes/auth.php';<br>[public_html/admin/key_reset_action.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/key_reset_action.php#L3) require_once __DIR__ . '/../includes/key_reset.php'; |
| Entry / redirect | [public_html/admin/key_reset_action.php:7](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/key_reset_action.php#L7) header('Location: key_resets.php', true, 303);<br>[public_html/admin/key_reset_action.php:34](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/key_reset_action.php#L34) header('Location: key_resets.php', true, 303);<br>[public_html/admin/key_reset_action.php:57](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/key_reset_action.php#L57) header('Location: ' . $location, true, 303);<br>[public_html/admin/key_reset_action.php:63](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/key_reset_action.php#L63) header('Location: key_resets.php#reset-logs', true, 303);<br>[public_html/admin/key_reset_action.php:168](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/key_reset_action.php#L168) header('Location: key_resets.php', true, 303); |
| Direct CSS / JS (also inherit nav/includes above) | No direct match (inherited/dynamic behavior must be traced) |
| Forms / actions | [public_html/admin/key_reset_action.php:6](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/key_reset_action.php#L6) if ($_SERVER['REQUEST_METHOD'] !== 'POST') {<br>[public_html/admin/key_reset_action.php:38](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/key_reset_action.php#L38) $action = isset($_POST['action']) && is_string($_POST['action']) ? trim($_POST['action']) : ''; |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `admin/key_resets.php`

- Scope: admin; risk: **Critical**; runtime: **UNKNOWN / UNTESTED**.
- Shell: role nav bridge if rendered; actual request branch must be observed.
- Inline CSS: yes; inline JS: yes.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/admin/key_resets.php:5](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/key_resets.php#L5) requireAdmin(); |
| Includes / render ownership | [public_html/admin/key_resets.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/key_resets.php#L2) require_once __DIR__ . '/../includes/auth.php';<br>[public_html/admin/key_resets.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/key_resets.php#L3) require_once __DIR__ . '/../includes/key_reset.php';<br>[public_html/admin/key_resets.php:152](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/key_resets.php#L152) &lt;?php include __DIR__ . '/nav.php'; ?&gt; |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | [public_html/admin/key_resets.php:136](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/key_resets.php#L136) &lt;link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3"&gt;<br>[public_html/admin/key_resets.php:137](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/key_resets.php#L137) &lt;link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css"&gt; |
| Forms / actions | [public_html/admin/key_resets.php:235](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/key_resets.php#L235) &lt;form method="post" action="key_reset_action.php#key-reset-diagnostics" data-no-page-loader="1"&gt;<br>[public_html/admin/key_resets.php:241](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/key_resets.php#L241) &lt;form method="post" action="key_reset_action.php#key-reset-diagnostics" data-no-page-loader="1"&gt;<br>[public_html/admin/key_resets.php:342](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/key_resets.php#L342) &lt;form method="post" action="key_reset_action.php"&gt;<br>[public_html/admin/key_resets.php:348](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/key_resets.php#L348) &lt;form method="post" action="key_reset_action.php"&gt;<br>[public_html/admin/key_resets.php:360](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/key_resets.php#L360) &lt;form method="post" action="key_reset_action.php" class="mt-4 space-y-4"&gt;<br>[public_html/admin/key_resets.php:384](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/key_resets.php#L384) &lt;form method="post" action="key_reset_action.php" id="adminResetForm" class="mt-4 space-y-4"&gt;<br>[public_html/admin/key_resets.php:433](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/key_resets.php#L433) &lt;form id="logSearchForm" method="get" action="key_resets.php#reset-logs" data-no-page-loader="1" class="grid gap-2 lg:grid-cols-[minmax(260px,1.8fr)_minmax(150px,.7fr)_minmax(210px,1fr)_auto]"&gt; |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | [public_html/admin/key_resets.php:684](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/key_resets.php#L684) logSearchInput?.focus(); |

### `admin/keys.php`

- Scope: admin; risk: **High**; runtime: **UNKNOWN / UNTESTED**.
- Shell: role nav bridge if rendered; actual request branch must be observed.
- Inline CSS: yes; inline JS: yes.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/admin/keys.php:5](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/keys.php#L5) requireAdmin(); |
| Includes / render ownership | [public_html/admin/keys.php:4](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/keys.php#L4) require_once __DIR__ . '/../includes/auth.php';<br>[public_html/admin/keys.php:888](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/keys.php#L888) &lt;?php include 'nav.php'; ?&gt; |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | [public_html/admin/keys.php:870](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/keys.php#L870) &lt;link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3"&gt;<br>[public_html/admin/keys.php:871](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/keys.php#L871) &lt;link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css"&gt;<br>[public_html/admin/keys.php:1424](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/keys.php#L1424) &lt;script src="../assets/js/instant-filter.js?v=3.0"&gt;&lt;/script&gt; |
| Forms / actions | [public_html/admin/keys.php:483](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/keys.php#L483) if ($_SERVER['REQUEST_METHOD'] === 'POST') {<br>[public_html/admin/keys.php:485](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/keys.php#L485) $action = isset($_POST['action']) && is_scalar($_POST['action'])<br>[public_html/admin/keys.php:486](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/keys.php#L486) ? substr((string) $_POST['action'], 0, 30)<br>[public_html/admin/keys.php:928](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/keys.php#L928) &lt;form method="GET" class="glass rounded-xl p-3 md:p-4 grid grid-cols-1 md:grid-cols-12 gap-3 items-end"&gt;<br>[public_html/admin/keys.php:982](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/keys.php#L982) &lt;form method="POST" id="bulkForm" class="space-y-3"&gt;<br>[public_html/admin/keys.php:1190](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/keys.php#L1190) &lt;form method="POST" id="deleteForm" class="p-4 sm:p-5"&gt; |
| Dynamic endpoints | [public_html/admin/keys.php:75](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/keys.php#L75) $stmt-&gt;fetch();<br>[public_html/admin/keys.php:111](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/keys.php#L111) $stmt-&gt;fetch(); |
| State / interactions | [public_html/admin/keys.php:912](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/keys.php#L912) &lt;div id="adminKeysLivePanel" data-instant-panel class="space-y-4"&gt;<br>[public_html/admin/keys.php:1168](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/keys.php#L1168) &lt;div id="detailsModal" class="fixed inset-0 z-[80] hidden items-end sm:items-center justify-center bg-black/70 p-0 sm:p-4" role="dialog" aria-modal="true" aria-labelledby="detailsTitle"&gt;<br>[public_html/admin/keys.php:1188](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/keys.php#L1188) &lt;div id="deleteModal" class="fixed inset-0 z-[90] hidden items-end sm:items-center justify-center bg-black/75 p-0 sm:p-4" role="dialog" aria-modal="true" aria-labelledby="deleteTitle"&gt;<br>[public_html/admin/keys.php:1240](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/keys.php#L1240) if (focusable) setTimeout(() =&gt; focusable.focus(), 0);<br>[public_html/admin/keys.php:1248](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/keys.php#L1248) if (lastFocused && typeof lastFocused.focus === 'function') lastFocused.focus(); |

### `admin/music.php`

- Scope: admin; risk: **High**; runtime: **UNKNOWN / UNTESTED**.
- Shell: role nav bridge if rendered; actual request branch must be observed.
- Inline CSS: yes; inline JS: yes.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/admin/music.php:4](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/music.php#L4) requireAdmin(); |
| Includes / render ownership | [public_html/admin/music.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/music.php#L2) require_once '../includes/auth.php';<br>[public_html/admin/music.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/music.php#L3) require_once '../includes/music_player.php';<br>[public_html/admin/music.php:159](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/music.php#L159) &lt;?php include 'nav.php'; ?&gt; |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | [public_html/admin/music.php:154](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/music.php#L154) &lt;link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3"&gt;<br>[public_html/admin/music.php:155](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/music.php#L155) &lt;link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css"&gt; |
| Forms / actions | [public_html/admin/music.php:37](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/music.php#L37) if ($_SERVER['REQUEST_METHOD'] === 'POST') {<br>[public_html/admin/music.php:179](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/music.php#L179) &lt;form method="post" class="space-y-4"&gt;<br>[public_html/admin/music.php:199](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/music.php#L199) &lt;form method="post" class="grid sm:grid-cols-[1fr_.7fr_auto] gap-3 items-end"&gt;<br>[public_html/admin/music.php:213](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/music.php#L213) &lt;form method="post" id="playlist-form" class="space-y-3"&gt; |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `admin/nav.php`

- Scope: admin; risk: **High**; runtime: **UNKNOWN / UNTESTED**.
- Shell: No direct nav include; wrapper/redirect/dynamic includes below govern.
- Inline CSS: yes; inline JS: yes.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/admin/nav.php:5](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/nav.php#L5) requireAdmin(); |
| Includes / render ownership | [public_html/admin/nav.php:4](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/nav.php#L4) require_once __DIR__ . '/../includes/auth.php';<br>[public_html/admin/nav.php:260](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/nav.php#L260) require_once __DIR__ . '/../includes/music_player.php';<br>[public_html/admin/nav.php:261](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/nav.php#L261) echo renderMusicPlayer('admin', '../'); |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | [public_html/admin/nav.php:24](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/nav.php#L24) &lt;script src="../assets/js/shell-bridge.js?v=&lt;?php echo $shellBridgeVersion; ?&gt;"&gt;&lt;/script&gt;<br>[public_html/admin/nav.php:25](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/nav.php#L25) &lt;script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"&gt;&lt;/script&gt;<br>[public_html/admin/nav.php:213](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/nav.php#L213) &lt;script src="../assets/js/nav.js?v=&lt;?php echo $navAssetVersion; ?&gt;"&gt;&lt;/script&gt;<br>[public_html/admin/nav.php:216](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/nav.php#L216) &lt;script src="../assets/js/security.js?v=&lt;?php echo $securityAssetVersion; ?&gt;"&gt;&lt;/script&gt;<br>[public_html/admin/nav.php:225](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/nav.php#L225) &lt;script src="../assets/js/lang.js?v=&lt;?php echo $langAssetVersion; ?&gt;"&gt;&lt;/script&gt; |
| Forms / actions | [public_html/admin/nav.php:21](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/nav.php#L21) $shellBridgeEligible = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET');<br>[public_html/admin/nav.php:137](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/nav.php#L137) &lt;form method="POST" action="../logout.php" class="hidden 2xl:inline-flex m-0 shrink-0"&gt;<br>[public_html/admin/nav.php:206](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/nav.php#L206) &lt;form method="POST" action="../logout.php" class="m-0"&gt; |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | [public_html/admin/nav.php:148](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/nav.php#L148) &lt;div id="drawerOverlay" class="drawer-overlay" aria-hidden="true"&gt;&lt;/div&gt;<br>[public_html/admin/nav.php:150](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/nav.php#L150) &lt;aside id="drawer" class="drawer glass border-r border-white/10 flex flex-col" aria-hidden="true" aria-label="Admin navigation"&gt; |

### `admin/out_of_stock.php`

- Scope: admin; risk: **High**; runtime: **UNKNOWN / UNTESTED**.
- Shell: role nav bridge if rendered; actual request branch must be observed.
- Inline CSS: no direct block; inline JS: yes.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/admin/out_of_stock.php:5](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/out_of_stock.php#L5) requireAdmin(); |
| Includes / render ownership | [public_html/admin/out_of_stock.php:4](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/out_of_stock.php#L4) require_once __DIR__ . '/../includes/auth.php';<br>[public_html/admin/out_of_stock.php:170](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/out_of_stock.php#L170) &lt;?php include 'nav.php'; ?&gt; |
| Entry / redirect | [public_html/admin/out_of_stock.php:125](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/out_of_stock.php#L125) header('Location: out_of_stock.php', true, 303); |
| Direct CSS / JS (also inherit nav/includes above) | [public_html/admin/out_of_stock.php:166](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/out_of_stock.php#L166) &lt;link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3"&gt;<br>[public_html/admin/out_of_stock.php:167](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/out_of_stock.php#L167) &lt;link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet"&gt; |
| Forms / actions | [public_html/admin/out_of_stock.php:12](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/out_of_stock.php#L12) $postedAction = isset($_POST['action']) && is_scalar($_POST['action'])<br>[public_html/admin/out_of_stock.php:13](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/out_of_stock.php#L13) ? substr((string) $_POST['action'], 0, 40)<br>[public_html/admin/out_of_stock.php:15](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/out_of_stock.php#L15) if ($_SERVER['REQUEST_METHOD'] === 'POST' && $postedAction === 'add_variant_keys') {<br>[public_html/admin/out_of_stock.php:270](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/out_of_stock.php#L270) &lt;form method="POST"&gt; |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | [public_html/admin/out_of_stock.php:258](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/out_of_stock.php#L258) &lt;div id="addVariantKeysModal" class="fixed inset-0 hidden items-center justify-center z-50"&gt;<br>[public_html/admin/out_of_stock.php:273](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/out_of_stock.php#L273) &lt;input type="hidden" name="variant_id" id="modal_variant_id"&gt;<br>[public_html/admin/out_of_stock.php:276](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/out_of_stock.php#L276) &lt;div class="text-gray-400 text-sm mb-1"&gt;&lt;span data-lang="admin.out_of_stock.modal.product"&gt;&lt;?php echo Lang::t('admin.out_of_stock.modal.product'); ?&gt;:&lt;/span&gt; &lt;span class="text-white font-semibold" id="modal_product_name"&gt;&lt;/span&gt;&lt;/div&gt;<br>[public_html/admin/out_of_stock.php:277](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/out_of_stock.php#L277) &lt;div class="text-gray-400 text-sm"&gt;&lt;span data-lang="admin.out_of_stock.modal.variant"&gt;&lt;?php echo Lang::t('admin.out_of_stock.modal.variant'); ?&gt;:&lt;/span&gt; &lt;span class="text-white font-semibold" id="modal_variant_name"&gt;&lt;/span&gt;&lt;/div&gt; |

### `admin/products.php`

- Scope: admin; risk: **High**; runtime: **UNKNOWN / UNTESTED**.
- Shell: role nav bridge if rendered; actual request branch must be observed.
- Inline CSS: yes; inline JS: yes.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/admin/products.php:97](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/products.php#L97) requireAdmin(); |
| Includes / render ownership | [public_html/admin/products.php:94](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/products.php#L94) require_once '../includes/auth.php';<br>[public_html/admin/products.php:95](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/products.php#L95) require_once '../includes/store_bridge.php';<br>[public_html/admin/products.php:1371](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/products.php#L1371) &lt;?php include 'nav.php'; ?&gt; |
| Entry / redirect | [public_html/admin/products.php:189](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/products.php#L189) header('Location: ' . productsPageUrl($anchor), true, 303); |
| Direct CSS / JS (also inherit nav/includes above) | [public_html/admin/products.php:1281](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/products.php#L1281) &lt;link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3"&gt;<br>[public_html/admin/products.php:1284](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/products.php#L1284) &lt;link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css"&gt;<br>[public_html/admin/products.php:3307](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/products.php#L3307) &lt;script src="../assets/js/instant-filter.js?v=3.0"&gt;&lt;/script&gt; |
| Forms / actions | [public_html/admin/products.php:287](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/products.php#L287) if ($_SERVER['REQUEST_METHOD'] === 'POST') {<br>[public_html/admin/products.php:294](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/products.php#L294) $actionRaw = $_POST['action'] ?? '';<br>[public_html/admin/products.php:1470](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/products.php#L1470) &lt;form method="GET" action="products.php#product-filters" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-6 gap-2 md:gap-3 items-end"&gt;<br>[public_html/admin/products.php:2146](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/products.php#L2146) &lt;form method="POST" enctype="multipart/form-data"&gt;<br>[public_html/admin/products.php:2315](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/products.php#L2315) &lt;form method="POST" enctype="multipart/form-data"&gt;<br>[public_html/admin/products.php:2393](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/products.php#L2393) &lt;form method="POST" enctype="multipart/form-data"&gt;<br>[public_html/admin/products.php:2470](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/products.php#L2470) &lt;form method="POST" enctype="multipart/form-data"&gt; |
| Dynamic endpoints | [public_html/admin/products.php:716](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/products.php#L716) $found = $lock-&gt;fetch();<br>[public_html/admin/products.php:840](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/products.php#L840) $found = $stmt-&gt;fetch();<br>[public_html/admin/products.php:2709](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/products.php#L2709) * Send via fetch(), then reload via redirect so the page is fully synced with the database.<br>[public_html/admin/products.php:3074](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/products.php#L3074) const res = await fetch(window.location.pathname, {<br>[public_html/admin/products.php:3082](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/products.php#L3082) 'X-Requested-With': 'XMLHttpRequest', |
| State / interactions | [public_html/admin/products.php:1468](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/products.php#L1468) &lt;div id="adminProductsLivePanel" data-instant-panel class="space-y-4"&gt;<br>[public_html/admin/products.php:2125](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/products.php#L2125) &lt;div class="fixed inset-0 bg-black/50 backdrop-blur-sm hidden items-center justify-center z-50" id="addModal"&gt; |

### `admin/profit.php`

- Scope: admin; risk: **High**; runtime: **UNKNOWN / UNTESTED**.
- Shell: role nav bridge if rendered; actual request branch must be observed.
- Inline CSS: yes; inline JS: yes.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/admin/profit.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/profit.php#L3) requireAdmin(); |
| Includes / render ownership | [public_html/admin/profit.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/profit.php#L2) require_once '../includes/auth.php';<br>[public_html/admin/profit.php:49](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/profit.php#L49) &lt;?php include 'nav.php'; ?&gt; |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | [public_html/admin/profit.php:42](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/profit.php#L42) &lt;link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3"&gt;<br>[public_html/admin/profit.php:43](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/profit.php#L43) &lt;link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css"&gt;<br>[public_html/admin/profit.php:196](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/profit.php#L196) &lt;script src="../assets/js/instant-filter.js?v=3.0"&gt;&lt;/script&gt; |
| Forms / actions | [public_html/admin/profit.php:59](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/profit.php#L59) &lt;form class="glass rounded-xl p-3 flex flex-col md:flex-row gap-2 items-stretch md:items-end" method="GET"&gt; |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | [public_html/admin/profit.php:52](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/profit.php#L52) &lt;div id="adminProfitLivePanel" data-instant-panel class="space-y-4"&gt; |

### `admin/rankings.php`

- Scope: admin; risk: **High**; runtime: **UNKNOWN / UNTESTED**.
- Shell: role nav bridge if rendered; actual request branch must be observed.
- Inline CSS: yes; inline JS: no direct block.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/admin/rankings.php:4](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/rankings.php#L4) requireAdmin(); |
| Includes / render ownership | [public_html/admin/rankings.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/rankings.php#L2) require_once '../includes/auth.php';<br>[public_html/admin/rankings.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/rankings.php#L3) require_once '../includes/ranking.php';<br>[public_html/admin/rankings.php:110](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/rankings.php#L110) &lt;?php include 'nav.php'; ?&gt; |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | [public_html/admin/rankings.php:99](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/rankings.php#L99) &lt;link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3"&gt;<br>[public_html/admin/rankings.php:100](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/rankings.php#L100) &lt;link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css"&gt; |
| Forms / actions | No direct match (inherited/dynamic behavior must be traced) |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `admin/reseller-prices.php`

- Scope: admin; risk: **High**; runtime: **UNKNOWN / UNTESTED**.
- Shell: role nav bridge if rendered; actual request branch must be observed.
- Inline CSS: yes; inline JS: yes.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/admin/reseller-prices.php:4](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/reseller-prices.php#L4) requireAdmin(); |
| Includes / render ownership | [public_html/admin/reseller-prices.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/reseller-prices.php#L2) require_once __DIR__ . '/../includes/auth.php';<br>[public_html/admin/reseller-prices.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/reseller-prices.php#L3) require_once __DIR__ . '/../includes/cheatgame.php';<br>[public_html/admin/reseller-prices.php:660](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/reseller-prices.php#L660) &lt;?php include 'nav.php'; ?&gt; |
| Entry / redirect | [public_html/admin/reseller-prices.php:33](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/reseller-prices.php#L33) header('Location: ' . $url, true, 303); |
| Direct CSS / JS (also inherit nav/includes above) | [public_html/admin/reseller-prices.php:645](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/reseller-prices.php#L645) &lt;link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3"&gt;<br>[public_html/admin/reseller-prices.php:646](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/reseller-prices.php#L646) &lt;script src="https://unpkg.com/lucide@latest"&gt;&lt;/script&gt;<br>[public_html/admin/reseller-prices.php:647](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/reseller-prices.php#L647) &lt;link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css"&gt; |
| Forms / actions | [public_html/admin/reseller-prices.php:97](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/reseller-prices.php#L97) if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {<br>[public_html/admin/reseller-prices.php:100](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/reseller-prices.php#L100) $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';<br>[public_html/admin/reseller-prices.php:725](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/reseller-prices.php#L725) &lt;form method="GET" class="grid grid-cols-1 md:grid-cols-[1fr_180px_200px_auto] gap-2"&gt;<br>[public_html/admin/reseller-prices.php:844](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/reseller-prices.php#L844) &lt;form method="POST"&gt;<br>[public_html/admin/reseller-prices.php:856](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/reseller-prices.php#L856) &lt;form method="POST" onsubmit="return confirmClearAll(event)"&gt;<br>[public_html/admin/reseller-prices.php:887](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/reseller-prices.php#L887) &lt;form method="POST" id="bulkForm" class="rounded-xl bg-white/[.03] border border-white/10 p-3"&gt;<br>[public_html/admin/reseller-prices.php:922](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/reseller-prices.php#L922) &lt;form method="POST" id="savePricesForm"&gt;<br>[public_html/admin/reseller-prices.php:1042](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/reseller-prices.php#L1042) &lt;form method="POST" id="rowActionForm" class="hidden"&gt; |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `admin/resellers.php`

- Scope: admin; risk: **High**; runtime: **UNKNOWN / UNTESTED**.
- Shell: role nav bridge if rendered; actual request branch must be observed.
- Inline CSS: yes; inline JS: yes.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/admin/resellers.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/resellers.php#L3) requireAdmin(); |
| Includes / render ownership | [public_html/admin/resellers.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/resellers.php#L2) require_once '../includes/auth.php';<br>[public_html/admin/resellers.php:148](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/resellers.php#L148) &lt;?php include 'nav.php'; ?&gt; |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | [public_html/admin/resellers.php:122](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/resellers.php#L122) &lt;link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3"&gt;<br>[public_html/admin/resellers.php:123](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/resellers.php#L123) &lt;link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css"&gt; |
| Forms / actions | [public_html/admin/resellers.php:16](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/resellers.php#L16) if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {<br>[public_html/admin/resellers.php:18](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/resellers.php#L18) $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';<br>[public_html/admin/resellers.php:174](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/resellers.php#L174) &lt;form method="GET" action="resellers.php" class="flex flex-col sm:flex-row gap-2"&gt;<br>[public_html/admin/resellers.php:334](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/resellers.php#L334) &lt;form method="POST"&gt;<br>[public_html/admin/resellers.php:367](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/resellers.php#L367) &lt;form method="POST"&gt; |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | [public_html/admin/resellers.php:196](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/resellers.php#L196) &lt;div class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden items-center justify-center z-50 p-3" id="securityDetailModal" role="dialog" aria-modal="true" aria-hidden="true" tabindex="-1"&gt;<br>[public_html/admin/resellers.php:328](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/resellers.php#L328) &lt;div class="fixed inset-0 bg-black/50 backdrop-blur-sm hidden items-center justify-center z-50" id="addModal" role="dialog" aria-modal="true" aria-hidden="true" tabindex="-1"&gt;<br>[public_html/admin/resellers.php:361](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/resellers.php#L361) &lt;div class="fixed inset-0 bg-black/50 backdrop-blur-sm hidden items-center justify-center z-50" id="balanceModal" role="dialog" aria-modal="true" aria-hidden="true" tabindex="-1"&gt;<br>[public_html/admin/resellers.php:412](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/resellers.php#L412) (focusable[0] &#124;&#124; modal).focus({preventScroll: true});<br>[public_html/admin/resellers.php:425](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/resellers.php#L425) managedModalReturnFocus.focus({preventScroll: true});<br>[public_html/admin/resellers.php:500](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/resellers.php#L500) if (focusable.length === 0) { event.preventDefault(); modal.focus(); return; }<br>[public_html/admin/resellers.php:503](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/resellers.php#L503) if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }<br>[public_html/admin/resellers.php:504](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/resellers.php#L504) else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); } |

### `admin/security.php`

- Scope: admin; risk: **High**; runtime: **UNKNOWN / UNTESTED**.
- Shell: role nav bridge if rendered; actual request branch must be observed.
- Inline CSS: yes; inline JS: yes.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/admin/security.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/security.php#L3) requireAdmin(); |
| Includes / render ownership | [public_html/admin/security.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/security.php#L2) require_once '../includes/auth.php';<br>[public_html/admin/security.php:245](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/security.php#L245) &lt;?php include 'nav.php'; ?&gt; |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | [public_html/admin/security.php:233](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/security.php#L233) &lt;link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3"&gt;<br>[public_html/admin/security.php:234](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/security.php#L234) &lt;link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css"&gt; |
| Forms / actions | [public_html/admin/security.php:49](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/security.php#L49) if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {<br>[public_html/admin/security.php:126](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/security.php#L126) if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') $accountSearch = securityPostString('account_q');<br>[public_html/admin/security.php:263](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/security.php#L263) &lt;form method="POST" class="space-y-4"&gt;<br>[public_html/admin/security.php:280](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/security.php#L280) &lt;form method="POST" class="space-y-2"&gt;<br>[public_html/admin/security.php:286](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/security.php#L286) &lt;form method="POST" class="space-y-2 border-t border-white/10 pt-4"&gt;<br>[public_html/admin/security.php:292](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/security.php#L292) &lt;form method="POST" class="space-y-2 border-t border-white/10 pt-4"&gt;<br>[public_html/admin/security.php:306](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/security.php#L306) &lt;form method="GET" class="flex flex-col sm:flex-row gap-2"&gt;<br>[public_html/admin/security.php:323](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/security.php#L323) &lt;form method="POST" onsubmit="return confirm('บล็อคบัญชีนี้พร้อม Email, Device ID และ IP ล่าสุดที่รู้จัก? IP อาจถูกแชร์โดยหลายคน ควรตรวจรายละเอียดก่อนยืนยัน');"&gt;<br>[public_html/admin/security.php:331](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/security.php#L331) &lt;form method="POST" onsubmit="return confirm('ปลดสถานะบัญชีและลบ Email / Device / IP ที่ระบบรู้จักของบัญชีนี้ออกจาก Shared Security?');"&gt;<br>[public_html/admin/security.php:356](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/security.php#L356) &lt;form method="GET" class="flex flex-col sm:flex-row gap-2 w-full lg:w-auto"&gt;<br>[public_html/admin/security.php:452](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/security.php#L452) &lt;form method="POST"&gt;&lt;?php echo csrfField(); ?&gt;&lt;input type="hidden" name="action" value="block_device"&gt;&lt;input type="hidden" name="device_hash" value="&lt;?php echo htmlspecialchars((string) ($row['device_hash'] ?? ''), ENT_QUOTES, 'UTF-8'); ?&gt;"<br>[public_html/admin/security.php:453](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/security.php#L453) &lt;?php if (!empty($row['last_ip'])): ?&gt;&lt;form method="POST"&gt;&lt;?php echo csrfField(); ?&gt;&lt;input type="hidden" name="action" value="block_ip"&gt;&lt;input type="hidden" name="ip" value="&lt;?php echo htmlspecialchars((string) $row['last_ip'], ENT_QUOTES, <br>[public_html/admin/security.php:492](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/security.php#L492) &lt;form method="POST"&gt; |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | [public_html/admin/security.php:372](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/security.php#L372) &lt;div class="security-modal" id="deviceDetailModal" role="dialog" aria-modal="true" aria-labelledby="deviceDetailTitle"&gt; |

### `admin/set_currency.php`

- Scope: admin; risk: **High**; runtime: **UNKNOWN / UNTESTED**.
- Shell: No direct nav include; wrapper/redirect/dynamic includes below govern.
- Inline CSS: no direct block; inline JS: no direct block.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/admin/set_currency.php:5](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/set_currency.php#L5) requireAdmin(); |
| Includes / render ownership | [public_html/admin/set_currency.php:4](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/set_currency.php#L4) require_once __DIR__ . '/../includes/auth.php'; |
| Entry / redirect | [public_html/admin/set_currency.php:8](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/set_currency.php#L8) header('Location: settings.php?notice=currency_managed_in_settings', true, 303); |
| Direct CSS / JS (also inherit nav/includes above) | No direct match (inherited/dynamic behavior must be traced) |
| Forms / actions | No direct match (inherited/dynamic behavior must be traced) |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `admin/settings.php`

- Scope: admin; risk: **High**; runtime: **UNKNOWN / UNTESTED**.
- Shell: role nav bridge if rendered; actual request branch must be observed.
- Inline CSS: yes; inline JS: yes.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/admin/settings.php:6](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/settings.php#L6) requireAdmin(); |
| Includes / render ownership | [public_html/admin/settings.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/settings.php#L2) require_once '../includes/auth.php';<br>[public_html/admin/settings.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/settings.php#L3) require_once '../includes/binance_giftcard.php';<br>[public_html/admin/settings.php:4](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/settings.php#L4) require_once '../includes/announcement_marquee.php';<br>[public_html/admin/settings.php:5](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/settings.php#L5) require_once '../includes/automation.php';<br>[public_html/admin/settings.php:1043](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/settings.php#L1043) &lt;?php include 'nav.php'; ?&gt; |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | [public_html/admin/settings.php:946](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/settings.php#L946) &lt;link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3"&gt;<br>[public_html/admin/settings.php:947](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/settings.php#L947) &lt;link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css"&gt;<br>[public_html/admin/settings.php:2247](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/settings.php#L2247) &lt;script src="../assets/js/announcement-marquee.js?v=&lt;?php echo (int) (@filemtime(__DIR__ . '/../assets/js/announcement-marquee.js') ?: 1); ?&gt;"&gt;&lt;/script&gt; |
| Forms / actions | [public_html/admin/settings.php:299](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/settings.php#L299) if ($_SERVER['REQUEST_METHOD'] === 'POST') {<br>[public_html/admin/settings.php:1080](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/settings.php#L1080) &lt;form method="POST" enctype="multipart/form-data" class="space-y-4"&gt;<br>[public_html/admin/settings.php:1528](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/settings.php#L1528) &lt;form method="POST"&gt;<br>[public_html/admin/settings.php:1536](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/settings.php#L1536) &lt;form method="POST" onsubmit="return confirm('&lt;?php echo $currentLang === 'en' ? 'The old scheduler URLs will stop working. Continue?' : 'URL Cron เดิมจะหยุดทำงาน ต้องการสร้างรหัสใหม่หรือไม่?'; ?&gt;');"&gt;<br>[public_html/admin/settings.php:1596](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/settings.php#L1596) &lt;form method="POST" enctype="multipart/form-data"&gt;<br>[public_html/admin/settings.php:1687](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/settings.php#L1687) &lt;form method="POST" class="space-y-4"&gt;<br>[public_html/admin/settings.php:1723](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/settings.php#L1723) &lt;form method="POST" class="space-y-4"&gt;<br>[public_html/admin/settings.php:1860](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/settings.php#L1860) &lt;form method="POST" class="space-y-4"&gt;<br>[public_html/admin/settings.php:1928](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/settings.php#L1928) &lt;form method="POST" class="space-y-5" autocomplete="off"&gt;<br>[public_html/admin/settings.php:2053](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/settings.php#L2053) &lt;form method="POST" class="mt-3"&gt;<br>[public_html/admin/settings.php:2072](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/settings.php#L2072) &lt;form method="POST" class="space-y-4"&gt;<br>[public_html/admin/settings.php:2140](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/settings.php#L2140) &lt;form method="POST"&gt; |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `admin/slip-debug.php`

- Scope: admin; risk: **High**; runtime: **UNKNOWN / UNTESTED**.
- Shell: role nav bridge if rendered; actual request branch must be observed.
- Inline CSS: yes; inline JS: no direct block.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/admin/slip-debug.php:5](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/slip-debug.php#L5) requireAdmin(); |
| Includes / render ownership | [public_html/admin/slip-debug.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/slip-debug.php#L2) require_once __DIR__ . '/../includes/auth.php';<br>[public_html/admin/slip-debug.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/slip-debug.php#L3) require_once __DIR__ . '/../includes/store_bridge.php';<br>[public_html/admin/slip-debug.php:4](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/slip-debug.php#L4) require_once __DIR__ . '/../includes/automation.php';<br>[public_html/admin/slip-debug.php:339](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/slip-debug.php#L339) &lt;?php include __DIR__ . '/nav.php'; ?&gt; |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | [public_html/admin/slip-debug.php:328](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/slip-debug.php#L328) &lt;link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3"&gt;<br>[public_html/admin/slip-debug.php:329](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/slip-debug.php#L329) &lt;link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css"&gt; |
| Forms / actions | [public_html/admin/slip-debug.php:59](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/slip-debug.php#L59) if ($_SERVER['REQUEST_METHOD'] === 'POST') {<br>[public_html/admin/slip-debug.php:61](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/slip-debug.php#L61) $postAction = (string) ($_POST['action'] ?? '');<br>[public_html/admin/slip-debug.php:377](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/slip-debug.php#L377) &lt;form method="POST" class="shrink-0" onsubmit="return confirm('&lt;?php echo $e($t('รัน Bulk Backfill ของเว็บนี้ทันทีหรือไม่? ระบบจะไม่ force readiness และไม่แตะยอดเงินลูกค้า', 'Run the local bulk slip-history migration now? This never forces <br>[public_html/admin/slip-debug.php:409](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/slip-debug.php#L409) &lt;form method="POST" class="flex flex-wrap items-end gap-2" onsubmit="return confirm('&lt;?php echo $e($t('ลบเฉพาะ Debug log ที่เก่ากว่าจำนวนวันที่เลือกหรือไม่?', 'Delete only debug events older than the selected number of days?')); ?&gt;')"&gt;<br>[public_html/admin/slip-debug.php:457](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/slip-debug.php#L457) &lt;form method="POST" class="shrink-0" onsubmit="return confirm('&lt;?php echo $e($refreshArmed ? $t('ยกเลิกการเรียก EasySlip ใหม่หรือไม่?', 'Cancel the fresh EasySlip capture?') : $t('การทดสอบครั้งถัดไปจะเรียก EasySlip จริงและใช้โควตา 1 ครั้ง ย<br>[public_html/admin/slip-debug.php:531](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/slip-debug.php#L531) &lt;form method="get" class="grid gap-3 md:grid-cols-2 xl:grid-cols-6"&gt; |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `admin/transaction_integrity.php`

- Scope: admin; risk: **Critical**; runtime: **UNKNOWN / UNTESTED**.
- Shell: role nav bridge if rendered; actual request branch must be observed.
- Inline CSS: yes; inline JS: yes.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/admin/transaction_integrity.php:4](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/transaction_integrity.php#L4) requireAdmin(); |
| Includes / render ownership | [public_html/admin/transaction_integrity.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/transaction_integrity.php#L2) require_once __DIR__ . '/../includes/auth.php';<br>[public_html/admin/transaction_integrity.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/transaction_integrity.php#L3) require_once __DIR__ . '/../includes/transaction_integrity.php';<br>[public_html/admin/transaction_integrity.php:66](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/transaction_integrity.php#L66) &lt;?php include __DIR__ . '/nav.php'; ?&gt; |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | [public_html/admin/transaction_integrity.php:52](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/transaction_integrity.php#L52) &lt;link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3"&gt;<br>[public_html/admin/transaction_integrity.php:53](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/transaction_integrity.php#L53) &lt;link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css"&gt; |
| Forms / actions | [public_html/admin/transaction_integrity.php:125](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/transaction_integrity.php#L125) &lt;form method="post" action="transaction_integrity_action.php" data-no-page-loader class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end" id="repairForm"&gt; |
| Dynamic endpoints | [public_html/admin/transaction_integrity.php:210](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/transaction_integrity.php#L210) const response=await fetch('transaction_integrity_action.php',{method:'POST',body:data,credentials:'same-origin',headers:{Accept:'application/json'},signal:controller.signal}); |
| State / interactions | [public_html/admin/transaction_integrity.php:203](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/transaction_integrity.php#L203) function setRunning(value){repairRunning=Boolean(value);if(repairSubmit){repairSubmit.disabled=repairRunning;repairSubmit.setAttribute('aria-busy',repairRunning?'true':'false');const span=repairSubmit.querySelector('span');if(span)span.text<br>[public_html/admin/transaction_integrity.php:224](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/transaction_integrity.php#L224) if(!confirmation&#124;&#124;confirmation.value.trim().toUpperCase()!=='REPAIR'){confirmation?.focus();setStatus('error',TI_TEXT.failed,TI_TEXT.confirm,'confirmation');return;} |

### `admin/transaction_integrity_action.php`

- Scope: admin; risk: **Critical**; runtime: **UNKNOWN / UNTESTED**.
- Shell: No direct nav include; wrapper/redirect/dynamic includes below govern.
- Inline CSS: no direct block; inline JS: no direct block.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/admin/transaction_integrity_action.php:4](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/transaction_integrity_action.php#L4) requireAdmin(); |
| Includes / render ownership | [public_html/admin/transaction_integrity_action.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/transaction_integrity_action.php#L2) require_once __DIR__ . '/../includes/auth.php';<br>[public_html/admin/transaction_integrity_action.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/transaction_integrity_action.php#L3) require_once __DIR__ . '/../includes/transaction_integrity.php'; |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | No direct match (inherited/dynamic behavior must be traced) |
| Forms / actions | [public_html/admin/transaction_integrity_action.php:60](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/transaction_integrity_action.php#L60) if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {<br>[public_html/admin/transaction_integrity_action.php:70](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/transaction_integrity_action.php#L70) $action = isset($_POST['action']) && is_scalar($_POST['action']) ? trim((string) $_POST['action']) : ''; |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `admin/transactions.php`

- Scope: admin; risk: **Critical**; runtime: **UNKNOWN / UNTESTED**.
- Shell: role nav bridge if rendered; actual request branch must be observed.
- Inline CSS: yes; inline JS: yes.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/admin/transactions.php:5](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/transactions.php#L5) requireAdmin(); |
| Includes / render ownership | [public_html/admin/transactions.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/transactions.php#L2) require_once __DIR__ . '/../includes/auth.php';<br>[public_html/admin/transactions.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/transactions.php#L3) require_once __DIR__ . '/../includes/cheatgame.php';<br>[public_html/admin/transactions.php:4](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/transactions.php#L4) require_once __DIR__ . '/../includes/wallet_ledger.php';<br>[public_html/admin/transactions.php:3682](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/transactions.php#L3682) &lt;?php include 'nav.php'; ?&gt; |
| Entry / redirect | [public_html/admin/transactions.php:1222](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/transactions.php#L1222) header('Location: ' . $location, true, 303); |
| Direct CSS / JS (also inherit nav/includes above) | [public_html/admin/transactions.php:3600](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/transactions.php#L3600) &lt;link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3"&gt;<br>[public_html/admin/transactions.php:3601](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/transactions.php#L3601) &lt;link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css"&gt;<br>[public_html/admin/transactions.php:4615](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/transactions.php#L4615) &lt;script src="../assets/js/instant-filter.js?v=3.0"&gt;&lt;/script&gt; |
| Forms / actions | [public_html/admin/transactions.php:1186](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/transactions.php#L1186) if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {<br>[public_html/admin/transactions.php:1188](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/transactions.php#L1188) $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';<br>[public_html/admin/transactions.php:3722](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/transactions.php#L3722) &lt;form method="GET" action="transactions.php" id="transactionFilterForm" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-5 gap-3"&gt;<br>[public_html/admin/transactions.php:4118](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/transactions.php#L4118) &lt;form method="POST" action="transactions.php" class="inline"&gt; |
| Dynamic endpoints | [public_html/admin/transactions.php:734](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/transactions.php#L734) $endpoint = strtolower((string) $parts['scheme']) . '://' . (string) $parts['host'];<br>[public_html/admin/transactions.php:4494](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/transactions.php#L4494) const response = await fetch(auditUrl, {<br>[public_html/admin/transactions.php:4500](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/transactions.php#L4500) 'X-Requested-With': 'XMLHttpRequest' |
| State / interactions | [public_html/admin/transactions.php:3713](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/transactions.php#L3713) &lt;div id="transactionFilterPanel" data-instant-panel data-instant-targets="#transactionFilterPanel,#transactionResultsPanel" class="space-y-4"&gt;<br>[public_html/admin/transactions.php:3822](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/transactions.php#L3822) &lt;section id="transactionResultsPanel" data-instant-panel data-instant-targets="#transactionFilterPanel,#transactionResultsPanel" class="space-y-3"&gt;<br>[public_html/admin/transactions.php:4247](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/transactions.php#L4247) &lt;div id="detailModalOverlay" aria-hidden="true"&gt;<br>[public_html/admin/transactions.php:4248](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/transactions.php#L4248) &lt;button type="button" id="detailModalBackdrop" aria-label="&lt;?php echo htmlspecialchars($t('ปิดหน้าต่างหลักฐาน', 'Close evidence dialog'), ENT_QUOTES, 'UTF-8'); ?&gt;"&gt;&lt;/button&gt;<br>[public_html/admin/transactions.php:4249](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/transactions.php#L4249) &lt;section id="detailModalPanel" role="dialog" aria-modal="true" aria-labelledby="detailModalTitle" tabindex="-1"&gt;<br>[public_html/admin/transactions.php:4252](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/transactions.php#L4252) &lt;h5 id="detailModalTitle" class="truncate font-bold text-white text-base"&gt;&lt;?php echo htmlspecialchars($t('หลักฐานการซื้อและ JSON สำหรับตรวจสอบ', 'Purchase Evidence and Diagnostic JSON'), ENT_QUOTES, 'UTF-8'); ?&gt;&lt;/h5&gt;<br>[public_html/admin/transactions.php:4253](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/transactions.php#L4253) &lt;div id="modalAuditStatus" class="mt-0.5 text-[10px] text-gray-400"&gt;&lt;?php echo htmlspecialchars($t('พร้อมตรวจสอบ', 'Ready'), ENT_QUOTES, 'UTF-8'); ?&gt;&lt;/div&gt;<br>[public_html/admin/transactions.php:4259](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/transactions.php#L4259) &lt;div class="rounded-lg border border-white/5 bg-[#111827] p-3 sm:col-span-2"&gt;&lt;div class="text-gray-500 text-[10px] mb-1 uppercase tracking-wide"&gt;&lt;?php echo htmlspecialchars($t('สินค้า', 'Product'), ENT_QUOTES, 'UTF-8'); ?&gt;&lt;/div&gt;&lt;div class="<br>[public_html/admin/transactions.php:4260](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/transactions.php#L4260) &lt;div class="rounded-lg border border-white/5 bg-[#111827] p-3"&gt;&lt;div class="text-gray-500 text-[10px] mb-1 uppercase tracking-wide"&gt;&lt;?php echo htmlspecialchars($t('วันที่ซื้อ', 'Purchase date'), ENT_QUOTES, 'UTF-8'); ?&gt;&lt;/div&gt;&lt;div class="text<br>[public_html/admin/transactions.php:4261](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/transactions.php#L4261) &lt;div class="rounded-lg border border-white/5 bg-[#111827] p-3"&gt;&lt;div class="text-gray-500 text-[10px] mb-1 uppercase tracking-wide"&gt;&lt;?php echo htmlspecialchars($t('ยอดที่จ่าย', 'Amount paid'), ENT_QUOTES, 'UTF-8'); ?&gt;&lt;/div&gt;&lt;div class="text-g<br>[public_html/admin/transactions.php:4266](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/transactions.php#L4266) &lt;div class="rounded-lg border border-white/5 bg-[#0b101e] p-3 text-xs text-gray-300 whitespace-pre-wrap break-all" id="modalMeta"&gt;-&lt;/div&gt;<br>[public_html/admin/transactions.php:4271](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/transactions.php#L4271) &lt;div class="max-h-56 overflow-y-auto rounded-lg border border-white/5 bg-[#0b101e] p-3 text-gray-300 font-mono text-sm break-all" id="modalKeys"&gt;&lt;/div&gt;<br>[public_html/admin/transactions.php:4281](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/transactions.php#L4281) &lt;a href="#" target="_blank" rel="noopener noreferrer" data-no-page-loader id="modalOpenJsonBtn" class="hidden rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-xs font-medium text-gray-200 hover:bg-white/10"&gt;&lt;i class="bi bi-box-ar<br>[public_html/admin/transactions.php:4282](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/transactions.php#L4282) &lt;button type="button" onclick="copyModalAuditJson()" class="hidden rounded-lg border border-cyan-400/20 bg-cyan-400/10 px-3 py-2 text-xs font-medium text-cyan-200 hover:bg-cyan-400/20" id="modalCopyJsonBtn"&gt;&lt;i class="bi bi-copy mr-1"&gt;&lt;/i&gt;&lt;?<br>[public_html/admin/transactions.php:4285](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/transactions.php#L4285) &lt;pre id="modalAuditJson" class="audit-json-box rounded-lg border border-white/5 bg-[#070b14] p-3 text-[11px] leading-relaxed text-gray-300"&gt;-&lt;/pre&gt;<br>[public_html/admin/transactions.php:4289](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/transactions.php#L4289) &lt;button type="button" onclick="copyModalKeys()" class="w-full bg-green-600 hover:bg-green-500 rounded-lg py-2.5 text-sm font-medium" id="modalCopyBtn"&gt;&lt;i class="bi bi-copy mr-1"&gt;&lt;/i&gt;&lt;?php echo htmlspecialchars($t('คัดลอกคีย์ทั้งหมด', 'Copy <br>[public_html/admin/transactions.php:4290](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/transactions.php#L4290) &lt;a href="#" target="_blank" rel="noopener noreferrer" id="modalDownloadBtn" class="hidden w-full items-center justify-center gap-2 rounded-lg bg-orange-600 py-2.5 text-sm font-medium hover:bg-orange-500"&gt;&lt;i class="bi bi-download"&gt;&lt;/i&gt;&lt;?php <br>[public_html/admin/transactions.php:4468](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/transactions.php#L4468) try { panel.focus({ preventScroll: true }); } catch (_error) { panel.focus(); } |

### `admin/truemoney_debug.php`

- Scope: admin; risk: **High**; runtime: **UNKNOWN / UNTESTED**.
- Shell: role nav bridge if rendered; actual request branch must be observed.
- Inline CSS: yes; inline JS: yes.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/admin/truemoney_debug.php:7](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/truemoney_debug.php#L7) requireAdmin(); |
| Includes / render ownership | [public_html/admin/truemoney_debug.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/truemoney_debug.php#L2) require_once __DIR__ . '/../includes/auth.php';<br>[public_html/admin/truemoney_debug.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/truemoney_debug.php#L3) require_once __DIR__ . '/../includes/truemoney.php';<br>[public_html/admin/truemoney_debug.php:4](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/truemoney_debug.php#L4) require_once __DIR__ . '/../includes/truemoney_byteindev.php';<br>[public_html/admin/truemoney_debug.php:5](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/truemoney_debug.php#L5) require_once __DIR__ . '/../includes/truemoney_byteindev_route.php';<br>[public_html/admin/truemoney_debug.php:6](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/truemoney_debug.php#L6) require_once __DIR__ . '/../includes/truemoney_byteindev_debug.php';<br>[public_html/admin/truemoney_debug.php:109](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/truemoney_debug.php#L109) &lt;?php include __DIR__ . '/nav.php'; ?&gt; |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | [public_html/admin/truemoney_debug.php:100](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/truemoney_debug.php#L100) &lt;link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3"&gt;<br>[public_html/admin/truemoney_debug.php:101](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/truemoney_debug.php#L101) &lt;link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css"&gt; |
| Forms / actions | [public_html/admin/truemoney_debug.php:37](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/truemoney_debug.php#L37) if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'provider_probe') { |
| Dynamic endpoints | [public_html/admin/truemoney_debug.php:248](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/truemoney_debug.php#L248) const response = await fetch('truemoney_debug.php', {method:'POST', credentials:'same-origin', cache:'no-store', headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}, body});<br>[public_html/admin/truemoney_debug.php:267](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/truemoney_debug.php#L267) const response = await fetch(url, {credentials:'same-origin', cache:'no-store', headers:{'Accept':'application/json'}}); |
| State / interactions | [public_html/admin/truemoney_debug.php:200](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/truemoney_debug.php#L200) &lt;div id="savedModal" class="fixed inset-0 z-[100] hidden items-center justify-center bg-black/70 p-4"&gt;<br>[public_html/admin/truemoney_debug.php:204](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/truemoney_debug.php#L204) &lt;div class="flex gap-2 border-t border-white/10 p-4"&gt;&lt;button id="copySavedModal" class="rounded-lg bg-violet-500/15 px-3 py-2 text-sm text-violet-200"&gt;&lt;i class="bi bi-copy mr-1"&gt;&lt;/i&gt;&lt;?php echo $e($t('คัดลอก JSON', 'Copy JSON')); ?&gt;&lt;/button&gt; |

### `admin/users.php`

- Scope: admin; risk: **High**; runtime: **UNKNOWN / UNTESTED**.
- Shell: role nav bridge if rendered; actual request branch must be observed.
- Inline CSS: yes; inline JS: yes.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/admin/users.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/users.php#L3) requireAdmin(); |
| Includes / render ownership | [public_html/admin/users.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/users.php#L2) require_once '../includes/auth.php';<br>[public_html/admin/users.php:159](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/users.php#L159) &lt;?php include 'nav.php'; ?&gt; |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | [public_html/admin/users.php:129](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/users.php#L129) &lt;link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3"&gt;<br>[public_html/admin/users.php:130](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/users.php#L130) &lt;link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css"&gt; |
| Forms / actions | [public_html/admin/users.php:22](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/users.php#L22) if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {<br>[public_html/admin/users.php:24](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/users.php#L24) $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';<br>[public_html/admin/users.php:188](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/users.php#L188) &lt;form method="GET" action="users.php" class="flex flex-col sm:flex-row gap-2"&gt;<br>[public_html/admin/users.php:437](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/users.php#L437) &lt;form method="POST"&gt;<br>[public_html/admin/users.php:481](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/users.php#L481) &lt;form method="POST"&gt; |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | [public_html/admin/users.php:239](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/users.php#L239) &lt;div class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden items-center justify-center z-50 p-3" id="securityDetailModal" role="dialog" aria-modal="true" aria-hidden="true" tabindex="-1"&gt;<br>[public_html/admin/users.php:429](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/users.php#L429) &lt;div class="fixed inset-0 bg-black/50 backdrop-blur-sm hidden items-center justify-center z-50" id="addModal" role="dialog" aria-modal="true" aria-hidden="true" tabindex="-1"&gt;<br>[public_html/admin/users.php:473](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/users.php#L473) &lt;div class="fixed inset-0 bg-black/50 backdrop-blur-sm hidden items-center justify-center z-50" id="balanceModal" role="dialog" aria-modal="true" aria-hidden="true" tabindex="-1"&gt;<br>[public_html/admin/users.php:540](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/users.php#L540) (focusable[0] &#124;&#124; modal).focus({preventScroll: true});<br>[public_html/admin/users.php:553](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/users.php#L553) managedModalReturnFocus.focus({preventScroll: true});<br>[public_html/admin/users.php:674](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/users.php#L674) if (focusable.length === 0) { event.preventDefault(); modal.focus(); return; }<br>[public_html/admin/users.php:677](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/users.php#L677) if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }<br>[public_html/admin/users.php:678](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/users.php#L678) else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); } |

### `admin/xchetos.php`

- Scope: admin; risk: **High**; runtime: **UNKNOWN / UNTESTED**.
- Shell: No direct nav include; wrapper/redirect/dynamic includes below govern.
- Inline CSS: no direct block; inline JS: no direct block.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/admin/xchetos.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/xchetos.php#L3) requireAdmin(); |
| Includes / render ownership | [public_html/admin/xchetos.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/xchetos.php#L2) require_once __DIR__ . '/../includes/auth.php'; |
| Entry / redirect | [public_html/admin/xchetos.php:4](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/xchetos.php#L4) header('Location: key_resets.php', true, 302); |
| Direct CSS / JS (also inherit nav/includes above) | No direct match (inherited/dynamic behavior must be traced) |
| Forms / actions | No direct match (inherited/dynamic behavior must be traced) |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `admin/xchetos_action.php`

- Scope: admin; risk: **High**; runtime: **UNKNOWN / UNTESTED**.
- Shell: No direct nav include; wrapper/redirect/dynamic includes below govern.
- Inline CSS: no direct block; inline JS: no direct block.

| Contract | Source evidence |
|---|---|
| Roles / guards | No direct match (inherited/dynamic behavior must be traced) |
| Includes / render ownership | [public_html/admin/xchetos_action.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/admin/xchetos_action.php#L3) require __DIR__ . '/key_reset_action.php'; |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | No direct match (inherited/dynamic behavior must be traced) |
| Forms / actions | No direct match (inherited/dynamic behavior must be traced) |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `api/store/diagnostic.php`

- Scope: public/API/background; risk: **Critical**; runtime: **UNKNOWN / UNTESTED**.
- Shell: No direct nav include; wrapper/redirect/dynamic includes below govern.
- Inline CSS: no direct block; inline JS: no direct block.

| Contract | Source evidence |
|---|---|
| Roles / guards | No direct match (inherited/dynamic behavior must be traced) |
| Includes / render ownership | [public_html/api/store/diagnostic.php:7](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/api/store/diagnostic.php#L7) require_once __DIR__ . '/../../includes/store_bridge.php'; |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | No direct match (inherited/dynamic behavior must be traced) |
| Forms / actions | [public_html/api/store/diagnostic.php:16](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/api/store/diagnostic.php#L16) $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')); |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `api/store/ping.php`

- Scope: public/API/background; risk: **Critical**; runtime: **UNKNOWN / UNTESTED**.
- Shell: No direct nav include; wrapper/redirect/dynamic includes below govern.
- Inline CSS: no direct block; inline JS: no direct block.

| Contract | Source evidence |
|---|---|
| Roles / guards | No direct match (inherited/dynamic behavior must be traced) |
| Includes / render ownership | [public_html/api/store/ping.php:9](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/api/store/ping.php#L9) require_once __DIR__ . '/../../includes/security.php'; |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | No direct match (inherited/dynamic behavior must be traced) |
| Forms / actions | [public_html/api/store/ping.php:18](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/api/store/ping.php#L18) $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')); |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `api/store/v1.php`

- Scope: public/API/background; risk: **Critical**; runtime: **UNKNOWN / UNTESTED**.
- Shell: No direct nav include; wrapper/redirect/dynamic includes below govern.
- Inline CSS: no direct block; inline JS: no direct block.

| Contract | Source evidence |
|---|---|
| Roles / guards | No direct match (inherited/dynamic behavior must be traced) |
| Includes / render ownership | [public_html/api/store/v1.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/api/store/v1.php#L2) require_once __DIR__ . '/../../includes/store_bridge.php'; |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | No direct match (inherited/dynamic behavior must be traced) |
| Forms / actions | [public_html/api/store/v1.php:11](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/api/store/v1.php#L11) $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')); |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `app.php`

- Scope: public/API/background; risk: **High**; runtime: **UNKNOWN / UNTESTED**.
- Shell: No direct nav include; wrapper/redirect/dynamic includes below govern.
- Inline CSS: yes; inline JS: yes.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/app.php:10](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/app.php#L10) requireLogin(); |
| Includes / render ownership | [public_html/app.php:9](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/app.php#L9) require_once __DIR__ . '/includes/auth.php';<br>[public_html/app.php:73](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/app.php#L73) require_once __DIR__ . '/includes/music_player.php';<br>[public_html/app.php:75](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/app.php#L75) $musicHtml = renderMusicPlayer($role, './'); |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | [public_html/app.php:95](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/app.php#L95) &lt;link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css"&gt;<br>[public_html/app.php:127](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/app.php#L127) &lt;script src="/assets/js/app-shell.js?v=&lt;?php echo (int) (@filemtime(__DIR__ . '/assets/js/app-shell.js') ?: 1); ?&gt;"&gt;&lt;/script&gt; |
| Forms / actions | No direct match (inherited/dynamic behavior must be traced) |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `assets/uploads/icons/index.php`

- Scope: public/API/background; risk: **High**; runtime: **UNKNOWN / UNTESTED**.
- Shell: No direct nav include; wrapper/redirect/dynamic includes below govern.
- Inline CSS: no direct block; inline JS: no direct block.

| Contract | Source evidence |
|---|---|
| Roles / guards | No direct match (inherited/dynamic behavior must be traced) |
| Includes / render ownership | No direct match (inherited/dynamic behavior must be traced) |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | No direct match (inherited/dynamic behavior must be traced) |
| Forms / actions | No direct match (inherited/dynamic behavior must be traced) |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `automation_runner.php`

- Scope: public/API/background; risk: **Critical**; runtime: **UNKNOWN / UNTESTED**.
- Shell: No direct nav include; wrapper/redirect/dynamic includes below govern.
- Inline CSS: no direct block; inline JS: no direct block.

| Contract | Source evidence |
|---|---|
| Roles / guards | No direct match (inherited/dynamic behavior must be traced) |
| Includes / render ownership | [public_html/automation_runner.php:38](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/automation_runner.php#L38) require_once __DIR__ . '/includes/automation.php';<br>[public_html/automation_runner.php:40](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/automation_runner.php#L40) require_once __DIR__ . '/includes/binance.php';<br>[public_html/automation_runner.php:41](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/automation_runner.php#L41) require_once __DIR__ . '/includes/maintenance_diagnostics.php'; |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | No direct match (inherited/dynamic behavior must be traced) |
| Forms / actions | No direct match (inherited/dynamic behavior must be traced) |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `cgo_inventory.php`

- Scope: public/API/background; risk: **High**; runtime: **UNKNOWN / UNTESTED**.
- Shell: No direct nav include; wrapper/redirect/dynamic includes below govern.
- Inline CSS: no direct block; inline JS: no direct block.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/cgo_inventory.php:12](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/cgo_inventory.php#L12) requireLogin();<br>[public_html/cgo_inventory.php:13](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/cgo_inventory.php#L13) if (!isUser() && !isReseller() && !isAdmin()) { |
| Includes / render ownership | [public_html/cgo_inventory.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/cgo_inventory.php#L2) require_once __DIR__ . '/includes/auth.php';<br>[public_html/cgo_inventory.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/cgo_inventory.php#L3) require_once __DIR__ . '/includes/cheatgame.php';<br>[public_html/cgo_inventory.php:4](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/cgo_inventory.php#L4) require_once __DIR__ . '/includes/automation.php'; |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | No direct match (inherited/dynamic behavior must be traced) |
| Forms / actions | No direct match (inherited/dynamic behavior must be traced) |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `cgo_order_status.php`

- Scope: public/API/background; risk: **Critical**; runtime: **UNKNOWN / UNTESTED**.
- Shell: No direct nav include; wrapper/redirect/dynamic includes below govern.
- Inline CSS: no direct block; inline JS: no direct block.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/cgo_order_status.php:11](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/cgo_order_status.php#L11) requireLogin();<br>[public_html/cgo_order_status.php:12](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/cgo_order_status.php#L12) if (!isUser() && !isReseller() && !isAdmin()) { |
| Includes / render ownership | [public_html/cgo_order_status.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/cgo_order_status.php#L2) require_once __DIR__ . '/includes/auth.php';<br>[public_html/cgo_order_status.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/cgo_order_status.php#L3) require_once __DIR__ . '/includes/cheatgame.php'; |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | No direct match (inherited/dynamic behavior must be traced) |
| Forms / actions | [public_html/cgo_order_status.php:15](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/cgo_order_status.php#L15) if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') { |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `forgot_password.php`

- Scope: public/API/background; risk: **High**; runtime: **UNKNOWN / UNTESTED**.
- Shell: No direct nav include; wrapper/redirect/dynamic includes below govern.
- Inline CSS: yes; inline JS: yes.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/forgot_password.php:8](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/forgot_password.php#L8) if (isLoggedIn()) redirectByRole(); |
| Includes / render ownership | [public_html/forgot_password.php:5](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/forgot_password.php#L5) require_once __DIR__ . '/includes/auth.php';<br>[public_html/forgot_password.php:6](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/forgot_password.php#L6) require_once __DIR__ . '/includes/account_recovery.php'; |
| Entry / redirect | [public_html/forgot_password.php:8](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/forgot_password.php#L8) if (isLoggedIn()) redirectByRole(); |
| Direct CSS / JS (also inherit nav/includes above) | [public_html/forgot_password.php:41](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/forgot_password.php#L41) &lt;link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3"&gt;<br>[public_html/forgot_password.php:42](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/forgot_password.php#L42) &lt;link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css"&gt;<br>[public_html/forgot_password.php:44](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/forgot_password.php#L44) &lt;script defer src="assets/js/security.js?v=3.4"&gt;&lt;/script&gt; |
| Forms / actions | [public_html/forgot_password.php:18](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/forgot_password.php#L18) if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {<br>[public_html/forgot_password.php:58](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/forgot_password.php#L58) &lt;form method="post" class="space-y-4" autocomplete="off"&gt; |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `generate_qr.php`

- Scope: public/API/background; risk: **High**; runtime: **UNKNOWN / UNTESTED**.
- Shell: No direct nav include; wrapper/redirect/dynamic includes below govern.
- Inline CSS: no direct block; inline JS: no direct block.

| Contract | Source evidence |
|---|---|
| Roles / guards | No direct match (inherited/dynamic behavior must be traced) |
| Includes / render ownership | No direct match (inherited/dynamic behavior must be traced) |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | No direct match (inherited/dynamic behavior must be traced) |
| Forms / actions | No direct match (inherited/dynamic behavior must be traced) |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `get_rate.php`

- Scope: public/API/background; risk: **High**; runtime: **UNKNOWN / UNTESTED**.
- Shell: No direct nav include; wrapper/redirect/dynamic includes below govern.
- Inline CSS: no direct block; inline JS: no direct block.

| Contract | Source evidence |
|---|---|
| Roles / guards | No direct match (inherited/dynamic behavior must be traced) |
| Includes / render ownership | [public_html/get_rate.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/get_rate.php#L2) require_once 'includes/db.php';<br>[public_html/get_rate.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/get_rate.php#L3) require_once 'includes/functions.php'; |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | No direct match (inherited/dynamic behavior must be traced) |
| Forms / actions | No direct match (inherited/dynamic behavior must be traced) |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `health.php`

- Scope: public/API/background; risk: **High**; runtime: **UNKNOWN / UNTESTED**.
- Shell: No direct nav include; wrapper/redirect/dynamic includes below govern.
- Inline CSS: no direct block; inline JS: no direct block.

| Contract | Source evidence |
|---|---|
| Roles / guards | No direct match (inherited/dynamic behavior must be traced) |
| Includes / render ownership | No direct match (inherited/dynamic behavior must be traced) |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | No direct match (inherited/dynamic behavior must be traced) |
| Forms / actions | No direct match (inherited/dynamic behavior must be traced) |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `index.php`

- Scope: public/API/background; risk: **High**; runtime: **UNKNOWN / UNTESTED**.
- Shell: No direct nav include; wrapper/redirect/dynamic includes below govern.
- Inline CSS: no direct block; inline JS: no direct block.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/index.php:5](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/index.php#L5) if (isLoggedIn()) { |
| Includes / render ownership | [public_html/index.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/index.php#L2) require_once 'includes/auth.php'; |
| Entry / redirect | [public_html/index.php:6](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/index.php#L6) redirectByRole();<br>[public_html/index.php:10](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/index.php#L10) header('Location: login.php'); |
| Direct CSS / JS (also inherit nav/includes above) | No direct match (inherited/dynamic behavior must be traced) |
| Forms / actions | No direct match (inherited/dynamic behavior must be traced) |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `line_callback.php`

- Scope: public/API/background; risk: **High**; runtime: **UNKNOWN / UNTESTED**.
- Shell: No direct nav include; wrapper/redirect/dynamic includes below govern.
- Inline CSS: no direct block; inline JS: no direct block.

| Contract | Source evidence |
|---|---|
| Roles / guards | No direct match (inherited/dynamic behavior must be traced) |
| Includes / render ownership | No direct match (inherited/dynamic behavior must be traced) |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | No direct match (inherited/dynamic behavior must be traced) |
| Forms / actions | No direct match (inherited/dynamic behavior must be traced) |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `login.php`

- Scope: public/API/background; risk: **High**; runtime: **UNKNOWN / UNTESTED**.
- Shell: No direct nav include; wrapper/redirect/dynamic includes below govern.
- Inline CSS: yes; inline JS: yes.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/login.php:15](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/login.php#L15) if (isLoggedIn()) { |
| Includes / render ownership | [public_html/login.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/login.php#L2) require_once __DIR__ . '/includes/auth.php'; |
| Entry / redirect | [public_html/login.php:16](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/login.php#L16) redirectByRole();<br>[public_html/login.php:96](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/login.php#L96) redirectByRole(); |
| Direct CSS / JS (also inherit nav/includes above) | [public_html/login.php:609](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/login.php#L609) &lt;script defer src="assets/js/security.js?v=3.4"&gt;&lt;/script&gt;<br>[public_html/login.php:776](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/login.php#L776) &lt;script src="assets/js/lang.js?v=&lt;?php echo $langAssetVersion; ?&gt;"&gt;&lt;/script&gt; |
| Forms / actions | [public_html/login.php:70](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/login.php#L70) if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {<br>[public_html/login.php:646](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/login.php#L646) &lt;form method="POST" autocomplete="on" class="login-form" id="loginForm" data-no-page-loader&gt; |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | [public_html/login.php:855](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/login.php#L855) window.addEventListener('pageshow', function (event) {<br>[public_html/login.php:869](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/login.php#L869) usernameInput.focus({ preventScroll: true }); |

### `logout.php`

- Scope: public/API/background; risk: **High**; runtime: **UNKNOWN / UNTESTED**.
- Shell: No direct nav include; wrapper/redirect/dynamic includes below govern.
- Inline CSS: yes; inline JS: no direct block.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/logout.php:23](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/logout.php#L23) if (!isLoggedIn()) { |
| Includes / render ownership | [public_html/logout.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/logout.php#L2) require_once __DIR__ . '/includes/auth.php'; |
| Entry / redirect | [public_html/logout.php:24](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/logout.php#L24) authRedirect('login.php'); |
| Direct CSS / JS (also inherit nav/includes above) | No direct match (inherited/dynamic behavior must be traced) |
| Forms / actions | [public_html/logout.php:18](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/logout.php#L18) if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {<br>[public_html/logout.php:54](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/logout.php#L54) &lt;form method="POST" style="margin:0"&gt; |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `purchase_activity.php`

- Scope: public/API/background; risk: **High**; runtime: **UNKNOWN / UNTESTED**.
- Shell: No direct nav include; wrapper/redirect/dynamic includes below govern.
- Inline CSS: no direct block; inline JS: no direct block.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/purchase_activity.php:20](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/purchase_activity.php#L20) if (!isLoggedIn() &#124;&#124; !authValidateCurrentSession(false)) {<br>[public_html/purchase_activity.php:26](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/purchase_activity.php#L26) if (!isAdmin() && !isReseller() && !isUser()) { |
| Includes / render ownership | [public_html/purchase_activity.php:5](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/purchase_activity.php#L5) require_once __DIR__ . '/includes/auth.php'; |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | No direct match (inherited/dynamic behavior must be traced) |
| Forms / actions | [public_html/purchase_activity.php:13](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/purchase_activity.php#L13) if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `redeem_binance_giftcard.php`

- Scope: public/API/background; risk: **Critical**; runtime: **UNKNOWN / UNTESTED**.
- Shell: No direct nav include; wrapper/redirect/dynamic includes below govern.
- Inline CSS: no direct block; inline JS: no direct block.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/redeem_binance_giftcard.php:7](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/redeem_binance_giftcard.php#L7) requireLogin(true);<br>[public_html/redeem_binance_giftcard.php:8](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/redeem_binance_giftcard.php#L8) requireActive();<br>[public_html/redeem_binance_giftcard.php:25](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/redeem_binance_giftcard.php#L25) if ($userId &lt; 1 &#124;&#124; (!isUser() && !isReseller())) { |
| Includes / render ownership | [public_html/redeem_binance_giftcard.php:4](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/redeem_binance_giftcard.php#L4) require_once __DIR__ . '/includes/auth.php';<br>[public_html/redeem_binance_giftcard.php:5](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/redeem_binance_giftcard.php#L5) require_once __DIR__ . '/includes/ranking.php';<br>[public_html/redeem_binance_giftcard.php:6](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/redeem_binance_giftcard.php#L6) require_once __DIR__ . '/includes/binance_giftcard.php'; |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | No direct match (inherited/dynamic behavior must be traced) |
| Forms / actions | [public_html/redeem_binance_giftcard.php:18](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/redeem_binance_giftcard.php#L18) if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `register.php`

- Scope: public/API/background; risk: **High**; runtime: **UNKNOWN / UNTESTED**.
- Shell: No direct nav include; wrapper/redirect/dynamic includes below govern.
- Inline CSS: yes; inline JS: yes.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/register.php:7](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/register.php#L7) if (isLoggedIn()) { |
| Includes / render ownership | [public_html/register.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/register.php#L2) require_once __DIR__ . '/includes/auth.php'; |
| Entry / redirect | [public_html/register.php:8](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/register.php#L8) redirectByRole();<br>[public_html/register.php:45](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/register.php#L45) redirectByRole();<br>[public_html/register.php:50](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/register.php#L50) header('Location: login.php?registered=1', true, 302); |
| Direct CSS / JS (also inherit nav/includes above) | [public_html/register.php:64](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/register.php#L64) &lt;link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3"&gt;<br>[public_html/register.php:65](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/register.php#L65) &lt;link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css"&gt;<br>[public_html/register.php:310](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/register.php#L310) &lt;script defer src="assets/js/security.js?v=3.4"&gt;&lt;/script&gt;<br>[public_html/register.php:484](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/register.php#L484) &lt;script src="assets/js/lang.js?v=&lt;?php echo $langAssetVersion; ?&gt;"&gt;&lt;/script&gt; |
| Forms / actions | [public_html/register.php:13](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/register.php#L13) if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {<br>[public_html/register.php:363](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/register.php#L363) &lt;form method="POST" autocomplete="on" class="space-y-3"&gt; |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `reseller/account.php`

- Scope: reseller; risk: **Medium**; runtime: **UNKNOWN / UNTESTED**.
- Shell: role nav bridge if rendered; actual request branch must be observed.
- Inline CSS: yes; inline JS: no direct block.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/reseller/account.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/account.php#L3) requireLogin();<br>[public_html/reseller/account.php:4](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/account.php#L4) requireActive();<br>[public_html/reseller/account.php:6](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/account.php#L6) if (!isReseller() && !isAdmin()) { |
| Includes / render ownership | [public_html/reseller/account.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/account.php#L2) require_once '../includes/auth.php';<br>[public_html/reseller/account.php:51](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/account.php#L51) &lt;?php include 'nav.php'; ?&gt; |
| Entry / redirect | [public_html/reseller/account.php:7](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/account.php#L7) header('Location: ../index.php'); |
| Direct CSS / JS (also inherit nav/includes above) | [public_html/reseller/account.php:43](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/account.php#L43) &lt;link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3"&gt;<br>[public_html/reseller/account.php:44](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/account.php#L44) &lt;link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css"&gt; |
| Forms / actions | [public_html/reseller/account.php:17](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/account.php#L17) if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {<br>[public_html/reseller/account.php:69](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/account.php#L69) &lt;form method="POST" class="space-y-4"&gt; |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `reseller/api_store.php`

- Scope: reseller; risk: **Medium**; runtime: **UNKNOWN / UNTESTED**.
- Shell: role nav bridge if rendered; actual request branch must be observed.
- Inline CSS: yes; inline JS: yes.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/reseller/api_store.php:21](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/api_store.php#L21) requireReseller(); |
| Includes / render ownership | [public_html/reseller/api_store.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/api_store.php#L2) require_once __DIR__ . '/../includes/auth.php';<br>[public_html/reseller/api_store.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/api_store.php#L3) require_once __DIR__ . '/../includes/store_bridge.php';<br>[public_html/reseller/api_store.php:305](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/api_store.php#L305) &lt;?php include 'nav.php'; ?&gt; |
| Entry / redirect | [public_html/reseller/api_store.php:41](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/api_store.php#L41) header('Location: api_store.php', true, 303); |
| Direct CSS / JS (also inherit nav/includes above) | [public_html/reseller/api_store.php:295](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/api_store.php#L295) &lt;link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3"&gt;<br>[public_html/reseller/api_store.php:296](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/api_store.php#L296) &lt;link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css"&gt; |
| Forms / actions | [public_html/reseller/api_store.php:13](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/api_store.php#L13) $storeApiMethod = strtoupper(trim((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')));<br>[public_html/reseller/api_store.php:134](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/api_store.php#L134) if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {<br>[public_html/reseller/api_store.php:137](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/api_store.php#L137) $action = isset($_POST['action']) && is_scalar($_POST['action']) ? trim((string) $_POST['action']) : '';<br>[public_html/reseller/api_store.php:341](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/api_store.php#L341) &lt;form method="post" class="mt-5 grid grid-cols-1 lg:grid-cols-2 gap-4"&gt;&lt;?php echo csrfField(); ?&gt;&lt;input type="hidden" name="action" value="create_api_key"&gt;<br>[public_html/reseller/api_store.php:354](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/api_store.php#L354) &lt;form method="post" class="mt-5"&gt;&lt;?php echo csrfField(); ?&gt;&lt;input type="hidden" name="action" value="save_api_ips"&gt;&lt;label class="block text-sm text-gray-300"&gt;&lt;?php echo $h($t('IP/CIDR ที่อนุญาต (เว้นว่าง = ไม่จำกัด IP)', 'Allowed IP/CIDR (b<br>[public_html/reseller/api_store.php:355](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/api_store.php#L355) &lt;div class="flex flex-wrap gap-2 mt-5"&gt;&lt;form method="post"&gt;&lt;?php echo csrfField(); ?&gt;&lt;input type="hidden" name="action" value="set_api_status"&gt;&lt;input type="hidden" name="status" value="&lt;?php echo $client['status'] === 'active' ? 'inactive' <br>[public_html/reseller/api_store.php:361](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/api_store.php#L361) &lt;form method="post" class="mt-5 grid grid-cols-1 lg:grid-cols-2 gap-4"&gt;&lt;?php echo csrfField(); ?&gt;&lt;input type="hidden" name="action" value="save_api_profile"&gt;<br>[public_html/reseller/api_store.php:376](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/api_store.php#L376) &lt;form method="post" class="shrink-0"&gt;&lt;?php echo csrfField(); ?&gt;&lt;input type="hidden" name="action" value="run_auto_test"&gt;&lt;button class="btn btn-primary" type="submit"&gt;&lt;i class="bi bi-play-circle"&gt;&lt;/i&gt;&lt;?php echo $h($t('เริ่มทดสอบ', 'Run test' |
| Dynamic endpoints | [public_html/reseller/api_store.php:282](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/api_store.php#L282) $endpoint = supplierBridgeCurrentEndpoint(); |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `reseller/api_store_download.php`

- Scope: reseller; risk: **Medium**; runtime: **UNKNOWN / UNTESTED**.
- Shell: No direct nav include; wrapper/redirect/dynamic includes below govern.
- Inline CSS: yes; inline JS: yes.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/reseller/api_store_download.php:5](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/api_store_download.php#L5) requireReseller(); |
| Includes / render ownership | [public_html/reseller/api_store_download.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/api_store_download.php#L2) require_once __DIR__ . '/../includes/auth.php';<br>[public_html/reseller/api_store_download.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/api_store_download.php#L3) require_once __DIR__ . '/../includes/store_bridge.php'; |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | No direct match (inherited/dynamic behavior must be traced) |
| Forms / actions | [public_html/reseller/api_store_download.php:347](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/api_store_download.php#L347) if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {<br>[public_html/reseller/api_store_download.php:418](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/api_store_download.php#L418) &lt;form method="post" autocomplete="off"&gt;<br>[public_html/reseller/api_store_download.php:433](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/api_store_download.php#L433) &lt;form method="post" action="?download=json"&gt;&lt;input type="hidden" name="report_json" value="&lt;?=h($reportJson)?&gt;"&gt;&lt;button type="submit"&gt;ดาวน์โหลด JSON / Download JSON&lt;/button&gt;&lt;/form&gt; |
| Dynamic endpoints | [public_html/reseller/api_store_download.php:24](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/api_store_download.php#L24) $endpoint = supplierBridgeCurrentEndpoint(); |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `reseller/buy.php`

- Scope: reseller; risk: **Critical**; runtime: **UNKNOWN / UNTESTED**.
- Shell: role nav bridge if rendered; actual request branch must be observed.
- Inline CSS: yes; inline JS: yes.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/reseller/buy.php:5](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/buy.php#L5) requireReseller(); |
| Includes / render ownership | [public_html/reseller/buy.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/buy.php#L2) require_once '../includes/auth.php';<br>[public_html/reseller/buy.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/buy.php#L3) require_once '../includes/cheatgame.php';<br>[public_html/reseller/buy.php:4](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/buy.php#L4) require_once '../includes/announcement_marquee.php';<br>[public_html/reseller/buy.php:522](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/buy.php#L522) &lt;?php include 'nav.php'; ?&gt;<br>[public_html/reseller/buy.php:567](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/buy.php#L567) include __DIR__ . '/../includes/purchase_activity.php'; |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | [public_html/reseller/buy.php:228](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/buy.php#L228) &lt;link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3"&gt;<br>[public_html/reseller/buy.php:229](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/buy.php#L229) &lt;link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css"&gt;<br>[public_html/reseller/buy.php:1699](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/buy.php#L1699) &lt;script src="../assets/js/announcement-marquee.js?v=&lt;?php echo (int) (@filemtime(__DIR__ . '/../assets/js/announcement-marquee.js') ?: 1); ?&gt;"&gt;&lt;/script&gt; |
| Forms / actions | [public_html/reseller/buy.php:125](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/buy.php#L125) if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['purchase_duration'])) {<br>[public_html/reseller/buy.php:576](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/buy.php#L576) &lt;form method="GET" action="" class="flex flex-col sm:flex-row gap-2"&gt;<br>[public_html/reseller/buy.php:907](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/buy.php#L907) &lt;form method="POST" id="quantityPurchaseForm" style="display:none;"&gt; |
| Dynamic endpoints | [public_html/reseller/buy.php:566](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/buy.php#L566) $purchaseActivityEndpoint = '../purchase_activity.php';<br>[public_html/reseller/buy.php:1371](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/buy.php#L1371) const response = await fetch('../cgo_order_status.php', {<br>[public_html/reseller/buy.php:1377](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/buy.php#L1377) 'X-Requested-With': 'XMLHttpRequest'<br>[public_html/reseller/buy.php:1550](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/buy.php#L1550) const response = await fetch('../cgo_inventory.php?action=token', {<br>[public_html/reseller/buy.php:1554](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/buy.php#L1554) headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }<br>[public_html/reseller/buy.php:1569](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/buy.php#L1569) const response = await fetch('../cgo_inventory.php', {<br>[public_html/reseller/buy.php:1577](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/buy.php#L1577) 'X-Requested-With': 'XMLHttpRequest', |
| State / interactions | [public_html/reseller/buy.php:827](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/buy.php#L827) &lt;div class="overlay" id="overlay" onclick="closeQuantityModal()" aria-hidden="true"&gt;&lt;/div&gt;<br>[public_html/reseller/buy.php:899](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/buy.php#L899) &lt;div id="purchaseProcessingOverlay" class="purchase-processing-overlay" role="status" aria-live="assertive" aria-hidden="true"&gt;<br>[public_html/reseller/buy.php:918](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/buy.php#L918) &lt;div class="overlay is-visible" id="successOverlay" style="display: flex;" aria-hidden="false"&gt;&lt;/div&gt;<br>[public_html/reseller/buy.php:1042](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/buy.php#L1042) (firstControl &#124;&#124; successModal).focus({ preventScroll: true });<br>[public_html/reseller/buy.php:1096](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/buy.php#L1096) window.addEventListener('pagehide', markPending);<br>[public_html/reseller/buy.php:1172](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/buy.php#L1172) if (quantityInput) quantityInput.focus({preventScroll: true});<br>[public_html/reseller/buy.php:1173](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/buy.php#L1173) else if (modal) modal.focus({preventScroll: true});<br>[public_html/reseller/buy.php:1191](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/buy.php#L1191) if (returnFocus instanceof HTMLElement && returnFocus.isConnected) returnFocus.focus({preventScroll: true});<br>[public_html/reseller/buy.php:1279](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/buy.php#L1279) if (focusable.length === 0) { event.preventDefault(); modal.focus(); return; }<br>[public_html/reseller/buy.php:1282](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/buy.php#L1282) if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }<br>[public_html/reseller/buy.php:1283](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/buy.php#L1283) else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }<br>[public_html/reseller/buy.php:1679](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/buy.php#L1679) window.addEventListener('pageshow', function (event) { |

### `reseller/dashboard.php`

- Scope: reseller; risk: **Medium**; runtime: **UNKNOWN / UNTESTED**.
- Shell: role nav bridge if rendered; actual request branch must be observed.
- Inline CSS: yes; inline JS: no direct block.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/reseller/dashboard.php:4](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/dashboard.php#L4) requireReseller(); |
| Includes / render ownership | [public_html/reseller/dashboard.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/dashboard.php#L2) require_once '../includes/auth.php';<br>[public_html/reseller/dashboard.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/dashboard.php#L3) require_once '../includes/cheatgame.php';<br>[public_html/reseller/dashboard.php:74](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/dashboard.php#L74) &lt;?php include 'nav.php'; ?&gt;<br>[public_html/reseller/dashboard.php:88](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/dashboard.php#L88) include __DIR__ . '/../includes/purchase_activity.php'; |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | [public_html/reseller/dashboard.php:25](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/dashboard.php#L25) &lt;link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3"&gt;<br>[public_html/reseller/dashboard.php:26](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/dashboard.php#L26) &lt;link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css"&gt; |
| Forms / actions | No direct match (inherited/dynamic behavior must be traced) |
| Dynamic endpoints | [public_html/reseller/dashboard.php:87](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/dashboard.php#L87) $purchaseActivityEndpoint = '../purchase_activity.php'; |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `reseller/deposit.php`

- Scope: reseller; risk: **Critical**; runtime: **UNKNOWN / UNTESTED**.
- Shell: No direct nav include; wrapper/redirect/dynamic includes below govern.
- Inline CSS: no direct block; inline JS: no direct block.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/reseller/deposit.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/deposit.php#L3) requireReseller(); |
| Includes / render ownership | [public_html/reseller/deposit.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/deposit.php#L2) require_once '../includes/auth.php';<br>[public_html/reseller/deposit.php:10](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/deposit.php#L10) require_once '../includes/deposit.php'; |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | No direct match (inherited/dynamic behavior must be traced) |
| Forms / actions | No direct match (inherited/dynamic behavior must be traced) |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `reseller/get_variants_reseller.php`

- Scope: reseller; risk: **Medium**; runtime: **UNKNOWN / UNTESTED**.
- Shell: No direct nav include; wrapper/redirect/dynamic includes below govern.
- Inline CSS: no direct block; inline JS: no direct block.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/reseller/get_variants_reseller.php:6](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/get_variants_reseller.php#L6) requireReseller(); |
| Includes / render ownership | [public_html/reseller/get_variants_reseller.php:4](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/get_variants_reseller.php#L4) require_once __DIR__ . '/../includes/auth.php';<br>[public_html/reseller/get_variants_reseller.php:5](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/get_variants_reseller.php#L5) require_once __DIR__ . '/../includes/cheatgame.php'; |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | No direct match (inherited/dynamic behavior must be traced) |
| Forms / actions | No direct match (inherited/dynamic behavior must be traced) |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `reseller/history.php`

- Scope: reseller; risk: **Medium**; runtime: **UNKNOWN / UNTESTED**.
- Shell: role nav bridge if rendered; actual request branch must be observed.
- Inline CSS: yes; inline JS: yes.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/reseller/history.php:4](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/history.php#L4) requireReseller(); |
| Includes / render ownership | [public_html/reseller/history.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/history.php#L2) require_once '../includes/auth.php';<br>[public_html/reseller/history.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/history.php#L3) require_once '../includes/cheatgame.php';<br>[public_html/reseller/history.php:69](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/history.php#L69) &lt;?php include 'nav.php'; ?&gt; |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | [public_html/reseller/history.php:47](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/history.php#L47) &lt;link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3"&gt;<br>[public_html/reseller/history.php:48](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/history.php#L48) &lt;link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css"&gt; |
| Forms / actions | [public_html/reseller/history.php:246](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/history.php#L246) &lt;form class="p-4" method="POST" action="history.php" role="search"&gt;<br>[public_html/reseller/history.php:463](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/history.php#L463) &lt;form method="post" action="history.php"&gt;<br>[public_html/reseller/history.php:475](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/history.php#L475) &lt;form method="post" action="history.php"&gt; |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | [public_html/reseller/history.php:199](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/history.php#L199) &lt;div class="text-white text-[15px]" id="modalProductName"&gt;&lt;/div&gt;<br>[public_html/reseller/history.php:203](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/history.php#L203) &lt;div class="text-white text-[15px]" id="modalDate"&gt;&lt;/div&gt;<br>[public_html/reseller/history.php:213](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/history.php#L213) &lt;div class="text-[#22c55e] font-bold text-[16px]" id="modalPrice"&gt;&lt;/div&gt;<br>[public_html/reseller/history.php:221](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/history.php#L221) &lt;a href="#" target="_blank" id="modalDownloadBtn"<br>[public_html/reseller/history.php:527](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/history.php#L527) (firstFocusable &#124;&#124; modal).focus({preventScroll: true});<br>[public_html/reseller/history.php:536](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/history.php#L536) window.historyModalReturnFocus.focus({preventScroll: true});<br>[public_html/reseller/history.php:563](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/history.php#L563) if (focusable.length === 0) { event.preventDefault(); modal.focus(); return; }<br>[public_html/reseller/history.php:566](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/history.php#L566) if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }<br>[public_html/reseller/history.php:567](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/history.php#L567) else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); } |

### `reseller/key_reset_action.php`

- Scope: reseller; risk: **Critical**; runtime: **UNKNOWN / UNTESTED**.
- Shell: No direct nav include; wrapper/redirect/dynamic includes below govern.
- Inline CSS: no direct block; inline JS: no direct block.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/reseller/key_reset_action.php:118](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/key_reset_action.php#L118) requireLogin();<br>[public_html/reseller/key_reset_action.php:121](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/key_reset_action.php#L121) if (!isReseller()) { |
| Includes / render ownership | [public_html/reseller/key_reset_action.php:94](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/key_reset_action.php#L94) require_once __DIR__ . '/../includes/auth.php';<br>[public_html/reseller/key_reset_action.php:95](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/key_reset_action.php#L95) require_once __DIR__ . '/../includes/key_reset.php'; |
| Entry / redirect | [public_html/reseller/key_reset_action.php:169](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/key_reset_action.php#L169) header('Location: key_resets.php', true, 303);<br>[public_html/reseller/key_reset_action.php:272](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/key_reset_action.php#L272) header('Location: key_resets.php', true, 303); |
| Direct CSS / JS (also inherit nav/includes above) | No direct match (inherited/dynamic behavior must be traced) |
| Forms / actions | [public_html/reseller/key_reset_action.php:129](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/key_reset_action.php#L129) if ($_SERVER['REQUEST_METHOD'] !== 'POST') {<br>[public_html/reseller/key_reset_action.php:174](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/key_reset_action.php#L174) $actionValue = $_POST['reset_action'] ?? $_POST['action'] ?? ''; |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `reseller/key_reset_status.php`

- Scope: reseller; risk: **Critical**; runtime: **UNKNOWN / UNTESTED**.
- Shell: No direct nav include; wrapper/redirect/dynamic includes below govern.
- Inline CSS: no direct block; inline JS: no direct block.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/reseller/key_reset_status.php:27](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/key_reset_status.php#L27) if (!isReseller()) { |
| Includes / render ownership | [public_html/reseller/key_reset_status.php:6](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/key_reset_status.php#L6) require_once __DIR__ . '/../includes/auth.php';<br>[public_html/reseller/key_reset_status.php:7](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/key_reset_status.php#L7) require_once __DIR__ . '/../includes/key_reset.php'; |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | No direct match (inherited/dynamic behavior must be traced) |
| Forms / actions | [public_html/reseller/key_reset_status.php:30](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/key_reset_status.php#L30) if ($_SERVER['REQUEST_METHOD'] !== 'GET') { |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `reseller/key_resets.php`

- Scope: reseller; risk: **Critical**; runtime: **UNKNOWN / UNTESTED**.
- Shell: role nav bridge if rendered; actual request branch must be observed.
- Inline CSS: yes; inline JS: yes.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/reseller/key_resets.php:5](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/key_resets.php#L5) requireLogin();<br>[public_html/reseller/key_resets.php:6](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/key_resets.php#L6) if (!isReseller()) authRedirect('index.php'); |
| Includes / render ownership | [public_html/reseller/key_resets.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/key_resets.php#L2) require_once __DIR__ . '/../includes/auth.php';<br>[public_html/reseller/key_resets.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/key_resets.php#L3) require_once __DIR__ . '/../includes/key_reset.php';<br>[public_html/reseller/key_resets.php:106](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/key_resets.php#L106) &lt;?php include __DIR__ . '/nav.php'; ?&gt; |
| Entry / redirect | [public_html/reseller/key_resets.php:6](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/key_resets.php#L6) if (!isReseller()) authRedirect('index.php'); |
| Direct CSS / JS (also inherit nav/includes above) | [public_html/reseller/key_resets.php:88](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/key_resets.php#L88) &lt;link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3"&gt;<br>[public_html/reseller/key_resets.php:89](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/key_resets.php#L89) &lt;link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css"&gt; |
| Forms / actions | [public_html/reseller/key_resets.php:189](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/key_resets.php#L189) &lt;form id="quickResetForm" method="post" action="key_reset_action.php" class="space-y-4" data-key-reset-ajax="1" data-no-page-loader="1"&gt; |
| Dynamic endpoints | [public_html/reseller/key_resets.php:287](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/key_resets.php#L287) const rawEndpoint = form.getAttribute('action') &#124;&#124; 'key_reset_action.php';<br>[public_html/reseller/key_resets.php:288](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/key_resets.php#L288) const endpoint = new URL(rawEndpoint, window.location.href);<br>[public_html/reseller/key_resets.php:295](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/key_resets.php#L295) const resetEndpoint = resolveResetEndpoint();<br>[public_html/reseller/key_resets.php:616](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/key_resets.php#L616) const response = await fetch(url, {...options, signal: controller.signal});<br>[public_html/reseller/key_resets.php:702](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/key_resets.php#L702) response = await fetch(resetEndpoint, { |
| State / interactions | [public_html/reseller/key_resets.php:750](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/key_resets.php#L750) keyInput.focus();<br>[public_html/reseller/key_resets.php:759](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/key_resets.php#L759) keyInput.focus();<br>[public_html/reseller/key_resets.php:761](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/key_resets.php#L761) keyInput.focus(); |

### `reseller/mykeys.php`

- Scope: reseller; risk: **Medium**; runtime: **UNKNOWN / UNTESTED**.
- Shell: role nav bridge if rendered; actual request branch must be observed.
- Inline CSS: yes; inline JS: yes.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/reseller/mykeys.php:5](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/mykeys.php#L5) requireLogin();<br>[public_html/reseller/mykeys.php:6](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/mykeys.php#L6) if (!isReseller()) { |
| Includes / render ownership | [public_html/reseller/mykeys.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/mykeys.php#L2) require_once __DIR__ . '/../includes/auth.php';<br>[public_html/reseller/mykeys.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/mykeys.php#L3) require_once __DIR__ . '/../includes/cheatgame.php';<br>[public_html/reseller/mykeys.php:51](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/mykeys.php#L51) &lt;?php include 'nav.php'; ?&gt; |
| Entry / redirect | [public_html/reseller/mykeys.php:7](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/mykeys.php#L7) authRedirect('index.php'); |
| Direct CSS / JS (also inherit nav/includes above) | [public_html/reseller/mykeys.php:33](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/mykeys.php#L33) &lt;link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3"&gt;<br>[public_html/reseller/mykeys.php:34](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/mykeys.php#L34) &lt;link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css"&gt; |
| Forms / actions | [public_html/reseller/mykeys.php:63](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/mykeys.php#L63) &lt;form method="post" class="flex gap-2" role="search"&gt;<br>[public_html/reseller/mykeys.php:172](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/mykeys.php#L172) &lt;form method="post"&gt;&lt;input type="hidden" name="q" value="&lt;?php echo htmlspecialchars($keySearch, ENT_QUOTES &#124; ENT_SUBSTITUTE, 'UTF-8'); ?&gt;"&gt;&lt;input type="hidden" name="page" value="&lt;?php echo $keyPage - 1; ?&gt;"&gt;&lt;button type="submit" class="ro<br>[public_html/reseller/mykeys.php:176](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/mykeys.php#L176) &lt;form method="post"&gt;&lt;input type="hidden" name="q" value="&lt;?php echo htmlspecialchars($keySearch, ENT_QUOTES &#124; ENT_SUBSTITUTE, 'UTF-8'); ?&gt;"&gt;&lt;input type="hidden" name="page" value="&lt;?php echo $keyPage + 1; ?&gt;"&gt;&lt;button type="submit" class="ro |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `reseller/nav.php`

- Scope: reseller; risk: **Medium**; runtime: **UNKNOWN / UNTESTED**.
- Shell: No direct nav include; wrapper/redirect/dynamic includes below govern.
- Inline CSS: yes; inline JS: yes.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/reseller/nav.php:5](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/nav.php#L5) requireReseller(); |
| Includes / render ownership | [public_html/reseller/nav.php:4](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/nav.php#L4) require_once __DIR__ . '/../includes/auth.php';<br>[public_html/reseller/nav.php:248](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/nav.php#L248) require_once __DIR__ . '/../includes/music_player.php';<br>[public_html/reseller/nav.php:249](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/nav.php#L249) echo renderMusicPlayer('reseller', '../'); |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | [public_html/reseller/nav.php:42](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/nav.php#L42) &lt;script src="../assets/js/shell-bridge.js?v=&lt;?php echo $shellBridgeVersion; ?&gt;"&gt;&lt;/script&gt;<br>[public_html/reseller/nav.php:43](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/nav.php#L43) &lt;script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"&gt;&lt;/script&gt;<br>[public_html/reseller/nav.php:201](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/nav.php#L201) &lt;script src="../assets/js/nav.js?v=&lt;?php echo $navAssetVersion; ?&gt;"&gt;&lt;/script&gt;<br>[public_html/reseller/nav.php:204](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/nav.php#L204) &lt;script src="../assets/js/security.js?v=&lt;?php echo $securityAssetVersion; ?&gt;"&gt;&lt;/script&gt;<br>[public_html/reseller/nav.php:213](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/nav.php#L213) &lt;script src="../assets/js/lang.js?v=&lt;?php echo $langAssetVersion; ?&gt;"&gt;&lt;/script&gt; |
| Forms / actions | [public_html/reseller/nav.php:39](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/nav.php#L39) $shellBridgeEligible = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET');<br>[public_html/reseller/nav.php:141](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/nav.php#L141) &lt;form method="POST" action="../logout.php" class="hidden 2xl:inline-flex m-0 shrink-0"&gt;<br>[public_html/reseller/nav.php:194](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/nav.php#L194) &lt;form method="POST" action="../logout.php" class="m-0"&gt; |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | [public_html/reseller/nav.php:154](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/nav.php#L154) &lt;div id="drawerOverlay" class="drawer-overlay" aria-hidden="true"&gt;&lt;/div&gt;<br>[public_html/reseller/nav.php:155](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/nav.php#L155) &lt;aside id="drawer" class="drawer glass border-r border-white/10 flex flex-col" aria-hidden="true" aria-label="Reseller navigation"&gt; |

### `reseller/rankings.php`

- Scope: reseller; risk: **Medium**; runtime: **UNKNOWN / UNTESTED**.
- Shell: role nav bridge if rendered; actual request branch must be observed.
- Inline CSS: yes; inline JS: no direct block.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/reseller/rankings.php:4](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/rankings.php#L4) requireReseller(); |
| Includes / render ownership | [public_html/reseller/rankings.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/rankings.php#L2) require_once '../includes/auth.php';<br>[public_html/reseller/rankings.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/rankings.php#L3) require_once '../includes/ranking.php';<br>[public_html/reseller/rankings.php:20](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/rankings.php#L20) &lt;?php include 'nav.php'; ?&gt; |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | [public_html/reseller/rankings.php:15](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/rankings.php#L15) &lt;link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3"&gt;<br>[public_html/reseller/rankings.php:16](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/rankings.php#L16) &lt;link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css"&gt; |
| Forms / actions | No direct match (inherited/dynamic behavior must be traced) |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `reseller/reset_hwid.php`

- Scope: reseller; risk: **Critical**; runtime: **UNKNOWN / UNTESTED**.
- Shell: No direct nav include; wrapper/redirect/dynamic includes below govern.
- Inline CSS: no direct block; inline JS: no direct block.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/reseller/reset_hwid.php:9](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/reset_hwid.php#L9) requireLogin();<br>[public_html/reseller/reset_hwid.php:10](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/reset_hwid.php#L10) if (!isReseller()) { |
| Includes / render ownership | [public_html/reseller/reset_hwid.php:6](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/reset_hwid.php#L6) require_once __DIR__ . '/../includes/auth.php';<br>[public_html/reseller/reset_hwid.php:7](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/reset_hwid.php#L7) require_once __DIR__ . '/../includes/key_reset.php'; |
| Entry / redirect | [public_html/reseller/reset_hwid.php:15](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/reset_hwid.php#L15) header('Location: key_resets.php', true, 303);<br>[public_html/reseller/reset_hwid.php:41](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/reset_hwid.php#L41) header('Location: key_resets.php', true, 303); |
| Direct CSS / JS (also inherit nav/includes above) | No direct match (inherited/dynamic behavior must be traced) |
| Forms / actions | [public_html/reseller/reset_hwid.php:14](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/reset_hwid.php#L14) if ($_SERVER['REQUEST_METHOD'] !== 'POST') {<br>[public_html/reseller/reset_hwid.php:21](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reseller/reset_hwid.php#L21) $action = isset($_POST['action']) && is_string($_POST['action']) ? trim($_POST['action']) : ''; |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `reset_password.php`

- Scope: public/API/background; risk: **Critical**; runtime: **UNKNOWN / UNTESTED**.
- Shell: No direct nav include; wrapper/redirect/dynamic includes below govern.
- Inline CSS: yes; inline JS: yes.

| Contract | Source evidence |
|---|---|
| Roles / guards | No direct match (inherited/dynamic behavior must be traced) |
| Includes / render ownership | [public_html/reset_password.php:5](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reset_password.php#L5) require_once __DIR__ . '/includes/auth.php';<br>[public_html/reset_password.php:6](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reset_password.php#L6) require_once __DIR__ . '/includes/account_recovery.php'; |
| Entry / redirect | [public_html/reset_password.php:22](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reset_password.php#L22) header('Location: login.php?reset=1', true, 302); |
| Direct CSS / JS (also inherit nav/includes above) | [public_html/reset_password.php:36](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reset_password.php#L36) &lt;link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3"&gt;<br>[public_html/reset_password.php:37](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reset_password.php#L37) &lt;link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css"&gt;<br>[public_html/reset_password.php:39](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reset_password.php#L39) &lt;script defer src="assets/js/security.js?v=3.4"&gt;&lt;/script&gt; |
| Forms / actions | [public_html/reset_password.php:16](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reset_password.php#L16) if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {<br>[public_html/reset_password.php:57](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/reset_password.php#L57) &lt;form method="post" class="space-y-4"&gt; |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `slip_maintenance.php`

- Scope: public/API/background; risk: **High**; runtime: **UNKNOWN / UNTESTED**.
- Shell: No direct nav include; wrapper/redirect/dynamic includes below govern.
- Inline CSS: no direct block; inline JS: no direct block.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/slip_maintenance.php:19](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/slip_maintenance.php#L19) requireLogin();<br>[public_html/slip_maintenance.php:20](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/slip_maintenance.php#L20) requireActive(); |
| Includes / render ownership | [public_html/slip_maintenance.php:10](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/slip_maintenance.php#L10) require_once __DIR__ . '/includes/auth.php';<br>[public_html/slip_maintenance.php:11](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/slip_maintenance.php#L11) require_once __DIR__ . '/includes/store_bridge.php';<br>[public_html/slip_maintenance.php:12](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/slip_maintenance.php#L12) require_once __DIR__ . '/includes/automation.php'; |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | No direct match (inherited/dynamic behavior must be traced) |
| Forms / actions | [public_html/slip_maintenance.php:21](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/slip_maintenance.php#L21) if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `toggle_lang.php`

- Scope: public/API/background; risk: **High**; runtime: **UNKNOWN / UNTESTED**.
- Shell: No direct nav include; wrapper/redirect/dynamic includes below govern.
- Inline CSS: no direct block; inline JS: no direct block.

| Contract | Source evidence |
|---|---|
| Roles / guards | No direct match (inherited/dynamic behavior must be traced) |
| Includes / render ownership | [public_html/toggle_lang.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/toggle_lang.php#L2) require_once __DIR__ . '/includes/auth.php'; |
| Entry / redirect | [public_html/toggle_lang.php:41](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/toggle_lang.php#L41) header('Location: ' . ($redirectTo !== '' ? $redirectTo : $fallback), true, 302); |
| Direct CSS / JS (also inherit nav/includes above) | No direct match (inherited/dynamic behavior must be traced) |
| Forms / actions | No direct match (inherited/dynamic behavior must be traced) |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `user/account.php`

- Scope: user; risk: **Medium**; runtime: **UNKNOWN / UNTESTED**.
- Shell: role nav bridge if rendered; actual request branch must be observed.
- Inline CSS: yes; inline JS: no direct block.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/user/account.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/account.php#L3) requireLogin();<br>[public_html/user/account.php:4](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/account.php#L4) requireActive();<br>[public_html/user/account.php:6](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/account.php#L6) if (!isUser() && !isAdmin()) { |
| Includes / render ownership | [public_html/user/account.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/account.php#L2) require_once '../includes/auth.php';<br>[public_html/user/account.php:51](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/account.php#L51) &lt;?php include 'nav.php'; ?&gt; |
| Entry / redirect | [public_html/user/account.php:7](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/account.php#L7) header('Location: ../index.php'); |
| Direct CSS / JS (also inherit nav/includes above) | [public_html/user/account.php:43](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/account.php#L43) &lt;link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3"&gt;<br>[public_html/user/account.php:44](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/account.php#L44) &lt;link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css"&gt; |
| Forms / actions | [public_html/user/account.php:17](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/account.php#L17) if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {<br>[public_html/user/account.php:69](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/account.php#L69) &lt;form method="POST" class="space-y-4"&gt; |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `user/api_store.php`

- Scope: user; risk: **Medium**; runtime: **UNKNOWN / UNTESTED**.
- Shell: No direct nav include; wrapper/redirect/dynamic includes below govern.
- Inline CSS: no direct block; inline JS: no direct block.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/user/api_store.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/api_store.php#L3) requireLogin(); |
| Includes / render ownership | [public_html/user/api_store.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/api_store.php#L2) require_once __DIR__ . '/../includes/auth.php'; |
| Entry / redirect | [public_html/user/api_store.php:4](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/api_store.php#L4) header('Location: buy.php', true, 302); |
| Direct CSS / JS (also inherit nav/includes above) | No direct match (inherited/dynamic behavior must be traced) |
| Forms / actions | No direct match (inherited/dynamic behavior must be traced) |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `user/buy.php`

- Scope: user; risk: **Critical**; runtime: **UNKNOWN / UNTESTED**.
- Shell: role nav bridge if rendered; actual request branch must be observed.
- Inline CSS: yes; inline JS: yes.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/user/buy.php:6](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/buy.php#L6) requireLogin();<br>[public_html/user/buy.php:11](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/buy.php#L11) if (!isUser() && !isAdmin() && !isReseller()) { |
| Includes / render ownership | [public_html/user/buy.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/buy.php#L2) require_once '../includes/auth.php';<br>[public_html/user/buy.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/buy.php#L3) require_once '../includes/db.php';<br>[public_html/user/buy.php:4](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/buy.php#L4) require_once '../includes/cheatgame.php';<br>[public_html/user/buy.php:5](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/buy.php#L5) require_once '../includes/announcement_marquee.php';<br>[public_html/user/buy.php:536](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/buy.php#L536) &lt;?php include 'nav.php'; ?&gt;<br>[public_html/user/buy.php:587](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/buy.php#L587) include __DIR__ . '/../includes/purchase_activity.php'; |
| Entry / redirect | [public_html/user/buy.php:12](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/buy.php#L12) header('Location: ../index.php'); |
| Direct CSS / JS (also inherit nav/includes above) | [public_html/user/buy.php:236](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/buy.php#L236) &lt;link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3"&gt;<br>[public_html/user/buy.php:237](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/buy.php#L237) &lt;link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css"&gt;<br>[public_html/user/buy.php:1748](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/buy.php#L1748) &lt;script src="../assets/js/announcement-marquee.js?v=&lt;?php echo (int) (@filemtime(__DIR__ . '/../assets/js/announcement-marquee.js') ?: 1); ?&gt;"&gt;&lt;/script&gt; |
| Forms / actions | [public_html/user/buy.php:133](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/buy.php#L133) if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['purchase_duration'])) {<br>[public_html/user/buy.php:596](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/buy.php#L596) &lt;form method="GET" action="" class="flex flex-col sm:flex-row gap-2"&gt;<br>[public_html/user/buy.php:951](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/buy.php#L951) &lt;form method="POST" id="quantityPurchaseForm" style="display:none;"&gt; |
| Dynamic endpoints | [public_html/user/buy.php:586](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/buy.php#L586) $purchaseActivityEndpoint = '../purchase_activity.php';<br>[public_html/user/buy.php:1416](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/buy.php#L1416) const response = await fetch('../cgo_order_status.php', {<br>[public_html/user/buy.php:1422](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/buy.php#L1422) 'X-Requested-With': 'XMLHttpRequest'<br>[public_html/user/buy.php:1595](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/buy.php#L1595) const response = await fetch('../cgo_inventory.php?action=token', {<br>[public_html/user/buy.php:1599](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/buy.php#L1599) headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }<br>[public_html/user/buy.php:1614](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/buy.php#L1614) const response = await fetch('../cgo_inventory.php', {<br>[public_html/user/buy.php:1622](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/buy.php#L1622) 'X-Requested-With': 'XMLHttpRequest', |
| State / interactions | [public_html/user/buy.php:872](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/buy.php#L872) &lt;div class="overlay" id="overlay" onclick="closeQuantityModal()" style="display: none;" aria-hidden="true"&gt;&lt;/div&gt;<br>[public_html/user/buy.php:943](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/buy.php#L943) &lt;div id="purchaseProcessingOverlay" class="purchase-processing-overlay" role="status" aria-live="assertive" aria-hidden="true"&gt;<br>[public_html/user/buy.php:962](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/buy.php#L962) &lt;div class="overlay is-visible" id="successOverlay" style="display: flex;" aria-hidden="false"&gt;&lt;/div&gt;<br>[public_html/user/buy.php:1086](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/buy.php#L1086) (firstControl &#124;&#124; successModal).focus({ preventScroll: true });<br>[public_html/user/buy.php:1140](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/buy.php#L1140) window.addEventListener('pagehide', markPending);<br>[public_html/user/buy.php:1216](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/buy.php#L1216) if (quantityInput) quantityInput.focus({preventScroll: true});<br>[public_html/user/buy.php:1217](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/buy.php#L1217) else if (modal) modal.focus({preventScroll: true});<br>[public_html/user/buy.php:1235](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/buy.php#L1235) if (returnFocus instanceof HTMLElement && returnFocus.isConnected) returnFocus.focus({preventScroll: true});<br>[public_html/user/buy.php:1327](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/buy.php#L1327) if (focusable.length === 0) { event.preventDefault(); modal.focus(); return; }<br>[public_html/user/buy.php:1330](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/buy.php#L1330) if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }<br>[public_html/user/buy.php:1331](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/buy.php#L1331) else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }<br>[public_html/user/buy.php:1729](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/buy.php#L1729) window.addEventListener('pageshow', function (event) { |

### `user/dashboard.php`

- Scope: user; risk: **Medium**; runtime: **UNKNOWN / UNTESTED**.
- Shell: role nav bridge if rendered; actual request branch must be observed.
- Inline CSS: yes; inline JS: no direct block.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/user/dashboard.php:5](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/dashboard.php#L5) requireLogin();<br>[public_html/user/dashboard.php:7](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/dashboard.php#L7) if (!isUser()) { |
| Includes / render ownership | [public_html/user/dashboard.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/dashboard.php#L2) require_once '../includes/auth.php';<br>[public_html/user/dashboard.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/dashboard.php#L3) require_once '../includes/cheatgame.php';<br>[public_html/user/dashboard.php:4](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/dashboard.php#L4) require_once '../includes/ranking.php';<br>[public_html/user/dashboard.php:81](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/dashboard.php#L81) &lt;?php include 'nav.php'; ?&gt;<br>[public_html/user/dashboard.php:101](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/dashboard.php#L101) include __DIR__ . '/../includes/purchase_activity.php'; |
| Entry / redirect | [public_html/user/dashboard.php:8](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/dashboard.php#L8) header('Location: ../index.php'); |
| Direct CSS / JS (also inherit nav/includes above) | [public_html/user/dashboard.php:32](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/dashboard.php#L32) &lt;link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3"&gt;<br>[public_html/user/dashboard.php:33](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/dashboard.php#L33) &lt;link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css"&gt; |
| Forms / actions | No direct match (inherited/dynamic behavior must be traced) |
| Dynamic endpoints | [public_html/user/dashboard.php:100](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/dashboard.php#L100) $purchaseActivityEndpoint = '../purchase_activity.php'; |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `user/dashboard_dynamic.php`

- Scope: user; risk: **Medium**; runtime: **UNKNOWN / UNTESTED**.
- Shell: No direct nav include; wrapper/redirect/dynamic includes below govern.
- Inline CSS: no direct block; inline JS: no direct block.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/user/dashboard_dynamic.php:5](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/dashboard_dynamic.php#L5) requireLogin();<br>[public_html/user/dashboard_dynamic.php:7](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/dashboard_dynamic.php#L7) if (!isUser()) { |
| Includes / render ownership | [public_html/user/dashboard_dynamic.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/dashboard_dynamic.php#L2) require_once '../includes/auth.php';<br>[public_html/user/dashboard_dynamic.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/dashboard_dynamic.php#L3) require_once '../includes/cheatgame.php';<br>[public_html/user/dashboard_dynamic.php:4](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/dashboard_dynamic.php#L4) require_once '../includes/ranking.php';<br>[public_html/user/dashboard_dynamic.php:40](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/dashboard_dynamic.php#L40) include __DIR__ . '/dashboard_dynamic_content.php'; |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | No direct match (inherited/dynamic behavior must be traced) |
| Forms / actions | No direct match (inherited/dynamic behavior must be traced) |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `user/dashboard_dynamic_content.php`

- Scope: user; risk: **Medium**; runtime: **UNKNOWN / UNTESTED**.
- Shell: No direct nav include; wrapper/redirect/dynamic includes below govern.
- Inline CSS: no direct block; inline JS: no direct block.

| Contract | Source evidence |
|---|---|
| Roles / guards | No direct match (inherited/dynamic behavior must be traced) |
| Includes / render ownership | [public_html/user/dashboard_dynamic_content.php:7](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/dashboard_dynamic_content.php#L7) include __DIR__ . '/../includes/purchase_activity.php'; |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | No direct match (inherited/dynamic behavior must be traced) |
| Forms / actions | No direct match (inherited/dynamic behavior must be traced) |
| Dynamic endpoints | [public_html/user/dashboard_dynamic_content.php:6](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/dashboard_dynamic_content.php#L6) $purchaseActivityEndpoint = '../purchase_activity.php'; |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `user/deposit.php`

- Scope: user; risk: **Critical**; runtime: **UNKNOWN / UNTESTED**.
- Shell: No direct nav include; wrapper/redirect/dynamic includes below govern.
- Inline CSS: no direct block; inline JS: no direct block.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/user/deposit.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/deposit.php#L3) requireLogin();<br>[public_html/user/deposit.php:5](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/deposit.php#L5) if (!isUser()) { |
| Includes / render ownership | [public_html/user/deposit.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/deposit.php#L2) require_once '../includes/auth.php';<br>[public_html/user/deposit.php:15](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/deposit.php#L15) require_once '../includes/deposit.php'; |
| Entry / redirect | [public_html/user/deposit.php:6](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/deposit.php#L6) header('Location: ../index.php'); |
| Direct CSS / JS (also inherit nav/includes above) | No direct match (inherited/dynamic behavior must be traced) |
| Forms / actions | No direct match (inherited/dynamic behavior must be traced) |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `user/get_variants.php`

- Scope: user; risk: **Medium**; runtime: **UNKNOWN / UNTESTED**.
- Shell: No direct nav include; wrapper/redirect/dynamic includes below govern.
- Inline CSS: no direct block; inline JS: no direct block.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/user/get_variants.php:6](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/get_variants.php#L6) requireLogin(); |
| Includes / render ownership | [public_html/user/get_variants.php:4](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/get_variants.php#L4) require_once __DIR__ . '/../includes/auth.php';<br>[public_html/user/get_variants.php:5](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/get_variants.php#L5) require_once __DIR__ . '/../includes/cheatgame.php'; |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | No direct match (inherited/dynamic behavior must be traced) |
| Forms / actions | No direct match (inherited/dynamic behavior must be traced) |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `user/history.php`

- Scope: user; risk: **Medium**; runtime: **UNKNOWN / UNTESTED**.
- Shell: role nav bridge if rendered; actual request branch must be observed.
- Inline CSS: yes; inline JS: yes.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/user/history.php:4](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/history.php#L4) requireLogin();<br>[public_html/user/history.php:6](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/history.php#L6) if (!isUser()) { |
| Includes / render ownership | [public_html/user/history.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/history.php#L2) require_once '../includes/auth.php';<br>[public_html/user/history.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/history.php#L3) require_once '../includes/cheatgame.php';<br>[public_html/user/history.php:74](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/history.php#L74) &lt;?php include 'nav.php'; ?&gt; |
| Entry / redirect | [public_html/user/history.php:7](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/history.php#L7) header('Location: ../index.php'); |
| Direct CSS / JS (also inherit nav/includes above) | [public_html/user/history.php:52](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/history.php#L52) &lt;link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3"&gt;<br>[public_html/user/history.php:53](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/history.php#L53) &lt;link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css"&gt; |
| Forms / actions | [public_html/user/history.php:243](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/history.php#L243) &lt;form class="p-4" method="POST" action="history.php" role="search"&gt;<br>[public_html/user/history.php:460](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/history.php#L460) &lt;form method="post" action="history.php"&gt;<br>[public_html/user/history.php:472](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/history.php#L472) &lt;form method="post" action="history.php"&gt; |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | [public_html/user/history.php:190](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/history.php#L190) &lt;div class="fixed inset-0 bg-black/80 backdrop-blur-sm z-[1000] hidden items-center justify-center p-4" id="detailModalOverlay" aria-hidden="true"&gt;<br>[public_html/user/history.php:191](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/history.php#L191) &lt;div class="bg-[#1b2336] rounded-xl overflow-hidden shadow-2xl w-full max-w-[340px] transform transition-all" id="detailModal" role="dialog" aria-modal="true" tabindex="-1"&gt;<br>[public_html/user/history.php:201](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/history.php#L201) &lt;div class="text-white text-[15px]" id="modalProductName"&gt;&lt;/div&gt;<br>[public_html/user/history.php:205](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/history.php#L205) &lt;div class="text-white text-[15px]" id="modalDate"&gt;&lt;/div&gt;<br>[public_html/user/history.php:209](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/history.php#L209) &lt;div class="bg-[#121827] border border-white/5 rounded-lg p-3 text-gray-300 font-mono text-[14px] break-all max-h-32 overflow-y-auto" id="modalKeys"&gt;<br>[public_html/user/history.php:214](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/history.php#L214) &lt;div class="text-[#22c55e] font-bold text-[16px]" id="modalPrice"&gt;&lt;/div&gt;<br>[public_html/user/history.php:217](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/history.php#L217) &lt;button type="button" onclick="copyModalKeys()" class="w-full bg-[#22c55e] hover:bg-green-600 text-white rounded-lg py-2.5 text-[14px] font-medium flex items-center justify-center gap-2 transition" id="modalCopyBtn"&gt;<br>[public_html/user/history.php:220](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/history.php#L220) &lt;a href="#" target="_blank" id="modalDownloadBtn" class="w-full bg-gradient-to-r from-[#ef4444] to-[#f97316] hover:opacity-90 text-white rounded-lg py-2.5 text-[14px] font-medium flex items-center justify-center gap-2 transition hidden"&gt;<br>[public_html/user/history.php:524](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/history.php#L524) (firstFocusable &#124;&#124; modal).focus({preventScroll: true});<br>[public_html/user/history.php:533](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/history.php#L533) window.historyModalReturnFocus.focus({preventScroll: true});<br>[public_html/user/history.php:576](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/history.php#L576) if (focusable.length === 0) { event.preventDefault(); modal.focus(); return; }<br>[public_html/user/history.php:579](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/history.php#L579) if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }<br>[public_html/user/history.php:580](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/history.php#L580) else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); } |

### `user/mykeys.php`

- Scope: user; risk: **Medium**; runtime: **UNKNOWN / UNTESTED**.
- Shell: role nav bridge if rendered; actual request branch must be observed.
- Inline CSS: yes; inline JS: yes.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/user/mykeys.php:4](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/mykeys.php#L4) requireLogin();<br>[public_html/user/mykeys.php:6](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/mykeys.php#L6) if (!isUser()) { |
| Includes / render ownership | [public_html/user/mykeys.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/mykeys.php#L2) require_once '../includes/auth.php';<br>[public_html/user/mykeys.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/mykeys.php#L3) require_once '../includes/cheatgame.php';<br>[public_html/user/mykeys.php:60](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/mykeys.php#L60) &lt;?php include 'nav.php'; ?&gt; |
| Entry / redirect | [public_html/user/mykeys.php:7](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/mykeys.php#L7) header('Location: ../index.php'); |
| Direct CSS / JS (also inherit nav/includes above) | [public_html/user/mykeys.php:34](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/mykeys.php#L34) &lt;link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3"&gt;<br>[public_html/user/mykeys.php:35](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/mykeys.php#L35) &lt;link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css"&gt; |
| Forms / actions | [public_html/user/mykeys.php:67](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/mykeys.php#L67) &lt;form method="post" class="flex w-full gap-2 sm:w-auto" role="search"&gt;<br>[public_html/user/mykeys.php:165](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/mykeys.php#L165) &lt;form method="post"&gt;&lt;input type="hidden" name="q" value="&lt;?php echo htmlspecialchars($keySearch, ENT_QUOTES &#124; ENT_SUBSTITUTE, 'UTF-8'); ?&gt;"&gt;&lt;input type="hidden" name="page" value="&lt;?php echo $keyPage - 1; ?&gt;"&gt;&lt;button type="submit" class="ro<br>[public_html/user/mykeys.php:169](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/mykeys.php#L169) &lt;form method="post"&gt;&lt;input type="hidden" name="q" value="&lt;?php echo htmlspecialchars($keySearch, ENT_QUOTES &#124; ENT_SUBSTITUTE, 'UTF-8'); ?&gt;"&gt;&lt;input type="hidden" name="page" value="&lt;?php echo $keyPage + 1; ?&gt;"&gt;&lt;button type="submit" class="ro |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `user/nav.php`

- Scope: user; risk: **Medium**; runtime: **UNKNOWN / UNTESTED**.
- Shell: No direct nav include; wrapper/redirect/dynamic includes below govern.
- Inline CSS: yes; inline JS: yes.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/user/nav.php:5](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/nav.php#L5) requireLogin(); |
| Includes / render ownership | [public_html/user/nav.php:4](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/nav.php#L4) require_once __DIR__ . '/../includes/auth.php';<br>[public_html/user/nav.php:256](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/nav.php#L256) require_once __DIR__ . '/../includes/music_player.php';<br>[public_html/user/nav.php:257](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/nav.php#L257) echo renderMusicPlayer('user', '../'); |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | [public_html/user/nav.php:34](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/nav.php#L34) &lt;script src="../assets/js/shell-bridge.js?v=&lt;?php echo $shellBridgeVersion; ?&gt;"&gt;&lt;/script&gt;<br>[public_html/user/nav.php:35](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/nav.php#L35) &lt;script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"&gt;&lt;/script&gt;<br>[public_html/user/nav.php:209](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/nav.php#L209) &lt;script src="../assets/js/nav.js?v=&lt;?php echo $navAssetVersion; ?&gt;"&gt;&lt;/script&gt;<br>[public_html/user/nav.php:212](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/nav.php#L212) &lt;script src="../assets/js/security.js?v=&lt;?php echo $securityAssetVersion; ?&gt;"&gt;&lt;/script&gt;<br>[public_html/user/nav.php:221](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/nav.php#L221) &lt;script src="../assets/js/lang.js?v=&lt;?php echo $langAssetVersion; ?&gt;"&gt;&lt;/script&gt; |
| Forms / actions | [public_html/user/nav.php:31](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/nav.php#L31) $shellBridgeEligible = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET');<br>[public_html/user/nav.php:150](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/nav.php#L150) &lt;form method="POST" action="../logout.php" class="hidden 2xl:inline-flex m-0 shrink-0"&gt;<br>[public_html/user/nav.php:202](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/nav.php#L202) &lt;form method="POST" action="../logout.php" class="m-0"&gt; |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | [public_html/user/nav.php:162](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/nav.php#L162) &lt;div id="drawerOverlay" class="drawer-overlay" aria-hidden="true"&gt;&lt;/div&gt;<br>[public_html/user/nav.php:164](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/nav.php#L164) &lt;aside id="drawer" class="drawer glass border-r border-white/10 flex flex-col" aria-hidden="true" aria-label="User navigation"&gt; |

### `user/rankings.php`

- Scope: user; risk: **Medium**; runtime: **UNKNOWN / UNTESTED**.
- Shell: role nav bridge if rendered; actual request branch must be observed.
- Inline CSS: yes; inline JS: no direct block.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/user/rankings.php:4](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/rankings.php#L4) requireLogin();<br>[public_html/user/rankings.php:5](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/rankings.php#L5) if (!isUser()) { |
| Includes / render ownership | [public_html/user/rankings.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/rankings.php#L2) require_once '../includes/auth.php';<br>[public_html/user/rankings.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/rankings.php#L3) require_once '../includes/ranking.php';<br>[public_html/user/rankings.php:53](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/rankings.php#L53) &lt;?php include 'nav.php'; ?&gt; |
| Entry / redirect | [public_html/user/rankings.php:6](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/rankings.php#L6) header('Location: ../index.php'); |
| Direct CSS / JS (also inherit nav/includes above) | [public_html/user/rankings.php:43](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/rankings.php#L43) &lt;link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3"&gt;<br>[public_html/user/rankings.php:44](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/rankings.php#L44) &lt;link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css"&gt; |
| Forms / actions | No direct match (inherited/dynamic behavior must be traced) |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `user/redeem_angpao.php`

- Scope: user; risk: **Critical**; runtime: **UNKNOWN / UNTESTED**.
- Shell: No direct nav include; wrapper/redirect/dynamic includes below govern.
- Inline CSS: no direct block; inline JS: no direct block.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/user/redeem_angpao.php:9](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/redeem_angpao.php#L9) requireLogin(true);<br>[public_html/user/redeem_angpao.php:10](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/redeem_angpao.php#L10) requireActive(); |
| Includes / render ownership | [public_html/user/redeem_angpao.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/redeem_angpao.php#L3) require_once '../includes/auth.php';<br>[public_html/user/redeem_angpao.php:4](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/redeem_angpao.php#L4) require_once '../includes/ranking.php';<br>[public_html/user/redeem_angpao.php:5](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/redeem_angpao.php#L5) require_once '../includes/truemoney.php';<br>[public_html/user/redeem_angpao.php:6](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/redeem_angpao.php#L6) require_once '../includes/truemoney_byteindev.php';<br>[public_html/user/redeem_angpao.php:7](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/redeem_angpao.php#L7) require_once '../includes/truemoney_byteindev_route.php'; |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | No direct match (inherited/dynamic behavior must be traced) |
| Forms / actions | [public_html/user/redeem_angpao.php:13](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/redeem_angpao.php#L13) if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `user/reseller_program.php`

- Scope: user; risk: **Medium**; runtime: **UNKNOWN / UNTESTED**.
- Shell: role nav bridge if rendered; actual request branch must be observed.
- Inline CSS: yes; inline JS: yes.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/user/reseller_program.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/reseller_program.php#L3) requireLogin();<br>[public_html/user/reseller_program.php:7](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/reseller_program.php#L7) if (!isUser()) { |
| Includes / render ownership | [public_html/user/reseller_program.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/reseller_program.php#L2) require_once '../includes/auth.php';<br>[public_html/user/reseller_program.php:353](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/reseller_program.php#L353) &lt;?php include 'nav.php'; ?&gt; |
| Entry / redirect | [public_html/user/reseller_program.php:8](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/reseller_program.php#L8) authRedirect('index.php'); |
| Direct CSS / JS (also inherit nav/includes above) | [public_html/user/reseller_program.php:53](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/reseller_program.php#L53) &lt;link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@400;500;600;700&display=swap" rel="stylesheet"&gt;<br>[public_html/user/reseller_program.php:54](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/reseller_program.php#L54) &lt;link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3"&gt;<br>[public_html/user/reseller_program.php:55](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/reseller_program.php#L55) &lt;link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css"&gt; |
| Forms / actions | No direct match (inherited/dynamic behavior must be traced) |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | [public_html/user/reseller_program.php:556](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/reseller_program.php#L556) &lt;div class="rp-modal" id="resellerContactModal" aria-hidden="true"&gt;<br>[public_html/user/reseller_program.php:561](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/reseller_program.php#L561) &lt;h3 id="resellerModalTitle"&gt;&lt;?php echo htmlspecialchars($tr('สมัครตัวแทนผ่าน Telegram', 'Apply through Telegram'), ENT_QUOTES, 'UTF-8'); ?&gt;&lt;/h3&gt;<br>[public_html/user/reseller_program.php:624](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/reseller_program.php#L624) if (first) first.focus();<br>[public_html/user/reseller_program.php:632](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/reseller_program.php#L632) if (lastFocus && typeof lastFocus.focus === 'function') lastFocus.focus();<br>[public_html/user/reseller_program.php:682](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/reseller_program.php#L682) last.focus();<br>[public_html/user/reseller_program.php:685](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/user/reseller_program.php#L685) first.focus(); |

### `verify_account.php`

- Scope: public/API/background; risk: **Critical**; runtime: **UNKNOWN / UNTESTED**.
- Shell: No direct nav include; wrapper/redirect/dynamic includes below govern.
- Inline CSS: yes; inline JS: no direct block.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/verify_account.php:3](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/verify_account.php#L3) requireLogin(true);<br>[public_html/verify_account.php:5](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/verify_account.php#L5) if (isAdmin()) { |
| Includes / render ownership | [public_html/verify_account.php:2](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/verify_account.php#L2) require_once __DIR__ . '/includes/auth.php'; |
| Entry / redirect | [public_html/verify_account.php:6](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/verify_account.php#L6) redirectByRole();<br>[public_html/verify_account.php:20](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/verify_account.php#L20) redirectByRole();<br>[public_html/verify_account.php:40](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/verify_account.php#L40) redirectByRole(); |
| Direct CSS / JS (also inherit nav/includes above) | [public_html/verify_account.php:57](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/verify_account.php#L57) &lt;link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3"&gt;<br>[public_html/verify_account.php:58](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/verify_account.php#L58) &lt;link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css"&gt; |
| Forms / actions | [public_html/verify_account.php:23](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/verify_account.php#L23) if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {<br>[public_html/verify_account.php:25](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/verify_account.php#L25) $action = isset($_POST['action']) && is_scalar($_POST['action']) ? (string) $_POST['action'] : '';<br>[public_html/verify_account.php:111](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/verify_account.php#L111) &lt;form method="POST" class="space-y-3"&gt;<br>[public_html/verify_account.php:118](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/verify_account.php#L118) &lt;form method="POST" class="space-y-3"&gt; |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `verify_binance.php`

- Scope: public/API/background; risk: **Critical**; runtime: **UNKNOWN / UNTESTED**.
- Shell: No direct nav include; wrapper/redirect/dynamic includes below govern.
- Inline CSS: no direct block; inline JS: no direct block.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/verify_binance.php:8](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/verify_binance.php#L8) requireLogin();<br>[public_html/verify_binance.php:9](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/verify_binance.php#L9) requireActive(); |
| Includes / render ownership | [public_html/verify_binance.php:4](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/verify_binance.php#L4) require_once __DIR__ . '/includes/auth.php';<br>[public_html/verify_binance.php:5](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/verify_binance.php#L5) require_once __DIR__ . '/includes/ranking.php';<br>[public_html/verify_binance.php:6](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/verify_binance.php#L6) require_once __DIR__ . '/includes/binance.php';<br>[public_html/verify_binance.php:7](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/verify_binance.php#L7) require_once __DIR__ . '/includes/store_bridge.php'; |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | No direct match (inherited/dynamic behavior must be traced) |
| Forms / actions | [public_html/verify_binance.php:12](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/verify_binance.php#L12) if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `verify_slip.php`

- Scope: public/API/background; risk: **Critical**; runtime: **UNKNOWN / UNTESTED**.
- Shell: No direct nav include; wrapper/redirect/dynamic includes below govern.
- Inline CSS: no direct block; inline JS: no direct block.

| Contract | Source evidence |
|---|---|
| Roles / guards | [public_html/verify_slip.php:12](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/verify_slip.php#L12) requireLogin(true);<br>[public_html/verify_slip.php:13](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/verify_slip.php#L13) requireActive(true); |
| Includes / render ownership | [public_html/verify_slip.php:9](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/verify_slip.php#L9) require_once __DIR__ . '/includes/auth.php';<br>[public_html/verify_slip.php:10](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/verify_slip.php#L10) require_once __DIR__ . '/includes/ranking.php';<br>[public_html/verify_slip.php:11](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/verify_slip.php#L11) require_once __DIR__ . '/includes/store_bridge.php'; |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | No direct match (inherited/dynamic behavior must be traced) |
| Forms / actions | [public_html/verify_slip.php:17](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/verify_slip.php#L17) if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {<br>[public_html/verify_slip.php:25](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/verify_slip.php#L25) $action = strtolower(trim((string) ($_POST['action'] ?? 'verify'))); |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

### `webhook_cheatgame.php`

- Scope: public/API/background; risk: **High**; runtime: **UNKNOWN / UNTESTED**.
- Shell: No direct nav include; wrapper/redirect/dynamic includes below govern.
- Inline CSS: no direct block; inline JS: no direct block.

| Contract | Source evidence |
|---|---|
| Roles / guards | No direct match (inherited/dynamic behavior must be traced) |
| Includes / render ownership | [public_html/webhook_cheatgame.php:4](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/webhook_cheatgame.php#L4) require_once __DIR__ . '/includes/security.php';<br>[public_html/webhook_cheatgame.php:5](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/webhook_cheatgame.php#L5) require_once __DIR__ . '/includes/functions.php';<br>[public_html/webhook_cheatgame.php:6](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/webhook_cheatgame.php#L6) require_once __DIR__ . '/includes/cheatgame.php'; |
| Entry / redirect | No direct match (inherited/dynamic behavior must be traced) |
| Direct CSS / JS (also inherit nav/includes above) | No direct match (inherited/dynamic behavior must be traced) |
| Forms / actions | [public_html/webhook_cheatgame.php:32](https://github.com/aekthai01/sakazuki/blob/74bb4fc38236bdd5a3ae90477b3e8df82844e9a6/public_html/webhook_cheatgame.php#L32) if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { |
| Dynamic endpoints | No direct match (inherited/dynamic behavior must be traced) |
| State / interactions | No direct match (inherited/dynamic behavior must be traced) |

