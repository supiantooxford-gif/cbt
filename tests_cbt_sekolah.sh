#!/usr/bin/env bash
# =====================================================================================
# Uji alur pengelolaan sekolah CBT: pendaftaran, aktivasi & penguncian, impor data
# (guru, siswa, soal), lembar cetak, serta pemisahan data antar sekolah.
#
# Pemakaian:  bash tests_cbt_sekolah.sh [BASE_URL]
# =====================================================================================
set -u
BASE="${1:-http://127.0.0.1:8899}"
DIR="$(cd "$(dirname "$0")" && pwd)"
DIR="${CBT_DIR:-$DIR}"
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
  bersihkan(){ [ -n "$SRV_PID" ] && kill "$SRV_PID" 2>/dev/null; rm -rf "$KERJA"; }
  trap bersihkan EXIT
fi
ok(){ LULUS=$((LULUS+1)); printf '  ok   %s\n' "$1"; }
no(){ GAGAL=$((GAGAL+1)); printf '  GAGAL %s\n' "$1"; }
cek(){ if [ "$2" = "$3" ]; then ok "$1"; else no "$1 (dapat: $2, harap: $3)"; fi; }
cari(){ if printf '%s' "$1" | grep -q -- "$2"; then ok "$2"; else no "$3"; fi; }
cari2(){ if printf '%s' "$1" | grep -q -- "$2"; then ok "$3"; else no "$3"; fi; }
tidakk(){ if printf '%s' "$1" | grep -q -- "$2"; then no "$3"; else ok "$3"; fi; }
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

echo "== 1. Sekolah baru dibuat Super Admin (v1.14.0: pendaftaran mandiri DIHAPUS) =="
# Permintaan pemilik: halaman pendaftaran (`daftar.php`) dan peninjauannya (`pendaftaran.php`)
# dihapus — seluruh data ditarik dari e-GURU. Sekolah kini hanya dibuat Super Admin.
cek "daftar.php sudah tidak ada (404)" \
  "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/daftar.php")" "404"
cek "pendaftaran.php sudah tidak ada (404)" \
  "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/pendaftaran.php")" "404"
JS=/tmp/cbt_s.jar
masuk "$JS" admin admin123
T=$(csrf "$JS" akun.php)
H=$(curl -s -b "$JS" -c "$JS" -o /tmp/x.html -w '%{http_code}' -d "csrf=$T" -d 'aksi=tambah' \
  --data-urlencode 'nama=Sekolah Uji Ketiga' -d 'jenjang=SMA/MA/SMK' -d 'npsn=999' \
  --data-urlencode 'kecamatan=Limboto' --data-urlencode 'kepala=Kepala Tiga' -d 'kkm=70' "$BASE/sekolah.php")
cek "tambah sekolah diproses (302)" "$H" "302"
S3=$(sql "SELECT id FROM sekolah WHERE nama='Sekolah Uji Ketiga'")
cek "sekolah baru tersimpan" "$([ -n "$S3" ] && echo ada)" "ada"
cek "aktivasi awal = percobaan" "$(sql "SELECT aktivasi FROM sekolah WHERE id=$S3")" "percobaan"
MASA=$(sql "SELECT CAST(julianday(aktif_sampai) - julianday('now') AS INTEGER) FROM sekolah WHERE id=$S3")
if [ "$MASA" -ge 364 ] && [ "$MASA" -le 366 ]; then ok "masa aktif awal 365 hari ($MASA hari)"; else no "masa aktif awal ($MASA hari)"; fi
cek "mata pelajaran bawaan dibuat" "$([ "$(sql "SELECT COUNT(*) FROM mapel WHERE sekolah_id=$S3")" -gt 5 ] && echo ada)" "ada"

echo "== 2. Akun operator sekolah baru dibuat Super Admin & bisa langsung masuk =="
T=$(csrf "$JS" akun.php)
curl -s -b "$JS" -c "$JS" -o /dev/null -d "csrf=$T" -d 'aksi=akun' -d "id=$S3" \
  --data-urlencode 'nama_admin=Operator Tiga' -d 'username=operator3' -d 'password=sandi12345' "$BASE/sekolah.php"
cek "akun operator tersimpan" "$(sql "SELECT COUNT(*) FROM users WHERE username='operator3'")" "1"
J3=/tmp/cbt_o3.jar
masuk "$J3" operator3 sandi12345
H=$(curl -s -b "$J3" -o /tmp/x.html -w '%{http_code}' "$BASE/index.php")
cek "dashboard operator baru (200)" "$H" "200"
cari2 "$(cat /tmp/x.html)" "Langkah Awal Menyiapkan Ujian" "panduan langkah awal tampil untuk sekolah baru"

echo "== 3. Impor data guru & siswa dari payload =="
T=$(csrf "$J3" guru_import.php)
PAYLOAD='{"judul":["Nama","NIP/NUPTK","Jenis Kelamin","Mata Pelajaran","Kelas Diajar","Telepon","Email","Akun (Nama Pengguna)"],"baris":[["Dedi Kurniawan, S.Pd","G-900","L","Matematika","X-A","0812","dedi@e.com","dedi"],["Rina Wati, S.Pd","","P","Bahasa Indonesia","X-A","","",""]]}'
curl -s -b "$J3" -c "$J3" -o /tmp/cbt_ig.html -d "csrf=$T" --data-urlencode "payload=$PAYLOAD" "$BASE/guru_import.php"
cari2 "$(cat /tmp/cbt_ig.html)" "data guru</b> tersimpan\|data guru" "impor guru melaporkan hasil"
cek "dua guru tersimpan" "$(sql "SELECT COUNT(*) FROM guru WHERE sekolah_id=$S3")" "2"
cek "akun guru dari kolom Akun dibuat" "$(sql "SELECT COUNT(*) FROM users WHERE sekolah_id=$S3 AND role='guru'")" "1"
cek "mata pelajaran baru ikut dibuat" "$([ "$(sql "SELECT COUNT(*) FROM mapel WHERE sekolah_id=$S3")" -gt 5 ] && echo ada)" "ada"

# kelas lebih dahulu agar siswa bisa masuk kelas
T=$(csrf "$J3" kelas.php)
curl -s -b "$J3" -c "$J3" -o /dev/null -d "csrf=$T" -d 'aksi=tambah' --data-urlencode 'nama=X-A' -d 'tingkat=10' "$BASE/kelas.php"
T=$(csrf "$J3" siswa_import.php)
PAYLOAD2='{"judul":["Nama","NIS","NISN","Jenis Kelamin","Tempat Lahir","Tanggal Lahir","Kelas","Wali","Telepon","Alamat","Akun (Nama Pengguna)"],"baris":[["Ani Lestari","9001","001","P","Limboto","2010-05-12","X-A","Bapak Ani","0813","Jl. Mawar","anil"],["Budi Santoso","9002","002","L","Limboto","12/06/2010","X-A","Bapak Budi","","",""]]}'
curl -s -b "$J3" -c "$J3" -o /tmp/cbt_is.html -d "csrf=$T" --data-urlencode "payload=$PAYLOAD2" "$BASE/siswa_import.php"
cek "dua siswa tersimpan" "$(sql "SELECT COUNT(*) FROM siswa WHERE sekolah_id=$S3")" "2"
cek "siswa masuk kelas X-A" "$(sql "SELECT COUNT(*) FROM siswa WHERE sekolah_id=$S3 AND kelas_id IS NOT NULL")" "2"
cek "akun siswa dari kolom Akun dibuat" "$(sql "SELECT COUNT(*) FROM users WHERE sekolah_id=$S3 AND role='siswa'")" "1"

echo "== 4. Lembar cetak =="
for hal in "cetak_guru.php" "cetak_siswa.php"; do
  H=$(curl -s -b "$J3" -o /tmp/cetak.html -w '%{http_code}' "$BASE/$hal")
  cek "$hal (200)" "$H" "200"
  cari2 "$(cat /tmp/cetak.html)" "Cetak / Simpan PDF" "$hal punya tombol cetak"
done
cari2 "$(cat /tmp/cetak.html 2>/dev/null)" "Kepala Sekolah/Madrasah" "lembar cetak memuat tanda tangan kepala"

echo "== 5. Operator/sekretaris sekolah kedua tidak melihat data sekolah lain =="
T=$(csrf "$J3" hasil.php)
curl -s -b "$J3" -o /tmp/hs.html "$BASE/hasil.php"
tidakk "$(cat /tmp/hs.html)" "Ujian Uji Otomatis" "ujian sekolah lain tidak terlihat"

echo "== 6. Penguncian masa aktif & kode aktivasi =="
masuk "$JS" admin admin123
S1=$(sql "SELECT id FROM sekolah WHERE nama='MA Uji CBT'")
# Habiskan masa aktif sekolah 1 (lewat salinan basis data uji, bukan data nyata)
# Catatan: blok PHP ditulis sebagai here-doc supaya tanda kutip di dalam SQL tidak
# bertabrakan dengan kutipan skrip bash.
php -d extension=pdo -d extension=pdo_sqlite <<PHP
<?php
\$p = new PDO('sqlite:${DB}');
\$p->exec("UPDATE sekolah SET aktivasi = 'aktif', aktif_sampai = datetime('now','-1 day') WHERE id = ${S1}");
PHP
JOP=/tmp/cbt_op.jar
masuk "$JOP" operator1 sandi12345
H=$(curl -s -b "$JOP" -o /dev/null -w '%{http_code} %{redirect_url}' "$BASE/index.php")
cari2 "$H" "aktivasi.php" "sekolah kedaluwarsa dialihkan ke halaman aktivasi"
H=$(curl -s -b "$JOP" -o /dev/null -w '%{http_code}' "$BASE/guru.php")
cek "halaman lain ikut terkunci (302)" "$H" "302"
H=$(curl -s -b "$JOP" -o /tmp/akt.html -w '%{http_code}' "$BASE/aktivasi.php")
cek "halaman aktivasi tetap dapat dibuka (200)" "$H" "200"
cari2 "$(cat /tmp/akt.html)" "Aplikasi terkunci\|Aplikasi Terkunci" "keterangan terkunci tampil"
cari2 "$(cat /tmp/akt.html)" "Kode Aktivasi" "formulir kode aktivasi tersedia"

# Super Admin melihat kodenya
T=$(csrf "$JS" "sekolah.php?edit=$S1")
curl -s -b "$JS" -c "$JS" -o /tmp/sk.html "$BASE/sekolah.php?edit=$S1"
KODE=$(grep -o 'id="kodeLembaga">[^<]*' /tmp/sk.html | sed 's/.*>//')
if [ -n "$KODE" ]; then ok "Super Admin melihat kode lembaga ($KODE)"; else no "kode lembaga tidak tampil"; fi

# Operator memasukkan kode (huruf kecil + spasi)
T=$(csrf "$JOP" aktivasi.php)
RENDAH=$(printf '%s' "$KODE" | tr 'A-Z' 'a-z' | sed 's/\(....\)/\1 /g')
H=$(curl -s -b "$JOP" -c "$JOP" -o /dev/null -w '%{http_code} %{redirect_url}' -d "csrf=$T" -d 'aksi=aktifkan' \
    --data-urlencode "kode=$RENDAH" "$BASE/aktivasi.php")
cari2 "$H" "aktivasi.php" "aktivasi berhasil (dialihkan)"
cek "keadaan sekolah menjadi aktif" "$(sql "SELECT aktivasi FROM sekolah WHERE id=$S1")" "aktif"
H=$(curl -s -b "$JOP" -o /dev/null -w '%{http_code}' "$BASE/guru.php")
cek "setelah aktivasi halaman terbuka lagi (200)" "$H" "200"
cek "kode berganti untuk periode berikutnya" "$(sql "SELECT aktivasi_ke FROM sekolah WHERE id=$S1")" "2"

echo "== 7. Kode salah ditolak & kode lama tidak berlaku =="
# Catatan: blok PHP ditulis sebagai here-doc supaya tanda kutip di dalam SQL tidak
# bertabrakan dengan kutipan skrip bash.
php -d extension=pdo -d extension=pdo_sqlite <<PHP
<?php
\$p = new PDO('sqlite:${DB}');
\$p->exec("UPDATE sekolah SET aktivasi = 'aktif', aktif_sampai = datetime('now','-1 day') WHERE id = ${S1}");
PHP
T=$(csrf "$JOP" aktivasi.php)
curl -sL -b "$JOP" -c "$JOP" -o /tmp/akt2.html -d "csrf=$T" -d 'aksi=aktifkan' -d 'kode=ZZZZ-ZZZZ-ZZZZ' "$BASE/aktivasi.php"
cari2 "$(cat /tmp/akt2.html)" "tidak cocok" "kode salah ditolak"
T=$(csrf "$JOP" aktivasi.php)
curl -s -b "$JOP" -c "$JOP" -o /dev/null -d "csrf=$T" -d 'aksi=aktifkan' --data-urlencode "kode=$KODE" "$BASE/aktivasi.php"
cek "kode periode lama ditolak" "$(sql "SELECT aktivasi FROM sekolah WHERE id=$S1")" "aktif"
# kembalikan sekolah 1 ke aktif permanen pada basis data UJI agar penguncian tidak
# mengganggu pemeriksaan hak akses di bawah
php -d extension=pdo -d extension=pdo_sqlite <<PHP
<?php
\$p = new PDO('sqlite:${DB}');
\$p->exec("UPDATE sekolah SET aktivasi = 'permanen', aktif_sampai = NULL WHERE id = ${S1}");
PHP
cek "sekolah uji kembali permanen" "$(sql "SELECT aktivasi FROM sekolah WHERE id=$S1")" "permanen"

echo "== 8. Super Admin: tambah sekolah manual (masa aktif 365 hari, BUKAN permanen) =="
# Permintaan pemilik: sekolah yang dibuat Super Admin diberi masa aktif 365 hari + hitung mundur;
# keadaan "permanen" hanya bila Super Admin memilihnya sendiri pada kartu Aktivasi & Kode Lembaga.
T=$(csrf "$JS" akun.php)
curl -s -b "$JS" -c "$JS" -o /dev/null -d "csrf=$T" -d 'aksi=tambah' --data-urlencode 'nama=Sekolah Manual CBT' \
  -d 'jenjang=SD/MI' --data-urlencode 'kepala=Kepala Manual' -d 'kkm=70' "$BASE/sekolah.php"
S4=$(sql "SELECT id FROM sekolah WHERE nama='Sekolah Manual CBT'")
cek "sekolah manual tersimpan" "$([ -n "$S4" ] && echo ada)" "ada"
cek "sekolah manual ber-masa-aktif (bukan permanen)" "$(sql "SELECT aktivasi FROM sekolah WHERE id=$S4")" "percobaan"
MASA4=$(sql "SELECT CAST(julianday(aktif_sampai) - julianday('now') AS INTEGER) FROM sekolah WHERE id=$S4")
if [ "$MASA4" -ge 364 ] && [ "$MASA4" -le 366 ]; then ok "masa aktif sekolah manual 365 hari ($MASA4 hari)"; else no "masa aktif sekolah manual ($MASA4 hari)"; fi
KARTU=$(curl -s -b "$JS" -c "$JS" "$BASE/sekolah.php?edit=$S4")
cari2 "$KARTU" 'Masa aktif 36[0-9] hari lagi' "kartu Super Admin menampilkan sisa hari (bukan permanen)"
HALOPS=$(curl -s -b "$JS" -c "$JS" "$BASE/aktivasi.php?sekolah=$S4" || true)

echo "== 9. Hak akses Super Admin =="
for hal in index.php sekolah.php pengguna.php; do
  H=$(curl -s -b "$JS" -o /dev/null -w '%{http_code}' "$BASE/$hal")
  cek "super $hal (200)" "$H" "200"
done
H=$(curl -s -b "$JOP" -o /dev/null -w '%{http_code}' "$BASE/sekolah.php")
cek "operator tidak boleh membuka Kelola Sekolah (403)" "$H" "403"

echo
echo "===== HASIL: $LULUS lulus, $GAGAL gagal ====="
[ "$GAGAL" -eq 0 ] || exit 1
