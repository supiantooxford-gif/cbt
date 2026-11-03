<?php
/**
 * CETAK KARTU UJIAN siswa (A4 potrait).
 *
 * Isi kartu (permintaan pemilik): **Nama, Jenis Kelamin, Kelas, Akun, Password**.
 *
 * Kata sandi tidak dapat dibaca dari basis data (hash bcrypt), sehingga kartu ini memakai
 * daftar sandi yang DITETAPKAN operator pada menu Data Siswa → kartu "Sandi Akun untuk
 * Dibagikan" (`$_SESSION['sandi_siswa']`). Bila belum ada, halaman mengarahkan kembali
 * dengan pesan yang jelas (tidak pernah mencetak kartu dengan sandi kosong tanpa penjelasan).
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';
require_once __DIR__ . '/inc/cetak.php';

require_operator();

$fKelas = (int) ($_GET['kelas'] ?? 0);
$cari = teks($_GET['cari'] ?? '', 60);
$auto = ($_GET['auto'] ?? '') === '1';

$sandiSiap = $_SESSION['sandi_siswa']['data'] ?? [];
$waktuSiap = $_SESSION['sandi_siswa']['dibuat'] ?? '';

$sql = 'SELECT sw.*, k.nama AS kelas, u.username
    FROM siswa sw LEFT JOIN kelas k ON k.id = sw.kelas_id
    LEFT JOIN users u ON u.siswa_id = sw.id AND u.sekolah_id = sw.sekolah_id
    WHERE sw.sekolah_id = ? AND sw.aktif = 1 AND sw.status = ?';
$p = [sekolah_id(), 'aktif'];
if ($fKelas > 0) { $sql .= ' AND sw.kelas_id = ?'; $p[] = $fKelas; }
if ($cari !== '') {
    $sql .= ' AND (sw.nama LIKE ? OR sw.nis LIKE ?)';
    array_push($p, '%' . $cari . '%', '%' . $cari . '%');
}
$sql .= ' ORDER BY k.urutan, k.nama, sw.nama';
$daftar = all($sql, $p);

$adaSandi = 0;
foreach ($daftar as $r) {
    if (isset($sandiSiap[(int) $r['id']])) { $adaSandi++; }
}

if (!$daftar) {
    flash('Tidak ada siswa aktif pada saringan itu.', 'warn');
    redirect('siswa.php');
}
if ($adaSandi === 0) {
    flash('Kartu ujian memuat kolom Password. Tetapkan dahulu sandi siswa pada bagian '
        . '"Sandi Akun untuk Dibagikan" di halaman Data Siswa, lalu cetak kartunya.', 'warn');
    redirect('siswa.php#cardSandiSiswa');
}

/** Baris data satu kartu. */
function kartu_baris(string $label, string $nilai, bool $tebal = false): string
{
    return '<tr><th>' . e($label) . '</th><td' . ($tebal ? ' class="tebal"' : '') . '>'
        . e($nilai !== '' ? $nilai : '-') . '</td></tr>';
}
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Kartu Ujian - <?= e(setting('nama')) ?></title>
<link rel="stylesheet" href="assets/style.css?v=<?= e(APP_VERSION) ?>">
<style>
/* Kartu ujian: 2 kolom x 4 baris per lembar A4 potrait (kartu 90 x 62 mm). */
.ku-sheet{display:grid;grid-template-columns:repeat(2,90mm);grid-auto-rows:62mm;gap:3mm 4mm;
  justify-content:center;padding:11mm 6mm;background:#fff;color:#000;max-width:210mm;margin:0 auto}
.ku-kartu{border:0.5mm solid #0b4a33;border-radius:2.5mm;overflow:hidden;display:flex;flex-direction:column;
  background:#fff;position:relative}
.ku-kepala{display:flex;align-items:center;gap:2mm;padding:1.6mm 2.4mm;color:#fff;
  background:linear-gradient(120deg,#0b4a33,#12684a 60%,#1d8a5f);border-bottom:0.6mm solid #c9a227}
.ku-logo{width:9mm;height:9mm;object-fit:contain;background:#fff;border-radius:50%;padding:0.4mm}
.ku-judul{display:flex;flex-direction:column;line-height:1.12;min-width:0}
.ku-judul b{font-size:8.1pt;letter-spacing:.02em}
.ku-judul span{font-size:5.6pt;color:#f4e7c0}
.ku-isi{flex:1;padding:1.4mm 2.4mm;display:flex;flex-direction:column;gap:.6mm}
.ku-nama{font-size:9pt;font-weight:800;color:#0b4a33;line-height:1.15;
  border-bottom:.25mm solid #e2d6a8;padding-bottom:.5mm;margin-bottom:.4mm;word-break:break-word}
table.ku-tbl{width:100%;border-collapse:collapse;font-size:6.9pt}
table.ku-tbl th{text-align:left;font-weight:600;color:#4a5f56;width:23mm;padding:.25mm 0;
  vertical-align:top;white-space:nowrap}
table.ku-tbl td{padding:.25mm 0;vertical-align:top;font-weight:600;color:#12271f}
table.ku-tbl td.tebal{font-weight:800}
.ku-sandi{font-family:ui-monospace,Menlo,Consolas,monospace;letter-spacing:.06em;font-weight:800;
  background:#f8f1da;border:.25mm solid #c9a227;border-radius:1.2mm;padding:0 .8mm}
.ku-kaki{font-size:5.3pt;color:#5c7369;padding:.8mm 2.4mm 1.2mm;border-top:.25mm dashed #d9e9e1}
.ku-info-cetak{max-width:210mm;margin:6mm auto 0;font-size:8pt;color:#333;background:#f8f1da;
  border:.3mm solid #c9a227;border-radius:2mm;padding:3mm 4mm}
@page{size:A4 portrait;margin:0}
@media print{
  .sheet-toolbar,.kaki,.sidebar,.topbar{display:none !important}
  html,body{min-height:0 !important;height:auto;margin:0}
  .ku-info-cetak{display:none !important}
  .ku-sheet{padding:8mm 6mm;gap:2mm 3mm}
  .ku-kartu{print-color-adjust:exact;-webkit-print-color-adjust:exact}
}
</style>
</head>
<body>
<div class="sheet-toolbar">
  <button class="btn btn-utama" onclick="window.print()">Cetak / Simpan PDF</button>
  <a class="btn" href="siswa.php#cardSandiSiswa">Kembali ke Data Siswa</a>
  <a class="btn" href="cetak_siswa.php?<?= e(http_build_query(array_filter(['kelas' => $fKelas, 'cari' => $cari]))) ?>" target="_blank">Cetak Data Siswa</a>
</div>

<div class="ku-info-cetak">
  <b><?= count($daftar) ?> kartu ujian</b> dari <?= count($daftar) ?> siswa aktif
  <?= $fKelas > 0 ? 'kelas ' . e(kelas_nama($fKelas)) : '(semua kelas)' ?>.
  Sandi tercetak untuk <b><?= $adaSandi ?></b> siswa
  <?php if ($adaSandi < count($daftar)): ?>
    — <?= count($daftar) - $adaSandi ?> siswa belum disiapkan sandinya (ditandai "-"). Tetapkan sandi
    pada halaman Data Siswa bagian "Sandi Akun untuk Dibagikan" bila ingin ikut tercetak.
  <?php endif; ?>.
  <?= $waktuSiap !== '' ? 'Sandi ditetapkan ' . e(waktu_id($waktuSiap)) . '.' : '' ?>
  <b>Simpan/cetak berkas ini sebelum menutup sesi</b> — sandi tidak dapat ditampilkan lagi setelah itu.
</div>

<div class="ku-sheet">
  <?php foreach ($daftar as $r): $sid = (int) $r['id']; $sandi = $sandiSiap[$sid] ?? ''; ?>
    <div class="ku-kartu">
      <div class="ku-kepala">
        <?php if (trim((string) setting('logo')) !== ''): ?>
          <img class="ku-logo" src="<?= e(setting('logo')) ?>" alt="Logo">
        <?php endif; ?>
        <div class="ku-judul">
          <b>KARTU UJIAN</b>
          <span><?= e(setting('nama')) ?></span>
          <span>T.A. <?= e(setting('tahun_ajaran', '-')) ?> &middot; Semester <?= e(setting('semester', '-')) ?></span>
        </div>
      </div>
      <div class="ku-isi">
        <div class="ku-nama"><?= e((string) $r['nama']) ?></div>
        <table class="ku-tbl">
          <?= kartu_baris('Nama', (string) $r['nama']) ?>
          <?= kartu_baris('Jenis Kelamin', (string) ($r['jenis_kelamin'] === 'P' ? 'Perempuan' : ($r['jenis_kelamin'] === 'L' ? 'Laki-laki' : '-'))) ?>
          <?= kartu_baris('Kelas', (string) ($r['kelas'] ?: '-')) ?>
          <?= kartu_baris('Akun', (string) ($r['username'] ?: '-'), true) ?>
          <tr><th>Password</th><td>
            <?php if ($sandi !== ''): ?>
              <span class="ku-sandi"><?= e($sandi) ?></span>
            <?php else: ?>
              <span style="color:#b3261e">belum disiapkan</span>
            <?php endif; ?>
          </td></tr>
        </table>
      </div>
      <div class="ku-kaki">Bawa kartu ini saat mengerjakan ujian. Jangan berikan kata sandi kepada orang lain.</div>
    </div>
  <?php endforeach; ?>
</div>
<script>if (<?= $auto ? 'true' : 'false' ?>) { window.print(); }</script>
</body>
</html>
