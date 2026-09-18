<?php
require_once '../includes/auth.php';
requireAdmin();

$error = '';
$success = '';
$currentLang = getAppLang();

$t = static function ($th, $en) use ($currentLang) {
    return $currentLang === 'en' ? $en : $th;
};

$escape = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

$normalizeLabel = static function ($value) {
    if (!is_scalar($value)) {
        return null;
    }

    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    if (preg_match('/[\x00-\x1F\x7F]/', $value)) {
        return null;
    }

    $length = function_exists('mb_strlen')
        ? mb_strlen($value, 'UTF-8')
        : strlen($value);

    return $length <= 255 ? $value : null;
};

$containsThai = static function ($value) {
    return is_scalar($value)
        && preg_match('/[\x{0E00}-\x{0E7F}]/u', (string) $value) === 1;
};

// Pull the canonical category keys from products. These values remain unchanged
// because they are also used by filters, product links and old records.
$products = getProducts('all');
$inUseCategories = [];
foreach ($products as $product) {
    $productCategories = $product['categories'] ?? [];
    if (empty($productCategories) && !empty($product['category'])) {
        $productCategories = [$product['category']];
    }

    foreach ((array) $productCategories as $categoryName) {
        $categoryName = trim((string) $categoryName);
        if ($categoryName !== '' && !in_array($categoryName, $inUseCategories, true)) {
            $inUseCategories[] = $categoryName;
        }
    }
}
sort($inUseCategories, SORT_NATURAL | SORT_FLAG_CASE);

// Store translations in the existing settings table. No ALTER TABLE runs when
// a customer opens buy.php, so a translation problem cannot take down the shop.
$categoryTranslations = [];
$translationJson = getSetting('category_translations_v1', '');
$previousTranslationJson = is_string($translationJson) ? $translationJson : '';
if (is_string($translationJson) && trim($translationJson) !== '') {
    $decodedTranslations = json_decode($translationJson, true);
    if (is_array($decodedTranslations)) {
        $categoryTranslations = $decodedTranslations;
    }
}

// Current category download links use the existing categories table exactly as
// before. This page does not add or require any new database columns.
$links = [];
foreach (getAllCategoriesWithLinks() as $link) {
    $name = trim((string) ($link['name'] ?? ''));
    if ($name !== '') {
        $links[$name] = trim((string) ($link['download_url'] ?? ''));
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    requireCsrfToken();

    $action = isset($_POST['action']) && is_scalar($_POST['action'])
        ? (string) $_POST['action']
        : '';
    $categoryName = isset($_POST['category_name']) && is_scalar($_POST['category_name'])
        ? trim((string) $_POST['category_name'])
        : '';
    $nameTh = $normalizeLabel($_POST['name_th'] ?? '');
    $nameEn = $normalizeLabel($_POST['name_en'] ?? '');
    $downloadUrl = isset($_POST['download_url']) && is_scalar($_POST['download_url'])
        ? trim((string) $_POST['download_url'])
        : '';

    if ($action !== 'update_category' || !in_array($categoryName, $inUseCategories, true)) {
        $error = $t('คำขอไม่ถูกต้อง', 'Invalid request.');
    } elseif ($nameTh === null || $nameEn === null) {
        $error = $t(
            'ชื่อหมวดหมู่ต้องยาวไม่เกิน 255 ตัวอักษร และห้ามมีอักขระควบคุม',
            'Category names must be no more than 255 characters and contain no control characters.'
        );
    } elseif ($nameTh === '' && $nameEn === '') {
        $error = $t(
            'กรุณากรอกชื่อภาษาไทยหรือภาษาอังกฤษอย่างน้อยหนึ่งช่อง',
            'Enter at least one Thai or English display name.'
        );
    } elseif ($downloadUrl !== '' && (strlen($downloadUrl) > 2048 || !isSafeHttpsUrl($downloadUrl))) {
        $error = Lang::t('admin.categories.error.invalid_url');
    } else {
        $updatedTranslations = $categoryTranslations;
        $updatedTranslations[$categoryName] = [
            'th' => $nameTh,
            'en' => $nameEn,
        ];

        $encodedTranslations = json_encode(
            $updatedTranslations,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if (!is_string($encodedTranslations) || strlen($encodedTranslations) > 60000) {
            $error = $t(
                'ข้อมูลภาษาหมวดหมู่มีขนาดใหญ่เกินกว่าที่ระบบตั้งค่าจะเก็บได้',
                'Category translation data is too large for the settings storage.'
            );
        } else {
            $translationSaved = updateSetting('category_translations_v1', $encodedTranslations);
            $urlSaved = $translationSaved && setCategoryDownloadUrl($categoryName, $downloadUrl);

            if ($translationSaved && !$urlSaved) {
                // Keep the old settings value when the existing category URL
                // storage fails, so the page never reports a half-saved row.
                updateSetting('category_translations_v1', $previousTranslationJson);
            }

            if ($translationSaved && $urlSaved) {
                $categoryTranslations = $updatedTranslations;
                $translationJson = $encodedTranslations;
                $previousTranslationJson = $encodedTranslations;
                $links[$categoryName] = $downloadUrl;
                $success = $t(
                    'บันทึกชื่อภาษาไทย ภาษาอังกฤษ และลิงก์ของหมวดหมู่เรียบร้อยแล้ว',
                    'Thai name, English name, and category link were saved.'
                );
                logHistory(
                    (int) $_SESSION['user_id'],
                    'admin_update_category_language',
                    'Updated category display names and download URL: ' . $categoryName
                );
            } else {
                $error = $t(
                    'บันทึกข้อมูลหมวดหมู่ไม่สำเร็จ กรุณาตรวจสอบสิทธิ์ฐานข้อมูลและ error log',
                    'Unable to save the category. Check database permissions and the error log.'
                );
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo $escape($currentLang); ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $escape($t('จัดการภาษาหมวดหมู่', 'Manage Category Languages')); ?></title>
    <link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>

<body class="bg-gray-900 text-gray-100 min-h-screen">
    <?php include 'nav.php'; ?>

    <main class="p-4 md:p-6 max-w-7xl mx-auto">
        <div class="mb-6">
            <h1 class="text-2xl font-bold flex items-center gap-2">
                <i class="bi bi-translate text-blue-400"></i>
                <span><?php echo $escape($t('จัดการภาษาหมวดหมู่', 'Category Language Management')); ?></span>
            </h1>
            <p class="text-sm text-gray-400 mt-2">
                <?php echo $escape($t(
                    'ชื่อเดิมยังใช้เป็นรหัสภายใน ระบบจะเปลี่ยนเฉพาะข้อความที่แสดงในหน้าซื้อสินค้า',
                    'The original category value remains the internal key. Only the storefront display text changes.'
                )); ?>
            </p>
        </div>

        <?php if ($error !== ''): ?>
            <div class="bg-red-900/20 border border-red-500/50 text-red-300 p-4 rounded-lg mb-6">
                <i class="bi bi-exclamation-triangle mr-2"></i><?php echo $escape($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="bg-green-900/20 border border-green-500/50 text-green-300 p-4 rounded-lg mb-6">
                <i class="bi bi-check-circle mr-2"></i><?php echo $escape($success); ?>
            </div>
        <?php endif; ?>

        <div class="mb-6 p-4 bg-amber-900/10 border border-amber-500/20 rounded-lg text-amber-100">
            <div class="flex items-start gap-2">
                <i class="bi bi-info-circle mt-0.5 text-amber-300"></i>
                <p class="text-sm leading-relaxed">
                    <?php echo $escape($t(
                        'ระบบไม่เดาคำแปลเอง กรุณากรอกชื่ออังกฤษของหมวดหมู่ภาษาไทย และชื่อไทยของหมวดหมู่ภาษาอังกฤษตามข้อมูลจริง',
                        'Translations are not guessed. Enter the real English name for Thai categories and the real Thai name for English categories.'
                    )); ?>
                </p>
            </div>
        </div>

        <div class="bg-gray-800 rounded-xl border border-white/10 overflow-hidden">
            <div class="p-4 md:p-6">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[1120px] text-left">
                        <thead>
                            <tr class="border-b border-white/10 text-gray-400 text-sm">
                                <th class="pb-3 pr-4 font-medium"><?php echo $escape($t('ชื่อเดิมในระบบ', 'Internal Category Key')); ?></th>
                                <th class="pb-3 pr-4 font-medium"><?php echo $escape($t('ชื่อแสดงภาษาไทย', 'Thai Display Name')); ?></th>
                                <th class="pb-3 pr-4 font-medium"><?php echo $escape($t('ชื่อแสดงภาษาอังกฤษ', 'English Display Name')); ?></th>
                                <th class="pb-3 pr-4 font-medium"><?php echo $escape($t('ลิงก์ดาวน์โหลด', 'Download URL')); ?></th>
                                <th class="pb-3 font-medium text-right"><?php echo $escape(Lang::t('common.action')); ?></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            <?php if (empty($inUseCategories)): ?>
                                <tr>
                                    <td colspan="5" class="py-10 text-center text-gray-500">
                                        <?php echo $escape($t('ไม่พบหมวดหมู่ที่ใช้งานอยู่', 'No categories are currently in use.')); ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($inUseCategories as $categoryName): ?>
                                    <?php
                                    $savedRow = $categoryTranslations[$categoryName] ?? [];
                                    $savedTh = is_array($savedRow) && is_scalar($savedRow['th'] ?? null)
                                        ? trim((string) $savedRow['th'])
                                        : '';
                                    $savedEn = is_array($savedRow) && is_scalar($savedRow['en'] ?? null)
                                        ? trim((string) $savedRow['en'])
                                        : '';

                                    if ($savedTh === '' && $savedEn === '') {
                                        if ($containsThai($categoryName)) {
                                            $savedTh = $categoryName;
                                        } else {
                                            $savedEn = $categoryName;
                                        }
                                    }

                                    $formId = 'category_' . md5($categoryName);
                                    ?>
                                    <tr class="align-top">
                                        <td class="py-4 pr-4">
                                            <span class="inline-block max-w-[250px] break-words px-2 py-1 bg-blue-500/10 text-blue-300 rounded-md text-sm font-medium">
                                                <?php echo $escape($categoryName); ?>
                                            </span>
                                        </td>
                                        <td class="py-4 pr-4">
                                            <form action="" method="POST" id="<?php echo $escape($formId); ?>" class="contents">
                                                <?php echo csrfField(); ?>
                                                <input type="hidden" name="action" value="update_category">
                                                <input type="hidden" name="category_name" value="<?php echo $escape($categoryName); ?>">
                                                <input type="text" name="name_th" maxlength="255"
                                                    value="<?php echo $escape($savedTh); ?>"
                                                    placeholder="ชื่อหมวดหมู่ภาษาไทย"
                                                    class="bg-gray-900 border border-white/10 rounded px-3 py-2 text-sm w-full focus:outline-none focus:border-blue-500 transition">
                                            </form>
                                        </td>
                                        <td class="py-4 pr-4">
                                            <input type="text" name="name_en" form="<?php echo $escape($formId); ?>" maxlength="255"
                                                value="<?php echo $escape($savedEn); ?>"
                                                placeholder="English category name"
                                                class="bg-gray-900 border border-white/10 rounded px-3 py-2 text-sm w-full focus:outline-none focus:border-blue-500 transition">
                                        </td>
                                        <td class="py-4 pr-4">
                                            <input type="url" name="download_url" form="<?php echo $escape($formId); ?>" maxlength="2048"
                                                value="<?php echo $escape($links[$categoryName] ?? ''); ?>"
                                                placeholder="https://example.com/download"
                                                class="bg-gray-900 border border-white/10 rounded px-3 py-2 text-sm w-full focus:outline-none focus:border-blue-500 transition">
                                        </td>
                                        <td class="py-4 text-right">
                                            <button type="submit" form="<?php echo $escape($formId); ?>"
                                                class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded text-sm transition font-medium">
                                                <?php echo $escape(Lang::t('common.save')); ?>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
</body>

</html>
