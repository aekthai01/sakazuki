<?php
/**
 * Run once per minute from the hosting control-panel cron.
 * This file is outside public_html and cannot be called by a customer browser.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

ignore_user_abort(true);
@set_time_limit(0);
if (!defined('SAKAZUKI_ALLOW_SCHEMA_MIGRATIONS')) define('SAKAZUKI_ALLOW_SCHEMA_MIGRATIONS', true);
require_once dirname(__DIR__) . '/public_html/includes/automation.php';

$schema = automationPrepareStorefrontSchemas();
automationRecordCronHeartbeat('cron');
$results = automationRunDueJobs('cron');
$results = ['schema' => $schema] + $results;
if (in_array('--verbose', $argv ?? [], true)) {
    $json = json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    fwrite(STDOUT, (is_string($json) ? $json : '{}') . PHP_EOL);
}
