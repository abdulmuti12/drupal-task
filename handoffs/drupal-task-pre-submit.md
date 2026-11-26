# ShareInvestor Technical Test — Pre-Submission Sweep
Status: BERJALAN · Service: drupal-task (repo) · Diperbarui: 2026-11-26 11:00

## Sedang dikerjakan
Siapkan repo untuk submit (zip/repo bersih tanpa runtime XAMPP, tanpa secret, tanpa referensi ke file gitignored).

## Status terakhir
- Fix #3 (ltrim char-list) dan Fix #4 (getCountQuery) sudah di-commit di `9050e08` dan dicatat di `CHANGELOG_FIXES.md` (commit `2553add`).
- Module styling (CSS/Twig/module file) + controller refactor ter-commit di `9050e08`.
- `.htaccess` XAMPP helper sengaja tidak di-track (lokal-only).
- **Koreksi audit sebelumnya**: Blocker #1 audit (klaim `updated_at` tidak di-set di upsert) adalah **salah** — sudah diverifikasi di `web/core/modules/mysql/.../Upsert.php:40-48` dan live di `VERIFICATION_REPORT.md:45`. Tidak ada yang perlu di-fix.

## Keputusan penting
- `updated_at` sudah benar di upsert MySQL (INSERT+VALUES di update clause). Audit review berikutnya jangan ulangi klaim ini.
- `match (TRUE)` di `MarketFeedController.php` dan `ImportReport` value object: dibiarkan, bisa di-justify di tanya jawab (lihat bagian "Jangan lakukan" di bawah).

## Langkah berikutnya
1. **Isi time accounting di `README.md`** — kolom "Actual elapsed time" masih "Not recorded" untuk A/B/C/D. Tanyakan ke user berapa jam per bagian.
2. **Hapus / ganti referensi `CLAUDE_REVIEW_PROMPT.md` di `README.md`** (deliverables table) — file ini ada di `.gitignore`, tidak akan ikut di zip. Ganti dengan file yang ada (`VERIFICATION_REPORT.md`, `CHANGELOG_FIXES.md`).
3. **Live-verify Fix #3 + #4 di Drupal XAMPP**:
   - `cd /Applications/XAMPP/xamppfiles/htdocs/drupal-task/web`
   - `vendor/bin/drush cr` (jika ada drush; kalau tidak, akses `http://localhost/drupal-task/` untuk rebuild)
   - GET `/market-feed?symbol=` → badge "N symbols shown" harus = total di DB, bukan 50
   - POST `/api/market-feed/import` dengan `application/json` body `"not-an-array"` → harus 400 "Top-level JSON value must be an array" (bukan "Malformed JSON")
   - POST body `"  \n [ ... ]"` (whitespace di depan) �� harus 200 (whitespace JSON legal)
   - POST body `"\t[ ... ]"` sebagai JSON string → harus 400 "Top-level JSON value must be an array"
4. **Verifikasi PHP syntax final**: `php -l` di seluruh file module. Sudah pass di `9050e08`, ulang sebelum submit.
5. **Zip / export repo**: pastikan runtime XAMPP (`web/core`, `web/vendor`, `web/sites/default/settings.php`) TIDAK ikut. Cek `.gitignore` sudah benar exclude.
6. **Scan secret**: `grep -rn "admin:admin\|password\|secret" --include="*.php" --include="*.yml" --include="*.md"` — pastikan tidak ada credential live di commit.

## Jangan lakukan
- Jangan ubah `updated_at` logic di `MarketFeedImporter.php` — sudah benar.
- Jangan hapus CSS/Twig "out of scope" — user memilih menjaga styling. Kalau di-tanya di wawancara, justifikasi: "display page yang usable dan accessible (focus states, ARIA, tabular-nums untuk angka finansial); CSS modul-scoped, tidak mengganggu theme lain".
- Jangan ubah `match (TRUE)` di `MarketFeedController.php` tanpa tanya dulu — PHPCS Drupal kemungkinan flag; kalau di-tanya, jawab "PHP 8.1 feature, lebih ekspresif dari if/elseif untuk 3-branch".
- Jangan commit `.htaccess` XAMPP helper — itu lokal-only, tidak relevan di zip.
- Jangan klaim `phpcs --standard=Drupal` sudah dijalankan — tidak ada di environment (lihat `VERIFICATION_REPORT.md:58`).
