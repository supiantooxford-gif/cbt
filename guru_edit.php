<?php
/** Tambah / ubah data guru beserta mata pelajaran, kelas yang diampu, dan akunnya. */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

require_operator();

$id = (int) ($_GET['id'] ?? 0);
$g = $id > 0 ? one('SELECT * FROM guru WHERE id = ? AND sekolah_id = ?', [$id, sekolah_id()]) : null;
if ($id > 0 && !$g) {
    flash('Data guru tidak ditemukan.', 'err');
    redirect('guru.php');
}

$form = [
    'nip' => (string) ($g['nip'] ?? ''), 'nuptk' => (string) ($g['nuptk'] ?? ''),
    'nama' => (string) ($g['nama'] ?? ''),
    'jenis_kelamin' => (string) ($g['jenis_kelamin'] ?? 'L'), 'jabatan' => (string) ($g['jabatan'] ?? ''),
    'telp' => (string) ($g['telp'] ?? ''),
    'email' => (string) ($g['email'] ?? ''), 'aktif' => (int) ($g['aktif'] ?? 1),
];
$mapelDipilih = $id > 0 ? guru_mapel_ids($id) : [];
$kelasDipilih = $id > 0 ? guru_kelas_ids($id) : [];
$buatAkun = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $form['nip'] = nip_bersih($_POST['nip'] ?? '');
    $form['nuptk'] = nip_bersih($_POST['nuptk'] ?? '');
    $form['nama'] = teks($_POST['nama'] ?? '', 120);
    $form['jenis_kelamin'] = in_array(($_POST['jenis_kelamin'] ?? 'L'), ['L', 'P'], true) ? (string) $_POST['jenis_kelamin'] : 'L';
    $form['jabatan'] = teks($_POST['jabatan'] ?? '', 80);
    $form['telp'] = teks($_POST['telp'] ?? '', 30);
    $form['email'] = teks($_POST['email'] ?? '', 120);
    $form['aktif'] = isset($_POST['aktif']) ? 1 : 0;
    $mapelDipilih = array_map('intval', pos_arr('mapel'));
    $kelasDipilih = array_map('intval', pos_arr('kelas'));
    $buatAkun = isset($_POST['buat_akun']);

    if ($form['nama'] === '') {
        flash('Nama guru wajib diisi.', 'err');
    } elseif ($form['nip'] !== '' && val('SELECT id FROM guru WHERE sekolah_id = ? AND nip = ? AND id <> ?', [sekolah_id(), $form['nip'], $id])) {
        flash('NIP/NUPTK itu sudah dipakai guru lain.', 'err');
    } else {
        db()->exec('BEGIN IMMEDIATE');
        try {
            if ($id > 0) {
                q('UPDATE guru SET nip=?, nuptk=?, nama=?, jenis_kelamin=?, jabatan=?, telp=?, email=?, aktif=?
                   WHERE id=? AND sekolah_id=?',
                    [$form['nip'] !== '' ? $form['nip'] : null, $form['nuptk'] !== '' ? $form['nuptk'] : null,
                        $form['nama'], $form['jenis_kelamin'], $form['jabatan'],
                        $form['telp'], $form['email'], $form['aktif'], $id, sekolah_id()]);
            } else {
                q('INSERT INTO guru (sekolah_id, nip, nuptk, nama, jenis_kelamin, jabatan, telp, email, aktif, dibuat)
                   VALUES (?,?,?,?,?,?,?,?,?,?)',
                    [sekolah_id(), $form['nip'] !== '' ? $form['nip'] : null,
                        $form['nuptk'] !== '' ? $form['nuptk'] : null, $form['nama'],
                        $form['jenis_kelamin'], $form['jabatan'], $form['telp'], $form['email'],
                        $form['aktif'], date('Y-m-d H:i:s')]);
                $id = (int) db()->lastInsertId();
            }
            guru_mapel_simpan($id, $mapelDipilih);
            guru_kelas_simpan($id, $kelasDipilih);

            if ($buatAkun && !one('SELECT id FROM users WHERE guru_id = ? AND sekolah_id = ?', [$id, sekolah_id()])) {
                $uname = username_unik(username_dari_nama($form['nama']));
                q('INSERT INTO users (sekolah_id, nama, username, password, role, guru_id, aktif, dibuat)
                   VALUES (?,?,?,?,?,?,1,?)',
                    [sekolah_id(), $form['nama'], $uname, password_hash('guru123', PASSWORD_DEFAULT), 'guru', $id, date('Y-m-d H:i:s')]);
                $_SESSION['sandi_sekali'] = ['nama' => $form['nama'], 'sandi' => 'guru123'];
                flash('Akun guru dibuat — nama pengguna: ' . $uname . ' (kata sandi awal guru123).', 'ok');
            } else {
                flash('Data guru disimpan.', 'ok');
            }
            db()->exec('COMMIT');
        } catch (Throwable $ex) {
            db()->exec('ROLLBACK');
            flash('Gagal menyimpan data guru.', 'err');
        }
        redirect('guru.php');
    }
}

$akunAda = $id > 0 ? one('SELECT * FROM users WHERE guru_id = ? AND sekolah_id = ?', [$id, sekolah_id()]) : null;

page_top($g ? 'Ubah Data Guru' : 'Tambah Data Guru');
?>
<form method="post">
  <?= csrf_field() ?>
  <div class="grid g21">
    <div class="card">
      <div class="card-head"><h3>Identitas Guru</h3></div>
      <div class="card-body">
        <div class="form-grid f2">
          <div class="field"><label for="nama">Nama Lengkap &amp; Gelar</label>
            <input type="text" id="nama" name="nama" value="<?= e($form['nama']) ?>" required></div>
          <div class="field"><label for="nip">NIP</label>
            <input type="text" id="nip" name="nip" value="<?= e($form['nip']) ?>">
            <div class="ket">Boleh dikosongkan atau diisi tanda "-".</div></div>
          <div class="field"><label for="nuptk">NUPTK</label>
            <input type="text" id="nuptk" name="nuptk" value="<?= e($form['nuptk']) ?>"></div>
          <div class="field"><label for="jenis_kelamin">Jenis Kelamin</label>
            <select id="jenis_kelamin" name="jenis_kelamin">
              <option value="L"<?= $form['jenis_kelamin'] === 'L' ? ' selected' : '' ?>>Laki-laki</option>
              <option value="P"<?= $form['jenis_kelamin'] === 'P' ? ' selected' : '' ?>>Perempuan</option>
            </select></div>
          <div class="field"><label for="jabatan">Jabatan</label>
            <input type="text" id="jabatan" name="jabatan" value="<?= e($form['jabatan']) ?>"
                   placeholder="mis. Guru Mata Pelajaran / Wakil Kepala">
            <div class="ket">Sama dengan kolom <b>Jabatan</b> pada ekspor/impor e-GURU.</div></div>
          <div class="field"><label for="telp">Telepon / WhatsApp</label>
            <input type="text" id="telp" name="telp" value="<?= e($form['telp']) ?>"></div>
          <div class="field"><label for="email">Email</label>
            <input type="email" id="email" name="email" value="<?= e($form['email']) ?>"></div>
        </div>
        <label class="cek"><input type="checkbox" name="aktif" value="1"<?= $form['aktif'] === 1 ? ' checked' : '' ?>>
          Guru aktif (hanya guru aktif yang dapat ditugaskan sebagai wali kelas)</label>
      </div>
    </div>

    <div>
      <div class="card">
        <div class="card-head"><h3>Akun Login</h3></div>
        <div class="card-body">
          <?php if ($akunAda): ?>
            <p>Nama pengguna: <b><?= e($akunAda['username']) ?></b></p>
            <p class="mut">Untuk mengganti kata sandi, gunakan tombol <b>Sandi Baru</b> pada daftar guru.</p>
          <?php elseif ($buatAkun): ?>
            <p class="mut">Akun akan dibuat saat data disimpan: nama pengguna dari kata awal nama guru dan kata sandi awal <b>guru123</b>.</p>
          <?php else: ?>
            <label class="cek"><input type="checkbox" name="buat_akun" value="1">
              Sekaligus buatkan akun login</label>
            <p class="mut">Nama pengguna otomatis dari kata awal nama guru, kata sandi awal <b>guru123</b>.</p>
          <?php endif; ?>
        </div>
      </div>

      <div class="card">
        <div class="card-head"><h3>Mata Pelajaran yang Diampu</h3></div>
        <div class="card-body">
          <?php if (count(mapel_list()) === 0): ?><div class="kosong">Belum ada mata pelajaran.</div><?php endif; ?>
          <?php foreach (mapel_list() as $m): ?>
            <label class="cek"><input type="checkbox" name="mapel[]" value="<?= (int) $m['id'] ?>"
              <?= in_array((int) $m['id'], $mapelDipilih, true) ? 'checked' : '' ?>> <?= e($m['nama']) ?></label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="card">
        <div class="card-head"><h3>Kelas yang Diajar</h3></div>
        <div class="card-body">
          <?php if (count(kelas_list()) === 0): ?><div class="kosong">Belum ada kelas.</div><?php endif; ?>
          <?php foreach (kelas_list() as $k): ?>
            <label class="cek"><input type="checkbox" name="kelas[]" value="<?= (int) $k['id'] ?>"
              <?= in_array((int) $k['id'], $kelasDipilih, true) ? 'checked' : '' ?>> <?= e($k['nama']) ?></label>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
  <div class="form-kaki">
    <button class="btn btn-utama" type="submit">Simpan Data Guru</button>
    <a class="btn" href="guru.php">Kembali</a>
  </div>
</form>
<?php page_bottom();
