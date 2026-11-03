#!/data/data/com.termux/files/usr/bin/bash
# แก้อาการ "เปิด claude ไม่ได้" หลังตั้งโหมด bypassPermissions ไว้
# (Claude ใน proot รันเป็น root ซึ่งไม่อนุญาตโหมดนี้ เลยเปิดไม่ขึ้น)
# สคริปต์นี้เปลี่ยน bypassPermissions -> acceptEdits (แก้ไฟล์ได้เลยไม่ต้องถาม แต่คำสั่ง shell ยังถาม)
# ไฟล์เดิมสำรองไว้เป็น *.bak

FIX='for f in "$@"; do
  [ -f "$f" ] || continue
  if grep -q "bypassPermissions" "$f"; then
    cp "$f" "$f.bak"
    sed -i "s/\"bypassPermissions\"/\"acceptEdits\"/g" "$f"
    echo "แก้แล้ว: $f"
  fi
done'

echo "==> ตรวจไฟล์ตั้งค่าใน Ubuntu..."
proot-distro login ubuntu -- bash -c "$FIX" _ \
  /root/.claude/settings.json /root/.claude/settings.local.json

echo "==> ตรวจไฟล์ตั้งค่าในโปรเจกต์ (Termux home)..."
mapfile -t FILES < <(find "$HOME" -maxdepth 5 -path '*/.claude/settings*.json' 2>/dev/null)
bash -c "$FIX" _ "${FILES[@]}"

# เผื่อมี alias ที่ใส่ --dangerously-skip-permissions ไว้
if grep -qE "^[[:space:]]*alias[[:space:]]+claude=" ~/.bashrc 2>/dev/null; then
  cp ~/.bashrc ~/.bashrc.bak
  sed -i -E "/^[[:space:]]*alias[[:space:]]+claude=/d" ~/.bashrc
  echo "ลบ alias claude เดิมแล้ว (สำรองไว้ที่ ~/.bashrc.bak)"
fi

echo
echo "เสร็จแล้ว! ปิด-เปิด Termux ใหม่ แล้วพิมพ์: claude"
