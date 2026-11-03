/* =====================================================================================
   Uji TAMPILAN tombol HAPUS pada kolom Aksi daftar sekolah — Playwright.

   Permintaan pemilik: *"Tambahkan fitur hapus pada yang ditandai warna merah"* (kolom AKSI pada
   tabel "Sekolah/Madrasah Aktif", halaman Kelola Sekolah).

   Yang diukur (bukan sekadar mencari teks):
   - setiap baris punya tombol "Hapus" yang berada di kolom AKSI (paling kanan) satu baris dengan
     tombol Masuk & Ubah, berwarna merah, dan ukurannya memadai untuk diketuk;
   - klik tombol → kartu konfirmasi terbuka (di halaman yang sama) berisi nama sekolah + dampak,
     dan SEKOLAH BELUM terhapus;
   - tombol "Batal" kembali ke daftar tanpa mengubah apa pun;
   - isian konfirmasi benar-benar FOKUS otomatis; setelah mengetik HAPUS lalu menekan
     "Hapus Permanen" → sekolah hilang dari daftar (alur nyata, bukan hanya HTML);
   - tombol Hapus ada juga pada tabel Sekolah Nonaktif;
   - tanpa luapan mendatar 1440 & 390; kartu konfirmasi rapi di HP; tanpa galat konsol.

   Jalankan lewat:  bash check_cbt_hapus_sekolah_ui.sh
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
const luapan = (page) => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 950 } });
  const page = await ctx.newPage();
  const galatKonsol = [];
  page.on('console', (m) => { if (m.type() === 'error') galatKonsol.push(m.text()); });
  page.on('dialog', (d) => d.accept());   // konfirmasi bawaan peramban (kalau muncul) diterima

  await masuk(page, 'admin', 'admin123');
  await page.goto(BASE + '/sekolah.php');
  await page.waitForTimeout(300);

  console.log('== 1. Tombol Hapus di kolom Aksi (paling kanan) ==');
  const tabel = page.locator('.card', { hasText: 'Sekolah/Madrasah Aktif' }).last();
  const barisPertama = tabel.locator('tbody tr').first();
  const hapus = barisPertama.locator('a', { hasText: 'Hapus' });
  cek('baris pertama punya tombol Hapus', await hapus.count(), 1);
  benar('tombol Hapus terlihat', await hapus.isVisible());
  const kolomAksi = barisPertama.locator('td').last();
  benar('tombol Hapus berada di sel AKSI (kolom terakhir)',
    (await kolomAksi.locator('a', { hasText: 'Hapus' }).count()) === 1);
  benar('tombol Masuk & Ubah juga ada pada sel yang sama',
    (await kolomAksi.locator('a', { hasText: 'Masuk' }).count()) === 1
    && (await kolomAksi.locator('a', { hasText: 'Ubah' }).count()) === 1);

  // Seluruh baris tabel punya tombol Hapus (bukan hanya baris pertama)
  const jumlahBaris = await tabel.locator('tbody tr').count();
  const jumlahHapus = await tabel.locator('a', { hasText: 'Hapus' }).count();
  cek('setiap baris punya tombol Hapus', jumlahHapus, jumlahBaris);

  // Warna merah (gaya bahaya) & ukuran yang nyaman diketuk
  const gaya = await hapus.evaluate((el) => {
    const s = getComputedStyle(el);
    return { warna: s.color, kelas: el.className, tinggi: el.getBoundingClientRect().height };
  });
  benar('tombol memakai kelas btn-bahaya', gaya.kelas.includes('btn-bahaya'));
  const n = (gaya.warna.match(/\d+/g) || []).map(Number);
  benar('teks tombol berwarna merah (' + gaya.warna + ')', n.length >= 3 && n[0] > n[1] && n[0] > n[2]);
  benar('tinggi tombol memadai (≥ 24px)', gaya.tinggi >= 24);

  // Tombol Hapus sebaris dengan Masuk/Ubah (kolom Aksi tidak turun ke baris baru)
  const kotakMasuk = await kolomAksi.locator('a', { hasText: 'Masuk' }).boundingBox();
  const kotakHapus = await hapus.boundingBox();
  benar('tombol Hapus sebaris dengan tombol Masuk',
    !!kotakMasuk && !!kotakHapus && Math.abs(kotakMasuk.y - kotakHapus.y) <= 6);
  benar('tombol Hapus di sebelah KANAN tombol Ubah',
    !!kotakHapus && (await kolomAksi.locator('a', { hasText: 'Ubah' }).boundingBox()).x < kotakHapus.x);

  console.log('== 2. Klik tombol membuka kartu konfirmasi (bukan langsung menghapus) ==');
  const namaSekolah = (await barisPertama.locator('td').nth(1).locator('b').first().innerText()).trim();
  const jumlahSebelum = await page.locator('.card', { hasText: 'Sekolah/Madrasah Aktif' }).last().locator('tbody tr').count();
  await Promise.all([page.waitForNavigation(), hapus.click()]);
  await page.waitForTimeout(300);
  benar('alamat memuat ?hapus= (kartu konfirmasi)', /sekolah\.php\?hapus=\d+/.test(page.url()));
  const kartu = page.locator('#hapusSekolah');
  cek('kartu konfirmasi tampil tepat satu', await kartu.count(), 1);
  benar('kartu konfirmasi terlihat', await kartu.isVisible());
  const isiKartu = (await kartu.innerText()).replace(/\s+/g, ' ');
  benar('kartu menyebut nama sekolah yang akan dihapus', isiKartu.includes(namaSekolah));
  benar('kartu memperingatkan PERMANEN', /PERMANEN/.test(isiKartu));
  benar('kartu menyebut dampak guru & siswa', /guru/.test(isiKartu) && /siswa/.test(isiKartu));
  benar('kartu menyarankan Nonaktifkan Sekolah sebagai alternatif', isiKartu.includes('Nonaktifkan Sekolah'));
  benar('sekolah BELUM terhapus (masih ada di daftar)',
    (await page.locator('.card', { hasText: 'Sekolah/Madrasah Aktif' }).last().locator('tbody tr').count()) === jumlahSebelum);

  console.log('== 3. Isian konfirmasi fokus otomatis & tombol Batal ==');
  const isian = page.locator('#konfirmasiHapus');
  benar('isian konfirmasi ada', (await isian.count()) === 1);
  benar('isian konfirmasi FOKUS otomatis tanpa diklik',
    await isian.evaluate((el) => document.activeElement === el));
  const batal = kartu.locator('a', { hasText: 'Batal' });
  benar('tombol Batal tersedia', (await batal.count()) === 1);
  await Promise.all([page.waitForNavigation(), batal.click()]);
  await page.waitForTimeout(200);
  benar('Batal kembali ke daftar tanpa ?hapus', !page.url().includes('hapus='));
  benar('jumlah sekolah tidak berubah setelah Batal',
    (await page.locator('.card', { hasText: 'Sekolah/Madrasah Aktif' }).last().locator('tbody tr').count()) === jumlahSebelum);

  console.log('== 4. ALUR NYATA: ketik HAPUS lalu hapus ==');
  // Pakai baris TERAKHIR supaya sekolah utama fixture tetap ada untuk halaman lain.
  const barisTerakhir = page.locator('.card', { hasText: 'Sekolah/Madrasah Aktif' }).last().locator('tbody tr').last();
  const namaHapus = (await barisTerakhir.locator('td').nth(1).locator('b').first().innerText()).trim();
  await Promise.all([page.waitForNavigation(), barisTerakhir.locator('a', { hasText: 'Hapus' }).click()]);
  await page.waitForTimeout(200);
  await page.fill('#konfirmasiHapus', 'HAPUS');
  await Promise.all([
    page.waitForNavigation({ timeout: 20000 }),
    page.click('button:has-text("Hapus Permanen")'),
  ]);
  await page.waitForTimeout(300);
  const halaman = await page.content();
  benar('pesan "dihapus permanen" tampil', halaman.includes('dihapus permanen'));
  benar('nama sekolah yang dihapus disebut pada pesan', halaman.includes(namaHapus));
  // Nama sekolah masih muncul pada PESAN flash; yang diperiksa: barisnya hilang dari TABEL.
  const daftarTabel = await page.locator('.card', { hasText: 'Sekolah/Madrasah Aktif' }).last().innerText();
  benar('baris sekolah hilang dari tabel daftar', !daftarTabel.includes(namaHapus));
  benar('kartu konfirmasi tidak lagi tampil', (await page.locator('#hapusSekolah').count()) === 0);

  console.log('== 5. Tombol Hapus juga ada pada tabel Sekolah Nonaktif ==');
  const nonaktif = page.locator('.card', { hasText: 'Sekolah Nonaktif' });
  if (await nonaktif.count()) {
    const sel = nonaktif.locator('tbody tr td').last();
    benar('tabel nonaktif punya tombol Hapus di kolom Aksi',
      (await sel.locator('a', { hasText: 'Hapus' }).count()) === 1);
    benar('tabel nonaktif juga masih punya tombol Aktifkan',
      (await sel.locator('button', { hasText: 'Aktifkan' }).count()) === 1);
  } else {
    console.log('     (tidak ada sekolah nonaktif pada data uji — bagian ini dilewati)');
  }

  console.log('== 6. Kerapian 1440 & HP 390 ==');
  await page.setViewportSize({ width: 1440, height: 950 });
  await page.goto(BASE + '/sekolah.php');
  await page.waitForTimeout(300);
  const ov1 = await luapan(page);
  benar('tanpa luapan mendatar di 1440 (' + ov1 + 'px)', ov1 <= 1);
  const kartuAksi = await page.locator('.card', { hasText: 'Sekolah/Madrasah Aktif' }).last().locator('tbody tr td').last().boundingBox();
  benar('sel Aksi tidak terpotong di 1440', !!kartuAksi && kartuAksi.width > 60);

  // Kartu konfirmasi pada HP
  const idPertama = (await page.locator('.card', { hasText: 'Sekolah/Madrasah Aktif' }).last()
    .locator('tbody tr').first().locator('a', { hasText: 'Hapus' }).getAttribute('href')).match(/\d+/)[0];
  await page.setViewportSize({ width: 390, height: 850 });
  await page.goto(BASE + '/sekolah.php?hapus=' + idPertama);
  await page.waitForTimeout(400);
  const ov2 = await luapan(page);
  benar('tanpa luapan mendatar di HP 390 (' + ov2 + 'px)', ov2 <= 1);
  const kartuHP = await page.locator('#hapusSekolah').boundingBox();
  const indukHP = await page.locator('#hapusSekolah').evaluateHandle((el) => el.parentElement);
  const kotakInduk = await indukHP.asElement().boundingBox();
  benar('kartu konfirmasi tidak melewati lebar halaman di HP',
    !!kartuHP && !!kotakInduk && kartuHP.x + kartuHP.width <= kotakInduk.x + kotakInduk.width + 1);
  benar('isian konfirmasi terlihat di HP', await page.locator('#konfirmasiHapus').isVisible());
  benar('tombol Hapus Permanen terlihat di HP', await page.locator('button:has-text("Hapus Permanen")').isVisible());

  benar('tanpa galat konsol', galatKonsol.length === 0);
  if (galatKonsol.length) { console.log('     ' + galatKonsol.slice(0, 3).join(' | ')); }

  await browser.close();
  console.log(`\n  ${lulus} lulus, ${gagal} gagal`);
  process.exit(gagal === 0 ? 0 : 1);
})();
