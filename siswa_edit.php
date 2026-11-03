<?php
/** Tambah / ubah data siswa beserta akunnya. */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

require_operator();

$id = (int) ($_GET['id'] ?? 0);
$sw = $id > 0 ? one('SELECT * FROM siswa WHERE id = ? AND sekolah_id = ?', [$id, sekolah_id()]) : null;
if ($id > 0 && !$sw) {
    flash('Data siswa tidak ditemukan.', 'err');
    redirect('siswa.php');
}

$form = [
    'nis' => (string) ($sw['nis'] ?? ''), 'nisn' => (string) ($sw['nisn'] ?? ''),
    'nama' => (string) ($sw['nama'] ?? ''), 'jenis_kelamin' => (string) ($sw['jenis_kelamin'] ?? 'L'),
    'tempat_lahir' => (string) ($sw['tempat_lahir'] ?? ''), 'tanggal_lahir' => (string) ($sw['tanggal_lahir'] ?? ''),
    'kelas_id' => (int) ($sw['kelas_id'] ?? 0), 'wali' => (string) ($sw['wali'] ?? ''),
    'telp' => (string) ($sw['telp'] ?? ''), 'alamat' => (string) ($sw['alamat'] ?? ''),
    'nama_ayah' => (string) ($sw['nama_ayah'] ?? ''), 'nama_ibu' => (string) ($sw['nama_ibu'] ?? ''),
    'status' => (string) ($sw['status'] ?? 'aktif'),
    'aktif' => (int) ($sw['aktif'] ?? 1),
];
$buatAkun = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $form['nis'] = teks($_POST['nis'] ?? '', 30);
    $form['nisn'] = teks($_POST['nisn'] ?? '', 30);
    $form['nama'] = teks($_POST['nama'] ?? '', 120);
    $form['jenis_kelamin'] = in_array($_POST['jenis_kelamin'] ?? 'L', ['L', 'P'], true) ? (string) $_POST['jenis_kelamin'] : 'L';
    $form['tempat_lahir'] = teks($_POST['tempat_lahir'] ?? '', 80);
    $form['tanggal_lahir'] = tgl_impor($_POST['tanggal_lahir'] ?? '');
    $form['kelas_id'] = (int) ($_POST['kelas_id'] ?? 0);
    $form['wali'] = teks($_POST['wali'] ?? '', 120);
    $form['telp'] = teks($_POST['telp'] ?? '', 30);
    $form['alamat'] = teks($_POST['alamat'] ?? '', 250);
    $form['nama_ayah'] = teks($_POST['nama_ayah'] ?? '', 120);
    $form['nama_ibu'] = teks($_POST['nama_ibu'] ?? '', 120);
    $form['status'] = array_key_exists((string) ($_POST['status'] ?? ''), siswa_status_list())
        ? (string) $_POST['status'] : 'aktif';
    // Kolom `aktif` DITURUNKAN dari status: hanya siswa berstatus Aktif yang dapat mengerjakan ujian.
    $form['aktif'] = $form['status'] === 'aktif' ? 1 : 0;
    $buatAkun = isset($_POST['buat_akun']);

    if ($form['nama'] === '') {
        flash('Nama siswa wajib diisi.', 'err');
    } elseif ($form['kelas_id'] > 0 && !kelas_by_id($form['kelas_id'])) {
        flash('Kelas tidak dikenal.', 'err');
    } elseif ($form['nis'] !== '' && val('SELECT id FROM siswa WHERE sekolah_id = ? AND nis = ? AND id <> ?', [sekolah_id(), $form['nis'], $id])) {
        flash('NIS itu sudah dipakai siswa lain.', 'err');
    } else {
        $kelasVal = $form['kelas_id'] > 0 ? $form['kelas_id'] : null;
        if ($id > 0) {
            q('UPDATE siswa SET nis=?, nisn=?, nama=?, jenis_kelamin=?, tempat_lahir=?, tanggal_lahir=?,
                    kelas_id=?, wali=?, telp=?, alamat=?, nama_ayah=?, nama_ibu=?, status=?, aktif=?
               WHERE id=? AND sekolah_id=?', [
                $form['nis'], $form['nisn'], $form['nama'], $form['jenis_kelamin'], $form['tempat_lahir'],
                $form['tanggal_lahir'] !== '' ? $form['tanggal_lahir'] : null, $kelasVal, $form['wali'],
                $form['telp'], $form['alamat'], $form['nama_ayah'], $form['nama_ibu'], $form['status'],
                $form['aktif'], $id, sekolah_id(),
            ]);
        } else {
            q('INSERT INTO siswa (sekolah_id, nis, nisn, nama, jenis_kelamin, tempat_lahir, tanggal_lahir,
                    kelas_id, wali, telp, alamat, nama_ayah, nama_ibu, status, aktif, dibuat)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
                sekolah_id(), $form['nis'], $form['nisn'], $form['nama'], $form['jenis_kelamin'],
                $form['tempat_lahir'], $form['tanggal_lahir'] !== '' ? $form['tanggal_lahir'] : null,
                $kelasVal, $form['wali'], $form['telp'], $form['alamat'], $form['nama_ayah'],
                $form['nama_ibu'], $form['status'], $form['aktif'], date('Y-m-d H:i:s'),
            ]);
            $id = (int) db()->lastInsertId();
        }
        if ($buatAkun && !one('SELECT id FROM users WHERE siswa_id = ? AND sekolah_id = ?', [$id, sekolah_id()])) {
            $dasar = $form['nis'] !== '' ? $form['nis'] : username_dari_nama($form['nama']);
            $uname = username_unik($dasar);
            q('INSERT INTO users (sekolah_id, nama, username, password, role, siswa_id, aktif, dibuat)
               VALUES (?,?,?,?,?,?,1,?)',
                [sekolah_id(), $form['nama'], $uname, password_hash('siswa123', PASSWORD_DEFAULT), 'siswa', $id, date('Y-m-d H:i:s')]);
            flash('Data siswa disimpan. Akun dibuat — nama pengguna: ' . $uname . ', kata sandi awal: siswa123', 'ok');
        } else {
            flash('Data siswa disimpan.', 'ok');
        }
        redirect('siswa.php');
    }
}

$akunAda = $id > 0 ? one('SELECT * FROM users WHERE siswa_id = ? AND sekolah_id = ?', [$id, sekolah_id()]) : null;

page_top($sw ? 'Ubah Data Siswa' : 'Tambah Data Siswa');
?>
<form method="post">
  <?= csrf_field() ?>
  <div class="grid g21">
    <div class="card">
      <div class="card-head"><h3>Identitas Siswa</h3></div>
      <div class="card-body">
        <div class="form-grid f2">
          <div class="field"><label for="nama">Nama Lengkap</label>
            <input type="text" id="nama" name="nama" value="<?= e($form['nama']) ?>" required></div>
          <div class="field"><label for="nis">NIS</label><input type="text" id="nis" name="nis" value="<?= e($form['nis']) ?>"></div>
          <div class="field"><label for="nisn">NISN</label><input type="text" id="nisn" name="nisn" value="<?= e($form['nisn']) ?>"></div>
          <div class="field"><label for="jenis_kelamin">Jenis Kelamin</label>
            <select id="jenis_kelamin" name="jenis_kelamin">
              <option value="L"<?= $form['jenis_kelamin'] === 'L' ? ' selected' : '' ?>>Laki-laki</option>
              <option value="P"<?= $form['jenis_kelamin'] === 'P' ? ' selected' : '' ?>>Perempuan</option></select></div>
          <div class="field"><label for="kelas_id">Kelas</label>
            <select id="kelas_id" name="kelas_id">
              <option value="0">- belum ditentukan -</option>
              <?php foreach (kelas_list() as $k): ?>
                <option value="<?= (int) $k['id'] ?>"<?= $form['kelas_id'] === (int) $k['id'] ? ' selected' : '' ?>><?= e($k['nama']) ?></option>
              <?php endforeach; ?></select></div>
          <div class="field"><label for="tempat_lahir">Tempat Lahir</label>
            <input type="text" id="tempat_lahir" name="tempat_lahir" value="<?= e($form['tempat_lahir']) ?>"></div>
          <div class="field"><label for="tanggal_lahir">Tanggal Lahir</label>
            <input type="text" id="tanggal_lahir" name="tanggal_lahir" value="<?= e($form['tanggal_lahir']) ?>"
                   placeholder="2011-04-12 atau 12/04/2011">
            <div class="ket">Diterima: 2011-04-12, 12/04/2011, 12 April 2011, atau 20110412.</div></div>
          <div class="field"><label for="nama_ayah">Nama Ayah</label>
            <input type="text" id="nama_ayah" name="nama_ayah" value="<?= e($form['nama_ayah']) ?>"></div>
          <div class="field"><label for="nama_ibu">Nama Ibu</label>
            <input type="text" id="nama_ibu" name="nama_ibu" value="<?= e($form['nama_ibu']) ?>"></div>
          <div class="field"><label for="wali">Nama Wali</label>
            <input type="text" id="wali" name="wali" value="<?= e($form['wali']) ?>">
            <div class="ket">Boleh dikosongkan bila wali sama dengan ayah/ibu.</div></div>
          <div class="field"><label for="telp">No. HP</label>
            <input type="text" id="telp" name="telp" value="<?= e($form['telp']) ?>"></div>
          <div class="field"><label for="alamat">Alamat</label>
            <input type="text" id="alamat" name="alamat" value="<?= e($form['alamat']) ?>"></div>
          <div class="field"><label for="status">Status Siswa</label>
            <select id="status" name="status">
              <?php foreach (siswa_status_list() as $kode => $label): ?>
                <option value="<?= e($kode) ?>"<?= $form['status'] === $kode ? ' selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="ket">Hanya siswa berstatus <b>Aktif</b> yang dapat mengerjakan ujian.
              Lulus/Nonaktif tetap tersimpan beserta nilainya.</div></div>
        </div>
      </div>
    </div>
    <div class="card">
      <div class="card-head"><h3>Akun Login</h3></div>
      <div class="card-body">
        <?php if ($akunAda): ?>
          <p>Nama pengguna: <b><?= e($akunAda['username']) ?></b></p>
          <p class="mut">Gunakan tombol <b>Sandi Baru</b> pada daftar siswa untuk mengganti kata sandinya.</p>
        <?php else: ?>
          <label class="cek"><input type="checkbox" name="buat_akun" value="1"<?= $buatAkun ? ' checked' : '' ?>>
            Sekaligus buatkan akun login</label>
          <p class="mut">Nama pengguna dari NIS (atau kata awal nama) dan kata sandi awal <b>siswa123</b>.
            Akun siswa hanya dipakai untuk mengerjakan ujian yang diaktifkan.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="form-kaki">
    <button class="btn btn-utama" type="submit">Simpan Data Siswa</button>
    <a class="btn" href="siswa.php">Kembali</a>
  </div>
</form>
<?php page_bottom();
