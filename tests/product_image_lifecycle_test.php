<?php
require_once __DIR__ . '/../public_html/includes/product_image_lifecycle.php';

$tests = 0;
$failures = 0;

function image_lifecycle_assert($condition, string $message): void
{
    global $tests, $failures;
    $tests++;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

image_lifecycle_assert(
    sakazukiNormalizeManagedProductImagePath('assets/uploads/products/p_abc123.webp') === 'assets/uploads/products/p_abc123.webp',
    'managed product image path should normalize'
);
image_lifecycle_assert(
    sakazukiNormalizeManagedProductImagePath('/assets/uploads/products/cgo_' . str_repeat('a', 64) . '.jpg') === 'assets/uploads/products/cgo_' . str_repeat('a', 64) . '.jpg',
    'leading slash should normalize for legacy database values'
);
foreach ([
    'https://example.com/image.webp',
    'assets/uploads/products/../secret.jpg',
    'assets/uploads/other/p_test.webp',
    '/etc/passwd',
    'assets/uploads/products/not-an-image.txt',
] as $unsafe) {
    image_lifecycle_assert(
        sakazukiNormalizeManagedProductImagePath($unsafe) === '',
        'unsafe/unmanaged path must be rejected: ' . $unsafe
    );
}

$root = sys_get_temp_dir() . '/sakazuki-image-lifecycle-' . bin2hex(random_bytes(6));
$uploadDir = $root . '/assets/uploads/products';
if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
    fwrite(STDERR, "FAIL: unable to create temporary test directory\n");
    exit(1);
}

$relative = 'assets/uploads/products/p_test.webp';
$file = $uploadDir . '/p_test.webp';
file_put_contents($file, 'image-bytes');
$referenced = sakazukiDeleteManagedProductImageIfUnreferenced(
    $relative,
    static fn(string $path): int => 1,
    $root
);
image_lifecycle_assert(empty($referenced['deleted']), 'referenced file must not be deleted');
image_lifecycle_assert(($referenced['reason'] ?? '') === 'referenced', 'referenced deletion should report referenced');
image_lifecycle_assert(is_file($file), 'referenced file must still exist');

$deleted = sakazukiDeleteManagedProductImageIfUnreferenced(
    $relative,
    static fn(string $path): int => 0,
    $root
);
image_lifecycle_assert(!empty($deleted['deleted']), 'unreferenced managed file should be deleted');
image_lifecycle_assert(($deleted['reason'] ?? '') === 'deleted', 'successful deletion should report deleted');
image_lifecycle_assert(!file_exists($file), 'deleted file must no longer exist');

$outside = $root . '/outside.webp';
file_put_contents($outside, 'outside');
$outsideResult = sakazukiDeleteManagedProductImageIfUnreferenced(
    '../outside.webp',
    static fn(string $path): int => 0,
    $root
);
image_lifecycle_assert(empty($outsideResult['deleted']), 'unmanaged traversal path must not delete anything');
image_lifecycle_assert(is_file($outside), 'outside file must remain intact');

$automationRunner = file_get_contents(__DIR__ . '/../public_html/automation_runner.php');
$maintenanceJobsNeedle = '$maintenanceJobs = [\'cgo_catalog\',\'shared_history\',\'shared_binance_history\',\'commerce_center\',\'history_cleanup\',\'product_image_cleanup\',\'product_image_optimizer\'];';
image_lifecycle_assert(
    is_string($automationRunner) && strpos($automationRunner, $maintenanceJobsNeedle) !== false,
    'maintenance runner must schedule product image cleanup and optimizer jobs'
);
image_lifecycle_assert(
    is_string($automationRunner)
        && strpos($automationRunner, "'product_image_cleanup','product_image_optimizer','admin_transaction_indexes'") !== false,
    'maintenance runner fatal-stage allowlist must include product image jobs'
);

@unlink($outside);
@rmdir($uploadDir);
@rmdir(dirname($uploadDir));
@rmdir($root . '/assets');
@rmdir($root);

if ($failures > 0) {
    fwrite(STDERR, "{$failures} of {$tests} assertions failed\n");
    exit(1);
}

echo "Product image lifecycle tests passed ({$tests} assertions).\n";
