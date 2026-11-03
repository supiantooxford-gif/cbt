/* =====================================================================================
   Uji TAMPILAN impor soal dari berkas WORD LAMA (.doc) — Playwright.

   Permintaan pemilik: *"Pastikan aplikasi bisa mengimpor dokumen word tipe .doc — saat ini
   aplikasi hanya bisa import tipe .docx"*.

   Yang diukur (alur NYATA di peramban, bukan hanya membaca HTML):
   - isian berkas menerima `.doc` (atribut accept + label + keterangan);
   - memilih berkas **.doc** lewat isian berkas lalu menekan "Impor dari Word" → 6 soal masuk
     dan langsung tampil pada daftar soal ujian (jenis-jenisnya terbaca);
   - berkas **.doc bertemplate TABEL** juga masuk (2 soal);
   - berkas .doc yang BUKAN dokumen Word ditolak dengan pesan di halaman (tidak menambah soal);
   - tanpa luapan mendatar 1440 & 390 dan tanpa galat konsol.

   Jalankan lewat:  bash check_cbt_impor_doc_ui.sh
   ===================================================================================== */
const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8951';
const DOC = process.env.DOC_ISI || '';
const DOC_TABEL = process.env.DOC_TABEL || '';
const DOC_RUSAK = process.env.DOC_RUSAK || '';
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

/** Buka halaman impor untuk ujian pertama milik guru. */
async function bukaImpor(page) {
  await page.goto(BASE + '/ujian.php');
  await Promise.all([page.waitForNavigation(), page.locator('a', { hasText: 'Kelola' }).first().click()]);
  const tautan = page.locator('a', { hasText: 'Impor dari Template' }).first();
  await Promise.all([page.waitForNavigation(), tautan.click()]);
  await page.waitForTimeout(250);
}

/** Unggah satu berkas Word lalu tunggu halaman hasil. */
async function unggah(page, berkas) {
  await page.setInputFiles('#berkasDocx', berkas);
  const tombol = page.locator('form:has(#berkasDocx) button[type=submit]').first();
  await Promise.all([page.waitForNavigation(), tombol.click()]);
  await page.waitForTimeout(250);
}

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 950 } });
  const page = await ctx.newPage();
  const galatKonsol = [];
  page.on('console', (m) => { if (m.type() === 'error') galatKonsol.push(m.text()); });

  await masuk(page, 'ahmad', 'guru123');
  await bukaImpor(page);

  console.log('== 1. Isian berkas menerima .doc ==');
  const isian = page.locator('#berkasDocx');
  benar('isian berkas terlihat', await isian.isVisible());
  cek('atribut accept', await isian.getAttribute('accept'), '.docx,.doc');
  const teksHalaman = await page.textContent('body');
  benar('label menyebut ".docx atau .doc"', teksHalaman.includes('Berkas Word (.docx atau .doc)'));
  benar('keterangan menyebut Word 97-2003', teksHalaman.includes('Word 97-2003'));
  benar('langkah pengisian menyebut kedua format dapat diunggah',
    teksHalaman.replace(/\s+/g, ' ').includes('keduanya dapat diunggah'));

  console.log('== 2. Unggah berkas .doc (template teks) ==');
  // Jumlah soal SEBELUM impor (fixture sudah menyiapkan 6 soal) untuk membandingkan hasil.
  const idUjian = (page.url().match(/ujian=(\d+)/) || [])[1];
  benar('id ujian terbaca dari halaman impor', !!idUjian);
  const halamanUjian = (id) => BASE + '/ujian_edit.php?id=' + id;
  await page.goto(halamanUjian(idUjian));
  await page.waitForTimeout(200);
  const soalAwal = await page.locator('.soal-jenis').count();
  benar('daftar soal awal terukur (' + soalAwal + ' soal)', soalAwal >= 1);
  await bukaImpor(page);

  await unggah(page, DOC);
  const hasil1 = await page.textContent('body');
  benar('pesan sukses impor tampil', hasil1.includes('berhasil diimpor'));
  benar('tidak ada pesan galat', (await page.locator('.alert-err').count()) === 0);

  // Kembali ke halaman ujian: soal harus sudah tampil di daftar soal.
  await page.goto(halamanUjian(idUjian));
  await page.waitForTimeout(250);
  const isiUjian = await page.textContent('body');
  benar('halaman ujian memuat soal hasil impor .doc',
    isiUjian.includes('Manakah dari pernyataan'));
  const jenisTampil = ['Pilihan Ganda', 'Pilihan Ganda Kompleks', 'Benar / Salah', 'Menjodohkan',
    'Uraian / Essai'];
  for (const j of jenisTampil) {
    benar('jenis soal tampil: ' + j, isiUjian.includes(j));
  }
  const jumlahKartu = await page.locator('.soal-jenis').count();
  cek('daftar soal bertambah tepat 6', jumlahKartu - soalAwal, 6);
  cek('tidak ada soal lain yang hilang', jumlahKartu, soalAwal + 6);

  console.log('== 3. Unggah .doc bertemplate TABEL ==');
  await bukaImpor(page);
  await unggah(page, DOC_TABEL);
  const hasil2 = await page.textContent('body');
  benar('impor tabel .doc dilaporkan', hasil2.includes('berhasil diimpor'));
  benar('dilaporkan berasal dari tabel Word', hasil2.includes('tabel Word'));

  console.log('== 4. Berkas .doc yang bukan dokumen Word ==');
  await bukaImpor(page);
  await unggah(page, DOC_RUSAK);
  benar('pesan galat tampil', (await page.locator('.alert-err').count()) >= 1);
  benar('pesan menyebut berkas bukan dokumen Word',
    (await page.locator('.alert-err').first().innerText()).includes('tidak dikenali sebagai dokumen Word'));

  console.log('== 5. Kerapian tampilan ==');
  await bukaImpor(page);
  cek('tanpa luapan mendatar 1440', await luapan(page), 0);
  await page.setViewportSize({ width: 390, height: 844 });
  await page.waitForTimeout(250);
  cek('tanpa luapan mendatar 390 (HP)', await luapan(page), 0);
  benar('isian berkas tetap terlihat di HP', await page.locator('#berkasDocx').isVisible());
  cek('tanpa galat konsol', galatKonsol.length, 0);
  if (galatKonsol.length) console.log('    ' + galatKonsol.join('\n    '));

  await browser.close();
  console.log('\n  LULUS: ' + lulus + '   GAGAL: ' + gagal);
  process.exit(gagal === 0 ? 0 : 1);
})().catch((e) => { console.error('GALAT UJI:', e.message); process.exit(1); });
