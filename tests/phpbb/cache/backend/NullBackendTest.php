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

use phpbb\cache\backend\NullBackend;
use PHPUnit\Framework\TestCase;

class NullBackendTest extends TestCase
{
	private NullBackend $backend;

	protected function setUp(): void
	{
		$this->backend = new NullBackend();
	}

	#[\PHPUnit\Framework\Attributes\Test]
	public function getAlwaysReturnsNull(): void
	{
		self::assertNull($this->backend->get('any-key'));
	}

	#[\PHPUnit\Framework\Attributes\Test]
	public function setAlwaysReturnsTrue(): void
	{
		self::assertTrue($this->backend->set('k', 'v', 60));
	}

	#[\PHPUnit\Framework\Attributes\Test]
	public function deleteAlwaysReturnsTrue(): void
	{
		self::assertTrue($this->backend->delete('k'));
	}

	#[\PHPUnit\Framework\Attributes\Test]
	public function hasAlwaysReturnsFalse(): void
	{
		self::assertFalse($this->backend->has('k'));
	}

	#[\PHPUnit\Framework\Attributes\Test]
	public function clearAlwaysReturnsTrue(): void
	{
		self::assertTrue($this->backend->clear());
	}

	#[\PHPUnit\Framework\Attributes\Test]
	public function getMultipleReturnsNullForAllKeys(): void
	{
		$result = $this->backend->getMultiple(['a', 'b', 'c']);

		self::assertSame(['a' => null, 'b' => null, 'c' => null], $result);
	}
}
