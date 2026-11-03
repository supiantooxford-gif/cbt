<?php
/**
 * FIXTURE UJI — pembuat berkas Word LAMA (.doc, wadah OLE2) untuk menguji `inc/doc.php`.
 *
 * HANYA untuk keperluan uji: berkas .doc asli dari Microsoft Word tidak tersedia di sandbox,
 * jadi di sini dibuat sendiri wadah OLE2 + FIB + piece table yang sah menurut spesifikasi
 * [MS-CFB] (Compound File Binary) dan [MS-DOC] (Word 97–2003).
 *
 * Pemakaian:
 *   php fixture_doc.php <folder-tujuan>
 * Menulis beberapa berkas .doc dengan bentuk berbeda:
 *   teks_mini.doc     template soal (bentuk teks) — kedua stream KECIL (< 4096 byte, di mini stream)
 *   teks_besar.doc    teks sama, WordDocument BESAR (≥ 4096 byte, di FAT biasa) + 1Table kecil
 *   teks_utf16.doc    potongan teks UTF-16LE (bukan cp1252)
 *   tanpa_clx.doc     tanpa piece table (jalur cadangan fcMin..fcMac)
 *   tabel.doc         template LAMA berbentuk TABEL (tanda sel 0x07)
 *   rusak.doc         bukan wadah OLE sama sekali (harus ditolak dengan pesan jelas)
 *   miring.doc        potongan teks dengan tanda baca cp1252 ( “ ” – ’ é )
 *   docx_palsu.doc    isinya sebenarnya berkas .docx (ZIP) yang hanya diganti namanya
 */
declare(strict_types=1);

require_once __DIR__ . '/inc/impor_soal.php';
require_once __DIR__ . '/inc/docx.php';

// ---------------------------------------------------------------------------
// util penulisan
// ---------------------------------------------------------------------------

/** Nama direktori OLE harus UTF-16LE; nama yang dipakai di sini ASCII. */
function fx_ole_nama(string $s): string
{
    $o = '';
    for ($i = 0, $n = strlen($s); $i < $n; $i++) {
        $o .= $s[$i] . "\0";
    }
    return $o;
}

/** Teks UTF-8 -> UTF-16LE (menangani aksara non-ASCII, seperti Word asli). */
function fx_teks_utf16(string $s): string
{
    $o = '';
    $n = strlen($s);
    for ($i = 0; $i < $n; $i++) {
        $c = ord($s[$i]);
        if ($c < 0x80) {
            $cp = $c;
        } elseif ($c < 0xE0) {
            $cp = (($c & 0x1F) << 6) | (ord($s[$i + 1] ?? "\0") & 0x3F);
            $i += 1;
        } elseif ($c < 0xF0) {
            $cp = (($c & 0x0F) << 12) | ((ord($s[$i + 1] ?? "\0") & 0x3F) << 6)
                | (ord($s[$i + 2] ?? "\0") & 0x3F);
            $i += 2;
        } else {
            $cp = (($c & 0x07) << 18) | ((ord($s[$i + 1] ?? "\0") & 0x3F) << 12)
                | ((ord($s[$i + 2] ?? "\0") & 0x3F) << 6) | (ord($s[$i + 3] ?? "\0") & 0x3F);
            $i += 3;
        }
        if ($cp >= 0x10000) {
            $cp -= 0x10000;
            $o .= pack('v', 0xD800 + ($cp >> 10)) . pack('v', 0xDC00 + ($cp & 0x3FF));
        } else {
            $o .= pack('v', $cp);
        }
    }
    return $o;
}

/**
 * Teks UTF-8 -> cp1252 seperti yang ditulis Word 97–2003 untuk potongan 8-bit.
 * Karakter yang tidak ada di cp1252 diganti "?".
 */
function fx_teks_cp1252(string $s): string
{
    static $peta = [
        '€' => 0x80, '‚' => 0x82, 'ƒ' => 0x83, '„' => 0x84, '…' => 0x85, '†' => 0x86,
        '‡' => 0x87, 'ˆ' => 0x88, '‰' => 0x89, 'Š' => 0x8A, '‹' => 0x8B, 'Œ' => 0x8C,
        'Ž' => 0x8E, '‘' => 0x91, '’' => 0x92, '“' => 0x93, '”' => 0x94, '•' => 0x95,
        '–' => 0x96, '—' => 0x97, '˜' => 0x98, '™' => 0x99, 'š' => 0x9A, '›' => 0x9B,
        'œ' => 0x9C, 'ž' => 0x9E, 'Ÿ' => 0x9F,
    ];
    $o = '';
    $n = strlen($s);
    for ($i = 0; $i < $n; $i++) {
        $c = ord($s[$i]);
        if ($c < 0x80) {
            $o .= $s[$i];
            continue;
        }
        // Ambil satu karakter UTF-8 utuh (1–4 byte) lalu petakan.
        $panjang = $c < 0xE0 ? 2 : ($c < 0xF0 ? 3 : 4);
        $kar = substr($s, $i, $panjang);
        $i += $panjang - 1;
        if (isset($peta[$kar])) {
            $o .= chr($peta[$kar]);
            continue;
        }
        $cp = 0;
        $b0 = ord($kar[0]);
        if ($panjang === 2) {
            $cp = (($b0 & 0x1F) << 6) | (ord($kar[1]) & 0x3F);
        } elseif ($panjang === 3) {
            $cp = (($b0 & 0x0F) << 12) | ((ord($kar[1]) & 0x3F) << 6) | (ord($kar[2]) & 0x3F);
        }
        // Latin-1 (0xA0–0xFF) sama persis dengan cp1252.
        $o .= ($cp >= 0xA0 && $cp <= 0xFF) ? chr($cp) : '?';
    }
    return $o;
}

/**
 * Bangun wadah OLE2 (Compound File) dengan sektor 512 byte, mini sektor 64 byte, cutoff 4096.
 * Stream < 4096 byte ditempatkan di MINI STREAM (seperti yang dilakukan Word) — jalur ini wajib
 * ikut diuji karena dokumen pendek selalu memakai mini stream.
 *
 * @param array<string,string> $streams nama => isi
 */
function fx_ole_bangun(array $streams): string
{
    $S = 512;
    $M = 64;
    $CUT = 4096;

    // ---- 1. Pisahkan stream kecil (mini) dan besar ----
    $miniData = '';
    $miniFat = [];
    $miniMulai = [];
    $besar = [];
    foreach ($streams as $nama => $data) {
        if (strlen($data) < $CUT) {
            $perlu = max(1, (int) ceil(strlen($data) / $M));
            $mulai = intdiv(strlen($miniData), $M);
            for ($i = 0; $i < $perlu; $i++) {
                $miniFat[$mulai + $i] = ($i === $perlu - 1) ? 0xFFFFFFFE : $mulai + $i + 1;
            }
            $miniData .= str_pad($data, $perlu * $M, "\0");
            $miniMulai[$nama] = $mulai;
        } else {
            $besar[$nama] = $data;
        }
    }

    // ---- 2. Perkirakan jumlah sektor ----
    $miniSektor = $miniData === '' ? 0 : (int) ceil(strlen($miniData) / $S);
    $jumlahEntri = 1 + count($streams);                 // root + stream
    $dirSektor = (int) ceil($jumlahEntri * 128 / $S);
    $miniFatSektor = $miniFat ? (int) ceil(count($miniFat) * 4 / $S) : 0;
    $totalBesar = 0;
    foreach ($besar as $d) {
        $totalBesar += (int) ceil(strlen($d) / $S);
    }
    $fatSektor = 1;
    for ($i = 0; $i < 32; $i++) {
        $perlu = (int) ceil(($totalBesar + $miniSektor + $dirSektor + $miniFatSektor + $fatSektor) / 128);
        if ($perlu <= $fatSektor) {
            break;
        }
        $fatSektor = $perlu;
    }

    // ---- 3. Alokasi nomor sektor ----
    $fat = [];      // nomor sektor => nilai FAT
    $next = 0;
    $fatMulai = [];
    for ($i = 0; $i < $fatSektor; $i++) {
        $fatMulai[] = $next;
        $fat[$next] = 0xFFFFFFFD;                       // FATSECT
        $next++;
    }
    $alokasi = function (int $jumlah) use (&$next, &$fat): int {
        if ($jumlah <= 0) {
            return 0xFFFFFFFE;
        }
        $mulai = $next;
        for ($i = 0; $i < $jumlah; $i++) {
            $fat[$next] = ($i === $jumlah - 1) ? 0xFFFFFFFE : $next + 1;
            $next++;
        }
        return $mulai;
    };
    $dirMulai = $alokasi($dirSektor);
    $miniFatMulai = $alokasi($miniFatSektor);
    $miniStreamMulai = $alokasi($miniSektor);
    $besarMulai = [];
    foreach ($besar as $nama => $d) {
        $besarMulai[$nama] = $alokasi((int) ceil(strlen($d) / $S));
    }
    $totalSektor = $next;

    // ---- 4. Entri direktori ----
    $entriBin = function (string $nama, int $tipe, int $kiri, int $kanan, int $anak, int $mulai, int $ukuran): string {
        $nm = str_pad(fx_ole_nama($nama), 64, "\0");
        $b = $nm;
        $b .= pack('v', $nama === '' ? 0 : (strlen($nama) + 1) * 2);
        $b .= chr($tipe) . chr(1);                       // tipe, warna hitam
        $b .= pack('V', $kiri) . pack('V', $kanan) . pack('V', $anak);
        $b .= str_repeat("\0", 16);                      // CLSID
        $b .= pack('V', 0);                              // state bits
        $b .= str_repeat("\0", 16);                      // creation + modified
        $b .= pack('V', $mulai);
        $b .= pack('V', $ukuran) . pack('V', 0);         // ukuran 8 byte
        return $b;
    };

    $daftar = [];
    // Root: anak = 1 (stream pertama), ukuran = panjang mini stream.
    $daftar[] = $entriBin('Root Entry', 5, 0xFFFFFFFF, 0xFFFFFFFF,
        $jumlahEntri > 1 ? 1 : 0xFFFFFFFF, $miniStreamMulai, strlen($miniData));
    $i = 1;
    foreach ($streams as $nama => $data) {
        $mulai = $besarMulai[$nama] ?? ($miniMulai[$nama] ?? 0xFFFFFFFE);
        // Pohon sederhana berantai ke kanan: 1 -> 2 -> 3 …
        $kanan = ($i + 1 < $jumlahEntri) ? $i + 1 : 0xFFFFFFFF;
        $daftar[] = $entriBin($nama, 2, 0xFFFFFFFF, $kanan, 0xFFFFFFFF, $mulai, strlen($data));
        $i++;
    }
    $dirBin = str_pad(implode('', $daftar), $dirSektor * $S, "\0");

    // ---- 5. Isi sektor ----
    $isi = [];
    foreach ($fatMulai as $idx => $sektorFat) {
        $s = '';
        for ($j = 0; $j < intdiv($S, 4); $j++) {
            $nomor = $idx * intdiv($S, 4) + $j;
            $s .= pack('V', $fat[$nomor] ?? 0xFFFFFFFF);
        }
        $isi[$sektorFat] = $s;
    }
    for ($i = 0; $i < $dirSektor; $i++) {
        $isi[$dirMulai + $i] = substr($dirBin, $i * $S, $S);
    }
    if ($miniFatSektor > 0) {
        $mf = '';
        foreach ($miniFat as $v) {
            $mf .= pack('V', $v);
        }
        $mf = str_pad($mf, $miniFatSektor * $S, "\0");
        for ($i = 0; $i < $miniFatSektor; $i++) {
            $isi[$miniFatMulai + $i] = substr($mf, $i * $S, $S);
        }
    }
    if ($miniSektor > 0) {
        $ms = str_pad($miniData, $miniSektor * $S, "\0");
        for ($i = 0; $i < $miniSektor; $i++) {
            $isi[$miniStreamMulai + $i] = substr($ms, $i * $S, $S);
        }
    }
    foreach ($besar as $nama => $d) {
        $mulai = $besarMulai[$nama];
        $d = str_pad($d, (int) ceil(strlen($d) / $S) * $S, "\0");
        for ($i = 0; $i < intdiv(strlen($d), $S); $i++) {
            $isi[$mulai + $i] = substr($d, $i * $S, $S);
        }
    }

    // ---- 6. Kepala berkas (512 byte) ----
    $h = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
    $h .= str_repeat("\0", 16);                          // CLSID
    $h .= pack('v', 0x003E) . pack('v', 0x0003);          // minor/major version
    $h .= pack('v', 0xFFFE);                             // byte order
    $h .= pack('v', 9) . pack('v', 6);                   // sector shift, mini sector shift
    $h .= str_repeat("\0", 6);                           // reserved
    $h .= pack('V', 0);                                  // jumlah sektor direktori (v3 = 0)
    $h .= pack('V', count($fatMulai));                   // jumlah sektor FAT
    $h .= pack('V', $dirMulai);                          // sektor direktori pertama
    $h .= pack('V', 0);                                  // transaction signature
    $h .= pack('V', $CUT);                               // cutoff mini stream
    $h .= pack('V', $miniFatMulai);                      // sektor mini FAT pertama
    $h .= pack('V', $miniFatSektor);                     // jumlah sektor mini FAT
    $h .= pack('V', 0xFFFFFFFE);                         // sektor DIFAT pertama (tidak ada)
    $h .= pack('V', 0);                                  // jumlah sektor DIFAT
    for ($i = 0; $i < 109; $i++) {
        $h .= pack('V', $fatMulai[$i] ?? 0xFFFFFFFF);
    }
    $h = str_pad($h, 512, "\0");

    $out = $h;
    for ($i = 0; $i < $totalSektor; $i++) {
        $out .= $isi[$i] ?? str_repeat("\0", $S);
    }
    return $out;
}

/**
 * Bangun berkas .doc dari teks.
 * $opsi: cp1252 (bool, bawaan true) · clx (bool, bawaan true) · pad (int, panjang minimum stream)
 *        bingkai (int, offset awal teks; bawaan 0x400)
 */
function fx_doc_bangun(string $teks, array $opsi = []): string
{
    $cp1252 = $opsi['cp1252'] ?? true;
    $pakaiClx = $opsi['clx'] ?? true;
    $pad = (int) ($opsi['pad'] ?? 0);
    $posTeks = (int) ($opsi['bingkai'] ?? 0x400);

    // "\n" -> tanda paragraf (0x0D). Tanda sel tabel (0x07) dibiarkan apa adanya.
    $t = str_replace("\n", "\r", $teks) . "\r";
    if ($cp1252) {
        $bytesTeks = fx_teks_cp1252($t);
        $jmlChar = strlen($bytesTeks);
    } else {
        $bytesTeks = fx_teks_utf16($t);
        $jmlChar = intdiv(strlen($bytesTeks), 2);
    }

    $wd = str_repeat("\0", $posTeks) . $bytesTeks;

    // CLX (piece table) pada stream 1Table, diawali 64 byte lain-lain.
    $clx = '';
    $isiTabel = '';
    if ($pakaiClx) {
        $cp = pack('V', 0) . pack('V', $jmlChar);
        $fc = $cp1252 ? (($posTeks * 2) | 0x40000000) : $posTeks;
        $pcd = pack('v', 0) . pack('V', $fc) . pack('v', 0);
        $plc = $cp . $pcd;
        $clx = "\x02" . pack('V', strlen($plc)) . $plc;
        $isiTabel = str_repeat("\0", 64) . $clx;
    }

    // FIB.
    $wd = substr_replace($wd, pack('v', 0xA5EC), 0, 2);              // wIdent
    $wd = substr_replace($wd, pack('v', 0x00C1), 2, 2);              // nFib (Word 97)
    $wd = substr_replace($wd, pack('v', 0x0421), 6, 2);              // lid
    $wd = substr_replace($wd, pack('v', 0x0200), 10, 2);             // fWhichTblStm = 1Table
    $wd = substr_replace($wd, pack('v', 0x00BF), 12, 2);             // nFibBack
    $wd = substr_replace($wd, pack('V', $posTeks), 0x18, 4);         // fcMin
    $wd = substr_replace($wd, pack('V', $posTeks + strlen($bytesTeks)), 0x1C, 4); // fcMac
    $wd = substr_replace($wd, pack('V', $jmlChar), 0x4C, 4);         // ccpText
    $wd = substr_replace($wd, pack('V', $pakaiClx ? 64 : 0), 0x1A2, 4);  // fcClx
    $wd = substr_replace($wd, pack('V', $pakaiClx ? strlen($clx) : 0), 0x1A6, 4); // lcbClx
    if ($pad > strlen($wd)) {
        $wd = str_pad($wd, $pad, "\0");
    }

    return fx_ole_bangun(['WordDocument' => $wd, '1Table' => $isiTabel]);
}

// ---------------------------------------------------------------------------
// CLI: tulis berkas contoh ke folder tujuan
// ---------------------------------------------------------------------------
if (PHP_SAPI === 'cli' && isset($argv[1])) {
    $dir = rtrim((string) $argv[1], '/');
    if ($dir === '' || !is_dir($dir)) {
        fwrite(STDERR, "Folder tujuan tidak ada: $dir\n");
        exit(1);
    }

    $template = soal_template_teks();

    // 1. Dokumen pendek: seluruh isi berada di MINI STREAM.
    file_put_contents($dir . '/teks_mini.doc', fx_doc_bangun($template));

    // 2. WordDocument besar (di FAT biasa) + 1Table kecil (tetap di mini stream).
    file_put_contents($dir . '/teks_besar.doc', fx_doc_bangun($template, ['pad' => 9000]));

    // 3. Potongan teks UTF-16LE.
    file_put_contents($dir . '/teks_utf16.doc', fx_doc_bangun($template, ['cp1252' => false]));

    // 4. Tanpa piece table (jalur cadangan fcMin..fcMac).
    file_put_contents($dir . '/tanpa_clx.doc', fx_doc_bangun($template, ['clx' => false]));

    // 5. Template LAMA berbentuk TABEL. Struktur .doc asli: tiap SEL diakhiri tanda paragraf
    //    (0x0D) lalu tanda sel (0x07); akhir BARIS ditandai satu 0x07 tambahan ("\x07\x07").
    $baris = [];
    $baris[] = implode("\x07", array_map(fn($x) => $x . "\r", ['Jenis', 'Pertanyaan', 'Opsi A',
        'Opsi B', 'Opsi C', 'Opsi D', 'Opsi E', 'Jawaban', 'Skor', 'Pembahasan'])) . "\x07\x07";
    $baris[] = implode("\x07", array_map(fn($x) => $x . "\r", ['pg', 'Hasil 7 x 8 adalah ...',
        '54', '56', '58', '64', '', 'B', '1', ''])) . "\x07\x07";
    $baris[] = implode("\x07", array_map(fn($x) => $x . "\r", ['bs',
        'Matahari terbit di sebelah timur.', 'Benar', 'Salah', '', '', '', 'B,S', '2', ''])) . "\x07\x07";
    file_put_contents($dir . '/tabel.doc', fx_doc_bangun(implode("\n", $baris), ['pad' => 6000]));

    // 6. Bukan Word sama sekali.
    file_put_contents($dir . '/rusak.doc', "Ini hanya berkas teks biasa, bukan dokumen Word.\n");

    // 7. Tanda baca cp1252 ( “ ” – ’ é ) untuk menguji konversi halaman kode.
    $miring = "Template Soal Essai Singkat\n1. Apa itu “fotosintesis”? Jelaskan – singkat saja.\n"
        . "Kunci: proses tumbuhan membuat makanan dengan bantuan cahaya matahari\n";
    file_put_contents($dir . '/miring.doc', fx_doc_bangun($miring));

    // 8. Berkas .docx yang hanya diganti namanya menjadi .doc (kolom lengkap agar soalnya sah).
    $docx = docx_dari_tabel('Soal Uji',
        ['Jenis', 'Pertanyaan', 'Opsi A', 'Opsi B', 'Opsi C', 'Jawaban', 'Skor', 'Pembahasan'], [
            ['pg', 'Soal dari docx yang dinamai .doc', 'Satu', 'Dua', 'Tiga', 'C', '1', ''],
        ]);
    file_put_contents($dir . '/docx_palsu.doc', $docx);

    echo "Fixture .doc dibuat di $dir\n";
    foreach (glob($dir . '/*.doc') ?: [] as $f) {
        printf("  %-18s %6d byte\n", basename($f), filesize($f));
    }
    exit(0);
}
