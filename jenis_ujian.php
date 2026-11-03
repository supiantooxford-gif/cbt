<?php
/**
 * JENIS PENILAIAN (JENIS ULANGAN) — halaman pengaturan Super Admin.
 *
 * Permintaan pemilik: *"Pada akun Super Admin tambahkan fitur untuk menghapus atau menambahkan
 * jenis ulangan yang tampil pada halaman buat ujian guru"*.
 *
 * Daftar jenis penilaian yang tampil pada pilihan "Jenis Penilaian" di halaman **Buat/Kelola Ujian**
 * (`ujian_edit.php`, akun Guru & Operator) kini diatur dari sini:
 *   - TAMBAH jenis baru;
 *   - HAPUS jenis yang tidak dipakai lagi;
 *   - KEMBALIKAN ke daftar bawaan aplikasi.
 *
 * TINGKAT APLIKASI: berlaku untuk seluruh sekolah/madrasah (disimpan pada tabel `app_pengaturan`),
 * sama seperti menu "Biaya & Aktivasi" dan "Logo Aplikasi".
 *
 * Menghapus sebuah jenis TIDAK mengubah ujian yang sudah tersimpan — kolom `ujian.jenis_ujian`
 * menyimpan nama jenis sebagai teks, jadi laporan/lembar cetak ujian lama tetap utuh.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

require_super();

$galat = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $aksi = teks($_POST['aksi'] ?? '', 16);

    if ($aksi === 'tambah') {
        $nama = (string) ($_POST['nama'] ?? '');
        $pesan = jenis_ujian_tambah($nama);
        $bersih = jenis_ujian_rapi($nama);
        if ($pesan !== '') {
            $galat = $pesan;
        } else {
            flash('Jenis penilaian "' . $bersih . '" ditambahkan. Pilihan pada halaman buat ujian guru '
                . 'langsung memuat jenis ini.', 'ok');
            redirect('jenis_ujian.php');
        }
    } elseif ($aksi === 'hapus') {
        $nama = (string) ($_POST['nama'] ?? '');
        $pesan = jenis_ujian_hapus($nama);
        if ($pesan !== '') {
            $galat = $pesan;
        } else {
            flash('Jenis penilaian "' . jenis_ujian_rapi($nama) . '" dihapus dari daftar pilihan. '
                . 'Ujian yang sudah memakainya TIDAK berubah.', 'warn');
            redirect('jenis_ujian.php');
        }
    } elseif ($aksi === 'bawaan') {
        jenis_ujian_bawaan_kembalikan();
        flash('Daftar jenis penilaian dikembalikan ke bawaan aplikasi.', 'warn');
        redirect('jenis_ujian.php');
    } else {
        redirect('jenis_ujian.php');
    }
}

$daftar = jenis_ujian_list(true);
$bawaan = jenis_ujian_masih_bawaan();
$namaBaru = $galat !== '' ? (string) ($_POST['nama'] ?? '') : '';

page_top('Jenis Penilaian');
?>
<div class="card">
  <div class="card-head">
    <h3>Jenis Penilaian (Jenis Ulangan)</h3>
    <div class="sub">Pilihan yang tampil pada kolom <b>Jenis Penilaian</b> di halaman buat ujian guru
      &mdash; berlaku untuk semua sekolah/madrasah</div>
    <div class="kanan">
      <?php if ($bawaan): ?><span class="pil pil-info">masih bawaan</span>
      <?php else: ?><span class="pil pil-ok">sudah disesuaikan</span><?php endif; ?>
      <?php if (!$bawaan): ?>
        <form method="post" data-confirm="Kembalikan daftar jenis penilaian ke bawaan aplikasi?">
          <?= csrf_field() ?>
          <input type="hidden" name="aksi" value="bawaan">
          <button class="btn btn-sm btn-bahaya" type="submit">Kembalikan Bawaan</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
  <div class="card-body">
    <p class="mut">Daftar di bawah ini dipakai pada isian <b>Jenis Penilaian</b> di halaman
      <b>Buat Ujian Baru / Kelola Ujian</b> (akun Guru dan Operator). Menghapus sebuah jenis
      <b>tidak mengubah ujian yang sudah tersimpan</b> &mdash; nama jenis pada ujian lama tetap
      tercetak di daftar ujian, hasil, dan lembar cetak.</p>

    <?php if ($galat !== ''): ?>
      <div class="alert alert-err"><?= e($galat) ?></div>
    <?php endif; ?>

    <form method="post" id="formTambahJenis">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="tambah">
      <div class="form-grid f2">
        <div class="field">
          <label for="nama">Nama Jenis Penilaian Baru</label>
          <input type="text" id="nama" name="nama" required maxlength="<?= (int) JENIS_UJIAN_PANJANG ?>"
                 value="<?= e($namaBaru) ?>" placeholder="mis. Asesmen Sumatif Akhir Semester">
          <div class="ket">Contoh: <b>Ulangan Harian</b>, <b>Sumatif Tengah Semester</b>,
            <b>Asesmen Madrasah</b>. Maksimal <?= (int) JENIS_UJIAN_PANJANG ?> karakter,
            sampai <?= (int) JENIS_UJIAN_MAKS ?> jenis pada daftar.</div>
        </div>
      </div>
      <div class="form-kaki">
        <button class="btn btn-utama" type="submit">Tambah Jenis Penilaian</button>
        <span class="mut" style="font-size:.78rem">Berlaku untuk <b>semua sekolah/madrasah</b> &mdash;
          tidak perlu disetel ulang per lembaga.</span>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-head">
    <h3>Daftar Jenis Penilaian</h3>
    <div class="sub"><?= count($daftar) ?> jenis &middot; urutan tampil mengikuti daftar ini</div>
  </div>
  <div class="card-body">
    <div class="table-wrap">
      <table class="tbl" id="tblJenisUjian">
        <thead>
          <tr><th style="width:56px">No</th><th>Jenis Penilaian</th>
            <th style="width:150px">Dipakai Ujian</th><th style="width:140px">Aksi</th></tr>
        </thead>
        <tbody>
          <?php $no = 0; foreach ($daftar as $j): $no++; $pakai = jenis_ujian_pakai($j); ?>
            <tr>
              <td><?= $no ?></td>
              <td><b><?= e($j) ?></b></td>
              <td><small class="mut"><?= $pakai > 0 ? $pakai . ' ujian' : 'belum dipakai' ?></small></td>
              <td>
                <?php if (count($daftar) > 1): ?>
                  <form method="post" data-confirm="Hapus jenis penilaian &quot;<?= e($j) ?>&quot; dari daftar pilihan? Ujian yang sudah memakainya tidak berubah.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="aksi" value="hapus">
                    <input type="hidden" name="nama" value="<?= e($j) ?>">
                    <button class="btn btn-sm btn-bahaya" type="submit">Hapus</button>
                  </form>
                <?php else: ?>
                  <small class="mut">&mdash;</small>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="mut" style="font-size:.78rem;margin:10px 0 0">Jenis terakhir tidak dapat dihapus &mdash;
      minimal harus ada satu jenis penilaian agar guru tetap dapat membuat ujian.</p>
  </div>
</div>
<?php page_bottom(); ?>
