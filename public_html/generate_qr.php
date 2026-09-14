<?php
/**
 * PromptPay QR generation is intentionally disabled until the integration is
 * completed and tested. Slip verification and the EasySlip receiver settings
 * are unaffected by this endpoint.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
http_response_code(410);
echo json_encode([
    'success' => false,
    'message' => 'ระบบสร้าง PromptPay QR ยังไม่เปิดใช้งาน',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;
