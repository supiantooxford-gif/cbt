/* =====================================================================================
   Uji TAMPILAN fitur "LINK PADA FITUR BUAT SOAL OTOMATIS" — Playwright.

   Permintaan pemilik: *"Pada halaman Super Admin tambahkan fitur untuk memasukkan link pada fitur
   buat soal otomatis"*.

   Yang diukur (bukan sekadar mencari teks, dan memakai ALUR NYATA di peramban):
   - menu "Soal Otomatis" ada di sidebar Super Admin & membuka halamannya;
   - isian tautan terisi tautan bawaan, pratinjau tombol memakai tautan itu;
   - isian SALAH (bukan URL) ditolak dengan pesan di halaman & nilai tidak berubah;
   - menyimpan tautan baru lewat formulir → pesan sukses, penanda "sudah disesuaikan",
     tombol "Kembalikan Bawaan" muncul, pratinjau memakai tautan baru;
   - pada instance peramban TERPISAH, halaman Tambah Soal guru memakai tautan baru itu
     (href tombol) dan tombol tetap berkedip;
   - "Kembalikan Bawaan" (dialog konfirmasi diterima) mengembalikan tautan bawaan di halaman guru;
   - tanpa luapan mendatar 1440 & 390 dan tanpa galat konsol.

   Jalankan lewat:  bash check_cbt_soal_otomatis_link_ui.sh
   ===================================================================================== */
const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8951';
const BAWAAN = 'https://gemini.google.com/share/9b5d2dc2944b';
const BARU = 'https://gemini.google.com/share/tautan-uji-ui-777';

let lulus = 0, gagal = 0;
const ok = (t) => { lulus++; console.log('  ok   ' + t); };
const no = (t) => { gagal++; console.log('  GAGAL ' + t); };
const cek = (t, a, b) => { String(a) === String(b) ? ok(t) : no(`${t} (dapat: ${a}, harap: ${b})`); };
const benar = (t, k) => { k ? ok(t) : no(t); };
const luapan = (page) => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);

async function masuk(page, user, sandi) {
  await page.goto(BASE + '/logout.php');
  await page.goto(BASE + '/login.php');
  await page.fill('#username', user);
  await page.fill('#password', sandi);
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
}

(async () => {
  // ---------------------------------------------------------------- Super Admin
  const b1 = await chromium.launch();
  const p1 = await (await b1.newContext({ viewport: { width: 1440, height: 950 } })).newPage();
  const galat1 = [];
  p1.on('console', (m) => { if (m.type() === 'error') galat1.push(m.text()); });
  p1.on('dialog', (d) => d.accept());   // dialog konfirmasi "Kembalikan Bawaan"

  await masuk(p1, 'admin', 'admin123');

  console.log('== 1. Menu & halaman Super Admin ==');
  const menu = p1.locator('.nav a.nav-item', { hasText: 'Soal Otomatis' });
  cek('menu "Soal Otomatis" ada di sidebar', await menu.count(), 1);
  benar('menu terlihat', await menu.isVisible());
  await Promise.all([p1.waitForNavigation(), menu.click()]);
  benar('menu membuka halaman soal_otomatis.php', p1.url().includes('/soal_otomatis.php'));

  console.log('== 2. Isian & pratinjau memakai tautan bawaan ==');
  const isian = p1.locator('#soal_otomatis_url');
  benar('isian tautan terlihat', await isian.isVisible());
  cek('isian terisi tautan bawaan', await isian.inputValue(), BAWAAN);
  const pratinjau = p1.locator('#btnSoalOtomatisContoh');
  cek('pratinjau tombol memakai tautan bawaan', await pratinjau.getAttribute('href'), BAWAAN);
  cek('pratinjau membuka tab baru', await pratinjau.getAttribute('target'), '_blank');
  benar('penanda "masih bawaan" tampil',
    (await p1.locator('.pil', { hasText: 'masih bawaan' }).count()) === 1);
  benar('belum ada tombol Kembalikan Bawaan',
    (await p1.locator('button', { hasText: 'Kembalikan Bawaan' }).count()) === 0);
  cek('tautan yang dipakai tertulis pada tabel', (await p1.locator('#tautanSoalOtomatis').innerText()).trim(), BAWAAN);
  // Pratinjau berada di BADAN kartu: pastikan tetap tersusun kolom (tombol di atas keterangan)
  // dan tidak melewati tepi kanan kartu.
  const kotakTombol = await pratinjau.boundingBox();
  const kotakKet = await p1.locator('#pratinjauSoalOtomatis .soal-otomatis-ket').boundingBox();
  const kotakKartu = await p1.locator('#pratinjauSoalOtomatis').boundingBox();
  benar('keterangan password berada DI BAWAH tombol pratinjau',
    !!kotakTombol && !!kotakKet && kotakKet.y >= kotakTombol.y + kotakTombol.height - 1);
  benar('pratinjau tidak melewati tepi kanan isi kartu',
    !!kotakKartu && !!kotakKet && kotakKet.x + kotakKet.width <= kotakKartu.x + kotakKartu.width + 1);
  benar('tombol pratinjau cukup besar untuk klik (≥ 28px tinggi)',
    !!kotakTombol && kotakTombol.height >= 28);

  console.log('== 3. Isian salah ditolak ==');
  // (a) Peramban sendiri menolak: isian bertipe url + required.
  await isian.fill('bukan url');
  await p1.click('#formSoalOtomatis button[type=submit]');
  await p1.waitForTimeout(600);
  benar('peramban menahan isian yang bukan URL (tetap di halaman)', p1.url().includes('/soal_otomatis.php'));
  benar('isian ditandai tidak sah oleh peramban',
    (await p1.locator('#soal_otomatis_url').evaluate((el) => el.checkValidity())) === false);
  // (b) Server juga menolak (mis. klien mengirim langsung tanpa pemeriksaan peramban).
  await p1.locator('#soal_otomatis_url').evaluate((el) => { el.type = 'text'; });
  await isian.fill('bukan url');
  await Promise.all([p1.waitForNavigation(), p1.click('#formSoalOtomatis button[type=submit]')]);
  benar('tetap di halaman soal_otomatis.php', p1.url().includes('/soal_otomatis.php'));
  const alert = p1.locator('.alert-err');
  benar('pesan galat tampil', (await alert.count()) >= 1);
  benar('pesan menyebut tautan belum benar',
    (await alert.first().innerText()).includes('Tautan belum benar'));
  cek('isian dikembalikan apa adanya', await p1.locator('#soal_otomatis_url').inputValue(), 'bukan url');
  cek('pratinjau TIDAK berubah', await p1.locator('#btnSoalOtomatisContoh').getAttribute('href'), BAWAAN);

  console.log('== 4. Simpan tautan baru lewat formulir ==');
  await p1.fill('#soal_otomatis_url', BARU);
  await Promise.all([p1.waitForNavigation(), p1.click('#formSoalOtomatis button[type=submit]')]);
  benar('pesan sukses tampil',
    (await p1.textContent('body')).includes('disimpan'));
  benar('penanda "sudah disesuaikan" tampil',
    (await p1.locator('.pil', { hasText: 'sudah disesuaikan' }).count()) === 1);
  benar('tombol Kembalikan Bawaan muncul',
    (await p1.locator('button', { hasText: 'Kembalikan Bawaan' }).count()) === 1);
  cek('isian memuat tautan baru', await p1.locator('#soal_otomatis_url').inputValue(), BARU);
  cek('pratinjau memakai tautan baru', await p1.locator('#btnSoalOtomatisContoh').getAttribute('href'), BARU);
  cek('tabel "tautan yang sedang dipakai" ikut berubah',
    (await p1.locator('#tautanSoalOtomatis').innerText()).trim(), BARU);

  // ---------------------------------------------------------------- Guru (instance terpisah)
  const b2 = await chromium.launch();
  const p2 = await (await b2.newContext({ viewport: { width: 1440, height: 950 } })).newPage();
  const galat2 = [];
  p2.on('console', (m) => { if (m.type() === 'error') galat2.push(m.text()); });
  await masuk(p2, 'ahmad', 'guru123');
  await p2.goto(BASE + '/ujian.php');
  await Promise.all([p2.waitForNavigation(), p2.locator('a', { hasText: 'Kelola' }).first().click()]);
  const idUjian = (p2.url().match(/id=(\d+)/) || [])[1];
  benar('id ujian terbaca dari halaman ujian guru', !!idUjian);
  await p2.goto(BASE + '/soal_edit.php?ujian=' + idUjian);
  await p2.waitForTimeout(250);

  console.log('== 5. Halaman Tambah Soal guru memakai tautan baru ==');
  const tombol = p2.locator('#btnSoalOtomatis');
  cek('href tombol = tautan dari halaman Super Admin', await tombol.getAttribute('href'), BARU);
  cek('target tombol', await tombol.getAttribute('target'), '_blank');
  cek('rel tombol', await tombol.getAttribute('rel'), 'noopener noreferrer');
  benar('tombol tetap terlihat', await tombol.isVisible());
  const animasi = await tombol.evaluate((el) => getComputedStyle(el).animationName);
  benar('tombol tetap BERKEDIP (animasi soalOtomatisKedip)', animasi === 'soalOtomatisKedip');
  benar('keterangan kata sandi tetap tampil', (await p2.locator('#sandiSoalOtomatis').innerText()).trim() === 'berkah');

  console.log('== 6. Kembalikan Bawaan dari halaman Super Admin ==');
  await Promise.all([
    p1.waitForNavigation(),
    p1.click('button:has-text("Kembalikan Bawaan")'),
  ]);
  benar('penanda kembali "masih bawaan"',
    (await p1.locator('.pil', { hasText: 'masih bawaan' }).count()) === 1);
  cek('pratinjau kembali ke tautan bawaan', await p1.locator('#btnSoalOtomatisContoh').getAttribute('href'), BAWAAN);
  await p2.reload();
  cek('halaman guru kembali memakai tautan bawaan', await p2.locator('#btnSoalOtomatis').getAttribute('href'), BAWAAN);

  console.log('== 7. Kerapian tampilan ==');
  cek('tanpa luapan mendatar 1440', await luapan(p1), 0);
  await p1.setViewportSize({ width: 390, height: 844 });
  await p1.waitForTimeout(250);
  cek('tanpa luapan mendatar 390 (HP)', await luapan(p1), 0);
  benar('isian tautan tetap terlihat di HP', await p1.locator('#soal_otomatis_url').isVisible());
  benar('pratinjau tombol tetap terlihat di HP', await p1.locator('#btnSoalOtomatisContoh').isVisible());
  cek('tanpa galat konsol (Super Admin)', galat1.length, 0);
  cek('tanpa galat konsol (Guru)', galat2.length, 0);
  if (galat1.length) console.log('    ' + galat1.join('\n    '));

  await b1.close();
  await b2.close();

  console.log('\n  LULUS: ' + lulus + '   GAGAL: ' + gagal);
  process.exit(gagal === 0 ? 0 : 1);
})().catch((e) => { console.error('GALAT UJI:', e.message); process.exit(1); });
