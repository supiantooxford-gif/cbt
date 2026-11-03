/* =====================================================================================
   Uji TAMPILAN menu "Pengaturan" (sub menu) pada sidebar Super Admin CBT — Playwright.

   Permintaan pemilik: "Pada halaman super admin tambahkan menu Pengaturan yang didalamnya berisi
   menu biaya & aktifasi, menu jenis penilaian, menu soal otomatis, menu logo aplikasi dan menu
   akun super admin."

   Yang diperiksa:
     1. Sub menu tampil & TERBUKA di sidebar Super Admin (1440): kepala "Pengaturan",
        kelima tautan terlihat & urutannya benar.
     2. Geometri: seluruh tautan sub menu berada DI DALAM sidebar (tidak melewati tepi
        kanan/kiri) dan tingginya nyaman disentuh.
     3. Buka-tutup benar-benar bekerja: ditutup → tautan tersembunyi, dibuka → tampil lagi,
        caret berputar.
     4. Kelima menu membuka halaman yang benar & menu aktifnya menyala di dalam sub menu.
     5. Bekerja TANPA JavaScript (peramban tanpa skrip): sub menu tetap terbuka & tautan bisa dibuka.
     6. HP 390px: sidebar bisa dibuka, tautan sub menu terlihat di dalam layar, tanpa luapan.
     7. Tanpa galat konsol & tanpa permintaan gagal.
   ===================================================================================== */
const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8899';
let LULUS = 0, GAGAL = 0;
const benar = (label, ok) => { if (ok) { LULUS++; console.log('  ok   ' + label); } else { GAGAL++; console.log('  GAGAL ' + label); } };
const cek = (label, dapat, harap) => benar(label + ' (dapat: ' + JSON.stringify(dapat) + ')',
  JSON.stringify(dapat) === JSON.stringify(harap));

const masuk = async (page, user, sandi) => {
  await page.goto(BASE + '/login.php');
  await page.fill('#username', user);
  await page.fill('#password', sandi);
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
};
const luapan = (page) => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);

(async () => {
  /* ---------------------------------------------------------------- 1–4. desktop */
  {
    const b = await chromium.launch();
    const ctx = await b.newContext({ viewport: { width: 1440, height: 950 } });
    const page = await ctx.newPage();
    const galat = [], gagalMuat = [];
    page.on('console', (m) => { if (m.type() === 'error') galat.push(m.text()); });
    page.on('requestfailed', (r) => gagalMuat.push(r.url()));

    await masuk(page, 'admin', 'admin123');

    console.log('== 1. Sub menu tampil & terbuka (1440) ==');
    const lipat = page.locator('details.nav-lipat');
    cek('sub menu Pengaturan tepat satu', await lipat.count(), 1);
    benar('sub menu terbuka secara bawaan', await lipat.first().evaluate((d) => d.open));
    const kepala = (await page.locator('.nav-lipat > summary').innerText()).trim().toLowerCase();
    benar('kepala sub menu berbunyi "pengaturan" (dapat: "' + kepala + '")', kepala === 'pengaturan');
    benar('kepala sub menu terlihat', await page.locator('.nav-lipat > summary').isVisible());

    const tautan = page.locator('.nav-sub a.nav-item');
    cek('jumlah tautan sub menu', await tautan.count(), 5);
    const label = (await tautan.allInnerTexts()).map((t) => t.trim());
    cek('label & urutan tautan', label,
      ['Biaya & Aktivasi', 'Jenis Penilaian', 'Soal Otomatis', 'Logo Aplikasi', 'Akun Super Admin']);
    const href = await tautan.evaluateAll((a) => a.map((x) => x.getAttribute('href')));
    cek('berkas tujuan tautan', href,
      ['biaya_aktivasi.php', 'jenis_ujian.php', 'soal_otomatis.php', 'logo.php', 'pengguna.php']);
    let semuaTerlihat = true;
    for (let i = 0; i < 5; i++) { if (!(await tautan.nth(i).isVisible())) semuaTerlihat = false; }
    benar('kelima tautan terlihat tanpa perlu membuka apa pun', semuaTerlihat);

    console.log('== 2. Geometri di dalam sidebar ==');
    const sisi = await page.locator('.sidebar').boundingBox();
    benar('sidebar selebar 248px (dapat: ' + Math.round(sisi.width) + ')', Math.abs(sisi.width - 248) < 1);
    let diDalam = true, tinggiCukup = true;
    for (let i = 0; i < 5; i++) {
      const k = await tautan.nth(i).boundingBox();
      if (!k || k.x < sisi.x - 0.5 || k.x + k.width > sisi.x + sisi.width + 0.5) diDalam = false;
      if (!k || k.height < 30) tinggiCukup = false;
    }
    benar('seluruh tautan sub menu berada di dalam sidebar', diDalam);
    benar('tinggi tiap tautan ≥ 30px (nyaman disentuh)', tinggiCukup);
    const sub = await page.locator('.nav-sub').boundingBox();
    benar('wadah sub menu menjorok ke dalam (x > sidebar.x)',
      sub.x > sisi.x && sub.x + sub.width <= sisi.x + sisi.width + 0.5);
    cek('tanpa luapan mendatar 1440', await luapan(page), 0);

    console.log('== 3. Buka-tutup sub menu ==');
    const putarBuka = await page.locator('.nav-caret').evaluate((n) => getComputedStyle(n).transform);
    benar('caret berputar saat terbuka (transform: ' + putarBuka + ')', putarBuka !== 'none');
    await page.click('.nav-lipat > summary');
    benar('setelah kepala diklik, sub menu TERTUTUP', !(await lipat.first().evaluate((d) => d.open)));
    benar('tautan sub menu tersembunyi saat tertutup', !(await tautan.first().isVisible()));
    await page.waitForTimeout(320);   // tunggu transisi caret (0,18 s) selesai
    const putarTutup = await page.locator('.nav-caret').evaluate((n) => getComputedStyle(n).transform);
    cek('caret kembali diam saat tertutup', putarTutup, 'none');
    benar('halaman tidak berpindah saat kepala sub menu diklik', page.url().includes('/index.php'));
    await page.click('.nav-lipat > summary');
    benar('diklik lagi, sub menu TERBUKA kembali', await lipat.first().evaluate((d) => d.open));
    benar('tautan tampil lagi', await tautan.first().isVisible());

    console.log('== 4. Kelima menu membuka halamannya & menyala ==');
    for (const tujuan of ['biaya_aktivasi.php', 'jenis_ujian.php', 'soal_otomatis.php', 'logo.php', 'pengguna.php']) {
      await Promise.all([page.waitForNavigation(), page.click('.nav-sub a[href="' + tujuan + '"]')]);
      benar('klik sub menu membuka ' + tujuan, page.url().endsWith('/' + tujuan));
      const aktif = page.locator('.nav-sub a.nav-item.on');
      cek('menu aktif di dalam sub menu: ' + tujuan, await aktif.getAttribute('href'), tujuan);
      benar('sub menu tetap terbuka di ' + tujuan,
        await page.locator('details.nav-lipat').evaluate((d) => d.open));
      cek('tanpa luapan mendatar di ' + tujuan, await luapan(page), 0);
    }

    console.log('== 7. Konsol & permintaan ==');
    cek('tanpa galat konsol', galat, []);
    cek('tanpa permintaan gagal', gagalMuat, []);
    await b.close();
  }

  /* ---------------------------------------------------------------- 5. tanpa JavaScript */
  {
    const b = await chromium.launch();
    const ctx = await b.newContext({ viewport: { width: 1280, height: 900 }, javaScriptEnabled: false });
    const page = await ctx.newPage();
    console.log('== 5. Tanpa JavaScript ==');
    await masuk(page, 'admin', 'admin123');
    const tautan = page.locator('.nav-sub a.nav-item');
    cek('jumlah tautan sub menu tanpa JS', await tautan.count(), 5);
    benar('sub menu tetap terbuka tanpa JavaScript',
      await page.locator('details.nav-lipat').evaluate((d) => d.open));
    benar('tautan sub menu terlihat tanpa JavaScript', await tautan.first().isVisible());
    await tautan.nth(1).click();
    await page.waitForLoadState('domcontentloaded');
    benar('tautan sub menu bisa dibuka tanpa JavaScript', page.url().endsWith('/jenis_ujian.php'));
    await b.close();
  }

  /* ---------------------------------------------------------------- 6. HP 390px */
  {
    const b = await chromium.launch();
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 } });
    const page = await ctx.newPage();
    const galat = [];
    page.on('console', (m) => { if (m.type() === 'error') galat.push(m.text()); });
    console.log('== 6. Tampilan HP 390px ==');
    await masuk(page, 'admin', 'admin123');
    cek('tanpa luapan mendatar 390 (sebelum menu dibuka)', await luapan(page), 0);
    await page.click('#btnMenu');
    await page.waitForTimeout(450);
    const tautan = page.locator('.nav-sub a.nav-item');
    benar('sub menu terlihat di HP setelah menu dibuka', await tautan.first().isVisible());
    let diLayar = true, tinggiCukup = true;
    for (let i = 0; i < 5; i++) {
      const k = await tautan.nth(i).boundingBox();
      if (!k || k.x < 0 || k.x + k.width > 390.5) diLayar = false;
      if (!k || k.height < 30) tinggiCukup = false;
    }
    benar('seluruh tautan sub menu berada di dalam layar HP', diLayar);
    benar('tinggi tautan nyaman disentuh di HP', tinggiCukup);
    cek('tanpa luapan mendatar 390 (menu terbuka)', await luapan(page), 0);
    await Promise.all([page.waitForNavigation(), page.click('.nav-sub a[href="logo.php"]')]);
    benar('tautan sub menu bisa dibuka di HP', page.url().endsWith('/logo.php'));
    cek('tanpa galat konsol di HP', galat, []);
    await b.close();
  }

  console.log('\n== Ringkasan ==');
  console.log('  LULUS: ' + LULUS + '   GAGAL: ' + GAGAL);
  process.exit(GAGAL === 0 ? 0 : 1);
})().catch((e) => { console.error('GALAT UJI:', e); process.exit(1); });
