#!/usr/bin/env bash
# =====================================================================================
# Uji KESELARASAN dengan aplikasi e-GURU:
#   - Impor Data Guru & Data Siswa memakai berkas berformat EKSPOR e-GURU.
#   - Kolom baru (NIP/NUPTK, Jabatan, Ayah/Ibu/Wali, Status) tersimpan & tampil.
#   - Ekspor CBT memakai susunan kolom yang sama dengan e-GURU (bolak-balik).
#
# Mode MANDIRI: menyiapkan SALINAN aplikasi + basis data uji di /tmp (data aplikasi tidak disentuh).
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
  KERJA="/tmp/cbt_eguru_$$"
  rm -rf "$KERJA"; mkdir -p "$KERJA"
  cp -r "$DIR"/. "$KERJA"/
  rm -f "$KERJA"/data/cbt.sqlite* "$KERJA"/data/.schema_ok
  mkdir -p "$KERJA/data/sessions"; chmod 777 "$KERJA/data" "$KERJA/data/sessions" 2>/dev/null || true
  CBT_DB_FILE="$KERJA/data/cbt.sqlite" php -d extension=pdo -d extension=pdo_sqlite "$KERJA/fixture_cbt.php" >/dev/null
  php -d extension=pdo -d extension=pdo_sqlite -S "127.0.0.1:$PORT" -t "$KERJA" > "/tmp/cbt_eguru_srv_$$.log" 2>&1 &
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
sql(){ php -d extension=pdo -d extension=pdo_sqlite -r '
$p=new PDO("sqlite:'"$DB"'"); $q=$p->query("'"$1"'"); echo ($q?$q->fetchColumn():"0");'; }

# fputcsv mengutip sel yang memuat spasi, dan berkas CSV diawali BOM UTF-8.
# Untuk membandingkan ISI (bukan penulisan), tanda kutip & BOM dibuang lebih dahulu.
csv_normal(){ printf '%s' "$1" | tr -d '"' | sed 's/^\xef\xbb\xbf//; s/\xef\xbb\xbf//g'; }

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

OP=/tmp/cbt_eg_op.jar
masuk "$OP" operator1 sandi12345

echo "== 1. Susunan kolom ekspor CBT SAMA dengan e-GURU =="
KOLOM_G=$(php -r '
$h = file_get_contents("'"$DIR"'/guru_export.php");
preg_match("/\\\$kolom = \[(.*?)\];/s", $h, $m);
echo str_replace([chr(39), " ", "\n"], "", $m[1]);')
cek "kolom ekspor guru" "$KOLOM_G" "Nama,JenisAnggota,NIP/NUPTK,JenisKelamin,Jabatan,MataPelajaran,KelasDiajar,TugasTambahan,Telepon,Email,Akun(NamaPengguna),Status,TanggalDaftar"
KOLOM_S=$(php -r '
$h = file_get_contents("'"$DIR"'/siswa_export.php");
preg_match("/\\\$kolom = \[(.*?)\];/s", $h, $m);
echo str_replace([chr(39), " ", "\n"], "", $m[1]);')
cek "kolom ekspor siswa" "$KOLOM_S" "Nama,JenisAnggota,NIS,NISN,JenisKelamin,Kelas,TanggalDaftar,TanggalLahir,NamaWali,Telepon,Alamat,StatusSiswa"

echo "== 2. Impor Data Guru dari berkas format e-GURU =="
T=$(csrf "$OP" guru_import.php)
PAYLOAD='{"judul":["Nama","Jenis Anggota","NIP/NUPTK","Jenis Kelamin","Jabatan","Mata Pelajaran","Kelas Diajar","Tugas Tambahan","Telepon","Email","Akun (Nama Pengguna)","Status","Tanggal Daftar"],"baris":[["Yusup Abaidata, SH","Guru","197505122005011002","L","Kepala Madrasah","Bahasa Arab","VIII-A, IX-A","Wali Kelas","081234567001","yusup@example.com","yusup","Aktif","2025-07-14"],["Rina Marlina, S.Pd","Guru","","P","Guru Mata Pelajaran","Biologi","IX-A","","","rina@example.com","","Aktif","2025-07-14"],["Guru Nonaktif","Guru","199001012015031003","L","Guru Mata Pelajaran","Kimia","IX-A","","","","","Nonaktif","2025-07-14"]]}'
curl -s -b "$OP" -c "$OP" -o /tmp/cbt_imp_g.html -d "csrf=$T" --data-urlencode "payload=$PAYLOAD" "$BASE/guru_import.php"
cari "$(cat /tmp/cbt_imp_g.html)" "data guru</b> tersimpan" "pesan hasil impor guru tampil"
cek "NIP tersimpan" "$(sql "SELECT COUNT(*) FROM guru WHERE nip='197505122005011002'")" "1"
cek "Jabatan tersimpan" "$(sql "SELECT jabatan FROM guru WHERE nip='197505122005011002'")" "Kepala Madrasah"
cek "Jenis kelamin perempuan tersimpan" "$(sql "SELECT COUNT(*) FROM guru WHERE nama LIKE 'Rina Marlina%' AND jenis_kelamin='P'")" "1"
cek "akun guru dibuat dari kolom Akun" "$(sql "SELECT COUNT(*) FROM users WHERE username='yusup'")" "1"
cek "status Nonaktif dibaca" "$(sql "SELECT aktif FROM guru WHERE nama='Guru Nonaktif'")" "0"
cek "mata pelajaran baru dibuat otomatis" "$(sql "SELECT COUNT(*) FROM mapel WHERE nama IN ('Biologi','Kimia')")" "2"
cek "kelas baru dibuat otomatis" "$(sql "SELECT COUNT(*) FROM kelas WHERE nama IN ('VIII-A','IX-A')")" "2"
cek "nama guru kembali dari impor ke ekspor" "$(sql "SELECT nama FROM guru WHERE nip='197505122005011002'")" "Yusup Abaidata, SH"

echo "== 3. Impor Data Siswa dari berkas format e-GURU =="
T=$(csrf "$OP" siswa_import.php)
PAYLOAD2='{"judul":["Nama","Jenis Anggota","NIS","NISN","Jenis Kelamin","Kelas","Tanggal Daftar","Tanggal Lahir","Nama Wali","Telepon","Alamat","Status Siswa"],"baris":[["Nurhaliza Putri","Siswa","2401001","0071234567","P","IX-A","2025-07-15","2010-03-21","Ahmad Yusuf","081234567801","Dusun Gandaria","Aktif"],["Rizki Pratama","Siswa","2401002","0071234568","L","IX-A","2025-07-15","2010-05-02","Siti Aminah","","","Lulus"]]}'
curl -s -b "$OP" -c "$OP" -o /tmp/cbt_imp_s.html -d "csrf=$T" --data-urlencode "payload=$PAYLOAD2" "$BASE/siswa_import.php"
cari "$(cat /tmp/cbt_imp_s.html)" "data siswa</b> tersimpan" "pesan hasil impor siswa tampil"
cek "NISN tersimpan" "$(sql "SELECT COUNT(*) FROM siswa WHERE nisn='0071234567'")" "1"
cek "tanggal lahir (ISO) tersimpan" "$(sql "SELECT tanggal_lahir FROM siswa WHERE nis='2401001'")" "2010-03-21"
cek "wali tersimpan" "$(sql "SELECT wali FROM siswa WHERE nis='2401001'")" "Ahmad Yusuf"
cek "kelas dari berkas dipakai" "$(sql "SELECT k.nama FROM siswa s JOIN kelas k ON k.id=s.kelas_id WHERE s.nis='2401001'")" "IX-A"
cek "status Aktif -> aktif=1" "$(sql "SELECT aktif FROM siswa WHERE nis='2401001'")" "1"
cek "status Lulus -> aktif=0" "$(sql "SELECT aktif FROM siswa WHERE nis='2401002'")" "0"
cek "status Lulus tersimpan sebagai teks" "$(sql "SELECT status FROM siswa WHERE nis='2401002'")" "lulus"

echo "== 4. Berkas salah halaman ditolak dengan pesan =="
T=$(csrf "$OP" guru_import.php)
PAYLOAD3='{"judul":["Nama","Jenis Anggota","NIS","NISN"],"baris":[["Siswa Nyasar","Siswa","9001",""]]}'
curl -s -b "$OP" -c "$OP" -o /tmp/cbt_imp_x.html -d "csrf=$T" --data-urlencode "payload=$PAYLOAD3" "$BASE/guru_import.php"
cari "$(cat /tmp/cbt_imp_x.html)" "dilewati" "berkas siswa pada impor guru dilaporkan dilewati"
cek "siswa nyasar TIDAK masuk data guru" "$(sql "SELECT COUNT(*) FROM guru WHERE nama='Siswa Nyasar'")" "0"

echo "== 5. Kolom yang tidak ada tidak mengosongkan data lama =="
T=$(csrf "$OP" siswa_edit.php)
# isi Tempat Lahir/Ayah/Ibu lewat form
SID=$(sql "SELECT id FROM siswa WHERE nis='2401001'")
T=$(csrf "$OP" "siswa_edit.php?id=$SID")
curl -s -b "$OP" -c "$OP" -o /dev/null -d "csrf=$T" -d 'nama=Nurhaliza Putri' -d "nis=2401001" -d 'nisn=0071234567' \
  -d 'jenis_kelamin=P' -d 'tempat_lahir=Limboto' -d 'tanggal_lahir=2010-03-21' -d "kelas_id=$(sql "SELECT id FROM kelas WHERE nama='IX-A'")" \
  -d 'wali=Ahmad Yusuf' -d 'nama_ayah=Ahmad Yusuf' -d 'nama_ibu=Salmah' -d 'status=aktif' "$BASE/siswa_edit.php?id=$SID"
cek "Tempat Lahir tersimpan dari form" "$(sql "SELECT tempat_lahir FROM siswa WHERE nis='2401001'")" "Limboto"
cek "Nama Ibu tersimpan dari form" "$(sql "SELECT nama_ibu FROM siswa WHERE nis='2401001'")" "Salmah"
# impor ulang berkas e-GURU (tanpa kolom Tempat Lahir / Ibu) -> nilai lama harus tetap ada
T=$(csrf "$OP" siswa_import.php)
curl -s -b "$OP" -c "$OP" -o /dev/null -d "csrf=$T" --data-urlencode "payload=$PAYLOAD2" "$BASE/siswa_import.php"
cek "Tempat Lahir TIDAK dikosongkan oleh berkas tanpa kolom itu" "$(sql "SELECT tempat_lahir FROM siswa WHERE nis='2401001'")" "Limboto"
cek "Nama Ibu TIDAK dikosongkan" "$(sql "SELECT nama_ibu FROM siswa WHERE nis='2401001'")" "Salmah"
cek "tidak ada siswa kembar (NIS sama diperbarui)" "$(sql "SELECT COUNT(*) FROM siswa WHERE nis='2401001'")" "1"

echo "== 6. Ekspor CBT + impor balik (bolak-balik) =="
H=$(curl -s -b "$OP" -o /tmp/cbt_exp_g.html -w '%{http_code}' "$BASE/guru_export.php")
cek "halaman ekspor guru (200)" "$H" "200"
cari "$(cat /tmp/cbt_exp_g.html)" "Jabatan" "kolom Jabatan tampil pada ekspor guru"
CSV=$(curl -s -b "$OP" "$BASE/guru_export.php?unduh=csv")
cari "$(csv_normal "$CSV")" "Nama;Jenis Anggota;NIP/NUPTK" "CSV guru memakai judul kolom e-GURU"
cari "$(csv_normal "$CSV")" "Yusup Abaidata, SH;Guru;197505122005011002" "baris guru tersusun seperti e-GURU"
H=$(curl -s -b "$OP" -o /tmp/cbt_exp_s.html -w '%{http_code}' "$BASE/siswa_export.php")
cek "halaman ekspor siswa (200)" "$H" "200"
CSVS=$(curl -s -b "$OP" "$BASE/siswa_export.php?unduh=csv")
cari "$(csv_normal "$CSVS")" "Nama;Jenis Anggota;NIS;NISN;Jenis Kelamin;Kelas;Tanggal Daftar;Tanggal Lahir;Nama Wali;Telepon;Alamat;Status Siswa" "CSV siswa memakai judul kolom e-GURU"
cari "$(csv_normal "$CSVS")" "Nurhaliza Putri;Siswa;2401001" "baris siswa tersusun seperti e-GURU"
# hapus satu guru lalu impor ulang dari CSV hasil ekspor -> harus kembali
php -d extension=pdo -d extension=pdo_sqlite -r '
$p=new PDO("sqlite:'"$DB"'"); $p->exec("DELETE FROM guru WHERE nama=\"Rina Marlina, S.Pd\"");'
cek "guru uji dihapus lebih dahulu" "$(sql "SELECT COUNT(*) FROM guru WHERE nama='Rina Marlina, S.Pd'")" "0"
# bangun payload dari CSV ekspor (baris dipisah ; dan kolom sesuai judul e-GURU)
PAYLOAD4=$(php -r '
$csv = file_get_contents("php://stdin");
$baris = array_map(fn($l) => str_getcsv(trim($l), ";"), array_filter(explode("\n", trim($csv))));
echo json_encode(["judul" => array_shift($baris), "baris" => array_values($baris)]);' <<< "$CSV")
T=$(csrf "$OP" guru_import.php)
curl -s -b "$OP" -c "$OP" -o /tmp/cbt_imp_rt.html -d "csrf=$T" --data-urlencode "payload=$PAYLOAD4" "$BASE/guru_import.php"
cek "guru kembali setelah impor ulang dari ekspor sendiri" "$(sql "SELECT COUNT(*) FROM guru WHERE nama='Rina Marlina, S.Pd'")" "1"

echo "== 7. Tampilan daftar & cetak memuat kolom baru =="
H=$(curl -s -b "$OP" -o /tmp/cbt_g_list.html -w '%{http_code}' "$BASE/guru.php")
cek "daftar guru (200)" "$H" "200"
cari "$(cat /tmp/cbt_g_list.html)" "NUPTK" "kolom NUPTK tampil pada daftar guru"
cari "$(cat /tmp/cbt_g_list.html)" "Jabatan" "kolom Jabatan tampil pada daftar guru"
H=$(curl -s -b "$OP" -o /tmp/cbt_s_list.html -w '%{http_code}' "$BASE/siswa.php")
cek "daftar siswa (200)" "$H" "200"
cari "$(cat /tmp/cbt_s_list.html)" "Ibu: Salmah" "nama ibu tampil pada daftar siswa"
cari "$(cat /tmp/cbt_s_list.html)" "Lulus" "status Lulus tampil pada daftar siswa"
H=$(curl -s -b "$OP" -o /tmp/cbt_g_cetak.html -w '%{http_code}' "$BASE/cetak_guru.php")
cek "cetak data guru (200)" "$H" "200"
cari "$(cat /tmp/cbt_g_cetak.html)" "Jabatan" "kolom Jabatan ada pada lembar cetak guru"
H=$(curl -s -b "$OP" -o /tmp/cbt_s_cetak.html -w '%{http_code}' "$BASE/cetak_siswa.php")
cek "cetak data siswa (200)" "$H" "200"
cari "$(cat /tmp/cbt_s_cetak.html)" "Orang Tua / Wali" "kolom Orang Tua/Wali ada pada lembar cetak siswa"

echo "== 8. Contoh format memakai susunan e-GURU =="
TMP=$(csv_normal "$(curl -s -b "$OP" "$BASE/template_impor.php?jenis=guru" | head -1)")
cari "$TMP" "Nama;Jenis Anggota;NIP/NUPTK" "contoh format guru memakai susunan e-GURU"
TMS=$(csv_normal "$(curl -s -b "$OP" "$BASE/template_impor.php?jenis=siswa" | head -1)")
cari "$TMS" "Nama;Jenis Anggota;NIS;NISN" "contoh format siswa memakai susunan e-GURU"

echo
echo "===== HASIL: $LULUS lulus, $GAGAL gagal ====="
[ "$GAGAL" -eq 0 ] || exit 1
