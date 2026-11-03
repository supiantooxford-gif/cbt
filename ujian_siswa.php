<?php
/** Ujian Saya (akun siswa) — hanya ujian yang sudah diaktifkan untuk kelasnya. */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

$u = require_siswa();
$sw = siswa_saya();
if (!$sw) {
    page_top('Ujian Saya');
    echo '<div class="alert alert-err">Akun Anda belum ditautkan ke data siswa. Hubungi Admin Operator sekolah.</div>';
    page_bottom();
    exit;
}

$berjalan = sesi_berjalan((int) $sw['id']);
if ($berjalan) {
    redirect('kerjakan.php?id=' . (int) $berjalan['ujian_id']);
}

$kelasId = (int) ($sw['kelas_id'] ?? 0);
$daftar = all("SELECT uj.*, m.nama AS mapel, g.nama AS guru,
        (SELECT COUNT(*) FROM soal s WHERE s.ujian_id = uj.id) AS jumlah_soal,
        (SELECT COALESCE(SUM(s.skor),0) FROM soal s WHERE s.ujian_id = uj.id) AS skor_maks,
        (SELECT su.id FROM sesi_ujian su WHERE su.ujian_id = uj.id AND su.siswa_id = ?) AS sesi_id,
        (SELECT su.status FROM sesi_ujian su WHERE su.ujian_id = uj.id AND su.siswa_id = ?) AS sesi_status,
        (SELECT su.nilai FROM sesi_ujian su WHERE su.ujian_id = uj.id AND su.siswa_id = ?) AS sesi_nilai
    FROM ujian uj LEFT JOIN mapel m ON m.id = uj.mapel_id LEFT JOIN guru g ON g.id = uj.guru_id
    WHERE uj.sekolah_id = ? AND uj.status = 'aktif'
      AND EXISTS (SELECT 1 FROM ujian_kelas uk WHERE uk.ujian_id = uj.id AND uk.kelas_id = ?)
    ORDER BY uj.id DESC", [(int) $sw['id'], (int) $sw['id'], (int) $sw['id'], sekolah_id(), $kelasId]);

page_top('Ujian Saya');
?>
<div class="card">
  <div class="card-head">
    <h3>Ujian yang Dapat Dikerjakan</h3>
    <div class="sub">Kelas <?= e(kelas_nama($kelasId)) ?> &middot; <?= e((string) $sw['nama']) ?></div>
  </div>
  <div class="card-body">
    <div class="alert alert-info">
      Sebelum menekan <b>Kerjakan</b>, pastikan koneksi internet stabil. Selama ujian berlangsung:
      layar masuk ke <b>mode layar penuh</b>, akun Anda <b>terkunci pada halaman ujian</b>, dan
      berpindah tab atau keluar dari layar penuh akan dicatat sebagai pelanggaran.
      Setelah <b><?= (int) UJIAN_MAKS_PELANGGARAN ?> pelanggaran</b>, jawaban Anda otomatis dikumpulkan.
    </div>
    <div class="table-wrap">
      <table class="tbl">
        <thead><tr><th>No</th><th>Ujian</th><th>Mata Pelajaran</th><th>Guru</th>
          <th class="center">Soal</th><th class="center">Durasi</th><th>Status Anda</th><th class="kanan">Aksi</th></tr></thead>
        <tbody>
        <?php foreach ($daftar as $i => $r): ?>
          <?php $jd = ujian_jadwal($r); ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td><b><?= e($r['judul']) ?></b><br><small class="mut"><?= e((string) $r['jenis_ujian']) ?>
              <?= (int) $r['acak_soal'] === 1 ? ' &middot; soal diacak' : '' ?></small>
              <?php if ($jd['mulai'] || $jd['selesai']): ?>
                <br><small class="mut">Jadwal:
                  <?= e($jd['mulai'] ? waktu_id((string) $jd['mulai']) : 'sekarang') ?> –
                  <?= e($jd['selesai'] ? waktu_id((string) $jd['selesai']) : 'selesai') ?></small>
              <?php endif; ?>
              <?php if (ujian_butuh_token($r)): ?>
                <br><small><span class="pil pil-warn">perlu token</span></small>
              <?php endif; ?></td>
            <td><?= e((string) ($r['mapel'] ?: '-')) ?></td>
            <td><small><?= e((string) ($r['guru'] ?: '-')) ?></small></td>
            <td class="center"><?= (int) $r['jumlah_soal'] ?></td>
            <td class="center"><?= (int) $r['durasi'] ?> menit</td>
            <td>
              <?php if (!$r['sesi_id']): ?><span class="pil pil-warn">Belum dikerjakan</span>
              <?php elseif ($r['sesi_status'] === 'mengerjakan'): ?><span class="pil pil-info">Sedang dikerjakan</span>
              <?php else: ?>
                <span class="pil pil-ok">Selesai</span>
                <?php if ((int) $r['tampilkan_hasil'] === 1): ?>
                  <b><?= r1($r['sesi_nilai'] ?? 0) ?></b>
                <?php endif; ?>
              <?php endif; ?>
            </td>
            <td class="kanan">
              <?php if (!$r['sesi_id']): ?>
                <?php if ($jd['keadaan'] === 'belum'): ?>
                  <span class="pil pil-warn">Dibuka <?= e($jd['mulai'] ? substr((string) $jd['mulai'], 11, 5) : '') ?></span>
                <?php elseif ($jd['keadaan'] === 'tutup'): ?>
                  <span class="pil pil-mut">Tertutup</span>
                <?php else: ?>
                  <a class="btn btn-sm btn-utama" href="kerjakan.php?id=<?= (int) $r['id'] ?>">
                    <?= ujian_butuh_token($r) ? 'Masukkan Token' : 'Kerjakan' ?></a>
                <?php endif; ?>
              <?php elseif ($r['sesi_status'] === 'mengerjakan'): ?>
                <a class="btn btn-sm btn-utama" href="kerjakan.php?id=<?= (int) $r['id'] ?>">Lanjutkan</a>
              <?php else: ?>
                <a class="btn btn-sm" href="selesai.php?sesi=<?= (int) $r['sesi_id'] ?>">Lihat Hasil</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$daftar): ?>
          <tr><td colspan="8" class="kosong"><b>Belum ada ujian yang aktif untuk kelas Anda.</b>
            Ujian akan muncul di sini setelah guru/operator mengaktifkannya.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php page_bottom();
