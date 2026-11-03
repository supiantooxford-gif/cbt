#!/usr/bin/env bash
# =====================================================================================
# Uji TEMPLATE SOAL BENTUK TEKS SEDERHANA (v1.13.0) — ujung ke ujung lewat HTTP.
#
# Permintaan pemilik: *"Template soal yang ada saat ini masih agak sulit. Buat template soal seperti
# yang saya upload agar mempermudah guru dalam mengisi template soal."*
#
# Yang diuji:
#   1. Pembaca template (tanpa server) — `php uji_impor_soal.php` (58 pemeriksaan).
#   2. Unduh template Word baru (tulisan biasa, bukan tabel) lalu UNGGAH kembali apa adanya
#      → 6 soal dari 6 jenis soal masuk dengan kunci yang benar.
#   3. Berkas Word yang soalnya diketik guru (tanpa nomor, kunci kata, essai) terbaca.
#   4. Tempel teks soal (tanpa berkas) juga jalan.
#   5. Berkas tanpa judul bagian template ditolak dengan pesan jelas (tidak menyimpan apa pun).
#   6. Kompatibilitas: template LAMA (tabel Word) & Excel/CSV masih bisa diimpor.
#   7. Halaman impor memakai penjelasan baru; tanpa CSRF 419; ujian milik guru lain 403.
#   8. Log server bersih dari PHP Warning/Fatal.
#
# Pemakaian:  bash tests_cbt_impor_soal_teks.sh [BASE_URL]
# Tanpa BASE_URL skrip menyiapkan SALINAN aplikasi + basis data uji di /tmp (data asli aman).
# =====================================================================================
set -u
BASE="${1:-http://127.0.0.1:8899}"
DIR="$(cd "$(dirname "$0")" && pwd)"
DIR="${CBT_DIR:-$DIR}"
LULUS=0; GAGAL=0
LOG=""

SRV_PID=""
if [ -z "${1:-}" ]; then
  PORT=""
  for _p in $(seq 8850 8899); do
    if ! (exec 3<>"/dev/tcp/127.0.0.1/$_p") 2>/dev/null; then PORT=$_p; break; fi
    exec 3<&- 2>/dev/null || true
  done
  [ -n "$PORT" ] || { echo "Tidak ada port uji yang bebas"; exit 1; }
  BASE="http://127.0.0.1:$PORT"
  KERJA="/tmp/cbt_tpl_$$"
  rm -rf "$KERJA"; mkdir -p "$KERJA"
  cp -r "$DIR"/. "$KERJA"/
  rm -f "$KERJA"/data/cbt.sqlite* "$KERJA"/data/.schema_ok
  mkdir -p "$KERJA/data/sessions"; chmod 777 "$KERJA/data" "$KERJA/data/sessions" 2>/dev/null || true
  CBT_DB_FILE="$KERJA/data/cbt.sqlite" php -d extension=pdo -d extension=pdo_sqlite "$KERJA/fixture_cbt.php" >/dev/null
  LOG="/tmp/cbt_tpl_srv_$$.log"
  php -d extension=pdo -d extension=pdo_sqlite -S "127.0.0.1:$PORT" -t "$KERJA" > "$LOG" 2>&1 &
  SRV_PID=$!
  for _i in $(seq 1 25); do
    curl -s -o /dev/null "http://127.0.0.1:$PORT/login.php" && break
    sleep 0.4
  done
  DB="$KERJA/data/cbt.sqlite"
  DIRUJI="$KERJA"
  export DB
  bersihkan(){ [ -n "$SRV_PID" ] && kill "$SRV_PID" 2>/dev/null; rm -rf "$KERJA"; }
  trap bersihkan EXIT
else
  DIRUJI="$DIR"
  DB="${CBT_DB_DEFAULT:-$DIR/data/cbt.sqlite}"
fi

ok(){ LULUS=$((LULUS+1)); printf '  ok   %s\n' "$1"; }
no(){ GAGAL=$((GAGAL+1)); printf '  GAGAL %s\n' "$1"; }
cek(){ if [ "$2" = "$3" ]; then ok "$1"; else no "$1 (dapat: $2, harap: $3)"; fi; }
cari(){ if printf '%s' "$1" | grep -q -- "$2"; then ok "$3"; else no "$3"; fi; }
tidakk(){ if printf '%s' "$1" | grep -q -- "$2"; then no "$3"; else ok "$3"; fi; }
sql(){ php -d extension=pdo -d extension=pdo_sqlite -r '
$p=new PDO("sqlite:'"$DB"'"); $q=$p->query("'"$1"'"); echo ($q?$q->fetchColumn():"0");'; }
csrf(){
  local hal; hal=$(curl -s -b "$1" -c "$1" "$BASE/$2")
  local t; t=$(printf '%s' "$hal" | grep -o 'name="csrf-token" content="[^"]*"' | head -1 | sed 's/.*content="//;s/"//')
  [ -n "$t" ] || t=$(printf '%s' "$hal" | grep -o 'name="csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
  printf '%s' "$t"
}
masuk(){
  rm -f "$1"
  local t; t=$(csrf "$1" login.php)
  curl -s -b "$1" -c "$1" -o /dev/null -d "csrf=$t" -d "username=$2" -d "password=$3" "$BASE/login.php"
}
# Jumlah soal pada ujian tertentu
jml(){ sql "SELECT COUNT(*) FROM soal WHERE ujian_id=$1"; }

echo "== 1. Pembaca template (tanpa server: php uji_impor_soal.php) =="
KELUARAN=$(php -d extension=pdo -d extension=pdo_sqlite "$DIRUJI/uji_impor_soal.php" 2>&1) || true
cari "$KELUARAN" "0 gagal" "seluruh pemeriksaan pembaca template lulus"
tidakk "$KELUARAN" "GAGAL" "tidak ada pemeriksaan pembaca template yang gagal"

echo "== 2. Halaman Impor Soal memakai penjelasan BARU =="
JG=/tmp/cbt_tpl_guru.jar
masuk "$JG" ahmad guru123
GURUID=$(sql "SELECT id FROM guru ORDER BY id LIMIT 1")
MAPELID=$(sql "SELECT id FROM mapel ORDER BY id LIMIT 1")
# Ujian baru khusus uji impor (tidak mengganggu ujian bawaan fixture)
sql "INSERT INTO ujian (sekolah_id, mapel_id, guru_id, judul, keterangan, jenis_ujian, durasi,
      acak_soal, acak_opsi, tampilkan_hasil, status, dibuat, diubah)
     VALUES (1, $MAPELID, $GURUID, 'Ujian Impor Teks Uji', 'uji', 'Ulangan Harian', 60, 0, 0, 1, 'draf',
             datetime('now'), datetime('now'))" >/dev/null
UJ=$(sql "SELECT id FROM ujian WHERE judul='Ujian Impor Teks Uji'")
cek "ujian uji tersedia" "$([ -n "$UJ" ] && echo ada)" "ada"

HAL=$(curl -s -b "$JG" -c "$JG" "$BASE/ujian_import.php?ujian=$UJ")
cari "$HAL" "Tempel Teks Soal" "halaman menawarkan cara tempel teks"
cari "$HAL" "Format Template (Mudah)" "halaman menjelaskan format template yang mudah"
cari "$HAL" "Unduh Template Word (.docx)" "tombol unduh template Word tersedia"
cari "$HAL" "Kunci: A, B, E" "contoh format kunci kompleks tampil"
cari "$HAL" "contoh-soal" "pratinjau contoh template tersedia"
tidakk "$HAL" "Isi tabelnya" "penjelasan lama (mengisi tabel) sudah tidak dipakai"
tidakk "$HAL" "Aturan Penulisan Kunci" "kartu aturan kunci LAMA diganti penjelasan format baru"

echo "== 3. Unduh template Word BARU (tulisan biasa) =="
KODE=$(curl -s -b "$JG" -o /tmp/cbt_tpl_baru.docx -w '%{http_code}' "$BASE/template_soal_word.php?ujian=$UJ")
cek "unduh template dijawab 200" "$KODE" "200"
cek "berkas berupa ZIP (.docx)" "$(head -c 2 /tmp/cbt_tpl_baru.docx)" "PK"
# Template baru TIDAK memakai tabel; isinya paragraf biasa yang memuat judul bagian.
ISI_TPL=$(python3 - <<'PY'
import zipfile, re
z = zipfile.ZipFile('/tmp/cbt_tpl_baru.docx')
xml = z.read('word/document.xml').decode('utf-8')
baris = re.findall(r'<w:t[^>]*>(.*?)</w:t>', xml, re.S)
teks = ''.join(baris)
print(('ADA-TABEL' if '<w:tbl>' in xml else 'TANPA-TABEL') + '|' + teks[:0])
import sys
# cetak penanda judul bagian yang ditemukan
for judul in ['Template Soal Pilihan Ganda', 'Template Soal Pilihan Ganda Kompleks',
              'Template Soal Benar Salah', 'Template Soal Menjodohkan',
              'Template Soal Essai Singkat', 'Template Soal Essai Panjang']:
    if judul.replace(' ', '') in teks.replace(' ', ''):
        sys.stdout.write('|' + judul)
PY
)
cari "$ISI_TPL" "TANPA-TABEL" "template baru tidak memakai tabel (mudah diisi)"
cari "$ISI_TPL" "Template Soal Pilihan Ganda" "template memuat judul bagian pilihan ganda"
cari "$ISI_TPL" "Template Soal Essai Panjang" "template memuat judul bagian essai panjang"

echo "== 4. Unggah template apa adanya -> 6 soal dari 6 jenis soal =="
SEBELUM=$(jml "$UJ")
T=$(csrf "$JG" "ujian_import.php?ujian=$UJ")
HASIL=$(curl -s -b "$JG" -c "$JG" -F "csrf=$T" -F "ujian=$UJ" -F "berkas_docx=@/tmp/cbt_tpl_baru.docx" \
  "$BASE/ujian_import.php?ujian=$UJ")
cari "$HASIL" "6 soal" "hasil impor melaporkan 6 soal"
cari "$HASIL" "template teks" "hasil impor menyebut sumber template teks"
cek "jumlah soal bertambah 6" "$(jml "$UJ")" "$((SEBELUM + 6))"
cek "urutan jenis soal hasil impor" \
  "$(sql "SELECT GROUP_CONCAT(jenis, ',') FROM (SELECT jenis FROM soal WHERE ujian_id=$UJ ORDER BY urutan, id)")" \
  "pg,pgk,bs,jodoh,uraian,uraian"
cek "kunci pilihan ganda tersimpan (indeks 2 = C)" \
  "$(sql "SELECT jawaban FROM soal WHERE ujian_id=$UJ AND jenis='pg' ORDER BY urutan LIMIT 1")" "2"
cek "kunci pilihan ganda kompleks tersimpan (A,B,E)" \
  "$(sql "SELECT jawaban FROM soal WHERE ujian_id=$UJ AND jenis='pgk' ORDER BY urutan LIMIT 1")" "[0,1,4]"
cek "kunci benar/salah tersimpan (A = Benar)" \
  "$(sql "SELECT jawaban FROM soal WHERE ujian_id=$UJ AND jenis='bs' ORDER BY urutan LIMIT 1")" '["B"]'
cek "jumlah pernyataan benar/salah" \
  "$(sql "SELECT json_array_length(opsi) FROM soal WHERE ujian_id=$UJ AND jenis='bs' ORDER BY urutan LIMIT 1")" "1"
cari "$(sql "SELECT opsi FROM soal WHERE ujian_id=$UJ AND jenis='bs' ORDER BY urutan LIMIT 1")" \
  "bahasa gaul" "pernyataan benar/salah diambil dari baris bernomor"
cek "jumlah pasangan menjodohkan" \
  "$(sql "SELECT json_array_length(opsi) FROM soal WHERE ujian_id=$UJ AND jenis='jodoh' ORDER BY urutan LIMIT 1")" "4"
cari "$(sql "SELECT jawaban FROM soal WHERE ujian_id=$UJ AND jenis='jodoh' ORDER BY urutan LIMIT 1")" \
  "Halo Sahabatku" "pasangan kanan tersimpan tanpa penomoran"
cek "essai singkat jadi uraian" \
  "$(sql "SELECT jenis FROM soal WHERE ujian_id=$UJ AND pertanyaan LIKE 'Mengapa penggunaan kata ganti%'")" "uraian"
cari "$(sql "SELECT jawaban FROM soal WHERE ujian_id=$UJ AND pertanyaan LIKE 'Mengapa penggunaan kata ganti%'")" \
  "keakraban" "kata kunci essai diambil otomatis"
cari "$(sql "SELECT pembahasan FROM soal WHERE ujian_id=$UJ AND pertanyaan LIKE 'Mengapa penggunaan kata ganti%'")" \
  "Kunci (jawaban contoh)" "jawaban contoh essai disimpan sebagai pembahasan"
cari "$(sql "SELECT pertanyaan FROM soal WHERE ujian_id=$UJ AND jenis='uraian' ORDER BY urutan DESC LIMIT 1")" \
  "minimal 3 paragraf" "pertanyaan essai panjang tersimpan utuh"
cek "skor bawaan soal = 1" \
  "$(sql "SELECT CAST(MAX(skor) AS INTEGER) FROM soal WHERE ujian_id=$UJ")" "1"

echo "== 5. Soal hasil impor tampil di halaman ujian =="
DETAIL=$(curl -s -b "$JG" -c "$JG" "$BASE/ujian_edit.php?id=$UJ")
cari "$DETAIL" "surat pribadi" "daftar soal menampilkan pertanyaan hasil impor"
cari "$DETAIL" "Menjodohkan" "jenis menjodohkan ikut tampil"
cari "$DETAIL" "6 soal" "ringkasan jumlah soal benar"

echo "== 6. Berkas Word yang diketik guru sendiri (tanpa nomor, kunci kata) =="
python3 - "$UJ" <<'PY'
import zipfile, sys
def par(t, tebal=False, tengah=False):
    pr = ''
    if tengah:
        pr += '<w:jc w:val="center"/>'
    rpr = '<w:rPr>' + ('<w:b/>' if tebal else '') + '</w:rPr>'
    return ('<w:p><w:pPr>' + pr + '</w:pPr><w:r>' + rpr
            + '<w:t xml:space="preserve">' + t + '</w:t></w:r></w:p>')
isi = [
    ('TEMPLATE SOAL', True, True),
    ('Petunjuk: awali soal dengan nomor. Bagian ini tidak diimpor.', False, False),
    ('Template Soal Pilihan Ganda', True, False),
    ('Ibu kota Provinsi Gorontalo adalah ...', False, False),
    ('A. Limboto', False, False),
    ('B. Gorontalo', False, False),
    ('C. Kwandang', False, False),
    ('Kunci: B', False, False),
    ('Template Soal Isian Singkat', True, False),
    ('1. Hasil dari 12 x 8 adalah ...', False, False),
    ('Kunci: 96|sembilan puluh enam', False, False),
    ('Template Soal Essai Panjang', True, False),
    ('1. Jelaskan proses fotosintesis pada tumbuhan!', False, False),
    ('Skor: 4', False, False),
    ('Kunci: fotosintesis|klorofil|cahaya matahari', False, False),
]
body = ''.join(par(t, tb, tg) for t, tb, tg in isi)
xml = ('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
       '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
       + body + '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/></w:sectPr></w:body></w:document>')
sumber = '/tmp/cbt_tpl_baru.docx'
z = zipfile.ZipFile(sumber)
data = {n: z.read(n) for n in z.namelist()}
data['word/document.xml'] = xml.encode('utf-8')
with zipfile.ZipFile('/tmp/cbt_tpl_ketik.docx', 'w', zipfile.ZIP_DEFLATED) as out:
    for n, b in data.items():
        out.writestr(n, b)
print('berkas Word ketikan guru dibuat')
PY
SEBELUM=$(jml "$UJ")
T=$(csrf "$JG" "ujian_import.php?ujian=$UJ")
HASIL=$(curl -s -b "$JG" -c "$JG" -F "csrf=$T" -F "ujian=$UJ" -F "berkas_docx=@/tmp/cbt_tpl_ketik.docx" \
  "$BASE/ujian_import.php?ujian=$UJ")
cari "$HASIL" "3 soal" "tiga soal dari berkas ketikan guru masuk"
cek "jumlah soal bertambah 3" "$(jml "$UJ")" "$((SEBELUM + 3))"
cek "soal tanpa nomor tetap terbaca (pilihan ganda)" \
  "$(sql "SELECT jawaban FROM soal WHERE ujian_id=$UJ AND pertanyaan LIKE 'Ibu kota Provinsi Gorontalo%'")" "1"
cek "bagian isian singkat dikenali" \
  "$(sql "SELECT jenis FROM soal WHERE ujian_id=$UJ AND pertanyaan LIKE 'Hasil dari 12 x 8%'")" "isian"
cek "dua alternatif jawaban isian tersimpan" \
  "$(sql "SELECT json_array_length(jawaban) FROM soal WHERE ujian_id=$UJ AND pertanyaan LIKE 'Hasil dari 12 x 8%'")" "2"
cek "skor dari baris 'Skor: 4' dipakai" \
  "$(sql "SELECT CAST(skor AS INTEGER) FROM soal WHERE ujian_id=$UJ AND pertanyaan LIKE 'Jelaskan proses fotosintesis%'")" "4"
tidakk "$(sql "SELECT GROUP_CONCAT(pertanyaan, ' ') FROM soal WHERE ujian_id=$UJ")" "Petunjuk:" \
  "petunjuk pengisian tidak ikut menjadi soal"

echo "== 7. Tempel teks soal (tanpa berkas) =="
SEBELUM=$(jml "$UJ")
T=$(csrf "$JG" "ujian_import.php?ujian=$UJ")
TEKS='Template Soal Pilihan Ganda
1. Dua tambah tiga adalah ...
A. 4
B. 5
C. 6
Kunci: B
Template Soal Benar Salah
1. Bumi berbentuk bulat.
Kunci: Benar'
HASIL=$(curl -s -b "$JG" -c "$JG" --data-urlencode "csrf=$T" -d "ujian=$UJ" \
  --data-urlencode "teks_soal=$TEKS" "$BASE/ujian_import.php?ujian=$UJ")
cari "$HASIL" "2 soal" "dua soal dari teks yang ditempel masuk"
cek "jumlah soal bertambah 2" "$(jml "$UJ")" "$((SEBELUM + 2))"
cek "kunci soal tempelan tersimpan" \
  "$(sql "SELECT jawaban FROM soal WHERE ujian_id=$UJ AND pertanyaan LIKE 'Dua tambah tiga%'")" "1"
# Pernyataan soal Benar/Salah disimpan pada kolom `opsi` (pertanyaannya kalimat pembuka).
cek "benar/salah dari tempelan tersimpan" \
  "$(sql "SELECT jawaban FROM soal WHERE ujian_id=$UJ AND opsi LIKE '%Bumi berbentuk bulat%'")" '["B"]'
cari "$(sql "SELECT opsi FROM soal WHERE ujian_id=$UJ AND opsi LIKE '%Bumi berbentuk bulat%'")" \
  "Bumi berbentuk bulat" "pernyataan benar/salah dari tempelan tersimpan"

echo "== 8. Berkas tanpa judul bagian template ditolak (tidak menyimpan apa pun) =="
python3 - <<'PY'
import zipfile
def par(t):
    return '<w:p><w:r><w:t xml:space="preserve">' + t + '</w:t></w:r></w:p>'
xml = ('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
       '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
       + par('Catatan rapat guru') + par('Tidak ada soal di sini.') + '</w:body></w:document>')
z = zipfile.ZipFile('/tmp/cbt_tpl_baru.docx')
data = {n: z.read(n) for n in z.namelist()}
data['word/document.xml'] = xml.encode('utf-8')
with zipfile.ZipFile('/tmp/cbt_tpl_kosong.docx', 'w', zipfile.ZIP_DEFLATED) as out:
    for n, b in data.items():
        out.writestr(n, b)
PY
SEBELUM=$(jml "$UJ")
T=$(csrf "$JG" "ujian_import.php?ujian=$UJ")
HASIL=$(curl -s -b "$JG" -c "$JG" -F "csrf=$T" -F "ujian=$UJ" -F "berkas_docx=@/tmp/cbt_tpl_kosong.docx" \
  "$BASE/ujian_import.php?ujian=$UJ")
cari "$HASIL" "Tidak ada soal yang dapat dibaca" "berkas tanpa soal dijawab pesan jelas"
cari "$HASIL" "Template Soal Pilihan Ganda" "pesan menyebut contoh judul bagian yang benar"
cek "tidak ada soal tersimpan dari berkas kosong" "$(jml "$UJ")" "$SEBELUM"

echo "== 9. Kompatibilitas: template LAMA (tabel Word) masih bisa diimpor =="
python3 - <<'PY'
import zipfile
def sel(t):
    return '<w:tc><w:p><w:r><w:t xml:space="preserve">' + t + '</w:t></w:r></w:p></w:tc>'
def baris(sel_list):
    return '<w:tr>' + ''.join(sel(s) for s in sel_list) + '</w:tr>'
kepala = ['Jenis', 'Pertanyaan', 'Opsi A', 'Opsi B', 'Opsi C', 'Opsi D', 'Opsi E', 'Jawaban', 'Skor', 'Pembahasan']
isi = ['Menjodohkan', 'TABEL-LAMA: jodohkan!', 'Kiri satu', 'Kiri dua', '', '', '',
       'Kanan satu|Kanan dua', '2', '']
tbl = ('<w:tbl><w:tblPr><w:tblW w:w="0" w:type="auto"/></w:tblPr>' + baris(kepala) + baris(isi) + '</w:tbl>')
xml = ('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
       '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
       + '<w:p><w:r><w:t>Template tabel lama</w:t></w:r></w:p>' + tbl + '</w:body></w:document>')
z = zipfile.ZipFile('/tmp/cbt_tpl_baru.docx')
data = {n: z.read(n) for n in z.namelist()}
data['word/document.xml'] = xml.encode('utf-8')
with zipfile.ZipFile('/tmp/cbt_tpl_lama.docx', 'w', zipfile.ZIP_DEFLATED) as out:
    for n, b in data.items():
        out.writestr(n, b)
PY
SEBELUM=$(jml "$UJ")
T=$(csrf "$JG" "ujian_import.php?ujian=$UJ")
HASIL=$(curl -s -b "$JG" -c "$JG" -F "csrf=$T" -F "ujian=$UJ" -F "berkas_docx=@/tmp/cbt_tpl_lama.docx" \
  "$BASE/ujian_import.php?ujian=$UJ")
cari "$HASIL" "1 soal" "satu soal dari template tabel lama masuk"
cek "jenis soal tabel lama" "$(sql "SELECT jenis FROM soal WHERE ujian_id=$UJ AND pertanyaan LIKE 'TABEL-LAMA%'")" "jodoh"
cek "kunci soal tabel lama" "$(sql "SELECT jawaban FROM soal WHERE ujian_id=$UJ AND pertanyaan LIKE 'TABEL-LAMA%'")" \
  '["Kanan satu","Kanan dua"]'
cek "jumlah soal bertambah 1" "$(jml "$UJ")" "$((SEBELUM + 1))"

echo "== 10. Kompatibilitas: impor Excel/CSV (payload JSON) tetap jalan =="
SEBELUM=$(jml "$UJ")
T=$(csrf "$JG" "ujian_import.php?ujian=$UJ")
PAYLOAD='{"judul":["Jenis","Pertanyaan","Opsi A","Opsi B","Opsi C","Opsi D","Opsi E","Jawaban","Skor","Pembahasan"],"baris":[["Pilihan Ganda","EXCEL-LAMA: satu tambah satu?","1","2","","","","B","1",""]]}'
HASIL=$(curl -s -b "$JG" -c "$JG" --data-urlencode "csrf=$T" -d "ujian=$UJ" \
  --data-urlencode "payload=$PAYLOAD" "$BASE/ujian_import.php?ujian=$UJ")
cari "$HASIL" "1 soal" "satu soal dari Excel/CSV masuk"
cek "jumlah soal bertambah 1" "$(jml "$UJ")" "$((SEBELUM + 1))"
cek "kunci soal Excel tersimpan" \
  "$(sql "SELECT jawaban FROM soal WHERE ujian_id=$UJ AND pertanyaan LIKE 'EXCEL-LAMA%'")" "1"

echo "== 11. Keamanan & hak akses =="
KODE=$(curl -s -b "$JG" -c "$JG" -o /dev/null -w '%{http_code}' -F "ujian=$UJ" -F "teks_soal=Template Soal Pilihan Ganda" \
  "$BASE/ujian_import.php?ujian=$UJ")
cek "tanpa CSRF ditolak 419" "$KODE" "419"
JG2=/tmp/cbt_tpl_guru2.jar
masuk "$JG2" siti guru123
KODE=$(curl -s -b "$JG2" -c "$JG2" -o /dev/null -w '%{http_code}' "$BASE/ujian_import.php?ujian=$UJ")
cek "guru lain tidak boleh mengimpor ke ujian ini (403)" "$KODE" "403"
KODE=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/ujian_import.php?ujian=$UJ")
cek "belum masuk dialihkan (302)" "$KODE" "302"

echo "== 12. Jalur unduh template =="
KODE=$(curl -s -b "$JG" -c "$JG" -o /tmp/cbt_tpl2.docx -w '%{http_code}' "$BASE/template_soal_word.php")
cek "unduh template tanpa parameter ujian juga 200" "$KODE" "200"
cek "berkasnya tetap .docx sah" "$(head -c 2 /tmp/cbt_tpl2.docx)" "PK"
KODE=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/template_soal_word.php")
cek "belum masuk tidak boleh mengunduh template (302)" "$KODE" "302"

echo "== 13. Kerapian halaman =="
HAL=$(curl -s -b "$JG" -c "$JG" "$BASE/ujian_import.php?ujian=$UJ")
tidakk "$HAL" 'href="/' "tidak ada tautan absolut (dipasang di subpath)"
cari "$HAL" "assets/style.css?v=" "aset CSS memakai pengusir cache"
cari "$HAL" "name=\"teks_soal\"" "kolom tempel teks ada pada formulir"
cari "$HAL" "name=\"csrf\"" "formulir memuat token CSRF"
cari "$HAL" "contoh-soal" "pratinjau contoh tetap tampil setelah impor"

echo "== 14. Integritas & log server =="
cek "tidak ada soal tanpa sekolah" "$(sql "SELECT COUNT(*) FROM soal WHERE sekolah_id IS NULL OR sekolah_id = 0")" "0"
cek "integritas basis data" "$(sql 'PRAGMA integrity_check')" "ok"
cek "pelanggaran foreign key" "$(sql 'SELECT COUNT(*) FROM pragma_foreign_key_check')" "0"
if [ -n "$LOG" ] && [ -f "$LOG" ]; then
  if grep -qiE 'PHP (Warning|Notice|Fatal error)|Uncaught' "$LOG"; then
    no "log server bersih dari galat PHP"
    grep -iE 'PHP (Warning|Notice|Fatal error)|Uncaught' "$LOG" | head -3
  else
    ok "log server bersih dari galat PHP"
  fi
fi

echo
echo "===== HASIL: $LULUS lulus, $GAGAL gagal ====="
[ "$GAGAL" -eq 0 ] || exit 1
