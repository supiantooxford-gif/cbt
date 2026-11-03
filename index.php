<?php
/** Dashboard (berbeda menurut peran). */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

$u = require_login();

if (is_siswa()) {
    redirect('ujian_siswa.php');
}

// ---------------------------------------------------------------------------------------------
// KEMBALI KE HALAMAN UTAMA SUPER ADMIN (permintaan pemilik).
// Super Admin yang sedang berada dalam konteks satu sekolah dapat melepas konteks itu lewat
// tombol pada bilah atas / banner (`index.php?keluar=1`) lalu dibawa ke halaman utamanya
// (Ringkasan Lintas Sekolah). Hanya berlaku untuk Super Admin — peran lain tidak terpengaruh.
if (is_super() && isset($_GET['keluar'])) {
    set_sekolah_context(null);
    flash('Kembali ke halaman utama Super Admin (lintas sekolah).', 'ok');
    redirect('index.php');
}

$s = current_sekolah();

/* =================================================== SUPER ADMIN (lintas sekolah) */
if (is_super() && !$s) {
    $baris = all("SELECT sc.*,
            (SELECT COUNT(*) FROM kelas k WHERE k.sekolah_id = sc.id) AS kelas,
            (SELECT COUNT(*) FROM guru g WHERE g.sekolah_id = sc.id AND g.aktif = 1) AS guru,
            (SELECT COUNT(*) FROM siswa sw WHERE sw.sekolah_id = sc.id AND sw.aktif = 1) AS siswa,
            (SELECT COUNT(*) FROM ujian uj WHERE uj.sekolah_id = sc.id) AS ujian,
            (SELECT COUNT(*) FROM sesi_ujian su WHERE su.sekolah_id = sc.id AND su.status IN ('selesai','dikumpulkan')) AS peserta
        FROM sekolah sc ORDER BY sc.aktif DESC, sc.nama");
    $tot = ['sekolah' => count($baris), 'kelas' => 0, 'guru' => 0, 'siswa' => 0, 'ujian' => 0, 'peserta' => 0];
    $nonaktif = 0;
    $terkunci = 0;
    foreach ($baris as $b) {
        $tot['kelas'] += (int) $b['kelas'];
        $tot['guru'] += (int) $b['guru'];
        $tot['siswa'] += (int) $b['siswa'];
        $tot['ujian'] += (int) $b['ujian'];
        $tot['peserta'] += (int) $b['peserta'];
        if ((int) $b['aktif'] !== 1) { $nonaktif++; }
        if (aktivasi_terkunci($b)) { $terkunci++; }
    }

    page_top('Ringkasan Lintas Sekolah');
    ?>
    <div class="grid g4">
      <?= kartu_stat('Sekolah Terdaftar', (string) $tot['sekolah'], $nonaktif . ' nonaktif / ' . $terkunci . ' terkunci', 'sekolah') ?>
      <?= kartu_stat('Guru', number_format($tot['guru'], 0, ',', '.'), 'seluruh sekolah', 'guru') ?>
      <?= kartu_stat('Siswa', number_format($tot['siswa'], 0, ',', '.'), 'seluruh sekolah', 'siswa') ?>
      <?= kartu_stat('Ujian Dibuat', number_format($tot['ujian'], 0, ',', '.'), $tot['peserta'] . ' sesi selesai', 'ujian') ?>
    </div>

    <div class="card">
      <div class="card-head">
        <h3>Daftar Sekolah/Madrasah</h3>
        <div class="sub">Total: <?= number_format($tot['kelas'], 0, ',', '.') ?> kelas,
          <?= number_format($tot['guru'], 0, ',', '.') ?> guru, <?= number_format($tot['siswa'], 0, ',', '.') ?> siswa</div>
        <div class="kanan">
          <a class="btn btn-sm btn-utama" href="sekolah.php">Kelola Sekolah</a>
        </div>
      </div>
      <div class="table-wrap">
        <table class="tbl">
          <thead><tr>
            <th>No</th><th>Sekolah/Madrasah</th><th>Jenjang</th><th class="center">Kelas</th>
            <th class="center">Guru</th><th class="center">Siswa</th><th class="center">Ujian</th>
            <th>Status</th><th class="kanan">Aksi</th>
          </tr></thead>
          <tbody>
          <?php foreach ($baris as $i => $b): ?>
            <tr>
              <td><?= $i + 1 ?></td>
              <td><b><?= e($b['nama']) ?></b><br><small class="mut"><?= e((string) ($b['kecamatan'] ?: '-')) ?></small></td>
              <td><?= e(jenjang_label((string) $b['jenjang'])) ?></td>
              <td class="center"><?= (int) $b['kelas'] ?></td>
              <td class="center"><?= (int) $b['guru'] ?></td>
              <td class="center"><?= (int) $b['siswa'] ?></td>
              <td class="center"><?= (int) $b['ujian'] ?></td>
              <td>
                <?php if ((int) $b['aktif'] !== 1): ?><span class="pil pil-mut">Nonaktif</span>
                <?php elseif (aktivasi_terkunci($b)): ?><span class="pil pil-err">Terkunci</span>
                <?php else: ?><span class="pil pil-ok">Aktif</span><?php endif; ?>
              </td>
              <td class="kanan"><a class="btn btn-sm" href="sekolah.php?masuk=<?= (int) $b['id'] ?>">Masuk</a></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$baris): ?>
            <tr><td colspan="9" class="kosong">Belum ada sekolah yang terdaftar.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php
    page_bottom();
    exit;
}

/* =================================================== KONTEKS SEKOLAH */
if (!$s) {
    redirect('sekolah.php');
}

$sid = (int) $s['id'];
$st = aktivasi_status($s);
$kelasN = (int) val('SELECT COUNT(*) FROM kelas WHERE sekolah_id = ?', [$sid], 0);
$guruN = (int) val('SELECT COUNT(*) FROM guru WHERE sekolah_id = ? AND aktif = 1', [$sid], 0);
$siswaN = (int) val('SELECT COUNT(*) FROM siswa WHERE sekolah_id = ? AND aktif = 1', [$sid], 0);
$mapelN = (int) val('SELECT COUNT(*) FROM mapel WHERE sekolah_id = ?', [$sid], 0);
$ujianN = (int) val('SELECT COUNT(*) FROM ujian WHERE sekolah_id = ?', [$sid], 0);
$aktifN = (int) val('SELECT COUNT(*) FROM ujian WHERE sekolah_id = ? AND status = ?', [$sid, 'aktif'], 0);
$sesiN = (int) val("SELECT COUNT(*) FROM sesi_ujian WHERE sekolah_id = ? AND status IN ('selesai','dikumpulkan')", [$sid], 0);
$perluKoreksi = (int) val("SELECT COUNT(*) FROM sesi_ujian WHERE sekolah_id = ? AND perlu_koreksi = 1 AND status IN ('selesai','dikumpulkan')", [$sid], 0);

page_top('Dashboard');
?>
<div class="grid g4">
  <?= kartu_stat('Kelas', (string) $kelasN, 'kelas terdaftar', 'kelas') ?>
  <?= kartu_stat('Guru', (string) $guruN, 'akun guru aktif', 'guru') ?>
  <?= kartu_stat('Siswa', (string) $siswaN, 'siswa aktif', 'siswa') ?>
  <?= kartu_stat('Mata Pelajaran', (string) $mapelN, 'mata pelajaran', 'mapel') ?>
</div>
<div class="grid g4">
  <?= kartu_stat('Ujian', (string) $ujianN, $aktifN . ' sedang aktif', 'ujian') ?>
  <?= kartu_stat('Sesi Selesai', (string) $sesiN, 'pengerjaan terkumpul', 'hasil') ?>
  <?= kartu_stat('Perlu Koreksi', (string) $perluKoreksi, 'jawaban uraian menunggu', 'koreksi') ?>
  <?= kartu_masa_aktif($st) ?>
</div>

<?php if ($kelasN === 0 || $guruN === 0 || $siswaN === 0): ?>
  <div class="card">
    <div class="card-head"><h3>Langkah Awal Menyiapkan Ujian</h3></div>
    <div class="card-body">
      <ul class="poin-list">
        <li><b>Identitas &amp; Pengaturan</b> — isi nama lembaga, tahun ajaran, semester, dan KKM.
          <?php if (trim((string) $s['nama']) === '' || trim((string) $s['tahun_ajaran']) === ''): ?>
            <span class="pil pil-warn">belum lengkap</span><?php endif; ?></li>
        <li><b>Kelas &amp; Mata Pelajaran</b> — siapkan daftar kelas dan mata pelajaran
          (<?= $kelasN ?> kelas, <?= $mapelN ?> mapel tercatat).</li>
        <li><b>Data Guru &amp; Data Siswa</b> — isi manual, impor Excel/CSV, atau cetak
          (<?= $guruN ?> guru, <?= $siswaN ?> siswa tercatat).</li>
        <li><b>Akun Pengguna</b> — buat akun guru dan siswa agar mereka dapat masuk.</li>
      </ul>
      <div class="form-kaki">
        <a class="btn btn-utama" href="pengaturan.php">Isi Identitas &amp; Pengaturan</a>
        <a class="btn" href="kelas.php">Kelola Kelas</a>
        <a class="btn" href="mapel.php">Kelola Mata Pelajaran</a>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php
$ujianTerakhir = all("SELECT uj.*, m.nama AS mapel, g.nama AS guru,
        (SELECT COUNT(*) FROM soal s WHERE s.ujian_id = uj.id) AS jumlah_soal,
        (SELECT COUNT(*) FROM sesi_ujian su WHERE su.ujian_id = uj.id AND su.status IN ('selesai','dikumpulkan')) AS peserta
    FROM ujian uj LEFT JOIN mapel m ON m.id = uj.mapel_id LEFT JOIN guru g ON g.id = uj.guru_id
    WHERE uj.sekolah_id = ? ORDER BY uj.id DESC LIMIT 8", [$sid]);
?>
<div class="card">
  <div class="card-head">
    <h3>Ujian Terbaru</h3>
    <div class="kanan">
      <?php if (is_guru()): ?>
        <a class="btn btn-sm btn-utama" href="ujian_edit.php">Buat Ujian Baru</a>
        <a class="btn btn-sm" href="bank_soal.php">Bank Soal</a>
      <?php endif; ?>
      <a class="btn btn-sm" href="hasil.php">Hasil &amp; Analisis</a>
    </div>
  </div>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>No</th><th>Judul Ujian</th><th>Mata Pelajaran</th><th>Guru</th>
        <th class="center">Soal</th><th class="center">Peserta</th><th>Status</th><th class="kanan">Aksi</th></tr></thead>
      <tbody>
      <?php foreach ($ujianTerakhir as $i => $r): ?>
        <tr>
          <td><?= $i + 1 ?></td>
          <td><b><?= e($r['judul']) ?></b><br><small class="mut"><?= e((string) $r['jenis_ujian']) ?> &middot; <?= (int) $r['durasi'] ?> menit</small></td>
          <td><?= e((string) ($r['mapel'] ?: '-')) ?></td>
          <td><?= e((string) ($r['guru'] ?: '-')) ?></td>
          <td class="center"><?= (int) $r['jumlah_soal'] ?></td>
          <td class="center"><?= (int) $r['peserta'] ?></td>
          <td>
            <?php if ($r['status'] === 'aktif'): ?><span class="pil pil-ok">Aktif</span>
            <?php elseif ($r['status'] === 'selesai'): ?><span class="pil pil-mut">Selesai</span>
            <?php else: ?><span class="pil pil-warn">Draf</span><?php endif; ?>
          </td>
          <td class="kanan">
            <?php if (is_guru()): ?>
              <a class="btn btn-sm" href="ujian_edit.php?id=<?= (int) $r['id'] ?>">Kelola</a>
            <?php endif; ?>
            <a class="btn btn-sm" href="hasil_ujian.php?id=<?= (int) $r['id'] ?>">Hasil</a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$ujianTerakhir): ?>
        <tr><td colspan="8" class="kosong">Belum ada ujian.
          <?php if (is_guru()): ?>Mulai dengan menekan <b>Buat Ujian Baru</b>.<?php else: ?>Ujian dibuat oleh akun Guru.<?php endif; ?>
        </td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php page_bottom();
