#!/usr/bin/env bash
# =====================================================================================
# Uji TOMBOL "KUMPULKAN JAWABAN" (Playwright) memakai SALINAN aplikasi di /tmp.
#
# Menjalankan EMPAT skenario berurutan pada salinan yang sama; sesi ujian siswa direset
# di antara skenario (pada SALINAN saja) supaya ujian selalu bisa dikerjakan dari awal:
#   1. konfirmasi memakai layar di dalam halaman (bukan dialog peramban) + tombol batal;
#   2. klik "Ya, Kumpulkan Sekarang" sampai halaman hasil;
#   3. tombol "Kumpulkan Jawaban Sekarang" di bawah daftar soal;
#   4. jalur cadangan saat SEMUA fetch gagal (jaringan putus).
#
# Latar belakang: bug nyata "klik kumpulkan jawaban tidak merespon sama sekali" akibat
# pengumpulan bergantung pada window.confirm() yang dapat diblokir peramban.
# =====================================================================================
set -eu
DIR="$(cd "$(dirname "$0")" && pwd)"
KERJA=/tmp/cbt_kumpul_ui
PORT="${CBT_UI_PORT:-}"
if [ -z "$PORT" ]; then
  for p in $(seq 8801 8840); do
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
CBT_DB_FILE="$KERJA/data/cbt.sqlite" php -d extension=pdo -d extension=pdo_sqlite "$KERJA/fixture_cbt.php" >/dev/null

php -d extension=pdo -d extension=pdo_sqlite -S "127.0.0.1:$PORT" -t "$KERJA" > /tmp/cbt_kumpul_ui.log 2>&1 &
SRV=$!
trap 'kill $SRV 2>/dev/null || true' EXIT
for i in $(seq 1 25); do
  curl -s -o /dev/null "$BASE/login.php" && break
  sleep 0.4
done

reset_sesi(){ # hapus sesi ujian siswa pada SALINAN supaya ujian bisa dikerjakan dari awal
  CBT_DB_FILE="$KERJA/data/cbt.sqlite" php -d extension=pdo -d extension=pdo_sqlite -r '
require "'"$KERJA"'/inc/init.php";
q("DELETE FROM jawaban WHERE sesi_id IN (SELECT id FROM sesi_ujian WHERE siswa_id = 1)");
q("DELETE FROM sesi_ujian WHERE siswa_id = 1");'
}

gagal=0
for SK in konfirmasi tuntas tombol2 darurat; do
  reset_sesi
  echo "--- skenario: $SK"
  BASE_URL="$BASE" SKENARIO="$SK" node "$DIR/check_cbt_kumpulkan_ui.js" || gagal=1
done

grep -iE 'PHP (Warning|Notice|Fatal)|Uncaught' /tmp/cbt_kumpul_ui.log | head -5 || true
exit "$gagal"
