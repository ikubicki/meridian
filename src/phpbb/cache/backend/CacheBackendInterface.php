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
 * Low-level binary-safe storage backend for the cache layer.
 *
 * Implementations deal only with string blobs; serialisation is handled
 * by the Marshaller layer above. Keys are pre-hashed by the backend to
 * produce filesystem- or storage-safe identifiers.
 */
interface CacheBackendInterface
{
	/**
	 * Retrieve a stored blob, or null if not found / expired.
	 */
	public function get(string $key): ?string;

	/**
	 * Persist a blob.
	 *
	 * @param string   $key   Storage key
	 * @param string   $value Serialised blob
	 * @param int|null $ttl   Seconds until expiry; null means no expiry
	 */
	public function set(string $key, string $value, ?int $ttl = null): bool;

	/**
	 * Remove a single entry. Returns true even if the key did not exist.
	 */
	public function delete(string $key): bool;

	/**
	 * Check existence without fetching the value; must honour TTL.
	 */
	public function has(string $key): bool;

	/**
	 * Remove all entries whose key starts with $prefix, or all entries when $prefix is ''.
	 */
	public function clear(string $prefix = ''): bool;

	/**
	 * Retrieve multiple blobs in a single call.
	 *
	 * Returns a map of key => blob|null.  Missing / expired entries are null.
	 *
	 * @param  array<string>         $keys
	 * @return array<string, string|null>
	 */
	public function getMultiple(array $keys): array;
}
