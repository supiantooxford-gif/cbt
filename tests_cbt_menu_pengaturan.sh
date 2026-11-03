#!/usr/bin/env bash
# =====================================================================================
# Uji menu "PENGATURAN" (sub menu) pada sidebar Super Admin aplikasi CBT.
#
# Permintaan pemilik: "Pada halaman super admin tambahkan menu Pengaturan yang didalamnya berisi
# menu biaya & aktifasi, menu jenis penilaian, menu soal otomatis, menu logo aplikasi dan menu
# akun super admin."
#
# Yang diuji:
#   1. Sidebar Super Admin memuat SATU sub menu "Pengaturan" (`<details class="nav-lipat" open>`)
#      berisi kelima menu dengan urutan yang benar, masing-masing tepat satu tautan.
#   2. Sub menu TERBUKA secara bawaan dan buka-tutupnya memakai <details> (bekerja tanpa JavaScript).
#   3. Kelima halaman terbuka (200) bagi Super Admin dan menu aktifnya menyala di dalam sub menu.
#   4. Menu lama tetap ada: Ringkasan/Kelola Sekolah/Tarik Data (di luar sub menu),
#      Akun Saya, dan Keluar.
#   5. Operator/guru/siswa TIDAK melihat sub menu itu; Super Admin DALAM konteks sekolah juga tidak
#      (di sana "Pengaturan" adalah pengaturan sekolah, bukan aplikasi).
#   6. Gaya `.nav-lipat`/`.nav-caret`/`.nav-sub` ada di assets/style.css (bukan <style> di halaman),
#      dan versi aset ikut APP_VERSION terbaru.
#   7. Kerapian (tautan relatif, tanpa token media) + integritas basis data.
#
# Pemakaian:  bash tests_cbt_menu_pengaturan.sh [BASE_URL]
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
  KERJA="/tmp/cbt_pengaturan_$$"
  rm -rf "$KERJA"; mkdir -p "$KERJA"
  cp -r "$DIR"/. "$KERJA"/
  rm -f "$KERJA"/data/cbt.sqlite* "$KERJA"/data/.schema_ok
  mkdir -p "$KERJA/data/sessions"; chmod 777 "$KERJA/data" "$KERJA/data/sessions" 2>/dev/null || true
  CBT_DB_FILE="$KERJA/data/cbt.sqlite" php -d extension=pdo -d extension=pdo_sqlite "$KERJA/fixture_cbt.php" >/dev/null
  php -d extension=pdo -d extension=pdo_sqlite -S "127.0.0.1:$PORT" -t "$KERJA" > "/tmp/cbt_pengaturan_srv_$$.log" 2>&1 &
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
cari(){ if printf '%s' "$1" | grep -q -- "$2"; then ok "$3"; else no "$3"; fi; }
tidak(){ if printf '%s' "$1" | grep -q -- "$2"; then no "$3"; else ok "$3"; fi; }
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

# Blok <div class="nav-sub"> … </div> (isi sub menu Pengaturan) dari sebuah halaman.
navsub(){ python3 - "$1" <<'PY'
import re, sys
h = open(sys.argv[1], encoding='utf-8').read()
m = re.search(r'<div class="nav-sub">(.*?)</div>', h, re.S)
print(m.group(1) if m else '')
PY
}
# Daftar href pada sub menu, dipisah koma.
urutan_sub(){ python3 - "$1" <<'PY'
import re, sys
h = open(sys.argv[1], encoding='utf-8').read()
m = re.search(r'<div class="nav-sub">(.*?)</div>', h, re.S)
blok = m.group(1) if m else ''
print(','.join(re.findall(r'href="([^"]+)"', blok)))
PY
}
# href menu yang sedang menyala (class="nav-item on").
nav_aktif(){ python3 - "$1" <<'PY'
import re, sys
h = open(sys.argv[1], encoding='utf-8').read()
m = re.search(r'class="nav-item on" href="([^"]+)"', h)
print(m.group(1) if m else '')
PY
}

JS=/tmp/cbt_peng_super.$RANDOM.jar
JO=/tmp/cbt_peng_op.$RANDOM.jar
JG=/tmp/cbt_peng_guru.$RANDOM.jar
JSI=/tmp/cbt_peng_siswa.$RANDOM.jar

masuk "$JS" admin admin123
masuk "$JO" operator1 sandi12345
masuk "$JG" ahmad guru123
masuk "$JSI" siswa1 siswa123

# ------------------------------------------------------------------ 1
echo "== 1. Sub menu 'Pengaturan' pada sidebar Super Admin =="
HOME_F="/tmp/cbt_peng_home_$$.html"
curl -s -b "$JS" -c "$JS" -o "$HOME_F" -w '' "$BASE/index.php"
HOME=$(cat "$HOME_F")
cek "sub menu <details class=\"nav-lipat\"> tepat satu" "$(hitung "$HOME" '<details class="nav-lipat"')" "1"
cari "$HOME" '<details class="nav-lipat" open>' "sub menu TERBUKA bawaan (tanpa JavaScript)"
cari "$HOME" '<summary class="nav-grup nav-lipat-kepala">Pengaturan' "kepala sub menu berjudul Pengaturan"
cari "$HOME" 'class="nav-caret" aria-hidden="true"' "ada penanda buka-tutup (caret)"
cek "kelima menu berada di dalam sub menu, urutannya benar" \
  "$(urutan_sub "$HOME_F")" \
  "biaya_aktivasi.php,jenis_ujian.php,soal_otomatis.php,logo.php,pengguna.php"
SUB="$(navsub "$HOME_F")"
for pasang in 'Biaya &amp; Aktivasi|biaya_aktivasi.php' 'Jenis Penilaian|jenis_ujian.php' \
              'Soal Otomatis|soal_otomatis.php' 'Logo Aplikasi|logo.php' \
              'Akun Super Admin|pengguna.php'; do
  label="${pasang%%|*}"; tautan="${pasang##*|}"
  cari "$SUB" "$label" "sub menu memuat label \"$label\""
  cek "tautan $tautan tepat satu di sub menu" "$(hitung "$SUB" "href=\"$tautan\"")" "1"
done
cek "sub menu berisi tepat 5 tautan" "$(hitung "$SUB" 'class="nav-item')" "5"

# ------------------------------------------------------------------ 2
echo "== 2. Menu lama tetap ada di luar sub menu =="
NAVLUAR="${HOME%%<div class=\"nav-sub\">*}"
cari "$NAVLUAR" 'href="index.php"' "menu Ringkasan tetap ada"
cari "$NAVLUAR" 'Kelola Sekolah' "menu Kelola Sekolah tetap ada"
cari "$NAVLUAR" 'Tarik Data dari e-GURU' "menu Tarik Data dari e-GURU tetap ada"
cari "$HOME" 'href="akun.php"' "menu Akun Saya tetap ada"
cari "$HOME" 'href="logout.php"' "menu Keluar tetap ada"
cek "tidak ada menu ganda di luar sub menu" "$(hitung "$NAVLUAR" 'href="biaya_aktivasi.php"')" "0"
cari "$HOME" '<div class="nav-grup">Akun</div>' "grup Akun tetap ada"

# ------------------------------------------------------------------ 3
echo "== 3. Kelima halaman terbuka & menunya menyala =="
for f in biaya_aktivasi jenis_ujian soal_otomatis logo pengguna; do
  HAL_F="/tmp/cbt_peng_${f}_$$.html"
  KODE=$(curl -s -b "$JS" -c "$JS" -o "$HAL_F" -w '%{http_code}' "$BASE/$f.php")
  cek "halaman $f.php dijawab 200" "$KODE" "200"
  cek "menu $f.php menyala (aktif)" "$(nav_aktif "$HAL_F")" "$f.php"
  cari "$(cat "$HAL_F")" '<details class="nav-lipat" open>' "sub menu tetap terbuka di halaman $f.php"
  rm -f "$HAL_F"
done

# ------------------------------------------------------------------ 4
echo "== 4. Peran lain tidak melihat sub menu aplikasi =="
for j in "$JO" "$JG" "$JSI"; do
  HF="/tmp/cbt_peng_lain_$$.html"
  curl -s -b "$j" -c "$j" -o "$HF" "$BASE/index.php"
  H=$(cat "$HF")
  tidak "$H" 'class="nav-lipat"' "peran non-super tanpa sub menu Pengaturan aplikasi"
  tidak "$H" 'class="nav-sub"' "peran non-super tanpa wadah sub menu"
  rm -f "$HF"
done

# Super Admin DALAM konteks sekolah: "Pengaturan" di sana = pengaturan sekolah (bukan aplikasi).
SID=$(sql "SELECT id FROM sekolah ORDER BY id LIMIT 1")
curl -s -b "$JS" -c "$JS" -o /dev/null "$BASE/sekolah.php?masuk=$SID"
HK="/tmp/cbt_peng_konteks_$$.html"
curl -s -b "$JS" -c "$JS" -o "$HK" "$BASE/index.php"
H=$(cat "$HK")
tidak "$H" 'class="nav-lipat"' "Super Admin dalam konteks tanpa sub menu Pengaturan aplikasi"
cari "$H" '<div class="nav-grup">Pengaturan</div>' "grup Pengaturan sekolah tetap ada dalam konteks"
cari "$H" 'Status &amp; Aktivasi' "menu Status & Aktivasi sekolah tetap ada"
rm -f "$HK"
curl -s -b "$JS" -c "$JS" -o /dev/null "$BASE/index.php?keluar=1"

# ------------------------------------------------------------------ 5
echo "== 5. Bekerja tanpa JavaScript & gaya sub menu =="
JS_F="/tmp/cbt_peng_js_$$.html"
curl -s -b "$JS" -c "$JS" -o "$JS_F" "$BASE/jenis_ujian.php"
HALJS=$(cat "$JS_F")
# Sub menu memakai <details> bawaan HTML sehingga buka-tutupnya bekerja walau skrip gagal dimuat:
# tidak ada kait JS yang diperlukan (tidak ada id/kelas pemicu yang dipanggil app.js).
tidak "$HALJS" 'data-nav-lipat' "buka-tutup memakai <details> murni (tanpa kait JavaScript)"
CSS=$(curl -s "$BASE/assets/style.css")
cari "$CSS" '.nav-lipat{' "gaya .nav-lipat ada di assets/style.css"
cari "$CSS" '.nav-lipat\[open\] .nav-caret{transform:rotate(180deg)}' "caret berputar saat terbuka"
cari "$CSS" '.nav-sub{' "gaya .nav-sub (garis sub menu) ada"
cari "$CSS" '.nav-lipat > summary::-webkit-details-marker{display:none}' "penanda bawaan peramban disembunyikan"
cari "$CSS" '.nav-lipat > summary:focus-visible' "fokus papan tunjuk terlihat (aksesibilitas)"
tidak "$HALJS" '<style' "tanpa tag <style> di halaman"
VER=$(php -r 'require "'"$DIR"'/inc/config.php"; echo APP_VERSION;')
cari "$HALJS" "assets/style.css?v=$VER" "aset CSS memakai versi aplikasi terbaru ($VER)"
cari "$HALJS" "assets/app.js?v=$VER" "aset JS memakai versi aplikasi terbaru ($VER)"
rm -f "$JS_F"

# ------------------------------------------------------------------ 6
echo "== 6. Kerapian & integritas =="
PAGE=$(curl -s -b "$JS" -c "$JS" "$BASE/index.php")
tidak "$PAGE" 'href="/' "tautan sidebar relatif (tanpa href=\"/\")"
tidak "$PAGE" 'vibecoder-media-token' "token media tidak bocor di HTML"
tidak "$PAGE" 'Pengaturan</div>' "tidak ada judul grup ganda untuk Pengaturan aplikasi"
cek "integritas basis data ok" "$(sql 'PRAGMA integrity_check')" "ok"
cek "tidak ada pelanggaran foreign key" "$(sql 'SELECT COUNT(*) FROM pragma_foreign_key_check')" "0"
cek "audit query tidak menemukan placeholder tidak cocok" \
  "$( (cd "$DIR/.." && php -d extension=tokenizer audit_query_cbt.php) 2>&1 | tail -1 )" "Semua cocok."

echo
echo "LULUS: $LULUS   GAGAL: $GAGAL"
[ "$GAGAL" -eq 0 ] || exit 1
