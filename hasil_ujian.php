<?php
/** Analisis hasil satu ujian: statistik nilai, sebaran, ketuntasan per kelas, dan analisis butir. */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

$u = require_sekolah();
if (!is_guru() && !is_operator() && !is_super()) {
    tolak403('Halaman ini untuk akun Guru atau pengelola sekolah.');
}

$id = (int) ($_GET['id'] ?? 0);
$uj = ujian_by_id($id);
if (!$uj) {
    flash('Ujian tidak ditemukan.', 'err');
    redirect('hasil.php');
}
if (is_guru() && (int) $uj['guru_id'] !== (int) (guru_saya()['id'] ?? 0)) {
    tolak403('Ujian ini bukan milik Anda.');
}

$fKelas = (int) ($_GET['kelas'] ?? 0);
$ringkas = ringkas_nilai($id, $fKelas > 0 ? $fKelas : null);
$sebaran = sebaran_nilai($ringkas['baris']);
$analisis = analisis_butir($id);
$perKelas = all("SELECT k.nama AS kelas, COUNT(*) AS peserta, AVG(su.nilai) AS rata,
        MAX(su.nilai) AS maks, MIN(su.nilai) AS min,
        SUM(CASE WHEN su.nilai >= ? THEN 1 ELSE 0 END) AS tuntas
    FROM sesi_ujian su JOIN siswa sw ON sw.id = su.siswa_id LEFT JOIN kelas k ON k.id = sw.kelas_id
    WHERE su.ujian_id = ? AND su.status IN ('selesai','dikumpulkan')
    GROUP BY sw.kelas_id ORDER BY k.urutan, k.nama", [$ringkas['kkm'], $id]);
$sesiPerluKoreksi = all("SELECT su.*, sw.nama, sw.nis FROM sesi_ujian su JOIN siswa sw ON sw.id = su.siswa_id
    WHERE su.ujian_id = ? AND su.perlu_koreksi = 1 AND su.status IN ('selesai','dikumpulkan')
    ORDER BY sw.nama", [$id]);

page_top('Analisis Hasil Ujian');
?>
<div class="card">
  <div class="card-head">
    <h3><?= e((string) $uj['judul']) ?></h3>
    <div class="sub"><?= e((string) mapel_nama((int) ($uj['mapel_id'] ?? 0))) ?> &middot;
      <?= e((string) $uj['jenis_ujian']) ?> &middot; <?= e(tanggal_id(substr((string) $uj['dibuat'], 0, 10))) ?></div>
    <div class="kanan row-actions">
      <a class="btn btn-sm" href="cetak_hasil.php?id=<?= (int) $id ?>" target="_blank">Cetak Laporan</a>
      <a class="btn btn-sm" href="cetak_hasil.php?id=<?= (int) $id ?>&bagian=analisis" target="_blank">Cetak Analisis</a>
      <a class="btn btn-sm" href="hasil.php">Kembali</a>
    </div>
  </div>
  <div class="card-body" style="padding-bottom:0">
    <form method="get" class="form-grid f3" style="align-items:end">
      <input type="hidden" name="id" value="<?= (int) $id ?>">
      <div class="field"><label>Kelas</label><select name="kelas">
        <option value="0">- semua kelas -</option>
        <?php foreach (kelas_list() as $k): ?>
          <option value="<?= (int) $k['id'] ?>"<?= $fKelas === (int) $k['id'] ? ' selected' : '' ?>><?= e($k['nama']) ?></option>
        <?php endforeach; ?></select></div>
      <div class="field"><label>&nbsp;</label><button class="btn btn-sm btn-utama" type="submit">Terapkan</button>
        <a class="btn btn-sm" href="hasil_ujian.php?id=<?= (int) $id ?>">Reset</a></div>
    </form>
  </div>
</div>

<div class="grid g4">
  <?= kartu_stat('Peserta', (string) $ringkas['peserta'], 'sesi terkumpul', 'siswa') ?>
  <?= kartu_stat('Rata-rata', r1($ringkas['rata']), 'skala 0-100', 'hasil') ?>
  <?= kartu_stat('Tertinggi / Terendah', r1($ringkas['tertinggi']) . ' / ' . r1($ringkas['terendah']), 'nilai', 'ok') ?>
  <?= kartu_stat('Tuntas', $ringkas['tuntas'] . ' dari ' . $ringkas['peserta'],
      'KKTP ' . r1($ringkas['kkm']) . ' (' . persen($ringkas['persen_tuntas']) . ')', 'kunci') ?>
</div>

<?php if ($sesiPerluKoreksi): ?>
  <div class="alert alert-warn">Ada <b><?= count($sesiPerluKoreksi) ?></b> sesi dengan jawaban uraian
    yang nilainya dihitung dari kata kunci. Nilai dapat disesuaikan pada tabel di bawah (tombol <b>Koreksi</b>).</div>
<?php endif; ?>

<div class="grid g21">
  <div class="card">
    <div class="card-head"><h3>Daftar Nilai Siswa</h3>
      <div class="sub"><?= count($ringkas['baris']) ?> baris</div></div>
    <div class="table-wrap">
      <table class="tbl">
        <thead><tr><th>No</th><th>Nama Siswa</th><th>NIS</th><th>Kelas</th>
          <th class="center">Benar</th><th class="center">Salah</th><th class="center">Kosong</th>
          <th class="center">Nilai</th><th class="center">Ketuntasan</th><th class="kanan">Aksi</th></tr></thead>
        <tbody>
        <?php foreach ($ringkas['baris'] as $i => $r): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td><b><?= e($r['nama']) ?></b><?= (int) $r['perlu_koreksi'] === 1 ? ' <span class="pil pil-info">uraian</span>' : '' ?></td>
            <td><?= e((string) $r['nis']) ?></td>
            <td><?= e((string) ($r['kelas'] ?: '-')) ?></td>
            <td class="center"><?= (int) $r['benar'] ?? '-' ?></td>
            <td class="center"><?= (int) $r['salah'] ?? '-' ?></td>
            <td class="center"><?= (int) $r['kosong'] ?? '-' ?></td>
            <td class="center"><b><?= r1($r['nilai']) ?></b></td>
            <td class="center">
              <?php if ((float) $r['nilai'] >= (float) $ringkas['kkm']): ?>
                <span class="pil pil-ok">Tuntas</span>
              <?php else: ?><span class="pil pil-err">Belum</span><?php endif; ?>
            </td>
            <td class="kanan"><a class="btn btn-sm" href="koreksi.php?sesi=<?= (int) $r['id'] ?>">Koreksi</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$ringkas['baris']): ?>
          <tr><td colspan="10" class="kosong">Belum ada siswa yang menyelesaikan ujian ini.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div>
    <div class="card">
      <div class="card-head"><h3>Sebaran Nilai</h3></div>
      <div class="card-body"><canvas id="chSebaran" height="180"></canvas></div>
    </div>
    <div class="card">
      <div class="card-head"><h3>Ketuntasan per Kelas</h3></div>
      <div class="table-wrap">
        <table class="tbl">
          <thead><tr><th>Kelas</th><th class="center">Peserta</th><th class="center">Rata-rata</th><th class="center">Tuntas</th></tr></thead>
          <tbody>
          <?php foreach ($perKelas as $r): ?>
            <tr>
              <td><?= e((string) ($r['kelas'] ?: '-')) ?></td>
              <td class="center"><?= (int) $r['peserta'] ?></td>
              <td class="center"><?= r1($r['rata']) ?></td>
              <td class="center"><?= (int) $r['tuntas'] ?>/<?= (int) $r['peserta'] ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$perKelas): ?><tr><td colspan="4" class="kosong">Belum ada data.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php
$remedial = $ringkas['remedial'];
$pengayaan = $ringkas['pengayaan'];
?>
<div class="grid g2">
  <div class="card" id="cardRemedial">
    <div class="card-head">
      <h3>Daftar Siswa REMEDIAL</h3>
      <div class="sub">Nilai di bawah KKTP <?= r1($ringkas['kkm']) ?> &middot; <?= count($remedial) ?> siswa</div>
      <div class="kanan"><a class="btn btn-sm" href="cetak_hasil.php?id=<?= (int) $id ?>&bagian=tindak" target="_blank">Cetak</a></div>
    </div>
    <div class="table-wrap">
      <table class="tbl">
        <thead><tr><th>No</th><th>Nama Siswa</th><th>NIS</th><th>Kelas</th>
          <th class="center">Nilai</th><th class="center">Selisih ke KKTP</th></tr></thead>
        <tbody>
        <?php foreach ($remedial as $i => $r): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td><b><?= e($r['nama']) ?></b></td>
            <td><?= e((string) $r['nis']) ?></td>
            <td><?= e((string) ($r['kelas'] ?: '-')) ?></td>
            <td class="center"><span class="pil pil-err"><?= r1($r['nilai']) ?></span></td>
            <td class="center"><small class="mut">kurang <?= r1($ringkas['kkm'] - (float) $r['nilai']) ?></small></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$remedial): ?>
          <tr><td colspan="6" class="kosong">Tidak ada siswa di bawah KKTP — seluruh peserta tuntas.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card" id="cardPengayaan">
    <div class="card-head">
      <h3>Daftar Siswa PENGAYAAN</h3>
      <div class="sub">Nilai mencapai KKTP <?= r1($ringkas['kkm']) ?> &middot; <?= count($pengayaan) ?> siswa</div>
      <div class="kanan"><a class="btn btn-sm" href="cetak_hasil.php?id=<?= (int) $id ?>&bagian=tindak" target="_blank">Cetak</a></div>
    </div>
    <div class="table-wrap">
      <table class="tbl">
        <thead><tr><th>No</th><th>Nama Siswa</th><th>NIS</th><th>Kelas</th>
          <th class="center">Nilai</th><th class="center">Di atas KKTP</th></tr></thead>
        <tbody>
        <?php foreach ($pengayaan as $i => $r): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td><b><?= e($r['nama']) ?></b></td>
            <td><?= e((string) $r['nis']) ?></td>
            <td><?= e((string) ($r['kelas'] ?: '-')) ?></td>
            <td class="center"><span class="pil pil-ok"><?= r1($r['nilai']) ?></span></td>
            <td class="center"><small class="mut">lebih <?= r1((float) $r['nilai'] - $ringkas['kkm']) ?></small></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$pengayaan): ?>
          <tr><td colspan="6" class="kosong">Belum ada siswa yang mencapai KKTP.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php $anRingkas = $analisis['ringkas'] ?? []; ?>
<div class="grid g4">
  <?= kartu_stat('Butir Sukar', (string) ($anRingkas['sukar'] ?? 0), 'di bawah 30% benar', 'kunci') ?>
  <?= kartu_stat('Butir Valid', (string) ($anRingkas['valid'] ?? 0), 'r ≥ 0,30', 'ok') ?>
  <?= kartu_stat('Validitas Rendah', (string) (($anRingkas['cukup'] ?? 0) + ($anRingkas['tidak_valid'] ?? 0)),
      'perlu direvisi', 'buku') ?>
  <?= kartu_stat('Distraktor Bermasalah', (string) ($anRingkas['distraktor_buruk'] ?? 0),
      'pilihan yang perlu diperbaiki', 'koreksi') ?>
</div>

<div class="card">
  <div class="card-head">
    <h3>Diagram Analisis</h3>
    <div class="sub">% benar, daya beda, dan validitas tiap butir</div>
  </div>
  <div class="card-body">
    <div class="grid g2">
      <div><b class="mut">Persentase Benar tiap Butir</b><canvas id="chBenar" height="200"></canvas></div>
      <div><b class="mut">Daya Beda tiap Butir</b><canvas id="chBeda" height="200"></canvas></div>
      <div><b class="mut">Validitas tiap Butir</b><canvas id="chValid" height="200"></canvas></div>
      <div><b class="mut">Sebaran Tingkat Kesukaran &amp; Validitas</b><canvas id="chKesukaran" height="200"></canvas></div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-head">
    <h3>Analisis Butir Soal</h3>
    <div class="sub">Tingkat kesukaran, daya beda, dan sebaran pilihan jawaban
      (dari <?= (int) $analisis['peserta'] ?> peserta terbaik-terlemah dibandingkan
      <?= (int) ($analisis['kelompok'] ?? 0) ?> peserta kelompok atas/bawah)</div>
  </div>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th class="center">No</th><th>Jenis</th><th>Butir Soal</th><th class="center">Skor</th>
        <th class="center">Benar</th><th class="center">% Benar</th><th class="center">Tingkat Kesukaran</th>
        <th class="center">Daya Beda</th><th class="center">Validitas</th>
        <th>Distraktor (sebaran pilihan)</th><th>Keputusan</th></tr></thead>
      <tbody>
      <?php foreach ($analisis['butir'] as $i => $b): ?>
        <tr>
          <td class="center"><?= $i + 1 ?></td>
          <td><small><?= e(jenis_soal_label($b['jenis'])) ?></small></td>
          <td><small><?= e(potong_teks($b['pertanyaan'], 110)) ?></small></td>
          <td class="center"><?= r1($b['skor']) ?></td>
          <td class="center"><?= (int) $b['benar'] ?>/<?= (int) $b['peserta'] ?></td>
          <td class="center"><?= persen($b['p_benar'] * 100, 0) ?></td>
          <td class="center">
            <span class="pil <?= $b['kesukaran'] === 'Mudah' ? 'pil-ok' : ($b['kesukaran'] === 'Sedang' ? 'pil-info' : 'pil-err') ?>">
              <?= e($b['kesukaran']) ?></span>
          </td>
          <td class="center">
            <span class="pil <?= $b['db_label'] === 'Baik' ? 'pil-ok' : ($b['db_label'] === 'Cukup' ? 'pil-info' : 'pil-warn') ?>">
              <?= e($b['db_label']) ?></span>
            <br><small class="mut"><?= number_format($b['daya_beda'], 2, ',', '.') ?></small>
          </td>
          <td class="center">
            <span class="pil <?= $b['validitas'] >= 0.3 ? 'pil-ok' : ($b['validitas'] >= 0.2 ? 'pil-warn' : 'pil-err') ?>">
              <?= number_format($b['validitas'], 2, ',', '.') ?></span>
            <br><small class="mut"><?= e($b['valid_label']) ?></small>
          </td>
          <td>
            <?php if ($b['distraktor']): ?>
              <table class="tbl" style="margin:0;font-size:.75rem">
                <thead><tr><th>Pil</th><th class="center">Jml</th><th class="center">%</th>
                  <th class="center">Atas</th><th class="center">Bawah</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($b['distraktor'] as $d): ?>
                  <tr<?= $d['kunci'] ? ' style="background:var(--utama-muda)"' : '' ?>>
                    <td><b><?= e($d['huruf']) ?></b><?= $d['kunci'] ? ' <span class="pil pil-ok">kunci</span>' : '' ?></td>
                    <td class="center"><?= (int) $d['jumlah'] ?></td>
                    <td class="center"><?= number_format($d['persen'], 0, ',', '.') ?></td>
                    <td class="center"><?= (int) $d['atas'] ?></td>
                    <td class="center"><?= (int) $d['bawah'] ?></td>
                    <td><small><?= e($d['status']) ?></small></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            <?php else: ?>
              <small class="mut">- (bukan soal pilihan)</small>
            <?php endif; ?>
          </td>
          <td>
            <?php
            $keputusan = [];
            if ($b['kesukaran'] === 'Sukar') { $keputusan[] = 'Terlalu sukar'; }
            if ($b['kesukaran'] === 'Mudah') { $keputusan[] = 'Terlalu mudah'; }
            if ($b['daya_beda'] < 0.2) { $keputusan[] = 'Daya beda rendah'; }
            if ($b['validitas'] < 0.2) { $keputusan[] = 'Validitas rendah'; }
            $adaDistraktorBuruk = false;
            foreach ($b['distraktor'] as $d) {
                if (!$d['kunci'] && $d['status'] !== 'Berfungsi') { $adaDistraktorBuruk = true; }
            }
            if ($adaDistraktorBuruk) { $keputusan[] = 'Distraktor perlu diperbaiki'; }
            ?>
            <?php if (!$keputusan): ?>
              <span class="pil pil-ok">Baik</span>
            <?php else: ?>
              <?php foreach ($keputusan as $k): ?><span class="field-chip"><?= e($k) ?></span><?php endforeach; ?>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$analisis['butir']): ?>
        <tr><td colspan="11" class="kosong">Analisis butir tersedia setelah ada siswa yang menyelesaikan ujian.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script src="assets/vendor/chart.umd.min.js"></script>
<script>
(function () {
  if (typeof Chart === 'undefined') { return; }
  var nomor = <?= json_encode(array_map(fn($i) => 'B' . ($i + 1), array_keys($analisis['butir']))) ?>;
  var persenBenar = <?= json_encode(array_map(fn($b) => round($b['p_benar'] * 100, 1), $analisis['butir'])) ?>;
  var dayaBeda = <?= json_encode(array_map(fn($b) => round($b['daya_beda'], 3), $analisis['butir'])) ?>;
  var validitas = <?= json_encode(array_map(fn($b) => $b['validitas'], $analisis['butir'])) ?>;
  var ringkasAn = <?= json_encode($analisis['ringkas'] ?? []) ?>;

  var opsiDasar = { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } };

  new Chart(document.getElementById('chBenar'), {
    type: 'bar',
    data: { labels: nomor, datasets: [{ label: '% benar', data: persenBenar,
      backgroundColor: persenBenar.map(function (v) { return v >= 70 ? '#0f7a52' : (v >= 30 ? '#c9a227' : '#b3261e'); }),
      borderRadius: 5 }] },
    options: Object.assign({}, opsiDasar, { scales: { y: { beginAtZero: true, max: 100 } } })
  });
  new Chart(document.getElementById('chBeda'), {
    type: 'bar',
    data: { labels: nomor, datasets: [{ label: 'daya beda', data: dayaBeda,
      backgroundColor: dayaBeda.map(function (v) { return v >= 0.4 ? '#0f7a52' : (v >= 0.2 ? '#c9a227' : '#b3261e'); }),
      borderRadius: 5 }] },
    options: opsiDasar
  });
  new Chart(document.getElementById('chValid'), {
    type: 'bar',
    data: { labels: nomor, datasets: [{ label: 'validitas', data: validitas,
      backgroundColor: validitas.map(function (v) { return v >= 0.3 ? '#0f7a52' : (v >= 0.2 ? '#c9a227' : '#b3261e'); }),
      borderRadius: 5 }] },
    options: opsiDasar
  });
  new Chart(document.getElementById('chKesukaran'), {
    type: 'doughnut',
    data: { labels: ['Mudah', 'Sedang', 'Sukar', 'Validitas cukup/rendah', 'Distraktor bermasalah'],
      datasets: [{ data: [ringkasAn.mudah || 0, ringkasAn.sedang || 0, ringkasAn.sukar || 0,
        (ringkasAn.cukup || 0) + (ringkasAn.tidak_valid || 0), ringkasAn.distraktor_buruk || 0],
        backgroundColor: ['#1d8a5f', '#c9a227', '#b3261e', '#8a6d1e', '#6d6d6d'] }] },
    options: { plugins: { legend: { position: 'bottom' } } }
  });
})();
</script>
<script>
(function () {
  var el = document.getElementById('chSebaran');
  if (!el || typeof Chart === 'undefined') { return; }
  new Chart(el, {
    type: 'bar',
    data: {
      labels: <?= json_encode(array_keys($sebaran)) ?>,
      datasets: [{
        label: 'Jumlah siswa',
        data: <?= json_encode(array_values($sebaran)) ?>,
        backgroundColor: ['#b3261e', '#c9a227', '#1d8a5f', '#12684a', '#0b4a33'],
        borderRadius: 6
      }]
    },
    options: {
      plugins: { legend: { display: false } },
      scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
    }
  });
})();
</script>
<?php page_bottom();
