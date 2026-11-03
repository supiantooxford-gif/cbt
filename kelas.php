<?php
/** Data Kelas (operator / Super Admin dalam konteks). */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

require_operator();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $aksi = teks($_POST['aksi'] ?? '', 20);
    $id = (int) ($_POST['id'] ?? 0);
    $nama = kelas_nama_rapi(teks($_POST['nama'] ?? '', 60));
    $tingkat = teks($_POST['tingkat'] ?? '', 20);
    $wali = (int) ($_POST['wali_id'] ?? 0);
    $urutan = (int) ($_POST['urutan'] ?? 0);

    if ($aksi === 'tambah' || $aksi === 'ubah') {
        if ($nama === '') {
            flash('Nama kelas wajib diisi.', 'err');
        } elseif (val('SELECT id FROM kelas WHERE sekolah_id = ? AND nama = ? AND id <> ?', [sekolah_id(), $nama, $id])) {
            flash('Kelas dengan nama itu sudah ada.', 'err');
        } elseif ($wali > 0 && !val('SELECT id FROM guru WHERE id = ? AND sekolah_id = ?', [$wali, sekolah_id()])) {
            flash('Wali kelas tidak dikenal.', 'err');
        } else {
            if ($urutan === 0) {
                $urutan = ((int) val('SELECT COALESCE(MAX(urutan),0) FROM kelas WHERE sekolah_id = ?', [sekolah_id()], 0)) + 10;
            }
            $waliId = $wali > 0 ? $wali : null;
            if ($aksi === 'tambah') {
                q('INSERT INTO kelas (sekolah_id, nama, tingkat, wali_id, urutan, dibuat) VALUES (?,?,?,?,?,?)',
                    [sekolah_id(), $nama, $tingkat, $waliId, $urutan, date('Y-m-d H:i:s')]);
                flash('Kelas "' . $nama . '" ditambahkan.', 'ok');
            } else {
                q('UPDATE kelas SET nama=?, tingkat=?, wali_id=?, urutan=? WHERE id=? AND sekolah_id=?',
                    [$nama, $tingkat, $waliId, $urutan, $id, sekolah_id()]);
                flash('Kelas diperbarui.', 'ok');
            }
        }
    } elseif ($aksi === 'hapus') {
        $n = (int) val('SELECT COUNT(*) FROM siswa WHERE kelas_id = ? AND sekolah_id = ?', [$id, sekolah_id()], 0);
        if ($n > 0) {
            flash('Kelas tidak dapat dihapus: masih ada ' . $n . ' siswa terdaftar.', 'err');
        } else {
            q('DELETE FROM kelas WHERE id = ? AND sekolah_id = ?', [$id, sekolah_id()]);
            flash('Kelas dihapus.', 'warn');
        }
    }
    redirect('kelas.php');
}

$editId = (int) ($_GET['edit'] ?? 0);
$editRow = $editId > 0 ? kelas_by_id($editId) : null;
$daftar = all('SELECT k.*, g.nama AS wali,
        (SELECT COUNT(*) FROM siswa sw WHERE sw.kelas_id = k.id AND sw.aktif = 1) AS siswa
    FROM kelas k LEFT JOIN guru g ON g.id = k.wali_id
    WHERE k.sekolah_id = ? ORDER BY k.urutan, k.nama', [sekolah_id()]);

page_top('Data Kelas');
?>
<div class="grid g21">
  <div class="card">
    <div class="card-head"><h3>Daftar Kelas</h3><div class="sub"><?= count($daftar) ?> kelas</div></div>
    <div class="table-wrap">
      <table class="tbl">
        <thead><tr><th>No</th><th>Nama Kelas</th><th>Tingkat</th><th>Wali Kelas</th>
          <th class="center">Siswa</th><th class="center">Urutan</th><th class="kanan">Aksi</th></tr></thead>
        <tbody>
        <?php foreach ($daftar as $i => $r): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td><b><?= e($r['nama']) ?></b></td>
            <td><?= e((string) ($r['tingkat'] ?: '-')) ?></td>
            <td><?= e((string) ($r['wali'] ?: '-')) ?></td>
            <td class="center"><?= (int) $r['siswa'] ?></td>
            <td class="center"><?= (int) $r['urutan'] ?></td>
            <td class="kanan row-actions">
              <a class="btn btn-sm" href="kelas.php?edit=<?= (int) $r['id'] ?>">Ubah</a>
              <form method="post" style="display:inline" data-confirm="Hapus kelas ini?">
                <?= csrf_field() ?><input type="hidden" name="aksi" value="hapus">
                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                <button class="btn btn-sm btn-bahaya" type="submit">Hapus</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$daftar): ?>
          <tr><td colspan="7" class="kosong"><b>Belum ada kelas.</b>Tambahkan kelas lebih dahulu
            (mis. Kelas VII-A, VIII-A, IX-A) karena kelas dipakai untuk menautkan siswa dan ujian.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h3><?= $editRow ? 'Ubah Kelas' : 'Tambah Kelas' ?></h3>
      <?php if ($editRow): ?><div class="kanan"><a class="btn btn-sm" href="kelas.php">Batal</a></div><?php endif; ?>
    </div>
    <div class="card-body">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="aksi" value="<?= $editRow ? 'ubah' : 'tambah' ?>">
        <input type="hidden" name="id" value="<?= (int) $editId ?>">
        <div class="field"><label for="nama">Nama Kelas</label>
          <input type="text" id="nama" name="nama" value="<?= e((string) ($editRow['nama'] ?? '')) ?>" required placeholder="mis. VII-A">
          <div class="ket">Tulis tingkat dengan angka Romawi (I, II, III, IV, V, VI, VII, VIII, IX, X, XI, XII)
            tanpa kata "Kelas" — mis. <b>VII-A</b>. Bila Anda menulis "Kelas 7A" akan dirapikan menjadi "VII-A".</div></div>
        <div class="field"><label for="tingkat">Tingkat</label>
          <input type="text" id="tingkat" name="tingkat" value="<?= e((string) ($editRow['tingkat'] ?? '')) ?>" placeholder="mis. 7"></div>
        <div class="field"><label for="wali_id">Wali Kelas</label>
          <select id="wali_id" name="wali_id">
            <option value="0">- belum ditetapkan -</option>
            <?php foreach (guru_list() as $g): ?>
              <option value="<?= (int) $g['id'] ?>"<?= (int) ($editRow['wali_id'] ?? 0) === (int) $g['id'] ? ' selected' : '' ?>>
                <?= e($g['nama']) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="field"><label for="urutan">Urutan</label>
          <input type="number" id="urutan" name="urutan" value="<?= (int) ($editRow['urutan'] ?? 0) ?>">
          <div class="ket">Kosongkan/0 = diletakkan paling akhir.</div></div>
        <button class="btn btn-utama" type="submit"><?= $editRow ? 'Simpan Perubahan' : 'Tambah Kelas' ?></button>
      </form>
    </div>
  </div>
</div>
<?php page_bottom();
