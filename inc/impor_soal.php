<?php
/**
 * PEMBACAAN TEMPLATE SOAL BENTUK TEKS SEDERHANA + persiapan satu baris soal.
 *
 * Permintaan pemilik: *"Template soal yang ada saat ini masih agak sulit. Buat template soal seperti
 * yang saya upload agar mempermudah guru dalam mengisi template soal."* Template lama memakai TABEL
 * berkolom (Jenis | Pertanyaan | Opsi A..E | Jawaban | Skor | Pembahasan) sehingga guru harus
 * menebak isi kolom "Jawaban" untuk tiap jenis soal. Template baru hanya berupa TULISAN biasa:
 *
 *     Template Soal Pilihan Ganda
 *     1. Pertanyaan ...
 *     A. pilihan 1
 *     B. pilihan 2
 *     Kunci: B
 *
 *     Template Soal Benar Salah
 *     2. Pernyataan ...
 *     A. Benar
 *     B. Salah
 *     Kunci: A
 *
 *     Template Soal Menjodohkan
 *     3. Pasangkanlah ...
 *     A. kiri | kanan
 *     B. kiri | kanan
 *
 *     Template Soal Essai Singkat
 *     4. Pertanyaan ...
 *     Kunci: jawaban contoh
 *
 * Modul ini:
 *  1. `soal_teks_parse()`  — mengubah tulisan itu menjadi daftar soal siap simpan.
 *  2. `soal_baris_siap()`  — memeriksa satu baris soal & menyiapkan kunci (dipakai BERSAMA oleh
 *                            impor teks, impor tabel Word lama, dan impor Excel/CSV).
 *  3. `soal_template_blok()` / `soal_template_teks()` — isi template contoh (dipakai
 *                            `template_soal_word.php` dan pratinjau pada halaman impor).
 *
 * Template LAMA (tabel) tetap dapat diimpor — lihat `soal_dari_tabel()`.
 */
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/** Kalimat pembuka untuk soal Benar/Salah yang pernyataannya ditulis pada baris bernomor. */
const SOAL_BS_PREAMBEL = 'Tentukan Benar atau Salah untuk pernyataan berikut.';

/** Kalimat pembuka untuk soal menjodohkan yang tidak menuliskan perintahnya. */
const SOAL_JODOH_PREAMBEL = 'Pasangkan setiap pernyataan dengan pasangan yang tepat.';

/** Jumlah kata kunci otomatis maksimal untuk soal essai (uraian). */
const SOAL_KUNCI_MAKS_KATA = 8;

// =====================================================================================
// 1. PENGENALAN JENIS SOAL
// =====================================================================================

/**
 * Jenis soal dari JUDUL BAGIAN template ("Pilihan Ganda Kompleks", "Benar Salah", …).
 * Mengembalikan null bila judulnya tidak dikenali (bagian itu diabaikan — JANGAN menebak jenis,
 * karena salah tebak akan menyimpan soal dengan bentuk kunci yang salah).
 */
function soal_jenis_dari_judul(string $judul): ?string
{
    $k = preg_replace('/[^a-z]/', '', bawah(trim($judul))) ?? '';
    if ($k === '') {
        return null;
    }
    // Urutan penting: "kompleks" diperiksa SEBELUM "pilihan ganda".
    if (str_contains($k, 'kompleks') || str_contains($k, 'banyakjawaban') || str_contains($k, 'lebihdarisatu')
        || str_contains($k, 'jawabanlebih')) {
        return 'pgk';
    }
    if (str_contains($k, 'isian') || str_contains($k, 'jawabansingkat') || str_contains($k, 'shortanswer')) {
        return 'isian';
    }
    if (str_contains($k, 'jodoh') || str_contains($k, 'pasang') || str_contains($k, 'matching')) {
        return 'jodoh';
    }
    if (str_contains($k, 'benar') || str_contains($k, 'truefalse') || $k === 'bs') {
        return 'bs';
    }
    if (str_contains($k, 'essai') || str_contains($k, 'esai') || str_contains($k, 'essay')
        || str_contains($k, 'uraian')) {
        return 'uraian';
    }
    if (str_contains($k, 'pilihanganda') || str_contains($k, 'multiplechoice')) {
        return 'pg';
    }
    return null;
}

/** Apakah baris ini judul bagian template? -> jenis soal atau null. */
function soal_judul_bagian(string $baris): array
{
    $l = trim($baris);
    if ($l === '') {
        return [false, null];
    }
    // Bentuk utama: "Template Soal <jenis>"
    if (preg_match('/^template\s+soal\b(.*)$/i', $l, $m) === 1) {
        return [true, soal_jenis_dari_judul((string) $m[1])];
    }
    // Bentuk lain yang sering dipakai guru: "Soal Pilihan Ganda", "Bagian Soal Benar Salah",
    // "SOAL ESSAI PANJANG". Sengaja ANCHOR ketat (harus berakhir tepat pada nama jenis) supaya
    // kalimat soal yang kebetulan dimulai "Soal …" tidak dianggap judul bagian.
    $pola = '/^(?:bagian\s+)?(?:jenis\s+)?soal\s*[-–:.]?\s*'
        . '(pilihan\s+ganda(?:\s+kompleks)?|benar\s*[-\/ ]?\s*salah|menjodohkan|mencocokkan'
        . '|isian(?:\s+singkat)?|jawaban\s+singkat|essai\s+(?:singkat|panjang)|esai\s+(?:singkat|panjang)'
        . '|uraian)\s*$/i';
    if (preg_match($pola, $l, $m) === 1) {
        return [true, soal_jenis_dari_judul((string) $m[1])];
    }
    return [false, null];
}

/** Baris pilihan jawaban ("A. …", "[ ] B. …", "A) …", "A: …") -> [huruf, teks] atau null. */
function soal_baris_opsi(string $baris): ?array
{
    $l = trim($baris);
    if ($l === '') {
        return null;
    }
    if (preg_match('/^\[\s*[xX]?\s*\]\s*([A-Ja-j])\s*[.)\]:]\s*(.*)$/u', $l, $m) === 1) {
        return ['huruf' => strtoupper($m[1]), 'teks' => trim((string) $m[2])];
    }
    if (preg_match('/^([A-Ja-j])\s*[.)\]:]\s+(.+)$/u', $l, $m) === 1) {
        return ['huruf' => strtoupper($m[1]), 'teks' => trim((string) $m[2])];
    }
    return null;
}

/** Apakah teks pilihan hanya penanda "Benar"/"Salah" (pilihan jawaban soal Benar-Salah)? */
function soal_opsi_benar_salah(string $teks): bool
{
    $t = preg_replace('/[^a-z]/', '', bawah(trim($teks))) ?? '';
    return in_array($t, ['benar', 'salah', 'true', 'false'], true);
}

// =====================================================================================
// 2. PEMBACA TEMPLATE TEKS
// =====================================================================================

/**
 * Ubah tulisan template menjadi daftar soal.
 *
 * @return array{baris: array<int,array>, salah: array<int,string>}
 *   `baris` = soal ternormalisasi: jenis, pertanyaan, opsi[], kunci (string|array), skor, pembahasan,
 *   pilihan_bs (bool: kunci memakai huruf pilihan A=Benar, B=Salah).
 */
function soal_teks_parse(string $teks): array
{
    $teks = str_replace(["\r\n", "\r", "\xC2\xA0", "\t"], ["\n", "\n", ' ', '    '], $teks);
    $hasil = [];
    $salah = [];
    $jenis = null;      // jenis bagian yang sedang dibaca (null = di luar bagian -> diabaikan)
    $cur = null;        // soal yang sedang dikumpulkan
    $menunggu = false;  // true setelah baris "Kunci:" (baris berikutnya = soal baru)
    $nomor = 0;

    $kosong = function (): array {
        return ['q' => '', 'opsi' => [], 'pasangan' => [], 'kunci' => '', 'skor' => null,
            'pilihan_bs' => false, 'terakhir_opsi' => false];
    };

    $flush = function () use (&$cur, &$jenis, &$hasil, &$salah, &$nomor): void {
        if ($cur === null) {
            return;
        }
        $c = $cur;
        $cur = null;
        $adaIsi = trim((string) $c['q']) !== '' || $c['opsi'] || $c['pasangan'];
        if (!$adaIsi) {
            return;
        }
        $nomor++;
        [$row, $pesan] = soal_teks_baris($jenis, $c);
        if ($row !== null) {
            $row['nomor'] = $nomor;
            $hasil[] = $row;
        } elseif ($pesan !== '') {
            $salah[] = 'Soal nomor ' . $nomor . ': ' . $pesan . ' — dilewati.';
        }
    };

    foreach (explode("\n", $teks) as $mentah) {
        $l = trim((string) $mentah);

        // Baris kosong = pemisah paragraf di dalam pertanyaan (penting untuk soal essai).
        if ($l === '') {
            if ($cur !== null && trim((string) $cur['q']) !== '' && !str_ends_with((string) $cur['q'], "\n")) {
                $cur['q'] = rtrim((string) $cur['q']) . "\n";
            }
            continue;
        }
        // Garis pemisah ("=====", "-----", "*****") diabaikan.
        if (preg_match('/^[=\-–—_*~.]{3,}$/u', $l) === 1) {
            continue;
        }

        // a. Judul bagian.
        [$judulYa, $judulJenis] = soal_judul_bagian($l);
        if ($judulYa) {
            $flush();
            $jenis = $judulJenis;      // null bila judul tidak dikenali -> isi bagian diabaikan
            $menunggu = true;
            continue;
        }

        // b. Semua yang berada di luar bagian template (judul dokumen, petunjuk pengisian) diabaikan.
        if ($jenis === null) {
            continue;
        }

        // c. Baris kunci.
        if (preg_match('/^(?:kunci(?:\s+jawaban)?|jawaban)\s*[:=]\s*(.*)$/i', $l, $m) === 1) {
            if ($cur === null) {
                $cur = $kosong();
            }
            $k = trim((string) $m[1]);
            $cur['kunci'] = trim((string) $cur['kunci']) === '' ? $k : (trim((string) $cur['kunci']) . ' ' . $k);
            $menunggu = true;
            continue;
        }

        // d. Baris skor (opsional): "Skor: 2".
        if (preg_match('/^skor\s*[:=]\s*([0-9]+(?:[.,][0-9]+)?)/i', $l, $m) === 1) {
            if ($cur !== null) {
                $cur['skor'] = (float) str_replace(',', '.', (string) $m[1]);
            }
            continue;
        }

        // e. Soal menjodohkan: baris pasangan "A. kiri | kanan" (huruf boleh tidak ada).
        if ($jenis === 'jodoh' && str_contains($l, '|')) {
            $bagian = explode('|', $l, 2);
            $kiri = trim((string) $bagian[0]);
            $kanan = trim((string) ($bagian[1] ?? ''));
            $opsiKiri = soal_baris_opsi($kiri);
            if ($opsiKiri !== null) {
                $kiri = $opsiKiri['teks'];
            }
            // Buang penomoran pada sisi kanan ("1. Halo …") — aplikasi memberi hurufnya sendiri.
            $kanan = preg_replace('/^\d{1,3}\s*[.)\]:]\s*/u', '', $kanan) ?? $kanan;
            $kanan = trim($kanan);
            $opsiKanan = soal_baris_opsi($kanan);
            if ($opsiKanan !== null) {
                $kanan = $opsiKanan['teks'];
            }
            if ($kiri !== '' && $kanan !== '') {
                if ($cur === null) {
                    $cur = $kosong();
                }
                $cur['pasangan'][] = [$kiri, $kanan];
                $menunggu = false;
                continue;
            }
        }

        // f. Soal bernomor = awal soal baru.
        if (preg_match('/^(\d{1,3})\s*[.)\]:]\s*(.+)$/u', $l, $m) === 1) {
            $flush();
            $cur = $kosong();
            $cur['q'] = trim((string) $m[2]);
            $menunggu = false;
            continue;
        }

        // g. Baris pilihan jawaban.
        $opsi = soal_baris_opsi($l);
        if ($opsi !== null && $jenis !== 'jodoh') {
            if ($cur === null) {
                $cur = $kosong();
            }
            if ($jenis === 'bs' && soal_opsi_benar_salah($opsi['teks'])) {
                // "A. Benar / B. Salah" = pilihan jawaban, bukan pernyataan.
                $cur['pilihan_bs'] = true;
                $menunggu = false;
                continue;
            }
            $cur['opsi'][] = $opsi['teks'];
            $cur['terakhir_opsi'] = true;
            $menunggu = false;
            continue;
        }

        // h. Baris bebas: soal baru (setelah kunci) ATAU lanjutan.
        if ($cur === null) {
            $cur = $kosong();
            $cur['q'] = $l;
            $menunggu = false;
            continue;
        }
        if ($menunggu) {
            if (in_array($jenis, ['uraian', 'isian'], true) && trim((string) $cur['kunci']) !== ''
                && !soal_mirip_pertanyaan($l)) {
                // Jawaban contoh soal essai boleh terdiri atas beberapa paragraf.
                $cur['kunci'] = trim((string) $cur['kunci']) . ' ' . $l;
                continue;
            }
            $flush();
            $cur = $kosong();
            $cur['q'] = $l;
            $menunggu = false;
            continue;
        }
        // Lanjutan pertanyaan/pilihan sebelumnya.
        if ($cur['terakhir_opsi'] && $cur['opsi']) {
            $idx = count($cur['opsi']) - 1;
            $cur['opsi'][$idx] = trim((string) $cur['opsi'][$idx] . ' ' . $l);
        } else {
            $cur['q'] = trim((string) $cur['q'] . ' ' . $l);
        }
    }
    $flush();

    return ['baris' => $hasil, 'salah' => $salah];
}

/**
 * Susun satu soal dari hasil pembacaan teks.
 * @return array{0: ?array, 1: string} [soal, pesan galat]
 */
function soal_teks_baris(?string $jenis, array $c): array
{
    $q = trim((string) ($c['q'] ?? ''));
    $kunci = trim((string) ($c['kunci'] ?? ''));
    $skor = $c['skor'] ?? null;
    $opsi = array_values(array_filter(array_map('trim', (array) ($c['opsi'] ?? [])), fn($x) => $x !== ''));

    if ($jenis === 'jodoh') {
        $pasangan = (array) ($c['pasangan'] ?? []);
        if (count($pasangan) < 2) {
            return [null, 'pasangan belum lengkap (tulis "A. pernyataan | pasangan", minimal dua baris)'];
        }
        $kiri = [];
        $kanan = [];
        foreach ($pasangan as $p) {
            $kiri[] = (string) ($p[0] ?? '');
            $kanan[] = (string) ($p[1] ?? '');
        }
        return [[
            'jenis' => 'jodoh', 'pertanyaan' => $q !== '' ? $q : SOAL_JODOH_PREAMBEL,
            'opsi' => $kiri, 'kunci' => $kanan, 'skor' => $skor, 'pembahasan' => '', 'pilihan_bs' => false,
        ], ''];
    }

    if ($jenis === 'bs') {
        $pernyataan = $opsi;
        $pembuka = $q;
        if (!$pernyataan) {
            if ($q === '') {
                return [null, 'pernyataan Benar/Salah kosong'];
            }
            $pernyataan = [$q];
            $pembuka = SOAL_BS_PREAMBEL;
        }
        if ($pembuka === '') {
            $pembuka = SOAL_BS_PREAMBEL;
        }
        if ($kunci === '') {
            return [null, 'kunci belum diisi (tulis "Kunci: Benar" atau "Kunci: Salah")'];
        }
        return [[
            'jenis' => 'bs', 'pertanyaan' => $pembuka, 'opsi' => $pernyataan, 'kunci' => $kunci,
            'skor' => $skor, 'pembahasan' => '', 'pilihan_bs' => !empty($c['pilihan_bs']),
        ], ''];
    }

    if ($q === '') {
        return [null, 'pertanyaan kosong'];
    }
    if (in_array($jenis, ['uraian', 'isian'], true)) {
        if ($kunci === '') {
            return [null, 'kunci jawaban belum diisi'];
        }
        $pembahasan = '';
        if (str_contains($kunci, '|')) {
            // Guru menulis sendiri kata kuncinya: "fotosintesis|klorofil|cahaya matahari".
            $kata = array_values(array_filter(array_map('trim', explode('|', $kunci)), fn($x) => $x !== ''));
        } elseif ($jenis === 'isian') {
            // Isian dinilai PERSIS: jawaban contoh dipakai apa adanya.
            $kata = array_values(array_filter(array_map('trim', explode("\n", $kunci)), fn($x) => $x !== ''));
            $kata = $kata ? [$kunci] : [];
        } else {
            // Essai: kata kunci diambil otomatis dari jawaban contoh, jawaban contoh disimpan
            // sebagai pembahasan supaya guru/siswa dapat melihatnya.
            $kata = soal_kata_kunci($kunci);
            $pembahasan = 'Kunci (jawaban contoh): ' . $kunci;
        }
        if (!$kata) {
            return [null, 'kunci penilaian kosong'];
        }
        return [[
            'jenis' => $jenis, 'pertanyaan' => $q, 'opsi' => [], 'kunci' => $kata,
            'skor' => $skor, 'pembahasan' => $pembahasan, 'pilihan_bs' => false,
        ], ''];
    }

    if (count($opsi) < 2) {
        return [null, 'pilihan jawaban kurang dari dua (tulis A., B., …)'];
    }
    if ($kunci === '') {
        return [null, 'kunci belum diisi (tulis "Kunci: C")'];
    }
    return [[
        'jenis' => $jenis, 'pertanyaan' => $q, 'opsi' => $opsi, 'kunci' => $kunci,
        'skor' => $skor, 'pembahasan' => '', 'pilihan_bs' => false,
    ], ''];
}

/**
 * Kata kunci penilaian soal essai dari jawaban contoh (kata penting, tanpa kata umum).
 * Minimal satu kata; bila tidak ada kata yang layak, seluruh jawaban contoh dipakai.
 */
function soal_kata_kunci(string $teks): array
{
    $bersih = preg_replace('/[^A-Za-z0-9À-ÿ ]+/u', ' ', $teks) ?? $teks;
    $kata = preg_split('/\s+/', bawah($bersih)) ?: [];
    $umum = ['yang', 'dan', 'atau', 'untuk', 'dengan', 'dari', 'pada', 'dalam', 'adalah', 'akan',
        'tidak', 'juga', 'karena', 'agar', 'supaya', 'oleh', 'ini', 'itu', 'saya', 'anda', 'kamu',
        'aku', 'mereka', 'kita', 'bisa', 'dapat', 'harus', 'lebih', 'sangat', 'secara', 'sebagai',
        'antara', 'setelah', 'sebelum', 'sesuai', 'serta', 'maupun', 'para', 'bagi', 'saat', 'ketika',
        'jika', 'bila', 'maka', 'namun', 'tetapi', 'perlu', 'sebuah', 'suatu', 'cara', 'telah',
        'sudah', 'belum', 'masih', 'banyak', 'semua', 'setiap', 'terhadap', 'kepada', 'dalam',
        'melalui', 'merupakan', 'yaitu', 'yakni', 'seperti', 'hanya', 'tetap', 'pula', 'pun',
        'adanya', 'dilakukan', 'membuat', 'buat', 'oleh', 'atas', 'bagian', 'secara'];
    $out = [];
    foreach ($kata as $k) {
        $k = trim((string) $k);
        if ($k === '' || strlen($k) < 4 || in_array($k, $umum, true) || in_array($k, $out, true)) {
            continue;
        }
        $out[] = $k;
        if (count($out) >= SOAL_KUNCI_MAKS_KATA) {
            break;
        }
    }
    if (!$out) {
        $out[] = teks($teks, 120);
    }
    return $out;
}

/**
 * Apakah baris ini kelihatan awal pertanyaan baru? Dipakai pada bagian ESSAI: jawaban contoh boleh
 * terdiri atas beberapa paragraf, jadi baris lanjutan hanya digabung bila BUKAN pertanyaan baru.
 */
function soal_mirip_pertanyaan(string $baris): bool
{
    $l = trim($baris);
    if ($l === '') {
        return false;
    }
    if (str_ends_with($l, '?')) {
        return true;
    }
    $awal = preg_split('/\s+/', bawah($l)) ?: [];
    $kata = ['jelaskan', 'sebutkan', 'mengapa', 'bagaimana', 'apa', 'apakah', 'tuliskan', 'uraikan',
        'bandingkan', 'buatlah', 'bayangkan', 'tentukan', 'berikan', 'lengkapi', 'pasangkan',
        'perhatikan', 'hitunglah', 'simpulkan', 'sebutkanlah', 'uraikanlah', 'coba', 'tulis',
        'susunlah', 'buat', 'uraikan', 'analisislah', 'identifikasilah', 'sebutkan'];
    return in_array((string) ($awal[0] ?? ''), $kata, true);
}

// =====================================================================================
// 3. PERSIAPAN & PEMERIKSAAN SATU BARIS SOAL (dipakai semua jalur impor)
// =====================================================================================

/** Ubah tulisan kunci menjadi daftar indeks opsi (A->0, B->1, "1"->0, …). */
function kunci_ke_indeks(string $teks, int $jumlahOpsi): array
{
    $out = [];
    foreach (preg_split('/[^A-Za-z0-9]+/', trim($teks)) ?: [] as $b) {
        $b = trim((string) $b);
        if ($b === '') {
            continue;
        }
        if (preg_match('/^[A-Za-z]$/', $b) === 1) {
            $i = ord(strtoupper($b)) - 65;
        } elseif (preg_match('/^\d+$/', $b) === 1) {
            $i = ((int) $b) - 1;
        } else {
            continue;
        }
        if ($i >= 0 && $i < $jumlahOpsi) {
            $out[] = $i;
        }
    }
    return array_values(array_unique($out));
}

/**
 * Baca kunci Benar/Salah.
 * $hurufPilihan = true bila guru memakai huruf pilihan template ("A. Benar / B. Salah") sehingga
 * huruf A berarti BENAR dan B berarti SALAH. Pada jalur Excel/tabel lama huruf B berarti BENAR
 * (b = benar, s = salah) — perilaku lama dipertahankan.
 */
function kunci_bs_list(string $teks, bool $hurufPilihan = false): array
{
    $out = [];
    foreach (preg_split('/[,;|\/\s]+/', trim($teks)) ?: [] as $b) {
        $b = bawah(trim((string) $b));
        $b = preg_replace('/[^a-z]/', '', $b) ?? '';
        if ($b === '') {
            continue;
        }
        if (in_array($b, ['benar', 'true', 'ya', 'y', 't'], true)) {
            $out[] = 'B';
            continue;
        }
        if (in_array($b, ['salah', 'false', 'tidak', 's'], true)) {
            $out[] = 'S';
            continue;
        }
        if ($b === 'bs' || $b === 'sb') {
            $out[] = $b[0] === 'b' ? ($hurufPilihan ? 'S' : 'B') : 'S';
            $out[] = $b[1] === 'b' ? ($hurufPilihan ? 'S' : 'B') : 'S';
            continue;
        }
        if ($b === 'b') {
            $out[] = $hurufPilihan ? 'S' : 'B';
            continue;
        }
        if ($b === 'a') {
            $out[] = 'B';
            continue;
        }
    }
    return $out;
}

/**
 * Periksa satu baris soal dan siapkan kuncinya.
 *
 * @param  string       $jenis        kode jenis (pg/pgk/bs/jodoh/isian/uraian)
 * @param  array        $opsi         daftar pilihan / pernyataan / pernyataan kiri
 * @param  string|array $jwb          kunci mentah (tulisan) ATAU sudah berupa array
 * @param  bool         $hurufPilihan kunci Benar/Salah memakai huruf pilihan (A=Benar, B=Salah)
 * @return array{ok:bool, pesan:string, jawaban:mixed}
 */
function soal_baris_siap(string $jenis, array $opsi, $jwb, bool $hurufPilihan = false): array
{
    $opsi = array_values(array_filter(array_map(fn($o) => trim((string) $o), $opsi), fn($o) => $o !== ''));
    $gagal = fn(string $pesan): array => ['ok' => false, 'pesan' => $pesan, 'jawaban' => null];

    if ($jenis === 'pg' || $jenis === 'pgk') {
        if (count($opsi) < 2) {
            return $gagal('pilihan jawaban kurang dari dua');
        }
        if (is_array($jwb)) {
            $kk = array_map('intval', array_values($jwb));
        } else {
            $kk = kunci_ke_indeks((string) $jwb, count($opsi));
        }
        $kk = array_values(array_unique(array_filter($kk, fn($i) => $i >= 0 && $i < count($opsi))));
        if (!$kk) {
            return $gagal('kunci jawaban (A/B/C/…) tidak dikenali' . (string) (is_array($jwb) ? '' : ''));
        }
        if ($jenis === 'pg') {
            if (count($kk) > 1) {
                return $gagal('soal pilihan ganda hanya boleh satu kunci');
            }
            return ['ok' => true, 'pesan' => '', 'jawaban' => $kk[0]];
        }
        sort($kk);
        return ['ok' => true, 'pesan' => '', 'jawaban' => $kk];
    }

    if ($jenis === 'bs') {
        if (!$opsi) {
            return $gagal('pernyataan Benar/Salah kosong');
        }
        $bs = is_array($jwb)
            ? array_values(array_filter(array_map(function ($x) {
                $x = strtoupper(trim((string) $x));
                return in_array($x, ['B', 'S'], true) ? $x : '';
            }, $jwb), fn($x) => $x !== ''))
            : kunci_bs_list((string) $jwb, $hurufPilihan);
        if (count($bs) !== count($opsi)) {
            return $gagal('jumlah kunci Benar/Salah (' . count($bs) . ') tidak sama dengan jumlah pernyataan ('
                . count($opsi) . ')');
        }
        return ['ok' => true, 'pesan' => '', 'jawaban' => $bs];
    }

    if ($jenis === 'jodoh') {
        if (count($opsi) < 2) {
            return $gagal('pasangan menjodohkan kurang dari dua');
        }
        $kanan = is_array($jwb)
            ? array_values(array_map('strval', $jwb))
            : array_values(array_filter(array_map('trim', explode('|', (string) $jwb)), fn($x) => $x !== ''));
        if (count($kanan) !== count($opsi)) {
            return $gagal('jumlah pasangan kanan (' . count($kanan) . ') tidak sama dengan jumlah pernyataan kiri ('
                . count($opsi) . ')');
        }
        return ['ok' => true, 'pesan' => '', 'jawaban' => array_map(fn($x) => teks($x, 300), $kanan)];
    }

    // isian / uraian
    $kunci = is_array($jwb)
        ? array_values(array_filter(array_map(fn($x) => trim((string) $x), $jwb), fn($x) => $x !== ''))
        : array_values(array_filter(array_map('trim', explode('|', (string) $jwb)), fn($x) => $x !== ''));
    if (!$kunci) {
        return $gagal('kunci penilaian kosong');
    }
    return ['ok' => true, 'pesan' => '', 'jawaban' => array_map(fn($x) => teks((string) $x, 200), $kunci)];
}

// =====================================================================================
// 4. TEMPLATE LAMA (TABEL WORD & EXCEL/CSV) -> bentuk soal ternormalisasi
// =====================================================================================

/**
 * Peta kolom dari baris judul -> ['peta' => [...], 'opsi' => [indeks…]].
 * Tanpa judul yang dikenali, dipakai susunan baku template LAMA: Jenis | Pertanyaan | Opsi A..E |
 * Jawaban | Skor | Pembahasan.
 */
function soal_peta_kolom(array $judul): array
{
    $peta = [];
    $opsiKolom = [];
    foreach ($judul as $i => $j) {
        $k = kolom_kunci((string) $j);
        if ($k === '') {
            continue;
        }
        if ($k === 'jenis' || $k === 'jenissoal' || $k === 'tipe') {
            $peta['jenis'] = (int) $i;
        } elseif ($k === 'pertanyaan' || $k === 'soal' || $k === 'butirsoal') {
            $peta['pertanyaan'] = (int) $i;
        } elseif ($k === 'jawaban' || $k === 'kunci' || $k === 'kuncijawaban') {
            $peta['jawaban'] = (int) $i;
        } elseif ($k === 'skor' || $k === 'bobot' || $k === 'poin') {
            $peta['skor'] = (int) $i;
        } elseif ($k === 'pembahasan' || $k === 'penjelasan') {
            $peta['pembahasan'] = (int) $i;
        } elseif (str_starts_with($k, 'opsi') || str_starts_with($k, 'pilihan')) {
            $opsiKolom[] = (int) $i;
        }
    }
    if (!$peta && !$opsiKolom) {
        $peta = ['jenis' => 0, 'pertanyaan' => 1, 'jawaban' => 7, 'skor' => 8, 'pembahasan' => 9];
        $opsiKolom = [2, 3, 4, 5, 6];
    }
    return ['peta' => $peta, 'opsi' => $opsiKolom];
}

/**
 * Ubah baris tabel (Word lama / Excel / CSV) menjadi soal ternormalisasi.
 *
 * @param array $baris     baris data (TANPA baris judul)
 * @param array $judul     baris judul kolom (kosong = susunan baku template lama)
 * @param int   $maksBaris batas jumlah baris yang diproses (0 = tanpa batas)
 */
function soal_dari_kolom(array $baris, array $judul = [], int $maksBaris = 0): array
{
    $baris = array_values($baris);
    if (!$baris) {
        return [];
    }
    $info = soal_peta_kolom(array_values($judul));
    $peta = $info['peta'];
    $opsiKolom = $info['opsi'];
    if ($maksBaris > 0) {
        $baris = array_slice($baris, 0, $maksBaris);
    }
    $ambil = function (array $row, string $key) use ($peta): string {
        $i = $peta[$key] ?? -1;
        return $i >= 0 && isset($row[$i]) ? trim((string) $row[$i]) : '';
    };
    $out = [];
    foreach ($baris as $row) {
        if (!is_array($row)) {
            continue;
        }
        $pertanyaan = teks($ambil($row, 'pertanyaan'), SOAL_TEKS_MAKS);
        if ($pertanyaan === '') {
            continue;
        }
        $opsi = [];
        foreach ($opsiKolom as $c) {
            $v = isset($row[$c]) ? trim((string) $row[$c]) : '';
            if ($v !== '') {
                $opsi[] = teks($v, 500);
            }
        }
        $skorTeks = str_replace(',', '.', $ambil($row, 'skor'));
        $out[] = [
            'jenis' => jenis_soal_dari_teks($ambil($row, 'jenis')),
            'pertanyaan' => $pertanyaan,
            'opsi' => $opsi,
            'kunci' => $ambil($row, 'jawaban'),
            'skor' => $skorTeks !== '' ? (float) $skorTeks : null,
            'pembahasan' => teks($ambil($row, 'pembahasan'), PEMBAHASAN_MAKS),
            'pilihan_bs' => false,
        ];
    }
    return $out;
}

/** Baris tabel Word template LAMA -> soal ternormalisasi (baris pertama = judul kolom). */
function soal_dari_tabel(array $tabel): array
{
    $tabel = array_values($tabel);
    if (!$tabel) {
        return [];
    }
    $judul = is_array($tabel[0] ?? null) ? (array) array_shift($tabel) : [];
    return soal_dari_kolom($tabel, $judul);
}

// =====================================================================================
// 5. ISI TEMPLATE CONTOH (dipakai halaman unduh Word + pratinjau di halaman impor)
// =====================================================================================

/**
 * Template contoh bentuk TEKS (persis seperti contoh yang dikirim pemilik).
 * Setiap blok: ['teks' => string, 'tebal' => bool, 'tengah' => bool, 'kecil' => bool].
 */
function soal_template_blok(): array
{
    $b = [];
    $t = function (string $teks, array $opsi = []) use (&$b): void {
        $b[] = array_merge(['teks' => $teks], $opsi);
    };
    $pemisah = '==========================================================================';

    $t('TEMPLATE SOAL', ['tebal' => true, 'tengah' => true]);
    $t('Petunjuk pengisian (bagian ini tidak ikut diimpor — boleh dibiarkan atau dihapus):', ['kecil' => true]);
    $t('1. Tulis soal di bawah judul bagian yang sesuai: Pilihan Ganda, Pilihan Ganda Kompleks, '
        . 'Benar Salah, Menjodohkan, Essai Singkat, atau Essai Panjang.', ['kecil' => true]);
    $t('2. Awali setiap soal dengan nomor (1., 2., 3., …). Baris berikutnya yang bukan pilihan '
        . 'dianggap lanjutan pertanyaan.', ['kecil' => true]);
    $t('3. Pilihan jawaban ditulis A., B., C., D., E. (boleh diawali [ ] pada pilihan ganda kompleks).',
        ['kecil' => true]);
    $t('4. Tulis kunci pada baris tersendiri: "Kunci: C" (pilihan ganda), "Kunci: A, B, E" (kompleks), '
        . '"Kunci: A" atau "Kunci: Benar/Salah" (benar salah), "Kunci: jawaban contoh" (essai).',
        ['kecil' => true]);
    $t('5. Soal menjodohkan: satu baris satu pasangan, mis. "A. Salam Pembuka | Halo Sahabatku, apa kabarmu?"',
        ['kecil' => true]);
    $t('6. Baris "Skor: 2" boleh ditambahkan bila bobotnya ingin berbeda (bawaan 1).', ['kecil' => true]);
    $t('7. PENTING: HAPUS semua soal contoh di bawah ini, lalu tulis soal Anda sendiri. '
        . 'Soal contoh akan ikut terimpor bila dibiarkan.', ['kecil' => true, 'tebal' => true]);
    $t('');
    $t('Simpan berkas ini sebagai Word (.docx), lalu unggah pada menu Impor Soal.', ['kecil' => true]);
    $t('');

    // ------------------------------------------------------------------ Pilihan Ganda
    $t('Template Soal Pilihan Ganda', ['tebal' => true]);
    $t('1. Manakah dari pernyataan berikut yang paling tepat menggambarkan ciri khas utama dari '
        . 'sebuah surat pribadi dibandingkan dengan surat resmi?');
    $t('A. Menggunakan kop surat dari instansi tertentu untuk menunjukkan kredibilitas.');
    $t('B. Memiliki nomor surat dan lampiran sebagai bagian dari administrasi.');
    $t('C. Bahasa yang digunakan bersifat subjektif, ekspresif, dan tidak terikat aturan baku yang kaku.');
    $t('D. Wajib menggunakan stempel atau segel resmi untuk memvalidasi isi pesan.');
    $t('E. Ditujukan hanya untuk keperluan bisnis dan korespondensi antarperusahaan.');
    $t('Kunci: C');
    $t($pemisah);

    // ------------------------------------------------------------------ Pilihan Ganda Kompleks
    $t('Template Soal Pilihan Ganda Kompleks', ['tebal' => true]);
    $t('2. Seorang siswa ingin menulis surat pribadi kepada gurunya untuk mengucapkan terima kasih '
        . 'setelah kelulusan. Unsur-unsur apa saja yang lazimnya terdapat dalam surat pribadi tersebut? '
        . '(Pilih semua jawaban yang benar)');
    $t('[ ] A. Tempat dan tanggal penulisan surat.');
    $t('[ ] B. Salam pembuka yang bersifat hangat atau sopan.');
    $t('[ ] C. Nomor surat dan kode pengarsipan.');
    $t('[ ] D. Tembusan (c.c) kepada kepala sekolah.');
    $t('[ ] E. Identitas pengirim dan tanda tangan.');
    $t('Kunci: A, B, E');
    $t($pemisah);

    // ------------------------------------------------------------------ Benar Salah
    $t('Template Soal Benar Salah', ['tebal' => true]);
    $t('3. Dalam penulisan surat pribadi, penggunaan bahasa yang tidak baku atau bahasa gaul '
        . 'diperbolehkan sepenuhnya asalkan penerima surat dapat memahami maksud dari pengirimnya.');
    $t('A. Benar');
    $t('B. Salah');
    $t('Kunci: A');
    $t($pemisah);

    // ------------------------------------------------------------------ Menjodohkan
    $t('Template Soal Menjodohkan', ['tebal' => true]);
    $t('4. Pasangkanlah bagian-bagian surat pribadi berikut dengan contoh kalimat yang sesuai!');
    $t('A. Salam Pembuka | Halo Sahabatku, apa kabarmu di sana?');
    $t('B. Titimangsa | Bandung, 20 Mei 2024');
    $t('C. Paragraf Penutup | Sekian dulu ya ceritaku, kutunggu balasanmu segera.');
    $t('D. Salam Penutup | Sahabat baikmu,');
    $t($pemisah);

    // ------------------------------------------------------------------ Essai Singkat
    $t('Template Soal Essai Singkat', ['tebal' => true]);
    $t("5. Mengapa penggunaan kata ganti orang (seperti 'aku', 'kamu', atau 'anda') sangat krusial "
        . 'dalam sebuah surat pribadi?');
    $t('Kunci: Karena kata ganti orang menentukan tingkat keakraban dan membangun kedekatan emosional '
        . 'antara penulis dan penerima surat.');
    $t($pemisah);

    // ------------------------------------------------------------------ Essai Panjang
    $t('Template Soal Essai Panjang', ['tebal' => true]);
    $t('6. Bayangkan Anda adalah seorang siswa yang sudah satu tahun pindah ke luar kota dan sangat '
        . 'merindukan sahabat lama Anda. Buatlah sebuah surat pribadi yang terdiri dari minimal 3 '
        . 'paragraf. Surat tersebut harus mencakup: (1) Ungkapan kerinduan dan kabar Anda saat ini, '
        . '(2) Pertanyaan mengenai keadaan sahabat Anda, dan (3) Harapan untuk bertemu di masa liburan '
        . 'mendatang. Pastikan struktur surat (tempat/tanggal, salam, isi, penutup) tertulis dengan jelas.');
    $t('Kunci: Evaluasi berdasarkan kelengkapan struktur (Titimangsa, Salam Pembuka, Isi 3 Paragraf '
        . 'sesuai instruksi, Penutup) dan kesesuaian nada bahasa yang akrab.');

    return $b;
}

/** Template contoh sebagai teks biasa (dipakai pratinjau halaman & uji). */
function soal_template_teks(): string
{
    return implode("\n", array_map(fn($x) => (string) $x['teks'], soal_template_blok()));
}
