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
use phpbb\cache\marshaller\VarExportMarshaller;
use phpbb\cache\TagVersionStore;
use PHPUnit\Framework\TestCase;

class TagVersionStoreTest extends TestCase
{
	private TagVersionStore $store;
	private NullBackend $backend;
	private VarExportMarshaller $marshaller;

	protected function setUp(): void
	{
		// We need a real backend for meaningful state in these tests
		$this->backend = new class () extends NullBackend {
			/** @var array<string, string> */
			private array $data = [];

			public function get(string $key): ?string
			{
				return $this->data[$key] ?? null;
			}

			public function set(string $key, string $value, ?int $ttl = null): bool
			{
				$this->data[$key] = $value;

				return true;
			}

			public function getMultiple(array $keys): array
			{
				$result = [];
				foreach ($keys as $key) {
					$result[$key] = $this->data[$key] ?? null;
				}

				return $result;
			}
		};

		$this->marshaller = new VarExportMarshaller();
		$this->store = new TagVersionStore($this->backend, $this->marshaller);
	}

	#[\PHPUnit\Framework\Attributes\Test]
	public function unknownTagsDefaultToVersionZero(): void
	{
		$versions = $this->store->getVersions(['foo', 'bar']);
		self::assertSame(['foo' => 0, 'bar' => 0], $versions);
	}

	#[\PHPUnit\Framework\Attributes\Test]
	public function invalidateIncrementsVersion(): void
	{
		$this->store->invalidate(['my-tag']);
		$versions = $this->store->getVersions(['my-tag']);
		self::assertSame(1, $versions['my-tag']);
	}

	#[\PHPUnit\Framework\Attributes\Test]
	public function multipleInvalidationsAccumulate(): void
	{
		$this->store->invalidate(['tag']);
		$this->store->invalidate(['tag']);
		$this->store->invalidate(['tag']);

		$versions = $this->store->getVersions(['tag']);
		self::assertSame(3, $versions['tag']);
	}

	#[\PHPUnit\Framework\Attributes\Test]
	public function invalidateReturnsTrueOnSuccess(): void
	{
		self::assertTrue($this->store->invalidate(['something']));
	}

	#[\PHPUnit\Framework\Attributes\Test]
	public function emptyTagsReturnEmptyVersionMap(): void
	{
		self::assertSame([], $this->store->getVersions([]));
	}

	#[\PHPUnit\Framework\Attributes\Test]
	public function invalidatingEmptyTagsReturnsTrue(): void
	{
		self::assertTrue($this->store->invalidate([]));
	}
}
