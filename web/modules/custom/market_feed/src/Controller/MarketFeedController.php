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

  private const PAGE_SIZE = 50;

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
    // Reject invalid filters without interpolating user input into SQL.
    if ($symbol !== '' && !preg_match('/^[A-Z0-9][A-Z0-9._-]{0,19}$/D', $symbol)) {
      $symbol = '__INVALID_FILTER__';
    }

    $query = $this->database->select('market_feed_prices', 'mf')
      ->fields('mf', ['symbol', 'price', 'change_amount', 'volume', 'updated_at'])
      ->orderBy('symbol', 'ASC')
      ->extend(PagerSelectExtender::class)
      ->limit(self::PAGE_SIZE);

    if ($symbol !== '') {
      $query->condition('symbol', $symbol);
    }

    $rows = [];
    foreach ($query->execute() as $record) {
      $rows[] = [
        $record->symbol,
        $record->price,
        $record->change_amount,
        $record->volume,
        gmdate('Y-m-d H:i:s', (int) $record->updated_at) . ' UTC',
      ];
    }

    $url = Url::fromRoute('market_feed.page')->toString();
    return [
      '#type' => 'container',
      'filter' => [
        '#type' => 'inline_template',
        '#template' => '<form action="{{ action }}" method="get"><label for="market-feed-symbol">{{ label }}</label> <input type="search" id="market-feed-symbol" name="symbol" maxlength="20" value="{{ value }}" /> <button type="submit">{{ submit }}</button> <a href="{{ reset }}">{{ clear }}</a></form>',
        '#context' => [
          'action' => $url,
          'label' => t('Symbol'),
          'value' => $symbol === '__INVALID_FILTER__' ? '' : $symbol,
          'submit' => t('Filter'),
          'reset' => $url,
          'clear' => t('Clear'),
        ],
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [t('Symbol'), t('Price'), t('Change'), t('Volume'), t('Updated at')],
        '#rows' => $rows,
        '#empty' => t('No prices found.'),
      ],
      'pager' => [
        '#type' => 'pager',
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
