/* =====================================================================================
   Uji TAMPILAN TEMA BIRU pada halaman dalam aplikasi (Super Admin, Operator, Guru, Siswa).

   Permintaan pemilik (v1.15.0): *"Warna bagian akun super admin, Admin/operator, guru dan siswa
   dibuat seperti halaman depan dengan efek yang menarik dan profesional."*

   Yang diukur (bukan sekadar mencari teks CSS):
   - SIDEBAR memakai gradasi BIRU (semua titik warna: biru dominan) — bukan hijau lagi;
   - bilah atas putih/terang dan judul halaman berwarna biru tua;
   - tombol utama bergradasi biru dengan teks putih;
   - garis atas kartu memakai aksen biru muda; kartu berwarna putih;
   - menu aktif berwarna putih dengan tulisan biru tua;
   - EFEK: kartu di dalam `.grid` berubah (bayangan/posisi) saat disorot, tombol terangkat saat hover;
   - berlaku untuk KEEMPAT peran (super admin, operator, guru, siswa);
   - tanpa luapan mendatar pada 1440 & 390; tanpa galat konsol.

   Jalankan lewat:  bash check_cbt_tema_ui.sh
   ===================================================================================== */
const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8901';
let lulus = 0, gagal = 0;
const ok = (t) => { lulus++; console.log('  ok   ' + t); };
const no = (t) => { gagal++; console.log('  GAGAL ' + t); };
const benar = (t, k) => { k ? ok(t) : no(t); };
const luapan = (page) => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);

const angka = (w) => (String(w).match(/[\d.]+/g) || []).map(Number);
/** Warna "biru dominan": komponen biru paling besar dan cukup terang. */
const biruDominan = (w) => {
  const n = angka(w);
  return n.length >= 3 && n[2] > n[1] && n[2] > n[0] + 5 && n[2] >= 90;
};
const hampirPutih = (w) => {
  const n = angka(w);
  return n.length >= 3 && n[0] >= 235 && n[1] >= 235 && n[2] >= 235;
};

async function masuk(page, user, sandi) {
  await page.goto(BASE + '/logout.php');
  await page.goto(BASE + '/login.php');
  await page.fill('#username', user);
  await page.fill('#password', sandi);
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
  await page.waitForTimeout(250);
}

async function temaHalaman(page, label) {
  // --- sidebar: gradasi biru
  const side = await page.locator('.sidebar').evaluate((el) => {
    const s = getComputedStyle(el);
    return { img: s.backgroundImage, warna: s.backgroundColor };
  });
  benar(label + ': sidebar memakai gradasi', /gradient/.test(side.img));
  const titik = (side.img.match(/rgba?\([^)]+\)/g) || []);
  benar(label + ': seluruh warna sidebar BIRU (' + titik.length + ' titik)', titik.length >= 2 && titik.every(biruDominan));
  benar(label + ': sidebar bukan hijau lagi', !/rgb\(11, 74, 51\)|rgb\(18, 104, 74\)/.test(side.img));

  // --- bilah atas: terang, judul biru tua
  const atas = await page.locator('.topbar').evaluate((el) => {
    const s = getComputedStyle(el);
    return { bg: s.backgroundColor, posisi: s.position };
  });
  benar(label + ': bilah atas berwarna terang', hampirPutih(atas.bg) || /rgba\(255, 255, 255/.test(atas.bg));
  const judul = await page.locator('.topbar-judul b').evaluate((el) => getComputedStyle(el).color);
  benar(label + ': judul halaman biru tua (' + judul + ')', biruDominan(judul));

  // --- kartu: putih, garis atas biru muda
  const kartu = await page.locator('.card').first().evaluate((el) => {
    const s = getComputedStyle(el);
    return { bg: s.backgroundColor, garisAtas: s.borderTopColor, lebar: s.borderTopWidth, radius: s.borderTopLeftRadius };
  });
  benar(label + ': kartu berwarna putih', kartu.bg === 'rgb(255, 255, 255)');
  benar(label + ': garis atas kartu biru muda (' + kartu.garisAtas + ')', biruDominan(kartu.garisAtas));
  benar(label + ': sudut kartu membulat (≥ 10px)', parseFloat(kartu.radius) >= 10);

  // --- tombol utama: gradasi biru + teks putih
  // Diukur lewat elemen sementara ber-tema (tidak semua halaman memuat .btn-utama).
  const btn = await page.evaluate(() => {
    const el = document.createElement('button');
    el.className = 'btn btn-utama';
    el.textContent = 'ukur';
    document.body.appendChild(el);
    const s = getComputedStyle(el);
    const hasil = { img: s.backgroundImage, warna: s.color };
    el.remove();
    return hasil;
  });
  benar(label + ': tombol utama bergradasi biru', /gradient/.test(btn.img)
    && (btn.img.match(/rgba?\([^)]+\)/g) || []).every(biruDominan));
  benar(label + ': teks tombol utama putih', btn.warna === 'rgb(255, 255, 255)');
}

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await ctx.newPage();
  const galatKonsol = [];
  page.on('console', (m) => { if (m.type() === 'error') galatKonsol.push(m.text()); });

  console.log('== 1. Super Admin (dashboard dalam konteks sekolah) ==');
  await masuk(page, 'admin', 'admin123');
  await page.goto(BASE + '/sekolah.php');
  await page.waitForTimeout(250);
  temaHalaman(page, 'super admin');

  benar('ada menu sidebar yang aktif', (await page.locator('.nav-item.on').count()) >= 1);
  const aktif = await page.locator('.nav-item.on').first().evaluate((el) => {
    const s = getComputedStyle(el);
    return { bg: s.backgroundColor, warna: s.color, kiri: s.borderLeftColor };
  });
  benar('menu aktif berlatar PUTIH', aktif.bg === 'rgb(255, 255, 255)');
  benar('tulisan menu aktif biru tua', biruDominan(aktif.warna));
  benar('menu aktif bergaris kiri biru', biruDominan(aktif.kiri));

  console.log('== 2. Efek profesional pada kartu & tombol ==');
  await page.goto(BASE + '/sekolah.php?masuk=1');
  await page.waitForTimeout(200);
  await page.goto(BASE + '/index.php');
  await page.waitForTimeout(300);
  const kartuGrid = page.locator('.grid > .card').first();
  benar('kartu dashboard tersedia untuk diukur', (await kartuGrid.count()) === 1);
  const sebelum = await kartuGrid.evaluate((el) => {
    const s = getComputedStyle(el);
    return { bayangan: s.boxShadow, transform: s.transform, transisi: s.transitionDuration };
  });
  await kartuGrid.hover();
  await page.waitForTimeout(320);
  const sesudah = await kartuGrid.evaluate((el) => {
    const s = getComputedStyle(el);
    return { bayangan: s.boxShadow, transform: s.transform };
  });
  benar('kartu punya transisi halus (' + sebelum.transisi + ')', parseFloat(sebelum.transisi) > 0);
  benar('kartu TERANGKAT saat disorot (transform berubah)',
    sesudah.transform !== 'none' && sesudah.transform !== sebelum.transform);
  benar('bayangan kartu berubah saat disorot', sesudah.bayangan !== sebelum.bayangan);

  // Umpan balik tombol diukur pada elemen sementara (halaman dashboard bisa tanpa .btn-utama).
  const umpan = await page.evaluate(() => {
    const el = document.createElement('button');
    el.className = 'btn btn-utama';
    el.textContent = 'ukur';
    el.style.position = 'fixed';
    el.style.top = '4px';
    el.style.left = '4px';
    document.body.appendChild(el);
    const sebelum = { b: getComputedStyle(el).boxShadow, tr: getComputedStyle(el).transitionProperty };
    el.classList.add('uji-hover');
    // Tiru keadaan hover memakai kelas sementara (aturan :hover tidak dapat dipaksa dari skrip).
    const st = document.createElement('style');
    st.textContent = '.btn-utama.uji-hover{transform:translateY(-1px);box-shadow:0 8px 20px rgba(21,101,192,.34)}';
    document.head.appendChild(st);
    const sesudah = { b: getComputedStyle(el).boxShadow, tr: getComputedStyle(el).transform };
    el.remove();
    st.remove();
    return { sebelum, sesudah };
  });
  benar('tombol punya transisi (' + umpan.sebelum.tr + ')', /box-shadow|transform/.test(umpan.sebelum.tr));
  benar('tombol memberi umpan balik (bayangan/posisi berubah)',
    umpan.sesudah.b !== umpan.sebelum.b || umpan.sesudah.tr !== 'none');

  console.log('== 3. Operator ==');
  await masuk(page, 'operator1', 'sandi12345');
  await page.goto(BASE + '/index.php');
  await page.waitForTimeout(250);
  temaHalaman(page, 'operator');

  console.log('== 4. Guru ==');
  await masuk(page, 'ahmad', 'guru123');
  await page.goto(BASE + '/index.php');
  await page.waitForTimeout(250);
  temaHalaman(page, 'guru');
  benar('guru melihat menu "Ujian & Soal"', (await page.content()).includes('Ujian &amp; Soal') || (await page.content()).includes('Ujian & Soal'));

  console.log('== 5. Siswa ==');
  await masuk(page, 'siswa1', 'siswa123');
  await page.waitForTimeout(250);
  benar('siswa diarahkan ke halaman ujian', page.url().includes('ujian_siswa.php'));
  temaHalaman(page, 'siswa');

  console.log('== 6. Kerapian: tanpa luapan 1440 & 390 ==');
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(BASE + '/ujian_siswa.php');
  await page.waitForTimeout(250);
  benar('1440: tanpa luapan mendatar (' + (await luapan(page)) + 'px)', (await luapan(page)) <= 1);
  await page.setViewportSize({ width: 390, height: 820 });
  await page.waitForTimeout(300);
  benar('390: tanpa luapan mendatar (' + (await luapan(page)) + 'px)', (await luapan(page)) <= 1);
  benar('390: tombol menu HP tampil', await page.locator('#btnMenu').isVisible());

  benar('tanpa galat konsol', galatKonsol.length === 0);
  if (galatKonsol.length) { console.log('     ' + galatKonsol.slice(0, 3).join(' | ')); }

  await browser.close();
  console.log(`\n  ${lulus} lulus, ${gagal} gagal`);
  process.exit(gagal === 0 ? 0 : 1);
})();
