# Sakazuki Store

ไฟล์เว็บไซต์สำหรับใช้งานบน DirectAdmin/PHP 8.1 ขึ้นไป

## การตั้งค่าฐานข้อมูล

ข้อมูลฐานข้อมูลไม่ได้เก็บใน `public_html/includes/db.php` แล้ว แต่เก็บที่:

```text
<domain-root>/private/database.php
```

โดย `<domain-root>` คือโฟลเดอร์ที่มี `public_html` อยู่ข้างใน ห้ามนำโฟลเดอร์ `private` ไปไว้ใน `public_html`

หลังเปลี่ยนรหัสผ่านฐานข้อมูลใน DirectAdmin ให้แก้เฉพาะค่า `pass` ใน `private/database.php` ให้ตรงกัน

## PHP extensions ที่ต้องมี

- mysqli
- curl
- mbstring
- fileinfo
- gd
- json
- openssl

## ข้อควรระวัง

- สำรองไฟล์และ export ฐานข้อมูลก่อนอัปเดต
- ไม่มีบัญชีผู้ดูแลหรือรหัสผ่านเริ่มต้นจากชุดไฟล์นี้
- ห้ามปิด CSRF หรือแก้ระบบสิทธิ์เพื่อหลบข้อความ Security validation failed
- ทดสอบการเติมเงินด้วยยอดต่ำที่สุดก่อนเปิดใช้งานจริง

## CHEATGAME Reseller API

ชุดไฟล์นี้เชื่อมต่อ API จากฝั่ง PHP เท่านั้น คีย์ API และ Webhook Secret อยู่ใน
`<domain-root>/private/cheatgame.php` และต้องไม่ย้ายเข้า `public_html`

### ขั้นตอนเปิดใช้งาน

1. อัปโหลดทั้ง `public_html` และ `private` โดยรักษาโครงสร้างเดิม
2. ใน CHEATGAME Dashboard เพิ่ม Allowed Server IP เป็น `15.235.227.117`
3. ตั้ง Webhook URL เป็น `https://sakazuki.spwz.online/webhook_cheatgame.php`
4. คัดลอกค่า `webhook_secret` จาก `private/cheatgame.php` ไปตั้งเป็น Webhook Secret ใน Dashboard
5. เข้า Admin > CHEATGAME API แล้วกดทดสอบ `products`, `balance`, `exchange_rate`
6. กำหนดมาร์กอัป จากนั้นซิงค์สินค้า ตรวจราคาขาย และเปิดขายทีละรายการ
7. ใช้ Send Test Webhook จาก Dashboard เพื่อตรวจ webhook โดยไม่สร้างคำสั่งซื้อจริง

ห้ามทดสอบ `order` หากไม่ต้องการให้ยอดตัวแทนและคีย์สินค้าถูกตัด ระบบหน้า Admin จึงทดสอบเฉพาะคำสั่งที่ปลอดภัยเท่านั้น

### สกุลเงิน

- ระบบเก็บจำนวนเงินด้วยสกุลเงินฐานของเว็บไซต์ ซึ่งจำกัดไว้ที่ THB หรือ USD
- หน้าไทยแสดงเงินบาท และหน้าอังกฤษแสดงดอลลาร์สหรัฐ
- การแปลง THB/USD ใช้อัตราที่ดึงและแคชสำเร็จจริงเท่านั้น หากไม่มีอัตราที่ตรวจสอบได้ ระบบจะแจ้งว่าอัตราไม่พร้อมใช้งานแทนการเดาตัวเลข
- ไม่อนุญาตให้เปลี่ยนสกุลเงินฐานหลังมีข้อมูลยอดเงิน ราคา หรือธุรกรรม เพราะต้องแปลงข้อมูลการเงินทุกตารางก่อน
- `price_usd` จาก CHEATGAME ใช้เป็นต้นทุนหลัก ส่วน `price_idr` และ `exchange_rate` เก็บไว้เพื่อการตรวจสอบในหน้า Admin ไม่แสดงเป็นสกุลเงินลูกค้า

### ตารางฐานข้อมูลที่สร้างอัตโนมัติ

- `cgo_products`
- `cgo_orders`
- `cgo_order_keys`
- `cgo_webhook_events`

Webhook ตรวจ `X-CGO-Signature`, จำกัดอายุ timestamp 5 นาที และบันทึก `X-CGO-Event-ID` เพื่อป้องกันการประมวลผลซ้ำ คำสั่งซื้อที่ผลลัพธ์ไม่ชัดเจนจะไม่ถูกส่งซ้ำอัตโนมัติและจะคงยอดไว้เพื่อตรวจสอบ ลดโอกาสถูกหักซ้ำแบบที่ระบบการเงินมักเลือกสร้างเรื่องในเวลาที่คนกำลังนอน
