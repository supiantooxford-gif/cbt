<?php
/**
 * Fungsi bantu: escaping, konteks sekolah, pengaturan, otorisasi berbasis peran,
 * CSRF, flash message, aktivasi sekolah, penilaian otomatis soal, dan analisis butir.
 *
 * Peran pengguna:
 *   super    = Super Admin (lintas sekolah)
 *   operator = Admin Operator sekolah (satu sekolah)
 *   guru     = Guru mata pelajaran (satu sekolah + satu baris data guru)
 *   siswa    = Peserta ujian (satu sekolah + satu baris data siswa)
 *
 * Berkas ini TIDAK boleh memanggil db() saat di-include (dipanggil lazy).
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';

// ------------------------------------------------------------------ dasar

function e($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function flash(string $pesan, string $tipe = 'ok'): void
{
    $_SESSION['flash'][] = ['pesan' => $pesan, 'tipe' => $tipe];
}

function flash_take(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return is_array($f) ? $f : [];
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return (string) $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_ok(): bool
{
    $t = (string) ($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return $t !== '' && hash_equals((string) ($_SESSION['csrf'] ?? ''), $t);
}

function csrf_guard(): void
{
    if (!csrf_ok()) {
        http_response_code(419);
        exit('Token keamanan tidak sah (419). Muat ulang halaman lalu coba lagi.');
    }
}

/** Trim + batasi panjang teks (tanpa mb_* bila ekstensi tidak tersedia). */
function teks($v, int $maks = 0): string
{
    $s = trim((string) $v);
    if ($maks > 0 && strlen($s) > $maks) {
        $s = substr($s, 0, $maks);
    }
    return $s;
}

function bawah(string $s): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

function atas(string $s): string
{
    return function_exists('mb_strtoupper') ? mb_strtoupper($s, 'UTF-8') : strtoupper($s);
}

function panjang(string $s): int
{
    return function_exists('mb_strlen') ? (int) mb_strlen($s, 'UTF-8') : strlen($s);
}

/** Tanggal Indonesia: 2026-03-14 -> 14 Maret 2026 */
function tanggal_id(?string $ymd): string
{
    if ($ymd === null || trim($ymd) === '') {
        return '-';
    }
    $t = substr(trim($ymd), 0, 10);
    $p = explode('-', $t);
    if (count($p) !== 3) {
        return (string) $ymd;
    }
    $bulan = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli',
        'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $b = (int) $p[1];
    return ((int) $p[2]) . ' ' . ($bulan[$b] ?? $p[1]) . ' ' . $p[0];
}

/** 2026-03-14 09:05:00 -> 14 Maret 2026, 09:05 */
function waktu_id(?string $dt): string
{
    if ($dt === null || trim($dt) === '') {
        return '-';
    }
    return tanggal_id(substr($dt, 0, 10)) . ', ' . substr($dt, 11, 5);
}

/** Angka rapi: 85.5 -> 85,5 (tanpa nol ekor yang mengganggu). */
function r1($n): string
{
    $n = (float) $n;
    if (abs($n - round($n)) < 0.005) {
        return number_format($n, 0, ',', '.');
    }
    return rtrim(rtrim(number_format($n, 2, ',', '.'), '0'), ',');
}

function persen($n, int $desimal = 1): string
{
    return number_format((float) $n, $desimal, ',', '.') . '%';
}

/** Nilai untuk atribut `value` pada <input type="number"> — WAJIB memakai titik desimal. */function num_input($n): string
{
    $n = (float) $n;
    if (abs($n - round($n)) < 0.0005) {
        return (string) (int) round($n);
    }
    return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
}

/** Potong teks panjang untuk ringkasan (aman untuk UTF-8 bila mbstring tersedia). */
function potong_teks(string $s, int $maks = 100): string
{
    $s = trim(preg_replace('/\s+/', ' ', $s) ?? '');
    if (panjang($s) <= $maks) {
        return $s;
    }
    $potong = function_exists('mb_substr') ? mb_substr($s, 0, $maks, 'UTF-8') : substr($s, 0, $maks);
    return rtrim($potong) . '…';
}

function role_label(?string $role): string
{
    return [
        'super'    => 'Super Admin',
        'operator' => 'Admin Operator',
        'guru'     => 'Guru',
        'siswa'    => 'Siswa',
    ][(string) $role] ?? 'Pengguna';
}

// ------------------------------------------------------------------ ikon tampilan
/** Ikon SVG sederhana (tanpa pustaka luar). */
function ikon(string $nama, int $size = 18): string
{
    $p = [
        'dashboard' => '<path d="M3 3h7v7H3zM14 3h7v4h-7zM14 10h7v11h-7zM3 13h7v8H3z"/>',
        'sekolah'   => '<path d="M12 3 2 8v13h20V8zm0 2.2 7 3.5V19H5V8.7zM9 19v-5h6v5z"/>',
        'daftar'    => '<path d="M4 3h11l5 5v13H4zm10 1.5V9h4.5zM7 12h10v1.6H7zm0 3.4h10V17H7z"/>',
        'guru'      => '<path d="M9 4h6v3.2a3 3 0 0 1-6 0zM4 20c0-3.6 3.6-6 8-6s8 2.4 8 6z"/>',
        'siswa'     => '<path d="M12 4 2 9l10 5 8-4v6h2V9zM6 13v3c0 1.7 2.7 3 6 3s6-1.3 6-3v-3l-6 3z"/>',
        'kelas'     => '<path d="M3 5h18v3H3zm0 5h8v9H3zm10 0h8v9h-8z"/>',
        'mapel'     => '<path d="M5 3h9a4 4 0 0 1 4 4v14H8a3 3 0 0 1-3-3zm3 3v12h8V7a2 2 0 0 0-2-2z"/>',
        'ujian'     => '<path d="M5 3h14v18H5zm3 3h8v1.6H8zm0 3.4h8v1.6H8zm0 3.4h5V14H8z"/>',
        'hasil'     => '<path d="M4 19h3V9H4zm5 0h3V5H9zm5 0h3v-7h-3zm5 0h3V8h-3z"/>',
        'pengguna'  => '<path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8m0 2c-4 0-8 2-8 5v1h16v-1c0-3-4-5-8-5"/>',
        'pengaturan' => '<path d="M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8m9 4-2-1 1.6-2.2-2-2L16 8.4 15 6.4 15.4 4h-2.8L12.2 6.4 11 4H8.6L9 6.4 8 8.4 5.4 6.8l-2 2L5 11l-2 1 2 1-1.6 2.2 2 2L8 15.6l1 2-.4 2.4h2.8l.4-2.4 1.2 2.4h2.4L15 17.6l1-2 2.6 1.6 2-2L19 13z"/>',
        'aktivasi'  => '<path d="M12 2 4 6v6c0 5 3.4 9.2 8 10 4.6-.8 8-5 8-10V6zm0 6a3 3 0 0 1 3 3v5h-6v-5a3 3 0 0 1 3-3m0 1.6a1.4 1.4 0 0 0-1.4 1.4v1h2.8v-1A1.4 1.4 0 0 0 12 9.6"/>',
        'logout'    => '<path d="M10 3H4v18h6v-2H6V5h4zm4.6 3.4L13.2 7.8 16.4 11H9v2h7.4l-3.2 3.2 1.4 1.4L20 12z"/>',
        'plus'      => '<path d="M11 5h2v6h6v2h-6v6h-2v-6H5v-2h6z"/>',
        'cetak'     => '<path d="M7 3h10v4H7zm-3 6h16v7h-3v5H7v-5H4zm5 4v5h6v-5z"/>',
        'impor'     => '<path d="M11 3h2v8h3l-4 4-4-4h3zM5 17h14v4H5z"/>',
        'ekspor'    => '<path d="M11 21h2v-8h3l-4-4-4 4h3zM5 3h14v4H5z"/>',
        'kunci'     => '<path d="M14 3a5 5 0 0 0-4.6 7L3 16.4V21h4.6l1.4-1.4V17h2v-2h2l1.2-1.2A5 5 0 1 0 14 3m1.6 3.4a1.6 1.6 0 1 1 0 3.2 1.6 1.6 0 0 1 0-3.2"/>',
        'jam'       => '<path d="M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18m0 2a7 7 0 1 1 0 14 7 7 0 0 1 0-14m-1 2v6l4 2 .8-1.6L13 11.5V7z"/>',
        'koreksi'   => '<path d="M4 3h11l5 5v13H4zm3 11 2 2 5-5-1.4-1.4L9 13.2 7.6 11.8zm0 3.4h10V19H7z"/>',
        'buku'      => '<path d="M4 4h7v16H4zm9 0h7v16h-7zm2 3v10h3V7z"/>',
        'website'   => '<path d="M3 4h18v16H3zm2 2v12h14V6zm2 2h6v5H7zm8 .5h4V10h-4zm0 5h4v2h-4zM7 15h8v2H7z"/>',
        'cari'      => '<path d="M10 3a7 7 0 1 0 4.24 12.57l4.1 4.1 1.41-1.42-4.1-4.1A7 7 0 0 0 10 3m0 2a5 5 0 1 1 0 10 5 5 0 0 1 0-10"/>',
        // Ikon "rumah" untuk tombol kembali ke HALAMAN UTAMA SUPER ADMIN (permintaan pemilik).
        'home'      => '<path d="M12 3 2 11h3v10h5v-6h4v6h5V11h3z"/>',
    ];
    $d = $p[$nama] ?? $p['dashboard'];
    return '<svg class="ico" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">' . $d . '</svg>';
}


// ------------------------------------------------------------------ konteks sekolah

function current_user(): ?array
{
    static $cache = null;
    static $sudah = false;
    if ($sudah) {
        return $cache;
    }
    $sudah = true;
    $id = (int) ($_SESSION['user_id'] ?? 0);
    if ($id <= 0) {
        $cache = null;
        return null;
    }
    $u = one('SELECT * FROM users WHERE id = ? AND aktif = 1', [$id]);
    if (!$u) {
        unset($_SESSION['user_id']);
        $cache = null;
        return null;
    }
    $cache = $u;
    return $cache;
}

function is_super(): bool
{
    $u = current_user();
    return $u && $u['role'] === 'super';
}

function is_operator(): bool
{
    $u = current_user();
    return $u && $u['role'] === 'operator';
}

function is_guru(): bool
{
    $u = current_user();
    return $u && $u['role'] === 'guru';
}

function is_siswa(): bool
{
    $u = current_user();
    return $u && $u['role'] === 'siswa';
}

/** Sekolah yang sedang aktif (operator/guru/siswa: sekolahnya; super: konteks terpilih). */
function current_sekolah(bool $fresh = false): ?array
{
    static $cache = null;
    static $sudah = false;
    if (!$fresh && $sudah) {
        return $cache;
    }
    $sudah = true;
    $cache = null;

    $u = current_user();
    if (!$u) {
        return null;
    }
    if ($u['role'] === 'super') {
        $id = (int) ($_SESSION['ctx_sekolah_id'] ?? 0);
        if ($id <= 0) {
            return null;
        }
        $cache = one('SELECT * FROM sekolah WHERE id = ? AND aktif = 1', [$id]);
        if (!$cache) {
            unset($_SESSION['ctx_sekolah_id']);
        }
        return $cache;
    }
    $sid = (int) ($u['sekolah_id'] ?? 0);
    if ($sid > 0) {
        $cache = one('SELECT * FROM sekolah WHERE id = ?', [$sid]);
    }
    return $cache;
}

function sekolah_id(): int
{
    $s = current_sekolah();
    return $s ? (int) $s['id'] : 0;
}

function set_sekolah_context(?int $id): void
{
    if ($id === null || $id <= 0) {
        unset($_SESSION['ctx_sekolah_id']);
    } else {
        $_SESSION['ctx_sekolah_id'] = (int) $id;
    }
    current_sekolah(true);
}

/** Ringkasan isi sebuah sekolah (dipakai Super Admin sebelum menghapus/menonaktifkan). */
function sekolah_dampak(int $sekolahId): array
{
    if ($sekolahId <= 0) {
        return ['guru' => 0, 'siswa' => 0, 'akun' => 0, 'ujian' => 0, 'soal' => 0, 'sesi' => 0];
    }
    return [
        'guru'  => (int) val('SELECT COUNT(*) FROM guru WHERE sekolah_id = ?', [$sekolahId], 0),
        'siswa' => (int) val('SELECT COUNT(*) FROM siswa WHERE sekolah_id = ?', [$sekolahId], 0),
        'akun'  => (int) val('SELECT COUNT(*) FROM users WHERE sekolah_id = ?', [$sekolahId], 0),
        'ujian' => (int) val('SELECT COUNT(*) FROM ujian WHERE sekolah_id = ?', [$sekolahId], 0),
        'soal'  => (int) val('SELECT COUNT(*) FROM soal WHERE sekolah_id = ?', [$sekolahId], 0),
        'sesi'  => (int) val('SELECT COUNT(*) FROM sesi_ujian WHERE sekolah_id = ?', [$sekolahId], 0),
    ];
}

/** Jumlah sekolah yang sudah disetujui (dipakai pada halaman publik). */
function jumlah_sekolah(): int
{
    return (int) val("SELECT COUNT(*) FROM sekolah WHERE verifikasi = 'disetujui'", [], 0);
}

/** Data guru milik akun guru yang sedang masuk. */
function guru_saya(): ?array
{
    $u = current_user();
    if (!$u || $u['role'] !== 'guru') {
        return null;
    }
    $gid = (int) ($u['guru_id'] ?? 0);
    if ($gid <= 0) {
        return null;
    }
    return one('SELECT * FROM guru WHERE id = ? AND sekolah_id = ?', [$gid, (int) $u['sekolah_id']]);
}

/** Data siswa milik akun siswa yang sedang masuk. */
function siswa_saya(): ?array
{
    $u = current_user();
    if (!$u || $u['role'] !== 'siswa') {
        return null;
    }
    $sid = (int) ($u['siswa_id'] ?? 0);
    if ($sid <= 0) {
        return null;
    }
    return one('SELECT * FROM siswa WHERE id = ? AND sekolah_id = ?', [$sid, (int) $u['sekolah_id']]);
}

// ------------------------------------------------------------------ pengaturan sekolah

/** Kolom tabel `sekolah` yang boleh diubah lewat halaman Pengaturan. */
function setting_kolom(): array
{
    return [
        'nama', 'jenjang', 'npsn', 'alamat', 'desa', 'kecamatan', 'kabupaten', 'provinsi',
        'telepon', 'email', 'website', 'kepala', 'nip_kepala', 'tahun_ajaran', 'semester', 'kkm',
    ];
}

function setting(string $key, string $default = ''): string
{
    $s = current_sekolah();
    if (!$s || !array_key_exists($key, $s)) {
        return $default;
    }
    $v = $s[$key];
    return ($v === null || $v === '') ? $default : (string) $v;
}

function set_setting(string $key, $value): void
{
    if (!in_array($key, setting_kolom(), true) || sekolah_id() <= 0) {
        return;
    }
    q('UPDATE sekolah SET ' . $key . ' = ? WHERE id = ?', [$value, sekolah_id()]);
    current_sekolah(true);
}

function jenjang_list(): array
{
    return ['SD/MI', 'SMP/MTs', 'SMA/MA/SMK'];
}

function jenjang_label(?string $j): string
{
    return in_array((string) $j, jenjang_list(), true) ? (string) $j : 'SMP/MTs';
}

// ------------------------------------------------------------------ otorisasi

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        redirect('login.php');
    }
    gerbang_aktivasi($u);
    gerbang_ujian_siswa($u);
    return $u;
}

function require_super(): array
{
    $u = require_login();
    if ($u['role'] !== 'super') {
        tolak403('Halaman ini khusus Super Admin aplikasi.');
    }
    return $u;
}

/** Halaman pengelolaan sekolah (operator, atau Super Admin dalam konteks sekolah). */
function require_sekolah(): array
{
    $u = require_login();
    if ($u['role'] === 'siswa') {
        tolak403('Halaman ini tidak tersedia untuk akun siswa.');
    }
    if ($u['role'] === 'super' && !current_sekolah()) {
        redirect('sekolah.php');
    }
    if ($u['role'] === 'guru' && !is_guru()) {
        redirect('index.php');
    }
    if (!current_sekolah()) {
        redirect('sekolah.php');
    }
    return $u;
}

function require_operator(): array
{
    $u = require_sekolah();
    if (!is_operator() && !is_super()) {
        tolak403('Halaman ini untuk Admin Operator sekolah.');
    }
    return $u;
}

function require_guru(): array
{
    $u = require_sekolah();
    if (!is_guru() && !is_super()) {
        tolak403('Halaman ini untuk akun Guru.');
    }
    return $u;
}

function require_siswa(): array
{
    $u = require_login();
    if (!is_siswa()) {
        tolak403('Halaman ini untuk akun Siswa.');
    }
    return $u;
}

function tolak403(string $pesan): void
{
    http_response_code(403);
    $judul = 'Akses ditolak';
    require_once __DIR__ . '/layout.php';
    page_top($judul);
    echo '<div class="card"><div class="card-body">';
    echo '<div class="alert alert-err">' . e($pesan) . '</div>';
    echo '<a class="btn" href="index.php">Kembali ke Dashboard</a>';
    echo '</div></div>';
    page_bottom();
    exit;
}

// ------------------------------------------------------------------ aktivasi sekolah

function app_setting(string $kunci, string $default = ''): string
{
    try {
        $v = val('SELECT nilai FROM app_pengaturan WHERE kunci = ?', [$kunci], null);
        return $v === null ? $default : (string) $v;
    } catch (Throwable $e) {
        return $default;
    }
}

function app_setting_set(string $kunci, string $nilai): void
{
    q('INSERT INTO app_pengaturan (kunci, nilai) VALUES (?, ?)
       ON CONFLICT(kunci) DO UPDATE SET nilai = excluded.nilai', [$kunci, $nilai]);
}

function aktivasi_abjad(): string
{
    return '0123456789ABCDEFGHJKMNPQRSTVWXYZ'; // Crockford base32 (32 karakter)
}

function aktivasi_secret(): string
{
    $s = app_setting('aktivasi_secret');
    if ($s === '' || strlen($s) < 32) {
        $s = bin2hex(random_bytes(24));
        app_setting_set('aktivasi_secret', $s);
    }
    return $s;
}

function aktivasi_normalisasi(string $teks): string
{
    $s = atas(trim($teks));
    $s = preg_replace('/[^A-Z0-9]/', '', $s) ?? '';
    $s = str_replace(['O'], ['0'], $s);
    $s = str_replace(['I', 'L'], ['1'], $s);
    return (string) $s;
}

function nama_kunci(string $nama): string
{
    $s = bawah(trim($nama));
    $s = preg_replace('/[^a-z0-9]+/', '', $s) ?? '';
    return $s;
}

/** Kode aktivasi lembaga: berbeda setiap periode (aktivasi_ke). */
function kode_aktivasi_sekolah(?array $s): string
{
    if (!$s) {
        return '';
    }
    $periode = max(1, (int) ($s['aktivasi_ke'] ?? 1));
    $bahan = nama_kunci((string) $s['nama']) . '|' . (int) $s['id'] . '|' . $periode;
    $hash = hash_hmac('sha256', $bahan, aktivasi_secret(), true);
    $abjad = aktivasi_abjad();
    $kode = '';
    for ($i = 0; $i < 12; $i++) {
        $kode .= $abjad[ord($hash[$i]) % 32];
    }
    return substr($kode, 0, 4) . '-' . substr($kode, 4, 4) . '-' . substr($kode, 8, 4);
}

function kode_aktivasi_cocok(?array $s, string $masukan): bool
{
    if (!$s) {
        return false;
    }
    $a = aktivasi_normalisasi($masukan);
    $b = aktivasi_normalisasi(kode_aktivasi_sekolah($s));
    return $a !== '' && $b !== '' && hash_equals($b, $a);
}

/**
 * Keadaan aktivasi sekolah: 'permanen' | 'uji_coba' | 'kadaluarsa'.
 * Masa aktif dihitung dari kolom `aktif_sampai` (NULL = tanpa batas untuk permanen).
 *
 * `sampai_ts` dipakai blok HITUNG MUNDUR (acuan WAKTU SERVER, bukan jam perangkat pengguna).
 * Masa aktif SELALU berakhir pukul 23:59:59 pada tanggal batas (pola yang sama dengan e-GURU).
 */
function aktivasi_status(?array $s = null): array
{
    $s = $s ?? current_sekolah();
    if (!$s) {
        return ['keadaan' => 'permanen', 'sisa_hari' => 0, 'sisa_detik' => 0, 'sampai' => null,
            'sampai_ts' => 0, 'berakhir' => false, 'mode' => 'permanen'];
    }
    $mode = (string) ($s['aktivasi'] ?? 'permanen');
    $sampai = $s['aktif_sampai'] ?? null;
    if ($mode === 'permanen' || $sampai === null || trim((string) $sampai) === '') {
        return ['keadaan' => 'permanen', 'sisa_hari' => 0, 'sisa_detik' => 0, 'sampai' => null,
            'sampai_ts' => 0, 'berakhir' => false, 'mode' => $mode];
    }
    $ts = strtotime((string) $sampai);
    $sisa = $ts !== false ? ($ts - time()) : 0;
    return [
        'keadaan'   => $sisa > 0 ? 'uji_coba' : 'kadaluarsa',
        'sisa_hari' => max(0, (int) ceil($sisa / 86400)),
        'sisa_detik' => max(0, $sisa),
        'sampai'    => (string) $sampai,
        'sampai_ts' => $ts !== false ? $ts : 0,
        'berakhir'  => $sisa <= 0,
        'mode'      => $mode,
    ];
}

function aktivasi_terkunci(?array $s = null): bool
{
    return aktivasi_status($s)['keadaan'] === 'kadaluarsa';
}

/** Batas akhir masa aktif: pukul 23:59:59 pada tanggal (dasar + hari). */
function aktivasi_batas_akhir(int $dasarTs, int $hari): string
{
    $hari = max(1, min(3650, $hari));
    return date('Y-m-d', strtotime('+' . $hari . ' days', $dasarTs)) . ' 23:59:59';
}

/** Aktifkan sekolah dengan masa aktif (default 365 hari) dan naikkan periode kode. */
function aktivasi_aktifkan(int $sekolahId, int $hari = AKTIVASI_HARI_AKTIF, bool $permanen = false): void
{
    $s = one('SELECT * FROM sekolah WHERE id = ?', [$sekolahId]);
    if (!$s) {
        return;
    }
    if ($permanen) {
        q('UPDATE sekolah SET aktivasi = ?, aktif_sampai = NULL, aktivasi_pada = COALESCE(aktivasi_pada, ?),
             aktivasi_ke = aktivasi_ke + 1 WHERE id = ?',
            ['permanen', date('Y-m-d H:i:s'), $sekolahId]);
        return;
    }
    // Dasar penambahan hari — pola yang sama dengan aplikasi e-GURU:
    //   * masa aktif BERBAYAR yang masih berjalan (mode `aktif`) → ditambahkan dari tanggal
    //     berakhirnya, jadi sisa waktu yang sudah dibayar TIDAK hangus (PERPANJANGAN);
    //   * masa aktif AWAL / uji coba (mode `percobaan`, mis. 365 hari bagi sekolah yang dibuat
    //     atau ditarik Super Admin) atau masa aktif yang sudah habis → dihitung dari HARI INI,
    //     sehingga aktivasi pertama memberi tepat 365 hari (tidak menumpuk dua periode).
    $dasar = time();
    $st = aktivasi_status($s);
    if ($st['mode'] === 'aktif' && !empty($st['sampai'])) {
        $ts = strtotime((string) $st['sampai']);
        if ($ts !== false && $ts > time()) {
            $dasar = $ts;
        }
    }
    q('UPDATE sekolah SET aktivasi = ?, aktif_sampai = ?, aktivasi_pada = COALESCE(aktivasi_pada, ?),
         aktivasi_ke = aktivasi_ke + 1 WHERE id = ?',
        ['aktif', aktivasi_batas_akhir($dasar, $hari), date('Y-m-d H:i:s'), $sekolahId]);
}

/**
 * Mulai MASA AKTIF AWAL (belum pernah memakai kode aktivasi) selama $hari hari.
 *
 * Dipakai sekolah yang dibuat Super Admin — baik lewat menu Tambah Sekolah maupun hasil TARIK
 * DATA LEMBAGA dari e-GURU (permintaan pemilik: "Sekolah yang ditarik oleh super admin ... aktif
 * 365 hari (1 tahun) bukan permanen"). Sejak v1.14.0 pendaftaran mandiri dihapus, jadi inilah
 * satu-satunya jalan masuknya.
 *
 * Nomor periode (`aktivasi_ke`) SENGAJA TIDAK dinaikkan: masa aktif awal ini setara dengan
 * periode yang sedang berjalan, sehingga kode aktivasi periode sekarang (yang ditampilkan
 * Super Admin) tetap menjadi kode yang dimasukkan saat masa aktifnya habis.
 */
function aktivasi_mulai_uji_coba(int $sekolahId, int $hari): void
{
    q('UPDATE sekolah SET aktivasi = ?, aktif_sampai = ?, aktif = 1 WHERE id = ?',
        ['percobaan', aktivasi_batas_akhir(time(), $hari), $sekolahId]);
}

/** Berkas yang tetap boleh dibuka walau aplikasi terkunci / sedang ujian. */
function aktivasi_berkas_bebas(): array
{
    // v1.14.0: `daftar.php` (pendaftaran mandiri) DIHAPUS — tidak perlu lagi dikecualikan.
    return ['login.php', 'logout.php', 'aktivasi.php'];
}

function berkas_sekarang(): string
{
    return basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
}

function aktivasi_terkunci_pesan(): string
{
    $wa = aktivasi_wa();
    return 'Masa aktif sekolah sudah berakhir. Aplikasi terkunci sementara — data Anda tetap aman. '
        . 'Hubungi Admin Aplikasi' . ($wa !== '' ? ' di WA ' . $wa : '')
        . ' untuk meminta kode aktivasi yang baru.';
}

/** Gerbang: aplikasi terkunci sampai kode aktivasi periode baru dimasukkan. */
function gerbang_aktivasi(array $u): void
{
    if ($u['role'] === 'super') {
        return; // Super Admin tidak pernah terkunci
    }
    if (in_array(berkas_sekarang(), aktivasi_berkas_bebas(), true)) {
        return;
    }
    if (!aktivasi_terkunci()) {
        return;
    }
    flash(aktivasi_terkunci_pesan(), 'err');
    redirect('aktivasi.php');
}

// ------------------------------------------------------------------ GERBANG SISWA (anti kecurangan)

/** Berkas yang boleh dibuka siswa SELAGI sesi ujiannya berjalan. */
function ujian_berkas_bebas(): array
{
    return ['kerjakan.php', 'simpan_jawaban.php', 'pelanggaran.php', 'selesai.php',
        'ujian_siswa.php', 'logout.php', 'login.php'];
}

/** Sesi ujian yang sedang berjalan milik siswa yang masuk. */
function sesi_berjalan(?int $siswaId = null): ?array
{
    $sw = $siswaId !== null ? $siswaId : (int) (siswa_saya()['id'] ?? 0);
    if ($sw <= 0) {
        return null;
    }
    return one('SELECT * FROM sesi_ujian WHERE siswa_id = ? AND status = ? ORDER BY id DESC LIMIT 1',
        [$sw, 'mengerjakan']);
}

/**
 * Selama ada sesi ujian yang berjalan, akun siswa TERKUNCI pada halaman ujian saja.
 * Permintaan pemilik: "Pastikan Ketika siswa membuka atau login ke aplikasi tidak bisa
 * lagi mengakses aplikasi yang lain (terkunci) untuk memastikan bahwa siswa tidak bisa
 * melakukan kecurangan."
 */
function gerbang_ujian_siswa(array $u): void
{
    if ($u['role'] !== 'siswa') {
        return;
    }
    if (in_array(berkas_sekarang(), ujian_berkas_bebas(), true)) {
        return;
    }
    $sesi = sesi_berjalan((int) ($u['siswa_id'] ?? 0));
    if (!$sesi) {
        return;
    }
    // Arahkan langsung ke ujian yang sedang dikerjakan (tanpa parameternya, halaman
    // pengerjaan akan memantul balik ke daftar ujian → pengalihan berputar).
    redirect('kerjakan.php?id=' . (int) $sesi['ujian_id']);
}

// ------------------------------------------------------------------ aktivasi: batas percobaan

function aktivasi_coba_habis(): bool
{
    $c = $_SESSION['aktivasi_coba'] ?? null;
    if (!is_array($c)) {
        return false;
    }
    if ((int) ($c['sampai'] ?? 0) < time()) {
        unset($_SESSION['aktivasi_coba']);
        return false;
    }
    return (int) ($c['jumlah'] ?? 0) >= AKTIVASI_MAKS_COBA;
}

function aktivasi_coba_tambah(): int
{
    $c = $_SESSION['aktivasi_coba'] ?? ['jumlah' => 0, 'sampai' => 0];
    if ((int) ($c['sampai'] ?? 0) < time()) {
        $c = ['jumlah' => 0, 'sampai' => time() + AKTIVASI_MENIT_COBA * 60];
    }
    $c['jumlah'] = (int) $c['jumlah'] + 1;
    $_SESSION['aktivasi_coba'] = $c;
    return max(0, AKTIVASI_MAKS_COBA - (int) $c['jumlah']);
}

function aktivasi_coba_bersih(): void
{
    unset($_SESSION['aktivasi_coba']);
}

/** Boleh memasukkan kode aktivasi: operator sekolah + Super Admin dalam konteks. */
function boleh_aktivasi(): bool
{
    return is_operator() || is_super();
}

// ------------------------------------------------------------------ BIAYA & KONTAK AKTIVASI
// Permintaan pemilik: "Pada bagian status dan aktivasi di akun operator buat seperti yang ada di
// e-GURU ada fitur untuk memasukkan kode aktivasi, permintaan kode aktivasi, nominal, dan nomor
// rekening — yang semuanya bisa diatur lewat akun Super Admin."
//
// Isinya bersifat TINGKAT APLIKASI (semua sekolah memakai tarif, nomor WA, dan rekening yang sama)
// sehingga disimpan pada tabel `app_pengaturan`: `aktivasi_biaya`, `aktivasi_biaya_langganan`,
// `aktivasi_wa`, `aktivasi_rekening`, `aktivasi_rekening_ket`. Konstanta di `inc/config.php`
// menjadi NILAI BAWAAN dan dipakai kembali oleh tombol "Kembalikan Bawaan".

/** Nominal rupiah untuk tampilan, berawalan "Rp. " — mis. 1000000 → "Rp. 1.000.000". */
function rupiah($v): string
{
    return 'Rp. ' . number_format(round((float) $v), 0, ',', '.');
}

/** Nomor telepon dalam format internasional untuk tautan wa.me (mis. 0852… → 62852…). */
function wa_internasional(string $nomor): string
{
    $d = (string) preg_replace('/\D/', '', $nomor);
    if ($d === '') {
        return '';
    }
    if (strpos($d, '0') === 0) {
        return '62' . substr($d, 1);
    }
    if (strpos($d, '62') !== 0) {
        return '62' . $d;
    }
    return $d;
}

/**
 * Pembacaan pengaturan biaya/kontak dengan CACHE per permintaan (satu halaman memanggilnya
 * berkali-kali, sedangkan `app_setting()` selalu menembak basis data). `$paksa` = segarkan cache,
 * dipakai fungsi penyimpan agar nilai baru langsung terpakai pada permintaan yang sama.
 */
function aktivasi_setting(string $kunci, string $bawaan, bool $paksa = false): string
{
    static $cache = [];
    if ($paksa) {
        $cache = [];
    }
    if (!array_key_exists($kunci, $cache)) {
        $cache[$kunci] = app_setting($kunci, $bawaan);
    }
    return $cache[$kunci];
}

/** Nominal biaya aktivasi PERTAMA (masa aktif 1 tahun) — bawaan `AKTIVASI_BIAYA`. */
function aktivasi_biaya_pertama(): int
{
    return max(0, (int) aktivasi_setting('aktivasi_biaya', (string) AKTIVASI_BIAYA));
}

/** Nominal biaya LANGGANAN tahun berikutnya — bawaan `AKTIVASI_BIAYA_LANGGANAN`. */
function aktivasi_biaya_langganan(): int
{
    return max(0, (int) aktivasi_setting('aktivasi_biaya_langganan', (string) AKTIVASI_BIAYA_LANGGANAN));
}

/**
 * Nomor WA HD Admin tujuan **permohonan kode aktivasi** sekaligus **bukti transfer** —
 * bawaan `AKTIVASI_WA_ADMIN`. Ditampilkan apa adanya (bentuk lokal 0…).
 */
function aktivasi_wa(): string
{
    return trim(aktivasi_setting('aktivasi_wa', AKTIVASI_WA_ADMIN));
}

/**
 * true bila nomornya masuk akal: hanya angka (boleh didahului +), 9–15 digit. Tanda baca yang lazim
 * dipakai saat menulis nomor (spasi, -, titik, tanda kurung) dimaafkan dan disimpan sebagai angka.
 */
function aktivasi_wa_sah(string $nomor): bool
{
    $d = (string) preg_replace('/\D/', '', $nomor);
    return $d !== '' && strlen($d) >= 9 && strlen($d) <= AKTIVASI_WA_MAKS
        && preg_match('/^\+?[0-9 ().\-]*$/', trim($nomor)) === 1;
}

/** Nomor WA yang sudah dibersihkan (hanya angka) — dipakai saat menyimpan. */
function aktivasi_wa_bersih(string $nomor): string
{
    return (string) preg_replace('/\D/', '', $nomor);
}

/** Nomor rekening tujuan transfer aktivasi — bawaan `AKTIVASI_REKENING`. */
function aktivasi_rekening(): string
{
    return trim(aktivasi_setting('aktivasi_rekening', AKTIVASI_REKENING));
}

/** Keterangan/peringatan di bawah nomor rekening — bawaan `AKTIVASI_REKENING_KET`. */
function aktivasi_rekening_ket(): string
{
    return trim(aktivasi_setting('aktivasi_rekening_ket', AKTIVASI_REKENING_KET));
}

/**
 * Simpan pengaturan biaya & kontak aktivasi. Nilai `null` = jangan diubah, sehingga formulir yang
 * hanya mengirim sebagian isian tidak mengosongkan isian lainnya.
 */
function aktivasi_biaya_simpan(?int $pertama, ?int $langganan, ?string $wa,
    ?string $rekening = null, ?string $ket = null): void
{
    aktivasi_setting('', '', true); // segarkan cache
    if ($pertama !== null) {
        app_setting_set('aktivasi_biaya', (string) max(0, min(AKTIVASI_BIAYA_MAKS, $pertama)));
    }
    if ($langganan !== null) {
        app_setting_set('aktivasi_biaya_langganan', (string) max(0, min(AKTIVASI_BIAYA_MAKS, $langganan)));
    }
    if ($wa !== null) {
        app_setting_set('aktivasi_wa', substr(aktivasi_wa_bersih($wa), 0, AKTIVASI_WA_MAKS));
    }
    if ($rekening !== null) {
        app_setting_set('aktivasi_rekening', teks($rekening, AKTIVASI_REKENING_MAKS));
    }
    if ($ket !== null) {
        app_setting_set('aktivasi_rekening_ket', teks($ket, AKTIVASI_REKENING_KET_MAKS));
    }
}

/** Kembalikan seluruh pengaturan biaya/kontak aktivasi ke nilai bawaan aplikasi. */
function aktivasi_biaya_bawaan(): void
{
    aktivasi_setting('', '', true);
    app_setting_set('aktivasi_biaya', (string) AKTIVASI_BIAYA);
    app_setting_set('aktivasi_biaya_langganan', (string) AKTIVASI_BIAYA_LANGGANAN);
    app_setting_set('aktivasi_wa', AKTIVASI_WA_ADMIN);
    app_setting_set('aktivasi_rekening', AKTIVASI_REKENING);
    app_setting_set('aktivasi_rekening_ket', AKTIVASI_REKENING_KET);
}

/** true bila seluruh pengaturan masih sama dengan nilai bawaannya (chip "masih bawaan"). */
function aktivasi_biaya_masih_bawaan(): bool
{
    return aktivasi_biaya_pertama() === (int) AKTIVASI_BIAYA
        && aktivasi_biaya_langganan() === (int) AKTIVASI_BIAYA_LANGGANAN
        && aktivasi_wa() === AKTIVASI_WA_ADMIN
        && aktivasi_rekening() === AKTIVASI_REKENING
        && aktivasi_rekening_ket() === AKTIVASI_REKENING_KET;
}

/**
 * Apakah lembaga ini SUDAH PERNAH diaktifasi (jadi tarifnya memakai biaya LANGGANAN)?
 * Penandanya jejak aktivasi yang sungguh terjadi: `aktivasi_pada` terisi ATAU nomor periode
 * (`aktivasi_ke`) sudah melewati 1 — keduanya diisi hanya setelah kode aktivasi berhasil dimasukkan
 * (atau masa aktif diberikan Super Admin lewat halaman Kelola Sekolah).
 *
 * ⚠️ Keadaan `permanen` SENDIRIAN tidak dianggap "sudah diaktifasi": sekolah yang dibuat Super Admin
 * memang permanen tanpa pernah membayar, jadi menampilkan "biaya langganan" di sana menyesatkan.
 */
function aktivasi_pernah_aktif(?array $s = null): bool
{
    $s = $s ?? current_sekolah();
    if (!$s) {
        return false;
    }
    if ((int) ($s['aktivasi_ke'] ?? 1) > 1) {
        return true;
    }
    return trim((string) ($s['aktivasi_pada'] ?? '')) !== '';
}

/** Nominal yang BERLAKU bagi lembaga ini (aktivasi pertama atau langganan perpanjangan). */
function aktivasi_biaya_berlaku(?array $s = null): int
{
    return aktivasi_pernah_aktif($s) ? aktivasi_biaya_langganan() : aktivasi_biaya_pertama();
}

/** Label singkat jenis pembayarannya. */
function aktivasi_biaya_label(?array $s = null): string
{
    $tahun = max(1, (int) round(AKTIVASI_HARI_AKTIF / 365));
    return aktivasi_pernah_aktif($s)
        ? 'Biaya langganan (perpanjangan ' . $tahun . ' tahun)'
        : 'Biaya aktivasi (masa aktif ' . $tahun . ' tahun)';
}

/**
 * Kalimat permintaan pembayaran — STRUKTUR mengikuti permintaan pemilik, hanya nominal & rekeningnya
 * mengikuti pengaturan Super Admin.
 */
function aktivasi_kalimat_biaya(?array $s = null): string
{
    return 'Untuk mengaktifasi Lembaga anda silahkan transfer ' . rupiah(aktivasi_biaya_berlaku($s))
        . ' ke ' . aktivasi_rekening() . '. Jika sudah melakukan transfer silahkan kirim bukti transfer ke wa.';
}

/** Pesan WhatsApp permohonan kode aktivasi. */
function aktivasi_pesan_permohonan(?array $s = null): string
{
    $s = $s ?? current_sekolah();
    $nama = trim((string) ($s['nama'] ?? ''));
    if ($nama === '') {
        $nama = '-';
    }
    return 'Assalamualaikum HD Admin Aplikasi ' . APP_NAME . '. Kami dari ' . $nama
        . ' ingin melakukan aktifasi aplikasi ' . APP_NAME . '.';
}

/** Tautan WhatsApp (wa.me) untuk mengirim permohonan kode aktivasi ke HD Admin. */
function aktivasi_tautan_permohonan(?array $s = null): string
{
    return 'https://wa.me/' . wa_internasional(aktivasi_wa())
        . '?text=' . rawurlencode(aktivasi_pesan_permohonan($s));
}

/** Pesan WhatsApp saat mengirim BUKTI TRANSFER (ke nomor permohonan yang sama). */
function aktivasi_pesan_bukti(?array $s = null): string
{
    $s = $s ?? current_sekolah();
    $nama = trim((string) ($s['nama'] ?? ''));
    if ($nama === '') {
        $nama = '-';
    }
    $jenis = aktivasi_pernah_aktif($s) ? 'biaya langganan' : 'biaya aktifasi';
    return 'Assalamualaikum HD Admin Aplikasi ' . APP_NAME . '. Kami dari ' . $nama
        . ' sudah melakukan transfer ' . $jenis . ' ' . rupiah(aktivasi_biaya_berlaku($s))
        . ' ke ' . aktivasi_rekening() . '. Berikut kami kirimkan bukti transfernya untuk proses '
        . 'verifikasi aktifasi aplikasi ' . APP_NAME . '.';
}

/** Tautan WhatsApp (wa.me) untuk mengirim bukti transfer. */
function aktivasi_tautan_bukti(?array $s = null): string
{
    return 'https://wa.me/' . wa_internasional(aktivasi_wa())
        . '?text=' . rawurlencode(aktivasi_pesan_bukti($s));
}

// ------------------------------------------------------------------ jenis penilaian (jenis ulangan)
// Permintaan pemilik: "Pada akun Super Admin tambahkan fitur untuk menghapus atau menambahkan
// jenis ulangan yang tampil pada halaman buat ujian guru".
//
// Daftar jenis penilaian — dulu DIPATOK pada `ujian_edit.php` — kini disimpan TINGKAT APLIKASI
// di tabel `app_pengaturan` (kunci `jenis_ujian`, JSON berisi daftar nama) sehingga berlaku untuk
// SEMUA sekolah/madrasah, sama seperti pengaturan aplikasi lainnya. Pengelolaannya di halaman
// `jenis_ujian.php` (khusus Super Admin).
//
// PENTING: `ujian.jenis_ujian` menyimpan NAMA jenis sebagai teks, jadi MENGHAPUS sebuah jenis
// tidak mengubah ujian yang sudah tersimpan (label lamanya tetap tercetak) — perilaku ini memang
// disengaja dan dijelaskan pada halaman pengelolaannya.

/** Daftar jenis penilaian bawaan aplikasi. */
function jenis_ujian_bawaan(): array
{
    return ['Ulangan Harian', 'Penilaian Tengah Semester', 'Penilaian Akhir Semester',
        'Try Out', 'Latihan', 'Praktik'];
}

/** Rapikan satu nama jenis penilaian (spasi dirapatkan, panjang dibatasi). */
function jenis_ujian_rapi($t): string
{
    $s = trim(preg_replace('/\s+/', ' ', (string) $t) ?? '');
    return teks($s, JENIS_UJIAN_PANJANG);
}

/**
 * Daftar jenis penilaian yang berlaku, urut sesuai pengaturan Super Admin.
 * Nilai tersimpan yang rusak/kosong otomatis jatuh ke daftar bawaan.
 * $paksa = segarkan cache (dipakai sesudah menyimpan).
 */
function jenis_ujian_list(bool $paksa = false): array
{
    static $cache = null;
    if ($cache !== null && !$paksa) {
        return $cache;
    }
    $daftar = [];
    $mentah = trim(app_setting('jenis_ujian', ''));
    if ($mentah !== '') {
        $data = json_decode($mentah, true);
        if (is_array($data)) {
            foreach ($data as $d) {
                if (!is_scalar($d)) {
                    continue;
                }
                $n = jenis_ujian_rapi($d);
                if ($n !== '' && !in_array($n, $daftar, true)) {
                    $daftar[] = $n;
                }
            }
        }
    }
    if (!$daftar) {
        $daftar = jenis_ujian_bawaan();
    }
    $cache = $daftar;
    return $cache;
}

/** Simpan daftar jenis penilaian (dibersihkan & dibatasi jumlahnya). */
function jenis_ujian_simpan(array $daftar): void
{
    $bersih = [];
    foreach ($daftar as $d) {
        if (!is_scalar($d)) {
            continue;
        }
        $n = jenis_ujian_rapi($d);
        if ($n !== '' && !in_array($n, $bersih, true)) {
            $bersih[] = $n;
        }
        if (count($bersih) >= JENIS_UJIAN_MAKS) {
            break;
        }
    }
    app_setting_set('jenis_ujian', json_encode($bersih, JSON_UNESCAPED_UNICODE));
    jenis_ujian_list(true);
}

/** Tambah satu jenis penilaian. Mengembalikan pesan galat, atau '' bila berhasil. */
function jenis_ujian_tambah(string $nama): string
{
    $n = jenis_ujian_rapi($nama);
    if ($n === '') {
        return 'Nama jenis penilaian wajib diisi.';
    }
    $daftar = jenis_ujian_list();
    foreach ($daftar as $d) {
        if (bawah($d) === bawah($n)) {
            return 'Jenis penilaian "' . $n . '" sudah ada pada daftar.';
        }
    }
    if (count($daftar) >= JENIS_UJIAN_MAKS) {
        return 'Daftar sudah penuh (maksimal ' . (int) JENIS_UJIAN_MAKS . ' jenis penilaian). '
            . 'Hapus salah satu lebih dahulu.';
    }
    $daftar[] = $n;
    jenis_ujian_simpan($daftar);
    return '';
}

/** Hapus satu jenis penilaian. Mengembalikan pesan galat, atau '' bila berhasil. */
function jenis_ujian_hapus(string $nama): string
{
    $daftar = jenis_ujian_list();
    $sisa = array_values(array_filter($daftar, fn($d) => bawah($d) !== bawah(trim($nama))));
    if (count($sisa) === count($daftar)) {
        return 'Jenis penilaian tidak ditemukan pada daftar.';
    }
    if (!$sisa) {
        return 'Minimal harus ada satu jenis penilaian. Tambahkan jenis lain sebelum menghapus yang ini.';
    }
    jenis_ujian_simpan($sisa);
    return '';
}

/** Kembalikan daftar jenis penilaian ke bawaan aplikasi. */
function jenis_ujian_bawaan_kembalikan(): void
{
    app_setting_set('jenis_ujian', json_encode(jenis_ujian_bawaan(), JSON_UNESCAPED_UNICODE));
    jenis_ujian_list(true);
}

/** Apakah daftar yang berlaku masih sama dengan daftar bawaan? */
function jenis_ujian_masih_bawaan(): bool
{
    return jenis_ujian_list() === jenis_ujian_bawaan();
}

/** Berapa ujian (seluruh sekolah) yang memakai satu nama jenis penilaian. */
function jenis_ujian_pakai(string $nama): int
{
    return (int) val('SELECT COUNT(*) FROM ujian WHERE jenis_ujian = ?', [$nama], 0);
}

// ------------------------------------------------------------------ tautan "buat soal otomatis"
// Permintaan pemilik: "Pada halaman Super Admin tambahkan fitur untuk memasukkan link pada fitur
// buat soal otomatis".
//
// Tautan tombol BERKEDIP "Buat Soal Otomatis" (halaman Tambah/Ubah Soal, akun Guru & Operator)
// kini diisi Super Admin lewat menu "Soal Otomatis" (`soal_otomatis.php`) dan disimpan TINGKAT
// APLIKASI di tabel `app_pengaturan` (kunci `soal_otomatis_url`). Konstanta `SOAL_OTOMATIS_URL`
// hanya NILAI BAWAAN — halaman WAJIB memakai `soal_otomatis_url()`, jangan konstantanya langsung.
//
// Kata sandi aplikasi bantu (`SOAL_OTOMATIS_PASSWORD`) BELUM dapat diubah dari aplikasi.

/** Tautan bawaan tombol "Buat Soal Otomatis". */
function soal_otomatis_url_bawaan(): string
{
    return SOAL_OTOMATIS_URL;
}

/** Apakah sebuah tautan boleh dipakai (http/https, ada host, panjang wajar)? */
function soal_otomatis_url_sah($url): bool
{
    $u = trim((string) $url);
    if ($u === '' || strlen($u) > SOAL_OTOMATIS_URL_MAKS) {
        return false;
    }
    if (preg_match('/[\s"\'<>]/', $u) === 1) {
        return false; // spasi/tanda kutip/tanda kurung sudut tidak pernah sah pada URL
    }
    $bagian = parse_url($u);
    if (!is_array($bagian) || empty($bagian['scheme']) || empty($bagian['host'])) {
        return false;
    }
    $skema = bawah((string) $bagian['scheme']);
    return ($skema === 'http' || $skema === 'https');
}

/**
 * Tautan yang BERLAKU untuk tombol "Buat Soal Otomatis".
 * Nilai tersimpan yang rusak/kosong otomatis jatuh ke tautan bawaan.
 */
function soal_otomatis_url(): string
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $u = trim(app_setting('soal_otomatis_url', ''));
    $cache = soal_otomatis_url_sah($u) ? $u : soal_otomatis_url_bawaan();
    return $cache;
}

/** Simpan tautan "Buat Soal Otomatis". Mengembalikan pesan galat, atau '' bila berhasil. */
function soal_otomatis_simpan(string $url): string
{
    $u = trim($url);
    if ($u === '') {
        return 'Tautan wajib diisi (mis. https://gemini.google.com/share/…).';
    }
    if (strlen($u) > SOAL_OTOMATIS_URL_MAKS) {
        return 'Tautan terlalu panjang (maksimal ' . (int) SOAL_OTOMATIS_URL_MAKS . ' karakter).';
    }
    if (!soal_otomatis_url_sah($u)) {
        return 'Tautan belum benar — tulis alamat lengkap yang dimulai dengan http:// atau https:// '
            . 'tanpa spasi, mis. https://gemini.google.com/share/9b5d2dc2944b';
    }
    app_setting_set('soal_otomatis_url', $u);
    return '';
}

/** Kembalikan tautan ke bawaan aplikasi. */
function soal_otomatis_bawaan_kembalikan(): void
{
    app_setting_set('soal_otomatis_url', '');
}

/** Apakah tautan yang berlaku masih tautan bawaan? */
function soal_otomatis_masih_bawaan(): bool
{
    return soal_otomatis_url() === soal_otomatis_url_bawaan();
}

// ------------------------------------------------------------------ ujian & soal

function jenis_soal_label(?string $j): string
{
    return JENIS_SOAL[(string) $j] ?? 'Pilihan Ganda';
}

function jenis_soal_sah(?string $j): string
{
    $j = bawah(trim((string) $j));
    return array_key_exists($j, JENIS_SOAL) ? $j : 'pg';
}

/** Menerjemahkan tulisan jenis soal dari berkas impor. */
function jenis_soal_dari_teks(string $t): string
{
    $s = bawah(trim($t));
    $s = preg_replace('/[^a-z]/', '', $s) ?? '';
    $peta = [
        'pg' => 'pg', 'pilihanganda' => 'pg', 'pilihangandasatu' => 'pg', 'pgbiasa' => 'pg',
        'pgk' => 'pgk', 'pilihangandakompleks' => 'pgk', 'pilihangandabanyak' => 'pgk',
        'pgkompleks' => 'pgk', 'kompleks' => 'pgk', 'multiplechoice' => 'pgk',
        'bs' => 'bs', 'benarsalah' => 'bs', 'benaratausalah' => 'bs', 'truefalse' => 'bs',
        'jodoh' => 'jodoh', 'menjodohkan' => 'jodoh', 'jodohkan' => 'jodoh', 'matching' => 'jodoh',
        'pasangan' => 'jodoh',
        'isian' => 'isian', 'isiansingkat' => 'isian', 'jawabansingkat' => 'isian', 'shortanswer' => 'isian',
        'uraian' => 'uraian', 'essai' => 'uraian', 'esai' => 'uraian', 'essay' => 'uraian', 'uraianessai' => 'uraian',
    ];
    return $peta[$s] ?? 'pg';
}

function opsi_decode(?string $json): array
{
    if ($json === null || trim((string) $json) === '') {
        return [];
    }
    $d = json_decode((string) $json, true);
    return is_array($d) ? array_values($d) : [];
}

function opsi_encode(array $opsi): string
{
    return json_encode(array_values($opsi), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function kunci_decode(?string $json)
{
    if ($json === null || trim((string) $json) === '') {
        return null;
    }
    $d = json_decode((string) $json, true);
    return $d;
}

function huruf_opsi(int $i): string
{
    return chr(65 + $i);
}

/** Satu soal lengkap (opsi & kunci sudah didekode). */
function soal_lengkap(array $s): array
{
    $s['opsi_arr'] = opsi_decode($s['opsi'] ?? null);
    $s['kunci'] = kunci_decode($s['jawaban'] ?? null);
    return $s;
}

function soal_ujian(int $ujianId): array
{
    $rows = all('SELECT * FROM soal WHERE ujian_id = ? ORDER BY urutan, id', [$ujianId]);
    return array_map('soal_lengkap', $rows);
}

function ujian_skor_maks(int $ujianId): float
{
    return (float) val('SELECT COALESCE(SUM(skor),0) FROM soal WHERE ujian_id = ?', [$ujianId], 0);
}

/** Data ujian milik sekolah yang sedang aktif (anti-IDOR). */
function ujian_by_id(int $id): ?array
{
    if ($id <= 0 || sekolah_id() <= 0) {
        return null;
    }
    return one('SELECT * FROM ujian WHERE id = ? AND sekolah_id = ?', [$id, sekolah_id()]);
}

function ujian_kelas_ids(int $ujianId): array
{
    $out = [];
    foreach (all('SELECT kelas_id FROM ujian_kelas WHERE ujian_id = ?', [$ujianId]) as $r) {
        $out[] = (int) $r['kelas_id'];
    }
    return $out;
}

/** Urutan tampilan soal bagi seorang siswa (acak bila ujian disetel acak). */
function soal_urut_untuk(int $ujianId, array $ujian, int $seed): array
{
    $soal = soal_ujian($ujianId);
    if ((int) ($ujian['acak_soal'] ?? 0) === 1 && count($soal) > 1) {
        mt_srand(crc32('soal' . $ujianId . '|' . $seed));
        for ($i = count($soal) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            $t = $soal[$i];
            $soal[$i] = $soal[$j];
            $soal[$j] = $t;
        }
        mt_srand();
    }
    return $soal;
}

/** Urutan acak opsi sebuah soal (dipakai soal PG/PGK) — stabil per sesi. */
function opsi_urut(int $soalId, array $ujian, int $seed, int $n): array
{
    $idx = range(0, max(0, $n - 1));
    if ((int) ($ujian['acak_opsi'] ?? 0) !== 1 || $n <= 1) {
        return $idx;
    }
    mt_srand(crc32('opsi' . $soalId . '|' . $seed));
    for ($i = $n - 1; $i > 0; $i--) {
        $j = mt_rand(0, $i);
        $t = $idx[$i];
        $idx[$i] = $idx[$j];
        $idx[$j] = $t;
    }
    mt_srand();
    return $idx;
}

/** Urutan tampilan opsi kanan pada soal menjodohkan (selalu diacak, stabil per sesi). */
function jodoh_urut(int $soalId, int $seed, int $n): array
{
    $idx = range(0, max(0, $n - 1));
    mt_srand(crc32('jodoh' . $soalId . '|' . $seed));
    for ($i = $n - 1; $i > 0; $i--) {
        $j = mt_rand(0, $i);
        $t = $idx[$i];
        $idx[$i] = $idx[$j];
        $idx[$j] = $t;
    }
    mt_srand();
    return $idx;
}

// ------------------------------------------------------------------ penilaian otomatis

/** Normalisasi teks jawaban isian/uraian agar perbandingan lebih toleran. */
function teks_normal(string $s): string
{
    $s = bawah(trim($s));
    $s = str_replace(
        ['á','à','â','ä','é','è','ê','ë','í','ì','î','ï','ó','ò','ô','ö','ú','ù','û','ü','ç','ñ'],
        ['a','a','a','a','e','e','e','e','i','i','i','i','o','o','o','o','u','u','u','u','c','n'],
        $s
    );
    $s = preg_replace('/[^a-z0-9 ]+/', ' ', $s) ?? '';
    $s = preg_replace('/\s+/', ' ', $s) ?? '';
    return trim($s);
}

/**
 * Nilai satu soal.
 * @return array{skor: float, maks: float, benar: int|null, status: string}
 *   status: benar | sebagian | salah | kosong | koreksi
 */
function soal_nilai(array $soal, $jawab, int $seed = 0): array
{
    $maks = (float) ($soal['skor'] ?? 0);
    $jenis = (string) ($soal['jenis'] ?? 'pg');
    $kunci = $soal['kunci'] ?? kunci_decode($soal['jawaban'] ?? null);
    $opsi = $soal['opsi_arr'] ?? opsi_decode($soal['opsi'] ?? null);
    $sid = (int) ($soal['id'] ?? 0);

    $kosong = $jawab === null || $jawab === '' || (is_array($jawab) && count($jawab) === 0);
    if ($kosong) {
        return ['skor' => 0.0, 'maks' => $maks, 'benar' => 0, 'status' => 'kosong'];
    }

    switch ($jenis) {
        case 'pg':
            $pilih = (int) $jawab;
            $k = (int) $kunci;
            $benar = ($pilih === $k) ? 1 : 0;
            return ['skor' => $benar ? $maks : 0.0, 'maks' => $maks, 'benar' => $benar,
                'status' => $benar ? 'benar' : 'salah'];

        case 'pgk':
            $pilih = array_map('intval', is_array($jawab) ? $jawab : []);
            $kunciArr = array_map('intval', is_array($kunci) ? $kunci : []);
            if (count($kunciArr) === 0) {
                return ['skor' => 0.0, 'maks' => $maks, 'benar' => 0, 'status' => 'salah'];
            }
            $tepat = count(array_intersect($pilih, $kunciArr));
            $salah = count(array_diff($pilih, $kunciArr));
            $rasio = max(0.0, ($tepat - $salah) / count($kunciArr));
            $rasio = min(1.0, $rasio);
            $status = $rasio >= 0.999 ? 'benar' : ($rasio > 0 ? 'sebagian' : 'salah');
            return ['skor' => round($maks * $rasio, 4), 'maks' => $maks,
                'benar' => $rasio >= 0.999 ? 1 : 0, 'status' => $status];

        case 'bs':
            $pilih = is_array($jawab) ? array_values($jawab) : [];
            $kunciArr = is_array($kunci) ? array_values($kunci) : [];
            $n = count($kunciArr);
            if ($n === 0) {
                return ['skor' => 0.0, 'maks' => $maks, 'benar' => 0, 'status' => 'salah'];
            }
            $tepat = 0;
            for ($i = 0; $i < $n; $i++) {
                if (isset($pilih[$i]) && atas((string) $pilih[$i]) === atas((string) $kunciArr[$i])) {
                    $tepat++;
                }
            }
            $rasio = $tepat / $n;
            $status = $rasio >= 0.999 ? 'benar' : ($rasio > 0 ? 'sebagian' : 'salah');
            return ['skor' => round($maks * $rasio, 4), 'maks' => $maks,
                'benar' => $rasio >= 0.999 ? 1 : 0, 'status' => $status];

        case 'jodoh':
            $pilih = is_array($jawab) ? array_values($jawab) : [];
            $n = count($opsi);
            if ($n === 0) {
                return ['skor' => 0.0, 'maks' => $maks, 'benar' => 0, 'status' => 'salah'];
            }
            $urut = jodoh_urut($sid, $seed, $n);
            $tepat = 0;
            for ($i = 0; $i < $n; $i++) {
                $p = $pilih[$i] ?? null;
                if ($p === null || $p === '') {
                    continue;
                }
                $p = (int) $p;
                if (isset($urut[$p]) && (int) $urut[$p] === $i) {
                    $tepat++;
                }
            }
            $rasio = $tepat / $n;
            $status = $rasio >= 0.999 ? 'benar' : ($rasio > 0 ? 'sebagian' : 'salah');
            return ['skor' => round($maks * $rasio, 4), 'maks' => $maks,
                'benar' => $rasio >= 0.999 ? 1 : 0, 'status' => $status];

        case 'isian':
            $isi = teks_normal(is_array($jawab) ? implode(' ', $jawab) : (string) $jawab);
            $kunciArr = is_array($kunci) ? $kunci : [(string) $kunci];
            foreach ($kunciArr as $k) {
                $k = teks_normal((string) $k);
                if ($k !== '' && $isi === $k) {
                    return ['skor' => $maks, 'maks' => $maks, 'benar' => 1, 'status' => 'benar'];
                }
            }
            return ['skor' => 0.0, 'maks' => $maks, 'benar' => 0, 'status' => 'salah'];

        case 'uraian':
            $isi = teks_normal(is_array($jawab) ? implode(' ', $jawab) : (string) $jawab);
            $kunciArr = is_array($kunci) ? $kunci : [(string) $kunci];
            $kunciArr = array_values(array_filter(array_map('trim', $kunciArr), fn($x) => $x !== ''));
            if (count($kunciArr) === 0) {
                return ['skor' => 0.0, 'maks' => $maks, 'benar' => null, 'status' => 'koreksi'];
            }
            $ada = 0;
            foreach ($kunciArr as $k) {
                foreach (explode('/', $k) as $alt) {
                    $alt = teks_normal($alt);
                    if ($alt !== '' && str_contains($isi, $alt)) {
                        $ada++;
                        break;
                    }
                }
            }
            $rasio = min(1.0, $ada / count($kunciArr));
            return ['skor' => round($maks * $rasio, 4), 'maks' => $maks,
                'benar' => null, 'status' => 'koreksi'];
    }

    return ['skor' => 0.0, 'maks' => $maks, 'benar' => 0, 'status' => 'salah'];
}

/** Simpan jawaban satu soal + hitung nilainya. */
function jawaban_simpan(int $sesiId, int $soalId, $jawab, bool $manual = false): void
{
    $sesi = one('SELECT * FROM sesi_ujian WHERE id = ?', [$sesiId]);
    $soal = one('SELECT * FROM soal WHERE id = ?', [$soalId]);
    if (!$sesi || !$soal) {
        return;
    }
    $soal = soal_lengkap($soal);
    $hit = soal_nilai($soal, $jawab, $sesiId);
    $json = is_array($jawab) ? json_encode(array_values($jawab), JSON_UNESCAPED_UNICODE) : (string) $jawab;

    $ada = one('SELECT id, manual FROM jawaban WHERE sesi_id = ? AND soal_id = ?', [$sesiId, $soalId]);
    if ($ada) {
        if (!$manual && (int) $ada['manual'] === 1) {
            // skor sudah disesuaikan guru — jangan ditimpa penilaian otomatis
            q('UPDATE jawaban SET jawaban = ?, dijawab = ? WHERE id = ?',
                [$json, date('Y-m-d H:i:s'), $ada['id']]);
            return;
        }
        q('UPDATE jawaban SET jawaban = ?, benar = ?, skor = ?, dijawab = ?, manual = ? WHERE id = ?',
            [$json, $hit['benar'], $hit['skor'], date('Y-m-d H:i:s'), $manual ? 1 : 0, $ada['id']]);
        return;
    }
    q('INSERT INTO jawaban (sesi_id, soal_id, jawaban, benar, skor, dijawab, manual) VALUES (?,?,?,?,?,?,?)',
        [$sesiId, $soalId, $json, $hit['benar'], $hit['skor'], date('Y-m-d H:i:s'), $manual ? 1 : 0]);
}

/** Nilai satu baris jawaban milik sesi (kunci dari tabel jawaban). */
function jawaban_sesi(int $sesiId): array
{
    $out = [];
    foreach (all('SELECT * FROM jawaban WHERE sesi_id = ?', [$sesiId]) as $r) {
        $out[(int) $r['soal_id']] = $r;
    }
    return $out;
}

/** Jawaban siswa dalam bentuk asli (JSON didekode). */
function jawaban_nilai_mentah(?string $json)
{
    if ($json === null || trim((string) $json) === '') {
        return null;
    }
    $d = json_decode((string) $json, true);
    return $d === null ? (string) $json : $d;
}

/**
 * Hitung ulang seluruh nilai sebuah sesi ujian dan simpan ke tabel sesi_ujian.
 * Memakai skor manual bila guru sudah menyesuaikan (kolom jawaban.manual = 1).
 */
function sesi_hitung(int $sesiId): array
{
    $sesi = one('SELECT * FROM sesi_ujian WHERE id = ?', [$sesiId]);
    if (!$sesi) {
        return [];
    }
    $soal = soal_ujian((int) $sesi['ujian_id']);
    $jwb = jawaban_sesi($sesiId);

    $skor = 0.0;
    $maks = 0.0;
    $benar = 0;
    $salah = 0;
    $kosong = 0;
    $perlu = 0;

    foreach ($soal as $s) {
        $sid = (int) $s['id'];
        $maks += (float) $s['skor'];
        $row = $jwb[$sid] ?? null;
        if ((string) $s['jenis'] === 'uraian') {
            $perlu = 1;
        }
        if (!$row) {
            $kosong++;
            continue;
        }
        $mentah = jawaban_nilai_mentah($row['jawaban'] ?? null);
        if ($mentah === null) {
            $kosong++;
            continue;
        }
        if ((int) $row['manual'] === 1) {
            $sk = (float) $row['skor'];
            $bn = $row['benar'];
        } else {
            $hit = soal_nilai($s, $mentah, $sesiId);
            $sk = $hit['skor'];
            $bn = $hit['benar'];
        }
        $skor += $sk;
        if ($bn === 1) {
            $benar++;
        } elseif ($bn === 0) {
            $salah++;
        }
    }

    $nilai = $maks > 0 ? round($skor / $maks * 100, 2) : 0.0;
    q('UPDATE sesi_ujian SET nilai = ?, benar = ?, salah = ?, kosong = ?, perlu_koreksi = ? WHERE id = ?',
        [$nilai, $benar, $salah, $kosong, $perlu, $sesiId]);

    return [
        'skor' => round($skor, 4), 'maks' => round($maks, 4), 'nilai' => $nilai,
        'benar' => $benar, 'salah' => $salah, 'kosong' => $kosong, 'perlu_koreksi' => $perlu,
    ];
}

/** Ringkasan cepat (dipakai saat menyimpan otomatis) tanpa menghitung ulang semua soal. */
function sesi_ringkas(int $sesiId): void
{
    $sesi = one('SELECT * FROM sesi_ujian WHERE id = ?', [$sesiId]);
    if (!$sesi) {
        return;
    }
    $maks = ujian_skor_maks((int) $sesi['ujian_id']);
    $totalSoal = (int) val('SELECT COUNT(*) FROM soal WHERE ujian_id = ?', [(int) $sesi['ujian_id']], 0);
    $r = one('SELECT COUNT(*) AS dijawab,
                     SUM(CASE WHEN benar = 1 THEN 1 ELSE 0 END) AS benar,
                     SUM(CASE WHEN benar = 0 THEN 1 ELSE 0 END) AS salah,
                     SUM(COALESCE(skor,0)) AS skor
              FROM jawaban WHERE sesi_id = ? AND jawaban IS NOT NULL AND jawaban <> ""', [$sesiId]);
    $dijawab = (int) ($r['dijawab'] ?? 0);
    $skor = (float) ($r['skor'] ?? 0);
    $nilai = $maks > 0 ? round($skor / $maks * 100, 2) : 0.0;
    q('UPDATE sesi_ujian SET nilai = ?, benar = ?, salah = ?, kosong = ? WHERE id = ?',
        [$nilai, (int) ($r['benar'] ?? 0), (int) ($r['salah'] ?? 0), max(0, $totalSoal - $dijawab), $sesiId]);
}

// ------------------------------------------------------------------ analisis butir & kelas

/**
 * Analisis setiap butir soal dari seluruh sesi sebuah ujian.
 *
 * Mengembalikan per butir: jumlah peserta, benar, %benar, tingkat kesukaran, daya beda,
 * **VALIDITAS** (korelasi skor butir dengan skor total), **DISTRAKTOR** (sebaran tiap pilihan
 * jawaban beserta penilaian berfungsi/tidak), dan sebaran jawaban.
 */
function analisis_butir(int $ujianId): array
{
    $ujian = one('SELECT * FROM ujian WHERE id = ?', [$ujianId]);
    if (!$ujian) {
        return [];
    }
    $soal = soal_ujian($ujianId);
    $sesi = all("SELECT id, siswa_id, nilai FROM sesi_ujian WHERE ujian_id = ? AND status IN ('selesai','dikumpulkan')", [$ujianId]);
    $peserta = count($sesi);
    if ($peserta === 0 || count($soal) === 0) {
        return ['peserta' => $peserta, 'butir' => [], 'kelompok' => 0, 'ringkas' => []];
    }

    // Peringkat peserta (untuk daya beda & kelompok atas/bawah 27%)
    usort($sesi, fn($a, $b) => ((float) $b['nilai']) <=> ((float) $a['nilai']));
    $nKel = max(1, (int) round($peserta * 0.27));
    $atas = array_map('intval', array_slice(array_column($sesi, 'id'), 0, $nKel));
    $bawah = array_map('intval', array_slice(array_column($sesi, 'id'), -$nKel));

    $rows = [];
    foreach ($soal as $s) {
        $rows[(int) $s['id']] = ['soal' => $s, 'benar' => 0, 'dijawab' => 0, 'sebaran' => [],
            'atas' => 0, 'bawah' => 0, 'skor' => [], 'pilih' => [], 'pilih_atas' => [], 'pilih_bawah' => []];
    }
    // Skor total tiap peserta (dasar perhitungan validitas)
    $totalSesi = [];
    foreach (all("SELECT sesi_id, SUM(COALESCE(skor,0)) AS t FROM jawaban
                  WHERE sesi_id IN (SELECT id FROM sesi_ujian WHERE ujian_id = ? AND status IN ('selesai','dikumpulkan'))
                  GROUP BY sesi_id", [$ujianId]) as $r) {
        $totalSesi[(int) $r['sesi_id']] = (float) $r['t'];
    }

    foreach (all("SELECT * FROM jawaban WHERE sesi_id IN (SELECT id FROM sesi_ujian WHERE ujian_id = ? AND status IN ('selesai','dikumpulkan'))", [$ujianId]) as $r) {
        $sid = (int) $r['soal_id'];
        $sesiId = (int) $r['sesi_id'];
        if (!isset($rows[$sid])) {
            continue;
        }
        $mentah = jawaban_nilai_mentah($r['jawaban'] ?? null);
        if ($mentah === null) {
            continue;
        }
        $rows[$sid]['dijawab']++;
        $rows[$sid]['skor'][$sesiId] = (float) ($r['skor'] ?? 0);
        if ((int) $r['benar'] === 1) {
            $rows[$sid]['benar']++;
        }
        if (in_array($sesiId, $atas, true) && (int) $r['benar'] === 1) {
            $rows[$sid]['atas']++;
        }
        if (in_array($sesiId, $bawah, true) && (int) $r['benar'] === 1) {
            $rows[$sid]['bawah']++;
        }
        // Sebaran pilihan (PG & PGK) + siapa yang memilih (untuk menilai distraktor)
        $jenis = (string) $rows[$sid]['soal']['jenis'];
        if (in_array($jenis, ['pg', 'pgk'], true)) {
            foreach ((is_array($mentah) ? $mentah : [$mentah]) as $pv) {
                $pv = (int) $pv;
                $rows[$sid]['sebaran'][$pv] = ($rows[$sid]['sebaran'][$pv] ?? 0) + 1;
                $rows[$sid]['pilih'][$pv] = ($rows[$sid]['pilih'][$pv] ?? 0) + 1;
                if (in_array($sesiId, $atas, true)) {
                    $rows[$sid]['pilih_atas'][$pv] = ($rows[$sid]['pilih_atas'][$pv] ?? 0) + 1;
                }
                if (in_array($sesiId, $bawah, true)) {
                    $rows[$sid]['pilih_bawah'][$pv] = ($rows[$sid]['pilih_bawah'][$pv] ?? 0) + 1;
                }
            }
        }
    }

    /** Korelasi Pearson antara skor butir dan skor total (indeks VALIDITAS butir). */
    $pearson = function (array $skorButir) use ($totalSesi): float {
        $xs = [];
        $ys = [];
        foreach ($skorButir as $sesiId => $x) {
            if (!isset($totalSesi[$sesiId])) {
                continue;
            }
            $xs[] = (float) $x;
            $ys[] = (float) $totalSesi[$sesiId];
        }
        $n = count($xs);
        if ($n < 3) {
            return 0.0;
        }
        $mx = array_sum($xs) / $n;
        $my = array_sum($ys) / $n;
        $cov = 0.0;
        $vx = 0.0;
        $vy = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dx = $xs[$i] - $mx;
            $dy = $ys[$i] - $my;
            $cov += $dx * $dy;
            $vx += $dx * $dx;
            $vy += $dy * $dy;
        }
        if ($vx <= 0 || $vy <= 0) {
            return 0.0;
        }
        return round($cov / sqrt($vx * $vy), 4);
    };

    $out = [];
    $hitung = ['mudah' => 0, 'sedang' => 0, 'sukar' => 0, 'valid' => 0, 'cukup' => 0, 'tidak_valid' => 0,
        'distraktor_buruk' => 0];
    foreach ($rows as $sid => $r) {
        $pB = $peserta > 0 ? $r['benar'] / $peserta : 0;
        $db = $nKel > 0 ? ($r['atas'] - $r['bawah']) / $nKel : 0;
        $val = $pearson($r['skor']);
        $valLabel = $val >= 0.4 ? 'Sangat baik' : ($val >= 0.3 ? 'Valid' : ($val >= 0.2 ? 'Cukup' : 'Tidak valid'));
        $kesukaran = $pB >= 0.7 ? 'Mudah' : ($pB >= 0.3 ? 'Sedang' : 'Sukar');

        // ---- DISTRAKTOR (hanya soal pilihan) ----
        $distraktor = [];
        $jenis = (string) $r['soal']['jenis'];
        if (in_array($jenis, ['pg', 'pgk'], true)) {
            $kunciArr = is_array($r['soal']['kunci'])
                ? array_map('intval', $r['soal']['kunci'])
                : [(int) $r['soal']['kunci']];
            foreach ($r['soal']['opsi_arr'] as $oi => $teks) {
                $jml = (int) ($r['pilih'][$oi] ?? 0);
                $pers = $peserta > 0 ? $jml / $peserta * 100 : 0;
                $a = (int) ($r['pilih_atas'][$oi] ?? 0);
                $b = (int) ($r['pilih_bawah'][$oi] ?? 0);
                $kunci = in_array($oi, $kunciArr, true);
                if ($kunci) {
                    $status = $jml === 0 ? 'Kunci tidak dipilih' : 'Kunci jawaban';
                } else {
                    // Distraktor BERFUNGSI bila ada yang memilih DAN kelompok bawah memilihnya
                    // lebih banyak daripada kelompok atas.
                    $status = ($jml > 0 && $b >= $a) ? 'Berfungsi' : ($jml === 0 ? 'Tidak ada pemilih' : 'Perlu diperbaiki');
                    if ($status !== 'Berfungsi') {
                        $hitung['distraktor_buruk']++;
                    }
                }
                $distraktor[] = [
                    'huruf' => huruf_opsi((int) $oi),
                    'teks' => (string) $teks,
                    'kunci' => $kunci,
                    'jumlah' => $jml,
                    'persen' => $pers,
                    'atas' => $a,
                    'bawah' => $b,
                    'status' => $status,
                ];
            }
        }

        $hitung[$kesukaran === 'Mudah' ? 'mudah' : ($kesukaran === 'Sedang' ? 'sedang' : 'sukar')]++;
        $hitung[$valLabel === 'Tidak valid' ? 'tidak_valid' : ($valLabel === 'Cukup' ? 'cukup' : 'valid')]++;

        $out[] = [
            'soal_id'   => $sid,
            'nomor'     => 0,
            'jenis'     => $jenis,
            'pertanyaan' => (string) $r['soal']['pertanyaan'],
            'skor'      => (float) $r['soal']['skor'],
            'benar'     => $r['benar'],
            'dijawab'   => $r['dijawab'],
            'peserta'   => $peserta,
            'p_benar'   => $pB,
            'kesukaran' => $kesukaran,
            'daya_beda' => $db,
            'db_label'  => $db >= 0.4 ? 'Baik' : ($db >= 0.2 ? 'Cukup' : 'Perlu revisi'),
            'validitas' => $val,
            'valid_label' => $valLabel,
            'sebaran'   => $r['sebaran'],
            'distraktor' => $distraktor,
            'opsi'      => $r['soal']['opsi_arr'],
            'kunci'     => $r['soal']['kunci'],
        ];
    }
    return ['peserta' => $peserta, 'butir' => $out, 'kelompok' => $nKel, 'ringkas' => $hitung];
}

/** Ringkasan statistik nilai sebuah ujian (kelompok: kelas). */
function ringkas_nilai(int $ujianId, ?int $kelasId = null): array
{
    $sql = "SELECT su.id, su.nilai, su.status, su.perlu_koreksi, su.benar, su.salah, su.kosong,
                   sw.kelas_id, sw.nama, sw.nis, k.nama AS kelas
            FROM sesi_ujian su
            JOIN siswa sw ON sw.id = su.siswa_id
            LEFT JOIN kelas k ON k.id = sw.kelas_id
            WHERE su.ujian_id = ? AND su.status IN ('selesai','dikumpulkan')";
    $p = [$ujianId];
    if ($kelasId !== null && $kelasId > 0) {
        $sql .= ' AND sw.kelas_id = ?';
        $p[] = $kelasId;
    }
    $sql .= ' ORDER BY su.nilai DESC, sw.nama';
    $rows = all($sql, $p);

    $nilai = array_map(fn($r) => (float) $r['nilai'], $rows);
    $n = count($nilai);
    $uj = one('SELECT * FROM ujian WHERE id = ?', [$ujianId]);
    $kkm = kktp_berlaku($uj); // KKTP ujian/mapel, cadangan KKM sekolah
    $tuntas = count(array_filter($nilai, fn($v) => $v >= $kkm));
    // Kelompok tindak lanjut: REMEDIAL (nilai < KKTP) dan PENGAYAAN (nilai >= KKTP).
    $remedial = array_values(array_filter($rows, fn($r) => (float) $r['nilai'] < $kkm));
    $pengayaan = array_values(array_filter($rows, fn($r) => (float) $r['nilai'] >= $kkm));

    return [
        'baris'    => $rows,
        'peserta'  => $n,
        'rata'     => $n ? array_sum($nilai) / $n : 0.0,
        'tertinggi' => $n ? max($nilai) : 0.0,
        'terendah' => $n ? min($nilai) : 0.0,
        'tuntas'   => $tuntas,
        'belum'    => $n - $tuntas,
        'kkm'      => $kkm,
        'persen_tuntas' => $n ? $tuntas / $n * 100 : 0.0,
        'perlu_koreksi' => count(array_filter($rows, fn($r) => (int) $r['perlu_koreksi'] === 1)),
        'remedial' => $remedial,
        'pengayaan' => $pengayaan,
    ];
}

/** Sebaran nilai per rentang (untuk grafik). */
function sebaran_nilai(array $baris): array
{
    $label = ['0-54', '55-69', '70-79', '80-89', '90-100'];
    $out = array_fill_keys($label, 0);
    foreach ($baris as $r) {
        $v = (float) $r['nilai'];
        if ($v < 55) {
            $out['0-54']++;
        } elseif ($v < 70) {
            $out['55-69']++;
        } elseif ($v < 80) {
            $out['70-79']++;
        } elseif ($v < 90) {
            $out['80-89']++;
        } else {
            $out['90-100']++;
        }
    }
    return $out;
}


// ------------------------------------------------------------------ KKTP (Kriteria Ketercapaian)

/**
 * Nilai KKTP yang berlaku: KKTP ujian -> KKTP mata pelajaran -> KKM sekolah.
 * Permintaan pemilik: guru dapat mengatur KKTP setiap mata pelajaran yang akan diaktifkan.
 */
function kktp_berlaku(?array $ujian = null): float
{
    if ($ujian) {
        $k = $ujian['kktp'] ?? null;
        if ($k !== null && $k !== '' && (float) $k > 0) {
            return (float) $k;
        }
        $mid = (int) ($ujian['mapel_id'] ?? 0);
        if ($mid > 0) {
            $mk = val('SELECT kktp FROM mapel WHERE id = ?', [$mid], null);
            if ($mk !== null && $mk !== '' && (float) $mk > 0) {
                return (float) $mk;
            }
        }
    }
    return (float) setting('kkm', '75');
}

/** KKTP tersimpan sebuah mata pelajaran (0 bila belum diatur). */
function kktp_mapel(int $mapelId): float
{
    if ($mapelId <= 0) {
        return 0.0;
    }
    $v = val('SELECT kktp FROM mapel WHERE id = ? AND sekolah_id = ?', [$mapelId, sekolah_id()], null);
    return ($v === null || $v === '') ? 0.0 : (float) $v;
}

/** Simpan KKTP sebuah mata pelajaran (dipakai saat guru menyimpan pengaturan ujian). */
function kktp_mapel_simpan(int $mapelId, float $nilai): void
{
    if ($mapelId > 0 && $nilai > 0) {
        q('UPDATE mapel SET kktp = ? WHERE id = ? AND sekolah_id = ?', [$nilai, $mapelId, sekolah_id()]);
    }
}


// ------------------------------------------------------------------ TOKEN & JADWAL UJIAN

/** Token acak tanpa karakter yang mudah tertukar (0/O, 1/I/L). */
function token_buat(int $panjang = 6): string
{
    $abjad = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $s = '';
    for ($i = 0; $i < $panjang; $i++) {
        $s .= $abjad[random_int(0, strlen($abjad) - 1)];
    }
    return $s;
}

/** Perbandingan token toleran penulisan (huruf kecil, spasi, tanda hubung). */
function token_normalisasi(string $t): string
{
    $s = strtoupper(trim($t));
    $s = preg_replace('/[^A-Z0-9]/', '', $s) ?? '';
    return str_replace(['0'], ['O'], $s);
}

function ujian_token(?array $ujian): string
{
    $t = trim((string) ($ujian['token'] ?? ''));
    return $t;
}

function ujian_butuh_token(?array $ujian): bool
{
    return ujian_token($ujian) !== '';
}

function ujian_token_sah(?array $ujian, string $masukan): bool
{
    if (!$ujian) {
        return false;
    }
    $a = token_normalisasi($masukan);
    $b = token_normalisasi(ujian_token($ujian));
    return $a !== '' && $b !== '' && hash_equals($b, $a);
}

/**
 * Keadaan jadwal sebuah ujian.
 * @return array{keadaan:string,pesan:string,mulai:?string,selesai:?string}
 *   keadaan: selalu | belum | buka | tutup
 */
function ujian_jadwal(?array $ujian): array
{
    if (!$ujian) {
        return ['keadaan' => 'tutup', 'pesan' => 'Ujian tidak ditemukan.', 'mulai' => null, 'selesai' => null];
    }
    $mulai = trim((string) ($ujian['mulai'] ?? ''));
    $selesai = trim((string) ($ujian['selesai'] ?? ''));
    if ((string) $ujian['status'] !== 'aktif') {
        return ['keadaan' => 'tutup', 'pesan' => 'Ujian belum diaktifkan operator.', 'mulai' => $mulai ?: null, 'selesai' => $selesai ?: null];
    }
    $kini = time();
    if ($mulai !== '') {
        $ts = strtotime($mulai);
        if ($ts !== false && $kini < $ts) {
            return ['keadaan' => 'belum', 'pesan' => 'Ujian dibuka ' . waktu_id($mulai) . '.', 'mulai' => $mulai, 'selesai' => $selesai ?: null];
        }
    }
    if ($selesai !== '') {
        $ts = strtotime($selesai);
        if ($ts !== false && $kini > $ts) {
            return ['keadaan' => 'tutup', 'pesan' => 'Jadwal ujian sudah berakhir (' . waktu_id($selesai) . ').', 'mulai' => $mulai ?: null, 'selesai' => $selesai];
        }
    }
    if ($mulai === '' && $selesai === '') {
        return ['keadaan' => 'selalu', 'pesan' => 'Ujian terbuka (tanpa jadwal khusus).', 'mulai' => null, 'selesai' => null];
    }
    return ['keadaan' => 'buka', 'pesan' => 'Ujian sedang berlangsung.', 'mulai' => $mulai ?: null, 'selesai' => $selesai ?: null];
}

function ujian_terbuka(?array $ujian): bool
{
    $k = ujian_jadwal($ujian)['keadaan'];
    return $k === 'buka' || $k === 'selalu';
}

/** Batas percobaan token per sesi (agar tidak bisa ditebak). */
function token_coba_habis(int $ujianId): bool
{
    $c = $_SESSION['token_coba'][$ujianId] ?? null;
    if (!is_array($c) || (int) ($c['sampai'] ?? 0) < time()) {
        unset($_SESSION['token_coba'][$ujianId]);
        return false;
    }
    return (int) ($c['jumlah'] ?? 0) >= TOKEN_MAKS_COBA;
}

function token_coba_tambah(int $ujianId): int
{
    $c = $_SESSION['token_coba'][$ujianId] ?? null;
    if (!is_array($c) || (int) ($c['sampai'] ?? 0) < time()) {
        $c = ['jumlah' => 0, 'sampai' => time() + TOKEN_MENIT_COBA * 60];
    }
    $c['jumlah'] = (int) $c['jumlah'] + 1;
    $_SESSION['token_coba'][$ujianId] = $c;
    return max(0, TOKEN_MAKS_COBA - (int) $c['jumlah']);
}

function token_coba_bersih(int $ujianId): void
{
    unset($_SESSION['token_coba'][$ujianId]);
}

// ------------------------------------------------------------------ GAMBAR PADA SOAL

function soal_gambar(?array $soal): string
{
    $g = trim((string) ($soal['gambar'] ?? ''));
    return $g;
}

/** Unduh isi sebuah URL (dipakai untuk menyisipkan gambar ke berkas Word). */
function unduh_url(string $url): ?string
{
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        return null;
    }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 30,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (is_string($body) && $body !== '' && $code < 400) {
            return $body;
        }
        return null;
    }
    if (!ini_get('allow_url_fopen')) {
        return null;
    }
    $ctx = stream_context_create(['http' => ['timeout' => 20, 'ignore_errors' => true]]);
    $body = @file_get_contents($url, false, $ctx);
    return (is_string($body) && $body !== '') ? $body : null;
}

/**
 * Ukuran gambar (lebar, tinggi) dari isi berkas — tanpa bergantung pada ekstensi GD.
 * Mendukung PNG, JPEG, dan GIF; bila gagal dipakai ukuran cadangan.
 */
function gambar_ukuran(string $bytes): array
{
    $cadangan = [800, 600];
    if (function_exists('getimagesize')) {
        $info = @getimagesize('data://application/octet-stream;base64,' . base64_encode($bytes));
        if (is_array($info) && !empty($info[0]) && !empty($info[1])) {
            return [(int) $info[0], (int) $info[1]];
        }
    }
    // PNG: lebar/tinggi ada di IHDR (byte 16-23)
    if (substr($bytes, 0, 8) === "\x89PNG\r\n\x1a\n") {
        $w = unpack('N', substr($bytes, 16, 4))[1] ?? 0;
        $h = unpack('N', substr($bytes, 20, 4))[1] ?? 0;
        if ($w > 0 && $h > 0) {
            return [(int) $w, (int) $h];
        }
    }
    // GIF: lebar/tinggi little-endian di byte 6-9
    if (substr($bytes, 0, 3) === 'GIF') {
        $w = unpack('v', substr($bytes, 6, 2))[1] ?? 0;
        $h = unpack('v', substr($bytes, 8, 2))[1] ?? 0;
        if ($w > 0 && $h > 0) {
            return [(int) $w, (int) $h];
        }
    }
    // JPEG: telusuri penanda SOF
    if (substr($bytes, 0, 2) === "\xFF\xD8") {
        $i = 2;
        $panjang = strlen($bytes);
        while ($i + 9 < $panjang) {
            if (ord($bytes[$i]) !== 0xFF) {
                $i++;
                continue;
            }
            $penanda = ord($bytes[$i + 1]);
            if ($penanda >= 0xC0 && $penanda <= 0xCF && $penanda !== 0xC4 && $penanda !== 0xC8 && $penanda !== 0xCC) {
                $h = unpack('n', substr($bytes, $i + 5, 2))[1] ?? 0;
                $w = unpack('n', substr($bytes, $i + 7, 2))[1] ?? 0;
                if ($w > 0 && $h > 0) {
                    return [(int) $w, (int) $h];
                }
            }
            $loncat = unpack('n', substr($bytes, $i + 2, 2))[1] ?? 0;
            if ($loncat < 2) {
                break;
            }
            $i += 2 + $loncat;
        }
    }
    return $cadangan;
}

/** Jenis gambar dari isi berkas -> [ekstensi, content-type]. */
function gambar_jenis(string $bytes): array
{
    if (substr($bytes, 0, 8) === "\x89PNG\r\n\x1a\n") {
        return ['png', 'image/png'];
    }
    if (substr($bytes, 0, 3) === 'GIF') {
        return ['gif', 'image/gif'];
    }
    if (stripos(substr($bytes, 0, 200), '<svg') !== false) {
        return ['svg', 'image/svg+xml'];
    }
    return ['jpeg', 'image/jpeg'];
}

// ------------------------------------------------------------------ logo aplikasi

/** URL logo aplikasi: hasil unggahan Super Admin, atau logo bawaan. */
function logo_aplikasi(): string
{
    $u = trim(app_setting('app_logo'));
    return $u !== '' ? $u : 'assets/logo-cbt.svg';
}

/** Apakah logo aplikasi masih memakai logo bawaan (belum diunggah Super Admin)? */
function logo_aplikasi_bawaan(): bool
{
    return trim(app_setting('app_logo')) === '';
}

// ------------------------------------------------------------------ pilihan data sekolah

function kelas_list(bool $aktifSaja = true): array
{
    if (sekolah_id() <= 0) {
        return [];
    }
    $rows = all('SELECT * FROM kelas WHERE sekolah_id = ? ORDER BY urutan, nama', [sekolah_id()]);
    foreach ($rows as $i => $r) {
        // Ditampilkan rapi (I..XII tanpa kata "Kelas") agar data lama pun langsung benar di layar.
        $rapi = kelas_nama_rapi((string) $r['nama']);
        $rows[$i]['nama'] = $rapi !== '' ? $rapi : (string) $r['nama'];
    }
    return $rows;
}

function mapel_list(): array
{
    if (sekolah_id() <= 0) {
        return [];
    }
    return all('SELECT * FROM mapel WHERE sekolah_id = ? ORDER BY urutan, nama', [sekolah_id()]);
}

function guru_list(bool $aktifSaja = true): array
{
    if (sekolah_id() <= 0) {
        return [];
    }
    $sql = 'SELECT * FROM guru WHERE sekolah_id = ?';
    if ($aktifSaja) {
        $sql .= ' AND aktif = 1';
    }
    return all($sql . ' ORDER BY nama', [sekolah_id()]);
}

function siswa_list(?int $kelasId = null, bool $aktifSaja = true): array
{
    if (sekolah_id() <= 0) {
        return [];
    }
    $sql = 'SELECT sw.*, k.nama AS kelas FROM siswa sw LEFT JOIN kelas k ON k.id = sw.kelas_id
            WHERE sw.sekolah_id = ?';
    $p = [sekolah_id()];
    if ($aktifSaja) {
        $sql .= ' AND sw.aktif = 1';
    }
    if ($kelasId !== null && $kelasId > 0) {
        $sql .= ' AND sw.kelas_id = ?';
        $p[] = $kelasId;
    }
    return all($sql . ' ORDER BY k.urutan, k.nama, sw.nama', $p);
}

function kelas_nama(?int $id): string
{
    if ($id === null || $id <= 0) {
        return '-';
    }
    $n = (string) val('SELECT nama FROM kelas WHERE id = ? AND sekolah_id = ?', [$id, sekolah_id()], '-');
    if ($n === '-') {
        return '-';
    }
    // Ditampilkan dalam bentuk rapi (I..XII tanpa kata "Kelas") supaya data lama pun tampil benar.
    // Nama yang setelah dirapikan menjadi KOSONG (mis. hanya "Kelas") dikembalikan apa adanya
    // supaya tidak ada kelas yang tampil tanpa nama.
    $rapi = kelas_nama_rapi($n);
    return $rapi !== '' ? $rapi : $n;
}

function mapel_nama(?int $id): string
{
    if ($id === null || $id <= 0) {
        return '-';
    }
    return (string) val('SELECT nama FROM mapel WHERE id = ? AND sekolah_id = ?', [$id, sekolah_id()], '-');
}

function mapel_by_id(int $id): ?array
{
    if ($id <= 0 || sekolah_id() <= 0) {
        return null;
    }
    return one('SELECT * FROM mapel WHERE id = ? AND sekolah_id = ?', [$id, sekolah_id()]);
}

function kelas_by_id(int $id): ?array
{
    if ($id <= 0 || sekolah_id() <= 0) {
        return null;
    }
    return one('SELECT * FROM kelas WHERE id = ? AND sekolah_id = ?', [$id, sekolah_id()]);
}

/** Mapel & kelas yang diampu seorang guru. */
function guru_mapel_ids(int $guruId): array
{
    $out = [];
    foreach (all('SELECT mapel_id FROM guru_mapel WHERE guru_id = ?', [$guruId]) as $r) {
        $out[] = (int) $r['mapel_id'];
    }
    return $out;
}

function guru_kelas_ids(int $guruId): array
{
    $out = [];
    foreach (all('SELECT kelas_id FROM guru_kelas WHERE guru_id = ?', [$guruId]) as $r) {
        $out[] = (int) $r['kelas_id'];
    }
    return $out;
}

function guru_mapel_simpan(int $guruId, array $mapelIds): void
{
    q('DELETE FROM guru_mapel WHERE guru_id = ?', [$guruId]);
    foreach (array_unique(array_map('intval', $mapelIds)) as $mid) {
        if ($mid > 0 && mapel_by_id($mid)) {
            q('INSERT OR IGNORE INTO guru_mapel (guru_id, mapel_id) VALUES (?, ?)', [$guruId, $mid]);
        }
    }
}

function guru_kelas_simpan(int $guruId, array $kelasIds): void
{
    q('DELETE FROM guru_kelas WHERE guru_id = ?', [$guruId]);
    foreach (array_unique(array_map('intval', $kelasIds)) as $kid) {
        if ($kid > 0 && kelas_by_id($kid)) {
            q('INSERT OR IGNORE INTO guru_kelas (guru_id, kelas_id) VALUES (?, ?)', [$guruId, $kid]);
        }
    }
}

// ------------------------------------------------------------------ akun

/** Nama pengguna unik se-aplikasi. */
function username_unik(string $dasar, int $kecualiUserId = 0): string
{
    $dasar = strtolower(preg_replace('/[^a-z0-9]+/i', '', $dasar) ?? '');
    if ($dasar === '') {
        $dasar = 'pengguna';
    }
    $dasar = substr($dasar, 0, 24);
    $coba = $dasar;
    $i = 2;
    while (true) {
        $ada = (int) val('SELECT COUNT(*) FROM users WHERE username = ? AND id <> ?', [$coba, $kecualiUserId], 0);
        if ($ada === 0) {
            return $coba;
        }
        $coba = $dasar . $i;
        $i++;
    }
}

/**
 * Nama pengguna UNIK dengan tulisan DIPERTAHANKAN APA ADANYA.
 *
 * Dipakai saat menarik akun dari e-GURU: nama pengguna e-GURU (mis. `rina.m`, `ahmad.fauzi`)
 * harus tetap sama di CBT. `username_unik()` sengaja membersihkan tanda baca sehingga tidak
 * bisa dipakai untuk keperluan ini. Bila namanya sudah dipakai akun lain, ditambah angka
 * (`rina.m2`, `rina.m3`, …) dan hal itu dilaporkan pemanggil.
 */
function username_unik_apa_adanya(string $dasar, int $kecualiUserId = 0): string
{
    $dasar = trim($dasar);
    $dasar = preg_replace('/\s+/', '', $dasar) ?? $dasar;
    $dasar = preg_replace('/[^A-Za-z0-9._@-]+/', '', $dasar) ?? $dasar;
    if ($dasar === '') {
        $dasar = 'pengguna';
    }
    $dasar = substr($dasar, 0, 40);
    $coba = $dasar;
    $i = 2;
    while (true) {
        $ada = (int) val('SELECT COUNT(*) FROM users WHERE username = ? AND id <> ?', [$coba, $kecualiUserId], 0);
        if ($ada === 0) {
            return $coba;
        }
        $coba = $dasar . $i;
        $i++;
        if ($i > 999) {
            return $dasar . bin2hex(random_bytes(2));
        }
    }
}

function username_dari_nama(string $nama): string
{
    $kata = preg_split('/\s+/', trim($nama)) ?: [];
    $gelar = ['h', 'hj', 'drs', 'dra', 'dr', 'prof', 'ir', 'kh', 'ust', 'ustadz', 'ustadzah', 'pak', 'bu', 'ibu', 'bapak', 'sdr', 'sdri', 'moh', 'muh'];
    foreach ($kata as $k) {
        $bersih = strtolower(preg_replace('/[^a-zA-Z]+/', '', $k) ?? '');
        if ($bersih === '' || in_array($bersih, $gelar, true)) {
            continue;
        }
        if (strlen($bersih) >= 3) {
            return $bersih;
        }
    }
    return strtolower(preg_replace('/[^a-z0-9]+/i', '', $nama) ?: 'pengguna');
}

function sandi_acak(int $panjang = 8): string
{
    $abjad = 'abcdefghjkmnpqrstuvwxyz23456789';
    $s = '';
    for ($i = 0; $i < $panjang; $i++) {
        $s .= $abjad[random_int(0, strlen($abjad) - 1)];
    }
    return $s;
}

function nip_bersih($v): string
{
    $s = trim((string) $v);
    if (in_array(bawah($s), ['', '-', '–', '—', '.', '_', 'n/a', 'na', 'none', 'tidak ada', 'belum ada'], true)) {
        return '';
    }
    return $s;
}

// ------------------------------------------------------------------ unggah (media proxy)

function media_upload(string $bytes, string $filename): array
{
    if ($bytes === '') {
        return ['ok' => false, 'pesan' => 'Berkas kosong.'];
    }
    $tokenFile = dirname(__DIR__) . '/.vibecoder-media-token';
    if (!is_file($tokenFile) || !is_readable($tokenFile)) {
        return ['ok' => false, 'pesan' => 'Layanan penyimpanan berkas belum siap.'];
    }
    $token = trim((string) @file_get_contents($tokenFile));
    if ($token === '') {
        return ['ok' => false, 'pesan' => 'Layanan penyimpanan berkas belum siap.'];
    }

    $endpoint = MEDIA_ENDPOINT . '?filename=' . rawurlencode($filename);
    $body = false;
    $code = 0;
    $err = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $bytes,
            CURLOPT_HTTPHEADER     => ['X-App-Media-Token: ' . $token, 'Content-Type: application/octet-stream'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 180,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = (string) curl_error($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "X-App-Media-Token: " . $token . "\r\nContent-Type: application/octet-stream\r\n",
            'content' => $bytes,
            'timeout' => 180,
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($endpoint, false, $ctx);
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $h) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $h, $m)) {
                    $code = (int) $m[1];
                }
            }
        }
        if ($body === false) {
            $err = 'tidak dapat menghubungi layanan unggah';
        }
    }

    if ($body === false || $body === null || $body === '') {
        return ['ok' => false, 'pesan' => 'Gagal mengunggah berkas. ' . trim($err)];
    }
    $json = json_decode((string) $body, true);
    if (is_array($json) && !empty($json['ok']) && !empty($json['url'])) {
        return ['ok' => true, 'url' => (string) $json['url']];
    }
    $pesan = is_array($json) ? (string) ($json['pesan'] ?? $json['error'] ?? '') : '';
    return ['ok' => false, 'pesan' => 'Unggahan ditolak: ' . ($pesan !== '' ? $pesan : 'HTTP ' . $code)];
}

function validasi_foto(string $namaAsli, int $ukuran, string $tmpPath): array
{
    $ext = strtolower((string) pathinfo($namaAsli, PATHINFO_EXTENSION));
    if (!in_array($ext, FOTO_EKSTENSI, true)) {
        return ['ok' => false, 'pesan' => 'Format gambar harus ' . implode(', ', FOTO_EKSTENSI) . '.'];
    }
    if ($ukuran <= 0) {
        return ['ok' => false, 'pesan' => 'Berkas gambar kosong atau gagal diunggah.'];
    }
    if ($ukuran > FOTO_MAKS_BYTE) {
        return ['ok' => false, 'pesan' => 'Ukuran gambar maksimal 15 MB.'];
    }
    if ($ext !== 'svg' && function_exists('getimagesize')) {
        if (@getimagesize($tmpPath) === false) {
            return ['ok' => false, 'pesan' => 'Berkas ini sepertinya bukan gambar yang sah.'];
        }
    }
    return ['ok' => true];
}

// ------------------------------------------------------------------ tanggal impor

/** Membaca tanggal dari berkas impor secara lenient -> Y-m-d (atau ''). */
function tgl_impor($nilai): string
{
    $s = trim((string) $nilai);
    if ($s === '') {
        return '';
    }
    // Angka serial Excel
    if (preg_match('/^\d{5}$/', $s)) {
        $n = (int) $s;
        if ($n > 20000 && $n < 60000) {
            $ts = ($n - 25569) * 86400;
            return gmdate('Y-m-d', (int) $ts);
        }
    }
    if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/', $s, $m)) {
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1])
            ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : '';
    }
    if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/', $s, $m)) {
        return checkdate((int) $m[2], (int) $m[1], (int) $m[3])
            ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : '';
    }
    if (preg_match('/^(\d{8})$/', $s, $m)) {
        $d = substr($s, 0, 2);
        $b = substr($s, 2, 2);
        $y = substr($s, 4, 4);
        return checkdate((int) $b, (int) $d, (int) $y) ? "$y-$b-$d" : '';
    }
    $bulan = ['januari' => 1, 'februari' => 2, 'maret' => 3, 'april' => 4, 'mei' => 5, 'juni' => 6,
        'juli' => 7, 'agustus' => 8, 'september' => 9, 'oktober' => 10, 'november' => 11, 'desember' => 12,
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'jun' => 6, 'jul' => 7, 'agu' => 8, 'ags' => 8,
        'sep' => 9, 'sept' => 9, 'okt' => 10, 'nov' => 11, 'des' => 12];
    if (preg_match('/^(\d{1,2})\s+([A-Za-z]+)\s+(\d{4})$/', $s, $m)) {
        $b = $bulan[bawah($m[2])] ?? 0;
        return ($b > 0 && checkdate($b, (int) $m[1], (int) $m[3]))
            ? sprintf('%04d-%02d-%02d', $m[3], $b, $m[1]) : '';
    }
    return '';
}

/** Judul kolom dinormalkan untuk pencocokan saat impor. */
function kolom_kunci(string $judul): string
{
    $s = bawah(trim($judul));
    return preg_replace('/[^a-z0-9]+/', '', $s) ?? '';
}

/**
 * Tanggal ISO (Y-m-d) untuk berkas EKSPOR.
 * Pengimpor aplikasi lain (mis. e-PERPUS/e-GURU) hanya menerima ISO; teks tanggal Indonesia
 * akan ditolak dan barisnya memakai tanggal hari ini.
 */
function ekspor_tgl($v): string
{
    $v = trim((string) $v);
    if ($v === '') {
        return '';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $v) === 1) {
        return substr($v, 0, 10);
    }
    $t = strtotime($v);
    return $t !== false ? date('Y-m-d', $t) : '';
}

/** Kirim CSV (pemisah ; + BOM agar Excel membaca UTF-8) ke peramban lalu hentikan skrip. */
function csv_kirim(string $namaBerkas, array $kolom, array $baris): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $namaBerkas . '"');
    header('Pragma: no-cache');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $kolom, ';');
    foreach ($baris as $r) {
        fputcsv($out, $r, ';');
    }
    fclose($out);
    exit;
}

/**
 * Id kelas dari NAMA kelas; kelas yang belum ada DIBUAT (dipakai saat impor).
 * Membuat kelas baru di sini penting supaya berkas ekspor e-GURU bisa langsung diimpor
 * tanpa harus menyiapkan daftar kelas lebih dahulu; nama yang dibuat dilaporkan ke pemakai.
 */
function kelas_id_dari_nama(string $nama, array &$baru = []): int
{
    static $peta = null;
    if ($peta === null) {
        $peta = [];
        foreach (kelas_list() as $k) {
            $peta[kolom_kunci((string) $k['nama'])] = (int) $k['id'];
        }
    }
    // Nama kelas disimpan dalam bentuk rapi (I..XII tanpa kata "Kelas").
    $nama = kelas_nama_rapi($nama);
    $kunci = kolom_kunci($nama);
    if ($kunci === '') {
        return 0;
    }
    if (isset($peta[$kunci])) {
        return (int) $peta[$kunci];
    }
    $urut = ((int) val('SELECT COALESCE(MAX(urutan),0) FROM kelas WHERE sekolah_id = ?', [sekolah_id()], 0)) + 10;
    q('INSERT INTO kelas (sekolah_id, nama, tingkat, urutan, dibuat) VALUES (?,?,?,?,?)',
        [sekolah_id(), teks($nama, 60), '', $urut, date('Y-m-d H:i:s')]);
    $id = (int) db()->lastInsertId();
    $peta[$kunci] = $id;
    $baru[teks($nama, 60)] = true;
    return $id;
}

/** Status siswa yang sah (selaras istilah ekspor e-GURU). */
function siswa_status_list(): array
{
    return ['aktif' => 'Aktif', 'lulus' => 'Lulus', 'nonaktif' => 'Nonaktif'];
}

/**
 * Ambil satu sel dari baris impor berdasarkan peta kolom.
 * $peta: kunci internal -> indeks kolom pada berkas.
 */
function impor_ambil(array $row, array $peta, string $key): string
{
    $i = $peta[$key] ?? -1;
    return $i >= 0 && isset($row[$i]) ? trim((string) $row[$i]) : '';
}

/** Terjemahkan tulisan status dari berkas impor -> 'aktif' | 'lulus' | 'nonaktif'. */function siswa_status_dari_teks(string $t): string
{
    $s = bawah(trim($t));
    if (str_starts_with($s, 'lulus') || $s === 'l') {
        return 'lulus';
    }
    if (str_starts_with($s, 'tidak') || str_starts_with($s, 'non') || $s === '0' || str_starts_with($s, 'tidak aktif')) {
        return 'nonaktif';
    }
    if ($s === 'aktif' || $s === '1' || $s === 'a') {
        return 'aktif';
    }
    return $s === '' ? '' : 'aktif';
}

/** Status guru dari berkas impor -> 1 (aktif) | 0 (nonaktif). */
function guru_status_dari_teks(string $t): int
{
    $s = bawah(trim($t));
    if ($s === '') {
        return 1;
    }
    if (str_starts_with($s, 'non') || str_starts_with($s, 'tidak') || $s === '0') {
        return 0;
    }
    return 1;
}

// ------------------------------------------------------------------ NAMA KELAS

/** Angka 1-12 -> angka Romawi (I .. XII). Di luar 1-12 dikembalikan apa adanya. */
function romawi(int $n): string
{
    $peta = [1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V', 6 => 'VI',
        7 => 'VII', 8 => 'VIII', 9 => 'IX', 10 => 'X', 11 => 'XI', 12 => 'XII'];
    return $peta[$n] ?? (string) $n;
}

/**
 * Rapikan nama kelas: buang kata "Kelas" di depan dan ubah angka Arab di awal menjadi
 * angka Romawi (I..XII) — permintaan pemilik: "pastikan kelas hanya tertulis I, II, III, ... XII;
 * jika ada kata Kelas sebelumnya dihapus saja".
 * Contoh: "Kelas X" -> "X" · "kelas 7" -> "VII" · "7A" -> "VII-A" · "7-A" -> "VII-A".
 * Nama yang sudah benar (mis. "VII-A") tidak diubah. Nama yang menjadi kosong dikembalikan apa adanya.
 */
function kelas_nama_rapi(string $nama): string
{
    $s = trim(preg_replace('/\s+/', ' ', $nama) ?? '');
    if ($s === '') {
        return '';
    }
    // 1) buang kata "kelas" (dan tanda hubung/titik sesudahnya) di depan
    $s = preg_replace('/^\s*kelas\b[\s.:\-]*/i', '', $s) ?? $s;
    $s = trim($s);
    // 2) angka Arab di awal -> angka Romawi (hanya 1..12 agar tidak salah ubah nomor lain)
    if (preg_match('/^(\d{1,2})(.*)$/', $s, $m) === 1) {
        $n = (int) $m[1];
        if ($n >= 1 && $n <= 12) {
            $sisa = trim($m[2]);
            if ($sisa !== '' && !preg_match('/^[-.\s]/', $sisa)) {
                $sisa = '-' . $sisa; // "7A" -> "VII-A" supaya konsisten dengan penulisan "VII-A"
            }
            $s = romawi($n) . $sisa;
        }
    }
    return trim(preg_replace('/\s*-\s*/', '-', $s) ?? $s);
}

/** Nama field POST yang berbentuk array dengan aman. */
function pos_arr(string $nama): array
{
    $v = $_POST[$nama] ?? [];
    return is_array($v) ? $v : [];
}
