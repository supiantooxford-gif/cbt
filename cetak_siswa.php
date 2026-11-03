<?php
/** Lembar cetak Data Siswa (A4 landscape). */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';
require_once __DIR__ . '/inc/cetak.php';

require_operator();

// Daftar sandi yang ditetapkan operator pada halaman Data Siswa ("Sandi Akun untuk Dibagikan").
// Kata sandi tersimpan sebagai hash sehingga tidak dapat dibaca dari basis data.
$sandiSiap = $_SESSION['sandi_siswa']['data'] ?? [];

$fKelas = (int) ($_GET['kelas'] ?? 0);
$cari = teks($_GET['cari'] ?? '', 60);
$fAkun = teks($_GET['akun'] ?? '', 10);

$sql = 'SELECT sw.*, k.nama AS kelas, u.username
    FROM siswa sw LEFT JOIN kelas k ON k.id = sw.kelas_id
    LEFT JOIN users u ON u.siswa_id = sw.id AND u.sekolah_id = sw.sekolah_id
    WHERE sw.sekolah_id = ?';
$p = [sekolah_id()];
if ($fKelas > 0) { $sql .= ' AND sw.kelas_id = ?'; $p[] = $fKelas; }
if ($cari !== '') {
    $sql .= ' AND (sw.nama LIKE ? OR sw.nis LIKE ? OR sw.nisn LIKE ?)';
    array_push($p, '%' . $cari . '%', '%' . $cari . '%', '%' . $cari . '%');
}
if ($fAkun === 'ada') { $sql .= ' AND u.id IS NOT NULL'; }
if ($fAkun === 'belum') { $sql .= ' AND u.id IS NULL'; }
$sql .= ' ORDER BY k.urutan, k.nama, sw.nama';
$daftar = all($sql, $p);
$wali = $fKelas > 0 ? (string) val('SELECT g.nama FROM kelas k LEFT JOIN guru g ON g.id = k.wali_id WHERE k.id = ?', [$fKelas], '') : '';
$adaSandiSandi = 0;
foreach ($daftar as $r) {
    if (($sandiSiap[(int) $r['id']] ?? '') !== '') { $adaSandiSandi++; }
}
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Cetak Data Siswa - <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="assets/style.css?v=<?= e(APP_VERSION) ?>">
</head>
<body>
<div class="sheet-toolbar">
  <button class="btn btn-utama" onclick="window.print()">Cetak / Simpan PDF</button>
  <a class="btn" href="siswa.php">Kembali ke Data Siswa</a>
</div>
<div class="sheet">
  <?php kop_cetak('Daftar Data Siswa', ['Tahun Ajaran ' . setting('tahun_ajaran', '-')]); ?>
  <div class="sheet-meta">
    <span>Jumlah siswa: <b><?= count($daftar) ?></b></span>
    <span>Laki-laki: <b><?= count(array_filter($daftar, fn($r) => $r['jenis_kelamin'] === 'L')) ?></b></span>
    <span>Perempuan: <b><?= count(array_filter($daftar, fn($r) => $r['jenis_kelamin'] === 'P')) ?></b></span>
    <span>Kelas: <b><?= e($fKelas > 0 ? kelas_nama($fKelas) : 'Semua kelas') ?></b></span>
    <span>Dicetak: <?= e(tanggal_cetak()) ?></span>
  </div>
  <table class="sheet-tbl">
    <thead><tr>
      <th style="width:32px">No</th><th style="width:70px">NIS</th><th style="width:80px">NISN</th>
      <th>Nama Siswa</th><th style="width:34px">JK</th><th>Kelas</th><th>Tempat, Tanggal Lahir</th>
      <th>Orang Tua / Wali</th><th>Kontak</th><th>Akun</th><th>Password</th>
      <th style="width:60px">Status</th>
    </tr></thead>
    <tbody>
    <?php foreach ($daftar as $i => $r): ?>
      <?php
      $ttl = trim((string) $r['tempat_lahir']);
      if (trim((string) $r['tanggal_lahir']) !== '') {
          $ttl .= ($ttl !== '' ? ', ' : '') . tanggal_id((string) $r['tanggal_lahir']);
      }
      ?>
      <tr>
        <td class="tengah"><?= $i + 1 ?></td>
        <td><?= e((string) ($r['nis'] ?: '-')) ?></td>
        <td><?= e((string) ($r['nisn'] ?: '-')) ?></td>
        <td><?= e($r['nama']) ?></td>
        <td class="tengah"><?= e((string) ($r['jenis_kelamin'] ?: '-')) ?></td>
        <td><?= e((string) ($r['kelas'] ?: '-')) ?></td>
        <td><?= e($ttl !== '' ? $ttl : '-') ?></td>
        <td><?php
          $kel = [];
          if (trim((string) $r['nama_ayah']) !== '') { $kel[] = 'Ayah: ' . $r['nama_ayah']; }
          if (trim((string) $r['nama_ibu']) !== '') { $kel[] = 'Ibu: ' . $r['nama_ibu']; }
          if (trim((string) $r['wali']) !== '') { $kel[] = 'Wali: ' . $r['wali']; }
          echo e($kel ? implode(', ', $kel) : '-');
        ?></td>
        <td><?= e((string) ($r['telp'] ?: '-')) ?></td>
        <td><?= e((string) ($r['username'] ?: '-')) ?></td>
        <td><?php $sd = $sandiSiap[(int) $r['id']] ?? ''; ?>
          <?= $sd !== '' ? '<b>' . e($sd) . '</b>' : '<span style="color:#777">-</span>' ?></td>
        <td class="tengah"><?php $st = (string) ($r['status'] ?? 'aktif');
          echo $st === 'lulus' ? 'Lulus' : ((int) $r['aktif'] === 1 ? 'Aktif' : 'Nonaktif'); ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$daftar): ?><tr><td colspan="12" class="tengah">Belum ada data siswa.</td></tr><?php endif; ?>
    </tbody>
    <?php if ($daftar): ?>
      <tfoot><tr>
        <td colspan="4">JUMLAH</td>
        <td class="tengah">L <?= count(array_filter($daftar, fn($r) => $r['jenis_kelamin'] === 'L')) ?></td>
        <td colspan="7"><?= count($daftar) ?> siswa
          (P <?= count(array_filter($daftar, fn($r) => $r['jenis_kelamin'] === 'P')) ?>)
          &middot; berakun <?= count(array_filter($daftar, fn($r) => trim((string) $r['username']) !== '')) ?>
          &middot; sandi tercetak <?= $adaSandiSandi ?></td>
      </tr></tfoot>
    <?php endif; ?>
  </table>
  <?php if ($adaSandiSandi === 0): ?>
    <p style="font-size:.75rem;margin-top:6px">
      <b>Catatan:</b> kolom <b>Password</b> masih bertanda "-" karena sandi siswa belum disiapkan.
      Kata sandi disimpan dalam bentuk teracak (hash) sehingga tidak dapat dibaca kembali — tetapkan
      sandi baru lewat halaman <b>Data Siswa → Sandi Akun untuk Dibagikan</b>, lalu cetak ulang.
    </p>
  <?php else: ?>
    <p style="font-size:.75rem;margin-top:6px">
      <b>Catatan:</b> kolom <b>Password</b> memuat sandi yang ditetapkan pada
      <?= e($_SESSION['sandi_siswa']['dibuat'] ?? '') !== '' ? e(waktu_id((string) ($_SESSION['sandi_siswa']['dibuat'] ?? ''))) : 'sesi ini' ?>.
      Simpan berkas ini dengan aman; sandi tidak dapat ditampilkan lagi setelah sesi berakhir.
    </p>
  <?php endif; ?>

  <div class="sheet-foot">
    <div class="sheet-sign">
      <div>Mengetahui,</div>
      <div>Kepala Sekolah/Madrasah</div>
      <div class="space"></div>
      <div class="nama"><?= e(setting('kepala', '................................')) ?></div>
      <?php if (setting('nip_kepala') !== ''): ?><div>NIP. <?= e(setting('nip_kepala')) ?></div><?php endif; ?>
    </div>
    <div class="sheet-sign">
      <div><?= e(setting('kecamatan') !== '' ? setting('kecamatan') . ', ' . tanggal_cetak() : tanggal_cetak()) ?></div>
      <div>Wali Kelas</div>
      <div class="space"></div>
      <div class="nama"><?= e($wali !== '' ? $wali : '................................') ?></div>
    </div>
  </div>
</div>
<script>if (location.search.indexOf('auto=1') >= 0) { window.print(); }</script>
</body>
</html>
