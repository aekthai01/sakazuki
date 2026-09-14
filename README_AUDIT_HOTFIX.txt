Historical Patches Audit Hotfix - 2026-09-11

Apply after the previously deployed patches and Dashboard Catalog Hotfix.

Files:
- public_html/includes/cheatgame.php
  * Batch local-stock lookups beyond 60 products in inventory snapshots.
  * Preserve search filtering on legacy history fallback.
  * Fail back to legacy history search if an optimized search summary query fails.
- public_html/admin/transactions.php
  * Cache failed/missing optional-table schema fallback attempts within one request.

No schema migration is required.
