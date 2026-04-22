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

use Psr\SimpleCache\CacheInterface as PsrCacheInterface;

/**
 * phpBB cache interface — extends PSR-16 SimpleCache.
 *
 * All cache consumers depend on this interface or TagAwareCacheInterface.
 * Prefer TagAwareCacheInterface when tag-based invalidation is needed.
 */
interface CacheInterface extends PsrCacheInterface
{
	// Inherits PSR-16 contract:
	// get(string $key, mixed $default = null): mixed
	// set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
	// delete(string $key): bool
	// clear(): bool
	// has(string $key): bool
	// getMultiple(iterable $keys, mixed $default = null): iterable
	// setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
	// deleteMultiple(iterable $keys): bool
}
