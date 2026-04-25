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

namespace phpbb\Tests\notifications\Listener;

use phpbb\cache\CachePoolFactoryInterface;
use phpbb\cache\TagAwareCacheInterface;
use phpbb\notifications\Event\NotificationReadEvent;
use phpbb\notifications\Event\NotificationsReadAllEvent;
use phpbb\notifications\Listener\CacheInvalidationSubscriber;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CacheInvalidationSubscriberTest extends TestCase
{
	private CachePoolFactoryInterface&MockObject $cacheFactory;
	private TagAwareCacheInterface&MockObject $cache;
	private CacheInvalidationSubscriber $subscriber;

	protected function setUp(): void
	{
		$this->cacheFactory = $this->createMock(CachePoolFactoryInterface::class);
		$this->cache = $this->createMock(TagAwareCacheInterface::class);

		$this->cacheFactory->method('getPool')->with('notifications')->willReturn($this->cache);

		$this->subscriber = new CacheInvalidationSubscriber($this->cacheFactory);
	}

	#[Test]
	public function subscribesToBothEvents(): void
	{
		$events = CacheInvalidationSubscriber::getSubscribedEvents();

		$this->assertArrayHasKey(NotificationReadEvent::class, $events);
		$this->assertArrayHasKey(NotificationsReadAllEvent::class, $events);
	}

	#[Test]
	public function getSubscribedEventsHasCorrectHandlerNames(): void
	{
		// Act
		$events = CacheInvalidationSubscriber::getSubscribedEvents();

		// Assert — verify the handler method names are correct
		$this->assertSame('onNotificationRead', $events[NotificationReadEvent::class]);
		$this->assertSame('onNotificationsReadAll', $events[NotificationsReadAllEvent::class]);
	}

	#[Test]
	public function onNotificationReadInvalidatesUserTag(): void
	{
		$event = new NotificationReadEvent(entityId: 7, actorId: 42);

		$this->cache->expects($this->once())
			->method('invalidateTags')
			->with(['user:42']);

		$this->subscriber->onNotificationRead($event);
	}

	#[Test]
	public function onNotificationsReadAllInvalidatesUserTag(): void
	{
		$event = new NotificationsReadAllEvent(entityId: 42, actorId: 42);

		$this->cache->expects($this->once())
			->method('invalidateTags')
			->with(['user:42']);

		$this->subscriber->onNotificationsReadAll($event);
	}
}
