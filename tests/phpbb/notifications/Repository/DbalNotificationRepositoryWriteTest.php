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
use phpbb\notifications\DTO\NotificationDTO;
use phpbb\notifications\Repository\DbalNotificationRepository;
use phpbb\Tests\Integration\IntegrationTestCase;
use phpbb\user\DTO\PaginatedResult;
use PHPUnit\Framework\Attributes\Test;

final class DbalNotificationRepositoryWriteTest extends IntegrationTestCase
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
	public function listByUserReturnsPaginatedDTOs(): void
	{
		$this->insertNotification(['user_id' => 1, 'notification_time' => 300]);
		$this->insertNotification(['user_id' => 1, 'notification_time' => 200]);
		$this->insertNotification(['user_id' => 1, 'notification_time' => 100]);

		$ctx    = new PaginationContext(page: 1, perPage: 10);
		$result = $this->repository->listByUser(1, $ctx);

		self::assertInstanceOf(PaginatedResult::class, $result);
		self::assertSame(3, $result->total);
		self::assertCount(3, $result->items);
		self::assertContainsOnlyInstancesOf(NotificationDTO::class, $result->items);
	}

	#[Test]
	public function markReadReturnsTrueForOwn(): void
	{
		$id = $this->insertNotification(['user_id' => 2, 'notification_read' => 0]);

		$result = $this->repository->markRead($id, 2);

		self::assertTrue($result);

		$row = $this->connection->fetchAssociative(
			'SELECT notification_read FROM phpbb_notifications WHERE notification_id = ?',
			[$id],
		);
		self::assertSame(1, (int) $row['notification_read']);
	}

	#[Test]
	public function markReadReturnsFalseForOther(): void
	{
		$id = $this->insertNotification(['user_id' => 2, 'notification_read' => 0]);

		$result = $this->repository->markRead($id, 99);

		self::assertFalse($result);

		$row = $this->connection->fetchAssociative(
			'SELECT notification_read FROM phpbb_notifications WHERE notification_id = ?',
			[$id],
		);
		self::assertSame(0, (int) $row['notification_read']);
	}

	#[Test]
	public function markReadReturnsFalseIfAlreadyRead(): void
	{
		$id = $this->insertNotification(['user_id' => 1, 'notification_read' => 1]);

		$result = $this->repository->markRead($id, 1);

		self::assertFalse($result);
	}

	#[Test]
	public function markAllReadUpdatesAllUnread(): void
	{
		$this->insertNotification(['user_id' => 3, 'notification_read' => 0]);
		$this->insertNotification(['user_id' => 3, 'notification_read' => 0]);
		$this->insertNotification(['user_id' => 3, 'notification_read' => 0]);

		$updated = $this->repository->markAllRead(3);

		self::assertSame(3, $updated);
		self::assertSame(0, $this->repository->countUnread(3));
	}

	#[Test]
	public function markAllReadWhenAllAlreadyReadReturnsZero(): void
	{
		// Arrange — all already read
		$this->insertNotification(['user_id' => 5, 'notification_read' => 1]);
		$this->insertNotification(['user_id' => 5, 'notification_read' => 1]);

		// Act
		$updated = $this->repository->markAllRead(5);

		// Assert
		self::assertSame(0, $updated);
	}
}
