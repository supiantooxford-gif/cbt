<?php
/**
 * Koreksi jawaban uraian seorang siswa.
 *
 * Nilai awal dihitung otomatis dari kata kunci; guru dapat menyesuaikannya per butir.
 * Skor yang disesuaikan manual tidak akan ditimpa penilaian otomatis (kolom jawaban.manual).
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

$u = require_sekolah();
if (!is_guru() && !is_operator() && !is_super()) {
    tolak403('Halaman ini untuk akun Guru atau pengelola sekolah.');
}

$sesiId = (int) ($_GET['sesi'] ?? $_POST['sesi'] ?? 0);
$sesi = one('SELECT * FROM sesi_ujian WHERE id = ? AND sekolah_id = ?', [$sesiId, sekolah_id()]);
if (!$sesi) {
    flash('Sesi ujian tidak ditemukan.', 'err');
    redirect('hasil.php');
}
$uj = ujian_by_id((int) $sesi['ujian_id']);
if (!$uj) {
    redirect('hasil.php');
}
if (is_guru() && (int) $uj['guru_id'] !== (int) (guru_saya()['id'] ?? 0)) {
    tolak403('Ujian ini bukan milik Anda.');
}
$siswa = one('SELECT * FROM siswa WHERE id = ?', [(int) $sesi['siswa_id']]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $skorMasuk = pos_arr('skor');
    $soalUjian = soal_ujian((int) $uj['id']);
    $n = 0;
    foreach ($soalUjian as $s) {
        if ($s['jenis'] !== 'uraian') {
            continue;
        }
        $sid = (int) $s['id'];
        if (!array_key_exists($sid, $skorMasuk)) {
            continue;
        }
        $nilai = (float) str_replace(',', '.', (string) $skorMasuk[$sid]);
        $nilai = max(0, min((float) $s['skor'], $nilai));
        $ada = one('SELECT id FROM jawaban WHERE sesi_id = ? AND soal_id = ?', [$sesiId, $sid]);
        if ($ada) {
            q('UPDATE jawaban SET skor = ?, manual = 1 WHERE id = ?', [$nilai, (int) $ada['id']]);
        } else {
            q('INSERT INTO jawaban (sesi_id, soal_id, jawaban, benar, skor, dijawab, manual) VALUES (?,?,NULL,NULL,?,?,1)',
                [$sesiId, $sid, $nilai, date('Y-m-d H:i:s')]);
        }
        $n++;
    }
    sesi_hitung($sesiId);
    flash($n . ' butir uraian disimpan. Nilai ujian diperbarui.', 'ok');
    redirect('koreksi.php?sesi=' . $sesiId);
}

$soal = soal_ujian((int) $uj['id']);
$jwb = jawaban_sesi($sesiId);

page_top('Koreksi Jawaban Uraian');
?>
<div class="card">
  <div class="card-head">
    <h3>Koreksi: <?= e((string) ($siswa['nama'] ?? '-')) ?></h3>
    <div class="sub"><?= e((string) $uj['judul']) ?> &middot;
      nilai saat ini <b><?= r1($sesi['nilai'] ?? 0) ?></b></div>
    <div class="kanan"><a class="btn btn-sm" href="hasil_ujian.php?id=<?= (int) $uj['id'] ?>">Kembali ke Hasil</a></div>
  </div>
  <div class="card-body">
    <div class="alert alert-info">Soal uraian dinilai otomatis dari kata kunci. Periksa jawaban siswa
      lalu sesuaikan skornya bila perlu — skor yang Anda tetapkan tidak akan ditimpa penilaian otomatis.</div>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="sesi" value="<?= (int) $sesiId ?>">
      <?php $adaUraian = false; ?>
      <?php foreach ($soal as $i => $s): if ($s['jenis'] !== 'uraian') { continue; } $adaUraian = true; ?>
        <?php
        $row = $jwb[(int) $s['id']] ?? null;
        $mentah = $row ? jawaban_nilai_mentah((string) $row['jawaban']) : null;
        $hit = $mentah === null ? ['skor' => 0.0, 'status' => 'kosong'] : soal_nilai($s, $mentah, $sesiId);
        $manual = $row && (int) $row['manual'] === 1;
        ?>
        <div class="soal-kartu" style="box-shadow:none">
          <div class="soal-head">
            <div class="soal-no"><?= $i + 1 ?></div>
            <div class="soal-jenis">Uraian / Essai</div>
            <div class="soal-skor">Skor maks <?= r1($s['skor']) ?></div>
          </div>
          <div class="soal-teks"><?= nl2br(e((string) $s['pertanyaan'])) ?></div>
          <div class="field">
            <label>Jawaban Siswa</label>
            <div class="kredensial" style="min-height:70px"><?= $mentah === null ? '<span class="mut">(tidak dijawab)</span>' : nl2br(e(is_array($mentah) ? implode(' ', $mentah) : (string) $mentah)) ?></div>
          </div>
          <div class="field">
            <label>Kata Kunci Penilaian</label>
            <div><?= e(is_array($s['kunci']) ? implode(' | ', array_map('strval', $s['kunci'])) : (string) $s['kunci']) ?></div>
          </div>
          <?php
          $ketemu = [];
          if ($mentah !== null && is_array($s['kunci'])) {
              $isi = teks_normal(is_array($mentah) ? implode(' ', $mentah) : (string) $mentah);
              foreach ($s['kunci'] as $k) {
                  $k = (string) $k;
                  foreach (explode('/', $k) as $alt) {
                      if (teks_normal($alt) !== '' && str_contains($isi, teks_normal($alt))) {
                          $ketemu[] = $k;
                          break;
                      }
                  }
              }
          }
          ?>
          <p class="mut">Kata kunci terdeteksi: <?= $ketemu ? e(implode(', ', $ketemu)) : '-' ?></p>
          <div class="field" style="max-width:260px">
            <label for="skor<?= (int) $s['id'] ?>">Skor Diberikan (0 - <?= r1($s['skor']) ?>)
              <?= $manual ? '<span class="pil pil-info">disesuaikan guru</span>' : '<span class="pil pil-mut">otomatis ' . r1($hit['skor']) . '</span>' ?></label>
            <input type="number" id="skor<?= (int) $s['id'] ?>" name="skor[<?= (int) $s['id'] ?>]"
                   min="0" max="<?= e(num_input($s['skor'])) ?>" step="0.5"
                   value="<?= e(num_input($manual ? $row['skor'] : $hit['skor'])) ?>">
          </div>
        </div>
      <?php endforeach; ?>
      <?php if (!$adaUraian): ?>
        <div class="kosong">Ujian ini tidak memuat soal uraian.</div>
      <?php else: ?>
        <div class="form-kaki">
          <button class="btn btn-utama" type="submit">Simpan Koreksi</button>
          <a class="btn" href="hasil_ujian.php?id=<?= (int) $uj['id'] ?>">Kembali tanpa mengubah</a>
        </div>
      <?php endif; ?>
    </form>
  </div>
</div>
<?php page_bottom();
