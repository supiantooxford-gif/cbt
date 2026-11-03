/* =====================================================================================
   Uji TAMPILAN HALAMAN MASUK (login.php) — Playwright.

   Permintaan pemilik (v1.15.0): *"Warna aplikasi bagian depan buat seperti contoh termasuk posisi
   logo dan tulisan. Tapi untuk inputan login jangan dirubah."*

   Yang diukur:
   - halaman masuk TIDAK lagi berupa landing page (tidak ada navigasi bagian, beranda, slider,
     fitur, keunggulan, testimoni, kontak, formulir pesan) dan TIDAK ada tautan pendaftaran;
   - formulir masuk lengkap (#username, #password, tombol Masuk) — ISIAN LOGIN TIDAK DIUBAH;
   - PITA BIRU di atas dengan LOGO di kiri tulisan dan SELURUH kelompok di TENGAH halaman;
   - KARTU PUTIH di tengah yang MENUMPANG tepi bawah pita biru, berjudul "Selamat Datang";
   - warna pita biru (terukur), bukan hijau/emas lagi;
   - RESPONSIF: satu kolom pada semua lebar, tanpa luapan mendatar 1440/1280/1024/900/768/390/360;
   - masuk dengan sandi salah menampilkan pesan galat di halaman;
   - masuk dengan akun benar membuka dashboard;
   - tanpa galat konsol & tanpa permintaan gagal.

   Jalankan lewat:  bash check_cbt_login_ui.sh
   ===================================================================================== */
const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8860';
let lulus = 0, gagal = 0;
const ok = (t) => { lulus++; console.log('  ok   ' + t); };
const no = (t) => { gagal++; console.log('  GAGAL ' + t); };
const cek = (t, a, b) => { String(a) === String(b) ? ok(t) : no(`${t} (dapat: ${a}, harap: ${b})`); };
const benar = (t, k) => { k ? ok(t) : no(t); };
const luapan = (page) => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await ctx.newPage();
  const galatKonsol = [];
  const permintaanGagal = [];
  page.on('console', (m) => { if (m.type() === 'error') galatKonsol.push(m.text()); });
  page.on('response', (r) => { if (r.status() >= 400) permintaanGagal.push(r.status() + ' ' + r.url()); });

  await page.goto(BASE + '/login.php');
  await page.waitForTimeout(300);
  const isi = await page.content();

  console.log('== 1. BUKAN landing page lagi ==');
  for (const [nama, pola] of [
    ['bilah navigasi landing', 'lp-nav'],
    ['bagian beranda', 'id="beranda"'],
    ['bagian fitur aplikasi', 'id="fitur"'],
    ['bagian keunggulan', 'id="keunggulan"'],
    ['bagian testimoni', 'id="testimoni"'],
    ['bagian kontak', 'id="kontak"'],
    ['slider beranda', 'lpSlider'],
    ['formulir pesan kontak', 'name="pesan"'],
  ]) {
    benar('tidak ada ' + nama, !isi.includes(pola));
  }
  benar('tidak ada tautan pendaftaran', !isi.includes('daftar.php'));
  benar('tidak ada tulisan "Daftarkan Sekolah"', !/Daftarkan Sekolah/i.test(isi));
  benar('tidak ada tulisan "Pendaftaran"', !/Pendaftaran/.test(isi));

  console.log('== 2. Formulir masuk lengkap ==');
  benar('kolom nama pengguna ada', (await page.locator('#username').count()) === 1);
  benar('kolom kata sandi ada', (await page.locator('#password').count()) === 1);
  benar('tombol Masuk ada', (await page.locator('button[type=submit]', { hasText: 'Masuk' }).count()) === 1);
  benar('token CSRF ada', (await page.locator('input[name=csrf]').count()) === 1);
  benar('kolom nama pengguna FOKUS otomatis', await page.locator('#username').evaluate((el) => document.activeElement === el));
  benar('judul jendela "Masuk - …"', (await page.title()).startsWith('Masuk - '));
  benar('panel identitas aplikasi tampil', await page.locator('.masuk-info h1').isVisible());
  // Teks ini DIMINTA pemilik (v1.15.1) — jangan dikembalikan ke versi "guru & siswa".
  const ketAkun = (await page.locator('.masuk-ket').innerText()).replace(/\s+/g, ' ').trim();
  benar('catatan akun: guru memakai akun e-GURU',
    ketAkun.includes('Akun guru memakai nama pengguna dan kata sandi dari aplikasi e-GURU.'));
  benar('catatan akun: akun siswa diberikan admin madrasah',
    ketAkun.includes('Sedangkan akun siswa akan diberikan oleh admin madrasah.'));
  benar('catatan akun TIDAK lagi berbunyi "Akun guru & siswa memakai … e-GURU"',
    !/Akun guru\s*&\s*siswa/i.test(ketAkun));
  benar('logo aplikasi tampil', (await page.locator('.masuk-logo').count()) === 1);

  console.log('== 3. Warna & posisi logo/tulisan seperti contoh ==');
  const pita = page.locator('.masuk-info');
  const kartu = page.locator('.masuk-kartu');
  benar('pita biru (header) tampil', await pita.isVisible());
  const warnaPita = await pita.evaluate((el) => {
    const m = getComputedStyle(el).backgroundImage.match(/rgba?\([^)]+\)/g) || [];
    return { img: getComputedStyle(el).backgroundImage.slice(0, 60), rgb: m };
  });
  benar('pita memakai gradasi biru (' + warnaPita.img + '…)', /gradient/.test(warnaPita.img));
  const biruDominan = (w) => {
    const n = (w.match(/\d+/g) || []).map(Number);
    return n.length >= 3 && n[2] > n[0] && n[2] >= 100;
  };
  benar('setiap warna gradasi pita biru dominan', warnaPita.rgb.length >= 2 && warnaPita.rgb.every(biruDominan));
  benar('gelombang putih di tepi bawah pita ada', (await page.locator('.masuk-gelombang').count()) === 1);
  benar('logo tampil di dalam pita biru', await page.locator('.masuk-info .masuk-logo').isVisible());
  benar('nama aplikasi tampil di pita biru', await page.locator('.masuk-info h1').isVisible());
  benar('subjudul aplikasi tampil', await page.locator('.masuk-sub').isVisible());
  benar('judul "Selamat Datang" ada di kartu',
    (await page.locator('.masuk-selamat').innerText()).trim() === 'Selamat Datang');
  benar('baris bantuan "Lupa kata sandi" ada di kartu',
    /Lupa kata sandi/i.test(await page.locator('.masuk-lupa').innerText()));
  benar('kaki halaman (versi) berada DI DALAM kartu',
    (await page.locator('.masuk-kartu .masuk-kaki').count()) === 1);

  console.log('== 4. Posisi terukur: pita di atas, kartu menumpang & terpusat ==');
  const ukur = async (lebar, tinggi) => {
    await page.setViewportSize({ width: lebar, height: tinggi });
    await page.waitForTimeout(280);
    const ov = await luapan(page);
    const k = await kartu.boundingBox();
    const p = await pita.boundingBox();
    const brand = await page.locator('.masuk-brand').boundingBox();
    const logo = await page.locator('.masuk-logo').boundingBox();
    const h1 = await page.locator('.masuk-info h1').boundingBox();
    const isi = await page.locator('.masuk-kartu').evaluate((el) => getComputedStyle(el).backgroundColor);
    return { ov, kartu: k, pita: p, brand, logo, h1, isi, lebar };
  };

  const d = await ukur(1440, 900);
  benar('1440: tanpa luapan mendatar (' + d.ov + 'px)', d.ov <= 1);
  benar('1440: pita biru di PALING ATAS (y≈0)', !!d.pita && d.pita.y <= 1);
  benar('1440: pita biru selebar layar', !!d.pita && d.pita.width >= 1440 - 1);
  benar('1440: kartu MENUMPANG tepi bawah pita',
    !!d.kartu && !!d.pita && d.kartu.y < d.pita.y + d.pita.height && d.kartu.y > d.pita.y);
  benar('1440: kartu berwarna PUTIH', d.isi === 'rgb(255, 255, 255)');
  benar('1440: kartu terpusat mendatar (selisih tepi ≤ 2px)',
    !!d.kartu && Math.abs(d.kartu.x - (1440 - (d.kartu.x + d.kartu.width))) <= 2);
  benar('1440: lebar kartu wajar (≤ 460px)', !!d.kartu && d.kartu.width <= 460);
  benar('1440: LOGO berada di KIRI nama aplikasi',
    !!d.logo && !!d.h1 && d.logo.x + d.logo.width <= d.h1.x + 2);
  benar('1440: kelompok logo+tulisan berada di TENGAH halaman',
    !!d.brand && Math.abs((d.brand.x + d.brand.width / 2) - 720) <= 3);

  for (const [lebar, tinggi] of [[1280, 860], [1024, 800], [900, 780], [768, 900], [390, 850], [360, 780]]) {
    const u = await ukur(lebar, tinggi);
    benar(lebar + ': tanpa luapan mendatar (' + u.ov + 'px)', u.ov <= 1);
    benar(lebar + ': kartu tetap terpusat', !!u.kartu && Math.abs(u.kartu.x - (lebar - (u.kartu.x + u.kartu.width))) <= 2);
    benar(lebar + ': kartu tidak melewati lebar layar', !!u.kartu && u.kartu.width <= lebar - 20);
    benar(lebar + ': formulir masuk terlihat', await page.locator('#username').isVisible());
  }

  console.log('== 5. Masuk: sandi salah & sandi benar ==');
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(BASE + '/login.php');
  await page.fill('#username', 'admin');
  await page.fill('#password', 'salah-sekali');
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
  await page.waitForTimeout(200);
  benar('sandi salah: pesan galat tampil', await page.locator('#galatMasuk').isVisible());
  benar('sandi salah: tetap di halaman masuk', page.url().includes('login.php'));
  benar('sandi salah: nama pengguna dikembalikan', (await page.inputValue('#username')) === 'admin');

  await page.fill('#username', 'admin');
  await page.fill('#password', 'admin123');
  await Promise.all([page.waitForNavigation({ timeout: 20000 }), page.click('button[type=submit]')]);
  await page.waitForTimeout(300);
  benar('sandi benar: masuk ke dashboard', !page.url().includes('login.php'));
  benar('sandi benar: halaman dashboard terbuka', (await page.content()).includes('Ringkasan Lintas Sekolah'));

  // Sudah masuk → membuka login.php dialihkan ke dashboard (tidak ada halaman ganda).
  await page.goto(BASE + '/login.php');
  await page.waitForTimeout(300);
  benar('sudah masuk: login.php dialihkan ke dashboard', !page.url().includes('login.php'));

  const gagalTakTerduga = permintaanGagal.filter((u) => !/favicon/.test(u));
  benar('tanpa permintaan gagal', gagalTakTerduga.length === 0);
  if (gagalTakTerduga.length) { console.log('     ' + gagalTakTerduga.slice(0, 3).join(' | ')); }
  benar('tanpa galat konsol', galatKonsol.length === 0);
  if (galatKonsol.length) { console.log('     ' + galatKonsol.slice(0, 3).join(' | ')); }

  await browser.close();
  console.log(`\n  ${lulus} lulus, ${gagal} gagal`);
  process.exit(gagal === 0 ? 0 : 1);
})();
