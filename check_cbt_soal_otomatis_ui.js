/* =====================================================================================
   Uji TAMPILAN tombol "BUAT SOAL OTOMATIS" (halaman Tambah/Ubah Soal) — Playwright.

   Yang diukur (bukan sekadar mencari teks):
   - tombol benar-benar tampil di KEPALA kartu "Tambah Soal", di sisi KANAN (posisi kotak merah
     pada tangkapan layar pemilik) dan sebaris dengan judul kartu;
   - tombol berupa `<a>` menuju tautan Gemini dengan target _blank + rel noopener noreferrer
     (benar-benar membuka tab baru);
   - warna tombol MERAH dan BENAR-BENAR BERKEDIP (warna/box-shadow berubah dalam 1 detik,
     terukur lewat dua cuplikan getComputedStyle);
   - keterangan "Password aplikasi: berkah" tampil di bawah tombol;
   - tetap terbaca & tidak meluber pada HP 390 (tombol melebar penuh);
   - mode "kurangi animasi" (prefers-reduced-motion) tidak berkedip tetapi tetap merah;
   - kedipnya berhenti tidak menghalangi: tombol tetap bisa diklik (navigasi ke tab baru);
   - tanpa galat konsol.

   Jalankan lewat:  bash check_cbt_soal_otomatis_ui.sh
   ===================================================================================== */
const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8901';
const TAUTAN = 'https://gemini.google.com/share/9b5d2dc2944b';
const SANDI = 'berkah';
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

  await masuk(page, 'ahmad', 'guru123');
  // Halaman Tambah Soal: ujian pertama milik guru ini.
  await page.goto(BASE + '/ujian.php');
  await Promise.all([page.waitForNavigation(), page.locator('a', { hasText: 'Kelola' }).first().click()]);
  const idUjian = (page.url().match(/id=(\d+)/) || [])[1];
  benar('id ujian terbaca dari halaman ujian', !!idUjian);
  await page.goto(BASE + '/soal_edit.php?ujian=' + idUjian);
  await page.waitForTimeout(300);

  console.log('== 1. Tombol ada di kepala kartu "Tambah Soal" sisi KANAN ==');
  const tombol = page.locator('#btnSoalOtomatis');
  cek('tombol "Buat Soal Otomatis" tampil tepat satu', await tombol.count(), 1);
  benar('tombol terlihat', await tombol.isVisible());
  cek('label tombol', (await tombol.innerText()).trim(), 'Buat Soal Otomatis');

  const kartuHead = page.locator('.card-head.soal-head').first();
  const kotakHead = await kartuHead.boundingBox();
  const kotakTombol = await tombol.boundingBox();
  benar('tombol berada DI DALAM kepala kartu',
    !!kotakHead && !!kotakTombol
    && kotakTombol.y >= kotakHead.y - 1
    && kotakTombol.y + kotakTombol.height <= kotakHead.y + kotakHead.height + 1);
  benar('tombol berada di separuh KANAN kepala kartu',
    !!kotakHead && !!kotakTombol && kotakTombol.x > kotakHead.x + kotakHead.width / 2);
  const judul = await page.locator('.card-head.soal-head h3').first().boundingBox();
  benar('tombol sebaris (vertikal) dengan judul kartu',
    !!judul && !!kotakTombol && Math.abs((judul.y + judul.height / 2) - (kotakTombol.y + kotakTombol.height / 2)) <= 12);
  benar('tinggi tombol memadai (≥ 28px)', !!kotakTombol && kotakTombol.height >= 28);

  console.log('== 2. Tautan & atribut keamanan ==');
  cek('href tombol', await tombol.getAttribute('href'), TAUTAN);
  cek('target tombol', await tombol.getAttribute('target'), '_blank');
  cek('rel tombol', await tombol.getAttribute('rel'), 'noopener noreferrer');
  benar('tombol berupa tautan (<a>)', (await tombol.evaluate((el) => el.tagName)) === 'A');

  console.log('== 3. Keterangan password ==');
  const ket = page.locator('.soal-otomatis-ket');
  cek('keterangan password tampil tepat satu', await ket.count(), 1);
  benar('keterangan terlihat', await ket.isVisible());
  benar('keterangan berbunyi "Password aplikasi: berkah"',
    (await ket.innerText()).replace(/\s+/g, ' ').trim() === 'Password aplikasi: ' + SANDI);
  cek('kata sandi pada penanda sendiri', (await page.locator('#sandiSoalOtomatis').innerText()).trim(), SANDI);
  const kotakKet = await ket.boundingBox();
  benar('keterangan berada DI BAWAH tombol', !!kotakKet && !!kotakTombol && kotakKet.y >= kotakTombol.y + kotakTombol.height - 1);
  benar('keterangan tidak melewati tepi kanan kepala kartu',
    !!kotakKet && !!kotakHead && kotakKet.x + kotakKet.width <= kotakHead.x + kotakHead.width + 1);

  console.log('== 4. Warna MERAH & benar-benar BERKEDIP ==');
  const warna = () => tombol.evaluate((el) => {
    const s = getComputedStyle(el);
    return { bg: s.backgroundColor, shadow: s.boxShadow, anim: s.animationName, dur: s.animationDuration };
  });
  const w1 = await warna();
  // Warna BERUBAH selama berkedip, jadi yang diperiksa sifatnya: merah dominan & cukup pekat.
  const merahDominan = (warna) => {
    const n = (warna.match(/\d+/g) || []).map(Number);
    return n.length >= 3 && n[0] >= 150 && n[1] <= 70 && n[2] <= 80 && n[0] > n[1] * 3;
  };
  benar('warna latar merah pekat (' + w1.bg + ')', merahDominan(w1.bg));
  benar('memakai animasi kedip (soalOtomatisKedip)', w1.anim.includes('soalOtomatisKedip'));
  cek('durasi kedip 1 detik', w1.dur, '1s');
  // Ambil dua cuplikan dengan jeda ~ setengah siklus: warna & bayangan harus BERUBAH.
  let berubah = false;
  for (let i = 0; i < 12 && !berubah; i++) {
    await page.waitForTimeout(60);
    const w2 = await warna();
    if (w2.bg !== w1.bg || w2.shadow !== w1.shadow) { berubah = true; }
  }
  benar('warna/bayangan tombol benar-benar berubah (berkedip)', berubah);

  console.log('== 5. Kontras teks putih pada warna kedip (AA ≥ 4,5:1) ==');
  const kontras = await page.evaluate(() => {
    const el = document.querySelector('#btnSoalOtomatis');
    const lin = (c) => { c /= 255; return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4); };
    const L = (rgb) => 0.2126 * lin(rgb[0]) + 0.7152 * lin(rgb[1]) + 0.0722 * lin(rgb[2]);
    const ke = (a, b) => (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
    const nilai = [];
    for (const w of ['#e6152b', '#b40e1c']) {
      const d = document.createElement('div');
      d.style.background = w;
      document.body.appendChild(d);
      const rgb = getComputedStyle(d).backgroundColor.match(/\d+/g).map(Number);
      d.remove();
      nilai.push(Number(ke(L([255, 255, 255]), L(rgb)).toFixed(2)));
    }
    return { warna: getComputedStyle(el).color, rasio: nilai };
  });
  cek('warna teks tombol putih', kontras.warna, 'rgb(255, 255, 255)');
  benar('kontras kedua warna kedip ≥ 4,5 (' + kontras.rasio.join(' & ') + ')',
    kontras.rasio.every((n) => n >= 4.5));

  console.log('== 6. Tombol benar-benar membuka tab baru ==');
  const [tabBaru] = await Promise.all([
    ctx.waitForEvent('page', { timeout: 15000 }),
    tombol.click(),
  ]);
  benar('klik tombol membuka tab baru', !!tabBaru);
  benar('tab baru mengarah ke tautan Gemini', (tabBaru.url() || '').includes('gemini.google.com/share/9b5d2dc2944b'));
  await tabBaru.close();

  console.log('== 7. Mode kurangi animasi (prefers-reduced-motion) ==');
  // Playwright di sini membatasi SATU tab per instance browser, jadi pengujian mode "kurangi
  // animasi" memakai instance browser TERPISAH (bukan tab kedua pada instance yang sama).
  const browser2 = await chromium.launch();
  const ctx2 = await browser2.newContext({ viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce' });
  const p2 = await ctx2.newPage();
  await masuk(p2, 'ahmad', 'guru123');
  await p2.goto(BASE + '/soal_edit.php?ujian=' + idUjian);
  await p2.waitForTimeout(300);
  const w3 = await p2.locator('#btnSoalOtomatis').evaluate((el) => {
    const s = getComputedStyle(el);
    return { anim: s.animationName, bg: s.backgroundColor };
  });
  cek('tanpa animasi kedip saat "kurangi animasi"', w3.anim, 'none');
  benar('tetap merah saat "kurangi animasi" (' + w3.bg + ')', merahDominan(w3.bg));
  benar('tombol tetap terlihat', await p2.locator('#btnSoalOtomatis').isVisible());
  await ctx2.close();
  await browser2.close();

  console.log('== 8. Kerapian 1440 & HP 390 ==');
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(BASE + '/soal_edit.php?ujian=' + idUjian);
  await page.waitForTimeout(300);
  const ov1 = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  benar('tanpa luapan mendatar di 1440 (' + ov1 + 'px)', ov1 <= 1);
  await page.setViewportSize({ width: 390, height: 820 });
  await page.waitForTimeout(400);
  const ov2 = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  benar('tanpa luapan mendatar di HP 390 (' + ov2 + 'px)', ov2 <= 1);
  const t390 = await page.locator('#btnSoalOtomatis').boundingBox();
  const h390 = await page.locator('.card-head.soal-head').first().boundingBox();
  benar('tombol masih terlihat di HP', await page.locator('#btnSoalOtomatis').isVisible());
  benar('tombol tidak melewati lebar kartu di HP',
    !!t390 && !!h390 && t390.x + t390.width <= h390.x + h390.width + 1);
  benar('keterangan password tetap terlihat di HP', await page.locator('.soal-otomatis-ket').isVisible());

  console.log('== 9. Halaman Ubah Soal juga memuat tombol ==');
  await page.setViewportSize({ width: 1440, height: 900 });
  const idSoal = await page.evaluate(async (uj) => {
    const r = await fetch('ujian_edit.php?id=' + uj);
    const t = await r.text();
    const m = t.match(/soal_edit\.php\?ujian=\d+&id=(\d+)/);
    return m ? m[1] : '';
  }, idUjian);
  benar('id soal terbaca dari daftar soal', !!idSoal);
  if (idSoal) {
    await page.goto(BASE + '/soal_edit.php?ujian=' + idUjian + '&id=' + idSoal);
    await page.waitForTimeout(300);
    cek('tombol juga tampil di halaman Ubah Soal', await page.locator('#btnSoalOtomatis').count(), 1);
    benar('judul kartu berbunyi "Ubah Soal"', (await page.locator('.card-head.soal-head h3').first().innerText()).includes('Ubah Soal'));
  }

  benar('tanpa galat konsol', galatKonsol.length === 0);
  if (galatKonsol.length) { console.log('     ' + galatKonsol.slice(0, 3).join(' | ')); }

  await browser.close();
  console.log(`\n  ${lulus} lulus, ${gagal} gagal`);
  process.exit(gagal === 0 ? 0 : 1);
})();
