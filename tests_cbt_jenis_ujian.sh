#!/usr/bin/env bash
# =====================================================================================
# Uji fitur "JENIS PENILAIAN (JENIS ULANGAN)" pada akun Super Admin.
#
# Permintaan pemilik: "Pada akun Super Admin tambahkan fitur untuk menghapus atau menambahkan
# jenis ulangan yang tampil pada halaman buat ujian guru".
#
# Yang diuji:
#   1. Daftar bawaan (6 jenis) berlaku pada pilihan "Jenis Penilaian" halaman buat ujian guru.
#   2. Menu sidebar Super Admin memuat "Jenis Penilaian"; halaman khusus Super Admin.
#   3. Tambah jenis baru -> langsung tampil pada pilihan guru & tersimpan di `app_pengaturan`
#      sebagai JSON; guru dapat MEMAKAI jenis baru itu saat membuat ujian.
#   4. Nama kosong, nama ganda, & nama berlebih ditolak/dipotong dengan pesan yang jelas.
#   5. Hapus jenis -> hilang dari pilihan guru, tetapi ujian yang sudah memakainya TIDAK berubah
#      (label tetap tampil, dan nilai lama tetap terpilih saat ujian itu disunting).
#   6. Jenis terakhir tidak dapat dihapus.
#   7. "Kembalikan Bawaan" mengembalikan 6 jenis bawaan.
#   8. Hak akses: operator/guru/siswa 403, tamu 302, POST tanpa CSRF 419.
#   9. Pengaturan berlaku untuk SEMUA sekolah (tingkat aplikasi).
#
# Pemakaian:  bash tests_cbt_jenis_ujian.sh [BASE_URL]
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
  KERJA="/tmp/cbt_jenis_$$"
  rm -rf "$KERJA"; mkdir -p "$KERJA"
  cp -r "$DIR"/. "$KERJA"/
  rm -f "$KERJA"/data/cbt.sqlite* "$KERJA"/data/.schema_ok
  mkdir -p "$KERJA/data/sessions"; chmod 777 "$KERJA/data" "$KERJA/data/sessions" 2>/dev/null || true
  CBT_DB_FILE="$KERJA/data/cbt.sqlite" php -d extension=pdo -d extension=pdo_sqlite "$KERJA/fixture_cbt.php" >/dev/null
  php -d extension=pdo -d extension=pdo_sqlite -S "127.0.0.1:$PORT" -t "$KERJA" > "/tmp/cbt_jenis_srv_$$.log" 2>&1 &
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
# Jumlah jenis pada daftar tersimpan + panjang nama terpanjang.
daftar_info(){ php -d extension=pdo -d extension=pdo_sqlite -r '
$p = new PDO("sqlite:'"$DB"'");
$st = $p->prepare("SELECT nilai FROM app_pengaturan WHERE kunci = ?");
$st->execute(["jenis_ujian"]);
$d = json_decode((string) $st->fetchColumn(), true);
$d = is_array($d) ? $d : [];
$maks = 0;
foreach ($d as $x) { $maks = max($maks, strlen((string) $x)); }
echo count($d) . "|" . $maks;'; }

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

SID=$(sql "SELECT id FROM sekolah ORDER BY id LIMIT 1")
JS=/tmp/cbt_ju_super.$RANDOM.jar
JG=/tmp/cbt_ju_guru.$RANDOM.jar
JO=/tmp/cbt_ju_op.$RANDOM.jar
JSI=/tmp/cbt_ju_siswa.$RANDOM.jar

masuk "$JS" admin admin123
masuk "$JO" operator1 sandi12345
masuk "$JG" ahmad guru123
masuk "$JSI" siswa1 siswa123

# Super Admin masuk konteks sekolah (agar bisa melihat halaman guru pada uji berikutnya).
curl -s -b "$JS" -c "$JS" -o /dev/null "$BASE/sekolah.php?masuk=$SID"

echo "== 1. Daftar bawaan tampil pada halaman buat ujian guru =="
HAL=$(curl -s -b "$JG" -c "$JG" "$BASE/ujian_edit.php")
cari2 "$HAL" 'id="jenis_ujian"' "halaman Buat Ujian Baru memuat pilihan Jenis Penilaian"
for j in 'Ulangan Harian' 'Penilaian Tengah Semester' 'Penilaian Akhir Semester' 'Try Out' 'Latihan' 'Praktik'; do
  cari2 "$HAL" "value=\"$j\"" "pilihan bawaan tersedia: $j"
done
cek "basis data belum diubah (masih bawaan)" \
  "$(sql "SELECT COALESCE(nilai,'') FROM app_pengaturan WHERE kunci='jenis_ujian'")" ""
cari2 "$HAL" 'diatur Super Admin pada menu' "halaman guru menjelaskan daftar diatur Super Admin"

echo "== 2. Menu & halaman Super Admin =="
curl -s -b "$JS" -c "$JS" -o /dev/null "$BASE/index.php?keluar=1"
HOME=$(curl -s -b "$JS" -c "$JS" "$BASE/index.php")
cari2 "$HOME" 'jenis_ujian.php' "sidebar Super Admin memuat menu Jenis Penilaian"
cari2 "$HOME" 'Jenis Penilaian' "label menu 'Jenis Penilaian' tampil"
KODE=$(curl -s -b "$JS" -c "$JS" -o /tmp/cbt_ju_page.html -w '%{http_code}' "$BASE/jenis_ujian.php")
cek "halaman jenis_ujian.php dibuka Super Admin (200)" "$KODE" "200"
PAGE=$(cat /tmp/cbt_ju_page.html)
cari2 "$PAGE" 'Jenis Penilaian (Jenis Ulangan)' "halaman memuat judul yang benar"
cari2 "$PAGE" 'id="formTambahJenis"' "halaman memuat formulir tambah jenis"
cek "tabel daftar jenis penilaian ada" "$(hitung "$PAGE" 'id="tblJenisUjian"')" "1"
cek "6 jenis bawaan terdaftar pada tabel" "$(hitung "$PAGE" '<td><b>')" "6"
cari2 "$PAGE" 'masih bawaan' "penanda 'masih bawaan' tampil"
tidakk "$PAGE" 'Kembalikan Bawaan' "tombol Kembalikan Bawaan disembunyikan selama masih bawaan"

echo "== 3. Tambah jenis penilaian baru =="
T=$(csrf "$JS" jenis_ujian.php)
KODE=$(curl -s -b "$JS" -c "$JS" -o /dev/null -w '%{http_code}' -d "csrf=$T" -d 'aksi=tambah' \
  --data-urlencode 'nama=Asesmen Madrasah' "$BASE/jenis_ujian.php")
cek "tambah jenis dijawab 302 (berhasil)" "$KODE" "302"
cek "daftar tersimpan berisi 7 jenis" "$(daftar_info | cut -d'|' -f1)" "7"
cek "tersimpan pada app_pengaturan (JSON)" \
  "$(sql "SELECT CASE WHEN nilai LIKE '%Asesmen Madrasah%' THEN 'ya' ELSE 'tidak' END FROM app_pengaturan WHERE kunci='jenis_ujian'")" "ya"
PAGE=$(curl -s -b "$JS" -c "$JS" "$BASE/jenis_ujian.php?x=1")
cari2 "$PAGE" 'Asesmen Madrasah' "jenis baru tampil pada daftar Super Admin"
cari2 "$PAGE" 'sudah disesuaikan' "penanda berubah menjadi 'sudah disesuaikan'"
cari2 "$PAGE" 'Kembalikan Bawaan' "tombol Kembalikan Bawaan muncul setelah daftar diubah"
cari2 "$PAGE" 'Jenis penilaian &quot;Asesmen Madrasah&quot; ditambahkan' "pesan sukses penambahan tampil"
HAL=$(curl -s -b "$JG" -c "$JG" "$BASE/ujian_edit.php")
cari2 "$HAL" 'value="Asesmen Madrasah"' "jenis baru LANGSUNG tampil pada halaman buat ujian guru"

echo "== 4. Validasi penambahan =="
T=$(csrf "$JS" jenis_ujian.php)
SEBELUM=$(sql "SELECT nilai FROM app_pengaturan WHERE kunci='jenis_ujian'")
KODE=$(curl -s -b "$JS" -c "$JS" -o /tmp/cbt_ju_dup.html -w '%{http_code}' -d "csrf=$T" -d 'aksi=tambah' \
  --data-urlencode 'nama=asesmen madrasah' "$BASE/jenis_ujian.php")
cek "nama ganda (beda huruf besar/kecil) ditolak (200)" "$KODE" "200"
cari2 "$(cat /tmp/cbt_ju_dup.html)" 'sudah ada pada daftar' "pesan 'sudah ada pada daftar' tampil"
cek "daftar TIDAK berubah saat nama ganda ditolak" \
  "$(sql "SELECT nilai FROM app_pengaturan WHERE kunci='jenis_ujian'")" "$SEBELUM"
KODE=$(curl -s -b "$JS" -c "$JS" -o /tmp/cbt_ju_emp.html -w '%{http_code}' -d "csrf=$T" -d 'aksi=tambah' \
  --data-urlencode 'nama=   ' "$BASE/jenis_ujian.php")
cek "nama kosong ditolak (200)" "$KODE" "200"
cari2 "$(cat /tmp/cbt_ju_emp.html)" 'wajib diisi' "pesan 'wajib diisi' tampil"
curl -s -b "$JS" -c "$JS" -o /dev/null -d "csrf=$T" -d 'aksi=tambah' \
  --data-urlencode "nama=$(printf 'A%.0s' $(seq 1 80))" "$BASE/jenis_ujian.php"
cek "nama kepanjangan dipotong menjadi 40 karakter" "$(daftar_info | cut -d'|' -f2)" "40"
# Bersihkan jenis panjang itu supaya daftar kembali ke 7 jenis.
curl -s -b "$JS" -c "$JS" -o /dev/null -d "csrf=$T" -d 'aksi=hapus' \
  --data-urlencode "nama=$(printf 'A%.0s' $(seq 1 40))" "$BASE/jenis_ujian.php"
cek "daftar kembali 7 jenis setelah dibersihkan" "$(daftar_info | cut -d'|' -f1)" "7"

echo "== 5. Guru dapat MEMAKAI jenis baru saat membuat ujian =="
T=$(csrf "$JG" ujian_edit.php)
KODE=$(curl -s -b "$JG" -c "$JG" -o /dev/null -w '%{http_code}' -d "csrf=$T" -d 'aksi=simpan' \
  --data-urlencode 'judul=Asesmen Madrasah Fikih' -d 'mapel_id=0' \
  --data-urlencode 'jenis_ujian=Asesmen Madrasah' -d 'durasi=45' \
  --data-urlencode 'keterangan=uji jenis penilaian' "$BASE/ujian_edit.php")
cek "guru menyimpan ujian dengan jenis baru (302)" "$KODE" "302"
cek "ujian tersimpan dengan jenis 'Asesmen Madrasah'" \
  "$(sql "SELECT COUNT(*) FROM ujian WHERE jenis_ujian='Asesmen Madrasah'")" "1"

echo "== 6. Hapus jenis penilaian =="
T=$(csrf "$JS" jenis_ujian.php)
KODE=$(curl -s -b "$JS" -c "$JS" -o /dev/null -w '%{http_code}' -d "csrf=$T" -d 'aksi=hapus' \
  --data-urlencode 'nama=Latihan' "$BASE/jenis_ujian.php")
cek "hapus jenis dijawab 302" "$KODE" "302"
HAL=$(curl -s -b "$JG" -c "$JG" "$BASE/ujian_edit.php")
tidakk "$HAL" 'value="Latihan"' "jenis yang dihapus hilang dari pilihan halaman buat ujian"
cari2 "$HAL" 'id="jenis_ujian"' "pilihan Jenis Penilaian tetap ada"
PAGE=$(curl -s -b "$JS" -c "$JS" "$BASE/jenis_ujian.php")
tidakk "$PAGE" '<td><b>Latihan</b></td>' "jenis yang dihapus hilang dari daftar Super Admin"
cari2 "$PAGE" 'Ujian yang sudah memakainya TIDAK berubah' "pesan penghapusan menjelaskan ujian lama aman"

# Ujian LAMA (dari fixture) memakai jenis 'Ulangan Harian' — hapus jenisnya lalu pastikan
# ujian itu TIDAK kehilangan labelnya.
UJ=$(sql "SELECT id FROM ujian WHERE jenis_ujian='Ulangan Harian' LIMIT 1")
cek "ujian lama berjenis 'Ulangan Harian' tersedia" "$([ -n "$UJ" ] && echo ya || echo tidak)" "ya"
T=$(csrf "$JS" jenis_ujian.php)
KODE=$(curl -s -b "$JS" -c "$JS" -o /dev/null -w '%{http_code}' -d "csrf=$T" -d 'aksi=hapus' \
  --data-urlencode 'nama=Ulangan Harian' "$BASE/jenis_ujian.php")
cek "hapus jenis 'Ulangan Harian' dijawab 302" "$KODE" "302"
cek "label jenis pada ujian lama TIDAK berubah" \
  "$(sql "SELECT jenis_ujian FROM ujian WHERE id=$UJ")" "Ulangan Harian"
HAL=$(curl -s -b "$JG" -c "$JG" "$BASE/ujian_edit.php?id=$UJ")
cari2 "$HAL" 'value="Ulangan Harian" selected' "nilai lama tetap terpilih saat ujian lama disunting"
LIST=$(curl -s -b "$JG" -c "$JG" "$BASE/ujian.php")
cari2 "$LIST" 'Ulangan Harian' "daftar ujian guru tetap menampilkan label jenis lama"
tidakk "$(curl -s -b "$JG" -c "$JG" "$BASE/ujian_edit.php")" 'value="Ulangan Harian"' \
  "jenis yang dihapus tidak ditawarkan pada ujian BARU"

echo "== 7. Jenis terakhir tidak dapat dihapus =="
php -d extension=pdo -d extension=pdo_sqlite -r '
$p = new PDO("sqlite:'"$DB"'");
$st = $p->prepare("INSERT INTO app_pengaturan (kunci, nilai) VALUES (?, ?)
                   ON CONFLICT(kunci) DO UPDATE SET nilai = excluded.nilai");
$st->execute(["jenis_ujian", "[\"Hanya Satu\"]"]);'
T=$(csrf "$JS" jenis_ujian.php)
KODE=$(curl -s -b "$JS" -c "$JS" -o /tmp/cbt_ju_last.html -w '%{http_code}' -d "csrf=$T" -d 'aksi=hapus' \
  --data-urlencode 'nama=Hanya Satu' "$BASE/jenis_ujian.php")
cek "hapus jenis terakhir ditolak (200, bukan 302)" "$KODE" "200"
cari2 "$(cat /tmp/cbt_ju_last.html)" 'Minimal harus ada satu jenis penilaian' \
  "pesan 'minimal satu jenis penilaian' tampil"
cek "jenis terakhir tetap ada" "$(sql "SELECT nilai FROM app_pengaturan WHERE kunci='jenis_ujian'")" \
  '["Hanya Satu"]'
cari2 "$(cat /tmp/cbt_ju_last.html)" '<td><b>Hanya Satu</b></td>' "daftar tetap menampilkan jenis terakhir"

echo "== 8. Kembalikan Bawaan =="
T=$(csrf "$JS" jenis_ujian.php)
KODE=$(curl -s -b "$JS" -c "$JS" -o /dev/null -w '%{http_code}' -d "csrf=$T" -d 'aksi=bawaan' \
  "$BASE/jenis_ujian.php")
cek "aksi bawaan dijawab 302" "$KODE" "302"
cek "daftar kembali ke 6 jenis bawaan" "$(daftar_info | cut -d'|' -f1)" "6"
PAGE=$(curl -s -b "$JS" -c "$JS" "$BASE/jenis_ujian.php")
cari2 "$PAGE" 'Daftar jenis penilaian dikembalikan ke bawaan aplikasi' "pesan bawaan tampil"
cari2 "$PAGE" 'masih bawaan' "penanda kembali 'masih bawaan'"
HAL=$(curl -s -b "$JG" -c "$JG" "$BASE/ujian_edit.php")
cari2 "$HAL" 'value="Latihan"' "jenis bawaan 'Latihan' kembali tersedia"
cari2 "$HAL" 'value="Ulangan Harian"' "jenis bawaan 'Ulangan Harian' kembali tersedia"

echo "== 9. Hak akses & keamanan =="
cek "operator 403 di halaman jenis penilaian" \
  "$(curl -s -b "$JO" -c "$JO" -o /dev/null -w '%{http_code}' "$BASE/jenis_ujian.php")" "403"
cek "guru 403 di halaman jenis penilaian" \
  "$(curl -s -b "$JG" -c "$JG" -o /dev/null -w '%{http_code}' "$BASE/jenis_ujian.php")" "403"
cek "siswa 403 di halaman jenis penilaian" \
  "$(curl -s -b "$JSI" -c "$JSI" -o /dev/null -w '%{http_code}' "$BASE/jenis_ujian.php")" "403"
cek "tanpa login dialihkan (302)" \
  "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/jenis_ujian.php")" "302"
TU=$(csrf "$JS" jenis_ujian.php)
cek "POST tanpa CSRF dijawab 419" \
  "$(curl -s -b "$JS" -c "$JS" -o /dev/null -w '%{http_code}' -d 'aksi=tambah' \
     --data-urlencode 'nama=Tidak Sah' "$BASE/jenis_ujian.php")" "419"
cek "POST operator (tanpa hak) 403" \
  "$(curl -s -b "$JO" -c "$JO" -o /dev/null -w '%{http_code}' -d "csrf=$TU" -d 'aksi=tambah' \
     --data-urlencode 'nama=Operator Nakal' "$BASE/jenis_ujian.php")" "403"
cek "nama 'Operator Nakal' tidak masuk daftar" \
  "$(sql "SELECT CASE WHEN nilai LIKE '%Operator Nakal%' THEN 'ya' ELSE 'TIDAK' END FROM app_pengaturan WHERE kunci='jenis_ujian'")" "TIDAK"
tidakk "$(curl -s -b "$JO" -c "$JO" "$BASE/index.php")" 'jenis_ujian.php' \
  "operator tidak melihat menu Jenis Penilaian di sidebar"
tidakk "$(curl -s -b "$JG" -c "$JG" "$BASE/index.php")" 'jenis_ujian.php' \
  "guru tidak melihat menu Jenis Penilaian di sidebar"

echo "== 10. Tingkat APLIKASI: berlaku untuk semua sekolah =="
T=$(csrf "$JS" jenis_ujian.php)
curl -s -b "$JS" -c "$JS" -o /dev/null -d "csrf=$T" -d 'aksi=tambah' \
  --data-urlencode 'nama=Sumatif Akhir Tahun' "$BASE/jenis_ujian.php"
T=$(csrf "$JS" sekolah.php)
curl -s -b "$JS" -c "$JS" -o /dev/null -d "csrf=$T" -d 'aksi=tambah' \
  --data-urlencode 'nama=Sekolah Uji Jenis' -d 'jenjang=SMP/MTs' -d 'npsn=777001' \
  --data-urlencode 'kecamatan=Uji' --data-urlencode 'kepala=Kepala Uji' -d 'kkm=70' "$BASE/sekolah.php"
S2=$(sql "SELECT id FROM sekolah WHERE nama='Sekolah Uji Jenis'")
cek "sekolah kedua dibuat" "$([ -n "$S2" ] && echo ya || echo tidak)" "ya"
HAL=$(curl -s -b "$JO" -c "$JO" "$BASE/ujian_edit.php")
cari2 "$HAL" 'value="Sumatif Akhir Tahun"' "jenis baru dipakai juga oleh akun sekolah lain"
cek "pengaturan tersimpan TINGKAT APLIKASI (bukan per sekolah)" \
  "$(sql "SELECT CASE WHEN nilai LIKE '%Sumatif Akhir Tahun%' THEN 'ya' ELSE 'tidak' END
          FROM app_pengaturan WHERE kunci='jenis_ujian'")" "ya"
tidakk "$(sql "SELECT sql FROM sqlite_master WHERE name='sekolah'")" 'jenis_ujian' \
  "tidak ada kolom jenis_ujian pada tabel sekolah (memang aplikasi-level)"

echo "== 11. Kerapian & integritas =="
PAGE=$(curl -s -b "$JS" -c "$JS" "$BASE/jenis_ujian.php")
tidakk "$PAGE" 'href="/' "halaman memakai tautan relatif (tanpa href=\"/\")"
tidakk "$PAGE" '<style' "tanpa tag <style> di halaman"
cari2 "$PAGE" "assets/style.css?v=" "aset CSS berversi (cache aman)"
tidakk "$PAGE" 'vibecoder-media-token' "token media tidak bocor di HTML"
cek "integritas basis data ok" "$(sql 'PRAGMA integrity_check')" "ok"
cek "tidak ada pelanggaran foreign key" "$(sql 'SELECT COUNT(*) FROM pragma_foreign_key_check')" "0"
cek "setiap ujian punya sekolah yang sah" \
  "$(sql 'SELECT COUNT(*) FROM ujian u LEFT JOIN sekolah s ON s.id=u.sekolah_id WHERE s.id IS NULL')" "0"

echo
echo "LULUS: $LULUS   GAGAL: $GAGAL"
[ "$GAGAL" -eq 0 ] || exit 1
