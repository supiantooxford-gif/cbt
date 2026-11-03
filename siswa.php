<?php
/** Data Siswa (operator / Super Admin dalam konteks) + cetak & impor. */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

require_operator();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $aksi = teks($_POST['aksi'] ?? '', 20);
    $id = (int) ($_POST['id'] ?? 0);

    // ---------------------------------------------------------------- aksi TINGKAT SEKOLAH
    // Aksi berikut tidak memakai satu `id` siswa, jadi WAJIB ditangani SEBELUM penjaga
    // per-siswa di bawah (kalau tidak, selalu dijawab "Data siswa tidak ditemukan").
    if ($aksi === 'sandi_siap' || $aksi === 'sandi_lupakan') {
        // Kata sandi disimpan sebagai HASH (bcrypt) sehingga TIDAK dapat dibaca kembali.
        // Agar operator bisa membagikan akun & sandi kepada siswa (kolom Password pada cetak
        // data siswa dan Kartu Ujian), sandi baru DITETAPKAN untuk lingkup tertentu dan hasilnya
        // disimpan SEMENTARA pada sesi operator — bukan di basis data.
        $mode = teks($_POST['mode'] ?? 'acak', 10);
        $lingkup = teks($_POST['lingkup'] ?? 'belum', 10);
        $konfirmasi = teks($_POST['konfirmasi'] ?? '', 20);
        $sebelum = $_SESSION['sandi_siswa']['data'] ?? [];
        if ($lingkup === 'semua' && $konfirmasi !== 'SETUJUI') {
            flash('Untuk menetapkan sandi SEMUA siswa, ketik SETUJUI pada kolom konfirmasi.', 'err');
        } else {
            $sasaran = ($lingkup === 'semua')
                ? all('SELECT id, nis, nama FROM siswa WHERE sekolah_id = ? AND aktif = 1 ORDER BY nama', [sekolah_id()])
                : array_values(array_filter(
                    all('SELECT id, nis, nama FROM siswa WHERE sekolah_id = ? AND aktif = 1 ORDER BY nama', [sekolah_id()]),
                    fn($r) => !isset($sebelum[(int) $r['id']])
                ));
            if (!$sasaran) {
                flash('Tidak ada siswa yang perlu disiapkan sandinya pada lingkup itu.', 'warn');
            } else {
                $n = 0;
                $peta = $sebelum;
                db()->exec('BEGIN IMMEDIATE');
                try {
                    foreach ($sasaran as $r) {
                        $sid = (int) $r['id'];
                        if ($mode === 'nis') {
                            $sandi = trim((string) $r['nis']);
                            if (strlen($sandi) < 6) {
                                $sandi = sandi_acak(8);
                            }
                        } else {
                            $sandi = sandi_acak(8);
                        }
                        q('UPDATE users SET password = ? WHERE siswa_id = ? AND sekolah_id = ?',
                            [password_hash($sandi, PASSWORD_DEFAULT), $sid, sekolah_id()]);
                        $peta[$sid] = $sandi;
                        $n++;
                    }
                    db()->exec('COMMIT');
                } catch (Throwable $ex) {
                    db()->exec('ROLLBACK');
                    $n = 0;
                    flash('Gagal menetapkan sandi.', 'err');
                }
                if ($n > 0) {
                    $_SESSION['sandi_siswa'] = ['dibuat' => date('Y-m-d H:i:s'), 'data' => $peta];
                    flash($n . ' sandi siswa ditetapkan (' . ($mode === 'nis' ? 'memakai NIS' : 'acak 8 karakter')
                        . '). Sandi hanya tersimpan sementara di sesi ini — segera cetak/bagikan, '
                        . 'karena sandi lama tidak dapat dibaca kembali.', 'ok');
                }
            }
        }
        redirect('siswa.php');
    } elseif ($aksi === 'sandi_lupakan') {
        unset($_SESSION['sandi_siswa']);
        flash('Daftar sandi siap-bagi dibersihkan dari sesi.', 'warn');
        redirect('siswa.php');
    $sw = one('SELECT * FROM siswa WHERE id = ? AND sekolah_id = ?', [$id, sekolah_id()]);
    if (!$sw) {
        flash('Data siswa tidak ditemukan.', 'err');
        redirect('siswa.php');
    }
    } elseif ($aksi === 'hapus') {
        q('DELETE FROM siswa WHERE id = ? AND sekolah_id = ?', [$id, sekolah_id()]);
        flash('Data siswa "' . $sw['nama'] . '" dihapus.', 'warn');
    } elseif ($aksi === 'akun') {
        $ada = one('SELECT * FROM users WHERE siswa_id = ? AND sekolah_id = ?', [$id, sekolah_id()]);
        if ($ada) {
            flash('Siswa ini sudah punya akun: ' . $ada['username'], 'err');
        } else {
            $dasar = trim((string) $sw['nis']) !== '' ? (string) $sw['nis'] : username_dari_nama((string) $sw['nama']);
            $uname = username_unik($dasar);
            q('INSERT INTO users (sekolah_id, nama, username, password, role, siswa_id, aktif, dibuat)
               VALUES (?,?,?,?,?,?,1,?)',
                [sekolah_id(), $sw['nama'], $uname, password_hash('siswa123', PASSWORD_DEFAULT), 'siswa', $id, date('Y-m-d H:i:s')]);
            flash('Akun siswa dibuat — nama pengguna: ' . $uname . ', kata sandi awal: siswa123', 'ok');
        }
    } elseif ($aksi === 'akun_massal') {
        $belum = all('SELECT * FROM siswa WHERE sekolah_id = ? AND aktif = 1
            AND NOT EXISTS (SELECT 1 FROM users u WHERE u.siswa_id = siswa.id) ORDER BY nama', [sekolah_id()]);
        $n = 0;
        foreach ($belum as $b) {
            $dasar = trim((string) $b['nis']) !== '' ? (string) $b['nis'] : username_dari_nama((string) $b['nama']);
            $uname = username_unik($dasar);
            q('INSERT INTO users (sekolah_id, nama, username, password, role, siswa_id, aktif, dibuat)
               VALUES (?,?,?,?,?,?,1,?)',
                [sekolah_id(), $b['nama'], $uname, password_hash('siswa123', PASSWORD_DEFAULT), 'siswa', (int) $b['id'], date('Y-m-d H:i:s')]);
            $n++;
        }
        flash($n > 0 ? $n . ' akun siswa dibuat (kata sandi awal siswa123).' : 'Semua siswa aktif sudah punya akun.', $n > 0 ? 'ok' : 'warn');
    } elseif ($aksi === 'reset') {
        $baru = sandi_acak(8);
        q('UPDATE users SET password = ? WHERE siswa_id = ? AND sekolah_id = ?',
            [password_hash($baru, PASSWORD_DEFAULT), $id, sekolah_id()]);
        $_SESSION['sandi_sekali'] = ['nama' => (string) $sw['nama'], 'sandi' => $baru];
        flash('Kata sandi baru dibuat — catat sekarang, hanya ditampilkan sekali.', 'ok');
    } elseif ($aksi === 'toggle') {
        $baru = (int) $sw['aktif'] === 1 ? 0 : 1;
        q('UPDATE siswa SET aktif = ? WHERE id = ? AND sekolah_id = ?', [$baru, $id, sekolah_id()]);
        q('UPDATE users SET aktif = ? WHERE siswa_id = ? AND sekolah_id = ?', [$baru, $id, sekolah_id()]);
        flash('Status siswa diubah.', 'ok');
    }
    redirect('siswa.php');
}

$cari = teks($_GET['cari'] ?? '', 60);
$fKelas = (int) ($_GET['kelas'] ?? 0);
$fAkun = teks($_GET['akun'] ?? '', 10);
$fStatus = teks($_GET['status'] ?? '', 10);

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
$daftar = all($sql, $p);
$sandiSekali = $_SESSION['sandi_sekali'] ?? null;
unset($_SESSION['sandi_sekali']);
// Sandi siap-bagi dibiarkan pada sesi sampai operator membersihkannya sendiri, supaya
// lembar cetak (Kartu Ujian / Cetak Data Siswa) dapat dibuka berkali-kali tanpa mengeset ulang.
$sandiSiap = $_SESSION['sandi_siswa']['data'] ?? [];
$sandiSiapWaktu = $_SESSION['sandi_siswa']['dibuat'] ?? '';
$belumAkun = (int) val('SELECT COUNT(*) FROM siswa WHERE sekolah_id = ? AND aktif = 1 AND NOT EXISTS (SELECT 1 FROM users u WHERE u.siswa_id = siswa.id)', [sekolah_id()], 0);
$belumSandi = (int) val('SELECT COUNT(*) FROM siswa WHERE sekolah_id = ? AND aktif = 1', [sekolah_id()], 0) - count($sandiSiap);
if ($belumSandi < 0) { $belumSandi = 0; }

page_top('Data Siswa');
?>
<?php if ($sandiSekali): ?>
  <div class="alert alert-warn"><b>Kata sandi baru untuk <?= e($sandiSekali['nama']) ?>:</b>
    <span class="kotak-kode" style="margin:6px 0"><?= e($sandiSekali['sandi']) ?></span><br>
    Catat sekarang — sandi hanya ditampilkan sekali.</div>
<?php endif; ?>

<div class="card">
  <div class="card-head">
    <h3>Daftar Siswa</h3>
    <div class="sub"><?= count($daftar) ?> siswa ditampilkan &middot; <?= $belumAkun ?> siswa aktif belum berakun</div>
    <div class="kanan">
      <a class="btn btn-sm btn-utama" href="siswa_edit.php">Tambah Siswa</a>
      <a class="btn btn-sm" href="siswa_import.php">Impor Excel/CSV</a>
      <a class="btn btn-sm" href="cetak_siswa.php?<?= e(http_build_query(array_filter(['kelas' => $fKelas, 'cari' => $cari]))) ?>" target="_blank">Cetak Data Siswa</a>
      <a class="btn btn-sm btn-utama" href="cetak_kartu_ujian.php?<?= e(http_build_query(array_filter(['kelas' => $fKelas, 'cari' => $cari]))) ?>" target="_blank">Cetak Kartu Ujian</a>
      <a class="btn btn-sm" href="siswa_export.php?<?= e(http_build_query(array_filter(['cari' => $cari, 'kelas' => $fKelas, 'akun' => $fAkun, 'status' => $fStatus]))) ?>">Ekspor Data Siswa</a>
    </div>
  </div>
  <div class="card-body" style="padding-bottom:0">
    <form method="get" class="form-grid f4" style="align-items:end">
      <div class="field"><label>Cari Nama/NIS/NISN</label><input type="text" name="cari" value="<?= e($cari) ?>"></div>
      <div class="field"><label>Kelas</label><select name="kelas"><option value="0">- semua -</option>
        <?php foreach (kelas_list() as $k): ?>
          <option value="<?= (int) $k['id'] ?>"<?= $fKelas === (int) $k['id'] ? ' selected' : '' ?>><?= e($k['nama']) ?></option>
        <?php endforeach; ?></select></div>
      <div class="field"><label>Akun</label><select name="akun">
        <option value="">- semua -</option>
        <option value="ada"<?= $fAkun === 'ada' ? ' selected' : '' ?>>Sudah punya akun</option>
        <option value="belum"<?= $fAkun === 'belum' ? ' selected' : '' ?>>Belum punya akun</option></select></div>
      <div class="field"><label>Status Siswa</label><select name="status">
        <option value="">- semua -</option>
        <?php foreach (siswa_status_list() as $kode => $label): ?>
          <option value="<?= e($kode) ?>"<?= $fStatus === $kode ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?></select></div>
      <div class="field"><label>&nbsp;</label><button class="btn btn-sm btn-utama" type="submit">Terapkan</button>
        <a class="btn btn-sm" href="siswa.php">Reset</a></div>
    </form>
    <?php if ($belumAkun > 0): ?>
      <form method="post" class="mb16" data-confirm="Buat akun untuk SEMUA siswa aktif yang belum punya akun?">
        <?= csrf_field() ?><input type="hidden" name="aksi" value="akun_massal">
        <button class="btn btn-sm btn-ok" type="submit">Buat Akun Massal (<?= $belumAkun ?> siswa)</button>
      </form>
    <?php endif; ?>
  </div>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>No</th><th>NIS</th><th>NISN</th><th>Nama Siswa</th><th>JK</th><th>Kelas</th>
        <th>Tempat/Tanggal Lahir</th><th>Wali</th><th>Kontak</th><th>Akun</th><th>Status</th><th class="kanan">Aksi</th></tr></thead>
      <tbody>
      <?php foreach ($daftar as $i => $r): ?>
        <tr>
          <td><?= $i + 1 ?></td>
          <td><?= e((string) ($r['nis'] ?: '-')) ?></td>
          <td><?= e((string) ($r['nisn'] ?: '-')) ?></td>
          <td><b><?= e($r['nama']) ?></b></td>
          <td><?= e((string) ($r['jenis_kelamin'] ?: '-')) ?></td>
          <td><?= e((string) ($r['kelas'] ?: '-')) ?></td>
          <td><small><?php
            $ttl = trim((string) $r['tempat_lahir']);
            if (trim((string) $r['tanggal_lahir']) !== '') {
                $ttl .= ($ttl !== '' ? ', ' : '') . tanggal_id((string) $r['tanggal_lahir']);
            }
            echo e($ttl !== '' ? $ttl : '-');
          ?></small></td>
          <td><small><?php
            $kel = [];
            if (trim((string) $r['nama_ayah']) !== '') { $kel[] = 'Ayah: ' . $r['nama_ayah']; }
            if (trim((string) $r['nama_ibu']) !== '') { $kel[] = 'Ibu: ' . $r['nama_ibu']; }
            if (trim((string) $r['wali']) !== '') { $kel[] = 'Wali: ' . $r['wali']; }
            echo e($kel ? implode(' · ', $kel) : '-');
          ?></small></td>
          <td><small><?= e((string) ($r['telp'] ?: '-')) ?></small></td>
          <td><?= $r['username'] ? '<small>' . e((string) $r['username']) . '</small>' : '<span class="pil pil-mut">belum</span>' ?></td>
          <td><?php $st = (string) ($r['status'] ?? 'aktif'); ?>
            <?php if ($st === 'aktif' && (int) $r['aktif'] === 1): ?><span class="pil pil-ok">Aktif</span>
            <?php elseif ($st === 'lulus'): ?><span class="pil pil-emas">Lulus</span>
            <?php else: ?><span class="pil pil-mut">Nonaktif</span><?php endif; ?></td>
          <td class="kanan row-actions">
            <a class="btn btn-sm" href="siswa_edit.php?id=<?= (int) $r['id'] ?>">Ubah</a>
            <?php if (!$r['username']): ?>
              <form method="post" style="display:inline"><?= csrf_field() ?>
                <input type="hidden" name="aksi" value="akun"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                <button class="btn btn-sm btn-ok" type="submit">Buat Akun</button></form>
            <?php else: ?>
              <form method="post" style="display:inline" data-confirm="Buat kata sandi baru untuk siswa ini?">
                <?= csrf_field() ?><input type="hidden" name="aksi" value="reset"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                <button class="btn btn-sm" type="submit">Sandi Baru</button></form>
            <?php endif; ?>
            <form method="post" style="display:inline"><?= csrf_field() ?>
              <input type="hidden" name="aksi" value="toggle"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <button class="btn btn-sm" type="submit"><?= (int) $r['aktif'] === 1 ? 'Nonaktifkan' : 'Aktifkan' ?></button></form>
            <form method="post" style="display:inline" data-confirm="Hapus data siswa ini?">
              <?= csrf_field() ?><input type="hidden" name="aksi" value="hapus"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <button class="btn btn-sm btn-bahaya" type="submit">Hapus</button></form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$daftar): ?>
        <tr><td colspan="12" class="kosong"><b>Belum ada data siswa.</b>Tambahkan satu per satu atau
          impor dari berkas Excel/CSV (termasuk berkas ekspor e-GURU).</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card" id="cardSandiSiswa">
  <div class="card-head">
    <h3>Sandi Akun untuk Dibagikan</h3>
    <div class="sub">Kata sandi tersimpan sebagai hash — sandi hanya dapat ditetapkan ulang, bukan dibaca</div>
  </div>
  <div class="card-body">
    <div class="alert alert-info">
      <b>Mengapa perlu langkah ini?</b> Kata sandi siswa disimpan dalam bentuk teracak (hash) sehingga
      <b>tidak dapat ditampilkan kembali</b> dari data. Agar operator bisa membagikan akun dan sandi
      kepada siswa — termasuk pada <b>Cetak Kartu Ujian</b> dan kolom <b>Password</b> di
      <b>Cetak Data Siswa</b> — tetapkan sandi baru di bawah ini. Hasilnya hanya tersimpan sementara
      pada sesi operator ini dan tidak ditulis ke basis data.
    </div>

    <form method="post" class="form-grid f3" style="align-items:end">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="sandi_siap">
      <div class="field"><label for="mode">Bentuk Sandi</label>
        <select id="mode" name="mode">
          <option value="acak">Acak 8 karakter (aman)</option>
          <option value="nis">Memakai NIS siswa</option>
        </select></div>
      <div class="field"><label for="lingkup">Lingkup</label>
        <select id="lingkup" name="lingkup">
          <option value="belum">Hanya yang belum disiapkan (<?= $belumSandi ?> siswa)</option>
          <option value="semua">SEMUA siswa aktif (<?= count(array_filter($daftar, fn($r) => (int) $r['aktif'] === 1)) ?> siswa)</option>
        </select></div>
      <div class="field"><label for="konfirmasi">Konfirmasi (untuk lingkup "SEMUA")</label>
        <input type="text" id="konfirmasi" name="konfirmasi" placeholder="ketik SETUJUI"></div>
      <div class="field"><label>&nbsp;</label>
        <button class="btn btn-sm btn-utama" type="submit"
          data-confirm="Tetapkan sandi baru? Sandi siswa sebelumnya tidak dapat dibaca lagi.">Tetapkan Sandi</button></div>
    </form>
    <p class="mut" style="font-size:.8rem;margin:0">
      ⚠️ Menetapkan sandi <b>mengganti</b> sandi siswa yang bersangkutan. Setelah halaman ini
      ditutup/dibersihkan, sandi tersebut tidak dapat dilihat lagi.
    </p>

    <?php if ($sandiSiap): ?>
      <div class="divider"></div>
      <p class="section-title">Sandi siap dibagikan
        (<?= count($sandiSiap) ?> siswa · ditetapkan <?= e($sandiSiapWaktu ? waktu_id($sandiSiapWaktu) : '-') ?>)</p>
      <div class="row-actions mb16">
        <a class="btn btn-sm btn-utama" href="cetak_kartu_ujian.php?<?= e(http_build_query(array_filter(['kelas' => $fKelas, 'cari' => $cari]))) ?>" target="_blank">Cetak Kartu Ujian (berisi sandi)</a>
        <a class="btn btn-sm" href="cetak_siswa.php?<?= e(http_build_query(array_filter(['kelas' => $fKelas, 'cari' => $cari]))) ?>" target="_blank">Cetak Data Siswa (kolom Password)</a>
        <a class="btn btn-sm" href="cetak_kartu_ujian.php?<?= e(http_build_query(array_filter(['kelas' => $fKelas, 'auto' => 1]))) ?>" target="_blank">Kartu Ujian kelas <?= e($fKelas > 0 ? kelas_nama($fKelas) : 'semua') ?> + cetak</a>
        <form method="post" style="display:inline" data-confirm="Bersihkan daftar sandi dari sesi ini?">
          <?= csrf_field() ?>
          <input type="hidden" name="aksi" value="sandi_lupakan">
          <button class="btn btn-sm btn-bahaya" type="submit">Bersihkan dari Sesi</button>
        </form>
      </div>
      <div class="table-wrap">
        <table class="tbl">
          <thead><tr><th>No</th><th>Nama Siswa</th><th>NIS</th><th>Nama Pengguna</th>
            <th class="center">Kata Sandi</th><th class="kanan">Salin</th></tr></thead>
          <tbody>
          <?php
          $no = 0;
          foreach ($daftar as $r):
              $sid = (int) $r['id'];
              if (!isset($sandiSiap[$sid])) { continue; }
              $no++;
          ?>
            <tr>
              <td><?= $no ?></td>
              <td><b><?= e($r['nama']) ?></b></td>
              <td><?= e((string) ($r['nis'] ?: '-')) ?></td>
              <td><?= e((string) ($r['username'] ?: '-')) ?></td>
              <td class="center"><span class="kotak-kode" id="sandi<?= $sid ?>" style="font-size:.9rem"><?= e($sandiSiap[$sid]) ?></span></td>
              <td class="kanan"><button class="btn btn-sm" type="button" data-salin="#sandi<?= $sid ?>">Salin</button></td>
            </tr>
          <?php endforeach; ?>
          <?php if ($no === 0): ?>
            <tr><td colspan="6" class="kosong">Siswa yang sandinya disiapkan tidak tampil pada saringan ini
              — tekan <b>Reset</b> pada saringan di atas untuk melihatnya.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <p class="mut mt16" style="font-size:.82rem;margin-bottom:0">
        Belum ada sandi yang disiapkan pada sesi ini. Kolom <b>Password</b> pada cetak data siswa dan
        <b>Kartu Ujian</b> akan menampilkan tanda "-" sampai sandi ditetapkan di sini.
      </p>
    <?php endif; ?>
  </div>
</div>
<?php page_bottom();
