# Test matrix and evidence status

No application behavior changed. No new tests added for documentation. Existing tests were inventoried, not falsely reported as executed. PHP is unavailable on PATH and an authenticated isolated database/runtime has not been established. All browser/device and PHP regression rows below are **UNTESTED** unless explicitly described as source evidence.

## Completed evidence checks

| Check | Result | What it proves / does not prove |
|---|---|---|
| Fetch pinned main source | 190 files verified against Git blob SHA-1 | Exact source snapshot; not runtime correctness |
| Compare pre-redesign baseline...main | Only Master Plan and Handoff differ; rollback in ancestry | Application tree restored in GitHub; not host parity |
| Regenerate entry/asset inventory | 94 PHP entry candidates; 18 JS/CSS assets | Enumeration beyond menu; access still guarded/server dependent |
| Second-pass source search | 166 source/access-rule files; preserved line evidence | Reference discovery; not branch or interaction coverage |
| Workflow trigger review | Audit docs do not match production FTP PR path; no workflow dispatch | Draft publication does not intentionally deploy |
| Secret guard | Existing .github/scripts/secret_guard.py executed and passed | Credential-pattern check only; not security/functional certification |
| Audit change scope | Original application/config/workflow file hashes unchanged; git diff --check passed | No PHP/JS/CSS changes; no UI regression test claimed |

## Runtime setup prerequisites

Use a non-production PHP/MySQL instance with matching schema and synthetic Admin/User/Reseller, verified/unverified, active/disabled users; synthetic stock, special prices, ranks, long usernames/categories, empty and populated feeds. Disable real email, payment, supplier, webhook and cron delivery; substitute documented test endpoints where needed. Do not remove auth/CSRF, load dormant assets, or alter production code to fabricate a screenshot. Confirm server access rules and runtime config. Capture commit, route, role, settings, viewport, browser, network manifest, console errors and observed assertions for each run.

## Viewport and context matrix

Widths: **360, 390, 412, 430, 768, 1024, 1200, 1366, 1440, 1536, 1920**. Add 1535 for navigation breakpoint boundary; test 200% desktop zoom and larger device font settings. Heights and orientation must be recorded, not assumed. Mobile emulation verifies layout/touch events; it cannot certify Android/iOS virtual keyboard behavior.

Contexts for Dashboard pilot:

1. `/app.php?path=user/dashboard.php` as verified User.
2. Ordinary `/user/dashboard.php` GET -> expected shell handoff.
3. `/user/dashboard.php?__shell=0` intentional direct escape.
4. Existing shell with expired/unverified user; wrong-role request.
5. TH and EN, music enabled/disabled, empty/populated/long data.

## Exact pilot acceptance cases

| ID | Action | Required assertion | Evidence | Status |
|---|---|---|---|---|
| P01 | Login as each role | User/Reseller land Store; Admin Dashboard | URL + network redirects | UNTESTED |
| P02 | Open Dashboard in all 3 entry contexts | Correct User guard, one main, expected bridge/parent; no dormant assets in network | DOM + resource manifest | UNTESTED |
| P03 | Vertical touch scroll; horizontal table when reachable | Correct scroll owner, no inaccessible content/unintended x overflow | Video + scroll metrics | UNTESTED |
| P04 | Drawer open/close, overlay tap, Escape, 1535/1536 crossing | Reachable logout/footer; correct aria; scroll unlocked; no resize-induced close within same breakpoint | Interaction trace | UNTESTED |
| P05 | Music expand/play/navigation/collapse | Single parent player in shell; no blocked child controls; continuity | Video + DOM count | UNTESTED |
| P06 | Activity empty/populated, changed signature, 304, 401/403, offline | Preserve rotation/update/auth-stop/backoff; no duplicate timers | Network + DOM assertions | UNTESTED |
| P07 | Back/Forward, reload, query/hash, real bfcache | Correct child and parent URL; feed resumes as approved baseline; no stale loader | pageshow.persisted + request trace | UNTESTED |
| P08 | TH/EN with stale opposite localStorage | Server language wins, layout intact, loader releases | Screenshots + runtime assertions | UNTESTED |
| P09 | Platform/category/Store/My Keys/ranking anchors | Exact destination/query; ordinary navigation; no revived fast-nav | Link + request assertions | UNTESTED |
| P10 | Long/empty/unavailable rank/catalogue/status data | All server-rendered branches remain readable; no lost functions | Fixture screenshots | UNTESTED |
| P11 | No input action, then explicit account/input tap and keyboard dismiss | Dashboard doesn't unexpectedly open keyboard; visible focused control; restore scroll | Actual Android/iOS recording | UNTESTED |
| P12 | Logout + invalid/expired CSRF in isolated test | Original method and rejection behavior remain; auth redirect escapes shell appropriately | Server response + URL | UNTESTED |
| P13 | Toggle reduced motion / keyboard navigation / font zoom | Visible focus, reachable controls, no touch/stacking regression | Interaction + computed styles | UNTESTED |
| P14 | Baseline and pilot at same fixtures/viewports | Main-only intentional visual differences; nav/Store/other roles unaffected | Paired screenshots + styles | UNTESTED |
| P15 | Fast navigation | N/A in traced source: no consumer. Assert absence of fast-nav/hydration assets; if found reopen audit | Network evidence required | UNTESTED absence check |

## Later phase regression cases (not waived)

Store: 1/many variants, long description, normal/account pricing, local/remote/low/sold-out/temporary stock, inventory change with modal open, quantity 1/over max/over stock, insufficient balance, local success, pending remote success, refund, conflict, ambiguous timeout, duplicated submission, stale callback after navigation, Copy All and optional Download File. Run both User and Reseller; UI source sharing does not prove parity.

Admin: each managed panel's filter/IME input, target replacement, focus/caret restoration, history, stale responses, action confirmation, feature menus and role rejection. Verification/recovery: send/retry/expiry/complete/cancel inside and outside shell; local mail stub only. Wallet: invalid amount/recipient, pending/retry/deduplication/refund with isolated provider fixture. Background: stale heartbeat and deterministic worker completion, no duplicate ledger effects.

## Existing regression tests

- tests/product_image_lifecycle_test.php
- tests/slip_nearby_test.php
- tests/truemoney_byteindev_debug_test.php
- tests/truemoney_byteindev_routing_test.php
- tests/truemoney_byteindev_test.php

These PHP suites do not establish Dashboard/mobile/shell interaction coverage. Run only the relevant suites for subsequent functional changes; create a new regression test only after inspecting these tests and stating the meaningful failure mode not already covered. No tests solely for labels, typography or CSS. No production FTP workflow is a test harness.
