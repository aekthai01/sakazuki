<?php
require_once '../includes/auth.php';
requireAdmin();

header('Content-Type: application/json');
echo json_encode(['discount' => (float)getSetting('reseller_discount', 50)]);

