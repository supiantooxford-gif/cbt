/* =====================================================================================
   Uji tampilan menu "Tarik Data dari e-GURU" (Super Admin) — Playwright.
   - Halaman pemilih sekolah: lembaga cocok/tidak cocok terlihat.
   - Buka konteks sekolah -> halaman penarikan -> tarik data sungguhan dari basis data e-GURU.
   - Hasilnya benar-benar muncul di menu Data Guru & Data Siswa.
   - Kerapian: tanpa luapan mendatar pada 1440 & 390, tanpa galat konsol.
   Jalankan lewat:  bash check_cbt_tarik_eguru_ui.sh
   ===================================================================================== */
const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8901';

// Galat tak terduga dilaporkan sebagai satu baris GAGAL (jangan menggantung: peramban
// dapat tetap memegang pipa keluaran sehingga skrip bash terlihat "berhenti").
process.on('uncaughtException', (e) => {
  console.log('  GAGAL galat tak tertangkap: ' + (e && e.message ? e.message : e));
  process.exit(1);
});
process.on('unhandledRejection', (e) => {
  console.log('  GAGAL janji gagal: ' + (e && e.message ? e.message : e));
  process.exit(1);
});
let lulus = 0, gagal = 0;
const ok = (t) => { lulus++; console.log('  ok   ' + t); };
const no = (t) => { gagal++; console.log('  GAGAL ' + t); };
const cek = (t, a, b) => { String(a) === String(b) ? ok(t) : no(`${t} (dapat: ${a}, harap: ${b})`); };
const benar = (t, k) => { k ? ok(t) : no(t); };

async function masuk(page, user, sandi) {
  await page.goto(BASE + '/logout.php');
  await page.goto(BASE + '/login.php');
  await page.fill('#username', user);
  await page.fill('#password', sandi);
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
}

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await ctx.newPage();
  const galatKonsol = [];
  page.on('console', (m) => { if (m.type() === 'error') galatKonsol.push(m.text()); });
  page.on('dialog', (d) => d.accept());   // dialog konfirmasi tarik data

  console.log('== 1. Menu & halaman pemilih (Super Admin) ==');
  await masuk(page, 'admin', 'admin123');
  const menuAda = await page.isVisible('a.nav-item[href="tarik_eguru.php"]');
  benar('menu "Tarik Data dari e-GURU" tampil di sidebar', menuAda);
  if (menuAda) {
    await Promise.all([page.waitForNavigation(), page.click('a.nav-item[href="tarik_eguru.php"]')]);
  } else {
    await page.goto(BASE + '/tarik_eguru.php');
  }
  benar('halaman pemilih sekolah terbuka', (await page.content()).includes('Pilih Sekolah/Madrasah'));
  benar('tabel seluruh lembaga e-GURU tampil', await page.isVisible('#tblEguru'));
  const pilWarn = await page.$$eval('.pil-warn', (n) => n.map((x) => x.textContent.trim()));
  benar('lembaga yang pasangannya belum ditarik ditandai "Belum ditarik"', pilWarn.includes('Belum ditarik'));
  const pilMut = await page.$$eval('.pil-mut', (n) => n.map((x) => x.textContent.trim()));
  benar('lembaga yang belum ada di CBT ditandai "Belum ada di CBT"', pilMut.includes('Belum ada di CBT'));
  const pilErr = await page.$$eval('.pil-err', (n) => n.map((x) => x.textContent.trim()));
  benar('sekolah CBT tanpa pasangan ditandai "Tidak ditemukan"', pilErr.includes('Tidak ditemukan'));
  benar('daftar sekolah CBT tanpa pasangan tampil',
    (await page.content()).includes('Sekolah/Madrasah CBT yang Belum Ada di e-GURU'));
  const isiDaftar = await page.content();
  benar('seluruh lembaga e-GURU ditampilkan (termasuk yang belum ada di CBT)',
    isiDaftar.includes('MA. Uji CBT') && isiDaftar.includes('MTsS Lain Yang Tak Terpakai'));
  benar('lembaga yang belum ada di CBT diberi tombol "Buat di CBT"',
    (await page.textContent('#tblEguru')).includes('Buat di CBT'));
  benar('ringkasan jumlah lembaga e-GURU tampil', /lembaga di e-GURU/.test(await page.textContent('body')));
  let luapan = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  benar('daftar pemilih tanpa luapan mendatar (desktop)', luapan <= 1);

  console.log('== 2. Buka konteks sekolah & tarik data ==');
  await Promise.all([page.waitForNavigation(), page.click('a.btn-utama[href^="tarik_eguru.php?sekolah="]')]);
  benar('berpindah ke halaman penarikan sekolah', page.url().includes('tarik_eguru.php'));
  const isi = await page.content();
  benar('lembaga e-GURU yang cocok diumumkan', isi.includes('Lembaga cocok'));
  benar('nama lembaga e-GURU ditampilkan', isi.includes('MA. Uji CBT'));
  benar('tombol "Tarik Data Sekarang" tersedia', await page.isVisible('button.btn-utama'));
  const tombolTeks = (await page.textContent('button.btn-utama')).trim();
  benar('label tombol menyebut "Tarik Data Sekarang"', /Tarik Data Sekarang/.test(tombolTeks));
  await Promise.all([page.waitForNavigation(), page.click('button.btn-utama')]);
  const hasil = await page.content();
  benar('kartu "Hasil Penarikan Data" tampil', hasil.includes('Hasil Penarikan Data'));
  benar('pesan sukses menyebut data masuk', hasil.includes('sudah masuk ke'));
  benar('ringkasan menyebut akun yang dibuat', hasil.includes('akun guru'));
  const angka = await page.$$eval('table.tbl tbody tr td', (n) => n.map((x) => x.textContent.trim()));
  benar('tabel hasil memuat baris Guru & Siswa', angka.includes('Guru (beserta akun)') && angka.includes('Siswa (beserta akun)'));

  console.log('== 2b. Keterangan "Sudah ditarik" setelah data masuk ==');
  await page.goto(BASE + '/sekolah.php?keluar=1');
  await page.goto(BASE + '/tarik_eguru.php');
  const setelahTarik = await page.content();
  const pilOkSetelah = await page.$$eval('.pil-ok', (n) => n.map((x) => x.textContent.trim()));
  benar('pil "Sudah ditarik" tampil pada lembaga yang datanya sudah masuk', pilOkSetelah.includes('Sudah ditarik'));
  benar('tanggal penarikan terakhir dicantumkan', setelahTarik.includes('Terakhir ditarik'));
  benar('ringkasan menyebut jumlah lembaga yang sudah ditarik', /[0-9]+ sudah ditarik/.test(setelahTarik));
  await page.goto(BASE + '/tarik_eguru.php?sekolah=1');
  benar('halaman penarikan menyebut data sudah ditarik', (await page.content()).includes('Data sudah ditarik'));

  console.log('== 3. Datanya benar-benar masuk ke menu Data Guru & Siswa ==');
  await page.goto(BASE + '/guru.php');
  let konten = await page.content();
  benar('guru hasil tarikan tampil pada Data Guru', konten.includes('Yusup Abaidata, SH'));
  benar('guru baru lainnya tampil', konten.includes('Rina Marlina, S.Pd'));
  await page.goto(BASE + '/siswa.php');
  konten = await page.content();
  benar('siswa hasil tarikan tampil pada Data Siswa', konten.includes('Nurhaliza Putri'));
  await page.goto(BASE + '/kelas.php');
  konten = await page.content();
  benar('kelas hasil tarikan tampil', /<td[^>]*>\s*X\s*<\/td>/.test(konten));
  await page.goto(BASE + '/mapel.php');
  benar('mata pelajaran hasil tarikan tampil', (await page.content()).includes('Biologi'));

  console.log('== 4. Kerapian halaman pada layar kecil ==');
  for (const [lebar, nama] of [[1440, 'desktop'], [390, 'HP']]) {
    await page.setViewportSize({ width: lebar, height: 860 });
    await page.goto(BASE + '/tarik_eguru.php');
    await page.waitForTimeout(300);
    luapan = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    benar(`halaman penarikan tanpa luapan mendatar (${nama})`, luapan <= 1);
  }
  await page.setViewportSize({ width: 390, height: 860 });
  await page.goto(BASE + '/sekolah.php?keluar=1');   // lepas konteks -> kembali ke daftar pemilih
  await page.goto(BASE + '/tarik_eguru.php');
  await page.waitForTimeout(300);
  luapan = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  benar('daftar pemilih tanpa luapan mendatar (HP)', luapan <= 1);

  console.log('== 5. Tarik LEMBAGA dari halaman Kelola Sekolah (Tambah Sekolah) ==');
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(BASE + '/sekolah.php?keluar=1');
  await page.goto(BASE + '/sekolah.php');
  benar('kartu "Tarik Data Lembaga dari e-GURU" tampil', await page.isVisible('#tarikLembaga'));
  benar('medan pencarian nama lembaga tersedia', await page.isVisible('#eguru_cari'));
  const saran = await page.evaluate(() =>
    Array.from(document.querySelectorAll('#daftarLembagaEguru option')).map((o) => o.value));
  benar('saran nama lembaga e-GURU tersedia (' + saran.length + ')', saran.length >= 3);
  const daftarNama = await page.textContent('#tarikLembaga');
  benar('daftar lembaga dapat dibuka tanpa JavaScript', daftarNama.includes('Lihat daftar lembaga e-GURU'));

  await page.fill('#eguru_cari', 'tak-terpakai');
  await Promise.all([page.waitForNavigation(), page.click('#btnCariLembaga')]);
  const halCari = await page.content();
  benar('pencarian menemukan lembaga', halCari.includes('MTsS Lain Yang Tak Terpakai'));
  benar('formulir penarikan disiapkan setelah ditemukan', halCari.includes('Tarik &amp; Buat Sekolah'));
  benar('identitas lembaga ditampilkan', halCari.includes('Jl. Uji No. 2') && halCari.includes('Kepala Lembaga Lain'));

  // v1.20.0 (permintaan pemilik): nama, nama pengguna, dan kata sandi akun Admin Operator diambil
  // dari AKUN OPERATOR e-GURU lembaga itu — Super Admin tidak mengetik apa pun di sini.
  cek('nama admin terisi dari akun operator e-GURU',
    (await page.inputValue('input[name="nama_admin"]')), 'Operator MTsS Lain');
  cek('nama pengguna terisi dari akun operator e-GURU',
    (await page.inputValue('input[name="username"]')), 'opmtss');
  benar('nama pengguna e-GURU tidak dapat diubah di formulir',
    (await page.getAttribute('input[name="username"]', 'readonly')) !== null);
  benar('tidak ada isian kata sandi baru (mengikuti e-GURU)',
    (await page.$('input[name="password"]')) === null);
  benar('keterangan kata sandi mengikuti akun e-GURU tampil',
    (await page.inputValue('#sandiIkutEguru')).includes('Mengikuti kata sandi akun e-GURU'));
  benar('centang "sekalian tarik" aktif', await page.isChecked('input[name="sekalian"]'));
  await Promise.all([page.waitForNavigation({ timeout: 30000 }), page.click('#btnKirimTarik')]);
  const halBaru = await page.content();
  benar('pesan sukses penarikan lembaga tampil', halBaru.includes('ditarik dari e-GURU'));
  benar('pesan menyebut data yang ikut masuk', /Data yang ikut masuk: \d+ kelas/.test(halBaru));
  benar('nama lembaga menjadi nama sekolah', halBaru.includes('MTsS Lain Yang Tak Terpakai'));
  benar('NPSN terisi dari e-GURU', (await page.inputValue('input[name="npsn"]')) === '222333');
  benar('kecamatan terisi dari e-GURU', (await page.inputValue('input[name="kecamatan"]')) === 'Mootilango');
  benar('nama kepala terisi dari e-GURU', (await page.inputValue('input[name="kepala"]')) === 'Kepala Lembaga Lain');
  benar('akun Admin Operator memakai nama pengguna e-GURU', halBaru.includes('opmtss'));
  benar('pesan menyebut kata sandi mengikuti akun e-GURU',
    halBaru.includes('kata sandi mengikuti akun operator e-GURU'));
  benar('dampak menunjukkan guru & siswa hasil tarikan',
    /Dampak: 1 guru, 1 siswa/.test(halBaru.replace(/\s+/g, ' ')));

  await page.goto(BASE + '/sekolah.php');
  const daftarSekolah = await page.content();
  benar('sekolah baru tampil pada daftar sekolah', daftarSekolah.includes('MTsS Lain Yang Tak Terpakai'));
  benar('lembaga yang sudah ada ditandai di daftar lembaga',
    (await page.textContent('#tarikLembaga')).includes('Sudah ada di CBT'));

  await page.setViewportSize({ width: 390, height: 860 });
  await page.goto(BASE + '/sekolah.php');
  await page.waitForTimeout(300);
  const luapanSekolah = await page.evaluate(() =>
    document.documentElement.scrollWidth - document.documentElement.clientWidth);
  benar('halaman Kelola Sekolah tanpa luapan mendatar (HP)', luapanSekolah <= 1);

  console.log('== 6. Masa aktif 365 hari + hitung mundur (bukan Permanen) ==');
  await page.goto(BASE + '/sekolah.php?keluar=1');
  await masuk(page, 'opmtss', 'SandiOperator2');   // akun operator e-GURU hasil tarikan
  await page.goto(BASE + '/index.php');
  const kartuText = (await page.textContent('body')).replace(/\s+/g, ' ');
  benar('kartu Masa Aktif tidak lagi berbunyi Permanen', !/Masa Aktif Permanen/.test(kartuText));
  benar('kartu Masa Aktif memuat jumlah hari', /Masa Aktif\s*3[0-9][0-9] hari/.test(kartuText));
  benar('hitung mundur ringkas tampil di dashboard', await page.isVisible('[data-hm-mini]'));
  const miniAwal = (await page.textContent('[data-hm-mini]')).trim();
  benar('bentuk hitung mundur ringkas benar (' + miniAwal + ')', /^Sisa \d+ hari \d\d:\d\d:\d\d$/.test(miniAwal));
  await page.waitForTimeout(2300);
  const miniAkhir = (await page.textContent('[data-hm-mini]')).trim();
  benar('hitung mundur dashboard benar-benar berjalan tanpa muat ulang', miniAwal !== miniAkhir);

  await page.goto(BASE + '/aktivasi.php');
  benar('blok hitung mundur tampil', await page.isVisible('#hitungMundurAktivasi'));
  const unit = await page.$$eval('#hitungMundurAktivasi .hm-unit', (n) => n.map((x) => ({
    hari: (x.querySelector('[data-hm="hari"]') || {}).textContent,
    jam: (x.querySelector('[data-hm="jam"]') || {}).textContent,
    label: (x.querySelector('span') || {}).textContent
  })));
  cek('empat kotak satuan (Hari/Jam/Menit/Detik)', unit.length, 4);
  cek('label satuan berurutan', unit.map((u) => u.label).join(','), 'Hari,Jam,Menit,Detik');
  benar('satuan Hari berisi 3xx', /^\d{3}$/.test((unit[0] || {}).hari || ''));
  const ketAwal = (await page.textContent('#hitungMundurAktivasi .hitung-mundur-ket')).trim();
  benar('keterangan sisa masa aktif (' + ketAwal + ')', /^Sisa \d+ hari \d\d:\d\d:\d\d$/.test(ketAwal));
  await page.waitForTimeout(2500);
  const ketAkhir = (await page.textContent('#hitungMundurAktivasi .hitung-mundur-ket')).trim();
  benar('hitung mundur benar-benar menurun tanpa muat ulang (' + ketAwal + ' → ' + ketAkhir + ')',
    ketAwal !== ketAkhir);
  benar('keterangan masa aktif 1 tahun tampil',
    (await page.textContent('#hitungMundurAktivasi')).includes('365 hari (1 tahun)'));

  console.log('== 7. Kerapian hitung mundur pada layar kecil ==');
  for (const [lebar, nama] of [[1440, 'desktop'], [390, 'HP']]) {
    await page.setViewportSize({ width: lebar, height: 880 });
    await page.goto(BASE + '/aktivasi.php');
    await page.waitForTimeout(300);
    const pos = await page.$$eval('#hitungMundurAktivasi .hm-unit', (n) => n.map((x) => ({
      atas: Math.round(x.getBoundingClientRect().top), lebar: Math.round(x.getBoundingClientRect().width)
    })));
    benar(`empat kotak tetap SEBARIS (${nama})`, new Set(pos.map((p) => p.atas)).size === 1);
    benar(`lebar kotak seragam (${nama})`, new Set(pos.map((p) => p.lebar)).size === 1);
    const luapanAkt = await page.evaluate(() =>
      document.documentElement.scrollWidth - document.documentElement.clientWidth);
    benar(`halaman Status & Aktivasi tanpa luapan (${nama})`, luapanAkt <= 1);
  }
  await page.goto(BASE + '/index.php');
  await page.waitForTimeout(300);
  const luapanDas = await page.evaluate(() =>
    document.documentElement.scrollWidth - document.documentElement.clientWidth);
  benar('dashboard tanpa luapan mendatar (HP)', luapanDas <= 1);

  benar('tanpa galat konsol', galatKonsol.length === 0);
  if (galatKonsol.length) { console.log('     ' + galatKonsol.slice(0, 3).join(' | ')); }

  await browser.close();
  console.log(`\n  ${lulus} lulus, ${gagal} gagal`);
  process.exit(gagal === 0 ? 0 : 1);
})();
