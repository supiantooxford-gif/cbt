<?php
/**
 * Ekspor Data Siswa — susunan kolom SENGAJA SAMA dengan ekspor aplikasi e-GURU,
 * sehingga berkasnya bisa diimpor balik ke e-GURU maupun ke aplikasi CBT lain.
 *
 * Kolom: Nama · Jenis Anggota · NIS · NISN · Jenis Kelamin · Kelas · Tanggal Daftar ·
 *        Tanggal Lahir · Nama Wali · Telepon · Alamat · Status Siswa
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

require_operator();

$cari = teks($_GET['cari'] ?? '', 60);
$fKelas = (int) ($_GET['kelas'] ?? 0);
$fAkun = teks($_GET['akun'] ?? '', 10);
$fStatus = teks($_GET['status'] ?? '', 12);

$sql = 'SELECT sw.*, k.nama AS kelas, u.username
    FROM siswa sw LEFT JOIN kelas k ON k.id = sw.kelas_id
    LEFT JOIN users u ON u.siswa_id = sw.id AND u.sekolah_id = sw.sekolah_id
    WHERE sw.sekolah_id = ?';
$p = [sekolah_id()];
if ($cari !== '') {
    $sql .= ' AND (sw.nama LIKE ? OR sw.nis LIKE ? OR sw.nisn LIKE ?)';
    array_push($p, '%' . $cari . '%', '%' . $cari . '%', '%' . $cari . '%');
}
if ($fKelas > 0) { $sql .= ' AND sw.kelas_id = ?'; $p[] = $fKelas; }
if ($fAkun === 'ada') { $sql .= ' AND u.id IS NOT NULL'; }
if ($fAkun === 'belum') { $sql .= ' AND u.id IS NULL'; }
if (array_key_exists($fStatus, siswa_status_list())) { $sql .= ' AND sw.status = ?'; $p[] = $fStatus; }
$sql .= ' ORDER BY k.urutan, k.nama, sw.nama';
$rows = all($sql, $p);

$kolom = ['Nama', 'Jenis Anggota', 'NIS', 'NISN', 'Jenis Kelamin', 'Kelas', 'Tanggal Daftar',
    'Tanggal Lahir', 'Nama Wali', 'Telepon', 'Alamat', 'Status Siswa'];

$data = [];
foreach ($rows as $s) {
    $status = (int) $s['aktif'] !== 1
        ? ((string) $s['status'] === 'lulus' ? 'Lulus' : 'Nonaktif')
        : 'Aktif';
    $data[] = [
        (string) $s['nama'],
        'Siswa',
        (string) ($s['nis'] ?? ''),
        (string) ($s['nisn'] ?? ''),
        (string) ($s['jenis_kelamin'] ?? ''),
        (string) ($s['kelas'] ?? ''),
        ekspor_tgl($s['dibuat'] ?? ''),
        ekspor_tgl($s['tanggal_lahir'] ?? ''),
        (string) ($s['wali'] ?? ''),
        (string) ($s['telp'] ?? ''),
        (string) ($s['alamat'] ?? ''),
        $status,
    ];
}

if (($_GET['unduh'] ?? '') === 'csv') {
    $sekolah = current_sekolah();
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', (string) ($sekolah['nama'] ?? '')) ?? '', '-'));
    csv_kirim('data-siswa' . ($slug !== '' ? '-' . $slug : '') . '-' . date('Y-m-d') . '.csv', $kolom, $data);
}

page_top('Ekspor Data Siswa');
?>
<div class="card">
  <div class="card-head">
    <h3>Ekspor Data Siswa</h3>
    <div class="sub"><?= count($data) ?> data siap diekspor</div>
    <div class="kanan"><a class="btn btn-sm" href="siswa.php">Kembali ke Data Siswa</a></div>
  </div>
  <div class="card-body">
    <div class="alert alert-ok">
      Susunan kolom &amp; berkasnya <b>sama dengan ekspor aplikasi e-GURU</b>, sehingga berkas hasil
      ekspor di sini dapat diimpor ke aplikasi lain (mis. e-PERPUS), dan sebaliknya berkas ekspor
      e-GURU dapat diimpor kembali ke aplikasi ini (menu <b>Impor Excel/CSV</b>).
    </div>
    <p class="section-title">Langkah 1 — Siapkan data</p>
    <p class="mut">Seluruh siswa sekolah ini diekspor. Gunakan saringan pada halaman Data Siswa bila
      ingin mengekspor sebagian saja, lalu tekan tombol Ekspor di halaman itu.</p>
    <div>
      <?php if ($cari !== ''): ?><span class="field-chip">Cari: <?= e($cari) ?></span><?php endif; ?>
      <?php if ($fKelas > 0): ?><span class="field-chip">Kelas: <?= e(kelas_nama($fKelas)) ?></span><?php endif; ?>
      <?php if ($fAkun !== ''): ?><span class="field-chip">Akun: <?= $fAkun === 'ada' ? 'sudah punya' : 'belum punya' ?></span><?php endif; ?>
      <?php if ($fStatus !== ''): ?><span class="field-chip">Status: <?= e(siswa_status_list()[$fStatus] ?? $fStatus) ?></span><?php endif; ?>
    </div>

    <div class="divider"></div>
    <p class="section-title">Langkah 2 — Unduh berkas</p>
    <div class="row-actions">
      <button class="btn btn-utama" type="button" id="btnXlsx">Unduh Excel (.xlsx)</button>
      <a class="btn" href="siswa_export.php?<?= e(http_build_query(array_filter([
          'unduh' => 'csv', 'cari' => $cari, 'kelas' => $fKelas, 'akun' => $fAkun, 'status' => $fStatus,
      ]))) ?>">Unduh CSV</a>
    </div>
    <div class="alert alert-info mt16">Berkas Excel dibuat di peramban. Bila tombol Excel tidak
      merespons, gunakan <b>Unduh CSV</b> — isinya sama.</div>
  </div>
</div>

<div class="card">
  <div class="card-head"><h3>Pratinjau</h3><div class="sub">Kolom persis seperti berkas yang diunduh</div></div>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><?php foreach ($kolom as $k): ?><th><?= e($k) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php foreach (array_slice($data, 0, 25) as $r): ?>
        <tr><?php foreach ($r as $c): ?><td><small><?= e((string) $c) ?></small></td><?php endforeach; ?></tr>
      <?php endforeach; ?>
      <?php if (!$data): ?><tr><td colspan="<?= count($kolom) ?>" class="kosong">Belum ada data siswa.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if (count($data) > 25): ?>
    <div class="card-body mut">Menampilkan 25 baris pertama dari <?= count($data) ?> baris.</div>
  <?php endif; ?>
</div>

<script id="dataEkspor" type="application/json"><?= json_encode(array_merge([$kolom], $data),
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="assets/vendor/xlsx.full.min.js"></script>
<script>
(function () {
  var el = document.getElementById('btnXlsx');
  if (!el) { return; }
  el.addEventListener('click', function () {
    var isi = document.getElementById('dataEkspor');
    if (!isi || typeof XLSX === 'undefined') {
      window.alert('Pustaka Excel belum termuat. Gunakan tombol Unduh CSV.');
      return;
    }
    var baris = JSON.parse(isi.textContent);
    var ws = XLSX.utils.aoa_to_sheet(baris);
    var wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Data Siswa');
    XLSX.writeFile(wb, 'data-siswa-<?= e(date('Y-m-d')) ?>.xlsx');
  });
})();
</script>
<?php page_bottom();
