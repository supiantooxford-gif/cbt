<?php
/**
 * Kelola Sekolah (khusus Super Admin): tambah/ubah sekolah, tarik data LEMBAGA dari e-GURU,
 * akun pengelola, aktivasi, nonaktifkan, hapus, dan masuk ke konteks sekolah.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';
require_once __DIR__ . '/inc/eguru.php';

$u = require_super();
$pesan = '';
$galat = '';
$edit = 0;
$form = [
    'nama' => '', 'jenjang' => 'SMP/MTs', 'npsn' => '', 'alamat' => '', 'desa' => '', 'kecamatan' => '',
    'kabupaten' => '', 'provinsi' => '', 'telepon' => '', 'email' => '', 'website' => '',
    'kepala' => '', 'nip_kepala' => '', 'tahun_ajaran' => '', 'semester' => 'Ganjil', 'kkm' => '75',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $aksi = teks($_POST['aksi'] ?? '', 30);
    $id = (int) ($_POST['id'] ?? 0);

    if ($aksi === 'tambah' || $aksi === 'ubah') {
        foreach (array_keys($form) as $k) {
            $form[$k] = teks($_POST[$k] ?? '', 200);
        }
        $form['jenjang'] = jenjang_label($form['jenjang']);
        $form['kkm'] = (string) max(0, min(100, (float) str_replace(',', '.', $form['kkm'])));
        if ($form['nama'] === '') {
            $galat = 'Nama sekolah wajib diisi.';
        } elseif ($aksi === 'tambah' && val('SELECT id FROM sekolah WHERE nama = ?', [$form['nama']])) {
            $galat = 'Nama sekolah itu sudah terdaftar.';
        } else {
            if ($aksi === 'tambah') {
                q('INSERT INTO sekolah (nama, jenjang, npsn, alamat, desa, kecamatan, kabupaten, provinsi,
                        telepon, email, website, kepala, nip_kepala, tahun_ajaran, semester, kkm,
                        verifikasi, aktif, aktivasi, aktif_sampai, dibuat)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
                    $form['nama'], $form['jenjang'], $form['npsn'], $form['alamat'], $form['desa'],
                    $form['kecamatan'], $form['kabupaten'], $form['provinsi'], $form['telepon'],
                    $form['email'], $form['website'], $form['kepala'], nip_bersih($form['nip_kepala']),
                    $form['tahun_ajaran'], $form['semester'], (float) $form['kkm'],
                    'disetujui', 1, 'percobaan', null, date('Y-m-d H:i:s'),
                ]);
                $baru = (int) db()->lastInsertId();
                provision_sekolah($baru);
                // Sekolah baru diberikan MASA AKTIF 365 hari (bukan permanen) — permintaan pemilik.
                // Keadaan "permanen" hanya bila Super Admin memilihnya sendiri pada kartu
                // "Aktivasi & Kode Lembaga" (pilihan "Aktif permanen").
                aktivasi_mulai_uji_coba($baru, AKTIVASI_HARI_AKTIF);
                flash('Sekolah "' . $form['nama'] . '" ditambahkan dengan masa aktif '
                    . (int) AKTIVASI_HARI_AKTIF . ' hari (1 tahun).', 'ok');
                redirect('sekolah.php?edit=' . $baru . '#formSekolah');
            }
            q('UPDATE sekolah SET nama=?, jenjang=?, npsn=?, alamat=?, desa=?, kecamatan=?, kabupaten=?,
                    provinsi=?, telepon=?, email=?, website=?, kepala=?, nip_kepala=?, tahun_ajaran=?,
                    semester=?, kkm=? WHERE id=?', [
                $form['nama'], $form['jenjang'], $form['npsn'], $form['alamat'], $form['desa'],
                $form['kecamatan'], $form['kabupaten'], $form['provinsi'], $form['telepon'],
                $form['email'], $form['website'], $form['kepala'], nip_bersih($form['nip_kepala']),
                $form['tahun_ajaran'], $form['semester'], (float) $form['kkm'], $id,
            ]);
            flash('Data sekolah diperbarui.', 'ok');
            redirect('sekolah.php');
        }
    } elseif ($aksi === 'akun') {
        $nama = teks($_POST['nama_admin'] ?? '', 120);
        $uname = teks($_POST['username'] ?? '', 40);
        $sandi = (string) ($_POST['password'] ?? '');
        if ($nama === '' || !preg_match('/^[a-zA-Z0-9._]{4,30}$/', $uname)) {
            flash('Nama dan nama pengguna (4-30 karakter) akun pengelola wajib diisi.', 'err');
        } elseif (strlen($sandi) < 8) {
            flash('Kata sandi minimal 8 karakter.', 'err');
        } elseif (val('SELECT id FROM users WHERE username = ?', [$uname])) {
            flash('Nama pengguna sudah dipakai.', 'err');
        } else {
            q('INSERT INTO users (sekolah_id, nama, username, password, role, aktif, dibuat) VALUES (?,?,?,?,?,1,?)',
                [$id, $nama, $uname, password_hash($sandi, PASSWORD_DEFAULT), 'operator', date('Y-m-d H:i:s')]);
            flash('Akun Admin Operator "' . $uname . '" dibuat.', 'ok');
        }
        redirect('sekolah.php?edit=' . $id . '#akunPengelola');
    } elseif ($aksi === 'aktivasi') {
        $mode = teks($_POST['mode'] ?? '', 20);
        $hari = (int) ($_POST['hari'] ?? 30);
        if (!in_array($hari, [30, 60, 90, 365], true)) {
            $hari = 30;
        }
        if ($mode === 'permanen') {
            q('UPDATE sekolah SET aktivasi = ?, aktif_sampai = NULL, aktivasi_pada = COALESCE(aktivasi_pada, ?) WHERE id = ?',
                ['permanen', date('Y-m-d H:i:s'), $id]);
            flash('Sekolah dijadikan aktif permanen.', 'ok');
        } elseif ($mode === 'uji_coba') {
            aktivasi_mulai_uji_coba($id, $hari);
            flash('Masa uji coba ' . $hari . ' hari dimulai.', 'ok');
        } elseif ($mode === 'aktifkan') {
            aktivasi_aktifkan($id, AKTIVASI_HARI_AKTIF);
            flash('Sekolah diaktivasi untuk ' . (int) AKTIVASI_HARI_AKTIF . ' hari. Kode periode berikutnya sudah berganti.', 'ok');
        }
        redirect('sekolah.php?edit=' . $id);
    } elseif ($aksi === 'nonaktif' || $aksi === 'aktifkan_sekolah') {
        q('UPDATE sekolah SET aktif = ? WHERE id = ?', [$aksi === 'nonaktif' ? 0 : 1, $id]);
        flash($aksi === 'nonaktif' ? 'Sekolah dinonaktifkan (data tetap utuh).' : 'Sekolah diaktifkan kembali.', 'warn');
        redirect('sekolah.php');
    } elseif ($aksi === 'hapus') {
        if (teks($_POST['konfirmasi'] ?? '', 20) !== 'HAPUS') {
            flash('Penghapusan dibatalkan: ketik HAPUS untuk konfirmasi.', 'err');
        } elseif ((int) val('SELECT COUNT(*) FROM sekolah WHERE aktif = 1', [], 0) <= 1) {
            flash('Sekolah terakhir tidak boleh dihapus.', 'err');
        } else {
            $s = one('SELECT * FROM sekolah WHERE id = ?', [$id]);
            $d = sekolah_dampak($id);
            q('DELETE FROM sekolah WHERE id = ?', [$id]);
            // Bila sekolah yang dihapus adalah konteks Super Admin, lepaskan konteksnya supaya
            // halaman berikutnya tidak menunjuk sekolah yang sudah tidak ada.
            if (isset($_SESSION['ctx_sekolah_id']) && (int) $_SESSION['ctx_sekolah_id'] === $id) {
                set_sekolah_context(null);
            }
            flash('Sekolah "' . (string) ($s['nama'] ?? '') . '" beserta ' . $d['guru'] . ' guru, '
                . $d['siswa'] . ' siswa, dan ' . $d['ujian'] . ' ujian dihapus permanen.', 'warn');
        }
        redirect('sekolah.php');
    } elseif ($aksi === 'masuk') {
        set_sekolah_context($id);
        redirect('index.php');
    } elseif ($aksi === 'tarik_lembaga') {
        // ---------------------------------------------------------------- TARIK LEMBAGA dari e-GURU
        $esid = (int) ($_POST['eguru_id'] ?? 0);
        $ident = eguru_identitas($esid);
        if (!$ident) {
            flash('Lembaga e-GURU tidak ditemukan atau basis datanya tidak terbaca. Cari ulang namanya.', 'err');
            redirect('sekolah.php');
        }
        $namaBaru = teks((string) $ident['nama'], 200);
        $sudah = eguru_sekolah_di_cbt($namaBaru);
        if ($sudah) {
            flash('Lembaga "' . $namaBaru . '" sudah ada di CBT. Untuk menarik data guru & siswanya,'
                . ' gunakan menu Tarik Data dari e-GURU pada sekolah itu.', 'err');
            redirect('sekolah.php?edit=' . (int) $sudah['id']);
        }
        $jenjang = jenjang_label(teks($_POST['jenjang'] ?? (string) $ident['jenjang'], 20));
        $ta = teks($_POST['tahun_ajaran'] ?? '', 20);
        $sm = teks($_POST['semester'] ?? 'Ganjil', 10);
        $sm = in_array($sm, ['Ganjil', 'Genap'], true) ? $sm : 'Ganjil';

        q('INSERT INTO sekolah (nama, jenjang, npsn, alamat, desa, kecamatan, kabupaten, provinsi,
                telepon, email, website, kepala, nip_kepala, tahun_ajaran, semester, kkm,
                verifikasi, aktif, aktivasi, aktif_sampai, eguru_id, dibuat)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
            $namaBaru, $jenjang,
            teks((string) ($ident['npsn'] ?? ''), 40),
            teks((string) ($ident['alamat'] ?? ''), 250),
            teks((string) ($ident['desa'] ?? ''), 100),
            teks((string) ($ident['kecamatan'] ?? ''), 100),
            teks((string) ($ident['kabupaten'] ?? ''), 100),
            teks((string) ($ident['provinsi'] ?? ''), 100),
            teks((string) ($ident['telepon'] ?? ''), 40),
            teks((string) ($ident['email'] ?? ''), 120),
            teks((string) ($ident['website'] ?? ''), 120),
            teks((string) $ident['kepala'], 120),
            nip_bersih($ident['nip_kepala']),
            $ta, $sm, 75, 'disetujui', 1, 'percobaan', null, $esid, date('Y-m-d H:i:s'),
        ]);
        $baru = (int) db()->lastInsertId();
        provision_sekolah($baru);
        // Masa aktif 365 hari (bukan permanen) — permintaan pemilik.
        aktivasi_mulai_uji_coba($baru, AKTIVASI_HARI_AKTIF);

        // Akun Admin Operator supaya sekolah baru langsung dapat dipakai.
        $namaAdmin = teks($_POST['nama_admin'] ?? '', 120);
        if ($namaAdmin === '') {
            $namaAdmin = teks((string) $ident['kepala'], 120);
        }
        if ($namaAdmin === '') {
            $namaAdmin = 'Administrator ' . $namaBaru;
        }
        $uname = teks($_POST['username'] ?? '', 40);
        $uname = preg_match('/^[a-zA-Z0-9._]{4,30}$/', $uname)
            ? username_unik($uname) : username_unik(username_dari_nama($namaBaru));
        $sandi = (string) ($_POST['password'] ?? '');
        $sandiAcak = false;
        if (strlen($sandi) < 8) {
            $sandi = sandi_acak(8);
            $sandiAcak = true;
        }
        q('INSERT INTO users (sekolah_id, nama, username, password, role, aktif, dibuat) VALUES (?,?,?,?,?,1,?)',
            [$baru, $namaAdmin, $uname, password_hash($sandi, PASSWORD_DEFAULT), 'operator', date('Y-m-d H:i:s')]);

        // Sekalian tarik guru/siswa/kelas/mapel (dijalankan DALAM konteks sekolah baru).
        $tarik = null;
        if (!empty($_POST['sekalian'])) {
            $konteksLama = $_SESSION['ctx_sekolah_id'] ?? null;
            set_sekolah_context($baru);
            $tarik = eguru_tarik_aman();
            set_sekolah_context($konteksLama === null ? null : (int) $konteksLama);
        }

        $pesan = 'Lembaga "' . $namaBaru . '" ditarik dari e-GURU: identitas sekolah dibuat'
            . ' (' . $jenjang . ') + akun Admin Operator "' . $uname . '"';
        $pesan .= $sandiAcak ? ' (kata sandi awal: ' . $sandi . ')' : '';
        $pesan .= '. Masa aktif ' . (int) AKTIVASI_HARI_AKTIF . ' hari (1 tahun) — bukan permanen.';
        if ($tarik === null) {
            $pesan .= ' Data guru & siswa dapat ditarik lewat menu Tarik Data dari e-GURU.';
        } elseif (isset($tarik['galat'])) {
            $pesan .= ' Namun penarikan data guru/siswa GAGAL: ' . (string) $tarik['galat'];
        } else {
            $pesan .= ' Data yang ikut masuk: ' . (int) $tarik['kelas_total'] . ' kelas, '
                . (int) $tarik['mapel_total'] . ' mata pelajaran, ' . (int) $tarik['guru_total'] . ' guru, '
                . (int) $tarik['siswa_total'] . ' siswa (' . (int) $tarik['akun_guru'] . ' akun guru & '
                . (int) $tarik['akun_siswa'] . ' akun siswa dibuat; '
                . (int) $tarik['akun_guru_sinkron'] . ' akun guru memakai akun e-GURU).';
        }
        flash($pesan, isset($tarik['galat']) ? 'warn' : 'ok');
        redirect('sekolah.php?edit=' . $baru . '#akunPengelola');
    }
}

if (isset($_GET['keluar'])) {
    set_sekolah_context(null);
    flash('Kembali ke tampilan Super Admin (lintas sekolah).', 'ok');
    redirect('sekolah.php');
}
if (isset($_GET['masuk'])) {
    $mid = (int) $_GET['masuk'];
    if (val('SELECT id FROM sekolah WHERE id = ? AND aktif = 1', [$mid])) {
        set_sekolah_context($mid);
        redirect('index.php');
    }
    flash('Sekolah tidak ditemukan atau nonaktif.', 'err');
    redirect('sekolah.php');
}

$edit = (int) ($_GET['edit'] ?? 0);
$editRow = $edit > 0 ? one('SELECT * FROM sekolah WHERE id = ?', [$edit]) : null;
if ($editRow) {
    foreach (array_keys($form) as $k) {
        $form[$k] = (string) ($editRow[$k] ?? '');
    }
}
if ($galat !== '') {
    flash($galat, 'err');
    redirect('sekolah.php');
}

// ---------------------------------------------------------------- TARIK LEMBAGA dari e-GURU (pencarian)
$egTersedia = eguru_tersedia();
$egCari = teks($_GET['eguru_cari'] ?? '', 80);
$egPilih = (int) ($_GET['eguru_pilih'] ?? 0);
$egDaftar = $egTersedia ? eguru_sekolah_semua() : [];
$egHasil = ($egTersedia && $egCari !== '') ? eguru_sekolah_cari_daftar($egCari, 30) : [];
$egPilihRow = ($egTersedia && $egPilih > 0) ? eguru_identitas($egPilih) : null;
// Hasil pencarian yang hanya SATU langsung disiapkan untuk ditarik.
if (!$egPilihRow && count($egHasil) === 1) {
    $egPilihRow = eguru_identitas((int) $egHasil[0]['id']);
}
$egPilihSudah = $egPilihRow ? eguru_sekolah_di_cbt((string) $egPilihRow['nama']) : null;
// Nilai bawaan formulir penarikan.
$egSandiSaran = $egPilihRow ? sandi_acak(8) : '';
$egUnameSaran = $egPilihRow ? username_unik(username_dari_nama((string) $egPilihRow['nama'])) : '';
$isi = fn($v): string => trim((string) $v) !== '' ? (string) $v : '—';

$daftar = all("SELECT sc.*,
        (SELECT COUNT(*) FROM kelas k WHERE k.sekolah_id = sc.id) AS kelas,
        (SELECT COUNT(*) FROM guru g WHERE g.sekolah_id = sc.id AND g.aktif = 1) AS guru,
        (SELECT COUNT(*) FROM siswa sw WHERE sw.sekolah_id = sc.id AND sw.aktif = 1) AS siswa,
        (SELECT COUNT(*) FROM ujian uj WHERE uj.sekolah_id = sc.id) AS ujian,
        (SELECT COUNT(*) FROM users us WHERE us.sekolah_id = sc.id) AS akun
    FROM sekolah sc ORDER BY sc.aktif DESC, sc.nama");
$aktifList = array_values(array_filter($daftar, fn($r) => (int) $r['aktif'] === 1));
$nonaktifList = array_values(array_filter($daftar, fn($r) => (int) $r['aktif'] !== 1));

// ---------------------------------------------------------------- KONFIRMASI HAPUS per baris
// Permintaan pemilik: tombol hapus pada kolom Aksi daftar sekolah. Penghapusan TETAP memakai
// penjaga "ketik HAPUS" (tidak boleh satu klik), jadi tombolnya membuka kartu konfirmasi ini
// (bekerja tanpa JavaScript) yang memuat dampak penghapusan sebelum form dikirim.
$hapusId = (int) ($_GET['hapus'] ?? 0);
$hapusRow = $hapusId > 0 ? one('SELECT * FROM sekolah WHERE id = ?', [$hapusId]) : null;
if ($hapusId > 0 && !$hapusRow) {
    flash('Sekolah tidak ditemukan (mungkin sudah dihapus).', 'err');
    redirect('sekolah.php');
}
$hapusDampak = $hapusRow ? sekolah_dampak((int) $hapusRow['id']) : [];
$hapusTerakhir = $hapusRow ? ((int) val('SELECT COUNT(*) FROM sekolah WHERE aktif = 1', [], 0) <= 1) : false;

page_top('Kelola Sekolah');
?>
<?php if ($hapusRow): ?>
  <?php // Kartu konfirmasi hapus (dibuka dari tombol "Hapus" pada kolom Aksi daftar sekolah). ?>
  <div class="card card-hapus" id="hapusSekolah">
    <div class="card-head">
      <h3>Hapus Sekolah/Madrasah</h3>
      <div class="sub">Penghapusan bersifat PERMANEN dan tidak dapat dibatalkan</div>
    </div>
    <div class="card-body">
      <p>Sekolah yang akan dihapus: <b><?= e((string) $hapusRow['nama']) ?></b>
        <span class="pil pil-mut"><?= e(jenjang_label((string) $hapusRow['jenjang'])) ?></span>
        <?php if ((int) $hapusRow['aktif'] !== 1): ?><span class="pil pil-mut">Nonaktif</span><?php endif; ?></p>
      <?php if ($hapusTerakhir): ?>
        <div class="alert alert-err"><b>Sekolah ini tidak dapat dihapus.</b> Aplikasi harus menyisakan
          minimal satu sekolah aktif. Tambahkan sekolah lain lebih dahulu, atau nonaktifkan sekolah ini.</div>
        <a class="btn" href="sekolah.php">Kembali</a>
      <?php else: ?>
        <p class="mut">Seluruh data berikut akan ikut terhapus permanen:
          <b><?= (int) $hapusDampak['guru'] ?> guru</b>, <b><?= (int) $hapusDampak['siswa'] ?> siswa</b>,
          <b><?= (int) $hapusDampak['akun'] ?> akun</b>, <b><?= (int) $hapusDampak['ujian'] ?> ujian</b>, dan
          <b><?= (int) $hapusDampak['soal'] ?> soal</b> beserta <?= (int) $hapusDampak['sesi'] ?> sesi ujian.</p>
        <div class="alert alert-warn">Bila hanya ingin menghentikan pemakaian sementara (data tetap utuh),
          gunakan tombol <b>Nonaktifkan Sekolah</b> pada kartu <b>Zona Berbahaya</b> di halaman Ubah.</div>
        <form method="post" data-confirm="Hapus PERMANEN sekolah ini beserta seluruh datanya?">
          <?= csrf_field() ?>
          <input type="hidden" name="aksi" value="hapus">
          <input type="hidden" name="id" value="<?= (int) $hapusRow['id'] ?>">
          <div class="form-grid f2">
            <div class="field"><label for="konfirmasiHapus">Ketik <b>HAPUS</b> untuk konfirmasi</label>
              <input type="text" id="konfirmasiHapus" name="konfirmasi" placeholder="HAPUS"
                     autocomplete="off" autofocus></div>
          </div>
          <div class="form-kaki">
            <button class="btn btn-bahaya" type="submit">Hapus Permanen</button>
            <a class="btn" href="sekolah.php">Batal</a>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php
// v1.15.0 — permintaan pemilik: kartu "Tambah Sekolah/Madrasah" DIHAPUS (kotak merah pada
// tangkapan layar). Seluruh data sekolah kini ditarik dari aplikasi e-GURU lewat kartu
// "Tarik Data Lembaga dari e-GURU" di bawah, jadi formulir tambah manual tidak dipakai lagi.
// Formulir di bawah hanya dirender saat MENGUBAH sekolah yang sudah ada (?edit=ID).
?>
<?php if (!$editRow): ?>
  <div class="alert alert-info">
    <b>Menambah sekolah/madrasah:</b> gunakan kartu <b>Tarik Data Lembaga dari e-GURU</b> di bawah —
    identitas sekolah, akun pengelola, guru, siswa, kelas, dan mata pelajaran ikut masuk otomatis.
    Formulir tambah manual sudah tidak dipakai lagi.
  </div>
<?php endif; ?>

<?php if ($editRow): ?>
<div class="grid g21">
  <div class="card" id="formSekolah">
    <div class="card-head">
      <h3>Ubah Sekolah/Madrasah</h3>
      <div class="sub">Ubah identitas &amp; pengaturan sekolah yang sudah terdaftar.</div>
      <div class="kanan"><a class="btn btn-sm" href="sekolah.php">Batal ubah</a></div>
    </div>
    <div class="card-body">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="aksi" value="ubah">
        <input type="hidden" name="id" value="<?= (int) $edit ?>">
        <div class="form-grid f2">
          <div class="field"><label>Nama Sekolah/Madrasah</label>
            <input type="text" name="nama" value="<?= e($form['nama']) ?>" required
                   placeholder="mis. MA. Sirojuth Tholibiin Mootilango"></div>
          <div class="field"><label>Jenjang</label>
            <select name="jenjang">
              <?php foreach (jenjang_list() as $j): ?>
                <option value="<?= e($j) ?>"<?= $form['jenjang'] === $j ? ' selected' : '' ?>><?= e($j) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="field"><label>NPSN</label><input type="text" name="npsn" value="<?= e($form['npsn']) ?>"></div>
          <div class="field"><label>Alamat (Jalan, Dusun)</label><input type="text" name="alamat" value="<?= e($form['alamat']) ?>"></div>
          <div class="field"><label>Desa / Kelurahan</label><input type="text" name="desa" value="<?= e($form['desa']) ?>"></div>
          <div class="field"><label>Kecamatan</label><input type="text" name="kecamatan" value="<?= e($form['kecamatan']) ?>"></div>
          <div class="field"><label>Kabupaten / Kota</label><input type="text" name="kabupaten" value="<?= e($form['kabupaten']) ?>"></div>
          <div class="field"><label>Provinsi</label><input type="text" name="provinsi" value="<?= e($form['provinsi']) ?>"></div>
          <div class="field"><label>Telepon / WhatsApp</label><input type="text" name="telepon" value="<?= e($form['telepon']) ?>"></div>
          <div class="field"><label>Email</label><input type="email" name="email" value="<?= e($form['email']) ?>"></div>
          <div class="field"><label>Situs / Web</label><input type="text" name="website" value="<?= e($form['website']) ?>"></div>
          <div class="field"><label>Nama Kepala</label><input type="text" name="kepala" value="<?= e($form['kepala']) ?>"></div>
          <div class="field"><label>NIP Kepala</label><input type="text" name="nip_kepala" value="<?= e($form['nip_kepala']) ?>"></div>
          <div class="field"><label>Tahun Ajaran</label><input type="text" name="tahun_ajaran" value="<?= e($form['tahun_ajaran']) ?>" placeholder="2025/2026"></div>
          <div class="field"><label>Semester</label>
            <select name="semester">
              <?php foreach (['Ganjil', 'Genap'] as $sm): ?>
                <option value="<?= e($sm) ?>"<?= $form['semester'] === $sm ? ' selected' : '' ?>><?= e($sm) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="field"><label>KKM / Nilai Ketuntasan</label><input type="number" name="kkm" min="0" max="100" step="0.5" value="<?= e($form['kkm']) ?>"></div>
        </div>
        <div class="form-kaki">
          <button class="btn btn-utama" type="submit">Simpan Perubahan</button>
        </div>
      </form>
    </div>
  </div>

  <?php if ($editRow): ?>
    <div>
      <?php
      $st = aktivasi_status($editRow);
      $kode = kode_aktivasi_sekolah($editRow);
      ?>
      <div class="card">
        <div class="card-head"><h3>Aktivasi &amp; Kode Lembaga</h3></div>
        <div class="card-body">
          <p class="mut">Keadaan: <b><?php
            if ($st['keadaan'] === 'permanen') {
                echo 'Aktif permanen';
            } elseif ($st['keadaan'] === 'uji_coba') {
                echo 'Masa aktif ' . (int) $st['sisa_hari'] . ' hari lagi (berakhir '
                    . e(tanggal_id(substr((string) $st['sampai'], 0, 10))) . ')';
            } else {
                echo 'Masa aktif habis sejak ' . e(tanggal_id(substr((string) $st['sampai'], 0, 10)));
            }
          ?></b></p>
          <p class="mut">Kode aktivasi <b>periode ke-<?= (int) $editRow['aktivasi_ke'] ?></b>
            (<?= (int) AKTIVASI_HARI_AKTIF ?> hari, hanya sah sekali pakai):</p>
          <div class="kotak-kode" id="kodeLembaga"><?= e($kode) ?></div>
          <div class="form-kaki">
            <button class="btn btn-sm" type="button" data-salin="#kodeLembaga">Salin Kode</button>
          </div>
          <form method="post" class="mt16" data-confirm="Aktifkan sekolah ini memakai kode periode sekarang?">
            <?= csrf_field() ?>
            <input type="hidden" name="aksi" value="aktivasi">
            <input type="hidden" name="mode" value="aktifkan">
            <input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>">
            <button class="btn btn-utama btn-sm" type="submit">Aktifkan Sekarang (<?= (int) AKTIVASI_HARI_AKTIF ?> hari)</button>
          </form>
          <div class="divider"></div>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="aksi" value="aktivasi">
            <input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>">
            <div class="field">
              <label>Ubah Masa Aktif</label>
              <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                <select name="mode" style="width:auto">
                  <option value="uji_coba">Uji coba</option>
                  <option value="permanen">Aktif permanen</option>
                </select>
                <select name="hari" style="width:auto">
                  <option value="30">30 hari</option>
                  <option value="60">60 hari</option>
                  <option value="90">90 hari</option>
                  <option value="365">365 hari</option>
                </select>
                <button class="btn btn-sm" type="submit">Terapkan</button>
              </div>
            </div>
          </form>
        </div>
      </div>
      <div class="card" id="akunPengelola">
        <div class="card-head"><h3>Akun Pengelola</h3></div>
        <div class="card-body">
          <table class="tbl mb16">
            <?php foreach (all('SELECT * FROM users WHERE sekolah_id = ? ORDER BY role, nama', [(int) $editRow['id']]) as $ak): ?>
              <tr>
                <td><b><?= e($ak['nama']) ?></b><br><small class="mut"><?= e($ak['username']) ?></small></td>
                <td><?= e(role_label($ak['role'])) ?></td>
                <td><?= (int) $ak['aktif'] === 1 ? '<span class="pil pil-ok">Aktif</span>' : '<span class="pil pil-mut">Nonaktif</span>' ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!val('SELECT id FROM users WHERE sekolah_id = ? LIMIT 1', [(int) $editRow['id']])): ?>
              <tr><td class="kosong" colspan="3">Belum ada akun pada sekolah ini.</td></tr>
            <?php endif; ?>
          </table>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="aksi" value="akun">
            <input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>">
            <div class="form-grid f2">
              <div class="field"><label>Nama Admin Operator</label><input type="text" name="nama_admin" required></div>
              <div class="field"><label>Nama Pengguna</label><input type="text" name="username" required></div>
              <div class="field"><label>Kata Sandi (min 8)</label><input type="password" name="password" required></div>
            </div>
            <button class="btn btn-sm" type="submit">Tambah Akun Operator</button>
          </form>
        </div>
      </div>
      <div class="card" style="border-color:#f0c4c1">
        <div class="card-head"><h3>Zona Berbahaya</h3></div>
        <div class="card-body">
          <?php $d = sekolah_dampak((int) $editRow['id']); ?>
          <p class="mut">Dampak: <?= (int) $d['guru'] ?> guru, <?= (int) $d['siswa'] ?> siswa,
            <?= (int) $d['akun'] ?> akun, <?= (int) $d['ujian'] ?> ujian, <?= (int) $d['soal'] ?> soal.</p>
          <form method="post" data-confirm="Nonaktifkan sekolah ini? Data tetap tersimpan.">
            <?= csrf_field() ?>
            <input type="hidden" name="aksi" value="nonaktif">
            <input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>">
            <button class="btn btn-bahaya btn-sm" type="submit">Nonaktifkan Sekolah</button>
          </form>
          <div class="divider"></div>
          <form method="post" data-confirm="Hapus PERMANEN sekolah ini beserta seluruh datanya?">
            <?= csrf_field() ?>
            <input type="hidden" name="aksi" value="hapus">
            <input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>">
            <div class="field"><label>Ketik HAPUS untuk konfirmasi</label><input type="text" name="konfirmasi" placeholder="HAPUS"></div>
            <button class="btn btn-bahaya btn-sm" type="submit">Hapus Permanen</button>
          </form>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>
<?php endif; // akhir blok khusus MENGUBAH sekolah (form tambah sudah dihapus) ?>

  <div class="card" id="tarikLembaga">
    <div class="card-head">
      <h3>Tarik Data Lembaga dari e-GURU</h3>
      <div class="sub">Cari nama lembaga di aplikasi e-GURU. Bila ditemukan, sekolah/madrasah beserta
        identitasnya langsung dibuat di CBT — tidak perlu mengetik ulang.</div>
    </div>
    <div class="card-body">
      <?php if (!$egTersedia): ?>
        <div class="alert alert-err"><?= e(eguru_galat()) ?></div>
      <?php else: ?>
        <form method="get">
          <?php if ($editRow): ?><input type="hidden" name="edit" value="<?= (int) $edit ?>"><?php endif; ?>
          <div class="field">
            <label for="eguru_cari">Nama Lembaga di e-GURU</label>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-start">
              <input type="text" id="eguru_cari" name="eguru_cari" style="flex:1 1 260px;min-width:220px"
                     list="daftarLembagaEguru" autocomplete="off" value="<?= e($egCari) ?>"
                     placeholder="mis. MA. Sirojuth Tholibiin Mootilango">
              <button class="btn btn-utama" type="submit" id="btnCariLembaga"><?= ikon('cari') ?> Cari Lembaga</button>
              <?php if ($egCari !== '' || $egPilih > 0): ?>
                <a class="btn" href="sekolah.php<?= $editRow ? '?edit=' . (int) $edit : '' ?>">Bersihkan</a>
              <?php endif; ?>
            </div>
            <div class="ket">Ketik sebagian nama saja — huruf besar/kecil, tanda titik, dan spasi ganda
              tidak berpengaruh. <?= count($egDaftar) ?> lembaga tersedia di e-GURU.</div>
          </div>
          <datalist id="daftarLembagaEguru">
            <?php foreach ($egDaftar as $eg): ?><option value="<?= e($eg['nama']) ?>"></option><?php endforeach; ?>
          </datalist>
        </form>

        <?php if ($egCari !== ''): ?>
          <?php if (!$egHasil): ?>
            <div class="alert alert-warn">Tidak ada lembaga e-GURU yang namanya memuat
              "<b><?= e($egCari) ?></b>". Periksa ejaan nama lembaganya, atau pilih dari daftar di bawah.</div>
          <?php else: ?>
            <p class="mut"><?= count($egHasil) ?> lembaga ditemukan<?= count($egHasil) > 1 ? ' — pilih salah satu untuk ditarik' : '' ?>:</p>
            <div class="table-wrap">
              <table class="tbl">
                <thead><tr><th>No</th><th>Nama Lembaga (e-GURU)</th><th>Jenis</th>
                  <th class="center">Kelas</th><th class="center">Mapel</th><th class="center">Guru</th>
                  <th class="center">Siswa</th><th>Keadaan</th><th class="kanan">Aksi</th></tr></thead>
                <tbody>
                <?php foreach ($egHasil as $i => $eg): $rg = eguru_ringkas((int) $eg['id']);
                      $adaCbt = eguru_sekolah_di_cbt((string) $eg['nama']); ?>
                  <tr>
                    <td><?= $i + 1 ?></td>
                    <td><b><?= e($eg['nama']) ?></b></td>
                    <td><?= e(eguru_jenis_label($eg['jenis'] ?? '')) ?></td>
                    <td class="center"><?= (int) $rg['kelas'] ?></td>
                    <td class="center"><?= (int) $rg['mapel'] ?></td>
                    <td class="center"><?= (int) $rg['guru'] ?></td>
                    <td class="center"><?= (int) $rg['siswa'] ?></td>
                    <td>
                      <?php if ($adaCbt): ?>
                        <span class="pil pil-ok">Sudah ada di CBT</span>
                        <div class="mut"><?= trim((string) ($adaCbt['eguru_tarik'] ?? '')) !== ''
                            ? 'Data sudah ditarik' : 'Data belum ditarik' ?></div>
                      <?php else: ?>
                        <span class="pil pil-info">Belum ada</span>
                        <div class="mut">Belum dibuat di CBT</div>
                      <?php endif; ?>
                    </td>
                    <td class="kanan row-actions">
                      <?php if ($adaCbt): ?>
                        <a class="btn btn-sm" href="sekolah.php?edit=<?= (int) $adaCbt['id'] ?>">Buka Sekolah</a>
                        <a class="btn btn-sm btn-utama" href="tarik_eguru.php?sekolah=<?= (int) $adaCbt['id'] ?>">Tarik Data Guru &amp; Siswa</a>
                      <?php else: ?>
                        <a class="btn btn-sm btn-utama" href="sekolah.php?eguru_pilih=<?= (int) $eg['id'] ?><?= $editRow ? '&amp;edit=' . (int) $edit : '' ?>#tarikLembaga">Tarik</a>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        <?php endif; ?>

        <?php if ($egPilihRow): ?>
          <div class="divider"></div>
          <?php if ($egPilihSudah): ?>
            <div class="alert alert-warn">Lembaga <b><?= e($egPilihRow['nama']) ?></b> sudah ada di CBT
              (sekolah "<?= e($egPilihSudah['nama']) ?>"). Buka sekolahnya, lalu tarik data guru &amp;
              siswanya lewat menu <b>Tarik Data dari e-GURU</b>.
              <div style="margin-top:8px"><a class="btn btn-sm" href="sekolah.php?edit=<?= (int) $egPilihSudah['id'] ?>">Buka Sekolah</a>
                <a class="btn btn-sm btn-utama" href="tarik_eguru.php?sekolah=<?= (int) $egPilihSudah['id'] ?>">Tarik Data Guru &amp; Siswa</a></div>
            </div>
          <?php else: ?>
            <div class="alert alert-ok">Lembaga ditemukan. Periksa identitasnya, lalu tekan
              <b>Tarik &amp; Buat Sekolah</b>.</div>
            <div class="table-wrap">
              <table class="tbl mb16">
                <tbody>
                  <tr><td class="mut">Nama Lembaga</td><td><b><?= e($egPilihRow['nama']) ?></b></td></tr>
                  <tr><td class="mut">Jenis di e-GURU</td><td><?= e(eguru_jenis_label($egPilihRow['jenis'] ?? '')) ?></td></tr>
                  <tr><td class="mut">NPSN</td><td><?= e($isi($egPilihRow['npsn'] ?? '')) ?></td></tr>
                  <tr><td class="mut">Alamat (Jalan, Dusun)</td><td><?= e($isi($egPilihRow['alamat'] ?? '')) ?></td></tr>
                  <tr><td class="mut">Desa / Kelurahan</td><td><?= e($isi($egPilihRow['desa'] ?? '')) ?></td></tr>
                  <tr><td class="mut">Kecamatan</td><td><?= e($isi($egPilihRow['kecamatan'] ?? '')) ?></td></tr>
                  <tr><td class="mut">Kabupaten / Kota</td><td><?= e($isi($egPilihRow['kabupaten'] ?? '')) ?></td></tr>
                  <tr><td class="mut">Provinsi</td><td><?= e($isi($egPilihRow['provinsi'] ?? '')) ?></td></tr>
                  <tr><td class="mut">Telepon / Email / Situs</td><td><?= e($isi($egPilihRow['telepon'] ?? '')) ?>
                    &middot; <?= e($isi($egPilihRow['email'] ?? '')) ?> &middot; <?= e($isi($egPilihRow['website'] ?? '')) ?></td></tr>
                  <tr><td class="mut">Nama Kepala &amp; NIP</td><td><?= e($isi($egPilihRow['kepala'])) ?>
                    <?= trim((string) $egPilihRow['nip_kepala']) !== '' ? ' &middot; NIP ' . e((string) $egPilihRow['nip_kepala']) : '' ?></td></tr>
                  <tr><td class="mut">Tahun Ajaran &amp; Semester</td><td><?= e($isi($egPilihRow['ta'])) ?>
                    &middot; <?= e((string) $egPilihRow['semester']) ?></td></tr>
                  <tr><td class="mut">Isi di e-GURU</td><td><?= (int) $egPilihRow['ringkas']['kelas'] ?> kelas
                    &middot; <?= (int) $egPilihRow['ringkas']['mapel'] ?> mata pelajaran
                    &middot; <?= (int) $egPilihRow['ringkas']['guru'] ?> guru
                    &middot; <?= (int) $egPilihRow['ringkas']['siswa'] ?> siswa</td></tr>
                </tbody>
              </table>
            </div>
            <form method="post" data-confirm="Tarik lembaga &quot;<?= e($egPilihRow['nama']) ?>&quot; dari e-GURU dan buat sekolahnya di CBT?">
              <?= csrf_field() ?>
              <input type="hidden" name="aksi" value="tarik_lembaga">
              <input type="hidden" name="eguru_id" value="<?= (int) $egPilihRow['id'] ?>">
              <div class="form-grid f2">
                <div class="field"><label>Jenjang (perkiraan dari kelas di e-GURU)</label>
                  <select name="jenjang">
                    <?php foreach (jenjang_list() as $j): ?>
                      <option value="<?= e($j) ?>"<?= (string) $egPilihRow['jenjang'] === $j ? ' selected' : '' ?>><?= e($j) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <div class="ket">Dapat diubah bila perkiraannya belum tepat.</div></div>
                <div class="field"><label>Tahun Ajaran</label>
                  <input type="text" name="tahun_ajaran" value="<?= e((string) $egPilihRow['ta']) ?>" placeholder="2025/2026"></div>
                <div class="field"><label>Semester</label>
                  <select name="semester">
                    <?php foreach (['Ganjil', 'Genap'] as $sm): ?>
                      <option value="<?= e($sm) ?>"<?= (string) $egPilihRow['semester'] === $sm ? ' selected' : '' ?>><?= e($sm) ?></option>
                    <?php endforeach; ?>
                  </select></div>
                <div class="field"><label>Nama Admin Operator</label>
                  <input type="text" name="nama_admin" value="<?= e((string) $egPilihRow['kepala'] !== '' ? (string) $egPilihRow['kepala'] : 'Administrator ' . $egPilihRow['nama']) ?>"></div>
                <div class="field"><label>Nama Pengguna Admin Operator</label>
                  <input type="text" name="username" value="<?= e($egUnameSaran) ?>" autocomplete="off"></div>
                <div class="field"><label>Kata Sandi Awal (min 8)</label>
                  <input type="text" name="password" value="<?= e($egSandiSaran) ?>" autocomplete="off">
                  <div class="ket">Catat dan berikan kepada pengelola sekolah; dapat diganti setelah masuk.</div></div>
              </div>
              <div class="field">
                <label style="display:flex;gap:8px;align-items:center;font-weight:400">
                  <input type="checkbox" name="sekalian" value="1" checked style="width:auto">
                  Sekalian tarik <b>data guru, siswa, kelas &amp; mata pelajaran</b> setelah sekolah dibuat
                </label>
              </div>
              <div class="form-kaki">
                <button class="btn btn-utama" type="submit" id="btnKirimTarik"><?= ikon('impor') ?> Tarik &amp; Buat Sekolah</button>
                <a class="btn" href="sekolah.php<?= $editRow ? '?edit=' . (int) $edit : '' ?>">Batal</a>
              </div>
            </form>
          <?php endif; ?>
        <?php elseif ($egCari === ''): ?>
          <details class="mt16">
            <summary class="mut">Lihat daftar lembaga e-GURU (<?= count($egDaftar) ?>)</summary>
            <div class="table-wrap mt16">
              <table class="tbl">
                <tbody>
                <?php foreach (array_slice($egDaftar, 0, 60) as $eg): $adaCbt = eguru_sekolah_di_cbt((string) $eg['nama']); ?>
                  <tr>
                    <td><?= e($eg['nama']) ?></td>
                    <td><?= e(eguru_jenis_label($eg['jenis'] ?? '')) ?></td>
                    <td class="kanan row-actions">
                      <?php if ($adaCbt): ?>
                        <span class="pil pil-ok">Sudah ada di CBT</span>
                      <?php else: ?>
                        <a class="btn btn-sm" href="sekolah.php?eguru_cari=<?= urlencode((string) $eg['nama']) ?>#tarikLembaga">Cari &amp; Tarik</a>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </details>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

<?php if ($editRow): ?>
  <p><a class="btn btn-sm" href="sekolah.php">&laquo; Kembali ke daftar sekolah</a></p>
<?php endif; ?>

<div class="card">
  <div class="card-head">
    <h3>Sekolah/Madrasah Aktif</h3>
    <div class="sub"><?= count($aktifList) ?> sekolah &middot; sekolah baru langsung aktif dengan masa aktif
      <?= (int) AKTIVASI_HARI_AKTIF ?> hari (1 tahun)</div>
  </div>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>No</th><th>Sekolah/Madrasah</th><th>Jenjang</th><th class="center">Kelas</th>
        <th class="center">Guru</th><th class="center">Siswa</th><th class="center">Ujian</th>
        <th>Keadaan</th><th class="kanan">Aksi</th></tr></thead>
      <tbody>
      <?php foreach ($aktifList as $i => $r): $rst = aktivasi_status($r); ?>
        <tr>
          <td><?= $i + 1 ?></td>
          <td><b><?= e($r['nama']) ?></b>
            <?php if (!empty($r['tanggal_daftar'])): ?>
              <br><small class="mut">Terdaftar: <?= e(tanggal_id((string) $r['tanggal_daftar'])) ?></small>
            <?php endif; ?></td>
          <td><?= e(jenjang_label((string) $r['jenjang'])) ?></td>
          <td class="center"><?= (int) $r['kelas'] ?></td>
          <td class="center"><?= (int) $r['guru'] ?></td>
          <td class="center"><?= (int) $r['siswa'] ?></td>
          <td class="center"><?= (int) $r['ujian'] ?></td>
          <td>
            <?php if ($rst['keadaan'] === 'permanen'): ?><span class="pil pil-ok">Permanen</span>
            <?php elseif ($rst['keadaan'] === 'uji_coba'): ?><span class="pil pil-info"><?= (int) $rst['sisa_hari'] ?> hari</span>
            <?php else: ?><span class="pil pil-err">Terkunci</span><?php endif; ?>
            <?php if ($r['verifikasi'] !== 'disetujui'): ?><span class="pil pil-warn"><?= e((string) $r['verifikasi']) ?></span><?php endif; ?>
          </td>
          <td class="kanan row-actions">
            <a class="btn btn-sm btn-utama" href="sekolah.php?masuk=<?= (int) $r['id'] ?>">Masuk</a>
            <a class="btn btn-sm" href="sekolah.php?edit=<?= (int) $r['id'] ?>#formSekolah">Ubah</a>
            <?php // Tombol Hapus per baris (permintaan pemilik) — membuka kartu konfirmasi hapus. ?>
            <a class="btn btn-sm btn-bahaya" href="sekolah.php?hapus=<?= (int) $r['id'] ?>"
               title="Hapus sekolah ini (perlu konfirmasi)">Hapus</a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$aktifList): ?><tr><td colspan="9" class="kosong">Belum ada sekolah aktif.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($nonaktifList): ?>
<div class="card">
  <div class="card-head"><h3>Sekolah Nonaktif</h3><div class="sub">Data tetap utuh, dapat diaktifkan kembali</div></div>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>No</th><th>Sekolah/Madrasah</th><th class="center">Guru</th><th class="center">Siswa</th><th class="kanan">Aksi</th></tr></thead>
      <tbody>
      <?php foreach ($nonaktifList as $i => $r): ?>
        <tr>
          <td><?= $i + 1 ?></td>
          <td><b><?= e($r['nama']) ?></b></td>
          <td class="center"><?= (int) $r['guru'] ?></td>
          <td class="center"><?= (int) $r['siswa'] ?></td>
          <td class="kanan row-actions">
            <form method="post" style="display:inline">
              <?= csrf_field() ?><input type="hidden" name="aksi" value="aktifkan_sekolah">
              <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <button class="btn btn-sm btn-ok" type="submit">Aktifkan</button>
            </form>
            <a class="btn btn-sm" href="sekolah.php?edit=<?= (int) $r['id'] ?>#formSekolah">Ubah</a>
            <a class="btn btn-sm btn-bahaya" href="sekolah.php?hapus=<?= (int) $r['id'] ?>"
               title="Hapus sekolah ini (perlu konfirmasi)">Hapus</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<?php page_bottom();
