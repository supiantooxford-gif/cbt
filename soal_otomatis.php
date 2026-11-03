<?php
/**
 * SOAL OTOMATIS — halaman pengaturan Super Admin.
 *
 * Permintaan pemilik: *"Pada halaman Super Admin tambahkan fitur untuk memasukkan link pada fitur
 * buat soal otomatis"*.
 *
 * Yang diatur di sini: **TAUTAN** tombol BERKEDIP "Buat Soal Otomatis" pada kepala kartu halaman
 * **Tambah/Ubah Soal** (akun Guru & Operator). Tautan itu membuka aplikasi bantu penyusun soal
 * (mis. Google Gemini "Gem" berbagi) di tab baru.
 *
 * TINGKAT APLIKASI: berlaku untuk seluruh sekolah/madrasah (disimpan pada tabel `app_pengaturan`,
 * kunci `soal_otomatis_url`), sama seperti menu "Biaya & Aktivasi", "Jenis Penilaian", dan
 * "Logo Aplikasi". Konstanta `SOAL_OTOMATIS_URL` di `inc/config.php` hanya NILAI BAWAAN dan dipakai
 * kembali oleh tombol "Kembalikan Bawaan".
 *
 * Kata sandi aplikasi bantu ("Password aplikasi: …") BELUM dapat diubah dari halaman ini — masih
 * konstanta `SOAL_OTOMATIS_PASSWORD` di `inc/config.php`.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

require_super();

$galat = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $aksi = teks($_POST['aksi'] ?? '', 16);

    if ($aksi === 'simpan') {
        $url = trim((string) ($_POST['soal_otomatis_url'] ?? ''));
        $pesan = soal_otomatis_simpan($url);
        if ($pesan !== '') {
            // Validasi gagal → halaman dirender ulang (200) dengan isian dikembalikan.
            $galat = $pesan;
        } else {
            flash('Tautan "Buat Soal Otomatis" disimpan. Tombol pada halaman Tambah/Ubah Soal '
                . 'guru & operator langsung memakai tautan ini.', 'ok');
            redirect('soal_otomatis.php');
        }
    } elseif ($aksi === 'bawaan') {
        soal_otomatis_bawaan_kembalikan();
        flash('Tautan "Buat Soal Otomatis" dikembalikan ke bawaan aplikasi.', 'warn');
        redirect('soal_otomatis.php');
    } else {
        redirect('soal_otomatis.php');
    }
}

// Isian: saat validasi gagal, nilai kiriman pengguna dikembalikan apa adanya.
$urlIsian = $galat !== '' ? trim((string) ($_POST['soal_otomatis_url'] ?? '')) : soal_otomatis_url();
// Pratinjau SELALU memakai tautan yang SEDANG BERLAKU (bukan kiriman yang ditolak), supaya
// Super Admin melihat keadaan sebenarnya setelah galat.
$urlAktif = soal_otomatis_url();
$bawaan = soal_otomatis_masih_bawaan();

page_top('Soal Otomatis');
?>
<div class="card">
  <div class="card-head">
    <h3>Buat Soal Otomatis</h3>
    <div class="sub">Tautan tombol berkedip pada halaman Tambah/Ubah Soal guru &mdash; berlaku untuk
      semua sekolah/madrasah</div>
    <div class="kanan">
      <?php if ($bawaan): ?><span class="pil pil-info">masih bawaan</span>
      <?php else: ?><span class="pil pil-ok">sudah disesuaikan</span><?php endif; ?>
      <?php if (!$bawaan): ?>
        <form method="post" data-confirm="Kembalikan tautan Buat Soal Otomatis ke bawaan aplikasi?">
          <?= csrf_field() ?>
          <input type="hidden" name="aksi" value="bawaan">
          <button class="btn btn-sm btn-bahaya" type="submit">Kembalikan Bawaan</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
  <div class="card-body">
    <p class="mut">Tautan di bawah ini dipakai tombol <b>Buat Soal Otomatis</b> pada kepala kartu
      halaman <b>Tambah Soal</b> / <b>Ubah Soal</b> (akun Guru dan Operator). Tombol itu membuka
      halaman tersebut di <b>tab baru</b>, jadi tautannya harus berupa alamat lengkap yang dimulai
      dengan <b>https://</b> (atau <b>http://</b>), mis. tautan berbagi Google Gemini &ldquo;Gem&rdquo;
      penyusun soal.</p>

    <?php if ($galat !== ''): ?>
      <div class="alert alert-err"><?= e($galat) ?></div>
    <?php endif; ?>

    <form method="post" id="formSoalOtomatis">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="simpan">
      <div class="form-grid">
        <div class="field">
          <label for="soal_otomatis_url">Link / Tautan Buat Soal Otomatis</label>
          <input type="url" inputmode="url" id="soal_otomatis_url" name="soal_otomatis_url" required
                 maxlength="<?= (int) SOAL_OTOMATIS_URL_MAKS ?>" value="<?= e($urlIsian) ?>"
                 placeholder="mis. https://gemini.google.com/share/9b5d2dc2944b">
          <div class="ket">Tulis alamat lengkap (diawali <b>http://</b> atau <b>https://</b>), tanpa
            spasi. Tautan bawaan aplikasi: <b><?= e(soal_otomatis_url_bawaan()) ?></b>.</div>
        </div>
      </div>
      <div class="form-kaki">
        <button class="btn btn-utama" type="submit">Simpan Tautan</button>
        <span class="mut" style="font-size:.78rem">Berlaku untuk <b>semua sekolah/madrasah</b> &mdash;
          tidak perlu disetel ulang per lembaga.</span>
      </div>
    </form>
  </div>
</div>

<?php // PRATINJAU: tombol persis seperti yang dilihat guru, supaya Super Admin tahu hasilnya
      // tanpa harus masuk ke akun guru. ?>
<div class="card">
  <div class="card-head">
    <h3>Pratinjau Tombol di Halaman Tambah Soal</h3>
    <div class="sub">Yang dilihat akun Guru &amp; Operator</div>
  </div>
  <div class="card-body">
    <div class="kanan soal-otomatis" id="pratinjauSoalOtomatis">
      <a class="btn btn-soal-otomatis" id="btnSoalOtomatisContoh" href="<?= e($urlAktif) ?>"
         target="_blank" rel="noopener noreferrer"
         title="Buka aplikasi bantu penyusun soal (tab baru)"><?= ikon('buku', 16) ?> Buat Soal Otomatis</a>
      <span class="soal-otomatis-ket">Password aplikasi:
        <b id="sandiSoalOtomatis"><?= e(SOAL_OTOMATIS_PASSWORD) ?></b></span>
    </div>
    <div class="table-wrap mt16">
      <table class="tbl">
        <tr><th style="width:38%">Tautan yang sedang dipakai</th>
          <td id="tautanSoalOtomatis"><?= e($urlAktif) ?></td></tr>
        <tr><th>Kata sandi aplikasi</th>
          <td><?= e(SOAL_OTOMATIS_PASSWORD) ?> <span class="mut">(diatur di berkas konfigurasi
            aplikasi &mdash; belum dapat diubah dari halaman ini)</span></td></tr>
      </table>
    </div>
    <p class="mut" style="font-size:.78rem;margin:10px 0 0">Klik tombol di atas untuk memastikan
      tautannya benar (terbuka di tab baru).</p>
  </div>
</div>
<?php page_bottom(); ?>
