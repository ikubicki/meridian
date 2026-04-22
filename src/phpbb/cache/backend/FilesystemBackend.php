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
 * Filesystem cache backend.
 *
 * Stores each entry as a plain file:
 *   {cacheDir}/{sha256(key)}.cache
 *
 * File format (two-line envelope):
 *   Line 1 — Unix timestamp of expiry (0 = never expires)
 *   Line 2+ — raw serialised blob
 *
 * Key hashing is done internally so callers never have to worry about
 * filesystem-unsafe characters in keys.
 */
class FilesystemBackend implements CacheBackendInterface
{
	public function __construct(
		private readonly string $cacheDir,
	) {
		if (!is_dir($this->cacheDir) && !mkdir($this->cacheDir, 0755, true) && !is_dir($this->cacheDir)) {
			throw new \RuntimeException(sprintf('Could not create cache directory "%s".', $this->cacheDir));
		}
	}

	public function get(string $key): ?string
	{
		$file = $this->filePath($key);

		if (!is_file($file)) {
			return null;
		}

		$contents = file_get_contents($file);
		if ($contents === false) {
			return null;
		}

		$newline = strpos($contents, "\n");
		if ($newline === false) {
			return null;
		}

		$expiry = (int) substr($contents, 0, $newline);

		if ($expiry !== 0 && $expiry < time()) {
			@unlink($file);

			return null;
		}

		return substr($contents, $newline + 1);
	}

	public function set(string $key, string $value, ?int $ttl = null): bool
	{
		// PSR-16: a TTL of 0 or negative means the item should not be stored (immediately expired).
		if ($ttl !== null && $ttl <= 0) {
			$this->delete($key);

			return true;
		}

		$expiry = ($ttl !== null) ? time() + $ttl : 0;
		$contents = $expiry . "\n" . $value;

		return file_put_contents($this->filePath($key), $contents, LOCK_EX) !== false;
	}

	public function delete(string $key): bool
	{
		$file = $this->filePath($key);

		if (!is_file($file)) {
			return true;
		}

		return @unlink($file);
	}

	public function has(string $key): bool
	{
		return $this->get($key) !== null;
	}

	public function clear(string $prefix = ''): bool
	{
		$files = glob($this->cacheDir . DIRECTORY_SEPARATOR . '*.cache');

		if ($files === false) {
			return true;
		}

		$hashedPrefix = $prefix !== '' ? hash('sha256', $prefix) : '';
		$success = true;

		foreach ($files as $file) {
			$basename = basename($file, '.cache');

			if ($hashedPrefix !== '' && !str_starts_with($basename, $hashedPrefix)) {
				continue;
			}

			if (!@unlink($file)) {
				$success = false;
			}
		}

		return $success;
	}

	public function getMultiple(array $keys): array
	{
		$result = [];

		foreach ($keys as $key) {
			$result[$key] = $this->get($key);
		}

		return $result;
	}

	private function filePath(string $key): string
	{
		return $this->cacheDir . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.cache';
	}
}
