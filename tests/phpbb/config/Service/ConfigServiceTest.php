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

namespace phpbb\Tests\config\Service;

use phpbb\cache\CachePoolFactoryInterface;
use phpbb\cache\TagAwareCacheInterface;
use phpbb\config\Contract\ConfigRepositoryInterface;
use phpbb\config\Service\ConfigService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConfigServiceTest extends TestCase
{
	private ConfigRepositoryInterface&MockObject $repo;
	private CachePoolFactoryInterface&MockObject $cacheFactory;
	private TagAwareCacheInterface&MockObject $cache;
	private ConfigService $service;

	protected function setUp(): void
	{
		$this->repo        = $this->createMock(ConfigRepositoryInterface::class);
		$this->cacheFactory = $this->createMock(CachePoolFactoryInterface::class);
		$this->cache       = $this->createMock(TagAwareCacheInterface::class);

		$this->cacheFactory->method('getPool')->with('config')->willReturn($this->cache);

		$this->service = new ConfigService($this->repo, $this->cacheFactory);
	}

	#[Test]
	public function it_get_returns_cached_value_without_calling_repository(): void
	{
		$this->cache->expects($this->once())
			->method('getOrCompute')
			->willReturn('cached_value');

		$this->repo->expects($this->never())->method('get');

		$result = $this->service->get('some_key');

		$this->assertSame('cached_value', $result);
	}

	#[Test]
	public function it_get_on_cache_miss_calls_repository_and_caches_result(): void
	{
		$this->repo->expects($this->once())
			->method('get')
			->with('some_key', '')
			->willReturn('db_value');

		$this->cache->method('getOrCompute')
			->willReturnCallback(fn ($key, $compute, $ttl, $tags) => $compute());

		$result = $this->service->get('some_key');

		$this->assertSame('db_value', $result);
	}

	#[Test]
	public function it_getInt_casts_string_value_to_int(): void
	{
		$this->cache->method('getOrCompute')
			->willReturn('42');

		$result = $this->service->getInt('some_key');

		$this->assertSame(42, $result);
	}

	#[Test]
	public function it_getBool_returns_false_for_empty_string_and_zero(): void
	{
		$this->cache->method('getOrCompute')
			->willReturnOnConsecutiveCalls('', '0');

		$this->assertFalse($this->service->getBool('key_empty'));
		$this->assertFalse($this->service->getBool('key_zero'));
	}

	#[Test]
	public function it_getBool_returns_true_for_non_zero_string(): void
	{
		$this->cache->method('getOrCompute')
			->willReturn('1');

		$this->assertTrue($this->service->getBool('some_key'));
	}

	#[Test]
	public function it_set_calls_repo_set_then_invalidates_cache_tags(): void
	{
		$this->repo->expects($this->once())
			->method('set')
			->with('some_key', 'some_value', false);

		$this->cache->expects($this->once())
			->method('invalidateTags')
			->with(['config']);

		$this->service->set('some_key', 'some_value');
	}

	#[Test]
	public function it_delete_calls_repo_delete_then_invalidates_cache_tags(): void
	{
		$this->repo->expects($this->once())
			->method('delete')
			->with('some_key')
			->willReturn(1);

		$this->cache->expects($this->once())
			->method('invalidateTags')
			->with(['config']);

		$result = $this->service->delete('some_key');

		$this->assertSame(1, $result);
	}

	#[Test]
	public function it_increment_calls_repo_increment_then_invalidates_cache_tags(): void
	{
		$this->repo->expects($this->once())
			->method('increment')
			->with('post_count', 1);

		$this->cache->expects($this->once())
			->method('invalidateTags')
			->with(['config']);

		$this->service->increment('post_count');
	}

	#[Test]
	public function it_getAll_returns_cached_value_without_calling_repository(): void
	{
		$this->cache->expects($this->once())
			->method('getOrCompute')
			->willReturn(['version' => '3.3.0']);

		$this->repo->expects($this->never())->method('getAll');

		$result = $this->service->getAll();

		$this->assertSame(['version' => '3.3.0'], $result);
	}

	#[Test]
	public function it_getAll_on_cache_miss_calls_repository_and_caches_result(): void
	{
		$this->repo->expects($this->once())
			->method('getAll')
			->willReturn(['board_name' => 'TestBoard']);

		$this->cache->method('getOrCompute')
			->willReturnCallback(fn ($key, $compute, $ttl, $tags) => $compute());

		$result = $this->service->getAll();

		$this->assertSame(['board_name' => 'TestBoard'], $result);
	}

	#[Test]
	public function it_getBool_returns_true_for_arbitrary_non_zero_non_empty_string(): void
	{
		$this->cache->method('getOrCompute')
			->willReturn('yes');

		$this->assertTrue($this->service->getBool('some_key'));
	}
}
