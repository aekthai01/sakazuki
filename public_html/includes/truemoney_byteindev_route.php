<?php
/**
 * Production routing guard for the ByteInDev TrueMoney provider family.
 *
 * The Go backend is intentionally disabled because its current gzip reader
 * pool can panic while decoding a compressed TrueMoney response. Requests
 * are therefore routed through NestJS first, with FastAPI as the next
 * health-checked backend in the existing provider order.
 */

function trueMoneyByteIndevProductionHealthTransport(string $url, int $timeoutSeconds, ?callable $delegate = null): array
{
    $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));

    if ($host === 'truemoney-voucher-go.vercel.app') {
        return [
            'executed' => false,
            'http_code' => 0,
            'curl_errno' => -2,
            'curl_error' => 'Backend disabled by Sakazuki production routing guard',
            'body' => '',
            'headers' => [],
            'response_too_large' => false,
            'primary_ip' => '',
            'ssl_verify_result' => null,
            'timing' => ['total_ms' => 0],
        ];
    }

    $transport = $delegate ?? 'trueMoneyByteIndevCurlGet';
    if (!is_callable($transport)) {
        return [
            'executed' => false,
            'http_code' => 0,
            'curl_errno' => -3,
            'curl_error' => 'Health transport delegate is not callable',
            'body' => '',
            'headers' => [],
            'response_too_large' => false,
            'primary_ip' => '',
            'ssl_verify_result' => null,
            'timing' => ['total_ms' => 0],
        ];
    }

    return $transport($url, $timeoutSeconds);
}
