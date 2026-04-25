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

namespace phpbb\Tests\notifications\Entity;

use phpbb\notifications\Entity\Notification;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NotificationTest extends TestCase
{
	private function makeRow(array $overrides = []): array
	{
		return array_merge([
			'notification_id'      => '7',
			'notification_type_id' => '3',
			'notification_type_name' => 'post',
			'item_id'              => '42',
			'item_parent_id'       => '10',
			'user_id'              => '5',
			'notification_read'    => '0',
			'notification_time'    => '1700000000',
			'notification_data'    => '{"responders":[]}',
		], $overrides);
	}

	#[Test]
	public function fromRowMapsAllFields(): void
	{
		// Arrange
		$row = $this->makeRow();

		// Act
		$notification = Notification::fromRow($row);

		// Assert
		self::assertSame(7, $notification->notificationId);
		self::assertSame(3, $notification->notificationTypeId);
		self::assertSame('post', $notification->typeName);
		self::assertSame(42, $notification->itemId);
		self::assertSame(10, $notification->itemParentId);
		self::assertSame(5, $notification->userId);
		self::assertFalse($notification->read);
		self::assertSame(1700000000, $notification->notificationTime);
	}

	#[Test]
	public function fromRowDecodesJsonData(): void
	{
		// Arrange
		$row = $this->makeRow(['notification_data' => '{"responders":[]}']);

		// Act
		$notification = Notification::fromRow($row);

		// Assert
		self::assertIsArray($notification->data);
		self::assertArrayHasKey('responders', $notification->data);
		self::assertSame([], $notification->data['responders']);
	}

	#[Test]
	public function fromRowSetsReadBool(): void
	{
		// Arrange / Act
		$unread = Notification::fromRow($this->makeRow(['notification_read' => '0']));
		$read   = Notification::fromRow($this->makeRow(['notification_read' => '1']));

		// Assert
		self::assertFalse($unread->read);
		self::assertTrue($read->read);
	}

	#[Test]
	public function fromRowDefaultsDataToEmptyArray(): void
	{
		// Arrange
		$row = $this->makeRow(['notification_data' => '[]']);

		// Act
		$notification = Notification::fromRow($row);

		// Assert
		self::assertSame([], $notification->data);
	}

	#[Test]
	public function fromRowHandlesEmptyStringData(): void
	{
		// Arrange
		$row = $this->makeRow(['notification_data' => '']);

		// Act
		$notification = Notification::fromRow($row);

		// Assert
		self::assertSame([], $notification->data);
	}
}
