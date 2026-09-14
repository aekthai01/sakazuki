Redeem Code Fix - 2026-09-10

Changed files:
1) public_html/includes/functions.php
2) public_html/includes/automation.php

What this fixes:
- Detects an older transactions.type schema that does not support redeem_code before changing wallet state.
- Changes the storefront schema marker so maintenance runs for this deployment.
- Prevents the automation marker fast-path from skipping maintenance when not all known transaction types are supported.
- Makes redeem failure logs identify the exact stage and gives the UI a safe RDC-* reference ID.
- Treats history as secondary audit data after the authoritative transaction + wallet ledger commit, matching the newer atomic purchase flow.

Deploy:
1) Back up the database and the two existing PHP files.
2) Upload the two changed files, preserving paths.
3) Run the existing maintenance worker once so transactions.type can be migrated safely outside customer traffic:
   php /PATH/TO/private/automation_worker.php --verbose
   OR run your configured automation_runner.php maintenance job.
4) Verify that maintenance reports success.
5) Generate a NEW 6-character top-up code with a small amount and redeem it once.

Read-only checks if it still fails:
- Inspect the PHP error log for the RDC-* reference ID and stage.
- Inspect transactions.type with:
  SHOW FULL COLUMNS FROM transactions LIKE 'type';
- Confirm redeem_code is unused and has a positive amount before the test.

Do not run ALTER TABLE manually during customer checkout traffic. The existing maintenance path already performs the schema-only migration with bounded lock waiting.
