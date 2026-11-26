# Catatan Perbaikan

### Fix #2 — Tampilan Market Feed kurang terbaca dengan formulir dan tabel bawaan

| | |
| --- | --- |
| Tanggal | 2026-11-26 |
| File | Controller, template Twig, dan library CSS di `web/modules/custom/market_feed/` |
| Masalah | Filter, harga, volume, dan perubahan harga tampil tanpa penekanan visual. |
| Akar | Formulir inline dan tabel memakai gaya bawaan Drupal tanpa layout khusus Market Feed. |
| Fix | Tambah panel filter, pesan validasi dan hasil kosong, indikator perubahan harga, pemisah ribuan untuk volume, dan area gulir tabel di layar sempit. |
| Verifikasi | Chrome pada lebar 1280 dan 390 px menampilkan 8 baris tanpa gulir horizontal pada halaman. Mengirim 1 simbol menghasilkan 1 baris. Clear memulihkan 8 baris. Simbol yang salah dan hasil kosong menampilkan pesan yang sesuai. Akses anonim tetap HTTP 403. |
| Pelajaran | Batasi gulir horizontal pada wadah tabel supaya formulir tetap muat di layar sempit. |
| Log Keyword | `market_feed_listing`, `market-feed.css`, `Filter by symbol` |
| Deploy | ✅ LIVE 2026-11-26 di XAMPP lokal setelah rebuild cache Drupal. |

### Fix #1 — Market Feed menampilkan indeks direktori karena runtime Drupal belum terpasang

| | |
| --- | --- |
| Tanggal | 2026-11-26 |
| File | `.htaccess`, `.gitignore`, `web/sites/default/settings.php` (lokal) |
| Masalah | Apache menampilkan indeks direktori di `/drupal-task/web/`. |
| Akar | Checkout berisi modul Market Feed. Core Drupal, dependensi PHP, dan konfigurasi MySQL belum terpasang di folder proyek. |
| Fix | Pasang Drupal 10.6.23, hubungkan ke database `market_feed` yang sudah ada, jalankan satu post-update Drupal, dan arahkan URL proyek ke `web/`. |
| Verifikasi | Beranda dan login HTTP 200. Market Feed HTTP 403 untuk pengunjung anonim. Uji Drupal dengan akun yang diizinkan membaca 8 harga dan menghasilkan 1 baris untuk filter simbol. Checksum tabel harga tetap `826473685`. Pembaruan skema dan post-update tersisa 0. |
| Pelajaran | Periksa tabel dan versi skema Drupal sebelum menjalankan installer pada database yang sudah berisi situs. |
| Log Keyword | `market_feed`, `drupal-task`, `xampp`, `system_post_update_mark_installation_finished` |
| Deploy | ✅ LIVE 2026-11-26 di XAMPP lokal, `http://localhost/drupal-task/`. |
