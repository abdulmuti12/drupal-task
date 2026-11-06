<?php

declare(strict_types=1);

namespace Drupal\market_feed\Service;

/**
 * Immutable result from one import request.
 */
final class ImportReport {

  /**
   * @param int $total
   *   Count of supplied raw rows.
   * @param int $imported
   *   Count of distinct symbols persisted successfully.
   * @param int $skipped
   *   Count of invalid rows.
   * @param int $duplicates
   *   Count of valid superseded rows for repeated symbols.
   * @param array $errors
   *   Bounded list of invalid-row diagnostics.
   * @param int $errorsTruncated
   *   Number of invalid-row diagnostics not included in the response.
   */
  public function __construct(
    public readonly int $total,
    public readonly int $imported,
    public readonly int $skipped,
    public readonly int $duplicates,
    public readonly array $errors,
    public readonly int $errorsTruncated,
  ) {}

  /**
   * Response-safe result representation.
   */
  public function toArray(): array {
    return [
      'total' => $this->total,
      'imported' => $this->imported,
      'skipped' => $this->skipped,
      'duplicates' => $this->duplicates,
      'errors' => $this->errors,
      'errors_truncated' => $this->errorsTruncated,
    ];
  }

}
