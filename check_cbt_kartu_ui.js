/* =====================================================================================
   Uji tampilan KARTU UJIAN (Playwright):
   - Kartu berukuran 90 x 62 mm, 2 kolom x 4 baris per lembar A4 potrait.
   - Isi kartu: Nama, Jenis Kelamin, Kelas, Akun, Password (sandi tampil).
   - Sandi benar-benar tercetak dari daftar siap-bagi (bukan tanda "-").
   - Tanpa luapan mendatar; jumlah halaman PDF = jumlah lembar sebenarnya.
   Jalankan lewat: bash check_cbt_kartu_ui.sh
   ===================================================================================== */
const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8941';
const MM = 96 / 25.4;                       // 1 mm dalam px CSS
let lulus = 0, gagal = 0;
const ok = (t) => { lulus++; console.log('  ok   ' + t); };
const no = (t) => { gagal++; console.log('  GAGAL ' + t); };
const benar = (t, k) => { k ? ok(t) : no(t); };
const dekat = (t, a, b, tol = 1.5) => benar(`${t} (${a} ≈ ${b})`, Math.abs(a - b) <= tol);

async function masuk(page, user, sandi) {
  await page.goto(BASE + '/login.php');
  await page.fill('#username', user);
  await page.fill('#password', sandi);
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
}

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1000, height: 900 } });
  const page = await ctx.newPage();
  const galat = [];
  page.on('console', (m) => { if (m.type() === 'error') galat.push(m.text()); });
  // Tombol penting memakai dialog konfirmasi (data-confirm). Playwright menolak dialog
  // secara bawaan, jadi harus diterima agar pengiriman formulir benar-benar berjalan.
  const dialogTerima = [];
  page.on('dialog', (d) => { dialogTerima.push(d.message()); d.accept(); });

  console.log('== 1. Menyiapkan sandi dari antarmuka ==');
  await masuk(page, 'operator1', 'sandi12345');
  await page.goto(BASE + '/siswa.php');
  benar('kartu "Sandi Akun untuk Dibagikan" tampil', (await page.content()).includes('Sandi Akun untuk Dibagikan'));
  await page.selectOption('#mode', 'acak');
  await page.selectOption('#lingkup', 'semua');
  await page.fill('#konfirmasi', 'SETUJUI');
  await Promise.all([
    page.waitForNavigation(),
    page.click('#cardSandiSiswa form[method=post] button[type=submit]'),
  ]);
  const setelah = await page.content();
  benar('sandi berhasil ditetapkan lewat antarmuka', setelah.includes('Sandi siap dibagikan'));

  console.log('== 2. Kartu ujian ==');
  await page.goto(BASE + '/cetak_kartu_ujian.php');
  const kartu = await page.$$('.ku-kartu');
  benar('kartu terbentuk (' + kartu.length + ' kartu)', kartu.length >= 2);
  const kotak = await kartu[0].boundingBox();
  dekat('lebar kartu 90 mm', kotak.width, 90 * MM, 3);
  dekat('tinggi kartu 62 mm', kotak.height, 62 * MM, 3);

  // 2 kolom x 4 baris per lembar
  const posisi = [];
  for (const k of kartu) { posisi.push(await k.boundingBox()); }
  const kolomKiri = posisi.filter((p) => Math.abs(p.x - posisi[0].x) < 3).length;
  benar('kartu tersusun >1 kolom (kiri: ' + kolomKiri + ' baris)', kolomKiri >= 2);
  const adaKolomKanan = posisi.some((p) => p.x > posisi[0].x + 200);
  benar('ada kolom kedua di sebelah kanan', adaKolomKanan);

  const isi = await kartu[0].innerText();
  for (const label of ['Nama', 'Jenis Kelamin', 'Kelas', 'Akun', 'Password']) {
    benar('isi kartu memuat label "' + label + '"', isi.includes(label));
  }
  const sandiTampil = await page.$$eval('.ku-sandi', (n) => n.map((e) => e.textContent.trim()));
  benar('sandi benar-benar tercetak pada kartu (' + (sandiTampil[0] || 'kosong') + ')',
    sandiTampil.length > 0 && /^[A-Za-z0-9]{6,}$/.test(sandiTampil[0] || ''));
  benar('tidak ada kartu bertuliskan "belum disiapkan"',
    !(await page.content()).includes('belum disiapkan'));

  const luapan = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  benar('tanpa luapan mendatar (1000px)', luapan <= 1);

  console.log('== 3. Cetak Data Siswa: kolom Password ==');
  await page.goto(BASE + '/cetak_siswa.php');
  const judul = await page.$$eval('table.sheet-tbl thead th', (n) => n.map((e) => e.textContent.trim()));
  const iAkun = judul.indexOf('Akun');
  const iSandi = judul.indexOf('Password');
  benar('kolom Password ada (' + judul.length + ' kolom)', iSandi >= 0);
  benar('Password tepat setelah Akun', iSandi === iAkun + 1);
  const sandiSel = await page.$$eval('table.sheet-tbl tbody tr td', (n) =>
    n.map((e) => e.textContent.trim()).filter((t) => /^[A-Za-z0-9]{8}$/.test(t)));
  benar('sandi tampil pada kolom Password (' + (sandiSel[0] || 'kosong') + ')', sandiSel.length > 0);

  console.log('== 4. Jumlah halaman PDF ==');
  const tinggiLembar = 62 * MM * 4 + 3 * 4 + 2 * 8 * MM;  // 4 baris + gap + padding
  const ukuran = page.viewportSize();
  await page.pdf({ path: '/tmp/cbt_kartu_uji.pdf', preferCSSPageSize: true, printBackground: true });
  const { execSync } = require('child_process');
  let halaman = 0;
  try {
    const pdf = require('fs').readFileSync('/tmp/cbt_kartu_uji.pdf');
    const m = pdf.toString('latin1').match(/\/Type\s*\/Pages[\s\S]{0,200}?\/Count\s+(\d+)/);
    halaman = m ? parseInt(m[1], 10) : 0;
  } catch (e) { halaman = 0; }
  benar('jumlah halaman PDF terhitung (' + halaman + ')', halaman >= 1);
  benar('jumlah halaman sesuai jumlah kartu (maks 8 per lembar)',
    halaman === Math.ceil(kartu.length / 8) || halaman === 0);

  benar('dialog konfirmasi muncul saat menetapkan sandi', dialogTerima.length > 0);
  benar('tanpa galat konsol', galat.length === 0);
  if (galat.length) { console.log('     ' + galat.slice(0, 3).join(' | ')); }

  await browser.close();
  console.log(`\n  ${lulus} lulus, ${gagal} gagal`);
  process.exit(gagal === 0 ? 0 : 1);
})();
