<?php
/**
 * StarkMods web-session provider adapter for Store Bridge.
 *
 * StarkMods does not expose an API key in the captured reseller flow. The
 * encrypted supplier credential therefore contains login credentials and the
 * source currency, for example:
 * {"username":"account","password":"...","currency":"USD","local_rate":33.25}
 *
 * PHPSESSID values are kept only inside the cURL handle for the current
 * request. They are never persisted in Store Bridge, returned in diagnostics,
 * or written to application logs.
 */

function supplierBridgeStarkmodsParseCredential(string $secret): array
{
    $decoded = json_decode($secret, true);
    if (!is_array($decoded)) {
        return ['success' => false, 'code' => 'starkmods_credential_invalid', 'message' => 'StarkMods credential must be valid JSON'];
    }
    $username = trim((string) ($decoded['username'] ?? ''));
    $password = (string) ($decoded['password'] ?? '');
    $currency = strtoupper(trim((string) ($decoded['currency'] ?? '')));
    $localRateRaw = $decoded['local_rate'] ?? null;
    if ($username === '' || strlen($username) > 190) {
        return ['success' => false, 'code' => 'starkmods_username_invalid', 'message' => 'StarkMods credential username is invalid'];
    }
    if ($password === '' || strlen($password) > 500) {
        return ['success' => false, 'code' => 'starkmods_password_invalid', 'message' => 'StarkMods credential password is invalid'];
    }
    if (!preg_match('/^[A-Z]{3,5}$/', $currency)) {
        return ['success' => false, 'code' => 'starkmods_currency_required', 'message' => 'StarkMods credential must declare a 3-5 letter currency code'];
    }
    $localCurrency = function_exists('storeBridgeCurrency') ? strtoupper(trim(storeBridgeCurrency())) : '';
    if ($localCurrency === '') $localCurrency = $currency;
    $localRate = 1.0;
    if (!hash_equals($localCurrency, $currency)) {
        if (!is_numeric($localRateRaw) || !is_finite((float) $localRateRaw) || (float) $localRateRaw <= 0 || (float) $localRateRaw > 1000000000) {
            return [
                'success' => false,
                'code' => 'starkmods_conversion_rate_required',
                'message' => 'StarkMods currency differs from the website currency; a positive local_rate is required',
            ];
        }
        $localRate = (float) $localRateRaw;
    }
    return [
        'success' => true,
        'username' => $username,
        'password' => $password,
        'source_currency' => $currency,
        'currency' => $localCurrency,
        'local_rate' => $localRate,
    ];
}

function supplierBridgeStarkmodsResolveEndpoint(string $endpoint): array
{
    $normalized = function_exists('storeBridgeNormalizeExternalHttpsUrl')
        ? storeBridgeNormalizeExternalHttpsUrl($endpoint, 'StarkMods endpoint')
        : ['success' => false, 'message' => 'Endpoint validation unavailable'];
    if (empty($normalized['success'])) {
        return ['success' => false, 'code' => 'endpoint_invalid', 'message' => 'StarkMods endpoint must be a public HTTPS URL'];
    }
    $value = (string) ($normalized['value'] ?? '');
    $parts = parse_url($value);
    if (!is_array($parts)) return ['success' => false, 'code' => 'endpoint_invalid', 'message' => 'StarkMods endpoint is invalid'];
    $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $port = isset($parts['port']) ? (int) $parts['port'] : 443;
    if ($scheme !== 'https' || $port !== 443 || !in_array($host, ['starkmods.my.id', 'www.starkmods.my.id'], true)) {
        return ['success' => false, 'code' => 'starkmods_endpoint_host_invalid', 'message' => 'StarkMods provider is restricted to starkmods.my.id over HTTPS'];
    }
    $resolved = function_exists('storeBridgeResolvePublicHttpsTarget')
        ? storeBridgeResolvePublicHttpsTarget($value, 'StarkMods endpoint')
        : ['success' => false, 'message' => 'DNS validation unavailable'];
    if (empty($resolved['success'])) {
        return [
            'success' => false,
            'code' => (string) ($resolved['code'] ?? 'endpoint_dns_invalid'),
            'message' => (string) ($resolved['message'] ?? 'StarkMods endpoint DNS validation failed'),
        ];
    }
    $ips = array_values(array_filter(
        (array) ($resolved['ips'] ?? []),
        static fn($ip): bool => is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false
    ));
    if ($ips === []) return ['success' => false, 'code' => 'dns_resolution_failed', 'message' => 'StarkMods endpoint has no usable public IP'];
    return [
        'success' => true,
        'origin' => 'https://' . $host,
        'host' => $host,
        'ip' => $ips[0],
    ];
}

function supplierBridgeStarkmodsDecode(string $value): string
{
    return trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

function supplierBridgeStarkmodsAttributes(string $raw): array
{
    $attributes = [];
    if (preg_match_all('/([a-zA-Z0-9_:-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/s', $raw, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $name = strtolower((string) ($match[1] ?? ''));
            if ($name === '') continue;
            $value = array_key_exists(2, $match) && $match[2] !== '' ? (string) $match[2] : (string) ($match[3] ?? '');
            $attributes[$name] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }
    return $attributes;
}

function supplierBridgeStarkmodsVariantRemoteId(string $productId, string $duration): string
{
    return substr(trim($productId), 0, 80) . ':' . substr(hash('sha256', trim($duration)), 0, 32);
}

function supplierBridgeStarkmodsAbsoluteUrl(string $origin, string $src): string
{
    $src = trim(html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($src === '') return '';
    if (preg_match('#^https://#i', $src) === 1) {
        $sourceParts = parse_url($src);
        $originParts = parse_url($origin);
        $sourceHost = is_array($sourceParts) ? strtolower((string) ($sourceParts['host'] ?? '')) : '';
        $originHost = is_array($originParts) ? strtolower((string) ($originParts['host'] ?? '')) : '';
        if ($sourceHost === '' || $originHost === '' || !hash_equals($originHost, $sourceHost)) return '';
        return substr($src, 0, 1000);
    }
    if (preg_match('#^http://#i', $src) === 1 || substr($src, 0, 2) === '//') return '';

    if ($src[0] === '/') return substr(rtrim($origin, '/') . $src, 0, 1000);
    $path = '/reseller/' . $src;
    $segments = [];
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.') continue;
        if ($segment === '..') {
            array_pop($segments);
            continue;
        }
        $segments[] = $segment;
    }
    return substr(rtrim($origin, '/') . '/' . implode('/', $segments), 0, 1000);
}

function supplierBridgeStarkmodsParseBalance(string $html): ?float
{
    if (preg_match('/id=["\']navBalanceValue["\'][^>]*>\s*[^0-9+\-]*([0-9][0-9,]*(?:\.[0-9]+)?)/i', $html, $match) !== 1) return null;
    $value = str_replace(',', '', (string) $match[1]);
    return is_numeric($value) ? max(0.0, (float) $value) : null;
}

function supplierBridgeStarkmodsParseCatalogue(string $html, string $origin, string $currency, float $localRate): array
{
    $recognized = stripos($html, 'id="productSearchForm"') !== false
        && stripos($html, 'open-purchase') !== false;
    $products = [];
    $variantButtonsSeen = 0;
    if (preg_match_all('/<article\b([^>]*)>(.*?)<\/article>/is', $html, $articleMatches, PREG_SET_ORDER)) {
        foreach ($articleMatches as $articleMatch) {
            $articleAttrs = supplierBridgeStarkmodsAttributes((string) ($articleMatch[1] ?? ''));
            $classes = preg_split('/\s+/', trim((string) ($articleAttrs['class'] ?? ''))) ?: [];
            if (!in_array('product-item', $classes, true)) continue;
            $articleBody = (string) ($articleMatch[2] ?? '');

            $title = '';
            if (preg_match('/<h3\b[^>]*class=["\'][^"\']*product-title[^"\']*["\'][^>]*>(.*?)<\/h3>/is', $articleBody, $titleMatch) === 1) {
                $title = supplierBridgeStarkmodsDecode((string) $titleMatch[1]);
            }
            $description = '';
            if (preg_match('/<p\b[^>]*class=["\'][^"\']*product-desc[^"\']*["\'][^>]*>(.*?)<\/p>/is', $articleBody, $descriptionMatch) === 1) {
                $description = supplierBridgeStarkmodsDecode((string) $descriptionMatch[1]);
            }
            $imageUrl = '';
            if (preg_match('/<img\b([^>]*)>/is', $articleBody, $imageMatch) === 1) {
                $imageAttrs = supplierBridgeStarkmodsAttributes((string) $imageMatch[1]);
                $imageUrl = supplierBridgeStarkmodsAbsoluteUrl($origin, (string) ($imageAttrs['src'] ?? ''));
            }

            $platformRaw = strtolower(trim((string) ($articleAttrs['data-platforms'] ?? '')));
            $platformParts = array_values(array_filter(array_map('trim', explode(',', $platformRaw)), static fn(string $v): bool => $v !== ''));
            $platform = count($platformParts) === 1 && in_array($platformParts[0], ['android', 'ios', 'pc'], true) ? $platformParts[0] : 'both';

            $categories = [];
            $gameRaw = trim((string) ($articleAttrs['data-games'] ?? ''));
            foreach (explode(',', $gameRaw) as $game) {
                $game = trim($game);
                if ($game !== '' && strlen($game) <= 255 && !in_array($game, $categories, true)) $categories[] = $game;
                if (count($categories) >= 4) break;
            }
            if ($categories === []) $categories[] = 'STARKMODS';

            if (!preg_match_all('/<button\b([^>]*)>(.*?)<\/button>/is', $articleBody, $buttonMatches, PREG_SET_ORDER)) continue;
            foreach ($buttonMatches as $buttonMatch) {
                $buttonAttrRaw = (string) ($buttonMatch[1] ?? '');
                $buttonAttrs = supplierBridgeStarkmodsAttributes($buttonAttrRaw);
                $buttonClasses = preg_split('/\s+/', trim((string) ($buttonAttrs['class'] ?? ''))) ?: [];
                if (!in_array('open-purchase', $buttonClasses, true)) continue;
                $variantButtonsSeen++;

                $productId = trim((string) ($buttonAttrs['data-product-id'] ?? ''));
                $duration = trim((string) ($buttonAttrs['data-duration'] ?? ''));
                $productName = trim((string) ($buttonAttrs['data-product-name'] ?? $title));
                $variantLabel = trim((string) ($buttonAttrs['data-variant-label'] ?? ''));
                $priceRaw = $buttonAttrs['data-price'] ?? null;
                $stockRaw = $buttonAttrs['data-available'] ?? null;
                if ($productId === '' || $duration === '' || $productName === '' || !is_numeric($priceRaw) || !is_numeric($stockRaw)) continue;
                if (strlen($productId) > 80 || strlen($duration) > 120 || strlen($productName) > 255) continue;

                $sourcePrice = (float) $priceRaw;
                $stock = max(0, min(1000000000, (int) $stockRaw));
                if (!is_finite($sourcePrice) || $sourcePrice < 0 || $sourcePrice > 1000000000) continue;
                $remoteId = supplierBridgeStarkmodsVariantRemoteId($productId, $duration);
                $localPrice = round($sourcePrice * $localRate, 2);
                $disabled = preg_match('/(?:^|\s)disabled(?:\s|=|$)/i', $buttonAttrRaw) === 1;
                $status = (!$disabled && $stock > 0) ? 'available' : 'out_of_stock';
                if ($variantLabel === '') $variantLabel = $productName . ' - ' . $duration;

                $products[] = [
                    'remote_product_id' => $remoteId,
                    'source_product_id' => $productId,
                    'name' => $productName,
                    'description' => $description,
                    'image_url' => $imageUrl,
                    'categories' => $categories,
                    'platform' => $platform,
                    'duration' => $duration,
                    'stock' => $stock,
                    'status' => $status,
                    'currency' => $currency,
                    'price' => $localPrice,
                    'source_currency' => '',
                    'source_price' => round($sourcePrice, 4),
                    'variant_label' => $variantLabel,
                    'starkmods_product_id' => $productId,
                ];
            }
        }
    }
    // Fail closed if the page still looks like a catalogue but any purchase
    // button could not be parsed. This prevents a markup change from being
    // misread as hundreds of supplier products disappearing.
    $recognized = $recognized && $variantButtonsSeen > 0 && count($products) === $variantButtonsSeen;
    return ['recognized' => $recognized, 'products' => $recognized ? $products : []];
}

function supplierBridgeStarkmodsFindVariant(array $products, string $remoteProductId): ?array
{
    foreach ($products as $product) {
        if (!is_array($product)) continue;
        if (hash_equals((string) ($product['remote_product_id'] ?? ''), $remoteProductId)) return $product;
    }
    return null;
}

function supplierBridgeStarkmodsParsePurchaseSuccess(string $html): ?array
{
    $successModalOffset = null;
    if (preg_match_all('/<div\b([^>]*)>/is', $html, $divStarts, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($divStarts as $divStart) {
            $rawAttrs = (string) ($divStart[1][0] ?? '');
            $attrs = supplierBridgeStarkmodsAttributes($rawAttrs);
            if (($attrs['id'] ?? '') !== 'successBackdrop' || strtolower(trim((string) ($attrs['aria-hidden'] ?? ''))) !== 'false') continue;
            $classes = preg_split('/\s+/', trim((string) ($attrs['class'] ?? ''))) ?: [];
            if (in_array('modal-backdrop', $classes, true) && in_array('is-open', $classes, true)) {
                $successModalOffset = (int) ($divStart[0][1] ?? 0);
                break;
            }
        }
    }
    if ($successModalOffset === null) return null;

    // Search only after the verified open success modal. This avoids picking up
    // similarly styled elements from the catalogue or purchase modal.
    $modalHtml = substr($html, $successModalOffset, 100000);
    if (preg_match('/id\s*=\s*["\']licenseTextBox["\']/i', $modalHtml) !== 1) return null;

    $productLabel = '';
    $amount = null;
    $keys = [];
    if (preg_match_all('/<div\b([^>]*)>([^<]*)<\/div>/is', $modalHtml, $simpleDivs, PREG_SET_ORDER)) {
        foreach ($simpleDivs as $simpleDiv) {
            $attrs = supplierBridgeStarkmodsAttributes((string) ($simpleDiv[1] ?? ''));
            $classes = preg_split('/\s+/', trim((string) ($attrs['class'] ?? ''))) ?: [];
            $text = supplierBridgeStarkmodsDecode((string) ($simpleDiv[2] ?? ''));
            if ($productLabel === '' && in_array('mt-2', $classes, true) && in_array('font-extrabold', $classes, true)) {
                $productLabel = $text;
            }
            if ($amount === null && in_array('amount-paid', $classes, true) && preg_match('/([0-9][0-9,]*(?:\.[0-9]+)?)/', $text, $amountMatch) === 1) {
                $amountValue = str_replace(',', '', (string) $amountMatch[1]);
                if (is_numeric($amountValue)) $amount = (float) $amountValue;
            }
            if (in_array('license-box', $classes, true) && $text !== '' && strlen($text) <= 5000 && !in_array($text, $keys, true)) {
                $keys[] = $text;
            }
        }
    }
    return [
        'product_label' => $productLabel,
        'amount_paid' => $amount,
        'keys' => $keys,
    ];
}

function supplierBridgeStarkmodsRawRequest(
    $ch,
    string $url,
    string $method,
    ?array $form,
    string $resolveEntry,
    int $connectTimeout,
    int $requestTimeout,
    string $referer = ''
): array {
    $response = '';
    $tooLarge = false;
    $responseHeaders = [];
    $method = strtoupper($method);
    $headers = [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
        'Accept-Language: en-US,en;q=0.8',
        'Cache-Control: no-cache',
        'Pragma: no-cache',
        'Upgrade-Insecure-Requests: 1',
        // StarkMods is a browser-only reseller panel rather than a documented API.
        // Use a browser-compatible UA because the verified login flow is a normal
        // browser navigation through LiteSpeed.
        'User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    ];
    if ($method === 'POST') {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $parts = parse_url($url);
        if (is_array($parts) && strtolower((string) ($parts['scheme'] ?? '')) === 'https' && !empty($parts['host'])) {
            $origin = 'https://' . (string) $parts['host'];
            $port = (int) ($parts['port'] ?? 443);
            if ($port !== 443) $origin .= ':' . $port;
            $headers[] = 'Origin: ' . $origin;
        }
    }

    $options = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => max(2, min(30, $connectTimeout)),
        CURLOPT_TIMEOUT => max(3, min(120, $requestTimeout)),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_ENCODING => '',
        CURLOPT_NOSIGNAL => true,
        CURLOPT_PROXY => '',
        CURLOPT_HEADER => false,
        CURLOPT_COOKIEFILE => '',
        CURLOPT_RESOLVE => [$resolveEntry],
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
            $length = strlen($line);
            $trimmed = trim($line);
            if ($trimmed === '' || stripos($trimmed, 'HTTP/') === 0) return $length;
            $pos = strpos($trimmed, ':');
            if ($pos === false) return $length;
            $name = strtolower(trim(substr($trimmed, 0, $pos)));
            $value = trim(substr($trimmed, $pos + 1));
            if ($name === 'location') $responseHeaders['location'] = substr($value, 0, 1000);
            return $length;
        },
    ];
    if ($referer !== '') $options[CURLOPT_REFERER] = $referer;
    if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
    if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTPS')) $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
    if ($method === 'POST') {
        $options[CURLOPT_HTTPGET] = false;
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = http_build_query($form ?? [], '', '&', PHP_QUERY_RFC3986);
    } else {
        $options[CURLOPT_POST] = false;
        $options[CURLOPT_HTTPGET] = true;
        $options[CURLOPT_POSTFIELDS] = null;
    }
    curl_setopt_array($ch, $options);
    if (function_exists('configureBoundedCurlResponse')) {
        configureBoundedCurlResponse($ch, $response, $tooLarge, defined('STORE_BRIDGE_MAX_RESPONSE') ? STORE_BRIDGE_MAX_RESPONSE : 2097152);
    } else {
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    }
    $executed = curl_exec($ch);
    if (!function_exists('configureBoundedCurlResponse')) {
        $response = is_string($executed) ? $executed : '';
        $executed = $executed !== false;
        if (strlen($response) > (defined('STORE_BRIDGE_MAX_RESPONSE') ? STORE_BRIDGE_MAX_RESPONSE : 2097152)) $tooLarge = true;
    }
    $curlNo = curl_errno($ch);
    $curlError = curl_error($ch);
    $info = curl_getinfo($ch);
    $httpCode = is_array($info) ? max(0, (int) ($info['http_code'] ?? 0)) : 0;
    if ($executed === false || $curlNo !== 0 || $tooLarge) {
        return [
            'ok' => false,
            'http_code' => $httpCode,
            'transport_error' => true,
            'error_code' => $tooLarge ? 'response_too_large' : 'transport_error',
            'error' => $tooLarge ? 'StarkMods response exceeded limit' : ('StarkMods network error ' . $curlNo . ': ' . $curlError),
            'body' => '',
            'location' => '',
        ];
    }
    return [
        'ok' => $httpCode >= 200 && $httpCode < 400,
        'http_code' => $httpCode,
        'transport_error' => false,
        'error_code' => ($httpCode >= 200 && $httpCode < 400) ? '' : 'starkmods_http_' . $httpCode,
        'error' => ($httpCode >= 200 && $httpCode < 400) ? '' : 'StarkMods returned HTTP ' . $httpCode,
        'body' => $response,
        'location' => (string) ($responseHeaders['location'] ?? ''),
    ];
}

function supplierBridgeStarkmodsDefinitiveFailure(string $code, string $message, int $httpCode = 409, ?array $data = null): array
{
    return [
        'ok' => false,
        'http_code' => $httpCode,
        'transport_error' => false,
        'error_code' => $code,
        'error' => $message,
        'data' => $data,
        'order_not_created_proven' => true,
    ];
}

function supplierBridgeStarkmodsNoOrderFailureFrom(array $result): array
{
    return [
        'ok' => false,
        'http_code' => (int) ($result['http_code'] ?? 0),
        'transport_error' => !empty($result['transport_error']),
        'error_code' => (string) ($result['error_code'] ?? 'starkmods_preflight_failed'),
        'error' => (string) ($result['error'] ?? 'StarkMods preflight failed before purchase'),
        'data' => null,
        'order_not_created_proven' => true,
    ];
}

function supplierBridgeStarkmodsLocalUserIsAdmin(int $userId): bool
{
    if ($userId < 1) return false;
    global $conn;
    if (!isset($conn) || !($conn instanceof mysqli)) return false;
    $stmt = $conn->prepare('SELECT role FROM users WHERE id=? LIMIT 1');
    if (!$stmt) return false;
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return strtolower(trim((string) ($row['role'] ?? ''))) === 'admin';
}

function supplierBridgeStarkmodsApiRequest(
    array $connection,
    string $action,
    string $method,
    array $payload,
    string $secret,
    int $connectTimeout,
    int $requestTimeout
): array {
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'http_code' => 0, 'transport_error' => false, 'error_code' => 'curl_unavailable', 'error' => 'PHP cURL is unavailable', 'data' => null];
    }
    $credential = supplierBridgeStarkmodsParseCredential($secret);
    if (empty($credential['success'])) {
        return [
            'ok' => false,
            'http_code' => 0,
            'transport_error' => false,
            'error_code' => (string) ($credential['code'] ?? 'starkmods_credential_invalid'),
            'error' => (string) ($credential['message'] ?? 'StarkMods credential is invalid'),
            'data' => null,
            'order_not_created_proven' => true,
        ];
    }
    $endpoint = supplierBridgeStarkmodsResolveEndpoint((string) ($connection['endpoint_url'] ?? ''));
    if (empty($endpoint['success'])) {
        return [
            'ok' => false,
            'http_code' => 0,
            'transport_error' => false,
            'error_code' => (string) ($endpoint['code'] ?? 'starkmods_endpoint_invalid'),
            'error' => (string) ($endpoint['message'] ?? 'StarkMods endpoint is invalid'),
            'data' => null,
            'order_not_created_proven' => true,
        ];
    }

    $origin = (string) $endpoint['origin'];
    $resolveEntry = (string) $endpoint['host'] . ':443:' . (string) $endpoint['ip'];
    $sourceCurrency = (string) $credential['source_currency'];
    $currency = (string) $credential['currency'];
    $localRate = (float) $credential['local_rate'];
    $ch = curl_init();
    if ($ch === false) {
        return ['ok' => false, 'http_code' => 0, 'transport_error' => true, 'error_code' => 'curl_init_failed', 'error' => 'Unable to initialize StarkMods request', 'data' => null, 'order_not_created_proven' => true];
    }

    $finish = static function (array $result) use ($ch): array {
        curl_close($ch);
        return $result;
    };

    $loginUrl = $origin . '/login.php';

    // Match the verified browser flow: first visit the login page so PHP creates
    // a session cookie, then submit credentials on the same cURL handle. Posting
    // credentials without this session bootstrap is not accepted reliably.
    $loginPage = supplierBridgeStarkmodsRawRequest(
        $ch,
        $loginUrl,
        'GET',
        null,
        $resolveEntry,
        $connectTimeout,
        min($requestTimeout, 15),
        $origin . '/'
    );
    if (empty($loginPage['ok'])) {
        return $finish(supplierBridgeStarkmodsNoOrderFailureFrom($loginPage));
    }
    $loginPageBody = (string) ($loginPage['body'] ?? '');
    if (
        stripos($loginPageBody, 'name="username"') === false
        || stripos($loginPageBody, 'name="password"') === false
    ) {
        return $finish(supplierBridgeStarkmodsDefinitiveFailure(
            'starkmods_login_page_invalid',
            'StarkMods login page did not contain the expected login form',
            (int) ($loginPage['http_code'] ?? 502)
        ));
    }

    $login = supplierBridgeStarkmodsRawRequest(
        $ch,
        $loginUrl,
        'POST',
        ['username' => (string) $credential['username'], 'password' => (string) $credential['password']],
        $resolveEntry,
        $connectTimeout,
        min($requestTimeout, 15),
        $loginUrl
    );
    if (empty($login['ok'])) {
        return $finish(supplierBridgeStarkmodsNoOrderFailureFrom($login));
    }
    $loginLocation = trim((string) ($login['location'] ?? ''));
    $loginCode = (int) ($login['http_code'] ?? 0);
    $loginRedirectValid = in_array($loginCode, [302, 303], true)
        && preg_match('~(?:^|/)reseller/buy\.php(?:[?/#]|$)~i', $loginLocation) === 1;
    if (!$loginRedirectValid) {
        $loginStillVisible = stripos((string) ($login['body'] ?? ''), 'name="username"') !== false
            && stripos((string) ($login['body'] ?? ''), 'name="password"') !== false;
        return $finish(supplierBridgeStarkmodsDefinitiveFailure(
            'starkmods_login_failed',
            $loginStillVisible
                ? 'StarkMods rejected the username/password'
                : ('StarkMods login returned unexpected HTTP ' . $loginCode . ' response'),
            $loginCode > 0 ? $loginCode : 401,
            [
                'upstream_http_code' => $loginCode,
                'redirect_received' => $loginLocation !== '',
                'redirect_target_is_reseller' => $loginRedirectValid,
            ]
        ));
    }

    $catalogueUrl = $origin . '/reseller/buy.php';
    $catalogueRequest = static function () use ($ch, $catalogueUrl, $resolveEntry, $connectTimeout, $requestTimeout, $loginUrl): array {
        return supplierBridgeStarkmodsRawRequest(
            $ch,
            $catalogueUrl,
            'GET',
            null,
            $resolveEntry,
            $connectTimeout,
            min($requestTimeout, 20),
            $loginUrl
        );
    };
    $loadCatalogue = static function () use ($catalogueRequest, $origin, $currency, $localRate): array {
        $response = $catalogueRequest();
        if (empty($response['ok'])) return ['response' => $response, 'parsed' => null, 'balance' => null];
        $body = (string) ($response['body'] ?? '');
        if (stripos($body, 'name="username"') !== false && stripos($body, 'name="password"') !== false) {
            return [
                'response' => ['ok' => false, 'http_code' => 401, 'transport_error' => false, 'error_code' => 'starkmods_session_expired', 'error' => 'StarkMods session returned to login', 'body' => '', 'location' => ''],
                'parsed' => null,
                'balance' => null,
            ];
        }
        $parsed = supplierBridgeStarkmodsParseCatalogue($body, $origin, $currency, $localRate);
        $balance = supplierBridgeStarkmodsParseBalance($body);
        return ['response' => $response, 'parsed' => $parsed, 'balance' => $balance];
    };

    if ($action === 'order_status') {
        return $finish([
            'ok' => false,
            'http_code' => 501,
            'transport_error' => false,
            'error_code' => 'starkmods_reconciliation_unavailable',
            'error' => 'StarkMods purchase history endpoint has not been verified; this order requires manual review',
            'data' => null,
        ]);
    }

    $catalogue = $loadCatalogue();
    $catalogueResponse = is_array($catalogue['response'] ?? null) ? $catalogue['response'] : [];
    if (empty($catalogueResponse['ok'])) {
        return $finish(supplierBridgeStarkmodsNoOrderFailureFrom($catalogueResponse));
    }
    $parsed = is_array($catalogue['parsed'] ?? null) ? $catalogue['parsed'] : null;
    if (!is_array($parsed) || empty($parsed['recognized'])) {
        return $finish(supplierBridgeStarkmodsDefinitiveFailure('starkmods_catalogue_unrecognized', 'StarkMods catalogue HTML was not recognized', 502));
    }
    $products = is_array($parsed['products'] ?? null) ? $parsed['products'] : [];
    $balanceSource = is_numeric($catalogue['balance'] ?? null) ? (float) $catalogue['balance'] : null;
    $balanceLocal = $balanceSource !== null ? round($balanceSource * $localRate, 2) : null;

    if ($action === 'products' && strtoupper($method) === 'GET') {
        return $finish([
            'ok' => true,
            'http_code' => 200,
            'transport_error' => false,
            'error_code' => '',
            'error' => '',
            'data' => [
                'success' => true,
                'products' => $products,
                'currency' => $currency,
                'source_currency' => $sourceCurrency,
                'balance' => $balanceLocal,
                'source_balance' => $balanceSource,
            ],
        ]);
    }

    if ($action === 'balance' && strtoupper($method) === 'GET') {
        if ($balanceLocal === null) {
            return $finish(supplierBridgeStarkmodsDefinitiveFailure('starkmods_balance_unrecognized', 'StarkMods account balance could not be read', 502));
        }
        return $finish([
            'ok' => true,
            'http_code' => 200,
            'transport_error' => false,
            'error_code' => '',
            'error' => '',
            'data' => [
                'success' => true,
                'balance' => $balanceLocal,
                'currency' => $currency,
                'source_balance' => $balanceSource,
                'source_currency' => $sourceCurrency,
            ],
        ]);
    }

    if ($action === 'inventory' && strtoupper($method) === 'GET') {
        $remoteProductId = trim((string) ($payload['product_id'] ?? ''));
        if ($remoteProductId === '') {
            return $finish(supplierBridgeStarkmodsDefinitiveFailure('invalid_product_id', 'StarkMods product id is required', 400));
        }
        $product = supplierBridgeStarkmodsFindVariant($products, $remoteProductId);
        if (!$product) return $finish(supplierBridgeStarkmodsDefinitiveFailure('product_not_found', 'StarkMods product was not found', 404));
        $stock = max(0, (int) ($product['stock'] ?? 0));
        $price = round((float) ($product['price'] ?? 0), 2);
        $status = trim((string) ($product['status'] ?? ($stock > 0 ? 'available' : 'out_of_stock')));
        return $finish([
            'ok' => true,
            'http_code' => 200,
            'transport_error' => false,
            'error_code' => '',
            'error' => '',
            'data' => [
                'success' => true,
                'product_id' => $remoteProductId,
                'stock' => $stock,
                'status' => $status,
                'price' => $price,
                'currency' => $currency,
                'revision' => substr(hash('sha256', $remoteProductId . '|' . $stock . '|' . round($price, 4) . '|' . $status), 0, 40),
            ],
        ]);
    }

    if ($action !== 'order' || strtoupper($method) !== 'POST') {
        return $finish(supplierBridgeStarkmodsDefinitiveFailure('invalid_api_action', 'Unsupported StarkMods action', 400));
    }

    $purchaseMode = strtolower(trim((string) ($connection['purchase_mode'] ?? 'disabled')));
    if (!in_array($purchaseMode, ['disabled', 'test', 'live'], true)) $purchaseMode = 'disabled';
    if ($purchaseMode === 'disabled') {
        return $finish(supplierBridgeStarkmodsDefinitiveFailure('starkmods_purchase_disabled', 'StarkMods purchasing is disabled for this connection'));
    }
    $originUserId = max(0, (int) ($payload['origin_user_id'] ?? 0));
    if ($purchaseMode === 'test' && !supplierBridgeStarkmodsLocalUserIsAdmin($originUserId)) {
        return $finish(supplierBridgeStarkmodsDefinitiveFailure('starkmods_admin_test_only', 'StarkMods is in admin test mode'));
    }

    $remoteProductId = trim((string) ($payload['product_id'] ?? ''));
    $quantity = max(1, min(100, (int) ($payload['quantity'] ?? 0)));
    $maxSupplierCost = $payload['_max_supplier_cost'] ?? null;
    if ($remoteProductId === '') return $finish(supplierBridgeStarkmodsDefinitiveFailure('invalid_product_id', 'StarkMods product id is required', 400));
    if (!is_numeric($maxSupplierCost) || (float) $maxSupplierCost <= 0) {
        return $finish(supplierBridgeStarkmodsDefinitiveFailure('starkmods_cost_guard_missing', 'StarkMods max supplier cost is required before purchase'));
    }
    $maxSupplierCost = round((float) $maxSupplierCost, 2);

    $product = supplierBridgeStarkmodsFindVariant($products, $remoteProductId);
    if (!$product) return $finish(supplierBridgeStarkmodsDefinitiveFailure('product_not_found', 'StarkMods product was not found', 404));
    $currentPrice = round((float) ($product['price'] ?? 0), 2);
    $sourcePrice = (float) ($product['source_price'] ?? 0);
    $stock = max(0, (int) ($product['stock'] ?? 0));
    if ($currentPrice > $maxSupplierCost + 0.00001) {
        return $finish(supplierBridgeStarkmodsDefinitiveFailure('supplier_cost_guard_exceeded', 'StarkMods current price exceeds max supplier cost'));
    }
    if ($stock < $quantity) {
        return $finish(supplierBridgeStarkmodsDefinitiveFailure('out_of_stock', 'StarkMods stock is lower than requested quantity'));
    }
    if ($balanceSource === null) {
        return $finish(supplierBridgeStarkmodsDefinitiveFailure('starkmods_balance_unrecognized', 'StarkMods supplier balance could not be verified before purchase', 502));
    }
    if ($balanceSource + 0.00001 < round($sourcePrice * $quantity, 4)) {
        return $finish(supplierBridgeStarkmodsDefinitiveFailure('insufficient_supplier_balance', 'StarkMods supplier balance is insufficient'));
    }

    $productId = trim((string) ($product['starkmods_product_id'] ?? $product['source_product_id'] ?? ''));
    $duration = trim((string) ($product['duration'] ?? ''));
    $variantLabel = trim((string) ($product['variant_label'] ?? ''));
    if ($productId === '' || $duration === '') {
        return $finish(supplierBridgeStarkmodsDefinitiveFailure('starkmods_variant_invalid', 'StarkMods purchase variant is incomplete'));
    }

    // This is the non-idempotent boundary. No result after this POST is treated
    // as definitive failure unless a future verified order-history endpoint can
    // prove that the purchase was not created.
    $purchase = supplierBridgeStarkmodsRawRequest(
        $ch,
        $catalogueUrl,
        'POST',
        [
            'duration_group' => $duration,
            'product_id_duration' => $productId,
            'quantity' => $quantity,
            'purchase_duration' => 1,
        ],
        $resolveEntry,
        $connectTimeout,
        min($requestTimeout, 15),
        $catalogueUrl
    );
    if (empty($purchase['ok'])) {
        return $finish([
            'ok' => false,
            'http_code' => (int) ($purchase['http_code'] ?? 0),
            'transport_error' => !empty($purchase['transport_error']),
            'error_code' => (string) ($purchase['error_code'] ?? 'starkmods_purchase_uncertain'),
            'error' => (string) ($purchase['error'] ?? 'StarkMods purchase response is uncertain'),
            'data' => null,
        ]);
    }

    $location = trim((string) ($purchase['location'] ?? ''));
    $purchaseCode = (int) ($purchase['http_code'] ?? 0);
    if (!in_array($purchaseCode, [302, 303], true)
        || preg_match('~(?:^|/)reseller/buy\.php(?:[?/#]|$)~i', $location) !== 1) {
        return $finish([
            'ok' => false,
            'http_code' => $purchaseCode,
            'transport_error' => false,
            'error_code' => 'starkmods_purchase_redirect_unrecognized',
            'error' => 'StarkMods purchase response could not be verified',
            'data' => null,
        ]);
    }

    $resultUrl = $catalogueUrl;
    if (substr($location, 0, 1) === '/') {
        $resultUrl = $origin . $location;
    } elseif (preg_match('#^https://#i', $location) === 1) {
        $locationParts = parse_url($location);
        $locationHost = is_array($locationParts) ? strtolower((string) ($locationParts['host'] ?? '')) : '';
        if (!hash_equals((string) $endpoint['host'], $locationHost)) {
            return $finish([
                'ok' => false,
                'http_code' => $purchaseCode,
                'transport_error' => false,
                'error_code' => 'starkmods_purchase_redirect_host_invalid',
                'error' => 'StarkMods purchase redirect left the verified supplier host',
                'data' => null,
            ]);
        }
        $resultUrl = $location;
    } else {
        $relative = ltrim($location, '/');
        $resultUrl = stripos($relative, 'reseller/') === 0
            ? $origin . '/' . $relative
            : $origin . '/reseller/' . $relative;
    }

    $resultPage = supplierBridgeStarkmodsRawRequest(
        $ch,
        $resultUrl,
        'GET',
        null,
        $resolveEntry,
        $connectTimeout,
        min($requestTimeout, 20),
        $catalogueUrl
    );
    if (empty($resultPage['ok'])) {
        return $finish([
            'ok' => false,
            'http_code' => (int) ($resultPage['http_code'] ?? 0),
            'transport_error' => !empty($resultPage['transport_error']),
            'error_code' => (string) ($resultPage['error_code'] ?? 'starkmods_delivery_unavailable'),
            'error' => (string) ($resultPage['error'] ?? 'StarkMods delivery page could not be verified'),
            'data' => null,
        ]);
    }

    $success = supplierBridgeStarkmodsParsePurchaseSuccess((string) ($resultPage['body'] ?? ''));
    if (!is_array($success)) {
        return $finish([
            'ok' => false,
            'http_code' => (int) ($resultPage['http_code'] ?? 200),
            'transport_error' => false,
            'error_code' => 'starkmods_purchase_result_unrecognized',
            'error' => 'StarkMods purchase result was not recognized; automatic refund is blocked',
            'data' => null,
        ]);
    }
    $keys = is_array($success['keys'] ?? null) ? array_values($success['keys']) : [];
    $actualLabel = trim((string) ($success['product_label'] ?? ''));
    $amountPaidSource = $success['amount_paid'] ?? null;
    if ($variantLabel !== '' && ($actualLabel === '' || !hash_equals($variantLabel, $actualLabel))) {
        return $finish([
            'ok' => false,
            'http_code' => 200,
            'transport_error' => false,
            'error_code' => 'starkmods_purchase_variant_mismatch',
            'error' => 'StarkMods returned a purchase result for a different variant; automatic refund is blocked',
            'data' => ['keys' => $keys],
        ]);
    }
    if (count($keys) !== $quantity) {
        return $finish([
            'ok' => false,
            'http_code' => 200,
            'transport_error' => false,
            'error_code' => 'starkmods_key_count_mismatch',
            'error' => 'StarkMods delivered key count does not match requested quantity',
            'data' => ['keys' => $keys],
        ]);
    }
    $expectedSourceTotal = round($sourcePrice * $quantity, 4);
    if (!is_numeric($amountPaidSource) || abs((float) $amountPaidSource - $expectedSourceTotal) > 0.01001) {
        return $finish([
            'ok' => false,
            'http_code' => 200,
            'transport_error' => false,
            'error_code' => 'starkmods_amount_mismatch',
            'error' => 'StarkMods purchase amount could not be matched to the preflight price; automatic refund is blocked',
            'data' => ['keys' => $keys],
        ]);
    }

    $totalDeducted = round((float) $amountPaidSource * $localRate, 2);
    $priceUsed = round($totalDeducted / max(1, $quantity), 2);
    if ($priceUsed > $maxSupplierCost + 0.00001 || $totalDeducted > round($maxSupplierCost * $quantity, 2) + 0.00001) {
        return $finish([
            'ok' => false,
            'http_code' => 200,
            'transport_error' => false,
            'error_code' => 'supplier_cost_guard_postpurchase',
            'error' => 'StarkMods charged more than the configured purchase ceiling; automatic refund is blocked',
            'data' => ['keys' => $keys],
        ]);
    }

    return $finish([
        'ok' => true,
        'http_code' => 200,
        'transport_error' => false,
        'error_code' => '',
        'error' => '',
        'data' => [
            'success' => true,
            'status' => 'success',
            'keys' => $keys,
            'product_id' => $remoteProductId,
            'product_label' => $actualLabel,
            'price_used' => $priceUsed,
            'total_deducted' => $totalDeducted,
            'source_price_used' => round($sourcePrice, 4),
            'source_total_deducted' => round((float) $amountPaidSource, 4),
            'source_currency' => $sourceCurrency,
            'currency' => $currency,
            'local_rate' => $localRate,
        ],
    ]);
}
