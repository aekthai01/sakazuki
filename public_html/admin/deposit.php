<?php
require_once '../includes/auth.php';
requireAdmin();

global $conn;

$error = '';
$success = '';

require_once '../includes/deposit.php';
