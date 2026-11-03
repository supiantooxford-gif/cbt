#!/usr/bin/env bash
# =====================================================================================
# Uji IMPOR SOAL dari berkas WORD LAMA (.doc) — permintaan pemilik:
#   "Pastikan aplikasi bisa mengimpor dokumen word tipe .doc — saat ini aplikasi hanya bisa
#    import tipe .docx"
#
# Yang diuji:
#   1. Modul `inc/doc.php` membaca wadah OLE2 (stream MINI maupun FAT biasa), piece table cp1252
#      & UTF-16LE, serta jalur cadangan tanpa piece table.
#   2. Template soal BENTUK TEKS di dalam .doc benar-benar terimpor menjadi 6 soal (1 pg, 1 pgk,
#      1 benar/salah, 1 menjodohkan, 2 uraian) dengan kunci & skor yang benar.
#   3. Tiga varian penulisan berkas Word lama (mini, besar, UTF-16, tanpa piece table) sama-sama
#      menghasilkan soal yang sama.
#   4. Template LAMA berbentuk TABEL di dalam .doc ikut terbaca (sel kosong tidak memecah baris).
#   5. Berkas .docx yang hanya DIGANTI NAMANYA menjadi .doc tetap terbaca (jenis dikenali dari isi).
#   6. Berkas bukan Word / RTF / ekstensi .txt DITOLAK dengan pesan jelas dan TIDAK menyimpan soal.
#   7. Tanda baca non-ASCII ( “ ” – ) terbaca utuh (bukan mojibake).
#   8. Isolasi: impor .doc hanya menambah soal pada ujian yang dituju.
#   9. HTML halaman impor menyebut .doc (label, accept, keterangan) + kerapian & integritas.
#
# Pemakaian:  bash tests_cbt_impor_doc.sh [BASE_URL]
# Tanpa BASE_URL skrip menyiapkan SALINAN aplikasi + basis data uji di /tmp dan menjalankan
# server PHP sendiri (basis data aplikasi TIDAK pernah disentuh).
# =====================================================================================
set -u
BASE="${1:-http://127.0.0.1:8899}"
DIR="$(cd "$(dirname "$0")" && pwd)"
DIR="${CBT_DIR:-$DIR}"
LULUS=0; GAGAL=0

# Berkas uji .doc dibuat dari folder aplikasi sumber (tidak memerlukan basis data).
FIX="/tmp/cbt_docimp_fix_$$"
rm -rf "$FIX"; mkdir -p "$FIX"
php -d extension=pdo -d extension=pdo_sqlite "$DIR/fixture_doc.php" "$FIX" >/dev/null
printf 'Bukan dokumen Word sama sekali.\n' > "$FIX/bukanword.doc"
printf '{\\rtf1\\ansi Ini RTF, bukan doc.}\n' > "$FIX/rtf.doc"
printf 'Hanya teks biasa, bukan Word.\n' > "$FIX/soal.txt"

SRV_PID=""
if [ -z "${1:-}" ]; then
  PORT=""
  for _p in $(seq 8850 8899); do
    if ! (exec 3<>"/dev/tcp/127.0.0.1/$_p") 2>/dev/null; then PORT=$_p; break; fi
    exec 3<&- 2>/dev/null || true
  done
  [ -n "$PORT" ] || { echo "Tidak ada port uji yang bebas"; exit 1; }
  BASE="http://127.0.0.1:$PORT"
  KERJA="/tmp/cbt_docimp_$$"
  rm -rf "$KERJA"; mkdir -p "$KERJA"
  cp -r "$DIR"/. "$KERJA"/
  rm -f "$KERJA"/data/cbt.sqlite* "$KERJA"/data/.schema_ok
  mkdir -p "$KERJA/data/sessions"; chmod 777 "$KERJA/data" "$KERJA/data/sessions" 2>/dev/null || true
  CBT_DB_FILE="$KERJA/data/cbt.sqlite" php -d extension=pdo -d extension=pdo_sqlite "$KERJA/fixture_cbt.php" >/dev/null
  php -d extension=pdo -d extension=pdo_sqlite -S "127.0.0.1:$PORT" -t "$KERJA" > "/tmp/cbt_docimp_srv_$$.log" 2>&1 &
  SRV_PID=$!
  for _i in $(seq 1 25); do
    curl -s -o /dev/null "http://127.0.0.1:$PORT/login.php" && break
    sleep 0.4
  done
  DB="$KERJA/data/cbt.sqlite"
  export DB
  bersihkan(){ [ -n "$SRV_PID" ] && kill "$SRV_PID" 2>/dev/null; rm -rf "$KERJA" "$FIX"; }
  trap bersihkan EXIT
fi
trap 'rm -rf "$FIX"' EXIT

ok(){ LULUS=$((LULUS+1)); printf '  ok   %s\n' "$1"; }
no(){ GAGAL=$((GAGAL+1)); printf '  GAGAL %s\n' "$1"; }
cek(){ if [ "$2" = "$3" ]; then ok "$1"; else no "$1 (dapat: $2, harap: $3)"; fi; }
cari2(){ if printf '%s' "$1" | grep -q -- "$2"; then ok "$3"; else no "$3"; fi; }
tidakk(){ if printf '%s' "$1" | grep -q -- "$2"; then no "$3"; else ok "$3"; fi; }
sql(){ php -d extension=pdo -d extension=pdo_sqlite -r '
$p=new PDO("sqlite:'"$DB"'"); $q=$p->query("'"$1"'"); echo ($q?$q->fetchColumn():"0");'; }

# ujian_baru <guru_id> <sekolah_id> <judul> -> id ujian baru (tanpa soal)
ujian_baru(){
  php -d extension=pdo -d extension=pdo_sqlite -r '
$p = new PDO("sqlite:'"$DB"'");
$st = $p->prepare("INSERT INTO ujian (sekolah_id, guru_id, judul, jenis_ujian, durasi, status, dibuat, diubah)
                   VALUES (?,?,?,?,?,?,?,?)");
$st->execute([(int) $argv[2], (int) $argv[1], (string) $argv[3], "Ulangan Harian", 60, "draft",
    date("Y-m-d H:i:s"), date("Y-m-d H:i:s")]);
echo (int) $p->lastInsertId();' "$1" "$2" "$3"
}

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
# unggah <jar> <ujian> <berkas> -> cetak kode HTTP; halaman hasil di /tmp/cbt_docimp_out.html
unggah(){
  local t; t=$(csrf "$1" "ujian_import.php?ujian=$2")
  curl -s -b "$1" -c "$1" -o /tmp/cbt_docimp_out.html -w '%{http_code}' \
    -F "csrf=$t" -F "ujian=$2" -F "berkas_docx=@$3" "$BASE/ujian_import.php?ujian=$2"
}
jumlah_soal(){ sql "SELECT COUNT(*) FROM soal WHERE ujian_id=$1"; }

SID=$(sql "SELECT id FROM sekolah ORDER BY id LIMIT 1")
GID=$(sql "SELECT g.id FROM guru g JOIN users u ON u.guru_id=g.id WHERE u.username='ahmad' LIMIT 1")
JG=/tmp/cbt_docimp_guru.$RANDOM.jar
masuk "$JG" ahmad guru123

echo "== 1. Modul pembaca .doc (dipanggil langsung) =="
KELUARAN=$(CBT_APP="$DIR" CBT_FIX="$FIX" php -d extension=pdo -d extension=pdo_sqlite -r '
require getenv("CBT_APP") . "/inc/doc.php";
$d = getenv("CBT_FIX");
$b = (string) file_get_contents($d . "/teks_mini.doc");
$ole = doc_ole_baca($b);
echo "ole=" . (!empty($ole["ok"]) ? "ya" : "tidak") . "\n";
echo "stream=" . count($ole["streams"]) . "\n";
$par = doc_baca_paragraf($b);
echo "paragraf=" . count($par) . "\n";
echo "judul=" . trim($par[0]) . "\n";
$tabel = doc_baca_tabel((string) file_get_contents($d . "/tabel.doc"));
echo "barisTabel=" . count($tabel) . "\n";
echo "selTabel=" . (isset($tabel[0]) ? count($tabel[0]) : 0) . "\n";
$miring = doc_baca_paragraf((string) file_get_contents($d . "/miring.doc"));
echo "miring=" . trim($miring[1]) . "\n";
echo "utf16=" . count(doc_baca_paragraf((string) file_get_contents($d . "/teks_utf16.doc"))) . "\n";
echo "tanpaClx=" . count(doc_baca_paragraf((string) file_get_contents($d . "/tanpa_clx.doc"))) . "\n";
echo "bukanWord=" . count(doc_baca_paragraf((string) file_get_contents($d . "/bukanword.doc"))) . "\n";
' 2>&1)
cari2 "$KELUARAN" 'ole=ya' "wadah OLE2 pada .doc terbaca"
cari2 "$KELUARAN" 'stream=2' "dua stream utama terbaca (WordDocument + 1Table)"
cari2 "$KELUARAN" 'judul=TEMPLATE SOAL' "judul template terbaca dari stream MINI"
BANYAK_PAR=$(printf '%s' "$KELUARAN" | grep -o 'paragraf=[0-9]*' | head -1 | cut -d= -f2)
if [ "${BANYAK_PAR:-0}" -ge 40 ]; then ok "paragraf template terbaca lengkap ($BANYAK_PAR baris)"; \
  else no "paragraf template kurang ($BANYAK_PAR)"; fi
cari2 "$KELUARAN" 'barisTabel=3' "tabel .doc terbaca 3 baris (header + 2 soal)"
cari2 "$KELUARAN" 'selTabel=10' "tabel .doc terbaca 10 kolom (sel kosong tidak memecah baris)"
cari2 "$KELUARAN" 'miring=1. Apa itu “fotosintesis”? Jelaskan – singkat saja.' \
  "tanda baca cp1252 ( “ ” – ) terbaca utuh"
cari2 "$KELUARAN" 'utf16=51' "potongan UTF-16LE terbaca sama banyaknya"
cari2 "$KELUARAN" 'tanpaClx=51' "jalur cadangan tanpa piece table juga terbaca"
cari2 "$KELUARAN" 'bukanWord=0' "berkas bukan Word menghasilkan 0 paragraf"

echo "== 2. Halaman impor menyebut .doc =="
HAL=$(curl -s -b "$JG" -c "$JG" "$BASE/ujian_import.php?ujian=$(sql "SELECT id FROM ujian ORDER BY id LIMIT 1")")
cari2 "$HAL" 'accept=".docx,.doc"' "isian berkas menerima .docx dan .doc"
cari2 "$HAL" 'Berkas Word (.docx atau .doc)' "label isian menyebut kedua format"
cari2 "$HAL" 'Word 97-2003' "keterangan menyebut Word 97-2003"
cari2 "$HAL" 'maupun <b>.doc</b>' "langkah pengisian menyebut .doc diterima"

echo "== 3. Impor .doc bertemplate TEKS (stream mini) =="
U1=$(ujian_baru "$GID" "$SID" "Uji Impor .doc Teks")
cek "ujian uji baru tanpa soal" "$(jumlah_soal "$U1")" "0"
KODE=$(unggah "$JG" "$U1" "$FIX/teks_mini.doc")
OUT=$(cat /tmp/cbt_docimp_out.html)
cek "unggah .doc dijawab 200" "$KODE" "200"
cari2 "$OUT" 'soal</b> berhasil diimpor' "halaman melaporkan soal berhasil diimpor"
cek "tepat 6 soal masuk dari .doc" "$(jumlah_soal "$U1")" "6"
cek "1 soal pilihan ganda" "$(sql "SELECT COUNT(*) FROM soal WHERE ujian_id=$U1 AND jenis='pg'")" "1"
cek "1 soal pilihan ganda kompleks" "$(sql "SELECT COUNT(*) FROM soal WHERE ujian_id=$U1 AND jenis='pgk'")" "1"
cek "1 soal benar/salah" "$(sql "SELECT COUNT(*) FROM soal WHERE ujian_id=$U1 AND jenis='bs'")" "1"
cek "1 soal menjodohkan" "$(sql "SELECT COUNT(*) FROM soal WHERE ujian_id=$U1 AND jenis='jodoh'")" "1"
cek "2 soal uraian" "$(sql "SELECT COUNT(*) FROM soal WHERE ujian_id=$U1 AND jenis='uraian'")" "2"
cek "kunci pilihan ganda = C (indeks 2)" \
  "$(sql "SELECT jawaban FROM soal WHERE ujian_id=$U1 AND jenis='pg' LIMIT 1")" "2"
cek "skor total 6 soal = 6" "$(sql "SELECT SUM(skor) FROM soal WHERE ujian_id=$U1")" "6"
cari2 "$(sql "SELECT pertanyaan FROM soal WHERE ujian_id=$U1 AND jenis='pg' LIMIT 1")" \
  'Manakah dari pernyataan' "pertanyaan pilihan ganda tersimpan utuh (bukan mojibake)"
cari2 "$(sql "SELECT pertanyaan FROM soal WHERE ujian_id=$U1 AND jenis='pgk' LIMIT 1")" \
  'surat pribadi' "pertanyaan kompleks tersimpan utuh"

echo "== 4. Varian lain berkas Word lama menghasilkan soal yang sama =="
for berkas in teks_besar teks_utf16 tanpa_clx; do
  UB=$(ujian_baru "$GID" "$SID" "Uji .doc $berkas")
  KODE=$(unggah "$JG" "$UB" "$FIX/$berkas.doc")
  N=$(jumlah_soal "$UB")
  if [ "$KODE" = "200" ] && [ "$N" = "6" ]; then
    ok "$berkas.doc → 6 soal"
  else
    no "$berkas.doc → gagal (kode $KODE, soal $N)"
  fi
  cari2 "$(sql "SELECT pertanyaan FROM soal WHERE ujian_id=$UB AND jenis='pg' LIMIT 1")" \
    'Manakah dari pernyataan' "$berkas.doc: teks soal sama persis"
done

echo "== 5. Template LAMA berbentuk TABEL dari .doc =="
U2=$(ujian_baru "$GID" "$SID" "Uji Impor .doc Tabel")
KODE=$(unggah "$JG" "$U2" "$FIX/tabel.doc")
cek "unggah tabel.doc dijawab 200" "$KODE" "200"
cek "2 soal dari tabel .doc masuk" "$(jumlah_soal "$U2")" "2"
cari2 "$(sql "SELECT pertanyaan FROM soal WHERE ujian_id=$U2 AND jenis='pg' LIMIT 1")" \
  'Hasil 7 x 8' "isi sel pertanyaan tabel terbaca"
cek "kunci tabel = opsi B (indeks 1)" \
  "$(sql "SELECT jawaban FROM soal WHERE ujian_id=$U2 AND jenis='pg' LIMIT 1")" "1"
cek "soal benar/salah dari tabel berjenis bs" \
  "$(sql "SELECT COUNT(*) FROM soal WHERE ujian_id=$U2 AND jenis='bs'")" "1"

echo "== 6. Berkas .docx yang diganti nama menjadi .doc =="
U3=$(ujian_baru "$GID" "$SID" "Uji docx bernama .doc")
KODE=$(unggah "$JG" "$U3" "$FIX/docx_palsu.doc")
cek "unggah docx_palsu.doc dijawab 200" "$KODE" "200"
cek "soal dari docx yang dinamai .doc ikut masuk" "$(jumlah_soal "$U3")" "1"
cari2 "$(sql "SELECT pertanyaan FROM soal WHERE ujian_id=$U3 LIMIT 1")" \
  'docx yang dinamai .doc' "isi soal dari docx terbaca"

echo "== 7. Berkas bukan Word ditolak =="
U4=$(ujian_baru "$GID" "$SID" "Uji berkas ditolak")
KODE=$(unggah "$JG" "$U4" "$FIX/bukanword.doc")
OUT=$(cat /tmp/cbt_docimp_out.html)
cek "unggah berkas bukan Word dijawab 200 (halaman dengan pesan)" "$KODE" "200"
cari2 "$OUT" 'tidak dikenali sebagai dokumen Word' "pesan 'tidak dikenali sebagai dokumen Word' tampil"
cek "tidak ada soal tersimpan dari berkas bukan Word" "$(jumlah_soal "$U4")" "0"

KODE=$(unggah "$JG" "$U4" "$FIX/rtf.doc")
OUT=$(cat /tmp/cbt_docimp_out.html)
cek "unggah berkas RTF dijawab 200" "$KODE" "200"
cari2 "$OUT" 'berformat RTF' "pesan khusus RTF tampil (agar disimpan sebagai .docx)"
cek "tidak ada soal tersimpan dari berkas RTF" "$(jumlah_soal "$U4")" "0"

KODE=$(unggah "$JG" "$U4" "$FIX/soal.txt")
OUT=$(cat /tmp/cbt_docimp_out.html)
cek "unggah berkas .txt dijawab 200" "$KODE" "200"
cari2 "$OUT" 'bukan berkas Word' "pesan ekstensi bukan Word tampil"
cek "tidak ada soal tersimpan dari berkas .txt" "$(jumlah_soal "$U4")" "0"

echo "== 8. Tanda baca & isolasi antar ujian =="
U5=$(ujian_baru "$GID" "$SID" "Uji tanda baca .doc")
U6=$(ujian_baru "$GID" "$SID" "Ujian lain (tidak disentuh)")
unggah "$JG" "$U5" "$FIX/miring.doc" >/dev/null
cek "ujian tujuan dapat 1 soal" "$(jumlah_soal "$U5")" "1"
cek "ujian lain tetap kosong (tidak ikut terisi)" "$(jumlah_soal "$U6")" "0"
cari2 "$(sql "SELECT pertanyaan FROM soal WHERE ujian_id=$U5 LIMIT 1")" \
  'Apa itu “fotosintesis”? Jelaskan – singkat saja.' \
  "tanda kutip lengkung & strip terbaca tepat"

echo "== 9. Kerapian & integritas =="
HAL=$(curl -s -b "$JG" -c "$JG" "$BASE/ujian_import.php?ujian=$U1")
cari2 "$HAL" 'assets/style.css?v=' "aset CSS berversi"
tidakk "$HAL" 'href="/' "halaman impor memakai tautan relatif"
cek "integritas basis data ok" "$(sql 'PRAGMA integrity_check')" "ok"
cek "tidak ada pelanggaran foreign key" "$(sql 'SELECT COUNT(*) FROM pragma_foreign_key_check')" "0"
cek "setiap soal menunjuk ujian yang sah" \
  "$(sql 'SELECT COUNT(*) FROM soal s LEFT JOIN ujian u ON u.id=s.ujian_id WHERE u.id IS NULL')" "0"
if CBT_DIR_UJI="$DIR" php -d extension=tokenizer "$DIR/../audit_query_cbt.php" > /tmp/cbt_docimp_audit.txt 2>&1; then
  cari2 "$(cat /tmp/cbt_docimp_audit.txt)" 'Semua cocok' "audit placeholder query: semua cocok"
else
  no "audit placeholder query gagal"
  sed -n 1,6p /tmp/cbt_docimp_audit.txt
fi

echo
echo "LULUS: $LULUS   GAGAL: $GAGAL"
[ "$GAGAL" -eq 0 ] || exit 1
