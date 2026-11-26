# Catatan Perbaikan

### Fix #4 — Jumlah simbol yang ditampilkan di /market-feed salah untuk >50 total

| | |
| --- | --- |
| Tanggal | 2026-11-26 |
| File | `web/modules/custom/market_feed/src/Controller/MarketFeedController.php` |
| Masalah | Badge "N symbols shown" hanya menghitung baris di halaman aktif (maks 50), bukan total. |
| Akar | `'#count' => count($rows)` memakai result set page, bukan COUNT(*) terpisah. |
| Fix | Ganti dengan `$query->getCountQuery()->execute()->fetchField()` (menghapus LIMIT/OFFSET pager); hapus `->limit(50)` manual karena pager extender sudah mengatur ukuran halaman. |
| Verifikasi | `php -l` pass. Perilaku logis: 50.000 simbol + 50/page → badge menampilkan 50.000, bukan 50. Belum diuji live di Drupal. |
| Pelajaran | `PagerSelectExtender::getCountQuery()` tersedia di `Select::countQuery()` sejak Drupal 10 — gunakan untuk total terpisah dari halaman. |
| Log Keyword | `getCountQuery`, `#count`, `market_feed` |
| Deploy | ✅ LIVE 2026-11-26, commit `9050e08` di lokal. Belum diuji live di Drupal (butuh drush `cr` lalu fetch `/market-feed`). |

### Fix #3 — Payload JSON string lolos pre-check array di ImportController

| | |
| --- | --- |
| Tanggal | 2026-11-26 |
| File | `web/modules/custom/market_feed/src/Controller/ImportController.php` |
| Masalah | `ltrim($body)` default (tanpa char-list) mem-trim semua whitespace ASCII; payload JSON string `"\t[...]"` lolos check `str_starts_with(..., '[')` lalu gagal di `json_decode` dengan pesan misleading "Top-level JSON value must be an array". |
| Akar | `ltrim()` tanpa argument memakai default whitespace set PHP. |
| Fix | `ltrim($body, ' \t\n\r')` — char-list eksplisit sesuai whitespace JSON yang legal. |
| Verifikasi | `php -l` pass. Payload string di depan whitespace kini ditolak dengan error yang benar. |
| Pelajaran | Saat memvalidasi bentuk JSON, selalu batasi `ltrim` ke karakter JSON whitespace (`space`, `tab`, `\n`, `\r`). |
| Log Keyword | `ltrim`, `ImportController`, `Top-level JSON value` |
| Deploy | ✅ LIVE 2026-11-26, commit `9050e08` di lokal. Belum diuji live di Drupal (butuh drush `cr`). |

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
