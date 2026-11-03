/* =====================================================================================
   Uji tampilan halaman IMPOR SOAL (template teks sederhana) — Playwright.

   Yang diukur:
   - halaman menampilkan 3 cara impor: Word (.docx), tempel teks, dan Excel/CSV (buka-tutup);
   - contoh template bisa dibuka & berisi judul bagian yang benar;
   - ALUR NYATA: tempel teks → tombol "Impor dari Teks" → 2 soal masuk dan tampil di daftar soal;
   - unggah berkas .docx lewat kolom berkas → soal masuk;
   - tanpa luapan mendatar pada 1440 & 390, kotak teks tidak melewati kartunya;
   - tanpa galat konsol.

   Jalankan lewat:  bash check_cbt_impor_soal_ui.sh
   ===================================================================================== */
const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8901';
const DOCX = process.env.DOCX_ISI || '';
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

const luapan = (page) => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await ctx.newPage();
  const galatKonsol = [];
  page.on('console', (m) => { if (m.type() === 'error') galatKonsol.push(m.text()); });

  await masuk(page, 'ahmad', 'guru123');

  // Ujian pertama milik guru ini (fixture menyiapkan satu ujian aktif).
  await page.goto(BASE + '/ujian.php');
  const kelola = page.locator('a', { hasText: 'Kelola' }).first();
  benar('daftar ujian guru memuat tombol Kelola', (await kelola.count()) === 1);
  await Promise.all([page.waitForNavigation(), kelola.click()]);
  await page.waitForTimeout(200);
  const tautan = page.locator('a', { hasText: 'Impor dari Template' }).first();
  benar('halaman ujian memuat tautan "Impor dari Template"', (await tautan.count()) === 1);
  await Promise.all([page.waitForNavigation(), tautan.click()]);
  await page.waitForTimeout(300);
  benar('halaman Impor Soal terbuka', (await page.content()).includes('Unggah Berkas Soal'));

  console.log('== 1. Tiga cara impor ==');
  benar('Cara 1 = tulis di template Word lalu unggah',
    (await page.locator('h3', { hasText: 'Cara 1' }).count()) >= 1);
  benar('Cara 2 = tempel teks tersedia',
    (await page.locator('h3', { hasText: 'Cara 2' }).count()) >= 1);
  benar('kotak teks soal (#teksSoal) ada', await page.locator('#teksSoal').isVisible());
  benar('kolom berkas Word (#berkasDocx) ada', (await page.locator('#berkasDocx').count()) === 1);
  const detailLama = page.locator('details.impor-lama');
  benar('Excel/CSV tetap tersedia (buka-tutup)', (await detailLama.count()) === 1);
  benar('isi Excel/CSV belum tampil sebelum dibuka', !(await page.locator('#fileImpor').isVisible()));
  await detailLama.locator('summary').click();
  await page.waitForTimeout(200);
  benar('setelah dibuka, kolom berkas Excel tampil', await page.locator('#fileImpor').isVisible());

  console.log('== 2. Pratinjau contoh template ==');
  const detailContoh = page.locator('details', { hasText: 'Lihat contoh template lengkap' }).first();
  benar('pratinjau contoh tersedia', (await detailContoh.count()) === 1);
  benar('contoh tersembunyi sebelum dibuka', !(await page.locator('.contoh-soal').isVisible()));
  await detailContoh.locator('summary').click();
  await page.waitForTimeout(200);
  benar('setelah dibuka, contoh template terlihat', await page.locator('.contoh-soal').isVisible());
  const isiContoh = await page.locator('.contoh-soal').innerText();
  benar('contoh memuat judul "Template Soal Pilihan Ganda"', isiContoh.includes('Template Soal Pilihan Ganda'));
  benar('contoh memuat "Kunci: A, B, E"', isiContoh.includes('Kunci: A, B, E'));
  benar('contoh memuat bagian menjodohkan dengan tanda |', /Salam Pembuka \| Halo Sahabatku/.test(isiContoh));

  console.log('== 3. ALUR: tempel teks lalu impor ==');
  // Ambil id ujian dari alamat halaman impor, lalu hitung soal yang sudah ada.
  const idUjian = (page.url().match(/ujian=(\d+)/) || [])[1];
  benar('alamat halaman impor membawa id ujian', !!idUjian);
  await page.goto(BASE + '/ujian_edit.php?id=' + idUjian);
  await page.waitForTimeout(200);
  const sebelum = await page.locator('.soal-kartu').count();
  await page.goto(BASE + '/ujian_import.php?ujian=' + idUjian);
  await page.waitForTimeout(200);

  const teks = [
    'Template Soal Pilihan Ganda',
    '1. TIGA TAMBAH EMPAT ADALAH ...',
    'A. 6',
    'B. 7',
    'C. 8',
    'Kunci: B',
    'Template Soal Benar Salah',
    '1. PERNYATAAN DARI TEMPEL: air membeku pada 0 derajat.',
    'Kunci: Benar',
  ].join('\n');
  await page.fill('#teksSoal', teks);
  await Promise.all([
    page.waitForNavigation({ timeout: 20000 }),
    page.click('button:has-text("Impor dari Teks")'),
  ]);
  await page.waitForTimeout(300);
  const halaman = await page.content();
  benar('pesan hasil impor menampilkan "2 soal"', /2 soal<\/b> berhasil diimpor/.test(halaman));
  benar('pesan menyebut sumber template teks', halaman.includes('template teks'));
  await Promise.all([page.waitForNavigation(),
    page.locator('a', { hasText: 'Kembali' }).first().click()]);
  await page.waitForTimeout(300);
  const daftar = await page.content();
  benar('soal hasil tempelan tampil di daftar soal', daftar.includes('TIGA TAMBAH EMPAT ADALAH'));
  benar('pernyataan benar/salah ikut tersimpan', daftar.includes('PERNYATAAN DARI TEMPEL'));
  const sesudah = await page.locator('.soal-kartu').count();
  cek('jumlah kartu soal bertambah dua', sesudah, sebelum + 2);

  console.log('== 4. ALUR: unggah berkas .docx ==');
  if (DOCX) {
    await page.goto(BASE + '/ujian_import.php?ujian=' + idUjian);
    await page.waitForTimeout(200);
    await page.setInputFiles('#berkasDocx', DOCX);
    await Promise.all([
      page.waitForNavigation({ timeout: 20000 }),
      page.click('button:has-text("Impor dari Word")'),
    ]);
    await page.waitForTimeout(300);
    const h = await page.content();
    benar('berkas Word diunggah & soal masuk', /1 soal<\/b> berhasil diimpor/.test(h));
    benar('pesan menyebut sumber template teks (Word)', h.includes('template teks'));
  } else {
    no('berkas .docx uji tidak tersedia (DOCX_ISI kosong)');
  }

  console.log('== 5. Kerapian ==');
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(BASE + '/ujian_import.php?ujian=' + idUjian);
  await page.waitForTimeout(300);
  const ov1 = await luapan(page);
  benar('tanpa luapan mendatar di 1440 (' + ov1 + 'px)', ov1 <= 1);
  const kartuTeks = await page.locator('#teksSoal').boundingBox();
  const kartuInduk = await page.locator('#teksSoal').evaluateHandle((el) => el.closest('.card'));
  const kotakKartu = await kartuInduk.asElement().boundingBox();
  benar('kotak teks tidak melewati kartunya',
    !!kartuTeks && !!kotakKartu && kartuTeks.x + kartuTeks.width <= kotakKartu.x + kotakKartu.width + 1);
  await page.setViewportSize({ width: 390, height: 820 });
  await page.waitForTimeout(400);
  const ov2 = await luapan(page);
  benar('tanpa luapan mendatar di HP 390 (' + ov2 + 'px)', ov2 <= 1);
  benar('kotak teks tetap terlihat di HP', await page.locator('#teksSoal').isVisible());
  await page.setViewportSize({ width: 1440, height: 900 });

  benar('tanpa galat konsol', galatKonsol.length === 0);
  if (galatKonsol.length) { console.log('     ' + galatKonsol.slice(0, 3).join(' | ')); }

  await browser.close();
  console.log(`\n  ${lulus} lulus, ${gagal} gagal`);
  process.exit(gagal === 0 ? 0 : 1);
})();
