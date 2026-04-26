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

namespace phpbb\Tests\search\Driver;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ConnectionException;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use phpbb\api\DTO\PaginationContext;
use phpbb\db\Exception\RepositoryException;
use phpbb\search\Driver\FullTextDriver;
use phpbb\search\Driver\LikeDriver;
use phpbb\search\DTO\SearchQuery;
use phpbb\user\DTO\PaginatedResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class FullTextDriverTest extends TestCase
{
	#[Test]
	public function it_uses_fulltext_driver_for_mysql_platform(): void
	{
		// Arrange
		/** @var Connection&MockObject $connection */
		$connection = $this->createMock(Connection::class);
		$connection->method('getDatabasePlatform')
			->willReturn(new MySQLPlatform());
		$connection->method('createQueryBuilder')
			->willThrowException(new ConnectionException('Simulated DB failure'));

		/** @var LikeDriver&MockObject $fallback */
		$fallback = $this->createMock(LikeDriver::class);
		$fallback->expects($this->never())->method('search');

		$driver = new FullTextDriver($connection, $fallback);

		// Assert + Act
		$this->expectException(RepositoryException::class);
		$driver->search(new SearchQuery(keywords: 'hello'), new PaginationContext());
	}

	#[Test]
	public function it_falls_back_to_like_on_sqlite(): void
	{
		// Arrange
		$connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
		$expected   = new PaginatedResult(items: [], total: 0, page: 1, perPage: 25);

		/** @var LikeDriver&MockObject $fallback */
		$fallback = $this->createMock(LikeDriver::class);
		$fallback->expects($this->once())
			->method('search')
			->willReturn($expected);

		$driver = new FullTextDriver($connection, $fallback);
		$ctx    = new PaginationContext(page: 1, perPage: 25);

		// Act
		$result = $driver->search(new SearchQuery(keywords: 'hello'), $ctx);

		// Assert
		$this->assertSame($expected, $result);
	}
}
