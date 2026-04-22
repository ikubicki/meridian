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

namespace phpbb\cache\backend;

/**
 * No-op cache backend — never stores anything.
 *
 * Used in test environments so that cache code paths are exercised without
 * writing to disk.  Every read returns null/false; every write returns true.
 */
class NullBackend implements CacheBackendInterface
{
	public function get(string $key): ?string
	{
		return null;
	}

	public function set(string $key, string $value, ?int $ttl = null): bool
	{
		return true;
	}

	public function delete(string $key): bool
	{
		return true;
	}

	public function has(string $key): bool
	{
		return false;
	}

	public function clear(string $prefix = ''): bool
	{
		return true;
	}

	public function getMultiple(array $keys): array
	{
		return array_fill_keys($keys, null);
	}
}
