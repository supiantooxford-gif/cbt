<?php
/**
 * Modul pembaca berkas Word LAMA — `.doc` (format biner "Compound File" / OLE2).
 * TANPA pustaka luar dan TANPA ekstensi tambahan (`zip`, `mbstring`, dsb. tidak dipakai).
 *
 * Permintaan pemilik: *"Pastikan aplikasi bisa mengimpor dokumen word tipe .doc — saat ini aplikasi
 * hanya bisa import tipe .docx"*.
 *
 * Cara kerja singkat (format .doc 97–2003):
 *   1. Berkasnya adalah "wadah" OLE2 (Compound File Binary) berisi beberapa STREAM; yang penting:
 *      **WordDocument** (isi dokumen) dan **0Table/1Table** (tabel struktur).
 *   2. Di dalam stream WordDocument ada FIB (File Information Block). FIB menunjukkan letak tabel
 *      potongan teks (CLX / "piece table") pada stream Table.
 *   3. Piece table memetakan nomor karakter ke lokasi byte di WordDocument; tiap potongan boleh
 *      berupa 8-bit (cp1252, ditandai bit 0x40000000) atau UTF-16LE.
 *   4. Hasilnya teks polos; tanda paragraf diubah menjadi baris baru, tanda sel tabel (0x07)
 *      dipertahankan agar template LAMA berbentuk tabel juga terbaca.
 *
 * Bila berkas .doc isinya sebenarnya berkas lain (banyak guru menamai .docx → .doc, atau berkas
 * RTF/HTML), `doc_baca_paragraf()`/`doc_baca_tabel()` mengembalikan daftar kosong dan pemanggil
 * memberi pesan yang jelas — lihat `ujian_import.php`.
 */
declare(strict_types=1);

// ---------------------------------------------------------------------------
// util baca angka (little-endian) & konversi teks
// ---------------------------------------------------------------------------

function doc_u16(string $b, int $o): int
{
    if ($o < 0 || $o + 2 > strlen($b)) {
        return 0;
    }
    return ord($b[$o]) | (ord($b[$o + 1]) << 8);
}

function doc_u32(string $b, int $o): int
{
    if ($o < 0 || $o + 4 > strlen($b)) {
        return 0;
    }
    return ord($b[$o]) | (ord($b[$o + 1]) << 8) | (ord($b[$o + 2]) << 16) | (ord($b[$o + 3]) << 24);
}

/** Satu titik kode Unicode -> UTF-8. */
function doc_codepoint_utf8(int $u): string
{
    if ($u < 0x80) {
        return chr($u);
    }
    if ($u < 0x800) {
        return chr(0xC0 | ($u >> 6)) . chr(0x80 | ($u & 0x3F));
    }
    if ($u < 0x10000) {
        return chr(0xE0 | ($u >> 12)) . chr(0x80 | (($u >> 6) & 0x3F)) . chr(0x80 | ($u & 0x3F));
    }
    return chr(0xF0 | ($u >> 18)) . chr(0x80 | (($u >> 12) & 0x3F))
        . chr(0x80 | (($u >> 6) & 0x3F)) . chr(0x80 | ($u & 0x3F));
}

/** Teks UTF-16LE (tanpa BOM) -> UTF-8. */
function doc_utf16le_utf8(string $b): string
{
    $n = strlen($b) - (strlen($b) % 2);
    $out = '';
    for ($i = 0; $i < $n; $i += 2) {
        $u = ord($b[$i]) | (ord($b[$i + 1]) << 8);
        if ($u >= 0xD800 && $u <= 0xDBFF && $i + 3 < $n) {
            $u2 = ord($b[$i + 2]) | (ord($b[$i + 3]) << 8);
            if ($u2 >= 0xDC00 && $u2 <= 0xDFFF) {
                $u = 0x10000 + (($u - 0xD800) << 10) + ($u2 - 0xDC00);
                $i += 2;
            }
        }
        $out .= doc_codepoint_utf8($u);
    }
    return $out;
}

/** Teks 8-bit halaman kode Windows-1252 -> UTF-8 (0xA0–0xFF sama dengan Latin-1). */
function doc_cp1252_utf8(string $b): string
{
    static $peta = [
        0x80 => 0x20AC, 0x82 => 0x201A, 0x83 => 0x0192, 0x84 => 0x201E, 0x85 => 0x2026,
        0x86 => 0x2020, 0x87 => 0x2021, 0x88 => 0x02C6, 0x89 => 0x2030, 0x8A => 0x0160,
        0x8B => 0x2039, 0x8C => 0x0152, 0x8E => 0x017D, 0x91 => 0x2018, 0x92 => 0x2019,
        0x93 => 0x201C, 0x94 => 0x201D, 0x95 => 0x2022, 0x96 => 0x2013, 0x97 => 0x2014,
        0x98 => 0x02DC, 0x99 => 0x2122, 0x9A => 0x0161, 0x9B => 0x203A, 0x9C => 0x0153,
        0x9E => 0x017E, 0x9F => 0x0178,
    ];
    $out = '';
    $n = strlen($b);
    for ($i = 0; $i < $n; $i++) {
        $o = ord($b[$i]);
        if ($o < 0x80) {
            $out .= $b[$i];
            continue;
        }
        $out .= doc_codepoint_utf8($peta[$o] ?? $o);
    }
    return $out;
}

// ---------------------------------------------------------------------------
// pengenal jenis berkas
// ---------------------------------------------------------------------------

/** Berkas berformat Word lama (wadah OLE2)? */
function doc_adalah_ole(string $bytes): bool
{
    return strlen($bytes) >= 512 && substr($bytes, 0, 8) === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
}

/** Berkas ZIP (antara lain .docx)? */
function doc_adalah_zip(string $bytes): bool
{
    return substr($bytes, 0, 2) === 'PK';
}

/** Berkas RTF? (banyak "berkas .doc" dari aplikasi lain sebenarnya RTF.) */
function doc_adalah_rtf(string $bytes): bool
{
    return substr(ltrim($bytes), 0, 5) === '{\rtf';
}

// ---------------------------------------------------------------------------
// pembaca wadah OLE2
// ---------------------------------------------------------------------------

/**
 * Baca seluruh STREAM dari wadah OLE2.
 * Hasil: ['ok' => bool, 'streams' => [nama => isi], 'pesan' => string]
 *
 * Mendukung sektor 512/4096, FAT ganda (DIFAT), dan **mini stream** (stream kecil < 4096 byte
 * disimpan pada mini-FAT — inilah cara Word menyimpan berkas pendek, jadi WAJIB didukung).
 */
function doc_ole_baca(string $bytes): array
{
    $hasil = ['ok' => false, 'streams' => [], 'pesan' => ''];
    if (!doc_adalah_ole($bytes)) {
        $hasil['pesan'] = 'Berkas bukan dokumen Word biner (OLE) yang sah.';
        return $hasil;
    }

    $shift = doc_u16($bytes, 0x1E);
    if ($shift < 7 || $shift > 12) {
        $shift = 9;
    }
    $ssz = 1 << $shift;                       // ukuran sektor (512 / 4096)
    $mshift = doc_u16($bytes, 0x20);
    if ($mshift < 4 || $mshift > 12) {
        $mshift = 6;
    }
    $msz = 1 << $mshift;                      // ukuran sektor mini (biasanya 64)

    $dirMulai    = doc_u32($bytes, 0x30);
    $cutoff      = doc_u32($bytes, 0x38) ?: 4096;
    $miniFatMulai = doc_u32($bytes, 0x3C);
    $difatMulai  = doc_u32($bytes, 0x44);

    // Satu sektor (offset = (nomor + 1) * ukuran sektor).
    $sektor = function (int $n) use ($bytes, $ssz): string {
        if ($n < 0) {
            return '';
        }
        $off = ($n + 1) * $ssz;
        if ($off >= strlen($bytes)) {
            return '';
        }
        return (string) substr($bytes, $off, $ssz);
    };

    // ---- DIFAT: daftar nomor sektor FAT ----
    $fatSektor = [];
    for ($i = 0; $i < 109; $i++) {
        $v = doc_u32($bytes, 0x4C + $i * 4);
        if ($v !== 0xFFFFFFFF) {
            $fatSektor[] = $v;
        }
    }
    $d = $difatMulai;
    $jaga = 0;
    while ($d !== 0xFFFFFFFF && $d !== 0xFFFFFFFE && $jaga < 4096) {
        $s = $sektor($d);
        if ($s === '') {
            break;
        }
        $per = intdiv($ssz, 4) - 1;
        for ($i = 0; $i < $per; $i++) {
            $v = doc_u32($s, $i * 4);
            if ($v !== 0xFFFFFFFF) {
                $fatSektor[] = $v;
            }
        }
        $d = doc_u32($s, $per * 4);
        $jaga++;
    }

    // ---- FAT: peta rantai sektor ----
    $fat = [];
    foreach ($fatSektor as $fs) {
        $s = $sektor($fs);
        if ($s === '') {
            continue;
        }
        for ($i = 0, $n = intdiv(strlen($s), 4); $i < $n; $i++) {
            $fat[] = doc_u32($s, $i * 4);
        }
    }
    if (!$fat) {
        $hasil['pesan'] = 'Tabel alokasi berkas Word rusak atau tidak terbaca.';
        return $hasil;
    }

    // Rangkai isi rantai sektor mulai nomor tertentu.
    $rantai = function (int $mulai) use ($fat, $sektor): string {
        $data = '';
        $n = $mulai;
        $jaga = 0;
        while ($n !== 0xFFFFFFFE && $n !== 0xFFFFFFFF && $jaga < 500000) {
            $data .= $sektor($n);
            if (!isset($fat[$n])) {
                break;
            }
            $n = $fat[$n];
            $jaga++;
        }
        return $data;
    };

    // ---- Direktori: daftar stream ----
    $dir = $rantai($dirMulai);
    $entri = [];
    for ($i = 0, $n = intdiv(strlen($dir), 128); $i < $n; $i++) {
        $e = substr($dir, $i * 128, 128);
        $panjangNama = doc_u16($e, 0x40);
        $tipe = ord($e[0x42] ?? "\0");
        if ($panjangNama < 2 || $panjangNama > 64) {
            continue;
        }
        $nama = doc_utf16le_utf8(substr($e, 0, $panjangNama - 2));
        $entri[] = [
            'nama' => $nama,
            'tipe' => $tipe,                 // 1=storage, 2=stream, 5=root
            'mulai' => doc_u32($e, 0x74),
            'ukuran' => doc_u32($e, 0x78),
        ];
    }
    $root = null;
    foreach ($entri as $e) {
        if ($e['tipe'] === 5) {
            $root = $e;
            break;
        }
    }
    if ($root === null) {
        $hasil['pesan'] = 'Struktur berkas Word rusak (kepala direktori tidak ditemukan).';
        return $hasil;
    }

    // ---- Mini stream (isi root) + mini FAT ----
    $mini = '';
    if ($root['ukuran'] > 0 && $root['mulai'] !== 0xFFFFFFFE) {
        $mini = substr($rantai($root['mulai']), 0, $root['ukuran']);
    }
    $miniFat = [];
    if ($miniFatMulai !== 0xFFFFFFFE && $miniFatMulai !== 0xFFFFFFFF) {
        $mf = $rantai($miniFatMulai);
        for ($i = 0, $n = intdiv(strlen($mf), 4); $i < $n; $i++) {
            $miniFat[] = doc_u32($mf, $i * 4);
        }
    }

    foreach ($entri as $e) {
        if ($e['tipe'] !== 2) {
            continue;
        }
        $ukuran = (int) $e['ukuran'];
        if ($ukuran <= 0) {
            $hasil['streams'][$e['nama']] = '';
            continue;
        }
        if ($ukuran < $cutoff) {
            // Stream kecil: dibaca dari mini stream memakai rantai mini FAT.
            $data = '';
            $n = $e['mulai'];
            $jaga = 0;
            while ($n !== 0xFFFFFFFE && $n !== 0xFFFFFFFF && $jaga < 500000) {
                $data .= substr($mini, $n * $msz, $msz);
                if (!isset($miniFat[$n])) {
                    break;
                }
                $n = $miniFat[$n];
                $jaga++;
            }
            if ($data === '') {
                // Cadangan: rantai mini tidak terbaca, ambil berurutan dari posisi awal.
                $data = substr($mini, $e['mulai'] * $msz, $ukuran);
            }
            $hasil['streams'][$e['nama']] = substr($data, 0, $ukuran);
        } else {
            $hasil['streams'][$e['nama']] = substr($rantai($e['mulai']), 0, $ukuran);
        }
    }

    $hasil['ok'] = isset($hasil['streams']['WordDocument'])
        && $hasil['streams']['WordDocument'] !== '';
    if (!$hasil['ok']) {
        $hasil['pesan'] = 'Berkas tidak memuat isi dokumen Word (stream WordDocument tidak ditemukan).';
    }
    return $hasil;
}

// ---------------------------------------------------------------------------
// teks dari stream WordDocument
// ---------------------------------------------------------------------------

/**
 * Teks mentah dokumen (UTF-8, tanda paragraf sudah jadi "\n", tanda sel tabel jadi "\x07").
 * Mengembalikan '' bila isinya tidak terbaca.
 */
function doc_teks_mentah(string $bytes): string
{
    $ole = doc_ole_baca($bytes);
    if (empty($ole['ok'])) {
        return '';
    }
    $wd = (string) $ole['streams']['WordDocument'];
    if (strlen($wd) < 0x44) {
        return '';
    }

    $tanda = doc_u16($wd, 0x0A);
    $namaTabel = ($tanda & 0x0200) ? '1Table' : '0Table';
    $tabel = (string) ($ole['streams'][$namaTabel] ?? '');
    if ($tabel === '') {
        $tabel = (string) ($ole['streams']['1Table'] ?? ($ole['streams']['0Table'] ?? ''));
    }

    $fcMin = doc_u32($wd, 0x18);
    $fcMac = doc_u32($wd, 0x1C);
    $ccpText = doc_u32($wd, 0x4C);
    $fcClx = doc_u32($wd, 0x1A2);
    $lcbClx = doc_u32($wd, 0x1A6);

    $teks = '';
    if ($tabel !== '' && $fcClx > 0 && $lcbClx > 0 && $fcClx + $lcbClx <= strlen($tabel)) {
        $teks = doc_dari_piece_table($wd, $tabel, $fcClx, $lcbClx);
    }
    if ($teks === '') {
        // Dokumen sederhana / berkas lama (Word 6–95): teks berurutan fcMin..fcMac.
        $teks = doc_dari_fc($wd, $fcMin, $fcMac, $ccpText);
    }
    return doc_rapikan_kontrol($teks);
}

/** Baca teks lewat "piece table" (CLX) pada stream Table. */
function doc_dari_piece_table(string $wd, string $tabel, int $fcClx, int $lcbClx): string
{
    $clx = substr($tabel, $fcClx, $lcbClx);
    $panjang = strlen($clx);
    $off = 0;
    $plc = null;
    while ($off < $panjang) {
        $jenis = ord($clx[$off]);
        if ($jenis === 0x01) {
            // Prc (pemformat karakter) — dilewati.
            if ($off + 3 > $panjang) {
                break;
            }
            $off += 3 + doc_u16($clx, $off + 1);
            continue;
        }
        if ($jenis === 0x02) {
            // Pcdt: berisi PlcPcd.
            if ($off + 5 > $panjang) {
                break;
            }
            $plc = substr($clx, $off + 5, doc_u32($clx, $off + 1));
            break;
        }
        break; // bentuk tak dikenal
    }
    if ($plc === null || strlen($plc) < 16) {
        return '';
    }

    // PlcPcd: (n+1) buah CP 4 byte, lalu n buah PCD 8 byte.
    $n = intdiv(strlen($plc) - 4, 12);
    if ($n <= 0) {
        return '';
    }
    $teks = '';
    for ($i = 0; $i < $n; $i++) {
        // Batas aman: dokumen soal tidak perlu lebih dari ~4 juta karakter.
        if (strlen($teks) > 4000000) {
            break;
        }
        $cpAwal = doc_u32($plc, $i * 4);
        $cpAkhir = doc_u32($plc, ($i + 1) * 4);
        $jml = $cpAkhir - $cpAwal;
        if ($jml <= 0 || $jml > 4000000) {
            continue;
        }
        $pcd = 4 * ($n + 1) + $i * 8;
        if ($pcd + 8 > strlen($plc)) {
            break;
        }
        $fc = doc_u32($plc, $pcd + 2);
        if (($fc & 0x40000000) !== 0) {
            // Potongan 8-bit (cp1252); alamat sebenarnya = fc/2.
            $pos = ($fc & 0x3FFFFFFF) >> 1;
            $teks .= doc_cp1252_utf8(substr($wd, $pos, $jml));
        } else {
            $teks .= doc_utf16le_utf8(substr($wd, $fc, $jml * 2));
        }
    }
    return $teks;
}

/** Baca teks berurutan dari fcMin sampai fcMac (dokumen tanpa piece table). */
function doc_dari_fc(string $wd, int $fcMin, int $fcMac, int $ccpText): string
{
    $panjangStream = strlen($wd);
    if ($fcMin <= 0 || $fcMac <= $fcMin || $fcMin >= $panjangStream) {
        // Cadangan terakhir: pakai seluruh stream setelah 0x800 (setelah area FIB).
        $fcMin = min(0x800, max(0, $panjangStream - 1));
        $fcMac = $panjangStream;
    }
    if ($fcMac > $panjangStream) {
        $fcMac = $panjangStream;
    }
    $mentah = substr($wd, $fcMin, max(0, $fcMac - $fcMin));

    // Heuristik: bila banyak byte 0x00 pada posisi ganjil, teksnya UTF-16LE.
    $cek = min(strlen($mentah), 4096);
    $nol = 0;
    for ($i = 1; $i < $cek; $i += 2) {
        if ($mentah[$i] === "\0") {
            $nol++;
        }
    }
    if ($cek > 32 && $nol > $cek / 4) {
        return doc_utf16le_utf8($mentah);
    }
    $teks = doc_cp1252_utf8($mentah);
    // Batasi ke jumlah karakter teks utama bila masuk akal.
    if ($ccpText > 0 && strlen($teks) > $ccpText * 4) {
        $t = '';
        for ($i = 0, $n = strlen($teks); $i < $n && strlen($t) < $ccpText * 4; $i++) {
            $t .= $teks[$i];
        }
        return $t;
    }
    return $teks;
}

/**
 * Rapikan karakter kendali Word:
 *  - 0x13…0x14…0x15 = kode field (dibuang), hasil field (0x14…0x15) DIPERTAHANKAN
 *  - 0x0D (tanda paragraf), 0x0B (baris baru), 0x0C (halaman), 0x0E (kolom) -> "\n"
 *  - 0x07 (tanda sel/baris tabel) dipertahankan sebagai "\x07"
 *  - 0x01/0x02/0x05/0x08/0x1F (objek/gambar/catatan) dibuang
 */
function doc_rapikan_kontrol(string $t): string
{
    $out = '';
    $mode = 0; // 0 = teks biasa, 1 = kode field, 2 = hasil field
    $n = strlen($t);
    for ($i = 0; $i < $n; $i++) {
        $c = $t[$i];
        $o = ord($c);
        if ($o === 0x13) {
            $mode = 1;
            continue;
        }
        if ($o === 0x14) {
            if ($mode === 1) {
                $mode = 2;
            }
            continue;
        }
        if ($o === 0x15) {
            if ($mode !== 0) {
                $mode = 0;
            }
            continue;
        }
        if ($mode === 1) {
            continue;
        }
        if ($o === 0x0D || $o === 0x0B || $o === 0x0C || $o === 0x0E) {
            $out .= "\n";
            continue;
        }
        if ($o === 0x1E) {
            $out .= '-';
            continue;
        }
        if ($o === 0x1F || $o === 0x01 || $o === 0x02 || $o === 0x05 || $o === 0x08) {
            continue;
        }
        if ($o === 0x07) {
            $out .= "\x07";
            continue;
        }
        // Spasi tak-terpisah (U+00A0 dalam UTF-8) -> spasi biasa.
        if ($c === "\xC2" && ($t[$i + 1] ?? '') === "\xA0") {
            $out .= ' ';
            $i++;
            continue;
        }
        $out .= $c;
    }
    return $out;
}

// ---------------------------------------------------------------------------
// API yang dipakai halaman impor
// ---------------------------------------------------------------------------

/**
 * Paragraf dokumen .doc sebagai daftar baris (bentuk yang sama dengan `docx_baca_paragraf()`),
 * dipakai template soal BENTUK TEKS. Tanda sel tabel dijadikan spasi agar kalimat tidak menempel.
 */
function doc_baca_paragraf(string $bytes): array
{
    $teks = doc_teks_mentah($bytes);
    if (trim($teks) === '') {
        return [];
    }
    $baris = [];
    foreach (explode("\n", $teks) as $b) {
        $b = str_replace("\x07", ' ', $b);
        $b = preg_replace('/[ \t]+/', ' ', $b) ?? $b;
        $baris[] = trim($b);
    }
    return $baris;
}

/**
 * Tabel PERTAMA dokumen .doc -> daftar baris; tiap baris = daftar teks sel
 * (bentuk yang sama dengan `docx_baca_tabel()`), dipakai template LAMA berbentuk tabel.
 *
 * Pada format .doc, akhir sel maupun akhir baris sama-sama ditandai 0x07; **dua tanda 0x07
 * berurutan** berarti akhir baris, satu tanda berarti akhir sel.
 */
function doc_baca_tabel(string $bytes): array
{
    $teks = doc_teks_mentah($bytes);
    if (strpos($teks, "\x07") === false) {
        return [];
    }
    $baris = [];
    $sel = [];
    $isi = '';
    $n = strlen($teks);
    for ($i = 0; $i < $n; $i++) {
        $c = $teks[$i];
        if ($c === "\x07") {
            $sel[] = trim(preg_replace('/\s*\n\s*/', "\n", $isi) ?? $isi);
            $isi = '';
            if (($teks[$i + 1] ?? '') === "\x07") {
                $baris[] = $sel;
                $sel = [];
                $i++;
            }
            continue;
        }
        $isi .= $c;
    }
    if (trim($isi) !== '' || $sel) {
        $sel[] = trim($isi);
        $baris[] = $sel;
    }

    $hasil = [];
    foreach ($baris as $r) {
        if (count($r) >= 2) {
            $hasil[] = $r;
        }
    }
    return $hasil;
}
