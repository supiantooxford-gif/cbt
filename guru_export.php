<?php
/**
 * Ekspor Data Guru — susunan kolom SENGAJA SAMA dengan ekspor aplikasi e-GURU,
 * sehingga berkasnya bisa diimpor balik ke e-GURU maupun ke aplikasi CBT lain.
 *
 * Kolom: Nama · Jenis Anggota · NIP/NUPTK · Jenis Kelamin · Jabatan · Mata Pelajaran ·
 *        Kelas Diajar · Tugas Tambahan · Telepon · Email · Akun (Nama Pengguna) · Status · Tanggal Daftar
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

require_operator();

$cari = teks($_GET['cari'] ?? '', 60);
$fMapel = (int) ($_GET['mapel'] ?? 0);
$fKelas = (int) ($_GET['kelas'] ?? 0);
$fAkun = teks($_GET['akun'] ?? '', 10);

$sql = "SELECT g.*, u.username,
        (SELECT GROUP_CONCAT(m.nama, '||') FROM guru_mapel gm JOIN mapel m ON m.id = gm.mapel_id
            WHERE gm.guru_id = g.id) AS mapel_nama,
        (SELECT GROUP_CONCAT(k.nama, '||') FROM guru_kelas gk JOIN kelas k ON k.id = gk.kelas_id
            WHERE gk.guru_id = g.id) AS kelas_nama
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
if ($fAkun === 'ada') { $sql .= ' AND u.id IS NOT NULL'; }
if ($fAkun === 'belum') { $sql .= ' AND u.id IS NULL'; }
$sql .= ' ORDER BY g.nama';
$rows = all($sql, $p);

$kolom = ['Nama', 'Jenis Anggota', 'NIP/NUPTK', 'Jenis Kelamin', 'Jabatan', 'Mata Pelajaran',
    'Kelas Diajar', 'Tugas Tambahan', 'Telepon', 'Email', 'Akun (Nama Pengguna)', 'Status', 'Tanggal Daftar'];

$data = [];
foreach ($rows as $g) {
    // e-GURU menggabungkan NIP dan NUPTK pada satu kolom: pakai NIP, cadangan NUPTK.
    $nip = trim((string) ($g['nip'] ?? ''));
    if ($nip === '' || $nip === '-') {
        $nip = trim((string) ($g['nuptk'] ?? ''));
    }
    $pip = fn($v) => trim((string) $v) !== '' ? explode('||', (string) $v) : [];
    $data[] = [
        (string) $g['nama'],
        'Guru',
        $nip === '-' ? '' : $nip,
        (string) ($g['jenis_kelamin'] ?? ''),
        (string) ($g['jabatan'] ?? ''),
        implode(', ', $pip($g['mapel_nama'] ?? '')),
        implode(', ', $pip($g['kelas_nama'] ?? '')),
        '', // Tugas Tambahan — belum dipakai aplikasi CBT; kolom tetap ada agar susunannya sama
        (string) ($g['telp'] ?? ''),
        (string) ($g['email'] ?? ''),
        trim((string) ($g['username'] ?? '')),
        (int) $g['aktif'] === 1 ? 'Aktif' : 'Nonaktif',
        ekspor_tgl($g['dibuat'] ?? ''),
    ];
}

// Unduhan CSV dilayani SERVER (tetap bekerja walau skrip peramban gagal).
if (($_GET['unduh'] ?? '') === 'csv') {
    $sekolah = current_sekolah();
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', (string) ($sekolah['nama'] ?? '')) ?? '', '-'));
    csv_kirim('data-guru' . ($slug !== '' ? '-' . $slug : '') . '-' . date('Y-m-d') . '.csv', $kolom, $data);
}

page_top('Ekspor Data Guru');
?>
<div class="card">
  <div class="card-head">
    <h3>Ekspor Data Guru</h3>
    <div class="sub"><?= count($data) ?> data siap diekspor</div>
    <div class="kanan"><a class="btn btn-sm" href="guru.php">Kembali ke Data Guru</a></div>
  </div>
  <div class="card-body">
    <div class="alert alert-ok">
      Susunan kolom &amp; berkasnya <b>sama dengan ekspor aplikasi e-GURU</b>, sehingga berkas hasil
      ekspor di sini dapat diimpor ke e-GURU, dan sebaliknya berkas ekspor e-GURU dapat diimpor
      kembali ke aplikasi ini (menu <b>Impor Excel/CSV</b>).
    </div>
    <p class="section-title">Langkah 1 — Siapkan data</p>
    <p class="mut">Semua guru sekolah ini diekspor. Gunakan saringan pada halaman Data Guru bila ingin
      mengekspor sebagian saja, lalu tekan tombol Ekspor di halaman itu.</p>
    <div>
      <?php if ($cari !== ''): ?><span class="field-chip">Cari: <?= e($cari) ?></span><?php endif; ?>
      <?php if ($fMapel > 0): ?><span class="field-chip">Mapel: <?= e(mapel_nama($fMapel)) ?></span><?php endif; ?>
      <?php if ($fKelas > 0): ?><span class="field-chip">Kelas: <?= e(kelas_nama($fKelas)) ?></span><?php endif; ?>
      <?php if ($fAkun !== ''): ?><span class="field-chip">Akun: <?= $fAkun === 'ada' ? 'sudah punya' : 'belum punya' ?></span><?php endif; ?>
    </div>

    <div class="divider"></div>
    <p class="section-title">Langkah 2 — Unduh berkas</p>
    <div class="row-actions">
      <button class="btn btn-utama" type="button" id="btnXlsx">Unduh Excel (.xlsx)</button>
      <a class="btn" href="guru_export.php?<?= e(http_build_query(array_filter([
          'unduh' => 'csv', 'cari' => $cari, 'mapel' => $fMapel, 'kelas' => $fKelas, 'akun' => $fAkun,
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
      <?php if (!$data): ?><tr><td colspan="<?= count($kolom) ?>" class="kosong">Belum ada data guru.</td></tr><?php endif; ?>
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
    XLSX.utils.book_append_sheet(wb, ws, 'Data Guru');
    XLSX.writeFile(wb, 'data-guru-<?= e(date('Y-m-d')) ?>.xlsx');
  });
})();
</script>
<?php page_bottom();
