# Part B — Production Market Feed Architecture

**Context.** A vendor pushes one full snapshot per minute for each of four exchanges (about 8,000 symbols across the exchanges), with occasional intraday corrections. Consumers are Drupal pages, a mobile REST API peaking at about 200 requests/s, and a nightly end-of-day history job. This design extends, but does not claim to implement, Part A.

## 1. Ingestion and ordering

```text
Vendor push -> HTTPS ingestion gateway -> authenticate / validate envelope
            -> durable queue (partition by exchange+symbol)
            -> consumer workers -> canonical validation + deduplication
            -> SQL latest_prices + snapshot ledger (transaction)
            -> invalidate cache / publish committed-version event
                                      |
                                      +-> nightly EOD job -> historical_prices
```

Prefer **push** over minute polling because vendor owns delivery cadence, corrections can arrive immediately, and polling can miss intermediate updates. Authenticate vendor requests with signed payloads (HMAC/mTLS), enforce timestamps and replay windows, validate message size, rate limits, and authorization. Acknowledge only after the event has been durably accepted to the queue; return retryable errors for unavailable infrastructure. Keep raw events in short-lived object storage for replay/forensics, subject to data-licensing requirements.

Use a bounded, durable queue (e.g. SQS or RabbitMQ; Kafka is not mandatory). Partition by `(exchange, symbol)` to preserve order for a ticker; throttle workers when the vendor sends a burst. Give each snapshot an `exchange`, `snapshot_id`, `source_timestamp`, and an exchange-specific version/sequence when available. Store `(vendor, exchange, snapshot_id, part_id)` in an idempotency/inbox ledger with a unique constraint so an acknowledged retry cannot double-apply. Duplicate rows inside a snapshot follow a documented deterministic policy. A correction carries a later authoritative version and supersedes an earlier snapshot; an older late delivery must not overwrite new prices. In the database, compare authoritative versions before updating, not just `updated_at` (the server's ingestion time).

For multi-part snapshots, stage or track the expected symbol count and publish an exchange snapshot only after validation and completeness checks pass. A temporary incomplete snapshot must not replace the visible complete set. Queue workers bulk upsert in chunks inside bounded transactions. An unprocessable event goes to a dead-letter queue after retry limits, with the snapshot ID, error and alert.

## 2. Storage and 200 req/s read path

- `latest_prices(exchange, symbol)` as a composite primary key, plus `price DECIMAL(18,4)`, `change_amount DECIMAL(18,4)`, `volume BIGINT`, `source_version`, `source_timestamp`, `received_at`, `snapshot_id`, `is_stale`. Exchange is included because ticker symbols may collide across exchanges.
- `historical_prices(exchange, symbol, trade_date)` as the composite primary key, with OHLCV fields and `source_version`, `finalized_at`. Maintain a secondary index starting with `trade_date` for day-level extracts. Consider date partitioning by month/year only if measured retention/query patterns justify it; add lifecycle/archive policies.
- `ingestion_snapshots(exchange, snapshot_id)` records expected and accepted counts, checksum, state, errors, and publish time. The inbox table provides event idempotency.

The API reads from Redis cache keyed by `(exchange, symbol, published_version)` with short-lived fallback, or indexed SQL on cache miss. A read replica can absorb read pressure if measured; monitor replication lag. For 200 requests/s, indexed point reads and a small cache are plausible, but validate with p95/p99 load tests rather than assuming capacity. Implement batch symbol APIs to avoid N+1 client requests. Use connection pooling, response compression and explicit read timeouts. The nightly job snapshots the last **validated and published** trading-session close, upserts history idempotently, and supports subsequent official corrections by version; trading calendars and timezone are exchange-specific.

## 3. Caching and exact invalidation

- **Drupal render cache:** attach tag `market_feed_prices` (or granular `market_feed:exchange:<id>`) to the price listing and contexts `url.query_args:symbol`, pager context and `user.permissions`. On a successful database commit, invalidate the affected tags; do not invalidate before commit. Part A does exactly this using the broad tag.
- **Redis application cache:** publish a new version only after commit, use versioned keys or write-through updates plus old-key eviction. Versioned keys prevent an in-flight old read from repopulating the current key. Keep a modest TTL as a recovery fallback, not as the only freshness guarantee.
- **Reverse proxy/CDN:** public non-personalized read-only responses may be cached briefly with surrogate tags and purged on publication or version changes. Authenticated portfolio data is private/no-store. Add explicit `data_as_of`, `source_version` and `stale` fields or headers so cached responses do not imply live data when the feed is delayed. Avoid caching the POST ingestion response.

## 4. Failure detection and recovery

**Vendor down 20 minutes:** detect heartbeat/snapshot gaps against each exchange's trading calendar and the expected one-minute cadence; alert on lag and missing completeness. Keep last-known-good prices but mark them stale with `data_as_of`; mobile/Drupal should prominently distinguish last-known price from a current quote. On recovery request backfill or replay missing snapshots, deduplicate by snapshot ID/version, process in order, and publish only validated complete states. Track time to recovery and queue depth.

**Corrupt snapshot:** validate types, tickers, volume bounds, exchange membership, expected record count/checksum, outliers versus recent data, monotonic versions, and correction signatures. Quarantine suspicious snapshot before publication; alert operations and preserve raw payload for diagnosis. Continue serving the last-known-good snapshot. Correct via authenticated vendor replay/reprocessing from the ledger, preserving audit history. For corruption discovered after publication, restore the preceding validated version, purge caches, and re-run EOD corrections where necessary.

Metrics: ingress latency, published snapshot lag, rejected-row ratio, duplicates, queue age, DLQ depth, DB transaction failures, API p95/p99, cache hit ratio, and stale-feed duration.

## 5. Explicit trade-off

**Choice:** durable asynchronous queue + validated snapshot publication, rather than synchronous per-request SQL writes straight from the vendor. **Benefit:** absorbs bursts, supports retries/replay, prevents partial snapshots becoming visible, and separates vendor availability from database capacity. **Cost:** added infrastructure and eventual consistency, with more monitoring and operational work. **Rejected alternative:** synchronous ingestion is simpler and has lower ideal-case latency, but exposes the database directly to vendor bursts, can time out halfway through large snapshots, and weakens reliable recovery. A small deployment could initially use one queue and a few workers; complexity should increase only as measured demand requires.

**Assumptions:** one snapshot per minute is per exchange; ~8,000 symbols is the combined population, not 8,000 per exchange; the vendor can supply stable snapshot IDs and version information (if not, the gateway must assign a trusted ordering key and document ambiguity); the schema shown here is a production proposal distinct from Part A's required `symbol`-unique table.
