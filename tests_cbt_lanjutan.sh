#!/usr/bin/env bash
# =====================================================================================
# Uji fitur lanjutan CBT:
#   1. Tulisan "Satu aplikasi untuk banyak sekolah/madrasah" hilang dari halaman pendaftaran
#   2. Nama kelas dirapikan (I..XII tanpa kata "Kelas") termasuk penggabungan kelas kembar
#   3. KKTP per mata pelajaran / per ujian (diisi guru saat membuat soal)
#   4. Analisis butir: VALIDITAS + DISTRAKTOR
#   5. Diagram analisis (4 kanvas grafik)
#   6. Tabel tersendiri siswa REMEDIAL & PENGAYAAN
#   7. Template soal WORD (.docx) dapat diunduh & diisi lalu diimpor kembali
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
  for _p in $(seq 8760 8799); do
    if ! (exec 3<>"/dev/tcp/127.0.0.1/$_p") 2>/dev/null; then PORT=$_p; break; fi
    exec 3<&- 2>/dev/null || true
  done
  [ -n "$PORT" ] || { echo "Tidak ada port bebas"; exit 1; }
  BASE="http://127.0.0.1:$PORT"
  KERJA="/tmp/cbt_lanjut_$$"
  rm -rf "$KERJA"; mkdir -p "$KERJA"
  cp -r "$DIR"/. "$KERJA"/
  rm -f "$KERJA"/data/cbt.sqlite* "$KERJA"/data/.schema_ok
  mkdir -p "$KERJA/data/sessions"; chmod 777 "$KERJA/data" "$KERJA/data/sessions" 2>/dev/null || true
  CBT_DB_FILE="$KERJA/data/cbt.sqlite" php -d extension=pdo -d extension=pdo_sqlite "$KERJA/fixture_cbt.php" >/dev/null
  php -d extension=pdo -d extension=pdo_sqlite -S "127.0.0.1:$PORT" -t "$KERJA" > "/tmp/cbt_lanjut_srv_$$.log" 2>&1 &
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

# Seluruh pemeriksaan lewat PHP WAJIB memakai SALINAN basis data uji (CBT_DB_FILE),
# kalau tidak aplikasi akan mencoba memakai basis data asli (read-only) dan gagal.
phpuji(){ CBT_DB_FILE="$DB" php -d extension=pdo -d extension=pdo_sqlite "$@"; }

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

OP=/tmp/cbt_lj_op.jar; GR=/tmp/cbt_lj_gr.jar
masuk "$OP" operator1 sandi12345
masuk "$GR" ahmad guru123

echo "== 1. Halaman pendaftaran DIHAPUS, halaman masuk hanya formulir =="
cek "daftar.php sudah tidak ada (404)" \
  "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/daftar.php")" "404"
HAL=$(curl -s "$BASE/login.php")
cari "$HAL" "<h1>Computer-Based Test (CBT)</h1>" "judul halaman masuk berbunyi Computer-Based Test (CBT)"
tidakk "$HAL" "Satu aplikasi untuk banyak sekolah" "tulisan promosi sekolah banyak sudah tidak ada"
tidakk "$HAL" "Daftarkan Sekolah" "tautan pendaftaran sekolah sudah tidak ada"
tidakk "$HAL" "landing page" "halaman masuk bukan landing page lagi"

echo "== 2. Nama kelas (I..XII tanpa kata Kelas) =="
CBT_DB_FILE="$DB" php -d extension=pdo -d extension=pdo_sqlite -r '
require "'"$DIR"'/inc/helpers.php";
$kasus = [["Kelas X","X"],["Kelas XI","XI"],["Kelas XII","XII"],["kelas 7","VII"],["7A","VII-A"],
          ["KELAS IX-B","IX-B"],["VIII","VIII"],["",""]];
$gagal = 0;
foreach ($kasus as $k) { $h = kelas_nama_rapi($k[0]); if ($h !== $k[1]) { echo "GAGAL {$k[0]} -> $h\n"; $gagal++; } }
echo $gagal === 0 ? "ok\n" : "gagal\n";' > /tmp/cbt_kelas.txt
cari "$(cat /tmp/cbt_kelas.txt)" "^ok$" "fungsi perapian nama kelas benar untuk semua contoh"
# simpan lewat antarmuka: "Kelas 8B" harus menjadi "VIII-B"
T=$(csrf "$OP" kelas.php)
curl -s -b "$OP" -c "$OP" -o /dev/null -d "csrf=$T" -d 'aksi=tambah' --data-urlencode 'nama=Kelas 8B' -d 'tingkat=8' "$BASE/kelas.php"
cek "menyimpan 'Kelas 8B' -> 'VIII-B'" "$(sql "SELECT nama FROM kelas WHERE nama='VIII-B'")" "VIII-B"
cek "tidak ada kelas yang masih memakai kata 'Kelas'" "$(sql "SELECT COUNT(*) FROM kelas WHERE LOWER(nama) LIKE 'kelas%'")" "0"
# kelas kembar: "Kelas X" digabung dengan "X" saat migrasi
php -d extension=pdo -d extension=pdo_sqlite -r '
$p = new PDO("sqlite:'"$DB"'");
$p->exec("INSERT INTO kelas (sekolah_id, nama, urutan, dibuat) VALUES (1, \"Kelas X\", 90, datetime(\"now\"))");
$p->exec("INSERT INTO siswa (sekolah_id, nis, nama, kelas_id, aktif, status, dibuat) VALUES (1, \"KEMBAR1\", \"Uji Kembar\", (SELECT id FROM kelas WHERE nama=\"Kelas X\"), 1, \"aktif\", datetime(\"now\"))");'
phpuji -r 'require "'"$DIR"'/inc/init.php"; rapikan_kelas(db());'
cek "kelas kembar 'Kelas X' digabung ke 'X'" "$(sql "SELECT COUNT(*) FROM kelas WHERE nama='Kelas X'")" "0"
cek "siswa pada kelas kembar ikut dipindahkan" "$(sql "SELECT kelas_id FROM siswa WHERE nis='KEMBAR1'")" "$(sql "SELECT id FROM kelas WHERE nama='X'")"

echo "== 3. KKTP per mata pelajaran / per ujian =="
T=$(csrf "$GR" "ujian_edit.php?id=1")
curl -s -b "$GR" -c "$GR" -o /tmp/cbt_lj_uj.html -d "csrf=$T" -d 'aksi=simpan' --data-urlencode 'judul=Ulangan Harian Matematika' \
  -d 'mapel_id=1' -d 'jenis_ujian=Ulangan Harian' -d 'durasi=60' -d 'kktp=70' -d 'kelas[]=1' "$BASE/ujian_edit.php?id=1"
cek "KKTP tersimpan pada ujian" "$(sql "SELECT CAST(ROUND(kktp) AS INTEGER) FROM ujian WHERE id=1")" "70"
cek "KKTP tersimpan pada mata pelajaran" "$(sql "SELECT CAST(ROUND(kktp) AS INTEGER) FROM mapel WHERE id=1")" "70"
H=$(curl -s -b "$GR" "$BASE/ujian_edit.php?id=1")
cari "$H" "KKTP" "medan KKTP tampil pada halaman kelola ujian"
cari "$H" "data-kktp-mapel" "KKTP disiapkan per mata pelajaran (peta untuk JS)"
# nilai ketuntasan mengikuti KKTP ujian (bukan KKM sekolah)
phpuji -r '
require "'"$DIR"'/inc/init.php";
$u = one("SELECT * FROM ujian WHERE id = 1");
echo kktp_berlaku($u);' > /tmp/cbt_kktp.txt
cek "kktp_berlaku() memakai KKTP ujian" "$(cat /tmp/cbt_kktp.txt | head -1)" "70"

echo "== 4. Analisis: validitas & distraktor =="
# 5 sesi dengan pola jawaban terkontrol
phpuji -r '
require "'"$DIR"'/inc/init.php";
q("DELETE FROM sesi_ujian WHERE ujian_id = 1");
for ($i = 1; $i <= 5; $i++) {
  if (!val("SELECT id FROM siswa WHERE sekolah_id = 1 AND nis = ?", ["LJ00$i"])) {
    q("INSERT INTO siswa (sekolah_id, nis, nama, kelas_id, aktif, status, dibuat) VALUES (1,?,?,1,1,\"aktif\",?)",
      ["LJ00$i", "Peserta LJ $i", date("Y-m-d H:i:s")]);
  }
}
$soal = all("SELECT * FROM soal WHERE ujian_id = 1 ORDER BY urutan");
$pola = [[1,1,1],[1,1,1],[1,1,1],[0,0,0],[0,0,0]];
foreach (all("SELECT id FROM siswa ORDER BY id LIMIT 5") as $no => $s) {
  q("INSERT INTO sesi_ujian (sekolah_id, ujian_id, siswa_id, mulai, selesai, status) VALUES (1,1,?,?,?,\"selesai\")",
    [(int) $s["id"], date("Y-m-d H:i:s"), date("Y-m-d H:i:s")]);
  $sesi = (int) db()->lastInsertId();
  foreach ($soal as $ix => $r) {
    $benar = (bool) ($pola[$no][$ix % 3] ?? 0);
    $kunci = json_decode((string) $r["jawaban"], true);
    $opsi = opsi_decode((string) $r["opsi"]);
    switch ($r["jenis"]) {
      case "pg": $jwb = $benar ? $kunci : (($kunci + 1) % max(1, count($opsi))); break;
      case "pgk": $jwb = $benar ? $kunci : [0]; break;
      case "bs": $jwb = $benar ? $kunci : array_fill(0, count($kunci), "B"); break;
      case "jodoh": $jwb = array_fill(0, count($opsi), 0); break;
      case "isian": $jwb = $benar ? "9" : "8"; break;
      default: $jwb = $benar ? "panjang x lebar" : "salah";
    }
    q("INSERT INTO jawaban (sesi_id, soal_id, jawaban, dijawab) VALUES (?,?,?,?)",
      [$sesi, (int) $r["id"], json_encode($jwb), date("Y-m-d H:i:s")]);
  }
  sesi_hitung($sesi);
}
$a = analisis_butir(1);
echo count($a["butir"]) . "|" . ($a["butir"][0]["validitas"]) . "|" . $a["butir"][0]["valid_label"] . "|"
   . count($a["butir"][0]["distraktor"]) . "|" . json_encode($a["ringkas"]);' > /tmp/cbt_an.txt
AN=$(cat /tmp/cbt_an.txt)
cari "$AN" "^6|" "analisis menghitung 6 butir"
cari "$AN" '"distraktor_buruk"' "ringkasan analisis memuat cacah distraktor bermasalah"
H=$(curl -s -b "$GR" "$BASE/hasil_ujian.php?id=1")
cari "$H" "Validitas" "kolom Validitas tampil pada analisis"
cari "$H" "Distraktor" "kolom Distraktor tampil pada analisis"
cari "$H" "Daftar Siswa REMEDIAL" "tabel REMEDIAL tersendiri tampil"
cari "$H" "Daftar Siswa PENGAYAAN" "tabel PENGAYAAN tersendiri tampil"
cari "$H" "cardRemedial" "kartu remedial punya penanda sendiri"
cari "$H" "cardPengayaan" "kartu pengayaan punya penanda sendiri"

echo "== 5. Diagram analisis =="
for c in chBenar chBeda chValid chKesukaran; do
  cari "$H" "id=\"$c\"" "diagram $c tersedia"
done
cari "$H" "assets/vendor/chart.umd.min.js" "pustaka grafik dimuat"

echo "== 6. Lembar cetak analisis & tindak lanjut =="
H=$(curl -s -b "$GR" "$BASE/cetak_hasil.php?id=1&bagian=analisis")
cari "$H" "Validitas" "lembar analisis memuat kolom Validitas"
cari "$H" "Distraktor" "lembar analisis memuat kolom Distraktor"
H=$(curl -s -b "$GR" "$BASE/cetak_hasil.php?id=1&bagian=tindak")
cari "$H" "SISWA REMEDIAL" "lembar tindak lanjut memuat REMEDIAL"
cari "$H" "SISWA PENGAYAAN" "lembar tindak lanjut memuat PENGAYAAN"
cari "$H" "KKTP" "lembar tindak lanjut menyebut KKTP"

echo "== 7. Template soal WORD (.docx) =="
H=$(curl -s -b "$GR" -o /tmp/cbt_tpl.docx -w '%{http_code}' "$BASE/template_soal_word.php?ujian=1")
cek "unduh template Word (200)" "$H" "200"
cek "berkas berupa ZIP (diawali PK)" "$(head -c 2 /tmp/cbt_tpl.docx)" "PK"
# v1.13.0: template BERUBAH menjadi TULISAN BIASA (bukan tabel). Uji ini mengisi satu soal baru
# dengan format teks sederhana seperti yang dikerjakan guru (judul bagian + nomor + pilihan + Kunci).
python3 - <<'PY'
import zipfile
def par(t):
    return '<w:p><w:r><w:t xml:space="preserve">' + t + '</w:t></w:r></w:p>'
isi = [
    'Template Soal Menjodohkan',
    'DARI-WORD: jodohkan!',
    'A. Kiri satu | Kanan satu',
    'B. Kiri dua | Kanan dua',
]
sumber = '/tmp/cbt_tpl.docx'
tujuan = '/tmp/cbt_tpl_isi.docx'
z = zipfile.ZipFile(sumber)
data = {n: z.read(n) for n in z.namelist()}
xml = data['word/document.xml'].decode('utf-8')
tambahan = ''.join(par(t) for t in isi)
xml = xml.replace('</w:body>', tambahan + '</w:body>')
data['word/document.xml'] = xml.encode('utf-8')
with zipfile.ZipFile(tujuan, 'w', zipfile.ZIP_DEFLATED) as out:
    for n, b in data.items():
        out.writestr(n, b)
print('berkas Word terisi dibuat')
PY
SEBELUM=$(sql "SELECT COUNT(*) FROM soal WHERE ujian_id=1")
T=$(csrf "$GR" "ujian_import.php?ujian=1")
curl -s -b "$GR" -c "$GR" -o /tmp/cbt_lj_imp.html -F "csrf=$T" -F 'ujian=1' -F "berkas_docx=@/tmp/cbt_tpl_isi.docx" "$BASE/ujian_import.php"
cari "$(cat /tmp/cbt_lj_imp.html)" "soal</b> berhasil diimpor\|soal</b>" "impor dari Word melaporkan hasil"
SESUDAH=$(sql "SELECT COUNT(*) FROM soal WHERE ujian_id=1")
if [ "$SESUDAH" -gt "$SEBELUM" ]; then ok "soal dari berkas Word tersimpan ($SEBELUM -> $SESUDAH)"; else no "soal dari Word tidak bertambah"; fi
cari "$(sql "SELECT pertanyaan FROM soal WHERE pertanyaan LIKE 'DARI-WORD%'")" "DARI-WORD" "pertanyaan dari Word tersimpan benar"
cek "jenis soal dari Word terbaca" "$(sql "SELECT jenis FROM soal WHERE pertanyaan LIKE 'DARI-WORD%'")" "jodoh"
cek "kunci soal menjodohkan dari Word" "$(sql "SELECT jawaban FROM soal WHERE pertanyaan LIKE 'DARI-WORD%'")" '["Kanan satu","Kanan dua"]'
# v1.18.0: berkas ber-ekstensi .doc DITERIMA — tetapi isinya harus benar-benar dokumen Word.
# Berkas ".doc" yang isinya bukan Word tetap ditolak dengan pesan jelas (dikenali dari ISI).
printf 'bukan docx' > /tmp/cbt_lj.doc
SEBELUM=$(sql "SELECT COUNT(*) FROM soal WHERE ujian_id=1")
T=$(csrf "$GR" "ujian_import.php?ujian=1")
curl -s -L -b "$GR" -c "$GR" -o /tmp/cbt_lj_doc.html -F "csrf=$T" -F 'ujian=1' -F "berkas_docx=@/tmp/cbt_lj.doc" "$BASE/ujian_import.php"
cari "$(cat /tmp/cbt_lj_doc.html)" "tidak dikenali sebagai dokumen Word" "berkas .doc yang isinya bukan Word ditolak dengan pesan jelas"
cek "berkas .doc rusak tidak menambah soal" "$(sql "SELECT COUNT(*) FROM soal WHERE ujian_id=1")" "$SEBELUM"

echo
echo "===== HASIL: $LULUS lulus, $GAGAL gagal ====="
[ "$GAGAL" -eq 0 ] || exit 1
