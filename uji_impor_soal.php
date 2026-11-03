<?php
/**
 * Uji PEMBACA TEMPLATE SOAL BENTUK TEKS (tanpa server, tanpa basis data).
 *
 * Dipakai `tests_cbt_impor_soal_teks.sh`:
 *   php uji_impor_soal.php
 *
 * Hanya boleh dijalankan dari baris perintah (bukan lewat peramban).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Hanya dari baris perintah.\n");
}

require_once __DIR__ . '/inc/impor_soal.php';

$lulus = 0;
$gagal = 0;
function ok(string $t): void
{
    global $lulus;
    $lulus++;
    echo "  ok   $t\n";
}
function no(string $t): void
{
    global $gagal;
    $gagal++;
    echo "  GAGAL $t\n";
}
function cek(string $t, $dapat, $harap): void
{
    if ($dapat === $harap) {
        ok($t);
    } else {
        no($t . ' (dapat: ' . var_export($dapat, true) . ', harap: ' . var_export($harap, true) . ')');
    }
}
function benar(string $t, bool $k): void
{
    $k ? ok($t) : no($t);
}
/** Jenis soal satu baris teks (membantu uji potongan kecil). */
function parse(string $teks): array
{
    return soal_teks_parse($teks);
}

// =====================================================================================
echo "== 1. Template contoh (6 jenis soal) ==\n";
$tpl = soal_template_teks();
$h = parse($tpl);
cek('jumlah soal pada template contoh', count($h['baris']), 6);
cek('tidak ada soal yang dilaporkan salah', count($h['salah']), 0);
cek('urutan jenis soal', implode(',', array_map(fn($r) => $r['jenis'], $h['baris'])),
    'pg,pgk,bs,jodoh,uraian,uraian');
benar('petunjuk pengisian di atas tidak menjadi soal',
    !str_contains(strtolower(implode(' ', array_column($h['baris'], 'pertanyaan'))), 'petunjuk pengisian'));

// Pilihan ganda
$pg = $h['baris'][0];
cek('PG: jumlah pilihan', count($pg['opsi']), 5);
benar('PG: pertanyaan diawali benar', str_starts_with($pg['pertanyaan'], 'Manakah dari pernyataan berikut'));
$siap = soal_baris_siap($pg['jenis'], $pg['opsi'], $pg['kunci']);
cek('PG: kunci C -> indeks 2', $siap['jawaban'], 2);

// Pilihan ganda kompleks
$pgk = $h['baris'][1];
cek('PGK: jumlah pilihan', count($pgk['opsi']), 5);
$siap = soal_baris_siap($pgk['jenis'], $pgk['opsi'], $pgk['kunci']);
cek('PGK: kunci A,B,E -> [0,1,4]', $siap['jawaban'], [0, 1, 4]);
benar('PGK: pilihan bertanda [ ] tetap terbaca (tanpa "[ ]" pada teks)',
    !str_contains(implode(' ', $pgk['opsi']), '[ ]'));

// Benar salah
$bs = $h['baris'][2];
cek('BS: satu pernyataan', count($bs['opsi']), 1);
benar('BS: pernyataan tersimpan pada opsi', str_contains($bs['opsi'][0], 'penggunaan bahasa yang tidak baku'));
benar('BS: pertanyaan memakai kalimat pembuka', str_contains($bs['pertanyaan'], 'Benar atau Salah'));
$siap = soal_baris_siap($bs['jenis'], $bs['opsi'], $bs['kunci'], !empty($bs['pilihan_bs']));
cek('BS: Kunci A (pilihan A=Benar) -> ["B"]', $siap['jawaban'], ['B']);

// Menjodohkan
$jd = $h['baris'][3];
cek('JODOH: jumlah pasangan', count($jd['opsi']), 4);
cek('JODOH: pernyataan kiri pertama', $jd['opsi'][0], 'Salam Pembuka');
cek('JODOH: pasangan kanan pertama (penomoran "1." dibuang)', $jd['kunci'][0],
    'Halo Sahabatku, apa kabarmu di sana?');
cek('JODOH: pasangan kanan terakhir', $jd['kunci'][3], 'Sahabat baikmu,');
$siap = soal_baris_siap($jd['jenis'], $jd['opsi'], $jd['kunci']);
benar('JODOH: kunci tersusun sejajar', $siap['ok'] && count($siap['jawaban']) === 4);

// Essai singkat & panjang
$es1 = $h['baris'][4];
cek('ESSAI SINGKAT: jadi jenis uraian', $es1['jenis'], 'uraian');
benar('ESSAI SINGKAT: kata kunci diambil otomatis (' . implode(', ', $es1['kunci']) . ')',
    in_array('keakraban', $es1['kunci'], true) && count($es1['kunci']) <= SOAL_KUNCI_MAKS_KATA);
benar('ESSAI SINGKAT: jawaban contoh disimpan sebagai pembahasan',
    str_contains($es1['pembahasan'], 'Kunci (jawaban contoh): Karena kata ganti orang'));
$es2 = $h['baris'][5];
cek('ESSAI PANJANG: jadi jenis uraian', $es2['jenis'], 'uraian');
benar('ESSAI PANJANG: pertanyaan panjang utuh',
    str_contains($es2['pertanyaan'], '(3) Harapan untuk bertemu') && str_contains($es2['pertanyaan'], 'minimal 3 paragraf'));

echo "\n== 2. Variasi penulisan guru ==\n";
// Tanpa nomor (daftar bernomor otomatis di Word): soal baru setelah Kunci.
$h2 = parse("Template Soal Pilihan Ganda\nSiapa presiden pertama?\nA. Soekarno\nB. Soeharto\nKunci: A\n"
    . "Ibu kota Indonesia?\nA. Bandung\nB. Jakarta\nKunci: B");
cek('tanpa nomor: dua soal terbaca', count($h2['baris']), 2);
cek('tanpa nomor: kunci soal kedua', soal_baris_siap('pg', $h2['baris'][1]['opsi'], $h2['baris'][1]['kunci'])['jawaban'], 1);

// Kunci berakhiran titik & pilihan bertanda [x]
$h3 = parse("Template Soal Pilihan Ganda\n1. Pilih satu.\n[ ] A. satu\n[ ] B. dua\nKunci: B.");
cek('kunci "B." terbaca', soal_baris_siap('pg', $h3['baris'][0]['opsi'], $h3['baris'][0]['kunci'])['jawaban'], 1);

// Benar/Salah memakai kata
$h4 = parse("Template Soal Benar Salah\n1. Air membeku pada 0 derajat.\nKunci: Benar\n"
    . "2. Matahari terbit di barat.\nKunci: Salah");
cek('BS kata: soal pertama', soal_baris_siap('bs', $h4['baris'][0]['opsi'], $h4['baris'][0]['kunci'])['jawaban'], ['B']);
cek('BS kata: soal kedua', soal_baris_siap('bs', $h4['baris'][1]['opsi'], $h4['baris'][1]['kunci'])['jawaban'], ['S']);

// Benar/Salah banyak pernyataan dalam satu soal
$h5 = parse("Template Soal Benar Salah\n1. Nyatakan pernyataan berikut.\nA. 2 + 2 = 4\nB. 5 x 5 = 20\nKunci: Benar, Salah");
cek('BS banyak pernyataan: dua pernyataan', count($h5['baris'][0]['opsi']), 2);
cek('BS banyak pernyataan: kunci B,S', soal_baris_siap('bs', $h5['baris'][0]['opsi'], $h5['baris'][0]['kunci'])['jawaban'], ['B', 'S']);

// Menjodohkan tanpa huruf
$h6 = parse("Template Soal Menjodohkan\n1. Jodohkan!\nSalam Pembuka | Halo\nPenutup | Sampai jumpa");
cek('JODOH tanpa huruf: dua pasangan', count($h6['baris'][0]['opsi']), 2);
cek('JODOH tanpa huruf: kunci kedua', $h6['baris'][0]['kunci'][1], 'Sampai jumpa');

// Essai dengan kata kunci eksplisit + skor
$h7 = parse("Template Soal Essai Panjang\n1. Jelaskan fotosintesis.\nSkor: 5\nKunci: fotosintesis|klorofil|cahaya matahari");
cek('ESSAI kata kunci eksplisit: tiga kunci', count($h7['baris'][0]['kunci']), 3);
cek('ESSAI kata kunci eksplisit: isi kunci', $h7['baris'][0]['kunci'], ['fotosintesis', 'klorofil', 'cahaya matahari']);
cek('ESSAI tanpa pembahasan otomatis', $h7['baris'][0]['pembahasan'], '');
cek('skor "Skor: 5" dipakai', $h7['baris'][0]['skor'], 5.0);

// Pertanyaan beberapa baris + jawaban contoh beberapa paragraf
$h8 = parse("Template Soal Essai Singkat\n1. Perhatikan bacaan berikut.\nBacaan itu menceritakan tentang\nseorang siswa.\n"
    . "Kunci: Jawaban pertama.\nJawaban tambahan yang masih satu kesatuan.");
cek('pertanyaan multi-baris digabung', $h8['baris'][0]['pertanyaan'],
    'Perhatikan bacaan berikut. Bacaan itu menceritakan tentang seorang siswa.');
benar('kunci essai boleh lanjut beberapa baris',
    str_contains((string) $h8['baris'][0]['kunci'][0], 'jawaban') || str_contains($h8['baris'][0]['pembahasan'], 'Jawaban tambahan'));

// Judul bagian tak dikenal -> isinya diabaikan (tidak ditebak menjadi pilihan ganda)
$h9 = parse("Template Soal Wacana\n1. Soal ini tidak boleh masuk.\nA. satu\nB. dua\nKunci: A\n"
    . "Template Soal Pilihan Ganda\n1. Soal ini harus masuk.\nA. satu\nB. dua\nKunci: B");
cek('judul tak dikenal diabaikan', count($h9['baris']), 1);
cek('soal setelah judul dikenal terbaca', $h9['baris'][0]['pertanyaan'], 'Soal ini harus masuk.');

// Soal tidak lengkap dilaporkan, soal lain tetap masuk
$h10 = parse("Template Soal Pilihan Ganda\n1. Tanpa kunci.\nA. satu\nB. dua\n2. Hanya satu pilihan.\nA. satu\nKunci: A\n"
    . "3. Lengkap.\nA. satu\nB. dua\nKunci: A");
cek('dua soal tidak lengkap dilaporkan', count($h10['salah']), 2);
cek('soal yang lengkap tetap masuk', count($h10['baris']), 1);
benar('pesan galat menyebut kunci', str_contains(strtolower($h10['salah'][0]), 'kunci'));

// Baris pemisah "=====" & judul dokumen diabaikan
$h11 = parse("TEMPLATE SOAL\n=================\nTemplate Soal Pilihan Ganda\n=====\n1. Pilih.\nA. a\nB. b\nKunci: A\n=====");
cek('garis pemisah diabaikan', count($h11['baris']), 1);

// "Kunci : C" (spasi sebelum titik dua) & huruf kecil
$h12 = parse("Template Soal Pilihan Ganda\n1. Pilih.\nA. a\nB. b\nC. c\nKunci : c");
cek('kunci huruf kecil + spasi titik dua', soal_baris_siap('pg', $h12['baris'][0]['opsi'], $h12['baris'][0]['kunci'])['jawaban'], 2);

// Soal isian singkat (bagian opsional)
$h13 = parse("Template Soal Isian Singkat\n1. Hasil 12 x 8 = ...\nKunci: 96|sembilan puluh enam");
cek('isian: jenis terbaca', $h13['baris'][0]['jenis'], 'isian');
cek('isian: dua alternatif jawaban', count($h13['baris'][0]['kunci']), 2);

echo "\n== 3. Impor jalur lama (tabel) tetap bekerja ==\n";
$tabel = [
    ['Jenis', 'Pertanyaan', 'Opsi A', 'Opsi B', 'Opsi C', 'Opsi D', 'Opsi E', 'Jawaban', 'Skor', 'Pembahasan'],
    ['Menjodohkan', 'Jodohkan!', 'Kiri satu', 'Kiri dua', '', '', '', 'Kanan satu|Kanan dua', '2', ''],
    ['Pilihan Ganda', 'Dua tambah dua?', '3', '4', '', '', '', 'B', '1', 'Empat.'],
];
$lama = soal_dari_tabel($tabel);
cek('tabel lama: dua soal', count($lama), 2);
cek('tabel lama: jenis pertama', $lama[0]['jenis'], 'jodoh');
cek('tabel lama: kunci jodoh', soal_baris_siap('jodoh', $lama[0]['opsi'], $lama[0]['kunci'])['jawaban'],
    ['Kanan satu', 'Kanan dua']);
cek('tabel lama: kunci pilihan ganda', soal_baris_siap('pg', $lama[1]['opsi'], $lama[1]['kunci'])['jawaban'], 1);
$kolom = soal_dari_kolom([['Pilihan Ganda', 'Satu tambah satu?', '1', '2', '', '', '', 'B', '1', '']],
    $tabel[0]);
cek('jalur Excel/CSV (judul terpisah): satu soal', count($kolom), 1);
cek('jalur Excel/CSV: kunci', soal_baris_siap('pg', $kolom[0]['opsi'], $kolom[0]['kunci'])['jawaban'], 1);

echo "\n== 4. Kunci Benar/Salah pada jalur lama (b = benar) ==\n";
cek('kunci_bs_list("B,S") jalur lama', kunci_bs_list('B,S'), ['B', 'S']);
cek('kunci_bs_list("A") jalur template pilihan (A=Benar)', kunci_bs_list('A', true), ['B']);
cek('kunci_bs_list("B") jalur template pilihan (B=Salah)', kunci_bs_list('B', true), ['S']);
cek('kunci_bs_list("Benar,Salah")', kunci_bs_list('Benar, Salah'), ['B', 'S']);

echo "\n===== HASIL: $lulus lulus, $gagal gagal =====\n";
exit($gagal === 0 ? 0 : 1);
