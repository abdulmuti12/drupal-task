<?php

declare(strict_types=1);

namespace Drupal\market_feed\Controller;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\PagerSelectExtender;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Displays the latest market prices with a query-parameter symbol filter.
 */
final class MarketFeedController implements ContainerInjectionInterface {

  public function __construct(private readonly Connection $database) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('database'));
  }

  /**
   * Builds a cache-aware listing with permission-gated route access.
   */
  public function page(Request $request): array {
    $raw = $request->query->get('symbol', '');
    $symbol = is_string($raw) ? strtoupper(trim($raw)) : '';
    $invalid_symbol = $symbol !== '' && !preg_match('/^[A-Z0-9][A-Z0-9._-]{0,19}$/D', $symbol);

    $query = $this->database->select('market_feed_prices', 'mf')
      ->fields('mf', ['symbol', 'price', 'change_amount', 'volume', 'updated_at'])
      ->orderBy('symbol', 'ASC')
      ->extend(PagerSelectExtender::class);

    if ($invalid_symbol) {
      $query->alwaysFalse();
    }
    elseif ($symbol !== '') {
      $query->condition('symbol', $symbol);
    }

    $totalSymbols = (int) $query->getCountQuery()->execute()->fetchField();

    $rows = [];
    foreach ($query->execute() as $record) {
      $change = (string) $record->change_amount;
      $direction = match (TRUE) {
        $change > 0 => 'up',
        $change < 0 => 'down',
        default => 'flat',
      };
      $timestamp = (int) $record->updated_at;
      $rows[] = [
        'symbol' => $record->symbol,
        'price' => $record->price,
        'change' => $direction === 'up' ? '+' . $change : $change,
        'direction' => $direction,
        'volume' => preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', (string) $record->volume),
        'updated_iso' => gmdate('Y-m-d\TH:i:s\Z', $timestamp),
        'updated_date' => gmdate('Y-m-d', $timestamp),
        'updated_time' => gmdate('H:i:s', $timestamp),
      ];
    }

    return [
      '#theme' => 'market_feed_listing',
      '#action' => Url::fromRoute('market_feed.page')->toString(),
      '#symbol' => $symbol,
      '#invalid_symbol' => $invalid_symbol,
      '#rows' => $rows,
      '#count' => $totalSymbols,
      '#pager' => [
        '#type' => 'pager',
      ],
      '#attached' => [
        'library' => ['market_feed/listing'],
      ],
      '#cache' => [
        'keys' => ['market_feed', 'page'],
        'tags' => ['market_feed_prices'],
        // Query filters and pagination must not share cached output.
        'contexts' => ['url.query_args:symbol', 'url.query_args.pagers:0', 'user.permissions'],
      ],
    ];
  }

}
