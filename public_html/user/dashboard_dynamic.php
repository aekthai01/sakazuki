<?php
require_once '../includes/auth.php';
require_once '../includes/cheatgame.php';
require_once '../includes/ranking.php';
requireLogin();

if (!isUser()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Forbidden');
}

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

$user = getCurrentUser();
// This endpoint is read-only. Release the session lock before expensive
// aggregate queries so navigation/preload requests from the same tab are not
// serialized behind dashboard hydration.
authReleaseReadOnlySessionLock(false);
$started = microtime(true);
$totalKeysBought = cgoCountUnifiedUserKeys((int) $user['id']);
$stats = [
    'balance' => getUserBalance($user['id']),
    'keys_bought' => $totalKeysBought,
    'status' => $user['status']
];
$catalogSummary = getActiveCatalogueSummary();
$catalogProductCount = (int) ($catalogSummary['product_count'] ?? 0);
$catalogPlatformCounts = $catalogSummary['platform_counts'] ?? ['all' => 0, 'android' => 0, 'ios' => 0, 'both' => 0];
$catalogCategoryCounts = $catalogSummary['category_counts'] ?? [];
$rankSnapshot = rankUserSnapshot((int) $user['id']);
$recentPurchaseActivity = getPublicRecentPurchaseActivity(10);
// Preserve the current dashboard behavior: the full key list belongs on My Keys.
$userKeys = [];

header('Server-Timing: dashboard_dynamic;dur=' . number_format((microtime(true) - $started) * 1000, 1, '.', ''));
include __DIR__ . '/dashboard_dynamic_content.php';
