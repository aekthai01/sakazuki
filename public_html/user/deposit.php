<?php
require_once '../includes/auth.php';
requireLogin();

if (!isUser()) {
    header('Location: ../index.php');
    exit();
}

global $conn;

$error = '';
$success = '';

require_once '../includes/deposit.php';
