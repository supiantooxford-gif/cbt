<?php
/**
 * STATUS & AKTIVASI sekolah/madrasah.
 *
 * Permintaan pemilik: *"Pada bagian status dan aktivasi di akun operator buat seperti yang ada di
 * e-GURU ada fitur untuk memasukkan kode aktivasi, permintaan kode aktivasi, nominal, dan nomor
 * rekening. Yang semuanya bisa diatur lewat akun Super Admin."*
 *
 * Isi halaman:
 *   1. keadaan lembaga (permanen / masa aktif berjalan / berakhir);
 *   2. **formulir kode aktivasi** — bisa dipakai untuk aktivasi pertama MAUPUN perpanjangan
 *      (kode bergantung periode, jadi satu kode hanya sah sekali pakai);
 *   3. **Aktivasi Sekolah/Madrasah** — permohonan kode aktivasi lewat WhatsApp, **nominal biaya**,
 *      tombol kirim **bukti transfer**, dan **nomor rekening** beserta keterangannya;
 *   4. kartu "Cara Kerja Aktivasi" di kolom kanan;
 *   5. khusus Super Admin: kode aktivasi lembaga yang berlaku (tidak pernah ditampilkan ke pengelola).
 *
 * Nominal, nomor WA, dan nomor rekening dibaca lewat `aktivasi_biaya_*()`/`aktivasi_wa()`/
 * `aktivasi_rekening()` sehingga perubahan Super Admin di menu **Biaya & Aktivasi**
 * (`biaya_aktivasi.php`) langsung terpakai di sini.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

$u = require_login();
$s = current_sekolah();

if (!$s) {
    if (is_super()) {
        flash('Pilih sekolah terlebih dahulu untuk melihat status aktivasi.', 'warn');
        redirect('sekolah.php');
    }
    redirect('index.php');
}

$st = aktivasi_status($s);
$kunci = aktivasi_terkunci($s);
$pernahAktif = aktivasi_pernah_aktif($s);
$galat = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Hak akses diperiksa SEBELUM CSRF (peran lain tidak pernah melihat formulirnya).
    if (!boleh_aktivasi()) {
        tolak403('Hanya Admin Operator sekolah atau Super Admin yang dapat memasukkan kode aktivasi.');
    }
    csrf_guard();
    $aksi = teks($_POST['aksi'] ?? '', 20);

    if ($aksi === 'aktifkan') {
        if (aktivasi_coba_habis()) {
            $galat = 'Percobaan memasukkan kode sudah terlalu banyak. Coba lagi dalam '
                . (int) AKTIVASI_MENIT_COBA . ' menit.';
        } else {
            $kode = teks($_POST['kode'] ?? '', 40);
            if (kode_aktivasi_cocok($s, $kode)) {
                // "Perpanjangan" = masa aktif masih berjalan DAN lembaga sudah pernah diaktivasi.
                // Sekolah yang baru pertama kali diaktivasi (atau diaktivasi ulang setelah masa
                // aktifnya habis) mendapat pesan "AKTIF".
                $perpanjang = !$kunci && $pernahAktif;
                aktivasi_aktifkan((int) $s['id'], AKTIVASI_HARI_AKTIF);
                aktivasi_coba_bersih();
                flash($perpanjang
                    ? 'Selamat! Masa aktif sekolah/madrasah Anda DIPERPANJANG ' . (int) AKTIVASI_HARI_AKTIF
                        . ' hari. Kode yang barusan dipakai sudah tidak berlaku lagi — kode berikutnya '
                        . 'diminta kepada Admin aplikasi bila masa aktifnya habis kembali.'
                    : 'Selamat! Sekolah/madrasah Anda AKTIF. Seluruh fitur terbuka untuk '
                        . (int) AKTIVASI_HARI_AKTIF . ' hari ke depan.', 'ok');
                redirect('aktivasi.php');
            }
            $sisa = aktivasi_coba_tambah();
            $pesan = 'Kode aktivasi tidak cocok untuk sekolah ini.';
            $pesan .= $sisa > 0 ? ' Sisa percobaan: ' . $sisa . ' kali.' : ' Percobaan sudah habis.';
            flash($pesan, 'err');
            redirect('aktivasi.php');
        }
    } elseif ($aksi === 'batasi') {
        // Hanya Super Admin yang boleh mengosongkan masa aktif (peran lain diabaikan, bukan 403,
        // karena mereka tetap berhak berada di halaman ini).
        if (is_super()) {
            q('UPDATE sekolah SET aktif_sampai = NULL, aktivasi = ? WHERE id = ?', ['percobaan', (int) $s['id']]);
            flash('Masa aktif dikosongkan — sekolah kembali ke keadaan percobaan.', 'warn');
        }
        redirect('aktivasi.php');
    } else {
        redirect('aktivasi.php');
    }
}

$s = current_sekolah(true);
$st = aktivasi_status($s);
$kunci = aktivasi_terkunci($s);
$pernahAktif = aktivasi_pernah_aktif($s);
$boleh = boleh_aktivasi();
$kode = is_super() ? kode_aktivasi_sekolah($s) : '';
$wa = aktivasi_wa();
$rekening = aktivasi_rekening();
$nominal = aktivasi_biaya_berlaku($s);

page_top('Status & Aktivasi');
?>
<?php if ($kunci): ?>
  <div class="alert alert-err">
    <b>Aplikasi terkunci.</b> <?= e(aktivasi_terkunci_pesan()) ?>
  </div>
<?php endif; ?>

<div class="grid g21">
  <div>
    <div class="card">
      <div class="card-head">
        <h3>Status Aktivasi Lembaga</h3>
        <div class="kanan">
          <?php if ($st['keadaan'] === 'permanen'): ?>
            <span class="pil pil-ok">Aktif permanen</span>
          <?php elseif ($st['keadaan'] === 'uji_coba'): ?>
            <span class="pil pil-info">Masa aktif <?= (int) $st['sisa_hari'] ?> hari lagi</span>
          <?php else: ?>
            <span class="pil pil-err">Masa aktif habis</span>
          <?php endif; ?>
        </div>
      </div>
      <div class="card-body">
        <table class="tbl">
          <tr><th style="width:38%">Sekolah/Madrasah</th><td><?= e($s['nama']) ?></td></tr>
          <tr><th>Jenjang</th><td><?= e(jenjang_label((string) $s['jenjang'])) ?></td></tr>
          <tr><th>Keadaan</th><td>
            <?php if ($st['keadaan'] === 'permanen'): ?>Aktif permanen (tanpa batas waktu)
            <?php elseif ($st['keadaan'] === 'uji_coba'): ?>Masa aktif berjalan sampai <b><?= e(tanggal_id(substr((string) $st['sampai'], 0, 10))) ?></b>
            <?php else: ?>Masa aktif berakhir pada <?= e(tanggal_id(substr((string) $st['sampai'], 0, 10))) ?>
            <?php endif; ?>
          </td></tr>
          <tr><th>Masa aktif setelah diaktivasi</th><td><?= (int) AKTIVASI_HARI_AKTIF ?> hari (1 tahun)</td></tr>
          <tr><th>Nominal yang berlaku sekarang</th>
            <td><?= e(aktivasi_biaya_label($s)) ?>: <b><?= e(rupiah($nominal)) ?></b></td></tr>
        </table>
      </div>
    </div>

    <?php // ------------------------------------------------------------------ HITUNG MUNDUR
          // Permintaan pemilik: masa aktif sekolah 365 hari harus terlihat berjalan
          // (hitung mundur), bukan "permanen". Nilai awal DICETAK PHP supaya tetap terbaca bila
          // skrip gagal dimuat; `initHitungMundur()` (assets/app.js) memperbaruinya tiap detik
          // memakai acuan WAKTU SERVER (data-berakhir + data-sekarang), bukan jam perangkat. ?>
    <?php if ($st['keadaan'] === 'uji_coba'):
      $sisa = (int) $st['sisa_detik'];
      $hh = (int) floor($sisa / 86400);
      $jj = (int) floor(($sisa % 86400) / 3600);
      $mm = (int) floor(($sisa % 3600) / 60);
      $dd = (int) ($sisa % 60);
      $dua = fn(int $n): string => str_pad((string) $n, 2, '0', STR_PAD_LEFT);
      $sisaTeks = 'Sisa ' . $hh . ' hari ' . $dua($jj) . ':' . $dua($mm) . ':' . $dua($dd);
    ?>
      <div class="card">
        <div class="card-body">
          <div class="hitung-mundur" id="hitungMundurAktivasi" data-hitung-mundur
               data-berakhir="<?= (int) $st['sampai_ts'] ?>" data-sekarang="<?= time() ?>">
            <div class="hitung-mundur-head">
              <span class="hitung-mundur-judul"><?= ikon('jam', 15) ?> Hitung mundur masa aktif</span>
              <span class="hitung-mundur-ket" aria-live="polite"><?= e($sisaTeks) ?></span>
            </div>
            <div class="hitung-mundur-unit">
              <div class="hm-unit"><b data-hm="hari"><?= $hh ?></b><span>Hari</span></div>
              <div class="hm-unit"><b data-hm="jam"><?= $dua($jj) ?></b><span>Jam</span></div>
              <div class="hm-unit"><b data-hm="menit"><?= $dua($mm) ?></b><span>Menit</span></div>
              <div class="hm-unit"><b data-hm="detik"><?= $dua($dd) ?></b><span>Detik</span></div>
            </div>
            <div class="hitung-mundur-kaki">Masa aktif <b><?= (int) AKTIVASI_HARI_AKTIF ?> hari (1 tahun)</b>
              berakhir pada <b><?= e(tanggal_id(substr((string) $st['sampai'], 0, 10))) ?></b>.
              Setelah itu aplikasi kembali ke mode terbatas sampai kode aktivasi yang baru dari
              Admin aplikasi dimasukkan<?= $wa !== '' ? ' (WA ' . e($wa) . ')' : '' ?>.</div>
          </div>
        </div>
      </div>
    <?php elseif ($st['keadaan'] === 'kadaluarsa'): ?>
      <div class="card">
        <div class="card-body">
          <div class="hitung-mundur habis">
            <div class="hitung-mundur-head">
              <span class="hitung-mundur-judul"><?= ikon('jam', 15) ?> Masa aktif sudah berakhir</span>
            </div>
            <div class="hitung-mundur-kaki">Masa aktif berakhir pada
              <b><?= e(tanggal_id(substr((string) $st['sampai'], 0, 10))) ?></b>. Seluruh fitur
              terkunci sampai kode aktivasi baru dimasukkan<?= $wa !== '' ? ' — minta kepada Admin
              aplikasi (WA ' . e($wa) . ')' : '' ?>.</div>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <?php // ------------------------------------------------------------------ FORMULIR KODE AKTIVASI
          // Satu formulir untuk DUA keperluan: membuka aplikasi yang terkunci (aktivasi pertama /
          // setelah masa aktif habis) dan memperpanjang masa aktif yang masih berjalan. Kode
          // aktivasi bergantung PERIODE → setelah dipakai, kode itu tidak berlaku lagi. ?>
    <div class="card" id="cardKode"<?= $kunci ? ' style="border-color:#f0c4c1"' : '' ?>>
      <div class="card-head">
        <h3><?php
          if ($kunci) { echo 'Aplikasi Terkunci &mdash; Aktivasi Kembali';
          } elseif ($pernahAktif) { echo 'Kode Aktivasi Perpanjangan';
          } else { echo 'Kode Aktivasi'; } ?></h3>
        <div class="sub"><?= $kunci
          ? 'Data sekolah Anda <b>tidak dihapus</b> — masukkan kode dari Admin aplikasi untuk membuka seluruh fitur kembali'
          : 'Masukkan kode aktivasi yang dikirim Admin aplikasi' ?></div>
      </div>
      <div class="card-body">
        <?php if ($galat !== ''): ?>
          <div class="alert alert-err"><?= e($galat) ?></div>
        <?php endif; ?>
        <?php if ($st['keadaan'] === 'permanen'): ?>
          <?php // Sekolah yang aktif permanen TIDAK perlu kode aktivasi — formulir kode tidak
                // ditampilkan supaya tidak ada yang tidak sengaja menurunkan statusnya menjadi
                // berbatas waktu. ?>
          <div class="alert alert-ok" style="margin-bottom:0">Aplikasi ini <b>aktif permanen</b> —
            tidak ada masa aktif yang perlu diperpanjang, sehingga <b>kode aktivasi tidak diperlukan</b>.<?= $wa !== ''
              ? ' Bila kelak masa aktif diberlakukan, kode aktivasi diminta kepada Admin aplikasi (WA ' . e($wa) . ').' : '' ?></div>
        <?php elseif ($boleh): ?>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="aksi" value="aktifkan">
            <div class="field">
              <label for="kode"><?= $pernahAktif && !$kunci ? 'Kode Aktivasi Perpanjangan' : 'Kode Aktivasi' ?></label>
              <input type="text" id="kode" name="kode" placeholder="XXXX-XXXX-XXXX" required
                     autocomplete="off" style="letter-spacing:.12em;text-transform:uppercase"
                     <?= $kunci ? 'autofocus' : '' ?>>
              <div class="ket">
                <?php if ($kunci): ?>
                  Kode aktivasi periode baru — huruf besar/kecil, spasi, dan tanda hubung tidak masalah.
                <?php elseif ($pernahAktif): ?>
                  Masa aktif <b><?= (int) AKTIVASI_HARI_AKTIF ?> hari</b> akan <b>ditambahkan</b> dari
                  <?= ($st['keadaan'] === 'uji_coba' && !empty($st['sampai']))
                      ? '<b>tanggal berakhir yang sedang berjalan</b> (' . e(tanggal_id(substr((string) $st['sampai'], 0, 10))) . ')'
                      : '<b>hari ini</b>' ?> — sisa waktu yang sudah dibayar tidak hangus.
                <?php else: ?>
                  Kode aktivasi lembaga — huruf besar/kecil, spasi, dan tanda hubung tidak masalah.
                <?php endif; ?>
              </div>
            </div>
            <button class="btn btn-utama" type="submit"><?= ($pernahAktif && !$kunci)
              ? 'Perpanjang Masa Aktif' : 'Aktifkan Sekarang' ?></button>
          </form>
          <?php // Permintaan pemilik: kalimat ini cukup sampai "tidak berlaku lagi" — keterangan
                // tentang kode yang berganti di akun Super Admin TIDAK ditampilkan lagi. ?>
          <p class="ket" style="margin:12px 0 0"><b>Setiap kode hanya dapat dipakai satu kali.</b> Setelah dipakai, kode tersebut tidak berlaku lagi.</p>
        <?php else: ?>
          <div class="alert alert-info" style="margin-bottom:0">Kode aktivasi dimasukkan oleh
            <b>Admin Operator</b> sekolah/madrasah Anda pada menu Status &amp; Aktivasi<?= $wa !== ''
              ? ' — kodenya diminta kepada Admin aplikasi (WA ' . e($wa) . ')' : '' ?>.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div>
    <?php if (is_super()): ?>
      <div class="card">
        <div class="card-head"><h3>Kode Aktivasi Lembaga</h3>
          <div class="sub">Hanya terlihat Super Admin</div></div>
        <div class="card-body">
          <p class="mut">Kode ini berlaku untuk <b>periode ke-<?= (int) $s['aktivasi_ke'] ?></b> dan
          memberikan masa aktif <?= (int) AKTIVASI_HARI_AKTIF ?> hari. Setelah dipakai, kode berganti
          sendiri (satu kode hanya sah sekali pakai). Kode ini juga dapat dimasukkan pengelola sekolah
          sebagai kode perpanjangan selagi masa aktif masih berjalan.</p>
          <div class="kotak-kode" id="kodeLembaga"><?= e($kode) ?></div>
          <div class="form-kaki">
            <button class="btn btn-sm" type="button" data-salin="#kodeLembaga">Salin Kode</button>
          </div>
          <?php if ($st['keadaan'] === 'uji_coba'): ?>
            <form method="post" class="mt16" data-confirm="Kembalikan sekolah ini ke keadaan percobaan (masa aktif dikosongkan)?">
              <?= csrf_field() ?>
              <input type="hidden" name="aksi" value="batasi">
              <button class="btn btn-bahaya btn-sm" type="submit">Kosongkan Masa Aktif</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($boleh): ?>
      <?php // ---------------------------------------------------------------- AKTIVASI SEKOLAH/MADRASAH
            // Permintaan pemilik (sama seperti e-GURU): "Hubungi HD Admin untuk melakukan aktifasi.
            // Kirim permohonan kode aktifasi … tambahkan Nomer Rekening … dan keterangannya",
            // ditambah NOMINAL biaya + tombol bukti transfer. Seluruh nilainya diatur Super Admin
            // pada menu "Biaya & Aktivasi". ?>
      <div class="card permohonan-aktivasi" id="permohonanAktivasi">
        <?php // Permintaan pemilik: keterangan kecil di sebelah judul ("Permohonan kode aktivasi,
              // biaya, dan rekening") DIHAPUS. ?>
        <div class="card-head"><h3>Aktivasi Sekolah/Madrasah</h3></div>
        <div class="card-body">
          <?php // Permintaan pemilik: kalimat ajakan berhenti pada "…lewat tombol di bawah ini"
                // (tanpa menyebut nomor WA-nya). ?>
          <p class="permohonan-ajak"><b>Hubungi HD Admin untuk melakukan aktifasi.</b> Kirim permohonan kode aktifasi lewat tombol di bawah ini</p>
          <?php if ($wa !== ''): ?>
            <a class="btn btn-utama btn-permohonan" href="<?= e(aktivasi_tautan_permohonan($s)) ?>"
               target="_blank" rel="noopener noreferrer">Kirim Permohonan Kode Aktifasi</a>
          <?php endif; ?>

          <?php // ---------------------------------------------------------------- NOMINAL BIAYA ?>
          <div class="biaya-aktivasi" id="biayaAktivasi">
            <div class="biaya-kepala">
              <span class="biaya-ikon"><?= ikon('kunci', 18) ?></span>
              <div>
                <span class="biaya-label" id="biayaLabel"><?= e(aktivasi_biaya_label($s)) ?></span>
                <b class="biaya-nominal" id="nominalAktivasi"><?= e(rupiah($nominal)) ?></b>
              </div>
            </div>
            <p class="biaya-kalimat" id="kalimatBiaya"><?= e(aktivasi_kalimat_biaya($s)) ?></p>
            <?php if ($wa !== ''): ?>
              <a class="btn btn-utama btn-bukti" id="btnKirimBukti" href="<?= e(aktivasi_tautan_bukti($s)) ?>"
                 target="_blank" rel="noopener noreferrer">Kirim Bukti Transfer ke WA</a>
            <?php endif; ?>
            <?php // Biaya aktivasi pertama HANYA ditampilkan bagi lembaga yang belum pernah diaktifasi
                  // (yang sudah pernah cukup melihat biaya langganan tahun depan). ?>
            <p class="biaya-tarif">
              <?php if ($pernahAktif): ?>
                Langganan tahun berikutnya: <b><?= e(rupiah(aktivasi_biaya_langganan())) ?></b>
                (masa aktif <?= (int) AKTIVASI_HARI_AKTIF ?> hari).
              <?php else: ?>
                Aktivasi pertama (masa aktif <?= (int) AKTIVASI_HARI_AKTIF ?> hari):
                <b><?= e(rupiah(aktivasi_biaya_pertama())) ?></b> &middot;
                Langganan tahun berikutnya: <b><?= e(rupiah(aktivasi_biaya_langganan())) ?></b>.
              <?php endif; ?>
            </p>
          </div>

          <?php if ($pernahAktif): ?>
            <div class="alert alert-ok biaya-langganan" id="infoLangganan">
              Aplikasi ini <b>sudah diaktifasi</b> &mdash; <b>biaya langganan
              <?= e(rupiah(aktivasi_biaya_langganan())) ?> untuk tahun depan</b>.
              Pembayaran langganan dilakukan menjelang masa aktif berakhir, lalu kode aktivasi
              perpanjangannya diminta kepada Admin aplikasi.
            </div>
          <?php endif; ?>

          <div class="rekening-aktivasi">
            <div class="rekening-baris">
              <span class="rekening-ikon"><?= ikon('kunci', 18) ?></span>
              <div>
                <span class="rekening-label">Nomor Rekening untuk Aktifasi</span>
                <b id="rekeningTeks"><?= e($rekening) ?></b>
              </div>
              <button class="btn btn-sm" type="button" data-salin="#rekeningTeks">Salin</button>
            </div>
            <?php if (aktivasi_rekening_ket() !== ''): ?>
              <p class="rekening-ket"><?= e(aktivasi_rekening_ket()) ?></p>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-head"><h3>Cara Kerja Aktivasi</h3>
        <div class="sub">Ringkasan singkat untuk pengelola lembaga</div></div>
      <div class="card-body">
        <ol class="poin-list">
          <li><b>Terdaftar</b> — sekolah/madrasah dibuat Admin aplikasi dengan masa aktif
            <?= (int) AKTIVASI_HARI_AKTIF ?> hari (1 tahun).</li>
          <li><b>Permohonan aktifasi</b> — setelah itu, minta kode aktivasi kepada Admin aplikasi
            <?= $wa !== '' ? '(WA ' . e($wa) . ')' : '' ?> lewat tombol di atas.</li>
          <li><b>Transfer &amp; verifikasi</b> — transfer <b><?= e(rupiah($nominal)) ?></b> ke
            <b><?= e($rekening) ?></b>, lalu kirim bukti transfernya ke WA Admin aplikasi.</li>
          <li><b>Masukkan kode</b> — kode yang dikirim Admin aplikasi dimasukkan pada formulir
            <b>Kode Aktivasi</b>; seluruh fitur langsung terbuka
            <?= (int) AKTIVASI_HARI_AKTIF ?> hari.</li>
          <li><b>Masa aktif habis</b> — aplikasi terkunci sementara (data tetap aman) sampai kode
            aktivasi periode baru dimasukkan. Minta kode barunya kembali kepada Admin aplikasi.</li>
        </ol>
      </div>
    </div>
  </div>
</div>
<?php page_bottom();
