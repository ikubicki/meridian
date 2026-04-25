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

namespace phpbb\notifications\Listener;

use phpbb\cache\CachePoolFactoryInterface;
use phpbb\cache\TagAwareCacheInterface;
use phpbb\notifications\Event\NotificationReadEvent;
use phpbb\notifications\Event\NotificationsReadAllEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Cache Invalidation Subscriber
 *
 * Invalidates notification cache tags when read events are dispatched.
 *
 * @TAG listener
 */
final class CacheInvalidationSubscriber implements EventSubscriberInterface
{
	private readonly TagAwareCacheInterface $cache;

	public function __construct(CachePoolFactoryInterface $cacheFactory)
	{
		$this->cache = $cacheFactory->getPool('notifications');
	}

	public static function getSubscribedEvents(): array
	{
		return [
			NotificationReadEvent::class => 'onNotificationRead',
			NotificationsReadAllEvent::class => 'onNotificationsReadAll',
		];
	}

	public function onNotificationRead(NotificationReadEvent $event): void
	{
		$this->cache->invalidateTags(["user:{$event->actorId}"]);
	}

	public function onNotificationsReadAll(NotificationsReadAllEvent $event): void
	{
		$this->cache->invalidateTags(["user:{$event->actorId}"]);
	}
}
