#!/usr/bin/env bash
# =====================================================================================
# Uji fitur "LINK PADA FITUR BUAT SOAL OTOMATIS" (akun Super Admin).
#
# Permintaan pemilik: "Pada halaman Super Admin tambahkan fitur untuk memasukkan link pada fitur
# buat soal otomatis".
#
# Yang diuji:
#   1. Sebelum diubah: tombol "Buat Soal Otomatis" di halaman Tambah Soal guru memakai tautan
#      bawaan aplikasi, dan halaman Super Admin menandainya "masih bawaan".
#   2. Menu sidebar Super Admin memuat "Soal Otomatis"; halaman khusus Super Admin.
#   3. Simpan tautan baru -> tersimpan di `app_pengaturan` (TINGKAT APLIKASI), tombol pada halaman
#      guru LANGSUNG memakai tautan baru, penanda berubah, tombol "Kembalikan Bawaan" muncul.
#   4. Validasi: kosong, bukan URL, skema selain http/https (javascript:/ftp:), ada spasi, dan
#      tautan kepanjangan ditolak (halaman dirender ulang 200, nilai tersimpan tidak berubah).
#   5. Tautan dengan parameter `&` ter-escape dengan benar pada href (atribut aman).
#   6. "Kembalikan Bawaan" mengembalikan tautan bawaan.
#   7. Hak akses: operator/guru/siswa 403, tamu 302, POST tanpa CSRF 419, POST operator 403.
#   8. Berlaku untuk semua sekolah (tidak ada kolom per sekolah).
#   9. Kerapian & integritas.
#
# Pemakaian:  bash tests_cbt_soal_otomatis_link.sh [BASE_URL]
# Tanpa BASE_URL skrip menyiapkan SALINAN aplikasi + basis data uji di /tmp dan menjalankan
# server PHP sendiri (basis data aplikasi TIDAK pernah disentuh).
# =====================================================================================
set -u
BASE="${1:-http://127.0.0.1:8899}"
DIR="$(cd "$(dirname "$0")" && pwd)"
DIR="${CBT_DIR:-$DIR}"
LULUS=0; GAGAL=0

SRV_PID=""
if [ -z "${1:-}" ]; then
  PORT=""
  for _p in $(seq 8850 8899); do
    if ! (exec 3<>"/dev/tcp/127.0.0.1/$_p") 2>/dev/null; then PORT=$_p; break; fi
    exec 3<&- 2>/dev/null || true
  done
  [ -n "$PORT" ] || { echo "Tidak ada port uji yang bebas"; exit 1; }
  BASE="http://127.0.0.1:$PORT"
  KERJA="/tmp/cbt_solink_$$"
  rm -rf "$KERJA"; mkdir -p "$KERJA"
  cp -r "$DIR"/. "$KERJA"/
  rm -f "$KERJA"/data/cbt.sqlite* "$KERJA"/data/.schema_ok
  mkdir -p "$KERJA/data/sessions"; chmod 777 "$KERJA/data" "$KERJA/data/sessions" 2>/dev/null || true
  CBT_DB_FILE="$KERJA/data/cbt.sqlite" php -d extension=pdo -d extension=pdo_sqlite "$KERJA/fixture_cbt.php" >/dev/null
  php -d extension=pdo -d extension=pdo_sqlite -S "127.0.0.1:$PORT" -t "$KERJA" > "/tmp/cbt_solink_srv_$$.log" 2>&1 &
  SRV_PID=$!
  for _i in $(seq 1 25); do
    curl -s -o /dev/null "http://127.0.0.1:$PORT/login.php" && break
    sleep 0.4
  done
  DB="$KERJA/data/cbt.sqlite"
  export DB
  bersihkan(){ [ -n "$SRV_PID" ] && kill "$SRV_PID" 2>/dev/null; rm -rf "$KERJA"; }
  trap bersihkan EXIT
fi

ok(){ LULUS=$((LULUS+1)); printf '  ok   %s\n' "$1"; }
no(){ GAGAL=$((GAGAL+1)); printf '  GAGAL %s\n' "$1"; }
cek(){ if [ "$2" = "$3" ]; then ok "$1"; else no "$1 (dapat: $2, harap: $3)"; fi; }
cari2(){ if printf '%s' "$1" | grep -q -- "$2"; then ok "$3"; else no "$3"; fi; }
tidakk(){ if printf '%s' "$1" | grep -q -- "$2"; then no "$3"; else ok "$3"; fi; }
hitung(){ printf '%s' "$1" | grep -o -- "$2" | wc -l | tr -d ' '; }
sql(){ php -d extension=pdo -d extension=pdo_sqlite -r '
$p=new PDO("sqlite:'"$DB"'"); $q=$p->query("'"$1"'"); echo ($q?$q->fetchColumn():"0");'; }
tautan_tersimpan(){ sql "SELECT COALESCE(nilai,'') FROM app_pengaturan WHERE kunci='soal_otomatis_url'"; }

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
# Href tombol "Buat Soal Otomatis" pada halaman Tambah Soal.
href_tombol(){ printf '%s' "$1" | tr '<' '\n<' | grep -A2 'id="btnSoalOtomatis"' \
  | grep -o 'href="[^"]*"' | head -1 | sed 's/href="//;s/"$//'; }

SID=$(sql "SELECT id FROM sekolah ORDER BY id LIMIT 1")
UJ=$(sql "SELECT id FROM ujian WHERE sekolah_id=$SID ORDER BY id LIMIT 1")
BAWAAN='https://gemini.google.com/share/9b5d2dc2944b'

JS=/tmp/cbt_solink_super.$RANDOM.jar
JG=/tmp/cbt_solink_guru.$RANDOM.jar
JO=/tmp/cbt_solink_op.$RANDOM.jar
JSI=/tmp/cbt_solink_siswa.$RANDOM.jar

masuk "$JS" admin admin123
masuk "$JO" operator1 sandi12345
masuk "$JG" ahmad guru123
masuk "$JSI" siswa1 siswa123

echo "== 1. Sebelum diubah: tautan bawaan berlaku =="
HAL=$(curl -s -b "$JG" -c "$JG" "$BASE/soal_edit.php?ujian=$UJ")
cari2 "$HAL" 'id="btnSoalOtomatis"' "tombol Buat Soal Otomatis tampil di halaman Tambah Soal guru"
cek "href tombol = tautan bawaan aplikasi" "$(href_tombol "$HAL")" "$BAWAAN"
cari2 "$HAL" 'target="_blank"' "tombol membuka tab baru"
cari2 "$HAL" 'rel="noopener noreferrer"' "tombol memakai rel=noopener noreferrer"
cari2 "$HAL" 'sandiSoalOtomatis' "keterangan kata sandi aplikasi tetap tampil"
cek "belum ada nilai tersimpan (masih bawaan)" "$(tautan_tersimpan)" ""

echo "== 2. Menu & halaman Super Admin =="
HOME=$(curl -s -b "$JS" -c "$JS" "$BASE/index.php")
cari2 "$HOME" 'soal_otomatis.php' "sidebar Super Admin memuat menu Soal Otomatis"
cari2 "$HOME" 'Soal Otomatis' "label menu 'Soal Otomatis' tampil"
KODE=$(curl -s -b "$JS" -c "$JS" -o /tmp/cbt_solink_page.html -w '%{http_code}' "$BASE/soal_otomatis.php")
cek "halaman soal_otomatis.php dibuka Super Admin (200)" "$KODE" "200"
PAGE=$(cat /tmp/cbt_solink_page.html)
cari2 "$PAGE" 'Buat Soal Otomatis' "halaman memuat judul fitur"
cari2 "$PAGE" 'id="formSoalOtomatis"' "halaman memuat formulir tautan"
cari2 "$PAGE" 'id="soal_otomatis_url"' "isian tautan tersedia"
cari2 "$PAGE" 'id="pratinjauSoalOtomatis"' "pratinjau tombol tersedia"
cek "pratinjau memakai tautan bawaan" \
  "$(printf '%s' "$PAGE" | tr '<' '\n<' | grep -A2 'id="btnSoalOtomatisContoh"' | grep -o 'href="[^"]*"' | head -1 | sed 's/href="//;s/"$//')" "$BAWAAN"
cari2 "$PAGE" 'masih bawaan' "penanda 'masih bawaan' tampil"
tidakk "$PAGE" 'Kembalikan Bawaan' "tombol Kembalikan Bawaan disembunyikan selama masih bawaan"
cari2 "$PAGE" 'belum dapat diubah dari halaman ini' "halaman menjelaskan kata sandi belum dapat diubah"

echo "== 3. Simpan tautan baru =="
T=$(csrf "$JS" soal_otomatis.php)
BARU='https://gemini.google.com/share/uji-link-baru-123'
KODE=$(curl -s -b "$JS" -c "$JS" -o /dev/null -w '%{http_code}' -d "csrf=$T" -d 'aksi=simpan' \
  --data-urlencode "soal_otomatis_url=$BARU" "$BASE/soal_otomatis.php")
cek "simpan tautan dijawab 302" "$KODE" "302"
cek "tautan tersimpan apa adanya di app_pengaturan" "$(tautan_tersimpan)" "$BARU"
PAGE=$(curl -s -b "$JS" -c "$JS" "$BASE/soal_otomatis.php?x=1")
cari2 "$PAGE" 'Tautan &quot;Buat Soal Otomatis&quot; disimpan' "pesan sukses tampil"
cari2 "$PAGE" 'sudah disesuaikan' "penanda berubah menjadi 'sudah disesuaikan'"
cari2 "$PAGE" 'Kembalikan Bawaan' "tombol Kembalikan Bawaan muncul setelah diubah"
cari2 "$PAGE" "$BARU" "tautan baru tampil pada pratinjau"
HAL=$(curl -s -b "$JG" -c "$JG" "$BASE/soal_edit.php?ujian=$UJ")
cek "halaman guru LANGSUNG memakai tautan baru" "$(href_tombol "$HAL")" "$BARU"
HAL2=$(curl -s -b "$JO" -c "$JO" "$BASE/soal_edit.php?ujian=$UJ")
cek "akun operator juga memakai tautan baru" "$(href_tombol "$HAL2")" "$BARU"

echo "== 4. Validasi isian =="
T=$(csrf "$JS" soal_otomatis.php)
SEBELUM=$(tautan_tersimpan)
KODE=$(curl -s -b "$JS" -c "$JS" -o /tmp/cbt_solink_empty.html -w '%{http_code}' -d "csrf=$T" \
  -d 'aksi=simpan' --data-urlencode 'soal_otomatis_url=' "$BASE/soal_otomatis.php")
cek "tautan kosong ditolak (200, halaman dirender ulang)" "$KODE" "200"
cari2 "$(cat /tmp/cbt_solink_empty.html)" 'Tautan wajib diisi' "pesan 'Tautan wajib diisi' tampil"
cek "nilai tersimpan TIDAK berubah saat kosong ditolak" "$(tautan_tersimpan)" "$SEBELUM"
for salah in 'bukan url' 'gemini.google.com/share/abc' 'javascript:alert(1)' 'ftp://contoh.com/soal'; do
  KODE=$(curl -s -b "$JS" -c "$JS" -o /tmp/cbt_solink_salah.html -w '%{http_code}' -d "csrf=$T" \
    -d 'aksi=simpan' --data-urlencode "soal_otomatis_url=$salah" "$BASE/soal_otomatis.php")
  if [ "$KODE" = "200" ] && grep -q 'Tautan belum benar' /tmp/cbt_solink_salah.html; then
    ok "ditolak dengan pesan jelas: $salah"
  else
    no "seharusnya ditolak: $salah (kode $KODE)"
  fi
done
cek "nilai tersimpan tetap setelah semua penolakan" "$(tautan_tersimpan)" "$SEBELUM"
PANJANG="https://contoh.com/$(printf 'a%.0s' $(seq 1 520))"
KODE=$(curl -s -b "$JS" -c "$JS" -o /tmp/cbt_solink_long.html -w '%{http_code}' -d "csrf=$T" \
  -d 'aksi=simpan' --data-urlencode "soal_otomatis_url=$PANJANG" "$BASE/soal_otomatis.php")
cek "tautan kepanjangan ditolak (200)" "$KODE" "200"
cari2 "$(cat /tmp/cbt_solink_long.html)" 'terlalu panjang' "pesan 'terlalu panjang' tampil"
cek "nilai tersimpan tetap setelah tautan panjang ditolak" "$(tautan_tersimpan)" "$SEBELUM"

echo "== 5. Tautan berparameter & ter-escape aman =="
T=$(csrf "$JS" soal_otomatis.php)
AMP='https://contoh.com/soal?kelas=7&mapel=mtk'
curl -s -b "$JS" -c "$JS" -o /dev/null -d "csrf=$T" -d 'aksi=simpan' \
  --data-urlencode "soal_otomatis_url=$AMP" "$BASE/soal_otomatis.php"
cek "tautan berparameter tersimpan" "$(tautan_tersimpan)" "$AMP"
HAL=$(curl -s -b "$JG" -c "$JG" "$BASE/soal_edit.php?ujian=$UJ")
cari2 "$HAL" 'kelas=7&amp;mapel=mtk' "tanda & pada href ter-escape jadi &amp; (aman)"
tidakk "$HAL" 'href="https://contoh.com/soal?kelas=7&mapel=mtk"' "tidak ada & mentah di dalam atribut"

echo "== 6. Kembalikan Bawaan =="
T=$(csrf "$JS" soal_otomatis.php)
KODE=$(curl -s -b "$JS" -c "$JS" -o /dev/null -w '%{http_code}' -d "csrf=$T" -d 'aksi=bawaan' \
  "$BASE/soal_otomatis.php")
cek "aksi bawaan dijawab 302" "$KODE" "302"
cek "nilai tersimpan dikosongkan (kembali bawaan)" "$(tautan_tersimpan)" ""
PAGE=$(curl -s -b "$JS" -c "$JS" "$BASE/soal_otomatis.php")
cari2 "$PAGE" 'dikembalikan ke bawaan aplikasi' "pesan bawaan tampil"
cari2 "$PAGE" 'masih bawaan' "penanda kembali 'masih bawaan'"
HAL=$(curl -s -b "$JG" -c "$JG" "$BASE/soal_edit.php?ujian=$UJ")
cek "halaman guru kembali memakai tautan bawaan" "$(href_tombol "$HAL")" "$BAWAAN"

echo "== 7. Hak akses & keamanan =="
cek "operator 403 di halaman soal otomatis" \
  "$(curl -s -b "$JO" -c "$JO" -o /dev/null -w '%{http_code}' "$BASE/soal_otomatis.php")" "403"
cek "guru 403 di halaman soal otomatis" \
  "$(curl -s -b "$JG" -c "$JG" -o /dev/null -w '%{http_code}' "$BASE/soal_otomatis.php")" "403"
cek "siswa 403 di halaman soal otomatis" \
  "$(curl -s -b "$JSI" -c "$JSI" -o /dev/null -w '%{http_code}' "$BASE/soal_otomatis.php")" "403"
cek "tanpa login dialihkan (302)" \
  "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/soal_otomatis.php")" "302"
TU=$(csrf "$JS" soal_otomatis.php)
cek "POST tanpa CSRF dijawab 419" \
  "$(curl -s -b "$JS" -c "$JS" -o /dev/null -w '%{http_code}' -d 'aksi=simpan' \
     --data-urlencode 'soal_otomatis_url=https://jahat.example/x' "$BASE/soal_otomatis.php")" "419"
cek "POST operator (tanpa hak) 403" \
  "$(curl -s -b "$JO" -c "$JO" -o /dev/null -w '%{http_code}' -d "csrf=$TU" -d 'aksi=simpan' \
     --data-urlencode 'soal_otomatis_url=https://operator.example/x' "$BASE/soal_otomatis.php")" "403"
cek "tautan 'operator.example' tidak tersimpan" "$(tautan_tersimpan)" ""
tidakk "$(curl -s -b "$JO" -c "$JO" "$BASE/index.php")" 'soal_otomatis.php' \
  "operator tidak melihat menu Soal Otomatis di sidebar"
tidakk "$(curl -s -b "$JG" -c "$JG" "$BASE/index.php")" 'soal_otomatis.php' \
  "guru tidak melihat menu Soal Otomatis di sidebar"

echo "== 8. Tingkat APLIKASI (bukan per sekolah) =="
T=$(csrf "$JS" soal_otomatis.php)
curl -s -b "$JS" -c "$JS" -o /dev/null -d "csrf=$T" -d 'aksi=simpan' \
  --data-urlencode 'soal_otomatis_url=https://contoh.com/soal-semua-sekolah' "$BASE/soal_otomatis.php"
cek "tautan tersimpan pada app_pengaturan (aplikasi-level)" \
  "$(sql "SELECT CASE WHEN nilai LIKE '%soal-semua-sekolah%' THEN 'ya' ELSE 'tidak' END
          FROM app_pengaturan WHERE kunci='soal_otomatis_url'")" "ya"
tidakk "$(sql "SELECT sql FROM sqlite_master WHERE name='sekolah'")" 'soal_otomatis' \
  "tidak ada kolom soal_otomatis pada tabel sekolah (memang aplikasi-level)"
cd "$DIR"
if [ -n "${DB:-}" ] && [ -f "$DB" ]; then
  # Helper dibaca langsung dari SALINAN uji (CBT_DB_FILE) — bukan basis data aplikasi.
  CBT_DB_FILE="$DB" CBT_DIR_UJI="$DIR" php -d extension=pdo -d extension=pdo_sqlite -r \
    'require getenv("CBT_DIR_UJI") . "/inc/init.php"; echo soal_otomatis_url();' \
    > /tmp/cbt_solink_helper.txt 2>/dev/null || true
fi
if [ -s /tmp/cbt_solink_helper.txt ]; then
  cari2 "$(cat /tmp/cbt_solink_helper.txt)" 'soal-semua-sekolah' \
    "helper soal_otomatis_url() mengembalikan tautan tersimpan"
fi
rm -f /tmp/cbt_solink_helper.txt

echo "== 9. Kerapian & integritas =="
PAGE=$(curl -s -b "$JS" -c "$JS" "$BASE/soal_otomatis.php")
tidakk "$PAGE" 'href="/' "halaman memakai tautan relatif (tanpa href=\"/\")"
tidakk "$PAGE" '<style' "tanpa tag <style> di halaman"
cari2 "$PAGE" "assets/style.css?v=" "aset CSS berversi (cache aman)"
tidakk "$PAGE" 'vibecoder-media-token' "token media tidak bocor di HTML"
cek "integritas basis data ok" "$(sql 'PRAGMA integrity_check')" "ok"
cek "tidak ada pelanggaran foreign key" "$(sql 'SELECT COUNT(*) FROM pragma_foreign_key_check')" "0"

echo
echo "LULUS: $LULUS   GAGAL: $GAGAL"
[ "$GAGAL" -eq 0 ] || exit 1
