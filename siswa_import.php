<?php
/**
 * Impor Data Siswa dari berkas Excel/CSV.
 *
 * Kolom yang diterima SAMA dengan EKSPOR aplikasi e-GURU, sehingga berkas
 * "Ekspor Data Siswa" dari e-GURU dapat langsung diimpor ke sini:
 *   Nama · Jenis Anggota · NIS · NISN · Jenis Kelamin · Kelas · Tanggal Daftar ·
 *   Tanggal Lahir · Nama Wali · Telepon · Alamat · Status Siswa
 * Kolom tambahan (Tempat Lahir, Nama Ayah, Nama Ibu, Akun) ikut diterima bila ada.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

require_operator();

const KOLOM_SISWA = ['Nama', 'Jenis Anggota', 'NIS', 'NISN', 'Jenis Kelamin', 'Kelas', 'Tanggal Daftar',
    'Tanggal Lahir', 'Nama Wali', 'Telepon', 'Alamat', 'Status Siswa',
    'Tempat Lahir', 'Nama Ayah', 'Nama Ibu', 'Akun (Nama Pengguna)'];

/** Peta judul kolom (dinormalkan) -> kunci internal. Judul yang tidak dikenal diabaikan. */
function siswa_alias_kolom(): array
{
    return [
        'nama' => 'nama', 'namasiswa' => 'nama', 'namalengkap' => 'nama',
        'jenisanggota' => 'anggota',
        'nis' => 'nis', 'nomorinduksiswa' => 'nis',
        'nisn' => 'nisn',
        'jeniskelamin' => 'jk', 'jk' => 'jk', 'lp' => 'jk', 'gender' => 'jk',
        'kelas' => 'kelas', 'rombel' => 'kelas',
        'tanggaldaftar' => 'dibuat', 'tgldaftar' => 'dibuat', 'terdaftar' => 'dibuat',
        'tanggallahir' => 'tgl', 'tgl' => 'tgl', 'tgllahir' => 'tgl',
        'namawali' => 'wali', 'wali' => 'wali', 'namaorangtua' => 'wali', 'orangtua' => 'wali',
        'namaayah' => 'ayah', 'ayah' => 'ayah',
        'namaibu' => 'ibu', 'ibu' => 'ibu',
        'tempatlahir' => 'tempat', 'tempat' => 'tempat',
        'telepon' => 'telp', 'telp' => 'telp', 'hp' => 'telp', 'nohp' => 'telp', 'nowa' => 'telp',
        'alamat' => 'alamat',
        'statussiswa' => 'status', 'status' => 'status', 'aktif' => 'status',
        'akun' => 'akun', 'username' => 'akun', 'namapengguna' => 'akun', 'akunlogin' => 'akun',
        'akunnamapengguna' => 'akun', 'akunloginpengguna' => 'akun',
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
        $alias = siswa_alias_kolom();
        $peta = [];
        foreach ($judul as $i => $j) {
            $k = kolom_kunci((string) $j);
            if (isset($alias[$k]) && !isset($peta[$alias[$k]])) {
                $peta[$alias[$k]] = (int) $i;
            }
        }
        // Berkas tanpa baris judul dibaca berurutan sesuai susunan kolom ekspor e-GURU.
        if (!$peta) {
            $peta = ['nama' => 0, 'anggota' => 1, 'nis' => 2, 'nisn' => 3, 'jk' => 4, 'kelas' => 5,
                'dibuat' => 6, 'tgl' => 7, 'wali' => 8, 'telp' => 9, 'alamat' => 10, 'status' => 11];
        }
        $ambil = fn(array $row, string $key): string => impor_ambil($row, $peta, $key);
        $ada = fn(string $key): bool => isset($peta[$key]);

        $masuk = 0;
        $lewat = 0;
        $kelasBaru = [];
        $tglSalah = 0;
        $anggotaTakCocok = 0;
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
                // Berkas guru yang salah diimpor ke sini: dilewati agar tidak menjadi data siswa.
                if ($ada('anggota') && bawah($ambil($row, 'anggota')) === 'guru') {
                    $anggotaTakCocok++;
                    continue;
                }
                $nis = teks($ambil($row, 'nis'), 30);
                $nisn = teks($ambil($row, 'nisn'), 30);
                $jkRaw = bawah($ambil($row, 'jk'));
                $jk = (str_starts_with($jkRaw, 'p') || $jkRaw === 'perempuan') ? 'P' : 'L';
                $tglRaw = $ambil($row, 'tgl');
                $tgl = tgl_impor($tglRaw);
                if ($tglRaw !== '' && $tgl === '') {
                    $tglSalah++;
                }
                $kelasId = null;
                if ($ada('kelas')) {
                    $namaKelas = teks($ambil($row, 'kelas'), 60);
                    if ($namaKelas !== '') {
                        $kid = kelas_id_dari_nama($namaKelas, $kelasBaru);
                        $kelasId = $kid > 0 ? $kid : null;
                    }
                }
                $wali = teks($ambil($row, 'wali'), 120);
                $ayah = teks($ambil($row, 'ayah'), 120);
                $ibu = teks($ambil($row, 'ibu'), 120);
                $tempat = teks($ambil($row, 'tempat'), 80);
                $telp = teks($ambil($row, 'telp'), 30);
                $alamat = teks($ambil($row, 'alamat'), 250);
                $status = siswa_status_dari_teks($ambil($row, 'status'));
                $tglDaftar = tgl_impor($ambil($row, 'dibuat'));

                $lama = $nis !== ''
                    ? one('SELECT * FROM siswa WHERE sekolah_id = ? AND nis = ?', [sekolah_id(), $nis])
                    : one('SELECT * FROM siswa WHERE sekolah_id = ? AND LOWER(nama) = ?', [sekolah_id(), bawah($nama)]);
                if ($lama) {
                    $swId = (int) $lama['id'];
                    $statusAkhir = $status !== '' ? $status : (string) ($lama['status'] ?? 'aktif');
                    $waliAkhir = $ada('wali') ? $wali : (string) ($lama['wali'] ?? '');
                    // Wali kosong pada data lama diisi ayah/ibu bila berkas membawanya.
                    if (!$ada('wali') && $waliAkhir === '' && $ayah !== '') {
                        $waliAkhir = $ayah;
                    }
                    q('UPDATE siswa SET nama=?, nis=?, nisn=?, jenis_kelamin=?, tempat_lahir=?, tanggal_lahir=?,
                            kelas_id=?, wali=?, telp=?, alamat=?, nama_ayah=?, nama_ibu=?, status=?, aktif=?
                       WHERE id=?', [
                        $nama, $nis, $nisn, $ada('jk') ? $jk : ($lama['jenis_kelamin'] ?? null),
                        $ada('tempat') ? $tempat : ($lama['tempat_lahir'] ?? null),
                        $tgl !== '' ? $tgl : ($lama['tanggal_lahir'] ?? null),
                        $kelasId !== null ? $kelasId : ($lama['kelas_id'] ?? null),
                        $waliAkhir,
                        $ada('telp') ? $telp : ($lama['telp'] ?? null),
                        $ada('alamat') ? $alamat : ($lama['alamat'] ?? null),
                        $ada('ayah') ? $ayah : ($lama['nama_ayah'] ?? null),
                        $ada('ibu') ? $ibu : ($lama['nama_ibu'] ?? null),
                        $statusAkhir, $statusAkhir === 'aktif' ? 1 : 0,
                        $swId,
                    ]);
                } else {
                    $statusAkhir = $status !== '' ? $status : 'aktif';
                    q('INSERT INTO siswa (sekolah_id, nis, nisn, nama, jenis_kelamin, tempat_lahir,
                            tanggal_lahir, kelas_id, wali, telp, alamat, nama_ayah, nama_ibu, status, aktif, dibuat)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
                        sekolah_id(), $nis, $nisn, $nama, $jk, $tempat,
                        $tgl !== '' ? $tgl : null, $kelasId,
                        $wali !== '' ? $wali : ($ayah !== '' ? $ayah : ''),
                        $telp, $alamat, $ayah, $ibu, $statusAkhir, $statusAkhir === 'aktif' ? 1 : 0,
                        $tglDaftar !== '' ? $tglDaftar . ' 00:00:00' : date('Y-m-d H:i:s'),
                    ]);
                    $swId = (int) db()->lastInsertId();
                }

                if ($ada('akun')) {
                    $akun = teks($ambil($row, 'akun'), 40);
                    if ($akun !== '' && !one('SELECT id FROM users WHERE siswa_id = ?', [$swId])) {
                        if (!preg_match('/^[a-zA-Z0-9._]{3,30}$/', $akun)) {
                            $akun = $nis !== '' ? $nis : username_dari_nama($nama);
                        }
                        $uname = username_unik($akun);
                        q('INSERT INTO users (sekolah_id, nama, username, password, role, siswa_id, aktif, dibuat)
                           VALUES (?,?,?,?,?,?,1,?)',
                            [sekolah_id(), $nama, $uname, password_hash('siswa123', PASSWORD_DEFAULT), 'siswa', $swId, date('Y-m-d H:i:s')]);
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
        $hasil = ['masuk' => $masuk, 'lewat' => $lewat, 'kelas_baru' => array_keys($kelasBaru),
            'tgl_salah' => $tglSalah, 'anggota_tak_cocok' => $anggotaTakCocok, 'total' => count($baris)];
    }
}

page_top('Impor Data Siswa');
?>
<div class="grid g21">
  <div class="card">
    <div class="card-head"><h3>Unggah Berkas Data Siswa</h3>
      <div class="kanan"><a class="btn btn-sm" href="template_impor.php?jenis=siswa">Unduh Contoh Format</a></div>
    </div>
    <div class="card-body">
      <?php if ($galat !== ''): ?><div class="alert alert-err"><?= e($galat) ?></div><?php endif; ?>
      <?php if ($hasil): ?>
        <div class="alert alert-ok">
          <b><?= (int) $hasil['masuk'] ?> data siswa</b> tersimpan dari <?= (int) $hasil['total'] ?> baris terbaca.
          <?php if ((int) $hasil['lewat'] > 0): ?><br><?= (int) $hasil['lewat'] ?> baris dilewati karena nama kosong.<?php endif; ?>
          <?php if ((int) $hasil['tgl_salah'] > 0): ?><br><?= (int) $hasil['tgl_salah'] ?> tanggal lahir tidak dikenali (baris tetap diimpor tanpa tanggal).<?php endif; ?>
          <?php if ($hasil['kelas_baru']): ?><br><?= count($hasil['kelas_baru']) ?> kelas baru dibuat otomatis:
            <?= e(implode(', ', $hasil['kelas_baru'])) ?>.<?php endif; ?>
        </div>
      <?php endif; ?>
      <?php if ($hasil && (int) $hasil['anggota_tak_cocok'] > 0): ?>
        <div class="alert alert-warn"><b><?= (int) $hasil['anggota_tak_cocok'] ?> baris dilewati</b> karena
          kolom <b>Jenis Anggota</b>-nya berbunyi "Guru" — sepertinya berkas Data <b>Guru</b> yang
          diimpor ke Impor Data Siswa.</div>
      <?php endif; ?>
      <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="payload" id="payloadImpor">
        <div class="field">
          <label for="fileImpor">Pilih Berkas (Excel .xlsx/.xls atau CSV)</label>
          <input type="file" id="fileImpor" name="berkas" accept=".xlsx,.xls,.csv"
                 data-kolom='<?= e(json_encode(KOLOM_SISWA)) ?>'>
          <div class="ket" id="infoImpor">Kolom: <?= e(implode(' | ', KOLOM_SISWA)) ?>. Kata sandi akun baru selalu <b>siswa123</b>.</div>
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
        Berkas <b>Ekspor Data Siswa</b> dari aplikasi <b>e-GURU</b> dapat langsung diimpor di sini
        (susunan kolomnya sama).
      </div>
      <ul class="poin-list">
        <li>Baris pertama sebaiknya berisi <b>judul kolom</b>.</li>
        <li>Kolom <b>Nama</b> wajib; <b>NIS</b> dipakai untuk mengenali siswa yang sudah ada (diperbarui,
          bukan diduplikasi).</li>
        <li><b>Kelas</b> yang belum ada dibuat otomatis berdasarkan isi berkas.</li>
        <li><b>Tanggal Lahir</b> menerima 2011-04-12, 12/04/2011, 12 April 2011, atau 20110412.</li>
        <li><b>Status Siswa</b> menerima Aktif / Lulus / Nonaktif; hanya siswa <b>Aktif</b> yang dapat
          mengerjakan ujian.</li>
        <li>Kolom tambahan <b>Tempat Lahir</b>, <b>Nama Ayah</b>, <b>Nama Ibu</b>, dan <b>Akun</b> ikut
          dibaca bila ada pada berkas.</li>
        <li>Kolom yang tidak ada pada berkas <b>tidak mengosongkan</b> data lama.</li>
      </ul>
      <p class="mut">Setelah datanya lengkap, gunakan tombol <b>Buat Akun Massal</b> pada halaman Data Siswa.</p>
    </div>
  </div>
</div>
<script src="assets/vendor/xlsx.full.min.js"></script>
<?php page_bottom();
