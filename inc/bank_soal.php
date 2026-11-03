<?php
/**
 * BAGIAN BERSAMA bank soal: saringan + penyusun daftar soal.
 * Dipakai `bank_soal.php`, `bank_soal_word.php`, dan `cetak_bank_soal.php`
 * supaya soal yang diunduh/dicetak SELALU sama dengan yang tampil di layar.
 */
declare(strict_types=1);

/** Saringan bank soal dari parameter GET (dibersihkan). */
function bank_saring(): array
{
    return [
        'mapel'  => (int) ($_GET['mapel'] ?? 0),
        'ujian'  => (int) ($_GET['ujian'] ?? 0),
        'jenis'  => teks($_GET['jenis'] ?? '', 12),
        'guru'   => (int) ($_GET['guru'] ?? 0),
        'gambar' => teks($_GET['gambar'] ?? '', 6),
        'cari'   => teks($_GET['cari'] ?? '', 80),
    ];
}

/** Daftar soal bank soal sesuai saringan (sudah lengkap: opsi & kunci terdekode). */
function bank_soal_list(array $f = []): array
{
    $sql = "SELECT s.*, uj.judul AS ujian, uj.mapel_id AS mapel_id, m.nama AS mapel, g.nama AS guru
        FROM soal s
        JOIN ujian uj ON uj.id = s.ujian_id
        LEFT JOIN mapel m ON m.id = uj.mapel_id
        LEFT JOIN guru g ON g.id = uj.guru_id
        WHERE s.sekolah_id = ?";
    $p = [sekolah_id()];
    if ((int) ($f['mapel'] ?? 0) > 0) { $sql .= ' AND uj.mapel_id = ?'; $p[] = (int) $f['mapel']; }
    if ((int) ($f['ujian'] ?? 0) > 0) { $sql .= ' AND s.ujian_id = ?'; $p[] = (int) $f['ujian']; }
    if (($f['jenis'] ?? '') !== '' && array_key_exists((string) $f['jenis'], JENIS_SOAL)) {
        $sql .= ' AND s.jenis = ?';
        $p[] = (string) $f['jenis'];
    }
    if ((int) ($f['guru'] ?? 0) > 0) { $sql .= ' AND uj.guru_id = ?'; $p[] = (int) $f['guru']; }
    if (($f['gambar'] ?? '') === 'ada') { $sql .= ' AND s.gambar IS NOT NULL AND s.gambar <> ""'; }
    if (($f['gambar'] ?? '') === 'tanpa') { $sql .= ' AND (s.gambar IS NULL OR s.gambar = "")'; }
    if (($f['cari'] ?? '') !== '') {
        $sql .= ' AND (s.pertanyaan LIKE ? OR s.pembahasan LIKE ?)';
        $p[] = '%' . $f['cari'] . '%';
        $p[] = '%' . $f['cari'] . '%';
    }
    $sql .= ' ORDER BY uj.id DESC, s.urutan, s.id';
    return array_map('soal_lengkap', all($sql, $p));
}

/** Teks kunci jawaban sebuah soal untuk ditampilkan/dicetak. */
function bank_kunci_teks(array $s): string
{
    $kunci = $s['kunci'];
    $jenis = (string) $s['jenis'];
    if ($jenis === 'pg') {
        return huruf_opsi((int) $kunci) . '. ' . (string) ($s['opsi_arr'][(int) $kunci] ?? '');
    }
    if ($jenis === 'pgk') {
        $arr = is_array($kunci) ? array_map('intval', $kunci) : [];
        $bagian = [];
        foreach ($arr as $oi) {
            $bagian[] = huruf_opsi($oi) . '. ' . (string) ($s['opsi_arr'][$oi] ?? '');
        }
        return implode(' ; ', $bagian);
    }
    if ($jenis === 'bs') {
        $arr = is_array($kunci) ? $kunci : [];
        $bagian = [];
        foreach ($s['opsi_arr'] as $oi => $teks) {
            $bagian[] = ($oi + 1) . '. ' . atas((string) ($arr[$oi] ?? '-'));
        }
        return implode(' ; ', $bagian);
    }
    if ($jenis === 'jodoh') {
        $arr = is_array($kunci) ? $kunci : [];
        $bagian = [];
        foreach ($s['opsi_arr'] as $oi => $teks) {
            $bagian[] = huruf_opsi($oi) . '. ' . $teks . ' -> ' . (string) ($arr[$oi] ?? '-');
        }
        return implode(' ; ', $bagian);
    }
    // isian & uraian: daftar alternatif/kata kunci
    $arr = is_array($kunci) ? $kunci : [(string) $kunci];
    $label = $jenis === 'isian' ? 'alternatif jawaban' : 'kata kunci';
    return implode(' | ', array_map('strval', $arr)) . ' (' . $label . ')';
}

/** Label saringan yang aktif (untuk kepala lembar cetak / dokumen Word). */
function bank_label_saring(array $f): array
{
    $out = [];
    if ((int) ($f['mapel'] ?? 0) > 0) { $out[] = 'Mata Pelajaran: ' . mapel_nama((int) $f['mapel']); }
    if ((int) ($f['ujian'] ?? 0) > 0) {
        $out[] = 'Ujian: ' . (string) val('SELECT judul FROM ujian WHERE id = ? AND sekolah_id = ?',
            [(int) $f['ujian'], sekolah_id()], '-');
    }
    if (($f['jenis'] ?? '') !== '') { $out[] = 'Jenis soal: ' . jenis_soal_label((string) $f['jenis']); }
    if ((int) ($f['guru'] ?? 0) > 0) {
        $out[] = 'Guru penyusun: ' . (string) val('SELECT nama FROM guru WHERE id = ? AND sekolah_id = ?',
            [(int) $f['guru'], sekolah_id()], '-');
    }
    if (($f['gambar'] ?? '') === 'ada') { $out[] = 'Hanya soal bergambar'; }
    if (($f['gambar'] ?? '') === 'tanpa') { $out[] = 'Tanpa gambar'; }
    if (($f['cari'] ?? '') !== '') { $out[] = 'Kata kunci: ' . $f['cari']; }
    return $out;
}
