#!/usr/bin/env bash
# =====================================================================================
# Uji TOMBOL HAPUS pada kolom Aksi daftar sekolah (permintaan pemilik).
#
# Permintaan: *"Tambahkan fitur hapus pada yang ditandai warna merah"* — kolom AKSI pada tabel
# "Sekolah/Madrasah Aktif" (halaman Kelola Sekolah, akun Super Admin).
#
# Yang diuji:
#   1. Setiap baris (sekolah aktif & nonaktif) punya tombol "Hapus" pada kolom Aksi.
#   2. Tombol itu TIDAK menghapus langsung — membuka kartu konfirmasi (`?hapus=ID`) yang
#      menampilkan nama sekolah + dampak (guru, siswa, akun, ujian, soal).
#   3. Penghapusan tetap wajib ketik HAPUS: tanpa/tanpa kata yang benar → ditolak, data utuh.
#   4. Dengan HAPUS → sekolah + seluruh datanya benar-benar terhapus (guru/siswa/akun/ujian soal).
#   5. Sekolah terakhir TIDAK boleh dihapus (kartu konfirmasi menjelaskannya).
#   6. `?hapus=` id asing → pesan ramah, bukan halaman rusak/500.
#   7. Konteks Super Admin yang menunjuk sekolah terhapus otomatis dilepas.
#   8. Hak akses: operator/guru 403, belum masuk 302; tanpa CSRF 419.
#   9. Kerapian: tanpa tautan absolut, aset berversi, integritas + FK bersih, log server bersih.
#
# Pemakaian:  bash tests_cbt_hapus_sekolah.sh [BASE_URL]
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
  KERJA="/tmp/cbt_hapus_$$"
  rm -rf "$KERJA"; mkdir -p "$KERJA"
  cp -r "$DIR"/. "$KERJA"/
  rm -f "$KERJA"/data/cbt.sqlite* "$KERJA"/data/.schema_ok
  mkdir -p "$KERJA/data/sessions"; chmod 777 "$KERJA/data" "$KERJA/data/sessions" 2>/dev/null || true
  CBT_DB_FILE="$KERJA/data/cbt.sqlite" php -d extension=pdo -d extension=pdo_sqlite "$KERJA/fixture_cbt.php" >/dev/null
  LOG="/tmp/cbt_hapus_srv_$$.log"
  php -d extension=pdo -d extension=pdo_sqlite -S "127.0.0.1:$PORT" -t "$KERJA" > "$LOG" 2>&1 &
  SRV_PID=$!
  for _i in $(seq 1 25); do
    curl -s -o /dev/null "http://127.0.0.1:$PORT/login.php" && break
    sleep 0.4
  done
  DB="$KERJA/data/cbt.sqlite"
  export DB
  bersihkan(){ [ -n "$SRV_PID" ] && kill "$SRV_PID" 2>/dev/null; rm -rf "$KERJA"; }
  trap bersihkan EXIT
else
  DB="${CBT_DB_DEFAULT:-$DIR/data/cbt.sqlite}"
fi

ok(){ LULUS=$((LULUS+1)); printf '  ok   %s\n' "$1"; }
no(){ GAGAL=$((GAGAL+1)); printf '  GAGAL %s\n' "$1"; }
cek(){ if [ "$2" = "$3" ]; then ok "$1"; else no "$1 (dapat: $2, harap: $3)"; fi; }
cari(){ if printf '%s' "$1" | grep -q -- "$2"; then ok "$3"; else no "$3"; fi; }
tidakk(){ if printf '%s' "$1" | grep -q -- "$2"; then no "$3"; else ok "$3"; fi; }
hitung(){ printf '%s' "$1" | grep -o -- "$2" | wc -l | tr -d ' '; }
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
# Dampak isi sebuah sekolah (untuk memastikan penghapusan benar-benar membersihkan datanya)
sidik(){ sql "SELECT (SELECT COUNT(*) FROM guru WHERE sekolah_id=$1)||'-'||(SELECT COUNT(*) FROM siswa WHERE sekolah_id=$1)||'-'||(SELECT COUNT(*) FROM users WHERE sekolah_id=$1)||'-'||(SELECT COUNT(*) FROM ujian WHERE sekolah_id=$1)||'-'||(SELECT COUNT(*) FROM soal WHERE sekolah_id=$1)"; }

JS=/tmp/cbt_hapus_super.jar
masuk "$JS" admin admin123

echo "== 1. Tombol Hapus ada pada kolom Aksi setiap baris =="
HAL=$(curl -s -b "$JS" -c "$JS" "$BASE/sekolah.php")
cari "$HAL" "Sekolah/Madrasah Aktif" "tabel Sekolah/Madrasah Aktif tampil"
JUMLAH_AKTIF=$(sql "SELECT COUNT(*) FROM sekolah WHERE aktif=1")
cek "jumlah tombol Hapus pada tabel aktif = jumlah sekolah aktif" \
  "$(hitung "$HAL" 'sekolah\.php?hapus=')" "$JUMLAH_AKTIF"
cari "$HAL" 'href="sekolah.php?hapus=[0-9]*"' "tombol Hapus menuju kartu konfirmasi"
tidakk "$HAL" 'sekolah.php?hapus=[0-9]*#hapusSekolah' "tautan hapus tidak memakai fragmen (menjaga autofocus)"
cari "$HAL" 'title="Hapus sekolah ini (perlu konfirmasi)"' "tombol Hapus punya keterangan"
cari "$HAL" ">Hapus</a>" "label tombol berbunyi Hapus"
cari "$HAL" "btn-bahaya" "tombol memakai gaya bahaya (merah)"
# Tombol Hapus berada pada kolom Aksi (satu baris dengan tombol Masuk & Ubah)
cari "$HAL" 'btn-utama" href="sekolah.php?masuk=' "tombol Masuk tetap ada"
tidakk "$HAL" 'sekolah.php?hapus=0' "tidak ada tautan hapus dengan id 0"

echo "== 2. Tombol TIDAK menghapus langsung: membuka kartu konfirmasi =="
# Fixture hanya menyiapkan SATU sekolah; penghapusan punya penjaga "sekolah terakhir tidak boleh
# dihapus", jadi lebih dulu dibuat sekolah uji tambahan yang memang boleh dihapus.
T=$(csrf "$JS" akun.php)
curl -s -b "$JS" -c "$JS" -o /dev/null -d "csrf=$T" -d 'aksi=tambah' --data-urlencode 'nama=Sekolah Uji Hapus' \
  -d 'jenjang=SD/MI' --data-urlencode 'kepala=Kepala Hapus' -d 'kkm=70' "$BASE/sekolah.php"
SID=$(sql "SELECT id FROM sekolah WHERE nama='Sekolah Uji Hapus'")
NAMA=$(sql "SELECT nama FROM sekolah WHERE id=$SID")
SEBELUM=$(sql "SELECT COUNT(*) FROM sekolah WHERE id=$SID")
cek "sekolah uji ada sebelum konfirmasi" "$SEBELUM" "1"

KONF=$(curl -s -b "$JS" -c "$JS" "$BASE/sekolah.php?hapus=$SID")
cari "$KONF" 'id="hapusSekolah"' "kartu konfirmasi hapus tampil"
cari "$KONF" "$NAMA" "kartu konfirmasi menyebut nama sekolah"
cari "$KONF" "Penghapusan bersifat PERMANEN" "kartu memperingatkan penghapusan permanen"
cari "$KONF" "guru</b>" "kartu menampilkan dampak jumlah guru"
cari "$KONF" "siswa</b>" "kartu menampilkan dampak jumlah siswa"
cari "$KONF" "ujian</b>" "kartu menampilkan dampak jumlah ujian"
cari "$KONF" 'name="konfirmasi"' "kartu meminta isian konfirmasi"
cari "$KONF" "Nonaktifkan Sekolah" "kartu menyarankan nonaktifkan bila hanya ingin menghentikan"
cari "$KONF" 'value="hapus"' "form konfirmasi mengirim aksi=hapus"
cek "sekolah BELUM terhapus setelah membuka kartu" "$(sql "SELECT COUNT(*) FROM sekolah WHERE id=$SID")" "1"

echo "== 3. Penghapusan tetap wajib ketik HAPUS =="
DAMPAK=$(sidik "$SID")
T=$(csrf "$JS" "sekolah.php?hapus=$SID")
HAL=$(curl -s -L -b "$JS" -c "$JS" -d "csrf=$T" -d 'aksi=hapus' -d "id=$SID" -d 'konfirmasi=salah' "$BASE/sekolah.php")
cari "$HAL" "ketik HAPUS untuk konfirmasi" "konfirmasi salah ditolak dengan pesan"
cek "sekolah masih ada setelah konfirmasi salah" "$(sql "SELECT COUNT(*) FROM sekolah WHERE id=$SID")" "1"
cek "data sekolah tidak berubah setelah konfirmasi salah" "$(sidik "$SID")" "$DAMPAK"
T=$(csrf "$JS" "sekolah.php?hapus=$SID")
HAL=$(curl -s -L -b "$JS" -c "$JS" -d "csrf=$T" -d 'aksi=hapus' -d "id=$SID" "$BASE/sekolah.php")
cari "$HAL" "ketik HAPUS untuk konfirmasi" "konfirmasi kosong juga ditolak"
cek "sekolah masih ada setelah konfirmasi kosong" "$(sql "SELECT COUNT(*) FROM sekolah WHERE id=$SID")" "1"

echo "== 4. Dengan HAPUS: sekolah + seluruh datanya terhapus =="
# Tambahkan data pada sekolah uji supaya pembersihan datanya benar-benar teruji.
sql "INSERT INTO kelas (sekolah_id, nama, tingkat, urutan, dibuat) VALUES ($SID, 'IX-UJI', '9', 10, datetime('now'))" >/dev/null
sql "INSERT INTO mapel (sekolah_id, nama, urutan, dibuat) VALUES ($SID, 'Mapel Uji', 10, datetime('now'))" >/dev/null
sql "INSERT INTO siswa (sekolah_id, nis, nama, kelas_id, aktif, dibuat) VALUES ($SID, 'HAPUS-1', 'Siswa Uji Hapus', (SELECT id FROM kelas WHERE sekolah_id=$SID LIMIT 1), 1, datetime('now'))" >/dev/null
sql "INSERT INTO ujian (sekolah_id, judul, jenis_ujian, durasi, status, dibuat, diubah) VALUES ($SID, 'Ujian Uji Hapus', 'Ulangan Harian', 30, 'draf', datetime('now'), datetime('now'))" >/dev/null
UJID=$(sql "SELECT id FROM ujian WHERE sekolah_id=$SID LIMIT 1")
sql "INSERT INTO soal (sekolah_id, ujian_id, jenis, pertanyaan, skor, urutan, dibuat) VALUES ($SID, $UJID, 'isian', 'Soal uji hapus', 1, 10, datetime('now'))" >/dev/null
DAMPAK=$(sidik "$SID")
cari "$DAMPAK" "^[0-9]*-[0-9]*" "data tambahan tersimpan (dampak: $DAMPAK)"
T=$(csrf "$JS" "sekolah.php?hapus=$SID")
KODE=$(curl -s -b "$JS" -c "$JS" -o /tmp/cbt_hapus_ok.html -w '%{http_code}' \
  -d "csrf=$T" -d 'aksi=hapus' -d "id=$SID" -d 'konfirmasi=HAPUS' "$BASE/sekolah.php")
cek "penghapusan dijawab 302 (pola POST→Redirect)" "$KODE" "302"
HAL=$(curl -s -b "$JS" -c "$JS" "$BASE/sekolah.php")
cari "$HAL" "dihapus permanen" "pesan sukses penghapusan tampil"
cari "$HAL" "$NAMA" "nama sekolah yang dihapus disebut pada pesan"
cek "sekolah benar-benar terhapus" "$(sql "SELECT COUNT(*) FROM sekolah WHERE id=$SID")" "0"
cek "data guru/siswa/akun/ujian/soal sekolah ikut terhapus" "$(sidik "$SID")" "0-0-0-0-0"
cek "kelas sekolah ikut terhapus" "$(sql "SELECT COUNT(*) FROM kelas WHERE sekolah_id=$SID")" "0"
cek "mata pelajaran sekolah ikut terhapus" "$(sql "SELECT COUNT(*) FROM mapel WHERE sekolah_id=$SID")" "0"
tidakk "$HAL" "sekolah.php?hapus=$SID#hapusSekolah" "baris sekolah terhapus hilang dari daftar"
cek "sekolah lain TIDAK ikut terhapus" "$(sql "SELECT COUNT(*) FROM sekolah WHERE aktif=1")" "$((JUMLAH_AKTIF))"

echo "== 5. Sekolah terakhir tidak boleh dihapus =="
SISA=$(sql "SELECT id FROM sekolah WHERE aktif=1 ORDER BY id LIMIT 1")
JUMLAHSEK=$(sql "SELECT COUNT(*) FROM sekolah")
# Nonaktifkan & hapus sekolah lain supaya hanya tersisa satu sekolah aktif.
for X in $(sql "SELECT id FROM sekolah WHERE aktif=1 AND id<>$SISA"); do
  T=$(csrf "$JS" akun.php)
  curl -s -b "$JS" -c "$JS" -o /dev/null -d "csrf=$T" -d 'aksi=hapus' -d "id=$X" -d 'konfirmasi=HAPUS' "$BASE/sekolah.php"
done
cek "tinggal satu sekolah aktif" "$(sql "SELECT COUNT(*) FROM sekolah WHERE aktif=1")" "1"
KONF=$(curl -s -b "$JS" -c "$JS" "$BASE/sekolah.php?hapus=$SISA")
cari "$KONF" "tidak dapat dihapus" "kartu konfirmasi menolak menghapus sekolah terakhir"
tidakk "$KONF" 'name="konfirmasi"' "form konfirmasi tidak dicetak untuk sekolah terakhir"
T=$(csrf "$JS" "sekolah.php?hapus=$SISA")
HAL=$(curl -s -L -b "$JS" -c "$JS" -d "csrf=$T" -d 'aksi=hapus' -d "id=$SISA" -d 'konfirmasi=HAPUS' "$BASE/sekolah.php")
cari "$HAL" "Sekolah terakhir tidak boleh dihapus" "server menolak hapus sekolah terakhir"
cek "sekolah terakhir tetap ada" "$(sql "SELECT COUNT(*) FROM sekolah WHERE id=$SISA")" "1"

echo "== 6. Id asing dijawab pesan ramah =="
KODE=$(curl -s -b "$JS" -c "$JS" -o /dev/null -w '%{http_code}' "$BASE/sekolah.php?hapus=999999")
cek "?hapus=999999 dialihkan (302), bukan 500" "$KODE" "302"
HAL=$(curl -s -b "$JS" -c "$JS" "$BASE/sekolah.php")
cari "$HAL" "tidak ditemukan" "pesan 'tidak ditemukan' tampil untuk id asing"

echo "== 7. Konteks Super Admin dilepas bila sekolahnya dihapus =="
T=$(csrf "$JS" akun.php)
curl -s -b "$JS" -c "$JS" -o /dev/null -d "csrf=$T" -d 'aksi=tambah' --data-urlencode 'nama=Sekolah Konteks Hapus' \
  -d 'jenjang=SD/MI' --data-urlencode 'kepala=Kepala Konteks' -d 'kkm=70' "$BASE/sekolah.php"
SK=$(sql "SELECT id FROM sekolah WHERE nama='Sekolah Konteks Hapus'")
cek "sekolah konteks dibuat" "$([ -n "$SK" ] && echo ada)" "ada"
curl -s -b "$JS" -c "$JS" -o /dev/null "$BASE/sekolah.php?masuk=$SK"
HAL=$(curl -s -b "$JS" -c "$JS" "$BASE/index.php")
cari "$HAL" "Sekolah Konteks Hapus" "Super Admin sudah masuk konteks sekolah itu"
T=$(csrf "$JS" akun.php)
curl -s -b "$JS" -c "$JS" -o /dev/null -d "csrf=$T" -d 'aksi=hapus' -d "id=$SK" -d 'konfirmasi=HAPUS' "$BASE/sekolah.php"
HAL=$(curl -s -b "$JS" -c "$JS" "$BASE/index.php")
cari "$HAL" "Ringkasan Lintas Sekolah" "konteks otomatis dilepas (kembali ke Ringkasan Lintas Sekolah)"
cari "$HAL" "dihapus permanen" "pesan penghapusan tampil pada halaman utama Super Admin"
DAFTAR=$(curl -s -b "$JS" -c "$JS" "$BASE/sekolah.php")   # permintaan GET baru: flash sudah terpakai
tidakk "$DAFTAR" "Sekolah Konteks Hapus" "nama sekolah terhapus tidak tersisa di daftar sekolah"

echo "== 8. Hak akses & CSRF =="
JOP=/tmp/cbt_hapus_op.jar
JGR=/tmp/cbt_hapus_guru.jar
SISA=$(sql "SELECT id FROM sekolah WHERE aktif=1 ORDER BY id LIMIT 1")
KODE=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/sekolah.php?hapus=$SISA")
cek "belum masuk: dialihkan (302)" "$KODE" "302"
# Operator & guru dibuat pada sekolah yang masih ada (sekolah terakhir).
masuk "$JOP" operator1 sandi12345
KODE=$(curl -s -b "$JOP" -o /dev/null -w '%{http_code}' "$BASE/sekolah.php?hapus=$SISA")
cek "operator tidak boleh membuka kartu hapus (403)" "$KODE" "403"
# Akun guru bawaan fixture (ahmad) ada pada sekolah yang tersisa.
masuk "$JGR" ahmad guru123
KODE=$(curl -s -b "$JGR" -o /dev/null -w '%{http_code}' "$BASE/sekolah.php?hapus=$SISA")
cek "guru tidak boleh membuka kartu hapus (403)" "$KODE" "403"
T=$(csrf "$JS" akun.php)
KODE=$(curl -s -b "$JS" -c "$JS" -o /dev/null -w '%{http_code}' -d 'aksi=hapus' -d "id=$SISA" -d 'konfirmasi=HAPUS' "$BASE/sekolah.php")
cek "tanpa CSRF 419 (sekolah tidak terhapus)" "$KODE" "419"
cek "sekolah terakhir tetap ada setelah percobaan tanpa CSRF" "$(sql "SELECT COUNT(*) FROM sekolah WHERE id=$SISA")" "1"

echo "== 9. Kerapian & integritas =="
HAL=$(curl -s -b "$JS" -c "$JS" "$BASE/sekolah.php")
tidakk "$HAL" 'href="/' "tidak ada tautan absolut (dipasang di subpath)"
cari "$HAL" "assets/style.css?v=" "aset CSS memakai pengusir cache"
KONF=$(curl -s -b "$JS" -c "$JS" "$BASE/sekolah.php?hapus=$SISA")
cari "$KONF" "card-hapus" "gaya kartu hapus dipakai pada halaman konfirmasi"
CSS=$(curl -s "$BASE/assets/style.css")
cari "$CSS" ".card-hapus" "gaya .card-hapus ada di assets/style.css"
cek "integritas basis data" "$(sql 'PRAGMA integrity_check')" "ok"
cek "pelanggaran foreign key" "$(sql 'SELECT COUNT(*) FROM pragma_foreign_key_check')" "0"
cek "tidak ada baris guru yatim (sekolah sudah tidak ada)" \
  "$(sql 'SELECT COUNT(*) FROM guru WHERE sekolah_id NOT IN (SELECT id FROM sekolah)')" "0"
cek "tidak ada baris ujian yatim" \
  "$(sql 'SELECT COUNT(*) FROM ujian WHERE sekolah_id NOT IN (SELECT id FROM sekolah)')" "0"
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
