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

namespace phpbb\Tests\storage\Quota;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use phpbb\storage\Contract\StorageQuotaRepositoryInterface;
use phpbb\storage\Entity\StorageQuota;
use phpbb\storage\Exception\QuotaExceededException;
use phpbb\storage\Quota\QuotaService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class QuotaServiceTest extends TestCase
{
	private StorageQuotaRepositoryInterface $quotaRepo;
	private Connection $connection;
	private EventDispatcherInterface $dispatcher;
	private QuotaService $service;

	protected function setUp(): void
	{
		$this->quotaRepo  = $this->createMock(StorageQuotaRepositoryInterface::class);
		$this->connection = $this->createMock(Connection::class);
		$this->dispatcher = $this->createMock(EventDispatcherInterface::class);

		$this->service = new QuotaService(
			quotaRepo:  $this->quotaRepo,
			connection: $this->connection,
			dispatcher: $this->dispatcher,
		);
	}

	#[Test]
	public function check_and_reserve_succeeds_when_increment_returns_true(): void
	{
		$this->quotaRepo->method('incrementUsage')->willReturn(true);

		// Should not throw
		$this->service->checkAndReserve(1, 0, 100);

		$this->addToAssertionCount(1);
	}

	#[Test]
	public function check_and_reserve_creates_default_row_for_unknown_user_and_reserves(): void
	{
		$this->quotaRepo
			->method('incrementUsage')
			->willReturnOnConsecutiveCalls(false, true);

		$this->quotaRepo->method('findByUserAndForum')->willReturn(null);
		$this->quotaRepo->expects($this->once())->method('initDefault');

		$this->service->checkAndReserve(99, 0, 50);
	}

	#[Test]
	public function check_and_reserve_throws_when_quota_full(): void
	{
		$this->quotaRepo->method('incrementUsage')->willReturn(false);
		$this->quotaRepo->method('findByUserAndForum')->willReturn(
			new StorageQuota(
				userId:    1,
				forumId:   0,
				usedBytes: 1000,
				maxBytes:  1000,
				updatedAt: time(),
			),
		);
		$this->dispatcher->method('dispatch')->willReturnArgument(0);

		$this->expectException(QuotaExceededException::class);
		$this->service->checkAndReserve(1, 0, 100);
	}

	#[Test]
	public function release_delegates_to_repo(): void
	{
		$this->quotaRepo->expects($this->once())
			->method('decrementUsage')
			->with(1, 0, 200);

		$this->service->release(1, 0, 200);
	}

	#[Test]
	public function reconcile_all_returns_event_collection(): void
	{
		$result = $this->createMock(Result::class);
		$result->method('fetchOne')->willReturn(500);

		$this->connection->method('executeQuery')->willReturn($result);
		$this->quotaRepo->method('findAllUserForumPairs')->willReturn([
			['user_id' => 1, 'forum_id' => 0],
		]);
		$this->quotaRepo->method('findByUserAndForum')->willReturn(
			new StorageQuota(
				userId:    1,
				forumId:   0,
				usedBytes: 400,
				maxBytes:  PHP_INT_MAX,
				updatedAt: time(),
			),
		);
		$this->quotaRepo->method('reconcile');

		$events = $this->service->reconcileAll();

		$this->assertCount(1, iterator_to_array($events));
	}
}
