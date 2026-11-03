#!/usr/bin/env bash
# =====================================================================================
# Uji TAMPILAN fitur "Jenis Penilaian (Jenis Ulangan)" pada akun Super Admin (Playwright).
#
# Permintaan pemilik: "Pada akun Super Admin tambahkan fitur untuk menghapus atau menambahkan
# jenis ulangan yang tampil pada halaman buat ujian guru".
#
# Cara pakai:  bash check_cbt_jenis_ujian_ui.sh
# Skrip ini membuat SALINAN aplikasi + basis data di /tmp — data aplikasi tidak disentuh.
# =====================================================================================
set -eu
DIR="$(cd "$(dirname "$0")" && pwd)"
KERJA=/tmp/cbt_jenis_ui
PORT="${CBT_JENIS_PORT:-}"
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

CBT_DB_FILE="$KERJA/data/cbt.sqlite" php -d extension=pdo -d extension=pdo_sqlite "$KERJA/fixture_cbt.php" >/dev/null

php -d extension=pdo -d extension=pdo_sqlite -S "127.0.0.1:$PORT" -t "$KERJA" > /tmp/cbt_jenis_ui_srv.log 2>&1 &
SRV=$!
trap 'kill $SRV 2>/dev/null || true' EXIT
for i in $(seq 1 25); do
  curl -s -o /dev/null "$BASE/login.php" && break
  sleep 0.4
done

gagal=0
BASE_URL="$BASE" node "$DIR/check_cbt_jenis_ujian_ui.js" || gagal=1

echo
echo "== Ringkasan =="
if [ "$gagal" -eq 0 ]; then
  echo "  SEMUA PEMERIKSAAN TAMPILAN LULUS"
else
  echo "  ADA PEMERIKSAAN YANG GAGAL"
fi
grep -iE 'PHP (Warning|Notice|Fatal)|Uncaught' /tmp/cbt_jenis_ui_srv.log | head -5 || true
exit "$gagal"
