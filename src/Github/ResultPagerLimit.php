<?php

namespace Custom\Github;

use Generator;
use Github\Api\AbstractApi;
use Github\Client;
use Github\ResultPager;

/**
 * Class ResultPagerLimit adding a page limit to fetchAll.
 *
 * @package Custom\Github
 */
class ResultPagerLimit extends ResultPager {

  /**
   * The maximum number of pages to fetch. FALSE for no limit.
   *
   * @var bool|int $pageLimit
   */
  private $pageLimit;

  public function __construct(Client $client, int $perPage = null, $pageLimit = FALSE) {
    parent::__construct($client, $perPage);
    $this->pageLimit = $pageLimit;
  }

  /**
   * {@inheritdoc}
   */
  public function fetchAll(AbstractApi $api, string $method, array $parameters = []): array
  {
    return iterator_to_array($this->fetchAllLazy($api, $method, $parameters));
  }

  /**
   * {@inheritdoc}
   */
  public function fetchAllLazy(AbstractApi $api, string $method, array $parameters = []): Generator {
    $result = $this->fetch($api, $method, $parameters);
    $count = 1;

    foreach ($result['items'] ?? $result as $key => $item) {
      if (is_string($key)) {
        yield $key => $item;
      } else {
        yield $item;
      }
    }

    while ((!$this->pageLimit || $this->pageLimit > $count) && $this->hasNext()) {
      $count++;
      $result = $this->fetchNext();

      foreach ($result['items'] ?? $result as $key => $item) {
        if (is_string($key)) {
          yield $key => $item;
        } else {
          yield $item;
        }
      }
    }
  }
}