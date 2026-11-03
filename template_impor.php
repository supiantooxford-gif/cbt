<?php
/**
 * Unduh contoh format impor (CSV) untuk data guru, data siswa, dan soal ujian.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

$u = require_login();
if (!(is_operator() || is_super() || is_guru())) {
    tolak403('Halaman ini untuk akun pengelola atau guru.');
}

$jenis = teks($_GET['jenis'] ?? 'guru', 20);

$data = [
    // Susunan kolom guru & siswa SENGAJA SAMA dengan ekspor aplikasi e-GURU supaya berkas
    // ekspor e-GURU bisa langsung diimpor (permintaan pemilik).
    'guru' => [
        'judul' => ['Nama', 'Jenis Anggota', 'NIP/NUPTK', 'Jenis Kelamin', 'Jabatan', 'Mata Pelajaran',
            'Kelas Diajar', 'Tugas Tambahan', 'Telepon', 'Email', 'Akun (Nama Pengguna)', 'Status', 'Tanggal Daftar'],
        'contoh' => [
            ['Ahmad Fauzi, S.Pd', 'Guru', '198501012010011001', 'L', 'Guru Mata Pelajaran', 'Matematika',
                'VII-A, VII-B', '', '081234567890', 'ahmad@example.com', 'ahmad', 'Aktif', '2025-07-14'],
            ['Siti Aminah, S.Pd', 'Guru', '', 'P', 'Guru Mata Pelajaran', 'Bahasa Indonesia, Bahasa Inggris',
                'VII-A', '', '', '', '', 'Aktif', '2025-07-14'],
        ],
    ],
    'siswa' => [
        'judul' => ['Nama', 'Jenis Anggota', 'NIS', 'NISN', 'Jenis Kelamin', 'Kelas', 'Tanggal Daftar',
            'Tanggal Lahir', 'Nama Wali', 'Telepon', 'Alamat', 'Status Siswa',
            'Tempat Lahir', 'Nama Ayah', 'Nama Ibu', 'Akun (Nama Pengguna)'],
        'contoh' => [
            ['Muhammad Rizki', 'Siswa', '2025001', '0123456789', 'L', 'VII-A', '2025-07-14', '2011-04-12',
                'Abdul Rahim', '081234567890', 'Dusun Gandaria', 'Aktif', 'Mootilango', 'Abdul Rahim', 'Halimah', ''],
            ['Nur Aisyah', 'Siswa', '2025002', '0123456790', 'P', 'VII-A', '2025-07-14', '2011-05-12',
                'Halimah', '', '', 'Aktif', 'Sidomukti', 'Rahmat', 'Halimah', 'aisyah'],
        ],
    ],
    'soal' => [
        'judul' => ['Jenis', 'Pertanyaan', 'Opsi A', 'Opsi B', 'Opsi C', 'Opsi D', 'Opsi E', 'Jawaban', 'Skor', 'Pembahasan'],
        'contoh' => [
            ['Pilihan Ganda', 'Ibu kota Provinsi Gorontalo adalah...', 'Gorontalo', 'Kwandang', 'Limboto', 'Marisa', '', 'A', '1', 'Ibu kota Provinsi Gorontalo adalah Kota Gorontalo.'],
            ['Pilihan Ganda Kompleks', 'Manakah yang termasuk bilangan prima?', '2', '3', '4', '9', '', 'A,B', '2', '2 dan 3 adalah bilangan prima.'],
            ['Benar Salah', 'Matahari terbit dari arah timur.|Air membeku pada suhu 100 derajat Celsius.', '', '', '', '', '', 'B,S', '2', 'Air membeku pada 0 derajat Celsius.'],
            ['Menjodohkan', 'Jodohkan negara dengan ibu kotanya.', 'Indonesia', 'Malaysia', 'Jepang', '', '', 'Jakarta|Kuala Lumpur|Tokyo', '3', ''],
            ['Isian Singkat', 'Hasil dari 12 x 8 adalah...', '', '', '', '', '', '96|sembilan puluh enam', '2', ''],
            ['Uraian', 'Jelaskan proses fotosintesis pada tumbuhan.', '', '', '', '', '', 'fotosintesis|klorofil/hijau daun|cahaya matahari|karbondioksida', '5', 'Sebutkan kata kunci utama.'],
        ],
    ],
];

if (!isset($data[$jenis])) {
    flash('Jenis contoh format tidak dikenal.', 'err');
    redirect('guru.php');
}

$berkas = $data[$jenis];
$namaBerkas = 'contoh-format-' . $jenis . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $namaBerkas . '"');
header('Pragma: no-cache');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM agar Excel membaca UTF-8
fputcsv($out, $berkas['judul'], ';');
foreach ($berkas['contoh'] as $row) {
    fputcsv($out, $row, ';');
}
fclose($out);
exit;
