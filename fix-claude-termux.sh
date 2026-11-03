#!/data/data/com.termux/files/usr/bin/bash
# แก้คำสั่ง claude ใน Termux (สำหรับคนที่ติดตั้งแบบ proot ไปแล้ว) - รันไฟล์นี้ครั้งเดียวจบ
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
  bash -lc 'cd "$1" 2>/dev/null || cd ~; shift; exec ~/.local/bin/claude "$@"' _ "$WD" "$@"
WRAPPER
chmod +x "$P/bin/claude"

# alias claude เดิมใน ~/.bashrc ทำให้มี flag ที่ใช้กับ root ไม่ได้ -> ลบออก (สำรองไว้ที่ ~/.bashrc.bak)
if grep -qE "^[[:space:]]*alias[[:space:]]+claude=" ~/.bashrc 2>/dev/null; then
  cp ~/.bashrc ~/.bashrc.bak
  sed -i -E "/^[[:space:]]*alias[[:space:]]+claude=/d" ~/.bashrc
  echo "ลบ alias claude เดิมแล้ว (สำรองไว้ที่ ~/.bashrc.bak)"
fi
unalias claude 2>/dev/null

echo "เสร็จแล้ว! ปิด-เปิด Termux ใหม่ แล้วพิมพ์: claude"
