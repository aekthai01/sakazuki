# Runtime dependency map — source trace, not runtime certification

Baseline: `74bb4fc38236bdd5a3ae90477b3e8df82844e9a6` (main at audit start, 2026-09-25). All execution observations are UNTESTED. See ASSET_CLASSIFICATION.md for all 18 JS/CSS assets and SOURCE_EVIDENCE.md for immutable file/line links. The Figma generation request is awaiting plan selection; no FigJam file was confirmed created.

## Verified source paths

| Entry | Guard / consumer | Render / JS owner | Endpoint / state | Scope |
|---|---|---|---|---|
| index.php / login success | auth.php::redirectByRole | User/Reseller buy.php; Admin dashboard.php | auth validation and verification before role redirect | Dashboard is not customer landing |
| app.php | requireLogin; normalize role route | fixed same-origin iframe; app-shell.js; optional parent music | child load/message -> same-origin validation -> parent replaceState | Parent is not content scroll owner |
| Role nav | included by role pages | shell-bridge.js, SweetAlert2, inline nav CSS, nav.js, security.js, PHP language/currency config, lang.js, inline loader init, music helper | direct eligible GET -> shell; in iframe -> postMessage plus history wrappers | POST skips direct redirect; __shell=0 escape |
| user/dashboard.php | requireLogin + exact isUser | server main, purchase_activity fragment; tailwind-built + local style | balance, key count, rank, catalogue summary; normal anchor navigation | No fast-page or dynamic-root contract |
| includes/purchase_activity.php | Dashboard/Store consumers | inline scoped CSS; deferred purchase-activity.js | purchase_activity.php -> ETag JSON -> rows/signature/count; timers rotate/poll | Auth 401/403 stops polling; pagehide stops controller |
| user/buy.php | login; user/reseller/admin allowed | inline Store handlers + role nav, marquee, activity | normal filter GET; purchase POST; cgo inventory / order status | Critical commerce path |
| reseller/buy.php | own role guard and independent page | separate markup/inline state; shared helpers | own purchase scope/role pricing and polling | Do not copy User markup blindly |
| Admin filter pages | requireAdmin path; see route index | instant-filter.js replaces listed panels, restores field focus, updates child history | GET same page + X-Instant-Filter; redirect fallback; instantfilter:updated | 10 loaders; not User Dashboard or Store |
| user/account.php, history.php, mykeys.php, rankings.php | individual role guards | ordinary page loads + local inline actions; shared nav | own forms/filter links/copy/detail states | Dormant user-fast-pages is not current action owner |
| user/reseller_program.php | User flow / settings | own application form and content | reseller application workflow | Separate state inventory required before redesign |
| user/reseller/admin deposit wrappers | role-specific guard | includes/deposit.php shared renderer | slip, Binance, giftcard, wallet actions | Hidden form/feature state must remain |
| verify_account.php | requireLogin(true), verification helpers | standalone verification UI | OTP send/verify and completion redirect | Shell promotion list omits this page |
| reseller/api_store.php | credential dispatch before requireReseller | JSON API if credential present; HTML otherwise | storeBridgeDevnoodServeApi or API management forms | Browser and machine contract share a route |
| api/store/v1.php + diagnostic/ping | route-specific API entry | store_bridge.php | API auth, inventory, balance, order/status | No nav/iframe assumption |
| user/api_store.php | requireLogin | redirect buy.php | no API dashboard rendered here | Redirect compatibility entry |
| line_callback.php | unconditional 404 | plain Not Found | none | Disabled entry, not OAuth screen |

## Commerce and server coupling

`user/buy.php:133` -> `requireCsrfToken()` -> `cgoConsumePurchaseToken()` -> `cgoPurchaseUnifiedVariant()` (cheatgame.php:4917). The unified function validates identifiers/quantity, resolves canonical variant duration and blocks unresolved remote orders before selecting stock source. Local purchase uses `functions.php::purchaseKeysAtomic` (11812): transactional user/key row locking, server price selection (including account override), balance and key ownership changes. Remote source handling lives in cheatgame/store_bridge; do not move or duplicate it in a presentation rewrite.

`cgo_order_status.php:18` requires CSRF and POST, validates order/source/user, releases session lock and calls the relevant storefront state function. Responses distinguish completed, conflict, refunded and deadline-exceeded ambiguity. A status request may reconcile; it is not a static read of a harmless JSON file. Browser callbacks must respect the Store navigation guard before redirects.

`cgo_inventory.php:18` allows GET only for token renewal; POST requires CSRF and scope validation. It derives role/user on server, checks cron heartbeat and distinguishes customer snapshot/refresh from admin diagnostics. Source inventory must preserve token-renewal/retry, displayed product/variant scope, temporary unavailability and callbacks while modal/navigation state changes.

`includes/functions.php` depends on wallet/commerce/integrity helpers. `wallet_ledger.php::walletLedgerRecordMovement`, transaction integrity and supplier reconciliation are server state owners. Route/form rendering is not authorization. This audit maps boundaries; it does not claim a complete transaction/security audit or prove race freedom.

## Background paths that affect UI

| Entry / gate | Function owner | Effect visible to UI | Observation |
|---|---|---|---|
| private/automation_worker.php; CLI only | automationPrepareStorefrontSchemas; automationRunDueJobs | pending-order reconciliation, stock/catalogue, slip/history, read models, cleanup | Actual scheduler configuration UNKNOWN |
| automation_runner.php; HTTP signed auth or CLI | critical vs maintenance allowlists and budgets | supplier/inventory/order state, schema/diagnostics | Never invoked in audit |
| cgo_inventory.php; unhealthy heartbeat | bounded automation fallback | refreshed stock/catalogue and pending states | Exact deployment flags/heartbeat UNKNOWN |
| auth.php bootstrap | keyHistoryScheduleAutoCleanup -> shutdown -> keyHistoryCleanupRun(false) | key/history cleanup after normal requests | GET can schedule writes; CLI and skip constant excluded |
| purchase_activity.php | SKIP_KEY_HISTORY_AUTO_CLEANUP before auth include | lightweight feed without that cleanup scheduling | Not proof other auth bootstrap has zero writes |
| webhook_cheatgame.php | provider webhook validation and reconciliation | remote order completion / histories | External provider delivery/config UNKNOWN |
| slip_maintenance.php / private/slip_history_migrate.php | maintenance/reconciliation | deposit/job and history state | Invocation and deployment configuration UNKNOWN |

The job definitions in automation.php:1216 include pending_orders, slip_reconciliation, cgo_inventory, supplier_catalog, cgo_catalog, shared_history, shared_binance_history, commerce_center, rate_limit_cleanup, history_cleanup, product_image_cleanup and product_image_optimizer. HTTP allowlists differ from all CLI jobs. Declared intervals are not proof of installed cron frequency.

## Dormant branch — do not attach to current graph

No current loader was found for fast-nav.js, user-fast-pages.js, dashboard-live.js, app.js, style.css, global-upgrades.css, tailwind-addon.css. The dormant fast-nav contains body.className replacement and main caching; that is a reason not to revive it, not evidence that active pages currently replace their body classes. Dashboard dynamic endpoint/fragment remain reachable source candidates; absence of a current client does not authorize removal or bypassing auth.

## External / dynamic boundaries

Bootstrap Icons CDN, SweetAlert2 CDN, optional YouTube iframe API and font requests have real source consumers. Settings-derived branding, title icons, category translation, currency/rate and music configuration change render output. Their actual values, CDN cache state, PHP auto-prepend behavior and host rewrite enforcement are UNKNOWN. JS file existence, filemtime calls and file comments never establish execution by themselves.
