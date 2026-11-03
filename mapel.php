<?php
/** Data Mata Pelajaran (operator / Super Admin dalam konteks). */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

require_operator();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $aksi = teks($_POST['aksi'] ?? '', 20);
    $id = (int) ($_POST['id'] ?? 0);
    $nama = teks($_POST['nama'] ?? '', 100);
    $kode = teks($_POST['kode'] ?? '', 20);
    $urutan = (int) ($_POST['urutan'] ?? 0);

    if ($aksi === 'tambah' || $aksi === 'ubah') {
        if ($nama === '') {
            flash('Nama mata pelajaran wajib diisi.', 'err');
        } elseif (val('SELECT id FROM mapel WHERE sekolah_id = ? AND nama = ? AND id <> ?', [sekolah_id(), $nama, $id])) {
            flash('Mata pelajaran dengan nama itu sudah ada.', 'err');
        } else {
            if ($urutan === 0) {
                $urutan = ((int) val('SELECT COALESCE(MAX(urutan),0) FROM mapel WHERE sekolah_id = ?', [sekolah_id()], 0)) + 10;
            }
            if ($aksi === 'tambah') {
                q('INSERT INTO mapel (sekolah_id, kode, nama, urutan, dibuat) VALUES (?,?,?,?,?)',
                    [sekolah_id(), $kode, $nama, $urutan, date('Y-m-d H:i:s')]);
                flash('Mata pelajaran "' . $nama . '" ditambahkan.', 'ok');
            } else {
                q('UPDATE mapel SET kode=?, nama=?, urutan=? WHERE id=? AND sekolah_id=?',
                    [$kode, $nama, $urutan, $id, sekolah_id()]);
                flash('Mata pelajaran diperbarui.', 'ok');
            }
        }
    } elseif ($aksi === 'hapus') {
        $n = (int) val('SELECT COUNT(*) FROM ujian WHERE mapel_id = ? AND sekolah_id = ?', [$id, sekolah_id()], 0);
        if ($n > 0) {
            flash('Mata pelajaran tidak dapat dihapus: dipakai oleh ' . $n . ' ujian.', 'err');
        } else {
            q('DELETE FROM mapel WHERE id = ? AND sekolah_id = ?', [$id, sekolah_id()]);
            flash('Mata pelajaran dihapus.', 'warn');
        }
    }
    redirect('mapel.php');
}

$editId = (int) ($_GET['edit'] ?? 0);
$editRow = $editId > 0 ? mapel_by_id($editId) : null;
$daftar = all("SELECT m.*,
        (SELECT COUNT(*) FROM ujian uj WHERE uj.mapel_id = m.id) AS ujian,
        (SELECT COUNT(*) FROM guru_mapel gm WHERE gm.mapel_id = m.id) AS guru
    FROM mapel m WHERE m.sekolah_id = ? ORDER BY m.urutan, m.nama", [sekolah_id()]);

page_top('Mata Pelajaran');
?>
<div class="grid g21">
  <div class="card">
    <div class="card-head"><h3>Daftar Mata Pelajaran</h3><div class="sub"><?= count($daftar) ?> mata pelajaran</div></div>
    <div class="table-wrap">
      <table class="tbl">
        <thead><tr><th>No</th><th>Kode</th><th>Mata Pelajaran</th><th class="center">Guru Pengampu</th>
          <th class="center">Ujian</th><th class="center">Urutan</th><th class="kanan">Aksi</th></tr></thead>
        <tbody>
        <?php foreach ($daftar as $i => $r): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td><?= e((string) ($r['kode'] ?: '-')) ?></td>
            <td><b><?= e($r['nama']) ?></b></td>
            <td class="center"><?= (int) $r['guru'] ?></td>
            <td class="center"><?= (int) $r['ujian'] ?></td>
            <td class="center"><?= (int) $r['urutan'] ?></td>
            <td class="kanan row-actions">
              <a class="btn btn-sm" href="mapel.php?edit=<?= (int) $r['id'] ?>">Ubah</a>
              <form method="post" style="display:inline" data-confirm="Hapus mata pelajaran ini?">
                <?= csrf_field() ?><input type="hidden" name="aksi" value="hapus">
                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                <button class="btn btn-sm btn-bahaya" type="submit">Hapus</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$daftar): ?>
          <tr><td colspan="7" class="kosong"><b>Belum ada mata pelajaran.</b>Sekolah yang mendaftar
            sendiri sudah mendapat daftar awal; Anda dapat menambah atau mengubahnya di sini.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h3><?= $editRow ? 'Ubah Mata Pelajaran' : 'Tambah Mata Pelajaran' ?></h3>
      <?php if ($editRow): ?><div class="kanan"><a class="btn btn-sm" href="mapel.php">Batal</a></div><?php endif; ?>
    </div>
    <div class="card-body">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="aksi" value="<?= $editRow ? 'ubah' : 'tambah' ?>">
        <input type="hidden" name="id" value="<?= (int) $editId ?>">
        <div class="field"><label for="nama">Nama Mata Pelajaran</label>
          <input type="text" id="nama" name="nama" value="<?= e((string) ($editRow['nama'] ?? '')) ?>" required></div>
        <div class="field"><label for="kode">Kode</label>
          <input type="text" id="kode" name="kode" value="<?= e((string) ($editRow['kode'] ?? '')) ?>" placeholder="mis. MTK"></div>
        <div class="field"><label for="urutan">Urutan</label>
          <input type="number" id="urutan" name="urutan" value="<?= (int) ($editRow['urutan'] ?? 0) ?>"></div>
        <button class="btn btn-utama" type="submit"><?= $editRow ? 'Simpan Perubahan' : 'Tambah' ?></button>
      </form>
    </div>
  </div>
</div>
<?php page_bottom();
