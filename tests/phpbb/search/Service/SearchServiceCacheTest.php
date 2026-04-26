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

namespace phpbb\Tests\search\Service;

use phpbb\api\DTO\PaginationContext;
use phpbb\cache\TagAwareCacheInterface;
use phpbb\config\Contract\ConfigRepositoryInterface;
use phpbb\search\Contract\SearchDriverInterface;
use phpbb\search\DTO\SearchQuery;
use phpbb\search\Service\SearchService;
use phpbb\user\DTO\PaginatedResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class SearchServiceCacheTest extends TestCase
{
	private SearchDriverInterface&MockObject $fullTextDriver;
	private SearchDriverInterface&MockObject $likeDriver;
	private SearchDriverInterface&MockObject $elasticsearchDriver;
	private SearchDriverInterface&MockObject $nativeDriver;
	private ConfigRepositoryInterface&MockObject $configRepository;
	private TagAwareCacheInterface&MockObject $cache;

	protected function setUp(): void
	{
		$this->fullTextDriver      = $this->createMock(SearchDriverInterface::class);
		$this->likeDriver          = $this->createMock(SearchDriverInterface::class);
		$this->elasticsearchDriver = $this->createMock(SearchDriverInterface::class);
		$this->nativeDriver        = $this->createMock(SearchDriverInterface::class);
		$this->configRepository    = $this->createMock(ConfigRepositoryInterface::class);
		$this->cache               = $this->createMock(TagAwareCacheInterface::class);
	}

	#[Test]
	public function it_returns_cached_result_on_second_call(): void
	{
		// Arrange
		$expected = new PaginatedResult(items: [], total: 0, page: 1, perPage: 25);
		$ctx      = new PaginationContext(page: 1, perPage: 25);
		$query    = new SearchQuery(keywords: 'hello');

		$this->configRepository->method('get')
			->willReturnMap([
				['search_cache_ttl', '300', '300'],
				['search_driver', 'fulltext', 'like'],
			]);

		$this->likeDriver->expects($this->once())
			->method('search')
			->willReturn($expected);

		$this->cache->expects($this->once())
			->method('getOrCompute')
			->willReturnCallback(fn ($key, $compute, $ttl, $tags) => $compute());

		$service = new SearchService(
			$this->fullTextDriver,
			$this->likeDriver,
			$this->elasticsearchDriver,
			$this->nativeDriver,
			$this->configRepository,
			$this->cache,
		);

		// Act
		$result = $service->search($query, $ctx);

		// Assert
		$this->assertSame($expected, $result);
	}

	#[Test]
	public function it_bypasses_cache_when_ttl_zero(): void
	{
		// Arrange
		$expected = new PaginatedResult(items: [], total: 0, page: 1, perPage: 25);
		$ctx      = new PaginationContext(page: 1, perPage: 25);
		$query    = new SearchQuery(keywords: 'hello');

		$this->configRepository->method('get')
			->willReturnMap([
				['search_cache_ttl', '300', '0'],
				['search_driver', 'fulltext', 'like'],
			]);

		$this->likeDriver->expects($this->once())
			->method('search')
			->willReturn($expected);

		$this->cache->expects($this->never())
			->method('getOrCompute');

		$service = new SearchService(
			$this->fullTextDriver,
			$this->likeDriver,
			$this->elasticsearchDriver,
			$this->nativeDriver,
			$this->configRepository,
			$this->cache,
		);

		// Act
		$result = $service->search($query, $ctx);

		// Assert
		$this->assertSame($expected, $result);
	}
}
