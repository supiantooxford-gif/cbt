<?php
/**
 * UNDUH BANK SOAL dalam bentuk WORD (.docx) — lengkap dengan kunci jawaban dan gambar soal.
 * Berkas dibuat di sisi server memakai modul `inc/docx.php` (tanpa pustaka luar).
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';
require_once __DIR__ . '/inc/docx.php';
require_once __DIR__ . '/inc/bank_soal.php';

$u = require_sekolah();
if (!is_guru() && !is_operator() && !is_super()) {
    tolak403('Halaman ini untuk akun Guru atau pengelola sekolah.');
}

$f = bank_saring();
$soal = bank_soal_list($f);
$guruNama = trim((string) (guru_saya()['nama'] ?? ($u['nama'] ?? '')));
$sekolah = (string) setting('nama');

$blok = [];
$blok[] = ['teks' => 'BANK SOAL', 'tebal' => true, 'tengah' => true, 'jarak' => 0];
if ($sekolah !== '') {
    $blok[] = ['teks' => $sekolah, 'tebal' => true, 'tengah' => true, 'jarak' => 0];
}
$barisInfo = ['Tahun Ajaran ' . setting('tahun_ajaran', '-') . ' · Semester ' . setting('semester', '-')];
$label = bank_label_saring($f);
if ($label) {
    $barisInfo[] = implode(' · ', $label);
}
$blok[] = ['teks' => implode("\n", $barisInfo), 'tengah' => true, 'kecil' => true, 'jarak' => 120];
$blok[] = ['teks' => 'Jumlah soal: ' . count($soal) . ' · Dicetak ' . tanggal_id(date('Y-m-d'))
    . ($guruNama !== '' ? ' · Penyusun: ' . $guruNama : ''), 'kecil' => true, 'jarak' => 200];

if (!$soal) {
    $blok[] = ['teks' => 'Tidak ada soal pada saringan yang dipilih.', 'miring' => true];
}

$ringkasKunci = [];
foreach ($soal as $i => $s) {
    $nomor = $i + 1;
    $blok[] = ['teks' => $nomor . '. [' . jenis_soal_label($s['jenis']) . ' · skor ' . r1($s['skor']) . ']'
        . '  ' . trim((string) ($s['mapel'] ?: '')) . ($s['guru'] ? ' — ' . $s['guru'] : ''),
        'tebal' => true, 'kecil' => true, 'jarak' => 40];
    $blok[] = ['teks' => (string) $s['pertanyaan'], 'jarak' => 60];

    // Gambar soal (disisipkan bila berkasnya dapat diambil)
    $gambar = soal_gambar($s);
    if ($gambar !== '') {
        $bytes = unduh_url($gambar);
        // Ambang kecil saja: cukup memastikan berkasnya benar-benar berisi data gambar
        // (jangan menolak gambar mungil — pernah terjadi karena ambang 100 byte).
        if ($bytes !== null && strlen($bytes) > 8) {
            [$ext] = gambar_jenis($bytes);
            $blok[] = ['gambar' => [$bytes, $ext, 10.5]];
        } else {
            $blok[] = ['teks' => '(gambar soal tersedia pada aplikasi: ' . $gambar . ')', 'miring' => true, 'kecil' => true];
        }
    }

    // Pilihan jawaban
    if ($s['opsi_arr']) {
        foreach ($s['opsi_arr'] as $oi => $teks) {
            $huruf = huruf_opsi($oi);
            $tanda = '';
            if ($s['jenis'] === 'pg') {
                $tanda = ((int) $s['kunci'] === $oi) ? '  <-- kunci' : '';
            } elseif ($s['jenis'] === 'pgk') {
                $tanda = (is_array($s['kunci']) && in_array($oi, array_map('intval', $s['kunci']), true)) ? '  <-- kunci' : '';
            }
            if ($s['jenis'] === 'bs') {
                $blok[] = ['teks' => $huruf . '. ' . (string) $teks . '   ( ' . ((is_array($s['kunci']) && atas((string) ($s['kunci'][$oi] ?? '')) === 'B') ? 'B' : 'S') . ' )',
                    'indent' => 360, 'jarak' => 20];
                continue;
            }
            if ($s['jenis'] === 'jodoh') {
                $blok[] = ['teks' => $huruf . '. ' . (string) $teks, 'indent' => 360, 'jarak' => 20];
                continue;
            }
            $blok[] = ['teks' => $huruf . '. ' . (string) $teks . $tanda, 'indent' => 360, 'jarak' => 20];
        }
        if ($s['jenis'] === 'jodoh') {
            $bagian = [];
            foreach ($s['opsi_arr'] as $oi => $teks) {
                $bagian[] = huruf_opsi($oi) . '->' . (string) (is_array($s['kunci']) ? ($s['kunci'][$oi] ?? '-') : '-');
            }
            $blok[] = ['teks' => 'Pasangan: ' . implode('  ', $bagian), 'indent' => 360, 'kecil' => true, 'jarak' => 40];
        }
    }

    $blok[] = ['teks' => 'Kunci Jawaban: ' . bank_kunci_teks($s), 'tebal' => true, 'indent' => 240, 'jarak' => 60];
    if (trim((string) $s['pembahasan']) !== '') {
        $blok[] = ['teks' => 'Pembahasan: ' . (string) $s['pembahasan'], 'miring' => true, 'kecil' => true, 'indent' => 240, 'jarak' => 120];
    } else {
        $blok[] = ['teks' => '', 'jarak' => 80];
    }
    $ringkasKunci[] = [$nomor, bank_kunci_teks($s)];
}

// Tabel ringkasan kunci jawaban di akhir dokumen (permintaan: "dilengkapi dengan kunci jawaban")
if ($ringkasKunci) {
    $blok[] = ['teks' => 'RINGKASAN KUNCI JAWABAN', 'tebal' => true, 'tengah' => true, 'jarak' => 120];
    $barisTabel = [];
    foreach ($ringkasKunci as $rk) {
        $barisTabel[] = [(string) $rk[0], (string) $rk[1]];
    }
    $blok[] = ['tabel' => [['No', 'Kunci Jawaban'], $barisTabel]];
}

$docx = docx_bangun($blok);
$slug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $sekolah) ?? '', '-'));
docx_kirim('bank-soal' . ($slug !== '' ? '-' . $slug : '') . '-' . date('Y-m-d') . '.docx', $docx);
