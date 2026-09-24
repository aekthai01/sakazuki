#!/usr/bin/env python3
"""Build the reviewed xNearby main-flow integration from sanitized main.

This is a one-shot branch helper. The workflow verifies that the generated
functions.php and settings.php are byte-for-byte identical to the reviewed
copies before it is allowed to commit.
"""
from __future__ import annotations

import hashlib
from pathlib import Path

EXPECTED_FUNCTIONS_SHA256 = "a2c7532b31a127858b64cad0343849c11b30805b0694e0920d8d8853d19a6aa2"
EXPECTED_SETTINGS_SHA256 = "93a5aa6672e6c2fe9d128605b6c8849a9729cd188b56ec5733b32c81a0fe2272"


def replace_once(text: str, old: str, new: str, label: str) -> str:
    count = text.count(old)
    if count != 1:
        raise SystemExit(f"{label}: expected exactly 1 match, got {count}")
    return text.replace(old, new, 1)


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def patch_functions() -> None:
    path = Path("public_html/includes/functions.php")
    text = path.read_text(encoding="utf-8")
    text = replace_once(
        text,
        "require_once __DIR__ . '/slip_debug.php';\nrequire_once __DIR__ . '/product_image_lifecycle.php';",
        "require_once __DIR__ . '/slip_debug.php';\nrequire_once __DIR__ . '/slip_nearby.php';\nrequire_once __DIR__ . '/product_image_lifecycle.php';",
        "functions require slip_nearby",
    )
    target = "verifySlipWithEasyslip($imageBase64, $verificationRemark, ["
    count = text.count(target)
    if count != 2:
        raise SystemExit(f"functions provider calls: expected exactly 2 matches, got {count}")
    text = text.replace(
        target,
        "verifySlipWithConfiguredProvider($imageBase64, $verificationRemark, [",
    )
    path.write_text(text, encoding="utf-8")


def patch_settings() -> None:
    path = Path("public_html/admin/settings.php")
    text = path.read_text(encoding="utf-8")

    text = replace_once(
        text,
        "        $esEnabled = settingsPostString('easyslip_enabled') === '1';\n        $esApiKey = trim(settingsPostString('easyslip_api_key'));",
        "        $esEnabled = settingsPostString('easyslip_enabled') === '1';\n        $esProvider = strtolower(trim(settingsPostString('slip_verification_provider', 'easyslip')));\n        $esApiKey = trim(settingsPostString('easyslip_api_key'));\n        $nearbyApiKey = trim(settingsPostString('slipverify_nearby_api_key'));",
        "settings provider inputs",
    )
    text = replace_once(
        text,
        "        $existingEasySlipApiKey = trim((string) (getSetting('easyslip_api_key') ?? ''));",
        "        $existingEasySlipApiKey = trim((string) (getSetting('easyslip_api_key') ?? ''));\n        $existingNearbyApiKey = trim((string) (getSetting('slipverify_nearby_api_key') ?? ''));",
        "settings existing nearby key",
    )
    text = replace_once(
        text,
        "        if ($esEnabled && $esApiKey === '' && $existingEasySlipApiKey === '') {\n            $error = Lang::t('admin.settings.error.easyslip_key_required');\n        } elseif ($esApiKey !== '' && (strlen($esApiKey) < 10 || strlen($esApiKey) > 512 || preg_match('/[\\x00-\\x1F\\x7F]/', $esApiKey))) {\n            $error = Lang::t('admin.settings.error.easyslip_key');",
        "        if (!in_array($esProvider, ['easyslip', 'nearby'], true)) {\n            $error = getAppLang() === 'en'\n                ? 'Invalid bank-slip verification provider.'\n                : 'ผู้ให้บริการตรวจสอบสลิปไม่ถูกต้อง';\n        } elseif ($esEnabled && $esProvider === 'easyslip' && $esApiKey === '' && $existingEasySlipApiKey === '') {\n            $error = Lang::t('admin.settings.error.easyslip_key_required');\n        } elseif ($esEnabled && $esProvider === 'nearby' && $nearbyApiKey === '' && $existingNearbyApiKey === '') {\n            $error = getAppLang() === 'en'\n                ? 'xNearby SlipVerify API key is required when xNearby is selected.'\n                : 'กรุณากรอก xNearby SlipVerify API Key เมื่อเลือกใช้ xNearby';\n        } elseif ($esApiKey !== '' && (strlen($esApiKey) < 10 || strlen($esApiKey) > 512 || preg_match('/[\\x00-\\x1F\\x7F]/', $esApiKey))) {\n            $error = Lang::t('admin.settings.error.easyslip_key');\n        } elseif ($nearbyApiKey !== '' && (strlen($nearbyApiKey) < 12 || strlen($nearbyApiKey) > 512 || preg_match('/[\\x00-\\x20\\x7F]/', $nearbyApiKey))) {\n            $error = getAppLang() === 'en'\n                ? 'xNearby SlipVerify API key format is invalid.'\n                : 'รูปแบบ xNearby SlipVerify API Key ไม่ถูกต้อง';",
        "settings provider validation",
    )
    text = replace_once(
        text,
        "                    ['easyslip_enabled', $esEnabled ? '1' : '0'],\n                    ['easyslip_receiver_name', $esReceiverNameTh],",
        "                    ['easyslip_enabled', $esEnabled ? '1' : '0'],\n                    ['slip_verification_provider', $esProvider],\n                    ['easyslip_receiver_name', $esReceiverNameTh],",
        "settings provider write",
    )
    text = replace_once(
        text,
        "                if ($esApiKey !== '') {\n                    $writes[] = ['easyslip_api_key', $esApiKey];\n                }\n                foreach ($writes as $write) {",
        "                if ($esApiKey !== '') {\n                    $writes[] = ['easyslip_api_key', $esApiKey];\n                }\n                if ($nearbyApiKey !== '') {\n                    $writes[] = ['slipverify_nearby_api_key', $nearbyApiKey];\n                }\n                // Legacy JWT/Bearer auth is retired by xNearby; keep stale tokens inert.\n                $writes[] = ['slipverify_nearby_access_token', ''];\n                foreach ($writes as $write) {",
        "settings nearby key write",
    )
    text = replace_once(
        text,
        "                $success = Lang::t('admin.settings.success.easyslip');\n                logHistory($_SESSION['user_id'], 'update_easyslip', 'Updated EasySlip API and receiver settings');",
        "                $success = getAppLang() === 'en'\n                    ? 'Bank-slip verification settings updated.'\n                    : 'บันทึกการตั้งค่าตรวจสอบสลิปแล้ว';\n                logHistory($_SESSION['user_id'], 'update_bank_slip_verification', 'Updated bank-slip provider, credentials, and shared receiver settings');",
        "settings success message",
    )
    text = replace_once(
        text,
        "                                <span class=\"text-gray-300\" data-lang=\"admin.settings.easyslip_enabled\"><?php echo Lang::t('admin.settings.easyslip_enabled'); ?></span>",
        "                                <span class=\"text-gray-300\"><?php echo $currentLang === 'en' ? 'Enable bank-slip verification' : 'เปิดระบบตรวจสอบสลิปธนาคาร'; ?></span>",
        "settings enabled label",
    )
    text = replace_once(
        text,
        "                            <div>\n                                <label class=\"text-gray-400 text-sm mb-2 block\" data-lang=\"admin.settings.api_key\"><?php echo Lang::t('admin.settings.api_key'); ?></label>\n                                <input type=\"password\" name=\"easyslip_api_key\"",
        "                            <div>\n                                <label class=\"text-gray-400 text-sm mb-2 block\">\n                                    <?php echo $currentLang === 'en' ? 'Verification provider' : 'ผู้ให้บริการตรวจสอบสลิป'; ?>\n                                </label>\n                                <?php $slipProvider = strtolower(trim((string) getSetting('slip_verification_provider', 'easyslip'))); ?>\n                                <select name=\"slip_verification_provider\"\n                                        class=\"glass border border-green-500/30 rounded-lg p-2 w-full bg-transparent text-white\">\n                                    <option value=\"easyslip\" <?php echo $slipProvider !== 'nearby' ? 'selected' : ''; ?>>EasySlip</option>\n                                    <option value=\"nearby\" <?php echo $slipProvider === 'nearby' ? 'selected' : ''; ?>>xNearby SlipVerify v2</option>\n                                </select>\n                                <small class=\"text-gray-500 text-xs\">\n                                    <?php echo $currentLang === 'en'\n                                        ? 'Both providers use the same receiver name and bank account configured below.'\n                                        : 'ทั้งสองผู้ให้บริการใช้ชื่อผู้รับและบัญชีธนาคารชุดเดียวกันที่ตั้งไว้ด้านล่าง'; ?>\n                                </small>\n                            </div>\n\n                            <div>\n                                <label class=\"text-gray-400 text-sm mb-2 block\">EasySlip API Key</label>\n                                <input type=\"password\" name=\"easyslip_api_key\"",
        "settings provider selector",
    )
    text = replace_once(
        text,
        "                                <small class=\"text-gray-500 text-xs\" data-lang=\"admin.settings.easyslip_api_hint\"><?php echo Lang::t('admin.settings.easyslip_api_hint'); ?></small>\n                            </div>\n\n                            <div>\n                                <label class=\"text-gray-400 text-sm mb-2 block\" data-lang=\"admin.settings.easyslip_phone\"><?php echo Lang::t('admin.settings.easyslip_phone'); ?></label>",
        "                                <small class=\"text-gray-500 text-xs\" data-lang=\"admin.settings.easyslip_api_hint\"><?php echo Lang::t('admin.settings.easyslip_api_hint'); ?></small>\n                            </div>\n\n                            <div>\n                                <label class=\"text-gray-400 text-sm mb-2 block\">xNearby SlipVerify API Key</label>\n                                <input type=\"password\" name=\"slipverify_nearby_api_key\"\n                                       class=\"glass border border-green-500/30 rounded-lg p-2 w-full bg-transparent text-white font-mono text-sm\"\n                                       value=\"\" autocomplete=\"new-password\"\n                                       placeholder=\"<?php echo $currentLang === 'en' ? 'Leave blank to keep the current API key' : 'เว้นว่างเพื่อใช้ API Key เดิม'; ?>\">\n                                <small class=\"text-gray-500 text-xs\">\n                                    <?php echo $currentLang === 'en'\n                                        ? 'Uses X-API-Key with xNearby SlipVerify v2. Legacy Bearer/JWT tokens are not used.'\n                                        : 'ใช้ X-API-Key กับ xNearby SlipVerify v2 และไม่ใช้ Bearer/JWT แบบเก่า'; ?>\n                                </small>\n                            </div>\n\n                            <div>\n                                <label class=\"text-gray-400 text-sm mb-2 block\" data-lang=\"admin.settings.easyslip_phone\"><?php echo Lang::t('admin.settings.easyslip_phone'); ?></label>",
        "settings nearby API field",
    )
    path.write_text(text, encoding="utf-8")


def write_ci() -> None:
    path = Path(".github/workflows/slip-nearby-ci.yml")
    path.write_text(
        """name: Bank Slip Provider CI

on:
  push:
    branches:
      - main
    paths:
      - public_html/includes/functions.php
      - public_html/includes/slip_nearby.php
      - public_html/admin/settings.php
      - public_html/admin/slipverify.php
      - tests/slip_nearby_test.php
      - .github/scripts/secret_guard.py
      - .github/workflows/slip-nearby-ci.yml
  pull_request:
    branches:
      - main
    paths:
      - public_html/includes/functions.php
      - public_html/includes/slip_nearby.php
      - public_html/admin/settings.php
      - public_html/admin/slipverify.php
      - tests/slip_nearby_test.php
      - .github/scripts/secret_guard.py
      - .github/workflows/slip-nearby-ci.yml
  workflow_dispatch:

permissions:
  contents: read

jobs:
  verify:
    runs-on: ubuntu-latest
    timeout-minutes: 10

    steps:
      - name: Checkout repository
        uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          extensions: curl, json, mbstring, mysqli, fileinfo
          coverage: none

      - name: Guard committed secrets
        run: python3 .github/scripts/secret_guard.py

      - name: Lint bank-slip provider files
        shell: bash
        run: |
          set -euo pipefail
          php -l public_html/includes/functions.php
          php -l public_html/includes/slip_nearby.php
          php -l public_html/admin/settings.php
          php -l tests/slip_nearby_test.php

      - name: Guard main-flow integration
        shell: bash
        run: |
          set -euo pipefail
          grep -q "require_once __DIR__ . '/slip_nearby.php';" public_html/includes/functions.php
          test "$(grep -Fc 'verifySlipWithConfiguredProvider($imageBase64, $verificationRemark, [' public_html/includes/functions.php)" -eq 2
          grep -q "slip_verification_provider" public_html/admin/settings.php
          grep -q "slipverify_nearby_api_key" public_html/admin/settings.php
          grep -q "easyslip_receiver_name" public_html/admin/settings.php
          grep -q "easyslip_account_number" public_html/admin/settings.php
          if grep -Eq "slipverify_nearby_(receiver_name|bank_code|account_no)" public_html/admin/settings.php; then
            echo "Nearby must reuse the existing store receiver settings."
            exit 1
          fi
          test ! -e public_html/admin/slipverify.php
          test ! -e .github/workflows/slipverify-production-deploy.yml

      - name: Run Nearby provider contract tests
        shell: bash
        run: |
          set -euo pipefail
          php tests/slip_nearby_test.php
""",
        encoding="utf-8",
    )


def main() -> int:
    patch_functions()
    patch_settings()
    write_ci()

    checks = [
        (Path("public_html/includes/functions.php"), EXPECTED_FUNCTIONS_SHA256),
        (Path("public_html/admin/settings.php"), EXPECTED_SETTINGS_SHA256),
    ]
    for path, expected in checks:
        actual = sha256(path)
        if actual != expected:
            raise SystemExit(
                f"Reviewed-file hash mismatch for {path}: expected {expected}, got {actual}"
            )
        print(f"Reviewed-file hash verified: {path} {actual}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
