<?php
/**
 * Unggah / kembalikan LOGO APLIKASI (khusus Super Admin).
 * Berkas diteruskan ke media proxy platform dari SISI SERVER; yang disimpan hanya URL hasilnya.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

require_super();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_ok()) {
    http_response_code(419);
    exit('Token keamanan tidak sah (419).');
}

$aksi = teks($_POST['aksi'] ?? '', 20);

if ($aksi === 'hapus') {
    app_setting_set('app_logo', '');
    flash('Logo aplikasi dikembalikan ke logo bawaan.', 'ok');
    redirect('logo.php');
}

if ($aksi !== 'unggah') {
    flash('Aksi tidak dikenal.', 'err');
    redirect('logo.php');
}

if (empty($_FILES['logo']) || !is_array($_FILES['logo'])) {
    flash('Tidak ada berkas yang dikirim.', 'err');
    redirect('logo.php');
}
$f = $_FILES['logo'];
if ((int) $f['error'] !== UPLOAD_ERR_OK) {
    flash('Berkas gagal diunggah (kode ' . (int) $f['error'] . ').', 'err');
    redirect('logo.php');
}
$v = validasi_foto((string) $f['name'], (int) $f['size'], (string) $f['tmp_name']);
if (!$v['ok']) {
    flash($v['pesan'], 'err');
    redirect('logo.php');
}

$bytes = (string) @file_get_contents((string) $f['tmp_name']);
$up = media_upload($bytes, 'logo-aplikasi-' . (string) $f['name']);
if (!$up['ok']) {
    flash($up['pesan'], 'err');
    redirect('logo.php');
}

app_setting_set('app_logo', $up['url']);
flash('Logo aplikasi berhasil diunggah dan langsung dipakai pada halaman masuk.', 'ok');
redirect('logo.php');
