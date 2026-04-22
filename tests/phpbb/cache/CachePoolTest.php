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

namespace phpbb\tests\cache;

use phpbb\cache\backend\NullBackend;
use phpbb\cache\CachePool;
use phpbb\cache\marshaller\VarExportMarshaller;
use phpbb\cache\TagVersionStore;
use PHPUnit\Framework\TestCase;

/**
 * In-memory backend stub used across CachePool tests.
 * Extends NullBackend so we only override what we need.
 */
class InMemoryBackend extends NullBackend
{
	/** @var array<string, string> */
	private array $store = [];

	public function get(string $key): ?string
	{
		return $this->store[$key] ?? null;
	}

	public function set(string $key, string $value, ?int $ttl = null): bool
	{
		$this->store[$key] = $value;

		return true;
	}

	public function delete(string $key): bool
	{
		unset($this->store[$key]);

		return true;
	}

	public function has(string $key): bool
	{
		return isset($this->store[$key]);
	}

	public function clear(string $prefix = ''): bool
	{
		if ($prefix === '') {
			$this->store = [];

			return true;
		}

		foreach (array_keys($this->store) as $key) {
			if (str_starts_with($key, $prefix)) {
				unset($this->store[$key]);
			}
		}

		return true;
	}

	public function getMultiple(array $keys): array
	{
		$result = [];
		foreach ($keys as $key) {
			$result[$key] = $this->store[$key] ?? null;
		}

		return $result;
	}
}

class CachePoolTest extends TestCase
{
	private InMemoryBackend $backend;
	private VarExportMarshaller $marshaller;
	private TagVersionStore $tagStore;
	private CachePool $pool;

	protected function setUp(): void
	{
		$this->backend = new InMemoryBackend();
		$this->marshaller = new VarExportMarshaller();
		$this->tagStore = new TagVersionStore($this->backend, $this->marshaller);
		$this->pool = new CachePool('test', $this->backend, $this->marshaller, $this->tagStore);
	}

	#[\PHPUnit\Framework\Attributes\Test]
	public function setAndGetRoundTrip(): void
	{
		$this->pool->set('key', 'value');
		self::assertSame('value', $this->pool->get('key'));
	}

	#[\PHPUnit\Framework\Attributes\Test]
	public function getMissingKeyReturnsDefault(): void
	{
		self::assertNull($this->pool->get('missing'));
		self::assertSame('fallback', $this->pool->get('missing', 'fallback'));
	}

	#[\PHPUnit\Framework\Attributes\Test]
	public function hasReturnsTrueForStoredKey(): void
	{
		$this->pool->set('present', 42);
		self::assertTrue($this->pool->has('present'));
	}

	#[\PHPUnit\Framework\Attributes\Test]
	public function hasReturnsFalseForMissingKey(): void
	{
		self::assertFalse($this->pool->has('ghost'));
	}

	#[\PHPUnit\Framework\Attributes\Test]
	public function deleteRemovesEntry(): void
	{
		$this->pool->set('gone', 'soon');
		$this->pool->delete('gone');
		self::assertNull($this->pool->get('gone'));
	}

	#[\PHPUnit\Framework\Attributes\Test]
	public function clearRemovesOnlyNamespacedEntries(): void
	{
		$other = new CachePool('other', $this->backend, $this->marshaller, $this->tagStore);
		$this->pool->set('a', 1);
		$other->set('b', 2);

		$this->pool->clear();

		self::assertNull($this->pool->get('a'));
		self::assertSame(2, $other->get('b'));
	}

	#[\PHPUnit\Framework\Attributes\Test]
	public function getMultipleReturnsMixedResults(): void
	{
		$this->pool->set('x', 10);
		$result = iterator_to_array($this->pool->getMultiple(['x', 'y']), true);
		self::assertSame(10, $result['x']);
		self::assertNull($result['y']);
	}

	#[\PHPUnit\Framework\Attributes\Test]
	public function setMultipleStoresAllValues(): void
	{
		$this->pool->setMultiple(['p' => 'P', 'q' => 'Q']);
		self::assertSame('P', $this->pool->get('p'));
		self::assertSame('Q', $this->pool->get('q'));
	}

	#[\PHPUnit\Framework\Attributes\Test]
	public function deleteMultipleRemovesAll(): void
	{
		$this->pool->setMultiple(['r' => 1, 's' => 2]);
		$this->pool->deleteMultiple(['r', 's']);
		self::assertNull($this->pool->get('r'));
		self::assertNull($this->pool->get('s'));
	}

	#[\PHPUnit\Framework\Attributes\Test]
	public function setTaggedAndGetRoundTrip(): void
	{
		$this->pool->setTagged('tagged', 'hello', null, ['forums']);
		self::assertSame('hello', $this->pool->get('tagged'));
	}

	#[\PHPUnit\Framework\Attributes\Test]
	public function invalidateTagMakesEntryStale(): void
	{
		$this->pool->setTagged('tagged', 'world', null, ['posts']);
		$this->pool->invalidateTags(['posts']);

		self::assertNull($this->pool->get('tagged'));
	}

	#[\PHPUnit\Framework\Attributes\Test]
	public function invalidateOneTagDoesNotAffectOtherTags(): void
	{
		$this->pool->setTagged('a', 'aval', null, ['tagA']);
		$this->pool->setTagged('b', 'bval', null, ['tagB']);

		$this->pool->invalidateTags(['tagA']);

		self::assertNull($this->pool->get('a'));
		self::assertSame('bval', $this->pool->get('b'));
	}

	#[\PHPUnit\Framework\Attributes\Test]
	public function getOrComputeReturnsCachedValueOnHit(): void
	{
		$this->pool->set('computed', 'cached');

		$calls = 0;
		$result = $this->pool->getOrCompute('computed', function () use (&$calls) {
			$calls++;

			return 'new-value';
		});

		self::assertSame('cached', $result);
		self::assertSame(0, $calls);
	}

	#[\PHPUnit\Framework\Attributes\Test]
	public function getOrComputeComputesAndCachesOnMiss(): void
	{
		$result = $this->pool->getOrCompute('fresh', fn () => 'computed-value', 60, ['t']);
		self::assertSame('computed-value', $result);
		self::assertSame('computed-value', $this->pool->get('fresh'));
	}

	#[\PHPUnit\Framework\Attributes\Test]
	public function setWithDateIntervalTtlWorks(): void
	{
		$interval = new \DateInterval('PT10S');
		self::assertTrue($this->pool->set('interval-key', 'cool', $interval));
		self::assertSame('cool', $this->pool->get('interval-key'));
	}
}
