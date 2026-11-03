<?php
/**
 * Konfigurasi dasar aplikasi CBT (Computer Based Test).
 *
 * Aplikasi MULTI SEKOLAH: banyak sekolah/madrasah memakai satu pemasangan aplikasi.
 *
 * v1.14.0 (permintaan pemilik): HALAMAN LANDING & PENDAFTARAN SEKOLAH MANDIRI DIHAPUS — seluruh
 * data (sekolah, guru, siswa, kelas, mata pelajaran) ditarik dari aplikasi e-GURU, dan halaman
 * publik aplikasi hanya HALAMAN LOGIN. Sekolah dibuat Super Admin lewat menu "Kelola Sekolah"
 * (termasuk "Tarik Data Lembaga dari e-GURU").
 */
declare(strict_types=1);

// Nama aplikasi tampil di halaman masuk, sidebar, footer, dan judul halaman.
define('APP_NAME', 'Computer-Based Test (CBT)');
define('APP_SUB', 'Aplikasi Ujian/Asesmen Berbasis Komputer');
define('APP_VERSION', '1.19.1');
define('APP_AUTHOR', 'Supianto');
define('APP_AUTHOR_JABATAN', 'Kepala MA. Sirojuth Tholibiin Mootilango');

// Data & sesi. Sesudah publish, folder ini dipakai apa adanya (bukan di disk lain).
// CBT_DB_FILE dapat diisi untuk menjalankan uji memakai SALINAN basis data — jangan pernah
// dipakai untuk mengarahkan ulang data nyata.
define('DB_FILE', getenv('CBT_DB_FILE') ?: (dirname(__DIR__) . '/data/cbt.sqlite'));
define('SESI_DIR', dirname(__DIR__) . '/data/sessions');

// Basis data aplikasi e-GURU pada pemasangan yang sama — dipakai menu "Tarik Data dari e-GURU"
// (data guru & siswa ikut masuk sehingga tidak perlu input manual lagi). Dibaca BACA-SAJA:
// aplikasi ini tidak pernah menulis apa pun ke basis data e-GURU.
// Bila tata letak folder berbeda, arahkan lewat variabel lingkungan EGURU_DB_FILE.
define('EGURU_DB_FILE', getenv('EGURU_DB_FILE') ?: (dirname(__DIR__, 2) . '/e-guru/data/eguru.sqlite'));

// Tautan RELATIF (aplikasi dipasang di subpath "/cbt"), jadi jangan memakai awalan "/".
define('BASE', '');

// Unggahan logo sekolah lewat media proxy platform (token dibaca server-side dari berkas
// .vibecoder-media-token; JANGAN pernah dikirim ke browser).
define('MEDIA_ENDPOINT', 'http://127.0.0.1:4310/api/app-media/upload');
define('FOTO_MAKS_BYTE', 15 * 1024 * 1024); // 15 MB (batas platform untuk gambar)
define('FOTO_EKSTENSI', ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg']);

// ---------------------------------------------------------------------------------------------
// AKTIVASI SEKOLAH
// Sekolah dibuat Super Admin (atau ditarik dari e-GURU) dengan MASA AKTIF 365 HARI. Setelah masa
// itu habis, aplikasi terkunci sampai KODE AKTIVASI lembaga dimasukkan (kode dibuat aplikasi,
// hanya terlihat Super Admin, dan berbeda setiap periode).
//
// v1.14.0: konstanta `PENDAFTARAN_HARI_AKTIF` (pendaftar mandiri 30 hari) DIHAPUS — halaman
// pendaftaran sudah tidak ada, semua sekolah dibuat Super Admin dengan masa aktif 1 tahun.
define('AKTIVASI_HARI_AKTIF', 365);     // masa aktif setelah diaktivasi dengan kode
define('AKTIVASI_MAKS_COBA', 5);        // maksimal percobaan kode salah
define('AKTIVASI_MENIT_COBA', 10);      // ... per sekian menit (per sesi)
define('AKTIVASI_WA_ADMIN', '085240102180'); // WA HD Admin (permohonan kode aktivasi)

// ---------------------------------------------------------------------------------------------
// BIAYA & KONTAK AKTIVASI
// Permintaan pemilik: halaman "Status & Aktivasi" pada akun Operator memuat fitur memasukkan kode
// aktivasi, permohonan kode aktivasi, NOMINAL biaya, dan NOMOR REKENING — dan semuanya dapat diatur
// Super Admin lewat menu "Biaya & Aktivasi" (`biaya_aktivasi.php`).
//
// Konstanta di bawah ini adalah NILAI BAWAAN (dipakai bila Super Admin belum pernah mengubahnya,
// dan oleh tombol "Kembalikan Bawaan"). Halaman WAJIB membacanya lewat fungsi
// `aktivasi_biaya_pertama()`, `aktivasi_biaya_langganan()`, `aktivasi_wa()`, `aktivasi_rekening()`
// dan `aktivasi_rekening_ket()` — JANGAN memakai konstantanya langsung, kalau tidak perubahan
// Super Admin tidak akan terpakai.
define('AKTIVASI_BIAYA', 1000000);            // biaya aktivasi pertama (masa aktif 1 tahun)
define('AKTIVASI_BIAYA_LANGGANAN', 500000);   // biaya langganan tahun berikutnya (perpanjangan)
define('AKTIVASI_BIAYA_MAKS', 1000000000);    // batas wajar nominal (Rp 1 miliar)
define('AKTIVASI_WA_MAKS', 15);               // panjang nomor WA yang disimpan (digit)
define('AKTIVASI_REKENING', 'Bank Mandiri 1500010135547. An. Supianto');
define('AKTIVASI_REKENING_MAKS', 160);
define('AKTIVASI_REKENING_KET', 'Pastikan anda transfer ke nomer rekening tersebut untuk proses '
    . 'aktifasi dan verikasi. Diluar rekening tersebut kami tidak bertanggungjawab dan aplikasi anda '
    . 'tidak akan aktif');
define('AKTIVASI_REKENING_KET_MAKS', 400);

// ---------------------------------------------------------------------------------------------
// ATURAN UJIAN
// Batas pelanggaran siswa saat mengerjakan (pindah tab / keluar layar penuh) sebelum jawaban
// otomatis dikumpulkan. Permintaan pemilik: "Layar penuh + deteksi pindah tab + 3 pelanggaran
// = otomatis dikumpulkan".
define('UJIAN_MAKS_PELANGGARAN', 3);

// Jenis-jenis soal yang didukung aplikasi (kode => label).
define('JENIS_SOAL', [
    'pg'     => 'Pilihan Ganda',
    'pgk'    => 'Pilihan Ganda Kompleks',
    'bs'     => 'Benar / Salah',
    'jodoh'  => 'Menjodohkan',
    'isian'  => 'Isian Singkat',
    'uraian' => 'Uraian / Essai',
]);

// Jumlah maksimal baris yang boleh diimpor sekaligus.
define('IMPOR_MAKS_BARIS', 500);
define('IMPOR_MAKS_BARIS_SOAL', 300);

// Token ujian (diinput siswa sebelum mengerjakan) — permintaan pemilik.
define('TOKEN_PANJANG', 6);      // panjang token
define('TOKEN_MAKS_COBA', 5);    // maksimal percobaan salah per sesi
define('TOKEN_MENIT_COBA', 10);  // ... per sekian menit

// Jenis penilaian / jenis ulangan (daftar yang tampil pada halaman buat ujian guru).
// Permintaan pemilik: daftarnya dapat DITAMBAH & DIHAPUS oleh Super Admin (halaman `jenis_ujian.php`),
// jadi isi bawaannya disimpan di sini sebagai NILAI AWAL saja — jangan memakai konstanta ini
// langsung di halaman; pakai `jenis_ujian_list()` (inc/helpers.php).
define('JENIS_UJIAN_MAKS', 20);       // maksimal banyaknya jenis pada daftar
define('JENIS_UJIAN_PANJANG', 40);    // panjang maksimal satu nama jenis

// Panjang teks
define('UJIAN_JUDUL_MAKS', 150);
define('SOAL_TEKS_MAKS', 3000);
define('SOAL_OPSI_MAKS', 12);   // maksimal opsi per soal
define('PEMBAHASAN_MAKS', 2000);

// ---------------------------------------------------------------------------------------------
// TOMBOL "BUAT SOAL OTOMATIS" — permintaan pemilik: tombol BERKEDIP WARNA MERAH pada kepala kartu
// halaman Tambah Soal yang membuka aplikasi bantu penyusun soal (Google Gemini "Gem" berbagi) di
// tab baru, disertai keterangan "Password aplikasi: berkah".
//
// v1.17.0 (permintaan pemilik): TAUTAN tombol ini dapat DIISI / DIGANTI oleh Super Admin lewat
// menu "Soal Otomatis" (`soal_otomatis.php`) — tersimpan TINGKAT APLIKASI pada `app_pengaturan`
// (kunci `soal_otomatis_url`). Konstanta `SOAL_OTOMATIS_URL` di bawah ini hanyalah NILAI BAWAAN
// (dipakai bila Super Admin belum pernah mengubahnya, dan oleh tombol "Kembalikan Bawaan").
// Halaman WAJIB membaca tautan lewat `soal_otomatis_url()` — JANGAN memakai konstantanya langsung.
// Kata sandi belum dapat diubah dari aplikasi (masih konstanta `SOAL_OTOMATIS_PASSWORD`).
define('SOAL_OTOMATIS_URL', 'https://gemini.google.com/share/9b5d2dc2944b');
define('SOAL_OTOMATIS_PASSWORD', 'berkah');
define('SOAL_OTOMATIS_URL_MAKS', 500);   // panjang maksimal tautan yang disimpan
