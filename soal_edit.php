<?php
/** Tambah / ubah satu soal (enam jenis soal). */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

$u = require_sekolah();
if (!is_guru() && !is_operator() && !is_super()) {
    tolak403('Halaman ini untuk akun Guru atau pengelola sekolah.');
}

$ujianId = (int) ($_GET['ujian'] ?? $_POST['ujian'] ?? 0);
$uj = ujian_by_id($ujianId);
if (!$uj) {
    flash('Ujian tidak ditemukan.', 'err');
    redirect('ujian.php');
}
if (is_guru() && (int) $uj['guru_id'] !== (int) (guru_saya()['id'] ?? 0)) {
    tolak403('Ujian ini bukan milik Anda.');
}

$id = (int) ($_GET['id'] ?? 0);
$soal = $id > 0 ? one('SELECT * FROM soal WHERE id = ? AND ujian_id = ?', [$id, $ujianId]) : null;
if ($id > 0 && !$soal) {
    flash('Soal tidak ditemukan.', 'err');
    redirect('ujian_edit.php?id=' . $ujianId);
}
$soal = $soal ? soal_lengkap($soal) : null;

$form = [
    'jenis' => (string) ($soal['jenis'] ?? 'pg'),
    'pertanyaan' => (string) ($soal['pertanyaan'] ?? ''),
    'skor' => (float) ($soal['skor'] ?? 1),
    'pembahasan' => (string) ($soal['pembahasan'] ?? ''),
];
$opsi = $soal['opsi_arr'] ?? [];
$kunci = $soal['kunci'] ?? null;
$kunciTeks = '';
if (in_array($form['jenis'], ['isian', 'uraian'], true) && is_array($kunci)) {
    $kunciTeks = implode("\n", array_map('strval', $kunci));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $form['jenis'] = jenis_soal_sah($_POST['jenis'] ?? 'pg');
    $form['pertanyaan'] = teks($_POST['pertanyaan'] ?? '', SOAL_TEKS_MAKS);
    $form['skor'] = max(0.5, min(100, (float) str_replace(',', '.', (string) ($_POST['skor'] ?? '1'))));
    $form['pembahasan'] = teks($_POST['pembahasan'] ?? '', PEMBAHASAN_MAKS);

    $namaOpsi = in_array($form['jenis'], ['pg', 'pgk'], true) ? 'opsi_pg'
        : ($form['jenis'] === 'bs' ? 'opsi_bs' : 'opsi_jodoh');
    $opsiMasuk = [];
    foreach (pos_arr($namaOpsi) as $o) {
        $o = teks($o, 500);
        if ($o === '') {
            continue;
        }
        $opsiMasuk[] = $o;
    }
    $galat = '';
    $jawaban = null;

    if ($form['pertanyaan'] === '') {
        $galat = 'Pertanyaan wajib diisi.';
    } elseif ($form['jenis'] === 'pg' || $form['jenis'] === 'pgk') {
        if (count($opsiMasuk) < 2) {
            $galat = 'Sediakan minimal dua pilihan jawaban.';
        } else {
            if ($form['jenis'] === 'pg') {
                $k = (int) ($_POST['kunci_pg'] ?? -1);
                if ($k < 0 || $k >= count($opsiMasuk)) {
                    $galat = 'Tentukan kunci jawaban yang benar.';
                } else {
                    $jawaban = $k;
                }
            } else {
                $kk = array_map('intval', pos_arr('kunci_pgk'));
                $kk = array_values(array_filter($kk, fn($x) => $x >= 0 && $x < count($opsiMasuk)));
                if (!$kk) {
                    $galat = 'Tentukan minimal satu kunci jawaban benar.';
                } else {
                    sort($kk);
                    $jawaban = $kk;
                }
            }
        }
    } elseif ($form['jenis'] === 'bs') {
        if (count($opsiMasuk) < 1) {
            $galat = 'Tulis minimal satu pernyataan pada kolom pernyataan.';
        } else {
            $bs = [];
            foreach ($opsiMasuk as $i => $x) {
                $v = atas(teks(pos_arr('bs')[$i] ?? '', 1));
                if ($v !== 'B' && $v !== 'S') {
                    $galat = 'Tentukan Benar atau Salah untuk setiap pernyataan.';
                    break;
                }
                $bs[] = $v;
            }
            if ($galat === '') {
                $jawaban = $bs;
            }
        }
    } elseif ($form['jenis'] === 'jodoh') {
        $pasangan = array_map(fn($x) => teks($x, 300), pos_arr('jodoh'));
        if (count($opsiMasuk) < 2) {
            $galat = 'Sediakan minimal dua pasangan untuk dijadikan soal menjodohkan.';
        } else {
            $jwb = [];
            foreach ($opsiMasuk as $i => $x) {
                if (trim((string) ($pasangan[$i] ?? '')) === '') {
                    $galat = 'Lengkapi pasangan kanan untuk setiap pernyataan.';
                    break;
                }
                $jwb[] = trim((string) $pasangan[$i]);
            }
            if ($galat === '') {
                $jawaban = $jwb;
            }
        }
    } else {
        $baris = preg_split('/\r\n|\r|\n|\|/', (string) ($_POST['kunci_teks'] ?? '')) ?: [];
        $arr = [];
        foreach ($baris as $b) {
            $b = teks($b, 200);
            if ($b !== '') {
                $arr[] = $b;
            }
        }
        if (!$arr) {
            $galat = $form['jenis'] === 'isian'
                ? 'Tulis minimal satu jawaban benar (boleh beberapa alternatif, satu per baris).'
                : 'Tulis minimal satu kata kunci penilaian.';
        } else {
            $jawaban = $arr;
        }
    }

    if ($galat !== '') {
        flash($galat, 'err');
        $opsi = $opsiMasuk;
        $kunci = is_array($jawaban) ? $jawaban : $jawaban;
        if ($form['jenis'] === 'isian' || $form['jenis'] === 'uraian') {
            $kunciTeks = (string) ($_POST['kunci_teks'] ?? '');
        }
    } else {
        $pakaiOpsi = in_array($form['jenis'], ['pg', 'pgk', 'jodoh', 'bs'], true);
        $urutan = $soal ? (int) $soal['urutan'] : (((int) val('SELECT COALESCE(MAX(urutan),0) FROM soal WHERE ujian_id = ?', [$ujianId], 0)) + 10);
        if ($soal) {
            q('UPDATE soal SET jenis=?, pertanyaan=?, opsi=?, jawaban=?, skor=?, pembahasan=? WHERE id=? AND ujian_id=?',
                [$form['jenis'], $form['pertanyaan'], $pakaiOpsi ? opsi_encode($opsiMasuk) : null,
                    json_encode($jawaban, JSON_UNESCAPED_UNICODE), $form['skor'], $form['pembahasan'],
                    (int) $soal['id'], $ujianId]);
            flash('Soal diperbarui.', 'ok');
        } else {
            q('INSERT INTO soal (sekolah_id, ujian_id, jenis, pertanyaan, opsi, jawaban, skor, pembahasan, urutan, dibuat)
               VALUES (?,?,?,?,?,?,?,?,?,?)',
                [sekolah_id(), $ujianId, $form['jenis'], $form['pertanyaan'],
                    $pakaiOpsi ? opsi_encode($opsiMasuk) : null,
                    json_encode($jawaban, JSON_UNESCAPED_UNICODE), $form['skor'], $form['pembahasan'],
                    $urutan, date('Y-m-d H:i:s')]);
            flash('Soal ditambahkan.', 'ok');
        }
        redirect('ujian_edit.php?id=' . $ujianId . '#daftarSoal');
    }
}

$jumlahOpsi = max(6, count($opsi) + 1);
page_top($soal ? 'Ubah Soal' : 'Tambah Soal');
?>
<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="ujian" value="<?= (int) $ujianId ?>">
  <div class="grid g21">
    <div class="card">
      <div class="card-head soal-head">
        <h3><?= $soal ? 'Ubah Soal' : 'Tambah Soal' ?></h3>
        <div class="sub"><?= e((string) $uj['judul']) ?></div>
        <?php // TOMBOL "BUAT SOAL OTOMATIS" (permintaan pemilik) — berkedip merah, membuka aplikasi
              // bantu penyusun soal di tab baru. TAUTANNYA diisi Super Admin lewat menu
              // "Soal Otomatis" (`soal_otomatis.php`); kata sandi masih di inc/config.php. ?>
        <div class="kanan soal-otomatis">
          <a class="btn btn-soal-otomatis" id="btnSoalOtomatis"
             href="<?= e(soal_otomatis_url()) ?>" target="_blank" rel="noopener noreferrer"
             title="Buka aplikasi bantu penyusun soal (tab baru)"><?= ikon('buku', 16) ?> Buat Soal Otomatis</a>
          <span class="soal-otomatis-ket">Password aplikasi:
            <b id="sandiSoalOtomatis"><?= e(SOAL_OTOMATIS_PASSWORD) ?></b></span>
        </div>
      </div>
      <div class="card-body">
        <div class="form-grid f2">
          <div class="field"><label for="jenis">Jenis Soal</label>
            <select id="jenis" name="jenis">
              <?php foreach (JENIS_SOAL as $kode => $label): ?>
                <option value="<?= e($kode) ?>"<?= $form['jenis'] === $kode ? ' selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="ket">Pilihan yang dipakai: <?= e(implode(', ', array_values(JENIS_SOAL))) ?>.</div></div>
          <div class="field"><label for="skor">Skor Maksimal</label>
            <input type="number" id="skor" name="skor" min="0.5" max="100" step="0.5" value="<?= e(num_input($form['skor'])) ?>"></div>
        </div>

        <div class="field"><label for="pertanyaan">Pertanyaan / Perintah</label>
          <textarea id="pertanyaan" name="pertanyaan" rows="4" required
            placeholder="Tulis pertanyaan, atau kalimat pengantar untuk soal menjodohkan/benar-salah."><?= e($form['pertanyaan']) ?></textarea></div>

        <!-- Pilihan ganda & kompleks -->
        <div class="blok-jenis" data-jenis="pg pgk">
          <p class="section-title">Pilihan Jawaban</p>
          <?php for ($i = 0; $i < $jumlahOpsi; $i++): ?>
            <div class="field" style="display:flex;gap:10px;align-items:center">
              <div class="opsi-huruf"><?= e(huruf_opsi($i)) ?></div>
              <input type="text" name="opsi_pg[]" value="<?= e((string) ($opsi[$i] ?? '')) ?>"
                     placeholder="<?= $i < 2 ? 'wajib' : 'kosongkan bila tidak dipakai' ?>">
            </div>
          <?php endfor; ?>
          <div class="blok-jenis" data-jenis="pg">
            <p class="section-title">Kunci Jawaban (pilih satu)</p>
            <?php for ($i = 0; $i < $jumlahOpsi; $i++): ?>
              <label class="cek"><input type="radio" name="kunci_pg" value="<?= $i ?>"
                <?= (is_int($kunci) && $kunci === $i) ? 'checked' : '' ?>> <?= e(huruf_opsi($i)) ?></label>
            <?php endfor; ?>
          </div>
          <div class="blok-jenis" data-jenis="pgk">
            <p class="section-title">Kunci Jawaban (boleh lebih dari satu)</p>
            <?php for ($i = 0; $i < $jumlahOpsi; $i++): ?>
              <label class="cek"><input type="checkbox" name="kunci_pgk[]" value="<?= $i ?>"
                <?= (is_array($kunci) && in_array($i, array_map('intval', $kunci), true)) ? 'checked' : '' ?>> <?= e(huruf_opsi($i)) ?></label>
            <?php endfor; ?>
            <div class="ket">Nilai sebagian dihitung: (kunci benar terpilih &minus; pilihan salah) &divide; jumlah kunci.</div>
          </div>
        </div>

        <!-- Benar / Salah -->
        <div class="blok-jenis" data-jenis="bs">
          <p class="section-title">Pernyataan &amp; Kunci</p>
          <?php for ($i = 0; $i < $jumlahOpsi; $i++): ?>
            <div class="field" style="display:flex;gap:10px;align-items:center">
              <div class="opsi-huruf"><?= $i + 1 ?></div>
              <input type="text" name="opsi_bs[]" value="<?= e((string) ($opsi[$i] ?? '')) ?>" placeholder="pernyataan">
              <select name="bs[]" style="width:120px">
                <?php $nilaibs = is_array($kunci) ? (string) ($kunci[$i] ?? '') : ''; ?>
                <option value="B"<?= $nilaibs === 'B' ? ' selected' : '' ?>>Benar</option>
                <option value="S"<?= $nilaibs === 'S' ? ' selected' : '' ?>>Salah</option>
              </select>
            </div>
          <?php endfor; ?>
          <div class="ket">Isi pernyataan pada kolom teks dan tentukan Benar/Salah. Pernyataan kosong diabaikan.</div>
        </div>

        <!-- Menjodohkan -->
        <div class="blok-jenis" data-jenis="jodoh">
          <p class="section-title">Pasangan (kiri &rarr; kanan)</p>
          <?php for ($i = 0; $i < $jumlahOpsi; $i++): ?>
            <div class="field" style="display:flex;gap:10px;align-items:center">
              <div class="opsi-huruf"><?= e(huruf_opsi($i)) ?></div>
              <input type="text" name="opsi_jodoh[]" value="<?= e((string) ($opsi[$i] ?? '')) ?>" placeholder="pernyataan kiri">
              <span>&rarr;</span>
              <input type="text" name="jodoh[]" value="<?= e(is_array($kunci) ? (string) ($kunci[$i] ?? '') : '') ?>" placeholder="pasangan kanan">
            </div>
          <?php endfor; ?>
          <div class="ket">Pilihan kanan diacak otomatis saat ujian berlangsung sehingga tidak bisa ditebak dari urutannya.</div>
        </div>

        <!-- Isian & Uraian -->
        <div class="blok-jenis" data-jenis="isian uraian">
          <p class="section-title">Kunci Penilaian</p>
          <div class="field">
            <textarea name="kunci_teks" rows="4" placeholder="satu kunci per baris"><?= e($kunciTeks) ?></textarea>
            <div class="ket" id="ketKunci">Isian singkat: tulis semua alternatif jawaban benar — satu per baris
              (boleh juga dipisah tanda <b>|</b>). Uraian: tulis kata kunci penilaian dengan cara yang sama
              (alternatif kata dalam satu kunci ditulis dengan tanda "/").</div>
          </div>
        </div>

        <div class="field"><label for="pembahasan">Pembahasan (opsional)</label>
          <textarea id="pembahasan" name="pembahasan" rows="2"><?= e($form['pembahasan']) ?></textarea></div>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h3>Bantuan</h3></div>
      <div class="card-body">
        <ul class="poin-list">
          <li><b>Pilihan Ganda</b> — siswa memilih satu jawaban; dinilai otomatis.</li>
          <li><b>Pilihan Ganda Kompleks</b> — siswa memilih beberapa jawaban; nilai sebagian dihitung.</li>
          <li><b>Benar/Salah</b> — beberapa pernyataan, masing-masing Benar atau Salah.</li>
          <li><b>Menjodohkan</b> — pasangkan pernyataan kiri dengan pasangan kanan (kanan diacak).</li>
          <li><b>Isian Singkat</b> — jawaban diketik; dinilai otomatis dari daftar alternatif jawaban.</li>
          <li><b>Uraian/Essai</b> — jawaban diketik bebas; dinilai otomatis dari kata kunci
            lalu <b>dapat disesuaikan guru</b> pada menu Hasil &amp; Analisis.</li>
          <li><b>Soal bergambar</b> — unggah gambar pada kartu <b>Gambar Soal</b> di bawah formulir;
            gambar tampil pada layar siswa dan ikut pada Bank Soal (unduh Word &amp; cetak).</li>
        </ul>
      </div>
    </div>
  </div>
  <div class="form-kaki">
    <button class="btn btn-utama" type="submit"><?= $soal ? 'Simpan Soal' : 'Tambahkan Soal' ?></button>
    <a class="btn" href="ujian_edit.php?id=<?= (int) $ujianId ?>#daftarSoal">Kembali ke Daftar Soal</a>
  </div>
</form>

<?php $gambarSoal = $soal ? soal_gambar($soal) : ''; ?>
<div class="grid g21" id="cardGambarSoal">
  <div class="card">
    <div class="card-head">
      <h3>Gambar Soal</h3>
      <div class="sub">Soal bergambar: grafik, peta, diagram, atau ilustrasi</div>
    </div>
    <div class="card-body">
      <?php if ($soal): ?>
        <div class="center mb16">
          <?php if ($gambarSoal !== ''): ?>
            <img src="<?= e($gambarSoal) ?>" alt="Gambar soal" class="gambar-soal-pratinjau" id="pratinjauGambar">
          <?php else: ?>
            <img src="" alt="" class="gambar-soal-pratinjau" id="pratinjauGambar" hidden>
            <div class="kosong">Belum ada gambar pada soal ini.</div>
          <?php endif; ?>
        </div>
        <form method="post" action="upload_soal_gambar.php" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <input type="hidden" name="aksi" value="unggah">
          <input type="hidden" name="ujian" value="<?= (int) $ujianId ?>">
          <input type="hidden" name="soal" value="<?= (int) $soal['id'] ?>">
          <div class="field">
            <label for="berkasGambar">Pilih Berkas Gambar</label>
            <input type="file" id="berkasGambar" name="gambar" accept="image/*">
            <div class="ket" id="pesanGambar">JPG, PNG, GIF, WEBP, atau SVG — maksimal 15 MB.
              Gambar tampil pada layar ujian siswa dan ikut pada Bank Soal (unduh Word &amp; cetak).</div>
          </div>
          <button class="btn btn-sm btn-utama" type="submit">Unggah / Ganti Gambar</button>
        </form>
        <?php if ($gambarSoal !== ''): ?>
          <form method="post" action="upload_soal_gambar.php" class="mt8" data-confirm="Hapus gambar pada soal ini?">
            <?= csrf_field() ?>
            <input type="hidden" name="aksi" value="hapus">
            <input type="hidden" name="ujian" value="<?= (int) $ujianId ?>">
            <input type="hidden" name="soal" value="<?= (int) $soal['id'] ?>">
            <button class="btn btn-sm btn-bahaya" type="submit">Hapus Gambar</button>
          </form>
        <?php endif; ?>
      <?php else: ?>
        <div class="alert alert-info" style="margin-bottom:0">
          <b>Simpan soal ini terlebih dahulu</b>, lalu buka kembali untuk mengunggah gambar.
          Gambar menempel pada soal yang sudah tersimpan (pola yang sama dengan bukti foto).
        </div>
      <?php endif; ?>
    </div>
  </div>
  <div class="card">
    <div class="card-head"><h3>Petunjuk Gambar</h3></div>
    <div class="card-body">
      <ul class="poin-list">
        <li>Gambar diletakkan <b>di bawah pertanyaan</b> pada layar siswa.</li>
        <li>Ukuran disarankan <b>lebar 600–1200 piksel</b> agar tajam saat dicetak.</li>
        <li>Gambar ikut tercetak pada menu <b>Bank Soal</b> dan pada unduhan Word.</li>
        <li>Bila ingin mengganti gambar, cukup unggah berkas baru (gambar lama diganti).</li>
      </ul>
    </div>
  </div>
</div>

<script>
(function () {
  // Pratinjau gambar sebelum diunggah (pelengkap; unggahan tetap bekerja tanpa JavaScript).
  var inp = document.getElementById('berkasGambar');
  if (!inp) { return; }
  inp.addEventListener('change', function () {
    var f = inp.files && inp.files[0];
    var pesan = document.getElementById('pesanGambar');
    if (!f) { return; }
    if (f.size > 15 * 1024 * 1024) {
      if (pesan) { pesan.textContent = 'Ukuran gambar maksimal 15 MB.'; }
      inp.value = '';
      return;
    }
    if (pesan) { pesan.textContent = 'Berkas siap diunggah: ' + f.name; }
    var baca = new FileReader();
    baca.onload = function (e) {
      var img = document.getElementById('pratinjauGambar');
      if (img) { img.src = e.target.result; img.hidden = false; }
    };
    baca.readAsDataURL(f);
  });
})();
</script>

<script>
(function () {
  var sel = document.getElementById('jenis');
  function atur() {
    var j = sel.value;
    document.querySelectorAll('.blok-jenis[data-jenis]').forEach(function (b) {
      b.hidden = b.getAttribute('data-jenis').split(' ').indexOf(j) < 0;
    });
  }
  sel.addEventListener('change', atur);
  atur();
})();
</script>
<?php page_bottom();
