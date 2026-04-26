<?php

/**
 *
 * This file is part of the phpBB4 "Meridian" package.
 *
 * @copyright (c) Irek Kubicki <phpbb@codebuilders.pl>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 * For full copyright and license information, please see
 * the docs/CREDITS.txt file.
 *
 */

declare(strict_types=1);

namespace phpbb\search\Service;

use phpbb\api\DTO\PaginationContext;
use phpbb\cache\TagAwareCacheInterface;
use phpbb\config\Contract\ConfigRepositoryInterface;
use phpbb\search\Contract\SearchDriverInterface;
use phpbb\search\Contract\SearchServiceInterface;
use phpbb\search\DTO\SearchQuery;
use phpbb\user\DTO\PaginatedResult;

final class SearchService implements SearchServiceInterface
{
	public function __construct(
		private readonly SearchDriverInterface $fullTextDriver,
		private readonly SearchDriverInterface $likeDriver,
		private readonly SearchDriverInterface $elasticsearchDriver,
		private readonly SearchDriverInterface $nativeDriver,
		private readonly ConfigRepositoryInterface $configRepository,
		private readonly TagAwareCacheInterface $cache,
	) {
	}

	/**
	 * @return PaginatedResult<\phpbb\search\DTO\SearchResultDTO>
	 */
	public function search(SearchQuery $query, PaginationContext $ctx): PaginatedResult
	{
		$ttl = (int) $this->configRepository->get('search_cache_ttl', '300');

		if ($ttl <= 0) {
			return $this->getDriver()->search($query, $ctx);
		}

		$cacheKey = 'search.' . md5(serialize([$query, $ctx]));

		return $this->cache->getOrCompute(
			$cacheKey,
			fn () => $this->getDriver()->search($query, $ctx),
			$ttl,
			['search'],
		);
	}

	private function getDriver(): SearchDriverInterface
	{
		return match ($this->configRepository->get('search_driver', 'fulltext')) {
			'like'          => $this->likeDriver,
			'elasticsearch' => $this->elasticsearchDriver,
			'native'        => $this->nativeDriver,
			default         => $this->fullTextDriver,
		};
	}
}
