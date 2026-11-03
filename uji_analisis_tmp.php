<?php
// Verifikasi rumus validitas (Pearson) & distraktor dengan data TERKONTROL.
$akar = getenv('CBT_DIR') ?: '/tmp/cbt_an';
require $akar . '/inc/init.php';

$sid = 1; $uj = 1;
q("DELETE FROM sesi_ujian WHERE ujian_id = ? AND sekolah_id = ?", [$uj, $sid]);
$siswa = all("SELECT id FROM siswa ORDER BY id LIMIT 5");
echo "jumlah siswa uji: " . count($siswa) . "\n";

// Skor butir-1 yang DIKETAHUI: 5 peserta (3 teratas benar)
$skorButir = [1, 1, 1, 0, 0];
$lain = [2, 2, 2, 1, 1];
$soal = all("SELECT id, jenis, jawaban, skor FROM soal WHERE ujian_id = ? ORDER BY urutan", [$uj]);
$soalPg = $soal[0];
$kunciPg = (int) json_decode((string) $soalPg['jawaban'], true);

foreach ($siswa as $i => $s) {
    q("INSERT INTO sesi_ujian (sekolah_id, ujian_id, siswa_id, mulai, selesai, status) VALUES (?,?,?,?,?,'selesai')",
      [$sid, $uj, (int) $s['id'], date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
    $sesiId = (int) db()->lastInsertId();
    q("INSERT INTO jawaban (sesi_id, soal_id, jawaban, benar, skor, dijawab) VALUES (?,?,?,?,?,?)",
      [$sesiId, (int) $soalPg['id'], json_encode($skorButir[$i] ? $kunciPg : (($kunciPg + 1) % 4)),
       $skorButir[$i], $skorButir[$i], date('Y-m-d H:i:s')]);
    q("INSERT INTO jawaban (sesi_id, soal_id, jawaban, benar, skor, dijawab) VALUES (?,?,?,?,?,?)",
      [$sesiId, (int) $soal[1]['id'], json_encode([0]), $lain[$i] >= 2 ? 1 : 0, $lain[$i], date('Y-m-d H:i:s')]);
    sesi_hitung($sesiId);
}

$a = analisis_butir($uj);
$b = $a['butir'][0];

$xs = $skorButir;
$ys = [];
foreach ($xs as $i => $x) { $ys[] = $x + $lain[$i]; }
$n = count($xs);
$mx = array_sum($xs) / $n; $my = array_sum($ys) / $n;
$cov = 0; $vx = 0; $vy = 0;
for ($i = 0; $i < $n; $i++) { $dx = $xs[$i] - $mx; $dy = $ys[$i] - $my; $cov += $dx * $dy; $vx += $dx * $dx; $vy += $dy * $dy; }
$manual = round($cov / sqrt($vx * $vy), 4);

echo "validitas manual = $manual | helper = {$b['validitas']} -> "
   . (abs($manual - $b['validitas']) < 0.01 ? "COCOK" : "TIDAK COCOK") . "\n";
echo "label validitas   : {$b['valid_label']}\n";
echo "tingkat kesukaran : {$b['kesukaran']} (p={$b['p_benar']})\n";
echo "daya beda         : {$b['daya_beda']} ({$b['db_label']})\n";
echo "distraktor:\n";
foreach ($b['distraktor'] as $d) {
    echo "  {$d['huruf']}" . ($d['kunci'] ? ' (KUNCI)' : '') . ": {$d['jumlah']} pemilih"
       . " (atas={$d['atas']}, bawah={$d['bawah']}) -> {$d['status']}\n";
}
echo "ringkas: " . json_encode($a['ringkas']) . "\n";
