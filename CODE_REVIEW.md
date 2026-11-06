# Part C — Merge Request Review: Portfolio Value Endpoint

**Overall review: Request changes (blocking).** Thanks for implementing the initial endpoint. There are multiple security and data-integrity issues to address before merge. The most important ones are injection and authorization. I have grouped the notes by severity and included a working reference below; happy to pair on the changes.

## Blocking comments

1. **Lines 84–86 — SQL injection / wrong source of identity.** `$_GET['user_id']` is concatenated directly into SQL; an attacker can alter the predicate. The function argument `$user_id` is silently ignored. Use a prepared statement and, for a self-service endpoint, derive the user ID from the authenticated session or token rather than a caller-controlled query parameter.
2. **Lines 84–86 — Broken object-level authorization (IDOR).** Even with prepared statements, accepting an arbitrary user ID lets one logged-in user query another's portfolio. Gate it with per-user access rules or restrict the function to the authenticated principal. Return 403/404 rather than leaking another user's data.
3. **Lines 90–93 — Second SQL injection path.** Concatenating `$row['symbol']` creates another unsafe query. Use bound parameters for all untrusted values, including values loaded from a database (which are not inherently safe).
4. **Lines 98–100 — Cross-site scripting.** Outputting `$_GET['user_id']` and the symbol directly in HTML permits injected markup/script. Render with Drupal render arrays or escape using `htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`. Never rely on a numeric-looking query string to be harmless.
5. **Lines 89–95 — N+1 queries.** For a portfolio with N positions, this implementation performs 1 + N database round trips. Use one indexed query joining each position to its latest historical close, and verify the query plan on realistic data.
6. **Line 94 — Missing-data crash / wrong valuations.** `mysqli_fetch_assoc()` returns `null`/`false` when history is absent or a query fails. Dereferencing `['close']` triggers warnings and the valuation becomes incorrect. Represent missing prices explicitly and do not silently treat them as zero.

## Major improvements

7. **Lines 84–100 — Split responsibilities.** Data fetching, authorization, valuation, and presentation are coupled. Return structured data from the domain/repository layer; let a controller or template render it. Easier to test, cache and reuse in API responses.
8. **Lines 85 and 90 — Check DB failures.** Both `mysqli_query()` calls can fail. Configure exceptions, catch at the HTTP boundary, log safely, and return a generic error to the caller.
9. **Line 86 — Avoid `SELECT *`.** Fetch only `symbol`, `qty` and price fields. Return typed values with documented semantics.
10. **Lines 94 and 100 — Precision and null handling.** Position value is financial data. Prefer database decimal arithmetic or a fixed-precision library rather than PHP float multiplication; keep decimal strings for serialization and never show a numeric value when the close is unknown.
11. **Line 92 — Latest close isn't always live price.** Confirm product semantics: the query uses the most recent *historical* close, not an intraday quote. If the endpoint claims to be live, switch to a current price source and include an as-of timestamp.
12. **Performance/index prerequisite.** Ensure `historical_prices` has `(symbol, trade_date)` indexing (present in Part D primary key) and `portfolios` supports `(user_id, symbol)`. Cap response size/paginate if portfolio size is unbounded; measure query plans rather than guessing.

## Suggested reference implementation

See [`corrected_portfolio.php`](corrected_portfolio.php). It uses PDO prepared statements, a trusted authenticated user ID, one database call, an indexed correlated lookup to the latest close per symbol, and SQL `DECIMAL` arithmetic. It returns data, not raw HTML, so the presentation layer can escape fields appropriately. It assumes the principal may only view their *own* portfolio; an admin-view use case needs a separate explicit permission check. Missing close is represented by `null`.

**Before re-requesting review:** please show a positive functional test, an unauthorized-user test, a quote-containing/malicious input test, a symbol without history, a portfolio with multiple holdings, and a query-plan comparison for 100+ positions.
