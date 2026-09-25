# Work Handoff Prompt — Sakazuki Redesign Audit

คัดลอก prompt ด้านล่างไปใช้ใน ChatGPT Work / coding agent ที่จะรับช่วงต่อ

---

## PROMPT

คุณกำลังรับช่วง redesign โปรเจกต์ GitHub `aekthai01/sakazuki`

งานนี้เคยมี redesign รอบหนึ่งแล้วเกิด regression หลายจุดบนมือถือและถูก rollback ทั้ง GitHub และ Production กลับไปยังสภาพก่อน redesign เรียบร้อยแล้ว ดังนั้น **ห้ามเริ่มจากการเขียนโค้ดหรือสร้าง theme ใหม่ทันที**

### Source of truth ที่ต้องอ่านก่อนทำอะไรทั้งหมด

อ่านไฟล์นี้ **ตั้งแต่ต้นจนจบ**:

`docs/SAKAZUKI_REDESIGN_MASTER_PLAN.md`

ถือไฟล์ดังกล่าวเป็น master execution plan ของงานนี้ ถ้าความคิดของคุณขัดกับข้อห้าม/gate ในไฟล์ ให้หยุดและอธิบายก่อน อย่าข้าม gate เอง

### Repository state

- Repository: `aekthai01/sakazuki`
- Production domain: `sakazuki.spwz.online`
- Pre-redesign baseline commit: `34b9f2f7a5ae2923fe07bc7a931f5589ceec1a65`
- Rollback commit: `e3dea59304287d7e0d0b02f4095d0278cd98a041`
- Master plan added after rollback as documentation only
- redesign application assets จากรอบก่อนถูก rollback ออกแล้ว

### สิ่งที่คุณต้องทำในรอบแรกนี้

**AUDIT ONLY. ห้ามแก้ application code. ห้าม deploy Production.**

1. ตรวจ GitHub `main` ปัจจุบันและยืนยันว่า application tree ยังเป็น pre-redesign behavior โดยไม่ยึดจากคำอธิบายนี้อย่างเดียว
2. สร้าง branch ใหม่ชื่อใกล้เคียง `redesign/audit-v1`
3. inventory route/file ของ Public/Auth, User, Reseller, Admin จาก repository จริง
4. อ่าน architecture/runtime ที่เกี่ยวข้องทั้งหมด โดยอย่างน้อยต้องครอบคลุม:
   - `public_html/app.php`
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
   - role nav files
   - User Dashboard files
   - includes/scripts/styles ที่ไฟล์เหล่านั้นเรียกต่อ
5. trace runtime ownership ให้ได้ว่าใครควบคุม:
   - iframe shell
   - page scroll
   - horizontal scroll
   - focus/keyboard
   - modal/overlay
   - z-index stack
   - drawer/dropdown
   - fast navigation
   - history/back/forward
   - bfcache/pageshow
   - dynamic DOM replacement
   - music player overlay
6. สร้างเอกสาร audit ตาม master plan:
   - `docs/redesign-audit/ROUTE_INVENTORY.md`
   - `docs/redesign-audit/RUNTIME_DEPENDENCY_MAP.md`
   - `docs/redesign-audit/INTERACTION_OWNERSHIP.md`
   - `docs/redesign-audit/BEHAVIOR_INVARIANTS.md`
   - `docs/redesign-audit/UNKNOWNS_AND_ASSUMPTIONS.md`
   - `docs/redesign-audit/TEST_MATRIX.md`
   - `docs/redesign-audit/DECISION_LOG.md`
7. User Dashboard เป็น pilot candidate แรก แต่ **ยังห้ามแก้ Dashboard ในรอบ audit**
8. ทำ Mandatory Second-Pass Discovery ตาม master plan โดยตั้งสมมติฐานว่าตัวเองพลาดอย่างน้อย 1 subsystem แล้วค้น references ซ้ำ
9. ค้น DOM IDs/classes/functions/event listeners/endpoints/CSS selectors ของพื้นที่ที่จะเสนอแก้ และบันทึก evidence
10. ระบุ High/Critical unknowns ที่ยังตอบไม่ได้อย่างตรงไปตรงมา ห้ามเติมด้วย assumption

### สิ่งที่ต้องระวังเป็นพิเศษ

Sakazuki ไม่ใช่เว็บหน้า PHP ตรง ๆ อย่างเดียว มี persistent parent shell (`app.php`) ที่เปิด authenticated child pages ผ่าน same-origin iframe และ music player อยู่ใน parent document นอกจากนี้มี fast navigation, shell bridge, shared nav และ page-specific JS/inline JS อีกหลายชั้น

ดังนั้นต้องทดสอบ/วิเคราะห์แยก:

- เปิดผ่าน `/app.php?path=...`
- เปิด child page โดยตรง
- full reload
- fast navigation
- browser Back/Forward
- bfcache restore
- Android/iOS virtual keyboard

อย่าใช้ direct child page เป็นหลักฐานแทน app-shell behavior

### ห้ามทำซ้ำข้อผิดพลาดรอบก่อน

- ห้ามสร้าง shared global CSS overlay เพื่อ redesign ทั้ง User/Reseller/Admin ในครั้งเดียว
- ห้าม global selector เช่น `button`, `input`, `.glass`, `table`, `[class*=modal]` โดยไม่มี component scope ชัด
- ห้าม overwrite `document.body.className`
- ห้าม programmatically focus input หลังเปิด modal จน keyboard เด้งเอง
- ห้ามซ่อน interactive UI เดิมแล้วสร้าง interactive UI ใหม่ครอบ โดยไม่มี single source-of-truth/state adapter ที่พิสูจน์แล้ว
- ห้ามถือว่า HTTP 200, syntax pass หรือ byte MATCH = UX test ผ่าน
- ห้ามใช้ Production เป็น staging
- ห้ามแตะ purchase/auth/payment/stock/API/database/ledger logic เพื่อแก้ appearance โดยไม่แยก scope และพิสูจน์ความจำเป็น

### Store ไม่ใช่ pilot

`public_html/user/buy.php` เป็น High/Critical risk และมี commerce states จำนวนมาก เช่น filter, pagination, special pricing, local/remote stock, inventory polling, purchase token, pending order, refund/conflict, success keys/copy/download และ navigation guard

อย่าแก้ Store จนกว่า:

- Dashboard pilot ผ่านจริง
- test harness/process พร้อม
- Store behavior inventory/state-transition diagram ครบ

### Definition ของคำว่า “ตรวจครบ” ในรอบ audit

คุณจะใช้คำว่า “ตรวจครบ” ได้เมื่อ:

- route inventory ถูก regenerate จาก repo
- runtime dependency map มี evidence
- interaction ownership มี owner หรือ UNKNOWN ชัด
- first-pass audit เสร็จ
- mandatory second-pass discovery เสร็จ
- High/Critical unknowns ถูกแสดง ไม่ถูกซ่อน
- exact files สำหรับ Dashboard pilot ถูกระบุ
- exact invariants ที่ห้ามพังถูกระบุ
- exact test matrix สำหรับ pilot ถูกระบุ

### Output ที่ต้องส่งกลับก่อนเขียนโค้ด

ส่งรายงานสรุปให้ผู้ใช้โดยมี:

1. Architecture map ที่พบจริง
2. จุด coupling/collision ที่เสี่ยงที่สุด
3. รายการ unknowns เรียงตามความเสี่ยง
4. User Dashboard function inventory
5. Dashboard pilot proposal
6. exact files ที่คาดว่าจะเปลี่ยนใน pilot
7. exact files ที่จะไม่แตะ
8. test plan 360/390/430 + tablet/desktop + iframe/direct + fast-nav/history/keyboard
9. rollback plan ของ pilot
10. สิ่งที่ต้องค้นเพิ่มก่อน coding (ถ้ายังมี)

จากนั้น **STOP** และรอ approval จากผู้ใช้ก่อนเริ่ม implementation

อย่า merge และอย่า deploy ใน audit phase

### Working style

- ใช้หลักฐานจาก repository จริงมากกว่าความคุ้นเคยกับ web app ทั่วไป
- ถ้าพบความขัดแย้งระหว่าง comment, code และ runtime ให้รายงานความขัดแย้ง อย่าเลือกคำตอบเองเงียบ ๆ
- ถ้าไฟล์ใหญ่ ให้ search symbols/references แล้วอ่านช่วงที่เกี่ยวข้องต่อจน trace ได้ครบ
- ตรวจ consumer ของสิ่งที่จะเปลี่ยนก่อน producer เสมอ
- หลังคิดว่าเสร็จ ให้ถามตัวเองอีกครั้งว่า “ระบบไหนที่ฉันยังไม่ได้ค้นเพราะไม่รู้ว่ามันมีอยู่?” แล้วทำ second pass

เป้าหมายของ session แรกคือ **เข้าใจระบบจนสามารถเสนอ pilot ที่ปลอดภัย** ไม่ใช่สร้าง UI ให้เร็วที่สุด

---
