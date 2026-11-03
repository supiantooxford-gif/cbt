#!/usr/bin/env bash
# =====================================================================================
# Uji TAMPILAN tombol Hapus pada kolom Aksi daftar sekolah (Playwright).
#
# Cara pakai:  bash check_cbt_hapus_sekolah_ui.sh
# Skrip ini membuat SALINAN aplikasi + basis data di /tmp — data aplikasi tidak disentuh.
# =====================================================================================
set -eu
DIR="$(cd "$(dirname "$0")" && pwd)"
KERJA=/tmp/cbt_hapus_ui
PORT="${CBT_HAPUS_PORT:-}"
if [ -z "$PORT" ]; then
  for p in $(seq 8901 8949); do
    if ! (exec 3<>"/dev/tcp/127.0.0.1/$p") 2>/dev/null; then PORT=$p; break; fi
    exec 3<&- 2>/dev/null || true
  done
fi
BASE="http://127.0.0.1:$PORT"

echo "== Menyiapkan salinan aplikasi di $KERJA =="
rm -rf "$KERJA"
mkdir -p "$KERJA"
cp -r "$DIR"/. "$KERJA"/
rm -f "$KERJA"/data/cbt.sqlite* "$KERJA"/data/.schema_ok
mkdir -p "$KERJA/data/sessions"
chmod 777 "$KERJA/data" "$KERJA/data/sessions" 2>/dev/null || true

CBT_DB_FILE="$KERJA/data/cbt.sqlite" php -d extension=pdo -d extension=pdo_sqlite "$KERJA/fixture_cbt.php"

# Sekolah tambahan pada SALINAN supaya daftar punya beberapa baris + satu sekolah nonaktif
# (agar tombol Hapus pada tabel "Sekolah Nonaktif" ikut teruji).
CBT_DB_FILE="$KERJA/data/cbt.sqlite" php -d extension=pdo -d extension=pdo_sqlite -r '
require "'"$KERJA"'/inc/init.php";
$now = date("Y-m-d H:i:s");
foreach ([["Sekolah Uji Hapus A", "SD/MI", 1], ["Sekolah Uji Hapus B", "SMP/MTs", 1],
          ["Sekolah Uji Nonaktif", "SMA/MA/SMK", 0]] as $s) {
    if (one("SELECT id FROM sekolah WHERE nama = ?", [$s[0]])) { continue; }
    q("INSERT INTO sekolah (nama, jenjang, verifikasi, aktif, aktivasi, aktif_sampai, dibuat)
       VALUES (?,?,?,?,?,?,?)", [$s[0], $s[1], "disetujui", $s[2], "uji_coba", date("Y-m-d H:i:s", strtotime("+20 days")), $now]);
    provision_sekolah((int) db()->lastInsertId());
}' 2>/dev/null || CBT_DB_FILE="$KERJA/data/cbt.sqlite" php -d extension=pdo -d extension=pdo_sqlite "$KERJA/fixture_cbt.php" >/dev/null

php -d extension=pdo -d extension=pdo_sqlite -S "127.0.0.1:$PORT" -t "$KERJA" > /tmp/cbt_hapus_ui_srv.log 2>&1 &
SRV=$!
trap 'kill $SRV 2>/dev/null || true' EXIT
for i in $(seq 1 25); do
  curl -s -o /dev/null "$BASE/login.php" && break
  sleep 0.4
done

gagal=0
BASE_URL="$BASE" node "$DIR/check_cbt_hapus_sekolah_ui.js" || gagal=1

echo
echo "== Ringkasan =="
if [ "$gagal" -eq 0 ]; then
  echo "  SEMUA PEMERIKSAAN TAMPILAN LULUS"
else
  echo "  ADA PEMERIKSAAN YANG GAGAL"
fi
grep -iE 'PHP (Warning|Notice|Fatal)|Uncaught' /tmp/cbt_hapus_ui_srv.log | head -5 || true
exit "$gagal"
