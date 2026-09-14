<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
header('Location: buy.php', true, 302);
exit;
