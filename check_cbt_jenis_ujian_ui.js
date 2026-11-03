/* =====================================================================================
   Uji TAMPILAN fitur "Jenis Penilaian (Jenis Ulangan)" — Playwright.

   Permintaan pemilik: *"Pada akun Super Admin tambahkan fitur untuk menghapus atau menambahkan
   jenis ulangan yang tampil pada halaman buat ujian guru"*.

   Yang diukur (bukan sekadar mencari teks):
   - menu "Jenis Penilaian" benar-benar ada di sidebar Super Admin & membuka halamannya;
   - daftar 6 jenis bawaan tampil pada tabel, tiap baris punya tombol Hapus, kolom Aksi rapi;
   - menambah lewat formulir → pesan sukses + baris baru muncul + pilihan pada halaman
     BUAT UJIAN guru (instance peramban terpisah) langsung memuat jenis itu;
   - menghapus lewat tombol Hapus (dialog konfirmasi diterima) → baris hilang dari daftar
     Super Admin dan pilihan pada halaman guru;
   - "Kembalikan Bawaan" mengembalikan 6 jenis;
   - tanpa luapan mendatar 1440 & 390 dan tanpa galat konsol.

   Jalankan lewat:  bash check_cbt_jenis_ujian_ui.sh
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
const opsiJenis = (page) => page.$$eval('#jenis_ujian option', (o) => o.map((x) => x.textContent.trim()));

(async () => {
  // ------------------------------------------------------------------ Super Admin
  const b1 = await chromium.launch();
  const p1 = await (await b1.newContext({ viewport: { width: 1440, height: 950 } })).newPage();
  const galat1 = [];
  p1.on('console', (m) => { if (m.type() === 'error') galat1.push(m.text()); });
  p1.on('dialog', (d) => d.accept());   // dialog konfirmasi hapus

  await masuk(p1, 'admin', 'admin123');

  console.log('== 1. Menu sidebar Super Admin ==');
  const menu = p1.locator('.nav a.nav-item', { hasText: 'Jenis Penilaian' });
  cek('menu "Jenis Penilaian" ada di sidebar', await menu.count(), 1);
  benar('menu terlihat', await menu.isVisible());
  await Promise.all([p1.waitForNavigation(), menu.click()]);
  benar('menu membuka halaman jenis_ujian.php', p1.url().includes('/jenis_ujian.php'));

  console.log('== 2. Daftar jenis penilaian bawaan ==');
  const tabel = p1.locator('#tblJenisUjian');
  benar('tabel daftar tampil', await tabel.isVisible());
  cek('6 jenis bawaan terdaftar', await tabel.locator('tbody tr').count(), 6);
  const namaBawaan = await tabel.locator('tbody tr td:nth-child(2)').allTextContents();
  benar('jenis "Ulangan Harian" ada pada daftar', namaBawaan.some((t) => t.includes('Ulangan Harian')));
  cek('setiap baris punya tombol Hapus', await tabel.locator('tbody tr button:has-text("Hapus")').count(), 6);
  const tombolPertama = tabel.locator('tbody tr').first().locator('button:has-text("Hapus")');
  benar('tombol Hapus terlihat', await tombolPertama.isVisible());
  const ukuran = await tombolPertama.boundingBox();
  benar('tombol Hapus cukup besar untuk diketuk (≥ 26px tinggi)', ukuran && ukuran.height >= 26);
  benar('halaman menampilkan tombol "Tambah Jenis Penilaian"',
    (await p1.locator('#formTambahJenis button[type=submit]').count()) === 1);
  benar('penanda "masih bawaan" tampil', (await p1.locator('.pil', { hasText: 'masih bawaan' }).count()) === 1);
  benar('belum ada tombol Kembalikan Bawaan', (await p1.locator('button', { hasText: 'Kembalikan Bawaan' }).count()) === 0);

  console.log('== 3. Tambah jenis penilaian lewat formulir ==');
  await p1.fill('#nama', 'Asesmen Sumatif Kelas Akhir');
  await Promise.all([p1.waitForNavigation(), p1.click('#formTambahJenis button[type=submit]')]);
  const pesanSukses = await p1.textContent('body');
  benar('pesan sukses penambahan tampil', pesanSukses.includes('ditambahkan'));
  cek('daftar menjadi 7 jenis', await p1.locator('#tblJenisUjian tbody tr').count(), 7);
  benar('jenis baru tampil pada tabel',
    (await p1.locator('#tblJenisUjian tbody tr', { hasText: 'Asesmen Sumatif Kelas Akhir' }).count()) === 1);
  benar('penanda berubah "sudah disesuaikan"',
    (await p1.locator('.pil', { hasText: 'sudah disesuaikan' }).count()) === 1);
  cek('tombol Kembalikan Bawaan muncul', await p1.locator('button', { hasText: 'Kembalikan Bawaan' }).count(), 1);

  // ------------------------------------------------------------------ Guru (instance terpisah)
  const b2 = await chromium.launch();
  const p2 = await (await b2.newContext({ viewport: { width: 1440, height: 950 } })).newPage();
  const galat2 = [];
  p2.on('console', (m) => { if (m.type() === 'error') galat2.push(m.text()); });
  await masuk(p2, 'ahmad', 'guru123');
  await p2.goto(BASE + '/ujian_edit.php');
  console.log('== 4. Pilihan pada halaman BUAT UJIAN guru ==');
  const opsi1 = await opsiJenis(p2);
  benar('jenis baru langsung tampil pada pilihan guru', opsi1.includes('Asesmen Sumatif Kelas Akhir'));
  benar('jenis bawaan masih ada', opsi1.includes('Ulangan Harian') && opsi1.includes('Latihan'));
  benar('keterangan "diatur Super Admin" tampil pada halaman guru',
    (await p2.textContent('body')).includes('diatur Super Admin pada menu'));

  console.log('== 5. Hapus jenis penilaian lewat tombol Hapus ==');
  const baris = p1.locator('#tblJenisUjian tbody tr', { hasText: 'Asesmen Sumatif Kelas Akhir' });
  await Promise.all([p1.waitForNavigation(), baris.locator('button:has-text("Hapus")').click()]);
  benar('pesan penghapusan tampil', (await p1.textContent('body')).includes('dihapus dari daftar pilihan'));
  cek('daftar kembali 6 jenis', await p1.locator('#tblJenisUjian tbody tr').count(), 6);
  benar('jenis yang dihapus tidak ada lagi pada tabel',
    (await p1.locator('#tblJenisUjian tbody tr', { hasText: 'Asesmen Sumatif Kelas Akhir' }).count()) === 0);
  await p2.reload();
  const opsi2 = await opsiJenis(p2);
  benar('jenis yang dihapus hilang dari pilihan halaman guru', !opsi2.includes('Asesmen Sumatif Kelas Akhir'));
  cek('jumlah pilihan kembali 6', opsi2.length, 6);

  console.log('== 6. Kembalikan Bawaan ==');
  await p1.fill('#nama', 'Jenis Sementara');
  await Promise.all([p1.waitForNavigation(), p1.click('#formTambahJenis button[type=submit]')]);
  cek('jenis sementara masuk (7)', await p1.locator('#tblJenisUjian tbody tr').count(), 7);
  await Promise.all([p1.waitForNavigation(), p1.click('button:has-text("Kembalikan Bawaan")')]);
  cek('setelah Kembalikan Bawaan: 6 jenis', await p1.locator('#tblJenisUjian tbody tr').count(), 6);
  benar('penanda kembali "masih bawaan"', (await p1.locator('.pil', { hasText: 'masih bawaan' }).count()) === 1);
  await p2.reload();
  benar('pilihan guru kembali memuat jenis bawaan', (await opsiJenis(p2)).includes('Ulangan Harian'));

  console.log('== 7. Kerapian tampilan ==');
  cek('tanpa luapan mendatar 1440', await luapan(p1), 0);
  await p1.setViewportSize({ width: 390, height: 844 });
  await p1.waitForTimeout(200);
  cek('tanpa luapan mendatar 390 (HP)', await luapan(p1), 0);
  const tombolHp = p1.locator('#tblJenisUjian tbody tr').first().locator('button:has-text("Hapus")');
  benar('tombol Hapus tetap terlihat di HP', await tombolHp.isVisible());
  const bungkus = await p1.$eval('#tblJenisUjian', (t) => {
    const w = t.closest('.table-wrap');
    if (!w) return 'tanpa-pembungkus';
    const s = getComputedStyle(w);
    return s.overflowX;
  });
  benar('tabel dibungkus wadah bergulir mendatar di HP (overflow-x auto/scroll)',
    bungkus === 'auto' || bungkus === 'scroll');
  benar('formulir tambah tetap terlihat di HP', await p1.locator('#nama').isVisible());
  cek('tanpa galat konsol (Super Admin)', galat1.length, 0);
  cek('tanpa galat konsol (Guru)', galat2.length, 0);
  if (galat1.length) console.log('    ' + galat1.join('\n    '));

  await b1.close();
  await b2.close();

  console.log('\n  LULUS: ' + lulus + '   GAGAL: ' + gagal);
  process.exit(gagal === 0 ? 0 : 1);
})().catch((e) => { console.error('GALAT UJI:', e.message); process.exit(1); });
