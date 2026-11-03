<?php
/** Lembar cetak Data Guru (A4 landscape). */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';
require_once __DIR__ . '/inc/cetak.php';

require_operator();

$cari = teks($_GET['cari'] ?? '', 60);
$fMapel = (int) ($_GET['mapel'] ?? 0);
$fKelas = (int) ($_GET['kelas'] ?? 0);

$sql = "SELECT g.*, u.username,
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
$sql .= ' ORDER BY g.nama';
$daftar = all($sql, $p);
$berakun = count(array_filter($daftar, fn($r) => trim((string) $r['username']) !== ''));
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Cetak Data Guru - <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="assets/style.css?v=<?= e(APP_VERSION) ?>">
</head>
<body>
<div class="sheet-toolbar">
  <button class="btn btn-utama" onclick="window.print()">Cetak / Simpan PDF</button>
  <a class="btn" href="guru.php">Kembali ke Data Guru</a>
</div>
<div class="sheet">
  <?php kop_cetak('Daftar Data Guru', ['Tahun Ajaran ' . setting('tahun_ajaran', '-')]); ?>
  <div class="sheet-meta">
    <span>Jumlah data: <b><?= count($daftar) ?></b> guru</span>
    <span>Sudah berakun: <b><?= $berakun ?></b></span>
    <span>Dicetak: <?= e(tanggal_cetak()) ?></span>
    <?php if ($cari !== ''): ?><span>Pencarian: <?= e($cari) ?></span><?php endif; ?>
    <?php if ($fMapel > 0): ?><span>Mapel: <?= e(mapel_nama($fMapel)) ?></span><?php endif; ?>
    <?php if ($fKelas > 0): ?><span>Kelas: <?= e(kelas_nama($fKelas)) ?></span><?php endif; ?>
  </div>
  <table class="sheet-tbl">
    <thead><tr>
      <th style="width:34px">No</th><th>Nama Guru</th><th>NIP</th><th>NUPTK</th><th style="width:34px">JK</th>
      <th>Jabatan</th><th>Mata Pelajaran</th><th>Kelas Diajar</th><th>Kontak</th><th>Akun</th><th>Status</th>
    </tr></thead>
    <tbody>
    <?php foreach ($daftar as $i => $r): ?>
      <tr>
        <td class="tengah"><?= $i + 1 ?></td>
        <td><?= e($r['nama']) ?></td>
        <td><?= e((string) ($r['nip'] ?: '-')) ?></td>
        <td><?= e((string) ($r['nuptk'] ?: '-')) ?></td>
        <td class="tengah"><?= e((string) ($r['jenis_kelamin'] ?: '-')) ?></td>
        <td><?= e((string) ($r['jabatan'] ?: '-')) ?></td>
        <td><?= e((string) ($r['mapel'] ?: '-')) ?></td>
        <td><?= e((string) ($r['kelas'] ?: '-')) ?></td>
        <td><?= e((string) ($r['telp'] ?: '-')) ?></td>
        <td><?= e((string) ($r['username'] ?: '-')) ?></td>
        <td><?= (int) $r['aktif'] === 1 ? 'Aktif' : 'Nonaktif' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$daftar): ?><tr><td colspan="11" class="tengah">Belum ada data guru.</td></tr><?php endif; ?>
    </tbody>
  </table>
  <div class="sheet-foot">
    <div class="sheet-sign"></div>
    <div class="sheet-sign">
      <div><?= e(setting('kecamatan') !== '' ? setting('kecamatan') . ', ' . tanggal_cetak() : tanggal_cetak()) ?></div>
      <div>Kepala Sekolah/Madrasah</div>
      <div class="space"></div>
      <div class="nama"><?= e(setting('kepala', '................................')) ?></div>
      <?php if (setting('nip_kepala') !== ''): ?><div>NIP. <?= e(setting('nip_kepala')) ?></div><?php endif; ?>
    </div>
  </div>
</div>
<script>if (location.search.indexOf('auto=1') >= 0) { window.print(); }</script>
</body>
</html>
