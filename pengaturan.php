<?php
/**
 * Identitas & Pengaturan sekolah (operator / Super Admin dalam konteks):
 * nama lembaga, tahun ajaran, semester, KKM, dan logo untuk kop dokumen cetak.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

require_operator();
$s = current_sekolah();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $aksi = teks($_POST['aksi'] ?? '', 20);
    if ($aksi === 'simpan') {
        foreach (setting_kolom() as $k) {
            if ($k === 'kkm') {
                $v = (string) max(0, min(100, (float) str_replace(',', '.', (string) ($_POST[$k] ?? '75'))));
            } elseif ($k === 'jenjang') {
                $v = jenjang_label(teks($_POST[$k] ?? '', 20));
            } else {
                $v = teks($_POST[$k] ?? '', 200);
            }
            q('UPDATE sekolah SET ' . $k . ' = ? WHERE id = ?', [$v, sekolah_id()]);
        }
        current_sekolah(true);
        flash('Identitas & pengaturan sekolah disimpan.', 'ok');
        redirect('pengaturan.php');
    }
}

$s = current_sekolah(true);
page_top('Identitas & Pengaturan');
?>
<div class="grid g21">
  <div class="card">
    <div class="card-head"><h3>Identitas Sekolah/Madrasah</h3>
      <div class="sub">Dipakai pada kop seluruh lembar cetak dan laporan</div></div>
    <div class="card-body">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="aksi" value="simpan">
        <div class="form-grid f2">
          <div class="field"><label for="nama">Nama Sekolah/Madrasah</label>
            <input type="text" id="nama" name="nama" value="<?= e(setting('nama')) ?>" required></div>
          <div class="field"><label for="jenjang">Jenjang</label>
            <select id="jenjang" name="jenjang">
              <?php foreach (jenjang_list() as $j): ?>
                <option value="<?= e($j) ?>"<?= setting('jenjang') === $j ? ' selected' : '' ?>><?= e($j) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="field"><label for="npsn">NPSN</label><input type="text" id="npsn" name="npsn" value="<?= e(setting('npsn')) ?>"></div>
          <div class="field"><label for="alamat">Alamat (Jalan, Dusun)</label><input type="text" id="alamat" name="alamat" value="<?= e(setting('alamat')) ?>"></div>
          <div class="field"><label for="desa">Desa / Kelurahan</label><input type="text" id="desa" name="desa" value="<?= e(setting('desa')) ?>"></div>
          <div class="field"><label for="kecamatan">Kecamatan</label><input type="text" id="kecamatan" name="kecamatan" value="<?= e(setting('kecamatan')) ?>"></div>
          <div class="field"><label for="kabupaten">Kabupaten / Kota</label><input type="text" id="kabupaten" name="kabupaten" value="<?= e(setting('kabupaten')) ?>"></div>
          <div class="field"><label for="provinsi">Provinsi</label><input type="text" id="provinsi" name="provinsi" value="<?= e(setting('provinsi')) ?>"></div>
          <div class="field"><label for="telepon">Telepon / WhatsApp</label><input type="text" id="telepon" name="telepon" value="<?= e(setting('telepon')) ?>"></div>
          <div class="field"><label for="email">Email</label><input type="email" id="email" name="email" value="<?= e(setting('email')) ?>"></div>
          <div class="field"><label for="website">Situs / Web</label><input type="text" id="website" name="website" value="<?= e(setting('website')) ?>"></div>
          <div class="field"><label for="kepala">Nama Kepala Sekolah/Madrasah</label>
            <input type="text" id="kepala" name="kepala" value="<?= e(setting('kepala')) ?>"></div>
          <div class="field"><label for="nip_kepala">NIP Kepala</label><input type="text" id="nip_kepala" name="nip_kepala" value="<?= e(setting('nip_kepala')) ?>"></div>
        </div>
        <div class="divider"></div>
        <p class="section-title">Tahun Ajaran &amp; Penilaian</p>
        <div class="form-grid f3">
          <div class="field"><label for="tahun_ajaran">Tahun Ajaran</label>
            <input type="text" id="tahun_ajaran" name="tahun_ajaran" value="<?= e(setting('tahun_ajaran')) ?>" placeholder="2025/2026"></div>
          <div class="field"><label for="semester">Semester</label>
            <select id="semester" name="semester">
              <?php foreach (['Ganjil', 'Genap'] as $sm): ?>
                <option value="<?= e($sm) ?>"<?= setting('semester') === $sm ? ' selected' : '' ?>><?= e($sm) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="field"><label for="kkm">KKM / Nilai Ketuntasan</label>
            <input type="number" id="kkm" name="kkm" min="0" max="100" step="0.5" value="<?= e(setting('kkm', '75')) ?>">
            <div class="ket">Dipakai pada laporan untuk menandai siswa Tuntas / Belum Tuntas.</div></div>
        </div>
        <div class="form-kaki">
          <button class="btn btn-utama" type="submit">Simpan Pengaturan</button>
          <a class="btn" href="index.php">Kembali</a>
        </div>
      </form>
    </div>
  </div>

  <div>
    <div class="card" id="cardLogo">
      <div class="card-head"><h3>Logo Sekolah/Madrasah</h3></div>
      <div class="card-body">
        <p class="mut">Dipakai pada kop dokumen cetak. Dianjurkan gambar persegi transparan (PNG/SVG), maksimal 15 MB.</p>
        <div class="center mb16">
          <?php if (trim((string) $s['logo']) !== ''): ?>
            <img id="pratinjauLogo" class="foto-mini" style="width:110px;height:110px" src="<?= e((string) $s['logo']) ?>" alt="Logo">
          <?php else: ?>
            <img id="pratinjauLogo" hidden class="foto-mini" style="width:110px;height:110px" alt="">
            <div class="kosong">Belum ada logo.</div>
          <?php endif; ?>
        </div>
        <form method="post" action="upload_logo.php" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <input type="hidden" name="aksi" value="unggah">
          <div class="field">
            <label for="fileLogo">Pilih Berkas Gambar</label>
            <input type="file" id="fileLogo" name="logo" accept="image/*">
            <div class="ket" id="pesanLogo"></div>
          </div>
          <button class="btn btn-sm btn-utama" type="submit">Unggah / Ganti Logo</button>
        </form>
        <?php if (trim((string) $s['logo']) !== ''): ?>
          <form method="post" action="upload_logo.php" class="mt8" data-confirm="Hapus logo sekolah?">
            <?= csrf_field() ?>
            <input type="hidden" name="aksi" value="hapus">
            <button class="btn btn-sm btn-bahaya" type="submit">Hapus Logo</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h3>Ringkasan</h3></div>
      <div class="card-body">
        <table class="tbl">
          <tr><th>Kelas</th><td><?= (int) val('SELECT COUNT(*) FROM kelas WHERE sekolah_id = ?', [sekolah_id()], 0) ?></td></tr>
          <tr><th>Mata Pelajaran</th><td><?= (int) val('SELECT COUNT(*) FROM mapel WHERE sekolah_id = ?', [sekolah_id()], 0) ?></td></tr>
          <tr><th>Guru</th><td><?= (int) val('SELECT COUNT(*) FROM guru WHERE sekolah_id = ?', [sekolah_id()], 0) ?></td></tr>
          <tr><th>Siswa</th><td><?= (int) val('SELECT COUNT(*) FROM siswa WHERE sekolah_id = ?', [sekolah_id()], 0) ?></td></tr>
          <tr><th>Akun</th><td><?= (int) val('SELECT COUNT(*) FROM users WHERE sekolah_id = ?', [sekolah_id()], 0) ?></td></tr>
        </table>
      </div>
    </div>
  </div>
</div>

<?php page_bottom();
