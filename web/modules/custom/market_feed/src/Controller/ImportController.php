<?php

declare(strict_types=1);

namespace Drupal\market_feed\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\market_feed\Contract\MarketFeedImporterInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Thin JSON adapter; business logic lives in the injected importer service.
 */
final class ImportController implements ContainerInjectionInterface {

  private const MAX_BYTES = 16777216;

  public function __construct(
    private readonly MarketFeedImporterInterface $importer,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('market_feed.importer'),
      $container->get('logger.channel.market_feed'),
    );
  }

  /**
   * Handles POST /api/market-feed/import.
   */
  public function import(Request $request): JsonResponse {
    $contentType = strtolower((string) $request->headers->get('Content-Type', ''));
    if (!preg_match('~^application/json(?:\s*;|$)~', $contentType)) {
      return $this->error('Content-Type must be application/json.', 415);
    }

    $declaredLength = $request->headers->get('Content-Length');
    if (is_string($declaredLength) && ctype_digit($declaredLength) &&
      (float) $declaredLength > self::MAX_BYTES) {
      return $this->error('JSON payload exceeds 16 MiB.', 413);
    }

    $body = $request->getContent();
    if (strlen($body) > self::MAX_BYTES) {
      return $this->error('JSON payload exceeds 16 MiB.', 413);
    }
    if (!str_starts_with(ltrim($body), '[')) {
      return $this->error('Top-level JSON value must be an array.', 400);
    }

    try {
      $rows = json_decode($body, TRUE, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
    }
    catch (\JsonException) {
      return $this->error('Malformed JSON payload.', 400);
    }

    if (!is_array($rows) || !array_is_list($rows)) {
      return $this->error('Top-level JSON value must be an array.', 400);
    }

    try {
      $result = $this->importer->import($rows);
    }
    catch (\Throwable $exception) {
      // The full exception is logged server-side, not disclosed to a caller.
      $this->logger->error('Market feed import failed: @message', [
        '@message' => $exception->getMessage(),
      ]);
      return $this->error('Import could not be completed. Check server logs; a committed write may require cache recovery.', 500);
    }

    return new JsonResponse($result->toArray(), 200, ['Cache-Control' => 'no-store']);
  }

  /**
   * Returns a consistent error response without exposing implementation data.
   */
  private function error(string $message, int $status): JsonResponse {
    return new JsonResponse(
      ['error' => ['message' => $message]],
      $status,
      ['Cache-Control' => 'no-store'],
    );
  }

}
