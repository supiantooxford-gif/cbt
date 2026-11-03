#!/usr/bin/env bash
# =====================================================================================
# Uji v1.14.0 — HAPUS LANDING PAGE & PENDAFTARAN + AKUN GURU MEMAKAI AKUN e-GURU.
#
# Permintaan pemilik:
#   "Hapus halaman landing page, hapus halaman pendaftaran (termasuk menu pendaftaran sekolah dan
#    menu halaman depan (landing) yang ada di halaman super admin) karena semua data akan ditarik
#    dari aplikasi e-Guru. Yang ditampilkan hanya halaman login yang responsive. Selain itu pastikan
#    akun guru yang ditarik dari e-Guru user dan passwordnya tetap sama (memakai akun e-Guru,
#    kecuali nanti guru menggantinya sendiri di akun CBT)."
#
# Yang diuji:
#   A. Halaman & berkas landing/pendaftaran benar-benar hilang (404) dan tidak ada rujukannya.
#   B. Menu Super Admin "Pendaftaran Sekolah" & "Halaman Depan (Landing)" hilang; menu lain utuh.
#   C. Halaman masuk HANYA formulir masuk (tanpa bagian landing/pendaftaran) + tetap berfungsi.
#   D. Akun guru hasil tarik e-GURU memakai NAMA PENGGUNA & KATA SANDI e-GURU (hash disalin),
#      guru bisa benar-benar MASUK ke CBT dengan akun e-GURU-nya.
#   E. Sandi milik guru yang sudah DIGANTI sendiri di CBT tidak ditimpa saat menarik ulang;
#      akun lama yang masih bersandi awal guru123 ikut disamakan dengan akun e-GURU.
#   F. Data e-GURU tetap tidak pernah ditulis (dibaca saja).
#   G. Kerapian: tanpa error PHP di log, integritas & FK bersih.
#
# Pemakaian:  bash tests_cbt_tanpa_landing_daftar.sh [BASE_URL]
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
  KERJA="/tmp/cbt_tanpa_landing_$$"
  rm -rf "$KERJA"; mkdir -p "$KERJA"
  cp -r "$DIR"/. "$KERJA"/
  rm -f "$KERJA"/data/cbt.sqlite* "$KERJA"/data/.schema_ok
  mkdir -p "$KERJA/data/sessions"; chmod 777 "$KERJA/data" "$KERJA/data/sessions" 2>/dev/null || true
  CBT_DB_FILE="$KERJA/data/cbt.sqlite" php -d extension=pdo -d extension=pdo_sqlite "$KERJA/fixture_cbt.php" >/dev/null
  # Basis data e-GURU tiruan (berisi AKUN GURU dengan kata sandi tertentu).
  EGDB="$KERJA/data/eguru_uji.sqlite"
  rm -f "$EGDB"
  EGURU_DB_FILE="$EGDB" php -d extension=pdo -d extension=pdo_sqlite "$KERJA/fixture_eguru_uji.php" >/dev/null
  LOG="/tmp/cbt_tanpa_landing_srv_$$.log"
  EGURU_DB_FILE="$EGDB" CBT_DB_FILE="$KERJA/data/cbt.sqlite" \
    php -d extension=pdo -d extension=pdo_sqlite -S "127.0.0.1:$PORT" -t "$KERJA" > "$LOG" 2>&1 &
  SRV_PID=$!
  for _i in $(seq 1 25); do
    curl -s -o /dev/null "http://127.0.0.1:$PORT/login.php" && break
    sleep 0.4
  done
  DB="$KERJA/data/cbt.sqlite"
  export DB EGDB
  bersihkan(){ [ -n "$SRV_PID" ] && kill "$SRV_PID" 2>/dev/null; rm -rf "$KERJA"; }
  trap bersihkan EXIT
else
  DB="${CBT_DB_DEFAULT:-$DIR/data/cbt.sqlite}"
  EGDB="${EGURU_DB_FILE:-$DIR/data/eguru_uji.sqlite}"
fi

ok(){ LULUS=$((LULUS+1)); printf '  ok   %s\n' "$1"; }
no(){ GAGAL=$((GAGAL+1)); printf '  GAGAL %s\n' "$1"; }
cek(){ if [ "$2" = "$3" ]; then ok "$1"; else no "$1 (dapat: $2, harap: $3)"; fi; }
cari(){ if printf '%s' "$1" | grep -q -- "$2"; then ok "$3"; else no "$3"; fi; }
tidakk(){ if printf '%s' "$1" | grep -q -- "$2"; then no "$3"; else ok "$3"; fi; }
sql(){ php -d extension=pdo -d extension=pdo_sqlite -r '
$p=new PDO("sqlite:'"$DB"'"); $q=$p->query("'"$1"'"); echo ($q?$q->fetchColumn():"0");'; }
isi(){ php -d extension=pdo -d extension=pdo_sqlite -r '
$p=new PDO("sqlite:'"$DB"'"); $p->exec("PRAGMA foreign_keys=OFF"); $p->exec("'"$1"'");'; }
csrf(){
  local hal; hal=$(curl -s -b "$1" -c "$1" "$BASE/$2")
  local t; t=$(printf '%s' "$hal" | grep -o 'name="csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
  printf '%s' "$t"
}
masuk(){
  rm -f "$1"
  local t; t=$(csrf "$1" login.php)
  curl -s -b "$1" -c "$1" -o /tmp/cbt_tl_login.html -d "csrf=$t" -d "username=$2" -d "password=$3" "$BASE/login.php"
}
# Tetapkan sandi akun (hash dibuat di dalam PHP — jangan menyisipkan hash ke dalam SQL bash).
setSandi(){ DB="$DB" U="$1" S="$2" php -d extension=pdo -d extension=pdo_sqlite -r '
$p=new PDO("sqlite:".getenv("DB"));
$st=$p->prepare("UPDATE users SET password = ? WHERE username = ?");
$st->execute([password_hash(getenv("S"), PASSWORD_DEFAULT), getenv("U")]);'; }
# Apakah kata sandi cocok dengan hash akun pada CBT?
sandiCocok(){ DB="$DB" U="$1" S="$2" php -d extension=pdo -d extension=pdo_sqlite -r '
$p=new PDO("sqlite:".getenv("DB"));
$st=$p->prepare("SELECT password FROM users WHERE username = ?"); $st->execute([getenv("U")]);
$h=(string) $st->fetchColumn(); echo $h !== "" && password_verify(getenv("S"), $h) ? "ya" : "tidak";'; }

echo "== A. Halaman landing & pendaftaran benar-benar DIHAPUS =="
for hal in landing.php daftar.php pendaftaran.php inc/landing.php; do
  H=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/$hal")
  if [ "$hal" = "inc/landing.php" ]; then
    cek "berkas $hal tidak dapat diakses (404/403)" "$([ "$H" = "404" ] || [ "$H" = "403" ] && echo hilang)" "hilang"
  else
    cek "halaman $hal sudah tidak ada (404)" "$H" "404"
  fi
done
test -f "$DIR/landing.php" && no "berkas landing.php masih ada di folder aplikasi" || ok "berkas landing.php tidak ada di folder aplikasi"
test -f "$DIR/daftar.php" && no "berkas daftar.php masih ada di folder aplikasi" || ok "berkas daftar.php tidak ada di folder aplikasi"
test -f "$DIR/pendaftaran.php" && no "berkas pendaftaran.php masih ada" || ok "berkas pendaftaran.php tidak ada di folder aplikasi"
test -f "$DIR/inc/landing.php" && no "modul inc/landing.php masih ada" || ok "modul inc/landing.php tidak ada di folder aplikasi"

echo "== B. Menu Super Admin: pendaftaran & landing hilang, menu lain utuh =="
JS=/tmp/cbt_tl_super.jar
masuk "$JS" admin admin123
# (POST login hanya membalas 302, jadi keberhasilan masuk diperiksa dari halaman dashboard.)
casek=$(curl -s -b "$JS" "$BASE/index.php")
cari "$casek" "Ringkasan Lintas Sekolah" "Super Admin berhasil masuk"
MENU=$(curl -s -b "$JS" -c "$JS" "$BASE/sekolah.php")
cari "$MENU" 'Kelola Sekolah' "menu Kelola Sekolah tetap ada"
cari "$MENU" 'Tarik Data dari e-GURU' "menu Tarik Data dari e-GURU tetap ada"
cari "$MENU" 'Akun Super Admin' "menu Akun Super Admin tetap ada"
cari "$MENU" 'Biaya &amp; Aktivasi' "menu Biaya & Aktivasi tetap ada"
tidakk "$MENU" 'pendaftaran.php' "tautan menu Pendaftaran Sekolah sudah tidak ada"
tidakk "$MENU" 'landing.php' "tautan menu Halaman Depan (Landing) sudah tidak ada"
tidakk "$MENU" 'Halaman Depan' "label menu Halaman Depan sudah tidak ada"
tidakk "$MENU" 'Pendaftaran Sekolah' "label menu Pendaftaran Sekolah sudah tidak ada"
DASH=$(curl -s -b "$JS" -c "$JS" "$BASE/index.php")
tidakk "$DASH" 'pendaftaran.php' "tombol Pendaftaran pada ringkasan lintas sekolah sudah tidak ada"
cari "$DASH" 'Daftar Sekolah/Madrasah' "tabel ringkasan sekolah tetap ada"

echo "== C. Halaman masuk hanya formulir masuk =="
MASUK=$(curl -s "$BASE/login.php")
cari "$MASUK" 'id="username"' "kolom nama pengguna ada"
cari "$MASUK" 'id="password"' "kolom kata sandi ada"
cari "$MASUK" 'name="csrf"' "token CSRF ada"
cari "$MASUK" 'masuk-kartu' "kartu masuk tampil"
cari "$MASUK" 'Selamat Datang' "kartu masuk berjudul Selamat Datang (v1.15.0)"
cari "$MASUK" 'e-GURU' "keterangan akun memakai e-GURU tampil"
for pola in 'lp-nav' 'id="beranda"' 'id="fitur"' 'id="keunggulan"' 'id="testimoni"' 'id="kontak"' 'lpSlider' 'daftar.php'; do
  tidakk "$MASUK" "$pola" "halaman masuk tidak memuat bagian lama ($pola)"
done
tidakk "$MASUK" 'Daftarkan Sekolah' "tidak ada tautan pendaftaran sekolah"
tidakk "$MASUK" 'name="pesan"' "tidak ada formulir pesan kontak pengunjung"
cek "kunjungan root dialihkan ke halaman masuk (302)" \
  "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/")" "302"

echo "== D. Akun guru hasil tarik memakai AKUN e-GURU (nama pengguna & sandi sama) =="
# Tarik data e-GURU ke sekolah 1 lewat antarmuka.
curl -s -b "$JS" -c "$JS" -o /dev/null "$BASE/tarik_eguru.php?sekolah=1"
T=$(csrf "$JS" tarik_eguru.php)
curl -s -b "$JS" -c "$JS" -o /dev/null -d "csrf=$T" "$BASE/tarik_eguru.php"
cek "data guru tertarik ke CBT" "$([ "$(sql 'SELECT COUNT(*) FROM guru')" -ge 4 ] && echo ada)" "ada"
GUNAME=$(sql "SELECT username FROM users WHERE guru_id = (SELECT id FROM guru WHERE nip = 'G-001')")
cek "nama pengguna guru = nama pengguna e-GURU (ahmad)" "$GUNAME" "ahmad"
cek "kata sandi guru = kata sandi e-GURU" "$(sandiCocok ahmad RahasiaAhmad1)" "ya"
cek "bukan sandi awal guru123" "$(sandiCocok ahmad guru123)" "tidak"
cek "nama pengguna bertitik dipertahankan (rina.m)" \
  "$(sql "SELECT COUNT(*) FROM users WHERE username = 'rina.m'")" "1"
cek "kata sandi guru rina = kata sandi e-GURU" "$(sandiCocok rina.m SandiRina333)" "ya"
cek "kata sandi guru yusup = kata sandi e-GURU" "$(sandiCocok yusup.abaidata SandiYusup22)" "ya"
cek "akun BUKAN guru tidak dipakai (kepala.uji)" "$(sql "SELECT COUNT(*) FROM users WHERE username = 'kepala.uji'")" "0"

# Guru BENAR-BENAR bisa masuk memakai akun e-GURU-nya.
JG=/tmp/cbt_tl_guru.jar
masuk "$JG" ahmad RahasiaAhmad1
KODE=$(curl -s -b "$JG" -o /tmp/cbt_tl_dash.html -w '%{http_code}' "$BASE/index.php")
cek "guru masuk memakai akun e-GURU (200)" "$KODE" "200"
cari "$(cat /tmp/cbt_tl_dash.html)" "Ujian Terbaru" "guru melihat dashboard sekolahnya"
cek "guru TIDAK bisa masuk dengan sandi awal guru123" \
  "$(masuk /tmp/cbt_tl_guru2.jar ahmad guru123; curl -s -b /tmp/cbt_tl_guru2.jar -o /dev/null -w '%{http_code}' "$BASE/index.php")" \
  "302"

echo "== E. Sandi yang sudah diganti guru TIDAK ditimpa saat menarik ulang =="
# a. Akun lama (meniru hasil penarikan versi lama) yang masih bersandi awal guru123 → pada
#    penarikan berikutnya DISAMAKAN dengan akun e-GURU.
setSandi rina.m guru123
cek "prasyarat: akun rina.m bersandi awal guru123" "$(sandiCocok rina.m guru123)" "ya"
T=$(csrf "$JS" tarik_eguru.php)
curl -s -b "$JS" -c "$JS" -o /dev/null -d "csrf=$T" "$BASE/tarik_eguru.php"
cek "akun bersandi awal guru123 disamakan dengan akun e-GURU setelah tarik ulang" \
  "$(sandiCocok rina.m SandiRina333)" "ya"
cek "nama pengguna bertitik tetap dipertahankan setelah tarik ulang" \
  "$(sql "SELECT COUNT(*) FROM users WHERE username = 'rina.m'")" "1"

# b. Guru benar-benar mengganti sandinya sendiri lewat menu Akun Saya di CBT, lalu tarik ulang:
#    sandi pilihannya TIDAK boleh tertimpa.
HAL=$(curl -s -b "$JG" -c "$JG" "$BASE/akun.php")
TG=$(printf '%s' "$HAL" | grep -o 'name="csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -b "$JG" -c "$JG" -o /dev/null -d "csrf=$TG" -d 'aksi=sandi' -d 'lama=RahasiaAhmad1' \
  -d 'baru=SandiPilihanGuru77' -d 'ulang=SandiPilihanGuru77' "$BASE/akun.php"
cek "guru berhasil mengganti sandinya sendiri di CBT" "$(sandiCocok ahmad SandiPilihanGuru77)" "ya"
T=$(csrf "$JS" tarik_eguru.php)
curl -s -b "$JS" -c "$JS" -o /dev/null -d "csrf=$T" "$BASE/tarik_eguru.php"
cek "sandi pilihan guru TIDAK ditimpa tarik ulang" "$(sandiCocok ahmad SandiPilihanGuru77)" "ya"
cek "sandi e-GURU tidak kembali berlaku" "$(sandiCocok ahmad RahasiaAhmad1)" "tidak"
cek "nama pengguna tidak berubah setelah ganti sandi" \
  "$(sql "SELECT username FROM users WHERE guru_id = (SELECT id FROM guru WHERE nip = 'G-001')")" "ahmad"

echo "== F. Basis data e-GURU tidak pernah ditulis =="
MD5_EG=$(md5sum "$EGDB" | cut -d' ' -f1)
T=$(csrf "$JS" tarik_eguru.php)
curl -s -b "$JS" -c "$JS" -o /dev/null -d "csrf=$T" "$BASE/tarik_eguru.php"
cek "md5 basis data e-GURU tidak berubah" "$(md5sum "$EGDB" | cut -d' ' -f1)" "$MD5_EG"

echo "== G. Hak akses & kerapian =="
cek "tanpa login: halaman kelola sekolah dialihkan (302)" \
  "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/sekolah.php")" "302"
cek "operator tidak boleh membuka Kelola Sekolah (403)" \
  "$([ "$(curl -s -b "$JG" -o /dev/null -w '%{http_code}' "$BASE/sekolah.php")" = "403" ] && echo 403)" "403"
tidakk "$MASUK" 'href="/' "halaman masuk tanpa tautan absolut"
cari "$MASUK" "assets/style.css?v=" "aset CSS memakai pengusir cache"
cari "$MASUK" "Versi\|v$(sed -n "s/^define('APP_VERSION', '\(.*\)');/\1/p" "$DIR/inc/config.php")" "versi aplikasi tercetak"
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
