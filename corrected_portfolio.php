<?php

declare(strict_types=1);

/**
 * Part C: safe reference implementation for a *self-service* portfolio API.
 *
 * Assumed tables:
 * - portfolios(user_id, symbol, qty)
 * - historical_prices(symbol, trade_date, close, ...)
 * with historical_prices PRIMARY KEY(symbol, trade_date) as in Part D.
 *
 * IMPORTANT: $authenticatedUserId must come from a verified session/token,
 * not from $_GET, an HTTP body, or other user-controlled input.
 */
function get_portfolio(PDO $db, int $authenticatedUserId): array {
  if ($authenticatedUserId <= 0) {
    throw new InvalidArgumentException('Authenticated user ID is required.');
  }

  // One prepared SQL call, instead of 1 + N calls. The correlated MAX lookup
  // is efficient with the (symbol, trade_date) primary key; it returns the
  // latest available historical trading day, not necessarily yesterday.
  $sql = <<<'SQL'
SELECT
  p.symbol,
  p.qty,
  hp.close AS last_price,
  CASE
    WHEN hp.close IS NULL THEN NULL
    ELSE CAST(p.qty * hp.close AS DECIMAL(28, 4))
  END AS position_value
FROM portfolios AS p
LEFT JOIN historical_prices AS hp
  ON hp.symbol = p.symbol
 AND hp.trade_date = (
   SELECT MAX(previous.trade_date)
   FROM historical_prices AS previous
   WHERE previous.symbol = p.symbol
 )
WHERE p.user_id = :user_id
ORDER BY p.symbol
SQL;

  $statement = $db->prepare($sql);
  $statement->bindValue(':user_id', $authenticatedUserId, PDO::PARAM_INT);
  $statement->execute();

  // DECIMAL values are usually delivered by the PDO driver as strings;
  // do not cast financial values to float in an API serializer.
  return $statement->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Optional safe HTML rendering example: escape values at the output boundary.
 *
 * For Drupal, use themed render arrays or Twig instead of building HTML here.
 */
function render_portfolio_html(array $positions): string {
  $escape = static fn (mixed $value): string => htmlspecialchars(
    (string) $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8',
  );

  $html = '<ul>';
  foreach ($positions as $position) {
    $value = $position['position_value'] === NULL
      ? 'Price unavailable'
      : $escape($position['position_value']);
    $html .= '<li>' . $escape($position['symbol']) . ': ' . $value . '</li>';
  }
  return $html . '</ul>';
}
