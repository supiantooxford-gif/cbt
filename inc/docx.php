<?php
/**
 * Modul .docx sederhana TANPA pustaka luar.
 *
 * Dipakai untuk dua hal (permintaan pemilik: "Pada bagian impor soal buat template dalam
 * bentuk word agar memudahkan guru mengisi"):
 *  1. MEMBUAT template Word berisi tabel kolom soal — guru mengisi langsung di Word.
 *  2. MEMBACA kembali tabel dari berkas .docx yang sudah diisi guru.
 *
 * .docx adalah berkas ZIP berisi XML. Sandbox ini tidak punya ekstensi `zip`, tetapi
 * `zlib` tersedia (`gzinflate`) sehingga ZIP dapat dibaca dan ditulis sendiri:
 *  - Menulis: entri disimpan TANPA kompresi (STORE) — bentuk ZIP yang sah.
 *  - Membaca: entri STORE dibaca apa adanya, entri DEFLATE dikembangkan dengan gzinflate().
 *
 * Catatan: berkas .doc (format lama, bukan ZIP) TIDAK dibaca di modul ini — pembacanya ada di
 * `inc/doc.php` (wadah OLE2 + piece table). Sejak v1.18.0 kedua bentuk berkas Word diterima
 * pada menu Impor Soal; lihat `ujian_import.php`.
 */
declare(strict_types=1);

// docx_par_gambar() memakai gambar_ukuran() dari helpers.php; modul itu dimuat agar docx.php
// tetap dapat dipakai sendiri tanpa init.php.
require_once __DIR__ . '/helpers.php';

/** Susun berkas ZIP dari daftar entri (nama => isi) — semuanya TANPA kompresi. */
function zip_sederhana(array $entri): string
{
    $lokal = '';
    $pusat = '';
    $offset = 0;
    foreach ($entri as $nama => $isi) {
        $nama = (string) $nama;
        $isi = (string) $isi;
        $crc = crc32($isi);
        $panjang = strlen($isi);
        $jam = getdate();
        $waktuDos = (($jam['hours'] << 11) | ($jam['minutes'] << 5) | ($jam['seconds'] >> 1)) & 0xFFFF;
        $tglDos = ((($jam['year'] - 1980) << 9) | ($jam['mon'] << 5) | $jam['mday']) & 0xFFFF;

        $kepala = pack('V', 0x04034b50) . pack('v', 20) . pack('v', 0) . pack('v', 0)
            . pack('v', $waktuDos) . pack('v', $tglDos) . pack('V', $crc)
            . pack('V', $panjang) . pack('V', $panjang) . pack('v', strlen($nama)) . pack('v', 0);
        $lokal .= $kepala . $nama . $isi;

        $pusat .= pack('V', 0x02014b50) . pack('v', 20) . pack('v', 20) . pack('v', 0) . pack('v', 0)
            . pack('v', $waktuDos) . pack('v', $tglDos) . pack('V', $crc)
            . pack('V', $panjang) . pack('V', $panjang) . pack('v', strlen($nama)) . pack('v', 0)
            . pack('v', 0) . pack('v', 0) . pack('v', 0) . pack('V', 0) . pack('V', $offset)
            . $nama;
        $offset += strlen($kepala) + strlen($nama) + $panjang;
    }
    $akhir = pack('V', 0x06054b50) . pack('v', 0) . pack('v', 0)
        . pack('v', count($entri)) . pack('v', count($entri))
        . pack('V', strlen($pusat)) . pack('V', strlen($lokal)) . pack('v', 0);
    return $lokal . $pusat . $akhir;
}

/** Baca SATU entri dari berkas ZIP (memakai daftar pusat → ukuran selalu benar). */
function zip_baca_satu(string $zip, string $nama): ?string
{
    $nama = str_replace('\\', '/', $nama);
    $panjang = strlen($zip);
    // Cari End Of Central Directory (dari belakang)
    $posEocd = -1;
    for ($i = $panjang - 22; $i >= 0 && $i > $panjang - 66000; $i--) {
        if (substr($zip, $i, 4) === "PK\x05\x06") {
            $posEocd = $i;
            break;
        }
    }
    if ($posEocd < 0) {
        return null;
    }
    $jmlEntri = unpack('v', substr($zip, $posEocd + 10, 2))[1];
    $posPusat = unpack('V', substr($zip, $posEocd + 16, 4))[1];

    $p = $posPusat;
    for ($n = 0; $n < $jmlEntri; $n++) {
        if (substr($zip, $p, 4) !== "PK\x01\x02") {
            return null;
        }
        $metode = unpack('v', substr($zip, $p + 10, 2))[1];
        $ukuranKompres = unpack('V', substr($zip, $p + 20, 4))[1];
        $namaPanjang = unpack('v', substr($zip, $p + 28, 2))[1];
        $tambahanPanjang = unpack('v', substr($zip, $p + 30, 2))[1];
        $komentarPanjang = unpack('v', substr($zip, $p + 32, 2))[1];
        $posLokal = unpack('V', substr($zip, $p + 42, 4))[1];
        $isiNama = substr($zip, $p + 46, $namaPanjang);

        if ($isiNama === $nama) {
            if (substr($zip, $posLokal, 4) !== "PK\x03\x04") {
                return null;
            }
            $namaLokal = unpack('v', substr($zip, $posLokal + 26, 2))[1];
            $tambahanLokal = unpack('v', substr($zip, $posLokal + 28, 2))[1];
            $awal = $posLokal + 30 + $namaLokal + $tambahanLokal;
            $data = substr($zip, $awal, $ukuranKompres);
            if ($metode === 0) {
                return $data === false ? null : $data;
            }
            if ($metode === 8) {
                $out = @gzinflate($data);
                return $out === false ? null : $out;
            }
            return null; // metode kompresi lain tidak didukung
        }
        $p += 46 + $namaPanjang + $tambahanPanjang + $komentarPanjang;
    }
    return null;
}

/** Escape teks untuk XML. */
function docx_esc(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Satu sel tabel Word (teks boleh memuat baris baru). */
function docx_sel(string $teks, bool $tebal = false): string
{
    $par = '';
    foreach (explode("\n", $teks) as $baris) {
        $par .= '<w:p><w:pPr><w:spacing w:after="0"/></w:pPr><w:r>'
            . ($tebal ? '<w:rPr><w:b/></w:rPr>' : '')
            . '<w:t xml:space="preserve">' . docx_esc($baris) . '</w:t></w:r></w:p>';
    }
    return '<w:tc><w:tcPr><w:tcMar><w:bottom w:w="40" w:type="dxa"/><w:top w:w="40" w:type="dxa"/>'
        . '</w:tcMar></w:tcPr>' . $par . '</w:tc>';
}

/** Satu baris tabel Word. */
function docx_baris(array $sel): string
{
    $isi = '';
    foreach ($sel as $s) {
        $teks = is_array($s) ? (string) ($s[0] ?? '') : (string) $s;
        $tebal = is_array($s) ? !empty($s[1]) : false;
        $isi .= docx_sel($teks, $tebal);
    }
    return '<w:tr>' . $isi . '</w:tr>';
}

/**
 * Bangun dokumen .docx berisi judul, catatan, dan SATU tabel.
 * $baris: daftar baris; tiap baris = daftar sel (string) atau [teks, tebal].
 */
function docx_dari_tabel(string $judul, array $kolom, array $baris, array $catatan = []): string
{
    $isi = '<w:p><w:pPr><w:jc w:val="center"/></w:pPr><w:r><w:rPr><w:b/><w:sz w:val="28"/></w:rPr>'
        . '<w:t xml:space="preserve">' . docx_esc($judul) . '</w:t></w:r></w:p>';
    foreach ($catatan as $c) {
        $isi .= '<w:p><w:r><w:t xml:space="preserve">' . docx_esc($c) . '</w:t></w:r></w:p>';
    }
    $isi .= '<w:p/>';

    $isi .= '<w:tbl><w:tblPr><w:tblStyle w:val="TableGrid"/><w:tblW w:w="0" w:type="auto"/>'
        . '<w:tblBorders>'
        . '<w:top w:val="single" w:sz="6" w:color="999999"/><w:left w:val="single" w:sz="6" w:color="999999"/>'
        . '<w:bottom w:val="single" w:sz="6" w:color="999999"/><w:right w:val="single" w:sz="6" w:color="999999"/>'
        . '<w:insideH w:val="single" w:sz="4" w:color="BBBBBB"/><w:insideV w:val="single" w:sz="4" w:color="BBBBBB"/>'
        . '</w:tblBorders></w:tblPr>';
    $kepala = [];
    foreach ($kolom as $k) {
        $kepala[] = [(string) $k, true];
    }
    $isi .= docx_baris($kepala);
    foreach ($baris as $b) {
        $isi .= docx_baris($b);
    }
    $isi .= '</w:tbl>';

    $dokumen = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:body>' . $isi . '<w:sectPr><w:pgSz w:w="16838" w:h="11906" w:orient="landscape"/>'
        . '<w:pgMar w:top="720" w:right="720" w:bottom="720" w:left="720"/></w:sectPr></w:body></w:document>';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        . '</Types>';
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
        . '</Relationships>';

    return zip_sederhana([
        '[Content_Types].xml' => $contentTypes,
        '_rels/.rels' => $rels,
        'word/document.xml' => $dokumen,
    ]);
}

/** Kirim berkas .docx ke peramban lalu hentikan skrip. */
function docx_kirim(string $namaBerkas, string $bytes): void
{
    if (!str_ends_with(strtolower($namaBerkas), '.docx')) {
        $namaBerkas .= '.docx';
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $namaBerkas . '"');
    header('Content-Length: ' . strlen($bytes));
    header('Pragma: no-cache');
    echo $bytes;
    exit;
}

/**
 * Baca tabel PERTAMA dari berkas .docx -> daftar baris; tiap baris = daftar teks sel.
 * Prefiks namespace dilepas lebih dahulu sehingga berlaku untuk prefiks apa pun (w:, a:, dsb.).
 */
function docx_baca_tabel(string $bytes): array
{
    $xml = zip_baca_satu($bytes, 'word/document.xml');
    if ($xml === null || $xml === '') {
        return [];
    }
    // Lepas prefiks namespace: <w:tbl> -> <tbl>, </w:tc> -> </tc>
    $xml = preg_replace('#<(/?)[A-Za-z0-9_]+:#', '<$1', $xml) ?? $xml;
    if (preg_match('#<tbl[ >].*?</tbl>#s', $xml, $m) !== 1) {
        return [];
    }
    $tabel = $m[0];
    $hasil = [];
    if (preg_match_all('#<tr[ >].*?</tr>#s', $tabel, $baris) === false) {
        return [];
    }
    foreach ($baris[0] as $tr) {
        $sel = [];
        if (preg_match_all('#<tc[ >].*?</tc>#s', $tr, $tcs) === false) {
            continue;
        }
        foreach ($tcs[0] as $tc) {
            // Pemisah paragraf -> baris baru, lalu ambil isi setiap <t>
            $tc = preg_replace('#</p>#', "\n", $tc) ?? $tc;
            $teks = '';
            if (preg_match_all('#<t[ >][^>]*>(.*?)</t>#s', $tc, $tt) !== false) {
                $teks = implode('', $tt[1]);
            }
            $teks = str_replace(['&amp;', '&lt;', '&gt;', '&quot;', '&apos;'], ['&', '<', '>', '"', "'"], $teks);
            $sel[] = trim(preg_replace('/\s*\n\s*/', "\n", $teks) ?? $teks);
        }
        $hasil[] = $sel;
    }
    return $hasil;
}

/**
 * Baca SEMUA paragraf dari berkas .docx sebagai daftar baris teks (urutan asli dokumen).
 *
 * Dipakai template soal BENTUK TEKS (v1.13.0) yang berupa tulisan biasa, bukan tabel.
 * Template LAMA berbentuk tabel tetap dibaca `docx_baca_tabel()` lebih dahulu — lihat
 * `ujian_import.php` (bila tabel ditemukan, jalur tabel yang dipakai).
 */
function docx_baca_paragraf(string $bytes): array
{
    $xml = zip_baca_satu($bytes, 'word/document.xml');
    if ($xml === null || $xml === '') {
        return [];
    }
    // Lepas prefiks namespace (<w:p> -> <p>) supaya tidak bergantung pada prefiksnya.
    $xml = preg_replace('#<(/?)[A-Za-z0-9_]+:#', '<$1', $xml) ?? $xml;
    foreach (['<br/>', '<br />', '<br></br>'] as $br) {
        $xml = str_replace($br, '<crt/>', $xml);
    }
    $xml = str_replace('<tab/>', ' ', $xml);

    $out = [];
    // Setiap paragraf diakhiri </p> — teks di dalam tabel pun ikut terbaca (tidak mengganggu,
    // karena berkas bertabel sudah ditangani jalur tabel lebih dahulu).
    foreach (preg_split('#</p>#', $xml) ?: [] as $blok) {
        if (!preg_match('#<t[ >]#', $blok)) {
            continue;
        }
        $teks = '';
        if (preg_match_all('#<t[ >][^>]*>(.*?)</t>#s', $blok, $m) !== false) {
            $teks = implode('', $m[1]);
        }
        // Pemisah baris di dalam satu paragraf (Shift+Enter di Word).
        $teks = preg_replace('#<crt\s*/?>#', "\n", $teks) ?? $teks;
        $teks = html_entity_decode($teks, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $teks = preg_replace('/[ \t]+/', ' ', $teks) ?? $teks;
        $out[] = trim($teks);
    }
    return $out;
}

// =====================================================================================
// PEMBUAT DOKUMEN UMUM (paragraf + gambar + tabel) — dipakai menu BANK SOAL
// =====================================================================================

/** Paragraf Word. $opsi: tebal, miring, tengah, kecil, jarak (spacing after, dxa), indent (dxa). */
function docx_par(string $teks, array $opsi = []): string
{
    $tebal = !empty($opsi['tebal']);
    $miring = !empty($opsi['miring']);
    $tengah = !empty($opsi['tengah']);
    $ukuran = !empty($opsi['kecil']) ? '18' : '22'; // setengah poin (22 = 11pt)
    $pr = '<w:pPr>';
    if ($tengah) {
        $pr .= '<w:jc w:val="center"/>';
    }
    $ind = (int) ($opsi['indent'] ?? 0);
    if ($ind > 0) {
        $pr .= '<w:ind w:left="' . $ind . '"/>';
    }
    $jarak = (int) ($opsi['jarak'] ?? 60);
    $pr .= '<w:spacing w:before="0" w:after="' . $jarak . '" w:line="240" w:lineRule="auto"/>';
    $pr .= '</w:pPr>';

    $rpr = '<w:rPr><w:sz w:val="' . $ukuran . '"/>'
        . ($tebal ? '<w:b/>' : '') . ($miring ? '<w:i/>' : '') . '</w:rPr>';

    $isi = '';
    foreach (explode("\n", $teks) as $i => $baris) {
        if ($i > 0) {
            $isi .= '<w:r><w:br/></w:r>';
        }
        $isi .= '<w:r>' . $rpr . '<w:t xml:space="preserve">' . docx_esc($baris) . '</w:t></w:r>';
    }
    if ($isi === '') {
        $isi = '<w:r>' . $rpr . '<w:t xml:space="preserve"></w:t></w:r>';
    }
    return '<w:p>' . $pr . $isi . '</w:p>';
}

/**
 * Gambar sebagai paragraf. $lebarCm lebar akhir; tinggi dihitung dari rasio asli.
 * Mengembalikan [xml, namaMedia, isiBerkas, ext] — relasi ditambahkan pemanggil.
 */
function docx_par_gambar(string $bytes, string $ext, float $lebarCm = 11.0): array
{
    [$w, $h] = gambar_ukuran($bytes);
    if ($w <= 0 || $h <= 0) {
        $w = 800;
        $h = 600;
    }
    // 1 cm = 360000 EMU
    $cx = (int) round($lebarCm * 360000);
    $cy = (int) round($cx * $h / $w);
    $xml = '<w:p><w:pPr><w:spacing w:after="80"/></w:pPr><w:r><w:drawing>'
        . '<wp:inline distT="0" distB="0" distL="0" distR="0" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing">'
        . '<wp:extent cx="' . $cx . '" cy="' . $cy . '"/>'
        . '<wp:docPr id="' . random_int(1000, 999999) . '" name="Gambar"/>'
        . '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
        . '<a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
        . '<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
        . '<pic:nvPicPr><pic:cNvPr id="0" name="Gambar"/><pic:cNvPicPr/></pic:nvPicPr>'
        . '<pic:blipFill><a:blip r:embed="__RID__" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"/>'
        . '<a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
        . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm>'
        . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
        . '</pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';
    return [$xml, $ext];
}

/**
 * Bangun dokumen dari daftar blok.
 *   ['teks' => '...', 'tebal' => bool, 'miring' => bool, 'tengah' => bool, 'kecil' => bool,
 *    'indent' => int, 'jarak' => int, 'gambar' => [bytes, ext, lebarCm], 'tabel' => [kolom, baris]]
 */
function docx_bangun(array $blok, string $judul = ''): string
{
    $isi = '';
    $media = [];   // nama berkas => isi
    $relasi = [];  // rid => [nama, ext]
    $rid = 100;

    foreach ($blok as $b) {
        if (isset($b['tabel']) && is_array($b['tabel'])) {
            $kolom = $b['tabel'][0] ?? [];
            $baris = $b['tabel'][1] ?? [];
            $t = '<w:tbl><w:tblPr><w:tblW w:w="0" w:type="auto"/><w:tblBorders>'
                . '<w:top w:val="single" w:sz="6" w:color="999999"/><w:left w:val="single" w:sz="6" w:color="999999"/>'
                . '<w:bottom w:val="single" w:sz="6" w:color="999999"/><w:right w:val="single" w:sz="6" w:color="999999"/>'
                . '<w:insideH w:val="single" w:sz="4" w:color="BBBBBB"/><w:insideV w:val="single" w:sz="4" w:color="BBBBBB"/>'
                . '</w:tblBorders></w:tblPr>';
            $kepala = [];
            foreach ($kolom as $k) {
                $kepala[] = [(string) $k, true];
            }
            $t .= docx_baris($kepala);
            foreach ($baris as $rb) {
                $t .= docx_baris($rb);
            }
            $t .= '</w:tbl>';
            $isi .= $t . '<w:p><w:pPr><w:spacing w:after="120"/></w:pPr></w:p>';
            continue;
        }
        if (!empty($b['gambar']) && is_array($b['gambar']) && strlen((string) $b['gambar'][0]) > 0) {
            [$xml, $ext] = docx_par_gambar((string) $b['gambar'][0], (string) ($b['gambar'][1] ?? 'png'),
                (float) ($b['gambar'][2] ?? 11.0));
            $nama = 'soal' . (count($media) + 1) . '.' . $ext;
            $media[$nama] = (string) $b['gambar'][0];
            $relasi[$rid] = $nama;
            $xml = str_replace('__RID__', 'rId' . $rid, $xml);
            $rid++;
            $isi .= $xml;
            continue;
        }
        $isi .= docx_par((string) ($b['teks'] ?? ''), $b);
    }

    $dokumen = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:body>' . $isi . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/>'
        . '<w:pgMar w:top="720" w:right="720" w:bottom="720" w:left="720"/></w:sectPr></w:body></w:document>';

    $tipeGambar = '';
    $extDipakai = array_unique(array_map(function ($n) {
        return strtolower((string) pathinfo($n, PATHINFO_EXTENSION));
    }, array_values($relasi)));
    foreach ($extDipakai as $ext) {
        $ct = ['png' => 'image/png', 'jpeg' => 'image/jpeg', 'jpg' => 'image/jpeg',
            'gif' => 'image/gif', 'svg' => 'image/svg+xml'][$ext] ?? 'image/png';
        $tipeGambar .= '<Default Extension="' . $ext . '" ContentType="' . $ct . '"/>';
    }
    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Default Extension="docx" ContentType="application/octet-stream"/>'
        . $tipeGambar
        . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        . '</Types>';
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
        . '</Relationships>';

    $docRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    foreach ($relasi as $ridNomor => $nama) {
        $docRels .= '<Relationship Id="rId' . $ridNomor . '" '
            . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" '
            . 'Target="media/' . $nama . '"/>';
    }
    $docRels .= '</Relationships>';

    $entri = [
        '[Content_Types].xml' => $contentTypes,
        '_rels/.rels' => $rels,
        'word/document.xml' => $dokumen,
    ];
    if ($relasi) {
        $entri['word/_rels/document.xml.rels'] = $docRels;
        foreach ($media as $nama => $bytes) {
            $entri['word/media/' . $nama] = $bytes;
        }
    }
    return zip_sederhana($entri);
}
