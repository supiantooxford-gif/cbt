<?php
/**
 * Impor Data Guru dari berkas Excel/CSV.
 *
 * Kolom yang diterima SAMA dengan EKSPOR aplikasi e-GURU, sehingga berkas
 * "Ekspor Data Guru" dari e-GURU dapat langsung diimpor ke sini:
 *   Nama · Jenis Anggota · NIP/NUPTK · Jenis Kelamin · Jabatan · Mata Pelajaran ·
 *   Kelas Diajar · Tugas Tambahan · Telepon · Email · Akun (Nama Pengguna) · Status · Tanggal Daftar
 *
 * Berkas dibaca di PERAMBAN (SheetJS) lalu dikirim sebagai JSON; server memeriksa ulang setiap baris.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

require_operator();

const KOLOM_GURU = ['Nama', 'Jenis Anggota', 'NIP/NUPTK', 'Jenis Kelamin', 'Jabatan', 'Mata Pelajaran',
    'Kelas Diajar', 'Tugas Tambahan', 'Telepon', 'Email', 'Akun (Nama Pengguna)', 'Status', 'Tanggal Daftar'];

/** Peta judul kolom (dinormalkan) -> kunci internal. Judul yang tidak dikenal diabaikan. */
function guru_alias_kolom(): array
{
    return [
        'nama' => 'nama', 'namaguru' => 'nama', 'namalengkap' => 'nama',
        'jenisanggota' => 'anggota',
        'nip' => 'nip', 'nipptk' => 'nip', 'nipnuptk' => 'nip', 'nipnik' => 'nip',
        'nuptk' => 'nuptk',
        'jeniskelamin' => 'jk', 'jk' => 'jk', 'gender' => 'jk',
        'jabatan' => 'jabatan', 'jabatanpegawai' => 'jabatan',
        'mapel' => 'mapel', 'matapelajaran' => 'mapel', 'pelajaran' => 'mapel',
        'kelas' => 'kelas', 'kelasdiajar' => 'kelas', 'rombel' => 'kelas', 'kelasdiampu' => 'kelas',
        'tugastambahan' => 'tugas', 'tugas' => 'tugas',
        'telepon' => 'telp', 'telp' => 'telp', 'hp' => 'telp', 'nowa' => 'telp', 'nohp' => 'telp',
        'email' => 'email', 'surel' => 'email',
        'akun' => 'akun', 'akunlogin' => 'akun', 'akunnamapengguna' => 'akun',
        'namapengguna' => 'akun', 'username' => 'akun', 'pengguna' => 'akun',
        'status' => 'status', 'aktif' => 'status',
        'tanggaldaftar' => 'dibuat', 'tgl' => 'dibuat', 'tglmulai' => 'dibuat',
    ];
}

$hasil = null;
$galat = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $payload = json_decode((string) ($_POST['payload'] ?? ''), true);
    if (!is_array($payload) || empty($payload['baris']) || !is_array($payload['baris'])) {
        $galat = 'Berkas belum dibaca dengan benar. Pilih berkas Excel/CSV lalu kirim kembali.';
    } else {
        $judul = isset($payload['judul']) && is_array($payload['judul']) ? $payload['judul'] : [];
        $alias = guru_alias_kolom();
        $peta = [];
        foreach ($judul as $i => $j) {
            $k = kolom_kunci((string) $j);
            if (isset($alias[$k]) && !isset($peta[$alias[$k]])) {
                $peta[$alias[$k]] = (int) $i;
            }
        }
        // Berkas tanpa baris judul dibaca berurutan sesuai susunan kolom ekspor e-GURU.
        if (!$peta) {
            $peta = ['nama' => 0, 'anggota' => 1, 'nip' => 2, 'jk' => 3, 'jabatan' => 4, 'mapel' => 5,
                'kelas' => 6, 'tugas' => 7, 'telp' => 8, 'email' => 9, 'akun' => 10, 'status' => 11, 'dibuat' => 12];
        }

        $ambil = fn(array $row, string $key): string => impor_ambil($row, $peta, $key);
        // Apakah sebuah kolom BENAR-BENAR ada di berkas? Kolom yang tidak ada tidak boleh
        // mengosongkan data lama (ekspor e-GURU tidak memuat semua kolom CBT).
        $ada = fn(string $key): bool => isset($peta[$key]);

        $masuk = 0;
        $lewat = 0;
        $mapelBaru = 0;
        $kelasBaru = [];
        $anggotaTakCocok = 0;
        $urutMapel = (int) val('SELECT COALESCE(MAX(urutan),0) FROM mapel WHERE sekolah_id = ?', [sekolah_id()], 0);

        $baris = array_slice((array) $payload['baris'], 0, IMPOR_MAKS_BARIS);
        db()->exec('BEGIN IMMEDIATE');
        try {
            foreach ($baris as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $nama = teks($ambil($row, 'nama'), 120);
                if ($nama === '') {
                    $lewat++;
                    continue;
                }
                // Berkas siswa yang salah diimpor ke sini: dilewati agar tidak menjadi data guru.
                if ($ada('anggota') && bawah($ambil($row, 'anggota')) === 'siswa') {
                    $anggotaTakCocok++;
                    continue;
                }
                $nip = nip_bersih($ambil($row, 'nip'));
                $nuptk = nip_bersih($ambil($row, 'nuptk'));
                $jkRaw = bawah($ambil($row, 'jk'));
                $jk = (str_starts_with($jkRaw, 'p') || $jkRaw === 'perempuan') ? 'P' : 'L';
                $jabatan = teks($ambil($row, 'jabatan'), 80);
                $telp = teks($ambil($row, 'telp'), 30);
                $email = teks($ambil($row, 'email'), 120);
                $status = guru_status_dari_teks($ambil($row, 'status'));
                $tglDaftar = tgl_impor($ambil($row, 'dibuat'));

                $lama = $nip !== ''
                    ? one('SELECT * FROM guru WHERE sekolah_id = ? AND nip = ?', [sekolah_id(), $nip])
                    : one('SELECT * FROM guru WHERE sekolah_id = ? AND LOWER(nama) = ?', [sekolah_id(), bawah($nama)]);
                if ($lama) {
                    $gid = (int) $lama['id'];
                    // Nilai lama dipakai bila kolomnya tidak ada di berkas (ekspor e-GURU tidak
                    // memuat kolom yang tidak dipakai, jadi jangan dikosongkan tanpa alasan).
                    q('UPDATE guru SET nama=?, nip=?, nuptk=?, jenis_kelamin=?, jabatan=?, telp=?, email=?, aktif=? WHERE id=?', [
                        $nama,
                        $nip !== '' ? $nip : ($lama['nip'] ?? null),
                        $nuptk !== '' ? $nuptk : ($ada('nuptk') ? null : ($lama['nuptk'] ?? null)),
                        $ada('jk') ? $jk : ($lama['jenis_kelamin'] ?? null),
                        $ada('jabatan') ? $jabatan : ($lama['jabatan'] ?? null),
                        $ada('telp') ? $telp : ($lama['telp'] ?? null),
                        $ada('email') ? $email : ($lama['email'] ?? null),
                        $ada('status') ? $status : (int) $lama['aktif'],
                        $gid,
                    ]);
                } else {
                    q('INSERT INTO guru (sekolah_id, nip, nuptk, nama, jenis_kelamin, jabatan, telp, email, aktif, dibuat)
                       VALUES (?,?,?,?,?,?,?,?,?,?)', [
                        sekolah_id(), $nip !== '' ? $nip : null, $nuptk !== '' ? $nuptk : null, $nama,
                        $jk, $jabatan, $telp, $email, $status,
                        $tglDaftar !== '' ? $tglDaftar . ' 00:00:00' : date('Y-m-d H:i:s'),
                    ]);
                    $gid = (int) db()->lastInsertId();
                }

                // Mata pelajaran (nama dipisah koma/titik koma) — yang belum ada dibuatkan.
                if ($ada('mapel')) {
                    $mIds = [];
                    foreach (preg_split('/[,;|]+/', $ambil($row, 'mapel')) ?: [] as $mn) {
                        $mn = teks($mn, 100);
                        if ($mn === '') {
                            continue;
                        }
                        $mid = (int) val('SELECT id FROM mapel WHERE sekolah_id = ? AND LOWER(nama) = ?',
                            [sekolah_id(), bawah($mn)], 0);
                        if ($mid === 0) {
                            $urutMapel += 10;
                            q('INSERT INTO mapel (sekolah_id, kode, nama, urutan, dibuat) VALUES (?,?,?,?,?)',
                                [sekolah_id(), '', $mn, $urutMapel, date('Y-m-d H:i:s')]);
                            $mid = (int) db()->lastInsertId();
                            $mapelBaru++;
                        }
                        $mIds[] = $mid;
                    }
                    if ($mIds) {
                        guru_mapel_simpan($gid, $mIds);
                    }
                }

                // Kelas yang diajar — kelas yang belum ada DIBUAT supaya impor langsung jalan.
                if ($ada('kelas')) {
                    $kIds = [];
                    foreach (preg_split('/[,;|]+/', $ambil($row, 'kelas')) ?: [] as $kn) {
                        $kn = teks($kn, 60);
                        if ($kn === '') {
                            continue;
                        }
                        $kid = kelas_id_dari_nama($kn, $kelasBaru);
                        if ($kid > 0) {
                            $kIds[] = $kid;
                        }
                    }
                    if ($kIds) {
                        guru_kelas_simpan($gid, $kIds);
                    }
                }

                // Akun (bila kolomnya diisi)
                if ($ada('akun')) {
                    $akun = teks($ambil($row, 'akun'), 40);
                    if ($akun !== '' && !one('SELECT id FROM users WHERE guru_id = ?', [$gid])) {
                        if (!preg_match('/^[a-zA-Z0-9._]{3,30}$/', $akun)) {
                            $akun = username_dari_nama($nama);
                        }
                        $uname = username_unik($akun);
                        q('INSERT INTO users (sekolah_id, nama, username, password, role, guru_id, aktif, dibuat)
                           VALUES (?,?,?,?,?,?,1,?)',
                            [sekolah_id(), $nama, $uname, password_hash('guru123', PASSWORD_DEFAULT), 'guru', $gid, date('Y-m-d H:i:s')]);
                    }
                }
                $masuk++;
            }
            db()->exec('COMMIT');
        } catch (Throwable $ex) {
            db()->exec('ROLLBACK');
            $masuk = 0;
            $galat = 'Impor gagal di tengah proses — tidak ada data yang disimpan.';
        }
        $hasil = [
            'masuk' => $masuk, 'lewat' => $lewat, 'mapel_baru' => $mapelBaru,
            'kelas_baru' => array_keys($kelasBaru), 'anggota_tak_cocok' => $anggotaTakCocok,
            'tugas_ada' => $ada('tugas'), 'total' => count($baris),
        ];
    }
}

page_top('Impor Data Guru');
?>
<div class="grid g21">
  <div class="card">
    <div class="card-head"><h3>Unggah Berkas Data Guru</h3>
      <div class="kanan"><a class="btn btn-sm" href="template_impor.php?jenis=guru">Unduh Contoh Format</a></div>
    </div>
    <div class="card-body">
      <?php if ($galat !== ''): ?><div class="alert alert-err"><?= e($galat) ?></div><?php endif; ?>
      <?php if ($hasil): ?>
        <div class="alert alert-ok">
          <b><?= (int) $hasil['masuk'] ?> data guru</b> tersimpan dari <?= (int) $hasil['total'] ?> baris terbaca.
          <?php if ((int) $hasil['lewat'] > 0): ?><br><?= (int) $hasil['lewat'] ?> baris dilewati karena nama kosong.<?php endif; ?>
          <?php if ((int) $hasil['mapel_baru'] > 0): ?><br><?= (int) $hasil['mapel_baru'] ?> mata pelajaran baru dibuat otomatis.<?php endif; ?>
          <?php if ($hasil['kelas_baru']): ?><br><?= count($hasil['kelas_baru']) ?> kelas baru dibuat otomatis:
            <?= e(implode(', ', $hasil['kelas_baru'])) ?>.<?php endif; ?>
        </div>
      <?php endif; ?>
      <?php if ($hasil && (int) $hasil['anggota_tak_cocok'] > 0): ?>
        <div class="alert alert-warn"><b><?= (int) $hasil['anggota_tak_cocok'] ?> baris dilewati</b> karena
          kolom <b>Jenis Anggota</b>-nya berbunyi "Siswa" — sepertinya berkas Data <b>Siswa</b> yang
          diimpor ke Impor Data Guru. Gunakan halaman <b>Impor Data Siswa</b> untuk berkas itu.</div>
      <?php endif; ?>
      <?php if ($hasil && !empty($hasil['tugas_ada'])): ?>
        <div class="alert alert-info">Kolom <b>Tugas Tambahan</b> pada berkas diabaikan (aplikasi CBT belum
          memakai tugas tambahan). Data lain tetap diimpor.</div>
      <?php endif; ?>
      <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="payload" id="payloadImpor">
        <div class="field">
          <label for="fileImpor">Pilih Berkas (Excel .xlsx/.xls atau CSV)</label>
          <input type="file" id="fileImpor" name="berkas" accept=".xlsx,.xls,.csv"
                 data-kolom='<?= e(json_encode(KOLOM_GURU)) ?>'>
          <div class="ket" id="infoImpor">Kolom: <?= e(implode(' | ', KOLOM_GURU)) ?>. Kata sandi akun baru selalu <b>guru123</b>.</div>
        </div>
        <button class="btn btn-utama" type="submit" id="btnKirimImpor" disabled>Impor Sekarang</button>
      </form>
      <div id="pratinjauImpor" hidden class="card mt16"></div>
    </div>
  </div>
  <div class="card">
    <div class="card-head"><h3>Petunjuk</h3></div>
    <div class="card-body">
      <div class="alert alert-ok" style="margin-bottom:12px">
        Berkas <b>Ekspor Data Guru</b> dari aplikasi <b>e-GURU</b> dapat langsung diimpor di sini
        (susunan kolomnya sama).
      </div>
      <ul class="poin-list">
        <li>Baris pertama sebaiknya berisi <b>judul kolom</b> seperti pada contoh format.</li>
        <li>Kolom <b>Nama</b> wajib. NIP/NUPTK boleh dikosongkan.</li>
        <li><b>Mata Pelajaran</b> boleh memuat beberapa nama dipisah koma; nama yang belum ada dibuat otomatis.</li>
        <li><b>Kelas Diajar</b> boleh memuat beberapa kelas dipisah koma; kelas yang belum ada dibuat otomatis.</li>
        <li>Kolom <b>Akun</b> diisi bila guru langsung diberi akun login (kata sandi awal <b>guru123</b>).</li>
        <li>Kolom <b>Status</b> menerima Aktif / Nonaktif. <b>Tanggal Daftar</b> dibaca dalam format ISO
          (mis. 2026-10-04).</li>
        <li>Guru yang NIP/namanya sudah ada akan <b>diperbarui</b>, bukan diduplikasi. Kolom yang tidak ada
          pada berkas tidak mengosongkan data lama.</li>
      </ul>
      <p class="mut">Berkas dibaca langsung di peramban; berkasnya tidak disimpan di server.</p>
    </div>
  </div>
</div>
<script src="assets/vendor/xlsx.full.min.js"></script>
<?php page_bottom();
