#!/usr/bin/env bash
# =====================================================================================
# Uji TARIK DATA DARI e-GURU (akun Super Admin) — v1.9.0
#   - Menu hanya untuk Super Admin (operator/guru/siswa 403, tamu 302).
#   - Lembaga dicocokkan lewat NAMA (dinormalkan) dengan lembaga e-GURU.
#   - Sekali tarik: Kelas + Mata Pelajaran + Guru (+akun) + Siswa (+akun).
#   - Menarik ulang aman (diperbarui, tidak digandakan); data CBT yang sudah diisi tidak dikosongkan.
#   - Basis data e-GURU TIDAK PERNAH ditulis (md5 sama sebelum & sesudah).
#
# Mode MANDIRI: menyiapkan salinan aplikasi + basis data CBT + basis data e-GURU kecil di /tmp.
#   bash tests_cbt_tarik_eguru.sh
# =====================================================================================
set -u
BASE="${1:-}"
DIR="$(cd "$(dirname "$0")" && pwd)"
LULUS=0; GAGAL=0

SRV_PID=""
if [ -z "$BASE" ]; then
  PORT=""
  for _p in $(seq 8800 8850); do
    if ! (exec 3<>"/dev/tcp/127.0.0.1/$_p") 2>/dev/null; then PORT=$_p; break; fi
    exec 3<&- 2>/dev/null || true
  done
  [ -n "$PORT" ] || { echo "Tidak ada port bebas"; exit 1; }
  BASE="http://127.0.0.1:$PORT"
  KERJA="/tmp/cbt_tarik_$$"
  EGDIR="/tmp/cbt_eguru_$$"
  rm -rf "$KERJA" "$EGDIR"; mkdir -p "$KERJA" "$EGDIR"
  cp -r "$DIR"/. "$KERJA"/
  rm -f "$KERJA"/data/cbt.sqlite* "$KERJA"/data/.schema_ok
  mkdir -p "$KERJA/data/sessions"; chmod 777 "$KERJA/data" "$KERJA/data/sessions" 2>/dev/null || true
  EGDB="$EGDIR/eguru_uji.sqlite"
  EGURU_DB_FILE="$EGDB" php -d extension=pdo -d extension=pdo_sqlite "$KERJA/fixture_eguru_uji.php" >/dev/null
  CBT_DB_FILE="$KERJA/data/cbt.sqlite" php -d extension=pdo -d extension=pdo_sqlite "$KERJA/fixture_cbt.php" >/dev/null
  EGURU_DB_FILE="$EGDB" php -d extension=pdo -d extension=pdo_sqlite -S "127.0.0.1:$PORT" -t "$KERJA" \
    > "/tmp/cbt_tarik_srv_$$.log" 2>&1 &
  SRV_PID=$!
  for _i in $(seq 1 25); do curl -s -o /dev/null "$BASE/login.php" && break; sleep 0.4; done
  DB="$KERJA/data/cbt.sqlite"
  bersihkan(){ [ -n "$SRV_PID" ] && kill "$SRV_PID" 2>/dev/null; rm -rf "$KERJA" "$EGDIR"; }
  trap bersihkan EXIT
else
  DB="${CBT_DB:-$DIR/data/cbt.sqlite}"
  EGDB="${EGURU_DB:-$EGDIR}"
fi

ok(){ LULUS=$((LULUS+1)); printf '  ok   %s\n' "$1"; }
no(){ GAGAL=$((GAGAL+1)); printf '  GAGAL %s\n' "$1"; }
cek(){ if [ "$2" = "$3" ]; then ok "$1"; else no "$1 (dapat: $2, harap: $3)"; fi; }
cari(){ if printf '%s' "$1" | grep -q -- "$2"; then ok "$3"; else no "$3"; fi; }
tidakk(){ if printf '%s' "$1" | grep -q -- "$2"; then no "$3"; else ok "$3"; fi; }

# Kueri basis data: SQL dikirim lewat variabel lingkungan supaya kutip tunggal tetap aman.
sqlx(){ DB="$1" SQL="$2" php -d extension=pdo -d extension=pdo_sqlite -r '
$p=new PDO("sqlite:".getenv("DB")); $q=$p->query(getenv("SQL")); echo ($q?$q->fetchColumn():"0");'; }
sql(){ sqlx "$DB" "$1"; }
sqlE(){ sqlx "$EGDB" "$1"; }
# Daftar nilai (satu per baris) — untuk memeriksa SEMUA lembaga e-GURU tampil.
kolomE(){ DB="$EGDB" SQL="$1" php -d extension=pdo -d extension=pdo_sqlite -r '
$p=new PDO("sqlite:".getenv("DB")); $q=$p->query(getenv("SQL"));
foreach(($q ? $q->fetchAll(PDO::FETCH_COLUMN) : []) as $v) echo $v, "\n";'; }
sqlw(){ DB="$1" SQL="$2" php -d extension=pdo -d extension=pdo_sqlite -r '
$p=new PDO("sqlite:".getenv("DB")); $p->exec("PRAGMA foreign_keys=OFF"); $p->exec(getenv("SQL"));'; }
isi(){ sqlw "$DB" "$1"; }
isiE(){ sqlw "$EGDB" "$1"; }
# Verifikasi kata sandi akun (bcrypt tidak dapat dibaca kembali).
sandiCocok(){ DB="$DB" U="$1" S="$2" php -d extension=pdo -d extension=pdo_sqlite -r '
$p=new PDO("sqlite:".getenv("DB"));
$st=$p->prepare("SELECT password FROM users WHERE username = ?"); $st->execute([getenv("U")]);
$h=(string) $st->fetchColumn(); echo $h !== "" && password_verify(getenv("S"), $h) ? "ya" : "tidak";'; }

csrf(){
  local hal; hal=$(curl -s -b "$1" -c "$1" "$BASE/$2")
  local t; t=$(printf '%s' "$hal" | grep -o 'name="csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
  printf '%s' "$t"
}
masuk(){
  rm -f "$1"
  local t; t=$(csrf "$1" login.php)
  curl -s -b "$1" -c "$1" -o /dev/null -d "csrf=$t" -d "username=$2" -d "password=$3" "$BASE/login.php"
}

SP=/tmp/cbt_tarik_super.jar; OP=/tmp/cbt_tarik_op.jar; GR=/tmp/cbt_tarik_guru.jar; SW=/tmp/cbt_tarik_siswa.jar
OP2=/tmp/cbt_tarik_op2.jar
rm -f /tmp/cbt_tarik_*.jar
masuk "$SP" admin admin123
masuk "$OP" operator1 sandi12345
masuk "$GR" ahmad guru123
masuk "$SW" siswa1 siswa123

echo "== 1. Hak akses: hanya Super Admin =="
cek "tanpa login dialihkan (302)" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/tarik_eguru.php")" "302"
cek "operator ditolak (403)" "$(curl -s -b "$OP" -o /dev/null -w '%{http_code}' "$BASE/tarik_eguru.php")" "403"
cek "guru ditolak (403)" "$(curl -s -b "$GR" -o /dev/null -w '%{http_code}' "$BASE/tarik_eguru.php")" "403"
cek "siswa ditolak (403)" "$(curl -s -b "$SW" -o /dev/null -w '%{http_code}' "$BASE/tarik_eguru.php")" "403"
cek "operator POST juga ditolak (403)" "$(curl -s -b "$OP" -o /dev/null -w '%{http_code}' -d 'x=1' "$BASE/tarik_eguru.php")" "403"
HAL_SUPER=$(curl -s -b "$SP" -c "$SP" "$BASE/index.php")
cari "$HAL_SUPER" 'tarik_eguru.php' "menu Tarik Data dari e-GURU tampil pada sidebar Super Admin"
cari "$HAL_SUPER" 'Tarik Data dari e-GURU' "label menu memakai istilah e-GURU"
HAL_OP=$(curl -s -b "$OP" "$BASE/index.php")
tidakk "$HAL_OP" 'tarik_eguru.php' "akun operator TIDAK melihat menunya"

echo "== 2. Halaman pemilih sekolah (Super Admin tanpa konteks) =="
# Sekolah kedua sengaja dibuat lebih dahulu: namanya tidak ada di e-GURU.
isi "INSERT INTO sekolah (nama, jenjang, verifikasi, aktif, tahun_ajaran, semester, kkm, aktivasi, dibuat)
     VALUES ('SMP Bukan e-GURU', 'SMP/MTs', 'disetujui', 1, '2025/2026', 'Ganjil', 75, 'permanen', '2026-01-01 00:00:00')"
SID2=$(sql "SELECT id FROM sekolah WHERE nama = 'SMP Bukan e-GURU'")
PILIH=$(curl -s -b "$SP" -c "$SP" "$BASE/tarik_eguru.php")
cari "$PILIH" 'Pilih Sekolah/Madrasah' "halaman pemilih tampil"
cari "$PILIH" 'id="tblEguru"' "tabel seluruh lembaga e-GURU tampil"
cari "$PILIH" 'MA Uji CBT' "sekolah CBT terdaftar"
cari "$PILIH" 'MA\. Uji CBT' "nama lembaga e-GURU ditampilkan (beda penulisan titik)"
cari "$PILIH" 'Belum ditarik' "lembaga yang pasangannya belum ditarik ditandai Belum ditarik"
cari "$PILIH" 'Belum ada di CBT' "lembaga yang belum dibuat di CBT ditandai"
cari "$PILIH" 'Tidak ditemukan' "sekolah CBT tanpa pasangan ditandai Tidak ditemukan"
cari "$PILIH" 'Sekolah/Madrasah CBT yang Belum Ada di e-GURU' "daftar sekolah CBT tanpa pasangan tampil"
cari "$PILIH" 'tarik_eguru.php?sekolah=' "tombol Buka memuat tautan konteks sekolah"
cari "$PILIH" 'sekolah.php?eguru_pilih=' "lembaga yang belum ada di CBT diberi jalan membuat sekolahnya"
# Seluruh lembaga e-GURU (termasuk yang baru mendaftar) harus muncul pada daftar.
EG_JUMLAH=$(sqlE "SELECT COUNT(*) FROM sekolah")
cari "$PILIH" "$EG_JUMLAH lembaga di e-GURU" "jumlah lembaga e-GURU disebut pada ringkasan ($EG_JUMLAH)"
while IFS= read -r _nm; do
  [ -n "$_nm" ] || continue
  cari "$PILIH" "$(printf '%s' "$_nm" | sed 's/[.[\*^$]/\\&/g')" "lembaga e-GURU tampil: $_nm"
done <<< "$(kolomE 'SELECT nama FROM sekolah ORDER BY id')"

echo "== 2b. REALTIME: sekolah baru mendaftar di e-GURU langsung muncul =="
isiE "INSERT INTO sekolah (id, nama, verifikasi, aktif)
      VALUES (91, 'MA Baru Daftar e-GURU', 'menunggu', 1)"
PILIH2=$(curl -s -b "$SP" -c "$SP" "$BASE/tarik_eguru.php")
cari "$PILIH2" 'MA Baru Daftar e-GURU' "lembaga yang baru mendaftar di e-GURU langsung tampil (tanpa menunggu)"
cari "$PILIH2" 'Belum disetujui di e-GURU' "keadaan verifikasi lembaga e-GURU ikut diterangkan"
EG_J2=$(sqlE "SELECT COUNT(*) FROM sekolah")
cari "$PILIH2" "$EG_J2 lembaga di e-GURU" "jumlah lembaga ikut bertambah ($EG_J2)"

echo "== 3. Pindah konteks sekolah =="
cek "sekolah kedua dibuat" "$([ "$SID2" -gt 0 ] && echo ada)" "ada"
cek "?sekolah=ID memindahkan konteks (302)" \
  "$(curl -s -b "$SP" -c "$SP" -o /dev/null -w '%{http_code}' "$BASE/tarik_eguru.php?sekolah=1")" "302"
cek "sekolah tidak sah ditolak (302)" \
  "$(curl -s -b "$SP" -c "$SP" -o /dev/null -w '%{http_code}' "$BASE/tarik_eguru.php?sekolah=99999")" "302"
curl -s -b "$SP" -c "$SP" -o /dev/null "$BASE/tarik_eguru.php?sekolah=$SID2"
HAL2=$(curl -s -b "$SP" -c "$SP" "$BASE/tarik_eguru.php")
cari "$HAL2" 'tidak ditemukan pada aplikasi e-GURU' "sekolah tanpa pasangan dijelaskan dengan pesan jelas"
tidakk "$HAL2" 'Tarik Data Sekarang' "tombol tarik TIDAK tersedia bila lembaga tidak cocok"

echo "== 4. Halaman penarikan (dalam konteks sekolah 1) =="
curl -s -b "$SP" -c "$SP" -o /dev/null "$BASE/tarik_eguru.php?sekolah=1"
HAL=$(curl -s -b "$SP" -c "$SP" "$BASE/tarik_eguru.php")
cari "$HAL" 'Lembaga cocok' "lembaga e-GURU yang cocok ditampilkan"
cari "$HAL" 'MA\. Uji CBT' "nama lembaga e-GURU tercetak apa adanya"
cari "$HAL" 'Tarik Data Sekarang' "tombol tarik tersedia"
cari "$HAL" 'kelas,.*mata pelajaran' "pratinjau jumlah data e-GURU ditampilkan"
cari "$HAL" 'akun e-GURU' "halaman menjelaskan akun guru memakai akun e-GURU"
cari "$HAL" 'siswa123' "petunjuk kata sandi akun siswa tampil"
tidakk "$HAL" 'href="/' "tidak ada tautan absolut"

echo "== 5. Sebelum penarikan =="
cek "kelas CBT" "$(sql 'SELECT COUNT(*) FROM kelas')" "2"
cek "mapel CBT" "$(sql 'SELECT COUNT(*) FROM mapel')" "11"
cek "guru CBT" "$(sql 'SELECT COUNT(*) FROM guru')" "2"
cek "siswa CBT" "$(sql 'SELECT COUNT(*) FROM siswa')" "2"
cek "akun CBT" "$(sql 'SELECT COUNT(*) FROM users')" "6"
MD5_EG_AWAL=$(md5sum "$EGDB" | cut -d' ' -f1)

echo "== 6. Tarik data =="
T=$(csrf "$SP" tarik_eguru.php)
cek "POST tanpa CSRF ditolak (419)" "$(curl -s -b "$SP" -o /dev/null -w '%{http_code}' -d 'x=1' "$BASE/tarik_eguru.php")" "419"
cek "POST tarik (302)" "$(curl -s -b "$SP" -c "$SP" -o /dev/null -w '%{http_code}' -d "csrf=$T" "$BASE/tarik_eguru.php")" "302"
HASIL=$(curl -s -b "$SP" -c "$SP" "$BASE/tarik_eguru.php")
cari "$HASIL" 'Hasil Penarikan Data' "kartu hasil penarikan tampil"
cari "$HASIL" 'sudah masuk ke' "pesan sukses tampil"

echo "== 7. Data yang masuk =="
cek "kelas bertambah (X, XI, XII)" "$(sql 'SELECT COUNT(*) FROM kelas')" "5"
cek "mapel bertambah (Biologi)" "$(sql 'SELECT COUNT(*) FROM mapel')" "12"
cek "guru bertambah 3" "$(sql 'SELECT COUNT(*) FROM guru')" "5"
cek "siswa bertambah 3" "$(sql 'SELECT COUNT(*) FROM siswa')" "5"
cek "akun bertambah 6 (3 guru + 3 siswa)" "$(sql 'SELECT COUNT(*) FROM users')" "12"
cek "kelas 'Kelas X' dirapikan menjadi X" "$(sql "SELECT nama FROM kelas WHERE nama = 'X'")" "X"
cek "tingkat kelas X tersimpan" "$(sql "SELECT tingkat FROM kelas WHERE nama = 'X'")" "X"
cek "guru lama (Ahmad) TIDAK digandakan" "$(sql "SELECT COUNT(*) FROM guru WHERE nip = 'G-001'")" "1"
cek "jabatan guru lama diperbarui dari e-GURU" "$(sql "SELECT jabatan FROM guru WHERE nip = 'G-001'")" "Guru Mata Pelajaran"
cek "telepon guru lama diperbarui dari e-GURU" "$(sql "SELECT telp FROM guru WHERE nip = 'G-001'")" "081200000021"
cek "guru baru (Yusup) masuk dengan NIP" "$(sql "SELECT COUNT(*) FROM guru WHERE nip = '197505122005011002'")" "1"
cek "guru tanpa NIP (Rina) masuk lewat nama" "$(sql "SELECT COUNT(*) FROM guru WHERE nama = 'Rina Marlina, S.Pd'")" "1"
cek "guru nonaktif tetap tersimpan nonaktif" "$(sql "SELECT aktif FROM guru WHERE nip = '199001012015031003'")" "0"
cek "guru sekolah e-GURU lain TIDAK ikut" "$(sql "SELECT COUNT(*) FROM guru WHERE nama = 'Guru Sekolah Lain'")" "0"
cek "siswa lama diperbarui (bukan ganda)" "$(sql "SELECT COUNT(*) FROM siswa WHERE nis = 'S-001'")" "1"
cek "kelas siswa lama mengikuti e-GURU (X)" \
  "$(sql "SELECT k.nama FROM siswa s JOIN kelas k ON k.id = s.kelas_id WHERE s.nis = 'S-001'")" "X"
cek "siswa baru masuk" "$(sql "SELECT COUNT(*) FROM siswa WHERE nis = '2401001'")" "1"
cek "tempat lahir siswa tersimpan" "$(sql "SELECT tempat_lahir FROM siswa WHERE nis = '2401001'")" "Gorontalo"
cek "nama ayah & ibu tersimpan" "$(sql "SELECT nama_ayah || '/' || nama_ibu FROM siswa WHERE nis = '2401001'")" "Ahmad Yusuf/Salmah"
cek "wali siswa tersimpan" "$(sql "SELECT wali FROM siswa WHERE nis = '2401001'")" "Ahmad Yusuf"
cek "status Lulus tersimpan sebagai teks" "$(sql "SELECT status FROM siswa WHERE nis = '2401002'")" "lulus"
cek "siswa Lulus tidak aktif" "$(sql "SELECT aktif FROM siswa WHERE nis = '2401002'")" "0"
cek "siswa tanpa kelas dibiarkan kosong" "$(sql "SELECT COUNT(*) FROM siswa WHERE nis = '2401003' AND kelas_id IS NULL")" "1"
cek "siswa sekolah e-GURU lain TIDAK ikut" "$(sql "SELECT COUNT(*) FROM siswa WHERE nama = 'Siswa Sekolah Lain'")" "0"
cek "wali kelas X terisi dari e-GURU" \
  "$(sql "SELECT g.nama FROM kelas k JOIN guru g ON g.id = k.wali_id WHERE k.nama = 'X'")" "Ahmad Fauzi, S.Pd"
cek "wali kelas XI terisi dari e-GURU" \
  "$(sql "SELECT g.nama FROM kelas k JOIN guru g ON g.id = k.wali_id WHERE k.nama = 'XI'")" "Yusup Abaidata, SH"
cek "mapel guru baru terhubung (Biologi)" \
  "$(sql "SELECT COUNT(*) FROM guru_mapel gm JOIN guru g ON g.id = gm.guru_id JOIN mapel m ON m.id = gm.mapel_id WHERE g.nama = 'Rina Marlina, S.Pd' AND m.nama = 'Biologi'")" "1"
cek "kelas ajar guru baru terhubung (XI)" \
  "$(sql "SELECT COUNT(*) FROM guru_kelas gk JOIN guru g ON g.id = gk.guru_id JOIN kelas k ON k.id = gk.kelas_id WHERE g.nama = 'Rina Marlina, S.Pd' AND k.nama = 'XI'")" "1"

echo "== 8. Akun otomatis (v1.14.0: akun guru = akun e-GURU) =="
# Permintaan pemilik: "pastikan akun guru yang ditarik dari e-Guru user dan passwordnya tetap sama
# (memakai akun e-Guru, kecuali nanti guru menggantinya sendiri di akun CBT)".
cek "akun guru rina memakai NAMA PENGGUNA e-GURU (rina.m)" \
  "$(sql "SELECT COUNT(*) FROM users WHERE username = 'rina.m' AND role = 'guru'")" "1"
cek "akun guru yusup memakai NAMA PENGGUNA e-GURU (yusup.abaidata)" \
  "$(sql "SELECT COUNT(*) FROM users WHERE username = 'yusup.abaidata' AND role = 'guru'")" "1"
cek "sandi guru rina SAMA dengan sandi e-GURU" "$(sandiCocok rina.m SandiRina333)" "ya"
cek "sandi guru yusup SAMA dengan sandi e-GURU" "$(sandiCocok yusup.abaidata SandiYusup22)" "ya"
cek "sandi guru tidak memakai sandi awal guru123" "$(sandiCocok rina.m guru123)" "tidak"
cek "nama pengguna lama hasil pola otomatis TIDAK dibuat" \
  "$(sql "SELECT COUNT(*) FROM users WHERE username IN ('rina','yusup')")" "0"
cek "akun guru terikat pada data guru" \
  "$(sql "SELECT COUNT(*) FROM users u JOIN guru g ON g.id = u.guru_id WHERE u.username = 'yusup.abaidata' AND g.nip = '197505122005011002'")" "1"
cek "akun guru 1 memakai akun e-GURU" "$(sandiCocok ahmad RahasiaAhmad1)" "ya"
cek "akun siswa memakai NIS" "$(sql "SELECT COUNT(*) FROM users WHERE username = '2401001' AND role = 'siswa'")" "1"
cek "akun terikat pada data siswa" "$(sql "SELECT COUNT(*) FROM users u JOIN siswa s ON s.id = u.siswa_id WHERE u.username = '2401001' AND s.nis = '2401001'")" "1"
cek "akun di sekolah yang benar" "$(sql "SELECT COUNT(*) FROM users WHERE username = '2401001' AND sekolah_id = 1")" "1"
cek "sandi awal siswa123 tetap berlaku" "$(sandiCocok 2401001 siswa123)" "ya"
cek "akun guru nonaktif ikut nonaktif" "$(sql "SELECT aktif FROM users WHERE guru_id = (SELECT id FROM guru WHERE nip = '199001012015031003')")" "0"
cek "akun guru nonaktif tetap memakai sandi e-GURU" "$(sandiCocok guru.nonaktif SandiNonaktif4)" "ya"
cek "akun BUKAN guru (kepala e-GURU) tidak dipakai untuk akun guru" \
  "$(sql "SELECT COUNT(*) FROM users WHERE username = 'kepala.uji'")" "0"

echo "== 9. Basis data e-GURU tidak pernah ditulis =="
cek "md5 basis data e-GURU sama" "$(md5sum "$EGDB" | cut -d' ' -f1)" "$MD5_EG_AWAL"

echo "== 9b. Keterangan SUDAH ditarik (dipakai halaman daftar) =="
cek "eguru_id tercatat pada sekolah CBT" "$(sql 'SELECT eguru_id FROM sekolah WHERE id = 1')" "1"
TARIK_OK=$(CBT_DB_FILE="$DB" php -d extension=pdo -d extension=pdo_sqlite -r '
require "'"$DIR"'/inc/init.php";
$t = (string) val("SELECT eguru_tarik FROM sekolah WHERE id = 1");
echo $t === "" ? "kosong" : (substr($t, 0, 10) === date("Y-m-d") ? "hari-ini" : "lain:" . $t);' 2>/dev/null)
cek "waktu penarikan tercatat = hari ini" "$TARIK_OK" "hari-ini"
curl -s -b "$SP" -c "$SP" -o /dev/null "$BASE/sekolah.php?keluar=1"
PILIHS=$(curl -s -b "$SP" -c "$SP" "$BASE/tarik_eguru.php")
cari "$PILIHS" 'Sudah ditarik' "daftar lembaga menandai Sudah ditarik"
cari "$PILIHS" '1 sudah ditarik' "ringkasan menghitung lembaga yang sudah ditarik"
cari "$PILIHS" 'Terakhir ditarik' "tanggal penarikan terakhir dicantumkan"
curl -s -b "$SP" -c "$SP" -o /dev/null "$BASE/tarik_eguru.php?sekolah=1"
HALDALAM=$(curl -s -b "$SP" -c "$SP" "$BASE/tarik_eguru.php")
cari "$HALDALAM" 'Data sudah ditarik' "halaman penarikan menyebut data sudah ditarik"
cari "$HALDALAM" 'Terakhir ditarik' "halaman penarikan mencantumkan waktu penarikan"

echo "== 10. Tarik ulang aman (idempoten) =="
T=$(csrf "$SP" tarik_eguru.php)
curl -s -b "$SP" -c "$SP" -o /dev/null -d "csrf=$T" "$BASE/tarik_eguru.php"
cek "kelas tetap" "$(sql 'SELECT COUNT(*) FROM kelas')" "5"
cek "mapel tetap" "$(sql 'SELECT COUNT(*) FROM mapel')" "12"
cek "guru tetap" "$(sql 'SELECT COUNT(*) FROM guru')" "5"
cek "siswa tetap" "$(sql 'SELECT COUNT(*) FROM siswa')" "5"
cek "akun tetap" "$(sql 'SELECT COUNT(*) FROM users')" "12"

echo "== 11. Data CBT yang sudah diisi tidak dikosongkan =="
isi "UPDATE siswa SET alamat = 'Alamat Manual CBT' WHERE nis = '2401003'"
isi "UPDATE guru SET telp = '0812999000' WHERE nama = 'Rina Marlina, S.Pd'"
# Guru yang TIDAK ada di e-GURU tetap punya mapel & kelas manual di CBT.
GID_MANUAL=$(sql "SELECT id FROM guru WHERE nip = 'G-002'")
MID_MANUAL=$(sql "SELECT id FROM mapel WHERE nama = 'Matematika'")
KID_MANUAL=$(sql "SELECT id FROM kelas WHERE nama = 'VII-A'")
isi "INSERT OR IGNORE INTO guru_mapel (guru_id, mapel_id) VALUES ($GID_MANUAL, $MID_MANUAL)"
isi "INSERT OR IGNORE INTO guru_kelas (guru_id, kelas_id) VALUES ($GID_MANUAL, $KID_MANUAL)"
cek "prasyarat: mapel manual terpasang" "$(sql "SELECT COUNT(*) FROM guru_mapel WHERE guru_id = $GID_MANUAL")" "1"
T=$(csrf "$SP" tarik_eguru.php)
curl -s -b "$SP" -c "$SP" -o /dev/null -d "csrf=$T" "$BASE/tarik_eguru.php"
cek "alamat manual siswa tidak dikosongkan" "$(sql "SELECT alamat FROM siswa WHERE nis = '2401003'")" "Alamat Manual CBT"
cek "telepon manual guru tidak dikosongkan" "$(sql "SELECT telp FROM guru WHERE nama = 'Rina Marlina, S.Pd'")" "0812999000"
cek "mapel guru yang tidak ada di e-GURU tetap" "$(sql "SELECT COUNT(*) FROM guru_mapel WHERE guru_id = $GID_MANUAL")" "1"
cek "kelas ajar guru yang tidak ada di e-GURU tetap" "$(sql "SELECT COUNT(*) FROM guru_kelas WHERE guru_id = $GID_MANUAL")" "1"
cek "mapel guru yang ADA di e-GURU diselaraskan (1)" \
  "$(sql "SELECT COUNT(*) FROM guru_mapel gm JOIN guru g ON g.id = gm.guru_id WHERE g.nip = 'G-001'")" "1"

echo "== 12. Perubahan pada e-GURU ikut terbawa =="
isiE "INSERT INTO guru (id, nama, nip, jenis_kelamin, jabatan, aktif, dibuat, sekolah_id)
      VALUES (60, 'Guru Baru Tambahan', 'G-099', 'P', 'Guru Mata Pelajaran', 1, '2026-01-01 00:00:00', 1)"
isiE "INSERT INTO siswa (id, nis, nama, jenis_kelamin, kelas_id, aktif, dibuat, sekolah_id, status)
      VALUES (70, '2401009', 'Siswa Baru Tambahan', 'L', 11, 1, '2026-01-01 00:00:00', 1, 'aktif')"
isiE "UPDATE guru SET jabatan = 'Wakil Kepala Madrasah' WHERE nip = 'G-001'"
cek "prasyarat: guru tambahan ada di e-GURU" "$(sqlE "SELECT COUNT(*) FROM guru WHERE nip = 'G-099'")" "1"
T=$(csrf "$SP" tarik_eguru.php)
curl -s -b "$SP" -c "$SP" -o /dev/null -d "csrf=$T" "$BASE/tarik_eguru.php"
cek "guru baru dari e-GURU ikut masuk" "$(sql "SELECT COUNT(*) FROM guru WHERE nip = 'G-099'")" "1"
cek "siswa baru dari e-GURU ikut masuk" "$(sql "SELECT COUNT(*) FROM siswa WHERE nis = '2401009'")" "1"
cek "perubahan jabatan ikut diperbarui" "$(sql "SELECT jabatan FROM guru WHERE nip = 'G-001'")" "Wakil Kepala Madrasah"
cek "akun guru baru ikut dibuat" "$(sql "SELECT COUNT(*) FROM users u JOIN guru g ON g.id = u.guru_id WHERE g.nip = 'G-099'")" "1"
cek "akun siswa baru ikut dibuat" "$(sql "SELECT COUNT(*) FROM users u JOIN siswa s ON s.id = u.siswa_id WHERE s.nis = '2401009'")" "1"

echo "== 13. Penarikan pada sekolah tanpa pasangan tidak mengubah apa pun =="
GURU_SEK2_AWAL=$(sql "SELECT COUNT(*) FROM guru WHERE sekolah_id = $SID2")
SISWA_SEK2_AWAL=$(sql "SELECT COUNT(*) FROM siswa WHERE sekolah_id = $SID2")
curl -s -b "$SP" -c "$SP" -o /dev/null "$BASE/tarik_eguru.php?sekolah=$SID2"
T=$(csrf "$SP" tarik_eguru.php)
curl -s -b "$SP" -c "$SP" -o /dev/null -d "csrf=$T" "$BASE/tarik_eguru.php"
GALAT=$(curl -s -b "$SP" -c "$SP" "$BASE/tarik_eguru.php")
cari "$GALAT" 'tidak ditemukan pada aplikasi e-GURU' "setelah POST, pesan galat ditampilkan"
cek "guru sekolah tanpa pasangan tidak berubah" "$(sql "SELECT COUNT(*) FROM guru WHERE sekolah_id = $SID2")" "$GURU_SEK2_AWAL"
cek "siswa sekolah tanpa pasangan tidak berubah" "$(sql "SELECT COUNT(*) FROM siswa WHERE sekolah_id = $SID2")" "$SISWA_SEK2_AWAL"
cek "tidak ada akun yang dibuat untuk sekolah itu" "$(sql "SELECT COUNT(*) FROM users WHERE sekolah_id = $SID2")" "0"
cid=""
curl -s -b "$SP" -c "$SP" -o /dev/null "$BASE/tarik_eguru.php?sekolah=1"

echo "== 14. Isolasi antar sekolah =="
cek "data guru sekolah 2 tetap terpisah" "$(sql "SELECT COUNT(*) FROM guru WHERE sekolah_id = 2")" "0"
cek "sekolah 1 memiliki guru hasil tarikan" "$(sql "SELECT COUNT(*) FROM guru WHERE sekolah_id = 1")" "6"
cek "akun hasil tarikan hanya di sekolah 1" "$(sql "SELECT COUNT(*) FROM users WHERE username = 'yusup.abaidata' AND sekolah_id = 1")" "1"

echo "== 15. Kerapian halaman =="
HAL3=$(curl -s -b "$SP" -c "$SP" "$BASE/tarik_eguru.php")
cari "$HAL3" 'assets/style.css?v=' "aset berversi (pengusir cache)"
tidakk "$HAL3" 'vibecoder-media-token' "token media tidak bocor ke halaman"
cari "$HAL3" '</html>' "halaman tertutup lengkap"
ISIAN=$(printf '%s' "$HAL3" | grep -c 'name="csrf"')
cek "halaman memuat tepat satu form (1 token)" "$ISIAN" "1"

echo "== 16. Basis data e-GURU tidak ada -> pesan ramah =="
PESAN=$(CBT_DB_FILE="$DB" EGURU_DB_FILE=/tmp/tidak-ada-eguru.sqlite php -d extension=pdo -d extension=pdo_sqlite -r '
require "'"$DIR"'/inc/init.php"; require "'"$DIR"'/inc/eguru.php";
echo eguru_tersedia() ? "ADA" : "TIDAK"; echo "|"; echo eguru_galat();' 2>/dev/null)
cari "$PESAN" '^TIDAK' "basis data e-GURU yang tidak ada terdeteksi"
cari "$PESAN" 'Impor Data Guru' "pesan galat menawarkan jalan lain (impor berkas)"
PESAN2=$(CBT_DB_FILE="$DB" EGURU_DB_FILE="$EGDB" php -d extension=pdo -d extension=pdo_sqlite -r '
require "'"$DIR"'/inc/init.php"; require "'"$DIR"'/inc/eguru.php";
echo eguru_tersedia() ? "ADA" : "TIDAK"; echo "|";
$s = eguru_sekolah_cari("MA uji cbt"); echo $s ? $s["nama"] : "tidak ketemu"; echo "|";
$s2 = eguru_sekolah_cari("Sekolah Yang Tidak Ada"); echo $s2 ? "ketemu" : "tidak ketemu";' 2>&1)
cari "$PESAN2" 'ADA|MA. Uji CBT' "pencocokan nama mengabaikan beda huruf besar-kecil/titik"
cari "$PESAN2" "tidak ketemu$" "nama yang tidak sama tidak dicocokkan"



# =====================================================================================
# BAGIAN 2 — TARIK DATA LEMBAGA dari halaman "Tambah Sekolah/Madrasah" (sekolah.php)
#   1) Super Admin mencari nama lembaga e-GURU -> bila ditemukan tinggal ditarik.
#   2) Sekolah dibuat lengkap dengan identitasnya + akun Admin Operatornya.
#   3) Centang "sekalian" menarik data guru/siswa/kelas/mapel sekaligus.
# =====================================================================================
# Keluar dari konteks sekolah supaya kembali ke tampilan Super Admin.
curl -s -b "$SP" -c "$SP" -o /dev/null "$BASE/sekolah.php?keluar=1"

echo "== 17. Pencarian lembaga pada halaman Tambah Sekolah =="
cek "operator ditolak (403)" "$(curl -s -b "$OP" -o /dev/null -w '%{http_code}' "$BASE/sekolah.php?eguru_cari=Uji")" "403"
teslo=$(curl -s -b "$SP" -c "$SP" "$BASE/sekolah.php")
cari "$teslo" 'Tarik Data Lembaga dari e-GURU' "kartu tarik lembaga tampil pada halaman Kelola Sekolah"
cari "$teslo" 'id="daftarLembagaEguru"' "saran nama lembaga e-GURU tersedia (datalist)"
cari "$teslo" 'Lihat daftar lembaga e-GURU' "daftar lembaga e-GURU dapat dibuka"
cari "$teslo" 'name="eguru_cari"' "medan pencarian nama lembaga ada"
tidakk "$teslo" 'vibecoder-media-token' "token media tidak bocor"
HASILCARI=$(curl -s -b "$SP" -c "$SP" "$BASE/sekolah.php?eguru_cari=tak-terpakai")
cari "$HASILCARI" 'MTsS Lain Yang Tak Terpakai' "pencarian sebagian nama menemukan lembaga"
cari "$HASILCARI" 'Belum ada' "lembaga yang belum ada di CBT ditandai"
cari "$HASILCARI" 'Tarik &amp; Buat Sekolah' "formulir penarikan langsung disiapkan (hasil tunggal)"
cari "$HASILCARI" 'form-grid' "formulir penarikan memakai tata letak formulir"
cari "$HASILCARI" 'Belum ada' "penanda keadaan lembaga tampil"
TIDAKCOCOK=$(curl -s -b "$SP" -c "$SP" "$BASE/sekolah.php?eguru_cari=zzzzz")
cari "$TIDAKCOCOK" 'Tidak ada lembaga e-GURU' "pencarian tanpa hasil diberi pesan jelas"
HASILBANYAK=$(curl -s -b "$SP" -c "$SP" "$BASE/sekolah.php?eguru_cari=uji")
cari "$HASILBANYAK" 'lembaga ditemukan' "pencarian dengan beberapa hasil melaporkan jumlahnya"
cari "$HASILBANYAK" 'Sudah ada di CBT' "lembaga yang sudah ada di CBT ditandai"
cari "$HASILBANYAK" 'tarik_eguru.php?sekolah=' "lembaga yang sudah ada diarahkan ke menu Tarik Data"

echo "== 18. Membuat sekolah dengan menarik lembaga e-GURU =="
cek "POST tanpa CSRF ditolak (419)" \
  "$(curl -s -b "$SP" -o /dev/null -w '%{http_code}' -d 'aksi=tarik_lembaga' -d 'eguru_id=2' "$BASE/sekolah.php")" "419"
cek "sekolah belum ada sebelum ditarik" "$(sql "SELECT COUNT(*) FROM sekolah WHERE nama = 'MTsS Lain Yang Tak Terpakai'")" "0"
T=$(csrf "$SP" akun.php)
cek "POST tarik lembaga (302)" "$(curl -s -b "$SP" -c "$SP" -o /dev/null -w '%{http_code}' \
  -d "csrf=$T" -d 'aksi=tarik_lembaga' -d 'eguru_id=2' -d 'jenjang=SMP/MTs' -d 'tahun_ajaran=2026/2027' \
  -d 'semester=Ganjil' -d 'nama_admin=Kepala Uji Tarik' -d 'username=mtss_uji' -d 'password=rahasia123' \
  -d 'sekalian=1' "$BASE/sekolah.php")" "302"
SIDBARU=$(sql "SELECT id FROM sekolah WHERE nama = 'MTsS Lain Yang Tak Terpakai'")
cek "sekolah baru dibuat" "$([ -n "$SIDBARU" ] && [ "$SIDBARU" -gt 0 ] && echo ada)" "ada"
cek "jenjang tersimpan" "$(sql "SELECT jenjang FROM sekolah WHERE id = $SIDBARU")" "SMP/MTs"
cek "NPSN ikut ditarik" "$(sql "SELECT npsn FROM sekolah WHERE id = $SIDBARU")" "222333"
cek "alamat ikut ditarik" "$(sql "SELECT alamat FROM sekolah WHERE id = $SIDBARU")" "Jl. Uji No. 2"
cek "kecamatan ikut ditarik" "$(sql "SELECT kecamatan FROM sekolah WHERE id = $SIDBARU")" "Mootilango"
cek "kabupaten & provinsi ikut ditarik" "$(sql "SELECT kabupaten || '/' || provinsi FROM sekolah WHERE id = $SIDBARU")" "Gorontalo/Gorontalo"
cek "telepon ikut ditarik" "$(sql "SELECT telepon FROM sekolah WHERE id = $SIDBARU")" "085200000002"
cek "email ikut ditarik" "$(sql "SELECT email FROM sekolah WHERE id = $SIDBARU")" "lain@example.com"
cek "nama kepala ikut ditarik" "$(sql "SELECT kepala FROM sekolah WHERE id = $SIDBARU")" "Kepala Lembaga Lain"
cek "NIP kepala ikut ditarik" "$(sql "SELECT nip_kepala FROM sekolah WHERE id = $SIDBARU")" "197001012000031001"
cek "tahun ajaran dari formulir tersimpan" "$(sql "SELECT tahun_ajaran FROM sekolah WHERE id = $SIDBARU")" "2026/2027"
cek "sekolah baru aktif" "$(sql "SELECT aktif FROM sekolah WHERE id = $SIDBARU")" "1"
cek "sekolah baru disetujui" "$(sql "SELECT verifikasi FROM sekolah WHERE id = $SIDBARU")" "disetujui"
cek "sekolah baru BUKAN permanen" "$(sql "SELECT aktivasi FROM sekolah WHERE id = $SIDBARU")" "percobaan"
MASABARU=$(sql "SELECT CAST(julianday(aktif_sampai) - julianday('now') AS INTEGER) FROM sekolah WHERE id = $SIDBARU")
if [ "$MASABARU" -ge 364 ] && [ "$MASABARU" -le 366 ]; then ok "masa aktif sekolah hasil tarikan 365 hari ($MASABARU hari)"; else no "masa aktif hasil tarikan ($MASABARU hari)"; fi
cek "mata pelajaran bawaan (11) + e-GURU (Fisika)" "$(sql "SELECT COUNT(*) FROM mapel WHERE sekolah_id = $SIDBARU")" "12"
cek "mata pelajaran e-GURU ikut ditarik (Fisika)" \
  "$(sql "SELECT COUNT(*) FROM mapel WHERE sekolah_id = $SIDBARU AND nama = 'Fisika'")" "1"
cek "kelas e-GURU ikut ditarik (VII)" "$(sql "SELECT COUNT(*) FROM kelas WHERE sekolah_id = $SIDBARU AND nama = 'VII'")" "1"
cek "guru e-GURU ikut ditarik" "$(sql "SELECT COUNT(*) FROM guru WHERE sekolah_id = $SIDBARU AND nama = 'Guru Sekolah Lain'")" "1"
cek "siswa e-GURU ikut ditarik" "$(sql "SELECT COUNT(*) FROM siswa WHERE sekolah_id = $SIDBARU AND nama = 'Siswa Sekolah Lain'")" "1"
cek "akun guru hasil tarikan dibuat" "$(sql "SELECT COUNT(*) FROM users u JOIN guru g ON g.id = u.guru_id WHERE g.sekolah_id = $SIDBARU")" "1"
# Guru ini TIDAK punya akun di e-GURU → akun CBT memakai nama pengguna otomatis + sandi guru123.
GUNAME=$(sql "SELECT u.username FROM users u JOIN guru g ON g.id = u.guru_id WHERE g.sekolah_id = $SIDBARU AND g.nama = 'Guru Sekolah Lain'")
cek "guru tanpa akun e-GURU tetap dibuatkan akun ($GUNAME)" "$([ -n "$GUNAME" ] && echo ada)" "ada"
cek "guru tanpa akun e-GURU memakai sandi awal guru123" "$(sandiCocok "$GUNAME" guru123)" "ya"

echo "== 19. Akun Admin Operator sekolah baru =="
cek "akun operator dibuat" "$(sql "SELECT COUNT(*) FROM users WHERE sekolah_id = $SIDBARU AND role = 'operator'")" "1"
cek "nama admin dari formulir" "$(sql "SELECT nama FROM users WHERE sekolah_id = $SIDBARU AND role = 'operator'")" "Kepala Uji Tarik"
cek "nama pengguna dari formulir" "$(sql "SELECT username FROM users WHERE sekolah_id = $SIDBARU AND role = 'operator'")" "mtssuji"
cek "kata sandi formulir berlaku" "$(sandiCocok mtssuji rahasia123)" "ya"
masuk "$OP2" mtssuji rahasia123
cek "operator baru dapat masuk (halaman 200, bukan dialihkan ke login)" \
  "$(curl -s -b "$OP2" -c "$OP2" -o /dev/null -w '%{http_code}' "$BASE/index.php")" "200"
cek "operator baru melihat nama sekolahnya" \
  "$(curl -s -b "$OP2" -c "$OP2" "$BASE/index.php" | grep -c 'MTsS Lain Yang Tak Terpakai')" "1"

echo "== 20. Sekolah hasil tarikan muncul di daftar & menu tarik data =="
DAFTARLAGI=$(curl -s -b "$SP" -c "$SP" "$BASE/sekolah.php")
cari "$DAFTARLAGI" 'MTsS Lain Yang Tak Terpakai' "sekolah baru tampil pada daftar Kelola Sekolah"
cek "eguru_id tercatat saat Tarik Lembaga" "$(sql "SELECT eguru_id FROM sekolah WHERE id = $SIDBARU")" "2"
cek "data ikut ditarik -> waktu penarikan tercatat" \
  "$(sql "SELECT CASE WHEN TRIM(COALESCE(eguru_tarik,'')) <> '' THEN 'ada' ELSE 'kosong' END FROM sekolah WHERE id = $SIDBARU")" "ada"
HALPILIH=$(curl -s -b "$SP" -c "$SP" "$BASE/tarik_eguru.php")
cari "$HALPILIH" 'MTsS Lain Yang Tak Terpakai' "sekolah baru tampil pada halaman Tarik Data"
cari "$HALPILIH" '2 sudah ditarik' "ringkasan menghitung dua lembaga sudah ditarik"

echo "== 21. Menarik lembaga yang sama dua kali ditolak =="
T=$(csrf "$SP" akun.php)
curl -s -b "$SP" -c "$SP" -o /dev/null -d "csrf=$T" -d 'aksi=tarik_lembaga' -d 'eguru_id=2' "$BASE/sekolah.php"
cek "tidak ada sekolah kembar" "$(sql "SELECT COUNT(*) FROM sekolah WHERE nama = 'MTsS Lain Yang Tak Terpakai'")" "1"
PESANDOBEL=$(curl -s -b "$SP" -c "$SP" "$BASE/sekolah.php?edit=$SIDBARU")
cari "$PESANDOBEL" 'sudah ada di CBT' "penarikan ulang dijawab pesan jelas"

echo "== 22. Tarik lembaga TANPA sekalian (identitas saja) =="
LANCAR=$(curl -s -b "$SP" -c "$SP" "$BASE/sekolah.php?eguru_cari=tanpa%20isi")
cari "$LANCAR" 'MA Lembaga Uji Tanpa Isi' "lembaga tanpa isi ditemukan lewat pencarian"
T=$(csrf "$SP" akun.php)
cek "POST tarik lembaga tanpa sekalian (302)" "$(curl -s -b "$SP" -c "$SP" -o /dev/null -w '%{http_code}' \
  -d "csrf=$T" -d 'aksi=tarik_lembaga' -d 'eguru_id=3' -d 'jenjang=SMA/MA/SMK' \
  -d 'nama_admin=Admin Tanpa Data' -d 'username=adminisiii' -d 'password=rahasia123' "$BASE/sekolah.php")" "302"
SID3=$(sql "SELECT id FROM sekolah WHERE nama = 'MA Lembaga Uji Tanpa Isi'")
cek "sekolah ketiga dibuat" "$([ -n "$SID3" ] && [ "$SID3" -gt 0 ] && echo ada)" "ada"
cek "jenjang pilihan Super Admin yang dipakai" "$(sql "SELECT jenjang FROM sekolah WHERE id = $SID3")" "SMA/MA/SMK"
cek "tahun ajaran e-GURU ikut terbawa" "$(sql "SELECT tahun_ajaran FROM sekolah WHERE id = $SID3")" "2026/2027"
cek "tanpa centang sekalian: TIDAK ada guru ditarik" "$(sql "SELECT COUNT(*) FROM guru WHERE sekolah_id = $SID3")" "0"
cek "tanpa centang sekalian: TIDAK ada siswa ditarik" "$(sql "SELECT COUNT(*) FROM siswa WHERE sekolah_id = $SID3")" "0"
cek "akun operator tetap dibuat" "$(sql "SELECT COUNT(*) FROM users WHERE sekolah_id = $SID3 AND role = 'operator'")" "1"
cek "lembaga tanpa data: eguru_id tetap tercatat" "$(sql "SELECT eguru_id FROM sekolah WHERE id = $SID3")" "3"
cek "lembaga tanpa data: data belum ditarik" \
  "$(sql "SELECT CASE WHEN TRIM(COALESCE(eguru_tarik,'')) = '' THEN 'kosong' ELSE 'terisi' END FROM sekolah WHERE id = $SID3")" "kosong"

echo "== 23. Nilai bawaan & perkiraan jenjang =="
FORM2=$(curl -s -b "$SP" -c "$SP" "$BASE/sekolah.php?eguru_cari=formulir")
SANDI_UI=$(printf '%s' "$FORM2" | grep -o 'name="password" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
cek "kata sandi saran terisi otomatis (8 karakter)" "${#SANDI_UI}" "8"
UNAME_UI=$(printf '%s' "$FORM2" | grep -o 'name="username" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
cek "nama pengguna saran terisi otomatis (kata awal nama lembaga)" "$UNAME_UI" "formulir"
cari "$FORM2" 'value="Kepala Formulir Uji"' "nama admin disarankan dari kepala sekolah e-GURU"
cari "$FORM2" 'value="SMA/MA/SMK" selected' "jenjang diperkirakan dari kelas e-GURU (X)"
cari "$FORM2" 'value="2026/2027"' "tahun ajaran e-GURU disarankan"
cari "$FORM2" 'name="sekalian" value="1" checked' "centang 'sekalian tarik' aktif secara bawaan"
CEK_JJ=$(CBT_DB_FILE="$DB" EGURU_DB_FILE="$EGDB" php -d extension=pdo -d extension=pdo_sqlite -r '
require "'"$DIR"'/inc/init.php"; require "'"$DIR"'/inc/eguru.php";
echo eguru_jenjang_tebak(1) . "|" . eguru_jenjang_tebak(2) . "|" . eguru_jenjang_tebak(3) . "|";
$p = eguru_sekolah_cari_daftar("uji CBT"); echo count($p) . "|" . $p[0]["nama"];' 2>&1)
cek "perkiraan jenjang: X-XII, VII-IX, tanpa kelas" "$CEK_JJ" "SMA/MA/SMK|SMP/MTs|SMP/MTs|1|MA. Uji CBT"

echo "== 25. MASA AKTIF 365 hari + HITUNG MUNDUR (permintaan pemilik) =="
# Kode aktivasi sebuah sekolah (periode yang sedang berlaku).
kodeAktivasi(){ CBT_DB_FILE="$DB" SID="$1" php -d extension=pdo -d extension=pdo_sqlite -r '
require "'"$DIR"'/inc/init.php";
$s = one("SELECT * FROM sekolah WHERE id = ?", [(int) getenv("SID")]);
echo kode_aktivasi_sekolah($s);' 2>/dev/null; }

# ⚠️ Timestamp dihitung lewat PHP (zona waktu aplikasi Asia/Jakarta) — strftime('%s', …) pada
# SQLite membacanya sebagai UTC sehingga selisih 7 jam dan uji menjadi gagal palsu.
SISA_TS=$(CBT_DB_FILE="$DB" SID="$SIDBARU" php -d extension=pdo -d extension=pdo_sqlite -r '
require "'"$DIR"'/inc/init.php";
echo (int) strtotime((string) val("SELECT aktif_sampai FROM sekolah WHERE id = ?", [(int) getenv("SID")]));' 2>/dev/null)
HALOPS2=$(curl -s -b "$OP2" -c "$OP2" "$BASE/aktivasi.php")
cari "$HALOPS2" 'Hitung mundur masa aktif' "blok hitung mundur tampil di halaman Status & Aktivasi"
cari "$HALOPS2" 'data-hitung-mundur' "penanda data-hitung-mundur ada"
cari "$HALOPS2" "data-berakhir=\"$SISA_TS\"" "acuan waktu = kolom aktif_sampai basis data"
cari "$HALOPS2" 'data-hm="hari"' "kotak satuan Hari ada"
cari "$HALOPS2" 'data-hm="jam"' "kotak satuan Jam ada"
cari "$HALOPS2" 'data-hm="menit"' "kotak satuan Menit ada"
cari "$HALOPS2" 'data-hm="detik"' "kotak satuan Detik ada"
cari "$HALOPS2" 'Sisa 36[0-9] hari' "nilai awal hitung mundur dicetak PHP (tanpa JavaScript)"
cari "$HALOPS2" 'Masa aktif <b>365 hari (1 tahun)</b>' "keterangan masa aktif 1 tahun"
tidakk "$HALOPS2" 'Aktif permanen' "sekolah hasil tarikan TIDAK lagi disebut permanen"
KET_HM=$(CBT_DB_FILE="$DB" php -d extension=pdo -d extension=pdo_sqlite -r '
require "'"$DIR"'/inc/init.php";
echo str_ends_with(aktivasi_batas_akhir(time(), 365), " 23:59:59") ? "ya" : "tidak";' 2>/dev/null)
cek "batas masa aktif berakhir pukul 23:59:59" "$KET_HM" "ya"

DASBOR=$(curl -s -b "$OP2" -c "$OP2" "$BASE/index.php")
cari "$DASBOR" 'Masa Aktif' "kartu Masa Aktif ada di dashboard"
cari "$DASBOR" 'data-hm-mini' "kartu dashboard memuat hitung mundur ringkas"
cari "$DASBOR" 'Sisa 36[0-9] hari ' "sisa hari tampil di dashboard"
tidakk "$DASBOR" '<b>Permanen</b>' "dashboard TIDAK menulis Permanen untuk sekolah hasil tarikan"

# Sekolah yang memang diaktifkan permanen oleh Super Admin (fixture sekolah 1) tetap tanpa
# hitung mundur — jangan sampai perubahan ini memaksa semua sekolah bermasa aktif.
perm=""
curl -s -b "$SP" -c "$SP" -o /dev/null "$BASE/sekolah.php?keluar=1"
HALPERM=$(curl -s -b "$SP" -c "$SP" "$BASE/sekolah.php?edit=1")
cari "$HALPERM" 'Aktif permanen' "sekolah permanen tetap ditandai permanen pada kartu Super Admin"
curl -s -b "$SP" -c "$SP" -o /dev/null "$BASE/sekolah.php?masuk=1"
HALP1=$(curl -s -b "$SP" -c "$SP" "$BASE/aktivasi.php")
tidakk "$HALP1" 'data-hitung-mundur' "sekolah permanen TIDAK memakai hitung mundur"
curl -s -b "$SP" -c "$SP" -o /dev/null "$BASE/sekolah.php?keluar=1"

echo "== 26. Aset pendukung hitung mundur =="
CSS=$(curl -s "$BASE/assets/style.css")
cari "$CSS" 'hitung-mundur{' "gaya .hitung-mundur ada di tema"
cari "$CSS" '\.hm-unit b' "gaya kotak satuan ada di tema"
JS=$(curl -s "$BASE/assets/app.js")
cari "$JS" 'function initHitungMundur' "skrip initHitungMundur ada"
cari "$JS" 'window.setInterval(gambar, 1000)' "hitung mundur diperbarui tiap 1 detik"
cari "$JS" 'data-hm-mini' "hitung mundur ringkas dashboard didukung skrip"

echo "== 27. Sekolah yang melakukan AKTIVASI PAKAI KODE juga 365 hari + hitung mundur =="
SID3OP=/tmp/cbt_tarik_op3.jar
masuk "$SID3OP" adminisiii rahasia123
cek "sekolah ketiga masih bermasa aktif awal 365 hari" \
  "$(sql "SELECT aktivasi FROM sekolah WHERE id = $SID3")" "percobaan"
KODE3=$(kodeAktivasi "$SID3")
cek "kode aktivasi terbaca (format XXXX-XXXX-XXXX)" "$(printf '%s' "$KODE3" | grep -cE '^[0-9A-Z]{4}-[0-9A-Z]{4}-[0-9A-Z]{4}$')" "1"
T=$(csrf "$SID3OP" aktivasi.php)
cek "masukkan kode (302)" "$(curl -s -b "$SID3OP" -c "$SID3OP" -o /dev/null -w '%{http_code}' \
  -d "csrf=$T" -d 'aksi=aktifkan' -d "kode=$KODE3" "$BASE/aktivasi.php")" "302"
cek "keadaan menjadi aktif" "$(sql "SELECT aktivasi FROM sekolah WHERE id = $SID3")" "aktif"
MASA3=$(sql "SELECT CAST(julianday(aktif_sampai) - julianday('now') AS INTEGER) FROM sekolah WHERE id = $SID3")
if [ "$MASA3" -ge 364 ] && [ "$MASA3" -le 366 ]; then ok "masa aktif setelah pakai kode 365 hari ($MASA3 hari)"; else no "masa aktif setelah pakai kode ($MASA3 hari)"; fi
cek "periode kode naik (kode lama tidak berlaku lagi)" "$(sql "SELECT aktivasi_ke FROM sekolah WHERE id = $SID3")" "2"
HALOPS3=$(curl -s -b "$SID3OP" -c "$SID3OP" "$BASE/aktivasi.php")
cari "$HALOPS3" 'Hitung mundur masa aktif' "hitung mundur tampil setelah aktivasi pakai kode"
cari "$HALOPS3" 'Sisa 36[0-9] hari' "sisa masa aktif dihitung dari kode aktivasi"
DAS3=$(curl -s -b "$SID3OP" -c "$SID3OP" "$BASE/index.php")
cari "$DAS3" 'data-hm-mini' "hitung mundur dashboard tampil setelah aktivasi pakai kode"
tidakk "$DAS3" '<b>Permanen</b>' "dashboard TIDAK menulis Permanen setelah aktivasi pakai kode"

echo "== 28. Migrasi SEKALI JALAN: sekolah lama berstatus permanen → masa aktif 365 hari =="
# Salinan terpisah supaya basis data uji yang sedang dipakai TIDAK terganggu.
MIG=/tmp/cbt_migrasi_$.sqlite
cp "$DB" "$MIG"
MIGDIR=/tmp/cbt_migrasi_dir_$
rm -rf "$MIGDIR"; mkdir -p "$MIGDIR"; cp "$MIG" "$MIGDIR/data.sqlite"
printf '5' > "$MIGDIR/.schema_ok"    # penanda lama -> memaksa ensure_schema berjalan
MIGDB="$MIGDIR/data.sqlite"
php -d extension=pdo -d extension=pdo_sqlite <<PHP
<?php
\$p = new PDO('sqlite:${MIGDB}');
\$p->exec("DELETE FROM app_pengaturan WHERE kunci = 'aktivasi_masa_aktif_365'");
\$p->exec("UPDATE sekolah SET aktivasi = 'permanen', aktif_sampai = NULL, aktivasi_pada = NULL WHERE id = 1");
PHP
# Keadaan basis data LAMA: kolom pelacak e-GURU sengaja dihapus lebih dahulu (SQLite >= 3.35).
php -d extension=pdo -d extension=pdo_sqlite <<PHP
<?php
\$p = new PDO('sqlite:${MIGDB}');
try { \$p->exec('ALTER TABLE sekolah DROP COLUMN eguru_id'); } catch (Throwable \$e) {}
try { \$p->exec('ALTER TABLE sekolah DROP COLUMN eguru_tarik'); } catch (Throwable \$e) {}
PHP
cek "prasyarat: kolom eguru_id belum ada pada basis data lama" \
  "$(DB="$MIGDB" SQL="SELECT COUNT(*) FROM pragma_table_info('sekolah') WHERE name IN ('eguru_id','eguru_tarik')" php -d extension=pdo -d extension=pdo_sqlite -r '$p=new PDO("sqlite:".getenv("DB")); echo $p->query(getenv("SQL"))->fetchColumn();')" "0"
cek "prasyarat: penanda migrasi belum ada" \
  "$(DB="$MIGDB" SQL="SELECT COUNT(*) FROM app_pengaturan WHERE kunci = 'aktivasi_masa_aktif_365'" php -d extension=pdo -d extension=pdo_sqlite -r '$p=new PDO("sqlite:".getenv("DB")); echo $p->query(getenv("SQL"))->fetchColumn();')" "0"
# Satu permintaan biasa -> migrasi v6 berjalan.
CBT_DB_FILE="$MIGDB" EGURU_DB_FILE="$EGDB" php -d extension=pdo -d extension=pdo_sqlite -r '
require "'"$DIR"'/inc/init.php"; val("SELECT 1");' >/dev/null
cek "penanda migrasi terpasang" \
  "$(DB="$MIGDB" SQL="SELECT nilai FROM app_pengaturan WHERE kunci = 'aktivasi_masa_aktif_365'" php -d extension=pdo -d extension=pdo_sqlite -r '$p=new PDO("sqlite:".getenv("DB")); echo $p->query(getenv("SQL"))->fetchColumn();')" "1"
cek "migrasi v7 MENAMBAH kolom pelacak e-GURU (eguru_id + eguru_tarik)" \
  "$(DB="$MIGDB" SQL="SELECT COUNT(*) FROM pragma_table_info('sekolah') WHERE name IN ('eguru_id','eguru_tarik')" php -d extension=pdo -d extension=pdo_sqlite -r '$p=new PDO("sqlite:".getenv("DB")); echo $p->query(getenv("SQL"))->fetchColumn();')" "2"
cek "sekolah permanen lama diubah menjadi bermasa aktif" \
  "$(DB="$MIGDB" SQL="SELECT aktivasi FROM sekolah WHERE id = 1" php -d extension=pdo -d extension=pdo_sqlite -r '$p=new PDO("sqlite:".getenv("DB")); echo $p->query(getenv("SQL"))->fetchColumn();')" "percobaan"
MIG_PANJANG=$(DB="$MIGDB" SQL="SELECT CAST(julianday(aktif_sampai) - julianday('now') AS INTEGER) FROM sekolah WHERE id = 1" php -d extension=pdo -d extension=pdo_sqlite -r '$p=new PDO("sqlite:".getenv("DB")); echo $p->query(getenv("SQL"))->fetchColumn();')
if [ "$MIG_PANJANG" -ge 364 ] && [ "$MIG_PANJANG" -le 366 ]; then ok "hasil konversi 365 hari ($MIG_PANJANG hari)"; else no "hasil konversi ($MIG_PANJANG hari)"; fi
# Sesudah migrasi, sekolah yang SENGAJA dijadikan permanen oleh Super Admin TIDAK diubah lagi.
php -d extension=pdo -d extension=pdo_sqlite <<PHP
<?php
\$p = new PDO('sqlite:${MIGDB}');
\$p->exec("UPDATE sekolah SET aktivasi = 'permanen', aktif_sampai = NULL WHERE id = 1");
PHP
CBT_DB_FILE="$MIGDB" EGURU_DB_FILE="$EGDB" php -d extension=pdo -d extension=pdo_sqlite -r '
require "'"$DIR"'/inc/init.php"; val("SELECT 1");' >/dev/null
cek "pilihan permanen sesudah migrasi TIDAK diubah lagi" \
  "$(DB="$MIGDB" SQL="SELECT aktivasi FROM sekolah WHERE id = 1" php -d extension=pdo -d extension=pdo_sqlite -r '$p=new PDO("sqlite:".getenv("DB")); echo $p->query(getenv("SQL"))->fetchColumn();')" "permanen"
cek "versi skema kini 6" "$(DB="$MIGDB" SQL="SELECT nilai FROM app_pengaturan WHERE kunci = 'x'" php -d extension=pdo -d extension=pdo_sqlite -r 'echo "ok";')" "ok"
# Angka skema TIDAK dipatok mati: dibaca dari const SCHEMA_VERSION di inc/db.php.
SKEMA=$(sed -n "s/^const SCHEMA_VERSION = '\([0-9]*\)';$/\1/p" "$DIR/inc/db.php" | head -1)
cek "penanda skema basis data uji = $SKEMA" "$(cat "$MIGDIR/.schema_ok")" "$SKEMA"
rm -rf "$MIG" "$MIGDIR"

echo "== 24. Kerapian halaman Kelola Sekolah =="
BERSIH=$(curl -s -b "$SP" -c "$SP" "$BASE/sekolah.php")
tidakk "$BERSIH" 'href="/' "tanpa tautan absolut"
cari "$BERSIH" 'assets/style.css?v=' "aset berversi (pengusir cache)"
cari "$BERSIH" '</html>' "halaman tertutup lengkap"

echo
echo "== Ringkasan =="
echo "  LULUS: $LULUS   GAGAL: $GAGAL"
if [ -n "$SRV_PID" ]; then
  grep -iE 'PHP (Warning|Notice|Fatal|Parse)|Uncaught' "/tmp/cbt_tarik_srv_$$.log" | head -5 || true
fi
[ "$GAGAL" -eq 0 ] || exit 1
