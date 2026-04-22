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

namespace phpbb\Tests\hierarchy\Service;

use phpbb\hierarchy\Service\SubscriptionService;
use phpbb\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Test;

class SubscriptionServiceTest extends IntegrationTestCase
{
	private SubscriptionService $service;

	protected function setUpSchema(): void
	{
		$this->connection->executeStatement('
			CREATE TABLE phpbb_forums_watch (
				forum_id       INTEGER NOT NULL,
				user_id        INTEGER NOT NULL,
				notify_status  INTEGER NOT NULL,
				PRIMARY KEY (forum_id, user_id)
			)
		');

		$this->service = new SubscriptionService($this->connection);
	}

	#[Test]
	public function testSubscribe_insertsRow(): void
	{
		// Act
		$this->service->subscribe(1, 5);

		// Assert
		$this->assertTrue($this->service->isSubscribed(1, 5));
	}

	#[Test]
	public function testSubscribe_calledTwice_doesNotDuplicate(): void
	{
		// Act
		$this->service->subscribe(1, 5);
		$this->service->subscribe(1, 5);

		// Assert
		$count = $this->connection->executeQuery(
			'SELECT COUNT(*) FROM phpbb_forums_watch WHERE forum_id = 1 AND user_id = 5'
		)->fetchOne();

		$this->assertSame(1, (int) $count);
	}

	#[Test]
	public function testUnsubscribe_removesRow(): void
	{
		// Arrange
		$this->service->subscribe(1, 5);

		// Act
		$this->service->unsubscribe(1, 5);

		// Assert
		$this->assertFalse($this->service->isSubscribed(1, 5));
	}

	#[Test]
	public function testUnsubscribe_nonExistentRow_doesNotThrow(): void
	{
		// Act & Assert — no exception expected
		$this->service->unsubscribe(999, 999);
		$this->assertTrue(true);
	}

	#[Test]
	public function testIsSubscribed_notSubscribed_returnsFalse(): void
	{
		// Act
		$result = $this->service->isSubscribed(1, 1);

		// Assert
		$this->assertFalse($result);
	}

	#[Test]
	public function testIsSubscribed_afterSubscribe_returnsTrue(): void
	{
		// Arrange
		$this->service->subscribe(1, 5);

		// Act
		$result = $this->service->isSubscribed(1, 5);

		// Assert
		$this->assertTrue($result);
	}
}
