#!/usr/bin/env bash
# =====================================================================================
# Uji TAMPILAN halaman Impor Soal (template teks sederhana) dengan Playwright.
#
# Cara pakai:  bash check_cbt_impor_soal_ui.sh
# Skrip ini membuat SALINAN aplikasi + basis data di /tmp — data aplikasi tidak disentuh.
# =====================================================================================
set -eu
DIR="$(cd "$(dirname "$0")" && pwd)"
KERJA=/tmp/cbt_impor_ui
PORT="${CBT_IMPOR_PORT:-}"
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

# Berkas Word (.docx) berisi SATU soal format teks — dibuat minimal (hanya word/document.xml)
# karena aplikasi hanya membaca bagian itu.
DOCX=/tmp/cbt_impor_ui.docx
python3 - "$DOCX" <<'PY'
import sys, zipfile
def par(t):
    return '<w:p><w:r><w:t xml:space="preserve">' + t + '</w:t></w:r></w:p>'
isi = ['Template Soal Pilihan Ganda',
       '1. SOAL DARI BERKAS WORD: dua kali tiga adalah ...',
       'A. 5', 'B. 6', 'C. 7', 'Kunci: B']
xml = ('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
       '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
       + ''.join(par(t) for t in isi) + '</w:body></w:document>')
ct = ('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
      '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
      '<Default Extension="xml" ContentType="application/xml"/>'
      '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
      '</Types>')
rels = ('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
        '</Relationships>')
with zipfile.ZipFile(sys.argv[1], 'w', zipfile.ZIP_DEFLATED) as z:
    z.writestr('[Content_Types].xml', ct)
    z.writestr('_rels/.rels', rels)
    z.writestr('word/document.xml', xml)
print('berkas docx uji dibuat')
PY

php -d extension=pdo -d extension=pdo_sqlite -S "127.0.0.1:$PORT" -t "$KERJA" > /tmp/cbt_impor_ui_srv.log 2>&1 &
SRV=$!
trap 'kill $SRV 2>/dev/null || true' EXIT
for i in $(seq 1 25); do
  curl -s -o /dev/null "$BASE/login.php" && break
  sleep 0.4
done

gagal=0
BASE_URL="$BASE" DOCX_ISI="$DOCX" node "$DIR/check_cbt_impor_soal_ui.js" || gagal=1

echo
echo "== Ringkasan =="
if [ "$gagal" -eq 0 ]; then
  echo "  SEMUA PEMERIKSAAN TAMPILAN LULUS"
else
  echo "  ADA PEMERIKSAAN YANG GAGAL"
fi
grep -iE 'PHP (Warning|Notice|Fatal)|Uncaught' /tmp/cbt_impor_ui_srv.log | head -5 || true
exit "$gagal"
