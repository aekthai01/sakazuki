<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

// Legacy endpoint: currency is managed from the protected Settings form.
header('Location: settings.php?notice=currency_managed_in_settings', true, 303);
exit;
