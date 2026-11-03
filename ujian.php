<?php
/** Daftar ujian (guru: ujian yang dibuatnya; operator/Super Admin: seluruh ujian sekolah). */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

$u = require_sekolah();
if (!is_guru() && !is_operator() && !is_super()) {
    tolak403('Halaman ini untuk akun Guru atau pengelola sekolah.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $aksi = teks($_POST['aksi'] ?? '', 20);
    $id = (int) ($_POST['id'] ?? 0);
    $uj = ujian_by_id($id);
    if (!$uj) {
        flash('Ujian tidak ditemukan.', 'err');
        redirect('ujian.php');
    }
    if (is_guru() && (int) $uj['guru_id'] !== (int) (guru_saya()['id'] ?? 0)) {
        tolak403('Ujian ini bukan milik Anda.');
    }
    if ($aksi === 'hapus') {
        q('DELETE FROM ujian WHERE id = ? AND sekolah_id = ?', [$id, sekolah_id()]);
        flash('Ujian "' . $uj['judul'] . '" beserta soal dan hasilnya dihapus.', 'warn');
    } elseif ($aksi === 'aktif' || $aksi === 'draf' || $aksi === 'selesai') {
        $n = (int) val('SELECT COUNT(*) FROM soal WHERE ujian_id = ?', [$id], 0);
        $kelasN = count(ujian_kelas_ids($id));
        if ($aksi === 'aktif' && ($n === 0 || $kelasN === 0)) {
            flash('Ujian belum dapat diaktifkan: tambahkan soal dan pilih kelas sasarannya lebih dahulu.', 'err');
        } else {
            q('UPDATE ujian SET status = ?, diubah = ? WHERE id = ? AND sekolah_id = ?',
                [$aksi === 'aktif' ? 'aktif' : $aksi, date('Y-m-d H:i:s'), $id, sekolah_id()]);
            flash($aksi === 'aktif'
                ? 'Ujian DIAKTIFKAN — siswa pada kelas sasaran dapat mengerjakannya di menu Ujian Saya.'
                : ($aksi === 'selesai' ? 'Ujian ditandai selesai.' : 'Ujian dikembalikan menjadi draf.'), 'ok');
        }
    }
    redirect('ujian.php');
}

$fStatus = teks($_GET['status'] ?? '', 12);
$sql = "SELECT uj.*, m.nama AS mapel, g.nama AS guru,
        (SELECT COUNT(*) FROM soal s WHERE s.ujian_id = uj.id) AS jumlah_soal,
        (SELECT COALESCE(SUM(s.skor),0) FROM soal s WHERE s.ujian_id = uj.id) AS skor_maks,
        (SELECT COUNT(*) FROM sesi_ujian su WHERE su.ujian_id = uj.id AND su.status IN ('selesai','dikumpulkan')) AS peserta,
        (SELECT COUNT(*) FROM ujian_kelas uk WHERE uk.ujian_id = uj.id) AS kelas
    FROM ujian uj LEFT JOIN mapel m ON m.id = uj.mapel_id LEFT JOIN guru g ON g.id = uj.guru_id
    WHERE uj.sekolah_id = ?";
$p = [sekolah_id()];
if (is_guru()) {
    $sql .= ' AND uj.guru_id = ?';
    $p[] = (int) (guru_saya()['id'] ?? 0);
}
if ($fStatus !== '' && in_array($fStatus, ['draft', 'aktif', 'selesai'], true)) {
    $sql .= ' AND uj.status = ?';
    $p[] = $fStatus;
}
$sql .= ' ORDER BY uj.id DESC';
$daftar = all($sql, $p);

page_top('Ujian & Soal');
?>
<div class="card">
  <div class="card-head">
    <h3>Daftar Ujian</h3>
    <div class="sub"><?= count($daftar) ?> ujian<?= is_guru() ? ' (milik Anda)' : '' ?></div>
    <div class="kanan">
      <a class="btn btn-sm btn-utama" href="ujian_edit.php">Buat Ujian Baru</a>
      <a class="btn btn-sm" href="bank_soal.php">Bank Soal</a>
      <a class="btn btn-sm" href="hasil.php">Hasil &amp; Analisis</a>
    </div>
  </div>
  <div class="card-body" style="padding-bottom:0">
    <div class="tab-bar">
      <a href="ujian.php" class="<?= $fStatus === '' ? 'on' : '' ?>">Semua</a>
      <a href="ujian.php?status=aktif" class="<?= $fStatus === 'aktif' ? 'on' : '' ?>">Aktif</a>
      <a href="ujian.php?status=draft" class="<?= $fStatus === 'draft' ? 'on' : '' ?>">Draf</a>
      <a href="ujian.php?status=selesai" class="<?= $fStatus === 'selesai' ? 'on' : '' ?>">Selesai</a>
    </div>
  </div>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>No</th><th>Judul Ujian</th><th>Mata Pelajaran</th><th>Guru</th>
        <th class="center">Soal</th><th class="center">Skor Maks</th><th class="center">Kelas</th>
        <th class="center">Peserta</th><th>Status</th><th class="kanan">Aksi</th></tr></thead>
      <tbody>
      <?php foreach ($daftar as $i => $r): ?>
        <tr>
          <td><?= $i + 1 ?></td>
          <td><b><?= e($r['judul']) ?></b><br><small class="mut"><?= e((string) $r['jenis_ujian']) ?> &middot;
            <?= (int) $r['durasi'] ?> menit<?= (int) $r['acak_soal'] === 1 ? ' &middot; soal diacak' : '' ?></small></td>
          <td><?= e((string) ($r['mapel'] ?: '-')) ?></td>
          <td><small><?= e((string) ($r['guru'] ?: '-')) ?></small></td>
          <td class="center"><?= (int) $r['jumlah_soal'] ?></td>
          <td class="center"><?= r1($r['skor_maks']) ?></td>
          <td class="center"><?= (int) $r['kelas'] ?></td>
          <td class="center"><?= (int) $r['peserta'] ?></td>
          <td>
            <?php if ($r['status'] === 'aktif'): ?><span class="pil pil-ok">Aktif</span>
            <?php elseif ($r['status'] === 'selesai'): ?><span class="pil pil-mut">Selesai</span>
            <?php else: ?><span class="pil pil-warn">Draf</span><?php endif; ?>
          </td>
          <td class="kanan row-actions">
            <a class="btn btn-sm btn-utama" href="ujian_edit.php?id=<?= (int) $r['id'] ?>">Kelola</a>
            <a class="btn btn-sm" href="hasil_ujian.php?id=<?= (int) $r['id'] ?>">Hasil</a>
            <form method="post" style="display:inline" data-confirm="Hapus ujian ini beserta soal dan hasilnya?">
              <?= csrf_field() ?><input type="hidden" name="aksi" value="hapus"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <button class="btn btn-sm btn-bahaya" type="submit">Hapus</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$daftar): ?>
        <tr><td colspan="10" class="kosong"><b>Belum ada ujian.</b>
          Tekan <b>Buat Ujian Baru</b> untuk membuat paket soal pertama Anda.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-head"><h3>Alur Singkat</h3></div>
  <div class="card-body">
    <ul class="poin-list">
      <li><b>1. Buat ujian</b> — tentukan mata pelajaran, kelas sasaran, durasi, dan pengaturan pengacakan.</li>
      <li><b>2. Isi soal</b> — tulis satu per satu (enam jenis soal tersedia, termasuk
        <b>soal bergambar</b>) atau <b>impor dari template</b>: <b>template Word (.docx / .doc)</b> untuk
        mengisi soal dengan nyaman di Word, atau template Excel/CSV.</li>
      <li><b>Bank Soal</b> — semua soal yang Anda buat otomatis terkumpul di menu <b>Bank Soal</b>;
        di sana soal dapat <b>diunduh dalam bentuk Word</b> dan <b>dicetak rapi beserta kunci jawaban</b>.</li>
      <li><b>3. Aktifkan</b> — setelah soal dan kelas siap, ubah status menjadi <b>Aktif</b>; barulah siswa
        pada kelas itu dapat membuka ujiannya.</li>
      <li><b>4. Pantau hasil</b> — nilai objektif dihitung otomatis; jawaban uraian dinilai dari kata kunci
        lalu dapat Anda sesuaikan pada menu Hasil &amp; Analisis.</li>
    </ul>
  </div>
</div>
<?php page_bottom();
