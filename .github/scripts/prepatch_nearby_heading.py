#!/usr/bin/env python3
from pathlib import Path

path = Path('public_html/admin/settings.php')
text = path.read_text(encoding='utf-8')
old = '''                        <!-- EasySlip API Settings -->
                        <div class="border-t border-green-500/30 my-6"></div>
                        <h6 class="mb-4 text-green-300 font-semibold flex items-center gap-2" data-lang="admin.settings.easyslip_title">
                            <i class="bi bi-receipt-cutoff text-green-400"></i>
                            <?php echo Lang::t('admin.settings.easyslip_title'); ?>
                        </h6>'''
new = '''                        <!-- Bank Slip Verification Settings -->
                        <div class="border-t border-green-500/30 my-6"></div>
                        <h6 class="mb-4 text-green-300 font-semibold flex items-center gap-2">
                            <i class="bi bi-receipt-cutoff text-green-400"></i>
                            <?php echo $currentLang === 'en' ? 'Bank Slip Verification' : 'ตรวจสอบสลิปธนาคาร'; ?>
                        </h6>'''
count = text.count(old)
if count != 1:
    raise SystemExit(f'settings section heading: expected exactly 1 match, got {count}')
path.write_text(text.replace(old, new, 1), encoding='utf-8')
