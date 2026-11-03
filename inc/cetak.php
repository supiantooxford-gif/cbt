<?php
/**
 * Bantuan lembar cetak (kop sekolah, identitas, dan blok tanda tangan).
 */
declare(strict_types=1);

function kop_cetak(string $judul = '', array $baris = []): void
{
    $s = current_sekolah();
    $nama = $s ? (string) $s['nama'] : APP_NAME;
    $logo = $s && trim((string) $s['logo']) !== '' ? (string) $s['logo'] : '';
    $wilayah = [];
    foreach ([['desa', 'Desa/Kelurahan '], ['kecamatan', 'Kec. '], ['kabupaten', 'Kab. '], ['provinsi', '']] as $w) {
        $v = trim((string) ($s[$w[0]] ?? ''));
        if ($v !== '' && $v !== '-') {
            $wilayah[] = $w[1] . $v;
        }
    }
    $kontak = [];
    if (trim((string) ($s['email'] ?? '')) !== '') { $kontak[] = 'email: ' . $s['email']; }
    if (trim((string) ($s['website'] ?? '')) !== '') { $kontak[] = 'website: ' . $s['website']; }
    $ident = [];
    if (trim((string) ($s['npsn'] ?? '')) !== '') { $ident[] = 'NPSN: ' . $s['npsn']; }
    if (trim((string) ($s['telepon'] ?? '')) !== '') { $ident[] = 'HP: ' . $s['telepon']; }
    ?>
    <div class="sheet-kop">
      <?php if ($logo !== ''): ?><img class="kop-logo" src="<?= e($logo) ?>" alt="Logo"><?php endif; ?>
      <div class="kop-teks">
        <b><?= e($nama) ?></b>
        <?php if (trim((string) ($s['alamat'] ?? '')) !== ''): ?><span><?= e((string) $s['alamat']) ?></span><?php endif; ?>
        <?php if ($wilayah): ?><span><?= e(implode(', ', $wilayah)) ?></span><?php endif; ?>
        <?php if ($kontak): ?><span><?= e(implode(', ', $kontak)) ?></span><?php endif; ?>
        <?php if ($ident): ?><span><?= e(implode(', ', $ident)) ?></span><?php endif; ?>
        <?php foreach ($baris as $b): ?><span><?= e($b) ?></span><?php endforeach; ?>
      </div>
    </div>
    <?php if ($judul !== ''): ?><div class="sheet-judul"><?= e($judul) ?></div><?php endif;
}

/** Blok tanda tangan. */
function ttd_blok(string $label, string $nama, string $nip = '', bool $kanan = true): void
{
    ?>
    <div class="sheet-sign<?= $kanan ? '' : '' ?>">
      <div><?= e($label) ?></div>
      <div class="space"></div>
      <div class="nama"><?= e($nama !== '' ? $nama : '................................') ?></div>
      <?php if ($nip !== ''): ?><div>NIP. <?= e($nip) ?></div><?php endif; ?>
    </div>
    <?php
}

function tanggal_cetak(): string
{
    return tanggal_id(date('Y-m-d'));
}
