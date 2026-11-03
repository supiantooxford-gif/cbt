<?php
/**
 * Impor soal ujian dari TEMPLATE TEKS SEDERHANA (.docx) — dan tetap mendukung template LAMA
 * (tabel Word) serta Excel/CSV yang sudah dipakai sebelumnya.
 *
 * Template BARU (v1.13.0, permintaan pemilik "agar mempermudah guru dalam mengisi template soal"):
 * guru hanya menulis tulisan biasa — judul bagian ("Template Soal Pilihan Ganda", …), soal bernomor,
 * pilihan A./B./C., lalu baris "Kunci: …". Pembacaannya ada di `inc/impor_soal.php`.
 *
 * Jalur impor:
 *   1. berkas Word (.docx MAUPUN .doc) → tabel ditemukan = template LAMA; bila tidak = template TEKS
 *   2. tempel teks (tanpa berkas) → template TEKS baru
 *   3. berkas Excel/CSV (dibaca di peramban, dikirim sebagai JSON) → susunan kolom lama
 *
 * v1.18.0 (permintaan pemilik): **berkas Word LAMA (.doc) kini juga diterima**. Format .doc adalah
 * wadah OLE2 biner (bukan ZIP), dibaca oleh `inc/doc.php` — lihat komentar di modul itu. Berkas
 * yang hanya DIGANTI NAMANYA (mis. .docx → .doc) tetap terbaca karena jenisnya dikenali dari ISI
 * berkas, bukan dari ekstensinya.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';
require_once __DIR__ . '/inc/docx.php';
require_once __DIR__ . '/inc/doc.php';
require_once __DIR__ . '/inc/impor_soal.php';

$u = require_sekolah();
if (!is_guru() && !is_operator() && !is_super()) {
    tolak403('Halaman ini untuk akun Guru atau pengelola sekolah.');
}

$ujianId = (int) ($_GET['ujian'] ?? $_POST['ujian'] ?? 0);
$uj = ujian_by_id($ujianId);
if (!$uj) {
    flash('Ujian tidak ditemukan.', 'err');
    redirect('ujian.php');
}
if (is_guru() && (int) $uj['guru_id'] !== (int) (guru_saya()['id'] ?? 0)) {
    tolak403('Ujian ini bukan milik Anda.');
}

/** Susunan kolom template LAMA (Excel/CSV) — masih dipakai halaman ini & pratinjau Excel. */
const KOLOM_SOAL = ['Jenis', 'Pertanyaan', 'Opsi A', 'Opsi B', 'Opsi C', 'Opsi D', 'Opsi E', 'Jawaban', 'Skor', 'Pembahasan'];

$hasil = null;
$galat = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $payload = json_decode((string) ($_POST['payload'] ?? ''), true);

    $daftar = [];      // soal ternormalisasi (semua jalur impor)
    $salah = [];       // peringatan baris/soal yang dilewati
    $asal = 'excel';   // sumber impor (untuk pesan hasil)

    // ---- Berkas WORD (.docx / .doc) -------------------------------------------------------
    // Template LAMA berbentuk TABEL, template BARU berupa tulisan biasa (paragraf). Keduanya
    // diterima: tabel dicoba lebih dahulu, bila tidak ada tabel isinya dibaca sebagai teks.
    //
    // v1.18.0: jenis berkas ditentukan dari ISINYA, bukan hanya ekstensi —
    //   • diawali "PK"            → .docx (ZIP + XML)
    //   • diawali tanda OLE2      → .doc lama (biner) lewat `inc/doc.php`
    // Jadi berkas .docx yang diganti namanya menjadi .doc (atau sebaliknya) tetap terbaca.
    $docxBaris = null;
    $teksMasuk = '';
    $asalTeksCadangan = false; // .doc: bila tabel tidak menghasilkan soal, teksnya dicoba lagi
    $dariDoc = false;          // true = berkas Word lama (.doc, OLE2) — untuk pesan galat yang tepat
    if ((!is_array($payload) || empty($payload['baris'])) && !empty($_FILES['berkas_docx']['tmp_name'])) {
        $f = $_FILES['berkas_docx'];
        $ext = strtolower((string) pathinfo((string) $f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['doc', 'docx'], true)) {
            $galat = 'Berkas Word harus berformat .docx atau .doc — berkas "' . $ext . '" bukan berkas Word. '
                . 'Di Word: File > Save As > pilih "Word Document (*.docx)" atau "Word 97-2003 (*.doc)".';
        } elseif ((int) $f['error'] !== UPLOAD_ERR_OK) {
            $galat = 'Berkas gagal diunggah (kode ' . (int) $f['error'] . ').';
        } elseif ((int) $f['size'] > 20 * 1024 * 1024) {
            // Batas wajar: dokumen Word soal jarang melebihi 20 MB (batas unggah server 21 MB).
            $galat = 'Berkas Word terlalu besar (maksimal 20 MB).';
        } else {
            $bytes = (string) @file_get_contents((string) $f['tmp_name']);
            if (doc_adalah_zip($bytes)) {
                // .docx (atau .doc yang isinya sebenarnya .docx)
                $docxBaris = docx_baca_tabel($bytes);
                if (!$docxBaris) {
                    $teksMasuk = implode("\n", docx_baca_paragraf($bytes));
                }
            } elseif (doc_adalah_ole($bytes)) {
                // .doc lama (Word 97–2003). Tabel dibaca lebih dahulu; bila tidak ada tabel,
                // isinya dibaca sebagai tulisan biasa (template TEKS).
                $docxBaris = doc_baca_tabel($bytes);
                if (!$docxBaris) {
                    $teksMasuk = implode("\n", doc_baca_paragraf($bytes));
                }
                $asalTeksCadangan = true;
                $dariDoc = true;
            } elseif (doc_adalah_rtf($bytes)) {
                $galat = 'Berkas ini berformat RTF (bukan Word .doc/.docx). Buka dengan Word lalu '
                    . 'simpan sebagai "Word Document (*.docx)", kemudian unggah kembali.';
            } else {
                $galat = 'Berkas tidak dikenali sebagai dokumen Word (.docx atau .doc). '
                    . 'Pastikan berkasnya benar-benar dokumen Word, bukan hasil penggantian nama '
                    . 'berkas lain.';
            }
        }
    }

    // ---- Tempel teks (tanpa berkas) ------------------------------------------------------
    if ($galat === '' && $docxBaris === null && trim($teksMasuk) === ''
        && (!is_array($payload) || empty($payload['baris']))) {
        $teksMasuk = (string) ($_POST['teks_soal'] ?? '');
    }

    if ($galat === '') {
        if ($docxBaris) {
            // Jalur LAMA: tabel pada Word (kompatibilitas template yang sudah beredar).
            $asal = 'word-tabel';
            $daftar = soal_dari_tabel($docxBaris);
            if (!$daftar && $asalTeksCadangan) {
                // Berkas .doc: strukturnya tidak selalu bisa dipetakan per sel. Bila tabel tidak
                // menghasilkan soal, isi dokumen dicoba dibaca sebagai template TEKS.
                $parse = soal_teks_parse(implode("\n", doc_baca_paragraf($bytes ?? '')));
                if ($parse['baris']) {
                    $asal = 'teks';
                    $daftar = $parse['baris'];
                    $salah = array_merge($salah, $parse['salah']);
                }
            }
            if (!$daftar) {
                $galat = $dariDoc
                    // Berkas .doc: templat teks SUDAH dicoba, jadi ini soal penulisan template.
                    ? 'Tidak ada soal yang dapat dibaca dari berkas Word ini. Pastikan setiap soal '
                        . 'diawali nomor (1., 2., 3., …) dan berada di bawah judul bagian seperti '
                        . '"Template Soal Pilihan Ganda" — atau isi berkas memakai template yang '
                        . 'diunduh dari halaman ini.'
                    : 'Tabel pada berkas Word tidak memuat soal yang dapat dibaca. '
                        . 'Bila soalnya ditulis sebagai tulisan biasa (template baru), simpan berkas '
                        . 'sebagai .docx lalu unggah kembali.';
            }
        } elseif (trim($teksMasuk) !== '') {
            // Jalur BARU: template teks sederhana (.docx atau teks yang ditempel).
            $asal = 'teks';
            $parse = soal_teks_parse($teksMasuk);
            $daftar = $parse['baris'];
            $salah = array_merge($salah, $parse['salah']);
            if (!$daftar) {
                $galat = 'Tidak ada soal yang dapat dibaca. Pastikan setiap soal diawali nomor '
                    . '(1., 2., 3., …) dan berada di bawah judul bagian seperti '
                    . '"Template Soal Pilihan Ganda".' . ($salah ? ' ' . $salah[0] : '');
            }
        } elseif (is_array($payload) && !empty($payload['baris'])) {
            // Jalur Excel/CSV (kolom lama).
            $asal = 'excel';
            $judul = isset($payload['judul']) && is_array($payload['judul']) ? (array) $payload['judul'] : [];
            $daftar = soal_dari_kolom((array) $payload['baris'], $judul, IMPOR_MAKS_BARIS_SOAL);
        } else {
            $galat = 'Berkas belum dibaca dengan benar. Pilih berkas Word (.docx atau .doc) / '
                . 'Excel / CSV, atau tempel teks soal pada kolom yang tersedia.';
        }
    }

    if ($galat === '' && $daftar) {
        $masuk = 0;
        $urut = (int) val('SELECT COALESCE(MAX(urutan),0) FROM soal WHERE ujian_id = ?', [$ujianId], 0);
        db()->exec('BEGIN IMMEDIATE');
        try {
            foreach ($daftar as $i => $r) {
                $label = 'Soal ' . (int) ($r['nomor'] ?? ($i + 1));
                $jenis = (string) ($r['jenis'] ?? 'pg');
                $pertanyaan = teks((string) ($r['pertanyaan'] ?? ''), SOAL_TEKS_MAKS);
                if ($pertanyaan === '') {
                    $salah[] = $label . ': pertanyaan kosong — dilewati.';
                    continue;
                }
                // Opsi dibersihkan SEKALI di sini: daftar yang diperiksa & disimpan harus sama.
                $opsi = array_values(array_filter(
                    array_map(fn($o) => teks((string) $o, 500), (array) ($r['opsi'] ?? [])),
                    fn($o) => $o !== ''
                ));
                $siap = soal_baris_siap($jenis, $opsi, $r['kunci'] ?? '', !empty($r['pilihan_bs']));
                if (empty($siap['ok'])) {
                    $salah[] = $label . ': ' . (string) $siap['pesan'] . ' — dilewati.';
                    continue;
                }
                $skor = $r['skor'] ?? null;
                $skor = ($skor === null || $skor === '') ? 1.0 : (float) $skor;
                $skor = max(0.5, min(100, $skor));
                $pembahasan = teks((string) ($r['pembahasan'] ?? ''), PEMBAHASAN_MAKS);

                $urut += 10;
                q('INSERT INTO soal (sekolah_id, ujian_id, jenis, pertanyaan, opsi, jawaban, skor, pembahasan, urutan, dibuat)
                   VALUES (?,?,?,?,?,?,?,?,?,?)',
                    [sekolah_id(), $ujianId, $jenis, $pertanyaan,
                        in_array($jenis, ['pg', 'pgk', 'bs', 'jodoh'], true) ? opsi_encode($opsi) : null,
                        json_encode($siap['jawaban'], JSON_UNESCAPED_UNICODE), $skor, $pembahasan, $urut,
                        date('Y-m-d H:i:s')]);
                $masuk++;
            }
            db()->exec('COMMIT');
        } catch (Throwable $ex) {
            db()->exec('ROLLBACK');
            $masuk = 0;
            $galat = 'Impor gagal di tengah proses — tidak ada soal yang disimpan.';
        }
        $hasil = ['masuk' => $masuk, 'salah' => $salah, 'total' => count($daftar) + count($salah), 'asal' => $asal];
    }
}

page_top('Impor Soal');
$contohTemplate = soal_template_teks();
?>
<div class="grid g21 impor-soal">
  <div class="card">
    <div class="card-head"><h3>Unggah Berkas Soal</h3>
      <div class="sub"><?= e((string) $uj['judul']) ?></div>
      <div class="kanan">
        <a class="btn btn-sm btn-utama" href="template_soal_word.php?ujian=<?= (int) $ujianId ?>">Unduh Template Word (.docx)</a>
        <a class="btn btn-sm" href="template_impor.php?jenis=soal">Contoh Excel/CSV</a>
        <a class="btn btn-sm" href="ujian_edit.php?id=<?= (int) $ujianId ?>#daftarSoal">Kembali</a>
      </div>
    </div>
    <div class="card-body">
      <?php if ($galat !== ''): ?><div class="alert alert-err"><?= e($galat) ?></div><?php endif; ?>
      <?php if ($hasil): ?>
        <?php
        $asalTeks = ['teks' => 'template teks (Word / tempel)', 'word-tabel' => 'tabel Word',
            'excel' => 'berkas Excel/CSV'][(string) $hasil['asal']] ?? 'berkas';
        ?>
        <div class="alert <?= (int) $hasil['masuk'] > 0 ? 'alert-ok' : 'alert-err' ?>">
          <b><?= (int) $hasil['masuk'] ?> soal</b> berhasil diimpor dari <?= (int) $hasil['total'] ?> soal terbaca
          (<?= e($asalTeks) ?>).
          <?php if ((string) $hasil['asal'] === 'teks'): ?>
            <br>Soal essai dinilai otomatis dari kata kunci jawaban contoh — nilainya dapat disesuaikan
            guru pada menu Hasil &amp; Analisis.
          <?php endif; ?>
          <?php if ($hasil['salah']): ?>
            <ul class="poin-list mt8">
              <?php foreach (array_slice($hasil['salah'], 0, 15) as $s): ?><li><?= e($s) ?></li><?php endforeach; ?>
              <?php if (count($hasil['salah']) > 15): ?><li>… dan <?= count($hasil['salah']) - 15 ?> soal lain.</li><?php endif; ?>
            </ul>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <div class="card" style="border:1px solid var(--aksen);margin-bottom:16px">
        <div class="card-head"><h3>Cara 1 — Tulis Soal di Template Word, lalu Unggah</h3>
          <div class="sub">Template baru berbentuk tulisan biasa (tidak ada tabel) — jauh lebih mudah diisi</div></div>
        <div class="card-body">
          <ol class="poin-list" style="margin-bottom:12px">
            <li>Unduh <b>Template Word (.docx)</b> (tombol kanan atas), lalu buka dengan Microsoft Word.</li>
            <li><b>Hapus dulu semua soal contoh</b> di dalam template, lalu tulis soal Anda di bawah
              judul bagian yang sesuai dan tulis <b>Kunci</b> pada baris tersendiri.</li>
            <li><b>Simpan berkas</b> (Ctrl+S) dengan format <b>.docx</b> (Word Document) atau
              <b>.doc</b> (Word 97-2003) — <b>keduanya dapat diunggah</b>.</li>
            <li>Unggah berkasnya di bawah ini lalu tekan <b>Impor dari Word</b>. Tidak perlu mengatur
              jenis soal atau kunci lagi — semuanya dibaca otomatis.</li>
          </ol>
          <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="ujian" value="<?= (int) $ujianId ?>">
            <div class="field">
              <label for="berkasDocx">Berkas Word (.docx atau .doc)</label>
              <input type="file" id="berkasDocx" name="berkas_docx" accept=".docx,.doc">
              <div class="ket">Kedua bentuk berkas Word diterima: <b>.docx</b> (Word 2007 ke atas)
                maupun <b>.doc</b> (Word 97-2003). Template lama berbentuk tabel juga masih bisa
                diunggah di sini.</div>
            </div>
            <button class="btn btn-utama" type="submit">Impor dari Word</button>
          </form>
        </div>
      </div>

      <div class="card" style="margin-bottom:16px">
        <div class="card-head"><h3>Cara 2 — Tempel Teks Soal (tanpa berkas)</h3>
          <div class="sub">Cocok bila soal sudah Anda tulis di Word/HP: salin tulisannya, tempel di sini</div></div>
        <div class="card-body">
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="ujian" value="<?= (int) $ujianId ?>">
            <div class="field">
              <label for="teksSoal">Teks soal</label>
              <textarea id="teksSoal" name="teks_soal" rows="10"
                placeholder="Template Soal Pilihan Ganda&#10;1. Pertanyaan ...&#10;A. pilihan 1&#10;B. pilihan 2&#10;Kunci: A"></textarea>
              <div class="ket">Formatnya sama dengan template di atas: judul bagian, soal bernomor,
                pilihan A./B./C., lalu baris "Kunci: …".</div>
            </div>
            <button class="btn btn-utama" type="submit">Impor dari Teks</button>
          </form>
        </div>
      </div>

      <details class="impor-lama">
        <summary>Cara 3 — Impor Berkas Excel / CSV (cara lama)</summary>
        <div class="mt16"></div>
        <form method="post" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <input type="hidden" name="ujian" value="<?= (int) $ujianId ?>">
          <input type="hidden" name="payload" id="payloadImpor">
          <div class="field">
            <label for="fileImpor">Pilih Berkas (Excel .xlsx/.xls atau CSV)</label>
            <input type="file" id="fileImpor" name="berkas" accept=".xlsx,.xls,.csv"
                   data-kolom='<?= e(json_encode(KOLOM_SOAL)) ?>'>
            <div class="ket" id="infoImpor">Kolom: <?= e(implode(' | ', KOLOM_SOAL)) ?></div>
          </div>
          <button class="btn btn-utama" type="submit" id="btnKirimImpor" disabled>Impor Soal</button>
        </form>
        <div id="pratinjauImpor" hidden class="card mt16"></div>
      </details>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h3>Format Template (Mudah)</h3>
      <div class="sub">Tidak ada tabel — cukup tulisan biasa</div></div>
    <div class="card-body">
      <table class="tbl">
        <thead><tr><th>Judul bagian</th><th>Cara menulis soal &amp; kunci</th></tr></thead>
        <tbody>
          <tr><td>Template Soal Pilihan Ganda</td>
            <td><code>1. Pertanyaan …</code> lalu <code>A.</code> … <code>E.</code>, ditutup <code>Kunci: C</code></td></tr>
          <tr><td>Template Soal Pilihan Ganda Kompleks</td>
            <td>Pilihan boleh diawali <code>[ ]</code>; kunci beberapa huruf: <code>Kunci: A, B, E</code></td></tr>
          <tr><td>Template Soal Benar Salah</td>
            <td><code>3. Pernyataan …</code> lalu <code>Kunci: Benar</code> (boleh juga <code>Kunci: A</code>)</td></tr>
          <tr><td>Template Soal Menjodohkan</td>
            <td>satu baris satu pasangan: <code>A. Salam Pembuka | Halo Sahabatku, apa kabarmu?</code></td></tr>
          <tr><td>Template Soal Essai Singkat</td>
            <td><code>5. Pertanyaan …</code> lalu <code>Kunci: jawaban contoh</code></td></tr>
          <tr><td>Template Soal Essai Panjang</td>
            <td>sama seperti essai singkat; pertanyaan boleh beberapa baris</td></tr>
        </tbody>
      </table>
      <ul class="poin-list mt16">
        <li>Awali setiap soal dengan <b>nomor</b> (1., 2., 3., …). Bagian di luar judul template
          (mis. petunjuk pengisian) tidak ikut diimpor.</li>
        <li>Soal <b>essai</b> dinilai otomatis dari kata kunci jawaban contoh; bila ingin kata kunci
          tertentu, tulis dengan tanda <code>|</code> — mis. <code>fotosintesis|klorofil|cahaya matahari</code>.</li>
        <li>Baris <code>Skor: 2</code> boleh ditambahkan bila bobot soalnya bukan 1.</li>
        <li>Soal yang belum lengkap dilewati dan dilaporkan; soal lain tetap masuk.</li>
      </ul>
      <details class="mt16">
        <summary>Lihat contoh template lengkap (6 jenis soal)</summary>
        <pre class="contoh-soal"><?= e($contohTemplate) ?></pre>
      </details>
      <details class="mt16">
        <summary>Aturan kunci pada impor Excel/CSV (cara lama)</summary>
        <ul class="poin-list mt8">
          <li>Pilihan Ganda: <code>A</code> · Kompleks: <code>A,C</code> · Benar/Salah: <code>B,S,B</code></li>
          <li>Menjodohkan: <code>Jakarta|Kuala Lumpur|Tokyo</code> (pasangan kanan tiap pernyataan kiri)</li>
          <li>Isian: <code>96|sembilan puluh enam</code> · Uraian: <code>fotosintesis|klorofil/cahaya matahari</code></li>
        </ul>
      </details>
    </div>
  </div>
</div>
<script src="assets/vendor/xlsx.full.min.js"></script>
<?php page_bottom();
