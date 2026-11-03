<?php
/**
 * Titik masuk setiap halaman: zona waktu, sesi, konfigurasi, helper, dan basis data.
 *
 * Modul HALAMAN DEPAN (`inc/landing.php`) sudah DIHAPUS pada v1.14.0 — permintaan pemilik:
 * landing page & pendaftaran sekolah tidak dipakai lagi karena data ditarik dari e-GURU.
 */
declare(strict_types=1);

date_default_timezone_set('Asia/Jakarta');

require_once __DIR__ . '/config.php';

if (!is_dir(SESI_DIR)) {
    @mkdir(SESI_DIR, 0775, true);
}
if (is_dir(SESI_DIR) && is_writable(SESI_DIR)) {
    session_save_path(SESI_DIR);
}
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

// Kesalahan PHP dicatat ke log server dan TIDAK dicetak ke halaman: keluaran tak sengaja di
// tengah permintaan dapat membuat header('Location: …') gagal ("headers already sent"),
// sehingga tombol simpan terlihat tidak bekerja tanpa pesan yang jelas.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
ob_start();

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/layout.php';
