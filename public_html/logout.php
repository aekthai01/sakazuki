<?php
require_once __DIR__ . '/includes/auth.php';
$currentLang = getAppLang();
$text = $currentLang === 'en'
    ? [
        'title' => 'Sign out',
        'message' => 'Do you want to sign out from this device?',
        'confirm' => 'Sign out',
        'cancel' => 'Cancel',
    ]
    : [
        'title' => 'ออกจากระบบ',
        'message' => 'ต้องการออกจากระบบบนอุปกรณ์นี้ใช่หรือไม่?',
        'confirm' => 'ออกจากระบบ',
        'cancel' => 'ยกเลิก',
    ];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    requireCsrfToken();
    logout(false);
}

if (!isLoggedIn()) {
    authRedirect('login.php');
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($currentLang, ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($text['title'], ENT_QUOTES, 'UTF-8'); ?></title>
    <style>
        :root { color-scheme: dark; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 20px; background: #0d0d10; color: #f3f4f6; font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        .card { width: min(100%, 390px); padding: 24px; border: 1px solid rgba(255,255,255,.1); border-radius: 16px; background: rgba(255,255,255,.05); box-shadow: 0 20px 70px rgba(0,0,0,.35); }
        h1 { margin: 0 0 8px; font-size: 22px; }
        p { margin: 0 0 22px; color: #9ca3af; line-height: 1.55; }
        .actions { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        button, a { min-height: 46px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; font: inherit; font-weight: 650; text-decoration: none; cursor: pointer; }
        button { border: 0; background: #2563eb; color: white; }
        button:hover { background: #1d4ed8; }
        a { border: 1px solid rgba(255,255,255,.12); color: #d1d5db; background: rgba(255,255,255,.04); }
        a:hover { background: rgba(255,255,255,.08); }
    </style>
</head>
<body>
    <main class="card">
        <h1><?php echo htmlspecialchars($text['title'], ENT_QUOTES, 'UTF-8'); ?></h1>
        <p><?php echo htmlspecialchars($text['message'], ENT_QUOTES, 'UTF-8'); ?></p>
        <div class="actions">
            <a href="<?php echo htmlspecialchars(authAppUrl('index.php'), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($text['cancel'], ENT_QUOTES, 'UTF-8'); ?></a>
            <form method="POST" style="margin:0">
                <?php echo csrfField(); ?>
                <button type="submit" style="width:100%"><?php echo htmlspecialchars($text['confirm'], ENT_QUOTES, 'UTF-8'); ?></button>
            </form>
        </div>
    </main>
</body>
</html>
