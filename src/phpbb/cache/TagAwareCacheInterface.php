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

namespace phpbb\cache;

/**
 * Tag-aware cache interface extending the base cache contract.
 *
 * Provides tag-based cache invalidation and a compute-or-cache helper.
 * Use this interface when grouped invalidation (e.g. "all forum data") is needed.
 */
interface TagAwareCacheInterface extends CacheInterface
{
	/**
	 * Store a value and associate it with one or more tags.
	 *
	 * @param string      $key   Cache key (will be namespace-prefixed by pool)
	 * @param mixed       $value Value to store
	 * @param int|null    $ttl   TTL in seconds; null means no expiry
	 * @param array<string> $tags Tag names for grouped invalidation
	 */
	public function setTagged(string $key, mixed $value, ?int $ttl = null, array $tags = []): bool;

	/**
	 * Invalidate all cache entries that were stored with the given tags.
	 *
	 * Implemented via tag-version counters: bumping the version effectively
	 * orphans all existing tag-namespaced entries without a physical scan.
	 *
	 * @param array<string> $tags Tag names to invalidate
	 */
	public function invalidateTags(array $tags): bool;

	/**
	 * Return the cached value for $key, or compute, cache and return it.
	 *
	 * @param string        $key     Cache key
	 * @param callable      $compute Callable that returns the value if cache misses
	 * @param int|null      $ttl     TTL in seconds; null means no expiry
	 * @param array<string> $tags    Tags to associate with the computed value
	 */
	public function getOrCompute(string $key, callable $compute, ?int $ttl = null, array $tags = []): mixed;
}
