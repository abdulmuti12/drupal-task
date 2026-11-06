# Part D — SQL Solutions and Performance Notes

**Dialect:** MySQL 8.0+ (CTEs and window functions supported). The given table uses `PRIMARY KEY (symbol, trade_date)`. Sample dates in these statements are illustrative, not a requirement to choose a specific evaluation date. All parameters in application code must be bound.

## D1. Day-over-day movers for an arbitrary trading date

```sql
WITH prices_for_date AS (
  SELECT
    current.symbol,
    current.trade_date,
    current.close AS current_close,
    (
      SELECT previous.close
      FROM historical_prices AS previous
      WHERE previous.symbol = current.symbol
        AND previous.trade_date < current.trade_date
      ORDER BY previous.trade_date DESC
      LIMIT 1
    ) AS previous_close
  FROM historical_prices AS current
  WHERE current.trade_date = :trade_date
)
SELECT
  symbol,
  current_close AS `close`,
  previous_close,
  ROUND(100.0 * (current_close - previous_close)
    / NULLIF(previous_close, 0), 4) AS pct_change
FROM prices_for_date
ORDER BY ABS(100.0 * (current_close - previous_close)
    / NULLIF(previous_close, 0)) DESC, symbol;
```

**Why correct:** The correlated lookup finds the most recent row *for that symbol*, regardless of weekends, public holidays, or suspensions. It doesn't subtract a calendar day. No previous row -> `previous_close = NULL`; prior close 0 -> `pct_change = NULL` (division by zero is undefined). Those symbols remain visible because the requirement says *every symbol*. The existing `(symbol, trade_date)` PK supports the descending previous-row lookup. At hundreds of millions of rows, consider `CREATE INDEX idx_trade_date_symbol_close ON historical_prices (trade_date, symbol, close)` for efficiently locating the specified day's symbols. Inspect `EXPLAIN ANALYZE` and real workloads before adding a potentially large covering index.

A `LAG(close) OVER (PARTITION BY symbol ORDER BY trade_date)` solution is also valid but may require scanning a large share of history if implemented naively; the indexed correlated lookup generally limits work to the selected day's symbols.

## D2. Gap detection within June 2026

```sql
WITH june_dates AS (
  SELECT DISTINCT trade_date
  FROM historical_prices
  WHERE trade_date >= '2026-06-01'
    AND trade_date < '2026-07-01'
),
june_symbols AS (
  SELECT DISTINCT symbol
  FROM historical_prices
  WHERE trade_date >= '2026-06-01'
    AND trade_date < '2026-07-01'
)
SELECT s.symbol, d.trade_date AS missing_trade_date
FROM june_symbols AS s
CROSS JOIN june_dates AS d
LEFT JOIN historical_prices AS h
  ON h.symbol = s.symbol
 AND h.trade_date = d.trade_date
WHERE h.symbol IS NULL
ORDER BY s.symbol, d.trade_date;
```

**Assumptions and limitations:** With only the supplied schema, there is no exchange identifier, official trading calendar, or listing/suspension period. Thus "trading date" means *any day when at least one symbol has a record*. A symbol counts as in scope if it has at least one June observation. The query flags likely missing records, but also flags legitimate suspensions, IPOs, delistings, and exchange-specific holidays. For production, join a per-exchange trading-calendar table and `symbols(active_from, active_to, exchange)` with suspension metadata; flag gaps only on expected dates for that specific symbol. Work for June is roughly symbols × observed dates, not 500M rows, assuming a suitable date-leading index.

## D3. Slow query on 500 million historical rows

**Given:**

```sql
SELECT * FROM historical_prices
WHERE YEAR(trade_date) = 2026
  AND symbol LIKE '%BANK%'
ORDER BY volume DESC
LIMIT 100;
```

**Why it's slow:**

1. `YEAR(trade_date)` is a function of the indexed column, preventing a normal B-tree range seek on `trade_date` (without a matching expression index).
2. `symbol LIKE '%BANK%'` starts with a wildcard, so an ordinary B-tree on `symbol` cannot efficiently seek by a prefix. MySQL may scan a very large fraction of the table.
3. `SELECT *` retrieves unused columns and defeats covering indexes.
4. `ORDER BY volume DESC LIMIT 100` may need an expensive sort/filesort on the filtered records. The declared PK `(symbol, trade_date)` does not automatically provide useful ordering by volume.
5. 500M rows magnifies I/O, buffer-pool and temporary-table costs.

**Immediate rewrite (semantically equivalent for the filters, selecting only needed columns):**

```sql
SELECT symbol, trade_date, close, volume
FROM historical_prices
WHERE trade_date >= '2026-01-01'
  AND trade_date < '2027-01-01'
  AND symbol LIKE '%BANK%'
ORDER BY volume DESC
LIMIT 100;
```

The date range is sargable, but `%BANK%` and sorting remain problematic. Proposed follow-ups, conditional on measurements:

- Add `INDEX idx_hp_date_symbol (trade_date, symbol)` if date-range reports are frequent and `EXPLAIN ANALYZE` shows the date filter is selective. A wider covering index may help only if the extra write/storage cost is justified.
- If `%BANK%` represents an industry/classification rather than an arbitrary substring, **normalize the classification** into a `symbols(symbol, sector)` dimension with indexes and join by an equality predicate. If substring search is genuinely required, use a dedicated n-gram/search index or precomputed symbol-matching table; standard B-tree prefix indexes cannot fix a leading wildcard. A MySQL FULLTEXT index is not a drop-in replacement for arbitrary substring matching.
- With a strong prefiltered symbol set, benchmark an index starting `(symbol, trade_date)` against a date-leading index. No single composite B-tree optimizes both broad substring matching and `ORDER BY volume` without selective predicates.
- Consider monthly/yearly partitioning by trade date for pruning and retention, but note **MySQL's unique/primary key requirements for partitioned tables**: partition keys must be included in every unique key. The supplied PK includes `trade_date`, which can fit date-based partitioning, but the physical design and migrations still require review.
- Run `EXPLAIN ANALYZE` on representative data before/after each change; record rows examined, filtered cardinality, index usage, filesort, query latency p95 and write impact. Don't propose indexes solely from intuition.

**Trade-off:** Extra indexes/storage increase import and maintenance costs. Prefer the smallest set that solves measured query patterns. Keep the original required schema for Part D as specified; any additional dimension/partition proposal is an explicit production extension, not an assumed column in the given table.
