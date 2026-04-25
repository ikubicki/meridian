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

namespace phpbb\Tests\notifications\Service;

use phpbb\api\DTO\PaginationContext;
use phpbb\cache\CachePoolFactoryInterface;
use phpbb\cache\TagAwareCacheInterface;
use phpbb\common\Event\DomainEventCollection;
use phpbb\notifications\Contract\NotificationRepositoryInterface;
use phpbb\notifications\Event\NotificationReadEvent;
use phpbb\notifications\Event\NotificationsReadAllEvent;
use phpbb\notifications\Service\NotificationService;
use phpbb\user\DTO\PaginatedResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class NotificationServiceTest extends TestCase
{
	private NotificationRepositoryInterface&MockObject $repo;
	private CachePoolFactoryInterface&MockObject $cacheFactory;
	private TagAwareCacheInterface&MockObject $cache;
	private NotificationService $service;

	protected function setUp(): void
	{
		$this->repo = $this->createMock(NotificationRepositoryInterface::class);
		$this->cacheFactory = $this->createMock(CachePoolFactoryInterface::class);
		$this->cache = $this->createMock(TagAwareCacheInterface::class);

		$this->cacheFactory->method('getPool')->with('notifications')->willReturn($this->cache);

		$this->service = new NotificationService($this->repo, $this->cacheFactory);
	}

	#[Test]
	public function getUnreadCountDelegatesToCache(): void
	{
		$this->cache->expects($this->once())
			->method('getOrCompute')
			->with('user:42:count', $this->isType('callable'), 30, ['user:42'])
			->willReturn(5);

		$result = $this->service->getUnreadCount(42);

		$this->assertSame(5, $result);
	}

	#[Test]
	public function getUnreadCountInvokesFallback(): void
	{
		$this->repo->expects($this->once())
			->method('countUnread')
			->with(42)
			->willReturn(3);

		$this->cache->method('getOrCompute')
			->willReturnCallback(fn ($key, callable $compute) => $compute());

		$result = $this->service->getUnreadCount(42);

		$this->assertSame(3, $result);
	}

	#[Test]
	public function getLastModifiedDelegatesToRepository(): void
	{
		$this->repo->expects($this->once())
			->method('getLastModified')
			->with(42)
			->willReturn(1700000000);

		$this->cache->expects($this->never())->method('getOrCompute');

		$result = $this->service->getLastModified(42);

		$this->assertSame(1700000000, $result);
	}

	#[Test]
	public function getNotificationsUsesPagedKey(): void
	{
		$ctx = new PaginationContext(page: 2);

		$cachedArray = [
			'items'   => [],
			'total'   => 0,
			'page'    => 2,
			'perPage' => 25,
		];

		$this->cache->expects($this->once())
			->method('getOrCompute')
			->with('user:42:notifications:2:25', $this->isType('callable'), 30, ['user:42'])
			->willReturn($cachedArray);

		$result = $this->service->getNotifications(42, $ctx);

		$this->assertInstanceOf(PaginatedResult::class, $result);
		$this->assertSame(0, $result->total);
		$this->assertSame(2, $result->page);
		$this->assertSame(25, $result->perPage);
	}

	#[Test]
	public function markReadThrowsForNotFound(): void
	{
		$this->repo->method('markRead')->with(99, 42)->willReturn(false);

		$this->expectException(\InvalidArgumentException::class);

		$this->service->markRead(99, 42);
	}

	#[Test]
	public function markReadReturnsDomainEventCollection(): void
	{
		$this->repo->method('markRead')->with(7, 42)->willReturn(true);

		$result = $this->service->markRead(7, 42);

		$this->assertInstanceOf(DomainEventCollection::class, $result);
		$event = $result->first();
		$this->assertInstanceOf(NotificationReadEvent::class, $event);
		$this->assertSame(7, $event->entityId);
	}

	#[Test]
	public function markAllReadReturnsDomainEventCollection(): void
	{
		$this->repo->method('markAllRead')->with(42)->willReturn(5);

		$result = $this->service->markAllRead(42);

		$this->assertInstanceOf(DomainEventCollection::class, $result);
		$event = $result->first();
		$this->assertInstanceOf(NotificationsReadAllEvent::class, $event);
		$this->assertSame(42, $event->entityId);
	}

	#[Test]
	public function markAllReadInvokesRepository(): void
	{
		// Arrange
		$this->repo->expects($this->once())
			->method('markAllRead')
			->with(17)
			->willReturn(0);

		// Act
		$this->service->markAllRead(17);

		// Assert — expects($this->once()) above covers the assertion
	}
}
