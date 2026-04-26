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

namespace phpbb\Tests\config;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use phpbb\config\ConfigRepository;
use phpbb\config\Contract\ConfigRepositoryInterface;
use phpbb\db\Exception\RepositoryException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConfigRepositoryTest extends TestCase
{
	private Connection&MockObject $connection;
	private ConfigRepository $repository;

	protected function setUp(): void
	{
		$this->connection = $this->createMock(Connection::class);
		$this->connection->method('createQueryBuilder')
			->willReturnCallback(fn () => new QueryBuilder($this->connection));
		$this->repository = new ConfigRepository($this->connection);
	}

	#[Test]
	public function it_implements_config_repository_interface(): void
	{
		$this->assertInstanceOf(ConfigRepositoryInterface::class, $this->repository);
	}

	#[Test]
	public function it_set_updates_only_when_row_exists(): void
	{
		$this->connection->expects($this->once())
			->method('executeStatement')
			->willReturn(1);

		$this->repository->set('existing_key', 'new_value');
	}

	#[Test]
	public function it_set_inserts_when_update_finds_no_row(): void
	{
		$this->connection->expects($this->exactly(2))
			->method('executeStatement')
			->willReturnOnConsecutiveCalls(0, 1);

		$this->repository->set('new_key', 'new_value');
	}

	#[Test]
	public function it_increment_executes_single_update_without_pre_read(): void
	{
		$this->connection->expects($this->once())
			->method('executeStatement')
			->willReturn(1);

		$this->connection->expects($this->never())
			->method('fetchAssociative');

		$this->repository->increment('post_count');
	}

	#[Test]
	public function it_increment_throws_repository_exception_when_0_rows_affected(): void
	{
		$this->connection->expects($this->once())
			->method('executeStatement')
			->willReturn(0);

		$this->expectException(RepositoryException::class);

		$this->repository->increment('nonexistent_key');
	}

	#[Test]
	public function it_delete_returns_affected_row_count(): void
	{
		$this->connection->expects($this->once())
			->method('executeStatement')
			->willReturn(1);

		$result = $this->repository->delete('test_key');

		$this->assertSame(1, $result);
	}
}
