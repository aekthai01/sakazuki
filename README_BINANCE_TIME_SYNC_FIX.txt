Binance Time Sync Fix - 2026-09-10
==================================

Purpose
-------
Fix Binance -1021 INVALID_TIMESTAMP on shared hosting without requiring SSH,
NTP access, or server clock changes. The project now synchronizes signed
request timestamps against Binance GET /api/v3/time.

Files
-----
public_html/includes/binance_time.php       NEW
public_html/includes/binance.php            REPLACE
public_html/includes/binance_giftcard.php   REPLACE
public_html/automation_runner.php            REPLACE

No database migration or SQL is required for this patch.

What changed
------------
1. Shared Binance clock synchronization with a bounded HTTPS request.
2. Gift Card and TRC20 signed requests use Binance-adjusted timestamps.
3. If Binance returns -1021, the request performs one forced time resync and
   one retry only.
4. TRC20 Off-chain history startTime/endTime use the adjusted clock too.
5. mode=maintenance now includes a non-sensitive binance_clock diagnostic:
   status, offset_ms, applied_offset_ms, rtt_ms, php_int_bits,
   server_time_ms, local_midpoint_ms.
6. Logs do not include API key, secret key, signature, signed payload, or raw
   Binance response.

After upload
------------
1. Run the existing automation_runner.php URL with mode=maintenance.
2. Confirm binance_clock.status is "ok".
3. Record offset_ms, applied_offset_ms, rtt_ms and php_int_bits.
4. In Admin Settings, press the existing Binance Gift Card API test button.
5. Test the TRC20 deposit verification flow.

Interpretation
--------------
offset_ms:
  Estimated hosting-clock difference relative to Binance, corrected roughly
  for half of network round-trip time.

applied_offset_ms:
  Conservative offset actually used when signing. It anchors to response
  arrival so the generated timestamp tends to be slightly behind Binance
  rather than crossing Binance's strict future-time boundary.

rtt_ms:
  Round-trip time for GET /api/v3/time.

php_int_bits:
  PHP integer width for diagnostics. Signed timestamps themselves are kept as
  decimal strings so the integration does not depend on 64-bit PHP integers.

Rollback
--------
Restore the previous versions of the three replaced files and remove
public_html/includes/binance_time.php.
