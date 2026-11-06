<?php

declare(strict_types=1);

namespace Drupal\market_feed\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Connection;
use Drupal\market_feed\Contract\MarketFeedImporterInterface;

/**
 * Validates and upserts market prices using bounded multi-row statements.
 *
 * A single transaction guarantees that a database failure cannot produce a
 * partially committed snapshot. Validation errors do not abort other rows.
 * Valid duplicates use last-valid-occurrence-wins before any SQL is issued.
 */
final class MarketFeedImporter implements MarketFeedImporterInterface {

  private const MAX_REPORTED_ERRORS = 100;

  /**
   * Constructor.
   *
   * Batch size 150 * 5 fields = 750 values, deliberately small for drivers
   * with conservative parameter limits (including older SQLite builds).
   */
  public function __construct(
    private readonly Connection $database,
    private readonly CacheTagsInvalidatorInterface $cacheInvalidator,
    private readonly TimeInterface $time,
    private readonly int $batchSize = 150,
  ) {
    if ($batchSize < 1 || $batchSize > 1000) {
      throw new \InvalidArgumentException('Batch size must be between 1 and 1000.');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function import(array $rows): ImportReport {
    $normalized = [];
    $errors = [];
    $skipped = 0;
    $duplicates = 0;
    $errorsTruncated = 0;

    foreach ($rows as $index => $row) {
      $error = NULL;
      $record = $this->normalizeRow($row, $error);

      if ($record === NULL) {
        $skipped++;
        if (count($errors) < self::MAX_REPORTED_ERRORS) {
          $errors[] = [
            // The offset is zero-based to match JSON arrays.
            'row' => $index,
            'reason' => $error,
          ];
        }
        else {
          $errorsTruncated++;
        }
        continue;
      }

      $symbol = $record['symbol'];
      if (isset($normalized[$symbol])) {
        $duplicates++;
      }
      $normalized[$symbol] = $record;
    }

    $count = count($normalized);
    if ($count > 0) {
      $timestamp = $this->time->getCurrentTime();
      $transaction = $this->database->startTransaction();

      try {
        // Drupal's Upsert query supports multiple values() per execute(), so
        // this does NOT issue one database round trip per row.
        foreach (array_chunk(array_values($normalized), $this->batchSize) as $batch) {
          $upsert = $this->database->upsert('market_feed_prices')
            ->key('symbol')
            ->fields(['symbol', 'price', 'change_amount', 'volume', 'updated_at']);

          foreach ($batch as $record) {
            $upsert->values([
              'symbol' => $record['symbol'],
              'price' => $record['price'],
              'change_amount' => $record['change_amount'],
              'volume' => $record['volume'],
              'updated_at' => $timestamp,
            ]);
          }
          $upsert->execute();
        }
      }
      catch (\Throwable $exception) {
        // A transaction object is committed when it goes out of scope;
        // explicitly roll back before propagating any storage error.
        $transaction->rollBack();
        unset($transaction);
        throw $exception;
      }

      // The root transaction commits when its object is destroyed.
      unset($transaction);

      // Never invalidate before successful commit, or readers could reload
      // stale data during an uncommitted write.
      $this->cacheInvalidator->invalidateTags(['market_feed_prices']);
    }

    return new ImportReport(
      count($rows),
      $count,
      $skipped,
      $duplicates,
      $errors,
      $errorsTruncated,
    );
  }

  /**
   * Converts one row into safe, DB-ready values or a diagnostic.
   *
   * @param mixed $row
   *   One JSON value (expected to be a map).
   * @param string|null $error
   *   Populated with a non-sensitive validation message upon failure.
   *
   * @return array|null
   *   Normalized row, or NULL for malformed input.
   */
  private function normalizeRow(mixed $row, ?string &$error): ?array {
    if (!is_array($row) || array_is_list($row)) {
      $error = 'Expected an object with symbol, price, change and volume.';
      return NULL;
    }

    if (!isset($row['symbol']) || !is_string($row['symbol'])) {
      $error = 'symbol must be a string.';
      return NULL;
    }

    $symbol = strtoupper(trim($row['symbol']));
    if (!preg_match('/^[A-Z0-9][A-Z0-9._-]{0,19}$/D', $symbol)) {
      $error = 'symbol must be 1-20 ASCII ticker characters.';
      return NULL;
    }

    $price = $this->normalizeDecimal($row['price'] ?? NULL, FALSE);
    if ($price === NULL) {
      $error = 'price must be non-negative, with at most 4 decimal places.';
      return NULL;
    }

    $change = $this->normalizeDecimal($row['change'] ?? NULL, TRUE);
    if ($change === NULL) {
      $error = 'change must be a signed decimal with at most 4 decimal places.';
      return NULL;
    }

    $volume = $this->normalizeVolume($row['volume'] ?? NULL);
    if ($volume === NULL) {
      $error = 'volume must be a non-negative signed 64-bit integer.';
      return NULL;
    }

    return [
      'symbol' => $symbol,
      'price' => $price,
      'change_amount' => $change,
      'volume' => $volume,
    ];
  }

  /**
   * Avoids monetary calculations using binary floats.
   *
   * JSON floating-point numbers are converted by PHP before this point. A
   * vendor with extreme precision should send decimal strings and, in real
   * production, a JSON parser preserving decimal lexemes should be used.
   */
  private function normalizeDecimal(mixed $value, bool $allowNegative): ?string {
    if ((!is_string($value) && !is_int($value) && !is_float($value)) ||
      (is_float($value) && !is_finite($value))) {
      return NULL;
    }
    $text = (string) $value;
    if (!preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/D', $text)) {
      return NULL;
    }
    $isNegative = str_starts_with($text, '-');
    if ($isNegative && !$allowNegative) {
      return NULL;
    }

    $unsigned = ltrim($text, '-');
    [$whole, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
    $whole = ltrim($whole, '0');
    $whole = $whole === '' ? '0' : $whole;
    $fraction = rtrim($fraction, '0');
    if (strlen($whole) > 14 || strlen($fraction) > 4) {
      return NULL;
    }

    $allZero = $whole === '0' && $fraction === '';
    $sign = $isNegative && !$allZero ? '-' : '';
    return $sign . $whole . '.' . str_pad($fraction, 4, '0');
  }

  /**
   * Bounds volume explicitly before converting into a native int.
   */
  private function normalizeVolume(mixed $value): ?int {
    if (!is_string($value) && !is_int($value)) {
      return NULL;
    }
    $text = (string) $value;
    if (!preg_match('/^(0|[1-9][0-9]*)$/D', $text)) {
      return NULL;
    }
    $max = (string) PHP_INT_MAX;
    if (strlen($text) > strlen($max) ||
      (strlen($text) === strlen($max) && strcmp($text, $max) > 0)) {
      return NULL;
    }
    return (int) $text;
  }

}
