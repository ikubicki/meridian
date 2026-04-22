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

use phpbb\cache\backend\CacheBackendInterface;
use phpbb\cache\marshaller\MarshallerInterface;

/**
 * Tag-version counter store.
 *
 * Implements logical cache invalidation via monotonic counters.  Each tag has
 * a version number stored in the backend under the key `__tags__:{tag}`.
 * When a tag is invalidated its counter is incremented; any cached values that
 * embedded the old counter are then naturally "stale" and get evicted on read.
 *
 * This avoids expensive key-scans while staying correct for all backends.
 */
class TagVersionStore
{
	private const KEY_PREFIX = '__tags__:';

	public function __construct(
		private readonly CacheBackendInterface $backend,
		private readonly MarshallerInterface $marshaller,
	) {
	}

	/**
	 * Return the current version numbers for the given tags.
	 *
	 * Missing tags are treated as version 0 and lazily created on first write.
	 *
	 * @param  array<string> $tags
	 * @return array<string, int>  map of tag => version
	 */
	public function getVersions(array $tags): array
	{
		if ($tags === []) {
			return [];
		}

		$backendKeys = array_map(fn(string $t) => self::KEY_PREFIX . $t, $tags);
		$blobs = $this->backend->getMultiple($backendKeys);
		$versions = [];

		foreach ($tags as $tag) {
			$blob = $blobs[self::KEY_PREFIX . $tag] ?? null;
			$versions[$tag] = ($blob !== null) ? (int) $this->marshaller->unmarshall($blob) : 0;
		}

		return $versions;
	}

	/**
	 * Increment the version counter for each of the given tags.
	 *
	 * @param array<string> $tags
	 */
	public function invalidate(array $tags): bool
	{
		if ($tags === []) {
			return true;
		}

		$current = $this->getVersions($tags);
		$success = true;

		foreach ($tags as $tag) {
			$newVersion = ($current[$tag] ?? 0) + 1;
			$blob = $this->marshaller->marshall($newVersion);

			if (!$this->backend->set(self::KEY_PREFIX . $tag, $blob)) {
				$success = false;
			}
		}

		return $success;
	}
}
