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
 * Namespaced, tag-aware cache pool.
 *
 * Wraps a low-level backend with:
 *  - namespace isolation (all keys prefixed with "{namespace}:")
 *  - value marshalling through MarshallerInterface
 *  - PSR-16 SimpleCache compliance
 *  - tag-based invalidation via TagVersionStore version counters
 *
 * Stored envelope format (marshalled array):
 *   ['v' => $value, 'tv' => [tag => version, ...]]
 * Tag versions are validated on read; stale entries are treated as misses.
 */
class CachePool implements TagAwareCacheInterface
{
	public function __construct(
		private readonly string $namespace,
		private readonly CacheBackendInterface $backend,
		private readonly MarshallerInterface $marshaller,
		private readonly TagVersionStore $tagVersionStore,
	) {
	}

	// -------------------------------------------------------------------------
	// TagAwareCacheInterface
	// -------------------------------------------------------------------------

	public function setTagged(string $key, mixed $value, ?int $ttl = null, array $tags = []): bool
	{
		$tagVersions = $tags !== [] ? $this->tagVersionStore->getVersions($tags) : [];

		$envelope = ['v' => $value, 'tv' => $tagVersions];
		$blob = $this->marshaller->marshall($envelope);

		return $this->backend->set($this->prefixKey($key), $blob, $ttl);
	}

	public function invalidateTags(array $tags): bool
	{
		return $this->tagVersionStore->invalidate($tags);
	}

	public function getOrCompute(string $key, callable $compute, ?int $ttl = null, array $tags = []): mixed
	{
		$value = $this->get($key);

		if ($value !== null) {
			return $value;
		}

		$value = $compute();
		$this->setTagged($key, $value, $ttl, $tags);

		return $value;
	}

	// -------------------------------------------------------------------------
	// PSR-16 CacheInterface
	// -------------------------------------------------------------------------

	public function get(string $key, mixed $default = null): mixed
	{
		$blob = $this->backend->get($this->prefixKey($key));

		if ($blob === null) {
			return $default;
		}

		try {
			$envelope = $this->marshaller->unmarshall($blob);
		} catch (\InvalidArgumentException) {
			return $default;
		}

		if (!is_array($envelope) || !array_key_exists('v', $envelope)) {
			return $default;
		}

		// Validate tag versions: if any tag was invalidated, treat as miss
		if (!empty($envelope['tv'])) {
			$storedVersions = $envelope['tv'];
			$currentVersions = $this->tagVersionStore->getVersions(array_keys($storedVersions));

			foreach ($storedVersions as $tag => $version) {
				if (($currentVersions[$tag] ?? 0) !== $version) {
					return $default;
				}
			}
		}

		return $envelope['v'];
	}

	public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
	{
		$seconds = $this->normaliseTtl($ttl);

		return $this->setTagged($key, $value, $seconds);
	}

	public function delete(string $key): bool
	{
		return $this->backend->delete($this->prefixKey($key));
	}

	public function clear(): bool
	{
		return $this->backend->clear($this->namespace . ':');
	}

	public function has(string $key): bool
	{
		return $this->get($key) !== null;
	}

	public function getMultiple(iterable $keys, mixed $default = null): iterable
	{
		$keyList = $this->iterableToArray($keys);
		$prefixed = array_map(fn (string $k) => $this->prefixKey($k), $keyList);

		$blobs = $this->backend->getMultiple($prefixed);
		$result = [];

		foreach ($keyList as $key) {
			$blob = $blobs[$this->prefixKey($key)] ?? null;

			if ($blob === null) {
				$result[$key] = $default;
				continue;
			}

			try {
				$envelope = $this->marshaller->unmarshall($blob);
			} catch (\InvalidArgumentException) {
				$result[$key] = $default;
				continue;
			}

			if (!is_array($envelope) || !array_key_exists('v', $envelope)) {
				$result[$key] = $default;
				continue;
			}

			if (!empty($envelope['tv'])) {
				$storedVersions = $envelope['tv'];
				$currentVersions = $this->tagVersionStore->getVersions(array_keys($storedVersions));
				$stale = false;

				foreach ($storedVersions as $tag => $version) {
					if (($currentVersions[$tag] ?? 0) !== $version) {
						$stale = true;
						break;
					}
				}

				if ($stale) {
					$result[$key] = $default;
					continue;
				}
			}

			$result[$key] = $envelope['v'];
		}

		return $result;
	}

	public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
	{
		$seconds = $this->normaliseTtl($ttl);
		$success = true;

		foreach ($values as $key => $value) {
			if (!$this->setTagged((string) $key, $value, $seconds)) {
				$success = false;
			}
		}

		return $success;
	}

	public function deleteMultiple(iterable $keys): bool
	{
		$success = true;

		foreach ($keys as $key) {
			if (!$this->delete((string) $key)) {
				$success = false;
			}
		}

		return $success;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function prefixKey(string $key): string
	{
		return $this->namespace . ':' . $key;
	}

	private function normaliseTtl(\DateInterval|int|null $ttl): ?int
	{
		if ($ttl === null) {
			return null;
		}

		if ($ttl instanceof \DateInterval) {
			$now = new \DateTimeImmutable('now');

			return (int) $now->add($ttl)->format('U') - (int) $now->format('U');
		}

		return $ttl;
	}

	/**
	 * @param iterable<string> $iterable
	 * @return array<string>
	 */
	private function iterableToArray(iterable $iterable): array
	{
		return $iterable instanceof \Traversable ? iterator_to_array($iterable, false) : (array) $iterable;
	}
}
