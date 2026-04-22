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

namespace phpbb\Tests\db\Exception;

use phpbb\db\Exception\RepositoryException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class RepositoryExceptionTest extends TestCase
{
	#[Test]
	public function isRuntimeExceptionSubclass(): void
	{
		$exception = new RepositoryException('test error');

		$this->assertInstanceOf(\RuntimeException::class, $exception);
	}

	#[Test]
	public function preservesPreviousException(): void
	{
		$previous = new \Exception('root cause');
		$exception = new RepositoryException('wrapper', 0, $previous);

		$this->assertSame($previous, $exception->getPrevious());
	}
}
