# Behavior invariants and Dashboard function inventory

These are contracts to preserve, grounded in source. Runtime regression status is UNTESTED, not PASS. Existing defects are not silently repaired by redesign; reproduce and separate any behavior change.

## Dashboard functions

| Function / trigger | DOM / PHP / JS owner | Data / state / failures | Must preserve | Evidence |
|---|---|---|---|---|
| Open Dashboard | dashboard.php -> requireLogin + exact isUser | invalid/expired session or role redirects; verification gate inherited | Yes | dashboard.php:2-10; auth.php::requireLogin |
| Welcome / status | server header + data-lang | escaped username; active vs banned style branch (reachability constrained by auth) | Yes, do not loosen auth to display fixture | dashboard.php:86 onward |
| Balance | dashboard.php stats + nav separate balance render | getUserBalance; server formatting/currency; no Dashboard-specific balance polling | Yes | dashboard.php:15; user/nav.php:17-26 |
| Bought key count / My Keys CTA | server stat card | cgoCountUnifiedUserKeys; normal mykeys.php anchor | Yes | dashboard.php:13; stats markup |
| Monthly rank / board CTA | rankUserSnapshot + rankLabel | available, no rank position, unavailable message | Yes | dashboard.php:24 and monthly rank branch |
| Product/platform summary | getActiveCatalogueSummary | default + settings/data-dependent platforms and counts; escaped query links | Yes | dashboard.php:20-23 and catalogPlatforms loop |
| Category links | catalogue category map | empty state vs dynamic category list; links use raw category keys through http_build_query | Yes | dashboard.php category section |
| Store CTA | buy.php normal anchor | preserves Store as landing and independent commerce runtime | Yes | dashboard.php:176 and category/platform anchors |
| Recent public purchases | includes/purchase_activity.php + purchase-activity.js | initial escaped rows, empty state, rotation, ETag, JSON/auth failures, retry | Yes | dashboard.php:102-106; fragment:359; JS:219-305 |
| Recent Keys panel | dashboard.php `$userKeys` branch | no assignment found in active include chain; empty() returns true when undefined; do not invent key retrieval | Preserve observed baseline pending reproduction | dashboard.php:284; assignment search only in other pages/dormant endpoint |
| Navigation / logout / email reminder | user/nav.php, nav.js, accountRecoveryRenderEmailNotice | drawer/dropdown, balance, TH/EN, POST logout+csrfField; recovery notice conditional | Yes | user/nav.php; auth/recovery helpers |
| Music | parent app.php + optional renderMusicPlayer; direct escape uses child player | enabled/role/playlist gates, persisted playback, YouTube error states | Yes | music helper:150; music-player.js:4-24 |

## Global protected contracts

- Keep requireLogin/role validation/session renewal, remembered-device handling, account verification and recovery requirements. UI route availability cannot replace server guards.
- Preserve CSRF fields, header/token formats, POST method restrictions and logout behavior. Do not rename hidden fields merely to suit a design system.
- Keep one-time purchase-token scope/consume behavior distinct from CSRF. Do not issue or consume additional tokens on visual render changes.
- Preserve server-derived effective role/account pricing, canonical variant validation, quantity/stock caps, atomic local debit/key assignment, remote unresolved-order blockers, ledger/refund/conflict semantics.
- Preserve supplier API protocol, stateless credential dispatch, IP/key/webhook behavior and session-lock release at existing boundaries.
- Preserve stock polling scope/token renewal, cron fallback gates and late-callback navigation guards. “Processing timeout” is not automatically a purchase failure/refund.
- Preserve wallet/slip/TrueMoney/Binance feature gates, pending states, duplicate protection, amount/recipient validation and histories. Never simplify payment flow for visual consistency.
- Preserve escaped user/product/key content and safe downloadable URLs. Copy/download control loss is functional regression.
- Preserve shell child URL/query/hash, native Back/Forward, bfcache semantics and optional music continuity. Test direct escape separately from direct GET handoff.
- Preserve TH/EN, configured currency/rate/branding/category labels and unrecognized translation fallback.

## Proposed invariants not yet demonstrated by baseline

“No keyboard opens without explicit input interaction” conflicts with Store's quantity focus source path. “Activity continues after bfcache restore” is not proven and may be affected by pagehide stop. “Recent keys show purchased items” is not a confirmed Dashboard behavior in this snapshot. These need baseline observation and a separate bug/requirement decision, not stealth fixes inside the Dashboard visual pilot.
