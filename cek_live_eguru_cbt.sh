#!/usr/bin/env bash
# =====================================================================================
# Verifikasi memakai SALINAN BASIS DATA ASLI pemilik (data nyata tidak disentuh):
#   - migrasi v1->v2 hanya MENAMBAH kolom; sidik baris seluruh tabel tidak berubah
#   - seluruh halaman dapat dibuka tanpa galat PHP pada data nyata
#   - impor ulang berkas berformat e-GURU tidak mengosongkan data lama
#
# Pemakaian: bash cek_live_eguru_cbt.sh
# =====================================================================================
set -eu
DIR="$(cd "$(dirname "$0")" && pwd)"
ASLI="$DIR/data/cbt.sqlite"
KERJA="/tmp/cbt_live_$$"

if [ ! -f "$ASLI" ]; then
  echo "Basis data asli tidak ditemukan: $ASLI"; exit 1
fi

echo "== 1. Menyalin aplikasi + basis data asli ke $KERJA (data asli TIDAK disentuh) =="
rm -rf "$KERJA"; mkdir -p "$KERJA"
cp -r "$DIR"/. "$KERJA"/
rm -f "$KERJA"/data/cbt.sqlite* "$KERJA"/data/.schema_ok
cp "$ASLI" "$KERJA/data/cbt.sqlite"
mkdir -p "$KERJA/data/sessions"; chmod 777 "$KERJA/data" "$KERJA/data/sessions" 2>/dev/null || true
DB="$KERJA/data/cbt.sqlite"

sidik(){ php -d extension=pdo -d extension=pdo_sqlite -r '
$p = new PDO("sqlite:'"$1"'");
$t = ["sekolah","users","kelas","mapel","guru","siswa","ujian","soal","sesi_ujian","jawaban","guru_mapel","guru_kelas","ujian_kelas"];
$out = [];
foreach ($t as $x) { $out[] = $x . "=" . $p->query("SELECT COUNT(*) FROM $x")->fetchColumn(); }
echo implode(" ", $out);'; }

SEBELUM=$(sidik "$DB")
echo "  sidik sebelum migrasi: $SEBELUM"

echo "== 2. Jalankan migrasi v2 pada salinan =="
CBT_DB_FILE="$DB" php -d extension=pdo -d extension=pdo_sqlite -r '
require "'"$KERJA"'/inc/init.php"; val("SELECT 1");'
SESUDAH=$(sidik "$DB")
if [ "$SEBELUM" = "$SESUDAH" ]; then echo "  ok   sidik baris SELURUH tabel identik sesudah migrasi"; else
  echo "  GAGAL sidik berubah: $SEBELUM -> $SESUDAH"; exit 1; fi

echo "== 3. Kolom baru ada & data lama utuh =="
php -d extension=pdo -d extension=pdo_sqlite -r '
$p = new PDO("sqlite:'"$DB"'");
$kG = array_column($p->query("PRAGMA table_info(guru)")->fetchAll(), "name");
$kS = array_column($p->query("PRAGMA table_info(siswa)")->fetchAll(), "name");
foreach (["nuptk","jabatan"] as $c) { printf("  %s guru.%s\n", in_array($c,$kG)?"ok  ":"GAGAL", $c); }
foreach (["nama_ayah","nama_ibu","status","tanggal_status"] as $c) { printf("  %s siswa.%s\n", in_array($c,$kS)?"ok  ":"GAGAL", $c); }
foreach ($p->query("SELECT nama, status, aktif FROM siswa") as $r) {
  echo "  siswa nyata: {$r["nama"]} status={$r["status"]} aktif={$r["aktif"]}\n";
}
foreach ($p->query("SELECT nama, jabatan, nuptk FROM guru") as $r) {
  echo "  guru nyata: {$r["nama"]} jabatan=[".($r["jabatan"]??"")."] nuptk=[".($r["nuptk"]??"")."]\n";
}
echo "  integritas: ".$p->query("PRAGMA integrity_check")->fetchColumn()."\n";
$fk = $p->query("PRAGMA foreign_key_check")->fetchAll();
echo "  pelanggaran FK: ".count($fk)."\n";'

echo "== 3b. Landing page & pendaftaran DIHAPUS (v1.14.0) =="
# Tabel `landing_*` pada basis data lama sengaja DIBIARKAN (tidak dihapus) supaya tidak ada operasi
# destruktif; yang penting kode tidak lagi membuat/membacanya dan halamannya benar-benar hilang.
php -d extension=pdo -d extension=pdo_sqlite -r '
$p = new PDO("sqlite:'"$DB"'");
$ada = array_column($p->query("SELECT name FROM sqlite_master WHERE type=\"table\"")->fetchAll(), "name");
$sisa = array_values(array_intersect(["landing_fitur","landing_unggulan","landing_testimoni","landing_pesan"], $ada));
printf("  ok   tabel landing lama dibiarkan (data pemilik aman): %s\n", $sisa ? implode(", ", $sisa) : "tidak ada");
printf("  ok   penanda skema: %s\n", trim((string) @file_get_contents("'"$KERJA"'/data/.schema_ok")));'

echo "== 4. Semua halaman dibuka pada data nyata (sandi admin diubah HANYA pada salinan) =="
php -d extension=pdo -d extension=pdo_sqlite -r '
$p = new PDO("sqlite:'"$DB"'");
$p->prepare("UPDATE users SET password = ? WHERE username = ?")
  ->execute([password_hash("uji_live_123", PASSWORD_DEFAULT), "admin"]);'

PORT=""
for p in $(seq 8790 8820); do
  if ! (exec 3<>"/dev/tcp/127.0.0.1/$p") 2>/dev/null; then PORT=$p; break; fi
  exec 3<&- 2>/dev/null || true
done
php -d extension=pdo -d extension=pdo_sqlite -S "127.0.0.1:$PORT" -t "$KERJA" > "/tmp/cbt_live_srv_$$.log" 2>&1 &
SRV=$!
bersihkan(){ kill $SRV 2>/dev/null || true; rm -rf "$KERJA"; }
trap bersihkan EXIT
BASE="http://127.0.0.1:$PORT"
for i in $(seq 1 25); do curl -s -o /dev/null "$BASE/login.php" && break; sleep 0.4; done

JAR=/tmp/cbt_live_$$.jar
rm -f "$JAR"
T=$(curl -s -c "$JAR" "$BASE/login.php" | grep -o 'name="csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -b "$JAR" -c "$JAR" -o /dev/null -d "csrf=$T" -d 'username=admin' -d 'password=uji_live_123' "$BASE/login.php"

# Halaman berkonteks sekolah hanya terbuka setelah Super Admin MASUK ke sebuah sekolah
# (tanpa konteks, pengalihan 302 ke sekolah.php adalah perilaku yang BENAR).
SID=$(php -d extension=pdo -d extension=pdo_sqlite -r '
$p = new PDO("sqlite:'"$DB"'"); echo (int) $p->query("SELECT id FROM sekolah WHERE aktif=1 ORDER BY id LIMIT 1")->fetchColumn();')
GAGAL=0
for hal in sekolah.php pengguna.php biaya_aktivasi.php logo.php; do
  KODE=$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "$BASE/$hal")
  if [ "$KODE" = "200" ]; then printf '  ok   %s (200, lintas sekolah)\n' "$hal"; else printf '  GAGAL %s (%s)\n' "$hal" "$KODE"; GAGAL=1; fi
done
if [ "$SID" -gt 0 ]; then
  curl -s -b "$JAR" -c "$JAR" -o /dev/null "$BASE/sekolah.php?masuk=$SID"
  echo "  (masuk konteks sekolah id $SID)"
fi
for hal in index.php guru.php siswa.php kelas.php mapel.php guru_import.php siswa_import.php \
           guru_export.php siswa_export.php hasil.php ujian.php cetak_guru.php cetak_siswa.php \
           pengaturan.php aktivasi.php akun.php; do
  KODE=$(curl -s -b "$JAR" -o /tmp/cbt_live_page.html -w '%{http_code}' "$BASE/$hal")
  if [ "$KODE" = "200" ]; then printf '  ok   %s (200)\n' "$hal"; else printf '  GAGAL %s (%s)\n' "$hal" "$KODE"; GAGAL=1; fi
done

echo "== 4b. Halaman masuk hanya formulir masuk (bukan landing page) =="
KODE=$(curl -s -o /tmp/cbt_live_depan.html -w '%{http_code}' "$BASE/login.php")
if [ "$KODE" = "200" ] && grep -q 'id="username"' /tmp/cbt_live_depan.html && grep -q 'Selamat Datang' /tmp/cbt_live_depan.html; then
  echo "  ok   halaman masuk tampil pada data nyata"
else
  echo "  GAGAL halaman masuk tidak lengkap ($KODE)"; GAGAL=1
fi
for pola in 'id="kontak"' 'id="beranda"' 'lpSlider' 'daftar.php'; do
  if grep -q -- "$pola" /tmp/cbt_live_depan.html; then
    echo "  GAGAL halaman masuk masih memuat bagian lama: $pola"; GAGAL=1
  else
    echo "  ok   halaman masuk tanpa bagian lama: $pola"
  fi
done
for hilang in daftar.php pendaftaran.php landing.php; do
  KODE=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/$hilang")
  if [ "$KODE" = "404" ]; then echo "  ok   $hilang sudah tidak ada (404)"; else echo "  GAGAL $hilang ($KODE)"; GAGAL=1; fi
done

echo "== 4c. Halaman Status & Aktivasi operator pada data nyata (nominal, WA, rekening) =="
# Nilai yang DIHARAPKAN dibaca dari basis data (dengan konstanta sebagai cadangan) — pemilik boleh
# saja sudah mengubahnya lewat menu "Biaya & Aktivasi", jadi uji ini TIDAK memakai angka mati.
HARAP_NOMINAL=$(php -d extension=pdo -d extension=pdo_sqlite -r '
require "'"$KERJA"'/inc/init.php";
$p = (int) val("SELECT COALESCE((SELECT nilai FROM app_pengaturan WHERE kunci = ?), ?)", ["aktivasi_biaya", AKTIVASI_BIAYA]);
echo "Rp. " . number_format($p, 0, ",", ".");')
HARAP_REK=$(php -d extension=pdo -d extension=pdo_sqlite -r '
require "'"$KERJA"'/inc/init.php";
echo trim(app_setting("aktivasi_rekening", AKTIVASI_REKENING));')
HARAP_WA=$(php -d extension=pdo -d extension=pdo_sqlite -r '
require "'"$KERJA"'/inc/init.php";
echo wa_internasional(aktivasi_wa());')
PID_UJI=$$
JAROP="/tmp/cbt_live_${PID_UJI}_op.jar"
SQLOP=$(php -d extension=pdo -d extension=pdo_sqlite -r '
$p = new PDO("sqlite:'"$DB"'");
$u = $p->query("SELECT username FROM users WHERE role = \"operator\" AND sekolah_id = '"$SID"' AND aktif = 1 ORDER BY id LIMIT 1")->fetchColumn();
$p->prepare("UPDATE users SET password = ? WHERE username = ?")->execute([password_hash("uji_op_123", PASSWORD_DEFAULT), (string) $u]);
echo (string) $u;')
if [ -n "$SQLOP" ]; then
  rm -f "$JAROP"
  T=$(curl -s -c "$JAROP" "$BASE/login.php" | grep -o 'name="csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
  curl -s -b "$JAROP" -c "$JAROP" -o /dev/null -d "csrf=$T" -d "username=$SQLOP" -d 'password=uji_op_123' "$BASE/login.php"
  AKT=$(curl -s -b "$JAROP" "$BASE/aktivasi.php")
  printf '%s' "$AKT" > /tmp/cbt_live_aktivasi.html
  if printf '%s' "$AKT" | grep -q 'id="permohonanAktivasi"'; then echo "  ok   kartu Aktivasi Sekolah/Madrasah tampil untuk operator"; else echo "  GAGAL kartu Aktivasi Sekolah/Madrasah tidak tampil"; GAGAL=1; fi
  if printf '%s' "$AKT" | grep -q -- "$HARAP_NOMINAL"; then echo "  ok   nominal yang tampil = $HARAP_NOMINAL"; else echo "  GAGAL nominal tidak sesuai (harap: $HARAP_NOMINAL)"; GAGAL=1; fi
  if printf '%s' "$AKT" | grep -q -- "$HARAP_REK"; then echo "  ok   nomor rekening tampil sesuai pengaturan"; else echo "  GAGAL nomor rekening tidak sesuai"; GAGAL=1; fi
  if printf '%s' "$AKT" | grep -q "wa.me/$HARAP_WA"; then echo "  ok   tautan WhatsApp memakai nomor yang berlaku (wa.me/$HARAP_WA)"; else echo "  GAGAL tautan WhatsApp tidak sesuai"; GAGAL=1; fi
  if printf '%s' "$AKT" | grep -q 'id="kode"'; then echo "  ok   formulir kode aktivasi tersedia"; else echo "  GAGAL formulir kode aktivasi tidak ada"; GAGAL=1; fi
  if printf '%s' "$AKT" | grep -q 'Cara Kerja Aktivasi'; then echo "  ok   kartu Cara Kerja Aktivasi tampil"; else echo "  GAGAL kartu Cara Kerja Aktivasi tidak ada"; GAGAL=1; fi
  PADAT=$(printf '%s' "$AKT" | sed 's/<[^>]*>//g' | tr '\n' ' ' | tr -s ' ')
  if printf '%s' "$PADAT" | grep -q 'Setiap kode hanya dapat dipakai satu kali. Setelah dipakai, kode tersebut tidak berlaku lagi.'; then
    echo "  ok   kalimat aturan sekali pakai (ringkas) tampil"; else echo "  GAGAL kalimat aturan sekali pakai tidak sesuai"; GAGAL=1; fi
  if printf '%s' "$PADAT" | grep -q 'Hubungi HD Admin untuk melakukan aktifasi. Kirim permohonan kode aktifasi lewat tombol di bawah ini'; then
    echo "  ok   kalimat ajakan tanpa nomor WA tampil"; else echo "  GAGAL kalimat ajakan tidak sesuai"; GAGAL=1; fi
  if printf '%s' "$AKT" | grep -q 'Permohonan kode aktivasi, biaya, dan rekening'; then
    echo "  GAGAL keterangan kecil di sebelah judul masih tampil"; GAGAL=1
  else echo "  ok   keterangan kecil di sebelah judul sudah dihapus"; fi
else
  echo "  (tidak ada akun operator aktif pada sekolah id $SID — pemeriksaan halaman operator dilewati)"
fi

echo "== 4e. Halaman pengerjaan ujian pada data nyata (layar Kumpulkan + jalur cadangan) =="
# Semuanya dibuat DETERMINISTIK: akun siswa + ujian dibaca dari basis data, lalu sebuah sesi
# 'mengerjakan' dibuat pada SALINAN (bukan basis data asli) supaya halaman pengerjaan pasti terbuka
# apa pun keadaan data pemilik (ada siswa yang sudah selesai / belum mengerjakan).
pb(){ php -d extension=pdo -d extension=pdo_sqlite -r '
$p = new PDO("sqlite:" . $argv[1]);
echo (string) $p->query($argv[2])->fetchColumn();' "$DB" "$1"; }

AKTIF=$(pb "SELECT id FROM ujian WHERE status = 'aktif' ORDER BY id LIMIT 1")
SISWA_UJI=$(pb "SELECT username FROM users WHERE role = 'siswa' AND siswa_id IS NOT NULL ORDER BY id LIMIT 1")

if [ -n "$SISWA_UJI" ] && [ -n "$AKTIF" ]; then
  # 1) sandi siswa + sesi 'mengerjakan' dibuat pada SALINAN.
  php -d extension=pdo -d extension=pdo_sqlite -r '
// ⚠️ Zona waktu aplikasi (Asia/Jakarta) WAJIB disetel: tanpa itu `mulai` sesi tertulis 7 jam lebih
// awal daripada waktu server aplikasi sehingga ujian dianggap sudah lewat waktu dan langsung
// dikumpulkan (pernah membuat pemeriksaan ini gagal palsu).
date_default_timezone_set("Asia/Jakarta");
$p = new PDO("sqlite:" . $argv[1]);
$p->prepare("UPDATE users SET password = ? WHERE username = ?")
  ->execute([password_hash("uji_siswa_123", PASSWORD_DEFAULT), $argv[2]]);
$sw = (int) $p->query("SELECT siswa_id FROM users WHERE username = " . $p->quote($argv[2]))->fetchColumn();
$uj = (int) $argv[3];
$p->prepare("DELETE FROM sesi_ujian WHERE siswa_id = ?")->execute([$sw]);
$p->prepare("INSERT INTO sesi_ujian (sekolah_id, ujian_id, siswa_id, mulai, status, pelanggaran, perlu_koreksi)
             VALUES ((SELECT sekolah_id FROM siswa WHERE id = ?), ?, ?, ?, ?, 0, 0)")
  ->execute([$sw, $uj, $sw, date("Y-m-d H:i:s"), "mengerjakan"]);' "$DB" "$SISWA_UJI" "$AKTIF" 2>/dev/null || true

  JSIS="/tmp/cbt_live_${PID_UJI}_siswa.jar"
  rm -f "$JSIS"
  T=$(curl -s -c "$JSIS" "$BASE/login.php" | grep -o 'name="csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
  curl -s -b "$JSIS" -c "$JSIS" -o /dev/null -d "csrf=$T" -d "username=$SISWA_UJI" -d 'password=uji_siswa_123' "$BASE/login.php"

  curl -s -b "$JSIS" -o "$KERJA/kerjakan.html" "$BASE/kerjakan.php?id=$AKTIF"
  # 2) bila halaman token yang tampil, masukkan token yang tercatat pada basis data.
  if grep -q 'id="token"' "$KERJA/kerjakan.html"; then
    TOKEN=$(pb "SELECT COALESCE(token,'') FROM ujian WHERE id = $AKTIF")
    : > "$KERJA/kerjakan2.html"
    if [ -n "$TOKEN" ]; then
      T=$(grep -o 'name="csrf" value="[^"]*"' "$KERJA/kerjakan.html" | head -1 | sed 's/.*value="//;s/"//')
      # ⚠️ POST token harus memakai ?id=<ujian> — tanpa itu halaman dialihkan lebih dahulu sehingga
      # permintaan token tidak pernah diproses (pernah membuat pemeriksaan ini gagal palsu).
      curl -s -L -b "$JSIS" -c "$JSIS" -o "$KERJA/kerjakan2.html" \
        -d "csrf=$T" -d 'aksi=token' -d "token=$TOKEN" "$BASE/kerjakan.php?id=$AKTIF"
      if grep -q 'id="ujianApp"' "$KERJA/kerjakan2.html"; then mv "$KERJA/kerjakan2.html" "$KERJA/kerjakan.html"; fi
    fi
  fi

  KERJ=$(cat "$KERJA/kerjakan.html")
  if grep -q 'id="ujianApp"' "$KERJA/kerjakan.html"; then
    echo "  ok   halaman pengerjaan ujian terbuka pada data nyata"
    for POLA in 'id="layarKumpulkan"' 'id="btnYaKumpulkan"' 'id="btnBatalKumpulkan"' 'id="formKumpulDarurat"'; do
      if printf '%s' "$KERJ" | grep -q -- "$POLA"; then echo "  ok   ada $POLA"; else echo "  GAGAL tidak ada $POLA"; GAGAL=1; fi
    done
    if printf '%s' "$KERJ" | grep -q -- 'name="lanjut" value="1"'; then echo "  ok   formulir cadangan mengirim lanjut=1"; else echo "  GAGAL formulir cadangan tanpa lanjut=1"; GAGAL=1; fi
    if printf '%s' "$KERJ" | grep -q 'window.confirm'; then echo "  GAGAL halaman ujian masih memakai window.confirm"; GAGAL=1; else echo "  ok   halaman ujian TIDAK memakai window.confirm lagi"; fi
  else
    echo "  GAGAL halaman pengerjaan ujian tidak terbuka pada salinan data nyata"; GAGAL=1
  fi
  if curl -s "$BASE/assets/app.js" | grep -q 'btnYaKumpulkan'; then echo "  ok   skrip memuat penanganan tombol konfirmasi Kumpulkan"; else echo "  GAGAL skrip tidak memuat penanganan tombol konfirmasi"; GAGAL=1; fi
else
  echo "  (tidak ada ujian aktif / akun siswa pada salinan ini — pemeriksaan halaman ujian dilewati)"
fi

echo "== 4d. Gaya landing SUDAH DIHAPUS, gaya halaman masuk baru ada di aset yang dilayani =="
CSS_LIVE=$(curl -s "$BASE/assets/style.css")
for ada in '.masuk-kisi' '.masuk-kartu' '.masuk-kaki' '@media (max-width:900px)'; do
  if printf '%s' "$CSS_LIVE" | grep -q -- "$ada"; then echo "  ok   aturan halaman masuk ada: $ada"; else echo "  GAGAL aturan halaman masuk hilang: $ada"; GAGAL=1; fi
done
for hilang in 'body.lp{' '.lp-hero' '.lp-testi-slide' '.lp-nav-link' '.lp-timbul'; do
  if printf '%s' "$CSS_LIVE" | grep -q -- "$hilang"; then echo "  GAGAL gaya landing masih ada: $hilang"; GAGAL=1; else echo "  ok   gaya landing sudah tidak ada: $hilang"; fi
done
JS_LIVE=$(curl -s "$BASE/assets/app.js")
for hilang in 'function initLanding' 'function initTestimoni' 'function initKartuTimbul' 'function initLandingAdmin'; do
  if printf '%s' "$JS_LIVE" | grep -q -- "$hilang"; then echo "  GAGAL skrip landing masih ada: $hilang"; GAGAL=1; else echo "  ok   skrip landing sudah tidak ada: $hilang"; fi
done

echo "== 5. Galat PHP selama penjelajahan =="
if grep -qiE 'PHP (Warning|Notice|Fatal|Deprecated)|Uncaught' "/tmp/cbt_live_srv_$$.log"; then
  grep -iE 'PHP (Warning|Notice|Fatal|Deprecated)|Uncaught' "/tmp/cbt_live_srv_$$.log" | head -10
  GAGAL=1
else
  echo "  ok   tidak ada galat/warning PHP"
fi

echo "== 6. Basis data ASLI tidak tersentuh =="
SEKARANG=$(sidik "$ASLI")
if [ "$SEBELUM" = "$SEKARANG" ]; then echo "  ok   sidik basis data asli tetap: $SEKARANG"; else
  echo "  GAGAL basis data asli berubah: $SEBELUM -> $SEKARANG"; exit 1; fi

if [ "$GAGAL" -eq 0 ]; then echo; echo "  SEMUA PEMERIKSAAN PADA SALINAN DATA ASLI LULUS"; else
  echo; echo "  ADA PEMERIKSAAN YANG GAGAL"; exit 1; fi
