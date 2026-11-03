/* =====================================================================================
   Uji TOMBOL "KUMPULKAN JAWABAN" pada halaman pengerjaan ujian (Playwright).

   Latar belakang (bug nyata yang dilaporkan pemilik): "saat klik kumpulkan jawaban aplikasi
   tidak merespon sama sekali". Penyebabnya: pengumpulan bergantung pada window.confirm();
   peramban dapat MENOLAK/menyembunyikan dialog itu (Chrome menampilkan centang
   "jangan tampilkan lagi dialog dari halaman ini"), sehingga confirm() mengembalikan false
   secara diam-diam dan tombol tampak mati.

   Yang diuji di sini:
   1. Konfirmasi memakai layar DI DALAM halaman (bukan dialog peramban) + tombol batal bekerja;
   2. Klik "Ya, Kumpulkan Sekarang" benar-benar menuntaskan pengumpulan sampai halaman hasil;
   3. Tombol "Kumpulkan Jawaban Sekarang" (di bawah daftar soal) ikut bekerja;
   4. JALUR CADANGAN: bila SEMUA fetch gagal (jaringan putus), pengumpulan tetap tuntas lewat
      formulir biasa ke selesai.php (`lanjut=1`).

   Jalankan lewat: bash check_cbt_kumpulkan_ui.sh
   ===================================================================================== */
const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8801';
let lulus = 0, gagal = 0;
const ok = (t) => { lulus++; console.log('  ok   ' + t); };
const no = (t) => { gagal++; console.log('  GAGAL ' + t); };
const benar = (t, k) => { k ? ok(t) : no(t); };

async function masuk(p) {
  await p.goto(BASE + '/logout.php');
  await p.goto(BASE + '/login.php');
  await p.fill('#username', 'siswa1');
  await p.fill('#password', 'siswa123');
  await Promise.all([p.waitForNavigation(), p.click('#masuk button[type=submit]')]);
}

async function bukaUjian(p) {
  await p.goto(BASE + '/ujian_siswa.php');
  const link = await p.$('a.btn-utama');
  if (!link) { throw new Error('tidak ada ujian tersedia pada salinan uji'); }
  await Promise.all([p.waitForNavigation(), link.click()]);
  if (await p.isVisible('#btnMulaiUjian')) { await p.click('#btnMulaiUjian'); }
  await p.waitForTimeout(400);
}

async function tungguHasil(p, ms) {
  try {
    await p.waitForURL(/selesai\.php\?sesi=/, { timeout: ms });
    return true;
  } catch (e) {
    console.log('     (waktu tunggu habis; URL sekarang: ' + p.url().replace(BASE, '') + ')');
    return false;
  }
}

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const p = await ctx.newPage();
  const dialogs = [];
  const galat = [];
  p.on('dialog', async (d) => { dialogs.push(d.type()); await d.accept(); });
  p.on('pageerror', (e) => galat.push('PAGEERROR: ' + e.message));
  const sken = process.env.SKENARIO || 'konfirmasi';

  await masuk(p);

  if (sken === 'konfirmasi') {
    console.log('== 1. Konfirmasi memakai layar di dalam halaman ==');
    await bukaUjian(p);
    benar('layar konfirmasi ada di halaman', !!(await p.$('#layarKumpulkan')));
    benar('layar konfirmasi awalnya tertutup', !(await p.isVisible('#layarKumpulkan')));
    await p.click('#btnKumpulkan');
    await p.waitForTimeout(300);
    benar('klik tombol MEMBUKA layar konfirmasi', await p.isVisible('#layarKumpulkan'));
    benar('TIDAK ada dialog bawaan peramban (' + JSON.stringify(dialogs) + ')', dialogs.length === 0);
    benar('pesan menyebut jumlah soal yang belum dijawab',
      (await p.textContent('#pesanKumpulkan')).includes('belum dijawab'));
    benar('tombol batal tersedia', !!(await p.$('#btnBatalKumpulkan')));
    await p.click('#btnBatalKumpulkan');
    await p.waitForTimeout(200);
    benar('"Periksa Jawaban Dulu" menutup layar konfirmasi', !(await p.isVisible('#layarKumpulkan')));
    benar('belum dikumpulkan setelah batal', !p.url().includes('selesai.php'));
  }

  if (sken === 'tuntas') {
    console.log('== 2. Klik "Ya, Kumpulkan Sekarang" sampai tuntas ==');
    await bukaUjian(p);
    await (await p.$('.soal-card[data-jenis="pg"] input')).check();
    await p.waitForTimeout(900);
    await p.click('#btnKumpulkan');
    await p.waitForTimeout(250);
    await p.click('#btnYaKumpulkan');
    benar('berpindah ke halaman hasil', await tungguHasil(p, 20000));
    benar('halaman hasil ditampilkan', (await p.content()).includes('Hasil:'));
    benar('tanpa dialog bawaan peramban', dialogs.length === 0);
  }

  if (sken === 'tombol2') {
    console.log('== 3. Tombol "Kumpulkan Jawaban Sekarang" di bawah soal ==');
    await bukaUjian(p);
    benar('tombol kedua ada', !!(await p.$('#btnKumpulkan2')));
    await p.click('#btnKumpulkan2');
    await p.waitForTimeout(300);
    benar('tombol kedua membuka layar konfirmasi yang sama', await p.isVisible('#layarKumpulkan'));
    await p.click('#btnYaKumpulkan');
    benar('tombol kedua menuntaskan pengumpulan', await tungguHasil(p, 20000));
  }

  if (sken === 'darurat') {
    console.log('== 4. JALUR CADANGAN: semua fetch gagal (jaringan putus) ==');
    await bukaUjian(p);
    benar('formulir cadangan tersedia di halaman', !!(await p.$('#formKumpulDarurat')));
    benar('formulir cadangan memuat csrf, sesi, dan lanjut=1',
      await p.$eval('#formKumpulDarurat', (f) =>
        !!f.querySelector('input[name=csrf]') && !!f.querySelector('input[name=sesi]')
        && !!f.querySelector('input[name=lanjut][value="1"]')));
    await p.evaluate(() => { window.fetch = function () { return Promise.reject(new Error('offline')); }; });
    await p.click('#btnKumpulkan');
    await p.waitForTimeout(250);
    await p.click('#btnYaKumpulkan');
    benar('pengumpulan tetap tuntas lewat formulir cadangan', await tungguHasil(p, 25000));
    benar('halaman hasil ditampilkan (sesi benar-benar ditutup)', (await p.content()).includes('Hasil:'));
  }

  benar('tanpa galat JS halaman', galat.length === 0);
  if (galat.length) { console.log('     ' + galat.slice(0, 3).join(' | ')); }

  await browser.close();
  console.log(`  ${lulus} lulus, ${gagal} gagal`);
  process.exit(gagal === 0 ? 0 : 1);
})();
