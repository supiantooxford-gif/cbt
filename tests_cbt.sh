#!/usr/bin/env bash
# =====================================================================================
# Uji alur CBT (satu sekolah): guru membuat ujian 6 jenis soal, siswa mengerjakan,
# penilaian otomatis, koreksi uraian, penguncian siswa, dan pelanggaran.
#
# Pemakaian:  bash tests_cbt.sh [BASE_URL]
# Basis data uji: CBT_DIR (default: folder aplikasi). JANGAN arahkan ke data nyata.
# =====================================================================================
set -u
BASE="${1:-http://127.0.0.1:8899}"
DIR="$(cd "$(dirname "$0")" && pwd)"
DIR="${CBT_DIR:-$DIR}"
DB="${CBT_DB_DEFAULT:-$DIR/data/cbt.sqlite}"
LULUS=0; GAGAL=0

# ---------------------------------------------------------------------------------------------
# Mode MANDIRI: bila BASE_URL tidak diberikan, skrip ini menyiapkan SALINAN aplikasi + basis
# data uji di /tmp dan menjalankan server PHP sendiri (data aplikasi TIDAK disentuh).
# ---------------------------------------------------------------------------------------------
SRV_PID=""
if [ -z "${1:-}" ]; then
  # pilih port yang masih bebas (menghindari server uji lain yang belum berhenti)
  PORT=""
  for _p in $(seq 8850 8899); do
    if ! (exec 3<>"/dev/tcp/127.0.0.1/$_p") 2>/dev/null; then PORT=$_p; break; fi
    exec 3<&- 2>/dev/null || true
  done
  [ -n "$PORT" ] || { echo "Tidak ada port uji yang bebas"; exit 1; }
  BASE="http://127.0.0.1:$PORT"
  KERJA="/tmp/cbt_uji_$$"
  rm -rf "$KERJA"; mkdir -p "$KERJA"
  cp -r "$DIR"/. "$KERJA"/
  rm -f "$KERJA"/data/cbt.sqlite* "$KERJA"/data/.schema_ok
  mkdir -p "$KERJA/data/sessions"; chmod 777 "$KERJA/data" "$KERJA/data/sessions" 2>/dev/null || true
  CBT_DB_FILE="$KERJA/data/cbt.sqlite" php -d extension=pdo -d extension=pdo_sqlite "$KERJA/fixture_cbt.php" >/dev/null
  php -d extension=pdo -d extension=pdo_sqlite -S "127.0.0.1:$PORT" -t "$KERJA" > "/tmp/cbt_uji_srv_$$.log" 2>&1 &
  SRV_PID=$!
  for _i in $(seq 1 25); do
    curl -s -o /dev/null "http://127.0.0.1:$PORT/login.php" && break
    sleep 0.4
  done
  DB="$KERJA/data/cbt.sqlite"
  export DB
  bersihkan(){ [ -n "$SRV_PID" ] && kill "$SRV_PID" 2>/dev/null; [ "${CBT_SIMPAN:-0}" = "1" ] || rm -rf "$KERJA"; echo "salinan uji: $KERJA"; }
  trap bersihkan EXIT
fi
ok(){ LULUS=$((LULUS+1)); printf '  ok   %s\n' "$1"; }
no(){ GAGAL=$((GAGAL+1)); printf '  GAGAL %s\n' "$1"; }
cek(){ if [ "$2" = "$3" ]; then ok "$1"; else no "$1 (dapat: $2, harap: $3)"; fi; }
cari(){ if printf '%s' "$1" | grep -q -- "$2"; then ok "$3"; else no "$3"; fi; }
tidakk(){ if printf '%s' "$1" | grep -q -- "$2"; then no "$3"; else ok "$3"; fi; }

csrf(){
  local hal; hal=$(curl -s -b "$1" -c "$1" "$BASE/$2")
  local t; t=$(printf '%s' "$hal" | grep -o 'name="csrf-token" content="[^"]*"' | head -1 | sed 's/.*content="//;s/"//')
  [ -n "$t" ] || t=$(printf '%s' "$hal" | grep -o 'name="csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
  printf '%s' "$t"
}
masuk(){ # $1 jar $2 user $3 sandi
  rm -f "$1"
  local t; t=$(csrf "$1" login.php)
  curl -s -b "$1" -c "$1" -o /dev/null -d "csrf=$t" -d "username=$2" -d "password=$3" "$BASE/login.php"
}

echo "== 0. Persiapan =="
SJ=/tmp/cbt_super.jar; JG=/tmp/cbt_guru.jar; JS=/tmp/cbt_siswa.jar
rm -f /tmp/cbt_*.jar
masuk "$SJ" admin admin123
masuk "$JG" ahmad guru123
masuk "$JS" siswa1 siswa123

T=$(csrf "$JG" index.php)
cek "token CSRF guru terbaca" "$([ -n "$T" ] && echo ada)" "ada"

echo "== 1. Guru membuat ujian =="
H=$(curl -s -b "$JG" -c "$JG" -o /tmp/cbt_ujian.html -w '%{http_code}' \
  --data-urlencode "csrf=$T" -d 'aksi=simpan' --data-urlencode 'judul=Ujian Uji Otomatis' \
  -d 'mapel_id=1' -d 'jenis_ujian=Ulangan Harian' -d 'durasi=60' --data-urlencode 'keterangan=Kerjakan dengan teliti.' \
  -d 'kelas[]=1' -d 'kelas[]=2' -d 'acak_soal=1' -d 'tampilkan_hasil=1' "$BASE/ujian_edit.php")
cek "simpan ujian (302)" "$H" "302"
UJID=$(php -d extension=pdo -d extension=pdo_sqlite -r '
$p=new PDO("sqlite:'$DB'");
echo (int)$p->query("SELECT id FROM ujian ORDER BY id DESC LIMIT 1")->fetchColumn();')
cek "ujian tersimpan di basis data" "$([ "$UJID" -gt 0 ] && echo ada)" "ada"

echo "== 2. Enam jenis soal =="
T=$(csrf "$JG" "ujian_edit.php?id=$UJID")
tambah_soal(){ # $1..: data-urlencode kunci nilai tambahan
  curl -s -b "$JG" -c "$JG" -o /tmp/cbt_soal.html -w '%{http_code}' \
    -d "csrf=$T" -d "ujian=$UJID" "${@}" "$BASE/soal_edit.php"
}
R=$(tambah_soal -d 'jenis=pg' --data-urlencode 'pertanyaan=Hasil 12 x 8 adalah...' --data-urlencode 'skor=1' \
     --data-urlencode 'opsi_pg[]=86' --data-urlencode 'opsi_pg[]=96' --data-urlencode 'opsi_pg[]=108' --data-urlencode 'opsi_pg[]=112' \
     -d 'kunci_pg=1')
cek "soal pilihan ganda (302)" "$R" "302"
R=$(tambah_soal -d 'jenis=pgk' --data-urlencode 'pertanyaan=Manakah bilangan prima?' --data-urlencode 'skor=2' \
     --data-urlencode 'opsi_pg[]=2' --data-urlencode 'opsi_pg[]=3' --data-urlencode 'opsi_pg[]=4' --data-urlencode 'opsi_pg[]=9' \
     -d 'kunci_pgk[]=0' -d 'kunci_pgk[]=1')
cek "soal pilihan ganda kompleks (302)" "$R" "302"
R=$(tambah_soal -d 'jenis=bs' --data-urlencode 'pertanyaan=Nyatakan benar atau salah.' --data-urlencode 'skor=2' \
     --data-urlencode 'opsi_bs[]=2 + 2 = 4' --data-urlencode 'opsi_bs[]=5 x 5 = 20' \
     -d 'bs[]=B' -d 'bs[]=S')
cek "soal benar/salah (302)" "$R" "302"
R=$(tambah_soal -d 'jenis=jodoh' --data-urlencode 'pertanyaan=Jodohkan!' --data-urlencode 'skor=2' \
     --data-urlencode 'opsi_jodoh[]=2 + 2' --data-urlencode 'opsi_jodoh[]=3 x 3' \
     --data-urlencode 'jodoh[]=4' --data-urlencode 'jodoh[]=9')
cek "soal menjodohkan (302)" "$R" "302"
R=$(tambah_soal -d 'jenis=isian' --data-urlencode 'pertanyaan=Akar dari 81 adalah...' --data-urlencode 'skor=1' \
     --data-urlencode 'kunci_teks=9
sembilan')
cek "soal isian singkat (302)" "$R" "302"
R=$(tambah_soal -d 'jenis=uraian' --data-urlencode 'pertanyaan=Jelaskan cara menghitung luas persegi panjang.' --data-urlencode 'skor=4' \
     --data-urlencode 'kunci_teks=panjang x lebar/panjang kali lebar/p x l')
cek "soal uraian (302)" "$R" "302"
unset -f tambah_soal

JUMLAH=$(php -d extension=pdo -d extension=pdo_sqlite -r '
$p=new PDO("sqlite:'$DB'");
echo (int)$p->query("SELECT COUNT(*) FROM soal WHERE ujian_id='"$UJID"'")->fetchColumn();')
cek "jumlah soal tersimpan" "$JUMLAH" "6"

echo "== 3. Aktivasi ujian =="
T=$(csrf "$JG" "ujian_edit.php?id=$UJID")
H=$(curl -s -b "$JG" -c "$JG" -o /dev/null -w '%{http_code}' -d "csrf=$T" -d 'aksi=aktif' "$BASE/ujian_edit.php?id=$UJID")
cek "aktifkan ujian (302)" "$H" "302"
ST=$(php -d extension=pdo -d extension=pdo_sqlite -r '
$p=new PDO("sqlite:'$DB'");
echo $p->query("SELECT status FROM ujian WHERE id='"$UJID"'")->fetchColumn();')
cek "status ujian" "$ST" "aktif"

echo "== 4. Siswa melihat & memulai ujian =="
L=$(curl -s -b "$JS" -c "$JS" "$BASE/ujian_siswa.php")
cari "$L" "Ujian Uji Otomatis" "ujian tampil pada daftar siswa"
cari "$L" "Kerjakan" "tombol Kerjakan tersedia"

H=$(curl -s -b "$JS" -c "$JS" -o /tmp/cbt_kerjakan.html -w '%{http_code}' "$BASE/kerjakan.php?id=$UJID")
cek "buka halaman pengerjaan (200)" "$H" "200"
K=$(cat /tmp/cbt_kerjakan.html)
cari "$K" "Masuk Mode Ujian Sekarang" "layar konfirmasi layar penuh tampil"
cari "$K" "timerUjian" "penghitung waktu tersedia"
for j in "Pilihan Ganda" "Pilihan Ganda Kompleks" "Benar / Salah" "Menjodohkan" "Isian Singkat" "Uraian / Essai"; do
  cari "$K" "$j" "soal jenis '$j' tampil"
done

echo "== 5. Penguncian akun siswa selama ujian =="
H=$(curl -s -b "$JS" -c "$JS" -o /dev/null -w '%{http_code}' "$BASE/index.php")
cek "index.php dialihkan (302) saat ujian berjalan" "$H" "302"
H=$(curl -s -b "$JS" -c "$JS" -o /dev/null -w '%{http_code} %{redirect_url}' "$BASE/akun.php")
cari "$H" "kerjakan.php" "halaman lain dialihkan ke kerjakan.php"

echo "== 6. Menjawab soal =="
SESI=$(php -d extension=pdo -d extension=pdo_sqlite -r '
$p=new PDO("sqlite:'$DB'");
echo (int)$p->query("SELECT id FROM sesi_ujian WHERE ujian_id='"$UJID"' ORDER BY id DESC LIMIT 1")->fetchColumn();')
cek "sesi ujian dibuat" "$([ "$SESI" -gt 0 ] && echo ada)" "ada"
mapfile -t SOAL < <(php -d extension=pdo -d extension=pdo_sqlite -r '
$p=new PDO("sqlite:'$DB'");
foreach($p->query("SELECT id,jenis FROM soal WHERE ujian_id='"$UJID"' ORDER BY urutan") as $r){ echo $r["id"]."|".$r["jenis"]."\n"; }')
IDS=()
for _s in "${SOAL[@]:-}"; do IDS+=("${_s%%|*}"); done
cek "enam soal terbaca dari basis data" "${#IDS[@]}" "6"
T=$(csrf "$JS" "kerjakan.php?id=$UJID")
jawab(){ curl -s -b "$JS" -c "$JS" -d "csrf=$T" -d "sesi=$SESI" -d "soal=$1" --data-urlencode "jawaban=$2" "$BASE/simpan_jawaban.php"; }
R=$(jawab "${IDS[0]:-0}" '1');            cari "$R" '"ok":true' "jawaban pilihan ganda tersimpan"
R=$(jawab "${IDS[1]:-0}" '[0,1]');        cari "$R" '"ok":true' "jawaban pilihan ganda kompleks tersimpan"
R=$(jawab "${IDS[2]:-0}" '["B","S"]');    cari "$R" '"ok":true' "jawaban benar/salah tersimpan"
# Susunan pilihan kanan diacak oleh server; hitung posisi yang benar lebih dahulu.
JAWAB_JODOH=$(CBT_DB_FILE="$DB" php -d extension=pdo -d extension=pdo_sqlite -r '
require "'"$DIR"'/inc/init.php";
$sid = (int) "'"${SOAL[3]:-0}|"'";
$bar = one("SELECT opsi FROM soal WHERE id = ?", [$sid]);
$n = count(opsi_decode($bar["opsi"] ?? null));
$urut = jodoh_urut($sid, '"$SESI"', $n);
$pos = [];
foreach ($urut as $p => $asli) { $pos[$asli] = $p; }
ksort($pos);
echo json_encode(array_values($pos));')
echo "     (posisi pasangan kanan yang benar: $JAWAB_JODOH)"
R=$(jawab "${IDS[3]:-0}" "$JAWAB_JODOH"); cari "$R" '"ok":true' "jawaban menjodohkan tersimpan"
R=$(jawab "${IDS[4]:-0}" '"9"');          cari "$R" '"ok":true' "jawaban isian tersimpan"
R=$(jawab "${IDS[5]:-0}" '"Luas persegi panjang dihitung dengan panjang x lebar."')
cari "$R" '"ok":true' "jawaban uraian tersimpan"
cari "$R" '"terjawab":6' "enam jawaban terhitung tersimpan"

echo "== 7. Nilai otomatis =="
php -d extension=pdo -d extension=pdo_sqlite -r '
require "'"$DIR"'/inc/init.php"; sesi_hitung('"$SESI"');' >/dev/null 2>&1
NILAI=$(php -d extension=pdo -d extension=pdo_sqlite -r '
$p=new PDO("sqlite:'$DB'");
echo $p->query("SELECT nilai FROM sesi_ujian WHERE id='"$SESI"'")->fetchColumn();')
# skor: pg=1, pgk=2, bs=2, jodoh=2, isian=1, uraian=4 (kata kunci "panjang x lebar" cocok) => 12 dari 12 = 100
cek "nilai otomatis 100" "$(printf '%.0f' "$NILAI")" "100"

echo "== 8. Mengumpulkan & hasil siswa =="
R=$(curl -s -b "$JS" -c "$JS" -d "csrf=$T" -d "sesi=$SESI" "$BASE/selesai.php")
cari "$R" '"ok":true' "pengumpulan jawaban berhasil"
H=$(curl -s -b "$JS" -c "$JS" -o /tmp/cbt_hasil.html -w '%{http_code}' "$BASE/selesai.php?sesi=$SESI")
cek "halaman hasil siswa (200)" "$H" "200"
# JALUR CADANGAN formulir biasa (tanpa fetch): `lanjut=1` harus MENGALIHKAN ke halaman hasil,
# bukan membalas JSON — inilah yang dipakai tombol Kumpulkan bila jaringan/fetch bermasalah.
LK=$(curl -s -b "$JS" -c "$JS" -o /dev/null -w '%{http_code} %{redirect_url}' \
  -d "csrf=$T" -d "sesi=$SESI" -d 'lanjut=1' "$BASE/selesai.php")
cek "formulir cadangan (lanjut=1) mengalihkan (302)" "$(printf '%s' "$LK" | cut -d' ' -f1)" "302"
cari "$LK" "selesai.php?sesi=$SESI" "pengalihan menuju halaman hasil sesi itu"
cek "pengumpulan tanpa CSRF lewat formulir cadangan ditolak (302 ke kerjakan)" \
  "$(curl -s -o /dev/null -w '%{http_code}' -d "sesi=$SESI" -d 'lanjut=1' "$BASE/selesai.php")" "302"
cek "sesi tetap terkumpul (tidak diubah menjadi mengerjakan)" \
  "$(php -d extension=pdo -d extension=pdo_sqlite -r '
$p=new PDO("sqlite:'"$DB"'"); echo $p->query("SELECT status FROM sesi_ujian WHERE id='"$SESI"'")->fetchColumn();')" "selesai"
cari "$(cat /tmp/cbt_hasil.html)" "Nilai" "nilai tampil pada halaman hasil"
H=$(curl -s -b "$JS" -c "$JS" -o /dev/null -w '%{http_code}' "$BASE/index.php")
cek "siswa kembali bebas setelah selesai (302 -> index)" "$H" "302"

echo "== 9. Laporan & analisis (guru) =="
H=$(curl -s -b "$JG" -c "$JG" -o /tmp/cbt_analisis.html -w '%{http_code}' "$BASE/hasil_ujian.php?id=$UJID")
cek "halaman analisis (200)" "$H" "200"
A=$(cat /tmp/cbt_analisis.html)
cari "$A" "Analisis Butir Soal" "analisis butir tersedia"
cari "$A" "Daya Beda" "kolom daya beda tersedia"
cari "$A" "Koreksi" "tombol koreksi tersedia"
H=$(curl -s -b "$JG" -o /tmp/cbt_cetak.html -w '%{http_code}' "$BASE/cetak_hasil.php?id=$UJID")
cek "cetak laporan nilai (200)" "$H" "200"
cari "$(cat /tmp/cbt_cetak.html)" "Laporan Hasil Ujian" "judul lembar cetak benar"
H=$(curl -s -b "$JG" -o /tmp/cbt_cetak2.html -w '%{http_code}' "$BASE/cetak_hasil.php?id=$UJID&bagian=analisis")
cek "cetak analisis (200)" "$H" "200"
cari "$(cat /tmp/cbt_cetak2.html)" "Analisis Butir Soal" "judul lembar analisis benar"

echo "== 10. Koreksi uraian =="
H=$(curl -s -b "$JG" -c "$JG" -o /tmp/cbt_koreksi.html -w '%{http_code}' "$BASE/koreksi.php?sesi=$SESI")
cek "halaman koreksi (200)" "$H" "200"
T=$(csrf "$JG" "koreksi.php?sesi=$SESI")
R=$(curl -s -b "$JG" -c "$JG" -o /dev/null -w '%{http_code}' -d "csrf=$T" -d "sesi=$SESI" \
    -d "skor[${IDS[5]:-0}]=2" "$BASE/koreksi.php")
cek "simpan koreksi (302)" "$R" "302"
NILAI2=$(php -d extension=pdo -d extension=pdo_sqlite -r '
$p=new PDO("sqlite:'$DB'");
echo $p->query("SELECT nilai FROM sesi_ujian WHERE id='"$SESI"'")->fetchColumn();')
cek "nilai menurun setelah koreksi manual (10 dari 12 = 83,33)" "$(printf '%.0f' "$NILAI2")" "83"

echo "== 11. Pelanggaran -> otomatis dikumpulkan =="
masuk "$JS" siswa2 siswa123
T=$(csrf "$JS" index.php)
curl -s -b "$JS" -c "$JS" -o /dev/null "$BASE/kerjakan.php?id=$UJID"
SESI2=$(php -d extension=pdo -d extension=pdo_sqlite -r '
$p=new PDO("sqlite:'$DB'");
echo (int)$p->query("SELECT id FROM sesi_ujian WHERE ujian_id='"$UJID"' ORDER BY id DESC LIMIT 1")->fetchColumn();')
T=$(csrf "$JS" "kerjakan.php?id=$UJID")
for i in 1 2 3; do
  R=$(curl -s -b "$JS" -c "$JS" -d "csrf=$T" -d "sesi=$SESI2" --data-urlencode "sebab=Pindah tab (uji $i)" "$BASE/pelanggaran.php")
done
cari "$R" '"pelanggaran":3' "tiga pelanggaran tercatat"
cari "$R" '"kumpulkan":true' "pelanggaran ke-3 memicu pengumpulan otomatis"
ST2=$(php -d extension=pdo -d extension=pdo_sqlite -r '
$p=new PDO("sqlite:'$DB'");
echo $p->query("SELECT status FROM sesi_ujian WHERE id='"$SESI2"'")->fetchColumn();')
cek "status sesi menjadi dikumpulkan" "$ST2" "dikumpulkan"

echo "== 12. Pemisahan sekolah (anti-IDOR) =="
# sekolah kedua
T=$(csrf "$SJ" akun.php)
curl -s -b "$SJ" -c "$SJ" -o /dev/null --data-urlencode "csrf=$T" -d 'aksi=tambah' --data-urlencode 'nama=Sekolah Uji Kedua' \
  -d 'jenjang=SD/MI' --data-urlencode 'kepala=Kepala Dua' -d 'kkm=70' "$BASE/sekolah.php"
H=$(curl -s -b "$JG" -c "$JG" -o /dev/null -w '%{http_code}' "$BASE/hasil_ujian.php?id=999999")
cek "id ujian asing ditolak" "$H" "302"

echo "== 13. Hak akses =="
H=$(curl -s -b "$JS" -o /dev/null -w '%{http_code}' "$BASE/guru.php")
cek "siswa tidak boleh membuka Data Guru (403)" "$H" "403"
H=$(curl -s -b "$JS" -o /dev/null -w '%{http_code}' "$BASE/ujian.php")
cek "siswa tidak boleh membuka menu ujian guru (403)" "$H" "403"
H=$(curl -s -b "$JG" -o /dev/null -w '%{http_code}' "$BASE/sekolah.php")
cek "guru tidak boleh membuka Kelola Sekolah (403)" "$H" "403"
H=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/index.php")
cek "belum masuk dialihkan ke login (302)" "$H" "302"

echo
echo "===== HASIL: $LULUS lulus, $GAGAL gagal ====="
[ "$GAGAL" -eq 0 ] || exit 1
