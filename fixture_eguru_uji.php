<?php
/**
 * Fixture UJI untuk fitur "Tarik Data dari e-GURU": membuat basis data KECIL yang meniru
 * sebagian skema aplikasi e-GURU (tabel sekolah, kelas, mapel, guru, siswa, guru_mapel,
 * guru_kelas) supaya penarikan data dapat diuji tanpa menyentuh data e-GURU yang sebenarnya.
 *
 * HANYA untuk berkas di /tmp. Jalankan:
 *   EGURU_DB_FILE=/tmp/xxx/eguru_uji.sqlite php fixture_eguru_uji.php
 */
declare(strict_types=1);

$berkas = (string) getenv('EGURU_DB_FILE');
if ($berkas === '' || !str_contains($berkas, '/tmp/')) {
    fwrite(STDERR, "Ditolak: jalankan dengan EGURU_DB_FILE yang menunjuk berkas di /tmp.\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . $berkas, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = OFF');
$pdo->exec('PRAGMA journal_mode = DELETE');

foreach ([
    'CREATE TABLE IF NOT EXISTS sekolah (id INTEGER PRIMARY KEY, nama TEXT, jenis TEXT, aktif INTEGER DEFAULT 1, verifikasi TEXT DEFAULT "disetujui",
        npsn TEXT, alamat TEXT, desa TEXT, kecamatan TEXT, kabupaten TEXT, provinsi TEXT, telepon TEXT, email TEXT, website TEXT,
        kepala_sekolah TEXT, nip_kepala_sekolah TEXT, ta_aktif_id INTEGER, semester_aktif_id INTEGER)',
    'CREATE TABLE IF NOT EXISTS tahun_ajaran (id INTEGER PRIMARY KEY, sekolah_id INTEGER, nama TEXT, tanggal_mulai TEXT, tanggal_selesai TEXT, keterangan TEXT, aktif INTEGER DEFAULT 1)',
    'CREATE TABLE IF NOT EXISTS semester (id INTEGER PRIMARY KEY, ta_id INTEGER, nama TEXT, urutan INTEGER, aktif INTEGER DEFAULT 1)',
    'CREATE TABLE IF NOT EXISTS guru (id INTEGER PRIMARY KEY, nama TEXT, nip TEXT, nuptk TEXT, jenis_kelamin TEXT, jabatan TEXT, telepon TEXT, email TEXT, aktif INTEGER DEFAULT 1, dibuat TEXT, sekolah_id INTEGER)',
    'CREATE TABLE IF NOT EXISTS kelas (id INTEGER PRIMARY KEY, sekolah_id INTEGER, nama TEXT, tingkat TEXT, wali_id INTEGER, keterangan TEXT, urutan INTEGER, aktif INTEGER DEFAULT 1)',
    'CREATE TABLE IF NOT EXISTS mapel (id INTEGER PRIMARY KEY, kode TEXT, nama TEXT, kelompok TEXT, urutan INTEGER, aktif INTEGER DEFAULT 1, sekolah_id INTEGER)',
    'CREATE TABLE IF NOT EXISTS siswa (id INTEGER PRIMARY KEY, nis TEXT, nisn TEXT, nama TEXT, jenis_kelamin TEXT, kelas_id INTEGER, tanggal_lahir TEXT, nama_wali TEXT, telepon TEXT, alamat TEXT, aktif INTEGER DEFAULT 1, dibuat TEXT, sekolah_id INTEGER, status TEXT, tanggal_status TEXT, foto TEXT, tempat_lahir TEXT, nama_ayah TEXT, nama_ibu TEXT)',
    'CREATE TABLE IF NOT EXISTS guru_mapel (guru_id INTEGER, mapel_id INTEGER, PRIMARY KEY (guru_id, mapel_id))',
    'CREATE TABLE IF NOT EXISTS guru_kelas (guru_id INTEGER, kelas_id INTEGER, PRIMARY KEY (guru_id, kelas_id))',
    // v1.14.0: akun guru e-GURU dipakai APA ADANYA di CBT (nama pengguna & kata sandi sama),
    // jadi tabel `users` e-GURU ikut dibuat pada fixture.
    'CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, username TEXT NOT NULL UNIQUE, password_hash TEXT NOT NULL,
        nama TEXT NOT NULL, role TEXT NOT NULL DEFAULT "guru", guru_id INTEGER, siswa_id INTEGER,
        aktif INTEGER DEFAULT 1, sekolah_id INTEGER, dibuat TEXT)',
] as $sql) {
    $pdo->exec($sql);
}
foreach (['sekolah', 'guru', 'kelas', 'mapel', 'siswa', 'guru_mapel', 'guru_kelas', 'tahun_ajaran', 'semester', 'users'] as $t) {
    $pdo->exec("DELETE FROM $t");
}

$now = date('Y-m-d H:i:s');

// Tahun ajaran & semester aktif (dipakai penarikan LEMBAGA dari halaman Tambah Sekolah).
$pdo->exec("INSERT INTO tahun_ajaran (id, sekolah_id, nama, tanggal_mulai, tanggal_selesai, keterangan, aktif) VALUES
    (1, 1, '2026/2027', '2026-07-01', '2027-06-30', 'Tahun ajaran bawaan aplikasi', 1),
    (2, 2, '2026/2027', '2026-07-01', '2027-06-30', 'Tahun ajaran bawaan aplikasi', 1),
    (3, 3, '2026/2027', '2026-07-01', '2027-06-30', 'Tahun ajaran bawaan aplikasi', 1)");
$pdo->exec("INSERT INTO semester (id, ta_id, nama, urutan, aktif) VALUES
    (1, 1, 'Ganjil', 1, 1), (2, 1, 'Genap', 2, 1),
    (3, 2, 'Ganjil', 1, 1), (4, 3, 'Ganjil', 1, 1)");

// CATATAN: nama lembaga pertama sengaja ditulis BEDA PENULISANNYA dengan nama pada CBT
// ("MA Uji CBT") supaya pencocokan memakai nama yang dinormalkan benar-benar teruji.
$pdo->exec("INSERT INTO sekolah (id, nama, jenis, aktif, verifikasi, npsn, alamat, desa, kecamatan,
        kabupaten, provinsi, telepon, email, website, kepala_sekolah, nip_kepala_sekolah,
        ta_aktif_id, semester_aktif_id) VALUES
    (1, 'MA. Uji CBT', 'madrasah', 1, 'disetujui', '111222', 'Jl. Uji No. 1', 'Sidomukti', 'Mootilango',
        'Gorontalo', 'Gorontalo', '085200000001', 'uji@example.com', 'ma-uji.sch.id',
        'Kepala Uji CBT', '198001012000031001', 1, 1),
    (2, 'MTsS Lain Yang Tak Terpakai', 'umum', 1, 'disetujui', '222333', 'Jl. Uji No. 2', 'Gandaria', 'Mootilango',
        'Gorontalo', 'Gorontalo', '085200000002', 'lain@example.com', 'lain.sch.id',
        'Kepala Lembaga Lain', '197001012000031001', 2, 3),
    (3, 'MA Lembaga Uji Tanpa Isi', 'madrasah', 1, 'disetujui', '333444', 'Jl. Uji No. 3', '', '',
        '', '', '', '', '', '', '', 3, 4),
    (4, 'MA Formulir Saran Uji', 'madrasah', 1, 'disetujui', '444555', 'Jl. Uji No. 4', 'Desa Uji', 'Mootilango',
        'Gorontalo', 'Gorontalo', '085200000004', 'formulir@example.com', 'formulir.sch.id',
        'Kepala Formulir Uji', '', 3, 4)");

$pdo->exec("INSERT INTO kelas (id, sekolah_id, nama, tingkat, wali_id, urutan, aktif) VALUES
    (11, 1, 'Kelas X', 'X', 21, 1, 1),
    (12, 1, 'Kelas XI', 'XI', 22, 2, 1),
    (13, 1, 'Kelas XII', 'XII', 0, 3, 1),
    (21, 2, 'Kelas VII', 'VII', 0, 1, 1)");

$pdo->exec("INSERT INTO mapel (id, kode, nama, urutan, aktif, sekolah_id) VALUES
    (31, 'MTK', 'Matematika', 1, 1, 1),
    (32, 'BIND', 'Bahasa Indonesia', 2, 1, 1),
    (33, 'BIO', 'Biologi', 3, 1, 1),
    (34, 'FIS', 'Fisika', 4, 1, 2)");

$pdo->exec("INSERT INTO kelas (id, sekolah_id, nama, tingkat, wali_id, urutan, aktif) VALUES
    (31, 4, 'Kelas X', 'X', 0, 1, 1)");

$pdo->exec("INSERT INTO guru (id, nama, nip, nuptk, jenis_kelamin, jabatan, telepon, email, aktif, dibuat, sekolah_id) VALUES
    (21, 'Ahmad Fauzi, S.Pd', 'G-001', '112233', 'L', 'Guru Mata Pelajaran', '081200000021', 'ahmad@example.com', 1, '$now', 1),
    (22, 'Yusup Abaidata, SH', '197505122005011002', '', 'L', 'Kepala Madrasah', '081234567001', 'yusup@example.com', 1, '$now', 1),
    (23, 'Rina Marlina, S.Pd', '', '998877', 'P', 'Guru Mata Pelajaran', '', 'rina@example.com', 1, '$now', 1),
    (24, 'Guru Nonaktif', '199001012015031003', '', 'L', 'Guru Mata Pelajaran', '', '', 0, '$now', 1),
    (31, 'Guru Sekolah Lain', '999', '', 'L', 'Guru', '', '', 1, '$now', 2)");

$pdo->exec("INSERT INTO guru_mapel (guru_id, mapel_id) VALUES
    (21, 31), (22, 32), (23, 33), (24, 31), (31, 34)");
$pdo->exec("INSERT INTO guru_kelas (guru_id, kelas_id) VALUES
    (21, 11), (21, 12), (22, 12), (23, 12), (31, 21)");

// Akun guru pada e-GURU — nama pengguna & KATA SANDI inilah yang harus dipakai di CBT.
// Guru 23 (Rina) sengaja BERBEDA nama penggunanya dari pola "kata awal nama".
$hash = fn(string $sandi): string => password_hash($sandi, PASSWORD_DEFAULT);
$ins = $pdo->prepare('INSERT INTO users (id, username, password_hash, nama, role, guru_id, aktif, sekolah_id, dibuat)
                      VALUES (?,?,?,?,?,?,?,?,?)');
$ins->execute([61, 'ahmad', $hash('RahasiaAhmad1'), 'Ahmad Fauzi, S.Pd', 'guru', 21, 1, 1, $now]);
$ins->execute([62, 'yusup.abaidata', $hash('SandiYusup22'), 'Yusup Abaidata, SH', 'guru', 22, 1, 1, $now]);
$ins->execute([63, 'rina.m', $hash('SandiRina333'), 'Rina Marlina, S.Pd', 'guru', 23, 1, 1, $now]);
// Akun NONAKTIF pada e-GURU (guru 24 nonaktif) — tetap disalin apa adanya.
$ins->execute([64, 'guru.nonaktif', $hash('SandiNonaktif4'), 'Guru Nonaktif', 'guru', 24, 0, 1, $now]);
// Akun kepala madrasah (role kepala) — BUKAN akun guru, tidak boleh dipakai untuk akun guru.
$ins->execute([65, 'kepala.uji', $hash('SandiKepala55'), 'Kepala Uji CBT', 'kepala', null, 1, 1, $now]);
// Guru 31 (sekolah lain) TIDAK punya akun e-GURU → akun CBT memakai sandi awal guru123.

$pdo->exec("INSERT INTO siswa (id, nis, nisn, nama, jenis_kelamin, kelas_id, tanggal_lahir, nama_wali, telepon, alamat, aktif, dibuat, sekolah_id, status, tempat_lahir, nama_ayah, nama_ibu) VALUES
    (41, 'S-001', '00S-001', 'Rizki Pratama', 'L', 11, '2010-05-02', 'Wali Rizki', '081234567801', 'Dusun Gandaria', 1, '$now', 1, 'aktif', 'Limboto', 'Ayah Rizki', 'Ibu Rizki'),
    (42, '2401001', '0071234567', 'Nurhaliza Putri', 'P', 12, '2010-03-21', 'Ahmad Yusuf', '081234567802', 'Desa Sidomukti', 1, '$now', 1, 'aktif', 'Gorontalo', 'Ahmad Yusuf', 'Salmah'),
    (43, '2401002', '0071234568', 'Anak Sudah Lulus', 'L', 12, '2009-01-02', 'Wali Lulus', '', '', 0, '$now', 1, 'lulus', '', '', ''),
    (44, '2401003', '', 'Siswa Tanpa Kelas', 'P', 999, '', '', '', '', 1, '$now', 1, 'aktif', '', '', ''),
    (51, 'L-001', '', 'Siswa Sekolah Lain', 'L', 21, '', '', '', '', 1, '$now', 2, 'aktif', '', '', '')");

echo "fixture e-GURU siap: $berkas\n";
