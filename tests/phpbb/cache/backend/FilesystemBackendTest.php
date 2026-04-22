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

namespace phpbb\tests\cache\backend;

use phpbb\cache\backend\FilesystemBackend;
use PHPUnit\Framework\TestCase;

class FilesystemBackendTest extends TestCase
{
	private string $cacheDir;
	private FilesystemBackend $backend;

	protected function setUp(): void
	{
		$this->cacheDir = sys_get_temp_dir() . '/phpbb4_cache_test_' . uniqid('', true);
		$this->backend = new FilesystemBackend($this->cacheDir);
	}

	protected function tearDown(): void
	{
		$files = glob($this->cacheDir . '/*.cache') ?: [];
		foreach ($files as $file) {
			@unlink($file);
		}
		@rmdir($this->cacheDir);
	}

	#[\PHPUnit\Framework\Attributes\Test]
	public function setAndGetRoundTrip(): void
	{
		self::assertTrue($this->backend->set('hello', 'world'));
		self::assertSame('world', $this->backend->get('hello'));
	}

	#[\PHPUnit\Framework\Attributes\Test]
	public function getMissingKeyReturnsNull(): void
	{
		self::assertNull($this->backend->get('no-such-key'));
	}

	#[\PHPUnit\Framework\Attributes\Test]
	public function hasReturnsTrueForExistingKey(): void
	{
		$this->backend->set('present', 'yes');
		self::assertTrue($this->backend->has('present'));
	}

	#[\PHPUnit\Framework\Attributes\Test]
	public function hasReturnsFalseForMissingKey(): void
	{
		self::assertFalse($this->backend->has('absent'));
	}

	#[\PHPUnit\Framework\Attributes\Test]
	public function deleteRemovesEntry(): void
	{
		$this->backend->set('tmp', 'val');
		self::assertTrue($this->backend->delete('tmp'));
		self::assertNull($this->backend->get('tmp'));
	}

	#[\PHPUnit\Framework\Attributes\Test]
	public function deleteNonExistentKeyReturnsTrue(): void
	{
		self::assertTrue($this->backend->delete('ghost'));
	}

	#[\PHPUnit\Framework\Attributes\Test]
	public function expiredEntryTreatedAsMiss(): void
	{
		$this->backend->set('short', 'lived', -1);
		self::assertNull($this->backend->get('short'));
	}

	#[\PHPUnit\Framework\Attributes\Test]
	public function nullTtlNeverExpires(): void
	{
		$this->backend->set('forever', 'ever', null);
		self::assertSame('ever', $this->backend->get('forever'));
	}

	#[\PHPUnit\Framework\Attributes\Test]
	public function clearRemovesAllEntries(): void
	{
		$this->backend->set('a', '1');
		$this->backend->set('b', '2');
		self::assertTrue($this->backend->clear());
		self::assertNull($this->backend->get('a'));
		self::assertNull($this->backend->get('b'));
	}

	#[\PHPUnit\Framework\Attributes\Test]
	public function getMultipleReturnsMixOfHitsAndNulls(): void
	{
		$this->backend->set('x', 'xval');
		$result = $this->backend->getMultiple(['x', 'missing']);
		self::assertSame('xval', $result['x']);
		self::assertNull($result['missing']);
	}
}
