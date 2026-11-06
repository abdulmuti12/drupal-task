<?php

declare(strict_types=1);

/**
 * Standalone importer orchestration smoke test, WITHOUT Drupal bootstrap.
 *
 * Implements minimal fakes for three Drupal service interfaces. This cannot
 * prove real Drupal Schema/Upsert compatibility or HTTP access controls.
 * Run: php scripts/offline_smoke.php
 */
namespace Drupal\Component\Datetime {
  interface TimeInterface {
    public function getCurrentTime();
  }
}

namespace Drupal\Core\Cache {
  interface CacheTagsInvalidatorInterface {
    public function invalidateTags(array $tags);
  }
}

namespace Drupal\Core\Database {
  class Connection {
    public array $persisted = [];
    public array $pending = [];
    public int $statements = 0;
    public int $throwOnStatement = 0;

    public function startTransaction(): FakeTransaction {
      return new FakeTransaction($this);
    }

    public function upsert(string $table): FakeUpsert {
      if ($table !== 'market_feed_prices') {
        throw new \LogicException('Unexpected table.');
      }
      return new FakeUpsert($this);
    }
  }

  class FakeTransaction {
    private bool $rolledBack = FALSE;

    public function __construct(private Connection $db) {}

    public function rollBack(): void {
      $this->rolledBack = TRUE;
      $this->db->pending = [];
    }

    public function __destruct() {
      if (!$this->rolledBack) {
        foreach ($this->db->pending as $key => $record) {
          $this->db->persisted[$key] = $record;
        }
        $this->db->pending = [];
      }
    }
  }

  class FakeUpsert {
    private array $values = [];

    public function __construct(private Connection $db) {}

    public function key(string $key): static {
      if ($key !== 'symbol') {
        throw new \LogicException('Bad key.');
      }
      return $this;
    }

    public function fields(array $fields): static {
      if (count($fields) !== 5) {
        throw new \LogicException('Bad fields.');
      }
      return $this;
    }

    public function values(array $values): static {
      $this->values[] = $values;
      return $this;
    }

    public function execute(): int {
      $this->db->statements++;
      if ($this->db->statements === $this->db->throwOnStatement) {
        throw new \RuntimeException('Synthetic database failure.');
      }
      foreach ($this->values as $row) {
        $this->db->pending[$row['symbol']] = $row;
      }
      return count($this->values);
    }
  }
}

namespace {
  use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
  use Drupal\Core\Database\Connection;
  use Drupal\market_feed\Service\MarketFeedImporter;
  use Drupal\Component\Datetime\TimeInterface;

  require __DIR__ . '/../web/modules/custom/market_feed/src/Service/ImportReport.php';
  require __DIR__ . '/../web/modules/custom/market_feed/src/Contract/MarketFeedImporterInterface.php';
  require __DIR__ . '/../web/modules/custom/market_feed/src/Service/MarketFeedImporter.php';

  class FakeCacheInvalidator implements CacheTagsInvalidatorInterface {
    public array $tags = [];

    public function invalidateTags(array $tags): void {
      $this->tags[] = $tags;
    }
  }

  class FakeTime implements TimeInterface {
    public function getCurrentTime(): int {
      return 1793930400;
    }
  }

  function must(bool $condition, string $message): void {
    if (!$condition) {
      throw new \RuntimeException('FAIL: ' . $message);
    }
  }

  $db = new Connection();
  $cache = new FakeCacheInvalidator();
  $importer = new MarketFeedImporter($db, $cache, new FakeTime(), 150);

  $payload = json_decode(file_get_contents(__DIR__ . '/../samples/import.json'), TRUE, 512, JSON_THROW_ON_ERROR);
  $report = $importer->import($payload);
  must($report->total === 3 && $report->imported === 2, 'sample counts');
  must($report->duplicates === 1 && $report->skipped === 0, 'sample duplicates');
  must($db->persisted['AAPL']['price'] === '233.0000', 'last AAPL occurrence wins');
  must($db->persisted['MSFT']['price'] === '415.6000', 'MSFT decimal price');
  must($db->statements === 1, 'sample must make one batch statement');
  must($cache->tags === [['market_feed_prices']], 'cache invalidated after commit');

  $invalid = json_decode(file_get_contents(__DIR__ . '/../samples/malformed.json'), TRUE, 512, JSON_THROW_ON_ERROR);
  $report = $importer->import($invalid);
  must($report->imported === 2 && $report->skipped === 2 && $report->duplicates === 1, 'malformed rows isolated');
  must(count($report->errors) === 2, 'two errors reported');
  must($db->persisted['AAPL']['price'] === '233.5000', 'last valid row retained');
  must($db->persisted['GOOG']['price'] === '167.1250', 'four-place normalization');

  $fresh = new Connection();
  $freshCache = new FakeCacheInvalidator();
  $many = new MarketFeedImporter($fresh, $freshCache, new FakeTime(), 150);
  $rows = [];
  for ($i = 0; $i < 500; $i++) {
    $rows[] = ['symbol' => sprintf('S%05d', $i), 'price' => 12.25, 'change' => 1, 'volume' => 1];
  }
  $report = $many->import($rows);
  must($report->imported === 500 && $fresh->statements === 4, '500 rows in four multi-value statements');
  must(count($fresh->persisted) === 500, 'transaction committed all rows');

  $failed = new Connection();
  $failed->throwOnStatement = 2;
  $failedCache = new FakeCacheInvalidator();
  $failureImporter = new MarketFeedImporter($failed, $failedCache, new FakeTime(), 150);
  $didThrow = FALSE;
  try {
    $failureImporter->import($rows);
  }
  catch (\RuntimeException) {
    $didThrow = TRUE;
  }
  must($didThrow, 'synthetic DB exception propagated');
  must($failed->persisted === [] && $failed->pending === [], 'transaction explicitly rolled back');
  must($failedCache->tags === [], 'no cache invalidation on rollback');

  $onlyBad = new Connection();
  $onlyBadCache = new FakeCacheInvalidator();
  $badImporter = new MarketFeedImporter($onlyBad, $onlyBadCache, new FakeTime());
  $report = $badImporter->import([['symbol' => '<bad>', 'price' => 1, 'change' => 1, 'volume' => 1]]);
  must($report->imported === 0 && $report->skipped === 1, 'invalid-only request');
  must($onlyBad->statements === 0 && $onlyBadCache->tags === [], 'invalid-only request skips SQL/cache');

  $largeDb = new Connection();
  $largeCache = new FakeCacheInvalidator();
  $largeImporter = new MarketFeedImporter($largeDb, $largeCache, new FakeTime(), 150);
  $largeRows = [];
  for ($i = 0; $i < 50000; $i++) {
    $largeRows[] = [
      'symbol' => sprintf('T%05d', $i),
      'price' => 123.45,
      'change' => -0.25,
      'volume' => 10000 + $i,
    ];
  }
  $largeReport = $largeImporter->import($largeRows);
  must($largeReport->imported === 50000, '50k unique rows imported');
  must($largeDb->statements === 334, '50k rows processed in 334 SQL batches');
  must(count($largeDb->persisted) === 50000, 'all 50k mock rows committed');
  must($largeCache->tags === [['market_feed_prices']], 'one invalidation for 50k rows');

  echo "PASS: sample import, duplicates, malformed rows, 500/50k batching, rollback, invalid-only input.\n";
}
