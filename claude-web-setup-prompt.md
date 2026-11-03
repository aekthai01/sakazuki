# งาน: ติดตั้งและทดสอบชุดเครื่องมือแก้เว็บให้ครบ (ทำอัตโนมัติจนจบ)

ตอบเป็นภาษาไทย ทำงานต่อเนื่องเองจนจบ ไม่ต้องถามระหว่างทางถ้าไม่จำเป็น สรุปผลท้ายสุดเป็นตาราง

## สภาพแวดล้อม (อ่านก่อนเริ่ม)
- ตอนนี้คุณรันอยู่ใน Ubuntu ผ่าน proot-distro บน Android (Termux) เป็น user root, HOME=/root
- Termux home อยู่ที่ /termux-home, ที่เก็บไฟล์มือถืออยู่ที่ /sdcard
- RAM มือถือน้อย อย่าเปิด process หนักพร้อมกันหลายตัว และปิด server ที่เปิดทิ้งไว้เมื่อเสร็จ
- มีสคริปต์ setup-web-tools.sh รันไปแล้ว: ติดตั้ง Chromium (Playwright) ที่ /usr/local/bin/web-chrome,
  คำสั่ง web-browser-up / web-browser-down (Chromium headless port 9222), mcp-chrome-devtools, mcp-playwright,
  webdeploy, webcheck, ~/.deploy.env.example, skills ใน ~/.claude/skills และลงทะเบียน MCP ไว้ใน user config แล้ว
- ปัญหาที่ค้างอยู่: `npm install -g` พังด้วย `ENOENT ... rename ~/.npm/_cacache/tmp/...`
  สาเหตุ: npm (cacache) ย้ายไฟล์ด้วย hardlink แต่ proot จำลอง hardlink ด้วย link2symlink ทำให้ไฟล์หาย
  แพ็กเกจที่ยังติดตั้งไม่ได้: `@upstash/context7-mcp` `lighthouse` `html-validate` `serve`

## ขั้นที่ 1: ตรวจสภาพปัจจุบัน
ตรวจแล้วรายงานสั้นๆ: `node -v`, `npm -v`, `npm ls -g --depth=0`, `which` ของ
chrome-devtools-mcp playwright-mcp context7-mcp lighthouse html-validate serve prettier uipro webdeploy webcheck web-chrome,
`claude mcp list`, `ls ~/.claude/skills`, และ `df -h /` (พื้นที่เหลือ)

## ขั้นที่ 2: ติดตั้งแพ็กเกจ npm ที่ยังขาด
ลองตามลำดับ หยุดเมื่อวิธีไหนสำเร็จ (ติดตั้งทีละแพ็กเกจ เช็ก `which` หลังติดตั้งทุกตัว):
1. npm โดยใช้ cache ใหม่และดาวน์โหลดทีละไฟล์:
   `npm_config_cache=/tmp/npmc-$RANDOM npm install -g --no-audit --no-fund --maxsockets=1 <pkg>` ลองซ้ำได้ 2-3 ครั้ง
2. ถ้ายังพังด้วย ENOENT rename: ใช้ตัวติดตั้งที่ไม่ใช้ hardlink เช่น
   - ติดตั้ง pnpm (`npm i -g pnpm` ตัวเล็ก มักผ่าน) แล้ว `pnpm config set package-import-method copy`
     `pnpm setup` แล้ว `pnpm add -g <pkg>`, หรือ
   - bun (`curl -fsSL https://bun.sh/install | bash`) แล้ว `bun add -g --backend=copyfile <pkg>`
   - ให้คำสั่งที่ได้อยู่ใน PATH ที่ Claude เห็น (ถ้าจำเป็นให้ symlink เข้า /usr/local/bin)
3. ถ้ายังไม่ได้อีก: บอกผู้ใช้ให้ `/exit` แล้วรันใน Termux: `bash web.sh --npm`
   (สคริปต์นั้นรัน npm ใน proot แบบ --no-link2symlink ซึ่งคุณทำจากข้างในไม่ได้) แล้วหยุดรอ

ถ้า context7-mcp ติดตั้งได้ ให้ตรวจว่า `claude mcp list` ชี้ไปที่คำสั่งที่มีอยู่จริง ถ้าไม่ใช่ให้
`claude mcp remove -s user context7` แล้ว `claude mcp add -s user context7 -- <path จริงของ context7-mcp>`

## ขั้นที่ 3: ทดสอบทุกเครื่องมือ (รันจริง ไม่ใช่แค่เช็กว่ามีไฟล์)
1. Chromium: `web-browser-up && curl -s http://127.0.0.1:9222/json/version`
2. สร้างเว็บทดสอบที่ /root/webtest/index.html: หน้า HTML ภาษาไทย มีปุ่มที่กดแล้วข้อความเปลี่ยน
   และใส่บั๊กไว้ 1 จุดโดยตั้งใจ (เช่น JS เรียกฟังก์ชันที่ไม่มีอยู่ ทำให้ console error)
3. `serve -l 8080 /root/webtest` เปิดไว้เบื้องหลัง
4. MCP chrome-devtools: เปิด http://127.0.0.1:8080 ถ่ายภาพหน้าจอ อ่าน console (ต้องเจอ error ที่ใส่ไว้) และ network
5. ทดสอบขั้นตอน web-fix แบบย่อ: แก้บั๊กนั้น -> โหลดใหม่ -> console ต้องสะอาด
6. MCP playwright: กดปุ่ม แล้วยืนยันว่าข้อความเปลี่ยนจริง
7. `html-validate /root/webtest/index.html` และ `prettier --check /root/webtest/index.html`
8. `webcheck https://example.com` (ต้องได้ HTTP 200 และคะแนน Lighthouse ครบทุกหมวด)
9. MCP context7: ค้นเอกสารสั้นๆ อะไรก็ได้ (เช่น วิธีใช้ fetch ใน Next.js)
10. `bash -n /usr/local/bin/webdeploy` และสร้าง /root/webtest/.deploy.env ปลอม แล้วรัน `webdeploy --dry-run`
    เพื่อดูว่าสคริปต์อ่าน config ได้ (เชื่อมต่อไม่ได้เป็นเรื่องปกติ เพราะ host ปลอม) แล้วลบ .deploy.env ปลอมทิ้ง
11. Skills: ตรวจว่า ui-ux-pro-max, frontend-design, webapp-testing, web-fix มี SKILL.md ครบ

ถ้า MCP ตัวไหนยังไม่โผล่เป็น tool ให้คุณใช้ (เพราะเพิ่งลงทะเบียน ต้องเปิด Claude ใหม่)
ให้ทดสอบส่วนอื่นให้เสร็จก่อน แล้วบอกผู้ใช้ให้ `/exit` -> `claude` -> `/mcp` -> พิมพ์ "ทดสอบ MCP ต่อ"
และระบุชัดว่าเหลือทดสอบข้อไหน

ถ้าข้อไหนพัง: หาสาเหตุ แก้ แล้วทดสอบซ้ำจนผ่าน (แก้ได้ทั้งสคริปต์ใน /usr/local/bin) ห้ามข้ามโดยไม่บอก

## ขั้นที่ 4: เก็บกวาดและสรุป
- ปิด serve, `web-browser-down`, ลบ cache ชั่วคราวใน /tmp
- คง /root/webtest ไว้เป็นตัวอย่าง
- สรุปเป็นตาราง: เครื่องมือ | สถานะ (ผ่าน/ไม่ผ่าน) | หมายเหตุ
- บอกสิ่งที่ผู้ใช้ต้องทำต่อ (ถ้ามี) เช่น ตั้งค่า .deploy.env ของเว็บจริง
