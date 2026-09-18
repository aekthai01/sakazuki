<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
header('Location: key_resets.php', true, 302);
exit();
