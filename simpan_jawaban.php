<?php
/**
 * Simpan satu jawaban siswa (dipanggil lewat fetch dari halaman pengerjaan).
 * Selalu membalas JSON.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

header('Content-Type: application/json; charset=utf-8');

function balas(array $data): void
{
    echo json_encode($data);
    exit;
}

$u = current_user();
if (!$u || $u['role'] !== 'siswa') {
    http_response_code(302);
    balas(['ok' => false, 'pesan' => 'Sesi login berakhir.']);
}
if (!csrf_ok()) {
    http_response_code(419);
    balas(['ok' => false, 'pesan' => 'Token keamanan tidak sah.']);
}

$sw = siswa_saya();
$sesiId = (int) ($_POST['sesi'] ?? 0);
$soalId = (int) ($_POST['soal'] ?? 0);
$mentah = (string) ($_POST['jawaban'] ?? '');

$sesi = one('SELECT * FROM sesi_ujian WHERE id = ? AND siswa_id = ?', [$sesiId, (int) ($sw['id'] ?? 0)]);
if (!$sesi) {
    http_response_code(404);
    balas(['ok' => false, 'pesan' => 'Sesi ujian tidak ditemukan.']);
}
if ($sesi['status'] !== 'mengerjakan') {
    balas(['ok' => false, 'pesan' => 'Ujian sudah dikumpulkan.', 'lanjut' => 'selesai.php?sesi=' . $sesiId]);
}

$uj = one('SELECT * FROM ujian WHERE id = ?', [(int) $sesi['ujian_id']]);
$soal = one('SELECT * FROM soal WHERE id = ? AND ujian_id = ?', [$soalId, (int) $sesi['ujian_id']]);
if (!$soal) {
    http_response_code(404);
    balas(['ok' => false, 'pesan' => 'Soal tidak ditemukan pada ujian ini.']);
}

// Batas waktu (waktu server).
if ($uj) {
    $berakhir = strtotime((string) $sesi['mulai']) + max(1, (int) $uj['durasi']) * 60;
    if (time() >= $berakhir) {
        q('UPDATE sesi_ujian SET status = ?, selesai = ? WHERE id = ?', ['selesai', date('Y-m-d H:i:s'), $sesiId]);
        sesi_hitung($sesiId);
        balas(['ok' => false, 'pesan' => 'Waktu ujian sudah habis.', 'lanjut' => 'selesai.php?sesi=' . $sesiId]);
    }
}

$soal = soal_lengkap($soal);
$jawab = null;
if ($mentah !== '' && $mentah !== 'null') {
    $d = json_decode($mentah, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $d = $mentah;
    }
    switch ($soal['jenis']) {
        case 'pg':
            $jawab = is_numeric($d) ? (int) $d : null;
            break;
        case 'pgk':
            $jawab = is_array($d) ? array_values(array_map('intval', $d)) : null;
            break;
        case 'bs':
            $jawab = is_array($d) ? array_values(array_map(fn($x) => strtoupper(substr((string) $x, 0, 1)), $d)) : null;
            break;
        case 'jodoh':
            $jawab = is_array($d) ? array_values(array_map(fn($x) => ($x === '' ? '' : (int) $x), $d)) : null;
            break;
        default:
            $jawab = is_array($d) ? implode(' ', array_map('strval', $d)) : (string) $d;
            $jawab = trim($jawab);
            if ($jawab === '') {
                $jawab = null;
            }
    }
}

if ($jawab === null || (is_array($jawab) && !array_filter($jawab, fn($x) => $x !== '' && $x !== null))) {
    q('DELETE FROM jawaban WHERE sesi_id = ? AND soal_id = ?', [$sesiId, $soalId]);
} else {
    jawaban_simpan($sesiId, $soalId, $jawab);
}
sesi_ringkas($sesiId);

$total = (int) val('SELECT COUNT(*) FROM soal WHERE ujian_id = ?', [(int) $sesi['ujian_id']], 0);
$terjawab = (int) val('SELECT COUNT(*) FROM jawaban WHERE sesi_id = ? AND jawaban IS NOT NULL AND jawaban <> ""', [$sesiId], 0);

balas([
    'ok' => true,
    'terjawab' => $terjawab,
    'total' => $total,
    'status' => $jawab === null ? 'kosong' : 'tersimpan',
]);
