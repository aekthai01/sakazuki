#!/usr/bin/env bash
# ------------------------------------------------------------------
#  ติดตั้งชุดเครื่องมือ "แก้เว็บ-ทดสอบเว็บ" ให้ Claude Code (Termux + Ubuntu proot)
#
#  วิธีใช้ (รันใน Termux):  bash setup-web-tools.sh
#
#  สิ่งที่ติดตั้ง (ทั้งหมดอยู่ใน Ubuntu ที่ Claude รันอยู่):
#   Tools : Node.js 22, Python3, Chromium (headless), Playwright, Lighthouse,
#           prettier, html-validate, serve, lftp/rsync/ssh (อัปโหลดขึ้นโฮสต์), jq ฯลฯ
#   MCP   : chrome-devtools (ดีบัก: console, network, performance, screenshot)
#           playwright      (คลิก/กรอกฟอร์ม/ทดสอบหน้าเว็บสด)
#           context7        (เอกสาร library/framework ล่าสุด)
#           * ทั้งสอง MCP ใช้ Chromium ตัวเดียวกัน -> เห็นหน้าเว็บเดียวกันสดๆ
#   Skill : ui-ux-pro-max, frontend-design, webapp-testing, web-fix (ขั้นตอน 5 ขั้น)
#   คำสั่ง: webdeploy (อัปขึ้นโฮสต์), webcheck (ทดสอบเว็บจริง), web-browser-up/down
# ------------------------------------------------------------------
set -u
G='\033[1;32m'; Y='\033[1;33m'; R='\033[1;31m'; B='\033[1;34m'; N='\033[0m'
info() { printf "${B}==>${N} %s\n" "$*"; }
ok()   { printf "${G}[OK]${N} %s\n" "$*"; }
warn() { printf "${Y}[!]${N} %s\n" "$*"; }
die()  { printf "${R}[X]${N} %s\n" "$*" >&2; exit 1; }

# ================= ส่วนที่ 1: รันใน Termux =================
if [ "${1:-}" != "--inside" ]; then
  command -v proot-distro >/dev/null 2>&1 || die "ไม่พบ proot-distro — รัน install-claude-termux.sh --proot ก่อน"
  proot-distro login ubuntu -- true >/dev/null 2>&1 || die "ยังไม่มี Ubuntu — รัน install-claude-termux.sh --proot ก่อน"

  warn "จะใช้พื้นที่ประมาณ 1-1.5 GB และเวลา 10-30 นาที (ขึ้นกับเน็ต)"
  SELF="$(realpath "$0")"
  proot-distro login ubuntu --shared-tmp --bind "$(dirname "$SELF"):/mnt/websetup" -- \
    bash "/mnt/websetup/$(basename "$SELF")" --inside || die "ติดตั้งใน Ubuntu ไม่สำเร็จ (ดู error ด้านบน)"

  # อัปเดตคำสั่ง claude ใน Termux: ปิด Chromium ให้อัตโนมัติหลัง /exit (ไม่งั้น Termux ค้าง)
  P="${PREFIX:-/data/data/com.termux/files/usr}"
  cat > "$P/bin/claude" <<'WRAPPER'
#!/data/data/com.termux/files/usr/bin/bash
# claude wrapper: รัน Claude Code ใน Ubuntu (proot-distro) โดยอยู่ในโฟลเดอร์ปัจจุบัน
TH="/data/data/com.termux/files/home"
case "$PWD" in
  "$TH"*) WD="/termux-home${PWD#$TH}" ;;
  /storage/emulated/0*|/sdcard*) WD="$PWD" ;;   # proot-distro ผูก /sdcard ให้อยู่แล้ว
  *) WD="/root" ;;
esac
exec proot-distro login ubuntu --shared-tmp --bind "$TH:/termux-home" -- \
  bash -lc 'cd "$1" 2>/dev/null || cd ~; shift; ~/.local/bin/claude "$@"; rc=$?; web-browser-down >/dev/null 2>&1; exit $rc' _ "$WD" "$@"
WRAPPER
  chmod +x "$P/bin/claude"

  echo
  ok "ติดตั้งครบแล้ว!"
  cat <<'EOF'

วิธีใช้:
  1) cd เข้าโฟลเดอร์เว็บ แล้วพิมพ์: claude
  2) พิมพ์ /mcp เพื่อดูว่า chrome-devtools, playwright, context7 เชื่อมต่อแล้ว
  3) สั่งงานได้เลย เช่น  "เว็บ https://example.com ปุ่มสมัครกดไม่ได้ แก้ให้ที แล้วอัปขึ้นโฮสต์"
     Claude จะทำ 5 ขั้น: ดูสาเหตุ -> แก้ -> ตรวจ -> อัปโหลด -> ทดสอบเว็บจริง

ตั้งค่าโฮสต์ (ครั้งเดียวต่อโปรเจกต์): ในโฟลเดอร์เว็บพิมพ์
     claude  แล้วสั่ง  "ตั้งค่า webdeploy ให้หน่อย"   หรือคัดลอก ~/.deploy.env.example มาเป็น .deploy.env
EOF
  exit 0
fi

# ================= ส่วนที่ 2: รันใน Ubuntu (root) =================
export DEBIAN_FRONTEND=noninteractive
export PATH="$HOME/.local/bin:/usr/local/bin:$PATH"

info "[1/8] ติดตั้งแพ็กเกจพื้นฐาน..."
apt-get update -y || warn "apt update มีปัญหา"
apt-get install -y curl wget ca-certificates git gnupg jq ripgrep unzip zip \
  python3 python3-pip python3-venv lftp rsync openssh-client procps || die "apt install ล้มเหลว"
for pk in sshpass dnsutils fonts-noto-core fonts-thai-tlwg; do
  apt-get install -y "$pk" >/dev/null 2>&1 || warn "ข้าม $pk (ไม่มีใน repo)"
done
ok "แพ็กเกจพื้นฐาน"

info "[2/8] ติดตั้ง Node.js 22..."
NODE_MAJOR="$(node -v 2>/dev/null | sed 's/^v\([0-9]*\).*/\1/')"
if [ "${NODE_MAJOR:-0}" -lt 20 ]; then
  curl -fsSL https://deb.nodesource.com/setup_22.x | bash - && apt-get install -y nodejs \
    || apt-get install -y nodejs npm || die "ติดตั้ง Node.js ไม่สำเร็จ"
fi
ok "Node $(node -v)  npm $(npm -v)"

info "[3/8] ติดตั้ง Playwright + Chromium (ไฟล์ใหญ่ รอสักครู่)..."
pip3 install -q --break-system-packages playwright 2>/dev/null || pip3 install -q playwright \
  || die "ติดตั้ง playwright (python) ไม่สำเร็จ"
python3 -m playwright install-deps chromium || {
  warn "install-deps ไม่ผ่าน ลองติดตั้ง library เอง..."
  apt-get install -y libnss3 libnspr4 libatk1.0-0t64 libatk-bridge2.0-0t64 libcups2t64 \
    libdrm2 libxkbcommon0 libxcomposite1 libxdamage1 libxfixes3 libxrandr2 libgbm1 \
    libpango-1.0-0 libcairo2 libasound2t64 2>/dev/null \
  || apt-get install -y libnss3 libnspr4 libatk1.0-0 libatk-bridge2.0-0 libcups2 \
    libdrm2 libxkbcommon0 libxcomposite1 libxdamage1 libxfixes3 libxrandr2 libgbm1 \
    libpango-1.0-0 libcairo2 libasound2 || warn "ติดตั้ง library บางตัวไม่ได้"
}
python3 -m playwright install chromium || die "ดาวน์โหลด Chromium ไม่สำเร็จ"
CHROME_BIN="$(find "$HOME/.cache/ms-playwright" -type f -name chrome -path '*chrome-linux*' 2>/dev/null | sort | tail -1)"
[ -x "$CHROME_BIN" ] || die "หาไฟล์ Chromium ไม่เจอ"
ln -sf "$CHROME_BIN" /usr/local/bin/web-chrome
ok "Chromium: $CHROME_BIN"

info "[4/8] สร้างคำสั่งเปิด/ปิดเบราว์เซอร์ที่ MCP ใช้ร่วมกัน..."
cat > /usr/local/bin/web-browser-up <<'EOF'
#!/usr/bin/env bash
# เปิด Chromium headless พร้อม remote debugging port 9222 (ถ้ายังไม่เปิด)
# ห้ามพิมพ์อะไรออก stdout เพราะ MCP ใช้ stdout คุยกับ Claude
PORT="${WEB_BROWSER_PORT:-9222}"
curl -fs "http://127.0.0.1:$PORT/json/version" >/dev/null 2>&1 && exit 0
mkdir -p "$HOME/.web-browser-profile"
nohup /usr/local/bin/web-chrome --headless=new --no-sandbox --disable-gpu \
  --disable-dev-shm-usage --no-first-run --no-default-browser-check \
  --remote-debugging-address=127.0.0.1 --remote-debugging-port="$PORT" \
  --user-data-dir="$HOME/.web-browser-profile" --window-size=1366,900 \
  --lang=th-TH about:blank >"$HOME/.web-browser.log" 2>&1 &
for _ in $(seq 1 40); do
  curl -fs "http://127.0.0.1:$PORT/json/version" >/dev/null 2>&1 && exit 0
  sleep 0.5
done
echo "Chromium เปิดไม่ขึ้น ดู log: $HOME/.web-browser.log" >&2
exit 1
EOF
cat > /usr/local/bin/web-browser-down <<'EOF'
#!/usr/bin/env bash
pkill -f -- "--user-data-dir=$HOME/.web-browser-profile" 2>/dev/null || true
EOF
cat > /usr/local/bin/mcp-chrome-devtools <<'EOF'
#!/usr/bin/env bash
web-browser-up >&2 || exit 1
exec chrome-devtools-mcp --browserUrl http://127.0.0.1:9222
EOF
cat > /usr/local/bin/mcp-playwright <<'EOF'
#!/usr/bin/env bash
web-browser-up >&2 || exit 1
exec playwright-mcp --cdp-endpoint http://127.0.0.1:9222
EOF
chmod +x /usr/local/bin/web-browser-up /usr/local/bin/web-browser-down \
  /usr/local/bin/mcp-chrome-devtools /usr/local/bin/mcp-playwright
if web-browser-up; then ok "Chromium เปิดได้ (port 9222)"; else warn "Chromium ยังเปิดไม่ได้ — ดู ~/.web-browser.log"; fi

info "[5/8] ติดตั้งเครื่องมือเว็บ + MCP ผ่าน npm (ทีละตัว)..."
# npm ใน proot มักพังตอนเขียน cache พร้อมกันหลายไฟล์ -> ล้าง cache, ลดการดาวน์โหลดพร้อมกัน, ลองซ้ำ
npm cache clean --force >/dev/null 2>&1 || true
rm -rf "$HOME/.npm/_cacache"
npm config set maxsockets 1 >/dev/null 2>&1 || true
npm config set fetch-retries 5 >/dev/null 2>&1 || true
NPM_FAIL=""
for pk in chrome-devtools-mcp @playwright/mcp @upstash/context7-mcp \
          lighthouse prettier html-validate serve ui-ux-pro-max-cli; do
  done_ok=0
  for try in 1 2 3; do
    if npm install -g --no-audit --no-fund "$pk" >/dev/null 2>"$HOME/.npm-err.log"; then done_ok=1; break; fi
    warn "$pk ล้มเหลว (ครั้งที่ $try) ล้าง cache แล้วลองใหม่..."
    rm -rf "$HOME/.npm/_cacache"; sleep 2
  done
  if [ "$done_ok" = 1 ]; then ok "$pk"; else NPM_FAIL+=" $pk"; tail -5 "$HOME/.npm-err.log"; fi
done
[ -z "$NPM_FAIL" ] || warn "ติดตั้งไม่สำเร็จ:$NPM_FAIL  (รันสคริปต์นี้ซ้ำได้)"

info "[6/8] สร้างคำสั่ง webdeploy / webcheck..."
cat > /usr/local/bin/webdeploy <<'EOF'
#!/usr/bin/env bash
# อัปโหลดเว็บขึ้นโฮสต์ ตามค่าใน .deploy.env ของโฟลเดอร์ปัจจุบัน
#   webdeploy            อัปโหลดจริง (เฉพาะไฟล์ที่เปลี่ยน)
#   webdeploy --dry-run  ดูว่าจะอัปไฟล์อะไรบ้าง โดยยังไม่อัป
set -e
ENV_FILE="${DEPLOY_ENV:-.deploy.env}"
[ -f "$ENV_FILE" ] || { echo "ไม่พบ $ENV_FILE — คัดลอกจาก ~/.deploy.env.example"; exit 1; }
case "$ENV_FILE" in */*) ;; *) ENV_FILE="./$ENV_FILE" ;; esac
set -a; . "$ENV_FILE"; set +a
: "${PROTOCOL:?}" "${HOST:?}" "${REMOTE_DIR:?}"
LOCAL_DIR="${LOCAL_DIR:-.}"
DRY=""; [ "${1:-}" = "--dry-run" ] && DRY="--dry-run"
EXCL=(.git/ node_modules/ .deploy.env .claude/ .DS_Store)
for e in ${EXCLUDE:-}; do EXCL+=("$e"); done

case "$PROTOCOL" in
  ftp|ftps|sftp)
    LX=""; for e in "${EXCL[@]}"; do LX+=" --exclude-glob $e"; done
    PORT_OPT=""; [ -n "${PORT:-}" ] && PORT_OPT="-p $PORT"
    SSL="set ftp:ssl-allow yes;"
    [ "${SSL_VERIFY:-yes}" = "no" ] && SSL+=" set ssl:verify-certificate no;"
    [ "$PROTOCOL" = "ftps" ] && SSL+=" set ftp:ssl-force yes;"
    [ "$PROTOCOL" = "sftp" ] && SSL="set sftp:auto-confirm yes;"
    proto="$PROTOCOL"; [ "$proto" = "ftps" ] && proto="ftp"
    lftp $PORT_OPT -u "$USER,${PASS:-}" "$proto://$HOST" -e \
      "$SSL set net:max-retries 2; mirror -R --only-newer --parallel=3 --verbose $DRY $LX \"$LOCAL_DIR\" \"$REMOTE_DIR\"; bye"
    ;;
  rsync)
    RX=(); for e in "${EXCL[@]}"; do RX+=(--exclude "$e"); done
    SSH="ssh -o StrictHostKeyChecking=accept-new ${PORT:+-p $PORT}"
    if [ -n "${PASS:-}" ]; then SSH="sshpass -e $SSH"; export SSHPASS="$PASS"; fi
    rsync -avz $DRY "${RX[@]}" -e "$SSH" "$LOCAL_DIR/" "$USER@$HOST:$REMOTE_DIR/"
    ;;
  *) echo "PROTOCOL ต้องเป็น ftp, ftps, sftp หรือ rsync"; exit 1 ;;
esac
if [ -z "$DRY" ] && [ -n "${SITE_URL:-}" ]; then
  echo; echo "==> ทดสอบ $SITE_URL"; webcheck "$SITE_URL" --quick
fi
EOF
cat > /usr/local/bin/webcheck <<'EOF'
#!/usr/bin/env bash
# ทดสอบเว็บจริง: สถานะ HTTP, เวลาโหลด, redirect, SSL และคะแนน Lighthouse
#   webcheck https://example.com          ตรวจเต็ม (รวม Lighthouse)
#   webcheck https://example.com --quick  ตรวจเร็ว (HTTP อย่างเดียว)
URL="${1:?ใส่ URL เช่น webcheck https://example.com}"
echo "== HTTP =="
curl -sS -o /dev/null -L --max-time 30 -H 'Cache-Control: no-cache' \
  -w 'status=%{http_code}  time=%{time_total}s  size=%{size_download}B  final=%{url_effective}  redirects=%{num_redirects}\n' "$URL"
[ "${2:-}" = "--quick" ] && exit 0
OUT="$(mktemp -d)/lh.json"
echo "== Lighthouse (mobile) — ใช้เวลาประมาณ 1 นาที =="
CHROME_PATH=/usr/local/bin/web-chrome lighthouse "$URL" --quiet --output=json --output-path="$OUT" \
  --chrome-flags="--headless=new --no-sandbox --disable-gpu --disable-dev-shm-usage" >/dev/null 2>&1 \
  || { echo "Lighthouse รันไม่สำเร็จ"; exit 1; }
node -e '
const r=require(process.argv[1]);
for (const [k,c] of Object.entries(r.categories)) console.log(`${c.title.padEnd(16)} ${Math.round(c.score*100)}`);
const bad=Object.values(r.audits).filter(a=>a.score!==null&&a.score<0.5&&a.scoreDisplayMode!=="informative").slice(0,10);
if(bad.length){console.log("\nปัญหาหลัก:");bad.forEach(a=>console.log(" - "+a.title+(a.displayValue?` (${a.displayValue})`:"")));}
console.log("\nรายงานเต็ม: "+process.argv[1]);' "$OUT"
EOF
chmod +x /usr/local/bin/webdeploy /usr/local/bin/webcheck
cat > "$HOME/.deploy.env.example" <<'EOF'
# คัดลอกไฟล์นี้ไปไว้ในโฟลเดอร์เว็บ ตั้งชื่อว่า .deploy.env แล้วแก้ค่า
# (ไฟล์นี้มีรหัสผ่าน — ห้าม commit ขึ้น git: เพิ่ม .deploy.env ใน .gitignore)
PROTOCOL=ftp            # ftp | ftps | sftp | rsync
HOST=ftp.example.com
PORT=                   # เว้นว่าง = ค่าปกติ (ftp 21, sftp/rsync 22)
USER=username
PASS='password'
REMOTE_DIR=/public_html # โฟลเดอร์บนโฮสต์
LOCAL_DIR=.             # โฟลเดอร์ที่จะอัป (เช่น dist หรือ build ถ้ามีการ build)
SITE_URL=https://example.com
EXCLUDE=""              # ไฟล์/โฟลเดอร์ที่ไม่อัปเพิ่มเติม คั่นด้วยช่องว่าง
SSL_VERIFY=yes          # ถ้าโฮสต์ใช้ใบรับรองไม่ถูกต้องและอัปไม่ได้ ค่อยเปลี่ยนเป็น no
EOF
ok "webdeploy, webcheck, ~/.deploy.env.example"

info "[7/8] ติดตั้ง Skills..."
mkdir -p "$HOME/.claude/skills"
if command -v uipro >/dev/null 2>&1 && ( cd "$HOME" && uipro init --ai claude --global ); then
  ok "ui-ux-pro-max (uipro)"
else
  warn "uipro ไม่สำเร็จ ใช้วิธีคัดลอกจาก GitHub แทน"
  T="$(mktemp -d)"; git clone -q --depth 1 https://github.com/nextlevelbuilder/ui-ux-pro-max-skill "$T/u" \
    && cp -r "$T/u/.claude/skills/." "$HOME/.claude/skills/" && ok "ui-ux-pro-max (GitHub)" \
    || warn "ติดตั้ง ui-ux-pro-max ไม่ได้"
fi
T="$(mktemp -d)"
if git clone -q --depth 1 --filter=blob:none --sparse https://github.com/anthropics/skills "$T/a" \
   && git -C "$T/a" sparse-checkout set skills/frontend-design skills/webapp-testing; then
  cp -r "$T/a/skills/frontend-design" "$T/a/skills/webapp-testing" "$HOME/.claude/skills/"
else
  warn "ติดตั้ง frontend-design / webapp-testing ไม่ได้"
fi

mkdir -p "$HOME/.claude/skills/web-fix"
cat > "$HOME/.claude/skills/web-fix/SKILL.md" <<'EOF'
---
name: web-fix
description: Full website fix workflow - diagnose, fix, verify, deploy to hosting, and test on the live site. Use whenever the user reports a website bug, broken layout, slow page, form or button not working, or asks to change a website and put it online.
---

# Web fix workflow (5 steps)

Always go through all five steps and report each one briefly (in the user's language, usually Thai).

## 1. Diagnose (ดูสาเหตุ)
- Reproduce first. Open the page with the `chrome-devtools` MCP (`new_page`/`navigate_page`),
  take a screenshot, read `list_console_messages` and `list_network_requests` (look for 4xx/5xx, CORS, mixed content).
- Check mobile too: `resize_page` / `emulate` to ~390x844.
- Find the responsible code with `rg`. State the root cause before editing.

## 2. Fix (แก้ไข)
- Smallest change that fixes the root cause. Keep the existing style.
- For design/UI work use the `ui-ux-pro-max` and `frontend-design` skills.
- If a docs question about a library comes up, use the `context7` MCP.

## 3. Verify locally (ตรวจสอบ)
- Static sites: `serve -l 8080 <dir>` in the background, then open http://127.0.0.1:8080 with the MCP.
  Framework projects: run the project's own dev/build script.
- Re-run the reproduction: screenshot, console has no new errors, network is clean.
- Interaction flows (click, forms, login): `playwright` MCP or the `webapp-testing` skill.
- `npx html-validate <file>` for HTML, `prettier --check` if the project uses it, project tests/lint if they exist.

## 4. Deploy (อัปขึ้นโฮสต์)
- Needs `.deploy.env` in the project root (template: `~/.deploy.env.example`). If missing, ask the user for
  protocol/host/user/password/remote dir, create it, and add `.deploy.env` to `.gitignore`.
- Build first if the project has a build step and set `LOCAL_DIR` to the output dir.
- Run `webdeploy --dry-run`, show the file list, then `webdeploy`.
- If the project is a git repo deployed by git (Vercel/Netlify/GitHub Pages), commit and push instead.

## 5. Test on the live site (ทดสอบบนเว็บจริง)
- `webcheck <SITE_URL>` (HTTP status + Lighthouse scores).
- Open the live URL with the `chrome-devtools` MCP, hard reload (cache can hide changes; add `?v=<timestamp>`),
  repeat the step-1 reproduction and confirm the bug is gone, with screenshots on desktop and mobile size.
- If it still fails on live but works locally: check caching/CDN, wrong upload dir, file permissions, case-sensitive paths.

Finish with a short summary: cause, what changed (files), verify result, deploy result, live test result.
EOF
ok "ui-ux-pro-max, frontend-design, webapp-testing, web-fix"

info "[8/8] ลงทะเบียน MCP + ตั้งค่า Claude..."
CLAUDE_BIN="$HOME/.local/bin/claude"
[ -x "$CLAUDE_BIN" ] || CLAUDE_BIN="$(command -v claude)"
if [ -n "$CLAUDE_BIN" ]; then
  for name in chrome-devtools playwright context7; do
    "$CLAUDE_BIN" mcp remove -s user "$name" >/dev/null 2>&1 || true
  done
  "$CLAUDE_BIN" mcp add -s user chrome-devtools -- /usr/local/bin/mcp-chrome-devtools
  "$CLAUDE_BIN" mcp add -s user playwright -- /usr/local/bin/mcp-playwright
  "$CLAUDE_BIN" mcp add -s user context7 -- context7-mcp
else
  warn "หา claude ไม่เจอ ข้ามการลงทะเบียน MCP"
fi

python3 - <<'PY'
import json, os
p = os.path.expanduser("~/.claude/settings.json")
d = {}
if os.path.exists(p):
    try:
        d = json.load(open(p))
    except Exception:
        os.rename(p, p + ".broken"); d = {}
d.setdefault("env", {})["CHROME_PATH"] = "/usr/local/bin/web-chrome"
json.dump(d, open(p, "w"), indent=2, ensure_ascii=False)
PY

MARK="<!-- web-tools-setup -->"
if ! grep -qF "$MARK" "$HOME/.claude/CLAUDE.md" 2>/dev/null; then
cat >> "$HOME/.claude/CLAUDE.md" <<'EOF'

<!-- web-tools-setup -->
# Environment: Claude Code on Android (Termux -> Ubuntu proot, root user)
- Web tasks: follow the `web-fix` skill (diagnose -> fix -> verify -> deploy -> live test).
- Live browser: MCP `chrome-devtools` (debug, console, network, performance, screenshots) and
  `playwright` (interaction) share one headless Chromium on 127.0.0.1:9222. Restart it with `web-browser-down; web-browser-up`.
- Commands: `serve` (local preview), `webdeploy` (upload via .deploy.env), `webcheck <url>` (live check + Lighthouse),
  `lighthouse`, `html-validate`, `prettier`, `lftp`, `rsync`.
- Termux home is mounted at /termux-home, phone storage at /sdcard.
- Phone is low on RAM: don't run more than one dev server; stop background servers when done.
- Reply to the user in Thai.
EOF
fi
web-browser-down
ok "MCP: chrome-devtools, playwright, context7"
