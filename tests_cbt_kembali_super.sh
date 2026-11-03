#!/usr/bin/env bash
# =====================================================================================
# Uji fitur "KEMBALI KE HALAMAN UTAMA SUPER ADMIN".
#
# Permintaan pemilik: "Tambahkan fitur kembali ke halaman utama super admin. Saat ini
# fitur tersebut belum ada" — sebelumnya Super Admin yang sudah MASUK ke konteks satu
# sekolah tidak punya jalan kembali ke tampilan lintas sekolah dari dalam aplikasi.
#
# Yang diuji:
#   1. Super Admin TANPA konteks tidak melihat tombol itu (tidak perlu).
#   2. Super Admin DALAM konteks melihat banner + tombol (bilah atas & banner) di SEMUA
#      halaman ber-konteks, dan tautannya `index.php?keluar=1`.
#   3. Menekan tautan itu melepas konteks dan membuka RINGKASAN LINTAS SEKOLAH.
#   4. Peran lain (operator/guru/siswa) tidak melihat tombol & tidak terpengaruh.
#   5. Data sekolah tidak berubah sama sekali (fitur ini hanya melepas konteks sesi).
#   6. Tautan lama `sekolah.php?keluar=1` tetap bekerja.
#
# Pemakaian:  bash tests_cbt_kembali_super.sh [BASE_URL]
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
  KERJA="/tmp/cbt_kembali_$$"
  rm -rf "$KERJA"; mkdir -p "$KERJA"
  cp -r "$DIR"/. "$KERJA"/
  rm -f "$KERJA"/data/cbt.sqlite* "$KERJA"/data/.schema_ok
  mkdir -p "$KERJA/data/sessions"; chmod 777 "$KERJA/data" "$KERJA/data/sessions" 2>/dev/null || true
  CBT_DB_FILE="$KERJA/data/cbt.sqlite" php -d extension=pdo -d extension=pdo_sqlite "$KERJA/fixture_cbt.php" >/dev/null
  php -d extension=pdo -d extension=pdo_sqlite -S "127.0.0.1:$PORT" -t "$KERJA" > "/tmp/cbt_kembali_srv_$$.log" 2>&1 &
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

TOMBOL='index.php?keluar=1'
LABEL_BANNER='Kembali ke Halaman Utama Super Admin'
LABEL_TOPBAR='Kembali ke Super Admin'

SID=$(sql "SELECT id FROM sekolah ORDER BY id LIMIT 1")

echo "== 1. Halaman utama Super Admin (TANPA konteks sekolah) =="
JS=/tmp/cbt_ks.$RANDOM.jar
masuk "$JS" admin admin123
HOME=$(curl -s -b "$JS" -c "$JS" "$BASE/index.php")
cari2 "$HOME" 'Ringkasan Lintas Sekolah' "halaman utama Super Admin = Ringkasan Lintas Sekolah"
tidakk "$HOME" "$TOMBOL" "tanpa konteks: tombol kembali tidak ditampilkan (tidak diperlukan)"
tidakk "$HOME" "$LABEL_BANNER" "tanpa konteks: banner konteks tidak ditampilkan"

echo "== 2. Super Admin MASUK konteks sekolah (sekolah.php?masuk=ID) =="
curl -s -b "$JS" -c "$JS" -o /dev/null "$BASE/sekolah.php?masuk=$SID"
DASH=$(curl -s -b "$JS" -c "$JS" "$BASE/index.php")
cari2 "$DASH" 'Kembali ke Super Admin' "dashboard dalam konteks menampilkan tombol kembali"
cek "tombol pada bilah atas tepat satu" "$(hitung "$DASH" 'btn-kembali-top')" "1"
cek "banner konteks Super Admin tampil tepat satu" "$(hitung "$DASH" "alert-konteks")" "1"
cek "jumlah tombol kembali (bilah atas + banner) = 2" "$(hitung "$DASH" 'btn-kembali-super')" "2"
cari2 "$DASH" "$LABEL_BANNER" "banner memuat label 'Kembali ke Halaman Utama Super Admin'"
cari2 "$DASH" "$LABEL_TOPBAR" "bilah atas memuat label 'Kembali ke Super Admin'"
cek "alamat tombol = index.php?keluar=1" "$(hitung "$DASH" "href=\"$TOMBOL\"")" "2"
cari2 "$DASH" 'Anda sedang' "banner menerangkan sedang mengelola sekolah"
NAMA=$(sql "SELECT nama FROM sekolah WHERE id=$SID")
cari2 "$DASH" "$NAMA" "banner menyebut nama sekolah yang sedang dikelola"

echo "== 3. Tombol tersedia di halaman lain selama berada dalam konteks =="
for hal in guru.php siswa.php kelas.php pengaturan.php hasil.php bank_soal.php jadwal_token.php; do
  HAL=$(curl -s -b "$JS" -c "$JS" "$BASE/$hal")
  if printf '%s' "$HAL" | grep -q -- "$TOMBOL"; then ok "tombol kembali ada di $hal"; else no "tombol kembali TIDAK ada di $hal"; fi
done
HAL=$(curl -s -b "$JS" -c "$JS" "$BASE/aktivasi.php")
cari2 "$HAL" "$TOMBOL" "tombol kembali ada di halaman Status & Aktivasi"

echo "== 4. Menekan tombol = melepas konteks & membuka halaman utama Super Admin =="
# Catat sidik data sekolah SEBELUM keluar konteks: fitur ini hanya melepas konteks sesi.
SIDIK_SEBELUM=$(sql "SELECT COUNT(*)||'-'||COALESCE(SUM(id),0) FROM guru WHERE sekolah_id=$SID")
KODE=$(curl -s -b "$JS" -c "$JS" -o /tmp/cbt_ks.out -w '%{http_code}' "$BASE/$TOMBOL")
cek "permintaan index.php?keluar=1 dijawab 302 (dialihkan)" "$KODE" "302"
HAL=$(curl -s -b "$JS" -c "$JS" "$BASE/index.php")
cari2 "$HAL" 'Ringkasan Lintas Sekolah' "setelah menekan tombol: kembali ke Ringkasan Lintas Sekolah"
cari2 "$HAL" 'Kembali ke halaman utama Super Admin' "pesan konfirmasi kembali tampil"
tidakk "$HAL" "$TOMBOL" "setelah keluar konteks tombol tidak tampil lagi"
tidakk "$HAL" 'alert-konteks' "banner konteks hilang setelah keluar"
cari2 "$HAL" 'Kelola Sekolah' "tombol Kelola Sekolah tersedia lagi pada halaman utama"
SIDIK_SESUDAH=$(sql "SELECT COUNT(*)||'-'||COALESCE(SUM(id),0) FROM guru WHERE sekolah_id=$SID")
cek "data sekolah tidak berubah (hanya konteks sesi yang dilepas)" "$SIDIK_SESUDAH" "$SIDIK_SEBELUM"
cek "konteks sekolah benar-benar dilepas pada basis data" "$(sql "SELECT COUNT(*) FROM sekolah WHERE id=$SID")" "1"

echo "== 5. Konteks benar-benar hilang: halaman sekolah meminta memilih sekolah dulu =="
KODE=$(curl -s -b "$JS" -c "$JS" -o /dev/null -w '%{http_code}' "$BASE/guru.php")
cek "guru.php tanpa konteks dialihkan (302)" "$KODE" "302"
HAL=$(curl -s -b "$JS" -c "$JS" "$BASE/sekolah.php")
cari2 "$HAL" 'Sekolah/Madrasah Aktif' "sekolah.php menampilkan daftar sekolah (pemilih konteks)"
cari2 "$HAL" "$NAMA" "sekolah yang tadi dikelola tetap ada pada daftar (data utuh)"

echo "== 6. Peran lain tidak melihat tombol & tidak terpengaruh =="
JOP=/tmp/cbt_ks_op.$RANDOM.jar
masuk "$JOP" operator1 sandi12345
OPHOME=$(curl -s -b "$JOP" -c "$JOP" "$BASE/index.php")
tidakk "$OPHOME" "$TOMBOL" "operator: tombol kembali Super Admin tidak tampil"
tidakk "$OPHOME" "$LABEL_BANNER" "operator: banner konteks Super Admin tidak tampil"
HAL=$(curl -s -b "$JOP" -c "$JOP" "$BASE/index.php?keluar=1")
cari2 "$HAL" 'Dashboard' "operator: index.php?keluar=1 tetap menampilkan dashboard sekolahnya"
tidakk "$HAL" 'Ringkasan Lintas Sekolah' "operator: tidak dialihkan ke tampilan lintas sekolah"
cari2 "$HAL" "$NAMA" "operator: tetap berada di sekolahnya sendiri"

echo "== 7. Tautan lama sekolah.php?keluar=1 tetap bekerja =="
curl -s -b "$JS" -c "$JS" -o /dev/null "$BASE/sekolah.php?masuk=$SID"
KODE=$(curl -s -b "$JS" -c "$JS" -o /dev/null -w '%{http_code}' "$BASE/sekolah.php?keluar=1")
cek "sekolah.php?keluar=1 dijawab 302 (dialihkan)" "$KODE" "302"
HAL=$(curl -s -b "$JS" -c "$JS" "$BASE/sekolah.php")
cari2 "$HAL" 'Kelola Sekolah' "sekolah.php?keluar=1 tetap membuka Kelola Sekolah"
HAL=$(curl -s -b "$JS" -c "$JS" "$BASE/index.php")
cari2 "$HAL" 'Ringkasan Lintas Sekolah' "setelah sekolah.php?keluar=1 konteks juga sudah lepas"

echo "== 8. Idempoten: menekan tombol saat sudah di halaman utama =="
KODE=$(curl -s -b "$JS" -c "$JS" -o /dev/null -w '%{http_code}' "$BASE/$TOMBOL")
cek "index.php?keluar=1 saat tanpa konteks tetap 302" "$KODE" "302"
HAL=$(curl -s -b "$JS" -c "$JS" "$BASE/index.php")
cari2 "$HAL" 'Ringkasan Lintas Sekolah' "halaman utama Super Admin tetap terbuka normal"

echo "== 9. Kerapian tampilan & aset =="
CSS=$(curl -s "$BASE/assets/style.css")
cari2 "$CSS" '.alert-konteks' "gaya banner konteks ada di assets/style.css"
cari2 "$CSS" '.btn-kembali-super' "gaya tombol kembali ada di assets/style.css"
cari2 "$DASH" 'assets/style.css?v=' "aset CSS memakai pengusir cache (?v=versi)"
tidakk "$DASH" 'href="/' "tidak ada tautan absolut (aplikasi dipasang di subpath)"
cari2 "$DASH" 'Kembali ke Super Admin' "label tombol tidak kosong (mudah dipahami pengguna)"

echo
echo "===== HASIL: $LULUS lulus, $GAGAL gagal ====="
[ "$GAGAL" -eq 0 ] || exit 1
