#!/usr/bin/env bash
# Uji tampilan Kartu Ujian (Playwright) memakai SALINAN aplikasi di /tmp.
set -eu
DIR="$(cd "$(dirname "$0")" && pwd)"
KERJA=/tmp/cbt_kartu_ui
PORT="${CBT_UI_PORT:-}"
if [ -z "$PORT" ]; then
  for p in $(seq 8941 8950); do
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

# 10 siswa (supaya kartu mengisi lebih dari satu lembar A4 = 8 kartu)
CBT_DB_FILE="$KERJA/data/cbt.sqlite" php -d extension=pdo -d extension=pdo_sqlite -r '
require "'"$KERJA"'/inc/init.php";
for ($i = 1; $i <= 10; $i++) {
    $nis = "UI000$i";
    if (!val("SELECT id FROM siswa WHERE sekolah_id = 1 AND nis = ?", [$nis])) {
        q("INSERT INTO siswa (sekolah_id, nis, nisn, nama, jenis_kelamin, kelas_id, wali, aktif, status, dibuat)
           VALUES (1,?,?,?,?,1,?,1,\"aktif\",?)",
          [$nis, "00$nis", "Siswa Kartu " . str_pad((string) $i, 2, "0", STR_PAD_LEFT),
           ($i % 2 ? "L" : "P"), "Wali $i", date("Y-m-d H:i:s")]);
        $sid = (int) db()->lastInsertId();
        q("INSERT INTO users (sekolah_id, nama, username, password, role, siswa_id, aktif, dibuat)
           VALUES (1,?,?,?,?,?,1,?)",
          ["Siswa Kartu $i", "ukartu$i", password_hash("siswa123", PASSWORD_DEFAULT), "siswa", $sid, date("Y-m-d H:i:s")]);
    }
}
echo "siswa uji siap\n";' >/dev/null

# logo sekolah supaya kepala kartu diuji bersama logo
python3 - "$KERJA/assets/logo-uji.png" <<'PY'
import struct, sys, zlib
def png(w, h):
    def chunk(t, d):
        return struct.pack('>I', len(d)) + t + d + struct.pack('>I', zlib.crc32(t + d) & 0xffffffff)
    isi = b''.join(b'\x00' + bytes([(x * 2) % 256 for x in range(w * 3)]) for _ in range(h))
    return (b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', w, h, 8, 2, 0, 0, 0))
            + chunk(b'IDAT', zlib.compress(isi)) + chunk(b'IEND', b''))
open(sys.argv[1], 'wb').write(png(120, 120))
PY
CBT_DB_FILE="$KERJA/data/cbt.sqlite" php -d extension=pdo -d extension=pdo_sqlite -r '
require "'"$KERJA"'/inc/init.php";
q("UPDATE sekolah SET logo = ? WHERE id = 1", ["assets/logo-uji.png"]);'

php -d extension=pdo -d extension=pdo_sqlite -S "127.0.0.1:$PORT" -t "$KERJA" > /tmp/cbt_kartu_ui.log 2>&1 &
SRV=$!
trap 'kill $SRV 2>/dev/null || true' EXIT
for i in $(seq 1 25); do curl -s -o /dev/null "$BASE/login.php" && break; sleep 0.4; done

gagal=0
BASE_URL="$BASE" node "$DIR/check_cbt_kartu_ui.js" || gagal=1
grep -iE 'PHP (Warning|Notice|Fatal)|Uncaught' /tmp/cbt_kartu_ui.log | head -5 || true
exit "$gagal"
