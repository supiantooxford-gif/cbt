<?php
/** Akun Saya: profil dan ganti kata sandi untuk semua peran. */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

$u = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $aksi = teks($_POST['aksi'] ?? '', 20);
    if ($aksi === 'profil') {
        $nama = teks($_POST['nama'] ?? '', 120);
        if ($nama === '') {
            flash('Nama tidak boleh kosong.', 'err');
        } else {
            q('UPDATE users SET nama = ? WHERE id = ?', [$nama, (int) $u['id']]);
            $gid = (int) ($u['guru_id'] ?? 0);
            $swid = (int) ($u['siswa_id'] ?? 0);
            if ($gid > 0) { q('UPDATE guru SET nama = ? WHERE id = ? AND sekolah_id = ?', [$nama, $gid, (int) $u['sekolah_id']]); }
            if ($swid > 0) { q('UPDATE siswa SET nama = ? WHERE id = ? AND sekolah_id = ?', [$nama, $swid, (int) $u['sekolah_id']]); }
            flash('Nama diperbarui.', 'ok');
        }
    } elseif ($aksi === 'sandi') {
        $lama = (string) ($_POST['lama'] ?? '');
        $baru = (string) ($_POST['baru'] ?? '');
        $ulang = (string) ($_POST['ulang'] ?? '');
        if (!password_verify($lama, (string) $u['password'])) {
            flash('Kata sandi lama tidak cocok.', 'err');
        } elseif (strlen($baru) < 8) {
            flash('Kata sandi baru minimal 8 karakter.', 'err');
        } elseif ($baru !== $ulang) {
            flash('Ulangi kata sandi baru tidak sama.', 'err');
        } else {
            q('UPDATE users SET password = ? WHERE id = ?', [password_hash($baru, PASSWORD_DEFAULT), (int) $u['id']]);
            flash('Kata sandi berhasil diganti.', 'ok');
        }
    }
    redirect('akun.php');
}

$u = current_user();
$s = current_sekolah();
$guru = guru_saya();
$siswa = siswa_saya();

page_top('Akun Saya');
?>
<div class="grid g21">
  <div class="card">
    <div class="card-head"><h3>Profil</h3></div>
    <div class="card-body">
      <table class="tbl mb16">
        <tr><th style="width:35%">Nama</th><td><?= e($u['nama']) ?></td></tr>
        <tr><th>Nama Pengguna</th><td><?= e($u['username']) ?></td></tr>
        <tr><th>Peran</th><td><?= e(role_label($u['role'])) ?></td></tr>
        <tr><th>Sekolah/Madrasah</th><td><?= e($s ? (string) $s['nama'] : 'Lintas sekolah (Super Admin)') ?></td></tr>
        <tr><th>Terakhir Masuk</th><td><?= e($u['masuk_terakhir'] ? waktu_id((string) $u['masuk_terakhir']) : '-') ?></td></tr>
        <?php if ($guru): ?>
          <tr><th>NIP/NUPTK</th><td><?= e((string) ($guru['nip'] ?: '-')) ?></td></tr>
          <tr><th>Mata Pelajaran</th><td><?php
            $mn = [];
            foreach (guru_mapel_ids((int) $guru['id']) as $mid) { $mn[] = mapel_nama($mid); }
            echo e($mn ? implode(', ', $mn) : '-'); ?></td></tr>
        <?php endif; ?>
        <?php if ($siswa): ?>
          <tr><th>NIS / NISN</th><td><?= e((string) ($siswa['nis'] ?: '-')) ?> / <?= e((string) ($siswa['nisn'] ?: '-')) ?></td></tr>
          <tr><th>Kelas</th><td><?= e(kelas_nama((int) $siswa['kelas_id'])) ?></td></tr>
        <?php endif; ?>
      </table>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="aksi" value="profil">
        <div class="field"><label for="nama">Ubah Nama Tampilan</label>
          <input type="text" id="nama" name="nama" value="<?= e($u['nama']) ?>" required></div>
        <button class="btn btn-utama btn-sm" type="submit">Simpan Nama</button>
      </form>
    </div>
  </div>
  <div class="card">
    <div class="card-head"><h3>Ganti Kata Sandi</h3></div>
    <div class="card-body">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="aksi" value="sandi">
        <div class="field"><label for="lama">Kata Sandi Sekarang</label>
          <input type="password" id="lama" name="lama" required></div>
        <div class="field"><label for="baru">Kata Sandi Baru (min 8)</label>
          <input type="password" id="baru" name="baru" required></div>
        <div class="field"><label for="ulang">Ulangi Kata Sandi Baru</label>
          <input type="password" id="ulang" name="ulang" required></div>
        <button class="btn btn-utama" type="submit">Ganti Kata Sandi</button>
      </form>
    </div>
  </div>
</div>
<?php page_bottom();
