# ShareInvestor — Senior Software Engineer (Drupal/PHP) Offline Technical Test

This repository contains my solution draft for the supplied technical test, comprising **Part A** (Drupal custom module), **Part B** (1–2 page architecture design), **Part C** (merge request review and corrected PHP), and **Part D** (SQL). The primary deliverable is an **installable custom module**, not a bundled preinstalled Drupal site.

> **Verification status:** The PHP source passed PHP CLI syntax checks; all four module YAML files parsed successfully. The offline importer smoke test passed, including a synthetic 50,000-row / 334-batch scenario and rollback on a failing batch. The day-over-day and gap-detection SQL semantics passed an SQLite fixture test. These are **not** substitutes for a real Drupal integration test. Composer, Drupal core, and a MySQL server were unavailable in the authoring environment; real Drupal installation, HTTP authorization, render caching, upsert driver behavior and MySQL query plans remain **unverified**.

## Deliverables

| Part | File(s) | Weight |
|---|---|---:|
| A | `web/modules/custom/market_feed/` | 45% |
| B | `ARCHITECTURE.md` | 25% |
| C | `CODE_REVIEW.md`, `corrected_portfolio.php` | 20% |
| D | `SQL_SOLUTIONS.md` | 10% |
| Samples | `samples/*.json` | — |
| Handoff | `CLAUDE_REVIEW_PROMPT.md` | — |

## Requirements

- Drupal **10 or 11** (choose a supported Drupal/PHP/Composer combination); PHP **8.1+** per assignment. Current Drupal 11 requires a newer PHP than the bare 8.1 assignment minimum. Do not force Drupal 11 on PHP 8.1.
- A supported MySQL/MariaDB/PostgreSQL/SQLite backend with schema/Upsert support.
- `drupal:basic_auth` core module (declared dependency), and Drush if using the CLI examples.
- HTTPS for production-like credentialed requests; a dedicated low-privilege importer account.

## Install in an existing Drupal Composer site

1. Back up your site or use a separate development/staging instance. **Do not install this into a live unrelated production site.**
2. From this repository, copy `web/modules/custom/market_feed/` into your Drupal site's `web/modules/custom/market_feed/`.
3. Install core Basic Auth and the Market Feed module, then clear caches:

   ```bash
   vendor/bin/drush en basic_auth market_feed -y
   vendor/bin/drush cr
   vendor/bin/drush status
   ```

4. Grant `view market feed` to a reader role; grant `import market feed` **only** to a dedicated service user/role. The import permission is marked `restrict access`. Do not grant it to anonymous or normal customer roles.
5. Open `https://YOUR-DRUPAL-HOST/market-feed` as a permitted user. It has an exact-symbol `?symbol=AAPL` filter and a 50-row pager.

To bootstrap **a fresh Drupal 10 site** where Composer is available:

```bash
composer create-project 'drupal/recommended-project:^10' shareinvestor-drupal
cd shareinvestor-drupal
composer require 'drush/drush:^12'
# Install Drupal using its browser installer, or configure a database and run
# vendor/bin/drush site:install standard --db-url='mysql://USER:PASS@127.0.0.1/DB' -y
# Copy this submission's web/modules/custom/market_feed into this site's web/modules/custom/
vendor/bin/drush en basic_auth market_feed -y
vendor/bin/drush cr
```

`--db-url` and DB credentials are placeholders. Protect credentials and site settings; **do not** put them in a publicly accessible repository. Web server docroot should be Drupal's `web/`, not the repository root.

## API: `POST /api/market-feed/import`

Requires a **logged-in Drupal account** with `import market feed` and Drupal core HTTP Basic authentication over **HTTPS**. This example is a test-only transport; in production prefer short-lived service credentials/OAuth2 client credentials or mTLS with rotation, request signing, logging and rate limiting. The route is restricted to Basic Auth, not cookie auth, to avoid browser-session CSRF exposure. Send an `application/json` request of at most **16 MiB**:

```bash
curl --fail-with-body --user 'IMPORT_USER' \
  -H 'Content-Type: application/json' \
  --data-binary '@samples/import.json' \
  'https://YOUR-DRUPAL-HOST/api/market-feed/import'
```

`curl --user 'IMPORT_USER'` interactively prompts for the password rather than putting it in shell history. **Never** use HTTP in production.

**Example accepted result:**

```json
{
  "total": 3,
  "imported": 2,
  "skipped": 0,
  "duplicates": 1,
  "errors": [],
  "errors_truncated": 0
}
```

**Counts:** `imported` is the number of distinct successfully persisted symbols; valid duplicate rows are counted as `duplicates` and do not separately increase `imported`. `skipped` counts malformed rows. Thus `total = imported + duplicates + skipped`. Example rows contain AAPL twice; the last valid occurrence wins. Row errors are zero-indexed (matching array offsets); only the first 100 errors are shown, with the remainder in `errors_truncated`. This bounds error response size even for 50,000 bad rows.

**Validation:** `symbol` is uppercase, 1–20 ASCII ticker characters (`[A-Z0-9._-]`); `price` is nonnegative, `change` signed, both allow at most four meaningful decimal places and fit `DECIMAL(18,4)`; `volume` is a non-negative 64-bit signed integer. Missing/invalid fields skip only that row. Unknown extra keys are ignored. For very large monetary numbers, use decimal **strings** in JSON to avoid binary-floating-point rounding when the payload is decoded in PHP. JSON numbers are fine for the sample values.

Bad envelope/malformed JSON produces HTTP 400, wrong content type 415, oversize request 413, unauthenticated/unauthorized request is denied by Drupal route checks, storage failure produces 500. Database failure triggers an explicit transaction rollback. If cache invalidation itself fails **after** commit, the API may return 500 even though data was persisted; check the logs and purge the affected tag. The operation is idempotent for identical price rows, apart from `updated_at`.

## Part A design and trade-offs

- **Schema:** `hook_schema()` creates `market_feed_prices` with `symbol` as the primary/unique key, `DECIMAL(18,4)` prices, `BIGINT` volume, and UTC Unix `updated_at` timestamp.
- **Separation:** the controller checks the HTTP/JSON envelope and delegates to `MarketFeedImporterInterface`. The service validates, normalizes, deduplicates, batch-upserts, owns transaction boundaries, and invalidates cache after commit. Dependencies are injected via `market_feed.services.yml`.
- **Batching:** max 150 rows × 5 columns per statement = max 750 bound values, conservative for multiple SQL drivers. 50,000 *unique valid* rows require **334** multi-row statements rather than 50,000 individual statements. Entire-request transaction provides atomicity if the backend supports transactions; malformed records are isolated in validation, before writes. Full snapshot transaction can hold locks; use a staging table / queue workers in production (Part B).
- **Duplicate semantics:** last *valid* row for a normalized symbol wins within the same payload. Different concurrent requests are serialized by database locking as appropriate, but no vendor version exists in Part A; late-arriving older snapshots could overwrite newer data. Production versioning is covered in Part B.
- **Cache:** listing render array uses `market_feed_prices` tag, `user.permissions`, `url.query_args:symbol`, and `url.query_args.pagers:0` contexts. The cache tag is invalidated **after commit**. An invalid-only request does not clear caches. Listing page uses Drupal render arrays and a 50-row pager, without custom theme/CSS.
- **Access:** custom `view market feed` and restrictive `import market feed` permissions. `_user_is_logged_in` plus Basic Auth prevents anonymous imports. Use TLS; production machine-to-machine credential approach is described above and in Part B.
- **Intentional limits:** no queue worker, custom CSS or full automated Drupal test suite (out of scope in assignment). Maximum request is 16 MiB; an upstream gateway may impose a lower limit and must be configured appropriately. No full snapshot-source version control is attempted in Part A.

## Automated test strategy for Part A (design, not a required deliverable)

| Level | Test cases and assertions |
|---|---|
| Unit: normalizer/service | Good price/change/volume, invalid types, symbol normalization, 20-char limit, over-precision/overflow, duplicate-last-valid-wins, all-invalid, capped errors, 50k batching count, rollback on failing batch, cache tag called once after commit |
| Kernel: schema/database | Enable module -> table exists; upsert inserts/updates; unique key; decimal exactness; transaction rollback across multiple batches; supported driver behaviour |
| Functional HTTP | Basic Auth + permission allows import; anonymous/unauthorized denied; wrong method, malformed JSON, wrong content type, oversize body, JSON root invalid, 50k import; real route dependencies |
| Functional/cache | Two symbols, filtered and unfiltered pages; pager offsets; warm render cache, import updated symbol -> newly rendered current price; ensure user roles do not share protected page access |
| Performance | Measure 10k/50k import wall time, query count (~ceil(unique/150)), peak memory, DB locks, p95 query latency, and page render times. Confirm no per-row DB queries. |

Two optional local checks are included:

```bash
php scripts/offline_smoke.php
python3 scripts/sql_smoke.py
```

The PHP check uses in-memory fakes and exercises a synthetic 50,000-row payload and transaction rollback; the Python SQL check uses SQLite fixture semantics, **not** MySQL performance. Neither replaces a Drupal kernel/HTTP integration test or MySQL `EXPLAIN ANALYZE`. See `VERIFICATION_REPORT.md`.

## Parts B, C, and D: assumptions

- **B:** Read the proposed architecture in `ARCHITECTURE.md`; it assumes stable source snapshot/version metadata. The latest-price production schema differs from Part A because an exchange key is needed for potential symbol collisions.
- **C:** `corrected_portfolio.php` is self-service only, so the user identity must be trusted; historical close is not a live quote. The Part D `(symbol, trade_date)` primary key is assumed.
- **D:** SQL dialect MySQL 8+; June trade dates are inferred from observed records, with no exchange calendar/listing history supplied. See `SQL_SOLUTIONS.md` for limitations, alternative indexes and trade-offs.

## Time accounting

The instructions ask for **approximate time spent per part**. Enter your *actual* time here after reviewing and, if needed, live-testing the result. Do not misstate AI-assisted work time as your personal implementation time.

| Part | Estimated test allocation | Actual elapsed time (candidate to complete) |
|---|---:|---|
| A | 75 min | Not recorded |
| B | 30 min | Not recorded |
| C | 25 min | Not recorded |
| D | 20 min | Not recorded |

## Submission checklist

- [ ] Drupal kernel/HTTP checks completed in a real installation; actual outcomes recorded.
- [ ] Import service runs with target Drupal version/database driver.
- [ ] Permission and Basic Auth verified with unauthorized and authorized accounts.
- [ ] Cache invalidation verified with warmed cache.
- [ ] README time accounting filled accurately.
- [ ] No passwords, local `settings.php`, API keys, `.env` or vendor-produced PII in repository.
- [ ] Zip code + documents or share private Git link; submit to the HR-provided email by the agreed deadline.
