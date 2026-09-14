<?php
require_once '../includes/auth.php';
requireLogin();

// The reseller application page is intentionally visible to normal users only.
// Admins already manage reseller accounts and resellers already have their own panel.
if (!isUser()) {
    authRedirect('index.php');
}

$lang = getAppLang() === 'en' ? 'en' : 'th';
$tr = static function (string $th, string $en) use ($lang): string {
    return $lang === 'en' ? $en : $th;
};

$telegramAdmins = [
    ['label' => 'Admin 1', 'handle' => '@DrkZeref', 'url' => 'https://t.me/DrkZeref'],
    ['label' => 'Admin 2', 'handle' => '@om_shop99', 'url' => 'https://t.me/om_shop99'],
];
$currentUsername = trim((string) ($_SESSION['username'] ?? ''));

$applyTemplate = $lang === 'en'
    ? "Hello, I would like to apply as a BLACKUP reseller.\n"
        . "• Current BLACKUP username: " . ($currentUsername !== '' ? $currentUsername : '___') . "\n"
        . "• Store / channel name: ___\n"
        . "• Sales channel: (page/group/store link)\n"
        . "• Followers / members: ___\n"
        . "• Products or product categories currently sold: ___\n"
        . "• Approximate selling experience: ___"
    : "สวัสดีครับ สนใจสมัครตัวแทน BLACKUP\n"
        . "• ชื่อผู้ใช้ BLACKUP ปัจจุบัน: " . ($currentUsername !== '' ? $currentUsername : '___') . "\n"
        . "• ชื่อร้าน/ช่องทาง: ___\n"
        . "• ช่องทางขาย: (วางลิงก์เพจ/กลุ่ม/ร้าน)\n"
        . "• ผู้ติดตาม: ___ คน\n"
        . "• สินค้าหรือประเภทสินค้าที่ขายอยู่: ___\n"
        . "• เคยขายมาแล้วประมาณ: ___";
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($lang, ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="color-scheme" content="dark">
    <meta name="theme-color" content="#0b0f17">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title><?php echo htmlspecialchars($tr('สมัครตัวแทนจำหน่าย | BLACKUP', 'Become a Reseller | BLACKUP'), ENT_QUOTES, 'UTF-8'); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($tr(
        'สมัครตัวแทนจำหน่าย BLACKUP สำหรับผู้ที่มีช่องทางขายอยู่แล้ว เครดิตเปิดบัญชีขั้นต่ำ 500 บาทและใช้ซื้อสินค้าได้เต็มจำนวน',
        'Apply to become a BLACKUP reseller if you already have an active sales channel. Opening credit starts at 500 THB and remains usable for purchases.'
    ), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        :root{
            --rp-bg:#0b0f17;
            --rp-surface:#111725;
            --rp-panel:#111827;
            --rp-text:#e8edf7;
            --rp-muted:#93a0b8;
            --rp-muted-2:#77849a;
            --rp-line:#1e2636;
            --rp-blue:#2563eb;
            --rp-blue-2:#1d4ed8;
            --rp-blue-soft:#7aa7ff;
            --rp-violet:#8b5cf6;
            --rp-green:#34d399;
            --rp-amber:#fbbf24;
            --rp-shadow:0 24px 60px rgba(0,0,0,.34);
            --rp-radius-lg:24px;
        }

        .rp-skip{
            position:fixed;left:14px;top:12px;z-index:9999;transform:translateY(-160%);
            padding:10px 13px;border-radius:10px;background:#fff;color:#0b0f17;font-size:13px;font-weight:700;
            transition:transform .15s ease;
        }
        .rp-skip:focus{transform:none}

        .reseller-program{
            position:relative;
            min-height:calc(100vh - 72px);
            overflow:hidden;
            color:var(--rp-text);
            font-family:"IBM Plex Sans Thai","Noto Sans Thai","Segoe UI",Tahoma,sans-serif;
            background:
                radial-gradient(circle at 20% -8%,rgba(37,99,235,.22),transparent 34rem),
                radial-gradient(circle at 96% 22%,rgba(139,92,246,.11),transparent 26rem),
                linear-gradient(180deg,#0a0e15 0%,#0b0f17 48%,#090d14 100%);
            -webkit-font-smoothing:antialiased;
        }
        .reseller-program::before{
            content:"";position:absolute;inset:0;pointer-events:none;opacity:.22;
            background-image:
                linear-gradient(rgba(255,255,255,.022) 1px,transparent 1px),
                linear-gradient(90deg,rgba(255,255,255,.022) 1px,transparent 1px);
            background-size:44px 44px;
            mask-image:linear-gradient(to bottom,#000,transparent 86%);
        }
        .reseller-program > *{position:relative;z-index:1}
        .rp-shell{width:min(1140px,calc(100% - 34px));margin-inline:auto}
        .rp-section[id]{scroll-margin-top:96px}

        .rp-hero{padding:72px 0 38px}
        .rp-hero-grid{
            display:grid;grid-template-columns:minmax(0,1.15fr) minmax(320px,.85fr);
            gap:34px;align-items:center;
        }
        .rp-hero-copy{max-width:720px}
        .rp-signal{
            display:inline-flex;align-items:center;gap:9px;margin-bottom:18px;
            color:#bfdbfe;font-size:12px;font-weight:600;
        }
        .rp-signal::before{content:"";width:28px;height:1px;background:linear-gradient(90deg,var(--rp-blue-soft),transparent)}
        .rp-hero h1{
            margin:0;max-width:780px;font-size:clamp(42px,6.2vw,78px);
            line-height:1.12;letter-spacing:0;font-weight:700;color:#f8fafc;
        }
        .rp-hero-lead{
            max-width:690px;margin:24px 0 0;color:#b9c5d6;
            font-size:clamp(15px,1.6vw,18px);line-height:1.8;
        }
        .rp-hero-actions{display:flex;flex-wrap:wrap;gap:11px;margin-top:30px}
        .rp-btn{
            border:0;cursor:pointer;min-height:48px;padding:0 18px;border-radius:12px;
            display:inline-flex;align-items:center;justify-content:center;gap:9px;
            font:700 14px/1 "IBM Plex Sans Thai",sans-serif;
            transition:transform .16s ease,border-color .16s ease,background .16s ease;
        }
        .rp-btn:hover{transform:translateY(-1px)}
        .rp-btn-primary{
            color:#fff;background:linear-gradient(135deg,#2b63db,var(--rp-blue-2));
            box-shadow:0 14px 30px rgba(37,99,235,.24),inset 0 1px 0 rgba(255,255,255,.15);
        }
        .rp-btn-secondary{color:#dbeafe;background:rgba(255,255,255,.035);border:1px solid var(--rp-line)}
        .rp-btn-secondary:hover{border-color:rgba(96,165,250,.34)}

        .rp-credit-stage{
            position:relative;min-height:430px;border-left:1px solid rgba(255,255,255,.08);
            padding:22px 0 22px 36px;display:flex;flex-direction:column;justify-content:center;
        }
        .rp-credit-stage::before{
            content:"";position:absolute;left:-1px;top:48px;width:1px;height:150px;
            background:linear-gradient(180deg,var(--rp-blue-soft),rgba(139,92,246,.55),transparent);
            box-shadow:0 0 16px rgba(96,165,250,.44);
        }
        .rp-credit-caption{color:var(--rp-muted);font-size:12px;line-height:1.5;margin-bottom:4px}
        .rp-credit-number{
            font-size:clamp(92px,12vw,156px);line-height:.82;letter-spacing:-.09em;
            font-weight:700;color:#f8fafc;text-shadow:0 20px 55px rgba(0,0,0,.28);
        }
        .rp-credit-unit{margin-top:8px;color:#93c5fd;font-size:23px;font-weight:700}
        .rp-credit-note{
            margin-top:22px;max-width:360px;padding:16px 0;border-top:1px solid var(--rp-line);
            color:#aebbd0;font-size:13px;line-height:1.72;
        }
        .rp-credit-note strong{color:#fff}

        .rp-section{padding:52px 0}
        .rp-section + .rp-section{border-top:1px solid rgba(255,255,255,.055)}
        .rp-section-head{
            display:grid;grid-template-columns:minmax(0,.72fr) minmax(0,1.28fr);
            gap:42px;margin-bottom:28px;align-items:end;
        }
        .rp-section-head h2{
            margin:0;font-size:clamp(28px,4vw,44px);line-height:1.15;
            letter-spacing:0;font-weight:700;color:#f8fafc;
        }
        .rp-section-head p{margin:0;color:var(--rp-muted);font-size:14px;line-height:1.78;max-width:660px}

        .rp-benefits{
            display:grid;grid-template-columns:repeat(4,minmax(0,1fr));
            border:1px solid var(--rp-line);border-radius:var(--rp-radius-lg);overflow:hidden;
            background:rgba(17,24,39,.56);box-shadow:var(--rp-shadow);
        }
        .rp-benefit{
            min-height:208px;padding:24px 22px 22px;border-right:1px solid var(--rp-line);
            background:linear-gradient(180deg,rgba(255,255,255,.025),transparent);
        }
        .rp-benefit:last-child{border-right:0}
        .rp-benefit-icon{
            width:40px;height:40px;margin-bottom:28px;border-radius:12px;display:grid;place-items:center;
            background:rgba(37,99,235,.10);border:1px solid rgba(96,165,250,.22);color:#93c5fd;font-size:18px;
        }
        .rp-benefit h3{margin:0 0 8px;font-size:16px;line-height:1.35;font-weight:700;color:#fff}
        .rp-benefit p{margin:0;color:var(--rp-muted);font-size:12.5px;line-height:1.7}

        .rp-requirements-wrap{
            display:grid;grid-template-columns:minmax(0,1.32fr) minmax(280px,.68fr);gap:22px;
        }
        .rp-requirements{
            border:1px solid var(--rp-line);border-radius:var(--rp-radius-lg);overflow:hidden;
            background:rgba(17,24,39,.58);box-shadow:var(--rp-shadow);
        }
        .rp-req-row{
            display:grid;grid-template-columns:50px 1fr auto;gap:17px;align-items:center;
            min-height:92px;padding:15px 20px;border-bottom:1px solid var(--rp-line);
        }
        .rp-req-row:last-child{border-bottom:0}
        .rp-req-icon{
            width:42px;height:42px;border-radius:13px;display:grid;place-items:center;
            color:#bfdbfe;background:#0d1728;border:1px solid rgba(96,165,250,.16);font-size:18px;
        }
        .rp-req-copy strong{display:block;font-size:14px;margin-bottom:3px;color:#fff}
        .rp-req-copy span{display:block;color:var(--rp-muted);font-size:12px;line-height:1.55}
        .rp-tag{
            padding:6px 9px;border-radius:999px;font-size:10px;font-weight:700;white-space:nowrap;
            border:1px solid rgba(52,211,153,.2);background:rgba(52,211,153,.08);color:#a7f3d0;
        }
        .rp-tag-blue{border-color:rgba(96,165,250,.2);background:rgba(37,99,235,.08);color:#bfdbfe}
        .rp-tag-amber{border-color:rgba(251,191,36,.2);background:rgba(251,191,36,.07);color:#fde68a}
        .rp-exception{
            border:1px solid rgba(139,92,246,.22);border-radius:var(--rp-radius-lg);padding:24px;
            background:radial-gradient(circle at 100% 0%,rgba(139,92,246,.12),transparent 14rem),rgba(17,24,39,.56);
            box-shadow:var(--rp-shadow);
        }
        .rp-exception-badge{
            display:inline-flex;align-items:center;gap:7px;color:#ddd6fe;font-size:11px;font-weight:700;
            padding:6px 9px;border-radius:999px;border:1px solid rgba(139,92,246,.25);background:rgba(139,92,246,.08);
        }
        .rp-exception h3{font-size:21px;line-height:1.35;margin:17px 0 10px;letter-spacing:0;color:#fff}
        .rp-exception p{margin:0;color:var(--rp-muted);font-size:13px;line-height:1.75}
        .rp-mini-note{margin-top:20px;padding-top:16px;border-top:1px solid var(--rp-line);font-size:11.5px;color:#b7c3d4;line-height:1.65}

        .rp-flow{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:0;position:relative;border-top:1px solid var(--rp-line)}
        .rp-flow-step{position:relative;padding:28px 24px 0 0;min-height:190px}
        .rp-flow-step::before{
            content:"";position:absolute;top:-4px;left:0;width:8px;height:8px;border-radius:50%;
            background:#60a5fa;box-shadow:0 0 0 5px var(--rp-bg),0 0 18px rgba(96,165,250,.6);
        }
        .rp-flow-step:not(:last-child)::after{
            content:"";position:absolute;top:-1px;left:12px;right:0;height:1px;
            background:linear-gradient(90deg,rgba(96,165,250,.34),transparent);
        }
        .rp-step-no{color:#60a5fa;font-size:11px;font-weight:700;margin-bottom:16px}
        .rp-flow-step h3{margin:0 0 8px;font-size:15px;color:#fff}
        .rp-flow-step p{margin:0;color:var(--rp-muted);font-size:12.5px;line-height:1.68}

        .rp-cta{padding:60px 0 74px}
        .rp-cta-panel{
            display:grid;grid-template-columns:minmax(0,1.1fr) minmax(320px,.9fr);gap:28px;align-items:center;
            padding:38px;border-radius:28px;border:1px solid rgba(96,165,250,.19);
            background:radial-gradient(circle at 88% 0%,rgba(37,99,235,.19),transparent 22rem),linear-gradient(135deg,rgba(17,24,39,.95),rgba(12,19,31,.94));
            box-shadow:0 30px 75px rgba(0,0,0,.38);
        }
        .rp-cta-panel h2{margin:0;font-size:clamp(31px,4.2vw,52px);line-height:1.15;letter-spacing:0;color:#fff}
        .rp-cta-panel p{margin:13px 0 0;color:var(--rp-muted);font-size:14px;line-height:1.75;max-width:640px}
        .rp-cta-actions{display:grid;gap:10px}
        .rp-warning{
            margin:0;padding:13px 14px;border:1px solid rgba(251,191,36,.24);border-radius:14px;
            background:rgba(251,191,36,.065);color:#e8d9a4;font-size:11.5px;line-height:1.65;
        }
        .rp-warning strong{color:#fff0b0}
        .rp-admin-btn{
            min-height:62px;border-radius:14px;border:1px solid var(--rp-line);padding:0 16px;
            display:flex;align-items:center;justify-content:space-between;gap:14px;
            background:rgba(255,255,255,.035);transition:background .16s ease,border-color .16s ease,transform .16s ease;
        }
        .rp-admin-btn:hover{transform:translateY(-1px);background:rgba(37,99,235,.08);border-color:rgba(96,165,250,.28)}
        .rp-admin-left{display:flex;align-items:center;gap:12px;min-width:0}
        .rp-tg-dot{width:35px;height:35px;border-radius:11px;display:grid;place-items:center;background:#229ed9;color:#fff;flex:0 0 35px;font-size:18px}
        .rp-admin-text{min-width:0}
        .rp-admin-text strong{display:block;font-size:13px;line-height:1.15;color:#fff}
        .rp-admin-text span{display:block;margin-top:4px;color:#8fb7d2;font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .rp-arrow{color:#94a3b8;font-size:18px}
        .rp-footer{padding:0 0 34px;color:#7f8ca2;font-size:11px;text-align:center}

        .rp-modal{
            position:fixed;inset:0;z-index:1000;display:none;align-items:flex-end;justify-content:center;padding:18px;
            font-family:"IBM Plex Sans Thai","Noto Sans Thai","Segoe UI",Tahoma,sans-serif;
        }
        .rp-modal.rp-show{display:flex}
        .rp-modal-backdrop{position:absolute;inset:0;background:rgba(2,6,23,.76);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px)}
        .rp-modal-card{
            position:relative;width:min(540px,100%);padding:24px;border-radius:22px;
            background:#111827;border:1px solid rgba(255,255,255,.10);box-shadow:0 32px 90px rgba(0,0,0,.56);
            animation:rpModalIn .18s ease-out both;
        }
        @keyframes rpModalIn{from{opacity:0;transform:translateY(12px) scale(.985)}to{opacity:1;transform:none}}
        .rp-modal-head{display:flex;align-items:flex-start;justify-content:space-between;gap:18px}
        .rp-modal-head h3{margin:0;font-size:22px;line-height:1.25;color:#fff}
        .rp-modal-head p{margin:6px 0 0;color:var(--rp-muted);font-size:12.5px;line-height:1.6}
        .rp-modal-close{
            width:44px;height:44px;flex:0 0 44px;border-radius:12px;border:1px solid var(--rp-line);
            background:rgba(255,255,255,.03);color:#d7deea;cursor:pointer;font-size:17px;
        }
        .rp-prep-box{margin:18px 0;border:1px solid var(--rp-line);border-radius:16px;overflow:hidden;background:#0c1320}
        .rp-prep-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 13px;border-bottom:1px solid var(--rp-line)}
        .rp-prep-head strong{font-size:12px;color:#fff}
        .rp-prep-head span{color:var(--rp-muted);font-size:10.5px;text-align:right}
        .rp-prep-template{
            margin:0;padding:15px 14px;white-space:pre-wrap;word-break:break-word;color:#dbe4f2;background:transparent;
            font:500 12px/1.72 "IBM Plex Sans Thai",sans-serif;
        }
        .rp-copy-row{display:flex;align-items:center;gap:10px;padding:0 13px 13px}
        .rp-copy-btn{
            min-height:44px;padding:0 14px;border:1px solid rgba(122,167,255,.25);border-radius:11px;
            background:rgba(37,99,235,.12);color:#dbeafe;cursor:pointer;font-weight:700;
        }
        .rp-copy-status{min-height:20px;color:#9ee8c6;font-size:11px;line-height:1.4}
        .rp-modal-actions{display:grid;grid-template-columns:1fr 1fr;gap:9px}
        .rp-modal-actions .rp-btn{width:100%}
        .reseller-program :focus-visible,.rp-modal :focus-visible{outline:2px solid #60a5fa;outline-offset:3px}

        @media(max-width:900px){
            .rp-hero{padding-top:50px}
            .rp-hero-grid,.rp-section-head,.rp-requirements-wrap,.rp-cta-panel{grid-template-columns:1fr}
            .rp-credit-stage{min-height:auto;padding:30px 0 8px;border-left:0;border-top:1px solid var(--rp-line)}
            .rp-credit-stage::before{left:0;top:-1px;width:150px;height:1px;background:linear-gradient(90deg,var(--rp-blue-soft),rgba(139,92,246,.55),transparent)}
            .rp-credit-number{font-size:clamp(92px,20vw,140px)}
            .rp-section-head{gap:10px}
            .rp-benefits{grid-template-columns:repeat(2,minmax(0,1fr))}
            .rp-benefit:nth-child(2){border-right:0}
            .rp-benefit:nth-child(-n+2){border-bottom:1px solid var(--rp-line)}
            .rp-flow{grid-template-columns:repeat(2,minmax(0,1fr));border-top:0;gap:18px}
            .rp-flow-step{border-top:1px solid var(--rp-line)}
            .rp-cta-panel{padding:28px}
        }
        @media(max-width:600px){
            .rp-shell{width:min(100% - 24px,1140px)}
            .rp-hero{padding:42px 0 26px}
            .rp-hero h1{font-size:clamp(39px,12.3vw,58px)}
            .rp-hero-lead{font-size:14px;line-height:1.74}
            .rp-hero-actions{display:grid;grid-template-columns:1fr}
            .rp-btn{width:100%}
            .rp-benefits{grid-template-columns:1fr;border-radius:18px}
            .rp-benefit{min-height:auto;border-right:0;border-bottom:1px solid var(--rp-line);padding:20px}
            .rp-benefit:nth-child(-n+2){border-bottom:1px solid var(--rp-line)}
            .rp-benefit:last-child{border-bottom:0}
            .rp-benefit-icon{margin-bottom:18px}
            .rp-req-row{grid-template-columns:43px 1fr;padding:14px;gap:12px}
            .rp-tag{grid-column:2;justify-self:start}
            .rp-exception{padding:20px}
            .rp-flow{grid-template-columns:1fr;gap:12px}
            .rp-flow-step{min-height:auto;padding:22px 4px 4px 0}
            .rp-cta{padding-top:48px}
            .rp-cta-panel{padding:22px;border-radius:20px}
            .rp-modal-actions{grid-template-columns:1fr}
            .rp-prep-head{align-items:flex-start;flex-direction:column;gap:3px}
        }
        @media(prefers-reduced-motion:reduce){
            .reseller-program *, .reseller-program *::before, .reseller-program *::after,
            .rp-modal *, .rp-modal *::before, .rp-modal *::after{
                scroll-behavior:auto!important;animation-duration:.01ms!important;animation-iteration-count:1!important;transition-duration:.01ms!important;
            }
        }
    </style>
</head>
<body class="bg-darkbg text-gray-100 min-h-screen">
<a class="rp-skip" href="#resellerMain"><?php echo htmlspecialchars($tr('ข้ามไปเนื้อหาหลัก', 'Skip to main content'), ENT_QUOTES, 'UTF-8'); ?></a>
<?php include 'nav.php'; ?>

<main id="resellerMain" class="reseller-program">
    <section class="rp-hero rp-section" id="overview">
        <div class="rp-shell rp-hero-grid">
            <div class="rp-hero-copy">
                <div class="rp-signal"><?php echo htmlspecialchars($tr('โปรแกรมตัวแทน BLACKUP', 'BLACKUP reseller program'), ENT_QUOTES, 'UTF-8'); ?></div>
                <h1><?php echo htmlspecialchars($tr(
                    'เปิดบัญชีตัวแทน เพื่อขายต่อในราคาที่จัดการกำไรได้ง่ายขึ้น',
                    'Open a reseller account and manage your resale margin more easily'
                ), ENT_QUOTES, 'UTF-8'); ?></h1>
                <p class="rp-hero-lead"><?php echo htmlspecialchars($tr(
                    'สำหรับร้าน กลุ่ม เพจ หรือแชแนลที่มีฐานลูกค้าอยู่แล้ว เมื่อผ่านการพิจารณา ทีมงานจะเปิดบัญชีตัวแทนให้ พร้อมระบบเครดิตและราคาสำหรับตัวแทนโดยเฉพาะ',
                    'For stores, groups, pages, or channels that already have an audience. After approval, our team opens a reseller account with reseller pricing and a dedicated credit balance.'
                ), ENT_QUOTES, 'UTF-8'); ?></p>
                <div class="rp-hero-actions">
                    <button class="rp-btn rp-btn-primary" type="button" data-rp-open-contact>
                        <i class="bi bi-send"></i>
                        <?php echo htmlspecialchars($tr('ติดต่อสมัครตัวแทน', 'Apply as a reseller'), ENT_QUOTES, 'UTF-8'); ?>
                    </button>
                    <a class="rp-btn rp-btn-secondary" href="#requirements">
                        <i class="bi bi-clipboard-check"></i>
                        <?php echo htmlspecialchars($tr('ดูคุณสมบัติก่อน', 'View requirements'), ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                </div>
            </div>

            <div class="rp-credit-stage" aria-label="<?php echo htmlspecialchars($tr('เครดิตเปิดบัญชีขั้นต่ำ 500 บาท', 'Minimum opening credit 500 THB'), ENT_QUOTES, 'UTF-8'); ?>">
                <div class="rp-credit-caption"><?php echo htmlspecialchars($tr('เครดิตเปิดบัญชีครั้งแรก ขั้นต่ำ', 'Minimum opening credit'), ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="rp-credit-number">500</div>
                <div class="rp-credit-unit"><?php echo htmlspecialchars($tr('บาท', 'THB'), ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="rp-credit-note">
                    <strong><?php echo htmlspecialchars($tr('เงินไม่ใช่ค่าสมัคร', 'This is not an application fee'), ENT_QUOTES, 'UTF-8'); ?></strong><br>
                    <?php echo htmlspecialchars($tr(
                        'เครดิต 500 บาทยังอยู่ในบัญชีและใช้ซื้อสินค้าได้เต็มจำนวน หลังเปิดบัญชี BLACKUP ไม่กำหนดยอดเติมขั้นต่ำสำหรับเครดิตครั้งถัดไป แต่อาจมีขั้นต่ำตามเงื่อนไขของช่องทางชำระเงินแต่ละแบบ',
                        'The 500 THB remains in your account and can be used in full for purchases. BLACKUP does not set an account-level minimum for later top-ups, although individual payment methods may have their own limits.'
                    ), ENT_QUOTES, 'UTF-8'); ?>
                </div>
            </div>
        </div>
    </section>

    <section class="rp-section" id="benefits">
        <div class="rp-shell">
            <div class="rp-section-head">
                <h2><?php echo htmlspecialchars($tr('สิ่งที่บัญชีตัวแทนได้ใช้จริง', 'What the reseller account actually gives you'), ENT_QUOTES, 'UTF-8'); ?></h2>
                <p><?php echo htmlspecialchars($tr(
                    'บัญชีตัวแทนช่วยให้การซื้อสินค้าไปจำหน่ายต่อเป็นระบบขึ้น ทั้งเรื่องราคาตัวแทน เครดิต และการติดตามรายการในบัญชีเดียว',
                    'The reseller account keeps pricing, credit, and purchase history in one place for day-to-day resale operations.'
                ), ENT_QUOTES, 'UTF-8'); ?></p>
            </div>

            <div class="rp-benefits">
                <article class="rp-benefit">
                    <div class="rp-benefit-icon"><i class="bi bi-tags"></i></div>
                    <h3><?php echo htmlspecialchars($tr('ราคาสำหรับตัวแทน', 'Reseller pricing'), ENT_QUOTES, 'UTF-8'); ?></h3>
                    <p><?php echo htmlspecialchars($tr('ซื้อสินค้าในราคาตัวแทน เพื่อให้คุณกำหนดราคาขายและวางกำไรของร้านได้ยืดหยุ่นขึ้น', 'Buy at reseller pricing so you can set your own retail price and margin more flexibly.'), ENT_QUOTES, 'UTF-8'); ?></p>
                </article>
                <article class="rp-benefit">
                    <div class="rp-benefit-icon"><i class="bi bi-grid-1x2"></i></div>
                    <h3><?php echo htmlspecialchars($tr('แดชบอร์ดตัวแทน', 'Reseller dashboard'), ENT_QUOTES, 'UTF-8'); ?></h3>
                    <p><?php echo htmlspecialchars($tr('จัดการยอดเครดิต รายการซื้อ และประวัติการทำรายการจากบัญชีตัวแทนโดยตรง', 'Manage credit, purchases, and transaction history directly from the reseller account.'), ENT_QUOTES, 'UTF-8'); ?></p>
                </article>
                <article class="rp-benefit">
                    <div class="rp-benefit-icon"><i class="bi bi-wallet2"></i></div>
                    <h3><?php echo htmlspecialchars($tr('เครดิตใช้ซื้อสินค้าได้', 'Opening credit stays usable'), ENT_QUOTES, 'UTF-8'); ?></h3>
                    <p><?php echo htmlspecialchars($tr('เครดิตเปิดบัญชีไม่หายไปเป็นค่าธรรมเนียม แต่ยังอยู่ในบัญชีเพื่อใช้ซื้อสินค้าตามปกติ', 'Opening credit is not consumed as a fee. It remains available in the account for normal purchases.'), ENT_QUOTES, 'UTF-8'); ?></p>
                </article>
                <article class="rp-benefit">
                    <div class="rp-benefit-icon"><i class="bi bi-headset"></i></div>
                    <h3><?php echo htmlspecialchars($tr('ติดต่อทีมงานได้โดยตรง', 'Direct admin contact'), ENT_QUOTES, 'UTF-8'); ?></h3>
                    <p><?php echo htmlspecialchars($tr('มีช่องทาง Admin สำหรับสมัคร เปิดบัญชี และสอบถามกรณีพิเศษก่อนเริ่มใช้งานจริง', 'Contact the listed admins for application review, account opening, or exceptional cases.'), ENT_QUOTES, 'UTF-8'); ?></p>
                </article>
            </div>
        </div>
    </section>

    <section class="rp-section" id="requirements">
        <div class="rp-shell">
            <div class="rp-section-head">
                <h2><?php echo htmlspecialchars($tr('คุณสมบัติที่ใช้พิจารณา', 'Application requirements'), ENT_QUOTES, 'UTF-8'); ?></h2>
                <p><?php echo htmlspecialchars($tr(
                    'เรารับผู้ที่มีพื้นฐานการขายและมีช่องทางของตัวเองเป็นหลัก เพราะบัญชีตัวแทนออกแบบมาสำหรับผู้ที่ต้องการนำสินค้าไปจำหน่ายต่อจริง',
                    'The program is intended for people who already sell through their own channel and plan to resell products actively.'
                ), ENT_QUOTES, 'UTF-8'); ?></p>
            </div>

            <div class="rp-requirements-wrap">
                <div class="rp-requirements">
                    <div class="rp-req-row">
                        <div class="rp-req-icon"><i class="bi bi-shop"></i></div>
                        <div class="rp-req-copy">
                            <strong><?php echo htmlspecialchars($tr('มีช่องทางการขายของตัวเอง', 'Have your own sales channel'), ENT_QUOTES, 'UTF-8'); ?></strong>
                            <span><?php echo htmlspecialchars($tr('เช่น กลุ่ม เพจ แชแนล ร้านออนไลน์ หรือพื้นที่ที่ใช้ขายสินค้าอยู่แล้ว', 'For example a group, page, channel, online store, or another active sales channel.'), ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <span class="rp-tag rp-tag-blue"><?php echo htmlspecialchars($tr('จำเป็น', 'Required'), ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <div class="rp-req-row">
                        <div class="rp-req-icon"><i class="bi bi-people"></i></div>
                        <div class="rp-req-copy">
                            <strong><?php echo htmlspecialchars($tr('สมาชิกหรือผู้ติดตาม 100 คนขึ้นไป', 'At least 100 members or followers'), ENT_QUOTES, 'UTF-8'); ?></strong>
                            <span><?php echo htmlspecialchars($tr('ใช้เป็นเกณฑ์พื้นฐานเพื่อดูว่าช่องทางมีฐานผู้ชมและพร้อมขายจริง', 'This is the baseline used to confirm that the channel has an active audience.'), ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <span class="rp-tag">100+</span>
                    </div>
                    <div class="rp-req-row">
                        <div class="rp-req-icon"><i class="bi bi-receipt-cutoff"></i></div>
                        <div class="rp-req-copy">
                            <strong><?php echo htmlspecialchars($tr('มีประสบการณ์หรือประวัติการขาย', 'Sales experience is a plus'), ENT_QUOTES, 'UTF-8'); ?></strong>
                            <span><?php echo htmlspecialchars($tr('หากมีหลักฐานหรือประวัติการขายเดิม จะช่วยให้การพิจารณาชัดเจนและเร็วขึ้น', 'Existing sales history or proof helps the admin review the application more clearly.'), ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <span class="rp-tag rp-tag-amber"><?php echo htmlspecialchars($tr('พิจารณาพิเศษ', 'Preferred'), ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <div class="rp-req-row">
                        <div class="rp-req-icon"><i class="bi bi-wallet2"></i></div>
                        <div class="rp-req-copy">
                            <strong><?php echo htmlspecialchars($tr('พร้อมเติมเครดิตเปิดบัญชี 500 บาท', 'Ready to add 500 THB opening credit'), ENT_QUOTES, 'UTF-8'); ?></strong>
                            <span><?php echo htmlspecialchars($tr('เติมหลังผ่านการพิจารณาแล้ว จากนั้น Admin จึงเปิดบัญชีตัวแทนให้', 'Add the opening credit only after approval, then the admin opens the reseller account.'), ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <span class="rp-tag rp-tag-blue"><?php echo htmlspecialchars($tr('ครั้งแรก', 'First time'), ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                </div>

                <aside class="rp-exception">
                    <div class="rp-exception-badge"><i class="bi bi-stars"></i> <?php echo htmlspecialchars($tr('กรณีพิเศษ', 'Exceptional review'), ENT_QUOTES, 'UTF-8'); ?></div>
                    <h3><?php echo htmlspecialchars($tr('ผู้ติดตามยังไม่ถึง 100 คน ก็ยังสอบถามได้', 'Fewer than 100 followers? You can still ask for a review'), ENT_QUOTES, 'UTF-8'); ?></h3>
                    <p><?php echo htmlspecialchars($tr(
                        'ถ้ามีช่องทางขายอยู่จริงและมีประวัติการขายมาก่อน สามารถส่งข้อมูลให้ทีมงานพิจารณาเป็นรายกรณีได้ ไม่ได้ตัดสิทธิ์อัตโนมัติจากตัวเลขเพียงอย่างเดียว',
                        'If you have an active sales channel and prior sales history, send the details to the team for a case-by-case review. The follower count alone does not automatically disqualify you.'
                    ), ENT_QUOTES, 'UTF-8'); ?></p>
                    <div class="rp-mini-note"><?php echo htmlspecialchars($tr('เน้นผู้สมัครที่มีพื้นฐานการขายและตั้งใจนำสินค้าไปจำหน่ายต่อจริง', 'Priority is given to applicants with real selling experience and an active resale plan.'), ENT_QUOTES, 'UTF-8'); ?></div>
                </aside>
            </div>
        </div>
    </section>

    <section class="rp-section" id="process">
        <div class="rp-shell">
            <div class="rp-section-head">
                <h2><?php echo htmlspecialchars($tr('สมัครอย่างไร', 'How to apply'), ENT_QUOTES, 'UTF-8'); ?></h2>
                <p><?php echo htmlspecialchars($tr('ส่งข้อมูลช่องทางขายให้ Admin ตรวจสอบก่อน หากผ่านการพิจารณาจึงเติมเครดิตเปิดบัญชีและเริ่มใช้งานบัญชีตัวแทน', 'Send your sales-channel details to an admin first. Only after approval do you add the opening credit and receive the reseller account.'), ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
            <div class="rp-flow">
                <article class="rp-flow-step">
                    <div class="rp-step-no">01</div>
                    <h3><?php echo htmlspecialchars($tr('ติดต่อ Admin', 'Contact an admin'), ENT_QUOTES, 'UTF-8'); ?></h3>
                    <p><?php echo htmlspecialchars($tr('เลือก Admin คนใดคนหนึ่งผ่าน Telegram แล้วแจ้งว่าต้องการสมัครเป็นตัวแทน', 'Choose either official Telegram admin and say that you want to apply as a reseller.'), ENT_QUOTES, 'UTF-8'); ?></p>
                </article>
                <article class="rp-flow-step">
                    <div class="rp-step-no">02</div>
                    <h3><?php echo htmlspecialchars($tr('ส่งข้อมูลช่องทางขาย', 'Send your sales details'), ENT_QUOTES, 'UTF-8'); ?></h3>
                    <p><?php echo htmlspecialchars($tr('ส่งชื่อร้าน ลิงก์กลุ่ม/เพจ/แชแนล จำนวนผู้ติดตาม และประวัติการขายถ้ามี', 'Send your store name, sales-channel link, follower count, and any existing sales history.'), ENT_QUOTES, 'UTF-8'); ?></p>
                </article>
                <article class="rp-flow-step">
                    <div class="rp-step-no">03</div>
                    <h3><?php echo htmlspecialchars($tr('ทีมงานพิจารณา', 'Admin review'), ENT_QUOTES, 'UTF-8'); ?></h3>
                    <p><?php echo htmlspecialchars($tr('Admin ตรวจข้อมูลและแจ้งผล หากผ่านจึงเข้าสู่ขั้นตอนเติมเครดิตเปิดบัญชี', 'The admin reviews the information and confirms whether the application is approved.'), ENT_QUOTES, 'UTF-8'); ?></p>
                </article>
                <article class="rp-flow-step">
                    <div class="rp-step-no">04</div>
                    <h3><?php echo htmlspecialchars($tr('เปิดบัญชีตัวแทน', 'Account opening'), ENT_QUOTES, 'UTF-8'); ?></h3>
                    <p><?php echo htmlspecialchars($tr('เติมเครดิตครั้งแรกขั้นต่ำ 500 บาท แล้ว Admin เปิดบัญชี reseller ให้ใช้งาน', 'Add at least 500 THB opening credit, then the admin opens the reseller account.'), ENT_QUOTES, 'UTF-8'); ?></p>
                </article>
            </div>
        </div>
    </section>

    <section class="rp-cta" id="apply">
        <div class="rp-shell">
            <div class="rp-cta-panel">
                <div>
                    <h2><?php echo $tr('มีช่องทางขายอยู่แล้ว?<br>ส่งข้อมูลให้ทีมงานตรวจได้เลย', 'Already have a sales channel?<br>Send your details for review'); ?></h2>
                    <p><?php echo htmlspecialchars($tr('เตรียมชื่อร้าน ลิงก์ช่องทางขาย จำนวนสมาชิกหรือผู้ติดตาม และประวัติการขายถ้ามี เพื่อให้ทีมงานพิจารณาได้เร็วขึ้น', 'Prepare your store name, sales-channel link, follower/member count, and any sales history so the review can be completed more efficiently.'), ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
                <div class="rp-cta-actions">
                    <div class="rp-warning">
                        <strong><?php echo htmlspecialchars($tr('ช่องทางสมัครอย่างเป็นทางการ', 'Official application channels'), ENT_QUOTES, 'UTF-8'); ?></strong><br>
                        <?php echo htmlspecialchars($tr('ใช้เฉพาะ Telegram @DrkZeref และ @om_shop99 ที่แสดงในหน้านี้ ก่อนเติมเครดิตเปิดบัญชีควรตรวจสอบชื่อบัญชีให้ตรงทุกครั้ง', 'Use only the Telegram accounts @DrkZeref and @om_shop99 shown on this page. Verify the account name before adding opening credit.'), ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <?php foreach ($telegramAdmins as $admin): ?>
                        <a class="rp-admin-btn" href="<?php echo htmlspecialchars($admin['url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">
                            <span class="rp-admin-left">
                                <span class="rp-tg-dot"><i class="bi bi-telegram"></i></span>
                                <span class="rp-admin-text">
                                    <strong><?php echo htmlspecialchars($admin['label'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <span><?php echo htmlspecialchars($admin['handle'], ENT_QUOTES, 'UTF-8'); ?></span>
                                </span>
                            </span>
                            <span class="rp-arrow" aria-hidden="true">›</span>
                        </a>
                    <?php endforeach; ?>
                    <button class="rp-btn rp-btn-primary" type="button" data-rp-open-contact>
                        <i class="bi bi-clipboard"></i>
                        <?php echo htmlspecialchars($tr('เตรียมข้อความสมัคร', 'Prepare application message'), ENT_QUOTES, 'UTF-8'); ?>
                    </button>
                </div>
            </div>
        </div>
    </section>

    <footer class="rp-footer">BLACKUP · Reseller Program</footer>
</main>

<div class="rp-modal" id="resellerContactModal" aria-hidden="true">
    <div class="rp-modal-backdrop" data-rp-close-contact></div>
    <section class="rp-modal-card" role="dialog" aria-modal="true" aria-labelledby="resellerModalTitle">
        <div class="rp-modal-head">
            <div>
                <h3 id="resellerModalTitle"><?php echo htmlspecialchars($tr('สมัครตัวแทนผ่าน Telegram', 'Apply through Telegram'), ENT_QUOTES, 'UTF-8'); ?></h3>
                <p><?php echo htmlspecialchars($tr('คัดลอกข้อความนี้ แล้วเลือก Admin คนใดคนหนึ่งเพื่อส่งข้อมูลให้ครบในครั้งเดียว', 'Copy this message, then choose either admin and send all required details in one message.'), ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
            <button class="rp-modal-close" type="button" data-rp-close-contact aria-label="<?php echo htmlspecialchars($tr('ปิด', 'Close'), ENT_QUOTES, 'UTF-8'); ?>"><i class="bi bi-x-lg"></i></button>
        </div>

        <div class="rp-prep-box">
            <div class="rp-prep-head">
                <strong><?php echo htmlspecialchars($tr('ข้อความสำหรับส่งให้ Admin', 'Message for the admin'), ENT_QUOTES, 'UTF-8'); ?></strong>
                <span><?php echo htmlspecialchars($tr('แก้ข้อมูลใน Telegram ก่อนส่งได้', 'Edit the details in Telegram before sending'), ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <pre class="rp-prep-template" id="resellerApplyTemplate"><?php echo htmlspecialchars($applyTemplate, ENT_QUOTES, 'UTF-8'); ?></pre>
            <div class="rp-copy-row">
                <button class="rp-copy-btn" id="resellerCopyTemplate" type="button"><?php echo htmlspecialchars($tr('คัดลอกข้อความ', 'Copy message'), ENT_QUOTES, 'UTF-8'); ?></button>
                <span class="rp-copy-status" id="resellerCopyStatus" role="status" aria-live="polite"></span>
            </div>
        </div>

        <div class="rp-warning">
            <strong><?php echo htmlspecialchars($tr('ตรวจสอบบัญชีก่อนติดต่อ', 'Verify the account before contacting'), ENT_QUOTES, 'UTF-8'); ?></strong><br>
            <?php echo htmlspecialchars($tr('Admin สำหรับสมัครตัวแทนมี 2 บัญชีตามปุ่มด้านล่าง: @DrkZeref และ @om_shop99', 'The two official reseller application accounts are @DrkZeref and @om_shop99.'), ENT_QUOTES, 'UTF-8'); ?>
        </div>

        <div class="rp-modal-actions" style="margin-top:16px">
            <?php foreach ($telegramAdmins as $index => $admin): ?>
                <a class="rp-btn <?php echo $index === 0 ? 'rp-btn-primary' : 'rp-btn-secondary'; ?>" href="<?php echo htmlspecialchars($admin['url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">
                    <?php echo htmlspecialchars($admin['label'] . ' · ' . $admin['handle'], ENT_QUOTES, 'UTF-8'); ?>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
</div>

<script>
(function(){
    'use strict';

    const modal = document.getElementById('resellerContactModal');
    const openers = document.querySelectorAll('[data-rp-open-contact]');
    const closers = document.querySelectorAll('[data-rp-close-contact]');
    const copyBtn = document.getElementById('resellerCopyTemplate');
    const template = document.getElementById('resellerApplyTemplate');
    const copyStatus = document.getElementById('resellerCopyStatus');
    const copiedText = <?php echo json_encode($tr('คัดลอกแล้ว ✓', 'Copied ✓'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const copyFailedText = <?php echo json_encode($tr('คัดลอกอัตโนมัติไม่ได้ กรุณาเลือกข้อความแล้วคัดลอก', 'Automatic copy is unavailable. Please select the text and copy it manually.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

    let lastFocus = null;
    let previousBodyOverflow = '';
    let clearStatusTimer = 0;

    function focusableItems(){
        if (!modal) return [];
        return Array.from(modal.querySelectorAll('a[href],button:not([disabled]),[tabindex]:not([tabindex="-1"])'));
    }

    function openModal(){
        if (!modal || modal.classList.contains('rp-show')) return;
        lastFocus = document.activeElement;
        previousBodyOverflow = document.body.style.overflow;
        modal.classList.add('rp-show');
        modal.setAttribute('aria-hidden','false');
        document.body.style.overflow = 'hidden';
        const first = focusableItems()[0];
        if (first) first.focus();
    }

    function closeModal(){
        if (!modal || !modal.classList.contains('rp-show')) return;
        modal.classList.remove('rp-show');
        modal.setAttribute('aria-hidden','true');
        document.body.style.overflow = previousBodyOverflow;
        if (lastFocus && typeof lastFocus.focus === 'function') lastFocus.focus();
    }

    async function copyTemplate(){
        if (!template || !copyStatus) return;
        const text = template.textContent.trim();
        let copied = false;

        try {
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(text);
                copied = true;
            }
        } catch (ignored) {
            copied = false;
        }

        if (!copied) {
            const area = document.createElement('textarea');
            area.value = text;
            area.setAttribute('readonly','');
            area.style.position = 'fixed';
            area.style.left = '-9999px';
            area.style.opacity = '0';
            document.body.appendChild(area);
            area.select();
            try {
                copied = document.execCommand('copy');
            } catch (ignored) {
                copied = false;
            }
            area.remove();
        }

        window.clearTimeout(clearStatusTimer);
        copyStatus.textContent = copied ? copiedText : copyFailedText;
        if (copied) {
            clearStatusTimer = window.setTimeout(function(){ copyStatus.textContent = ''; }, 1800);
        }
    }

    function trapTab(event){
        if (!modal || event.key !== 'Tab' || !modal.classList.contains('rp-show')) return;
        const items = focusableItems();
        if (!items.length) return;
        const first = items[0];
        const last = items[items.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    openers.forEach(function(button){ button.addEventListener('click', openModal); });
    closers.forEach(function(button){ button.addEventListener('click', closeModal); });
    if (copyBtn) copyBtn.addEventListener('click', copyTemplate);

    document.addEventListener('keydown', function(event){
        if (event.key === 'Escape') closeModal();
        trapTab(event);
    });
})();
</script>
</body>
</html>
