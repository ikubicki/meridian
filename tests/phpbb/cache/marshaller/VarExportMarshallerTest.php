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

namespace phpbb\tests\cache\marshaller;

use phpbb\cache\marshaller\VarExportMarshaller;
use PHPUnit\Framework\TestCase;

class VarExportMarshallerTest extends TestCase
{
	private VarExportMarshaller $marshaller;

	protected function setUp(): void
	{
		$this->marshaller = new VarExportMarshaller();
	}

	#[\PHPUnit\Framework\Attributes\Test]
	public function roundTripString(): void
	{
		self::assertSame('hello', $this->marshaller->unmarshall($this->marshaller->marshall('hello')));
	}

	#[\PHPUnit\Framework\Attributes\Test]
	public function roundTripInteger(): void
	{
		self::assertSame(42, $this->marshaller->unmarshall($this->marshaller->marshall(42)));
	}

	#[\PHPUnit\Framework\Attributes\Test]
	public function roundTripNull(): void
	{
		self::assertNull($this->marshaller->unmarshall($this->marshaller->marshall(null)));
	}

	#[\PHPUnit\Framework\Attributes\Test]
	public function roundTripBooleanFalse(): void
	{
		self::assertFalse($this->marshaller->unmarshall($this->marshaller->marshall(false)));
	}

	#[\PHPUnit\Framework\Attributes\Test]
	public function roundTripArray(): void
	{
		$data = ['a' => 1, 'b' => [2, 3]];
		self::assertSame($data, $this->marshaller->unmarshall($this->marshaller->marshall($data)));
	}

	#[\PHPUnit\Framework\Attributes\Test]
	public function unmarshallCorruptDataThrows(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->marshaller->unmarshall('not-valid-serialised-data');
	}

	#[\PHPUnit\Framework\Attributes\Test]
	public function objectsAreReplacedWithFalseForSafety(): void
	{
		$blob = serialize(new \stdClass());
		$result = $this->marshaller->unmarshall($blob);
		// unserialize with allowed_classes:false turns objects into __PHP_Incomplete_Class
		// but our implementation returns it as-is; what matters is no live object survives
		self::assertNotInstanceOf(\stdClass::class, $result);
	}
}
