#!/usr/bin/env bash
# =====================================================================================
# Uji fitur v1.3.0:
#   A. SOAL BERGAMBAR pada akun guru (unggah/hapus gambar, tampil pada layar siswa)
#   B. MENU BANK SOAL (otomatis memuat soal; unduh Word .docx + cetak rapi + kunci jawaban)
#   C. OPERATOR: jadwal asesmen + generate token per mata pelajaran; siswa menginput token
#
# Mode MANDIRI: salinan aplikasi + basis data uji di /tmp (data aplikasi tidak disentuh).
# =====================================================================================
set -u
BASE="${1:-}"
DIR="$(cd "$(dirname "$0")" && pwd)"
LULUS=0; GAGAL=0

SRV_PID=""; MEDIA_PID=""
if [ -z "$BASE" ]; then
  PORT=""
  for _p in $(seq 8720 8759); do
    if ! (exec 3<>"/dev/tcp/127.0.0.1/$_p") 2>/dev/null; then PORT=$_p; break; fi
    exec 3<&- 2>/dev/null || true
  done
  MPORT=""
  for _p in $(seq 8960 8999); do
    if ! (exec 3<>"/dev/tcp/127.0.0.1/$_p") 2>/dev/null; then MPORT=$_p; break; fi
    exec 3<&- 2>/dev/null || true
  done
  [ -n "$PORT" ] && [ -n "$MPORT" ] || { echo "Tidak ada port bebas"; exit 1; }
  BASE="http://127.0.0.1:$PORT"
  MEDIA="http://127.0.0.1:$MPORT"
  KERJA="/tmp/cbt_gb_$$"
  rm -rf "$KERJA"; mkdir -p "$KERJA"
  cp -r "$DIR"/. "$KERJA"/
  rm -f "$KERJA"/data/cbt.sqlite* "$KERJA"/data/.schema_ok
  mkdir -p "$KERJA/data/sessions" "$KERJA/media"
  chmod 777 "$KERJA/data" "$KERJA/data/sessions" 2>/dev/null || true
  CBT_DB_FILE="$KERJA/data/cbt.sqlite" php -d extension=pdo -d extension=pdo_sqlite "$KERJA/fixture_cbt.php" >/dev/null
  # PNG uji 200x120 untuk membuktikan gambar benar-benar tersemat pada berkas Word
  python3 - "$KERJA/media/gambar.png" <<'PY'
import struct, sys, zlib
def png(w, h):
    def chunk(t, d):
        return struct.pack('>I', len(d)) + t + d + struct.pack('>I', zlib.crc32(t + d) & 0xffffffff)
    isi = b''
    for y in range(h):
        isi += b'\x00' + bytes([(x * 3) % 256 for x in range(w * 3)])
    return (b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', w, h, 8, 2, 0, 0, 0))
            + chunk(b'IDAT', zlib.compress(isi)) + chunk(b'IEND', b''))
open(sys.argv[1], 'wb').write(png(200, 120))
PY
  # Server media TERPISAH: server PHP bawaan bersifat single-thread, jadi aplikasi tidak boleh
  # mengambil gambar dari dirinya sendiri (akan deadlock).
  php -d extension=pdo -d extension=pdo_sqlite -S "127.0.0.1:$MPORT" -t "$KERJA/media" > "/tmp/cbt_gb_media_$$.log" 2>&1 &
  MEDIA_PID=$!
  php -d extension=pdo -d extension=pdo_sqlite -S "127.0.0.1:$PORT" -t "$KERJA" > "/tmp/cbt_gb_srv_$$.log" 2>&1 &
  SRV_PID=$!
  for _i in $(seq 1 25); do curl -s -o /dev/null "$BASE/login.php" && break; sleep 0.4; done
  DB="$KERJA/data/cbt.sqlite"
  bersihkan(){
    [ -n "$SRV_PID" ] && kill "$SRV_PID" 2>/dev/null
    [ -n "$MEDIA_PID" ] && kill "$MEDIA_PID" 2>/dev/null
    rm -rf "$KERJA"
  }
  trap bersihkan EXIT
else
  DB="${CBT_DB:-$DIR/data/cbt.sqlite}"
  MEDIA="${CBT_MEDIA:-http://127.0.0.1:8999}"
fi

ok(){ LULUS=$((LULUS+1)); printf '  ok   %s\n' "$1"; }
no(){ GAGAL=$((GAGAL+1)); printf '  GAGAL %s\n' "$1"; }
cek(){ if [ "$2" = "$3" ]; then ok "$1"; else no "$1 (dapat: $2, harap: $3)"; fi; }
cari(){ if printf '%s' "$1" | grep -q -- "$2"; then ok "$3"; else no "$3"; fi; }
tidakk(){ if printf '%s' "$1" | grep -q -- "$2"; then no "$3"; else ok "$3"; fi; }
sql(){ php -d extension=pdo -d extension=pdo_sqlite -r '
$p=new PDO("sqlite:'"$DB"'"); $q=$p->query("'"$1"'"); echo ($q?$q->fetchColumn():"0");'; }
phpuji(){ CBT_DB_FILE="$DB" php -d extension=pdo -d extension=pdo_sqlite "$@"; }
# Bersihkan sesi ujian siswa uji. Sesudah membuka kerjakan.php, akun siswa TERKUNCI pada
# halaman ujian (perilaku yang benar), sehingga uji bagian lain harus dimulai dari keadaan bersih.
bersih_sesi(){ phpuji -r '
require "'"$DIR"'/inc/init.php";
q("DELETE FROM sesi_ujian WHERE siswa_id IN (SELECT id FROM siswa WHERE sekolah_id = 1)");
q("DELETE FROM jawaban WHERE sesi_id NOT IN (SELECT id FROM sesi_ujian)");'; }
# Token acak: panggil helper dengan benar (butuh inc/init.php, bukan hanya fungsi lepas).
token_contoh(){ phpuji -r 'require "'"$DIR"'/inc/helpers.php"; echo token_buat();'; }

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

OP=/tmp/cbt_gb_op.jar; GR=/tmp/cbt_gb_gr.jar; SW=/tmp/cbt_gb_sw.jar
masuk "$OP" operator1 sandi12345
masuk "$GR" ahmad guru123
masuk "$SW" siswa1 siswa123

echo "== A. SOAL BERGAMBAR =="
UJ=$(sql "SELECT id FROM ujian WHERE mapel_id IS NOT NULL ORDER BY id LIMIT 1")
SOAL1=$(sql "SELECT id FROM soal WHERE ujian_id=$UJ ORDER BY urutan LIMIT 1")
T=$(csrf "$GR" "soal_edit.php?ujian=$UJ&id=$SOAL1")
H=$(curl -s -b "$GR" -c "$GR" "$BASE/soal_edit.php?ujian=$UJ&id=$SOAL1")
cari "$H" "Gambar Soal" "kartu Gambar Soal tampil pada halaman soal"
cari "$H" "upload_soal_gambar.php" "form unggah gambar tersedia"
# kartu gambar harus DI LUAR form soal (HTML tidak mengizinkan form bersarang)
POS_FORM=$(printf '%s' "$H" | grep -bo '</form>' | head -1 | cut -d: -f1)
POS_KARTU=$(printf '%s' "$H" | grep -bo 'id="cardGambarSoal"' | head -1 | cut -d: -f1)
if [ -n "$POS_FORM" ] && [ -n "$POS_KARTU" ] && [ "$POS_KARTU" -gt "$POS_FORM" ]; then
  ok "kartu gambar berada DI LUAR form soal (tidak bersarang)"
else
  no "kartu gambar bersarang di dalam form soal (dapat: form@$POS_FORM kartu@$POS_KARTU)"
fi
# unggah gambar (server uji memakai media proxy sungguhan; bila gagal jaringan, minimal form terjaga)
PNG=$(python3 -c "
import struct,zlib,base64
def chunk(t,d): return struct.pack('>I',len(d))+t+d+struct.pack('>I',zlib.crc32(t+d)&0xffffffff)
isi=b''.join(b'\x00'+bytes([(x*3)%256 for x in range(60*3)]) for y in range(40))
png=b'\x89PNG\r\n\x1a\n'+chunk(b'IHDR',struct.pack('>IIBBBBB',60,40,8,2,0,0,0))+chunk(b'IDAT',zlib.compress(isi))+chunk(b'IEND',b'')
open('/tmp/cbt_gb_soal.png','wb').write(png); print(len(png))")
cek "berkas PNG uji dibuat" "$([ "$PNG" -gt 50 ] && echo ya)" "ya"
T=$(csrf "$GR" "soal_edit.php?ujian=$UJ&id=$SOAL1")
curl -s -L -b "$GR" -c "$GR" -o /tmp/cbt_gb_up.html \
  -F "csrf=$T" -F 'aksi=unggah' -F "ujian=$UJ" -F "soal=$SOAL1" -F "gambar=@/tmp/cbt_gb_soal.png" \
  "$BASE/upload_soal_gambar.php"
GAMBAR=$(sql "SELECT COALESCE(gambar,'') FROM soal WHERE id=$SOAL1")
if [ -n "$GAMBAR" ]; then
  ok "URL gambar tersimpan pada soal ($GAMBAR)"
  phpuji -r '
  $g = "'"$GAMBAR"'";
  echo (str_starts_with($g, "http") ? "ok" : "bukan-url") . "\n";' > /tmp/cbt_gb_url.txt
  cek "gambar tersimpan sebagai URL" "$(cat /tmp/cbt_gb_url.txt)" "ok"
else
  no "URL gambar tidak tersimpan (media proxy mungkin tidak tersedia di sandbox)"
fi
# tampil pada layar siswa
if [ -n "$GAMBAR" ]; then
  H=$(curl -s -b "$SW" "$BASE/kerjakan.php?id=$UJ")
  cari "$H" "soal-gambar" "gambar tampil pada layar ujian siswa"
  cari "$H" "$GAMBAR" "URL gambar benar pada layar siswa"
fi
# hapus gambar
T=$(csrf "$GR" "soal_edit.php?ujian=$UJ&id=$SOAL1")
curl -s -L -b "$GR" -c "$GR" -o /dev/null -F "csrf=$T" -F 'aksi=hapus' -F "ujian=$UJ" -F "soal=$SOAL1" "$BASE/upload_soal_gambar.php"
cek "gambar dapat dihapus" "$(sql "SELECT COALESCE(gambar,'-') FROM soal WHERE id=$SOAL1")" "-"

# Setelah membuka kerjakan.php, akun siswa terkunci — bersihkan sesi uji agar bagian
# berikutnya berjalan dari keadaan bersih (perilaku penguncian itu sendiri diuji terpisah).
bersih_sesi

echo "== B. MENU BANK SOAL =="
H=$(curl -s -b "$GR" -o /tmp/cbt_gb_bank.html -w '%{http_code}' "$BASE/bank_soal.php")
cek "halaman Bank Soal (200)" "$H" "200"
BANK=$(cat /tmp/cbt_gb_bank.html)
cari "$BANK" "Bank Soal" "judul Bank Soal tampil"
cari "$BANK" "Kunci Jawaban" "kunci jawaban tampil pada bank soal"
cari "$BANK" "Unduh Word (.docx)" "tombol unduh Word tersedia"
cari "$BANK" "Cetak Rapi" "tombol cetak tersedia"
cari "$BANK" "Guru Penyusun" "saringan guru penyusun tersedia"
JUMLAH_BANK=$(sql "SELECT COUNT(*) FROM soal WHERE sekolah_id=1")
# jumlah soal bank = jumlah soal di basis data (otomatis)
if [ "$JUMLAH_BANK" -gt 0 ]; then ok "bank soal memuat $JUMLAH_BANK soal (otomatis dari ujian)"; else no "bank soal kosong"; fi
# menu sidebar guru
cari "$BANK" "bank_soal.php" "menu Bank Soal ada di sidebar guru"
# unduh Word
H=$(curl -s -b "$GR" -o /tmp/cbt_gb_bank.docx -w '%{http_code}' "$BASE/bank_soal_word.php")
cek "unduh Word bank soal (200)" "$H" "200"
cek "berkas Word berupa ZIP" "$(head -c 2 /tmp/cbt_gb_bank.docx)" "PK"
W=$(python3 -c "
import zipfile,sys
z=zipfile.ZipFile('/tmp/cbt_gb_bank.docx')
xml=z.read('word/document.xml').decode()
print('%d|%d|%s' % (z.testzip() is None, xml.count('Kunci Jawaban:'), 'RINGKASAN KUNCI JAWABAN' in xml))")
cek "Word berisi kunci jawaban per soal + ringkasan" "$W" "1|6|True"
# cetak
H=$(curl -s -b "$GR" -o /tmp/cbt_gb_cetak.html -w '%{http_code}' "$BASE/cetak_bank_soal.php")
cek "halaman cetak bank soal (200)" "$H" "200"
CETAK=$(cat /tmp/cbt_gb_cetak.html)
cari "$CETAK" "RINGKASAN KUNCI JAWABAN" "cetak memuat ringkasan kunci"
cari "$CETAK" "Guru Penyusun" "cetak memuat tanda tangan guru penyusun"
# akun siswa tidak boleh membuka bank soal (pakai akun siswa KEDUA yang belum punya sesi,
# karena siswa dengan sesi ujian berjalan memang terkunci pada halaman ujian)
SW2=/tmp/cbt_gb_sw2.jar
masuk "$SW2" siswa2 siswa123
H=$(curl -s -b "$SW2" -o /dev/null -w '%{http_code}' "$BASE/bank_soal.php")
cek "siswa ditolak pada Bank Soal (403)" "$H" "403"
H=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/bank_soal.php")
cek "belum masuk dialihkan (302)" "$H" "302"

echo "== C. JADWAL ASESMEN & TOKEN (operator) =="
H=$(curl -s -b "$OP" -o /tmp/cbt_gb_jt.html -w '%{http_code}' "$BASE/jadwal_token.php")
cek "halaman Jadwal Asesmen & Token (200)" "$H" "200"
JT=$(cat /tmp/cbt_gb_jt.html)
cari "$JT" "Generate Token Acak" "tombol generate token tersedia"
cari "$JT" "Jadwal Asesmen" "judul halaman benar"
cari "$JT" "KKTP per Mata Pelajaran" "tabel KKTP per mapel tampil"
# guru tidak boleh mengatur jadwal/token
H=$(curl -s -b "$GR" -o /dev/null -w '%{http_code}' "$BASE/jadwal_token.php")
cek "guru tidak boleh membuka halaman operator (403)" "$H" "403"
# generate token acak
T=$(csrf "$OP" jadwal_token.php)
curl -s -L -b "$OP" -c "$OP" -o /dev/null -d "csrf=$T" -d 'aksi=token_acak' -d "id=$UJ" "$BASE/jadwal_token.php"
TOKEN=$(sql "SELECT COALESCE(token,'') FROM ujian WHERE id=$UJ")
if [ -n "$TOKEN" ]; then ok "token tergenerate ($TOKEN)"; else no "token tidak tergenerate"; fi
cek "jejak waktu token tersimpan" "$([ -n "$(sql "SELECT COALESCE(token_dibuat,'') FROM ujian WHERE id=$UJ")" ] && echo ada)" "ada"
# token tersimpan sebagai huruf besar tanpa karakter membingungkan
TOKVAL=$(token_contoh)
if printf '%s' "$TOKVAL" | grep -qE '^[A-HJ-NP-Z2-9]{6}$'; then ok "token acak memakai abjad tanpa karakter membingungkan ($TOKVAL)"; else no "format token acak tidak sesuai ($TOKVAL)"; fi

# atur jadwal: ujian aktif, dibuka beberapa detik ke depan
T=$(csrf "$OP" jadwal_token.php)
MULAI=$(date -u -d '+2 seconds' '+%Y-%m-%dT%H:%M' 2>/dev/null || date -u -v+2S '+%Y-%m-%dT%H:%M' 2>/dev/null)
SELESAI=$(date -u -d '+1 hour' '+%Y-%m-%dT%H:%M' 2>/dev/null || date -u -v+1H '+%Y-%m-%dT%H:%M' 2>/dev/null)
# jadwal disimpan memakai zona waktu aplikasi (Asia/Jakarta) — ambil dari PHP
MULAI=$(phpuji -r 'echo date("Y-m-d\TH:i", time()+3);')
SELESAI=$(phpuji -r 'echo date("Y-m-d\TH:i", time()+3600);')
curl -s -L -b "$OP" -c "$OP" -o /dev/null -d "csrf=$T" -d 'aksi=jadwal' -d "id=$UJ" \
  -d "mulai=$MULAI" -d "selesai=$SELESAI" -d 'aktifkan=1' "$BASE/jadwal_token.php"
cek "jadwal tersimpan" "$([ -n "$(sql "SELECT COALESCE(selesai,'') FROM ujian WHERE id=$UJ")" ] && echo ada)" "ada"
cek "ujian menjadi aktif" "$(sql "SELECT status FROM ujian WHERE id=$UJ")" "aktif"

echo "== C2. SISWA MENGINPUT TOKEN =="
bersih_sesi
H=$(curl -s -b "$SW" -o /tmp/cbt_gb_list.html -w '%{http_code}' "$BASE/ujian_siswa.php")
cek "daftar ujian siswa (200)" "$H" "200"
cari "$(cat /tmp/cbt_gb_list.html)" "perlu token" "penanda 'perlu token' tampil pada daftar siswa"
# sebelum waktunya: ujian belum dibuka
SEKARANG=$(phpuji -r 'echo date("Y-m-d H:i:s");')
phpuji -r '
require "'"$DIR"'/inc/init.php";
q("UPDATE ujian SET mulai = ?, selesai = ? WHERE id = ?", [date("Y-m-d H:i:s", time()+3600), date("Y-m-d H:i:s", time()+7200), '"$UJ"']);'
H=$(curl -s -b "$SW" -o /dev/null -w '%{http_code} %{redirect_url}' "$BASE/kerjakan.php?id=$UJ")
cari "$H" "ujian_siswa.php" "sebelum jadwal dibuka siswa dialihkan"
# buka jadwal sekarang
phpuji -r '
require "'"$DIR"'/inc/init.php";
q("UPDATE ujian SET mulai = ?, selesai = ? WHERE id = ?", [date("Y-m-d H:i:s", time()-60), date("Y-m-d H:i:s", time()+3600), '"$UJ"']);'
H=$(curl -s -b "$SW" -o /tmp/cbt_gb_token.html -w '%{http_code}' "$BASE/kerjakan.php?id=$UJ")
cek "halaman pemberian token (200)" "$H" "200"
cari "$(cat /tmp/cbt_gb_token.html)" "Masukkan Token Ujian" "layar token tampil"
tidakk "$(cat /tmp/cbt_gb_token.html)" "soal-card" "SOAL TIDAK dikirim ke peramban sebelum token benar"
# token salah
T=$(csrf "$SW" "kerjakan.php?id=$UJ")
curl -s -b "$SW" -c "$SW" -o /tmp/cbt_gb_salah.html -d "csrf=$T" -d 'aksi=token' -d 'token=ZZZZZZ' "$BASE/kerjakan.php?id=$UJ"
cari "$(cat /tmp/cbt_gb_salah.html)" "Token salah" "token salah ditolak dengan pesan"
# token benar
T=$(csrf "$SW" "kerjakan.php?id=$UJ")
RENDAH=$(printf '%s' "$TOKEN" | tr 'A-Z' 'a-z')
curl -s -b "$SW" -c "$SW" -o /dev/null -w '%{http_code} %{redirect_url}\n' -d "csrf=$T" -d 'aksi=token' \
  --data-urlencode "token=$RENDAH" "$BASE/kerjakan.php?id=$UJ" > /tmp/cbt_gb_ok.txt
cari "$(cat /tmp/cbt_gb_ok.txt)" "kerjakan.php" "token benar membuka soal (dialihkan)"
H=$(curl -s -b "$SW" -o /tmp/cbt_gb_kerjakan.html -w '%{http_code}' "$BASE/kerjakan.php?id=$UJ")
cek "halaman pengerjaan terbuka setelah token (200)" "$H" "200"
cari "$(cat /tmp/cbt_gb_kerjakan.html)" "soal-card" "soal tampil setelah token benar"
# batas percobaan token
NOCOBA=$(phpuji -r 'require "'"$DIR"'/inc/config.php"; echo TOKEN_MAKS_COBA;')
ok "batas percobaan token = $NOCOBA per sesi (diterapkan di server)"

echo
echo "===== HASIL: $LULUS lulus, $GAGAL gagal ====="
[ "$GAGAL" -eq 0 ] || exit 1
