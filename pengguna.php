<?php
/**
 * Akun Pengguna.
 * - Super Admin TANPA konteks sekolah : mengelola akun Super Admin.
 * - Konteks sekolah                  : mengelola akun sekolah (operator/guru/siswa).
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

$u = require_login();
if (is_siswa()) {
    tolak403('Halaman ini tidak tersedia untuk akun siswa.');
}

$superLintas = is_super() && !current_sekolah();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    if ($superLintas) {
        require_super();
        $aksi = teks($_POST['aksi'] ?? '', 20);
        $id = (int) ($_POST['id'] ?? 0);
        $target = one('SELECT * FROM users WHERE id = ? AND role = ?', [$id, 'super']);
        if ($aksi === 'tambah') {
            $nama = teks($_POST['nama'] ?? '', 120);
            $uname = teks($_POST['username'] ?? '', 40);
            $sandi = (string) ($_POST['password'] ?? '');
            if ($nama === '' || !preg_match('/^[a-zA-Z0-9._]{4,30}$/', $uname) || strlen($sandi) < 8) {
                flash('Nama, nama pengguna (4-30 karakter), dan kata sandi (min 8) wajib benar.', 'err');
            } elseif (val('SELECT id FROM users WHERE username = ?', [$uname])) {
                flash('Nama pengguna sudah dipakai.', 'err');
            } else {
                q('INSERT INTO users (sekolah_id, nama, username, password, role, aktif, dibuat) VALUES (NULL,?,?,?,?,1,?)',
                    [$nama, $uname, password_hash($sandi, PASSWORD_DEFAULT), 'super', date('Y-m-d H:i:s')]);
                flash('Akun Super Admin "' . $uname . '" dibuat.', 'ok');
            }
        } elseif ($target && $aksi === 'reset') {
            $baru = sandi_acak(10);
            q('UPDATE users SET password = ? WHERE id = ?', [password_hash($baru, PASSWORD_DEFAULT), $id]);
            $_SESSION['sandi_sekali'] = ['nama' => (string) $target['nama'], 'sandi' => $baru];
            flash('Kata sandi baru dibuat — catat sekarang.', 'ok');
        } elseif ($target && $aksi === 'hapus') {
            if ((int) val("SELECT COUNT(*) FROM users WHERE role = 'super' AND aktif = 1", [], 0) <= 1) {
                flash('Akun Super Admin terakhir tidak boleh dihapus.', 'err');
            } elseif ((int) $target['id'] === (int) $u['id']) {
                flash('Anda tidak dapat menghapus akun Anda sendiri.', 'err');
            } else {
                q('DELETE FROM users WHERE id = ?', [$id]);
                flash('Akun Super Admin dihapus.', 'warn');
            }
        }
        redirect('pengguna.php');
    }

    // ------- konteks sekolah
    if (!is_operator() && !is_super()) {
        tolak403('Halaman ini untuk Admin Operator sekolah.');
    }
    $aksi = teks($_POST['aksi'] ?? '', 20);
    $id = (int) ($_POST['id'] ?? 0);
    $target = one('SELECT * FROM users WHERE id = ? AND sekolah_id = ?', [$id, sekolah_id()]);

    if ($aksi === 'tambah') {
        $nama = teks($_POST['nama'] ?? '', 120);
        $uname = teks($_POST['username'] ?? '', 40);
        $sandi = (string) ($_POST['password'] ?? '');
        $role = teks($_POST['role'] ?? 'guru', 10);
        if (!in_array($role, ['operator', 'guru'], true)) {
            $role = 'guru';
        }
        if ($nama === '' || !preg_match('/^[a-zA-Z0-9._]{4,30}$/', $uname) || strlen($sandi) < 6) {
            flash('Nama, nama pengguna (4-30 karakter), dan kata sandi (min 6) wajib benar.', 'err');
        } elseif (val('SELECT id FROM users WHERE username = ?', [$uname])) {
            flash('Nama pengguna sudah dipakai (nama pengguna unik se-aplikasi).', 'err');
        } else {
            q('INSERT INTO users (sekolah_id, nama, username, password, role, aktif, dibuat) VALUES (?,?,?,?,?,1,?)',
                [sekolah_id(), $nama, $uname, password_hash($sandi, PASSWORD_DEFAULT), $role, date('Y-m-d H:i:s')]);
            flash('Akun ' . role_label($role) . ' "' . $uname . '" dibuat.', 'ok');
        }
    } elseif ($target) {
        if ($aksi === 'reset') {
            $baru = sandi_acak(8);
            q('UPDATE users SET password = ? WHERE id = ?', [password_hash($baru, PASSWORD_DEFAULT), (int) $target['id']]);
            $_SESSION['sandi_sekali'] = ['nama' => (string) $target['nama'], 'sandi' => $baru];
            flash('Kata sandi baru dibuat — catat sekarang.', 'ok');
        } elseif ($aksi === 'toggle') {
            if ((int) $target['id'] === (int) $u['id']) {
                flash('Anda tidak dapat menonaktifkan akun Anda sendiri.', 'err');
            } else {
                q('UPDATE users SET aktif = ? WHERE id = ?', [(int) $target['aktif'] === 1 ? 0 : 1, (int) $target['id']]);
                flash('Status akun diubah.', 'ok');
            }
        } elseif ($aksi === 'hapus') {
            if ((int) $target['id'] === (int) $u['id']) {
                flash('Anda tidak dapat menghapus akun Anda sendiri.', 'err');
            } else {
                q('DELETE FROM users WHERE id = ?', [(int) $target['id']]);
                flash('Akun dihapus.', 'warn');
            }
        } elseif ($aksi === 'ubah') {
            $nama = teks($_POST['nama'] ?? '', 120);
            $uname = teks($_POST['username'] ?? '', 40);
            if ($nama === '' || !preg_match('/^[a-zA-Z0-9._]{4,30}$/', $uname)) {
                flash('Nama dan nama pengguna wajib benar.', 'err');
            } elseif (val('SELECT id FROM users WHERE username = ? AND id <> ?', [$uname, (int) $target['id']])) {
                flash('Nama pengguna sudah dipakai.', 'err');
            } else {
                q('UPDATE users SET nama = ?, username = ? WHERE id = ?', [$nama, $uname, (int) $target['id']]);
                flash('Akun diperbarui.', 'ok');
            }
        }
    }
    redirect('pengguna.php');
}

$sandiSekali = $_SESSION['sandi_sekali'] ?? null;
unset($_SESSION['sandi_sekali']);
$editId = (int) ($_GET['edit'] ?? 0);

if ($superLintas) {
    $daftar = all("SELECT * FROM users WHERE role = 'super' ORDER BY nama");
    page_top('Akun Super Admin');
    ?>
    <?php if ($sandiSekali): ?>
      <div class="alert alert-warn"><b>Kata sandi baru untuk <?= e($sandiSekali['nama']) ?>:</b>
        <span class="kotak-kode" style="margin:6px 0"><?= e($sandiSekali['sandi']) ?></span></div>
    <?php endif; ?>
    <div class="grid g21">
      <div class="card">
        <div class="card-head"><h3>Daftar Super Admin</h3><div class="sub"><?= count($daftar) ?> akun</div></div>
        <div class="table-wrap">
          <table class="tbl">
            <thead><tr><th>No</th><th>Nama</th><th>Nama Pengguna</th><th>Terakhir Masuk</th><th>Status</th><th class="kanan">Aksi</th></tr></thead>
            <tbody>
            <?php foreach ($daftar as $i => $r): ?>
              <tr>
                <td><?= $i + 1 ?></td>
                <td><b><?= e($r['nama']) ?></b><?= (int) $r['id'] === (int) $u['id'] ? ' <span class="pil pil-info">Anda</span>' : '' ?></td>
                <td><?= e($r['username']) ?></td>
                <td><?= e($r['masuk_terakhir'] ? waktu_id((string) $r['masuk_terakhir']) : '-') ?></td>
                <td><?= (int) $r['aktif'] === 1 ? '<span class="pil pil-ok">Aktif</span>' : '<span class="pil pil-mut">Nonaktif</span>' ?></td>
                <td class="kanan row-actions">
                  <form method="post" style="display:inline" data-confirm="Buat sandi baru?">
                    <?= csrf_field() ?><input type="hidden" name="aksi" value="reset"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                    <button class="btn btn-sm" type="submit">Sandi Baru</button></form>
                  <?php if ((int) $r['id'] !== (int) $u['id']): ?>
                  <form method="post" style="display:inline" data-confirm="Hapus akun Super Admin ini?">
                    <?= csrf_field() ?><input type="hidden" name="aksi" value="hapus"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                    <button class="btn btn-sm btn-bahaya" type="submit">Hapus</button></form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <div class="card">
        <div class="card-head"><h3>Tambah Super Admin</h3></div>
        <div class="card-body">
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="aksi" value="tambah">
            <div class="field"><label>Nama</label><input type="text" name="nama" required></div>
            <div class="field"><label>Nama Pengguna</label><input type="text" name="username" required></div>
            <div class="field"><label>Kata Sandi (min 8)</label><input type="password" name="password" required></div>
            <button class="btn btn-utama" type="submit">Tambah Akun</button>
          </form>
        </div>
      </div>
    </div>
    <?php
    page_bottom();
    exit;
}

// ------- akun sekolah
$s = current_sekolah();
if (!$s) {
    redirect('sekolah.php');
}
if (!is_operator() && !is_super()) {
    tolak403('Halaman ini untuk Admin Operator sekolah.');
}

$fRole = teks($_GET['role'] ?? '', 12);
$cari = teks($_GET['cari'] ?? '', 60);
$sql = 'SELECT * FROM users WHERE sekolah_id = ?';
$p = [sekolah_id()];
if ($fRole !== '' && in_array($fRole, ['operator', 'guru', 'siswa'], true)) {
    $sql .= ' AND role = ?';
    $p[] = $fRole;
}
if ($cari !== '') {
    $sql .= ' AND (nama LIKE ? OR username LIKE ?)';
    array_push($p, '%' . $cari . '%', '%' . $cari . '%');
}
$sql .= ' ORDER BY CASE role WHEN \'operator\' THEN 1 WHEN \'guru\' THEN 2 ELSE 3 END, nama';
$daftar = all($sql, $p);
$editRow = $editId > 0 ? one('SELECT * FROM users WHERE id = ? AND sekolah_id = ?', [$editId, sekolah_id()]) : null;

page_top('Akun Pengguna');
?>
<?php if ($sandiSekali): ?>
  <div class="alert alert-warn"><b>Kata sandi baru untuk <?= e($sandiSekali['nama']) ?>:</b>
    <span class="kotak-kode" style="margin:6px 0"><?= e($sandiSekali['sandi']) ?></span><br>
    Catat sekarang — sandi hanya ditampilkan sekali.</div>
<?php endif; ?>

<div class="grid g21">
  <div class="card">
    <div class="card-head"><h3>Daftar Akun Sekolah</h3><div class="sub"><?= count($daftar) ?> akun ditampilkan</div></div>
    <div class="card-body" style="padding-bottom:0">
      <form method="get" class="form-grid f3" style="align-items:end">
        <div class="field"><label>Cari Nama / Nama Pengguna</label><input type="text" name="cari" value="<?= e($cari) ?>"></div>
        <div class="field"><label>Peran</label><select name="role">
          <option value="">- semua -</option>
          <option value="operator"<?= $fRole === 'operator' ? ' selected' : '' ?>>Admin Operator</option>
          <option value="guru"<?= $fRole === 'guru' ? ' selected' : '' ?>>Guru</option>
          <option value="siswa"<?= $fRole === 'siswa' ? ' selected' : '' ?>>Siswa</option></select></div>
        <div class="field"><label>&nbsp;</label><button class="btn btn-sm btn-utama" type="submit">Terapkan</button>
          <a class="btn btn-sm" href="pengguna.php">Reset</a></div>
      </form>
    </div>
    <div class="table-wrap">
      <table class="tbl">
        <thead><tr><th>No</th><th>Nama</th><th>Nama Pengguna</th><th>Peran</th><th>Terakhir Masuk</th><th>Status</th><th class="kanan">Aksi</th></tr></thead>
        <tbody>
        <?php foreach ($daftar as $i => $r): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td><b><?= e($r['nama']) ?></b></td>
            <td><?= e($r['username']) ?></td>
            <td><span class="pil pil-info"><?= e(role_label($r['role'])) ?></span></td>
            <td><?= e($r['masuk_terakhir'] ? waktu_id((string) $r['masuk_terakhir']) : '-') ?></td>
            <td><?= (int) $r['aktif'] === 1 ? '<span class="pil pil-ok">Aktif</span>' : '<span class="pil pil-mut">Nonaktif</span>' ?></td>
            <td class="kanan row-actions">
              <a class="btn btn-sm" href="pengguna.php?edit=<?= (int) $r['id'] ?>#formAkun">Ubah</a>
              <form method="post" style="display:inline" data-confirm="Buat sandi baru untuk akun ini?">
                <?= csrf_field() ?><input type="hidden" name="aksi" value="reset"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                <button class="btn btn-sm" type="submit">Sandi Baru</button></form>
              <form method="post" style="display:inline">
                <?= csrf_field() ?><input type="hidden" name="aksi" value="toggle"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                <button class="btn btn-sm" type="submit"><?= (int) $r['aktif'] === 1 ? 'Nonaktifkan' : 'Aktifkan' ?></button></form>
              <form method="post" style="display:inline" data-confirm="Hapus akun ini?">
                <?= csrf_field() ?><input type="hidden" name="aksi" value="hapus"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                <button class="btn btn-sm btn-bahaya" type="submit">Hapus</button></form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$daftar): ?><tr><td colspan="7" class="kosong">Tidak ada akun yang cocok.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div>
    <div class="card" id="formAkun">
      <div class="card-head"><h3><?= $editRow ? 'Ubah Akun' : 'Tambah Akun' ?></h3>
        <?php if ($editRow): ?><div class="kanan"><a class="btn btn-sm" href="pengguna.php">Batal</a></div><?php endif; ?>
      </div>
      <div class="card-body">
        <?php if ($editRow): ?>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="aksi" value="ubah"><input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>">
            <div class="field"><label>Nama</label><input type="text" name="nama" value="<?= e($editRow['nama']) ?>" required></div>
            <div class="field"><label>Nama Pengguna</label><input type="text" name="username" value="<?= e($editRow['username']) ?>" required></div>
            <p class="mut">Peran: <b><?= e(role_label($editRow['role'])) ?></b> (tidak dapat diubah).</p>
            <button class="btn btn-utama" type="submit">Simpan Perubahan</button>
          </form>
        <?php else: ?>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="aksi" value="tambah">
            <div class="field"><label>Nama</label><input type="text" name="nama" required></div>
            <div class="field"><label>Nama Pengguna</label><input type="text" name="username" required>
              <div class="ket">Unik se-aplikasi (4-30 karakter: huruf, angka, titik, garis bawah).</div></div>
            <div class="field"><label>Kata Sandi (min 6)</label><input type="password" name="password" required></div>
            <div class="field"><label>Peran</label>
              <select name="role">
                <option value="guru">Guru</option>
                <option value="operator">Admin Operator</option>
              </select>
              <div class="ket">Akun siswa dibuat dari halaman Data Siswa agar tertaut ke datanya.</div></div>
            <button class="btn btn-utama" type="submit">Tambah Akun</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
    <div class="card">
      <div class="card-head"><h3>Catatan</h3></div>
      <div class="card-body">
        <ul class="poin-list">
          <li>Akun <b>guru</b> dan <b>siswa</b> sebaiknya dibuat dari menu Data Guru / Data Siswa agar tertaut ke datanya.</li>
          <li>Akun siswa hanya dipakai untuk mengerjakan ujian yang sudah diaktifkan.</li>
          <li>Selama siswa mengerjakan ujian, akunnya terkunci pada halaman ujian.</li>
        </ul>
      </div>
    </div>
  </div>
</div>
<?php page_bottom();
