<?php
/**
 * Fixture uji CBT: menyiapkan satu sekolah lengkap (kelas, mapel, guru, siswa, akun)
 * beserta SATU ujian aktif berisi enam jenis soal.
 *
 * HANYA untuk basis data uji (salinan di /tmp). Jalankan:
 *   CBT_DB_FILE=/tmp/xxx.sqlite php fixture_cbt.php
 */
declare(strict_types=1);

// ---------------------------------------------------------------------------------------------
// PENJAGA: berkas ini MENGISI basis data uji. WAJIB dijalankan pada SALINAN di /tmp, mis.:
//   CBT_DB_FILE=/tmp/cbt_uji.sqlite php fixture_cbt.php
// ---------------------------------------------------------------------------------------------
$dbUji = (string) getenv('CBT_DB_FILE');
if ($dbUji === '' || !str_contains($dbUji, '/tmp/')) {
    fwrite(STDERR, "Ditolak: jalankan dengan CBT_DB_FILE yang menunjuk berkas di /tmp (salinan uji).\n");
    exit(1);
}

require_once __DIR__ . '/inc/init.php';

$now = date('Y-m-d H:i:s');

$sekolah = one('SELECT * FROM sekolah ORDER BY id LIMIT 1');
if (!$sekolah) {
    q('INSERT INTO sekolah (nama, jenjang, npsn, kecamatan, kabupaten, provinsi, telepon, email,
            kepala, nip_kepala, verifikasi, aktif, tahun_ajaran, semester, kkm, aktivasi, dibuat)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        ['MA Uji CBT', 'SMP/MTs', '111222', 'Mootilango', 'Gorontalo', 'Gorontalo', '08123', 'uji@example.com',
            'Kepala Uji', '19800101', 'disetujui', 1, '2025/2026', 'Ganjil', 75, 'permanen', $now]);
    $sid = (int) db()->lastInsertId();
    provision_sekolah($sid);
    $sekolah = one('SELECT * FROM sekolah WHERE id = ?', [$sid]);
}
$sid = (int) $sekolah['id'];

$akun = function (string $nama, string $uname, string $sandi, string $role, ?int $guruId = null, ?int $siswaId = null) use ($sid, $now): void {
    if (one('SELECT id FROM users WHERE username = ?', [$uname])) {
        return;
    }
    q('INSERT INTO users (sekolah_id, nama, username, password, role, guru_id, siswa_id, aktif, dibuat)
       VALUES (?,?,?,?,?,?,?,1,?)',
        [$sid, $nama, $uname, password_hash($sandi, PASSWORD_DEFAULT), $role, $guruId, $siswaId, $now]);
};

$akun('Operator Uji', 'operator1', 'sandi12345', 'operator');

// Kelas
$kelas = [];
foreach ([['VII-A', '7', 10], ['VII-B', '7', 20]] as $k) {
    $ada = one('SELECT id FROM kelas WHERE sekolah_id = ? AND nama = ?', [$sid, $k[0]]);
    if (!$ada) {
        q('INSERT INTO kelas (sekolah_id, nama, tingkat, urutan, dibuat) VALUES (?,?,?,?,?)', [$sid, $k[0], $k[1], $k[2], $now]);
        $ada = one('SELECT id FROM kelas WHERE sekolah_id = ? AND nama = ?', [$sid, $k[0]]);
    }
    $kelas[$k[0]] = (int) $ada['id'];
}
$mapelId = (int) val('SELECT id FROM mapel WHERE sekolah_id = ? ORDER BY urutan LIMIT 1', [$sid], 0);

// Guru
$guruId = 0;
foreach ([['G-001', 'Ahmad Fauzi, S.Pd', 'L', 'ahmad'], ['G-002', 'Siti Aminah, S.Pd', 'P', 'siti']] as $g) {
    $ada = one('SELECT id FROM guru WHERE sekolah_id = ? AND nip = ?', [$sid, $g[0]]);
    if (!$ada) {
        q('INSERT INTO guru (sekolah_id, nip, nama, jenis_kelamin, aktif, dibuat) VALUES (?,?,?,?,1,?)', [$sid, $g[0], $g[1], $g[2], $now]);
        $ada = one('SELECT id FROM guru WHERE sekolah_id = ? AND nip = ?', [$sid, $g[0]]);
    }
    if ($guruId === 0) {
        $guruId = (int) $ada['id'];
    }
    guru_mapel_simpan((int) $ada['id'], [$mapelId]);
    guru_kelas_simpan((int) $ada['id'], array_values($kelas));
    $akun($g[1], $g[3], 'guru123', 'guru', (int) $ada['id']);
}

// Siswa
$siswaIds = [];
foreach ([['S-001', 'Rizki Pratama', 'L', 'VII-A', 'siswa1'], ['S-002', 'Nur Aisyah', 'P', 'VII-A', 'siswa2']] as $s) {
    $ada = one('SELECT id FROM siswa WHERE sekolah_id = ? AND nis = ?', [$sid, $s[0]]);
    if (!$ada) {
        q('INSERT INTO siswa (sekolah_id, nis, nisn, nama, jenis_kelamin, tempat_lahir, tanggal_lahir,
                kelas_id, wali, aktif, dibuat) VALUES (?,?,?,?,?,?,?,?,?,1,?)',
            [$sid, $s[0], '00' . $s[0], $s[1], $s[2], 'Mootilango', '2011-04-12', $kelas[$s[3]], 'Wali ' . $s[1], $now]);
        $ada = one('SELECT id FROM siswa WHERE sekolah_id = ? AND nis = ?', [$sid, $s[0]]);
    }
    $siswaIds[] = (int) $ada['id'];
    $akun($s[1], $s[4], 'siswa123', 'siswa', null, (int) $ada['id']);
}

// Ujian aktif + enam jenis soal
$uj = one('SELECT * FROM ujian WHERE sekolah_id = ? ORDER BY id LIMIT 1', [$sid]);
if (!$uj) {
    q('INSERT INTO ujian (sekolah_id, mapel_id, guru_id, judul, keterangan, jenis_ujian, durasi,
            acak_soal, acak_opsi, tampilkan_hasil, status, dibuat, diubah)
       VALUES (?,?,?,?,?,?,?,0,0,1,?,?,?)',
        [$sid, $mapelId, $guruId, 'Ulangan Harian Matematika', 'Kerjakan dengan teliti. Dilarang membuka tab lain.',
            'Ulangan Harian', 60, 'aktif', $now, $now]);
    $ujianId = (int) db()->lastInsertId();
    foreach ($kelas as $kid) {
        q('INSERT OR IGNORE INTO ujian_kelas (ujian_id, kelas_id) VALUES (?, ?)', [$ujianId, $kid]);
    }
    $daftar = [
        ['pg', 'Hasil 12 x 8 adalah...', ['86', '96', '108', '112'], 1, 1, ''],
        ['pgk', 'Manakah yang termasuk bilangan prima?', ['2', '3', '4', '9'], [0, 1], 2, ''],
        ['bs', 'Nyatakan pernyataan berikut benar atau salah.', ['2 + 2 = 4', '5 x 5 = 20'], ['B', 'S'], 2, ''],
        ['jodoh', 'Jodohkan operasi dengan hasilnya.', ['2 + 2', '3 x 3'], ['4', '9'], 2, ''],
        ['isian', 'Akar dari 81 adalah...', [], ['9', 'sembilan'], 1, ''],
        ['uraian', 'Jelaskan cara menghitung luas persegi panjang.', [], ['panjang x lebar/panjang kali lebar/p x l'], 4, ''],
    ];
    $u = 10;
    foreach ($daftar as $d) {
        [$jenis, $tanya, $opsi, $kunci, $skor, $bahas] = $d;
        q('INSERT INTO soal (sekolah_id, ujian_id, jenis, pertanyaan, opsi, jawaban, skor, pembahasan, urutan, dibuat)
           VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$sid, $ujianId, $jenis, $tanya, $opsi ? opsi_encode($opsi) : null,
                json_encode($kunci, JSON_UNESCAPED_UNICODE), $skor, $bahas, $u, $now]);
        $u += 10;
    }
}

echo "fixture siap: sekolah=$sid ujian=" . (int) val('SELECT id FROM ujian WHERE sekolah_id = ? ORDER BY id LIMIT 1', [$sid], 0) . "\n";
