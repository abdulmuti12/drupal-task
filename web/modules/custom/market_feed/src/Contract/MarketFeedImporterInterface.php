<?php

declare(strict_types=1);

namespace Drupal\market_feed\Contract;

use Drupal\market_feed\Service\ImportReport;

/**
 * Imports vendor price rows without exposing HTTP or persistence concerns.
 */
interface MarketFeedImporterInterface {

  /**
   * Imports an array of raw decoded JSON rows.
   *
   * @param array $rows
   *   Top-level JSON array. Individual rows may be malformed.
   *
   * @return \Drupal\market_feed\Service\ImportReport
   *   Consistent counts; duplicates represent superseded valid input rows.
   *
   * @throws \Throwable
   *   On a storage failure; no partial commit is reported.
   */
  public function import(array $rows): ImportReport;

}
