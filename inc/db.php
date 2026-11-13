<?php
/**
 * Lapisan database (SQLite via PDO) + skema, migrasi, dan data awal.
 *
 * Aplikasi ini MULTI SEKOLAH: hampir semua tabel operasional punya kolom `sekolah_id`
 * dan setiap query WAJIB dibatasi ke sekolah yang sedang aktif (termasuk saat mengambil
 * satu baris berdasarkan id — anti-IDOR).
 *
 * ensure_schema() berjalan pada setiap request. Seluruh isinya dibungkus SATU transaksi
 * (BEGIN IMMEDIATE) supaya SQLite tidak melakukan fsync per statement.
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
// rapikan_kelas() memakai kelas_nama_rapi()/kolom_kunci() dari helpers.php, jadi modul itu
// ikut dimuat agar db.php tetap dapat dijalankan sendiri (tanpa init.php).
require_once __DIR__ . '/helpers.php';
// v1.14.0: modul halaman depan (`inc/landing.php`) DIHAPUS bersama landing page & pendaftaran
// sekolah (permintaan pemilik), jadi tidak ada lagi yang dimuat di sini.

/** Naikkan angka ini setiap kali skema berubah agar migrasi ikut dijalankan. */
const SCHEMA_VERSION = '8';

/**
 * Penjaga "penanda skema bisa lebih baru daripada isi basis data".
 * Setiap kolom/tabel baru WAJIB didaftarkan di sini supaya basis data yang tertinggal
 * dapat memulihkan diri sendiri (pernah terjadi di aplikasi lain: migrasi tidak pernah
 * jalan lagi karena penanda sudah lebih baru, lalu penyimpanan gagal tanpa jejak).
 */
const SCHEMA_TABEL_WAJIB = [
    'sekolah', 'users', 'app_pengaturan', 'kelas', 'mapel', 'guru', 'guru_mapel', 'guru_kelas',
    'siswa', 'ujian', 'ujian_kelas', 'soal', 'sesi_ujian', 'jawaban',
];

const SCHEMA_KOLOM_WAJIB = [
    'sekolah' => ['jenjang', 'aktivasi', 'aktif_sampai', 'aktivasi_ke', 'logo', 'tahun_ajaran', 'semester', 'kkm',
        'eguru_id', 'eguru_tarik'],
    'users'   => ['sekolah_id', 'role', 'guru_id', 'siswa_id', 'foto', 'aktif', 'sandi_eguru'],
    'guru'    => ['sekolah_id', 'aktif', 'nuptk', 'jabatan'],
    'siswa'   => ['sekolah_id', 'kelas_id', 'aktif', 'nama_ayah', 'nama_ibu', 'status', 'tanggal_status'],
    'mapel'   => ['kktp'],
    'ujian'   => ['kktp', 'token', 'token_dibuat'],
    'soal'    => ['ujian_id', 'jenis', 'opsi', 'jawaban', 'skor', 'gambar'],
    'sesi_ujian' => ['pelanggaran', 'status', 'nilai'],
];

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dir = dirname(DB_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = NORMAL');

    ensure_schema($pdo);

    $pdo->exec('PRAGMA foreign_keys = ON');
    return $pdo;
}

/** Jalankan query dengan prepared statement. */
function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function val(string $sql, array $params = [], $default = null)
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? $default : $v;
}

/** Nama tabel yang ada di basis data. */
function tabel_ada(PDO $pdo): array
{
    $out = [];
    foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll() as $r) {
        $out[] = (string) $r['name'];
    }
    return $out;
}

/** Nama kolom sebuah tabel. */
function kolom_tabel(PDO $pdo, string $tabel): array
{
    $out = [];
    try {
        foreach ($pdo->query('PRAGMA table_info(' . $tabel . ')')->fetchAll() as $r) {
            $out[] = (string) $r['name'];
        }
    } catch (Throwable $e) {
        return [];
    }
    return $out;
}

/** Tambah kolom bila belum ada (tanpa membangun ulang tabel). */
function db_add_column(PDO $pdo, string $tabel, string $kolom, string $definisi): void
{
    if (in_array($kolom, kolom_tabel($pdo, $tabel), true)) {
        return;
    }
    if (!in_array($tabel, tabel_ada($pdo), true)) {
        return;
    }
    $pdo->exec('ALTER TABLE ' . $tabel . ' ADD COLUMN ' . $kolom . ' ' . $definisi);
}

/** Apakah penanda skema DAN seluruh tabel/kolom wajib sudah benar-benar ada. */
function skema_terkini(PDO $pdo): bool
{
    $tabel = tabel_ada($pdo);
    foreach (SCHEMA_TABEL_WAJIB as $t) {
        if (!in_array($t, $tabel, true)) {
            return false;
        }
    }
    foreach (SCHEMA_KOLOM_WAJIB as $t => $kolom) {
        if (!in_array($t, $tabel, true)) {
            return false;
        }
        $punya = kolom_tabel($pdo, $t);
        foreach ((array) $kolom as $k) {
            if (!in_array($k, $punya, true)) {
                return false;
            }
        }
    }
    return true;
}

function penanda_file(): string
{
    return dirname(DB_FILE) . '/.schema_ok';
}

function ensure_schema(PDO $pdo): void
{
    $tanda = is_file(penanda_file()) ? trim((string) @file_get_contents(penanda_file())) : '';
    if ($tanda === SCHEMA_VERSION && skema_terkini($pdo)) {
        return;
    }

    $pdo->exec('PRAGMA foreign_keys = OFF');
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        buat_tabel($pdo);
        migrasi($pdo);
        isi_awal($pdo);
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
    $pdo->exec('PRAGMA foreign_keys = ON');

    @file_put_contents(penanda_file(), SCHEMA_VERSION);
}

function buat_tabel(PDO $pdo): void
{
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS sekolah (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nama TEXT NOT NULL,
    jenjang TEXT NOT NULL DEFAULT 'SMP/MTs',
    npsn TEXT,
    alamat TEXT,
    desa TEXT,
    kecamatan TEXT,
    kabupaten TEXT,
    provinsi TEXT,
    telepon TEXT,
    email TEXT,
    website TEXT,
    kepala TEXT,
    nip_kepala TEXT,
    logo TEXT,
    tahun_ajaran TEXT NOT NULL DEFAULT '',
    semester TEXT NOT NULL DEFAULT 'Ganjil',
    kkm REAL NOT NULL DEFAULT 75,
    verifikasi TEXT NOT NULL DEFAULT 'disetujui',
    aktif INTEGER NOT NULL DEFAULT 1,
    tanggal_daftar TEXT,
    alasan_tolak TEXT,
    aktivasi TEXT NOT NULL DEFAULT 'percobaan',
    aktif_sampai TEXT,
    aktivasi_pada TEXT,
    aktivasi_ke INTEGER NOT NULL DEFAULT 1,
    eguru_id INTEGER,
    eguru_tarik TEXT,
    dibuat TEXT
)
SQL);

    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sekolah_id INTEGER REFERENCES sekolah(id) ON DELETE CASCADE,
    nama TEXT NOT NULL,
    username TEXT NOT NULL UNIQUE,
    password TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'siswa',
    guru_id INTEGER,
    siswa_id INTEGER,
    foto TEXT,
    aktif INTEGER NOT NULL DEFAULT 1,
    dibuat TEXT,
    masuk_terakhir TEXT,
    sandi_eguru TEXT
)
SQL);

    $pdo->exec('CREATE TABLE IF NOT EXISTS app_pengaturan (kunci TEXT PRIMARY KEY, nilai TEXT)');

    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS kelas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sekolah_id INTEGER NOT NULL REFERENCES sekolah(id) ON DELETE CASCADE,
    nama TEXT NOT NULL,
    tingkat TEXT,
    wali_id INTEGER,
    urutan INTEGER NOT NULL DEFAULT 0,
    dibuat TEXT,
    UNIQUE (sekolah_id, nama)
)
SQL);

    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS mapel (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sekolah_id INTEGER NOT NULL REFERENCES sekolah(id) ON DELETE CASCADE,
    kode TEXT,
    nama TEXT NOT NULL,
    kktp REAL,
    urutan INTEGER NOT NULL DEFAULT 0,
    dibuat TEXT,
    UNIQUE (sekolah_id, nama)
)
SQL);

    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS guru (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sekolah_id INTEGER NOT NULL REFERENCES sekolah(id) ON DELETE CASCADE,
    nip TEXT,
    nuptk TEXT,
    nama TEXT NOT NULL,
    jenis_kelamin TEXT,
    jabatan TEXT,
    telp TEXT,
    email TEXT,
    aktif INTEGER NOT NULL DEFAULT 1,
    dibuat TEXT
)
SQL);

    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS guru_mapel (
    guru_id INTEGER NOT NULL REFERENCES guru(id) ON DELETE CASCADE,
    mapel_id INTEGER NOT NULL REFERENCES mapel(id) ON DELETE CASCADE,
    PRIMARY KEY (guru_id, mapel_id)
)
SQL);

    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS guru_kelas (
    guru_id INTEGER NOT NULL REFERENCES guru(id) ON DELETE CASCADE,
    kelas_id INTEGER NOT NULL REFERENCES kelas(id) ON DELETE CASCADE,
    PRIMARY KEY (guru_id, kelas_id)
)
SQL);

    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS siswa (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sekolah_id INTEGER NOT NULL REFERENCES sekolah(id) ON DELETE CASCADE,
    nis TEXT,
    nisn TEXT,
    nama TEXT NOT NULL,
    jenis_kelamin TEXT,
    tempat_lahir TEXT,
    tanggal_lahir TEXT,
    kelas_id INTEGER REFERENCES kelas(id) ON DELETE SET NULL,
    wali TEXT,
    telp TEXT,
    alamat TEXT,
    nama_ayah TEXT,
    nama_ibu TEXT,
    status TEXT NOT NULL DEFAULT 'aktif',
    tanggal_status TEXT,
    foto TEXT,
    aktif INTEGER NOT NULL DEFAULT 1,
    dibuat TEXT
)
SQL);

    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS ujian (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sekolah_id INTEGER NOT NULL REFERENCES sekolah(id) ON DELETE CASCADE,
    mapel_id INTEGER REFERENCES mapel(id) ON DELETE SET NULL,
    guru_id INTEGER REFERENCES guru(id) ON DELETE SET NULL,
    judul TEXT NOT NULL,
    keterangan TEXT,
    jenis_ujian TEXT NOT NULL DEFAULT 'Ulangan Harian',
    durasi INTEGER NOT NULL DEFAULT 60,
    kktp REAL,
    acak_soal INTEGER NOT NULL DEFAULT 0,
    acak_opsi INTEGER NOT NULL DEFAULT 0,
    tampilkan_hasil INTEGER NOT NULL DEFAULT 1,
    status TEXT NOT NULL DEFAULT 'draft',
    mulai TEXT,
    selesai TEXT,
    token TEXT,
    token_dibuat TEXT,
    dibuat TEXT,
    diubah TEXT
)
SQL);

    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS ujian_kelas (
    ujian_id INTEGER NOT NULL REFERENCES ujian(id) ON DELETE CASCADE,
    kelas_id INTEGER NOT NULL REFERENCES kelas(id) ON DELETE CASCADE,
    PRIMARY KEY (ujian_id, kelas_id)
)
SQL);

    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS soal (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sekolah_id INTEGER NOT NULL REFERENCES sekolah(id) ON DELETE CASCADE,
    ujian_id INTEGER NOT NULL REFERENCES ujian(id) ON DELETE CASCADE,
    jenis TEXT NOT NULL DEFAULT 'pg',
    pertanyaan TEXT NOT NULL,
    opsi TEXT,
    jawaban TEXT,
    skor REAL NOT NULL DEFAULT 1,
    pembahasan TEXT,
    gambar TEXT,
    urutan INTEGER NOT NULL DEFAULT 0,
    dibuat TEXT
)
SQL);

    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS sesi_ujian (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sekolah_id INTEGER NOT NULL REFERENCES sekolah(id) ON DELETE CASCADE,
    ujian_id INTEGER NOT NULL REFERENCES ujian(id) ON DELETE CASCADE,
    siswa_id INTEGER NOT NULL REFERENCES siswa(id) ON DELETE CASCADE,
    mulai TEXT,
    selesai TEXT,
    status TEXT NOT NULL DEFAULT 'mengerjakan',
    pelanggaran INTEGER NOT NULL DEFAULT 0,
    nilai REAL,
    benar INTEGER NOT NULL DEFAULT 0,
    salah INTEGER NOT NULL DEFAULT 0,
    kosong INTEGER NOT NULL DEFAULT 0,
    perlu_koreksi INTEGER NOT NULL DEFAULT 0,
    dikoreksi_oleh INTEGER,
    ip TEXT,
    peramban TEXT
)
SQL);

    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS jawaban (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sesi_id INTEGER NOT NULL REFERENCES sesi_ujian(id) ON DELETE CASCADE,
    soal_id INTEGER NOT NULL REFERENCES soal(id) ON DELETE CASCADE,
    jawaban TEXT,
    benar INTEGER,
    skor REAL,
    dijawab TEXT,
    manual INTEGER NOT NULL DEFAULT 0,
    UNIQUE (sesi_id, soal_id)
)
SQL);

    // v1.14.0: tabel halaman depan (landing_fitur/unggulan/testimoni/pesan) TIDAK lagi dibuat —
    // fitur landing page & pendaftaran sekolah dihapus atas permintaan pemilik. Tabel lama
    // yang sudah telanjur ada pada basis data sebelumnya dibiarkan (tidak pernah dibaca).

    // Indeks
    foreach ([
        'CREATE INDEX IF NOT EXISTS idx_users_sekolah ON users (sekolah_id, role)',
        'CREATE INDEX IF NOT EXISTS idx_guru_sekolah ON guru (sekolah_id, aktif)',
        'CREATE INDEX IF NOT EXISTS idx_siswa_sekolah ON siswa (sekolah_id, kelas_id, aktif)',
        'CREATE INDEX IF NOT EXISTS idx_kelas_sekolah ON kelas (sekolah_id, urutan)',
        'CREATE INDEX IF NOT EXISTS idx_mapel_sekolah ON mapel (sekolah_id, urutan)',
        'CREATE INDEX IF NOT EXISTS idx_ujian_sekolah ON ujian (sekolah_id, status)',
        'CREATE INDEX IF NOT EXISTS idx_soal_ujian ON soal (ujian_id, urutan)',
        'CREATE INDEX IF NOT EXISTS idx_sesi_ujian ON sesi_ujian (ujian_id, siswa_id, status)',
        'CREATE INDEX IF NOT EXISTS idx_jawaban_sesi ON jawaban (sesi_id)',
    ] as $sql) {
        $pdo->exec($sql);
    }
}

function migrasi(PDO $pdo): void
{
    // ---------------------------------------------------------------- v2: selaras dengan e-GURU
    // Tujuan (permintaan pemilik): "Input guru dan siswa samakan dengan aplikasi e-guru,
    // sehingga ekspor dari e-guru bisa diimpor ke aplikasi CBT."
    // Hanya MENAMBAH kolom — tidak ada tabel yang dibangun ulang; data lama tetap utuh.
    db_add_column($pdo, 'guru', 'nuptk', 'TEXT');
    db_add_column($pdo, 'guru', 'jabatan', 'TEXT');
    db_add_column($pdo, 'siswa', 'nama_ayah', 'TEXT');
    db_add_column($pdo, 'siswa', 'nama_ibu', 'TEXT');
    db_add_column($pdo, 'siswa', 'status', "TEXT NOT NULL DEFAULT 'aktif'");
    db_add_column($pdo, 'siswa', 'tanggal_status', 'TEXT');

    // ---------------------------------------------------------------- v3: KKTP + nama kelas rapi
    // KKTP (Kriteria Ketercapaian Tujuan Pembelajaran) per MATA PELAJARAN dan per UJIAN
    // — permintaan pemilik: "Ketika guru membuat soal tambahkan fitur untuk mengatur KKTP
    // setiap mata pelajaran yang akan diaktifkan." Nilai kosong (NULL) berarti memakai KKM sekolah.
    db_add_column($pdo, 'mapel', 'kktp', 'REAL');
    db_add_column($pdo, 'ujian', 'kktp', 'REAL');

    // ---------------------------------------------------------------- v4: soal bergambar + token + jadwal
    // Permintaan pemilik: "Tambahkan fitur untuk membuat soal bergambar pada akun guru" dan
    // "Pada akun operator tambahkan fitur untuk mengaktifkan soal sesuai jadwal asesmen/ujian
    //  dan tambahkan generate token (per mata pelajaran yang akan diujikan)".
    // `soal.gambar` menyimpan URL CDN hasil media proxy (bukan berkas di server aplikasi).
    // `ujian.token` = token yang harus diisi siswa sebelum mengerjakan (kosong = tanpa token);
    // `ujian.mulai`/`selesai` (sudah ada sejak v1) dipakai sebagai jendela jadwal asesmen.
    db_add_column($pdo, 'soal', 'gambar', 'TEXT');
    db_add_column($pdo, 'ujian', 'token', 'TEXT');
    db_add_column($pdo, 'ujian', 'token_dibuat', 'TEXT');

    // Nama kelas dirapikan: buang kata "Kelas" + angka Arab -> Romawi (I..XII), dan kelas
    // yang setelah dirapikan menjadi SAMA digabungkan (siswa/ujian/guru dipindahkan) supaya
    // tidak ada dua kelas bernama sama. Fungsi ini idempoten.
    rapikan_kelas($pdo);

    // Kolom baru berikutnya ditambahkan lewat db_add_column() DI SINI dan didaftarkan juga
    // pada SCHEMA_KOLOM_WAJIB + definisi CREATE TABLE di atas.
    db_add_column($pdo, 'siswa', 'foto', 'TEXT');
    db_add_column($pdo, 'guru', 'foto', 'TEXT');

    // ---------------------------------------------------------------- v5: HALAMAN DEPAN (LANDING)
    // CATATAN v1.14.0: fitur landing page & pendaftaran sekolah DIHAPUS atas permintaan pemilik
    // ("semua data akan ditarik dari aplikasi e-Guru, yang ditampilkan hanya halaman login").
    // Tabel `landing_*` tidak lagi dibuat/diisi; pada basis data lama tabelnya dibiarkan apa
    // adanya (tidak pernah dibaca lagi) supaya tidak ada operasi destruktif pada data.

    // ---------------------------------------------------------------- v6→v7: PELACAK TARIK DATA e-GURU
    // Permintaan pemilik: "Pada halaman super admin bagian tarik data dari e-Guru pastikan
    // menampilkan semua sekolah yang ada di dalam aplikasi e-guru (realtime termasuk yang baru
    // mendaftar). Tambahkan keterangan yang sudah ditarik datanya dengan yang belum."
    //
    // `eguru_id`   = id lembaga e-GURU yang datanya pernah ditarik ke sekolah ini
    // `eguru_tarik`= waktu terakhir penarikan berhasil (kosong = belum pernah ditarik)
    // Hanya MENAMBAH kolom (tanpa membangun ulang tabel); sekolah lama bernilai NULL sehingga
    // tampil sebagai "Belum ditarik" — tidak ada nilai yang ditebak.
    db_add_column($pdo, 'sekolah', 'eguru_id', 'INTEGER');
    db_add_column($pdo, 'sekolah', 'eguru_tarik', 'TEXT');

    // ---------------------------------------------------------------- v8: PENANDA SANDI e-GURU
    // Permintaan pemilik (v1.20.0): *"pastikan data guru yang masuk ke CBT nama pengguna dan
    // sandinya sama dengan akun mereka di e-Guru"*.
    //
    // `users.sandi_eguru` = hash kata sandi e-GURU yang TERAKHIR disalin ke akun ini. Selama
    // `users.password` masih sama dengan nilai ini, sandinya belum pernah diganti di CBT sehingga
    // penarikan ulang boleh menyamakannya lagi bila sandi e-GURU berubah (mis. operator e-GURU
    // mereset sandi guru). Begitu sandinya diganti sendiri (menu Akun Saya) atau direset Admin CBT,
    // hash-nya berbeda → sandi pilihan pengguna TIDAK PERNAH ditimpa. NULL/kosong = akun lama yang
    // belum punya penanda (memakai aturan lama: hanya disamakan bila masih sandi awal guru123).
    db_add_column($pdo, 'users', 'sandi_eguru', 'TEXT');
}

/**
 * Rapikan nama kelas pada SELURUH sekolah:
 *  - "Kelas X" -> "X", "kelas 7A" -> "VII-A" (lihat kelas_nama_rapi()).
 *  - Bila hasil rapikan sama dengan kelas lain di sekolah yang sama, kelas itu DIGABUNG:
 *    siswa, guru_kelas, dan ujian_kelas dipindahkan ke kelas tujuan lalu kelas kembar dihapus.
 *  - Nama yang menjadi kosong (mis. hanya "Kelas") DIBIARKAN apa adanya.
 * Idempoten: dijalankan ulang tidak mengubah apa pun lagi.
 */
function rapikan_kelas(PDO $pdo): void
{
    $kelas = $pdo->query('SELECT id, sekolah_id, nama FROM kelas ORDER BY id')->fetchAll();
    $adaNama = [];
    foreach ($kelas as $k) {
        $adaNama[(int) $k['sekolah_id']][kolom_kunci((string) $k['nama'])] = (int) $k['id'];
    }
    foreach ($kelas as $k) {
        $id = (int) $k['id'];
        $sid = (int) $k['sekolah_id'];
        $rapi = kelas_nama_rapi((string) $k['nama']);
        if ($rapi === '' || $rapi === (string) $k['nama']) {
            continue;
        }
        $kunci = kolom_kunci($rapi);
        $tujuan = $adaNama[$sid][$kunci] ?? 0;
        if ($tujuan > 0 && $tujuan !== $id) {
            // Kembar -> gabungkan ke kelas tujuan
            $pdo->prepare('UPDATE siswa SET kelas_id = ? WHERE kelas_id = ?')->execute([$tujuan, $id]);
            $pdo->prepare('UPDATE OR IGNORE guru_kelas SET kelas_id = ? WHERE kelas_id = ?')->execute([$tujuan, $id]);
            $pdo->prepare('DELETE FROM guru_kelas WHERE kelas_id = ?')->execute([$id]);
            $pdo->prepare('UPDATE OR IGNORE ujian_kelas SET kelas_id = ? WHERE kelas_id = ?')->execute([$tujuan, $id]);
            $pdo->prepare('DELETE FROM ujian_kelas WHERE kelas_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM kelas WHERE id = ?')->execute([$id]);
            continue;
        }
        $pdo->prepare('UPDATE kelas SET nama = ? WHERE id = ?')->execute([$rapi, $id]);
        $adaNama[$sid][$kunci] = $id;
    }

    // ---------------------------------------------------------------- v6: MASA AKTIF 365 HARI
    // Permintaan pemilik: *"Sekolah yang ditarik oleh super admin ... aktif 365 hari (1 tahun)
    // bukan permanen. Di dashboard tertulis permanen harusnya 365 hari (dan hitung mundur)."*
    //
    // Sekolah yang MASIH berstatus 'permanen' — dibuat sebelum aturan ini berlaku (mis. sekolah
    // hasil TARIK DATA LEMBAGA dari e-GURU) — diberi masa aktif 365 hari DIHITUNG DARI HARI INI.
    // Dijalankan SEKALI SAJA (dijaga penanda `aktivasi_masa_aktif_365`) supaya sekolah yang kelak
    // sengaja dijadikan permanen oleh Super Admin lewat kartu "Aktivasi & Kode Lembaga" TIDAK
    // ikut diubah lagi pada permintaan-permintaan berikutnya.
    // Pola ini sama dengan aplikasi e-GURU (konversi sekali sekolah lama berstatus permanen).
    if (app_setting('aktivasi_masa_aktif_365', '') !== '1') {
        foreach ($pdo->query("SELECT id FROM sekolah WHERE aktivasi = 'permanen'")->fetchAll(PDO::FETCH_COLUMN) as $sid) {
            aktivasi_mulai_uji_coba((int) $sid, AKTIVASI_HARI_AKTIF);
        }
        app_setting_set('aktivasi_masa_aktif_365', '1');
    }
}

function isi_awal(PDO $pdo): void
{
    // Akun Super Admin bawaan (hanya dibuat sekali, saat belum ada satu pun akun).
    $jumlah = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($jumlah === 0) {
        $pdo->prepare('INSERT INTO users (sekolah_id, nama, username, password, role, aktif, dibuat)
                       VALUES (NULL, ?, ?, ?, ?, 1, ?)')
            ->execute(['Super Admin', 'admin', password_hash('admin123', PASSWORD_DEFAULT), 'super', date('Y-m-d H:i:s')]);
    }

    // v1.14.0: isi bawaan halaman depan (landing) TIDAK lagi disemai — halaman landing page
    // sudah dihapus atas permintaan pemilik.
}

/**
 * Bahan menu/mapel bawaan per jenjang (dipakai saat sekolah baru didaftarkan).
 * Sekolah tetap dapat menambah/mengubah/menghapus mata pelajaran sendiri.
 */
function mapel_bawaan(string $jenjang): array
{
    $umum = [
        ['PABP', 'Pendidikan Agama dan Budi Pekerti'],
        ['PPKn', 'Pendidikan Pancasila dan Kewarganegaraan'],
        ['BIN', 'Bahasa Indonesia'],
        ['MTK', 'Matematika'],
        ['BIG', 'Bahasa Inggris'],
        ['PJOK', 'Pendidikan Jasmani, Olahraga dan Kesehatan'],
        ['SBdP', 'Seni Budaya dan Prakarya'],
    ];
    if (str_starts_with($jenjang, 'SD')) {
        return array_merge($umum, [
            ['IPA', 'Ilmu Pengetahuan Alam'],
            ['IPS', 'Ilmu Pengetahuan Sosial'],
            ['BJW', 'Bahasa Jawa / Muatan Lokal'],
        ]);
    }
    if (str_starts_with($jenjang, 'SMP')) {
        return array_merge($umum, [
            ['IPA', 'Ilmu Pengetahuan Alam'],
            ['IPS', 'Ilmu Pengetahuan Sosial'],
            ['INF', 'Informatika'],
            ['BAR', 'Bahasa Arab'],
        ]);
    }
    return array_merge($umum, [
        ['FI', 'Fisika'],
        ['KI', 'Kimia'],
        ['BI', 'Biologi'],
        ['SJI', 'Sejarah Indonesia'],
        ['GEO', 'Geografi'],
        ['EKO', 'Ekonomi'],
        ['SOS', 'Sosiologi'],
        ['INF', 'Informatika'],
    ]);
}

/** Siapkan data awal sebuah sekolah baru (mata pelajaran + tahun ajaran + semester). */
function provision_sekolah(int $sekolahId): void
{
    if ($sekolahId <= 0) {
        return;
    }
    $s = one('SELECT id, jenjang, tahun_ajaran FROM sekolah WHERE id = ?', [$sekolahId]);
    if (!$s) {
        return;
    }
    if ((string) $s['tahun_ajaran'] === '') {
        $y = (int) date('Y');
        $m = (int) date('n');
        $ta = ($m >= 7) ? ($y . '/' . ($y + 1)) : (($y - 1) . '/' . $y);
        q('UPDATE sekolah SET tahun_ajaran = ?, semester = ? WHERE id = ?',
            [$ta, ($m >= 7 ? 'Ganjil' : 'Genap'), $sekolahId]);
    }
    $ada = (int) val('SELECT COUNT(*) FROM mapel WHERE sekolah_id = ?', [$sekolahId], 0);
    if ($ada === 0) {
        $u = 10;
        foreach (mapel_bawaan((string) $s['jenjang']) as $m) {
            try {
                q('INSERT INTO mapel (sekolah_id, kode, nama, urutan, dibuat) VALUES (?,?,?,?,?)',
                    [$sekolahId, $m[0], $m[1], $u, date('Y-m-d H:i:s')]);
                $u += 10;
            } catch (Throwable $e) {
                // nama sudah ada — abaikan
            }
        }
    }
}
