<?php
require_once '../includes/auth.php';
requireReseller();

global $conn;

$error = '';
$success = '';

require_once '../includes/deposit.php';
