<?php
/**
 * TARIK DATA DARI e-GURU
 *
 * Permintaan pemilik: "Pada akun admin tambahkan menu tarik data dari e-Guru. Yang ditarik
 * berdasarkan Nama lembaga (Otomatis data guru dan siswa akan masuk di aplikasi CBT sehingga
 * tidak perlu lagi input manual)".
 *
 * Pilihan pemilik (jangan diubah tanpa bertanya):
 *   1. menunya di akun SUPER ADMIN saja;
 *   2. akun login guru & siswa sekaligus dibuatkan otomatis. KHUSUS GURU (permintaan pemilik
 *      v1.14.0): akun memakai **nama pengguna DAN kata sandi e-GURU yang sama** — hash sandi
 *      e-GURU disalin apa adanya, sehingga guru masuk ke CBT dengan akun e-GURU-nya. Setelah itu
 *      guru tetap bebas mengganti sendiri kata sandinya pada menu "Akun Saya" di CBT
 *      (penarikan ULANG tidak menimpa sandi yang sudah diganti — lihat `eguru_akun_guru_sinkron`);
 *      siswa tetap memakai kata sandi awal siswa123;
 *   3. yang ditarik: Kelas & Mata Pelajaran + Data Guru + Data Siswa sekaligus.
 *
 * CARA KERJA
 *   - Lembaga CBT dicocokkan dengan lembaga e-GURU lewat NAMA yang dinormalkan (huruf kecil,
 *     tanpa spasi/tanda baca) sehingga perbedaan penulisan seperti titik/spasi ganda tidak
 *     menghalangi. Nama boleh berbeda jenis huruf besar-kecil.
 *   - Seluruh data e-GURU dibaca dari SALINAN BACA-SAJA (mode=ro + PRAGMA query_only) sehingga
 *     aplikasi ini TIDAK PERNAH menulis apa pun ke basis data e-GURU.
 *   - Menarik ulang aman: data yang sudah ada DIperbarui (dicocokkan lewat NIP/nama untuk guru,
 *     NIS/NISN/nama untuk siswa, nama untuk kelas & mata pelajaran) — tidak pernah diduplikasi.
 *   - Kolom yang kosong pada e-GURU tidak mengosongkan data CBT yang sudah diisi.
 */
declare(strict_types=1);

/** Berkas basis data e-GURU (dapat diarahkan lewat variabel lingkungan EGURU_DB_FILE untuk uji). */
function eguru_berkas(): string
{
    return (string) EGURU_DB_FILE;
}

/**
 * Koneksi ke basis data e-GURU (baca-saja). null = tidak tersedia.
 * Percobaan pertama memakai URI baca-saja; bila gagal, dibuka biasa lalu dikunci dengan
 * `PRAGMA query_only = ON` supaya tetap tidak mungkin menulis.
 */
function eguru_koneksi(): ?PDO
{
    static $pdo = false; // false = belum dicoba, null = gagal
    if ($pdo !== false) {
        return $pdo;
    }
    $pdo = null;
    $berkas = eguru_berkas();
    if ($berkas === '' || !is_file($berkas) || !is_readable($berkas)) {
        return $pdo;
    }
    foreach (['sqlite:file:' . $berkas . '?mode=ro', 'sqlite:' . $berkas] as $dsn) {
        try {
            $p = new PDO($dsn, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $p->exec('PRAGMA query_only = ON');
            // Pastikan tabel inti memang ada (berkas lain / basis data rusak ditolak).
            $p->query('SELECT id, nama FROM sekolah LIMIT 1');
            $p->query('SELECT id, nama FROM guru LIMIT 1');
            $p->query('SELECT id, nama FROM siswa LIMIT 1');
            $pdo = $p;
            return $pdo;
        } catch (Throwable $e) {
            $pdo = null;
        }
    }
    return $pdo;
}

function eguru_tersedia(): bool
{
    return eguru_koneksi() !== null;
}

/** Pesan ramah saat basis data e-GURU tidak dapat dibaca. */
function eguru_galat(): string
{
    $berkas = eguru_berkas();
    if ($berkas === '' || !is_file($berkas)) {
        return 'Basis data aplikasi e-GURU tidak ditemukan pada pemasangan ini ('
            . $berkas . '). Pastikan aplikasi e-GURU ada di server yang sama. '
            . 'Sebagai jalan lain, gunakan menu Impor Data Guru / Impor Data Siswa dengan berkas '
            . 'ekspor dari e-GURU.';
    }
    return 'Basis data aplikasi e-GURU tidak dapat dibaca oleh aplikasi ini (' . $berkas . '). '
        . 'Gunakan menu Impor Data Guru / Impor Data Siswa dengan berkas ekspor dari e-GURU.';
}

// ------------------------------------------------------------------ pembantu baca

/**
 * AKUN GURU pada e-GURU: nama pengguna + hash kata sandi.
 *
 * Permintaan pemilik (v1.14.0): *"pastikan akun guru yang ditarik dari e-Guru user dan passwordnya
 * tetap sama (memakai akun e-Guru. kecuali nanti guru menggantinya sendiri di akun CBT)"*.
 * Karena kedua aplikasi memakai PHP `password_hash()` (bcrypt), hash e-GURU dapat **disalin
 * langsung** dan `password_verify()` di CBT akan menerima kata sandi yang sama tanpa perlu tahu
 * kata sandinya. Tidak ada kata sandi yang disimpan sebagai teks.
 *
 * @return array{username:string, hash:string}|null null = e-GURU tidak punya akun untuk guru itu
 */
function eguru_akun_guru(int $eguruGuruId): ?array
{
    if ($eguruGuruId <= 0) {
        return null;
    }
    $u = eguru_one('SELECT username, password_hash FROM users WHERE guru_id = ? ORDER BY (aktif = 1) DESC, id LIMIT 1',
        [$eguruGuruId]);
    if (!$u) {
        return null;
    }
    $uname = trim((string) ($u['username'] ?? ''));
    $hash = trim((string) ($u['password_hash'] ?? ''));
    if ($uname === '' || $hash === '') {
        return null;
    }
    return ['username' => $uname, 'hash' => $hash];
}

/**
 * Samakan akun guru CBT dengan akun e-GURU-nya.
 *
 * Aturan yang dipakai (jangan diubah tanpa bertanya):
 *  • AKUN BARU  → dibuat dengan nama pengguna & hash sandi e-GURU (bila e-GURU tidak punya akun,
 *                 dipakai nama pengguna otomatis + sandi awal `guru123` seperti sebelumnya).
 *  • AKUN LAMA  → nama pengguna disamakan bila belum dipakai akun lain, dan kata sandinya
 *                 disalin HANYA bila sandinya masih `guru123` (belum pernah diganti pemiliknya).
 *                 Sandi yang SUDAH diganti guru di CBT TIDAK PERNAH ditimpa — itulah maksud
 *                 "kecuali nanti guru menggantinya sendiri di akun CBT".
 *
 * @return string 'baru' | 'sinkron' | 'tetap' | ''
 */
function eguru_akun_guru_sinkron(array $guruCbt, array $akunEguru, int $sid, string $now): string
{
    $gid = (int) ($guruCbt['id'] ?? 0);
    if ($gid <= 0) {
        return '';
    }
    $nama = (string) ($guruCbt['nama'] ?? '');
    $aktif = (int) ($guruCbt['aktif'] ?? 1) === 1 ? 1 : 0;
    $lama = one('SELECT * FROM users WHERE guru_id = ?', [$gid]);

    if (!$lama) {
        $uname = $akunEguru['username'] ?? '';
        $hash = $akunEguru['hash'] ?? '';
        if ($hash === '') {
            $hash = password_hash('guru123', PASSWORD_DEFAULT);
        }
        // Nama pengguna e-GURU dipakai APA ADANYA bila masih bebas; hanya bila sudah dipakai akun
        // lain di CBT dibuat variasi unik (dan hal itu dilaporkan pada ringkasan penarikan).
        // Nama pengguna e-GURU dipakai APA ADANYA (tanda titik dsb. dipertahankan).
        $uname = $uname !== ''
            ? username_unik_apa_adanya($uname)
            : username_unik(username_dari_nama($nama));
        q('INSERT INTO users (sekolah_id, nama, username, password, role, guru_id, aktif, dibuat)
           VALUES (?,?,?,?,?,?,?,?)',
            [$sid, $nama, $uname, $hash, 'guru', $gid, $aktif, $now]);
        return 'baru';
    }

    if (!$akunEguru) {
        return 'tetap';
    }
    $ubah = [];
    $nilai = [];
    $uname = (string) $akunEguru['username'];
    if ($uname !== '' && (string) $lama['username'] !== $uname
        && !one('SELECT id FROM users WHERE username = ? AND id <> ?', [$uname, (int) $lama['id']])) {
        $ubah[] = 'username = ?';
        $nilai[] = $uname;
    }
    if (password_verify('guru123', (string) $lama['password'])) {
        // Sandi awal (belum pernah diganti) → samakan dengan sandi e-GURU.
        $ubah[] = 'password = ?';
        $nilai[] = (string) $akunEguru['hash'];
    }
    if (!$ubah) {
        return 'tetap';
    }
    $nilai[] = (int) $lama['id'];
    q('UPDATE users SET ' . implode(', ', $ubah) . ' WHERE id = ?', $nilai);
    return 'sinkron';
}

function eguru_all(string $sql, array $p = []): array
{
    $db = eguru_koneksi();
    if (!$db) {
        return [];
    }
    try {
        $st = $db->prepare($sql);
        $st->execute($p);
        return $st->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function eguru_one(string $sql, array $p = []): ?array
{
    $b = eguru_all($sql, $p);
    return $b ? $b[0] : null;
}

function eguru_val(string $sql, array $p = [], $bawaan = 0)
{
    $r = eguru_one($sql, $p);
    if (!$r) {
        return $bawaan;
    }
    $v = array_values($r);
    return $v[0] ?? $bawaan;
}

/** Kunci pencocokan NAMA LEMBAGA: huruf kecil, spasi & tanda baca dibuang. */
function eguru_nama_kunci(string $nama): string
{
    $s = strtolower(trim($nama));
    return (string) preg_replace('/[^a-z0-9]+/', '', $s);
}

/**
 * Cari lembaga e-GURU yang namanya sama dengan $nama (dinormalkan).
 * Mengembalikan baris sekolah e-GURU, atau null bila tidak ada.
 */
function eguru_sekolah_cari(string $nama): ?array
{
    $kunci = eguru_nama_kunci($nama);
    if ($kunci === '') {
        return null;
    }
    foreach (eguru_all('SELECT id, nama, jenis, aktif, verifikasi FROM sekolah ORDER BY id') as $r) {
        if (eguru_nama_kunci((string) $r['nama']) === $kunci) {
            return $r;
        }
    }
    return null;
}

/** Ringkasan isi sebuah lembaga e-GURU (dipakai pratinjau sebelum menarik data). */
function eguru_ringkas(int $esid): array
{
    return [
        'kelas' => (int) eguru_val('SELECT COUNT(*) FROM kelas WHERE sekolah_id = ?', [$esid], 0),
        'mapel' => (int) eguru_val('SELECT COUNT(*) FROM mapel WHERE sekolah_id = ?', [$esid], 0),
        'guru'  => (int) eguru_val('SELECT COUNT(*) FROM guru WHERE sekolah_id = ?', [$esid], 0),
        'siswa' => (int) eguru_val('SELECT COUNT(*) FROM siswa WHERE sekolah_id = ?', [$esid], 0),
    ];
}

/** Seluruh lembaga e-GURU (untuk saran nama & pencarian). */
function eguru_sekolah_semua(): array
{
    return eguru_all('SELECT id, nama, jenis FROM sekolah ORDER BY nama');
}

/**
 * Cari lembaga e-GURU berdasarkan SEPOTONG NAMA (dinormalkan: huruf kecil tanpa spasi/tanda baca).
 * Urutan hasil: nama yang PERSIS sama → berawalan → memuat. Teks kosong = seluruh lembaga.
 */
function eguru_sekolah_cari_daftar(string $teks, int $maks = 30): array
{
    $kunci = eguru_nama_kunci($teks);
    $hasil = [];
    foreach (eguru_sekolah_semua() as $r) {
        $k = eguru_nama_kunci((string) $r['nama']);
        if ($kunci === '') {
            $urut = 3;
        } elseif ($k === $kunci) {
            $urut = 0;
        } elseif (str_starts_with($k, $kunci)) {
            $urut = 1;
        } elseif (str_contains($k, $kunci)) {
            $urut = 2;
        } else {
            continue;
        }
        $r['_urut'] = $urut;
        $hasil[] = $r;
    }
    usort($hasil, fn(array $a, array $b): int =>
        [$a['_urut'], strtolower((string) $a['nama'])] <=> [$b['_urut'], strtolower((string) $b['nama'])]);
    foreach ($hasil as &$h) {
        unset($h['_urut']);
    }
    unset($h);
    return array_slice($hasil, 0, max(1, $maks));
}

/**
 * Apakah lembaga itu SUDAH ada di CBT? Dicocokkan dengan nama yang dinormalkan
 * (sehingga beda tanda titik/spasi tetap dikenali). Mengembalikan baris `sekolah` CBT atau null.
 */
function eguru_sekolah_di_cbt(string $nama): ?array
{
    $kunci = eguru_nama_kunci($nama);
    if ($kunci === '') {
        return null;
    }
    foreach (all('SELECT * FROM sekolah ORDER BY id') as $r) {
        if (eguru_nama_kunci((string) $r['nama']) === $kunci) {
            return $r;
        }
    }
    return null;
}

// ------------------------------------------------------------------ daftar SEMUA lembaga e-GURU
// Permintaan pemilik: "Pada halaman super admin bagian tarik data dari e-Guru pastikan menampilkan
// semua sekolah yang ada di dalam aplikasi e-guru (realtime termasuk yang baru mendaftar).
// Tambahkan keterangan yang sudah ditarik datanya dengan yang belum."

/**
 * Peta sekolah CBT untuk pencocokan cepat: lewat `eguru_id` (hasil penarikan sebelumnya) DAN
 * lewat nama yang dinormalkan. Dimuat SEKALI per permintaan supaya daftar e-GURU tidak
 * menjalankan kueri berulang.
 *
 * @return array{id:array<int,array>,nama:array<string,array>}
 */
function eguru_cbt_peta(): array
{
    static $peta = null;
    if ($peta !== null) {
        return $peta;
    }
    $peta = ['id' => [], 'nama' => []];
    foreach (all('SELECT * FROM sekolah ORDER BY id') as $r) {
        $eid = (int) ($r['eguru_id'] ?? 0);
        if ($eid > 0 && !isset($peta['id'][$eid])) {
            $peta['id'][$eid] = $r;
        }
        $kunci = eguru_nama_kunci((string) $r['nama']);
        if ($kunci !== '' && !isset($peta['nama'][$kunci])) {
            $peta['nama'][$kunci] = $r;
        }
    }
    return $peta;
}

/**
 * Sekolah CBT yang berpasangan dengan sebuah lembaga e-GURU.
 * Urutan pencocokan: `eguru_id` (paling tepat) → nama lembaga yang dinormalkan.
 */
function eguru_cbt_pasangan(array $eg): ?array
{
    $peta = eguru_cbt_peta();
    $eid = (int) ($eg['id'] ?? 0);
    if ($eid > 0 && isset($peta['id'][$eid])) {
        return $peta['id'][$eid];
    }
    $kunci = eguru_nama_kunci((string) ($eg['nama'] ?? ''));
    return $kunci !== '' ? ($peta['nama'][$kunci] ?? null) : null;
}

/**
 * Keadaan penarikan data sebuah lembaga e-GURU ke CBT.
 * Mengembalikan ['kode' => sudah|belum|tanpa, 'label', 'kelas' (kelas CSS pil), 'ket'].
 */
function eguru_status_pasangan(?array $cbt): array
{
    if (!$cbt) {
        return ['kode' => 'tanpa', 'label' => 'Belum ada di CBT', 'kelas' => 'pil-mut',
            'ket' => 'Lembaga ini belum dibuat di CBT'];
    }
    $tarik = trim((string) ($cbt['eguru_tarik'] ?? ''));
    if ($tarik !== '') {
        return ['kode' => 'sudah', 'label' => 'Sudah ditarik', 'kelas' => 'pil-ok',
            'ket' => 'Terakhir ditarik ' . waktu_id($tarik)];
    }
    return ['kode' => 'belum', 'label' => 'Belum ditarik', 'kelas' => 'pil-warn',
        'ket' => 'Sekolahnya sudah ada di CBT, datanya belum ditarik'];
}

/**
 * SELURUH lembaga yang ada di aplikasi e-GURU (realtime — dibaca langsung dari basis data
 * e-GURU setiap halaman dibuka, termasuk sekolah yang baru mendaftar), lengkap dengan
 * jumlah isi, pasangannya di CBT, dan keadaan penarikan datanya.
 *
 * @return array<int,array> tiap baris: id, nama, jenis, aktif, verifikasi, ringkas, cbt, status
 */
function eguru_sekolah_lengkap(): array
{
    $hasil = [];
    foreach (eguru_all('SELECT id, nama, jenis, aktif, verifikasi FROM sekolah ORDER BY nama') as $r) {
        $r['ringkas'] = eguru_ringkas((int) $r['id']);
        $r['cbt'] = eguru_cbt_pasangan($r);
        $r['status'] = eguru_status_pasangan($r['cbt']);
        $hasil[] = $r;
    }
    return $hasil;
}

/**
 * Hitungan ringkas daftar lembaga e-GURU: total, sudah ditarik, belum ditarik, belum ada di CBT.
 *
 * @param array<int,array>|null $daftar hasil eguru_sekolah_lengkap() (null = hitung sendiri)
 */
function eguru_ringkas_status(?array $daftar = null): array
{
    $daftar = $daftar ?? eguru_sekolah_lengkap();
    $h = ['total' => count($daftar), 'sudah' => 0, 'belum' => 0, 'tanpa' => 0];
    foreach ($daftar as $r) {
        $kode = (string) ($r['status']['kode'] ?? 'tanpa');
        if (isset($h[$kode])) {
            $h[$kode]++;
        }
    }
    return $h;
}

/**
 * Sekolah CBT yang TIDAK berpasangan dengan lembaga e-GURU mana pun (nama belum sama).
 * Dipakai halaman Tarik Data agar sekolah seperti ini tidak "hilang" dari pandangan Super Admin.
 */
function eguru_cbt_tanpa_pasangan(): array
{
    $kunciEguru = [];
    foreach (eguru_sekolah_semua() as $eg) {
        $k = eguru_nama_kunci((string) $eg['nama']);
        if ($k !== '') {
            $kunciEguru[$k] = true;
        }
    }
    // Sekolah CBT yang `eguru_id`-nya menunjuk lembaga e-GURU yang masih ada juga dianggap cocok.
    $idEguru = [];
    foreach (eguru_sekolah_semua() as $eg) {
        $idEguru[(int) $eg['id']] = true;
    }
    $hasil = [];
    foreach (all("SELECT sc.*,
            (SELECT COUNT(*) FROM guru g WHERE g.sekolah_id = sc.id) AS guru,
            (SELECT COUNT(*) FROM siswa sw WHERE sw.sekolah_id = sc.id) AS siswa
          FROM sekolah sc ORDER BY sc.nama") as $r) {
        $eid = (int) ($r['eguru_id'] ?? 0);
        if ($eid > 0 && isset($idEguru[$eid])) {
            continue;
        }
        $k = eguru_nama_kunci((string) $r['nama']);
        if ($k !== '' && isset($kunciEguru[$k])) {
            continue;
        }
        $hasil[] = $r;
    }
    return $hasil;
}

/**
 * Perkiraan JENJANG CBT dari kelas-kelas di e-GURU (I–VI → SD/MI, VII–IX → SMP/MTs,
 * X–XII → SMA/MA/SMK). Bila tidak dapat diperkirakan, dipakai 'SMP/MTs' sebagai bawaan
 * dan pemakai boleh mengubahnya pada formulir.
 */
function eguru_jenjang_tebak(int $esid): string
{
    $peta = ['i' => 'SD/MI', 'ii' => 'SD/MI', 'iii' => 'SD/MI', 'iv' => 'SD/MI', 'v' => 'SD/MI', 'vi' => 'SD/MI',
        'vii' => 'SMP/MTs', 'viii' => 'SMP/MTs', 'ix' => 'SMP/MTs',
        'x' => 'SMA/MA/SMK', 'xi' => 'SMA/MA/SMK', 'xii' => 'SMA/MA/SMK'];
    $hitung = [];
    foreach (eguru_all('SELECT tingkat, nama FROM kelas WHERE sekolah_id = ?', [$esid]) as $k) {
        $t = strtolower(trim((string) ($k['tingkat'] ?? '')));
        if (!isset($peta[$t])) {
            // Cadangan: ambil angka Romawi dari NAMA kelas ("Kelas VII" / "VII-A" / "kelas 8").
            $nm = strtolower(kelas_nama_rapi(teks((string) ($k['nama'] ?? ''), 60)));
            if (preg_match('/^(xii|xi|ix|viii|vii|vi|iv|iii|ii|x|v|i)(?![a-z])/', $nm, $m)) {
                $t = $m[1];
            }
        }
        if (isset($peta[$t])) {
            $hitung[$peta[$t]] = ($hitung[$peta[$t]] ?? 0) + 1;
        }
    }
    if (!$hitung) {
        return 'SMP/MTs';
    }
    arsort($hitung);
    return (string) array_key_first($hitung);
}

/**
 * Identitas lengkap sebuah lembaga e-GURU, siap dipakai membuat sekolah di CBT
 * (+ ringkasan jumlah isi, perkiraan jenjang, tahun ajaran & semester aktifnya).
 */
function eguru_identitas(int $esid): ?array
{
    $s = eguru_one('SELECT * FROM sekolah WHERE id = ?', [$esid]);
    if (!$s) {
        return null;
    }
    $s['ringkas'] = eguru_ringkas($esid);
    $s['jenjang'] = eguru_jenjang_tebak($esid);
    $s['ta'] = (string) eguru_val('SELECT nama FROM tahun_ajaran WHERE id = ?', [(int) ($s['ta_aktif_id'] ?? 0)], '');
    $sem = (string) eguru_val('SELECT nama FROM semester WHERE id = ?', [(int) ($s['semester_aktif_id'] ?? 0)], '');
    $s['semester'] = in_array(ucfirst(strtolower($sem)), ['Ganjil', 'Genap'], true) ? ucfirst(strtolower($sem)) : 'Ganjil';
    $s['kepala'] = (string) ($s['kepala_sekolah'] ?? '');
    $s['nip_kepala'] = (string) ($s['nip_kepala_sekolah'] ?? '');
    return $s;
}

/** Label jenis lembaga e-GURU ('umum'/'madrasah') untuk tampilan. */
function eguru_jenis_label(?string $jenis): string
{
    return strtolower((string) $jenis) === 'madrasah' ? 'Madrasah' : 'Sekolah Umum';
}

// ------------------------------------------------------------------ hasil (tampil sekali)

function eguru_hasil_simpan(array $hasil): void
{
    $_SESSION['tarik_eguru_hasil'] = $hasil;
}

function eguru_hasil_ambil(): ?array
{
    $h = $_SESSION['tarik_eguru_hasil'] ?? null;
    unset($_SESSION['tarik_eguru_hasil']);
    return is_array($h) ? $h : null;
}

// ------------------------------------------------------------------ penarikan data

/**
 * Menarik Kelas, Mata Pelajaran, Guru (+akun), dan Siswa (+akun) dari lembaga e-GURU yang
 * namanya sama dengan sekolah CBT yang sedang aktif ($sekolahId = sekolah_id()).
 *
 * Mengembalikan ringkasan angka, atau ['galat' => pesan] bila tidak bisa dijalankan.
 */
function eguru_tarik(): array
{
    $sid = sekolah_id();
    if ($sid <= 0) {
        return ['galat' => 'Sekolah/madrasah belum dipilih. Pilih sekolah lebih dahulu.'];
    }
    $cbt = one('SELECT * FROM sekolah WHERE id = ?', [$sid]);
    if (!$cbt) {
        return ['galat' => 'Sekolah/madrasah tidak ditemukan.'];
    }
    if (!eguru_tersedia()) {
        return ['galat' => eguru_galat()];
    }
    $es = eguru_sekolah_cari((string) $cbt['nama']);
    if (!$es) {
        return ['galat' => 'Lembaga bernama "' . $cbt['nama'] . '" tidak ditemukan di aplikasi e-GURU. '
            . 'Nama lembaga pada kedua aplikasi harus SAMA — samakan dahulu pada menu '
            . 'Identitas & Pengaturan.'];
    }
    $esid = (int) $es['id'];
    $now = date('Y-m-d H:i:s');

    $hasil = [
        'sekolah'        => (string) $cbt['nama'],
        'sekolah_eguru'  => (string) $es['nama'],
        'kelas_baru'     => 0, 'kelas_total' => 0,
        'mapel_baru'     => 0, 'mapel_total' => 0,
        'guru_baru'      => 0, 'guru_diperbarui' => 0, 'guru_total' => 0,
        'siswa_baru'     => 0, 'siswa_diperbarui' => 0, 'siswa_total' => 0,
        'akun_guru'      => 0, 'akun_siswa' => 0,
        // v1.14.0: akun guru memakai akun e-GURU (nama pengguna & sandi sama).
        'akun_guru_sinkron' => 0, 'akun_guru_tanpa_eguru' => 0,
        'tanpa_kelas'    => 0,
    ];

    // ---- 1. Mata pelajaran ---------------------------------------------------------------
    $petaMapel = []; // id mapel e-GURU -> id mapel CBT
    $urutMapel = (int) val('SELECT COALESCE(MAX(urutan),0) FROM mapel WHERE sekolah_id = ?', [$sid], 0);
    foreach (eguru_all('SELECT id, nama, kode FROM mapel WHERE sekolah_id = ? ORDER BY urutan, nama', [$esid]) as $m) {
        $nama = teks((string) $m['nama'], 100);
        if ($nama === '') {
            continue;
        }
        $lama = one('SELECT * FROM mapel WHERE sekolah_id = ? AND LOWER(nama) = ?', [$sid, bawah($nama)]);
        $kode = teks((string) ($m['kode'] ?? ''), 20);
        if ($lama) {
            if ($kode !== '' && trim((string) $lama['kode']) === '') {
                q('UPDATE mapel SET kode = ? WHERE id = ? AND sekolah_id = ?', [$kode, (int) $lama['id'], $sid]);
            }
            $petaMapel[(int) $m['id']] = (int) $lama['id'];
        } else {
            $urutMapel += 10;
            q('INSERT INTO mapel (sekolah_id, kode, nama, urutan, dibuat) VALUES (?,?,?,?,?)',
                [$sid, $kode, $nama, $urutMapel, $now]);
            $petaMapel[(int) $m['id']] = (int) db()->lastInsertId();
            $hasil['mapel_baru']++;
        }
    }
    $hasil['mapel_total'] = count($petaMapel);

    // ---- 2. Kelas -----------------------------------------------------------------------
    $petaKelas = []; // id kelas e-GURU -> id kelas CBT
    $waliEguru = []; // id kelas CBT -> id guru e-GURU (wali)
    $urutKelas = (int) val('SELECT COALESCE(MAX(urutan),0) FROM kelas WHERE sekolah_id = ?', [$sid], 0);
    foreach (eguru_all('SELECT id, nama, tingkat, wali_id FROM kelas WHERE sekolah_id = ? ORDER BY urutan, nama', [$esid]) as $k) {
        $nama = kelas_nama_rapi(teks((string) $k['nama'], 60));
        if ($nama === '') {
            continue;
        }
        $tingkat = teks((string) ($k['tingkat'] ?? ''), 20);
        $lama = one('SELECT * FROM kelas WHERE sekolah_id = ? AND LOWER(nama) = ?', [$sid, bawah($nama)]);
        if ($lama) {
            if ($tingkat !== '' && trim((string) ($lama['tingkat'] ?? '')) === '') {
                q('UPDATE kelas SET tingkat = ? WHERE id = ? AND sekolah_id = ?', [$tingkat, (int) $lama['id'], $sid]);
            }
            $petaKelas[(int) $k['id']] = (int) $lama['id'];
        } else {
            $urutKelas += 10;
            q('INSERT INTO kelas (sekolah_id, nama, tingkat, urutan, dibuat) VALUES (?,?,?,?,?)',
                [$sid, $nama, $tingkat, $urutKelas, $now]);
            $petaKelas[(int) $k['id']] = (int) db()->lastInsertId();
            $hasil['kelas_baru']++;
        }
        if ((int) ($k['wali_id'] ?? 0) > 0) {
            $waliEguru[(int) $petaKelas[(int) $k['id']]] = (int) $k['wali_id'];
        }
    }
    $hasil['kelas_total'] = count($petaKelas);

    // ---- 3. Guru + akunnya ---------------------------------------------------------------
    $petaGuru = [];  // id guru e-GURU -> id guru CBT
    foreach (eguru_all('SELECT * FROM guru WHERE sekolah_id = ? ORDER BY nama', [$esid]) as $g) {
        $nama = teks((string) $g['nama'], 120);
        if ($nama === '') {
            continue;
        }
        $nip = nip_bersih($g['nip'] ?? '');
        $nuptk = nip_bersih($g['nuptk'] ?? '');
        $jkRaw = bawah((string) ($g['jenis_kelamin'] ?? ''));
        $jk = $jkRaw === '' ? '' : ((str_starts_with($jkRaw, 'p') || $jkRaw === 'perempuan') ? 'P' : 'L');
        $jabatan = teks((string) ($g['jabatan'] ?? ''), 80);
        $telp = teks((string) ($g['telepon'] ?? ''), 30);
        $email = teks((string) ($g['email'] ?? ''), 120);
        $aktif = (int) ($g['aktif'] ?? 1) === 1 ? 1 : 0;

        $lama = $nip !== ''
            ? one('SELECT * FROM guru WHERE sekolah_id = ? AND nip = ?', [$sid, $nip])
            : one('SELECT * FROM guru WHERE sekolah_id = ? AND LOWER(nama) = ?', [$sid, bawah($nama)]);
        if ($lama) {
            $gid = (int) $lama['id'];
            q('UPDATE guru SET nama=?, nip=?, nuptk=?, jenis_kelamin=?, jabatan=?, telp=?, email=?, aktif=?
               WHERE id=? AND sekolah_id=?', [
                $nama,
                $nip !== '' ? $nip : ($lama['nip'] ?? null),
                $nuptk !== '' ? $nuptk : ($lama['nuptk'] ?? null),
                $jk !== '' ? $jk : ($lama['jenis_kelamin'] ?? null),
                $jabatan !== '' ? $jabatan : ($lama['jabatan'] ?? null),
                $telp !== '' ? $telp : ($lama['telp'] ?? null),
                $email !== '' ? $email : ($lama['email'] ?? null),
                $aktif, $gid, $sid,
            ]);
            $hasil['guru_diperbarui']++;
        } else {
            q('INSERT INTO guru (sekolah_id, nip, nuptk, nama, jenis_kelamin, jabatan, telp, email, aktif, dibuat)
               VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$sid, $nip !== '' ? $nip : null, $nuptk !== '' ? $nuptk : null, $nama, $jk, $jabatan,
                    $telp, $email, $aktif, $now]);
            $gid = (int) db()->lastInsertId();
            $hasil['guru_baru']++;
        }
        $petaGuru[(int) $g['id']] = $gid;

        // Mata pelajaran & kelas yang diampu — HANYA ditulis bila e-GURU punya datanya,
        // supaya pengaturan manual pada CBT tidak terhapus tanpa alasan.
        $mIds = [];
        foreach (eguru_all('SELECT mapel_id FROM guru_mapel WHERE guru_id = ?', [(int) $g['id']]) as $gm) {
            $mid = $petaMapel[(int) $gm['mapel_id']] ?? 0;
            if ($mid > 0) {
                $mIds[] = $mid;
            }
        }
        if ($mIds) {
            guru_mapel_simpan($gid, $mIds);
        }
        $kIds = [];
        foreach (eguru_all('SELECT kelas_id FROM guru_kelas WHERE guru_id = ?', [(int) $g['id']]) as $gk) {
            $kid = $petaKelas[(int) $gk['kelas_id']] ?? 0;
            if ($kid > 0) {
                $kIds[] = $kid;
            }
        }
        if ($kIds) {
            guru_kelas_simpan($gid, $kIds);
        }

        // Akun guru memakai AKUN e-GURU (nama pengguna & kata sandi sama) — permintaan pemilik.
        $akunEguru = eguru_akun_guru((int) $g['id']);
        $caraAkun = eguru_akun_guru_sinkron(
            ['id' => $gid, 'nama' => $nama, 'aktif' => $aktif], $akunEguru ?: [], $sid, $now
        );
        if ($caraAkun === 'baru') {
            $hasil['akun_guru']++;
            if ($akunEguru) {
                $hasil['akun_guru_sinkron']++;
            }
        } elseif ($caraAkun === 'sinkron') {
            $hasil['akun_guru_sinkron']++;
        }
        if (!$akunEguru) {
            $hasil['akun_guru_tanpa_eguru']++;
        }
    }
    $hasil['guru_total'] = count($petaGuru);

    // ---- 4. Wali kelas (hanya bila kelas CBT belum punya wali) ---------------------------
    foreach ($waliEguru as $kelasCbt => $guruEguru) {
        $gid = $petaGuru[$guruEguru] ?? 0;
        if ($gid <= 0) {
            continue;
        }
        $waliSekarang = (int) val('SELECT COALESCE(wali_id,0) FROM kelas WHERE id = ? AND sekolah_id = ?', [$kelasCbt, $sid], 0);
        if ($waliSekarang === 0) {
            q('UPDATE kelas SET wali_id = ? WHERE id = ? AND sekolah_id = ?', [$gid, $kelasCbt, $sid]);
        }
    }

    // ---- 5. Siswa + akunnya --------------------------------------------------------------
    foreach (eguru_all('SELECT * FROM siswa WHERE sekolah_id = ? ORDER BY nama', [$esid]) as $s) {
        $nama = teks((string) $s['nama'], 120);
        if ($nama === '') {
            continue;
        }
        $nis = teks((string) ($s['nis'] ?? ''), 30);
        $nisn = teks((string) ($s['nisn'] ?? ''), 30);
        $jkRaw = bawah((string) ($s['jenis_kelamin'] ?? ''));
        $jk = $jkRaw === '' ? '' : ((str_starts_with($jkRaw, 'p') || $jkRaw === 'perempuan') ? 'P' : 'L');
        $tempat = teks((string) ($s['tempat_lahir'] ?? ''), 80);
        $tgl = teks((string) ($s['tanggal_lahir'] ?? ''), 20);
        $wali = teks((string) ($s['nama_wali'] ?? ''), 120);
        $telp = teks((string) ($s['telepon'] ?? ''), 30);
        $alamat = teks((string) ($s['alamat'] ?? ''), 250);
        $ayah = teks((string) ($s['nama_ayah'] ?? ''), 120);
        $ibu = teks((string) ($s['nama_ibu'] ?? ''), 120);
        $status = siswa_status_dari_teks((string) ($s['status'] ?? ''));
        if ($status === '') {
            $status = (int) ($s['aktif'] ?? 1) === 1 ? 'aktif' : 'nonaktif';
        }
        $kelasId = $petaKelas[(int) ($s['kelas_id'] ?? 0)] ?? 0;
        if ($kelasId === 0 && (int) ($s['kelas_id'] ?? 0) > 0) {
            $hasil['tanpa_kelas']++;
        }

        $lama = null;
        if ($nis !== '') {
            $lama = one('SELECT * FROM siswa WHERE sekolah_id = ? AND nis = ?', [$sid, $nis]);
        }
        if (!$lama && $nisn !== '') {
            $lama = one('SELECT * FROM siswa WHERE sekolah_id = ? AND nisn = ?', [$sid, $nisn]);
        }
        if (!$lama) {
            $lama = one('SELECT * FROM siswa WHERE sekolah_id = ? AND LOWER(nama) = ?', [$sid, bawah($nama)]);
        }

        if ($lama) {
            $swId = (int) $lama['id'];
            $waliAkhir = $wali !== '' ? $wali : (string) ($lama['wali'] ?? '');
            if ($waliAkhir === '' && $ayah !== '') {
                $waliAkhir = $ayah;
            }
            q('UPDATE siswa SET nama=?, nis=?, nisn=?, jenis_kelamin=?, tempat_lahir=?, tanggal_lahir=?,
                    kelas_id=?, wali=?, telp=?, alamat=?, nama_ayah=?, nama_ibu=?, status=?, aktif=?
               WHERE id=? AND sekolah_id=?', [
                $nama,
                $nis !== '' ? $nis : ($lama['nis'] ?? null),
                $nisn !== '' ? $nisn : ($lama['nisn'] ?? null),
                $jk !== '' ? $jk : ($lama['jenis_kelamin'] ?? null),
                $tempat !== '' ? $tempat : ($lama['tempat_lahir'] ?? null),
                $tgl !== '' ? $tgl : ($lama['tanggal_lahir'] ?? null),
                $kelasId > 0 ? $kelasId : ($lama['kelas_id'] ?? null),
                $waliAkhir,
                $telp !== '' ? $telp : ($lama['telp'] ?? null),
                $alamat !== '' ? $alamat : ($lama['alamat'] ?? null),
                $ayah !== '' ? $ayah : ($lama['nama_ayah'] ?? null),
                $ibu !== '' ? $ibu : ($lama['nama_ibu'] ?? null),
                $status, $status === 'aktif' ? 1 : 0,
                $swId, $sid,
            ]);
            $hasil['siswa_diperbarui']++;
        } else {
            q('INSERT INTO siswa (sekolah_id, nis, nisn, nama, jenis_kelamin, tempat_lahir, tanggal_lahir,
                    kelas_id, wali, telp, alamat, nama_ayah, nama_ibu, status, aktif, dibuat)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
                $sid, $nis, $nisn, $nama, $jk, $tempat, $tgl !== '' ? $tgl : null,
                $kelasId > 0 ? $kelasId : null,
                $wali !== '' ? $wali : ($ayah !== '' ? $ayah : ''),
                $telp, $alamat, $ayah, $ibu, $status, $status === 'aktif' ? 1 : 0,
                $now,
            ]);
            $swId = (int) db()->lastInsertId();
            $hasil['siswa_baru']++;
        }
        $hasil['siswa_total']++;

        // Akun siswa: dibuat bila belum ada (kata sandi awal siswa123 — permintaan pemilik).
        if (!one('SELECT id FROM users WHERE siswa_id = ?', [$swId])) {
            $dasar = $nis !== '' ? $nis : ($nisn !== '' ? $nisn : username_dari_nama($nama));
            $uname = username_unik($dasar);
            q('INSERT INTO users (sekolah_id, nama, username, password, role, siswa_id, aktif, dibuat)
               VALUES (?,?,?,?,?,?,?,?)',
                [$sid, $nama, $uname, password_hash('siswa123', PASSWORD_DEFAULT), 'siswa', $swId,
                    $status === 'aktif' ? 1 : 0, $now]);
            $hasil['akun_siswa']++;
        }
    }

    // ---- 6. Catat jejak penarikan (dipakai halaman Tarik Data untuk keterangan
    //         "Sudah ditarik" / "Belum ditarik" — realtime, bukan tebakan dari jumlah data).
    q('UPDATE sekolah SET eguru_id = ?, eguru_tarik = ? WHERE id = ?', [$esid, $now, $sid]);
    $hasil['eguru_id'] = $esid;
    $hasil['ditarik_pada'] = $now;

    return $hasil;
}

/**
 * Jalankan penarikan dalam satu transaksi (begin immediate + rollback bila gagal),
 * supaya tidak ada data setengah masuk.
 */
function eguru_tarik_aman(): array
{
    try {
        db()->exec('BEGIN IMMEDIATE');
    } catch (Throwable $ex) {
        return ['galat' => 'Basis data sedang dipakai proses lain — coba lagi beberapa saat lagi.'];
    }
    try {
        $hasil = eguru_tarik();
        if (isset($hasil['galat'])) {
            db()->exec('ROLLBACK');
            return $hasil;
        }
        db()->exec('COMMIT');
        return $hasil;
    } catch (Throwable $ex) {
        db()->exec('ROLLBACK');
        return ['galat' => 'Penarikan data gagal di tengah proses — tidak ada data yang disimpan.'];
    }
}
