<?php
/**
 * Halaman pengerjaan ujian (siswa).
 *
 * - Selama sesi berjalan, akun siswa TERKUNCI pada halaman ini (gerbang_ujian_siswa()).
 * - Layar penuh diwajibkan; pindah tab / keluar layar penuh dicatat sebagai pelanggaran.
 * - Setelah UJIAN_MAKS_PELANGGARAN pelanggaran, jawaban otomatis dikumpulkan (server).
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

$u = require_siswa();
$sw = siswa_saya();
if (!$sw) {
    flash('Akun Anda belum ditautkan ke data siswa.', 'err');
    redirect('index.php');
}

$ujianId = (int) ($_GET['id'] ?? 0);
if ($ujianId <= 0) {
    $berjalan = sesi_berjalan((int) $sw['id']);
    if ($berjalan) {
        redirect('kerjakan.php?id=' . (int) $berjalan['ujian_id']);
    }
    redirect('ujian_siswa.php');
}
$uj = ujian_by_id($ujianId);
if (!$uj || $uj['status'] !== 'aktif') {
    flash('Ujian tidak ditemukan atau belum diaktifkan.', 'err');
    redirect('ujian_siswa.php');
}
$kelasOk = in_array((int) ($sw['kelas_id'] ?? 0), ujian_kelas_ids($ujianId), true);
if (!$kelasOk) {
    tolak403('Ujian ini bukan untuk kelas Anda.');
}

// Data sesi diambil LEBIH DULU: gerbang jadwal/token di bawah memakainya ($sesiAda).
// Sesi yang sudah ada berarti siswa sudah pernah masuk (melanjutkan ujian), sehingga token
// tidak diminta lagi dan jadwal tidak menghalanginya.
// Sesi: lanjutkan bila sudah ada, atau buat baru.
$sesi = one('SELECT * FROM sesi_ujian WHERE ujian_id = ? AND siswa_id = ?', [$ujianId, (int) $sw['id']]);
$sesiAda = $sesi !== null;

// ---------------------------------------------------------------- JADWAL ASESMEN
// Permintaan pemilik: "Pada akun operator tambahkan fitur untuk mengaktifkan soal sesuai jadwal
// asesmen/ujian". Di luar jendela jadwal, siswa TIDAK dapat membuka soal.
$jadwal = ujian_jadwal($uj);
if (!$sesiAda && ($jadwal['keadaan'] === 'belum' || $jadwal['keadaan'] === 'tutup')) {
    flash($jadwal['pesan'], 'warn');
    redirect('ujian_siswa.php');
}

// ---------------------------------------------------------------- TOKEN UJIAN
// Permintaan pemilik: "tambahkan generate token (per mata pelajaran yang akan diujikan).
// Nomer token ini akan diinput oleh siswa sebelum mengerjakan soal."
$kunciToken = 'ujian_token_' . $ujianId;
$perluToken = ujian_butuh_token($uj) && empty($_SESSION[$kunciToken]);
$galatToken = '';
if ($perluToken && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'token') {
    if (!csrf_ok()) {
        $galatToken = 'Token keamanan halaman tidak sah. Muat ulang halaman.';
    } elseif (token_coba_habis($ujianId)) {
        $galatToken = 'Percobaan token terlalu banyak. Coba lagi dalam ' . (int) TOKEN_MENIT_COBA . ' menit.';
    } elseif (ujian_token_sah($uj, (string) ($_POST['token'] ?? ''))) {
        $_SESSION[$kunciToken] = 1;
        token_coba_bersih($ujianId);
        flash('Token benar — soal dibuka.', 'ok');
        redirect('kerjakan.php?id=' . $ujianId);
    } else {
        $sisa = token_coba_tambah($ujianId);
        $galatToken = 'Token salah.';
        $galatToken .= $sisa > 0 ? ' Sisa percobaan: ' . $sisa . ' kali.' : ' Percobaan sudah habis.';
    }
}

if (!$sesi) {
    q('INSERT INTO sesi_ujian (sekolah_id, ujian_id, siswa_id, mulai, status, pelanggaran, ip, peramban)
       VALUES (?,?,?,?,?,0,?,?)',
        [sekolah_id(), $ujianId, (int) $sw['id'], date('Y-m-d H:i:s'), 'mengerjakan',
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''), teks($_SERVER['HTTP_USER_AGENT'] ?? '', 250)]);
    $sesi = one('SELECT * FROM sesi_ujian WHERE id = ?', [(int) db()->lastInsertId()]);
} elseif ($sesi['status'] !== 'mengerjakan') {
    redirect('selesai.php?sesi=' . (int) $sesi['id']);
}

if ($perluToken) {
    // Token belum benar: tampilkan LAYAR TOKEN saja — soal tidak dikirim ke peramban.
    page_top('Token Ujian', ['tanpa_sidebar' => true]);
    ?>
    <div style="min-height:100vh;display:grid;place-items:center;padding:20px">
      <div class="card" style="max-width:520px;margin:0">
        <div class="card-head"><h3>Masukkan Token Ujian</h3>
          <div class="sub"><?= e((string) $uj['judul']) ?> &middot; <?= e(mapel_nama((int) ($uj['mapel_id'] ?? 0))) ?></div></div>
        <div class="card-body">
          <div class="alert alert-info">
            Token diberikan oleh pengawas/operator saat ujian dimulai.
            Tanpa token yang benar, soal tidak akan terbuka.
          </div>
          <?php if ($galatToken !== ''): ?>
            <div class="alert alert-err"><?= e($galatToken) ?></div>
          <?php endif; ?>
          <form method="post" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="aksi" value="token">
            <div class="field">
              <label for="token">Token Ujian</label>
              <input type="text" id="token" name="token" maxlength="12" required autofocus
                     placeholder="mis. K7M2QP" style="text-transform:uppercase;letter-spacing:.14em;font-size:1.1rem">
              <div class="ket">Huruf besar/kecil dan spasi tidak menjadi masalah.</div>
            </div>
            <button class="btn btn-utama btn-blok" type="submit">Buka Soal</button>
          </form>
          <p class="mut mt16" style="font-size:.8rem">
            <?= e($jadwal['pesan']) ?>
            <?php if ($jadwal['selesai']): ?>
              <br>Ujian ditutup pukul <?= e(substr((string) $jadwal['selesai'], 11, 5)) ?>.
            <?php endif; ?>
          </p>
          <a class="btn btn-blok mt8" href="ujian_siswa.php">Kembali ke Ujian Saya</a>
        </div>
      </div>
    </div>
    <?php
    page_bottom();
    exit;
}

$batas = (int) UJIAN_MAKS_PELANGGARAN;
if ((int) $sesi['pelanggaran'] >= $batas) {
    q('UPDATE sesi_ujian SET status = ?, selesai = ? WHERE id = ?',
        ['dikumpulkan', date('Y-m-d H:i:s'), (int) $sesi['id']]);
    sesi_hitung((int) $sesi['id']);
    redirect('selesai.php?sesi=' . (int) $sesi['id']);
}

// Batas waktu (acuan waktu server).
$mulaiTs = strtotime((string) $sesi['mulai']);
$berakhir = $mulaiTs + max(1, (int) $uj['durasi']) * 60;
if (time() >= $berakhir) {
    q('UPDATE sesi_ujian SET status = ?, selesai = ? WHERE id = ?',
        ['selesai', date('Y-m-d H:i:s'), (int) $sesi['id']]);
    sesi_hitung((int) $sesi['id']);
    flash('Waktu ujian sudah habis — jawaban Anda dikumpulkan otomatis.', 'warn');
    redirect('selesai.php?sesi=' . (int) $sesi['id']);
}

$soal = soal_urut_untuk($ujianId, $uj, (int) $sesi['id']);
$jawaban = jawaban_sesi((int) $sesi['id']);
$terjawab = 0;
foreach ($soal as $s) {
    $row = $jawaban[(int) $s['id']] ?? null;
    if ($row && trim((string) $row['jawaban']) !== '' && (string) $row['jawaban'] !== 'null') {
        $terjawab++;
    }
}
$sisaDetik = max(0, $berakhir - time());

page_top((string) $uj['judul'], ['tanpa_sidebar' => true]);
?>
<div id="ujianApp"
     data-sesi="<?= (int) $sesi['id'] ?>"
     data-csrf="<?= e(csrf_token()) ?>"
     data-pantau="1"
     data-batas="<?= (int) $batas ?>"
     data-pelanggaran="<?= (int) $sesi['pelanggaran'] ?>"
     data-berakhir="<?= (int) $berakhir ?>"
     data-sekarang="<?= time() ?>">

  <div class="ujian-top">
    <div class="judul">
      <b><?= e((string) $uj['judul']) ?></b>
      <span><?= e((string) $sw['nama']) ?> &middot; <?= e(kelas_nama((int) $sw['kelas_id'])) ?> &middot;
        <?= e((string) (mapel_nama((int) ($uj['mapel_id'] ?? 0)) ?: '')) ?></span>
    </div>
    <div id="timerUjian" class="timer"></div>
    <div class="mut" style="font-size:.72rem;text-align:center">
      <?php if ($jadwal['selesai']): ?>Jadwal berakhir<br><?= e(substr((string) $jadwal['selesai'], 11, 5)) ?>
      <?php else: ?>Tanpa batas jadwal<?php endif; ?>
    </div>
    <div class="mut" style="font-size:.75rem">
      Pelanggaran: <b id="pelanggaranKe"><?= (int) $sesi['pelanggaran'] ?> dari <?= (int) $batas ?></b><br>
      Terjawab: <b id="jumlahTerjawab"><?= (int) $terjawab ?></b> dari <b id="jumlahTotal"><?= count($soal) ?></b>
    </div>
    <button class="btn btn-utama" type="button" id="btnKumpulkan">Kumpulkan Jawaban</button>
  </div>

  <div class="ujian-badan">
    <div>
      <?php if (trim((string) $uj['keterangan']) !== ''): ?>
        <div class="alert alert-info"><b>Petunjuk:</b> <?= nl2br(e((string) $uj['keterangan'])) ?></div>
      <?php endif; ?>

      <?php foreach ($soal as $i => $s): $sid = (int) $s['id']; $row = $jawaban[$sid] ?? null; $jwb = $row ? jawaban_nilai_mentah((string) $row['jawaban']) : null; ?>
        <div class="soal-card" data-soal="<?= $sid ?>" data-jenis="<?= e($s['jenis']) ?>" id="soal<?= $sid ?>">
          <div class="soal-head">
            <div class="soal-no"><?= $i + 1 ?></div>
            <div class="soal-jenis"><?= e(jenis_soal_label($s['jenis'])) ?></div>
            <div class="soal-skor">Skor maks <?= r1($s['skor']) ?></div>
          </div>
          <div class="soal-teks"><?= nl2br(e((string) $s['pertanyaan'])) ?></div>
          <?php if (soal_gambar($s) !== ''): ?>
            <div class="soal-gambar">
              <img src="<?= e(soal_gambar($s)) ?>" alt="Gambar soal nomor <?= $i + 1 ?>">
            </div>
          <?php endif; ?>

          <?php if (in_array($s['jenis'], ['pg', 'pgk'], true)): ?>
            <?php $urutOpsi = opsi_urut($sid, $uj, (int) $sesi['id'], count($s['opsi_arr'])); ?>
            <div class="opsi-list">
              <?php foreach ($urutOpsi as $pos => $asli): $teks = (string) ($s['opsi_arr'][$asli] ?? ''); ?>
                <?php $cek = $s['jenis'] === 'pg'
                    ? ((string) $jwb === (string) $asli)
                    : (is_array($jwb) && in_array((int) $asli, array_map('intval', $jwb), true)); ?>
                <label class="opsi<?= $cek ? ' on' : '' ?>">
                  <input type="<?= $s['jenis'] === 'pg' ? 'radio' : 'checkbox' ?>"
                         name="soal_<?= $sid ?><?= $s['jenis'] === 'pg' ? '' : '[]' ?>" value="<?= (int) $asli ?>"
                         <?= $cek ? 'checked' : '' ?>>
                  <span class="opsi-huruf"><?= e(huruf_opsi($pos)) ?></span>
                  <span><?= e($teks) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          <?php elseif ($s['jenis'] === 'bs'): ?>
            <div data-jumlah="<?= count($s['opsi_arr']) ?>">
              <?php foreach ($s['opsi_arr'] as $oi => $teks): ?>
                <div class="bs-baris">
                  <div class="teks"><?= ($oi + 1) ?>. <?= e((string) $teks) ?></div>
                  <div class="bs-pil">
                    <?php $pilih = is_array($jwb) ? (string) ($jwb[$oi] ?? '') : ''; ?>
                    <label class="<?= $pilih === 'B' ? 'on' : '' ?>"><input type="radio" name="soal_<?= $sid ?>_<?= $oi ?>" value="B"<?= $pilih === 'B' ? ' checked' : '' ?>>Benar</label>
                    <label class="<?= $pilih === 'S' ? 'on' : '' ?>"><input type="radio" name="soal_<?= $sid ?>_<?= $oi ?>" value="S"<?= $pilih === 'S' ? ' checked' : '' ?>>Salah</label>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php elseif ($s['jenis'] === 'jodoh'): ?>
            <?php $urutKanan = jodoh_urut($sid, (int) $sesi['id'], count($s['opsi_arr'])); ?>
            <p class="mut">Pasangkan setiap pernyataan dengan pasangan yang tepat.</p>
            <div data-jumlah="<?= count($s['opsi_arr']) ?>">
              <?php foreach ($s['opsi_arr'] as $oi => $teks): ?>
                <?php $pilih = is_array($jwb) ? (string) ($jwb[$oi] ?? '') : ''; ?>
                <div class="jodoh-kiri">
                  <div class="opsi-huruf"><?= e(huruf_opsi($oi)) ?></div>
                  <div class="teks"><?= e((string) $teks) ?></div>
                  <select name="soal_<?= $sid ?>_<?= $oi ?>" style="max-width:320px">
                    <option value="">- pilih pasangan -</option>
                    <?php foreach ($urutKanan as $pos => $asli): ?>
                      <option value="<?= (int) $pos ?>"<?= $pilih === (string) $pos ? ' selected' : '' ?>>
                        <?= e(huruf_opsi($pos)) ?>. <?= e((string) ($s['kunci'][$asli] ?? '')) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              <?php endforeach; ?>
            </div>
          <?php elseif ($s['jenis'] === 'isian'): ?>
            <input type="text" name="soal_<?= $sid ?>" value="<?= e(is_array($jwb) ? implode(' ', $jwb) : (string) $jwb) ?>"
                   placeholder="Tulis jawaban singkat Anda" autocomplete="off">
          <?php else: ?>
            <textarea name="soal_<?= $sid ?>" rows="5"
              placeholder="Tulis jawaban uraian Anda"><?= e(is_array($jwb) ? implode(' ', $jwb) : (string) $jwb) ?></textarea>
          <?php endif; ?>

          <div class="row-actions mt8">
            <button class="btn btn-sm btn-ragu" type="button">Tandai ragu-ragu</button>
          </div>
        </div>
      <?php endforeach; ?>

      <div class="card">
        <div class="card-body center">
          <p class="mut">Sudah yakin dengan semua jawaban Anda?</p>
          <button class="btn btn-utama" type="button" id="btnKumpulkan2"
                  onclick="document.getElementById('btnKumpulkan').click()">Kumpulkan Jawaban Sekarang</button>
        </div>
      </div>
    </div>

    <div class="nav-soal">
      <b>Navigasi Soal</b>
      <div class="nav-grid">
        <?php foreach ($soal as $i => $s): ?>
          <a href="#soal<?= (int) $s['id'] ?>" data-nav="<?= (int) $s['id'] ?>"><?= $i + 1 ?></a>
        <?php endforeach; ?>
      </div>
      <div class="legenda">
        <span><i style="background:#e8f7f1;border:1px solid #bfe3d5"></i>sudah dijawab</span>
        <span><i style="background:#fff5e6;border:1px solid #f3ddb4"></i>ragu-ragu</span>
        <span><i style="background:#fff;border:1px solid #d9e9e1"></i>belum</span>
      </div>
      <p class="mut mt8" id="simpanStatus" style="font-size:.72rem">Jawaban tersimpan otomatis.</p>
    </div>
  </div>
</div>

<div class="layar-peringatan" id="layarPelanggaran">
  <div class="kotak-peringatan">
    <h2>Pelanggaran Terdeteksi</h2>
    <p id="pesanPelanggaran"></p>
    <button class="btn btn-utama" type="button" id="btnLanjutUjian">Kembali ke Layar Penuh &amp; Lanjutkan</button>
  </div>
</div>


<div class="layar-peringatan on" id="layarMasukUjian">
  <div class="kotak-peringatan">
    <h2>Siap Mengerjakan?</h2>
    <p>Ujian <b><?= e((string) $uj['judul']) ?></b> berdurasi <b><?= (int) $uj['durasi'] ?> menit</b>
      dengan <b><?= count($soal) ?> soal</b>.</p>
    <p class="mut">Setelah menekan tombol di bawah, halaman masuk ke mode <b>layar penuh</b>.
      Berpindah tab atau keluar dari layar penuh akan dicatat sebagai pelanggaran
      (maksimal <?= (int) $batas ?>).</p>
    <button class="btn btn-utama" type="button" id="btnMulaiUjian">Masuk Mode Ujian Sekarang</button>
  </div>
</div>

<?php // Konfirmasi "Kumpulkan Jawaban" memakai layar DI DALAM halaman, bukan window.confirm().
      // Peramban dapat MENOLAK/menyembunyikan dialog confirm (mis. Chrome menampilkan centang
      // "Jangan tampilkan lagi dialog dari halaman ini" setelah beberapa dialog, sehingga
      // confirm() langsung mengembalikan false) — akibatnya tombol Kumpulkan tampak tidak
      // merespon sama sekali. Layar ini tidak dapat diblokir peramban. ?>
<div class="layar-peringatan" id="layarKumpulkan">
  <div class="kotak-peringatan kotak-kumpul">
    <h2>Kumpulkan Jawaban?</h2>
    <p id="pesanKumpulkan"></p>
    <p class="mut">Setelah dikumpulkan, jawaban Anda tidak dapat diubah lagi.</p>
    <div class="aksi">
      <button class="btn btn-utama" type="button" id="btnYaKumpulkan">Ya, Kumpulkan Sekarang</button>
      <button class="btn" type="button" id="btnBatalKumpulkan">Periksa Jawaban Dulu</button>
    </div>
  </div>
</div>

<?php // Jalur CADANGAN tanpa fetch: formulir biasa ke selesai.php (csrf + sesi + lanjut=1 → server
      // menutup sesi lalu mengalihkan ke halaman hasil). Dipakai bila permintaan fetch menggantung
      // atau gagal, supaya pengumpulan jawaban TIDAK pernah bergantung pada JavaScript/fetch saja. ?>
<form method="post" action="selesai.php" id="formKumpulDarurat" style="display:none">
  <?= csrf_field() ?>
  <input type="hidden" name="sesi" value="<?= (int) $sesi['id'] ?>">
  <input type="hidden" name="lanjut" value="1">
</form>
<?php page_bottom();
