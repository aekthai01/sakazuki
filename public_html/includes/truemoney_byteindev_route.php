<?php
/**
 * Production routing and recipient-integrity guards for the ByteInDev
 * TrueMoney provider family.
 *
 * The Go backend is intentionally disabled because its current gzip reader
 * pool can panic while decoding a compressed TrueMoney response. Requests
 * are therefore routed through NestJS first, with FastAPI as the next
 * health-checked backend in the existing provider order.
 *
 * After a successful redeem response, Sakazuki also verifies that TrueMoney
 * reports the configured receiver phone as the redeemer before the response
 * is allowed to continue to wallet settlement. The receiver first name is
 * checked when TrueMoney includes it. Only a SHA-256 fingerprint of the
 * expected first name is stored here; the plaintext name is never logged.
 */

if (!defined('TM_BYTEINDEV_EXPECTED_FIRST_NAME_SHA256')) {
    define('TM_BYTEINDEV_EXPECTED_FIRST_NAME_SHA256', 'd1cc59ef09063df9a9f30687174695588b00971e5a981f1a57ed90152df7b1bc');
}

function trueMoneyByteIndevProductionTransportError(int $errno, string $message): array
{
    return [
        'executed' => false,
        'http_code' => 0,
        'curl_errno' => $errno,
        'curl_error' => $message,
        'body' => '',
        'headers' => [],
        'response_too_large' => false,
        'primary_ip' => '',
        'ssl_verify_result' => null,
        'timing' => ['total_ms' => 0],
    ];
}

function trueMoneyByteIndevProductionHealthTransport(string $url, int $timeoutSeconds, ?callable $delegate = null): array
{
    $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));

    if ($host === 'truemoney-voucher-go.vercel.app') {
        return trueMoneyByteIndevProductionTransportError(
            -2,
            'Backend disabled by Sakazuki production routing guard'
        );
    }

    $transport = $delegate ?? 'trueMoneyByteIndevCurlGet';
    if (!is_callable($transport)) {
        return trueMoneyByteIndevProductionTransportError(-3, 'Health transport delegate is not callable');
    }

    return $transport($url, $timeoutSeconds);
}

function trueMoneyByteIndevProductionNormalizePhone($value): string
{
    if (!is_scalar($value)) return '';
    return preg_replace('/\D+/', '', trim((string) $value)) ?? '';
}

function trueMoneyByteIndevProductionExpectedMobileFromUrl(string $url): string
{
    $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
    if ($path === '') return '';
    $segments = array_values(array_filter(explode('/', trim($path, '/')), static function ($segment): bool {
        return $segment !== '';
    }));
    if ($segments === []) return '';
    return trueMoneyByteIndevProductionNormalizePhone(rawurldecode((string) end($segments)));
}

function trueMoneyByteIndevProductionFirstName($value): string
{
    if (!is_scalar($value)) return '';
    $name = trim((string) $value);
    if ($name === '') return '';
    $name = preg_replace('/\s+/u', ' ', $name) ?? '';
    if ($name === '') return '';
    $parts = preg_split('/\s+/u', $name, 2);
    return is_array($parts) && isset($parts[0]) ? trim((string) $parts[0]) : '';
}

/**
 * Validate recipient identity from a successful TrueMoney response.
 *
 * Phone verification is mandatory and exact. Name verification is enforced
 * whenever TrueMoney supplies data.my_ticket.full_name. If the name field is
 * absent, an exact phone match is sufficient so a harmless upstream response
 * shape change does not take the deposit feature offline.
 */
function trueMoneyByteIndevProductionRecipientCheck(
    array $json,
    string $expectedMobile,
    ?string $expectedFirstNameHash = null
): array {
    $expectedMobile = trueMoneyByteIndevProductionNormalizePhone($expectedMobile);
    if (preg_match('/^0\d{9}$/D', $expectedMobile) !== 1) {
        return [
            'verified' => false,
            'reason' => 'expected_mobile_invalid',
            'phone_match' => false,
            'name_present' => false,
            'name_match' => false,
            'actual_phone_masked' => '',
        ];
    }

    $reportedMobile = $json['data']['redeemer_profile']['mobile_number']
        ?? $json['data']['my_ticket']['mobile']
        ?? '';
    $reportedMobile = trueMoneyByteIndevProductionNormalizePhone($reportedMobile);
    $phoneMatch = preg_match('/^0\d{9}$/D', $reportedMobile) === 1
        && hash_equals($expectedMobile, $reportedMobile);

    $firstName = trueMoneyByteIndevProductionFirstName($json['data']['my_ticket']['full_name'] ?? '');
    $namePresent = $firstName !== '';
    $nameHash = strtolower(trim((string) ($expectedFirstNameHash ?? TM_BYTEINDEV_EXPECTED_FIRST_NAME_SHA256)));
    $nameCheckEnabled = preg_match('/^[a-f0-9]{64}$/D', $nameHash) === 1;
    $nameMatch = !$nameCheckEnabled || !$namePresent || hash_equals($nameHash, hash('sha256', $firstName));

    $masked = '';
    if ($reportedMobile !== '') {
        if (function_exists('trueMoneyDebugMaskedPhone')) {
            $masked = trueMoneyDebugMaskedPhone($reportedMobile);
        } elseif (strlen($reportedMobile) >= 4) {
            $masked = str_repeat('*', max(0, strlen($reportedMobile) - 4)) . substr($reportedMobile, -4);
        }
    }

    $reason = 'verified';
    if (!$phoneMatch) {
        $reason = $reportedMobile === '' ? 'recipient_mobile_missing' : 'recipient_mobile_mismatch';
    } elseif ($nameCheckEnabled && $namePresent && !$nameMatch) {
        $reason = 'recipient_name_mismatch';
    }

    return [
        'verified' => $phoneMatch && $nameMatch,
        'reason' => $reason,
        'phone_match' => $phoneMatch,
        'name_present' => $namePresent,
        'name_match' => $nameMatch,
        'actual_phone_masked' => $masked,
    ];
}

/**
 * Redeem transport wrapper used only in production.
 *
 * A failed recipient check is converted into an indeterminate transport result
 * after the real SUCCESS response has been received. The core adapter therefore
 * follows its existing safest path: do not retry the voucher and do not credit
 * the customer's wallet until an administrator investigates.
 */
function trueMoneyByteIndevProductionRedeemTransport(
    string $url,
    int $timeoutSeconds,
    ?callable $delegate = null,
    ?string $expectedMobile = null,
    ?string $expectedFirstNameHash = null
): array {
    $transport = $delegate ?? 'trueMoneyByteIndevCurlGet';
    if (!is_callable($transport)) {
        return trueMoneyByteIndevProductionTransportError(-3, 'Redeem transport delegate is not callable');
    }

    $response = $transport($url, $timeoutSeconds);
    if (!is_array($response)) {
        return trueMoneyByteIndevProductionTransportError(-4, 'Redeem transport returned invalid result');
    }

    $http = (int) ($response['http_code'] ?? 0);
    if (empty($response['executed'])
        || (int) ($response['curl_errno'] ?? 0) !== 0
        || !empty($response['response_too_large'])
        || $http < 200
        || $http >= 300) {
        return $response;
    }

    $body = (string) ($response['body'] ?? '');
    $json = json_decode($body, true);
    if (!is_array($json) || json_last_error() !== JSON_ERROR_NONE) {
        return $response;
    }

    $status = strtoupper(trim((string) ($json['status']['code'] ?? '')));
    if ($status !== 'SUCCESS') {
        return $response;
    }

    $expected = $expectedMobile !== null
        ? trueMoneyByteIndevProductionNormalizePhone($expectedMobile)
        : trueMoneyByteIndevProductionExpectedMobileFromUrl($url);
    $check = trueMoneyByteIndevProductionRecipientCheck($json, $expected, $expectedFirstNameHash);

    if (!isset($response['headers']) || !is_array($response['headers'])) {
        $response['headers'] = [];
    }
    $response['headers']['x-sakazuki-recipient-guard'] = !empty($check['verified'])
        ? 'verified'
        : 'blocked:' . (string) ($check['reason'] ?? 'recipient_unverified');
    $response['headers']['x-sakazuki-recipient-phone-match'] = !empty($check['phone_match']) ? 'yes' : 'no';
    $response['headers']['x-sakazuki-recipient-name-match'] = !empty($check['name_match']) ? 'yes' : 'no';

    if (!empty($check['verified'])) {
        return $response;
    }

    $response['executed'] = false;
    $response['curl_errno'] = -21;
    $response['curl_error'] = 'Recipient verification failed: ' . (string) ($check['reason'] ?? 'recipient_unverified');
    return $response;
}
