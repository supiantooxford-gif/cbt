<?php
/**
 * Unggah / hapus logo sekolah (lewat media proxy platform — hanya URL CDN yang disimpan).
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

require_operator();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_ok()) {
    http_response_code(419);
    exit('Token keamanan tidak sah (419).');
}

$aksi = teks($_POST['aksi'] ?? '', 20);

if ($aksi === 'hapus') {
    q('UPDATE sekolah SET logo = NULL WHERE id = ?', [sekolah_id()]);
    current_sekolah(true);
    flash('Logo sekolah dihapus.', 'ok');
    redirect('pengaturan.php#cardLogo');
}

if ($aksi !== 'unggah') {
    flash('Aksi tidak dikenal.', 'err');
    redirect('pengaturan.php');
}

if (empty($_FILES['logo']) || !is_array($_FILES['logo'])) {
    flash('Tidak ada berkas yang dikirim.', 'err');
    redirect('pengaturan.php#cardLogo');
}

$f = $_FILES['logo'];
if ((int) $f['error'] !== UPLOAD_ERR_OK) {
    flash('Berkas gagal diunggah (kode ' . (int) $f['error'] . ').', 'err');
    redirect('pengaturan.php#cardLogo');
}

$v = validasi_foto((string) $f['name'], (int) $f['size'], (string) $f['tmp_name']);
if (!$v['ok']) {
    flash($v['pesan'], 'err');
    redirect('pengaturan.php#cardLogo');
}

$bytes = (string) @file_get_contents((string) $f['tmp_name']);
$up = media_upload($bytes, (string) $f['name']);
if (!$up['ok']) {
    flash($up['pesan'], 'err');
    redirect('pengaturan.php#cardLogo');
}

q('UPDATE sekolah SET logo = ? WHERE id = ?', [$up['url'], sekolah_id()]);
current_sekolah(true);
flash('Logo sekolah berhasil diunggah dan dipakai pada kop dokumen cetak.', 'ok');
redirect('pengaturan.php#cardLogo');
