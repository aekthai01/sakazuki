<?php
require_once __DIR__ . '/includes/auth.php';
$currentLang = getAppLang();
$langAssetVersion = (int) (@filemtime(__DIR__ . '/assets/js/lang.js') ?: 1);

// Redirect users who already have an active session.
if (isLoggedIn()) {
    redirectByRole();
}

$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    requireCsrfToken('register.php');

    // Public registration is rate-limited per client IP. Only the normal user
    // role can be created from this page; reseller/admin accounts remain under
    // administrator control.
    if (!checkRateLimit('public_registration', 5, 3600)) {
        $remaining = getRateLimitReset('public_registration', 3600);
        $error = Lang::t('register.error.rate_limit', ['minutes' => max(1, (int) ceil($remaining / 60))]);
    } else {
        $username = isset($_POST['username']) && is_scalar($_POST['username'])
            ? trim((string) $_POST['username']) : '';
        $email = isset($_POST['email']) && is_scalar($_POST['email'])
            ? trim((string) $_POST['email']) : '';
        $password = isset($_POST['password']) && is_string($_POST['password'])
            ? $_POST['password'] : '';
        $confirmPassword = isset($_POST['confirm_password']) && is_string($_POST['confirm_password'])
            ? $_POST['confirm_password'] : '';

        if (!hash_equals($password, $confirmPassword)) {
            $error = Lang::t('register.error.mismatch');
        } else {
            $result = register($username, $email, $password, 'user');
            if (!empty($result['success'])) {
                $loginResult = login($username, $password);
                if (!empty($loginResult['success'])) {
                    redirectByRole();
                }
                // The account exists even if session creation unexpectedly
                // fails, so send the customer to login rather than inserting a
                // duplicate account on a second submission.
                header('Location: login.php?registered=1', true, 302);
                exit();
            }
            $error = (string) ($result['message'] ?? Lang::t('register.error.failed'));
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo getAppLang(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title data-lang="register.title"><?php echo Lang::t('register.title'); ?> - <?php echo htmlspecialchars(getStoreBranding()['title_text']); ?></title>
    <link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>:root{--sakazuki-accent:#1e40af;--sakazuki-accent-rgb:30 64 175;--sakazuki-accent2:#1e3a8a;--sakazuki-accent2-rgb:30 58 138;--sakazuki-accent-light:#3b82f6;--sakazuki-accent-light-rgb:59 130 246;--sakazuki-glow:0 0 15px rgba(30, 64, 175, 0.25)}</style>
    <style>
        html {
            touch-action: auto;
            -webkit-text-size-adjust: 100%;
            -ms-text-size-adjust: 100%;
            text-size-adjust: 100%;
        }
        
        body {
            overflow-x: hidden;
            -webkit-tap-highlight-color: transparent;
            -webkit-touch-callout: default;
        }
        
        input, select, button, textarea {
            font-size: 16px !important; /* Prevents unwanted focus zoom on mobile Safari */
        }
        input, select, textarea {
            -webkit-user-select: text;
            user-select: text;
            -webkit-touch-callout: default;
        }
        
        .glass {
            background: rgba(255, 255, 255, 0.03);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.05);
        }
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        .animate-fade-in {
            animation: fadeInUp .15s ease-out;
        }
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
        }
        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        @keyframes glow {
            0%, 100% { box-shadow: 0 0 10px rgba(30, 64, 175, 0.3); }
            50% { box-shadow: 0 0 20px rgba(30, 64, 175, 0.5), 0 0 30px rgba(30, 64, 175, 0.2); }
        }
        @keyframes shimmer {
            0% { background-position: -1000px 0; }
            100% { background-position: 1000px 0; }
        }
        .floating-icon {
            animation: float 3s ease-in-out infinite;
        }
        .rotating-bg {
            animation: rotate 20s linear infinite;
        }
        .glow-effect {
            animation: glow 2s ease-in-out infinite;
        }
        .shimmer-bg {
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.05), transparent);
            background-size: 200% 100%;
            animation: shimmer 3s infinite;
        }
        .gradient-border {
            position: relative;
            background: linear-gradient(135deg, rgba(30, 64, 175, 0.1), rgba(30, 58, 138, 0.1));
            border-radius: 16px;
            padding: 2px;
        }
        .gradient-border::before {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: 16px;
            padding: 2px;
            background: linear-gradient(135deg, #1e40af, #1e3a8a, #1e40af);
            -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
            -webkit-mask-composite: xor;
            mask-composite: exclude;
            animation: rotate 3s linear infinite;
        }
        .particle {
            position: absolute;
            width: 3px;
            height: 3px;
            background: rgba(30, 64, 175, 0.4);
            border-radius: 50%;
            animation: float-particle 15s infinite;
        }
        @keyframes float-particle {
            0% {
                transform: translateY(100vh) translateX(0) rotate(0deg);
                opacity: 0;
            }
            10% {
                opacity: 1;
            }
            90% {
                opacity: 1;
            }
            100% {
                transform: translateY(-100px) translateX(100px) rotate(360deg);
                opacity: 0;
            }
        }
        .input-focus-effect {
            transition: all .15s ease;
        }
        .input-focus-effect:focus {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(30, 64, 175, 0.2);
        }
        .btn-hover-effect {
            position: relative;
            overflow: hidden;
        }
        .btn-hover-effect::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
            transform: translate(-50%, -50%);
            transition: width .15s, height .15s;
        }
        .btn-hover-effect:hover::before {
            width: 300px;
            height: 300px;
        }
        .bg-animated {
            background: linear-gradient(-45deg, #0a0a0a, #121212, #0d0d10, #0a0a0a);
            background-size: 400% 400%;
            animation: gradient-shift 15s ease infinite;
        }
        @keyframes gradient-shift {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
            20%, 40%, 60%, 80% { transform: translateX(5px); }
        }
        /* Splash Screen Styles */
        #splashScreen {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, #0a0a0a 0%, #121212 50%, #0d0d10 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            transition: opacity .15s ease-out;
        }
        #splashScreen.fade-out {
            opacity: 0;
            pointer-events: none;
        }
        .splash-logo {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: linear-gradient(135deg, #1e40af, #1e3a8a);
            display: flex;
            align-items: center;
            justify-content: center;
            animation: splash-pulse 1.5s ease-in-out infinite, splash-rotate 3s linear infinite;
            box-shadow: 0 0 30px rgba(30, 64, 175, 0.4);
        }
        .splash-logo i {
            font-size: 3rem;
            color: white;
            animation: splash-icon 2s ease-in-out infinite;
        }
        @keyframes splash-pulse {
            0%, 100% { 
                transform: scale(1);
                box-shadow: 0 0 30px rgba(30, 64, 175, 0.4);
            }
            50% { 
                transform: scale(1.1);
                box-shadow: 0 0 50px rgba(30, 64, 175, 0.6);
            }
        }
        @keyframes splash-rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        @keyframes splash-icon {
            0%, 100% { transform: scale(1) rotate(0deg); }
            25% { transform: scale(1.1) rotate(-10deg); }
            75% { transform: scale(1.1) rotate(10deg); }
        }
        .splash-text {
            margin-top: 1.5rem;
            font-size: 1.2rem;
            font-weight: bold;
            background: linear-gradient(135deg, #1e40af, #1e3a8a);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            animation: text-shimmer 2s ease-in-out infinite;
        }
        @keyframes text-shimmer {
            0%, 100% { opacity: 0.8; }
            50% { opacity: 1; }
        }
        .splash-loader {
            margin-top: 1.5rem;
            width: 150px;
            height: 3px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 2px;
            overflow: hidden;
        }
        .splash-loader-bar {
            height: 100%;
            background: linear-gradient(90deg, #1e40af, #1e3a8a);
            border-radius: 2px;
            animation: loader-progress 2s ease-in-out;
            width: 0%;
        }
        @keyframes loader-progress {
            0% { width: 0%; }
            100% { width: 100%; }
        }
    </style>
    <script defer src="assets/js/security.js?v=3.4"></script>
</head>
<body class="bg-gray-900 text-gray-100 min-h-screen flex items-center justify-center relative overflow-hidden">
    <!-- Splash Screen -->
    <div id="splashScreen">
        <div class="text-center">
            <div class="splash-logo mx-auto">
                <i class="bi bi-person-plus-fill"></i>
            </div>
            <div class="splash-text"><?php echo htmlspecialchars(getStoreBranding()['title_text']); ?></div>
            <div class="splash-loader">
                <div class="splash-loader-bar"></div>
            </div>
        </div>
    </div>

    <!-- Animated Background Particles -->
    <div class="absolute inset-0 overflow-hidden pointer-events-none">
        <div class="particle" style="left: 10%; animation-delay: 0s;"></div>
        <div class="particle" style="left: 20%; animation-delay: 2s;"></div>
        <div class="particle" style="left: 30%; animation-delay: 4s;"></div>
        <div class="particle" style="left: 40%; animation-delay: 1s;"></div>
        <div class="particle" style="left: 50%; animation-delay: 3s;"></div>
        <div class="particle" style="left: 60%; animation-delay: 5s;"></div>
        <div class="particle" style="left: 70%; animation-delay: 2.5s;"></div>
        <div class="particle" style="left: 80%; animation-delay: 4.5s;"></div>
        <div class="particle" style="left: 90%; animation-delay: 1.5s;"></div>
    </div>

    <!-- Rotating Gradient Orbs -->
    <div class="absolute top-0 left-0 w-64 h-64 bg-accent/5 rounded-full blur-3xl rotating-bg opacity-30"></div>
    <div class="absolute bottom-0 right-0 w-64 h-64 bg-accent2/5 rounded-full blur-3xl rotating-bg opacity-30" style="animation-duration: 15s; animation-direction: reverse;"></div>

    <div class="container mx-auto px-4 relative z-10">
        <div class="max-w-sm w-full mx-auto">
            <div class="glass rounded-xl p-5 animate-fade-in relative overflow-hidden">
                <!-- Animated Border Effect -->
                <div class="absolute inset-0 rounded-xl opacity-30">
                    <div class="absolute inset-0 rounded-xl" style="background: linear-gradient(135deg, #1e40af, #1e3a8a, #1e40af); background-size: 200% 200%; animation: gradient-shift 3s ease infinite;"></div>
                </div>
                
                <div class="relative z-10">
                    <div class="text-center mb-3">
                        <h3 class="text-xl font-bold text-white mb-1 bg-gradient-to-r from-accent to-accent2 bg-clip-text text-transparent" data-lang="register.title"><?php echo Lang::t('register.title'); ?></h3>
                        <p class="text-gray-500 text-xs" data-lang="register.desc"><?php echo Lang::t('register.desc'); ?></p>
                    </div>
                
                    <?php if ($error): ?>
                        <div class="glass border border-red-800/50 p-2 rounded-lg bg-red-900/10 text-red-400 mb-3 text-xs" style="animation: shake .15s ease-out;">
                            <i class="bi bi-exclamation-triangle-fill mr-1"></i><?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST" autocomplete="on" class="space-y-3">
                        <?php echo csrfField(); ?>
                        <div class="group">
                            <label for="username" class="block text-gray-500 text-xs mb-1 transition-colors group-focus-within:text-accentLight" data-lang="login.username">
                                <i class="bi bi-person mr-1"></i><?php echo Lang::t('login.username'); ?>
                            </label>
                            <div class="relative">
                                <input type="text" 
                                       class="glass border border-white/5 rounded-lg p-2.5 w-full bg-transparent text-white placeholder-gray-600 focus:outline-none focus:border-accent focus:ring-1 focus:ring-accent/20 input-focus-effect pl-9 text-sm" 
                                       name="username"
                                       id="username"
                                       autocomplete="username"
                                       autocapitalize="none"
                                       spellcheck="false"
                                       minlength="3"
                                       maxlength="60"
                                       pattern="[A-Za-z0-9_.-]{3,60}"
                                       value="<?php echo htmlspecialchars(isset($username) ? $username : '', ENT_QUOTES, 'UTF-8'); ?>"
                                       placeholder="Enter username" 
                                       data-lang-placeholder="login.placeholder.username"
                                       required 
                                       autofocus>
                                <i class="bi bi-person absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-600 group-focus-within:text-accentLight transition-colors text-xs"></i>
                            </div>
                        </div>
                        
                        <div class="group">
                            <label for="email" class="block text-gray-500 text-xs mb-1 transition-colors group-focus-within:text-accentLight" data-lang="register.email">
                                <i class="bi bi-envelope-fill mr-1"></i><?php echo Lang::t('register.email'); ?>
                            </label>
                            <div class="relative">
                                <input type="email" 
                                       class="glass border border-white/5 rounded-lg p-2.5 w-full bg-transparent text-white placeholder-gray-600 focus:outline-none focus:border-accent focus:ring-1 focus:ring-accent/20 input-focus-effect pl-9 text-sm" 
                                       name="email"
                                       id="email"
                                       autocomplete="email"
                                       inputmode="email"
                                       autocapitalize="none"
                                       maxlength="190"
                                       pattern="^[^@\s]+@gmail\.com$"
                                       value="<?php echo htmlspecialchars(isset($email) ? $email : '', ENT_QUOTES, 'UTF-8'); ?>"
                                       placeholder="example@gmail.com" 
                                       data-lang-placeholder="register.placeholder.email"
                                       required>
                                <i class="bi bi-envelope-fill absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-600 group-focus-within:text-accentLight transition-colors text-xs"></i>
                            </div>
                            <p class="mt-1 text-[10px] text-gray-600"><?php echo getAppLang() === 'en' ? 'Gmail only. This address is used for password recovery.' : 'รองรับเฉพาะ Gmail และใช้สำหรับรีเซ็ตรหัสผ่าน'; ?></p>
                        </div>
                        
                        <div class="group">
                            <label for="password" class="block text-gray-500 text-xs mb-1 transition-colors group-focus-within:text-accentLight" data-lang="login.password">
                                <i class="bi bi-lock-fill mr-1"></i><?php echo Lang::t('login.password'); ?>
                            </label>
                            <div class="relative">
                                <input type="password" 
                                       class="glass border border-white/5 rounded-lg p-2.5 w-full bg-transparent text-white placeholder-gray-600 focus:outline-none focus:border-accent focus:ring-1 focus:ring-accent/20 input-focus-effect pl-9 text-sm" 
                                       name="password"
                                       id="password"
                                       minlength="8"
                                       maxlength="4096"
                                       autocomplete="new-password"
                                       placeholder="Enter password" 
                                       data-lang-placeholder="login.placeholder.password"
                                       required>
                                <i class="bi bi-lock-fill absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-600 group-focus-within:text-accentLight transition-colors text-xs"></i>
                            </div>
                        </div>
                        
                        <div class="group">
                            <label for="confirm_password" class="block text-gray-500 text-xs mb-1 transition-colors group-focus-within:text-accentLight" data-lang="register.confirm_password">
                                <i class="bi bi-lock-fill mr-1"></i><?php echo Lang::t('register.confirm_password'); ?>
                            </label>
                            <div class="relative">
                                <input type="password" 
                                       class="glass border border-white/5 rounded-lg p-2.5 w-full bg-transparent text-white placeholder-gray-600 focus:outline-none focus:border-accent focus:ring-1 focus:ring-accent/20 input-focus-effect pl-9 text-sm" 
                                       name="confirm_password"
                                       id="confirm_password"
                                       minlength="8"
                                       maxlength="4096"
                                       autocomplete="new-password"
                                       placeholder="Confirm password" 
                                       data-lang-placeholder="register.placeholder.confirm"
                                       required>
                                <i class="bi bi-shield-check absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-600 group-focus-within:text-accentLight transition-colors text-xs"></i>
                            </div>
                        </div>
                        
                        <button type="submit" class="w-full bg-gradient-to-r from-accent to-accent2 hover:from-accentLight hover:to-accent text-white py-2.5 rounded-lg font-medium transition-all duration-150 btn-hover-effect relative overflow-hidden group mb-2 text-sm">
                            <span class="relative z-10 flex items-center justify-center" data-lang="register.btn">
                                <i class="bi bi-person-plus mr-1.5 group-hover:translate-x-1 transition-transform"></i><?php echo Lang::t('register.btn'); ?>
                            </span>
                        </button>

                        <div class="text-center mt-3">
                            <p class="text-xs text-gray-400">
                                <span data-lang="register.have_account"><?php echo Lang::t('register.have_account'); ?></span>
                                <a href="login.php" class="text-accent hover:text-accentLight transition-colors font-medium ml-1" data-lang="login.btn">
                                    <?php echo Lang::t('login.btn'); ?>
                                </a>
                            </p>
                        </div>
                    </form>
                    
                    <div class="text-center pt-2">
                        <a href="toggle_lang.php?lang=<?php echo $currentLang === 'en' ? 'th' : 'en'; ?>&amp;return=register.php"
                           class="text-gray-600 hover:text-gray-400 text-[10px] uppercase tracking-widest transition-colors flex items-center justify-center mx-auto opacity-50 hover:opacity-100">
                            <i class="bi bi-translate mr-1.5"></i>
                            <span id="langText"><?php echo $currentLang === 'en' ? 'ENGLISH' : 'ภาษาไทย'; ?></span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Registration intentionally omits client-side copy blocking so password
         managers, long-press paste, and mobile accessibility controls work. -->
    <!-- Localization Script -->
    <script>
        window.PHP_LANG = <?php echo json_encode($currentLang); ?>;
    </script>
    <script src="assets/js/lang.js?v=<?php echo $langAssetVersion; ?>"></script>
    <script>
        Lang.init();
    </script>

    <script>
        // Splash Screen Animation
        window.addEventListener('load', function() {
            const splashScreen = document.getElementById('splashScreen');
            setTimeout(function() {
                splashScreen.classList.add('fade-out');
                setTimeout(function() {
                    splashScreen.style.display = 'none';
                }, 500);
            }, 250);
        });
        
    </script>
</body>
</html>