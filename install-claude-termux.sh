#!/data/data/com.termux/files/usr/bin/bash
# ------------------------------------------------------------------
#  ติดตั้ง Claude Code CLI บน Termux (Android) แบบอัตโนมัติ
#
#  วิธีใช้:
#     bash install-claude-termux.sh            # ติดตั้งแบบปกติ (npm)
#     bash install-claude-termux.sh --proot    # บังคับใช้ Ubuntu (proot-distro)
#
#  ขั้นตอน:
#   1) ลองติดตั้งผ่าน npm บน Termux โดยตรง (เร็ว เบา)
#   2) ถ้าใช้ไม่ได้ จะสลับไปติดตั้งใน Ubuntu ผ่าน proot-distro ให้อัตโนมัติ
#      แล้วสร้างคำสั่ง `claude` ใน Termux ที่เรียกเข้า Ubuntu ให้เอง
# ------------------------------------------------------------------
set -u

G='\033[1;32m'; Y='\033[1;33m'; R='\033[1;31m'; B='\033[1;34m'; N='\033[0m'
info() { printf "${B}==>${N} %s\n" "$*"; }
ok()   { printf "${G}[OK]${N} %s\n" "$*"; }
warn() { printf "${Y}[!]${N} %s\n" "$*"; }
die()  { printf "${R}[X]${N} %s\n" "$*" >&2; exit 1; }

# ---------- ตรวจว่าอยู่ใน Termux ----------
PREFIX="${PREFIX:-/data/data/com.termux/files/usr}"
if [ ! -d "$PREFIX" ] || ! command -v pkg >/dev/null 2>&1; then
  die "สคริปต์นี้ต้องรันในแอป Termux (ติดตั้งจาก F-Droid หรือ GitHub ไม่ใช่ Play Store)"
fi

ARCH="$(uname -m)"
case "$ARCH" in
  aarch64|x86_64) ;;
  *) warn "สถาปัตยกรรม $ARCH อาจไม่รองรับ (แนะนำ aarch64 / ARM64)";;
esac

FORCE_PROOT=0
[ "${1:-}" = "--proot" ] && FORCE_PROOT=1

BASHRC="$HOME/.bashrc"
add_line() { grep -qxF "$1" "$BASHRC" 2>/dev/null || echo "$1" >> "$BASHRC"; }

# ---------- อัปเดตแพ็กเกจพื้นฐาน ----------
info "อัปเดตแพ็กเกจ Termux (อาจใช้เวลาสักครู่)..."
export DEBIAN_FRONTEND=noninteractive
yes | pkg update -y >/dev/null 2>&1 || warn "pkg update มีปัญหา ลองรัน 'termux-change-repo' เพื่อเปลี่ยน mirror"
pkg upgrade -y -o Dpkg::Options::="--force-confnew" >/dev/null 2>&1 || true

# ---------- วิธีที่ 1: npm บน Termux ----------
install_npm() {
  info "ติดตั้ง nodejs-lts, git, ripgrep..."
  pkg install -y nodejs-lts git ripgrep curl >/dev/null 2>&1 \
    || pkg install -y nodejs git ripgrep curl >/dev/null 2>&1 \
    || { warn "ติดตั้ง Node.js ไม่สำเร็จ"; return 1; }
  ok "Node $(node -v 2>/dev/null)  npm $(npm -v 2>/dev/null)"

  # Termux ไม่มี /tmp ที่เขียนได้ และ ripgrep ที่แถมมาใช้บน Android ไม่ได้
  mkdir -p "$PREFIX/tmp"
  export TMPDIR="$PREFIX/tmp"
  export USE_BUILTIN_RIPGREP=0
  add_line "export TMPDIR=\"$PREFIX/tmp\""
  add_line "export USE_BUILTIN_RIPGREP=0"

  info "ติดตั้ง @anthropic-ai/claude-code ผ่าน npm..."
  npm install -g @anthropic-ai/claude-code || { warn "npm install ล้มเหลว"; return 1; }

  hash -r
  if command -v claude >/dev/null 2>&1 && claude --version >/dev/null 2>&1; then
    ok "ติดตั้งสำเร็จ: $(claude --version 2>/dev/null)"
    return 0
  fi
  warn "ติดตั้งแล้วแต่ 'claude --version' รันไม่ผ่านบน Termux โดยตรง"
  npm uninstall -g @anthropic-ai/claude-code >/dev/null 2>&1 || true
  return 1
}

# ---------- วิธีที่ 2: Ubuntu ผ่าน proot-distro ----------
install_proot() {
  info "สลับไปใช้ Ubuntu ผ่าน proot-distro..."
  pkg install -y proot-distro >/dev/null 2>&1 || die "ติดตั้ง proot-distro ไม่สำเร็จ"

  if [ ! -d "$PREFIX/var/lib/proot-distro/installed-rootfs/ubuntu" ]; then
    info "ดาวน์โหลด Ubuntu rootfs (ประมาณ 50-100MB)..."
    proot-distro install ubuntu || die "ติดตั้ง Ubuntu ไม่สำเร็จ"
  else
    ok "มี Ubuntu อยู่แล้ว"
  fi

  info "ติดตั้ง Claude Code ภายใน Ubuntu..."
  proot-distro login ubuntu -- bash -c '
    set -e
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -y
    apt-get install -y curl ca-certificates git ripgrep
    curl -fsSL https://claude.ai/install.sh | bash
    grep -q ".local/bin" ~/.bashrc || echo "export PATH=\$HOME/.local/bin:\$PATH" >> ~/.bashrc
    ~/.local/bin/claude --version
  ' || die "ติดตั้ง Claude ใน Ubuntu ไม่สำเร็จ"

  # สร้างคำสั่ง claude ใน Termux ที่เรียกเข้า Ubuntu โดยอยู่ในโฟลเดอร์ปัจจุบัน
  # (ไฟล์ใน Termux home เข้าถึงได้ที่ /termux-home, ส่วน /sdcard ใช้ได้ถ้ารัน termux-setup-storage แล้ว)
  cat > "$PREFIX/bin/claude" <<'EOF'
#!/data/data/com.termux/files/usr/bin/bash
TH="/data/data/com.termux/files/home"
CWD="$PWD"
case "$CWD" in
  "$TH"*) WD="/termux-home${CWD#$TH}" ;;
  /storage/emulated/0*|/sdcard*) WD="$CWD" ;;
  *) WD="/root" ;;
esac
BINDS=(--bind "$TH:/termux-home")
[ -d /storage/emulated/0 ] && BINDS+=(--bind /storage/emulated/0:/storage/emulated/0 --bind /storage/emulated/0:/sdcard)
exec proot-distro login ubuntu --shared-tmp "${BINDS[@]}" -- \
  bash -lc 'cd "$1" 2>/dev/null || cd ~; shift; exec ~/.local/bin/claude "$@"' _ "$WD" "$@"
EOF
  chmod +x "$PREFIX/bin/claude"
  ok "สร้างคำสั่ง 'claude' ใน Termux แล้ว (ทำงานผ่าน Ubuntu)"
}

if [ "$FORCE_PROOT" -eq 1 ]; then
  install_proot
elif ! install_npm; then
  install_proot
fi

echo
ok "เสร็จสิ้น!"
cat <<EOF

ขั้นตอนต่อไป:
  1) ปิดแล้วเปิด Termux ใหม่ (หรือรัน: source ~/.bashrc)
  2) เข้าโฟลเดอร์โปรเจกต์ แล้วพิมพ์:  claude
  3) ล็อกอินครั้งแรก: ระบบจะให้ลิงก์ เปิดในเบราว์เซอร์มือถือ ล็อกอิน
     แล้วคัดลอกโค้ดกลับมาวางใน Termux

อัปเดตภายหลัง:
  - แบบ npm  : npm install -g @anthropic-ai/claude-code@latest
  - แบบ proot: proot-distro login ubuntu -- ~/.local/bin/claude update
EOF
