# BlackupV3

Repository หลักสำหรับซอร์สโค้ดเว็บไซต์ BlackupV3

> **สถานะปัจจุบัน:** GitHub `main` ใช้เป็น source-of-truth สำหรับงานพัฒนาแล้ว โดยมี source/config/docs ที่ต้องเก็บใน repo ครบตาม baseline ที่ตรวจสอบล่าสุด และมีการ sanitize ไฟล์ตัวอย่างฐานข้อมูลเพื่อไม่ให้ credential จริงอยู่ใน `main`.

## Source of truth

- Repository: `aekthai01/BlackupV3`
- Branch หลัก: `main`
- Baseline ต้นฉบับที่ใช้ตรวจ: `SAK010.zip`
- Baseline มีทั้งหมด 313 ไฟล์
- รูปภาพที่จงใจไม่เก็บใน GitHub: 125 ไฟล์
- production secret/runtime ที่จงใจไม่เก็บใน GitHub: 10 ไฟล์
- source/config/docs ที่ต้องมีใน repo: 178 paths
- ผลตรวจล่าสุด: 177 ไฟล์ตรงกับ SAK010 แบบ byte-for-byte และ `private/database.php.example` ถูก sanitize โดยเจตนา จึงไม่ควรตรง SHA กับไฟล์ใน SAK010

Source verification รอบหลักสำเร็จที่ commit `bf78f38ebec0cccca2f55b4c7fb820957bc8c24a` ก่อนแก้ความปลอดภัยของ database example และสถานะปลอดภัยปัจจุบันถูกยืนยันที่ `e141386a471e35495b4be0251aca4cfdf411eb74`. Documentation commits หลังจากนั้นอาจทำให้ HEAD เปลี่ยนโดยไม่เปลี่ยน source snapshot.

## กฎสำคัญ

1. ใช้ GitHub `main` เป็นฐานงานพัฒนา ไม่ต้องขอ ZIP เว็บไซต์ใหม่ทุกครั้ง
2. อย่าอัป `SAK010.zip`, `SAK010.1.tar.xz` หรือ archive production ทั้งก้อนเข้า GitHub
3. อย่า commit password, token, API key, encryption key, runtime cache หรือ production-only config
4. รูปภาพ production ไม่อยู่ใน scope ของ source repository ตามข้อตกลงปัจจุบัน
5. ก่อนแก้ไฟล์สำคัญ ให้ดึงเวอร์ชันล่าสุดจาก `main` และตรวจ diff เฉพาะงานที่ได้รับมอบหมาย
6. หลังแก้ ให้ verify path/behavior ที่เกี่ยวข้องจริงก่อนประกาศว่างานเสร็จ

## ไฟล์ production secret/runtime ที่ห้ามนำเข้า

รายการต่อไปนี้เป็น path ที่จงใจไม่เก็บใน source repository:

```text
private/database.php
private/key_reset_vault_secret.php
private/recovery_mail_secret.php
private/store_bridge_legacy_keys.php
private/store_bridge_secret.php
private/store_bridge_secret.php.lock
private/cheatgame.php
private/xchetos.php
private/purchase_activity_cache_v4.json
public_html/includes/exchange_rate_cache.json
```

ชื่อ path สามารถอยู่ในเอกสารเพื่ออธิบาย policy ได้ แต่ **ห้ามนำค่าจริงของไฟล์เหล่านี้เข้า repository**.

## `private/database.php.example`

ไฟล์นี้เป็นข้อยกเว้นสำคัญจากการเทียบ byte-for-byte กับ SAK010.

ใน SAK010 เคยมีค่าฐานข้อมูลจริงอยู่ในไฟล์ที่ชื่อว่า `.example` จึงไม่ปลอดภัยสำหรับ version control. เวอร์ชันที่ถูกต้องใน GitHub ต้องเป็น template ที่มี placeholder เช่น:

```text
YOUR_DATABASE_USER
YOUR_DATABASE_PASSWORD
YOUR_DATABASE_NAME
```

ดังนั้นอย่าใช้ SHA จาก SAK010 เพื่อบังคับให้ไฟล์นี้กลับไปเป็นเวอร์ชัน production.

## โครงสร้างหลัก

```text
private/       schema, private helpers, example config
public_html/   web application
  admin/       admin pages and actions
  api/         API endpoints
  assets/      CSS/JS and upload guards
  includes/    shared application logic
  reseller/    reseller area
  user/        user area
```

มีเอกสาร migration/upgrade และไฟล์ metadata อื่น ๆ ที่ root ตามประวัติการพัฒนา.

## วิธีทำงานเมื่อเปิดแชท/เซสชันใหม่

สำหรับ AI assistant หรือผู้ดูแลคนใหม่:

1. อ่าน `README.md` นี้ก่อน
2. อ่าน branch `main` ปัจจุบันจาก GitHub โดยตรง อย่าอาศัย manifest เก่าหรือรายการไฟล์จากแชทเก่าเป็นหลัก
3. ถ้าต้องแก้โค้ด ให้ fetch ไฟล์จริงจาก GitHub ก่อนทุกครั้ง
4. ถ้ามีการอ้างว่าไฟล์ “ตรง production/SAK010” ให้ยืนยันด้วย Git blob SHA หรือ diff ไม่ใช่เดา
5. ถ้า GitHub connector ถูก safety gate บล็อกบาง path ให้ใช้ workflow ที่ปลอดภัยหรือ Termux/Git ปกติแทน ห้ามลด security ของไฟล์เพื่อให้ connector ยอมรับ
6. ห้ามนำ archive production ขึ้น repo เพื่อหลบข้อจำกัด connector

## Termux

เจ้าของโปรเจกต์มี Termux และ GitHub CLI (`gh`) ที่ล็อกอินกับ GitHub แล้ว ใช้ HTTPS สำหรับ Git operations ได้.

Termux เหมาะสำหรับกรณีที่ connector แก้ path บางตัวไม่ได้ โดย workflow ทั่วไปคือ:

```bash
cd ~/BlackupV3-sak010-sync
git pull --ff-only origin main
# แก้/คัดลอกเฉพาะไฟล์ที่ต้องการ
git status
git diff
git add <paths>
git commit -m "..."
git push origin main
```

อย่าใช้สคริปต์ sync เก่ากับ SAK010 แบบไม่ตรวจ policy เพราะไฟล์ `private/database.php.example` ใน archive ต้นฉบับมีข้อมูลจริงและต้อง sanitize ก่อนเสมอ.

## Security status

`main` ปัจจุบันไม่มีค่าฐานข้อมูลจริงใน `private/database.php.example` แล้ว แต่ Git history เคยมี temporary archive และ database example ที่มีข้อมูลอ่อนไหวผ่าน commit เก่า.

ดังนั้น:

- อย่าทำ repository เป็น public จนกว่าจะทำ history cleanup
- credential ที่เคยถูก commit ควรถูก rotate
- การลบไฟล์จาก HEAD ไม่ได้ลบ blob ออกจาก Git history
- หากจะทำ history rewrite ต้องวางแผนและตรวจ branch/tag/ref ทั้งหมดก่อน force-push

## เอกสารสถานะเก่า

เอกสาร/manifest จากรอบอัปโหลดก่อนหน้าอาจระบุว่าไฟล์จำนวนมากยัง pending หรือ blocked ซึ่ง **ไม่ใช่สถานะปัจจุบันแล้ว**. ให้ถือ `main` ปัจจุบันและ README นี้เป็นข้อมูลหลัก และตรวจ GitHub จริงเมื่อมีข้อสงสัย.

## หลักการแก้โค้ด

- เปลี่ยนเฉพาะสิ่งที่จำเป็นกับงาน
- อย่า refactor โค้ดข้างเคียงโดยไม่มีเหตุผล
- งาน bug ต้อง reproduce/trace ก่อนแก้
- งาน review ต้อง trace behavior end-to-end ไม่ดูแค่ diff
- งาน auth/input/payment/external API ต้องตรวจ security boundary ก่อน
- ก่อนส่งงาน ให้ทดสอบหรือมีหลักฐานที่ตรวจสอบได้ว่า behavior ที่แก้ทำงานจริง

---

Updated: 2026-09-15
