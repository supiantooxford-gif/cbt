<?php
/**
 * Penyelesaian ujian:
 * - POST (dari tombol Kumpulkan)  : menutup sesi lalu membalas JSON (fetch), atau mengalihkan ke
 *                                   halaman hasil bila permintaan datang dari formulir cadangan
 *                                   (`lanjut=1`, dipakai saat fetch menggantung/gagal).
 * - GET  ?sesi=ID                 : menampilkan ringkasan hasil siswa.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

$u = require_siswa();
$sw = siswa_saya();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Dua bentuk permintaan:
    //  1. dari halaman pengerjaan (fetch, membalas JSON) — perilaku lama;
    //  2. JALUR CADANGAN dari FORMULIR BIASA (tanpa fetch) yang mengirim `lanjut=1`: sesi ditutup
    //     seperti biasa, lalu peramban DIALIHKAN ke halaman hasil. Jalur ini dipakai bila permintaan
    //     fetch menggantung/gagal, supaya pengumpulan jawaban siswa tidak pernah gagal total.
    $mintaLanjut = (string) ($_POST['lanjut'] ?? '') !== '';
    if ($mintaLanjut) {
        if (!csrf_ok()) {
            flash('Token keamanan tidak sah. Coba kumpulkan lagi.', 'err');
            redirect('kerjakan.php?id=' . (int) ($_POST['ujian'] ?? 0));
        }
        $sesiId = (int) ($_POST['sesi'] ?? 0);
        $sesi = one('SELECT * FROM sesi_ujian WHERE id = ? AND siswa_id = ?', [$sesiId, (int) ($sw['id'] ?? 0)]);
        if (!$sesi) {
            flash('Sesi ujian tidak ditemukan.', 'err');
            redirect('ujian_siswa.php');
        }
        if ($sesi['status'] === 'mengerjakan') {
            q('UPDATE sesi_ujian SET status = ?, selesai = ? WHERE id = ?',
                ['selesai', date('Y-m-d H:i:s'), $sesiId]);
            sesi_hitung($sesiId);
        }
        redirect('selesai.php?sesi=' . $sesiId);
    }

    header('Content-Type: application/json; charset=utf-8');
    if (!csrf_ok()) {
        http_response_code(419);
        echo json_encode(['ok' => false, 'pesan' => 'Token keamanan tidak sah.']);
        exit;
    }
    $sesiId = (int) ($_POST['sesi'] ?? 0);
    $sesi = one('SELECT * FROM sesi_ujian WHERE id = ? AND siswa_id = ?', [$sesiId, (int) ($sw['id'] ?? 0)]);
    if (!$sesi) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'pesan' => 'Sesi ujian tidak ditemukan.']);
        exit;
    }
    if ($sesi['status'] === 'mengerjakan') {
        q('UPDATE sesi_ujian SET status = ?, selesai = ? WHERE id = ?',
            ['selesai', date('Y-m-d H:i:s'), $sesiId]);
        sesi_hitung($sesiId);
    }
    echo json_encode(['ok' => true, 'lanjut' => 'selesai.php?sesi=' . $sesiId]);
    exit;
}

$sesiId = (int) ($_GET['sesi'] ?? 0);
$sesi = one('SELECT * FROM sesi_ujian WHERE id = ? AND siswa_id = ?', [$sesiId, (int) ($sw['id'] ?? 0)]);
if (!$sesi) {
    flash('Data hasil tidak ditemukan.', 'err');
    redirect('ujian_siswa.php');
}
$uj = ujian_by_id((int) $sesi['ujian_id']);
if (!$uj) {
    flash('Ujian sudah tidak tersedia.', 'err');
    redirect('ujian_siswa.php');
}

$rincian = [];
$skor = 0.0;
$jwb = jawaban_sesi($sesiId);
$maks = ujian_skor_maks((int) $uj['id']);
foreach (soal_ujian((int) $uj['id']) as $s) {
    $row = $jwb[(int) $s['id']] ?? null;
    $mentah = $row ? jawaban_nilai_mentah((string) $row['jawaban']) : null;
    $hit = $mentah === null
        ? ['skor' => 0.0, 'status' => 'kosong']
        : soal_nilai($s, $mentah, $sesiId);
    if ($row && (int) $row['manual'] === 1) {
        $hit['skor'] = (float) $row['skor'];
        $hit['status'] = 'disesuaikan guru';
    }
    $skor += (float) $hit['skor'];
    $rincian[] = ['soal' => $s, 'jawaban' => $mentah, 'skor' => (float) $hit['skor'], 'status' => $hit['status']];
}

page_top('Hasil Ujian');
?>
<div class="card">
  <div class="card-head"><h3>Hasil: <?= e((string) $uj['judul']) ?></h3>
    <div class="kanan"><a class="btn btn-sm" href="ujian_siswa.php">Kembali ke Ujian Saya</a></div>
  </div>
  <div class="card-body">
    <?php if ($sesi['status'] === 'dikumpulkan'): ?>
      <div class="alert alert-err"><b>Ujian dihentikan karena batas pelanggaran tercapai.</b>
        Jawaban Anda telah dikumpulkan otomatis.</div>
    <?php endif; ?>
    <?php
    $tampil = (int) $uj['tampilkan_hasil'] === 1;
    ?>
    <?php if ($tampil): ?>
      <div class="grid g4">
        <?= kartu_stat('Nilai', r1($sesi['nilai'] ?? 0), 'skala 0-100', 'hasil') ?>
        <?= kartu_stat('Benar', (string) (int) $sesi['benar'], 'butir', 'ok') ?>
        <?= kartu_stat('Salah', (string) (int) $sesi['salah'], 'butir', 'kunci') ?>
        <?= kartu_stat('Kosong', (string) (int) $sesi['kosong'], 'butir', 'buku') ?>
      </div>
    <?php else: ?>
      <div class="alert alert-info">Jawaban Anda sudah tersimpan. Nilai akan diumumkan oleh guru
        mata pelajaran.</div>
    <?php endif; ?>
    <table class="tbl">
      <tr><th>Sekolah</th><td><?= e(setting('nama')) ?></td></tr>
      <tr><th>Siswa</th><td><?= e((string) $sw['nama']) ?> (<?= e((string) $sw['nis']) ?>)</td></tr>
      <tr><th>Kelas</th><td><?= e(kelas_nama((int) $sw['kelas_id'])) ?></td></tr>
      <tr><th>Mulai</th><td><?= e(waktu_id((string) $sesi['mulai'])) ?></td></tr>
      <tr><th>Selesai</th><td><?= e($sesi['selesai'] ? waktu_id((string) $sesi['selesai']) : '-') ?></td></tr>
      <tr><th>Skor diperoleh</th><td><?= r1($skor) ?> dari <?= r1($maks) ?></td></tr>
      <tr><th>Pelanggaran tercatat</th><td><?= (int) $sesi['pelanggaran'] ?> dari <?= (int) UJIAN_MAKS_PELANGGARAN ?></td></tr>
      <?php if ((int) $sesi['perlu_koreksi'] === 1): ?>
        <tr><th>Catatan</th><td>Ada soal uraian yang dinilai dari kata kunci; nilai akhir dapat
          disesuaikan guru.</td></tr>
      <?php endif; ?>
    </table>
  </div>
</div>

<?php if ($tampil): ?>
<div class="card">
  <div class="card-head"><h3>Rincian Jawaban</h3></div>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th class="center">No</th><th>Jenis</th><th>Pertanyaan</th><th class="center">Skor</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($rincian as $i => $r): ?>
        <tr>
          <td class="center"><?= $i + 1 ?></td>
          <td><small><?= e(jenis_soal_label($r['soal']['jenis'])) ?></small></td>
          <td><small><?= e(potong_teks((string) $r['soal']['pertanyaan'], 120)) ?></small></td>
          <td class="center"><?= r1($r['skor']) ?> / <?= r1($r['soal']['skor']) ?></td>
          <td><span class="pil <?= $r['status'] === 'benar' ? 'pil-ok' : ($r['status'] === 'kosong' ? 'pil-mut' : 'pil-warn') ?>">
            <?= e(ucfirst($r['status'])) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<?php page_bottom();
