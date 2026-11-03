<?php
/**
 * CETAK RAPI BANK SOAL (A4 potrait) — lengkap dengan kunci jawaban dan gambar soal.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';
require_once __DIR__ . '/inc/cetak.php';
require_once __DIR__ . '/inc/bank_soal.php';

$u = require_sekolah();
if (!is_guru() && !is_operator() && !is_super()) {
    tolak403('Halaman ini untuk akun Guru atau pengelola sekolah.');
}

$f = bank_saring();
$soal = bank_soal_list($f);
$saring = bank_label_saring($f);
$guruNama = trim((string) (guru_saya()['nama'] ?? ($u['nama'] ?? '')));
$nGambar = 0;
foreach ($soal as $s) {
    if (soal_gambar($s) !== '') { $nGambar++; }
}
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Bank Soal - <?= e(setting('nama')) ?></title>
<link rel="stylesheet" href="assets/style.css?v=<?= e(APP_VERSION) ?>">
<style>
/* Gaya khusus lembar bank soal (A4 potrait, satu kolom) */
:root{--bs-line:#9a9a9a}
.bank-soal{background:#fff;color:#000;max-width:800px;margin:0 auto;padding:14mm}
.bank-soal h2{text-align:center;font-family:var(--serif);margin:0 0 2px;font-size:1.15rem}
.bank-soal .bs-info{text-align:center;font-size:.8rem;margin-bottom:14px}
.bs-soal{border-top:1px solid var(--bs-line);padding:10px 0 12px;break-inside:avoid;page-break-inside:avoid}
.bs-kepala{display:flex;gap:10px;font-size:.78rem;text-transform:uppercase;letter-spacing:.03em;margin-bottom:6px}
.bs-kepala b{font-size:.85rem}
.bs-jenis{margin-left:auto;color:#444}
.bs-teks{white-space:pre-wrap;margin-bottom:6px}
.bs-gambar{text-align:center;margin:6px 0}
.bs-gambar img{max-width:100%;max-height:60mm}
.bs-opsi{margin:2px 0 2px 20px;font-size:.9rem}
.bs-opsi.kunci{font-weight:700}
.bs-kunci{margin-top:6px;font-size:.85rem}
.bs-kunci b{letter-spacing:.02em}
.bs-pembahasan{font-size:.8rem;font-style:italic;color:#333;margin-top:2px}
table.bs-kunci-tbl{width:100%;border-collapse:collapse;font-size:.78rem;margin-top:8px}
table.bs-kunci-tbl th,table.bs-kunci-tbl td{border:1px solid var(--bs-line);padding:3px 6px;vertical-align:top}
table.bs-kunci-tbl th{background:#f0f0f0;text-align:center}
.bs-ttd{margin-top:22px;display:flex;justify-content:flex-end}
.bs-ttd div{text-align:center;font-size:.82rem}
.bs-ttd .ruang{height:52px}
@page{size:A4 portrait;margin:12mm}
@media print{
  .sheet-toolbar{display:none !important}
  html,body{min-height:0 !important;height:auto}
  .bank-soal{padding:0;max-width:none}
}
</style>
</head>
<body>
<div class="sheet-toolbar">
  <button class="btn btn-utama" onclick="window.print()">Cetak / Simpan PDF</button>
  <a class="btn" href="bank_soal.php">Kembali ke Bank Soal</a>
  <a class="btn" href="bank_soal_word.php?<?= e(http_build_query(array_filter($f))) ?>">Unduh Word</a>
</div>

<div class="bank-soal">
  <?php kop_cetak('', ['Tahun Ajaran ' . setting('tahun_ajaran', '-')]); ?>
  <h2>BANK SOAL</h2>
  <div class="bs-info">
    <?= e(setting('nama')) ?><?= setting('kecamatan') !== '' ? ' &middot; ' . e(setting('kecamatan')) : '' ?><br>
    Jumlah soal: <b><?= count($soal) ?></b>
    <?php if ($nGambar > 0): ?> &middot; soal bergambar: <b><?= $nGambar ?></b><?php endif; ?>
    &middot; Semester <?= e(setting('semester', '-')) ?>
    <?php if ($saring): ?><br><b>Saringan:</b> <?= e(implode(' · ', $saring)) ?><?php endif; ?>
    <?php if ($guruNama !== ''): ?><br>Penyusun: <?= e($guruNama) ?><?php endif; ?>
  </div>

  <?php if (!$soal): ?>
    <p class="tengah">Tidak ada soal pada saringan yang dipilih.</p>
  <?php endif; ?>

  <?php foreach ($soal as $i => $s): ?>
    <div class="bs-soal">
      <div class="bs-kepala">
        <b><?= $i + 1 ?>.</b>
        <span><?= e((string) ($s['mapel'] ?: '-')) ?></span>
        <?php if (trim((string) $s['guru']) !== ''): ?><span>&middot; <?= e((string) $s['guru']) ?></span><?php endif; ?>
        <span class="bs-jenis"><?= e(jenis_soal_label($s['jenis'])) ?> &middot; skor <?= r1($s['skor']) ?></span>
      </div>
      <div class="bs-teks"><?= nl2br(e((string) $s['pertanyaan'])) ?></div>
      <?php if (soal_gambar($s) !== ''): ?>
        <div class="bs-gambar"><img src="<?= e(soal_gambar($s)) ?>" alt="Gambar soal <?= $i + 1 ?>"></div>
      <?php endif; ?>

      <?php if ($s['opsi_arr']): ?>
        <?php foreach ($s['opsi_arr'] as $oi => $teks): ?>
          <?php
          $kunci = false;
          if ($s['jenis'] === 'pg') { $kunci = ((int) $s['kunci'] === $oi); }
          if ($s['jenis'] === 'pgk') { $kunci = is_array($s['kunci']) && in_array($oi, array_map('intval', $s['kunci']), true); }
          $label = huruf_opsi($oi) . '. ' . $teks;
          if ($s['jenis'] === 'bs') {
              $nilai = is_array($s['kunci']) ? atas((string) ($s['kunci'][$oi] ?? '-')) : '-';
              $label .= '   ( ' . $nilai . ' )';
          }
          if ($s['jenis'] === 'jodoh') {
              $label .= '  ..............';
          }
          ?>
          <div class="bs-opsi<?= $kunci ? ' kunci' : '' ?>"><?= e((string) $label) ?></div>
        <?php endforeach; ?>
      <?php endif; ?>

      <div class="bs-kunci"><b>Kunci Jawaban:</b> <?= e(bank_kunci_teks($s)) ?></div>
      <?php if (trim((string) $s['pembahasan']) !== ''): ?>
        <div class="bs-pembahasan">Pembahasan: <?= nl2br(e((string) $s['pembahasan'])) ?></div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <?php if ($soal): ?>
    <div class="bs-soal">
      <h2 style="font-size:1rem">RINGKASAN KUNCI JAWABAN</h2>
      <table class="bs-kunci-tbl">
        <thead><tr><th style="width:44px">No</th><th>Kunci Jawaban</th></tr></thead>
        <tbody>
        <?php foreach ($soal as $i => $s): ?>
          <tr><td class="tengah"><?= $i + 1 ?></td><td><?= e(bank_kunci_teks($s)) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="bs-ttd">
      <div>
        <div><?= e(setting('kecamatan') !== '' ? setting('kecamatan') . ', ' . tanggal_cetak() : tanggal_cetak()) ?></div>
        <div>Guru Penyusun</div>
        <div class="ruang"></div>
        <div><b><?= e($guruNama !== '' ? $guruNama : '................................') ?></b></div>
      </div>
    </div>
  <?php endif; ?>
</div>
<script>if (location.search.indexOf('auto=1') >= 0) { window.print(); }</script>
</body>
</html>
