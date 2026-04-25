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

namespace phpbb\Tests\notifications\DTO;

use phpbb\notifications\DTO\NotificationDTO;
use phpbb\notifications\Entity\Notification;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NotificationDTOTest extends TestCase
{
	private function makeNotification(array $overrides = []): Notification
	{
		return Notification::fromRow(array_merge([
			'notification_id'        => '1',
			'notification_type_id'   => '2',
			'notification_type_name' => 'post',
			'item_id'                => '10',
			'item_parent_id'         => '5',
			'user_id'                => '3',
			'notification_read'      => '0',
			'notification_time'      => '1700000000',
			'notification_data'      => '{"responders":[{"user_id":9}]}',
		], $overrides));
	}

	#[Test]
	public function fromEntityMapsId(): void
	{
		// Arrange
		$notification = $this->makeNotification(['notification_id' => '42']);

		// Act
		$dto = NotificationDTO::fromEntity($notification);

		// Assert
		self::assertSame(42, $dto->id);
	}

	#[Test]
	public function fromEntitySetsUnreadInvertsRead(): void
	{
		// Arrange
		$unreadNotification = $this->makeNotification(['notification_read' => '0']);
		$readNotification   = $this->makeNotification(['notification_read' => '1']);

		// Act
		$unreadDto = NotificationDTO::fromEntity($unreadNotification);
		$readDto   = NotificationDTO::fromEntity($readNotification);

		// Assert
		self::assertTrue($unreadDto->unread);
		self::assertFalse($readDto->unread);
	}

	#[Test]
	public function fromEntityBuildsDataArray(): void
	{
		// Arrange
		$notification = $this->makeNotification([
			'notification_data' => '{"responders":[{"user_id":9},{"user_id":11}]}',
			'item_id'           => '10',
			'item_parent_id'    => '5',
		]);

		// Act
		$dto = NotificationDTO::fromEntity($notification);

		// Assert
		self::assertSame(10, $dto->data['itemId']);
		self::assertSame(5, $dto->data['itemParentId']);
		self::assertCount(2, $dto->data['responders']);
		self::assertSame(2, $dto->data['responderCount']);
	}

	#[Test]
	public function toArrayMatchesJsonShape(): void
	{
		// Arrange
		$notification = $this->makeNotification();

		// Act
		$array = NotificationDTO::fromEntity($notification)->toArray();

		// Assert
		self::assertArrayHasKey('id', $array);
		self::assertArrayHasKey('type', $array);
		self::assertArrayHasKey('unread', $array);
		self::assertArrayHasKey('createdAt', $array);
		self::assertArrayHasKey('data', $array);
	}

	#[Test]
	public function fromEntityCountsRespondersCorrectly(): void
	{
		// Arrange — three responders
		$notification = $this->makeNotification([
			'notification_data' => '{"responders":[{"user_id":1},{"user_id":2},{"user_id":3}]}',
		]);

		// Act
		$dto = NotificationDTO::fromEntity($notification);

		// Assert
		self::assertSame(3, $dto->data['responderCount']);
		self::assertCount(3, $dto->data['responders']);
	}
}
