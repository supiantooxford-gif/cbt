<?php
/**
 * BANK SOAL — kumpulan seluruh soal yang pernah dibuat pada sekolah ini.
 *
 * Permintaan pemilik: "Pada akun guru tambahkan menu bank soal. (soal yang dibuat akan otomatis
 * menjadi bank soal yang bisa di download (dalam bentuk word) dan dicetak rapi yang dilengkapi
 * dengan kunci jawaban."
 *
 * Setiap soal yang disimpan pada menu Ujian & Soal OTOMATIS menjadi anggota bank soal
 * (tidak ada tabel baru — bank soal membaca tabel `soal`).
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

$u = require_sekolah();
if (!is_guru() && !is_operator() && !is_super()) {
    tolak403('Halaman ini untuk akun Guru atau pengelola sekolah.');
}

$guruSaya = (int) (guru_saya()['id'] ?? 0);
$fMapel = (int) ($_GET['mapel'] ?? 0);
$fUjian = (int) ($_GET['ujian'] ?? 0);
$fJenis = teks($_GET['jenis'] ?? '', 12);
$fGuru = (int) ($_GET['guru'] ?? 0);
$fGambar = teks($_GET['gambar'] ?? '', 6);
$cari = teks($_GET['cari'] ?? '', 80);

$sql = "SELECT s.*, uj.judul AS ujian, uj.id AS ujian_id, uj.status AS ujian_status,
        m.nama AS mapel, g.nama AS guru
    FROM soal s
    JOIN ujian uj ON uj.id = s.ujian_id
    LEFT JOIN mapel m ON m.id = uj.mapel_id
    LEFT JOIN guru g ON g.id = uj.guru_id
    WHERE s.sekolah_id = ?";
$p = [sekolah_id()];
if ($fMapel > 0) { $sql .= ' AND uj.mapel_id = ?'; $p[] = $fMapel; }
if ($fUjian > 0) { $sql .= ' AND s.ujian_id = ?'; $p[] = $fUjian; }
if ($fJenis !== '' && array_key_exists($fJenis, JENIS_SOAL)) { $sql .= ' AND s.jenis = ?'; $p[] = $fJenis; }
if ($fGuru > 0) { $sql .= ' AND uj.guru_id = ?'; $p[] = $fGuru; }
if ($fGambar === 'ada') { $sql .= ' AND s.gambar IS NOT NULL AND s.gambar <> ""'; }
if ($fGambar === 'tanpa') { $sql .= ' AND (s.gambar IS NULL OR s.gambar = "")'; }
if ($cari !== '') {
    $sql .= ' AND (s.pertanyaan LIKE ? OR s.pembahasan LIKE ?)';
    $p[] = '%' . $cari . '%';
    $p[] = '%' . $cari . '%';
}
$sql .= ' ORDER BY uj.id DESC, s.urutan, s.id';
$daftar = array_map('soal_lengkap', all($sql, $p));

$jumlahGambar = 0;
$perJenis = [];
foreach ($daftar as $s) {
    if (soal_gambar($s) !== '') { $jumlahGambar++; }
    $perJenis[$s['jenis']] = ($perJenis[$s['jenis']] ?? 0) + 1;
}
$ujianList = all('SELECT uj.id, uj.judul, m.nama AS mapel FROM ujian uj
    LEFT JOIN mapel m ON m.id = uj.mapel_id WHERE uj.sekolah_id = ? ORDER BY uj.id DESC', [sekolah_id()]);

/** Tautan saringan yang sedang aktif (dipakai tombol unduh & cetak). */
$qSaring = array_filter([
    'mapel' => $fMapel, 'ujian' => $fUjian, 'jenis' => $fJenis, 'guru' => $fGuru,
    'gambar' => $fGambar, 'cari' => $cari,
]);

page_top('Bank Soal');
?>
<div class="grid g4">
  <?= kartu_stat('Soal di Bank', (string) count($daftar), 'sesuai saringan', 'buku') ?>
  <?= kartu_stat('Soal Bergambar', (string) $jumlahGambar, 'punya gambar', 'impor') ?>
  <?= kartu_stat('Ujian Sumber', (string) count(array_unique(array_map(fn($s) => $s['ujian_id'], $daftar))), 'paket soal', 'ujian') ?>
  <?= kartu_stat('Jenis Soal', (string) count($perJenis), 'variasi bentuk', 'koreksi') ?>
</div>

<div class="alert alert-ok">
  <b>Setiap soal yang Anda buat otomatis masuk ke bank soal ini.</b> Bank soal dapat
  <b>diunduh dalam bentuk Word (.docx)</b> maupun <b>dicetak rapi lengkap dengan kunci jawaban</b> —
  pilih saringan di bawah untuk menentukan soal yang ikut.
</div>

<div class="card">
  <div class="card-head">
    <h3>Kumpulan Soal</h3>
    <div class="sub"><?= count($daftar) ?> soal ditampilkan</div>
    <div class="kanan">
      <a class="btn btn-sm btn-utama" href="bank_soal_word.php?<?= e(http_build_query($qSaring)) ?>">Unduh Word (.docx)</a>
      <a class="btn btn-sm" href="cetak_bank_soal.php?<?= e(http_build_query($qSaring)) ?>" target="_blank">Cetak Rapi</a>
    </div>
  </div>
  <div class="card-body" style="padding-bottom:0">
    <form method="get" class="form-grid f4" style="align-items:end">
      <div class="field"><label>Cari Pertanyaan / Pembahasan</label>
        <input type="text" name="cari" value="<?= e($cari) ?>"></div>
      <div class="field"><label>Mata Pelajaran</label>
        <select name="mapel"><option value="0">- semua -</option>
          <?php foreach (mapel_list() as $m): ?>
            <option value="<?= (int) $m['id'] ?>"<?= $fMapel === (int) $m['id'] ? ' selected' : '' ?>><?= e($m['nama']) ?></option>
          <?php endforeach; ?></select></div>
      <div class="field"><label>Ujian Sumber</label>
        <select name="ujian"><option value="0">- semua -</option>
          <?php foreach ($ujianList as $uj): ?>
            <option value="<?= (int) $uj['id'] ?>"<?= $fUjian === (int) $uj['id'] ? ' selected' : '' ?>>
              <?= e($uj['judul']) ?><?= $uj['mapel'] ? ' (' . e((string) $uj['mapel']) . ')' : '' ?></option>
          <?php endforeach; ?></select></div>
      <div class="field"><label>Jenis Soal</label>
        <select name="jenis"><option value="">- semua -</option>
          <?php foreach (JENIS_SOAL as $k => $v): ?>
            <option value="<?= e($k) ?>"<?= $fJenis === $k ? ' selected' : '' ?>><?= e($v) ?></option>
          <?php endforeach; ?></select></div>
      <div class="field"><label>Guru Penyusun</label>
        <select name="guru"><option value="0">- semua -</option>
          <?php foreach (guru_list() as $g): ?>
            <option value="<?= (int) $g['id'] ?>"<?= $fGuru === (int) $g['id'] ? ' selected' : '' ?>><?= e($g['nama']) ?></option>
          <?php endforeach; ?></select></div>
      <div class="field"><label>Gambar</label>
        <select name="gambar"><option value="">- semua -</option>
          <option value="ada"<?= $fGambar === 'ada' ? ' selected' : '' ?>>Hanya bergambar</option>
          <option value="tanpa"<?= $fGambar === 'tanpa' ? ' selected' : '' ?>>Tanpa gambar</option></select></div>
      <div class="field"><label>&nbsp;</label>
        <button class="btn btn-sm btn-utama" type="submit">Terapkan</button>
        <a class="btn btn-sm" href="bank_soal.php">Reset</a></div>
    </form>
    <?php if (is_guru()): ?>
      <p class="mut" style="font-size:.8rem">Bank soal memuat seluruh soal sekolah ini
        (<?= (int) val('SELECT COUNT(*) FROM soal WHERE sekolah_id = ?', [sekolah_id()], 0) ?> soal),
        termasuk soal guru lain — supaya bisa dipakai bersama. Gunakan saringan
        <b>Guru Penyusun</b> untuk menampilkan soal Anda sendiri.</p>
    <?php endif; ?>
  </div>

  <?php if (!$daftar): ?>
    <div class="kosong"><b>Belum ada soal pada saringan ini.</b>
      Buat soal pada menu <b>Ujian &amp; Soal</b> — soal langsung otomatis masuk bank soal.</div>
  <?php else: ?>
    <?php foreach ($daftar as $i => $s): ?>
      <div class="card-body" style="border-top:1px solid var(--line)">
        <div class="soal-head" style="margin-bottom:8px">
          <div class="soal-no"><?= $i + 1 ?></div>
          <div>
            <div class="soal-jenis"><?= e(jenis_soal_label($s['jenis'])) ?>
              <?php if (soal_gambar($s) !== ''): ?><span class="pil pil-info">bergambar</span><?php endif; ?>
            </div>
            <div class="mut" style="font-size:.75rem">
              <?= e((string) ($s['mapel'] ?: '-')) ?> &middot; <?= e((string) $s['ujian']) ?>
              <?php if (trim((string) $s['guru']) !== ''): ?> &middot; <?= e((string) $s['guru']) ?><?php endif; ?>
            </div>
          </div>
          <div class="soal-skor">Skor <?= r1($s['skor']) ?></div>
        </div>
        <div class="soal-teks" style="margin-bottom:6px"><?= nl2br(e((string) $s['pertanyaan'])) ?></div>
        <?php if (soal_gambar($s) !== ''): ?>
          <img src="<?= e(soal_gambar($s)) ?>" alt="Gambar soal" class="bank-soal-gambar">
        <?php endif; ?>
        <?php if ($s['opsi_arr']): ?>
          <div class="opsi-list" style="margin-top:8px">
            <?php foreach ($s['opsi_arr'] as $oi => $ot): ?>
              <?php
              $kunci = false;
              if ($s['jenis'] === 'pg') { $kunci = ((int) $s['kunci'] === $oi); }
              if ($s['jenis'] === 'pgk') { $kunci = is_array($s['kunci']) && in_array($oi, array_map('intval', $s['kunci']), true); }
              if ($s['jenis'] === 'jodoh') {
                  $kunci = true;
                  $ot = $ot . '  &rarr;  ' . (string) (is_array($s['kunci']) ? ($s['kunci'][$oi] ?? '') : '');
              }
              ?>
              <div class="opsi<?= $kunci ? ' on' : '' ?>">
                <div class="opsi-huruf"><?= e(huruf_opsi($oi)) ?></div>
                <div><?= $ot ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php elseif ($s['jenis'] === 'bs'): ?>
          <?php foreach ($s['opsi_arr'] as $oi => $ot): ?>
            <div class="bs-baris"><div class="teks"><?= e((string) $ot) ?></div>
              <div><span class="pil pil-info"><?= e((string) (is_array($s['kunci']) ? ($s['kunci'][$oi] ?? '-') : '-')) ?></span></div></div>
          <?php endforeach; ?>
        <?php endif; ?>
        <p class="mut" style="font-size:.8rem;margin:8px 0 0">
          <b>Kunci Jawaban:</b>
          <?= e(implode(' | ', array_map(fn($x) => is_array($x) ? json_encode($x, JSON_UNESCAPED_UNICODE) : (string) $x,
              (array) (is_array($s['kunci']) ? $s['kunci'] : [$s['kunci']])))) ?>
          <?php if (trim((string) $s['pembahasan']) !== ''): ?>
            <br><b>Pembahasan:</b> <?= e((string) $s['pembahasan']) ?>
          <?php endif; ?>
        </p>
        <div class="row-actions mt8">
          <a class="btn btn-sm" href="soal_edit.php?ujian=<?= (int) $s['ujian_id'] ?>&id=<?= (int) $s['id'] ?>">Buka Soal</a>
          <a class="btn btn-sm" href="ujian_edit.php?id=<?= (int) $s['ujian_id'] ?>#daftarSoal">Ujian Sumber</a>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
<?php page_bottom();
