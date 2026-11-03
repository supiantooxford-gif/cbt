<?php
/**
 * Lembar cetak laporan hasil ujian dan analisis butir soal (A4 landscape).
 * ?bagian=nilai    -> daftar nilai + statistik + ketuntasan per kelas
 * ?bagian=analisis -> analisis butir soal
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';
require_once __DIR__ . '/inc/cetak.php';

$u = require_sekolah();
if (!is_guru() && !is_operator() && !is_super()) {
    tolak403('Halaman ini untuk akun Guru atau pengelola sekolah.');
}

$id = (int) ($_GET['id'] ?? 0);
$bagian = teks($_GET['bagian'] ?? 'nilai', 12);
$fKelas = (int) ($_GET['kelas'] ?? 0);
$uj = ujian_by_id($id);
if (!$uj) {
    flash('Ujian tidak ditemukan.', 'err');
    redirect('hasil.php');
}
if (is_guru() && (int) $uj['guru_id'] !== (int) (guru_saya()['id'] ?? 0)) {
    tolak403('Ujian ini bukan milik Anda.');
}

$ringkas = ringkas_nilai($id, $fKelas > 0 ? $fKelas : null);
$analisis = analisis_butir($id);
$perKelas = all("SELECT k.nama AS kelas, COUNT(*) AS peserta, AVG(su.nilai) AS rata,
        MAX(su.nilai) AS maks, MIN(su.nilai) AS min,
        SUM(CASE WHEN su.nilai >= ? THEN 1 ELSE 0 END) AS tuntas
    FROM sesi_ujian su JOIN siswa sw ON sw.id = su.siswa_id LEFT JOIN kelas k ON k.id = sw.kelas_id
    WHERE su.ujian_id = ? AND su.status IN ('selesai','dikumpulkan')
    GROUP BY sw.kelas_id ORDER BY k.urutan, k.nama", [$ringkas['kkm'], $id]);
$judulDok = $bagian === 'analisis' ? 'Analisis Butir Soal'
    : ($bagian === 'tindak' ? 'Daftar Remedial & Pengayaan' : 'Laporan Hasil Ujian');
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<title><?= e($judulDok) ?> - <?= e((string) $uj['judul']) ?></title>
<link rel="stylesheet" href="assets/style.css?v=<?= e(APP_VERSION) ?>">
</head>
<body>
<div class="sheet-toolbar">
  <button class="btn btn-utama" onclick="window.print()">Cetak / Simpan PDF</button>
  <a class="btn" href="cetak_hasil.php?id=<?= (int) $id ?>&bagian=nilai">Bagian: Nilai</a>
  <a class="btn" href="cetak_hasil.php?id=<?= (int) $id ?>&bagian=analisis">Bagian: Analisis</a>
  <a class="btn" href="cetak_hasil.php?id=<?= (int) $id ?>&bagian=tindak">Bagian: Remedial &amp; Pengayaan</a>
  <a class="btn" href="hasil_ujian.php?id=<?= (int) $id ?>">Kembali</a>
</div>
<div class="sheet">
  <?php kop_cetak($judulDok, ['Tahun Ajaran ' . setting('tahun_ajaran', '-') . ' · Semester ' . setting('semester', '-')]); ?>
  <div class="sheet-meta">
    <span>Ujian: <b><?= e((string) $uj['judul']) ?></b></span>
    <span>Mata Pelajaran: <b><?= e(mapel_nama((int) ($uj['mapel_id'] ?? 0))) ?></b></span>
    <span>Jenis: <?= e((string) $uj['jenis_ujian']) ?></span>
    <span>Kelas sasaran: <?= e(implode(', ', array_map(fn($k) => kelas_nama((int) $k), ujian_kelas_ids($id))) ?: '-') ?></span>
    <span>KKTP: <b><?= r1($ringkas['kkm']) ?></b></span>
    <span>Dicetak: <?= e(tanggal_cetak()) ?></span>
  </div>

  <?php if ($bagian === 'analisis'): ?>
    <table class="sheet-tbl">
      <thead><tr>
        <th style="width:30px">No</th><th style="width:80px">Jenis</th><th>Butir Soal</th>
        <th style="width:44px">Skor</th><th style="width:70px">Benar</th><th style="width:60px">% Benar</th>
        <th style="width:70px">Kesukaran</th><th style="width:80px">Daya Beda</th>
        <th style="width:70px">Validitas</th><th style="width:200px">Distraktor</th>
      </tr></thead>
      <tbody>
      <?php foreach ($analisis['butir'] as $i => $b): ?>
        <tr>
          <td class="tengah"><?= $i + 1 ?></td>
          <td><?= e(jenis_soal_label($b['jenis'])) ?></td>
          <td><?= e(potong_teks($b['pertanyaan'], 180)) ?></td>
          <td class="tengah"><?= r1($b['skor']) ?></td>
          <td class="tengah"><?= (int) $b['benar'] ?>/<?= (int) $b['peserta'] ?></td>
          <td class="tengah"><?= persen($b['p_benar'] * 100, 0) ?></td>
          <td class="tengah"><?= e($b['kesukaran']) ?></td>
          <td class="tengah"><?= e($b['db_label']) ?> (<?= number_format($b['daya_beda'], 2, ',', '.') ?>)</td>
          <td class="tengah"><?= number_format($b['validitas'], 2, ',', '.') ?><br><small><?= e($b['valid_label']) ?></small></td>
          <td>
            <?php if ($b['distraktor']): ?>
              <?php foreach ($b['distraktor'] as $d): ?>
                <b><?= e($d['huruf']) ?></b><?= $d['kunci'] ? '(kunci)' : '' ?>:<?= (int) $d['jumlah'] ?>
                (<?= number_format($d['persen'], 0, ',', '.') ?>%)
                <small><?= $d['kunci'] ? '' : '[' . e($d['status']) . ']' ?></small>&nbsp;
              <?php endforeach; ?>
            <?php else: ?>-<?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$analisis['butir']): ?>
        <tr><td colspan="9" class="tengah">Belum ada data analisis (belum ada peserta yang menyelesaikan ujian).</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
    <p style="font-size:.78rem">Keterangan tingkat kesukaran: <b>Sukar</b> bila kurang dari 30% siswa menjawab benar,
      <b>Sedang</b> 30%-69%, <b>Mudah</b> 70% ke atas. Daya beda dihitung dengan membandingkan kelompok atas dan
      kelompok bawah (masing-masing <?= (int) ($analisis['kelompok'] ?? 0) ?> peserta):
      <b>Baik</b> ≥ 0,40, <b>Cukup</b> 0,20-0,39, <b>Perlu revisi</b> &lt; 0,20.
      <b>Validitas</b> = korelasi skor butir dengan skor total: <b>Sangat baik</b> ≥ 0,40,
      <b>Valid</b> 0,30-0,39, <b>Cukup</b> 0,20-0,29, <b>Tidak valid</b> &lt; 0,20.
      <b>Distraktor</b> dinilai berfungsi bila ada peserta yang memilihnya dan kelompok bawah
      memilihnya lebih banyak daripada kelompok atas (angka dalam kurung = jumlah pemilih tiap pilihan).</p>
  <?php elseif ($bagian === 'tindak'): ?>
    <table class="sheet-tbl">
      <thead><tr>
        <th style="width:30px">No</th><th style="width:85px">NIS</th><th>Nama Siswa</th><th style="width:80px">Kelas</th>
        <th style="width:60px">Nilai</th><th style="width:110px">Keterangan</th>
      </tr></thead>
      <tbody>
      <?php if ($ringkas['remedial']): ?>
        <tr><td colspan="6" style="background:#f3f3f3"><b>A. SISWA REMEDIAL (nilai di bawah KKTP <?= r1($ringkas['kkm']) ?>) —
          <?= count($ringkas['remedial']) ?> siswa</b></td></tr>
        <?php foreach ($ringkas['remedial'] as $i => $r): ?>
          <tr>
            <td class="tengah"><?= $i + 1 ?></td>
            <td><?= e((string) $r['nis']) ?></td>
            <td><?= e((string) $r['nama']) ?></td>
            <td><?= e((string) ($r['kelas'] ?: '-')) ?></td>
            <td class="tengah"><?= r1($r['nilai']) ?></td>
            <td>Remedial (kurang <?= r1($ringkas['kkm'] - (float) $r['nilai']) ?>)</td>
          </tr>
        <?php endforeach; ?>
      <?php else: ?>
        <tr><td colspan="6">Tidak ada siswa remedial — seluruh peserta mencapai KKTP.</td></tr>
      <?php endif; ?>
      <?php if ($ringkas['pengayaan']): ?>
        <tr><td colspan="6" style="background:#f3f3f3"><b>B. SISWA PENGAYAAN (nilai mencapai KKTP <?= r1($ringkas['kkm']) ?>) —
          <?= count($ringkas['pengayaan']) ?> siswa</b></td></tr>
        <?php foreach ($ringkas['pengayaan'] as $i => $r): ?>
          <tr>
            <td class="tengah"><?= $i + 1 ?></td>
            <td><?= e((string) $r['nis']) ?></td>
            <td><?= e((string) $r['nama']) ?></td>
            <td><?= e((string) ($r['kelas'] ?: '-')) ?></td>
            <td class="tengah"><?= r1($r['nilai']) ?></td>
            <td>Pengayaan (lebih <?= r1((float) $r['nilai'] - $ringkas['kkm']) ?>)</td>
          </tr>
        <?php endforeach; ?>
      <?php else: ?>
        <tr><td colspan="6">Belum ada siswa pengayaan.</td></tr>
      <?php endif; ?>
      </tbody>
      <tfoot><tr>
        <td colspan="4">JUMLAH</td>
        <td colspan="2"><?= count($ringkas['remedial']) ?> remedial &middot; <?= count($ringkas['pengayaan']) ?> pengayaan
          dari <?= (int) $ringkas['peserta'] ?> peserta</td>
      </tr></tfoot>
    </table>
    <p style="font-size:.78rem">Tindak lanjut: siswa pada daftar <b>remedial</b> mengikuti pembelajaran ulang
      dan penilaian kembali; siswa pada daftar <b>pengayaan</b> diberi kegiatan pengayaan atau tugas
      tantangan sesuai mata pelajaran <?= e(mapel_nama((int) ($uj['mapel_id'] ?? 0))) ?>.</p>
  <?php else: ?>
    <table class="sheet-tbl">
      <thead><tr>
        <th style="width:30px">No</th><th style="width:80px">NIS</th><th>Nama Siswa</th><th>Kelas</th>
        <th style="width:50px">Benar</th><th style="width:50px">Salah</th><th style="width:50px">Kosong</th>
        <th style="width:60px">Nilai</th><th style="width:80px">Ketuntasan</th><th style="width:90px">Pelanggaran</th>
      </tr></thead>
      <tbody>
      <?php foreach ($ringkas['baris'] as $i => $r): ?>
        <tr>
          <td class="tengah"><?= $i + 1 ?></td>
          <td><?= e((string) $r['nis']) ?></td>
          <td><?= e((string) $r['nama']) ?></td>
          <td><?= e((string) ($r['kelas'] ?: '-')) ?></td>
          <td class="tengah"><?= (int) $r['benar'] ?></td>
          <td class="tengah"><?= (int) $r['salah'] ?></td>
          <td class="tengah"><?= (int) $r['kosong'] ?></td>
          <td class="tengah"><b><?= r1($r['nilai']) ?></b></td>
          <td class="tengah"><?= (float) $r['nilai'] >= (float) $ringkas['kkm'] ? 'Tuntas' : 'Belum Tuntas' ?></td>
          <td class="tengah"><?= (int) ($r['pelanggaran'] ?? 0) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$ringkas['baris']): ?>
        <tr><td colspan="10" class="tengah">Belum ada peserta yang menyelesaikan ujian ini.</td></tr>
      <?php endif; ?>
      </tbody>
      <tfoot><tr>
        <td colspan="3">RINGKASAN</td>
        <td><?= (int) $ringkas['peserta'] ?> peserta</td>
        <td colspan="3">Rata-rata <b><?= r1($ringkas['rata']) ?></b></td>
        <td colspan="3">Tuntas <?= (int) $ringkas['tuntas'] ?> &middot; Belum <?= (int) $ringkas['belum'] ?>
          &middot; Terendah <?= r1($ringkas['terendah']) ?> &middot; Tertinggi <?= r1($ringkas['tertinggi']) ?></td>
      </tr></tfoot>
    </table>

    <p class="sheet-judul" style="font-size:.9rem;margin-top:14px">Ketuntasan per Kelas</p>
    <table class="sheet-tbl">
      <thead><tr><th style="width:40px">No</th><th>Kelas</th><th style="width:80px">Peserta</th>
        <th style="width:90px">Rata-rata</th><th style="width:80px">Terendah</th><th style="width:80px">Tertinggi</th>
        <th style="width:90px">Tuntas</th></tr></thead>
      <tbody>
      <?php foreach ($perKelas as $i => $r): ?>
        <tr>
          <td class="tengah"><?= $i + 1 ?></td>
          <td><?= e((string) ($r['kelas'] ?: '-')) ?></td>
          <td class="tengah"><?= (int) $r['peserta'] ?></td>
          <td class="tengah"><?= r1($r['rata']) ?></td>
          <td class="tengah"><?= r1($r['min']) ?></td>
          <td class="tengah"><?= r1($r['maks']) ?></td>
          <td class="tengah"><?= (int) $r['tuntas'] ?>/<?= (int) $r['peserta'] ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$perKelas): ?><tr><td colspan="7" class="tengah">Belum ada data.</td></tr><?php endif; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <div class="sheet-foot">
    <div class="sheet-sign"></div>
    <div class="sheet-sign">
      <div><?= e(setting('kecamatan') !== '' ? setting('kecamatan') . ', ' . tanggal_cetak() : tanggal_cetak()) ?></div>
      <div>Guru Mata Pelajaran</div>
      <div class="space"></div>
      <div class="nama"><?php
        $gn = (int) ($uj['guru_id'] ?? 0);
        echo e($gn > 0 ? (string) val('SELECT nama FROM guru WHERE id = ?', [$gn], '................................') : '................................');
      ?></div>
    </div>
  </div>
</div>
<script>if (location.search.indexOf('auto=1') >= 0) { window.print(); }</script>
</body>
</html>
