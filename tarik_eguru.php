<?php
/**
 * TARIK DATA DARI e-GURU — akun Super Admin.
 *
 * Permintaan pemilik: "Pada akun admin tambahkan menu tarik data dari e-Guru. Yang ditarik
 * berdasarkan Nama lembaga (Otomatis data guru dan siswa akan masuk di aplikasi CBT sehingga
 * tidak perlu lagi input manual)".
 *
 * Pencocokan lembaga memakai NAMA (dinormalkan: huruf kecil tanpa spasi/tanda baca), sehingga
 * perbedaan penulisan tanda titik atau spasi ganda tidak menghalangi. Yang ditarik sekaligus:
 * Kelas, Mata Pelajaran, Data Guru (+akun), dan Data Siswa (+akun).
 * Logika lengkapnya ada pada modul `inc/eguru.php`.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';
require_once __DIR__ . '/inc/eguru.php';

require_super();

// ---------------------------------------------------------------- pindah konteks sekolah
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['sekolah'])) {
    $mid = (int) $_GET['sekolah'];
    $tujuan = one('SELECT * FROM sekolah WHERE id = ? AND aktif = 1', [$mid]);
    if (!$tujuan) {
        flash('Sekolah/madrasah tidak ditemukan atau sudah dinonaktifkan.', 'err');
        redirect('tarik_eguru.php');
    }
    set_sekolah_context($mid);
    redirect('tarik_eguru.php');
}

$sekolah = current_sekolah();

// ---------------------------------------------------------------- aksi tarik data
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    if (!$sekolah) {
        flash('Pilih sekolah/madrasah yang datanya akan ditarik.', 'err');
        redirect('tarik_eguru.php');
    }
    $hasil = eguru_tarik_aman();
    if (isset($hasil['galat'])) {
        flash($hasil['galat'], 'err');
    } else {
        $nGuru = (int) $hasil['guru_baru'] + (int) $hasil['guru_diperbarui'];
        $nSiswa = (int) $hasil['siswa_baru'] + (int) $hasil['siswa_diperbarui'];
        $aksi = ((int) $hasil['guru_baru'] > 0 || (int) $hasil['siswa_baru'] > 0)
            ? 'berhasil ditarik' : 'diperbarui';
        flash($nGuru . ' data guru & ' . $nSiswa . ' data siswa dari e-GURU ' . $aksi . '.', 'ok');
        eguru_hasil_simpan($hasil);
    }
    redirect('tarik_eguru.php');
}

$hasil = eguru_hasil_ambil();

$tersedia = eguru_tersedia();
$eSekolah = null;
$eRingkas = ['kelas' => 0, 'mapel' => 0, 'guru' => 0, 'siswa' => 0];
if ($tersedia && $sekolah) {
    $eSekolah = eguru_sekolah_cari((string) $sekolah['nama']);
    if ($eSekolah) {
        $eRingkas = eguru_ringkas((int) $eSekolah['id']);
    }
}
// Daftar SELURUH lembaga yang ada di aplikasi e-GURU (dibaca langsung setiap halaman dibuka,
// jadi sekolah yang baru mendaftar di e-GURU langsung muncul) + keterangan sudah/belum ditarik.
$egDaftar = $tersedia ? eguru_sekolah_lengkap() : [];
$egHitung = eguru_ringkas_status($egDaftar);
$cbtTanpa = $tersedia ? eguru_cbt_tanpa_pasangan() : [];
$dampak = $sekolah ? sekolah_dampak(sekolah_id()) : ['guru' => 0, 'siswa' => 0, 'akun' => 0];

page_top('Tarik Data dari e-GURU');

if (!$sekolah):
?>
<div class="card">
  <div class="card-head"><h3>Pilih Sekolah/Madrasah</h3>
    <div class="sub">Daftar di bawah adalah <b>seluruh sekolah/madrasah yang ada di aplikasi
      e-GURU</b> — dibaca langsung setiap halaman ini dibuka, jadi sekolah yang baru mendaftar
      di e-GURU langsung muncul di sini.</div>
  </div>
  <div class="card-body">
    <?php if (!$tersedia): ?>
      <div class="alert alert-err"><?= e(eguru_galat()) ?></div>
    <?php else: ?>
      <div class="chip-list" style="margin-bottom:12px">
        <span class="pil pil-info"><?= (int) $egHitung['total'] ?> lembaga di e-GURU</span>
        <span class="pil pil-ok"><?= (int) $egHitung['sudah'] ?> sudah ditarik</span>
        <span class="pil pil-warn"><?= (int) $egHitung['belum'] ?> belum ditarik</span>
        <span class="pil pil-mut"><?= (int) $egHitung['tanpa'] ?> belum ada di CBT</span>
      </div>
      <p class="mut">Pilih lembaga yang datanya akan ditarik, lalu tekan <b>Buka</b>. Lembaga yang
        belum punya pasangan di CBT dapat dibuatkan sekolahnya lebih dahulu lewat tombol
        <b>Buat di CBT</b>.</p>
      <div class="table-wrap">
        <table class="tbl" id="tblEguru">
          <thead><tr><th>No</th><th>Nama Sekolah/Madrasah (e-GURU)</th><th>Jenis</th>
            <th class="center">Guru</th><th class="center">Siswa</th>
            <th>Keterangan Data</th><th class="kanan">Aksi</th></tr></thead>
          <tbody>
          <?php
          $no = 0;
          foreach ($egDaftar as $eg):
              $no++;
              $st = $eg['status'];
              $cbtPas = $eg['cbt'];
              $aktifEg = (int) ($eg['aktif'] ?? 1) === 1;
              $verifEg = strtolower((string) ($eg['verifikasi'] ?? 'disetujui'));
          ?>
            <tr>
              <td><?= $no ?></td>
              <td><b><?= e($eg['nama']) ?></b>
                <?php if (!$aktifEg || $verifEg !== 'disetujui'): ?>
                  <div class="mut"><?= !$aktifEg ? 'Nonaktif di e-GURU' : 'Belum disetujui di e-GURU' ?></div>
                <?php endif; ?>
              </td>
              <td><?= e(eguru_jenis_label($eg['jenis'] ?? '')) ?></td>
              <td class="center"><?= (int) $eg['ringkas']['guru'] ?></td>
              <td class="center"><?= (int) $eg['ringkas']['siswa'] ?></td>
              <td>
                <span class="pil <?= e($st['kelas']) ?>"><?= e($st['label']) ?></span>
                <div class="mut">
                  <?php if ($cbtPas): ?>
                    CBT: <b><?= e($cbtPas['nama']) ?></b><br><?= e($st['ket']) ?>
                  <?php else: ?>
                    <?= e($st['ket']) ?>
                  <?php endif; ?>
                </div>
              </td>
              <td class="kanan row-actions">
                <?php if ($cbtPas): ?>
                  <a class="btn btn-sm btn-utama" href="tarik_eguru.php?sekolah=<?= (int) $cbtPas['id'] ?>"><?= $st['kode'] === 'sudah' ? 'Buka' : 'Tarik Data' ?></a>
                <?php else: ?>
                  <a class="btn btn-sm btn-utama" href="sekolah.php?eguru_pilih=<?= (int) $eg['id'] ?>#tarikLembaga">Buat di CBT</a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$egDaftar): ?>
            <tr><td colspan="7" class="mut">Belum ada sekolah/madrasah pada aplikasi e-GURU.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
      <p class="mut" style="margin-top:10px">Keterangan: <span class="pil pil-ok">Sudah ditarik</span>
        = data guru &amp; siswa lembaga itu sudah pernah ditarik ke CBT (tanggal terakhirnya
        dicantumkan). <span class="pil pil-warn">Belum ditarik</span> = sekolahnya sudah ada di CBT
        tetapi datanya belum pernah ditarik. <span class="pil pil-mut">Belum ada di CBT</span> =
        lembaganya belum dibuat di CBT.</p>
    <?php endif; ?>
  </div>
</div>

<?php if ($tersedia && $cbtTanpa): ?>
<div class="card">
  <div class="card-head"><h3>Sekolah/Madrasah CBT yang Belum Ada di e-GURU</h3>
    <div class="sub">Nama lembaga pada kedua aplikasi harus sama. Samakan dahulu nama sekolah di
      menu Identitas &amp; Pengaturan (CBT) dan di e-GURU agar datanya dapat ditarik.</div>
  </div>
  <div class="card-body">
    <div class="table-wrap">
      <table class="tbl" id="tblCbtTanpaEguru">
        <thead><tr><th>No</th><th>Nama Sekolah/Madrasah (CBT)</th><th>Jenjang</th>
          <th class="center">Guru</th><th class="center">Siswa</th><th>Lembaga di e-GURU</th>
          <th class="kanan">Aksi</th></tr></thead>
        <tbody>
        <?php $no = 0; foreach ($cbtTanpa as $r): $no++; ?>
          <tr>
            <td><?= $no ?></td>
            <td><b><?= e($r['nama']) ?></b></td>
            <td><?= e(jenjang_label($r['jenjang'] ?? '')) ?></td>
            <td class="center"><?= (int) ($r['guru'] ?? 0) ?></td>
            <td class="center"><?= (int) ($r['siswa'] ?? 0) ?></td>
            <td><span class="pil pil-err">Tidak ditemukan</span>
              <div class="mut">Nama lembaga belum sama dengan e-GURU</div></td>
            <td class="kanan row-actions">
              <a class="btn btn-sm" href="tarik_eguru.php?sekolah=<?= (int) $r['id'] ?>">Buka</a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>
<?php
page_bottom();
return;
endif;
?>

<div class="card">
  <div class="card-head"><h3>Tarik Data dari e-GURU</h3>
    <div class="kanan"><a class="btn btn-sm" href="sekolah.php?keluar=1">Keluar dari Konteks Sekolah</a></div>
  </div>
  <div class="card-body">
    <?php if (!$tersedia): ?>
      <div class="alert alert-err"><?= e(eguru_galat()) ?></div>
    <?php elseif (!$eSekolah): ?>
      <div class="alert alert-err">
        Lembaga bernama <b><?= e($sekolah['nama']) ?></b> tidak ditemukan pada aplikasi e-GURU.
        Pencocokan memakai <b>nama lembaga</b>, jadi nama pada kedua aplikasi harus sama —
        periksa dan samakan dahulu pada menu <b>Identitas &amp; Pengaturan</b> di CBT dan
        e-GURU, lalu buka kembali halaman ini.
      </div>
    <?php else: ?>
      <div class="alert alert-ok">
        Lembaga cocok: <b><?= e($eSekolah['nama']) ?></b> (e-GURU).
        Data yang tersedia di sana: <b><?= (int) $eRingkas['kelas'] ?></b> kelas,
        <b><?= (int) $eRingkas['mapel'] ?></b> mata pelajaran,
        <b><?= (int) $eRingkas['guru'] ?></b> guru, <b><?= (int) $eRingkas['siswa'] ?></b> siswa.
      </div>
      <?php $tarikTerakhir = trim((string) ($sekolah['eguru_tarik'] ?? '')); ?>
      <div class="chip-list" style="margin:10px 0 12px">
        <?php if ($tarikTerakhir !== ''): ?>
          <span class="pil pil-ok">Data sudah ditarik</span>
          <span class="pil pil-mut">Terakhir ditarik: <?= e(waktu_id($tarikTerakhir)) ?></span>
        <?php else: ?>
          <span class="pil pil-warn">Data belum pernah ditarik</span>
          <span class="pil pil-mut">Guru: <?= (int) $dampak['guru'] ?> &middot; Siswa: <?= (int) $dampak['siswa'] ?></span>
        <?php endif; ?>
      </div>
      <form method="post" data-confirm="Tarik data guru, siswa, kelas, dan mata pelajaran dari e-GURU ke <?= e($sekolah['nama']) ?>?">
        <?= csrf_field() ?>
        <button class="btn btn-utama" type="submit"><?= ikon('impor') ?> Tarik Data Sekarang</button>
      </form>
      <p class="mut" style="margin-top:10px">Menekan tombol di atas akan menyalin
        <b>Kelas &amp; Mata Pelajaran</b>, <b>Data Guru</b> (+ akun memakai <b>akun e-GURU</b>
        — nama pengguna &amp; kata sandi sama), dan <b>Data Siswa</b> (+ akun, kata sandi awal
        <b>siswa123</b>).</p>
    <?php endif; ?>
  </div>
</div>

<?php if ($hasil): ?>
<div class="card">
  <div class="card-head"><h3>Hasil Penarikan Data</h3></div>
  <div class="card-body">
    <div class="alert alert-ok">Data dari lembaga e-GURU <b><?= e($hasil['sekolah_eguru']) ?></b>
      sudah masuk ke <b><?= e($hasil['sekolah']) ?></b>.</div>
    <table class="tbl">
      <thead><tr><th>Data</th><th>Baru</th><th>Diperbarui</th><th>Jumlah di CBT</th></tr></thead>
      <tbody>
        <tr><td>Kelas</td><td><?= (int) $hasil['kelas_baru'] ?></td><td>-</td>
          <td><?= (int) $hasil['kelas_total'] ?></td></tr>
        <tr><td>Mata Pelajaran</td><td><?= (int) $hasil['mapel_baru'] ?></td><td>-</td>
          <td><?= (int) $hasil['mapel_total'] ?></td></tr>
        <tr><td>Guru (beserta akun)</td><td><?= (int) $hasil['guru_baru'] ?></td>
          <td><?= (int) $hasil['guru_diperbarui'] ?></td><td><?= (int) $hasil['guru_total'] ?></td></tr>
        <tr><td>Siswa (beserta akun)</td><td><?= (int) $hasil['siswa_baru'] ?></td>
          <td><?= (int) $hasil['siswa_diperbarui'] ?></td><td><?= (int) $hasil['siswa_total'] ?></td></tr>
      </tbody>
      <tfoot><tr><td>Akun dibuat</td><td colspan="3"><b><?= (int) $hasil['akun_guru'] ?></b> akun guru
        &middot; <b><?= (int) $hasil['akun_siswa'] ?></b> akun siswa</td></tr></tfoot>
    </table>
    <?php if ((int) $hasil['tanpa_kelas'] > 0): ?>
      <div class="alert alert-warn"><?= (int) $hasil['tanpa_kelas'] ?> siswa belum memperoleh kelas
        karena kelasnya tidak ada pada daftar e-GURU. Kelasnya dapat diatur pada menu Data Siswa.</div>
    <?php endif; ?>
    <div class="alert alert-info">
      <b>Akun guru memakai akun e-GURU.</b> Nama pengguna dan kata sandi guru disamakan dengan
      akun e-GURU-nya, jadi guru masuk ke CBT dengan akun yang selama ini dipakai di e-GURU.
      <?php if ((int) $hasil['akun_guru_sinkron'] > 0): ?>
        <br><?= (int) $hasil['akun_guru_sinkron'] ?> akun guru disamakan dengan akun e-GURU.
      <?php endif; ?>
      <?php if ((int) $hasil['akun_guru_tanpa_eguru'] > 0): ?>
        <br><b><?= (int) $hasil['akun_guru_tanpa_eguru'] ?> guru belum punya akun di e-GURU</b> —
        akunnya dibuat dengan sandi awal <b>guru123</b>.
      <?php endif; ?>
      <br>Sandi yang sudah diganti sendiri oleh guru pada menu <b>Akun Saya</b> di CBT
      <b>tidak ditimpa</b> saat menarik data ulang.
    </div>
    <p class="mut">Menarik data ulang aman: data yang sudah ada <b>diperbarui</b>, bukan diduplikasi.</p>
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px">
      <a class="btn" href="guru.php">Lihat Data Guru</a>
      <a class="btn" href="siswa.php">Lihat Data Siswa</a>
      <a class="btn" href="kelas.php">Lihat Kelas</a>
      <a class="btn" href="mapel.php">Lihat Mata Pelajaran</a>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="grid g21">
  <div class="card">
    <div class="card-head"><h3>Cara Kerja</h3></div>
    <div class="card-body">
      <ul class="poin-list">
        <li>Lembaga CBT dicocokkan dengan lembaga e-GURU memakai <b>nama lembaga</b>. Beda huruf
          besar-kecil, tanda titik, atau spasi ganda tidak masalah.</li>
        <li>Yang ditarik sekaligus: <b>Kelas</b>, <b>Mata Pelajaran</b>, <b>Guru</b> (beserta akun),
          dan <b>Siswa</b> (beserta akun) — jadi data tidak perlu diinput manual lagi.</li>
        <li><b>Akun guru memakai akun e-GURU</b>: nama pengguna dan kata sandi guru sama dengan
          yang dipakai di e-GURU, jadi guru tidak perlu akun baru. Bila e-GURU belum punya akun
          untuk guru itu, akun dibuat dengan kata sandi awal <b>guru123</b>.</li>
        <li>Kata sandi yang sudah diganti sendiri oleh guru pada menu <b>Akun Saya</b> di CBT
          <b>tidak ditimpa</b> saat menarik data ulang.</li>
        <li>Akun siswa memakai kata sandi awal <b>siswa123</b>.</li>
        <li>Data yang sudah ada dicocokkan lewat NIP/nama (guru), NIS/NISN/nama (siswa), dan nama
          (kelas &amp; mata pelajaran) — diperbarui, bukan digandakan.</li>
        <li>Mata pelajaran &amp; kelas yang diampu guru ikut ditarik; pengaturan yang sudah ada
          tetap dipertahankan bila e-GURU tidak memuat datanya.</li>
        <li>Data pada aplikasi e-GURU <b>tidak diubah</b> sama sekali (dibaca saja).</li>
      </ul>
    </div>
  </div>
  <div class="card">
    <div class="card-head"><h3>Isi Sekolah Ini Sekarang</h3></div>
    <div class="card-body">
      <table class="tbl">
        <tbody>
          <tr><td>Sekolah/Madrasah</td><td><b><?= e($sekolah['nama']) ?></b></td></tr>
          <tr><td>Jenjang</td><td><?= e(jenjang_label($sekolah['jenjang'] ?? '')) ?></td></tr>
          <tr><td>Kelas</td><td><?= (int) val('SELECT COUNT(*) FROM kelas WHERE sekolah_id = ?', [sekolah_id()], 0) ?></td></tr>
          <tr><td>Mata Pelajaran</td><td><?= (int) val('SELECT COUNT(*) FROM mapel WHERE sekolah_id = ?', [sekolah_id()], 0) ?></td></tr>
          <tr><td>Guru</td><td><?= (int) $dampak['guru'] ?></td></tr>
          <tr><td>Siswa</td><td><?= (int) $dampak['siswa'] ?></td></tr>
          <tr><td>Akun pengguna</td><td><?= (int) $dampak['akun'] ?></td></tr>
        </tbody>
      </table>
      <p class="mut">Belum sempat menarik data? Berkas <b>Ekspor Data Guru</b> / <b>Ekspor Data
        Siswa</b> dari e-GURU juga dapat diimpor lewat menu <a href="guru_import.php">Impor Data
        Guru</a> dan <a href="siswa_import.php">Impor Data Siswa</a>.</p>
    </div>
  </div>
</div>
<?php page_bottom();
