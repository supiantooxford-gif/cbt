<?php
/** Data Guru (operator / Super Admin dalam konteks) + cetak & impor. */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

require_operator();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $aksi = teks($_POST['aksi'] ?? '', 20);
    $id = (int) ($_POST['id'] ?? 0);
    $g = one('SELECT * FROM guru WHERE id = ? AND sekolah_id = ?', [$id, sekolah_id()]);

    if (!$g) {
        flash('Data guru tidak ditemukan.', 'err');
        redirect('guru.php');
    }

    if ($aksi === 'hapus') {
        q('DELETE FROM guru WHERE id = ? AND sekolah_id = ?', [$id, sekolah_id()]);
        flash('Data guru "' . $g['nama'] . '" dihapus beserta akunnya.', 'warn');
    } elseif ($aksi === 'akun') {
        $ada = one('SELECT * FROM users WHERE guru_id = ? AND sekolah_id = ?', [$id, sekolah_id()]);
        if ($ada) {
            flash('Guru ini sudah punya akun: ' . $ada['username'], 'err');
        } else {
            $uname = username_unik(username_dari_nama((string) $g['nama']));
            q('INSERT INTO users (sekolah_id, nama, username, password, role, guru_id, aktif, dibuat)
               VALUES (?,?,?,?,?,?,1,?)',
                [sekolah_id(), $g['nama'], $uname, password_hash('guru123', PASSWORD_DEFAULT), 'guru', $id, date('Y-m-d H:i:s')]);
            flash('Akun guru dibuat — nama pengguna: ' . $uname . ', kata sandi awal: guru123', 'ok');
        }
    } elseif ($aksi === 'reset') {
        $baru = sandi_acak(8);
        q('UPDATE users SET password = ? WHERE guru_id = ? AND sekolah_id = ?',
            [password_hash($baru, PASSWORD_DEFAULT), $id, sekolah_id()]);
        $_SESSION['sandi_sekali'] = ['nama' => (string) $g['nama'], 'sandi' => $baru];
        flash('Kata sandi baru dibuat. Catat sekarang — hanya ditampilkan sekali.', 'ok');
    } elseif ($aksi === 'toggle') {
        q('UPDATE guru SET aktif = ? WHERE id = ? AND sekolah_id = ?', [(int) $g['aktif'] === 1 ? 0 : 1, $id, sekolah_id()]);
        q('UPDATE users SET aktif = ? WHERE guru_id = ? AND sekolah_id = ?', [(int) $g['aktif'] === 1 ? 0 : 1, $id, sekolah_id()]);
        flash('Status guru diubah.', 'ok');
    }
    redirect('guru.php');
}

$cari = teks($_GET['cari'] ?? '', 60);
$fMapel = (int) ($_GET['mapel'] ?? 0);
$fKelas = (int) ($_GET['kelas'] ?? 0);
$fAkun = teks($_GET['akun'] ?? '', 10);

$sql = "SELECT g.*, u.username, u.aktif AS akun_aktif,
        (SELECT GROUP_CONCAT(m.nama, ', ') FROM guru_mapel gm JOIN mapel m ON m.id = gm.mapel_id WHERE gm.guru_id = g.id) AS mapel,
        (SELECT GROUP_CONCAT(k.nama, ', ') FROM guru_kelas gk JOIN kelas k ON k.id = gk.kelas_id WHERE gk.guru_id = g.id) AS kelas
    FROM guru g LEFT JOIN users u ON u.guru_id = g.id AND u.sekolah_id = g.sekolah_id
    WHERE g.sekolah_id = ?";
$p = [sekolah_id()];
if ($cari !== '') {
    $sql .= ' AND (g.nama LIKE ? OR g.nip LIKE ?)';
    $p[] = '%' . $cari . '%';
    $p[] = '%' . $cari . '%';
}
if ($fMapel > 0) {
    $sql .= ' AND EXISTS (SELECT 1 FROM guru_mapel gm WHERE gm.guru_id = g.id AND gm.mapel_id = ?)';
    $p[] = $fMapel;
}
if ($fKelas > 0) {
    $sql .= ' AND EXISTS (SELECT 1 FROM guru_kelas gk WHERE gk.guru_id = g.id AND gk.kelas_id = ?)';
    $p[] = $fKelas;
}
if ($fAkun === 'ada') { $sql .= ' AND u.id IS NOT NULL'; }
if ($fAkun === 'belum') { $sql .= ' AND u.id IS NULL'; }
$sql .= ' ORDER BY g.nama';
$daftar = all($sql, $p);
$sandiSekali = $_SESSION['sandi_sekali'] ?? null;
unset($_SESSION['sandi_sekali']);

page_top('Data Guru');
?>
<?php if ($sandiSekali): ?>
  <div class="alert alert-warn">
    <b>Kata sandi baru untuk <?= e($sandiSekali['nama']) ?>:</b>
    <span class="kotak-kode" style="margin:6px 0"><?= e($sandiSekali['sandi']) ?></span>
    <br>Catat sekarang — sandi hanya ditampilkan sekali (tersimpan dalam bentuk teracak).
  </div>
<?php endif; ?>

<div class="card">
  <div class="card-head">
    <h3>Daftar Guru</h3>
    <div class="sub"><?= count($daftar) ?> guru</div>
    <div class="kanan">
      <a class="btn btn-sm btn-utama" href="guru_edit.php">Tambah Guru</a>
      <a class="btn btn-sm" href="guru_import.php">Impor Excel/CSV</a>
      <a class="btn btn-sm" href="cetak_guru.php?<?= e(http_build_query(array_filter(['cari' => $cari, 'mapel' => $fMapel, 'kelas' => $fKelas]))) ?>" target="_blank">Cetak Data Guru</a>
      <a class="btn btn-sm" href="guru_export.php?<?= e(http_build_query(array_filter(['cari' => $cari, 'mapel' => $fMapel, 'kelas' => $fKelas, 'akun' => $fAkun]))) ?>">Ekspor Data Guru</a>
    </div>
  </div>
  <div class="card-body" style="padding-bottom:0">
    <form method="get" class="form-grid f4" style="align-items:end">
      <div class="field"><label>Cari Nama/NIP</label><input type="text" name="cari" value="<?= e($cari) ?>"></div>
      <div class="field"><label>Mata Pelajaran</label>
        <select name="mapel"><option value="0">- semua -</option>
          <?php foreach (mapel_list() as $m): ?>
            <option value="<?= (int) $m['id'] ?>"<?= $fMapel === (int) $m['id'] ? ' selected' : '' ?>><?= e($m['nama']) ?></option>
          <?php endforeach; ?></select></div>
      <div class="field"><label>Kelas</label>
        <select name="kelas"><option value="0">- semua -</option>
          <?php foreach (kelas_list() as $k): ?>
            <option value="<?= (int) $k['id'] ?>"<?= $fKelas === (int) $k['id'] ? ' selected' : '' ?>><?= e($k['nama']) ?></option>
          <?php endforeach; ?></select></div>
      <div class="field"><label>Akun</label>
        <select name="akun">
          <option value="">- semua -</option>
          <option value="ada"<?= $fAkun === 'ada' ? ' selected' : '' ?>>Sudah punya akun</option>
          <option value="belum"<?= $fAkun === 'belum' ? ' selected' : '' ?>>Belum punya akun</option>
        </select></div>
      <div class="field"><label>&nbsp;</label><button class="btn btn-sm btn-utama" type="submit">Terapkan</button>
        <a class="btn btn-sm" href="guru.php">Reset</a></div>
    </form>
  </div>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>No</th><th>Nama Guru</th><th>NIP</th><th>NUPTK</th><th>JK</th><th>Jabatan</th>
        <th>Mata Pelajaran</th><th>Kelas Diajar</th><th>Kontak</th><th>Akun</th><th>Status</th>
        <th class="kanan">Aksi</th></tr></thead>
      <tbody>
      <?php foreach ($daftar as $i => $r): ?>
        <tr>
          <td><?= $i + 1 ?></td>
          <td><b><?= e($r['nama']) ?></b></td>
          <td><?= e((string) ($r['nip'] ?: '-')) ?></td>
          <td><?= e((string) ($r['nuptk'] ?: '-')) ?></td>
          <td><?= e((string) ($r['jenis_kelamin'] ?: '-')) ?></td>
          <td><small><?= e((string) ($r['jabatan'] ?: '-')) ?></small></td>
          <td><small><?= e((string) ($r['mapel'] ?: '-')) ?></small></td>
          <td><small><?= e((string) ($r['kelas'] ?: '-')) ?></small></td>
          <td><small><?= e((string) ($r['telp'] ?: '-')) ?></small></td>
          <td><?= $r['username'] ? '<small>' . e((string) $r['username']) . '</small>' : '<span class="pil pil-mut">belum</span>' ?></td>
          <td><?= (int) $r['aktif'] === 1 ? '<span class="pil pil-ok">Aktif</span>' : '<span class="pil pil-mut">Nonaktif</span>' ?></td>
          <td class="kanan row-actions">
            <a class="btn btn-sm" href="guru_edit.php?id=<?= (int) $r['id'] ?>">Ubah</a>
            <?php if (!$r['username']): ?>
              <form method="post" style="display:inline"><?= csrf_field() ?>
                <input type="hidden" name="aksi" value="akun"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                <button class="btn btn-sm btn-ok" type="submit">Buat Akun</button></form>
            <?php else: ?>
              <form method="post" style="display:inline" data-confirm="Buat kata sandi baru untuk guru ini?">
                <?= csrf_field() ?>
                <input type="hidden" name="aksi" value="reset"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                <button class="btn btn-sm" type="submit">Sandi Baru</button></form>
            <?php endif; ?>
            <form method="post" style="display:inline"><?= csrf_field() ?>
              <input type="hidden" name="aksi" value="toggle"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <button class="btn btn-sm" type="submit"><?= (int) $r['aktif'] === 1 ? 'Nonaktifkan' : 'Aktifkan' ?></button></form>
            <form method="post" style="display:inline" data-confirm="Hapus data guru ini?">
              <?= csrf_field() ?>
              <input type="hidden" name="aksi" value="hapus"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <button class="btn btn-sm btn-bahaya" type="submit">Hapus</button></form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$daftar): ?>
        <tr><td colspan="12" class="kosong"><b>Belum ada data guru.</b>Tambahkan satu per satu atau
          impor dari berkas Excel/CSV (termasuk berkas ekspor e-GURU).</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php page_bottom();
