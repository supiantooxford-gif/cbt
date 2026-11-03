<?php
/**
 * Handler unggah/hapus GAMBAR SOAL (soal bergambar) — akun guru.
 *
 * Berkas diteruskan ke media proxy platform dari SISI SERVER; yang disimpan ke basis data
 * hanya URL CDN hasil respons. Token unggah tidak pernah dikirim ke peramban.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

$u = require_sekolah();
if (!is_guru() && !is_operator() && !is_super()) {
    tolak403('Halaman ini untuk akun Guru atau pengelola sekolah.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_ok()) {
    http_response_code(419);
    exit('Token keamanan tidak sah (419).');
}

$ujianId = (int) (($_POST['ujian'] ?? $_GET['ujian'] ?? 0));
$soalId = (int) (($_POST['soal'] ?? $_GET['soal'] ?? 0));
$aksi = teks($_POST['aksi'] ?? 'unggah', 20);

$uj = ujian_by_id($ujianId);
$soal = $soalId > 0 ? one('SELECT * FROM soal WHERE id = ? AND ujian_id = ? AND sekolah_id = ?', [$soalId, $ujianId, sekolah_id()]) : null;
if (!$uj || !$soal) {
    http_response_code(404);
    flash('Soal tidak ditemukan.', 'err');
    redirect('ujian.php');
}
if (is_guru() && (int) $uj['guru_id'] !== (int) (guru_saya()['id'] ?? 0)) {
    tolak403('Soal ini bukan milik Anda.');
}

$kembali = 'soal_edit.php?ujian=' . $ujianId . '&id=' . $soalId;

if ($aksi === 'hapus') {
    q('UPDATE soal SET gambar = NULL WHERE id = ? AND ujian_id = ?', [$soalId, $ujianId]);
    flash('Gambar soal dihapus.', 'warn');
    redirect($kembali);
}

if (empty($_FILES['gambar']) || !is_array($_FILES['gambar'])) {
    flash('Tidak ada berkas gambar yang dikirim.', 'err');
    redirect($kembali);
}
$f = $_FILES['gambar'];
if ((int) $f['error'] !== UPLOAD_ERR_OK) {
    flash('Berkas gagal diunggah (kode ' . (int) $f['error'] . ').', 'err');
    redirect($kembali);
}
$v = validasi_foto((string) $f['name'], (int) $f['size'], (string) $f['tmp_name']);
if (!$v['ok']) {
    flash($v['pesan'], 'err');
    redirect($kembali);
}
$bytes = (string) @file_get_contents((string) $f['tmp_name']);
$up = media_upload($bytes, 'soal-' . $soalId . '-' . (string) $f['name']);
if (!$up['ok']) {
    flash($up['pesan'], 'err');
    redirect($kembali);
}
q('UPDATE soal SET gambar = ? WHERE id = ? AND ujian_id = ?', [$up['url'], $soalId, $ujianId]);
flash('Gambar soal tersimpan.', 'ok');
redirect($kembali);
