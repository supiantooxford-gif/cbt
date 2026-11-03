<?php
/**
 * LOGO APLIKASI (khusus Super Admin) — tingkat APLIKASI, bukan per sekolah.
 *
 * Tampil di halaman masuk, halaman pendaftaran, dan sidebar seluruh pengguna.
 * Logo bawaan adalah `assets/logo-cbt.svg`; halaman ini memungkinkan Super Admin
 * menggantinya dengan berkas miliknya sendiri (disimpan sebagai URL di app_pengaturan).
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

require_super();

$logo = logo_aplikasi();
$bawaan = logo_aplikasi_bawaan();

page_top('Logo Aplikasi');
?>
<div class="grid g21">
  <div class="card">
    <div class="card-head">
      <h3>Logo Aplikasi</h3>
      <div class="sub">Dipakai pada halaman masuk, halaman pendaftaran, dan sidebar</div>
      <div class="kanan">
        <?php if ($bawaan): ?><span class="pil pil-info">logo bawaan</span>
        <?php else: ?><span class="pil pil-ok">logo unggahan</span><?php endif; ?>
      </div>
    </div>
    <div class="card-body">
      <div class="alert alert-info">
        Logo ini dipakai <b>seluruh sekolah</b> (tingkat aplikasi). Berkas yang disarankan:
        gambar <b>persegi (1:1)</b>, minimal 512 × 512 piksel, format PNG/SVG dengan latar transparan,
        maksimal 15 MB.
      </div>

      <div class="grid g2" style="align-items:center">
        <div class="center">
          <img src="<?= e($logo) ?>" alt="Logo aplikasi" class="logo-pratinjau" id="pratinjauLogoApp">
          <p class="mut" style="font-size:.78rem;margin-top:8px">
            <?= $bawaan ? 'Logo bawaan aplikasi' : 'Logo yang sedang dipakai' ?>
          </p>
        </div>
        <div>
          <p class="section-title">Contoh pemakaian</p>
          <div class="center" style="background:var(--utama-muda);border-radius:12px;padding:14px">
            <img src="<?= e($logo) ?>" alt="Contoh kecil" style="width:64px;height:64px;object-fit:contain">
            <div style="margin-top:6px;font-size:.75rem" class="mut">ukuran halaman masuk (64 px)</div>
            <img src="<?= e($logo) ?>" alt="Contoh sangat kecil" style="width:34px;height:34px;object-fit:contain;margin-top:12px">
            <div style="margin-top:4px;font-size:.7rem" class="mut">ukuran sidebar (34 px)</div>
          </div>
        </div>
      </div>

      <div class="divider"></div>

      <form method="post" action="upload_logo_app.php" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="aksi" value="unggah">
        <div class="field">
          <label for="fileLogoApp">Pilih Berkas Logo</label>
          <input type="file" id="fileLogoApp" name="logo" accept="image/*">
          <div class="ket" id="pesanLogoApp">PNG, JPG, GIF, WEBP, atau SVG — maksimal 15 MB.</div>
        </div>
        <button class="btn btn-utama" type="submit">Unggah / Ganti Logo</button>
      </form>

      <?php if (!$bawaan): ?>
        <form method="post" action="upload_logo_app.php" class="mt16"
              data-confirm="Kembalikan logo aplikasi ke logo bawaan?">
          <?= csrf_field() ?>
          <input type="hidden" name="aksi" value="hapus">
          <button class="btn btn-bahaya" type="submit">Kembalikan Logo Bawaan</button>
        </form>
      <?php endif; ?>

      <p class="mut mt16" style="font-size:.8rem;margin-bottom:0">
        Logo disimpan di layanan penyimpanan berkas aplikasi; yang dicatat pada basis data hanya
        alamat gambarnya. Mengganti logo <b>tidak</b> mengubah logo sekolah pada kop dokumen cetak —
        itu diatur terpisah di menu <b>Identitas &amp; Pengaturan</b> oleh operator sekolah.
      </p>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h3>Petunjuk</h3></div>
    <div class="card-body">
      <ul class="poin-list">
        <li>Gunakan gambar <b>persegi</b> agar tidak terpotong saat ditampilkan kecil.</li>
        <li>Logo tampil pada <b>lingkaran/ubin membulat</b> berukuran 64–72 px di halaman masuk,
          jadi detail yang terlalu halus tidak akan terbaca.</li>
        <li>Format <b>SVG</b> paling tajam (tidak pecah saat diperbesar) dan ukurannya kecil.</li>
        <li>Bila logo baru tidak tampak, <b>muat ulang halaman</b> dengan Ctrl+F5 (berkas lama
          mungkin masih tersimpan pada peramban).</li>
        <li>Nama aplikasi diatur pada berkas <code>inc/config.php</code> (<code>APP_NAME</code>) —
          saat ini: <b><?= e(APP_NAME) ?></b>.</li>
      </ul>
    </div>
  </div>
</div>

<script>
(function () {
  // Pratinjau berkas sebelum diunggah (pelengkap; unggahan tetap bekerja tanpa JavaScript).
  var inp = document.getElementById('fileLogoApp');
  if (!inp) { return; }
  inp.addEventListener('change', function () {
    var f = inp.files && inp.files[0];
    var pesan = document.getElementById('pesanLogoApp');
    if (!f) { return; }
    if (f.size > 15 * 1024 * 1024) {
      if (pesan) { pesan.textContent = 'Ukuran gambar maksimal 15 MB.'; }
      inp.value = '';
      return;
    }
    if (pesan) { pesan.textContent = 'Berkas siap diunggah: ' + f.name; }
    var baca = new FileReader();
    baca.onload = function (e) {
      var img = document.getElementById('pratinjauLogoApp');
      if (img) { img.src = e.target.result; }
    };
    baca.readAsDataURL(f);
  });
})();
</script>
<?php page_bottom();
