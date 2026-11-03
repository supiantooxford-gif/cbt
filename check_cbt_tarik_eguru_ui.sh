#!/usr/bin/env bash
# =====================================================================================
# Uji tampilan menu "Tarik Data dari e-GURU" dengan Playwright.
# Skrip ini membuat SALINAN aplikasi + basis data CBT + basis data e-GURU kecil di /tmp —
# data aplikasi (dan data e-GURU yang sebenarnya) TIDAK disentuh.
#
# Cara pakai:  bash check_cbt_tarik_eguru_ui.sh
# =====================================================================================
set -eu
DIR="$(cd "$(dirname "$0")" && pwd)"
KERJA=/tmp/cbt_tarik_ui
EGDIR=/tmp/cbt_tarik_ui_eguru
PORT="${CBT_UI_PORT:-}"
if [ -z "$PORT" ]; then
  for p in $(seq 8901 8949); do
    if ! (exec 3<>"/dev/tcp/127.0.0.1/$p") 2>/dev/null; then PORT=$p; break; fi
    exec 3<&- 2>/dev/null || true
  done
fi
BASE="http://127.0.0.1:$PORT"

echo "== Menyiapkan salinan aplikasi di $KERJA =="
rm -rf "$KERJA" "$EGDIR"
mkdir -p "$KERJA" "$EGDIR"
cp -r "$DIR"/. "$KERJA"/
rm -f "$KERJA"/data/cbt.sqlite* "$KERJA"/data/.schema_ok
mkdir -p "$KERJA/data/sessions"
chmod 777 "$KERJA/data" "$KERJA/data/sessions" 2>/dev/null || true

EGDB="$EGDIR/eguru_uji.sqlite"
EGURU_DB_FILE="$EGDB" php -d extension=pdo -d extension=pdo_sqlite "$KERJA/fixture_eguru_uji.php" >/dev/null
CBT_DB_FILE="$KERJA/data/cbt.sqlite" php -d extension=pdo -d extension=pdo_sqlite "$KERJA/fixture_cbt.php" >/dev/null

# Sekolah kedua yang NAMANYA TIDAK ADA di e-GURU -> menguji penanda "Tidak ditemukan".
CBT_DB_FILE="$KERJA/data/cbt.sqlite" php -d extension=pdo -d extension=pdo_sqlite <<'PHP'
<?php
$p = new PDO('sqlite:' . getenv('CBT_DB_FILE'));
$p->exec("INSERT INTO sekolah (nama, jenjang, verifikasi, aktif, tahun_ajaran, semester, kkm, aktivasi, dibuat)
          VALUES ('SMP Bukan e-GURU', 'SMP/MTs', 'disetujui', 1, '2025/2026', 'Ganjil', 75, 'permanen', '2026-01-01 00:00:00')");
PHP

EGURU_DB_FILE="$EGDB" php -d extension=pdo -d extension=pdo_sqlite -S "127.0.0.1:$PORT" -t "$KERJA" > /tmp/cbt_tarik_ui_srv.log 2>&1 &
SRV=$!
trap 'kill $SRV 2>/dev/null || true' EXIT
for i in $(seq 1 25); do
  curl -s -o /dev/null "$BASE/login.php" && break
  sleep 0.4
done

gagal=0
# Keluaran ditampung ke berkas lebih dahulu: bila peramban gagal ditutup, pipa keluarannya
# masih terbuka sehingga skrip bash tampak "berhenti" tanpa pernah mencetak ringkasan.
LOG=/tmp/cbt_tarik_ui_out.txt
BASE_URL="$BASE" node "$DIR/check_cbt_tarik_eguru_ui.js" > "$LOG" 2>&1 || gagal=1
cat "$LOG" || true

echo
echo "== Ringkasan =="
if [ "$gagal" -eq 0 ]; then
  echo "  SEMUA PEMERIKSAAN TAMPILAN LULUS"
else
  echo "  ADA PEMERIKSAAN YANG GAGAL"
fi
grep -iE 'PHP (Warning|Notice|Fatal)|Uncaught' /tmp/cbt_tarik_ui_srv.log | head -5 || true
exit "$gagal"
