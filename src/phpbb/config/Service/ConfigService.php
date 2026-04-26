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

namespace phpbb\config\Service;

use phpbb\cache\CachePoolFactoryInterface;
use phpbb\cache\TagAwareCacheInterface;
use phpbb\config\Contract\ConfigRepositoryInterface;
use phpbb\config\Contract\ConfigServiceInterface;

final class ConfigService implements ConfigServiceInterface
{
	private readonly TagAwareCacheInterface $cache;

	public function __construct(
		private readonly ConfigRepositoryInterface $repository,
		CachePoolFactoryInterface $cacheFactory,
		private readonly int $cacheTtl = 3600,
	) {
		$this->cache = $cacheFactory->getPool('config');
	}

	public function get(string $key, string $default = ''): string
	{
		return $this->cache->getOrCompute("config:{$key}", fn () => $this->repository->get($key, $default), $this->cacheTtl, ['config']);
	}

	public function getAll(): array
	{
		return $this->cache->getOrCompute('config:all', fn () => $this->repository->getAll(), $this->cacheTtl, ['config']);
	}

	public function getInt(string $key, int $default = 0): int
	{
		return (int) $this->get($key, (string) $default);
	}

	public function getBool(string $key, bool $default = false): bool
	{
		$v = $this->get($key, $default ? '1' : '0');

		return $v !== '' && $v !== '0';
	}

	public function set(string $key, string $value, bool $isDynamic = false): void
	{
		$this->repository->set($key, $value, $isDynamic);
		$this->cache->invalidateTags(['config']);
	}

	public function increment(string $key, int $by = 1): void
	{
		$this->repository->increment($key, $by);
		$this->cache->invalidateTags(['config']);
	}

	public function delete(string $key): int
	{
		$affected = $this->repository->delete($key);
		$this->cache->invalidateTags(['config']);

		return $affected;
	}
}
