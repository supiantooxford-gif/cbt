<?php
/**
 * Fixture uji CBT: mengisi satu sekolah dengan kelas, guru, siswa, dan akun.
 * HANYA untuk basis data uji (dipakai skrip uji, bukan data nyata).
 */
declare(strict_types=1);

// ---------------------------------------------------------------------------------------------
// PENJAGA: berkas ini MENGISI basis data uji. WAJIB dijalankan pada SALINAN di /tmp, mis.:
//   CBT_DB_FILE=/tmp/cbt_uji.sqlite php seed_uji.php
// ---------------------------------------------------------------------------------------------
$dbUji = (string) getenv('CBT_DB_FILE');
if ($dbUji === '' || !str_contains($dbUji, '/tmp/')) {
    fwrite(STDERR, "Ditolak: jalankan dengan CBT_DB_FILE yang menunjuk berkas di /tmp (salinan uji).\n");
    exit(1);
}

$akar = getenv('CBT_DIR') ?: __DIR__;
require_once $akar . '/inc/init.php';

$sid = (int) ($argv[1] ?? 1);
$s = one('SELECT * FROM sekolah WHERE id = ?', [$sid]);
if (!$s) {
    fwrite(STDERR, "Sekolah id $sid tidak ada\n");
    exit(1);
}

$now = date('Y-m-d H:i:s');

// Kelas
$kelasIds = [];
foreach ([['VII-A', '7', 10], ['VII-B', '7', 20], ['VIII-A', '8', 30]] as $k) {
    $ada = one('SELECT id FROM kelas WHERE sekolah_id = ? AND nama = ?', [$sid, $k[0]]);
    if ($ada) {
        $kelasIds[$k[0]] = (int) $ada['id'];
        continue;
    }
    q('INSERT INTO kelas (sekolah_id, nama, tingkat, urutan, dibuat) VALUES (?,?,?,?,?)',
        [$sid, $k[0], $k[1], $k[2], $now]);
    $kelasIds[$k[0]] = (int) db()->lastInsertId();
}

// Mata pelajaran yang dipakai untuk ujian
$mapelId = (int) val('SELECT id FROM mapel WHERE sekolah_id = ? ORDER BY urutan LIMIT 1', [$sid], 0);
if ($mapelId === 0) {
    q('INSERT INTO mapel (sekolah_id, kode, nama, urutan, dibuat) VALUES (?,?,?,?,?)',
        [$sid, 'MTK', 'Matematika', 10, $now]);
    $mapelId = (int) db()->lastInsertId();
}

// Guru
$guru = [];
foreach ([
    ['G-001', 'Ahmad Fauzi, S.Pd', 'L', 'ahmad', 'guru123'],
    ['G-002', 'Siti Aminah, S.Pd', 'P', 'siti', 'guru123'],
] as $g) {
    $ada = one('SELECT id FROM guru WHERE sekolah_id = ? AND nip = ?', [$sid, $g[0]]);
    if ($ada) {
        $gid = (int) $ada['id'];
    } else {
        q('INSERT INTO guru (sekolah_id, nip, nama, jenis_kelamin, aktif, dibuat) VALUES (?,?,?,?,1,?)',
            [$sid, $g[0], $g[1], $g[2], $now]);
        $gid = (int) db()->lastInsertId();
    }
    guru_mapel_simpan($gid, [$mapelId]);
    guru_kelas_simpan($gid, array_values($kelasIds));
    if (!one('SELECT id FROM users WHERE guru_id = ?', [$gid])) {
        q('INSERT INTO users (sekolah_id, nama, username, password, role, guru_id, aktif, dibuat) VALUES (?,?,?,?,?,?,1,?)',
            [$sid, $g[1], $g[3], password_hash($g[4], PASSWORD_DEFAULT), 'guru', $gid, $now]);
    }
    $guru[] = $gid;
}
$guruId = $guru[0];

// Siswa + akun
$siswa = [
    ['S-001', 'Rizki Pratama', 'L', 'VII-A', 'siswa1'],
    ['S-002', 'Nur Aisyah', 'P', 'VII-A', 'siswa2'],
    ['S-003', 'Bagas Saputra', 'L', 'VII-B', 'siswa3'],
];
$siswaIds = [];
foreach ($siswa as $s2) {
    $ada = one('SELECT id FROM siswa WHERE sekolah_id = ? AND nis = ?', [$sid, $s2[0]]);
    if ($ada) {
        $swid = (int) $ada['id'];
    } else {
        q('INSERT INTO siswa (sekolah_id, nis, nisn, nama, jenis_kelamin, tempat_lahir, tanggal_lahir,
                kelas_id, wali, aktif, dibuat) VALUES (?,?,?,?,?,?,?,?,?,1,?)',
            [$sid, $s2[0], '00' . $s2[0], $s2[1], $s2[2], 'Mootilango', '2011-04-12',
                $kelasIds[$s2[3]], 'Wali ' . $s2[1], $now]);
        $swid = (int) db()->lastInsertId();
    }
    if (!one('SELECT id FROM users WHERE siswa_id = ?', [$swid])) {
        q('INSERT INTO users (sekolah_id, nama, username, password, role, siswa_id, aktif, dibuat) VALUES (?,?,?,?,?,?,1,?)',
            [$sid, $s2[1], $s2[4], password_hash('siswa123', PASSWORD_DEFAULT), 'siswa', $swid, $now]);
    }
    $siswaIds[] = $swid;
}

echo "sekolah=$sid kelas=" . implode(',', $kelasIds) . " guru=$guruId mapel=$mapelId siswa=" . implode(',', $siswaIds) . "\n";
