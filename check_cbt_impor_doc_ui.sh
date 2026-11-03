#!/usr/bin/env bash
# =====================================================================================
# Uji TAMPILAN impor soal dari berkas WORD LAMA (.doc) — Playwright.
#
# Permintaan pemilik: "Pastikan aplikasi bisa mengimpor dokumen word tipe .doc — saat ini
# aplikasi hanya bisa import tipe .docx".
#
# Cara pakai:  bash check_cbt_impor_doc_ui.sh
# Skrip ini membuat SALINAN aplikasi + basis data di /tmp — data aplikasi tidak disentuh.
# =====================================================================================
set -eu
DIR="$(cd "$(dirname "$0")" && pwd)"
KERJA=/tmp/cbt_impor_doc_ui
PORT="${CBT_IMPDOC_PORT:-}"
if [ -z "$PORT" ]; then
  for p in $(seq 8951 8999); do
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

# Berkas .doc uji (dibuat oleh fixture_doc.php dari folder aplikasi).
FIX=/tmp/cbt_impor_doc_fix
rm -rf "$FIX"; mkdir -p "$FIX"
php -d extension=pdo -d extension=pdo_sqlite "$KERJA/fixture_doc.php" "$FIX" >/dev/null
printf 'Bukan dokumen Word.\n' > "$FIX/bukanword.doc"

php -d extension=pdo -d extension=pdo_sqlite -S "127.0.0.1:$PORT" -t "$KERJA" > /tmp/cbt_impor_doc_ui_srv.log 2>&1 &
SRV=$!
trap 'kill $SRV 2>/dev/null || true; rm -rf "$FIX"' EXIT
for i in $(seq 1 25); do
  curl -s -o /dev/null "$BASE/login.php" && break
  sleep 0.4
done

gagal=0
BASE_URL="$BASE" DOC_ISI="$FIX/teks_mini.doc" DOC_TABEL="$FIX/tabel.doc" \
  DOC_RUSAK="$FIX/bukanword.doc" node "$DIR/check_cbt_impor_doc_ui.js" || gagal=1

echo
echo "== Ringkasan =="
if [ "$gagal" -eq 0 ]; then
  echo "  SEMUA PEMERIKSAAN TAMPILAN LULUS"
else
  echo "  ADA PEMERIKSAAN YANG GAGAL"
fi
grep -iE 'PHP (Warning|Notice|Fatal)|Uncaught' /tmp/cbt_impor_doc_ui_srv.log | head -5 || true
exit "$gagal"
