# Sakazuki Redesign Master Plan

> **สถานะ:** Planning / Audit First
>
> **วัตถุประสงค์:** วางแผน redesign Sakazuki ใหม่แบบลด regression ให้มากที่สุด โดยรักษา business behavior เดิมทั้งหมด จนกว่าจะมีหลักฐานว่าการเปลี่ยนแปลงนั้นปลอดภัย
>
> **Baseline ก่อน redesign รอบก่อน:** `34b9f2f7a5ae2923fe07bc7a931f5589ceec1a65`
>
> **Rollback commit:** `e3dea59304287d7e0d0b02f4095d0278cd98a041`
>
> หลัง rollback repository tree ถูกคืนให้ตรงกับ baseline ด้านบน ก่อนสร้างเอกสารแผนนี้

---

## 0. กฎสูงสุดของงานนี้

1. **ห้ามเริ่มจากการแก้โค้ด** ให้เริ่มจาก audit และ dependency mapping ก่อน
2. **ห้ามสรุปว่า “ตรวจครบ” จากการเห็น route/menu อย่างเดียว** ต้อง trace runtime behavior, DOM ownership, JS ownership, CSS ownership, network endpoints, focus/scroll/history behavior และ state transitions
3. **ห้ามเดา behavior ที่ยังหา source-of-truth ไม่เจอ** ให้บันทึกเป็น `UNKNOWN` พร้อมวิธีตรวจต่อ
4. **ห้าม redesign หลาย role หรือหลายหน้าใน PR เดียวในช่วงแรก**
5. **ห้ามใช้ global CSS/JS overlay เพื่อบังคับหน้าตาใหม่ทั้งระบบเป็นวิธีหลัก**
6. **ห้ามสร้าง UI ชุดใหม่ที่ซ้อนบน UI เดิม โดยปล่อย UI เดิมเป็น hidden source-of-truth** เว้นแต่มี state adapter ที่ออกแบบและทดสอบอย่างเป็นทางการ
7. **ห้ามเปลี่ยน purchase/auth/payment/stock/order/API/database/ledger logic เพื่อแก้ visual bug** ถ้าไม่จำเป็นจริง และหากจำเป็นต้องแยก PR/เหตุผล/ทดสอบต่างหาก
8. **ห้าม deploy Production เพื่อใช้เป็นพื้นที่ทดสอบ**
9. **ห้าม merge เพราะ syntax ผ่าน, HTTP 200 หรือไฟล์ MATCH อย่างเดียว** สิ่งเหล่านี้ไม่ใช่ interaction test
10. **ทุก Production write ต้องขออนุญาตผู้ใช้แบบระบุไฟล์ชัดเจนอีกครั้ง** แม้ผู้ใช้จะเคยอนุญาตการ deploy ในรอบก่อน
11. เมื่อพบว่าข้อมูลไม่พอ ให้หยุดและค้นเพิ่ม ไม่ใช่เติมช่องว่างด้วย assumption
12. ถ้าการออกแบบใน Figma/ภาพตัวอย่างขัดกับ behavior จริง ให้ behavior/invariant ที่ได้รับการยืนยันมีสิทธิ์เหนือ mockup จนกว่าจะออกแบบ state นั้นใหม่อย่างชัดเจน

---

# 0.1 Source audit correction — 2026-09-25 (takes precedence over historical assumptions)

Audit source: `main` at `74bb4fc38236bdd5a3ae90477b3e8df82844e9a6`. GitHub comparison against `34b9f2f7a5ae2923fe07bc7a931f5589ceec1a65` changes only this plan and the handoff document; rollback is in ancestry. This proves repository state, not Production parity.

- `app.php:11-13` and `includes/auth.php::redirectByRole` select `user/buy.php` / `reseller/buy.php` after login; Dashboard is an engineering pilot only.
- Current role navs load shell bridge, nav, security and lang. Repository-wide consumer search finds no PHP/HTML/CSS loader for `fast-nav.js`, `user-fast-pages.js`, `dashboard-live.js`, `app.js`, `style.css`, `global-upgrades.css` or `tailwind-addon.css`. Classify these as **DORMANT in this source snapshot**, not active architecture. Historical names are not proof of LEGACY intent. Do not load or revive them during redesign.
- `user/dashboard.php` server-renders its content. It does not include `dashboard_dynamic_content.php`, load `dashboard-live.js`, or emit a `data-dashboard-dynamic-root`. `dashboard_dynamic.php` still exists as a directly requestable authenticated endpoint; a dormant JS reference is not proof of a current consumer.
- A plain direct GET to a role page normally redirects into the shell via `shell-bridge.js`. Test the intentional direct-page path with `?__shell=0` (or `&__shell=0`), separately from the ordinary direct-entry redirect. POST eligibility and auth redirects also need separate tests.
- `requireLogin()` can require account verification. `app-shell.js::promoteAuthPage` does not list `verify_account.php`; verification behavior inside the iframe is an explicit HIGH-risk UNKNOWN until observed.
- `auth.php` calls `keyHistoryScheduleAutoCleanup()` at bootstrap. Request-time/background side effects must be traced; authenticated GET is not automatically a side-effect-free Production probe.

## Evidence and activity classification gate

Maintain two fields: **source classification** and **runtime observation**. ACTIVE means a traced current consumer in the stated source context; CONDITIONAL requires its exact gate; DORMANT means no discovered current loader/consumer; LEGACY requires explicit retirement evidence; UNKNOWN records unresolved reachability/ownership. No source classification alone proves observed execution. All browser/DB/mobile behavior remains UNTESTED until exercised in an isolated non-production environment with matching schema, representative settings and roles. Do not relabel UNTESTED as PASS from syntax, static search, screenshots of a mock, or HTTP 200.

Inventory must include root endpoints, Auth/Verification, Store API, cron/worker, shared fragments and server access rules, not only role menus. Asset tables must give consumer, condition, render context and evidence. Absence of an in-repository reference does not prove an endpoint has no external caller.

Before UI implementation: resolve HIGH/Critical ownership unknowns, capture authenticated baseline through shell and direct escape, verify loaded assets/network/DOM ownership, then obtain pilot approval per Phase A. Never activate dormant code to make a test pass. Fast-nav tests are N/A for the traced source route unless a real loader is discovered; test ordinary navigation and active instant-filter behavior instead.

See `docs/redesign-audit/` for evidence, limitations, pilot scope and test matrix. Audit work changes documentation only. No Production writes, broad FTP workflow, merge or auto-merge are authorized.

---

# 1. บทเรียนจากรอบที่ล้มเหลว

รอบก่อนล้มเหลวไม่ใช่เพราะ CSS อย่างเดียว แต่เกิดจากการเข้าใจ architecture ไม่ครบก่อนแก้

Sakazuki มีหลาย runtime layer ที่เชื่อมกัน เช่น:

- `public_html/app.php` เป็น persistent parent shell
- หน้าที่ authenticated ถูกเปิดใน same-origin `<iframe>`
- music player อยู่ใน parent shell เพื่อไม่ให้หยุดเมื่อ child page เปลี่ยน
- `assets/js/app-shell.js` sync URL/history ระหว่าง parent และ iframe
- child pages มี navigation/runtime ของตัวเอง
- `assets/js/nav.js` ควบคุม drawer/dropdown
- มี `fast-nav.js`, `user-fast-pages.js`, `shell-bridge.js`, `instant-filter.js`, `dashboard-live.js`, `purchase-activity.js`, `announcement-marquee.js` และ inline JS ตามหน้า
- PHP pages หลายหน้าเป็นทั้ง server logic + markup + inline CSS + inline JS ในไฟล์เดียว
- `user/buy.php` มีขนาดใหญ่และมี commerce state จำนวนมาก จึงไม่ควรเป็น pilot page

ดังนั้น “เปลี่ยนหน้าตา” สามารถกระทบ:

- parent iframe scrolling
- child document scrolling
- browser visual viewport
- virtual keyboard
- focus
- back/forward history
- bfcache/pageshow
- fast navigation
- state ที่ถูก replace โดย JS
- modal/overlay stacking
- music player floating UI
- horizontal scrollers
- inventory updates
- dynamic content replacement

**ข้อสรุป:** งาน redesign ต้องถูกปฏิบัติเหมือน refactor ของ interactive application ไม่ใช่งาน theme/CSS ทั่วไป

---

# 2. Architecture facts ที่ยืนยันแล้ว และต้องตรวจซ้ำก่อนเริ่มงาน

## 2.1 Persistent application shell

`public_html/app.php`:

- authenticate ก่อนสร้าง shell
- เลือก role: `admin`, `reseller`, `user`
- default child route แตกต่างตาม role
- render child page ใน `#sakazuki-app-frame`
- iframe เป็น same-origin
- music player render ใน parent document
- โหลด `assets/js/app-shell.js`

`assets/js/app-shell.js`:

- normalize child role routes
- sync child URL กลับไปที่ `/app.php?path=...`
- ใช้ `history.replaceState`
- ฟัง `message` ชนิด `sakazuki:shell-route`
- sync อีกครั้งเมื่อ iframe `load`
- มี behavior สำหรับ `pageshow`/bfcache

**Implication:** ทุก interaction test ต้องทดสอบอย่างน้อย 2 context:

1. เปิดผ่าน `/app.php?path=...` ตามการใช้งานจริง
2. เปิด child page โดยตรงเพื่อแยกว่า bug อยู่ shell หรือ child page

ห้ามสรุปว่า responsive/scroll/focus ถูกต้องจาก child page โดยตรงเพียงอย่างเดียว

## 2.2 Shared navigation

`assets/js/nav.js` ปัจจุบันควบคุม:

- drawer
- overlay
- dropdown
- Escape key
- responsive reset ที่ breakpoint 1536px
- bfcache transient state reset

ห้ามเปลี่ยน `body.className` ทั้งก้อนเพื่อเพิ่ม design state
ห้ามให้ redesign JS เป็นเจ้าของ navigation state ถ้า nav.js เดิมเป็นเจ้าของอยู่แล้ว

## 2.3 JS runtime ที่ต้อง audit อย่างน้อย

ต้องอ่านทั้งไฟล์และค้นว่าไฟล์ใด include มันบ้าง:

- `public_html/assets/js/app-shell.js`
- `public_html/assets/js/shell-bridge.js`
- `public_html/assets/js/nav.js`
- `public_html/assets/js/fast-nav.js`
- `public_html/assets/js/user-fast-pages.js`
- `public_html/assets/js/instant-filter.js`
- `public_html/assets/js/dashboard-live.js`
- `public_html/assets/js/purchase-activity.js`
- `public_html/assets/js/announcement-marquee.js`
- `public_html/assets/js/music-player.js`
- `public_html/assets/js/app.js`
- `public_html/assets/js/lang.js`
- `public_html/assets/js/security.js`

รายการนี้เป็น **ขั้นต่ำ ไม่ใช่รายการครบ** ต้อง regenerate inventory จาก repository อีกครั้ง

---

# 3. Mandatory Discovery Pass ก่อนเขียนโค้ด

สร้างเอกสาร audit ชั่วคราวบน working branch อย่างน้อยดังนี้:

- `docs/redesign-audit/ROUTE_INVENTORY.md`
- `docs/redesign-audit/RUNTIME_DEPENDENCY_MAP.md`
- `docs/redesign-audit/INTERACTION_OWNERSHIP.md`
- `docs/redesign-audit/BEHAVIOR_INVARIANTS.md`
- `docs/redesign-audit/UNKNOWNS_AND_ASSUMPTIONS.md`
- `docs/redesign-audit/TEST_MATRIX.md`
- `docs/redesign-audit/DECISION_LOG.md`

**Phase นี้ห้ามแก้ production UI code**

## 3.1 Route inventory

ต้อง enumerate ไฟล์ที่ผู้ใช้เข้าถึงได้ของทั้ง:

- public/auth
- user
- reseller
- admin

อย่าใช้เมนูอย่างเดียว เพราะมีหน้าที่เรียกผ่าน redirect/API/feature flag/dynamic link

สำหรับแต่ละ route บันทึก:

| Field | ต้องมี |
|---|---|
| Route | path จริง |
| Role(s) | ใครเข้าถึงได้ |
| Entry points | menu/link/redirect/API |
| Parent shell | ใช้ app.php หรือไม่ |
| Shared nav | include nav ใด |
| Shared CSS | stylesheet ที่โหลด |
| Shared JS | script ที่โหลด |
| Inline CSS/JS | มี/ไม่มี |
| Forms/actions | POST/GET endpoints |
| Dynamic endpoints | AJAX/fetch/polling |
| Special state | modal, live update, pagination, filter ฯลฯ |
| Risk | Low/Med/High/Critical |
| Evidence | file + symbol/line |

## 3.2 Dependency graph

ต้องทำ graph อย่างน้อยระดับ:

`Shell -> Role nav -> Page -> JS modules -> endpoint -> state mutation -> DOM region`

รวมถึง:

- include/require PHP
- `<script src>`
- dynamic script loading
- event dispatch/listen
- `postMessage`
- history manipulation
- `fetch`/XHR
- form action
- redirects
- polling
- timers
- MutationObserver/ResizeObserver/IntersectionObserver ถ้ามี
- global variables/functions ที่ข้ามไฟล์

## 3.3 Interaction ownership map

ต้องตอบให้ได้ว่าใครเป็น owner ของ:

- page scroll
- horizontal scroll
- focus
- virtual keyboard trigger
- modal open/close
- overlay
- z-index stack
- drawer
- dropdown
- history
- Back/Forward
- bfcache restore
- page replacement/fast navigation
- active menu state
- language switch
- music player
- toast/alert
- inventory refresh
- purchase processing

ถ้าพบ owner มากกว่า 1 ตัว ให้ถือเป็น **collision risk** และห้าม redesign ส่วนนั้นจนกว่าจะอธิบาย precedence ได้

---

# 4. Mandatory Second-Pass Discovery: “Assume We Missed Something”

หลัง audit pass แรกเสร็จ **ห้ามเริ่มโค้ดทันที**

ให้ตั้งสมมติฐานว่า audit แรกพลาดอย่างน้อย 1 ระบบ แล้วทำ pass ที่สองโดย:

1. ค้นทุก DOM `id`/class สำคัญที่ตั้งใจแตะ แล้วค้น references ทั้ง repository
2. ค้นชื่อ function ที่อ่าน/เขียน DOM เหล่านั้น
3. ค้น `addEventListener`, inline `onclick/onchange/onsubmit`, delegated event handlers
4. ค้น `focus()`, `blur()`, `autofocus`, `scrollTo`, `scrollIntoView`
5. ค้น `overflow`, `position: fixed/sticky`, `touch-action`, `overscroll`, `pointer-events`
6. ค้น `body.className`, `classList`, DOM replacement (`innerHTML`, `replaceWith`, `outerHTML`)
7. ค้น custom events (`dispatchEvent`, `CustomEvent`)
8. ค้น iframe/postMessage/history APIs
9. ค้น `fetch`, endpoints, polling interval, retry logic
10. ค้น selectors ที่ broad เช่น `[class*=...]`, element selectors, global `.glass`, global `button/input/table`
11. ค้น script load order และ duplicate includes
12. ตรวจ behavior เมื่อหน้าอยู่ใน iframe และเมื่อเปิดตรง
13. ตรวจ behavior หลัง fast navigation เทียบกับ full reload
14. ตรวจ behavior หลัง Back/Forward และ bfcache
15. ตรวจ behavior เมื่อ Android/iOS keyboard เปิด

จากนั้น update `UNKNOWNS_AND_ASSUMPTIONS.md`

**กฎ:** ถ้ายังมี UNKNOWN ที่สามารถทำให้ data loss, purchase error, auth error, navigation lock, unscrollable UI หรือ inaccessible control ได้ ให้หยุด implementation

---

# 5. Behavior Invariants: สิ่งที่ redesign ห้ามทำหาย

เอกสารนี้ไม่ใช่รายการครบ Work ต้องเติมหลัง audit

## 5.1 Global invariants

- authentication/authorization เดิม
- role boundaries เดิม
- CSRF protection เดิม
- logout flow เดิม
- language behavior เดิม
- shell URL sync เดิม
- browser Back/Forward เดิม
- music player continuity เดิม
- direct child page behavior ที่ยังรองรับอยู่เดิม
- drawer/dropdown accessibility behavior เดิม
- no forced keyboard unless user explicitly interacts with an input that requires keyboard

## 5.2 User Dashboard

ต้อง inventory จากโค้ดจริงก่อน design และระบุอย่างน้อย:

- balance/account summary
- live/dynamic dashboard content
- purchase activity ถ้ามี
- links/CTA
- dynamic refresh
- notification/status states
- language
- navigation

Dashboard เป็น **pilot page แรก** เพราะ risk ต่ำกว่า Store

## 5.3 Store / `user/buy.php` — HIGH/CRITICAL RISK

Store ห้ามเป็นหน้าแรกของ redesign

ก่อนแตะ Store ต้องมี behavior matrix ที่ยืนยันครบอย่างน้อย:

- announcement marquee + dynamic text/color
- recent public purchase activity (ปัจจุบันมี behavior live/rotating)
- search
- platform filter
- category filter
- instant filtering / navigation interaction
- pagination
- local stock
- remote/API stock
- merge/fallback behavior ของ stock source
- inventory background refresh/polling
- stock status/banner states
- product image fallback
- platform badges
- short description
- full description/details
- variants/durations
- sold-out states
- temporary sold-out states
- account/reseller special pricing
- original vs special price display
- quantity limits
- available-stock limit
- maximum purchase quantity
- total calculation
- balance-after-purchase calculation
- CSRF
- one-time purchase token / duplicate submission protection
- purchase processing overlay
- pending remote order polling
- completed redirect
- conflict/admin-review state
- refund state
- timeout/deadline behavior
- purchase success modal
- license keys
- Copy All
- optional Download File
- Close behavior
- My Keys continuation
- navigation guard that prevents stale async callbacks overriding user navigation
- focus behavior
- keyboard behavior
- scroll behavior while modal is open
- shell music player overlap/z-index

ก่อน Store implementation ต้องทำ **state-transition diagram** ไม่ใช่แค่ screenshot/mockup

ตัวอย่าง state categories ที่ต้องครอบคลุม:

`catalog-ready -> variant-selected -> quantity-edit -> submit -> processing -> local-success`

`catalog-ready -> submit -> remote-pending -> polling -> success`

`remote-pending -> refunded`

`remote-pending -> conflict/review`

`inventory-ready -> checking -> refreshed/changed/failed/sold-out`

UI design ต้องระบุหน้าตาของทุก state ที่ user มองเห็น ไม่ใช่เฉพาะ happy path

## 5.4 History / My Keys / Rankings / Account

แต่ละหน้าต้อง inventory:

- filters/search
- pagination
- copy/download/reset/action buttons
- empty/loading/error states
- long text wrapping
- table/card responsive behavior
- permission/ownership checks

## 5.5 Reseller

ห้ามถือว่า User layout = Reseller layout โดยอัตโนมัติ

ต้องตรวจ:

- reseller-specific pricing
- reseller purchasing
- keys
- history
- reset tools
- rankings
- account
- Developer API / API Store ที่เปิดตาม setting
- feature flags
- role-specific balances/commission/profit fields

## 5.6 Admin

Admin ต้องถูกถือเป็น application คนละ density profile กับ User

ต้อง inventory ทุกหมวดจาก route/menu/links จริง เช่น:

- dashboard
- users
- security
- products/catalog/variants
- transactions
- settings
- email recovery
- music
- resellers/pricing
- stock
- keys
- deposits/debug
- Binance
- profit/rankings
- API Hub
- commerce center / consistency / ledger / checks / repairs
- codes
- key reset tools
- feature flags/conditional menus

Admin table/modal/form redesign ทำหลัง User patterns ผ่านแล้ว

---

# 6. Pilot Strategy — ทำทีละหน้า

## Phase A — Audit only

Deliverables:

- route inventory
- dependency map
- ownership map
- behavior invariants
- unknowns
- test matrix
- screenshots/recordings ของ baseline
- risk classification
- recommended pilot design

**STOP GATE:** ส่งรายงานให้ผู้ใช้ตรวจ ก่อนแก้ application code

## Phase B — User Dashboard pilot

เป้าหมาย:

- redesign `user/dashboard.php` เท่านั้นเท่าที่ทำได้
- shared changesทำเฉพาะสิ่งที่จำเป็นสำหรับ dashboard
- ห้ามเพิ่ม global theme ที่เปลี่ยน Store/Admin/Reseller โดยไม่ได้ตั้งใจ

ก่อน coding:

- ระบุ exact files ที่จะเปลี่ยน
- ระบุ DOM/JS contracts ที่ห้ามเปลี่ยน
- ระบุ test cases

หลัง coding:

- screenshot comparison
- interaction test
- iframe test
- direct child test
- fast-nav test ถ้า route นี้เข้าร่วม fast-nav
- Back/Forward
- reload
- bfcache
- language
- Android keyboard ไม่ควรปรากฏถ้าไม่มี user input action

**STOP GATE:** ยังไม่ขยายไปหน้าอื่นจนผู้ใช้ยืนยัน pilot

## Phase C — Extract only proven shared primitives

หลัง Dashboard ผ่านจริง ค่อยสกัดสิ่งที่พิสูจน์แล้ว เช่น:

- spacing tokens
- card primitive
- button primitive
- typography
- mobile container
- accessible modal primitive (ถ้าทดสอบแล้ว)

ห้าม extract จาก design mockup อย่างเดียว

## Phase D — Secondary User pages

ทีละ PR:

- account
- history
- mykeys
- rankings
- redeem/deposit flows

แต่ละ PR ต้องมี acceptance evidence ของตัวเอง

## Phase E — Store

ทำหลัง infrastructure/test harness พร้อม

Store อาจต้อง refactor markup โดยตรงแทน progressive overlay

หลักการ:

- one source of truth ต่อ state
- business logic แยกจาก presentation เท่าที่ทำได้
- ห้ามซ่อน old interactive UI แล้วสร้าง new interactive UI ซ้อนโดยไม่มี adapter ที่ formalize state synchronization
- focus management ต้อง explicit
- keyboard ต้องเกิดจาก user intent
- horizontal/vertical scrolling ต้องทดสอบบน touch device/emulation
- modal/bottom-sheet ต้องทดสอบร่วมกับ parent iframe + music player

## Phase F — Reseller

ใช้เฉพาะ primitives ที่ผ่าน User แล้ว แต่ audit differences ก่อน reuse

## Phase G — Admin

จัดการ dense information architecture แยกจาก customer-facing design

---

# 7. Branch / PR policy

- ห้ามแก้ `main` โดยตรงสำหรับ application redesign
- Audit branch ตัวอย่าง: `redesign/audit-v1`
- Pilot branch: `redesign/user-dashboard-v1`
- Store branch แยก: `redesign/user-store-v1`
- เปิด Draft PR ตั้งแต่ต้น
- 1 PR = 1 bounded goal
- ถ้า PR เริ่มแตะหลาย role โดยไม่ได้ตั้งใจ ให้หยุดและ split
- ห้าม merge ถ้ามี unresolved UNKNOWN ที่เกี่ยวข้องกับพื้นที่ที่แก้
- ห้าม auto-merge

Commit ควรแยก:

1. tests/instrumentation (ถ้าจำเป็น)
2. structure/markup
3. styling
4. behavior
5. accessibility/hardening

อย่ารวม refactor business logic เข้ากับ visual change commit

---

# 8. Testing Requirements

## 8.1 Viewport matrix ขั้นต่ำ

Mobile:

- 360px width Android class
- 390px width
- 412/430px width

Tablet/compact desktop:

- 768px
- 1024px
- 1200px

Desktop:

- 1366px
- 1440px
- 1536px
- 1920px

## 8.2 Runtime matrix

ต้องทดสอบ:

- ผ่าน `app.php` iframe shell
- child route โดยตรง
- normal page load
- fast navigation (หน้าที่รองรับ)
- browser Back
- browser Forward
- reload
- bfcache/pageshow
- language switch
- logged-in roles ตามจริง

## 8.3 Touch/keyboard matrix

Mobile test ต้องมี:

- vertical scroll ด้วยนิ้ว
- horizontal scroll/chips ด้วยนิ้ว
- tap vs drag cancellation
- open/close drawer
- open/close modal
- keyboard closed
- keyboard open หลัง explicit input tap
- keyboard dismiss
- viewport resize ตอน keyboard เปิด
- input not hidden under bottom sheet/browser keyboard
- background scroll lock
- return focus หลังปิด modal
- orientation change ถ้าเป็นไปได้

## 8.4 Store-specific scenarios

ต้องทดสอบอย่างน้อย:

- product 1 variant
- product หลาย variants
- product description ยาว
- special price
- normal price
- local stock
- remote stock
- low stock
- sold out
- temporary unavailable
- inventory changes while modal open
- quantity = 1
- quantity > stock
- quantity > max
- insufficient balance
- successful local purchase
- successful remote/pending purchase
- refund
- conflict/review
- timeout/network failure
- duplicate tap protection
- Copy All
- Download File (เมื่อมี)

**ถ้าไม่สามารถจำลอง state ใดได้ ให้ระบุเป็น UNTESTED ห้ามเขียนว่า PASS**

---

# 9. Evidence standard

คำว่า PASS ต้องมีอย่างน้อยหนึ่งอย่างตามประเภท:

- automated test result
- browser interaction evidence
- screenshot/video
- exact code trace proving invariant

คำต่อไปนี้ห้ามใช้แทน functional proof:

- “syntax ผ่าน”
- “HTTP 200”
- “ไฟล์ MATCH”
- “ดูจากโค้ดน่าจะได้”
- “CSS ถูกต้อง”

HTTP 200/byte match มีประโยชน์เฉพาะ deployment integrity ไม่ใช่ UX correctness

---

# 10. CSS safety rules

หลีกเลี่ยง selector แบบกว้าง เช่น:

- `button { ... }`
- `input { ... }`
- `table { ... }`
- `.glass { ... }`
- `[class*="modal"]`

เว้นแต่ component ownership ชัดและ scope อยู่ใต้ page/component root ที่เฉพาะเจาะจง

ต้องตรวจ selector collision ผ่าน repository search ก่อน merge

ห้ามเปลี่ยน breakpoint global โดยไม่ตรวจ menu/content width จริงของแต่ละ role

ห้ามกำหนด `overflow: hidden` บน `html/body/main` โดยไม่ระบุ scroll owner

ห้ามใช้ `touch-action` แบบ global เพื่อแก้ปัญหาเฉพาะ component

---

# 11. JavaScript safety rules

- ห้าม programmatic `.focus()` หลังเปิด purchase/modal เว้นแต่ requirement และทดสอบ keyboard แล้ว
- ถ้าต้อง focus เพื่อ accessibility ให้ focus non-input container (`tabindex=-1`) ก่อน
- ห้าม overwrite `document.body.className`
- ห้าม redefine global owner state โดยไม่ audit existing owner
- ห้ามใช้ `setTimeout` เป็น synchronization contract หากสามารถใช้ event/state callback ได้
- ห้าม duplicate event listeners หลัง fast navigation
- ทุก init function ต้องพิจารณา idempotency
- ทุก observer/timer ต้องมี cleanup strategy เมื่อ DOM/page ถูก replace
- dynamic page replacement ต้อง reinitialize เฉพาะ component ที่จำเป็น
- custom events ต้อง document producer/consumer

---

# 12. Accessibility / usability gates

ขั้นต่ำ:

- touch target ~44px เมื่อเหมาะสม
- visible focus
- keyboard navigation desktop
- ESC close modal/drawer ตาม expectation
- modal focus containment/return focus
- labels/aria สำหรับ icon-only controls
- no horizontal page overflow โดยไม่ตั้งใจ
- text ไม่ถูกตัดจนข้อมูลสำคัญหาย
- safe-area support เมื่อมี fixed mobile control
- reduced motion
- contrast ตรวจอย่างน้อยกับ core text/control states

---

# 13. Production policy

ก่อน Production ทุกครั้ง:

1. ระบุ commit SHA
2. ระบุ exact file list
3. เปรียบเทียบ production กับ expected baseline/current main
4. backup exact production files ที่จะเปลี่ยน
5. ระบุ rollback procedure ก่อน deploy
6. ขอ explicit user authorization หลังจากแสดง scope
7. deploy เฉพาะ approved scope
8. byte verify
9. HTTP verify
10. functional smoke test บน production
11. ถ้า functional smoke ไม่ผ่าน ให้ rollback ก่อนวิเคราะห์ต่อ

**อย่า deploy งาน redesign ทั้ง directory เพื่อความสะดวก**

---

# 14. Unknowns & Assumptions Register Template

ต้องรักษาตารางนี้ตลอดงาน ห้ามลบ UNKNOWN เพื่อให้เอกสารดูสะอาด

| ID | Question / Unknown | Why it matters | Evidence checked | Current hypothesis | Risk if wrong | Next action | Status |
|---|---|---|---|---|---|---|---|
| U-001 | ตัวอย่าง: ใครเป็น scroll owner ขณะ purchase modal เปิดผ่าน app.php? | keyboard/bottom-sheet อาจล็อก scroll | app.php, buy.php, CSS, runtime | UNKNOWN | High | reproduce + trace | OPEN |

เมื่อมีคำตอบ:

- ใส่ evidence
- เปลี่ยนสถานะเป็น RESOLVED
- ถ้าคำตอบเปลี่ยน design ให้บันทึกใน Decision Log

---

# 15. Function Inventory Template

สำหรับแต่ละหน้าที่จะ redesign ต้องสร้างตารางแบบนี้ก่อน:

| Function | User trigger | DOM owner | JS/PHP owner | Endpoint/data | State changes | Failure states | Must preserve? | Test evidence |
|---|---|---|---|---|---|---|---|---|

**ห้ามเริ่ม implementation ถ้าหน้า High/Critical risk ยังมีช่องสำคัญว่าง**

---

# 16. Decision Log Template

| Decision | Alternatives | Evidence | Why chosen | Risks | Revisit when |
|---|---|---|---|---|---|

ตัวอย่าง decision ที่ต้องบันทึก:

- shell architecture จะคง iframe หรือไม่
- navigation strategy
- fast-nav จะคง/ลด/เลิกใช้หรือไม่
- page scroll ownership
- modal strategy
- Store variant architecture
- shared design tokens extraction point

---

# 17. “Think Further” Reserved Section — ห้ามลบ

ส่วนนี้ตั้งใจเว้นไว้เพื่อบังคับให้ agent/engineer คิดสิ่งที่แผนปัจจุบันอาจลืม

## 17.1 สิ่งที่อาจยังไม่ถูกค้นพบ

- [ ] hidden routes / redirects
- [ ] feature-flagged UI
- [ ] cron/automation side effects ที่สะท้อนใน UI
- [ ] third-party widgets/scripts
- [ ] CSS ที่ inject จาก PHP/settings
- [ ] user-customizable branding/colors
- [ ] localization strings ที่ทำให้ layout กว้างขึ้น
- [ ] very long usernames/product names/keys
- [ ] zero/negative/edge numeric values
- [ ] session expiry ขณะ modal เปิด
- [ ] CSRF expiry/renewal
- [ ] multiple tabs
- [ ] stale async response
- [ ] slow network/offline/retry
- [ ] API supplier latency
- [ ] auth redirect inside iframe
- [ ] browser-specific iframe/keyboard quirks
- [ ] music player overlap on small viewport
- [ ] cache/CDN behavior
- [ ] service worker/PWA behavior (ถ้ามี)
- [ ] browser autofill/password manager
- [ ] accessibility zoom / font scaling
- [ ] 200% zoom desktop
- [ ] system reduced motion
- [ ] iOS safe area
- [ ] Android browser toolbar resize
- [ ] RTL future possibility (ถ้ามี language เพิ่ม)

## 17.2 Questions the next agent MUST ask itself before saying “ready”

1. มี code path ไหนที่เราไม่ได้ trigger หรือไม่?
2. มี component ไหนถูก render จาก include ที่เราไม่ได้เปิดอ่านหรือไม่?
3. มี JS ตัวไหนแก้ DOM เดียวกันหลังเรา render แล้วหรือไม่?
4. มี CSS selector จากไฟล์อื่นที่สามารถชนะ cascade ของเราในบาง breakpoint หรือไม่?
5. มี behavior ต่างกันระหว่าง iframe กับ direct page หรือไม่?
6. มี behavior ต่างกันระหว่าง full reload กับ fast-nav หรือไม่?
7. มี state หลัง asynchronous update ที่ mockup ไม่มีหรือไม่?
8. ถ้า API ช้า/ล้ม/คืนข้อมูลเก่า UI จะทำอะไร?
9. ถ้า keyboard เปิด viewport/scroll จะเป็นอย่างไร?
10. ถ้าผู้ใช้ลากแทน tap จะเกิด accidental action หรือไม่?
11. ถ้ากด Back ขณะ modal/pending process จะเกิดอะไร?
12. ถ้าหน้า restore จาก bfcache state จะ stale หรือไม่?
13. ถ้าสินค้าหมดหลังผู้ใช้เลือกแล้ว UI จะ recover อย่างไร?
14. ถ้า script init สองครั้งจะ duplicate listener/timer หรือไม่?
15. ถ้า JS ไม่โหลด หน้า server-rendered fallback ยังใช้งานได้หรือไม่?
16. เรากำลังแก้ symptom ด้วย CSS/JS overlay แทนแก้ ownership จริงหรือไม่?
17. สิ่งที่เรากำลังจะเปลี่ยนมี consumer อื่นที่ search ยังไม่เจอหรือไม่?
18. มี security/authorization assumption ที่ presentation code ไม่ควรแตะหรือไม่?
19. มี production-only setting/data ที่ test data ไม่ครอบคลุมหรือไม่?
20. ถ้าต้อง rollback exact steps คืออะไร และข้อมูลผู้ใช้จะปลอดภัยหรือไม่?

**หากคำถามใดตอบไม่ได้ ให้บันทึก UNKNOWN และค้นต่อ**

---

# 18. Definition of Done ของแต่ละหน้า

หน้าใดจะเรียกว่า “เสร็จ” ได้เมื่อ:

- function inventory ครบ
- unknown ที่มี High/Critical risk ถูก resolve
- design states ครบทั้ง happy/error/loading/empty/disabled ที่ relevant
- code scope ชัด
- no unintended cross-page style impact
- tests ตาม matrix ที่เกี่ยวข้องผ่าน
- tested through app shell
- tested direct child page
- tested interaction บน mobile viewport
- keyboard/focus behavior ผ่าน
- navigation/back/reload ผ่าน
- no unresolved regression จาก baseline
- user review/acceptance ผ่านสำหรับ pilot/major page

---

# 19. สิ่งที่ Work/agent ควรทำเป็นลำดับแรก

เมื่อเริ่ม session ใหม่:

1. อ่านไฟล์นี้ทั้งหมด
2. ตรวจว่า `main` ปัจจุบันสืบมาจาก rollback และไม่มี redesign assets รอบก่อนกลับมา
3. สร้าง branch `redesign/audit-v1`
4. **ห้ามแก้ application code**
5. regenerate route/file inventory จาก repository จริง
6. อ่าน architecture/runtime files ทั้งหมด
7. trace User Dashboard ก่อน แต่ยังไม่แก้
8. ทำ first-pass audit
9. ทำ mandatory second-pass discovery
10. สรุป unknowns + risks
11. เสนอ Dashboard pilot scope พร้อม exact files และ exact test plan
12. หยุดและรอ approval ก่อน implementation

---

# 20. Guiding principle

> **Understand ownership before changing appearance. Preserve behavior before improving appearance. Prove interactions before deployment.**

ถ้าต้องเลือกระหว่าง “เสร็จเร็ว” กับ “รู้จริงว่าระบบนี้ทำงานอย่างไร” ให้เลือกอย่างหลัง

เป้าหมายของรอบใหม่ไม่ใช่ทำ V3 ให้เร็วกว่า V2 แต่คือทำให้ **V1 ของหน้าแรกผ่านจริง** ก่อนมี V2 ของหน้าใด ๆ
