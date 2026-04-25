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

namespace phpbb\Tests\notifications\Repository;

use phpbb\api\DTO\PaginationContext;
use phpbb\notifications\Entity\Notification;
use phpbb\notifications\Repository\DbalNotificationRepository;
use phpbb\Tests\Integration\IntegrationTestCase;
use phpbb\user\DTO\PaginatedResult;
use PHPUnit\Framework\Attributes\Test;

final class DbalNotificationRepositoryReadTest extends IntegrationTestCase
{
	private DbalNotificationRepository $repository;

	protected function setUpSchema(): void
	{
		$this->connection->executeStatement('
			CREATE TABLE phpbb_notification_types (
				notification_type_id INTEGER PRIMARY KEY AUTOINCREMENT,
				notification_type_name TEXT NOT NULL DEFAULT \'\',
				notification_type_enabled INTEGER NOT NULL DEFAULT 1
			)
		');

		$this->connection->executeStatement('
			CREATE TABLE phpbb_notifications (
				notification_id INTEGER PRIMARY KEY AUTOINCREMENT,
				notification_type_id INTEGER NOT NULL DEFAULT 0,
				item_id INTEGER NOT NULL DEFAULT 0,
				item_parent_id INTEGER NOT NULL DEFAULT 0,
				user_id INTEGER NOT NULL DEFAULT 0,
				notification_read INTEGER NOT NULL DEFAULT 0,
				notification_time INTEGER NOT NULL DEFAULT 0,
				notification_data TEXT NOT NULL DEFAULT \'\'
			)
		');
	}

	protected function setUp(): void
	{
		parent::setUp();
		$this->repository = new DbalNotificationRepository($this->connection);
	}

	private function insertType(string $name): int
	{
		$this->connection->insert('phpbb_notification_types', [
			'notification_type_name'    => $name,
			'notification_type_enabled' => 1,
		]);

		return (int) $this->connection->lastInsertId();
	}

	private function insertNotification(array $data): int
	{
		$defaults = [
			'notification_type_id' => 0,
			'item_id'              => 0,
			'item_parent_id'       => 0,
			'user_id'              => 1,
			'notification_read'    => 0,
			'notification_time'    => 100,
			'notification_data'    => '',
		];

		$this->connection->insert('phpbb_notifications', array_merge($defaults, $data));

		return (int) $this->connection->lastInsertId();
	}

	#[Test]
	public function findByIdReturnsNullForMissing(): void
	{
		$result = $this->repository->findById(999, 1);

		self::assertNull($result);
	}

	#[Test]
	public function findByIdReturnsScopedToUser(): void
	{
		$id = $this->insertNotification(['user_id' => 5]);

		$result = $this->repository->findById($id, 99);

		self::assertNull($result);
	}

	#[Test]
	public function findByIdReturnsHydratedEntity(): void
	{
		$typeId = $this->insertType('post');
		$id     = $this->insertNotification([
			'notification_type_id' => $typeId,
			'user_id'              => 10,
			'item_id'              => 42,
		]);

		$result = $this->repository->findById($id, 10);

		self::assertInstanceOf(Notification::class, $result);
		self::assertSame($id, $result->notificationId);
		self::assertSame('post', $result->typeName);
		self::assertSame(10, $result->userId);
		self::assertSame(42, $result->itemId);
	}

	#[Test]
	public function countUnreadReturnsZeroByDefault(): void
	{
		self::assertSame(0, $this->repository->countUnread(1));
	}

	#[Test]
	public function countUnreadCountsOnlyUnread(): void
	{
		$this->insertNotification(['user_id' => 1, 'notification_read' => 0]);
		$this->insertNotification(['user_id' => 1, 'notification_read' => 0]);
		$this->insertNotification(['user_id' => 1, 'notification_read' => 1]);

		self::assertSame(2, $this->repository->countUnread(1));
	}

	#[Test]
	public function getLastModifiedReturnsNullWhenEmpty(): void
	{
		self::assertNull($this->repository->getLastModified(1));
	}

	#[Test]
	public function getLastModifiedReturnsMaxTime(): void
	{
		$this->insertNotification(['user_id' => 1, 'notification_time' => 100]);
		$this->insertNotification(['user_id' => 1, 'notification_time' => 200]);

		self::assertSame(200, $this->repository->getLastModified(1));
	}

	#[Test]
	public function listByUserReturnsEmptyForUnknownUser(): void
	{
		// Arrange — no notifications inserted
		$ctx = new PaginationContext(page: 1, perPage: 10);

		// Act
		$result = $this->repository->listByUser(9999, $ctx);

		// Assert
		self::assertInstanceOf(PaginatedResult::class, $result);
		self::assertSame(0, $result->total);
		self::assertCount(0, $result->items);
	}
}
