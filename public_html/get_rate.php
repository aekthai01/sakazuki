<?php
require_once 'includes/db.php';
require_once 'includes/functions.php';

header('Content-Type: application/json');

$rate = getExchangeRateThbToUsd();

if ($rate) {
    echo json_encode(['success' => true, 'rate' => $rate]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to fetch rate']);
}
