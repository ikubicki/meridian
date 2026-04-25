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

namespace phpbb\Tests\storage\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use phpbb\storage\Entity\StorageQuota;
use phpbb\storage\Repository\DbalStorageQuotaRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DbalStorageQuotaRepositoryTest extends TestCase
{
	private Connection $connection;
	private DbalStorageQuotaRepository $repository;

	protected function setUp(): void
	{
		$this->connection = $this->createMock(Connection::class);
		$this->connection->method('createQueryBuilder')
			->willReturnCallback(fn () => new QueryBuilder($this->connection));
		$this->repository = new DbalStorageQuotaRepository($this->connection);
	}

	#[Test]
	public function find_by_user_and_forum_returns_null_when_not_found(): void
	{
		$result = $this->createMock(Result::class);
		$result->method('fetchAssociative')->willReturn(false);
		$this->connection->method('executeQuery')->willReturn($result);

		$quota = $this->repository->findByUserAndForum(1, 0);

		$this->assertNull($quota);
	}

	#[Test]
	public function find_by_user_and_forum_returns_entity(): void
	{
		$result = $this->createMock(Result::class);
		$result->method('fetchAssociative')->willReturn([
			'user_id'    => '1',
			'forum_id'   => '0',
			'used_bytes' => '500',
			'max_bytes'  => '9223372036854775807',
			'updated_at' => '1000000',
		]);
		$this->connection->method('executeQuery')->willReturn($result);

		$quota = $this->repository->findByUserAndForum(1, 0);

		$this->assertInstanceOf(StorageQuota::class, $quota);
		$this->assertSame(1, $quota->userId);
		$this->assertSame(500, $quota->usedBytes);
	}

	#[Test]
	public function increment_usage_returns_true_when_row_updated(): void
	{
		$this->connection->method('executeStatement')->willReturn(1);

		$result = $this->repository->incrementUsage(1, 0, 100);

		$this->assertTrue($result);
	}

	#[Test]
	public function increment_usage_returns_false_when_no_row_updated(): void
	{
		$this->connection->method('executeStatement')->willReturn(0);

		$result = $this->repository->incrementUsage(1, 0, 100);

		$this->assertFalse($result);
	}

	#[Test]
	public function decrement_usage_executes_update(): void
	{
		$this->connection->expects($this->once())
			->method('executeStatement')
			->with($this->stringContains('UPDATE'));

		$this->repository->decrementUsage(1, 0, 200);
	}

	#[Test]
	public function reconcile_executes_update_with_actual_bytes(): void
	{
		$this->connection->expects($this->once())
			->method('executeStatement')
			->with($this->stringContains('used_bytes = :actualBytes'));

		$this->repository->reconcile(1, 0, 750);
	}

	#[Test]
	public function find_all_user_forum_pairs_returns_array(): void
	{
		$result = $this->createMock(Result::class);
		$result->method('fetchAllAssociative')->willReturn([
			['user_id' => '1', 'forum_id' => '0'],
			['user_id' => '2', 'forum_id' => '5'],
		]);
		$this->connection->method('executeQuery')->willReturn($result);

		$pairs = $this->repository->findAllUserForumPairs();

		$this->assertCount(2, $pairs);
	}
}
