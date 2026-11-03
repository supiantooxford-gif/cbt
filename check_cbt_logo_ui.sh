#!/usr/bin/env bash
# Uji tampilan Logo Aplikasi (Playwright) memakai SALINAN aplikasi di /tmp.
set -eu
DIR="$(cd "$(dirname "$0")" && pwd)"
KERJA=/tmp/cbt_logo_ui
PORT="${CBT_UI_PORT:-}"
if [ -z "$PORT" ]; then
  for p in $(seq 8971 8999); do
    if ! (exec 3<>"/dev/tcp/127.0.0.1/$p") 2>/dev/null; then PORT=$p; break; fi
    exec 3<&- 2>/dev/null || true
  done
fi
BASE="http://127.0.0.1:$PORT"
SANDI=uji_logo_123

echo "== Menyiapkan salinan aplikasi di $KERJA =="
rm -rf "$KERJA"; mkdir -p "$KERJA"
cp -r "$DIR"/. "$KERJA"/
rm -f "$KERJA"/data/cbt.sqlite* "$KERJA"/data/.schema_ok
mkdir -p "$KERJA/data/sessions"; chmod 777 "$KERJA/data" "$KERJA/data/sessions" 2>/dev/null || true
CBT_DB_FILE="$KERJA/data/cbt.sqlite" php -d extension=pdo -d extension=pdo_sqlite "$KERJA/fixture_cbt.php"
# sandi Super Admin ditetapkan pada SALINAN saja
CBT_DB_FILE="$KERJA/data/cbt.sqlite" php -d extension=pdo -d extension=pdo_sqlite -r '
require "'"$KERJA"'/inc/init.php";
q("UPDATE users SET password = ? WHERE username = ?", [password_hash("'"$SANDI"'", PASSWORD_DEFAULT), "admin"]);'

# berkas logo uji (PNG 300x300)
python3 - <<'PY'
import struct, zlib
def png(w, h):
    def chunk(t, d):
        return struct.pack('>I', len(d)) + t + d + struct.pack('>I', zlib.crc32(t + d) & 0xffffffff)
    isi = b''.join(b'\x00' + bytes([(x * 5) % 256 for x in range(w * 3)]) for _ in range(h))
    return (b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', w, h, 8, 2, 0, 0, 0))
            + chunk(b'IDAT', zlib.compress(isi)) + chunk(b'IEND', b''))
open('/tmp/cbt_logo_ui_baru.png', 'wb').write(png(300, 300))
PY

php -d extension=pdo -d extension=pdo_sqlite -S "127.0.0.1:$PORT" -t "$KERJA" > /tmp/cbt_logo_ui.log 2>&1 &
SRV=$!
trap 'kill $SRV 2>/dev/null || true' EXIT
for i in $(seq 1 25); do curl -s -o /dev/null "$BASE/login.php" && break; sleep 0.4; done

gagal=0
BASE_URL="$BASE" CBT_SANDI_ADMIN="$SANDI" CBT_LOGO_UJI=/tmp/cbt_logo_ui_baru.png \
  node "$DIR/check_cbt_logo_ui.js" || gagal=1
grep -iE 'PHP (Warning|Notice|Fatal)|Uncaught' /tmp/cbt_logo_ui.log | head -5 || true
exit "$gagal"
