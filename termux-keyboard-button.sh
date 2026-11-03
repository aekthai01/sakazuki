#!/data/data/com.termux/files/usr/bin/bash
# เพิ่มปุ่ม ⌨ (เปิด/ปิดแป้นพิมพ์มือถือ) และปุ่ม PASTE ลงในแถบปุ่มพิเศษของ Termux
# รันครั้งเดียวจบ: bash termux-keyboard-button.sh

CONF_DIR="$HOME/.termux"
CONF="$CONF_DIR/termux.properties"
mkdir -p "$CONF_DIR"
touch "$CONF"
cp "$CONF" "$CONF.bak"

# ลบการตั้งค่า extra-keys เดิม (รวมบรรทัดต่อท้ายที่ลงท้ายด้วย \)
awk '
  skip { if ($0 !~ /\\[[:space:]]*$/) skip=0; next }
  /^[[:space:]]*extra-keys[[:space:]]*=/ { if ($0 ~ /\\[[:space:]]*$/) skip=1; next }
  { print }
' "$CONF.bak" > "$CONF"

# แถว 1: ESC / - HOME ↑ END PGUP ⌨(แป้นพิมพ์)
# แถว 2: TAB CTRL ALT ← ↓ → PGDN PASTE
cat >> "$CONF" <<'EOF'
extra-keys = [ \
  ['ESC', '/', '-', 'HOME', 'UP', 'END', 'PGUP', {key: 'KEYBOARD', display: '⌨'}], \
  ['TAB', 'CTRL', 'ALT', 'LEFT', 'DOWN', 'RIGHT', 'PGDN', {key: 'PASTE', display: 'วาง'}] \
]
EOF

termux-reload-settings 2>/dev/null || true
echo "เสร็จแล้ว! ปุ่ม ⌨ อยู่มุมขวาบนของแถบปุ่ม กดเพื่อเปิด/ปิดแป้นพิมพ์"
echo "ถ้ายังไม่เห็น ให้ปิด-เปิด Termux ใหม่  (ไฟล์เดิมสำรองไว้ที่ $CONF.bak)"
