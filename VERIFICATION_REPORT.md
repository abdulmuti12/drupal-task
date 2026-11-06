# Verification Report

## Static Checks (initial authoring — 2026-11-06)

- `php -l` on `corrected_portfolio.php`, all custom-module `.php` files, `market_feed.install`, and `scripts/offline_smoke.php`: no syntax errors (PHP 8.4.24 CLI).
- `php scripts/offline_smoke.php`: **PASS** — import samples, duplicate-last-valid-wins, row validation/isolation, four statements for 500 rows, **334 batched statements for 50,000 distinct rows**, explicit rollback on synthetic second-batch failure, no cache invalidation after rollback or invalid-only input. Uses local fake Drupal service classes, not real SQL.
- `python3 scripts/sql_smoke.py`: **PASS** for D1 previous available trading day and D2 observed June gap on in-memory SQLite fixture. Validates answer semantics at small scale only.
- `yaml.safe_load()` on four module YAML files: parse success.

## Live Integration Checks (2026-11-06 — XAMPP, Drupal 10.6.20, PHP 8.2.28, MariaDB)

Environment: `drupal-market-feed` XAMPP project, DB `market_feed`, module `market_feed` enabled, Basic Auth on import route.

### Module & Schema
- `drush pm:list` → `market_feed` **Enabled**.
- `hook_schema()` ran on install: `market_feed_prices` table exists in DB (InnoDB, PK on `symbol`, `DECIMAL(18,4)` price, `BIGINT` volume, `BIGINT` updated_at, index on `updated_at`).

### Import API
- No credentials → **401** ✓
- Wrong Basic Auth credentials → **403** ✓
- Valid `admin:admin` → **200** ✓
- `Content-Type: text/plain` → **415** ✓
- `Content-Type: application/json; charset=UTF-8` → **200** ✓
- GET on import route → **405** ✓
- Top-level object, `null`, malformed JSON → **400** ✓
- Empty array `[]` → **200** (no cache invalidation, confirmed in `cachetags`)
- Valid 3-row payload (mixed-case `msft` normalized to `MSFT`) → **200**, `imported:2, duplicates:1` ✓
- Negative price, price >4 decimals, NaN/Inf, negative volume, float volume, volume > PHP_INT_MAX, row-is-list, row-is-string, invalid symbol chars, symbol >20 chars → all **skipped** with correct error reasons ✓
- 120 invalid rows → `errors:100, errors_truncated:20` ✓
- Duplicate symbol in same payload (last-valid-wins) → `imported:1, duplicates:1` ✓
- 20 concurrent same-symbol upserts → all **200**, no duplicate-key errors, single row in DB ✓

### Display Page
- `/market-feed` with `view market feed` granted via session → **200**, table renders ✓
- Invalid symbol filter (`?symbol=foo bar`) → empty table, no DB query issued ✓
- Valid symbol filter (`?symbol=aapl`) → shows `AAPL` row only ✓
- No filter → shows all rows ✓

### Caching
- `market_feed_prices` cache tag invalidated **33 times** across all import runs (confirmed in `cachetags` table).
- All import responses carry `Cache-Control: no-store` ✓
- Page fetches via Basic Auth show `X-Drupal-Cache: UNCACHEABLE (request policy)` — expected; `$settings['cache']['default'] = 'cache.backend.database'` disables full-page cache in this deployment. Render cache still responds to tag invalidation.

### Performance
- **50,000-row single import**: 2.19 s wall-clock, ~22,900 rows/s, `imported:50000` ✓
- **3× 50,000-row re-upserts** (idempotent path): 7.24 s total, ~2.4 s/call ✓
- **10 concurrent × 1,000-row imports**: 0.99 s total, all 10,000 rows in DB ✓
- **5 sequential page fetches** (500 rows in DB): 147 ms average ✓

### Code Fixes Applied During Live Audit
- `ImportController.php`: `Content-Length` pre-check now trims whitespace before `ctype_digit()` (was silently skipped on padded headers); `(float)` → `(int)` cast; `ltrim($body)` now uses explicit JSON-whitespace charlist `' \t\n\r'` instead of default.
- `MarketFeedController.php`: invalid `?symbol=` filter now short-circuits before the DB query instead of issuing `WHERE symbol = '__INVALID_FILTER__'`; form value logic updated to reference `$filterValid` instead of the sentinel.
- All fixes confirmed working in live integration tests above.

## Not Yet Verified

- `EXPLAIN ANALYZE` on MySQL 8 / 500M rows (Part D design reference only; Part D SQL solutions validated on SQLite fixture, not production-scale MySQL).
- Drupal PHPCS coding standards (Composer and PHPCS not available in this environment).
- 200 requests/s REST API performance under concurrent mobile load (Part B is a design document, not an implementation).

## Required Before Submission

- [ ] Fill in README *actual elapsed time* values after candidate review.
- [ ] Confirm reviewer can follow README and reproduce the live integration tests above in their own Drupal environment.
- [ ] Confirm no secrets in any committed file.
