#!/usr/bin/env bash
# =====================================================================================
# Uji fitur v1.5.0:
#   A. NAMA APLIKASI: halaman masuk menampilkan "Computer-Based Test (CBT)"
#      (bukan lagi "CBT Sekolah") — termasuk judul <title>, footer, dan sidebar.
#   B. LOGO APLIKASI: logo bawaan SVG dipakai pada halaman masuk / pendaftaran / sidebar,
#      dan Super Admin dapat MENGGANTINYA dengan berkas unggahan (media proxy) lalu
#      mengembalikannya ke logo bawaan.
#
# Mode MANDIRI: salinan aplikasi + basis data uji di /tmp (data aplikasi tidak disentuh).
# =====================================================================================
set -u
BASE="${1:-}"
DIR="$(cd "$(dirname "$0")" && pwd)"
LULUS=0; GAGAL=0

SRV_PID=""
if [ -z "$BASE" ]; then
  PORT=""
  for _p in $(seq 8640 8679); do
    if ! (exec 3<>"/dev/tcp/127.0.0.1/$_p") 2>/dev/null; then PORT=$_p; break; fi
    exec 3<&- 2>/dev/null || true
  done
  [ -n "$PORT" ] || { echo "Tidak ada port bebas"; exit 1; }
  BASE="http://127.0.0.1:$PORT"
  KERJA="/tmp/cbt_logo_$$"
  rm -rf "$KERJA"; mkdir -p "$KERJA"
  cp -r "$DIR"/. "$KERJA"/
  rm -f "$KERJA"/data/cbt.sqlite* "$KERJA"/data/.schema_ok
  mkdir -p "$KERJA/data/sessions"; chmod 777 "$KERJA/data" "$KERJA/data/sessions" 2>/dev/null || true
  CBT_DB_FILE="$KERJA/data/cbt.sqlite" php -d extension=pdo -d extension=pdo_sqlite "$KERJA/fixture_cbt.php" >/dev/null
  # sandi Super Admin ditetapkan pada SALINAN agar uji dapat masuk
  CBT_DB_FILE="$KERJA/data/cbt.sqlite" php -d extension=pdo -d extension=pdo_sqlite -r '
  require "'"$KERJA"'/inc/init.php";
  q("UPDATE users SET password = ? WHERE username = ?", [password_hash("uji_logo_123", PASSWORD_DEFAULT), "admin"]);' >/dev/null
  php -d extension=pdo -d extension=pdo_sqlite -S "127.0.0.1:$PORT" -t "$KERJA" > "/tmp/cbt_logo_srv_$$.log" 2>&1 &
  SRV_PID=$!
  for _i in $(seq 1 25); do curl -s -o /dev/null "$BASE/login.php" && break; sleep 0.4; done
  DB="$KERJA/data/cbt.sqlite"
  bersihkan(){ [ -n "$SRV_PID" ] && kill "$SRV_PID" 2>/dev/null; rm -rf "$KERJA"; }
  trap bersihkan EXIT
else
  DB="${CBT_DB:-$DIR/data/cbt.sqlite}"
fi

ok(){ LULUS=$((LULUS+1)); printf '  ok   %s\n' "$1"; }
no(){ GAGAL=$((GAGAL+1)); printf '  GAGAL %s\n' "$1"; }
cek(){ if [ "$2" = "$3" ]; then ok "$1"; else no "$1 (dapat: $2, harap: $3)"; fi; }
cari(){ if printf '%s' "$1" | grep -q -- "$2"; then ok "$3"; else no "$3"; fi; }
tidakk(){ if printf '%s' "$1" | grep -q -- "$2"; then no "$3"; else ok "$3"; fi; }
sql(){ php -d extension=pdo -d extension=pdo_sqlite -r '
$p=new PDO("sqlite:'"$DB"'"); $q=$p->query("'"$1"'"); echo ($q?$q->fetchColumn():"0");'; }
set_setting(){ CBT_DB_FILE="$DB" php -d extension=pdo -d extension=pdo_sqlite -r '
require "'"$DIR"'/inc/init.php"; app_setting_set("app_logo", $argv[1]);' "$1"; }

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

echo "== A. Nama aplikasi pada halaman masuk =="
H=$(curl -s -o /tmp/cbt_logo_login.html -w '%{http_code}' "$BASE/login.php")
cek "halaman masuk (200)" "$H" "200"
LOGIN=$(cat /tmp/cbt_logo_login.html)
cari "$LOGIN" "<h1>Computer-Based Test (CBT)</h1>" "judul halaman masuk berbunyi Computer-Based Test (CBT)"
tidakk "$LOGIN" "CBT Sekolah" "nama lama 'CBT Sekolah' sudah tidak dipakai"
cari "$LOGIN" "<title>Masuk - Computer-Based Test (CBT)</title>" "judul jendela (title) ikut berubah"
cari "$LOGIN" "Computer-Based Test (CBT) v" "footer memakai nama baru"
# v1.14.0: halaman pendaftaran (`daftar.php`) DIHAPUS — cukup dipastikan benar-benar tidak ada.
cek "halaman pendaftaran sudah tidak ada (404)" \
  "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/daftar.php")" "404"

echo "== B. Logo aplikasi bawaan =="
cari "$LOGIN" 'src="assets/logo-cbt.svg"' "halaman masuk memakai logo bawaan (SVG)"
cek "berkas logo bawaan dapat diakses" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/assets/logo-cbt.svg")" "200"
cek "tipe berkas logo benar" "$(curl -s -o /dev/null -w '%{content_type}' "$BASE/assets/logo-cbt.svg")" "image/svg+xml"
tidakk "$LOGIN" '<div class="portal-logo">' "logo lama berupa ikon SVG inline sudah diganti gambar"

echo "== C. Halaman Logo Aplikasi (Super Admin) =="
JS=/tmp/cbt_logo_super.jar
masuk "$JS" admin uji_logo_123
H=$(curl -s -b "$JS" -o /tmp/cbt_logo_admin.html -w '%{http_code}' "$BASE/logo.php")
cek "halaman logo.php (200)" "$H" "200"
ADMIN=$(cat /tmp/cbt_logo_admin.html)
cari "$ADMIN" "Logo Aplikasi" "judul halaman tampil"
cari "$ADMIN" "logo bawaan" "keadaan awal ditandai logo bawaan"
cari "$ADMIN" "Unggah / Ganti Logo" "form unggah tersedia"
tidakk "$ADMIN" "Kembalikan Logo Bawaan" "tombol kembali-bawaan tersembunyi saat masih bawaan"
# hak akses: operator & guru ditolak, tamu dialihkan
JO=/tmp/cbt_logo_op.jar
masuk "$JO" operator1 sandi12345
cek "operator ditolak (403)" "$(curl -s -b "$JO" -o /dev/null -w '%{http_code}' "$BASE/logo.php")" "403"
JG=/tmp/cbt_logo_guru.jar
masuk "$JG" ahmad guru123
cek "guru ditolak (403)" "$(curl -s -b "$JG" -o /dev/null -w '%{http_code}' "$BASE/logo.php")" "403"
cek "belum masuk dialihkan (302)" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/logo.php")" "302"
# tanpa CSRF
cek "unggah tanpa CSRF ditolak (419)" \
  "$(curl -s -b "$JS" -o /dev/null -w '%{http_code}' -F 'aksi=unggah' -F 'logo=@/dev/null' "$BASE/upload_logo_app.php")" "419"

echo "== D. Ganti logo dengan berkas unggahan =="
python3 - <<'PY'
import struct, zlib
def png(w, h):
    def chunk(t, d):
        return struct.pack('>I', len(d)) + t + d + struct.pack('>I', zlib.crc32(t + d) & 0xffffffff)
    isi = b''.join(b'\x00' + bytes([(x * 5) % 256 for x in range(w * 3)]) for _ in range(h))
    return (b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', w, h, 8, 2, 0, 0, 0))
            + chunk(b'IDAT', zlib.compress(isi)) + chunk(b'IEND', b''))
open('/tmp/cbt_logo_baru.png', 'wb').write(png(300, 300))
print('PNG logo uji dibuat')
PY
# berkas bukan gambar harus ditolak
printf 'ini bukan gambar' > /tmp/cbt_logo_salah.txt
T=$(csrf "$JS" logo.php)
curl -s -L -b "$JS" -c "$JS" -o /tmp/cbt_logo_tolak.html \
  -F "csrf=$T" -F 'aksi=unggah' -F "logo=@/tmp/cbt_logo_salah.txt" "$BASE/upload_logo_app.php"
cari "$(cat /tmp/cbt_logo_tolak.html)" "Format gambar harus" "berkas bukan gambar ditolak dengan pesan"
cek "logo belum berubah setelah penolakan" "$(sql "SELECT COUNT(*) FROM app_pengaturan WHERE kunci='app_logo' AND nilai<>''")" "0"
# unggah gambar sah
T=$(csrf "$JS" logo.php)
curl -s -L -b "$JS" -c "$JS" -o /tmp/cbt_logo_upload.html \
  -F "csrf=$T" -F 'aksi=unggah' -F "logo=@/tmp/cbt_logo_baru.png" "$BASE/upload_logo_app.php"
cari "$(cat /tmp/cbt_logo_upload.html)" "berhasil diunggah\|logo unggahan" "unggahan logo dilaporkan berhasil"
URL=$(sql "SELECT COALESCE(nilai,'') FROM app_pengaturan WHERE kunci='app_logo'")
if [ -n "$URL" ]; then ok "URL logo tersimpan: $URL"; else no "URL logo tidak tersimpan"; fi
cari "$URL" "^https\?://" "logo disimpan sebagai URL (bukan berkas lokal)"
# halaman masuk & sidebar memakai logo baru
LOGIN2=$(curl -s "$BASE/login.php")
cari "$LOGIN2" "$URL" "halaman masuk memakai logo unggahan"
tidakk "$LOGIN2" 'src="assets/logo-cbt.svg"' "logo bawaan tidak dipakai lagi setelah diganti"
ADMIN2=$(curl -s -b "$JS" "$BASE/logo.php")
cari "$ADMIN2" "logo unggahan" "halaman logo menandai logo unggahan"
cari "$ADMIN2" "Kembalikan Logo Bawaan" "tombol kembali-bawaan muncul setelah logo diganti"

echo "== E. Kembalikan logo bawaan =="
T=$(csrf "$JS" logo.php)
curl -s -L -b "$JS" -c "$JS" -o /tmp/cbt_logo_hapus.html -d "csrf=$T" -d 'aksi=hapus' "$BASE/upload_logo_app.php"
cari "$(cat /tmp/cbt_logo_hapus.html)" "logo bawaan" "pengembalian logo bawaan dilaporkan"
cek "nilai app_logo dikosongkan (bukan dihapus barisnya)" \
  "$(sql "SELECT CASE WHEN COALESCE(nilai,'') = '' THEN '-' ELSE 'terisi' END FROM app_pengaturan WHERE kunci='app_logo'")" "-"
cari "$(curl -s "$BASE/login.php")" 'src="assets/logo-cbt.svg"' "halaman masuk kembali memakai logo bawaan"

echo
echo "===== HASIL: $LULUS lulus, $GAGAL gagal ====="
[ "$GAGAL" -eq 0 ] || exit 1
