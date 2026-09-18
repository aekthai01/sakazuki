<?php
require_once '../includes/auth.php';
requireAdmin();

global $conn;

$appLang = (string) getAppLang();
$isThai = stripos($appLang, 'th') === 0;

$error = '';
$success = '';
$generatedCode = '';
$generatedAmount = null;

// อ่านข้อความชั่วคราวหลัง Redirect (Post/Redirect/Get)
// ช่วยป้องกันการสร้างโค้ดซ้ำเมื่อผู้ใช้กดรีเฟรชหน้า
if (!empty($_SESSION['redeem_code_flash']) && is_array($_SESSION['redeem_code_flash'])) {
    $flash = $_SESSION['redeem_code_flash'];
    unset($_SESSION['redeem_code_flash']);

    if (($flash['type'] ?? '') === 'success') {
        $success = isset($flash['message']) && is_scalar($flash['message'])
            ? (string) $flash['message']
            : '';
    } else {
        $error = isset($flash['message']) && is_scalar($flash['message'])
            ? (string) $flash['message']
            : '';
    }

    if (!empty($flash['code']) && is_scalar($flash['code'])) {
        $generatedCode = substr((string) $flash['code'], 0, 100);
    }

    if (isset($flash['amount']) && is_numeric($flash['amount'])) {
        $generatedAmount = (float) $flash['amount'];
    }
}

// Handle generate
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_code'])) {
    requireCsrfToken();

    $amountInput = isset($_POST['amount']) && is_scalar($_POST['amount'])
        ? trim((string) $_POST['amount'])
        : '';
    $amountInput = preg_replace('/[^0-9\.,]/', '', $amountInput);
    $amountInput = str_replace(',', '.', $amountInput);
    $amount = (float) $amountInput;

    if ($amountInput === '' || !is_finite($amount) || $amount <= 0) {
        $_SESSION['redeem_code_flash'] = [
            'type' => 'error',
            'message' => $isThai ? 'กรุณาระบุจำนวนเงินที่มากกว่า 0' : 'Please enter an amount greater than 0',
        ];
        header('Location: codes.php');
        exit;
    }

    $res = generateRedeemCode($amount, (int) $_SESSION['user_id']);

    if (!empty($res['success'])) {
        $_SESSION['redeem_code_flash'] = [
            'type' => 'success',
            'message' => $isThai ? 'สร้างโค้ดเติมเงินสำเร็จ' : 'Redeem code generated successfully',
            'code' => isset($res['code']) && is_scalar($res['code']) ? (string) $res['code'] : '',
            'amount' => isset($res['amount']) && is_numeric($res['amount']) ? (float) $res['amount'] : $amount,
        ];
    } else {
        $_SESSION['redeem_code_flash'] = [
            'type' => 'error',
            'message' => isset($res['message']) && is_scalar($res['message'])
                ? (string) $res['message']
                : ($isThai ? 'สร้างโค้ดเติมเงินไม่สำเร็จ' : 'Failed to generate redeem code'),
        ];
    }

    header('Location: codes.php');
    exit;
}

// Handle redeem for admin self
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['redeem_code'])) {
    requireCsrfToken();

    $code = isset($_POST['code']) && is_scalar($_POST['code'])
        ? substr(strtoupper(trim((string) $_POST['code'])), 0, 100)
        : '';

    $res = redeemTopupCode((int) $_SESSION['user_id'], $code);

    $_SESSION['redeem_code_flash'] = [
        'type' => !empty($res['success']) ? 'success' : 'error',
        'message' => isset($res['message']) && is_scalar($res['message'])
            ? (string) $res['message']
            : (!empty($res['success'])
                ? ($isThai ? 'เติมเงินสำเร็จ' : 'Redeemed successfully')
                : ($isThai ? 'เติมเงินไม่สำเร็จ' : 'Redeem failed')),
    ];

    header('Location: codes.php');
    exit;
}

// Recent codes
ensureRedeemCodesSchema();
$adminId = (int) $_SESSION['user_id'];
$recent = $conn->query("SELECT rc.*, u.username AS used_by_username
            FROM redeem_codes rc
            LEFT JOIN users u ON u.id = rc.used_by
            WHERE rc.created_by = $adminId
            ORDER BY rc.created_at DESC
            LIMIT 30");
$recentCodes = $recent ? $recent->fetch_all(MYSQLI_ASSOC) : [];

$balance = getUserBalance($_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($appLang, ENT_QUOTES, 'UTF-8'); ?>">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title data-lang="admin.codes.title"><?php echo Lang::t('admin.codes.title'); ?> - Admin Panel</title>
  <link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <style>
    body{background:#0b0f18;color:#e5e7eb;}
    .glass{background:rgba(255,255,255,.04);backdrop-filter:blur(10px);}
    .card{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);}
    .muted{color:rgba(229,231,235,.7);}
    input,select{background:rgba(255,255,255,.04)!important;border:1px solid rgba(255,255,255,.10)!important;color:#e5e7eb!important;}
    input::placeholder{color:rgba(229,231,235,.4);}
    .btn{border:1px solid rgba(255,255,255,.12);}
    .btn:disabled{opacity:.55;cursor:not-allowed;}
    .code-value{overflow-wrap:anywhere;word-break:break-word;}
    .copy-toast{
      position:fixed;
      left:50%;
      bottom:24px;
      z-index:9999;
      transform:translate(-50%,20px);
      opacity:0;
      pointer-events:none;
      transition:opacity .15s ease,transform .15s ease;
      background:rgba(15,23,42,.96);
      border:1px solid rgba(255,255,255,.14);
      box-shadow:0 18px 50px rgba(0,0,0,.35);
    }
    .copy-toast.show{opacity:1;transform:translate(-50%,0);}
  </style>
</head>
<body class="min-h-screen">
<?php include 'nav.php'; ?>

<div class="max-w-6xl mx-auto px-4 py-6">
  <div class="flex items-center justify-between gap-3 flex-wrap">
    <h1 class="text-xl font-bold flex items-center gap-2"><i class="bi bi-ticket-perforated"></i> <span data-lang="admin.codes.heading"><?php echo Lang::t('admin.codes.heading'); ?></span></h1>
    <div class="text-sm muted"><span data-lang="nav.balance"><?php echo Lang::t('nav.balance'); ?>:</span> <span class="text-emerald-400 font-semibold"><?php echo formatCurrency($balance); ?></span></div>
  </div>

  <?php if (!empty($error)): ?>
    <div class="mt-4 p-3 rounded-lg bg-red-500/10 border border-red-500/30 text-red-200" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
  <?php endif; ?>
  <?php if (!empty($success)): ?>
    <div class="mt-4 p-3 rounded-lg bg-emerald-500/10 border border-emerald-500/30 text-emerald-100" role="status"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
  <?php endif; ?>

  <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mt-6">
    <div class="card rounded-xl p-4">
      <h2 class="font-semibold mb-3 flex items-center gap-2"><i class="bi bi-plus-circle"></i> <span data-lang="admin.codes.generate.title"><?php echo Lang::t('admin.codes.generate.title'); ?></span></h2>
      <form method="post" class="space-y-3 js-submit-once">
        <?php echo csrfField(); ?>
        <div>
          <label class="text-sm muted" data-lang="admin.codes.generate.nominal"><?php echo Lang::t('admin.codes.generate.nominal'); ?></label>
          <input
            name="amount"
            inputmode="decimal"
            autocomplete="off"
            min="0.01"
            step="0.01"
            class="w-full px-3 py-2 rounded-lg"
            data-lang-placeholder="common.placeholder.nominal"
            placeholder="<?php echo htmlspecialchars(Lang::t('common.placeholder.nominal'), ENT_QUOTES, 'UTF-8'); ?>"
            required
          >
        </div>
        <button name="generate_code" value="1" class="btn js-submit-btn px-4 py-2 rounded-lg bg-indigo-500/20 hover:bg-indigo-500/30 transition" data-lang="admin.codes.generate.btn">
          <?php echo Lang::t('admin.codes.generate.btn'); ?>
        </button>
      </form>

      <?php if (!empty($generatedCode)): ?>
        <div id="generated-code-card" class="mt-4 p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/25">
          <div class="flex items-start justify-between gap-3 flex-wrap">
            <div class="min-w-0 flex-1">
              <div class="text-sm muted" data-lang="admin.codes.generate.result"><?php echo Lang::t('admin.codes.generate.result'); ?></div>
              <div id="generated-code" class="code-value text-2xl font-extrabold tracking-widest mt-1 font-mono"><?php echo htmlspecialchars($generatedCode, ENT_QUOTES, 'UTF-8'); ?></div>
              <?php if ($generatedAmount !== null): ?>
                <div class="text-sm text-emerald-200 mt-2">
                  <i class="bi bi-cash-coin mr-1"></i>
                  <span data-copy-amount-label>จำนวนเงิน</span>:
                  <strong><?php echo formatCurrency($generatedAmount); ?></strong>
                </div>
              <?php endif; ?>
            </div>

            <button
              type="button"
              class="btn js-copy-code shrink-0 px-4 py-2 rounded-lg bg-emerald-500/20 hover:bg-emerald-500/30 text-emerald-100 transition flex items-center gap-2"
              data-copy-code="<?php echo htmlspecialchars($generatedCode, ENT_QUOTES, 'UTF-8'); ?>"
              aria-label="คัดลอกโค้ดเติมเงิน"
            >
              <i class="bi bi-copy"></i>
              <span class="js-copy-label">คัดลอกโค้ด</span>
            </button>
          </div>
          <div class="text-xs muted mt-3" data-lang="admin.codes.generate.help"><?php echo Lang::t('admin.codes.generate.help'); ?></div>
        </div>
      <?php endif; ?>
    </div>

    <div class="card rounded-xl p-4">
      <h2 class="font-semibold mb-3 flex items-center gap-2"><i class="bi bi-wallet2"></i> <span data-lang="admin.codes.redeem.title"><?php echo Lang::t('admin.codes.redeem.title'); ?></span></h2>
      <form method="post" class="space-y-3 js-submit-once">
        <?php echo csrfField(); ?>
        <div>
          <label class="text-sm muted" data-lang="admin.codes.redeem.code"><?php echo Lang::t('admin.codes.redeem.code'); ?></label>
          <input
            name="code"
            autocomplete="off"
            autocapitalize="characters"
            spellcheck="false"
            maxlength="100"
            class="w-full px-3 py-2 rounded-lg uppercase tracking-widest"
            data-lang-placeholder="common.placeholder.code"
            placeholder="<?php echo htmlspecialchars(Lang::t('common.placeholder.code'), ENT_QUOTES, 'UTF-8'); ?>"
            required
          >
        </div>
        <button name="redeem_code" value="1" class="btn js-submit-btn px-4 py-2 rounded-lg bg-emerald-500/20 hover:bg-emerald-500/30 transition" data-lang="admin.codes.redeem.btn">
          <?php echo Lang::t('admin.codes.redeem.btn'); ?>
        </button>
        <div class="text-xs muted" data-lang="admin.codes.redeem.warning"><?php echo Lang::t('admin.codes.redeem.warning'); ?></div>
      </form>
    </div>
  </div>

  <div class="card rounded-xl p-4 mt-6">
    <h2 class="font-semibold mb-3 flex items-center gap-2"><i class="bi bi-clock-history"></i> <span data-lang="admin.codes.recent.title"><?php echo Lang::t('admin.codes.recent.title'); ?></span></h2>
    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="muted">
          <tr class="border-b border-white/10">
            <th class="text-left py-2 pr-4" data-lang="admin.codes.redeem.code"><?php echo Lang::t('admin.codes.redeem.code'); ?></th>
            <th class="text-left py-2 pr-4" data-lang="common.amount"><?php echo Lang::t('common.amount'); ?></th>
            <th class="text-left py-2 pr-4" data-lang="admin.users.table.status"><?php echo Lang::t('admin.users.table.status'); ?></th>
            <th class="text-left py-2 pr-4" data-lang="admin.codes.table.used_by"><?php echo Lang::t('admin.codes.table.used_by'); ?></th>
            <th class="text-left py-2 pr-4" data-lang="admin.codes.table.created"><?php echo Lang::t('admin.codes.table.created'); ?></th>
            <th class="text-left py-2" data-copy-action-label>จัดการ</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($recentCodes)): ?>
            <tr><td colspan="6" class="py-3 muted" data-lang="admin.codes.recent.empty"><?php echo Lang::t('admin.codes.recent.empty'); ?></td></tr>
          <?php else: foreach ($recentCodes as $c): ?>
            <?php
              $codeValue = isset($c['code']) && is_scalar($c['code']) ? (string) $c['code'] : '';
              $isUsed = ($c['status'] ?? '') === 'used';
            ?>
            <tr class="border-b border-white/5">
              <td class="py-2 pr-4 font-mono tracking-widest code-value"><?php echo htmlspecialchars($codeValue, ENT_QUOTES, 'UTF-8'); ?></td>
              <td class="py-2 pr-4"><?php echo formatCurrency($c['amount']); ?></td>
              <td class="py-2 pr-4">
                <?php if ($isUsed): ?>
                  <span class="px-2 py-1 rounded-full bg-yellow-500/15 text-yellow-200 border border-yellow-500/20" data-lang="admin.codes.status.used"><?php echo Lang::t('admin.codes.status.used'); ?></span>
                <?php else: ?>
                  <span class="px-2 py-1 rounded-full bg-emerald-500/15 text-emerald-100 border border-emerald-500/20" data-lang="admin.codes.status.unused"><?php echo Lang::t('admin.codes.status.unused'); ?></span>
                <?php endif; ?>
              </td>
              <td class="py-2 pr-4"><?php echo !empty($c['used_by_username']) ? htmlspecialchars($c['used_by_username'], ENT_QUOTES, 'UTF-8') : '-'; ?></td>
              <td class="py-2 pr-4 muted whitespace-nowrap"><?php echo htmlspecialchars((string) $c['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
              <td class="py-2 whitespace-nowrap">
                <button
                  type="button"
                  class="btn js-copy-code px-3 py-1.5 rounded-lg transition flex items-center gap-2 <?php echo $isUsed ? 'bg-white/5 text-white/40' : 'bg-indigo-500/15 hover:bg-indigo-500/25 text-indigo-100'; ?>"
                  data-copy-code="<?php echo htmlspecialchars($codeValue, ENT_QUOTES, 'UTF-8'); ?>"
                  aria-label="คัดลอกโค้ดเติมเงิน"
                  <?php echo $isUsed ? 'disabled title="โค้ดนี้ถูกใช้แล้ว"' : ''; ?>
                >
                  <i class="bi bi-copy"></i>
                  <span class="js-copy-label">คัดลอก</span>
                </button>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div id="copy-toast" class="copy-toast px-4 py-3 rounded-xl text-sm text-white" role="status" aria-live="polite"></div>

<script>
(function () {
  'use strict';

  var isThai = (document.documentElement.lang || '').toLowerCase().indexOf('th') === 0;
  var text = isThai ? {
    copyCode: 'คัดลอกโค้ด',
    copy: 'คัดลอก',
    copied: 'คัดลอกแล้ว',
    copySuccess: 'คัดลอกโค้ดเติมเงินแล้ว',
    copyFailed: 'คัดลอกไม่สำเร็จ กรุณาคัดลอกด้วยตนเอง',
    processing: 'กำลังดำเนินการ...',
    amount: 'จำนวนเงิน',
    action: 'จัดการ'
  } : {
    copyCode: 'Copy code',
    copy: 'Copy',
    copied: 'Copied',
    copySuccess: 'Redeem code copied',
    copyFailed: 'Copy failed. Please copy it manually.',
    processing: 'Processing...',
    amount: 'Amount',
    action: 'Action'
  };

  var toast = document.getElementById('copy-toast');
  var toastTimer = null;

  function showToast(message) {
    if (!toast) return;
    toast.textContent = message;
    toast.classList.add('show');
    window.clearTimeout(toastTimer);
    toastTimer = window.setTimeout(function () {
      toast.classList.remove('show');
    }, 2200);
  }

  function fallbackCopy(value) {
    var textarea = document.createElement('textarea');
    textarea.value = value;
    textarea.setAttribute('readonly', '');
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    textarea.style.pointerEvents = 'none';
    document.body.appendChild(textarea);
    textarea.focus();
    textarea.select();
    textarea.setSelectionRange(0, textarea.value.length);

    var copied = false;
    try {
      copied = document.execCommand('copy');
    } catch (e) {
      copied = false;
    }

    document.body.removeChild(textarea);
    return copied;
  }

  function copyText(value) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(value).then(function () {
        return true;
      }).catch(function () {
        return fallbackCopy(value);
      });
    }
    return Promise.resolve(fallbackCopy(value));
  }

  document.querySelectorAll('.js-copy-code').forEach(function (button) {
    var label = button.querySelector('.js-copy-label');
    var isMainButton = button.closest('#generated-code-card') !== null;

    if (label) {
      label.textContent = isMainButton ? text.copyCode : text.copy;
    }
    button.setAttribute('aria-label', text.copyCode);

    button.addEventListener('click', function () {
      if (button.disabled) return;

      var code = button.getAttribute('data-copy-code') || '';
      if (!code) {
        showToast(text.copyFailed);
        return;
      }

      copyText(code).then(function (copied) {
        if (!copied) {
          showToast(text.copyFailed);
          return;
        }

        var icon = button.querySelector('i');
        var originalIcon = icon ? icon.className : '';
        var originalText = label ? label.textContent : '';

        if (icon) icon.className = 'bi bi-check2-circle';
        if (label) label.textContent = text.copied;
        showToast(text.copySuccess);

        window.setTimeout(function () {
          if (icon) icon.className = originalIcon;
          if (label) label.textContent = originalText;
        }, 1600);
      });
    });
  });

  document.querySelectorAll('[data-copy-amount-label]').forEach(function (el) {
    el.textContent = text.amount;
  });
  document.querySelectorAll('[data-copy-action-label]').forEach(function (el) {
    el.textContent = text.action;
  });

  // ป้องกันการกดปุ่มซ้ำระหว่างที่คำขอกำลังถูกส่ง
  document.querySelectorAll('.js-submit-once').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      if (!form.checkValidity()) return;

      var button = event.submitter || form.querySelector('.js-submit-btn');
      if (!button || button.disabled) {
        event.preventDefault();
        return;
      }

      // เมื่อปิดใช้งาน submit button เบราว์เซอร์จะไม่ส่ง name/value ของปุ่มไปด้วย
      // จึงสำรองค่าลง hidden input ก่อน เพื่อให้ PHP ยังแยก generate/redeem ได้ถูกต้อง
      if (button.name) {
        var hiddenSubmit = document.createElement('input');
        hiddenSubmit.type = 'hidden';
        hiddenSubmit.name = button.name;
        hiddenSubmit.value = button.value || '1';
        form.appendChild(hiddenSubmit);
      }

      button.disabled = true;
      button.dataset.originalText = button.textContent;
      button.innerHTML = '<span class="inline-flex items-center gap-2"><i class="bi bi-arrow-repeat animate-spin"></i>' + text.processing + '</span>';
    });
  });

  // หลังสร้างโค้ดสำเร็จ เลื่อนให้เห็นผลลัพธ์ทันที โดยเฉพาะหน้าจอมือถือ
  var generatedCard = document.getElementById('generated-code-card');
  if (generatedCard && window.location.hash !== '#generated-code-card') {
    window.setTimeout(function () {
      generatedCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }, 120);
  }
})();
</script>
</body>
</html>
