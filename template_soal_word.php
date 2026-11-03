<?php
/**
 * Unduh TEMPLATE SOAL dalam bentuk Word (.docx) — BENTUK TEKS SEDERHANA (v1.13.0).
 *
 * Permintaan pemilik: *"Template soal yang ada saat ini masih agak sulit. Buat template soal seperti
 * yang saya upload agar mempermudah guru dalam mengisi template soal."*
 *
 * Template LAMA memakai TABEL berkolom (Jenis | Pertanyaan | Opsi A..E | Jawaban | Skor | Pembahasan)
 * sehingga guru harus menebak isi kolom "Jawaban" untuk tiap jenis soal. Template BARU hanya tulisan
 * biasa seperti contoh yang dikirim pemilik: judul bagian ("Template Soal Pilihan Ganda", …), soal
 * bernomor, pilihan A./B./C., lalu baris "Kunci: …".
 *
 * Isi template diambil dari `soal_template_blok()` (`inc/impor_soal.php`) sehingga contoh yang
 * diunduh guru, pratinjau pada halaman impor, dan bahan uji SELALU sama.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';
require_once __DIR__ . '/inc/docx.php';
require_once __DIR__ . '/inc/impor_soal.php';

require_sekolah();
if (!is_guru() && !is_operator() && !is_super()) {
    tolak403('Halaman ini untuk akun Guru atau pengelola sekolah.');
}

$ujianId = (int) ($_GET['ujian'] ?? 0);
$uj = $ujianId > 0 ? ujian_by_id($ujianId) : null;

// Identitas pada berkas (membantu guru memastikan template untuk ujian yang benar).
$kepala = [];
$kepala[] = ['teks' => 'Template Penulisan Soal — ' . APP_NAME, 'tebal' => true, 'tengah' => true];
if ($uj) {
    $kepala[] = ['teks' => 'Ujian: ' . (string) $uj['judul'], 'tengah' => true];
    $kepala[] = ['teks' => 'Mata Pelajaran: ' . mapel_nama((int) ($uj['mapel_id'] ?? 0))
        . ' · Tahun Ajaran ' . setting('tahun_ajaran', '-') . ' · Semester ' . setting('semester', '-'),
        'tengah' => true, 'kecil' => true];
} else {
    $kepala[] = ['teks' => (string) setting('nama') . ' · Tahun Ajaran ' . setting('tahun_ajaran', '-')
        . ' · Semester ' . setting('semester', '-'), 'tengah' => true, 'kecil' => true];
}
$kepala[] = ['teks' => ''];

$blok = array_merge($kepala, soal_template_blok());
// Paragraf kosong pada isi template dibuat berjarak kecil agar tidak terlalu renggang.
foreach ($blok as $i => $b) {
    if (trim((string) $b['teks']) === '') {
        $blok[$i]['jarak'] = 30;
    }
}

$docx = docx_bangun($blok);
$nama = 'template-soal-' . date('Y-m-d') . '.docx';
docx_kirim($nama, $docx);
