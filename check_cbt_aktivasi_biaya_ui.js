/* =====================================================================================
   Uji tampilan BIAYA & AKTIVASI (Playwright):
   - Halaman Super Admin "Biaya & Aktivasi": isian, pratinjau, simpan lewat antarmuka,
     Kembalikan Bawaan.
   - Halaman "Status & Aktivasi" di akun OPERATOR: formulir kode aktivasi, tombol permohonan
     WhatsApp, nominal biaya terukur, tombol bukti transfer (tautan), nomor rekening + tombol Salin
     yang benar-benar bekerja, kartu Cara Kerja Aktivasi.
   - Tanpa luapan mendatar pada 1440 & 390, tanpa galat konsol.

   Jalankan lewat: bash check_cbt_aktivasi_biaya_ui.sh
   ===================================================================================== */
const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8841';
const SANDI = process.env.CBT_SANDI_ADMIN || 'uji_ui_akt';
let lulus = 0, gagal = 0;
const ok = (t) => { lulus++; console.log('  ok   ' + t); };
const no = (t) => { gagal++; console.log('  GAGAL ' + t); };
const benar = (t, k) => { k ? ok(t) : no(t); };
const cek = (t, a, b) => { String(a) === String(b) ? ok(t) : no(`${t} (dapat: ${a}, harap: ${b})`); };

async function masuk(page, user, sandi) {
  await page.goto(BASE + '/logout.php');
  await page.goto(BASE + '/login.php');
  await page.fill('#username', user);
  await page.fill('#password', sandi);
  await Promise.all([page.waitForNavigation(), page.click('#masuk button[type=submit]')]);
}

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 950 } });
  const page = await ctx.newPage();
  const galat = [];
  page.on('console', (m) => { if (m.type() === 'error') galat.push(m.text()); });

  console.log('== 1. Halaman pengaturan Super Admin ==');
  await masuk(page, 'admin', SANDI);
  benar('menu "Biaya & Aktivasi" ada di sidebar',
    (await page.content()).includes('Biaya &amp; Aktivasi'));
  await page.click('a.nav-item[href="biaya_aktivasi.php"]');
  await page.waitForLoadState('domcontentloaded');
  benar('halaman Biaya & Aktivasi terbuka', page.url().includes('biaya_aktivasi.php'));
  for (const id of ['aktivasi_biaya', 'aktivasi_biaya_langganan', 'aktivasi_wa',
    'aktivasi_rekening', 'aktivasi_rekening_ket']) {
    benar('isian #' + id + ' tersedia', !!(await page.$('#' + id)));
  }
  benar('formulir pengaturan memuat kelima isian wajib',
    (await page.$$eval('#formBiayaAktivasi input, #formBiayaAktivasi textarea', (n) =>
      n.filter((x) => x.required || x.tagName === 'TEXTAREA').length)) >= 5);
  benar('kartu Pratinjau tampil', !!(await page.$('#pratinjauAktivasi')));
  benar('pratinjau memuat nominal besar',
    (await page.$eval('#nominalAktivasi', (el) => el.getBoundingClientRect().height)) >= 20);
  benar('pratinjau memuat tombol bukti transfer berupa TAUTAN wa.me',
    await page.$eval('#btnKirimBukti', (el) =>
      el.tagName === 'A' && /^https:\/\/wa\.me\/\d+/.test(el.getAttribute('href') || '')
      && el.getAttribute('target') === '_blank'
      && /noopener/.test(el.getAttribute('rel') || '')));

  console.log('== 2. Menyimpan pengaturan lewat antarmuka ==');
  await page.fill('#aktivasi_biaya', '1.350.000');
  await page.fill('#aktivasi_biaya_langganan', '850000');
  await page.fill('#aktivasi_wa', '0812-1111-2222');
  await page.fill('#aktivasi_rekening', 'Bank Uji 999. An. Penguji');
  await page.fill('#aktivasi_rekening_ket', 'Transfer hanya ke rekening ini.');
  await Promise.all([
    page.waitForNavigation(),
    page.click('#formBiayaAktivasi button[type=submit]'),
  ]);
  let isi = await page.content();
  benar('penyimpanan dilaporkan', isi.includes('Pengaturan aktivasi disimpan'));
  benar('chip berubah menjadi "sudah disesuaikan"', isi.includes('sudah disesuaikan'));
  benar('pesan menyebut nominal baru', isi.includes('Rp. 1.350.000'));
  benar('pratinjau memakai nominal baru', isi.includes('Rp. 1.350.000'));
  benar('pratinjau memakai nomor rekening baru', isi.includes('Bank Uji 999. An. Penguji'));
  benar('tombol Kembalikan Bawaan muncul', isi.includes('Kembalikan Bawaan'));

  console.log('== 3. Halaman Status & Aktivasi di akun OPERATOR ==');
  await masuk(page, 'operator1', 'sandi12345');
  await page.goto(BASE + '/aktivasi.php');
  isi = await page.content();
  benar('kartu Status Aktivasi Lembaga tampil', isi.includes('Status Aktivasi Lembaga'));
  benar('kartu "Aktivasi Sekolah/Madrasah" tampil', isi.includes('Aktivasi Sekolah/Madrasah'));
  benar('ajakan menghubungi HD Admin tampil', isi.includes('Hubungi HD Admin untuk melakukan aktifasi'));
  benar('kartu Cara Kerja Aktivasi tampil', isi.includes('Cara Kerja Aktivasi'));
  const waHref = await page.$eval('.btn-permohonan', (el) => el.getAttribute('href'));
  benar('tombol permohonan menuju WA 6281211112222 (dapat: ' + (waHref || '').slice(0, 46) + '…)',
    /^https:\/\/wa\.me\/6281211112222\?text=/.test(waHref || ''));
  benar('pesan WhatsApp permohonan memuat nama sekolah',
    decodeURIComponent(waHref).includes('MA Uji CBT'));
  benar('pesan WhatsApp permohonan memuat nama aplikasi',
    decodeURIComponent(waHref).includes('Computer-Based Test (CBT)'));
  benar('tombol permohonan tampil di dalam kartu Aktivasi Sekolah/Madrasah',
    await page.$eval('#permohonanAktivasi', (el) => !!el.querySelector('.btn-permohonan')));
  const nominal = await page.$eval('#nominalAktivasi', (el) => {
    const r = el.getBoundingClientRect();
    return { teks: el.textContent.trim(), ukuran: parseFloat(getComputedStyle(el).fontSize), h: r.height };
  });
  cek('nominal yang tampil', nominal.teks, 'Rp. 1.350.000');
  benar('nominal dicetak besar (' + nominal.ukuran + 'px)', nominal.ukuran >= 20);
  benar('kalimat permintaan pembayaran tampil & memuat rekening baru',
    (await page.textContent('#kalimatBiaya')).includes('Bank Uji 999'));
  benar('tombol bukti transfer berupa TAUTAN wa.me dengan pesan',
    await page.$eval('#btnKirimBukti', (el) => {
      const h = el.getAttribute('href') || '';
      return el.tagName === 'A'
        && /^https:\/\/wa\.me\/6281211112222\?text=/.test(h)
        && decodeURIComponent(h).includes('1.350.000')
        && el.getAttribute('target') === '_blank'
        && /noopener/.test(el.getAttribute('rel') || '');
    }));
  benar('nomor rekening dicetak', (await page.textContent('#rekeningTeks')).includes('Bank Uji 999'));
  benar('keterangan rekening dicetak', isi.includes('Transfer hanya ke rekening ini.'));
  benar('kolom kode aktivasi ada', !!(await page.$('#kode')));
  // Bandingkan TEKS YANG TAMPIL (innerText sudah menggabungkan tag) supaya kalimat yang di
  // tengahnya memuat <b> tetap terbaca utuh.
  const teksTampil = (await page.textContent('body')).replace(/\s+/g, ' ');
  benar('aturan sekali pakai dijelaskan (kalimat ringkas)',
    teksTampil.includes('Setiap kode hanya dapat dipakai satu kali. Setelah dipakai, kode tersebut tidak berlaku lagi.'));
  benar('keterangan "berganti otomatis" tidak dicetak lagi', !teksTampil.includes('berganti otomatis'));
  benar('keterangan "kode berikutnya diminta kembali" tidak dicetak lagi',
    !teksTampil.includes('kode berikutnya diminta kembali'));
  benar('kalimat ajakan berhenti di "…lewat tombol di bawah ini"',
    teksTampil.includes('Hubungi HD Admin untuk melakukan aktifasi. Kirim permohonan kode aktifasi lewat tombol di bawah ini'));
  benar('kalimat ajakan tidak lagi menyebut nomor WhatsApp',
    !/lewat tombol di bawah ini \(menuju WhatsApp/.test(teksTampil));
  benar('keterangan kecil di sebelah judul Aktivasi Sekolah/Madrasah dihapus',
    !teksTampil.includes('Permohonan kode aktivasi, biaya, dan rekening'));

  console.log('== 4. Tombol Salin rekening benar-benar bekerja ==');
  await ctx.grantPermissions(['clipboard-read', 'clipboard-write']);
  await page.click('[data-salin="#rekeningTeks"]');
  await page.waitForTimeout(250);
  const tersalin = await page.$eval('[data-salin="#rekeningTeks"]', (el) => el.textContent.trim());
  benar('tombol Salin memberi umpan balik "Tersalin" (' + tersalin + ')', /Tersalin/i.test(tersalin));
  const papan = await page.evaluate(() => navigator.clipboard.readText().catch(() => ''));
  benar('isi papan klip = nomor rekening (' + (papan || '').slice(0, 26) + ')',
    (papan || '').includes('Bank Uji 999'));

  console.log('== 5. Yang dilihat GURU (tanpa nominal/rekening) ==');
  await masuk(page, 'ahmad', 'guru123');
  await page.goto(BASE + '/aktivasi.php');
  isi = await page.content();
  benar('guru melihat kartu Cara Kerja Aktivasi', isi.includes('Cara Kerja Aktivasi'));
  benar('guru TIDAK melihat nominal', !(await page.$('#nominalAktivasi')));
  benar('guru TIDAK melihat nomor rekening', !(await page.$('#rekeningTeks')));
  benar('guru TIDAK melihat tombol permohonan', !(await page.$('.btn-permohonan')));

  console.log('== 6. Kerapian: desktop & HP ==');
  await masuk(page, 'operator1', 'sandi12345');
  for (const [lebar, nama] of [[1440, 'desktop'], [390, 'HP']]) {
    await page.setViewportSize({ width: lebar, height: 950 });
    await page.goto(BASE + '/aktivasi.php');
    await page.waitForTimeout(200);
    const luapan = await page.evaluate(() =>
      document.documentElement.scrollWidth - document.documentElement.clientWidth);
    benar('halaman Status & Aktivasi tanpa luapan mendatar di ' + nama, luapan <= 1);
    const dalamKartu = await page.evaluate(() => {
      const kartu = document.querySelector('#permohonanAktivasi');
      const nom = document.querySelector('#nominalAktivasi');
      if (!kartu || !nom) { return false; }
      const k = kartu.getBoundingClientRect();
      const n = nom.getBoundingClientRect();
      return n.left >= k.left - 1 && n.right <= k.right + 1;
    });
    benar('nominal tidak keluar dari kartunya di ' + nama, dalamKartu);
  }
  await page.setViewportSize({ width: 390, height: 950 });
  await page.goto(BASE + '/aktivasi.php');
  benar('tombol permohonan selebar kartunya di HP',
    await page.evaluate(() => {
      const b = document.querySelector('.btn-permohonan').getBoundingClientRect();
      const k = document.querySelector('#permohonanAktivasi').getBoundingClientRect();
      return b.width >= k.width - 50;
    }));
  // Halaman pengaturan hanya boleh dibuka Super Admin → masuk sebagai admin lebih dahulu
  // (kalau tidak, permintaan 403 yang MEMANG benar akan terhitung sebagai galat konsol).
  await page.setViewportSize({ width: 1440, height: 950 });
  await masuk(page, 'admin', SANDI);
  await page.goto(BASE + '/biaya_aktivasi.php');
  const luapan = await page.evaluate(() =>
    document.documentElement.scrollWidth - document.documentElement.clientWidth);
  benar('halaman pengaturan tanpa luapan mendatar', luapan <= 1);
  await page.setViewportSize({ width: 390, height: 950 });
  await page.goto(BASE + '/biaya_aktivasi.php');
  benar('halaman pengaturan tanpa luapan mendatar di HP',
    (await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)) <= 1);

  benar('tanpa galat konsol', galat.length === 0);
  if (galat.length) { console.log('     ' + galat.slice(0, 3).join(' | ')); }

  await browser.close();
  console.log(`\n  ${lulus} lulus, ${gagal} gagal`);
  process.exit(gagal === 0 ? 0 : 1);
})();
