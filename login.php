<?php
/**
 * HALAMAN MASUK (login) — satu-satunya halaman publik aplikasi.
 *
 * Permintaan pemilik: *"Hapus halaman landing page, hapus halaman pendaftaran ... Yang ditampilkan
 * hanya halaman login yang responsive."* Lalu (v1.15.0): *"Warna aplikasi bagian depan buat seperti
 * contoh termasuk posisi logo dan tulisan. Tapi untuk inputan login jangan dirubah."*
 *
 * Tata letak (mengikuti contoh yang dikirim pemilik):
 *   • PITA BIRU bergradasi + gelombang di bagian atas, berisi LOGO + NAMA APLIKASI + subjudul
 *     (posisi logo di kiri tulisan, seluruhnya di tengah halaman).
 *   • KARTU PUTIH di tengah yang sedikit MENUMPANG pita biru: baris bantuan kecil, judul
 *     "Selamat Datang", keterangan singkat, formulir masuk, catatan, dan kaki halaman (versi).
 *
 * 🚨 ISIAN LOGIN SENGAJA TIDAK DIUBAH: `#username` + `#password` dengan label "Nama Pengguna" /
 * "Kata Sandi" dan tombol "Masuk" (permintaan pemilik). Jangan menambah/menghapus medan di sini.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

if (current_user()) {
    redirect('index.php');
}

$galat = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $username = teks($_POST['username'] ?? '', 60);
    $sandi = (string) ($_POST['password'] ?? '');

    // Akun guru & operator di CBT memakai NAMA PENGGUNA e-GURU apa adanya (bisa bertitik, berspasi,
    // atau berhuruf besar). e-GURU mencocokkan nama pengguna TANPA membedakan huruf besar-kecil
    // (`COLLATE NOCASE`), jadi di sini dicoba: persis dahulu, lalu tanpa beda huruf besar-kecil —
    // supaya akun yang sama benar-benar bisa dipakai dengan cara yang sama seperti di e-GURU.
    $u = one('SELECT * FROM users WHERE username = ?', [$username])
        ?: one('SELECT * FROM users WHERE username = ? COLLATE NOCASE', [$username]);
    if (!$u || !password_verify($sandi, (string) $u['password'])) {
        $galat = 'Nama pengguna atau kata sandi salah.';
    } elseif ((int) $u['aktif'] !== 1) {
        $galat = 'Akun ini dinonaktifkan. Hubungi Admin sekolah Anda.';
    } elseif ($u['role'] !== 'super' && (int) ($u['sekolah_id'] ?? 0) <= 0) {
        $galat = 'Akun ini belum ditautkan ke sekolah. Hubungi Admin aplikasi.';
    } else {
        if ($u['role'] !== 'super') {
            $s = one('SELECT * FROM sekolah WHERE id = ?', [(int) $u['sekolah_id']]);
            if (!$s) {
                $galat = 'Sekolah akun ini tidak ditemukan. Hubungi Admin aplikasi.';
            } elseif ((int) $s['aktif'] !== 1) {
                $galat = 'Sekolah Anda dinonaktifkan. Hubungi Admin aplikasi.';
            } elseif ($s['verifikasi'] === 'ditolak') {
                $galat = 'Akun sekolah Anda belum diaktifkan'
                    . (trim((string) $s['alasan_tolak']) !== '' ? ': ' . $s['alasan_tolak'] : '.');
            } elseif ($s['verifikasi'] !== 'disetujui') {
                $galat = 'Akun sekolah Anda belum disetujui Admin aplikasi.';
            }
        }
    }

    if ($galat === '') {
        $_SESSION['user_id'] = (int) $u['id'];
        session_regenerate_id(true);
        q('UPDATE users SET masuk_terakhir = ? WHERE id = ?', [date('Y-m-d H:i:s'), (int) $u['id']]);
        unset($_SESSION['ctx_sekolah_id']);
        redirect('index.php');
    }
}
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Masuk - <?= e(APP_NAME) ?></title>
<meta name="description" content="<?= e(APP_SUB) ?>">
<link rel="icon" href="<?= e(logo_aplikasi()) ?>">
<link rel="stylesheet" href="assets/style.css?v=<?= e(APP_VERSION) ?>">
</head>
<body class="masuk">

<!-- ============================ PITA BIRU: logo + nama aplikasi + subjudul ============================ -->
<header class="masuk-info">
  <div class="masuk-brand">
    <img class="masuk-logo" src="<?= e(logo_aplikasi()) ?>" alt="Logo <?= e(APP_NAME) ?>"
         width="72" height="72">
    <div class="masuk-brand-teks">
      <h1><?= e(APP_NAME) ?></h1>
      <p class="masuk-sub"><?= e(APP_SUB) ?></p>
    </div>
  </div>
  <?php // Gelombang putih di tepi bawah pita biru (dibuat dari SVG, tanpa berkas gambar). ?>
  <svg class="masuk-gelombang" viewBox="0 0 1440 120" preserveAspectRatio="none" aria-hidden="true"
       focusable="false"><path d="M0,64 C240,120 480,8 720,44 C960,80 1200,120 1440,52 L1440,120 L0,120 Z"
       fill="#f2f6fc"/></svg>
</header>

<!-- ============================ KARTU MASUK (menumpang pita biru) ============================ -->
<main class="masuk-wrap">
  <div class="masuk-kisi">
    <section class="card masuk-kartu" id="masuk">

      <p class="masuk-lupa">Lupa kata sandi? Hubungi Admin Operator sekolah/madrasah Anda.</p>

      <h2 class="masuk-selamat">Selamat Datang</h2>
      <p class="masuk-desc">Silakan masuk menggunakan nama pengguna dan kata sandi yang Anda miliki.</p>

      <?php if ($galat !== ''): ?>
        <div class="alert alert-err" id="galatMasuk"><?= e($galat) ?></div>
      <?php endif; ?>
      <?php foreach (flash_take() as $f): ?>
        <div class="alert alert-<?= e($f['tipe']) ?>"><?= e($f['pesan']) ?></div>
      <?php endforeach; ?>

      <?php // ISIAN LOGIN — jangan diubah (permintaan pemilik). ?>
      <form method="post" autocomplete="off" class="masuk-form">
        <?= csrf_field() ?>
        <div class="field">
          <label for="username">Nama Pengguna</label>
          <input type="text" id="username" name="username" value="<?= e($username) ?>"
                 required autofocus autocapitalize="none" spellcheck="false">
        </div>
        <div class="field">
          <label for="password">Kata Sandi</label>
          <input type="password" id="password" name="password" required>
        </div>
        <button class="btn btn-utama btn-blok" type="submit">Masuk</button>
      </form>

      <p class="masuk-ket">
        Akun guru memakai <b>nama pengguna dan kata sandi dari aplikasi e-GURU</b>.
        Sedangkan akun siswa akan diberikan oleh admin madrasah.
      </p>

      <footer class="masuk-kaki">
        <span>&copy; <?= date('Y') ?> <?= e(APP_NAME) ?> v<?= e(APP_VERSION) ?></span>
        <span>Dikembangkan oleh <?= e(APP_AUTHOR) ?> &middot; <?= e(APP_AUTHOR_JABATAN) ?></span>
      </footer>
    </section>
  </div>
</main>
<script src="assets/app.js?v=<?= e(APP_VERSION) ?>"></script>
</body>
</html>
