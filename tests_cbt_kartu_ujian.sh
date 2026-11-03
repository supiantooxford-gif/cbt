#!/usr/bin/env bash
# =====================================================================================
# Uji fitur v1.4.0:
#   A. SANDI SIAP-BAGI pada menu Data Siswa (tetapkan sandi; hanya tersimpan di sesi)
#   B. CETAK KARTU UJIAN (Nama, Jenis Kelamin, Kelas, Akun, Password) — 2x4 kartu per A4
#   C. CETAK DATA SISWA memuat kolom Password SETELAH kolom Akun
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
  for _p in $(seq 8680 8719); do
    if ! (exec 3<>"/dev/tcp/127.0.0.1/$_p") 2>/dev/null; then PORT=$_p; break; fi
    exec 3<&- 2>/dev/null || true
  done
  [ -n "$PORT" ] || { echo "Tidak ada port bebas"; exit 1; }
  BASE="http://127.0.0.1:$PORT"
  KERJA="/tmp/cbt_ku_$$"
  rm -rf "$KERJA"; mkdir -p "$KERJA"
  cp -r "$DIR"/. "$KERJA"/
  rm -f "$KERJA"/data/cbt.sqlite* "$KERJA"/data/.schema_ok
  mkdir -p "$KERJA/data/sessions"; chmod 777 "$KERJA/data" "$KERJA/data/sessions" 2>/dev/null || true
  CBT_DB_FILE="$KERJA/data/cbt.sqlite" php -d extension=pdo -d extension=pdo_sqlite "$KERJA/fixture_cbt.php" >/dev/null
  # tambah beberapa siswa supaya kartu uji punya isi lebih banyak
  CBT_DB_FILE="$KERJA/data/cbt.sqlite" php -d extension=pdo -d extension=pdo_sqlite -r '
  require "'"$KERJA"'/inc/init.php";
  for ($i = 1; $i <= 6; $i++) {
    $nis = "KU000$i";
    if (!val("SELECT id FROM siswa WHERE sekolah_id = 1 AND nis = ?", [$nis])) {
      q("INSERT INTO siswa (sekolah_id, nis, nisn, nama, jenis_kelamin, kelas_id, wali, aktif, status, dibuat)
         VALUES (1,?,?,?,?,1,"Wali $i",1,"aktif",?)",
        [$nis, "00$nis", "Kartu Uji $i", ($i % 2 ? "L" : "P"), date("Y-m-d H:i:s")]);
      $sid = (int) db()->lastInsertId();
      q("INSERT INTO users (sekolah_id, nama, username, password, role, siswa_id, aktif, dibuat)
         VALUES (1,?,?,?,?,?,1,?)", ["Kartu Uji $i", "kartu$i", password_hash("siswa123", PASSWORD_DEFAULT), "siswa", $sid, date("Y-m-d H:i:s")]);
    }
  }
  echo "siswa uji tambahan siap\n";' >/dev/null
  php -d extension=pdo -d extension=pdo_sqlite -S "127.0.0.1:$PORT" -t "$KERJA" > "/tmp/cbt_ku_srv_$$.log" 2>&1 &
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

OP=/tmp/cbt_ku_op.jar
masuk "$OP" operator1 sandi12345

# Hak akses diperiksa SEBELUM penetapan sandi massal: penetapan itu memang mengganti sandi
# seluruh siswa (termasuk akun siswa uji), sehingga login siswa harus dilakukan lebih dahulu.
SW=/tmp/cbt_ku_sw.jar
masuk "$SW" siswa1 siswa123
H=$(curl -s -b "$SW" -o /dev/null -w '%{http_code}' "$BASE/cetak_kartu_ujian.php")
cek "siswa tidak boleh mencetak kartu ujian (403)" "$H" "403"
H=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/cetak_kartu_ujian.php")
cek "belum masuk dialihkan (302)" "$H" "302"

echo "== A. Sandi siap-bagi pada Data Siswa =="
H=$(curl -s -b "$OP" -o /tmp/cbt_ku_list.html -w '%{http_code}' "$BASE/siswa.php")
cek "halaman Data Siswa (200)" "$H" "200"
LIST=$(cat /tmp/cbt_ku_list.html)
cari "$LIST" "Sandi Akun untuk Dibagikan" "kartu sandi siap-bagi tampil"
cari "$LIST" "tidak dapat ditampilkan kembali" "penjelasan mengapa sandi perlu ditetapkan"
cari "$LIST" "Cetak Kartu Ujian" "tombol Cetak Kartu Ujian tampil"
# tanpa sandi: kartu ujian mengarahkan kembali dengan pesan
H=$(curl -s -b "$OP" -o /dev/null -w '%{http_code} %{redirect_url}' "$BASE/cetak_kartu_ujian.php")
cari "$H" "siswa.php" "kartu ujian menolak dicetak sebelum sandi disiapkan (dialihkan)"
# lingkup SEMUA wajib SETUJUI
T=$(csrf "$OP" siswa.php)
curl -s -L -b "$OP" -c "$OP" -o /tmp/cbt_ku_konf.html -d "csrf=$T" -d 'aksi=sandi_siap' -d 'mode=acak' -d 'lingkup=semua' "$BASE/siswa.php"
cari "$(cat /tmp/cbt_ku_konf.html)" "ketik SETUJUI" "lingkup SEMUA tanpa konfirmasi ditolak"
# setelah penolakan, sandi akun TIDAK berubah (masih memakai sandi awal siswa123)
MASIH=$(php -d extension=pdo -d extension=pdo_sqlite -r '
$p = new PDO("sqlite:'"$DB"'");
$r = $p->query("SELECT u.password FROM users u JOIN siswa s ON s.id = u.siswa_id WHERE u.sekolah_id = 1 ORDER BY u.id LIMIT 1")->fetchColumn();
echo password_verify("siswa123", (string) $r) ? "masih-awal" : "berubah";')
cek "sandi tidak berubah saat konfirmasi kurang" "$MASIH" "masih-awal"
# tetapkan sandi acak untuk semua siswa (dengan konfirmasi)
T=$(csrf "$OP" siswa.php)
curl -s -L -b "$OP" -c "$OP" -o /tmp/cbt_ku_set.html -d "csrf=$T" -d 'aksi=sandi_siap' -d 'mode=acak' \
  -d 'lingkup=semua' -d 'konfirmasi=SETUJUI' "$BASE/siswa.php"
SET=$(cat /tmp/cbt_ku_set.html)
cari "$SET" "sandi ditetapkan\|Sandi Akun untuk Dibagikan" "sandi berhasil ditetapkan"
cari "$SET" "kotak-kode" "daftar sandi ditampilkan pada halaman"
JUMLAH_SANDI=$(printf '%s' "$SET" | grep -o 'id="sandi[0-9]*"' | wc -l)
if [ "$JUMLAH_SANDI" -gt 0 ]; then ok "daftar sandi memuat $JUMLAH_SANDI baris"; else no "daftar sandi kosong"; fi
# sandi benar-benar mengganti kata sandi akun (verifikasi hash di salinan)
VERIF=$(php -d extension=pdo -d extension=pdo_sqlite -r '
$p = new PDO("sqlite:'"$DB"'");
$r = $p->query("SELECT u.password FROM users u JOIN siswa s ON s.id = u.siswa_id WHERE u.sekolah_id = 1 ORDER BY u.id LIMIT 1")->fetchColumn();
echo password_verify("siswa123", (string) $r) ? "lama" : "sudah-diganti";')
cek "kata sandi akun benar-benar diganti (bukan sandi lama)" "$VERIF" "sudah-diganti"
# kata sandi TIDAK ditulis ke basis data dalam bentuk teks biasa
TEKS=$(php -d extension=pdo -d extension=pdo_sqlite -r '
$p = new PDO("sqlite:'"$DB"'");
$n = 0;
foreach ($p->query("SELECT password FROM users WHERE sekolah_id = 1") as $r) {
  if (strlen((string) $r["password"]) < 40) { $n++; }
}
echo $n;')
cek "tidak ada kata sandi yang disimpan sebagai teks biasa" "$TEKS" "0"

echo "== B. CETAK KARTU UJIAN =="
H=$(curl -s -b "$OP" -o /tmp/cbt_ku_kartu.html -w '%{http_code}' "$BASE/cetak_kartu_ujian.php")
cek "halaman kartu ujian (200)" "$H" "200"
KARTU=$(cat /tmp/cbt_ku_kartu.html)
for isi in "KARTU UJIAN" "Jenis Kelamin" "Kelas" "Akun" "Password"; do
  cari "$KARTU" "$isi" "kartu ujian memuat isi: $isi"
done
cari "$KARTU" "ku-sheet" "kartu disusun dalam kisi (grid) lembar A4"
cari "$KARTU" "ku-sandi" "sandi tercetak pada kartu"
cari "$KARTU" "print-color-adjust" "warna kartu ikut tercetak"
JML_KARTU=$(printf '%s' "$KARTU" | grep -o 'class="ku-kartu"' | wc -l)
if [ "$JML_KARTU" -ge 2 ]; then ok "jumlah kartu pada lembar: $JML_KARTU"; else no "kartu tidak terbentuk ($JML_KARTU)"; fi
cari "$KARTU" "Simpan/cetak berkas ini sebelum menutup sesi" "peringatan menyimpan berkas tampil"
# saringan kelas dihormati
KELAS=$(sql "SELECT id FROM kelas ORDER BY id LIMIT 1")
H=$(curl -s -b "$OP" "$BASE/cetak_kartu_ujian.php?kelas=$KELAS")
JML2=$(printf '%s' "$H" | grep -o 'class="ku-kartu"' | wc -l)
if [ "$JML2" -le "$JML_KARTU" ]; then ok "saringan kelas mempersempit kartu ($JML2 dari $JML_KARTU)"; else no "saringan kelas tidak bekerja"; fi
echo "== C. CETAK DATA SISWA + kolom Password =="
H=$(curl -s -b "$OP" -o /tmp/cbt_ku_ds.html -w '%{http_code}' "$BASE/cetak_siswa.php")
cek "halaman cetak data siswa (200)" "$H" "200"
DS=$(cat /tmp/cbt_ku_ds.html)
cari "$DS" "<th>Password</th>" "kolom Password ada pada cetak data siswa"
# urutan: Password harus SETELAH Akun
URUT=$(printf '%s' "$DS" | tr -d '\n' | grep -o '<th>Akun</th><th>Password</th>' | head -1)
cek "kolom Password tepat SETELAH kolom Akun" "$URUT" "<th>Akun</th><th>Password</th>"
cari "$DS" "sandi tercetak" "ringkasan menyebut jumlah sandi tercetak"
cari "$DS" "sandi tidak dapat ditampilkan lagi\|Sandi tidak dapat ditampilkan\|sandi tidak dapat" "catatan keamanan pada lembar cetak"
# jumlah kolom tabel konsisten (th vs td baris data)
TH=$(printf '%s' "$DS" | sed -n '/<thead>/,/<\/thead>/p' | grep -o '<th[ >]' | wc -l)
cek "jumlah kolom judul = 12" "$TH" "12"
# tanpa sandi (sesi operator lain) kolom Password tetap ada & bertanda "-"
OP2=/tmp/cbt_ku_op2.jar
masuk "$OP2" admin admin123
curl -s -b "$OP2" -c "$OP2" -o /dev/null "$BASE/sekolah.php?masuk=1"
H2=$(curl -s -b "$OP2" "$BASE/cetak_siswa.php")
cari "$H2" "<th>Password</th>" "kolom Password tetap ada pada sesi yang belum menyiapkan sandi"
cari "$H2" 'color:#777">-<' "sandi kosong ditandai tanda hubung"
cari "$H2" "belum disiapkan" "catatan cara menyiapkan sandi tampil"

echo
echo "===== HASIL: $LULUS lulus, $GAGAL gagal ====="
[ "$GAGAL" -eq 0 ] || exit 1
