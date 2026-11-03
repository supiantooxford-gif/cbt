<?php
/**
 * BIAYA & AKTIVASI — halaman pengaturan Super Admin.
 *
 * Permintaan pemilik: *"Pada bagian status dan aktivasi di akun operator buat seperti yang ada di
 * e-GURU ada fitur untuk memasukkan kode aktivasi, permintaan kode aktivasi, nominal, dan nomor
 * rekening. Yang semuanya bisa diatur lewat akun Super Admin."*
 *
 * Yang diatur di sini (TINGKAT APLIKASI — dipakai seluruh sekolah/madrasah):
 *   1. nominal **biaya aktivasi pertama** (masa aktif 1 tahun);
 *   2. nominal **biaya langganan** tahun berikutnya (perpanjangan);
 *   3. **nomor WA HD Admin** tujuan permohonan kode aktivasi & bukti transfer;
 *   4. **nomor rekening** tujuan transfer;
 *   5. **keterangan** nomor rekening (peringatan agar transfer hanya ke rekening itu).
 *
 * Disimpan di tabel `app_pengaturan`; konstanta di `inc/config.php` hanya NILAI BAWAAN dan dipakai
 * kembali oleh tombol "Kembalikan Bawaan".
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

require_super();
// Halaman lintas sekolah/madrasah → istilah generik "Sekolah".
$GLOBALS['__vs_istilah_nonaktif'] = true;

$galat = [];
$tampilkanForm = false; // true = validasi gagal → halaman dirender ulang dengan isian dikembalikan

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $aksi = teks($_POST['aksi'] ?? '', 20);

    if ($aksi === 'simpan') {
        // Nominal ditulis bebas ("1.000.000" / "1000000") → hanya angkanya yang dipakai.
        $pertamaRaw = (string) ($_POST['aktivasi_biaya'] ?? '');
        $langgananRaw = (string) ($_POST['aktivasi_biaya_langganan'] ?? '');
        $waRaw = trim((string) ($_POST['aktivasi_wa'] ?? ''));
        $rekening = teks($_POST['aktivasi_rekening'] ?? '', AKTIVASI_REKENING_MAKS);
        $ket = teks($_POST['aktivasi_rekening_ket'] ?? '', AKTIVASI_REKENING_KET_MAKS);

        $pertama = (int) preg_replace('/\D/', '', $pertamaRaw);
        $langganan = (int) preg_replace('/\D/', '', $langgananRaw);

        if (preg_replace('/\D/', '', $pertamaRaw) === '') {
            $galat[] = 'Nominal biaya aktivasi pertama wajib diisi (angka rupiah).';
        } elseif ($pertama > AKTIVASI_BIAYA_MAKS) {
            $galat[] = 'Nominal biaya aktivasi pertama terlalu besar (maksimal ' . rupiah(AKTIVASI_BIAYA_MAKS) . ').';
        }
        if (preg_replace('/\D/', '', $langgananRaw) === '') {
            $galat[] = 'Nominal biaya langganan wajib diisi (angka rupiah).';
        } elseif ($langganan > AKTIVASI_BIAYA_MAKS) {
            $galat[] = 'Nominal biaya langganan terlalu besar (maksimal ' . rupiah(AKTIVASI_BIAYA_MAKS) . ').';
        }
        if ($pertama > 0 && $langganan > $pertama) {
            $galat[] = 'Biaya langganan (' . rupiah($langganan) . ') lebih besar daripada biaya aktivasi '
                . 'pertama (' . rupiah($pertama) . '). Periksa kembali nominalnya.';
        }
        if ($waRaw === '') {
            $galat[] = 'Nomor WA HD Admin wajib diisi.';
        } elseif (!aktivasi_wa_sah($waRaw)) {
            $galat[] = 'Nomor WA HD Admin belum benar — tulis angka saja (boleh diawali 0 atau 62), '
                . '9 sampai ' . (int) AKTIVASI_WA_MAKS . ' digit, mis. 08xxxxxxxxxx.';
        }
        if ($rekening === '') {
            $galat[] = 'Nomor rekening wajib diisi (mis. "Bank Mandiri 1500010135547. An. Supianto").';
        }

        if ($galat) {
            // PENTING: jangan mengalihkan halaman saat validasi gagal — pesan galat dan isian
            // pengguna harus tetap terlihat (pola $tampilkanForm).
            $tampilkanForm = true;
        } else {
            aktivasi_biaya_simpan($pertama, $langganan, $waRaw, $rekening, $ket);
            flash('Pengaturan aktivasi disimpan: biaya aktivasi pertama ' . rupiah($pertama)
                . ', biaya langganan ' . rupiah($langganan) . ', nomor WA HD Admin ' . aktivasi_wa()
                . ', rekening ' . aktivasi_rekening() . '. Halaman Status & Aktivasi seluruh sekolah, '
                . 'tombol permohonan kode aktivasi, dan tombol bukti transfer langsung memakai nilai baru ini.');
            redirect('biaya_aktivasi.php');
        }
    } elseif ($aksi === 'bawaan') {
        aktivasi_biaya_bawaan();
        flash('Biaya aktivasi, biaya langganan, nomor WA HD Admin, dan nomor rekening dikembalikan ke '
            . 'bawaan aplikasi.', 'warn');
        redirect('biaya_aktivasi.php');
    } else {
        redirect('biaya_aktivasi.php');
    }
}

// Isian: dari POST saat validasi gagal, kalau tidak dari pengaturan tersimpan.
$pertama = $tampilkanForm ? (string) ($_POST['aktivasi_biaya'] ?? '') : (string) aktivasi_biaya_pertama();
$langganan = $tampilkanForm ? (string) ($_POST['aktivasi_biaya_langganan'] ?? '') : (string) aktivasi_biaya_langganan();
$wa = $tampilkanForm ? trim((string) ($_POST['aktivasi_wa'] ?? '')) : aktivasi_wa();
$rekening = $tampilkanForm ? (string) ($_POST['aktivasi_rekening'] ?? '') : aktivasi_rekening();
$ket = $tampilkanForm ? (string) ($_POST['aktivasi_rekening_ket'] ?? '') : aktivasi_rekening_ket();
$pakaiBawaan = aktivasi_biaya_masih_bawaan();

page_top('Biaya & Aktivasi');
?>
<div class="card">
  <div class="card-head">
    <h3>Biaya &amp; Aktivasi</h3>
    <div class="sub">Nominal biaya, nomor WA HD Admin, dan nomor rekening &mdash; berlaku untuk semua
      sekolah/madrasah</div>
    <div class="kanan">
      <?php if ($pakaiBawaan): ?><span class="pil pil-info">masih bawaan</span>
      <?php else: ?><span class="pil pil-ok">sudah disesuaikan</span><?php endif; ?>
      <?php if (!$pakaiBawaan): ?>
        <form method="post" data-confirm="Kembalikan nominal biaya, nomor WA, dan nomor rekening ke bawaan aplikasi?">
          <?= csrf_field() ?>
          <input type="hidden" name="aksi" value="bawaan">
          <button class="btn btn-sm btn-bahaya" type="submit">Kembalikan Bawaan</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
  <div class="card-body">
    <p class="mut">Nilai di bawah ini dipakai pada menu <b>Status &amp; Aktivasi</b> di akun Operator
      seluruh sekolah: <b>formulir kode aktivasi</b>, <b>tombol permohonan kode aktivasi</b>,
      <b>nominal biaya</b>, <b>tombol kirim bukti transfer</b>, dan <b>nomor rekening</b>.</p>

    <?php foreach ($galat as $g): ?>
      <div class="alert alert-err"><?= e($g) ?></div>
    <?php endforeach; ?>

    <form method="post" id="formBiayaAktivasi">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="simpan">

      <p class="section-title">Nominal Biaya</p>
      <div class="form-grid f2">
        <div class="field">
          <label for="aktivasi_biaya">Biaya Aktivasi Pertama</label>
          <input type="text" inputmode="numeric" id="aktivasi_biaya" name="aktivasi_biaya" required
                 value="<?= e($pertama) ?>" placeholder="mis. 1000000">
          <div class="ket">Nominal untuk <b>aktivasi pertama</b> (masa aktif
            <?= (int) AKTIVASI_HARI_AKTIF ?> hari). Boleh ditulis dengan titik pemisah, mis.
            <b>1.000.000</b>.</div>
        </div>
        <div class="field">
          <label for="aktivasi_biaya_langganan">Biaya Langganan</label>
          <input type="text" inputmode="numeric" id="aktivasi_biaya_langganan" name="aktivasi_biaya_langganan"
                 required value="<?= e($langganan) ?>" placeholder="mis. 500000">
          <div class="ket">Nominal <b>perpanjangan</b> — muncul sebagai info "biaya langganan … untuk
            tahun depan" pada lembaga yang sudah pernah diaktivasi.</div>
        </div>
      </div>

      <div class="divider"></div>
      <p class="section-title">Kontak &amp; Rekening</p>
      <div class="form-grid f2">
        <div class="field">
          <label for="aktivasi_wa">Nomor WA HD Admin</label>
          <input type="text" inputmode="tel" id="aktivasi_wa" name="aktivasi_wa" required
                 value="<?= e($wa) ?>" placeholder="mis. 085240102180">
          <div class="ket">Tujuan <b>permohonan kode aktivasi</b> sekaligus <b>bukti transfer</b>.
            Boleh diawali <b>0</b> atau <b>62</b>.</div>
        </div>
        <div class="field">
          <label for="aktivasi_rekening">Nomor Rekening</label>
          <input type="text" id="aktivasi_rekening" name="aktivasi_rekening" required
                 maxlength="<?= (int) AKTIVASI_REKENING_MAKS ?>"
                 value="<?= e($rekening) ?>" placeholder="mis. Bank Mandiri 1500010135547. An. Supianto">
          <div class="ket">Dipakai pada kalimat permintaan transfer dan pesan WhatsApp bukti transfer.</div>
        </div>
      </div>
      <div class="field">
        <label for="aktivasi_rekening_ket">Keterangan Nomor Rekening</label>
        <textarea id="aktivasi_rekening_ket" name="aktivasi_rekening_ket" rows="3"
                  maxlength="<?= (int) AKTIVASI_REKENING_KET_MAKS ?>"><?= e($ket) ?></textarea>
        <div class="ket">Ditampilkan di bawah nomor rekening pada halaman Status &amp; Aktivasi.</div>
      </div>

      <div class="form-kaki">
        <button class="btn btn-utama" type="submit">Simpan Pengaturan</button>
        <span class="mut" style="font-size:.78rem">Berlaku untuk <b>semua sekolah/madrasah</b> —
          tidak perlu disetel ulang per lembaga.</span>
      </div>
    </form>
  </div>
</div>

<?php // PRATINJAU: apa yang akan dilihat pengelola sekolah, supaya Super Admin tahu hasilnya
      // tanpa berpindah halaman. ?>
<div class="card">
  <div class="card-head">
    <h3>Pratinjau di Halaman Status &amp; Aktivasi</h3>
    <div class="sub">Kalimat, tombol, dan nomor rekening yang tampil pada akun Operator sekolah</div>
  </div>
  <div class="card-body">
    <div class="permohonan-aktivasi" id="pratinjauAktivasi">
      <div class="permohonan-judul">Aktivasi Sekolah/Madrasah</div>
      <p class="permohonan-ajak"><b>Hubungi HD Admin untuk melakukan aktifasi.</b> Kirim permohonan kode aktifasi lewat tombol di bawah ini</p>
      <a class="btn btn-utama btn-permohonan" href="<?= e(aktivasi_tautan_permohonan()) ?>"
         target="_blank" rel="noopener noreferrer">Kirim Permohonan Kode Aktifasi</a>

      <div class="biaya-aktivasi" id="biayaAktivasi">
        <div class="biaya-kepala">
          <span class="biaya-ikon"><?= ikon('kunci', 18) ?></span>
          <div>
            <span class="biaya-label"><?= e(aktivasi_biaya_label()) ?></span>
            <b class="biaya-nominal" id="nominalAktivasi"><?= e(rupiah(aktivasi_biaya_pertama())) ?></b>
          </div>
        </div>
        <p class="biaya-kalimat" id="kalimatBiaya"><?= e(aktivasi_kalimat_biaya()) ?></p>
        <a class="btn btn-utama btn-bukti" id="btnKirimBukti" href="<?= e(aktivasi_tautan_bukti()) ?>"
           target="_blank" rel="noopener noreferrer">Kirim Bukti Transfer ke WA</a>
        <p class="biaya-tarif">Aktivasi pertama (masa aktif <?= (int) AKTIVASI_HARI_AKTIF ?> hari):
          <b><?= e(rupiah(aktivasi_biaya_pertama())) ?></b> &middot;
          Langganan tahun berikutnya: <b><?= e(rupiah(aktivasi_biaya_langganan())) ?></b>.</p>
      </div>

      <div class="alert alert-ok biaya-langganan" id="infoLangganan">
        Info yang muncul pada lembaga yang <b>sudah diaktivasi</b>: <b>biaya langganan
        <?= e(rupiah(aktivasi_biaya_langganan())) ?> untuk tahun depan</b> — baris tarif di atas
        berubah menjadi hanya nominal langganan.
      </div>

      <div class="rekening-aktivasi">
        <div class="rekening-baris">
          <span class="rekening-ikon"><?= ikon('kunci', 18) ?></span>
          <div>
            <span class="rekening-label">Nomor Rekening untuk Aktifasi</span>
            <b id="rekeningTeks"><?= e(aktivasi_rekening()) ?></b>
          </div>
          <button class="btn btn-sm" type="button" data-salin="#rekeningTeks">Salin</button>
        </div>
        <p class="rekening-ket"><?= e(aktivasi_rekening_ket()) ?></p>
      </div>
    </div>

    <div class="table-wrap mt16">
      <table class="tbl">
        <tr><th style="width:38%">Tautan WA permohonan kode aktivasi</th>
          <td id="waTeks"><?= e('https://wa.me/' . wa_internasional(aktivasi_wa())) ?></td></tr>
        <tr><th>Tautan WA bukti transfer</th>
          <td><?= e('https://wa.me/' . wa_internasional(aktivasi_wa())) ?></td></tr>
      </table>
    </div>
    <p class="mut" style="font-size:.78rem;margin:10px 0 0">
      Nomor WA dipakai untuk KEDUA tombol (permohonan kode aktivasi &amp; bukti transfer). Bila
      nomornya berganti, ubah pada formulir di atas.
    </p>
  </div>
</div>
<?php page_bottom();
