/* =====================================================================================
   Uji tampilan LOGO APLIKASI (Playwright):
   - Logo benar-benar TERMUAT di halaman masuk (naturalWidth > 0) dan tampil persegi.
   - Logo juga tampil di sidebar setelah masuk.
   - Mengunggah logo lewat antarmuka Super Admin langsung menggantinya pada halaman masuk.
   Jalankan lewat: bash check_cbt_logo_ui.sh
   ===================================================================================== */
const { chromium } = require('playwright');
const fs = require('fs');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8971';
const SANDI = process.env.CBT_SANDI_ADMIN || 'uji_logo_123';
let lulus = 0, gagal = 0;
const ok = (t) => { lulus++; console.log('  ok   ' + t); };
const no = (t) => { gagal++; console.log('  GAGAL ' + t); };
const benar = (t, k) => { k ? ok(t) : no(t); };

async function masuk(page, user, sandi) {
  await page.goto(BASE + '/login.php');
  await page.fill('#username', user);
  await page.fill('#password', sandi);
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
}

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await ctx.newPage();
  const galat = [];
  page.on('console', (m) => { if (m.type() === 'error') galat.push(m.text()); });
  page.on('dialog', (d) => d.accept());

  console.log('== 1. Logo pada halaman masuk (tamu) ==');
  await page.goto(BASE + '/login.php');
  // v1.14.0: halaman masuk bukan landing page lagi → judul diambil dari panel identitas.
  const h1 = (await page.textContent('.masuk-info h1')).trim();
  benar('judul aplikasi "Computer-Based Test (CBT)" (dapat: ' + h1 + ')',
    h1 === 'Computer-Based Test (CBT)');
  const logo = await page.$('.masuk-logo');
  benar('logo tampil sebagai gambar di halaman masuk', !!logo);
  if (logo) {
    const t = await logo.evaluate((el) => ({
      w: el.naturalWidth, h: el.naturalHeight, src: el.getAttribute('src'),
    }));
    benar('logo TERMUAT (naturalWidth > 0) — ' + t.src, t.w > 0 && t.h > 0);
    const k = await logo.boundingBox();
    benar('ukuran logo wajar (' + Math.round(k.width) + '×' + Math.round(k.height) + ' px)',
      k.width >= 48 && k.width <= 120 && Math.abs(k.width - k.height) <= 6);
    const warnaLatar = await logo.evaluate((el) => getComputedStyle(el).backgroundColor);
    benar('latar logo transparan (tidak menutupi gradien tema) — ' + warnaLatar,
      /rgba\(0, 0, 0, 0\)|transparent/.test(warnaLatar));
  }
  let luapan = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  benar('tanpa luapan mendatar di halaman masuk (1280)', luapan <= 1);

  console.log('== 2. Logo pada sidebar setelah masuk ==');
  await masuk(page, 'admin', SANDI);
  const brand = await page.$('.brand-ikon img');
  benar('logo tampil pada sidebar', !!brand);
  if (brand) {
    const t = await brand.evaluate((el) => ({ w: el.naturalWidth, h: el.naturalHeight }));
    benar('logo sidebar TERMUAT', t.w > 0 && t.h > 0);
    const k = await brand.boundingBox();
    benar('logo sidebar tidak melebihi kotaknya (' + Math.round(k.width) + 'px)', k.width <= 44);
  }

  console.log('== 3. Unggah logo baru lewat antarmuka ==');
  await page.goto(BASE + '/logo.php');
  benar('halaman Logo Aplikasi terbuka', (await page.content()).includes('Logo Aplikasi'));
  const pratinjauAwal = await page.$eval('#pratinjauLogoApp', (el) => el.getAttribute('src'));
  benar('pratinjau logo tampil (' + pratinjauAwal + ')', (pratinjauAwal || '').length > 0);
  await page.setInputFiles('#fileLogoApp', process.env.CBT_LOGO_UJI);
  await Promise.all([
    page.waitForNavigation(),
    page.click('form[action="upload_logo_app.php"] button[type=submit]'),
  ]);
  const isi = await page.content();
  benar('unggahan logo dilaporkan berhasil', isi.includes('berhasil diunggah'));
  benar('halaman menandai logo unggahan', isi.includes('logo unggahan'));
  benar('tombol Kembalikan Logo Bawaan muncul', isi.includes('Kembalikan Logo Bawaan'));

  console.log('== 4. Logo baru langsung dipakai halaman masuk ==');
  await page.goto(BASE + '/logout.php');
  await page.goto(BASE + '/login.php');
  const logoSekarang = await page.$eval('.masuk-logo', (el) => el.getAttribute('src'));
  benar('halaman masuk memakai logo unggahan (' + logoSekarang.slice(0, 60) + '…)',
    logoSekarang.startsWith('http') && !logoSekarang.includes('logo-cbt.svg'));
  const termuat = await page.$eval('.masuk-logo', (el) => el.complete && el.naturalWidth > 0);
  benar('logo unggahan benar-benar termuat di peramban', termuat);

  console.log('== 5. Kembalikan logo bawaan ==');
  await masuk(page, 'admin', SANDI);
  await page.goto(BASE + '/logo.php');
  await Promise.all([
    page.waitForNavigation(),
    page.click('form[action="upload_logo_app.php"] button[type=submit].btn-bahaya'),
  ]);
  benar('pengembalian logo bawaan dilaporkan',
    (await page.content()).includes('dikembalikan ke logo bawaan'));

  console.log('== 6. Kerapian ==');
  await page.setViewportSize({ width: 390, height: 820 });
  // halaman masuk mengalihkan pengguna yang masih masuk -> keluar lebih dahulu
  await page.goto(BASE + '/logout.php');
  await page.goto(BASE + '/login.php');
  luapan = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  benar('tanpa luapan mendatar di HP 390', luapan <= 1);
  const logoSempit = await page.$eval('.masuk-logo', (el) => {
    const r = el.getBoundingClientRect();
    return { w: r.width, h: r.height };
  });
  benar('logo tetap persegi di HP (' + Math.round(logoSempit.w) + '×' + Math.round(logoSempit.h) + ')',
    Math.abs(logoSempit.w - logoSempit.h) <= 6);

  benar('tanpa galat konsol', galat.length === 0);
  if (galat.length) { console.log('     ' + galat.slice(0, 3).join(' | ')); }

  await browser.close();
  console.log(`\n  ${lulus} lulus, ${gagal} gagal`);
  process.exit(gagal === 0 ? 0 : 1);
})();
