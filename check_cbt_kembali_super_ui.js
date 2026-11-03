/* =====================================================================================
   Uji tampilan fitur "KEMBALI KE HALAMAN UTAMA SUPER ADMIN" — Playwright.

   Yang diukur (bukan sekadar mencari teks):
   - Tombol pada BILAH ATAS berada di sisi KANAN (sebelah kiri info pengguna) dan benar-benar
     terlihat saat Super Admin berada dalam konteks sekolah;
   - Banner konteks tampil di atas isi halaman, tombolnya berada di sisi kanan banner;
   - Menekan tombol (bilah atas MAUPUN banner) benar-benar membuka halaman utama Super Admin;
   - Tanpa konteks sekolah tombol/banner tidak tampil;
   - Peran lain (operator) tidak melihat tombol itu;
   - Tanpa luapan mendatar pada 1440 dan 390; label panjang disembunyikan di HP (hanya ikon);
   - Tanpa galat konsol.

   Jalankan lewat:  bash check_cbt_kembali_super_ui.sh
   ===================================================================================== */
const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8901';
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

  console.log('== 1. Super Admin tanpa konteks: tombol tidak perlu ada ==');
  await masuk(page, 'admin', 'admin123');
  await page.waitForTimeout(300);
  benar('halaman utama Super Admin terbuka (Ringkasan Lintas Sekolah)',
    (await page.content()).includes('Ringkasan Lintas Sekolah'));
  cek('tombol kembali tidak tampil di halaman utama', await page.locator('.btn-kembali-super').count(), 0);
  cek('banner konteks tidak tampil di halaman utama', await page.locator('.alert-konteks').count(), 0);

  console.log('== 2. Super Admin masuk konteks sekolah ==');
  // Masuk konteks lewat tombol "Masuk" pada tabel sekolah di halaman utama (jalur pengguna).
  const masukPertama = page.locator('table.tbl a.btn', { hasText: 'Masuk' }).first();
  benar('tombol "Masuk" sekolah tersedia pada halaman utama', (await masukPertama.count()) === 1);
  await Promise.all([page.waitForNavigation(), masukPertama.click()]);
  await page.waitForTimeout(300);
  benar('dashboard sekolah (dalam konteks) terbuka', page.url().includes('index.php'));

  const btnTop = page.locator('.topbar .btn-kembali-top');
  cek('tombol kembali pada bilah atas tampil tepat satu', await btnTop.count(), 1);
  benar('tombol kembali pada bilah atas benar-benar terlihat', await btnTop.isVisible());
  cek('label bilah atas = "Kembali ke Super Admin"',
    (await btnTop.innerText()).trim(), 'Kembali ke Super Admin');

  // Tombol berada di sisi KANAN: titik tengahnya di separuh kanan layar, dan letaknya di dalam bilah atas.
  const kotakTombol = await btnTop.boundingBox();
  const kotakTopbar = await page.locator('.topbar').boundingBox();
  benar('tombol berada di separuh KANAN bilah atas',
    !!kotakTombol && kotakTombol.x > kotakTopbar.x + kotakTopbar.width / 2);
  benar('tombol berada DI DALAM bilah atas',
    !!kotakTombol && kotakTombol.y >= kotakTopbar.y && kotakTombol.y + kotakTombol.height <= kotakTopbar.y + kotakTopbar.height + 1);
  benar('tinggi tombol memadai untuk diketuk (≥ 28px)', !!kotakTombol && kotakTombol.height >= 28);

  const banner = page.locator('.alert-konteks');
  cek('banner konteks tampil tepat satu', await banner.count(), 1);
  benar('banner konteks terlihat', await banner.isVisible());
  benar('banner memuat nama sekolah yang dikelola',
    /Anda sedang\s+mengelola/.test((await banner.innerText()).replace(/\s+/g, ' ')));
  const btnBanner = banner.locator('.btn-kembali-super');
  benar('banner punya tombol kembali', (await btnBanner.count()) === 1);
  const kotakBanner = await banner.boundingBox();
  const kotakBtnBanner = await btnBanner.boundingBox();
  benar('tombol banner berada di sisi KANAN banner',
    !!kotakBanner && !!kotakBtnBanner && kotakBtnBanner.x > kotakBanner.x + kotakBanner.width / 2);
  benar('banner berada di ATAS kartu statistik (di atas isi halaman)',
    !!kotakBanner && kotakBanner.y < (await page.locator('.grid .card').first().boundingBox()).y);

  console.log('== 3. Tombol bekerja: bilah atas membuka halaman utama Super Admin ==');
  await Promise.all([page.waitForNavigation(), btnTop.click()]);
  await page.waitForTimeout(300);
  benar('setelah menekan tombol: halaman utama Super Admin terbuka',
    (await page.content()).includes('Ringkasan Lintas Sekolah'));
  benar('pesan konfirmasi kembali tampil', (await page.content()).includes('Kembali ke halaman utama Super Admin'));
  cek('tombol kembali hilang setelah keluar konteks', await page.locator('.btn-kembali-super').count(), 0);
  cek('banner konteks hilang setelah keluar konteks', await page.locator('.alert-konteks').count(), 0);

  console.log('== 4. Tombol banner juga bekerja ==');
  await Promise.all([page.waitForNavigation(), page.locator('table.tbl a.btn', { hasText: 'Masuk' }).first().click()]);
  await page.waitForTimeout(250);
  cek('kembali masuk konteks: tombol tampil lagi', await page.locator('.btn-kembali-super').count(), 2);
  await Promise.all([page.waitForNavigation(), page.locator('.alert-konteks .btn-kembali-super').click()]);
  await page.waitForTimeout(300);
  benar('tombol pada banner membuka halaman utama Super Admin',
    (await page.content()).includes('Ringkasan Lintas Sekolah'));

  console.log('== 5. Tombol tampil juga di halaman lain selama dalam konteks ==');
  await Promise.all([page.waitForNavigation(), page.locator('table.tbl a.btn', { hasText: 'Masuk' }).first().click()]);
  await page.goto(BASE + '/guru.php');
  await page.waitForTimeout(250);
  benar('halaman Data Guru menampilkan tombol kembali', await page.locator('.btn-kembali-top').isVisible());
  await page.goto(BASE + '/pengaturan.php');
  await page.waitForTimeout(250);
  benar('halaman Identitas & Pengaturan menampilkan tombol kembali', await page.locator('.alert-konteks .btn-kembali-super').isVisible());

  console.log('== 6. Kerapian pada HP 390 (label panjang → ikon saja) ==');
  await page.setViewportSize({ width: 390, height: 820 });
  await page.goto(BASE + '/index.php');
  await page.waitForTimeout(400);
  const luapanHP = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  benar('tanpa luapan mendatar pada HP (' + luapanHP + 'px)', luapanHP <= 1);
  benar('label bilah atas disembunyikan di HP (hanya ikon)', !(await page.locator('.topbar .teks-kembali').isVisible()));
  const btnTopHP = await page.locator('.topbar .btn-kembali-top').boundingBox();
  benar('tombol ikon tetap terlihat & dapat diketuk di HP', !!btnTopHP && btnTopHP.width >= 24 && btnTopHP.height >= 24);
  const btnBannerHP = await page.locator('.alert-konteks .btn-kembali-super').boundingBox();
  const bannerHP = await page.locator('.alert-konteks').boundingBox();
  benar('tombol banner tidak melewati lebar banner di HP',
    !!btnBannerHP && !!bannerHP && btnBannerHP.x + btnBannerHP.width <= bannerHP.x + bannerHP.width + 1);

  console.log('== 7. Kerapian pada desktop 1440 ==');
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(BASE + '/index.php');
  await page.waitForTimeout(400);
  const luapanDesktop = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  benar('tanpa luapan mendatar pada desktop (' + luapanDesktop + 'px)', luapanDesktop <= 1);

  console.log('== 8. Peran lain tidak melihat tombol ==');
  await masuk(page, 'operator1', 'sandi12345');
  await page.goto(BASE + '/index.php');
  await page.waitForTimeout(300);
  cek('operator: tombol kembali Super Admin tidak tampil', await page.locator('.btn-kembali-super').count(), 0);
  cek('operator: banner konteks tidak tampil', await page.locator('.alert-konteks').count(), 0);
  benar('operator tetap melihat dashboard sekolahnya', (await page.content()).includes('Ujian Terbaru'));

  benar('tanpa galat konsol', galatKonsol.length === 0);
  if (galatKonsol.length) { console.log('     ' + galatKonsol.slice(0, 3).join(' | ')); }

  await browser.close();
  console.log(`\n  ${lulus} lulus, ${gagal} gagal`);
  process.exit(gagal === 0 ? 0 : 1);
})();
