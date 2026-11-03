<?php
/** Rekap hasil seluruh ujian (guru: ujiannya sendiri; operator/Super Admin: seluruh sekolah). */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

$u = require_sekolah();
if (!is_guru() && !is_operator() && !is_super()) {
    tolak403('Halaman ini untuk akun Guru atau pengelola sekolah.');
}

$fMapel = (int) ($_GET['mapel'] ?? 0);
$fStatus = teks($_GET['status'] ?? '', 12);

$sql = "SELECT uj.*, m.nama AS mapel, g.nama AS guru,
        (SELECT COUNT(*) FROM soal s WHERE s.ujian_id = uj.id) AS jumlah_soal,
        (SELECT COUNT(*) FROM sesi_ujian su WHERE su.ujian_id = uj.id AND su.status IN ('selesai','dikumpulkan')) AS peserta,
        (SELECT AVG(su.nilai) FROM sesi_ujian su WHERE su.ujian_id = uj.id AND su.status IN ('selesai','dikumpulkan')) AS rata,
        (SELECT MAX(su.nilai) FROM sesi_ujian su WHERE su.ujian_id = uj.id AND su.status IN ('selesai','dikumpulkan')) AS maks,
        (SELECT MIN(su.nilai) FROM sesi_ujian su WHERE su.ujian_id = uj.id AND su.status IN ('selesai','dikumpulkan')) AS min,
        (SELECT COUNT(*) FROM sesi_ujian su WHERE su.ujian_id = uj.id AND su.status IN ('selesai','dikumpulkan') AND su.nilai >= ?) AS tuntas,
        (SELECT COUNT(*) FROM sesi_ujian su WHERE su.ujian_id = uj.id AND su.perlu_koreksi = 1 AND su.status IN ('selesai','dikumpulkan')) AS koreksi
    FROM ujian uj LEFT JOIN mapel m ON m.id = uj.mapel_id LEFT JOIN guru g ON g.id = uj.guru_id
    WHERE uj.sekolah_id = ?";
$p = [(float) setting('kkm', '75'), sekolah_id()];
if (is_guru()) {
    $sql .= ' AND uj.guru_id = ?';
    $p[] = (int) (guru_saya()['id'] ?? 0);
}
if ($fMapel > 0) { $sql .= ' AND uj.mapel_id = ?'; $p[] = $fMapel; }
if ($fStatus !== '' && in_array($fStatus, ['draft', 'aktif', 'selesai'], true)) { $sql .= ' AND uj.status = ?'; $p[] = $fStatus; }
$sql .= ' ORDER BY uj.id DESC';
$daftar = all($sql, $p);

$totPeserta = 0;
$jumlahKoreksi = 0;
foreach ($daftar as $r) {
    $totPeserta += (int) $r['peserta'];
    $jumlahKoreksi += (int) $r['koreksi'];
}

page_top('Hasil & Analisis');
?>
<div class="grid g4">
  <?= kartu_stat('Ujian', (string) count($daftar), 'sesuai saringan', 'ujian') ?>
  <?= kartu_stat('Sesi Dinilai', (string) $totPeserta, 'pengerjaan terkumpul', 'hasil') ?>
  <?= kartu_stat('Perlu Koreksi', (string) $jumlahKoreksi, 'jawaban uraian', 'koreksi') ?>
  <?= kartu_stat('KKM', r1(setting('kkm', '75')), 'batas ketuntasan', 'ok') ?>
</div>

<div class="card">
  <div class="card-head">
    <h3>Rekap Hasil Ujian</h3>
    <div class="sub">Rata-rata, ketuntasan, dan tautan analisis butir soal</div>
  </div>
  <div class="card-body" style="padding-bottom:0">
    <form method="get" class="form-grid f3" style="align-items:end">
      <div class="field"><label>Mata Pelajaran</label><select name="mapel">
        <option value="0">- semua -</option>
        <?php foreach (mapel_list() as $m): ?>
          <option value="<?= (int) $m['id'] ?>"<?= $fMapel === (int) $m['id'] ? ' selected' : '' ?>><?= e($m['nama']) ?></option>
        <?php endforeach; ?></select></div>
      <div class="field"><label>Status Ujian</label><select name="status">
        <option value="">- semua -</option>
        <?php foreach (['aktif' => 'Aktif', 'draft' => 'Draf', 'selesai' => 'Selesai'] as $k => $v): ?>
          <option value="<?= e($k) ?>"<?= $fStatus === $k ? ' selected' : '' ?>><?= e($v) ?></option>
        <?php endforeach; ?></select></div>
      <div class="field"><label>&nbsp;</label><button class="btn btn-sm btn-utama" type="submit">Terapkan</button>
        <a class="btn btn-sm" href="hasil.php">Reset</a></div>
    </form>
  </div>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>No</th><th>Ujian</th><th>Mata Pelajaran</th><th class="center">Soal</th>
        <th class="center">Peserta</th><th class="center">Rata-rata</th><th class="center">Tertinggi</th>
        <th class="center">Terendah</th><th class="center">Tuntas</th><th class="kanan">Aksi</th></tr></thead>
      <tbody>
      <?php foreach ($daftar as $i => $r): ?>
        <tr>
          <td><?= $i + 1 ?></td>
          <td><b><?= e($r['judul']) ?></b><br><small class="mut"><?= e((string) ($r['guru'] ?: '-')) ?>
            <?php if ((int) $r['koreksi'] > 0): ?> &middot; <span class="pil pil-info"><?= (int) $r['koreksi'] ?> perlu koreksi</span><?php endif; ?>
          </small></td>
          <td><?= e((string) ($r['mapel'] ?: '-')) ?></td>
          <td class="center"><?= (int) $r['jumlah_soal'] ?></td>
          <td class="center"><?= (int) $r['peserta'] ?></td>
          <td class="center"><b><?= $r['peserta'] ? r1($r['rata']) : '-' ?></b></td>
          <td class="center"><?= $r['peserta'] ? r1($r['maks']) : '-' ?></td>
          <td class="center"><?= $r['peserta'] ? r1($r['min']) : '-' ?></td>
          <td class="center"><?= (int) $r['tuntas'] ?>/<?= (int) $r['peserta'] ?></td>
          <td class="kanan row-actions">
            <a class="btn btn-sm btn-utama" href="hasil_ujian.php?id=<?= (int) $r['id'] ?>">Analisis</a>
            <a class="btn btn-sm" href="cetak_hasil.php?id=<?= (int) $r['id'] ?>" target="_blank">Cetak</a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$daftar): ?>
        <tr><td colspan="10" class="kosong"><b>Belum ada ujian.</b>Buat ujian lebih dahulu pada menu Ujian &amp; Soal.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php page_bottom();
