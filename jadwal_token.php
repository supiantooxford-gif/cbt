<?php
/**
 * JADWAL ASESMEN & TOKEN UJIAN (akun Admin Operator).
 *
 * Permintaan pemilik: "Pada akun operator tambahkan fitur untuk mengaktifkan soal sesuai jadwal
 * asesmen/ujian dan tambahkan generate token (per mata pelajaran yang akan diujikan). Nomer token
 * ini akan diinput oleh siswa sebelum mengerjakan soal."
 *
 * - Jadwal = `ujian.mulai` .. `ujian.selesai`; di luar jendela itu siswa TIDAK dapat masuk.
 * - Token = `ujian.token` (satu ujian = satu mata pelajaran, jadi token memang per mata pelajaran).
 *   Kosong = ujian tanpa token.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

$u = require_sekolah();
if (!is_operator() && !is_super()) {
    tolak403('Halaman ini untuk Admin Operator sekolah.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $aksi = teks($_POST['aksi'] ?? '', 24);
    $id = (int) ($_POST['id'] ?? 0);
    $uj = ujian_by_id($id);
    if (!$uj) {
        flash('Ujian tidak ditemukan.', 'err');
        redirect('jadwal_token.php');
    }

    if ($aksi === 'jadwal') {
        $mulai = teks($_POST['mulai'] ?? '', 20);
        $selesai = teks($_POST['selesai'] ?? '', 20);
        $aktifkan = isset($_POST['aktifkan']);
        // Normalisasi "YYYY-MM-DDTHH:MM" (input datetime-local) -> "YYYY-MM-DD HH:MM:00"
        $rapikan = function (string $v): ?string {
            $v = str_replace('T', ' ', trim($v));
            if ($v === '') {
                return null;
            }
            $ts = strtotime($v);
            return $ts === false ? null : date('Y-m-d H:i:s', $ts);
        };
        $m = $rapikan($mulai);
        $s = $rapikan($selesai);
        if ($m !== null && $s !== null && strtotime($s) <= strtotime($m)) {
            flash('Waktu selesai harus setelah waktu mulai.', 'err');
        } else {
            $n = (int) val('SELECT COUNT(*) FROM soal WHERE ujian_id = ?', [$id], 0);
            $kn = count(ujian_kelas_ids($id));
            if ($aktifkan && ($n === 0 || $kn === 0)) {
                flash('Ujian belum dapat diaktifkan: soal dan kelas sasarannya harus lengkap.', 'err');
            } else {
                $status = $aktifkan ? 'aktif' : (string) $uj['status'];
                q('UPDATE ujian SET mulai = ?, selesai = ?, status = ?, diubah = ? WHERE id = ? AND sekolah_id = ?',
                    [$m, $s, $status, date('Y-m-d H:i:s'), $id, sekolah_id()]);
                flash($aktifkan
                    ? 'Jadwal disimpan dan ujian DIAKTIFKAN sesuai jadwal.'
                    : 'Jadwal ujian disimpan.', 'ok');
            }
        }
    } elseif ($aksi === 'token') {
        $token = strtoupper(trim(teks($_POST['token'] ?? '', 12)));
        if ($token !== '' && !preg_match('/^[A-Z0-9]{4,12}$/', $token)) {
            flash('Token hanya boleh huruf/angka (4–12 karakter).', 'err');
        } else {
            q('UPDATE ujian SET token = ?, token_dibuat = ? WHERE id = ? AND sekolah_id = ?',
                [$token !== '' ? $token : null, $token !== '' ? date('Y-m-d H:i:s') : null, $id, sekolah_id()]);
            flash($token !== ''
                ? 'Token untuk ' . (string) mapel_nama((int) ($uj['mapel_id'] ?? 0)) . ': ' . $token
                  . ' — berikan kepada siswa sebelum ujian dimulai.'
                : 'Token dihapus: ujian ini dapat dikerjakan tanpa token.', 'ok');
        }
    } elseif ($aksi === 'token_acak') {
        $token = token_buat(TOKEN_PANJANG);
        q('UPDATE ujian SET token = ?, token_dibuat = ? WHERE id = ? AND sekolah_id = ?',
            [$token, date('Y-m-d H:i:s'), $id, sekolah_id()]);
        flash('Token baru untuk ' . (string) mapel_nama((int) ($uj['mapel_id'] ?? 0)) . ': ' . $token, 'ok');
    } elseif ($aksi === 'tutup') {
        q('UPDATE ujian SET status = ?, selesai = COALESCE(selesai, ?), diubah = ? WHERE id = ? AND sekolah_id = ?',
            ['selesai', date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $id, sekolah_id()]);
        flash('Ujian ditutup.', 'warn');
    }
    redirect('jadwal_token.php');
}

$daftar = all("SELECT uj.*, m.nama AS mapel, g.nama AS guru,
        (SELECT COUNT(*) FROM soal s WHERE s.ujian_id = uj.id) AS jumlah_soal,
        (SELECT COUNT(*) FROM ujian_kelas uk WHERE uk.ujian_id = uj.id) AS kelas,
        (SELECT GROUP_CONCAT(k.nama, ', ') FROM ujian_kelas uk JOIN kelas k ON k.id = uk.kelas_id
            WHERE uk.ujian_id = uj.id) AS kelas_nama,
        (SELECT COUNT(*) FROM sesi_ujian su WHERE su.ujian_id = uj.id) AS sesi
    FROM ujian uj LEFT JOIN mapel m ON m.id = uj.mapel_id LEFT JOIN guru g ON g.id = uj.guru_id
    WHERE uj.sekolah_id = ? ORDER BY uj.id DESC", [sekolah_id()]);
$perMapel = all("SELECT m.id, m.nama, m.kktp, COUNT(uj.id) AS jml
    FROM mapel m LEFT JOIN ujian uj ON uj.mapel_id = m.id AND uj.sekolah_id = ?
    WHERE m.sekolah_id = ? GROUP BY m.id ORDER BY m.urutan, m.nama", [sekolah_id(), sekolah_id()]);

page_top('Jadwal Asesmen & Token');
?>
<div class="alert alert-info">
  <b>Cara kerja.</b> Tentukan <b>jadwal</b> (mulai &amp; selesai) tiap ujian lalu aktifkan —
  siswa hanya dapat masuk pada rentang waktu itu. Bila <b>token</b> diisi, siswa wajib mengetikkan
  token tersebut sebelum soal terbuka, sehingga soal tidak bocor sebelum ujian dimulai.
</div>

<div class="card">
  <div class="card-head">
    <h3>Daftar Ujian, Jadwal, dan Token</h3>
    <div class="sub"><?= count($daftar) ?> ujian &middot; token berlaku per mata pelajaran</div>
  </div>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr>
        <th>No</th><th>Ujian / Mata Pelajaran</th><th>Kelas Sasaran</th>
        <th class="center">Soal</th><th class="center">KKTP</th><th>Jadwal</th>
        <th>Keadaan</th><th class="center">Token</th><th class="center">Peserta</th><th class="kanan">Aksi</th>
      </tr></thead>
      <tbody>
      <?php foreach ($daftar as $i => $r): ?>
        <?php $jd = ujian_jadwal($r); $tk = ujian_token($r); ?>
        <tr>
          <td><?= $i + 1 ?></td>
          <td><b><?= e((string) $r['judul']) ?></b><br>
            <small class="mut"><?= e((string) ($r['mapel'] ?: '-')) ?>
              <?php if (trim((string) $r['guru']) !== ''): ?> &middot; <?= e((string) $r['guru']) ?><?php endif; ?></small></td>
          <td><small><?= e((string) ($r['kelas_nama'] ?: '-')) ?></small></td>
          <td class="center"><?= (int) $r['jumlah_soal'] ?></td>
          <td class="center"><?= r1(kktp_berlaku($r)) ?></td>
          <td><small>
            <?php if (trim((string) $r['mulai']) === '' && trim((string) $r['selesai']) === ''): ?>
              <span class="mut">tanpa jadwal</span>
            <?php else: ?>
              Mulai: <?= e($r['mulai'] ? waktu_id((string) $r['mulai']) : '-') ?><br>
              Selesai: <?= e($r['selesai'] ? waktu_id((string) $r['selesai']) : '-') ?>
            <?php endif; ?>
          </small></td>
          <td>
            <?php if ($jd['keadaan'] === 'buka' || $jd['keadaan'] === 'selalu'): ?>
              <span class="pil pil-ok">Terbuka</span>
            <?php elseif ($jd['keadaan'] === 'belum'): ?>
              <span class="pil pil-warn">Belum dibuka</span>
            <?php elseif ((string) $r['status'] === 'selesai'): ?>
              <span class="pil pil-mut">Ditutup</span>
            <?php else: ?>
              <span class="pil pil-err">Draf</span>
            <?php endif; ?>
            <br><small class="mut"><?= e($jd['pesan']) ?></small>
          </td>
          <td class="center">
            <?php if ($tk === ''): ?>
              <span class="pil pil-mut">tanpa token</span>
            <?php else: ?>
              <span class="kotak-kode" id="token<?= (int) $r['id'] ?>" style="font-size:.9rem"><?= e($tk) ?></span><br>
              <small class="mut"><?= e($r['token_dibuat'] ? waktu_id((string) $r['token_dibuat']) : '') ?></small>
            <?php endif; ?>
          </td>
          <td class="center"><?= (int) $r['sesi'] ?></td>
          <td class="kanan">
            <details class="detail-aksi">
              <summary class="btn btn-sm">Atur</summary>
              <div class="detail-isi">
                <form method="post">
                  <?= csrf_field() ?>
                  <input type="hidden" name="aksi" value="jadwal">
                  <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                  <div class="field"><label>Mulai</label>
                    <input type="datetime-local" name="mulai"
                           value="<?= e($r['mulai'] ? date('Y-m-d\TH:i', (int) strtotime((string) $r['mulai'])) : '') ?>"></div>
                  <div class="field"><label>Selesai</label>
                    <input type="datetime-local" name="selesai"
                           value="<?= e($r['selesai'] ? date('Y-m-d\TH:i', (int) strtotime((string) $r['selesai'])) : '') ?>"></div>
                  <label class="cek"><input type="checkbox" name="aktifkan" value="1"
                    <?= (string) $r['status'] === 'aktif' ? 'checked' : '' ?>> Aktifkan ujian sesuai jadwal</label>
                  <button class="btn btn-sm btn-utama" type="submit">Simpan Jadwal</button>
                </form>
                <div class="divider"></div>
                <form method="post">
                  <?= csrf_field() ?>
                  <input type="hidden" name="aksi" value="token">
                  <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                  <div class="field"><label>Token (kosongkan bila tidak perlu)</label>
                    <input type="text" name="token" value="<?= e($tk) ?>" maxlength="12" placeholder="mis. K7M2QP">
                  </div>
                  <div class="ket">Token hanya huruf/angka, 4–12 karakter. Kosongkan lalu simpan
                    bila ujian tidak perlu token.</div>
                  <button class="btn btn-sm" type="submit">Simpan Token</button>
                </form>
                <div class="divider"></div>
                <div class="row-actions">
                  <form method="post" style="display:inline" data-confirm="Buat token acak baru untuk ujian ini?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="aksi" value="token_acak"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                    <button class="btn btn-sm btn-utama" type="submit">Generate Token Acak</button>
                  </form>
                  <?php if ($tk !== ''): ?>
                    <button class="btn btn-sm" type="button" data-salin="<?= e('#token' . (int) $r['id']) ?>">Salin Token</button>
                  <?php endif; ?>
                  <a class="btn btn-sm" href="ujian_edit.php?id=<?= (int) $r['id'] ?>#daftarSoal">Lihat Soal</a>
                  <form method="post" style="display:inline" data-confirm="Tutup ujian ini sekarang?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="aksi" value="tutup"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                    <button class="btn btn-sm btn-bahaya" type="submit">Tutup Ujian</button>
                  </form>
                </div>
              </div>
            </details>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$daftar): ?>
        <tr><td colspan="10" class="kosong"><b>Belum ada ujian.</b>
          Ujian dibuat oleh akun Guru pada menu Ujian &amp; Soal; setelah itu jadwal &amp; tokennya diatur di sini.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="grid g21">
  <div class="card">
    <div class="card-head"><h3>KKTP per Mata Pelajaran</h3>
      <div class="sub">Batas ketuntasan yang dipakai ujian pada mapel tersebut</div></div>
    <div class="table-wrap">
      <table class="tbl">
        <thead><tr><th>No</th><th>Mata Pelajaran</th><th class="center">KKTP</th>
          <th class="center">Ujian</th><th class="center">Sumber</th></tr></thead>
        <tbody>
        <?php foreach ($perMapel as $i => $m): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td><?= e($m['nama']) ?></td>
            <td class="center"><b><?= r1($m['kktp'] !== null && $m['kktp'] !== '' ? (float) $m['kktp'] : (float) setting('kkm', '75')) ?></b></td>
            <td class="center"><?= (int) $m['jml'] ?></td>
            <td class="center"><small class="mut">
              <?= ($m['kktp'] !== null && $m['kktp'] !== '') ? 'diatur per mapel' : 'memakai KKM sekolah' ?></small></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="card">
    <div class="card-head"><h3>Petunjuk Singkat</h3></div>
    <div class="card-body">
      <ul class="poin-list">
        <li><b>Jadwal</b> menentukan kapan siswa boleh membuka soal. Sebelum waktu mulai, siswa
          melihat keterangan "belum dibuka"; setelah waktu selesai, ujian tertutup sendiri.</li>
        <li><b>Token</b> diminta kepada siswa <i>sebelum</i> soal terbuka; berikan token saat
          pengawas mulai membuka ujian. Token per mata pelajaran (satu ujian = satu mapel).</li>
        <li>Token boleh diganti kapan saja; siswa yang belum masuk harus memakai token terbaru.</li>
        <li>Uji coba token dilakukan maksimal <?= (int) TOKEN_MAKS_COBA ?> kali per sesi siswa.</li>
        <li>Ujian tanpa jadwal dan tanpa token tetap dapat dikerjakan selama statusnya aktif.</li>
      </ul>
    </div>
  </div>
</div>
<?php page_bottom();
