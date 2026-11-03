/* =====================================================================================
   Uji tampilan fitur v1.3.0 (Playwright):
   - SOAL BERGAMBAR: gambar benar-benar termuat pada layar ujian siswa.
   - BANK SOAL: halaman bank soal, tombol unduh Word & cetak, gambar pada pratinjau.
   - TOKEN: layar token siswa (soal tidak tampil sebelum token benar), tombol fokus.
   - Kerapian: tanpa luapan mendatar 1440 & 390, tanpa galat konsol.
   Jalankan lewat: bash check_cbt_gambar_ui.sh
   ===================================================================================== */
const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8951';
let lulus = 0, gagal = 0;
const ok = (t) => { lulus++; console.log('  ok   ' + t); };
const no = (t) => { gagal++; console.log('  GAGAL ' + t); };
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
  const galat = [];
  page.on('console', (m) => { if (m.type() === 'error') galat.push(m.text()); });

  console.log('== 1. Bank Soal (guru) ==');
  await masuk(page, 'ahmad', 'guru123');
  await page.goto(BASE + '/bank_soal.php');
  benar('judul Bank Soal tampil', (await page.content()).includes('Bank Soal'));
  const kartuSoal = await page.$$eval('.card-body', (n) => n.length);
  benar('kartu soal tampil (' + kartuSoal + ' blok)', kartuSoal > 3);
  benar('tombol unduh Word tampil', (await page.content()).includes('Unduh Word (.docx)'));
  benar('tombol cetak tampil', (await page.content()).includes('Cetak Rapi'));
  benar('kunci jawaban tampil pada layar', (await page.content()).includes('Kunci Jawaban:'));
  const adaGambar = await page.$('.bank-soal-gambar');
  benar('soal bergambar tampil pada bank soal', !!adaGambar);
  if (adaGambar) {
    const termuat = await adaGambar.evaluate((el) => el.complete && el.naturalWidth > 0);
    benar('gambar soal benar-benar TERMUAT (naturalWidth > 0)', termuat);
  }
  let luapan = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  benar('tanpa luapan mendatar di Bank Soal (1440)', luapan <= 1);

  await page.setViewportSize({ width: 390, height: 820 });
  await page.reload();
  luapan = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  benar('tanpa luapan mendatar di Bank Soal (HP 390)', luapan <= 1);
  await page.setViewportSize({ width: 1440, height: 900 });

  console.log('== 2. Lembar cetak Bank Soal ==');
  await page.goto(BASE + '/cetak_bank_soal.php');
  benar('lembar cetak bank soal memuat ringkasan kunci',
    (await page.content()).includes('RINGKASAN KUNCI JAWABAN'));
  benar('lembar cetak memuat gambar soal', !!(await page.$('.bs-gambar img')));

  console.log('== 3. Layar token siswa ==');
  await masuk(page, 'siswa1', 'siswa123');
  await page.goto(BASE + '/ujian_siswa.php');
  const daftar = await page.content();
  benar('daftar ujian siswa tampil', daftar.includes('Ujian yang Dapat Dikerjakan'));
  benar('penanda "perlu token" tampil', daftar.includes('perlu token'));
  // buka ujian yang memerlukan token
  const tautan = await page.$('a.btn-utama');
  benar('tombol menuju ujian tersedia', !!tautan);
  if (tautan) {
    await Promise.all([page.waitForNavigation(), tautan.click()]);
    const isi = await page.content();
    benar('layar token tampil', isi.includes('Masukkan Token Ujian'));
    benar('SOAL belum dikirim sebelum token benar', !(await page.$('.soal-card')));
    const fokus = await page.evaluate(() => document.activeElement && document.activeElement.id);
    benar('kolom token otomatis FOKUS tanpa diklik (dapat: ' + fokus + ')', fokus === 'token');
    // token salah
    await page.fill('#token', 'ZZZZZZ');
    await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    benar('token salah ditolak dengan pesan', (await page.content()).includes('Token salah'));
    // token benar diisi lewat query basis data uji
    const token = process.env.CBT_TOKEN || '';
    if (token) {
      await page.fill('#token', token.toLowerCase());
      await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
      const isiUjian = await page.content();
      benar('token benar membuka soal', isiUjian.includes('soal-card') || isiUjian.includes('soal-teks'));
      if (await page.$('.soal-gambar')) {
        const g = await page.$('.soal-gambar img');
        const termuat = g ? await g.evaluate((el) => el.complete && el.naturalWidth > 0) : false;
        benar('gambar soal termuat pada layar ujian', termuat);
      }
    } else {
      no('token uji tidak diberikan (CBT_TOKEN kosong)');
    }
  }

  await page.setViewportSize({ width: 390, height: 820 });
  await page.goto(BASE + '/bank_soal.php');
  benar('tanpa galat konsol', galat.length === 0);
  if (galat.length) { console.log('     ' + galat.slice(0, 3).join(' | ')); }

  await browser.close();
  console.log(`\n  ${lulus} lulus, ${gagal} gagal`);
  process.exit(gagal === 0 ? 0 : 1);
})();
