/* =====================================================================================
   Uji tampilan Computer-Based Test (CBT) — Playwright.
   - Daftar ujian siswa, layar konfirmasi layar penuh, hitung mundur yang benar-benar berjalan.
   - Penanda jawaban pada navigasi soal.
   - Deteksi pelanggaran (blur jendela) -> peringatan -> pengumpulan otomatis pada pelanggaran ke-3.
   - Kerapian: tanpa luapan mendatar pada 1440 dan 390, tanpa galat konsol.
   Jalankan lewat:  bash check_cbt_ui.sh
   ===================================================================================== */
const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8901';
let lulus = 0, gagal = 0;
const ok = (t) => { lulus++; console.log('  ok   ' + t); };
const no = (t) => { gagal++; console.log('  GAGAL ' + t); };
const cek = (t, a, b) => { String(a) === String(b) ? ok(t) : no(`${t} (dapat: ${a}, harap: ${b})`); };
const benar = (t, k) => { k ? ok(t) : no(t); };

async function masuk(page, user, sandi) {
  await page.goto(BASE + '/logout.php');   // pastikan tidak ada sesi sebelumnya
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

  /* ---------------------------------------------------------------- guru membuat ujian sudah disiapkan fixture */
  console.log('== 1. Siswa: daftar ujian ==');
  await masuk(page, 'siswa1', 'siswa123');
  await page.goto(BASE + '/ujian_siswa.php');
  benar('daftar ujian memuat ujian yang diaktifkan', (await page.content()).includes('Ulangan Harian Matematika'));
  benar('pemberitahuan mode layar penuh tampil', (await page.content()).includes('mode layar penuh'));
  const tautanKerjakan = await page.$('a.btn-utama');
  benar('tombol Kerjakan tersedia', !!tautanKerjakan);

  console.log('== 2. Halaman pengerjaan ==');
  await Promise.all([page.waitForNavigation(), tautanKerjakan.click()]);
  benar('layar konfirmasi "Siap Mengerjakan?" tampil', await page.isVisible('#layarMasukUjian'));
  const timerAwal = (await page.textContent('#timerUjian')).trim();
  benar('penghitung waktu terisi (' + timerAwal + ')', /\d+:\d\d/.test(timerAwal));
  const jumlahSoal = await page.$$eval('.soal-card', (n) => n.length);
  cek('enam soal tampil', jumlahSoal, 6);
  const jenisTampil = await page.$$eval('.soal-jenis', (n) => n.map((x) => x.textContent.trim()));
  benar('jenis soal pilihan ganda tampil', jenisTampil.includes('Pilihan Ganda'));
  benar('jenis soal uraian tampil', jenisTampil.includes('Uraian / Essai'));

  await page.click('#btnMulaiUjian');
  await page.waitForTimeout(600);
  benar('overlay konfirmasi tertutup setelah mode ujian dimulai', !(await page.isVisible('#layarMasukUjian')));
  await page.waitForTimeout(2500);
  const timerAkhir = (await page.textContent('#timerUjian')).trim();
  benar('hitung mundur benar-benar berjalan (' + timerAwal + ' -> ' + timerAkhir + ')', timerAwal !== timerAkhir);

  console.log('== 3. Menjawab soal ==');
  await page.locator('.soal-card').first().locator('.opsi').nth(1).click();
  await page.waitForTimeout(900);
  const navDijawab = await page.$$eval('.nav-grid a.dijawab', (n) => n.length);
  cek('navigasi menandai satu soal terjawab', navDijawab, 1);
  const jumlahTerjawab = (await page.textContent('#jumlahTerjawab')).trim();
  cek('penghitung "terjawab" ikut bertambah', jumlahTerjawab, '1');

  console.log('== 4. Deteksi pelanggaran ==');
  await page.evaluate(() => window.dispatchEvent(new Event('blur')));
  await page.waitForTimeout(900);
  benar('peringatan pelanggaran muncul', await page.isVisible('#layarPelanggaran'));
  const pesan1 = await page.textContent('#pesanPelanggaran');
  benar('peringatan menyebut pelanggaran ke-1 dari 3', /ke-1 dari 3/.test(pesan1));
  await page.click('#btnLanjutUjian');
  await page.waitForTimeout(400);
  benar('peringatan dapat ditutup dan siswa melanjutkan', !(await page.isVisible('#layarPelanggaran')));
  await page.evaluate(() => window.dispatchEvent(new Event('blur')));
  await page.waitForTimeout(900);
  const pesan2 = await page.textContent('#pesanPelanggaran');
  benar('peringatan kedua tercatat', /ke-2 dari 3/.test(pesan2));
  await page.click('#btnLanjutUjian');
  await page.waitForTimeout(400);
  await page.evaluate(() => window.dispatchEvent(new Event('blur')));
  await Promise.all([page.waitForNavigation({ timeout: 15000 }), page.waitForTimeout(300)]);
  await page.waitForTimeout(1500);
  benar('pelanggaran ke-3 memindahkan siswa ke halaman hasil', page.url().includes('selesai.php'));
  benar('halaman hasil menyebut penghentian karena pelanggaran',
    (await page.content()).includes('batas pelanggaran'));

  console.log('== 5. Penguncian akun siswa ==');
  await masuk(page, 'siswa2', 'siswa123');
  await page.goto(BASE + '/ujian_siswa.php');
  await Promise.all([page.waitForNavigation(), (await page.$('a.btn-utama')).click()]);
  await page.waitForTimeout(300);
  const sudahKerjakan = page.url().includes('kerjakan.php');
  if (sudahKerjakan) {
    await page.click('#btnMulaiUjian').catch(() => {});
    await page.waitForTimeout(300);
    await page.goto(BASE + '/guru.php');
    await page.waitForTimeout(300);
    benar('halaman lain dialihkan ke kerjakan.php selama ujian berjalan', page.url().includes('kerjakan.php'));
    const adaMenu = await page.$('.sidebar');
    benar('menu aplikasi tidak ditampilkan pada halaman ujian', !adaMenu);
  } else {
    no('halaman pengerjaan tidak terbuka untuk siswa kedua');
  }

  console.log('== 6. Kerapian tata letak ==');
  for (const [lebar, nama] of [[1440, 'desktop'], [390, 'HP']]) {
    await page.setViewportSize({ width: lebar, height: 820 });
    await page.goto(BASE + '/kerjakan.php');   // tanpa ?id -> memakai sesi yang sedang berjalan
    await page.waitForTimeout(700);
    const luapan = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    benar(`tanpa luapan mendatar di halaman ujian (${nama})`, luapan <= 1);
  }

  await page.setViewportSize({ width: 390, height: 820 });
  await masuk(page, 'siswa1', 'siswa123');
  await page.goto(BASE + '/ujian_siswa.php');
  await page.waitForTimeout(400);
  const luapan2 = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  benar('tanpa luapan mendatar pada daftar ujian (HP)', luapan2 <= 1);

  await page.setViewportSize({ width: 1440, height: 900 });
  await masuk(page, 'ahmad', 'guru123');
  await page.goto(BASE + '/hasil.php');
  const adaTabel = await page.$('table.tbl');
  benar('halaman Hasil & Analisis menampilkan tabel', !!adaTabel);
  await page.goto(BASE + '/ujian.php');
  benar('halaman Ujian & Soal terbuka untuk guru', (await page.content()).includes('Daftar Ujian'));

  benar('tanpa galat konsol', galatKonsol.length === 0);
  if (galatKonsol.length) { console.log('     ' + galatKonsol.slice(0, 3).join(' | ')); }

  await browser.close();
  console.log(`\n  ${lulus} lulus, ${gagal} gagal`);
  process.exit(gagal === 0 ? 0 : 1);
})();
