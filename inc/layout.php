<?php
/**
 * Kerangka tampilan: kepala halaman, sidebar per peran, bilah atas, dan kaki halaman.
 */
declare(strict_types=1);

/** Item menu: [berkas, label, ikon, lencana(bool aktif)] */
function menu_sidebar(): array
{
    $u = current_user();
    if (!$u) {
        return [];
    }
    $role = (string) $u['role'];
    $berkas = berkas_sekarang();
    $s = current_sekolah();
    $menu = [];

    $item = function (string $f, string $label, string $ico, int $lencana = 0) use ($berkas): array {
        return ['berkas' => $f, 'label' => $label, 'ikon' => $ico, 'aktif' => ($berkas === $f), 'lencana' => $lencana];
    };

    if ($role === 'super' && !$s) {
        $menu[] = ['judul' => 'Super Admin', 'item' => [
            $item('index.php', 'Ringkasan', 'dashboard'),
            $item('sekolah.php', 'Kelola Sekolah', 'sekolah'),
            $item('tarik_eguru.php', 'Tarik Data dari e-GURU', 'impor'),
        ]];
        // MENU "PENGATURAN" BERISI SUB MENU (permintaan pemilik v1.19.0).
        // Kelima menu pengaturan tingkat aplikasi dikelompokkan di dalam satu menu buka-tutup
        // (`<details>`, bekerja tanpa JavaScript) supaya sidebar Super Admin tidak menumpuk.
        // Sub menu selalu TERBUKA secara bawaan agar seluruh tautannya langsung terlihat.
        $menu[] = ['judul' => 'Pengaturan', 'lipat' => true, 'item' => [
            $item('biaya_aktivasi.php', 'Biaya & Aktivasi', 'kunci'),
            $item('jenis_ujian.php', 'Jenis Penilaian', 'ujian'),
            $item('soal_otomatis.php', 'Soal Otomatis', 'koreksi'),
            $item('logo.php', 'Logo Aplikasi', 'impor'),
            $item('pengguna.php', 'Akun Super Admin', 'pengguna'),
        ]];
        $menu[] = ['judul' => 'Akun', 'item' => [
            $item('akun.php', 'Akun Saya', 'pengguna'),
            $item('logout.php', 'Keluar', 'logout'),
        ]];
        return $menu;
    }

    // Konteks sekolah
    $menu[] = ['judul' => 'Utama', 'item' => [
        $item('index.php', 'Dashboard', 'dashboard'),
    ]];
    if ($role === 'super' || $role === 'operator') {
        $menu[0]['item'][] = $item('hasil.php', 'Hasil & Analisis', 'hasil');
        $menu[0]['item'][] = $item('jadwal_token.php', 'Jadwal Asesmen & Token', 'jam');
        $menu[0]['item'][] = $item('bank_soal.php', 'Bank Soal', 'buku');
    }
    if ($role === 'guru') {
        $menu[0]['item'][] = $item('ujian.php', 'Ujian & Soal', 'ujian');
        $menu[0]['item'][] = $item('bank_soal.php', 'Bank Soal', 'buku');
        $menu[0]['item'][] = $item('hasil.php', 'Hasil & Analisis', 'hasil');
    }
    if ($role === 'siswa') {
        $menu[0]['item'] = [$item('ujian_siswa.php', 'Ujian Saya', 'ujian')];
    }

    if ($role === 'super' || $role === 'operator') {
        $dataInduk = [
            $item('kelas.php', 'Kelas', 'kelas'),
            $item('mapel.php', 'Mata Pelajaran', 'mapel'),
            $item('guru.php', 'Data Guru', 'guru'),
            $item('siswa.php', 'Data Siswa', 'siswa'),
        ];
        if ($role === 'super') {
            // Menu "Tarik Data dari e-GURU" hanya untuk Super Admin (permintaan pemilik).
            $dataInduk[] = $item('tarik_eguru.php', 'Tarik Data dari e-GURU', 'impor');
        }
        $menu[] = ['judul' => 'Data Induk', 'item' => $dataInduk];
        $menu[] = ['judul' => 'Pengaturan', 'item' => [
            $item('pengaturan.php', 'Identitas & Pengaturan', 'pengaturan'),
            $item('pengguna.php', 'Akun Pengguna', 'pengguna'),
            $item('aktivasi.php', 'Status & Aktivasi', 'aktivasi'),
        ]];
        if ($role === 'super') {
            // Pengaturan tingkat APLIKASI (bukan per sekolah).
            // v1.14.0: menu "Halaman Depan (Landing)" DIHAPUS (halaman landing sudah tidak ada).
            $menu[] = ['judul' => 'Aplikasi', 'item' => [
                $item('biaya_aktivasi.php', 'Biaya & Aktivasi', 'kunci'),
                $item('jenis_ujian.php', 'Jenis Penilaian', 'ujian'),
                $item('soal_otomatis.php', 'Soal Otomatis', 'koreksi'),
                $item('logo.php', 'Logo Aplikasi', 'impor'),
            ]];
        }
    }

    $menu[] = ['judul' => 'Akun', 'item' => [
        $item('akun.php', 'Akun Saya', 'pengguna'),
        $item('logout.php', 'Keluar', 'logout'),
    ]];
    return $menu;
}

/**
 * Kepala halaman.
 * $opsi: tanpa_sidebar (dipakai halaman ujian siswa), kelas_body
 */
function page_top(string $judul = '', array $opsi = []): void
{
    $u = current_user();
    $s = current_sekolah();
    $tanpaSidebar = !empty($opsi['tanpa_sidebar']);
    $v = APP_VERSION;
    ?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<title><?= e($judul !== '' ? $judul . ' - ' . APP_NAME : APP_NAME) ?></title>
<link rel="stylesheet" href="assets/style.css?v=<?= e($v) ?>">
</head>
<body class="<?= $tanpaSidebar ? 'tanpa-sidebar' : '' ?>">
<div class="app">
<?php if (!$tanpaSidebar): ?>
  <aside class="sidebar" id="sidebar">
    <div class="brand">
      <div class="brand-ikon"><img src="<?= e(logo_aplikasi()) ?>" alt="Logo aplikasi"></div>
      <div class="brand-teks">
        <b><?= e(APP_NAME) ?></b>
        <span><?= e(APP_SUB) ?></span>
      </div>
    </div>
    <nav class="nav">
      <?php foreach (menu_sidebar() as $grup): ?>
        <?php if (!empty($grup['lipat'])): ?>
          <?php // Sub menu buka-tutup: <details> bekerja tanpa JavaScript. ?>
          <details class="nav-lipat" open>
            <summary class="nav-grup nav-lipat-kepala"><?= e($grup['judul']) ?><span class="nav-caret" aria-hidden="true"></span></summary>
            <div class="nav-sub">
              <?php foreach ($grup['item'] as $it): ?>
                <a class="nav-item<?= $it['aktif'] ? ' on' : '' ?>" href="<?= e($it['berkas']) ?>">
                  <?= ikon($it['ikon']) ?><span><?= e($it['label']) ?></span>
                  <?php if (!empty($it['lencana'])): ?><b class="nav-lencana"><?= (int) $it['lencana'] ?></b><?php endif; ?>
                </a>
              <?php endforeach; ?>
            </div>
          </details>
        <?php else: ?>
        <div class="nav-grup"><?= e($grup['judul']) ?></div>
        <?php foreach ($grup['item'] as $it): ?>
          <a class="nav-item<?= $it['aktif'] ? ' on' : '' ?>" href="<?= e($it['berkas']) ?>">
            <?= ikon($it['ikon']) ?><span><?= e($it['label']) ?></span>
            <?php if (!empty($it['lencana'])): ?><b class="nav-lencana"><?= (int) $it['lencana'] ?></b><?php endif; ?>
          </a>
        <?php endforeach; ?>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-kaki">
      <div class="versi">Versi <?= e(APP_VERSION) ?></div>
    </div>
  </aside>
<?php endif; ?>
  <div class="konten">
<?php if (!$tanpaSidebar): ?>
    <header class="topbar">
      <button class="btn-ikon" type="button" id="btnMenu" aria-label="Buka menu"><?= ikon('dashboard', 20) ?></button>
      <div class="topbar-judul">
        <b><?= e($judul) ?></b>
        <span><?= $s ? e($s['nama']) . ' &middot; ' . e(jenjang_label($s['jenjang'] ?? '')) : 'Super Admin &middot; Lintas Sekolah' ?></span>
      </div>
      <div class="topbar-kanan">
        <?php if ($u && $s && $u['role'] === 'super'): ?>
          <?php // Tombol pintas selalu terlihat (bilah atas sticky) untuk kembali ke tampilan Super Admin. ?>
          <a class="btn btn-sm btn-kembali-super btn-kembali-top" href="index.php?keluar=1"
             title="Keluar dari konteks sekolah dan buka halaman utama Super Admin"><?= ikon('home', 15) ?><span class="teks-kembali">Kembali ke Super Admin</span></a>
        <?php endif; ?>
        <?php if ($u): ?>
          <div class="pengguna-info">
            <b><?= e($u['nama']) ?></b>
            <span><?= e(role_label($u['role'])) ?></span>
          </div>
          <div class="avatar"><?= e(atas(substr((string) $u['nama'], 0, 1))) ?></div>
        <?php endif; ?>
      </div>
    </header>
<?php endif; ?>
    <main class="isi<?= $tanpaSidebar ? ' isi-polos' : '' ?>">

<?php if (!$tanpaSidebar): ?>
<?php foreach (flash_take() as $f): ?>
  <div class="alert alert-<?= e($f['tipe']) ?>"><?= e($f['pesan']) ?></div>
<?php endforeach; ?>
<?php
// -----------------------------------------------------------------------------------------
// KEMBALI KE HALAMAN UTAMA SUPER ADMIN (permintaan pemilik).
// Super Admin dapat MASUK ke konteks satu sekolah (sekolah.php → "Masuk"); selama berada di
// konteks itu seluruh data yang tampil hanya milik sekolah tersebut. Tanpa tombol ini tidak ada
// jalan kembali ke tampilan lintas sekolah dari dalam aplikasi. Tombolnya ditampilkan pada
// SETIAP halaman ber-konteks (banner di bawah bilah atas) dan juga pada bilah atas itu sendiri.
// Alamatnya `index.php?keluar=1` — ditangani index.php (melepas konteks lalu membuka Ringkasan).
?>
<?php if ($u && $s && $u['role'] === 'super'): ?>
  <div class="alert alert-info alert-konteks">
    <span class="konteks-teks"><?= ikon('sekolah', 16) ?>
      Anda sedang <b>mengelola <?= e((string) $s['nama']) ?></b>. Seluruh data yang tampil hanya milik
      sekolah/madrasah ini.</span>
    <a class="btn btn-sm btn-kembali-super" href="index.php?keluar=1"><?= ikon('home', 15) ?>
      Kembali ke Halaman Utama Super Admin</a>
  </div>
<?php endif; ?>
<?php if ($u && $s && aktivasi_terkunci($s) && $u['role'] !== 'super'): ?>
  <div class="alert alert-err"><?= e(aktivasi_terkunci_pesan()) ?></div>
<?php elseif ($u && $s && $u['role'] !== 'super'): ?>
  <?php $st = aktivasi_status($s); ?>
  <?php if ($st['keadaan'] === 'uji_coba' && $st['sisa_hari'] <= 7): ?>
    <div class="alert alert-warn">Masa aktif aplikasi tinggal <b><?= (int) $st['sisa_hari'] ?> hari</b>
      (berakhir <?= e(tanggal_id(substr((string) $st['sampai'], 0, 10))) ?>).
      Minta kode aktivasi baru ke Admin Aplikasi (WA <?= e(aktivasi_wa()) ?>) sebelum masa aktif habis.</div>
  <?php endif; ?>
<?php endif; ?>
<?php endif;
}

function page_bottom(): void
{
    ?>
    </main>
    <?php if (!is_siswa()): ?>
    <footer class="kaki">
      <span>&copy; <?= date('Y') ?> <?= e(APP_NAME) ?> v<?= e(APP_VERSION) ?></span>
      <span>Dikembangkan oleh <?= e(APP_AUTHOR) ?> &middot; <?= e(APP_AUTHOR_JABATAN) ?></span>
    </footer>
    <?php endif; ?>
  </div>
</div>
<script src="assets/app.js?v=<?= e(APP_VERSION) ?>"></script>
</body>
</html>
<?php
}

/**
 * Kartu "Masa Aktif" dashboard.
 *
 * Permintaan pemilik: sekolah yang dibuat/ditarik Super Admin TIDAK lagi "permanen" melainkan
 * bermasa aktif 365 hari — jadi kartu ini menampilkan sisa hari + **HITUNG MUNDUR hidup**
 * (diperbarui `initHitungMundur()` di assets/app.js memakai acuan waktu server).
 * Keadaan "permanen" (pilihan Super Admin sendiri) tetap ditampilkan apa adanya tanpa hitung mundur.
 */
function kartu_masa_aktif(array $st): string
{
    if ($st['keadaan'] === 'permanen') {
        return '<div class="card stat card-tight"><div class="stat-ic">' . ikon('aktivasi', 22) . '</div>'
            . '<div class="stat-isi"><span>Masa Aktif</span><b>Permanen</b>'
            . '<small>tanpa batas waktu</small></div></div>';
    }
    $sisa = (int) $st['sisa_detik'];
    $hari = (int) floor($sisa / 86400);
    $jam = (int) floor(($sisa % 86400) / 3600);
    $menit = (int) floor(($sisa % 3600) / 60);
    $detik = (int) ($sisa % 60);
    $dua = fn(int $n): string => str_pad((string) $n, 2, '0', STR_PAD_LEFT);
    $teks = $st['keadaan'] === 'kadaluarsa'
        ? 'Masa aktif sudah berakhir'
        : 'Sisa ' . $hari . ' hari ' . $dua($jam) . ':' . $dua($menit) . ':' . $dua($detik);
    $nilai = $st['keadaan'] === 'kadaluarsa' ? 'Habis' : ($hari . ' hari');
    $ket = $st['keadaan'] === 'kadaluarsa'
        ? 'berakhir ' . tanggal_id(substr((string) $st['sampai'], 0, 10)) . ' — masukkan kode aktivasi baru'
        : 'berakhir ' . tanggal_id(substr((string) $st['sampai'], 0, 10));
    return '<div class="card stat card-tight"><div class="stat-ic">' . ikon('jam', 22) . '</div>'
        . '<div class="stat-isi"><span>Masa Aktif</span><b>' . e($nilai) . '</b>'
        . '<small>' . e($ket) . '</small>'
        . '<small class="hm-mini" data-hm-mini data-berakhir="' . (int) $st['sampai_ts'] . '"'
        . ' data-sekarang="' . time() . '">' . e($teks) . '</small></div></div>';
}

/** Kartu statistik dashboard. */
function kartu_stat(string $label, string $nilai, string $ket = '', string $ico = 'ujian'): string
{
    return '<div class="card stat card-tight"><div class="stat-ic">' . ikon($ico, 22) . '</div>'
        . '<div class="stat-isi"><span>' . e($label) . '</span><b>' . e($nilai) . '</b>'
        . ($ket !== '' ? '<small>' . e($ket) . '</small>' : '') . '</div></div>';
}
