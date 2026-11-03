<?php
/**
 * Catat pelanggaran siswa selama ujian (pindah tab / keluar layar penuh).
 *
 * Penegakan batas dilakukan di SERVER: begitu jumlah pelanggaran mencapai
 * UJIAN_MAKS_PELANGGARAN, jawaban siswa dikumpulkan otomatis dan ujiannya berakhir.
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
$sebab = teks($_POST['sebab'] ?? 'Tidak diketahui', 200);
$batas = (int) UJIAN_MAKS_PELANGGARAN;

$sesi = one('SELECT * FROM sesi_ujian WHERE id = ? AND siswa_id = ?', [$sesiId, (int) ($sw['id'] ?? 0)]);
if (!$sesi) {
    http_response_code(404);
    balas(['ok' => false, 'pesan' => 'Sesi ujian tidak ditemukan.']);
}
if ($sesi['status'] !== 'mengerjakan') {
    balas(['ok' => true, 'pelanggaran' => (int) $sesi['pelanggaran'], 'batas' => $batas,
        'kumpulkan' => true, 'lanjut' => 'selesai.php?sesi=' . $sesiId]);
}

$jumlah = (int) $sesi['pelanggaran'] + 1;
q('UPDATE sesi_ujian SET pelanggaran = ? WHERE id = ?', [$jumlah, $sesiId]);

// Catat juga sebagai keterangan pada sesi (jejak pengawasan).
q('UPDATE sesi_ujian SET peramban = ? WHERE id = ?',
    [substr(teks((string) $sesi['peramban'] . ' | pelanggaran: ' . $sebab, 250), 0, 250), $sesiId]);

$kumpulkan = $jumlah >= $batas;
if ($kumpulkan) {
    q('UPDATE sesi_ujian SET status = ?, selesai = ? WHERE id = ? AND status = ?',
        ['dikumpulkan', date('Y-m-d H:i:s'), $sesiId, 'mengerjakan']);
    sesi_hitung($sesiId);
}

balas([
    'ok' => true,
    'pelanggaran' => $jumlah,
    'batas' => $batas,
    'kumpulkan' => $kumpulkan,
    'lanjut' => $kumpulkan ? 'selesai.php?sesi=' . $sesiId : null,
]);
