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

image_lifecycle_assert(
    sakazukiParsePhpIniBytes('128M') === 128 * 1024 * 1024,
    'PHP memory limit parser should understand M suffix'
);
image_lifecycle_assert(
    sakazukiParsePhpIniBytes('-1') === 0,
    'unlimited PHP memory limit should normalize to zero'
);
$smallMemoryPlan = sakazukiProductImageOptimizationMemoryPlan(
    1600,
    900,
    1024 * 1024,
    1024,
    128 * 1024 * 1024,
    8 * 1024 * 1024
);
image_lifecycle_assert(!empty($smallMemoryPlan['safe']), 'normal storefront image should fit a 128M worker budget');
$largeMemoryPlan = sakazukiProductImageOptimizationMemoryPlan(
    6000,
    4000,
    4 * 1024 * 1024,
    1024,
    128 * 1024 * 1024,
    8 * 1024 * 1024
);
image_lifecycle_assert(empty($largeMemoryPlan['safe']), '24MP image must be rejected before GD decode on a 128M worker');
image_lifecycle_assert(
    ($largeMemoryPlan['estimated_additional_bytes'] ?? 0) > ($largeMemoryPlan['available_bytes'] ?? 0),
    'unsafe memory plan should explain that estimated demand exceeds available budget'
);

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
$maintenanceJobsNeedle = '$maintenanceJobs = [\'cgo_catalog\',\'shared_history\',\'shared_binance_history\',\'commerce_center\',\'history_cleanup\',\'product_image_cleanup\'];';
image_lifecycle_assert(
    is_string($automationRunner) && strpos($automationRunner, $maintenanceJobsNeedle) !== false,
    'HTTP maintenance runner must keep reference-aware product image cleanup enabled'
);
$cliOptimizerNeedle = "if (\$isCli && \$mode === 'maintenance') \$maintenanceJobs[] = 'product_image_optimizer';";
image_lifecycle_assert(
    is_string($automationRunner) && strpos($automationRunner, $cliOptimizerNeedle) !== false,
    'legacy product image optimizer must be isolated to CLI maintenance'
);
$unsafeHttpOptimizerNeedle = "\$maintenanceJobs = ['cgo_catalog','shared_history','shared_binance_history','commerce_center','history_cleanup','product_image_cleanup','product_image_optimizer'];";
image_lifecycle_assert(
    is_string($automationRunner) && strpos($automationRunner, $unsafeHttpOptimizerNeedle) === false,
    'HTTP maintenance whitelist must not directly include the GD optimizer'
);
image_lifecycle_assert(
    is_string($automationRunner)
        && strpos($automationRunner, "'product_image_cleanup','product_image_optimizer','admin_transaction_indexes'") !== false,
    'maintenance runner fatal-stage allowlist must include product image jobs'
);

image_lifecycle_assert(
    is_string($automationRunner)
        && strpos($automationRunner, 'sakazukiPhpMemoryLimitBytes') !== false
        && strpos($automationRunner, 'sakazuki_direct_partial_jobs') !== false,
    'runner fatal diagnostics should preserve partial jobs and memory information'
);

$functionsSource = file_get_contents(__DIR__ . '/../public_html/includes/functions.php');
$preflightNeedle = '$memoryPlan = sakazukiProductImageOptimizationMemoryPlan($width, $height, max(0, $size), 1024);';
$readNeedle = '$bytes = @file_get_contents($source);';
$preflightPos = is_string($functionsSource) ? strpos($functionsSource, $preflightNeedle) : false;
$readPos = is_string($functionsSource) ? strpos($functionsSource, $readNeedle) : false;
image_lifecycle_assert(
    $preflightPos !== false && $readPos !== false && $preflightPos < $readPos,
    'legacy optimizer must perform memory preflight before reading compressed image bytes'
);

$automationSource = file_get_contents(__DIR__ . '/../public_html/includes/automation.php');
image_lifecycle_assert(
    is_string($automationSource)
        && strpos($automationSource, "'interval' => 300") !== false
        && strpos($automationSource, 'sakazukiOptimizeLegacyProductImages(1)') !== false,
    'automation should process at most one legacy image per optimizer run'
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
