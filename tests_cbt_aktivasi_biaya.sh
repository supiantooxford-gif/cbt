#!/usr/bin/env bash
# =====================================================================================
# Uji HALAMAN STATUS & AKTIVASI (kode aktivasi, permohonan WA, nominal biaya, nomor rekening)
# beserta halaman pengaturannya di akun Super Admin (menu "Biaya & Aktivasi").
#
# Permintaan pemilik: "Pada bagian status dan aktivasi di akun operator buat seperti yang ada di
# e-GURU ada fitur untuk memasukkan kode aktivasi, permintaan kode aktivasi, nominal, dan nomor
# rekening — yang semuanya bisa diatur lewat akun Super Admin."
#
# Pemakaian:  bash tests_cbt_aktivasi_biaya.sh [BASE_URL]
# Tanpa argumen: SALINAN aplikasi + basis data uji di /tmp dijalankan sendiri (data aplikasi nyata
# TIDAK disentuh).
# =====================================================================================
set -u
BASE="${1:-}"
DIR="$(cd "$(dirname "$0")" && pwd)"
LULUS=0; GAGAL=0
SANDI_ADMIN='uji_akt_123'
KERJA=""

SRV_PID=""
if [ -z "$BASE" ]; then
  PORT=""
  for _p in $(seq 8830 8890); do
    if ! (exec 3<>"/dev/tcp/127.0.0.1/$_p") 2>/dev/null; then PORT=$_p; break; fi
    exec 3<&- 2>/dev/null || true
  done
  [ -n "$PORT" ] || { echo "Tidak ada port uji yang bebas"; exit 1; }
  BASE="http://127.0.0.1:$PORT"
  KERJA="/tmp/cbt_akt_$$"
  rm -rf "$KERJA"; mkdir -p "$KERJA"
  cp -r "$DIR"/. "$KERJA"/
  rm -f "$KERJA"/data/cbt.sqlite* "$KERJA"/data/.schema_ok
  mkdir -p "$KERJA/data/sessions"; chmod 777 "$KERJA/data" "$KERJA/data/sessions" 2>/dev/null || true
  CBT_DB_FILE="$KERJA/data/cbt.sqlite" php -d extension=pdo -d extension=pdo_sqlite "$KERJA/fixture_cbt.php" >/dev/null
  CBT_DB_FILE="$KERJA/data/cbt.sqlite" php -d extension=pdo -d extension=pdo_sqlite -r '
require "'"$KERJA"'/inc/init.php";
q("UPDATE users SET password = ? WHERE username = ?", [password_hash("'"$SANDI_ADMIN"'", PASSWORD_DEFAULT), "admin"]);'
  php -d extension=pdo -d extension=pdo_sqlite -S "127.0.0.1:$PORT" -t "$KERJA" > "/tmp/cbt_akt_srv_$$.log" 2>&1 &
  SRV_PID=$!
  for _i in $(seq 1 25); do
    curl -s -o /dev/null "$BASE/login.php" && break
    sleep 0.4
  done
  bersihkan(){ [ -n "$SRV_PID" ] && kill "$SRV_PID" 2>/dev/null; [ "${CBT_SIMPAN:-0}" = "1" ] || rm -rf "$KERJA"; }
  trap bersihkan EXIT
fi

ok(){ LULUS=$((LULUS+1)); printf '  ok   %s\n' "$1"; }
no(){ GAGAL=$((GAGAL+1)); printf '  GAGAL %s\n' "$1"; }
cek(){ if [ "$2" = "$3" ]; then ok "$1"; else no "$1 (dapat: $2, harap: $3)"; fi; }
cari(){ if printf '%s' "$1" | grep -q -- "$2"; then ok "$3"; else no "$3"; fi; }
tidakk(){ if printf '%s' "$1" | grep -q -- "$2"; then no "$3"; else ok "$3"; fi; }

phpq(){ # jalankan potongan PHP pada salinan uji (dipakai untuk memeriksa helper & mengubah keadaan)
  # Argumen tambahan diteruskan ke php -r sehingga tersedia sebagai $argv[1], $argv[2], …
  local kode="$1"; shift
  CBT_DB_FILE="${KERJA}/data/cbt.sqlite" php -d extension=pdo -d extension=pdo_sqlite \
    -r 'require "'"$KERJA"'/inc/init.php"; '"$kode" "$@"
}
sql(){ phpq 'echo (string) val($argv[1], []);' "$1"; }
# Bandingkan TEKS YANG TAMPIL: tag dibuang lebih dahulu supaya kalimat yang di tengahnya ada
# <b>/</b>/<span> (mis. "…aktifasi.</b> Kirim permohonan…") tetap terbaca sebagai satu kalimat.
tanpa_tag(){ printf '%s' "$1" | sed 's/<[^>]*>//g'; }

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
masuk_konteks(){ # super masuk konteks sekolah $2 (pola sekolah.php?masuk=ID)
  curl -s -b "$1" -c "$1" -o /dev/null "$BASE/sekolah.php?masuk=$2"
}

VER=$(phpq 'echo APP_VERSION;')
JS=/tmp/cbt_akt_super.jar
JO=/tmp/cbt_akt_op.jar
JG=/tmp/cbt_akt_guru.jar
SID=$(sql "SELECT id FROM sekolah ORDER BY id LIMIT 1")

echo "== 1. Konstanta & helper biaya/kontak aktivasi =="
cek "rupiah(1000000)" "$(phpq 'echo rupiah(1000000);')" "Rp. 1.000.000"
cek "rupiah(0)" "$(phpq 'echo rupiah(0);')" "Rp. 0"
cek "rupiah(1250000)" "$(phpq 'echo rupiah(1250000);')" "Rp. 1.250.000"
cek "wa_internasional(085240102180)" "$(phpq 'echo wa_internasional("085240102180");')" "6285240102180"
cek "wa_internasional(628…) tetap" "$(phpq 'echo wa_internasional("6281234567890");')" "6281234567890"
cek "biaya aktivasi pertama bawaan" "$(phpq 'echo aktivasi_biaya_pertama();')" "1000000"
cek "biaya langganan bawaan" "$(phpq 'echo aktivasi_biaya_langganan();')" "500000"
cek "nomor WA bawaan" "$(phpq 'echo aktivasi_wa();')" "085240102180"
cari "$(phpq 'echo aktivasi_rekening();')" "Bank Mandiri 1500010135547" "nomor rekening bawaan tersedia"
cari "$(phpq 'echo aktivasi_rekening_ket();')" "tidak akan aktif" "keterangan rekening bawaan tersedia"
cek "masih bawaan = true" "$(phpq 'echo aktivasi_biaya_masih_bawaan() ? "yes" : "no";')" "yes"
cek "wa_sah(085240102180)" "$(phpq 'echo aktivasi_wa_sah("085240102180") ? "yes" : "no";')" "yes"
cek "wa_sah(08-12.345-6789) tanda baca dimaafkan" "$(phpq 'echo aktivasi_wa_sah("08-12.345-6789") ? "yes" : "no";')" "yes"
cek "wa_sah(abc) ditolak" "$(phpq 'echo aktivasi_wa_sah("abc") ? "yes" : "no";')" "no"
cek "aktivasi_wa_bersih" "$(phpq 'echo aktivasi_wa_bersih("0812-3456-7890");')" "081234567890"

echo "== 2. Kodenya TIDAK ditulis di dalam halaman (semua lewat helper) =="
tidakk "$(cat "$KERJA/aktivasi.php" 2>/dev/null || cat "$DIR/aktivasi.php")" "AKTIVASI_BIAYA" "aktivasi.php tidak memakai konstanta nominal langsung"
tidakk "$(cat "$KERJA/aktivasi.php" 2>/dev/null || cat "$DIR/aktivasi.php")" "085240102180" "aktivasi.php tidak menulis nomor WA mati"
tidakk "$(cat "$KERJA/aktivasi.php" 2>/dev/null || cat "$DIR/aktivasi.php")" "1500010135547" "aktivasi.php tidak menulis nomor rekening mati"

echo "== 3. Halaman pengaturan 'Biaya & Aktivasi' (Super Admin) =="
cek "belum masuk dialihkan (302)" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/biaya_aktivasi.php")" "302"
masuk "$JO" operator1 sandi12345
cek "operator ditolak (403)" "$(curl -s -b "$JO" -o /dev/null -w '%{http_code}' "$BASE/biaya_aktivasi.php")" "403"
masuk "$JG" ahmad guru123
cek "guru ditolak (403)" "$(curl -s -b "$JG" -o /dev/null -w '%{http_code}' "$BASE/biaya_aktivasi.php")" "403"
masuk "$JS" admin "$SANDI_ADMIN"
H=$(curl -s -b "$JS" -o /tmp/cbt_akt_ba.html -w '%{http_code}' "$BASE/biaya_aktivasi.php")
cek "Super Admin boleh membuka (200)" "$H" "200"
BA=$(cat /tmp/cbt_akt_ba.html)
cari "$BA" "Biaya &amp; Aktivasi" "judul halaman tampil"
for F in aktivasi_biaya aktivasi_biaya_langganan aktivasi_wa aktivasi_rekening aktivasi_rekening_ket; do
  cari "$BA" "id=\"$F\"" "isian $F tersedia"
done
cari "$BA" 'value="1000000"' "biaya aktivasi pertama terisi bawaan"
cari "$BA" 'value="500000"' "biaya langganan terisi bawaan"
cari "$BA" 'value="085240102180"' "nomor WA terisi bawaan"
cari "$BA" "masih bawaan" "chip 'masih bawaan' tampil"
tidakk "$BA" "Kembalikan Bawaan" "tombol kembalikan bawaan tersembunyi saat masih bawaan"
cari "$BA" "pratinjauAktivasi" "kartu pratinjau tersedia"
cari "$BA" "Rp. 1.000.000" "pratinjau memuat nominal"
cari "$BA" "Bank Mandiri 1500010135547" "pratinjau memuat nomor rekening"
cari "$(tanpa_tag "$BA")" "Hubungi HD Admin untuk melakukan aktifasi. Kirim permohonan kode aktifasi lewat tombol di bawah ini" "pratinjau memakai kalimat ajakan tanpa nomor WA"
SIDE=$(curl -s -b "$JS" "$BASE/index.php")
cek "menu 'Biaya & Aktivasi' tampil SEKALI di sidebar Super Admin" \
  "$(printf '%s' "$SIDE" | grep -c 'Biaya &amp; Aktivasi')" "1"
cek "operator tidak melihat menu itu" \
  "$(curl -s -b "$JO" "$BASE/index.php" | grep -c 'Biaya &amp; Aktivasi')" "0"

echo "== 4. Validasi isian (halaman dirender ulang, nilai tersimpan tidak berubah) =="
T=$(csrf "$JS" biaya_aktivasi.php)
H=$(curl -s -b "$JS" -o /tmp/cbt_akt_val.html -w '%{http_code}' \
  -d "csrf=$T" -d 'aksi=simpan' -d 'aktivasi_biaya=' -d 'aktivasi_biaya_langganan=500000' \
  -d 'aktivasi_wa=085240102180' -d 'aktivasi_rekening=Bank Uji' "$BASE/biaya_aktivasi.php")
cek "nominal kosong: halaman dirender ulang (200)" "$H" "200"
cari "$(cat /tmp/cbt_akt_val.html)" "wajib diisi" "nominal kosong ditolak dengan pesan"
cek "nilai tersimpan belum berubah" "$(sql "SELECT COALESCE((SELECT nilai FROM app_pengaturan WHERE kunci='aktivasi_biaya'),'')")" ""
T=$(csrf "$JS" biaya_aktivasi.php)
curl -s -b "$JS" -o /tmp/cbt_akt_val.html \
  -d "csrf=$T" -d 'aksi=simpan' -d 'aktivasi_biaya=1000000' -d 'aktivasi_biaya_langganan=2000000' \
  -d 'aktivasi_wa=085240102180' -d 'aktivasi_rekening=Bank Uji' "$BASE/biaya_aktivasi.php"
cari "$(cat /tmp/cbt_akt_val.html)" "lebih besar daripada biaya aktivasi" "langganan > aktivasi pertama ditolak"
T=$(csrf "$JS" biaya_aktivasi.php)
curl -s -b "$JS" -o /tmp/cbt_akt_val.html \
  -d "csrf=$T" -d 'aksi=simpan' -d 'aktivasi_biaya=1000000' -d 'aktivasi_biaya_langganan=500000' \
  -d 'aktivasi_wa=abc' -d 'aktivasi_rekening=Bank Uji' "$BASE/biaya_aktivasi.php"
cari "$(cat /tmp/cbt_akt_val.html)" "Nomor WA HD Admin belum benar" "nomor WA bukan angka ditolak"
T=$(csrf "$JS" biaya_aktivasi.php)
curl -s -b "$JS" -o /tmp/cbt_akt_val.html \
  -d "csrf=$T" -d 'aksi=simpan' -d 'aktivasi_biaya=1000000' -d 'aktivasi_biaya_langganan=500000' \
  -d 'aktivasi_wa=085240102180' -d 'aktivasi_rekening=' "$BASE/biaya_aktivasi.php"
cari "$(cat /tmp/cbt_akt_val.html)" "Nomor rekening wajib diisi" "nomor rekening kosong ditolak"
cek "isian dikembalikan setelah galat (nominal tetap terlihat)" \
  "$(printf '%s' "$(cat /tmp/cbt_akt_val.html)" | grep -c 'name="aktivasi_biaya"')" "1"
cek "simpan tanpa CSRF ditolak (419)" \
  "$(curl -s -b "$JS" -o /dev/null -w '%{http_code}' -d 'aksi=simpan' -d 'aktivasi_biaya=1' "$BASE/biaya_aktivasi.php")" "419"

echo "== 5. Menyimpan pengaturan baru =="
T=$(csrf "$JS" biaya_aktivasi.php)
curl -s -L -b "$JS" -c "$JS" -o /tmp/cbt_akt_simpan.html \
  -d "csrf=$T" -d 'aksi=simpan' -d 'aktivasi_biaya=1.250.000' -d 'aktivasi_biaya_langganan=750000' \
  -d 'aktivasi_wa=0812-3456-7890' -d 'aktivasi_rekening=Bank BRI 123456789. An. Uji Coba' \
  -d 'aktivasi_rekening_ket=Hanya transfer ke rekening ini.' "$BASE/biaya_aktivasi.php"
SIMPAN=$(cat /tmp/cbt_akt_simpan.html)
cari "$SIMPAN" "Pengaturan aktivasi disimpan" "penyimpanan dilaporkan"
cari "$SIMPAN" "Rp. 1.250.000" "pesan menyebut nominal baru"
cek "biaya aktivasi tersimpan (titik diabaikan)" "$(sql "SELECT nilai FROM app_pengaturan WHERE kunci='aktivasi_biaya'")" "1250000"
cek "biaya langganan tersimpan" "$(sql "SELECT nilai FROM app_pengaturan WHERE kunci='aktivasi_biaya_langganan'")" "750000"
cek "nomor WA tersimpan tanpa tanda hubung" "$(sql "SELECT nilai FROM app_pengaturan WHERE kunci='aktivasi_wa'")" "081234567890"
cek "nomor rekening tersimpan" "$(sql "SELECT nilai FROM app_pengaturan WHERE kunci='aktivasi_rekening'")" "Bank BRI 123456789. An. Uji Coba"
cek "keterangan rekening tersimpan" "$(sql "SELECT nilai FROM app_pengaturan WHERE kunci='aktivasi_rekening_ket'")" "Hanya transfer ke rekening ini."
cek "helper membaca nilai baru" "$(phpq 'echo aktivasi_biaya_pertama();')" "1250000"
cari "$(curl -s -b "$JS" "$BASE/biaya_aktivasi.php")" "sudah disesuaikan" "chip berubah menjadi 'sudah disesuaikan'"
cari "$(curl -s -b "$JS" "$BASE/biaya_aktivasi.php")" "Kembalikan Bawaan" "tombol kembalikan bawaan muncul"

echo "== 6. Halaman Status & Aktivasi di akun OPERATOR =="
# keadaan awal fixture: permanen → kode aktivasi tidak diperlukan
H=$(curl -s -b "$JO" -o /tmp/cbt_akt_op.html -w '%{http_code}' "$BASE/aktivasi.php")
cek "operator boleh membuka (200)" "$H" "200"
OPS=$(cat /tmp/cbt_akt_op.html)
cari "$OPS" "Status Aktivasi Lembaga" "kartu status tampil"
cari "$OPS" "Aktif permanen" "keadaan permanen ditandai"
cari "$OPS" "kode aktivasi tidak diperlukan" "sekolah permanen tidak diminta kode"
cari "$OPS" "Cara Kerja Aktivasi" "kartu Cara Kerja Aktivasi tampil"
cari "$OPS" "Aktivasi Sekolah/Madrasah" "kartu Aktivasi Sekolah/Madrasah tampil"
cari "$(tanpa_tag "$OPS")" "Hubungi HD Admin untuk melakukan aktifasi. Kirim permohonan kode aktifasi lewat tombol di bawah ini" "ajakan menghubungi HD Admin (tanpa nomor WA) tampil"
tidakk "$OPS" "lewat tombol di bawah ini (menuju WhatsApp" "kalimat ajakan tidak lagi menyebut nomor WA"
tidakk "$OPS" 'Permohonan kode aktivasi, biaya, dan rekening' "keterangan kecil di sebelah judul Aktivasi Sekolah/Madrasah dihapus"
cari "$OPS" "Kirim Permohonan Kode Aktifasi" "tombol permohonan kode aktifasi tampil"
cari "$OPS" "https://wa.me/6281234567890" "tombol permohonan memakai nomor WA yang diatur Super Admin"
cari "$OPS" 'id="nominalAktivasi"' "nominal biaya dicetak (id=nominalAktivasi)"
cari "$OPS" "Rp. 1.250.000" "nominal baru tampil"
cari "$OPS" "Bank BRI 123456789" "nomor rekening baru tampil"
cari "$OPS" "Hanya transfer ke rekening ini." "keterangan rekening tampil"
cari "$OPS" 'id="btnKirimBukti"' "tombol kirim bukti transfer ada"
cari "$OPS" 'data-salin="#rekeningTeks"' "tombol Salin rekening memakai elemen teks (bukan isian tersembunyi)"
tidakk "$OPS" 'class="sr-salin"' "tidak ada isian tersembunyi untuk rekening"
cari "$OPS" 'id="cardKode"' "kartu kode aktivasi tetap dicetak"
tidakk "$OPS" 'id="kode"' "kolom kode TIDAK ditampilkan pada sekolah permanen"

echo "== 7. Yang dilihat GURU (tanpa nominal/rekening) =="
GR=$(curl -s -b "$JG" "$BASE/aktivasi.php")
cek "guru boleh membuka halaman status (200)" "$(curl -s -b "$JG" -o /dev/null -w '%{http_code}' "$BASE/aktivasi.php")" "200"
cari "$GR" "Cara Kerja Aktivasi" "guru tetap melihat kartu Cara Kerja Aktivasi"
tidakk "$GR" 'id="nominalAktivasi"' "guru tidak melihat nominal"
tidakk "$GR" 'id="rekeningTeks"' "guru tidak melihat nomor rekening"
tidakk "$GR" "Kirim Permohonan Kode Aktifasi" "guru tidak melihat tombol permohonan"
tidakk "$GR" 'id="kode"' "guru tidak melihat formulir kode aktivasi"

echo "== 8. Aktivasi dengan kode (sekali pakai) pada akun OPERATOR =="
# Ubah keadaan sekolah menjadi percobaan (masa aktif 30 hari) — pada SALINAN uji saja.
phpq 'q("UPDATE sekolah SET aktivasi = ?, aktif_sampai = ?, aktivasi_pada = NULL, aktivasi_ke = 1 WHERE id = ?",
  ["percobaan", date("Y-m-d H:i:s", time() + 30 * 86400), (int) $argv[1]]);' "$SID"
OPS2=$(curl -s -b "$JO" "$BASE/aktivasi.php")
cari "$OPS2" 'id="kode"' "formulir kode aktivasi tampil setelah sekolah tidak permanen"
cari "$OPS2" "Kode Aktivasi" "judul formulir kode tampil"
cari "$OPS2" "Aktifkan Sekarang" "tombol Aktifkan Sekarang tampil"
tidakk "$OPS2" "Kode Aktivasi Perpanjangan" "belum pernah aktivasi → belum berlabel perpanjangan"
cari "$(tanpa_tag "$OPS2")" "Setiap kode hanya dapat dipakai satu kali. Setelah dipakai, kode tersebut tidak berlaku lagi." "aturan sekali pakai dijelaskan (kalimat ringkas)"
tidakk "$OPS2" "berganti otomatis" "keterangan 'kode di akun Super Admin berganti otomatis' tidak dicetak lagi"
tidakk "$OPS2" "kode berikutnya diminta kembali kepada Admin aplikasi" "keterangan 'kode berikutnya diminta kembali' tidak dicetak lagi"
GR2=$(curl -s -b "$JG" "$BASE/aktivasi.php")
cari "$GR2" "dimasukkan oleh" "guru diberi tahu siapa yang memasukkan kode"
# kode salah
T=$(csrf "$JO" aktivasi.php)
curl -s -L -b "$JO" -c "$JO" -o /tmp/cbt_akt_salah.html \
  -d "csrf=$T" -d 'aksi=aktifkan' -d 'kode=ZZZZ-ZZZZ-ZZZZ' "$BASE/aktivasi.php"
cari "$(cat /tmp/cbt_akt_salah.html)" "tidak cocok" "kode salah ditolak dengan pesan"
cari "$(cat /tmp/cbt_akt_salah.html)" "Sisa percobaan" "sisa percobaan dilaporkan"
cek "keadaan belum berubah" "$(sql "SELECT aktivasi FROM sekolah WHERE id=$SID")" "percobaan"
# super melihat kode periode 1
masuk_konteks "$JS" "$SID"
SK=$(curl -s -b "$JS" "$BASE/aktivasi.php")
cari "$SK" 'id="kodeLembaga"' "Super Admin melihat kode aktivasi lembaga"
KODE1=$(printf '%s' "$SK" | grep -o 'id="kodeLembaga">[^<]*' | head -1 | sed 's/.*>//')
if [ -n "$KODE1" ]; then ok "kode periode 1 terbaca ($KODE1)"; else no "kode periode 1 tidak terbaca"; fi
# operator memakai kode (huruf kecil + spasi)
T=$(csrf "$JO" aktivasi.php)
RENDAH=$(printf '%s' "$KODE1" | tr 'A-Z' 'a-z' | sed 's/-/ - /g')
curl -s -L -b "$JO" -c "$JO" -o /tmp/cbt_akt_ok.html \
  -d "csrf=$T" -d 'aksi=aktifkan' --data-urlencode "kode=$RENDAH" "$BASE/aktivasi.php"
cari "$(cat /tmp/cbt_akt_ok.html)" "AKTIF" "aktivasi berhasil dilaporkan"
cek "keadaan menjadi aktif" "$(sql "SELECT aktivasi FROM sekolah WHERE id=$SID")" "aktif"
cek "periode naik menjadi 2" "$(sql "SELECT aktivasi_ke FROM sekolah WHERE id=$SID")" "2"
cek "jejak aktivasi_pada terisi" "$(phpq 'echo trim((string) val("SELECT aktivasi_pada FROM sekolah WHERE id = ?", [(int) $argv[1]])) !== "" ? "yes" : "no";' "$SID")" "yes"
SISA=$(phpq 'echo (int) ((strtotime((string) val("SELECT aktif_sampai FROM sekolah WHERE id = ?", [(int) $argv[1]])) - time()) / 86400);' "$SID")
# AKTIVASI PERTAMA diberi 365 hari DIHITUNG DARI HARI INI (pola yang sama dengan e-GURU): masa
# aktif AWAL/uji coba digantikan, bukan ditumpuk. Penambahan dari tanggal berakhir hanya berlaku
# untuk PERPANJANGAN masa aktif berbayar yang masih berjalan (diuji pada bagian 9).
if [ "$SISA" -ge 364 ] && [ "$SISA" -le 366 ]; then ok "aktivasi pertama memberi 365 hari ($SISA hari)"; else no "masa aktif tidak wajar ($SISA hari)"; fi
# kode lama tidak berlaku lagi + label perpanjangan
T=$(csrf "$JO" aktivasi.php)
curl -s -L -b "$JO" -c "$JO" -o /tmp/cbt_akt_lama.html \
  -d "csrf=$T" -d 'aksi=aktifkan' --data-urlencode "kode=$KODE1" "$BASE/aktivasi.php"
cari "$(cat /tmp/cbt_akt_lama.html)" "tidak cocok" "kode periode lama ditolak (sekali pakai)"
OPS3=$(curl -s -b "$JO" "$BASE/aktivasi.php")
cari "$OPS3" "Kode Aktivasi Perpanjangan" "setelah aktivasi → label berubah menjadi perpanjangan"
cari "$OPS3" "Perpanjang Masa Aktif" "tombol menjadi Perpanjang Masa Aktif"
cari "$OPS3" 'id="infoLangganan"' "info biaya langganan tampil bagi yang sudah diaktivasi"
cari "$OPS3" "sudah diaktifasi" "kalimat 'sudah diaktifasi' tampil"
cari "$OPS3" "Rp. 750.000" "biaya LANGGANAN yang ditampilkan setelah aktivasi"
tidakk "$OPS3" "Aktivasi pertama (masa aktif" "baris tarif tidak lagi menyebut biaya aktivasi pertama"
KODE2=$(curl -s -b "$JS" "$BASE/aktivasi.php" | grep -o 'id="kodeLembaga">[^<]*' | head -1 | sed 's/.*>//')
if [ "$KODE2" != "$KODE1" ] && [ -n "$KODE2" ]; then ok "kode di akun Super Admin BERGANTI ($KODE1 → $KODE2)"; else no "kode tidak berganti ($KODE1 → $KODE2)"; fi

echo "== 9. PERPANJANGAN dengan kode baru (masa aktif ditambahkan) =="
T=$(csrf "$JO" aktivasi.php)
curl -s -L -b "$JO" -c "$JO" -o /tmp/cbt_akt_pj.html \
  -d "csrf=$T" -d 'aksi=aktifkan' --data-urlencode "kode=$KODE2" "$BASE/aktivasi.php"
cari "$(cat /tmp/cbt_akt_pj.html)" "DIPERPANJANG" "perpanjangan dilaporkan"
SISA2=$(phpq 'echo (int) ((strtotime((string) val("SELECT aktif_sampai FROM sekolah WHERE id = ?", [(int) $argv[1]])) - time()) / 86400);' "$SID")
# Perpanjangan: masa aktif BERBAYAR yang masih berjalan (365 hari) + 365 hari = ±730 hari.
if [ "$SISA2" -ge 729 ] && [ "$SISA2" -le 731 ]; then ok "perpanjangan menambah 365 hari lagi ($SISA2 hari)"; else no "penambahan masa aktif tidak wajar ($SISA2 hari)"; fi
cek "periode naik menjadi 3" "$(sql "SELECT aktivasi_ke FROM sekolah WHERE id=$SID")" "3"

echo "== 10. Keadaan TERKUNCI (masa aktif habis) =="
phpq 'q("UPDATE sekolah SET aktivasi = ?, aktif_sampai = ? WHERE id = ?",
  ["aktif", date("Y-m-d H:i:s", time() - 86400), (int) $argv[1]]);' "$SID"
H=$(curl -s -b "$JO" -o /tmp/cbt_akt_kunci.html -w '%{http_code}' "$BASE/aktivasi.php")
cek "halaman aktivasi tetap 200 saat terkunci" "$H" "200"
KUNCI=$(cat /tmp/cbt_akt_kunci.html)
cari "$KUNCI" "Aplikasi terkunci" "keterangan terkunci tampil"
cari "$KUNCI" "Aplikasi Terkunci" "kartu aktivasi kembali berjudul Aplikasi Terkunci"
cari "$KUNCI" "tidak dihapus" "dijelaskan data tidak dihapus"
cari "$KUNCI" 'id="kode"' "formulir kode tersedia saat terkunci"
cari "$KUNCI" 'autofocus' "kolom kode langsung difokuskan"
cek "halaman lain ikut terkunci (302)" \
  "$(curl -s -b "$JO" -o /dev/null -w '%{http_code}' "$BASE/index.php")" "302"
cek "nominal & rekening TETAP tampil saat terkunci" \
  "$(printf '%s' "$KUNCI" | grep -c 'id="rekeningTeks"')" "1"

echo "== 11. Kembalikan Bawaan =="
T=$(csrf "$JS" biaya_aktivasi.php)
curl -s -L -b "$JS" -c "$JS" -o /tmp/cbt_akt_bawaan.html \
  -d "csrf=$T" -d 'aksi=bawaan' "$BASE/biaya_aktivasi.php"
cari "$(cat /tmp/cbt_akt_bawaan.html)" "dikembalikan ke" "pengembalian bawaan dilaporkan"
cek "biaya aktivasi kembali bawaan" "$(sql "SELECT nilai FROM app_pengaturan WHERE kunci='aktivasi_biaya'")" "1000000"
cek "biaya langganan kembali bawaan" "$(sql "SELECT nilai FROM app_pengaturan WHERE kunci='aktivasi_biaya_langganan'")" "500000"
cek "nomor WA kembali bawaan" "$(sql "SELECT nilai FROM app_pengaturan WHERE kunci='aktivasi_wa'")" "085240102180"
cari "$(sql "SELECT nilai FROM app_pengaturan WHERE kunci='aktivasi_rekening'")" "Bank Mandiri" "nomor rekening kembali bawaan"
cek "masih bawaan = true" "$(phpq 'echo aktivasi_biaya_masih_bawaan() ? "yes" : "no";')" "yes"

echo "== 12. Hak akses & keamanan =="
cek "guru POST kode aktivasi ditolak (403)" \
  "$(curl -s -b "$JG" -o /dev/null -w '%{http_code}' -d 'aksi=aktifkan' -d 'kode=AAAA-AAAA-AAAA' "$BASE/aktivasi.php")" "403"
cek "tamu dialihkan (302)" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/aktivasi.php")" "302"
cek "operator POST tanpa CSRF ditolak (419)" \
  "$(curl -s -b "$JO" -o /dev/null -w '%{http_code}' -d 'aksi=aktifkan' -d 'kode=AAAA-AAAA-AAAA' "$BASE/aktivasi.php")" "419"
SEBELUM_BATASI=$(sql "SELECT COALESCE(aktif_sampai,'-') FROM sekolah WHERE id=$SID")
T=$(csrf "$JO" aktivasi.php)
cek "aksi 'batasi' oleh operator diabaikan (302)" \
  "$(curl -s -b "$JO" -o /dev/null -w '%{http_code}' -d "csrf=$T" -d 'aksi=batasi' "$BASE/aktivasi.php")" "302"
cek "masa aktif tidak dikosongkan oleh operator" \
  "$(sql "SELECT COALESCE(aktif_sampai,'-') FROM sekolah WHERE id=$SID")" "$SEBELUM_BATASI"

echo "== 13. Kerapian & integritas =="
PAGES="$(curl -s -b "$JO" "$BASE/aktivasi.php")$(curl -s -b "$JS" "$BASE/biaya_aktivasi.php")$(curl -s -b "$JG" "$BASE/aktivasi.php")"
tidakk "$PAGES" 'href="/' "tidak ada tautan berawalan garis miring"
tidakk "$PAGES" 'src="/' "tidak ada berkas berawalan garis miring"
tidakk "$PAGES" '<style' "tidak ada <style> di dalam halaman"
cari "$PAGES" "assets/style.css?v=$VER" "aset memakai versi terbaru (pengusir cache: $VER)"
if [ -n "$KERJA" ] && [ -f "$KERJA/.vibecoder-media-token" ]; then
  TOKEN=$(tr -d '\n' < "$KERJA/.vibecoder-media-token")
  tidakk "$PAGES" "$TOKEN" "token unggah media tidak muncul di halaman"
fi
cek "integritas basis data" "$(phpq 'echo val("PRAGMA integrity_check");')" "ok"
cek "pelanggaran foreign key" "$(phpq 'echo count(all("PRAGMA foreign_key_check"));')" "0"

echo
echo "===== HASIL: $LULUS lulus, $GAGAL gagal ====="
[ "$GAGAL" -eq 0 ] || exit 1
