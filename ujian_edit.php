<?php
/**
 * Kelola satu ujian: pengaturan, kelas sasaran, daftar soal, dan status aktivasi.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

$u = require_sekolah();
if (!is_guru() && !is_operator() && !is_super()) {
    tolak403('Halaman ini untuk akun Guru atau pengelola sekolah.');
}

$id = (int) ($_GET['id'] ?? 0);
$guruId = (int) (guru_saya()['id'] ?? 0);
$uj = $id > 0 ? ujian_by_id($id) : null;
if ($id > 0 && !$uj) {
    flash('Ujian tidak ditemukan.', 'err');
    redirect('ujian.php');
}
if ($uj && is_guru() && (int) $uj['guru_id'] !== $guruId) {
    tolak403('Ujian ini bukan milik Anda.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $aksi = teks($_POST['aksi'] ?? '', 24);

    if ($aksi === 'simpan') {
        $judul = teks($_POST['judul'] ?? '', UJIAN_JUDUL_MAKS);
        $mapelId = (int) ($_POST['mapel_id'] ?? 0);
        $jenis = teks($_POST['jenis_ujian'] ?? 'Ulangan Harian', 40);
        $durasi = (int) ($_POST['durasi'] ?? 60);
        $keterangan = teks($_POST['keterangan'] ?? '', 500);
        $kktpRaw = str_replace(',', '.', (string) ($_POST['kktp'] ?? ''));
        $kktp = $kktpRaw === '' ? 0.0 : max(0, min(100, (float) $kktpRaw));
        $acakSoal = isset($_POST['acak_soal']) ? 1 : 0;
        $acakOpsi = isset($_POST['acak_opsi']) ? 1 : 0;
        $tampilHasil = isset($_POST['tampilkan_hasil']) ? 1 : 0;
        $kelasIds = array_map('intval', pos_arr('kelas'));

        if ($judul === '') {
            flash('Judul ujian wajib diisi.', 'err');
        } elseif ($mapelId > 0 && !mapel_by_id($mapelId)) {
            flash('Mata pelajaran tidak dikenal.', 'err');
        } else {
            $durasi = max(5, min(300, $durasi));
            $mapelVal = $mapelId > 0 ? $mapelId : null;
            db()->exec('BEGIN IMMEDIATE');
            try {
                $kktpVal = $kktp > 0 ? $kktp : null;
                if ($uj) {
                    q('UPDATE ujian SET judul=?, mapel_id=?, jenis_ujian=?, durasi=?, keterangan=?, kktp=?,
                            acak_soal=?, acak_opsi=?, tampilkan_hasil=?, diubah=? WHERE id=? AND sekolah_id=?',
                        [$judul, $mapelVal, $jenis, $durasi, $keterangan, $kktpVal, $acakSoal, $acakOpsi,
                            $tampilHasil, date('Y-m-d H:i:s'), (int) $uj['id'], sekolah_id()]);
                    $uid = (int) $uj['id'];
                } else {
                    q('INSERT INTO ujian (sekolah_id, mapel_id, guru_id, judul, keterangan, jenis_ujian,
                            durasi, kktp, acak_soal, acak_opsi, tampilkan_hasil, status, dibuat, diubah)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                        [sekolah_id(), $mapelVal, is_guru() ? $guruId : null, $judul, $keterangan, $jenis,
                            $durasi, $kktpVal, $acakSoal, $acakOpsi, $tampilHasil, 'draft',
                            date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
                    $uid = (int) db()->lastInsertId();
                }
                // KKTP juga disimpan pada MATA PELAJARAN-nya sehingga ujian berikutnya
                // untuk mapel yang sama otomatis memakai angka yang sama.
                if ($mapelId > 0 && $kktp > 0) {
                    kktp_mapel_simpan($mapelId, $kktp);
                }
                q('DELETE FROM ujian_kelas WHERE ujian_id = ?', [$uid]);
                foreach (array_unique($kelasIds) as $kid) {
                    if ($kid > 0 && kelas_by_id($kid)) {
                        q('INSERT OR IGNORE INTO ujian_kelas (ujian_id, kelas_id) VALUES (?, ?)', [$uid, $kid]);
                    }
                }
                db()->exec('COMMIT');
                flash($uj ? 'Pengaturan ujian disimpan.' : 'Ujian dibuat. Sekarang tambahkan soalnya.', 'ok');
                redirect('ujian_edit.php?id=' . $uid . '#daftarSoal');
            } catch (Throwable $ex) {
                db()->exec('ROLLBACK');
                flash('Gagal menyimpan ujian.', 'err');
            }
        }
        redirect($uj ? 'ujian_edit.php?id=' . (int) $uj['id'] : 'ujian_edit.php');
    }

    if (!$uj) {
        redirect('ujian_edit.php');
    }
    if (is_guru() && (int) $uj['guru_id'] !== $guruId) {
        tolak403('Ujian ini bukan milik Anda.');
    }
    $uid = (int) $uj['id'];

    if ($aksi === 'hapus_soal') {
        $sid = (int) ($_POST['soal_id'] ?? 0);
        q('DELETE FROM soal WHERE id = ? AND ujian_id = ? AND sekolah_id = ?', [$sid, $uid, sekolah_id()]);
        soal_urutkan($uid);
        flash('Soal dihapus.', 'warn');
    } elseif ($aksi === 'naik' || $aksi === 'turun') {
        $sid = (int) ($_POST['soal_id'] ?? 0);
        soal_geser($uid, $sid, $aksi === 'naik' ? -1 : 1);
        flash('Urutan soal diperbarui.', 'ok');
    } elseif ($aksi === 'aktif' || $aksi === 'draf' || $aksi === 'selesai') {
        $n = (int) val('SELECT COUNT(*) FROM soal WHERE ujian_id = ?', [$uid], 0);
        $kn = count(ujian_kelas_ids($uid));
        if ($aksi === 'aktif' && ($n === 0 || $kn === 0)) {
            flash('Ujian belum dapat diaktifkan: lengkapi soal dan kelas sasarannya.', 'err');
        } else {
            q('UPDATE ujian SET status = ?, diubah = ? WHERE id = ?', [$aksi === 'aktif' ? 'aktif' : $aksi, date('Y-m-d H:i:s'), $uid]);
            flash($aksi === 'aktif' ? 'Ujian DIAKTIFKAN.' : 'Status ujian diubah.', 'ok');
        }
    } elseif ($aksi === 'reset_sesi') {
        q('DELETE FROM sesi_ujian WHERE ujian_id = ? AND sekolah_id = ?', [$uid, sekolah_id()]);
        flash('Seluruh sesi pengerjaan ujian ini dihapus (siswa dapat mengerjakan ulang).', 'warn');
    }
    redirect('ujian_edit.php?id=' . $uid);
}

/** Rapikan nomor urut soal. */
function soal_urutkan(int $ujianId): void
{
    $n = 10;
    foreach (all('SELECT id FROM soal WHERE ujian_id = ? ORDER BY urutan, id', [$ujianId]) as $r) {
        q('UPDATE soal SET urutan = ? WHERE id = ?', [$n, (int) $r['id']]);
        $n += 10;
    }
}

/** Geser satu soal naik/turun di dalam urutannya. */
function soal_geser(int $ujianId, int $soalId, int $arah): void
{
    $daftar = all('SELECT id FROM soal WHERE ujian_id = ? ORDER BY urutan, id', [$ujianId]);
    $ids = array_map(fn($r) => (int) $r['id'], $daftar);
    $pos = array_search($soalId, $ids, true);
    if ($pos === false) {
        return;
    }
    $tujuan = $pos + $arah;
    if ($tujuan < 0 || $tujuan >= count($ids)) {
        return;
    }
    $t = $ids[$pos];
    $ids[$pos] = $ids[$tujuan];
    $ids[$tujuan] = $t;
    $n = 10;
    foreach ($ids as $sid) {
        q('UPDATE soal SET urutan = ? WHERE id = ?', [$n, $sid]);
        $n += 10;
    }
}

$kktpNilai = $uj ? kktp_berlaku($uj) : (float) setting('kkm', '75');
$kktpMapelPeta = [];
foreach (mapel_list() as $m) {
    $kktpMapelPeta[(string) $m['id']] = kktp_mapel((int) $m['id']) > 0
        ? kktp_mapel((int) $m['id'])
        : (float) setting('kkm', '75');
}
$soal = $uj ? soal_ujian((int) $uj['id']) : [];
$kelasDipilih = $uj ? ujian_kelas_ids((int) $uj['id']) : [];
$skorMaks = 0.0;
foreach ($soal as $s) {
    $skorMaks += (float) $s['skor'];
}
$jumlahJenis = [];
foreach ($soal as $s) {
    $jumlahJenis[$s['jenis']] = ($jumlahJenis[$s['jenis']] ?? 0) + 1;
}

page_top($uj ? 'Kelola Ujian' : 'Buat Ujian Baru');
?>
<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="aksi" value="simpan">
  <div class="grid g21">
    <div class="card">
      <div class="card-head"><h3>Pengaturan Ujian</h3>
        <?php if ($uj): ?>
          <div class="kanan">
            <?php if ($uj['status'] === 'aktif'): ?>
              <span class="pil pil-ok">Aktif</span>
            <?php elseif ($uj['status'] === 'selesai'): ?>
              <span class="pil pil-mut">Selesai</span>
            <?php else: ?><span class="pil pil-warn">Draf</span><?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <div class="form-grid f2">
          <div class="field"><label for="judul">Judul Ujian</label>
            <input type="text" id="judul" name="judul" value="<?= e((string) ($uj['judul'] ?? '')) ?>" required
                   placeholder="mis. Ulangan Harian Matematika Bab 1"></div>
          <div class="field"><label for="mapel_id">Mata Pelajaran</label>
            <select id="mapel_id" name="mapel_id">
              <option value="0">- belum ditentukan -</option>
              <?php foreach (mapel_list() as $m): ?>
                <option value="<?= (int) $m['id'] ?>"<?= (int) ($uj['mapel_id'] ?? 0) === (int) $m['id'] ? ' selected' : '' ?>><?= e($m['nama']) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="field"><label for="jenis_ujian">Jenis Penilaian</label>
            <?php
            // Daftar jenis penilaian diatur Super Admin lewat menu "Jenis Penilaian"
            // (`jenis_ujian.php`) — JANGAN dipatok di sini lagi. Nilai yang sudah tersimpan pada
            // ujian ini tetap ditampilkan walau jenisnya sudah dihapus dari daftar, supaya ujian
            // lama tidak kehilangan labelnya saat disunting.
            $jenisTersedia = jenis_ujian_list();
            $jenisKini = (string) ($uj['jenis_ujian'] ?? '');
            if ($jenisKini !== '' && !in_array($jenisKini, $jenisTersedia, true)) {
                $jenisTersedia[] = $jenisKini;
            }
            ?>
            <select id="jenis_ujian" name="jenis_ujian">
              <?php foreach ($jenisTersedia as $jt): ?>
                <option value="<?= e($jt) ?>"<?= $jenisKini === $jt ? ' selected' : '' ?>><?= e($jt) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="ket">Daftar pilihan ini diatur Super Admin pada menu <b>Jenis Penilaian</b>.</div>
          </div>
          <div class="field"><label for="durasi">Durasi (menit)</label>
            <input type="number" id="durasi" name="durasi" min="5" max="300" value="<?= (int) ($uj['durasi'] ?? 60) ?>"></div>
          <div class="field">
            <label for="kktp">KKTP — Kriteria Ketercapaian Tujuan Pembelajaran</label>
            <input type="number" id="kktp" name="kktp" min="0" max="100" step="0.5"
                   value="<?= e(num_input($kktpNilai)) ?>" data-kktp-mapel='<?= e(json_encode($kktpMapelPeta)) ?>'>
            <div class="ket">Batas ketuntasan untuk ujian ini. Nilainya <b>disimpan per mata pelajaran</b>,
              jadi ujian berikutnya pada mapel yang sama otomatis memakai angka yang sama.
              Dipakai untuk menentukan <b>Remedial</b> (di bawah KKTP) dan <b>Pengayaan</b> (mencapai KKTP).</div>
          </div>
        </div>
        <div class="field"><label for="keterangan">Keterangan / Petunjuk Ujian</label>
          <textarea id="keterangan" name="keterangan" placeholder="mis. Bacalah soal dengan teliti. Dilarang membuka tab lain."><?= e((string) ($uj['keterangan'] ?? '')) ?></textarea></div>
      </div>
    </div>

    <div>
      <div class="card">
        <div class="card-head"><h3>Kelas Sasaran</h3>
          <div class="sub">Ujian muncul pada siswa kelas yang dicentang</div></div>
        <div class="card-body">
          <?php if (!kelas_list()): ?>
            <div class="kosong">Belum ada kelas. Tambahkan kelas pada menu Data Kelas.</div>
          <?php endif; ?>
          <?php foreach (kelas_list() as $k): ?>
            <label class="cek"><input type="checkbox" name="kelas[]" value="<?= (int) $k['id'] ?>"
              <?= in_array((int) $k['id'], $kelasDipilih, true) ? 'checked' : '' ?>> <?= e($k['nama']) ?></label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="card">
        <div class="card-head"><h3>Pengaturan Pengerjaan</h3></div>
        <div class="card-body">
          <label class="cek"><input type="checkbox" name="acak_soal" value="1"<?= (int) ($uj['acak_soal'] ?? 0) === 1 ? ' checked' : '' ?>>
            Acak urutan soal</label>
          <label class="cek"><input type="checkbox" name="acak_opsi" value="1"<?= (int) ($uj['acak_opsi'] ?? 0) === 1 ? ' checked' : '' ?>>
            Acak urutan pilihan jawaban</label>
          <label class="cek"><input type="checkbox" name="tampilkan_hasil" value="1"<?= (int) ($uj['tampilkan_hasil'] ?? 1) === 1 ? ' checked' : '' ?>>
            Tampilkan nilai kepada siswa setelah ujian dikumpulkan</label>
        </div>
      </div>
    </div>
  </div>
  <div class="form-kaki">
    <button class="btn btn-utama" type="submit"><?= $uj ? 'Simpan Perubahan' : 'Buat Ujian' ?></button>
    <a class="btn" href="ujian.php">Kembali</a>
  </div>
</form>

<?php if ($uj): ?>
  <?php if ($uj['status'] !== 'aktif'): ?>
    <div class="card">
      <div class="card-head"><h3>Aktifkan Ujian</h3></div>
      <div class="card-body">
        <p class="mut">Ujian hanya dapat dikerjakan siswa setelah diaktifkan. Pastikan soal
          (<?= count($soal) ?> butir, skor maks <?= r1($skorMaks) ?>) dan kelas sasaran
          (<?= count($kelasDipilih) ?> kelas) sudah benar.</p>
        <form method="post" data-confirm="Aktifkan ujian ini untuk kelas sasaran?">
          <?= csrf_field() ?><input type="hidden" name="aksi" value="aktif">
          <button class="btn btn-utama" type="submit">Aktifkan Ujian</button>
        </form>
      </div>
    </div>
  <?php else: ?>
    <div class="card">
      <div class="card-head"><h3>Ujian Sedang Aktif</h3></div>
      <div class="card-body">
        <p class="mut">Siswa pada kelas sasaran dapat mengerjakan ujian ini. Bila pengerjaan sudah selesai,
          tandai ujian selesai agar tidak ada siswa baru yang memulai.</p>
        <div class="row-actions">
          <form method="post" data-confirm="Tandai ujian ini selesai?">
            <?= csrf_field() ?><input type="hidden" name="aksi" value="selesai">
            <button class="btn" type="submit">Tandai Selesai</button></form>
          <form method="post" data-confirm="Nonaktifkan ujian (kembali ke draf)?">
            <?= csrf_field() ?><input type="hidden" name="aksi" value="draf">
            <button class="btn btn-bahaya" type="submit">Kembalikan ke Draf</button></form>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <div class="card" id="daftarSoal">
    <div class="card-head">
      <h3>Daftar Soal</h3>
      <div class="sub"><?= count($soal) ?> soal &middot; skor maksimal <?= r1($skorMaks) ?>
        <?php foreach ($jumlahJenis as $j => $n): ?>&middot; <?= e(jenis_soal_label($j)) ?>: <?= (int) $n ?><?php endforeach; ?>
      </div>
      <div class="kanan">
        <a class="btn btn-sm btn-utama" href="soal_edit.php?ujian=<?= (int) $uj['id'] ?>">Tambah Soal</a>
        <a class="btn btn-sm" href="ujian_import.php?ujian=<?= (int) $uj['id'] ?>">Impor dari Template</a>
        <a class="btn btn-sm btn-utama" href="template_soal_word.php?ujian=<?= (int) $uj['id'] ?>">Template Word (.docx)</a>
        <a class="btn btn-sm" href="template_impor.php?jenis=soal">Template Excel/CSV</a>
      </div>
    </div>

    <?php if (!$soal): ?>
      <div class="kosong"><b>Belum ada soal pada ujian ini.</b>
        Unduh <b>Template Word (.docx)</b> di atas, tulis soalnya, lalu unggah kembali
        (berkas <b>.docx</b> maupun <b>.doc</b> diterima) lewat
        <b>Impor dari Template</b> — jauh lebih cepat daripada mengetik satu per satu.</div>
    <?php else: ?>
      <div class="card-body">
        <?php foreach ($soal as $i => $s): ?>
          <div class="soal-kartu" style="box-shadow:none">
            <div class="soal-head">
              <div class="soal-no"><?= $i + 1 ?></div>
              <div>
                <div class="soal-jenis"><?= e(jenis_soal_label($s['jenis'])) ?>
                  <?php if (soal_gambar($s) !== ''): ?><span class="pil pil-info">bergambar</span><?php endif; ?></div>
              </div>
              <div class="soal-skor">Skor maks: <?= r1($s['skor']) ?></div>
            </div>
            <div class="soal-teks"><?= nl2br(e((string) $s['pertanyaan'])) ?></div>
            <?php if (soal_gambar($s) !== ''): ?>
              <img src="<?= e(soal_gambar($s)) ?>" alt="Gambar soal" class="gambar-soal-mini">
            <?php endif; ?>
            <?php if ($s['opsi_arr']): ?>
              <div class="opsi-list">
                <?php foreach ($s['opsi_arr'] as $oi => $ot): ?>
                  <?php
                  $benar = false;
                  if ($s['jenis'] === 'pg') { $benar = ((int) $s['kunci'] === $oi); }
                  if ($s['jenis'] === 'pgk') { $benar = is_array($s['kunci']) && in_array($oi, array_map('intval', $s['kunci']), true); }
                  if ($s['jenis'] === 'jodoh') {
                      $pasang = is_array($s['kunci']) ? ($s['kunci'][$oi] ?? '') : '';
                      $ot = $ot . '  →  ' . $pasang;
                      $benar = true;
                  }
                  ?>
                  <div class="opsi<?= $benar ? ' on' : '' ?>">
                    <div class="opsi-huruf"><?= e(huruf_opsi($oi)) ?></div>
                    <div><?= e((string) $ot) ?></div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php elseif ($s['jenis'] === 'bs'): ?>
              <?php foreach ($s['opsi_arr'] as $oi => $ot): ?>
                <div class="bs-baris"><div class="teks"><?= e((string) $ot) ?></div>
                  <div><span class="pil pil-info"><?= e((string) (is_array($s['kunci']) ? ($s['kunci'][$oi] ?? '-') : '-')) ?></span></div></div>
              <?php endforeach; ?>
            <?php elseif (in_array($s['jenis'], ['isian', 'uraian'], true)): ?>
              <p class="mut"><b>Kunci:</b>
                <?= e(is_array($s['kunci']) ? implode(' | ', array_map('strval', $s['kunci'])) : (string) $s['kunci']) ?></p>
            <?php endif; ?>
            <div class="row-actions mt8">
              <a class="btn btn-sm" href="soal_edit.php?ujian=<?= (int) $uj['id'] ?>&id=<?= (int) $s['id'] ?>">Ubah</a>
              <form method="post" style="display:inline"><?= csrf_field() ?>
                <input type="hidden" name="aksi" value="naik"><input type="hidden" name="soal_id" value="<?= (int) $s['id'] ?>">
                <button class="btn btn-sm" type="submit">▲</button></form>
              <form method="post" style="display:inline"><?= csrf_field() ?>
                <input type="hidden" name="aksi" value="turun"><input type="hidden" name="soal_id" value="<?= (int) $s['id'] ?>">
                <button class="btn btn-sm" type="submit">▼</button></form>
              <form method="post" style="display:inline" data-confirm="Hapus soal nomor <?= $i + 1 ?>?">
                <?= csrf_field() ?><input type="hidden" name="aksi" value="hapus_soal">
                <input type="hidden" name="soal_id" value="<?= (int) $s['id'] ?>">
                <button class="btn btn-sm btn-bahaya" type="submit">Hapus</button></form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <?php
  $sesi = all("SELECT su.*, sw.nama, sw.nis, k.nama AS kelas FROM sesi_ujian su
      JOIN siswa sw ON sw.id = su.siswa_id LEFT JOIN kelas k ON k.id = sw.kelas_id
      WHERE su.ujian_id = ? ORDER BY su.status DESC, su.nilai DESC", [(int) $uj['id']]);
  ?>
  <div class="card">
    <div class="card-head"><h3>Sesi Pengerjaan</h3>
      <div class="sub"><?= count($sesi) ?> sesi</div>
      <div class="kanan"><a class="btn btn-sm" href="hasil_ujian.php?id=<?= (int) $uj['id'] ?>">Lihat Hasil &amp; Analisis</a></div>
    </div>
    <div class="table-wrap">
      <table class="tbl">
        <thead><tr><th>No</th><th>Siswa</th><th>Kelas</th><th>Mulai</th><th>Status</th>
          <th class="center">Pelanggaran</th><th class="kanan">Nilai</th></tr></thead>
        <tbody>
        <?php foreach ($sesi as $i => $r): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td><b><?= e($r['nama']) ?></b> <small class="mut"><?= e((string) $r['nis']) ?></small></td>
            <td><?= e((string) ($r['kelas'] ?: '-')) ?></td>
            <td><small><?= e($r['mulai'] ? waktu_id((string) $r['mulai']) : '-') ?></small></td>
            <td>
              <?php if ($r['status'] === 'mengerjakan'): ?><span class="pil pil-warn">Mengerjakan</span>
              <?php elseif ($r['status'] === 'dikumpulkan'): ?><span class="pil pil-err">Dikumpulkan (pelanggaran)</span>
              <?php else: ?><span class="pil pil-ok">Selesai</span><?php endif; ?>
              <?php if ((int) $r['perlu_koreksi'] === 1 && $r['status'] !== 'mengerjakan'): ?>
                <span class="pil pil-info">uraian</span><?php endif; ?>
            </td>
            <td class="center"><?= (int) $r['pelanggaran'] ?></td>
            <td class="kanan"><b><?= r1($r['nilai'] ?? 0) ?></b></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$sesi): ?><tr><td colspan="7" class="kosong">Belum ada siswa yang mengerjakan.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php if ($sesi): ?>
      <div class="card-body">
        <form method="post" data-confirm="Hapus SEMUA sesi pengerjaan ujian ini? Siswa harus mengerjakan ulang.">
          <?= csrf_field() ?><input type="hidden" name="aksi" value="reset_sesi">
          <button class="btn btn-bahaya btn-sm" type="submit">Hapus Semua Sesi (izinkan mengerjakan ulang)</button>
        </form>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>
<script>
(function () {
  // Memilih mata pelajaran mengisi KKTP dengan angka yang tersimpan untuk mapel itu,
  // sehingga guru tidak perlu mengetik ulang setiap kali membuat soal.
  var sel = document.getElementById('mapel_id');
  var inp = document.getElementById('kktp');
  if (!sel || !inp) { return; }
  var peta = {};
  try { peta = JSON.parse(inp.getAttribute('data-kktp-mapel') || '{}'); } catch (e) { peta = {}; }
  sel.addEventListener('change', function () {
    var v = peta[sel.value];
    if (v !== undefined) { inp.value = v; }
  });
})();
</script>
<?php page_bottom();
