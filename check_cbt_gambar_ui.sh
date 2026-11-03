#!/usr/bin/env bash
# Uji tampilan fitur v1.3.0 (soal bergambar, bank soal, token) memakai SALINAN aplikasi di /tmp.
set -eu
DIR="$(cd "$(dirname "$0")" && pwd)"
KERJA=/tmp/cbt_gambar_ui
PORT="${CBT_UI_PORT:-}"
if [ -z "$PORT" ]; then
  for p in $(seq 8951 8999); do
    if ! (exec 3<>"/dev/tcp/127.0.0.1/$p") 2>/dev/null; then PORT=$p; break; fi
    exec 3<&- 2>/dev/null || true
  done
fi
BASE="http://127.0.0.1:$PORT"

echo "== Menyiapkan salinan aplikasi di $KERJA =="
rm -rf "$KERJA"; mkdir -p "$KERJA"
cp -r "$DIR"/. "$KERJA"/
rm -f "$KERJA"/data/cbt.sqlite* "$KERJA"/data/.schema_ok
mkdir -p "$KERJA/data/sessions"; chmod 777 "$KERJA/data" "$KERJA/data/sessions" 2>/dev/null || true
CBT_DB_FILE="$KERJA/data/cbt.sqlite" php -d extension=pdo -d extension=pdo_sqlite "$KERJA/fixture_cbt.php"

# 1) Berkas gambar uji di dalam aplikasi (harus ada SEBELUM keadaan uji disiapkan).
python3 - "$KERJA/assets/logo-uji.png" <<'PY'
import struct, sys, zlib
def png(w, h):
    def chunk(t, d):
        return struct.pack('>I', len(d)) + t + d + struct.pack('>I', zlib.crc32(t + d) & 0xffffffff)
    isi = b''.join(b'\x00' + bytes([(x * 2) % 256 for x in range(w * 3)]) for _ in range(h))
    return (b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', w, h, 8, 2, 0, 0, 0))
            + chunk(b'IDAT', zlib.compress(isi)) + chunk(b'IEND', b''))
open(sys.argv[1], 'wb').write(png(240, 140))
PY

# 2) Keadaan uji: soal pertama bergambar, ujian aktif dengan jadwal terbuka + token tetap UJI123.
CBT_DB_FILE="$KERJA/data/cbt.sqlite" php -d extension=pdo -d extension=pdo_sqlite -r '
require "'"$KERJA"'/inc/init.php";
q("UPDATE ujian SET status = ?, mulai = ?, selesai = ?, token = ?, token_dibuat = ? WHERE id = 1",
  ["aktif", date("Y-m-d H:i:s", time() - 120), date("Y-m-d H:i:s", time() + 3600),
   "UJI123", date("Y-m-d H:i:s")]);
q("UPDATE soal SET gambar = ? WHERE id = (SELECT MIN(id) FROM soal WHERE ujian_id = 1)",
  ["assets/logo-uji.png"]);
echo "keadaan uji siap: token=UJI123, 1 soal bergambar\n";'

# 3) Server aplikasi
php -d extension=pdo -d extension=pdo_sqlite -S "127.0.0.1:$PORT" -t "$KERJA" > /tmp/cbt_gambar_ui.log 2>&1 &
SRV=$!
trap 'kill $SRV 2>/dev/null || true' EXIT
for i in $(seq 1 25); do curl -s -o /dev/null "$BASE/login.php" && break; sleep 0.4; done

gagal=0
BASE_URL="$BASE" CBT_TOKEN="UJI123" node "$DIR/check_cbt_gambar_ui.js" || gagal=1
grep -iE 'PHP (Warning|Notice|Fatal)|Uncaught' /tmp/cbt_gambar_ui.log | head -5 || true
exit "$gagal"
