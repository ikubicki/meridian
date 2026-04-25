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

namespace phpbb\notifications\Service;

use phpbb\api\DTO\PaginationContext;
use phpbb\cache\CachePoolFactoryInterface;
use phpbb\cache\TagAwareCacheInterface;
use phpbb\common\Event\DomainEventCollection;
use phpbb\notifications\Contract\NotificationRepositoryInterface;
use phpbb\notifications\Contract\NotificationServiceInterface;
use phpbb\notifications\DTO\NotificationDTO;
use phpbb\notifications\Event\NotificationReadEvent;
use phpbb\notifications\Event\NotificationsReadAllEvent;
use phpbb\user\DTO\PaginatedResult;

/**
 * Notification Service
 *
 * Handles notification reads and cache-backed queries.
 *
 * @TAG service
 */
final class NotificationService implements NotificationServiceInterface
{
	private readonly TagAwareCacheInterface $cache;

	public function __construct(
		private readonly NotificationRepositoryInterface $repository,
		CachePoolFactoryInterface $cacheFactory,
	) {
		$this->cache = $cacheFactory->getPool('notifications');
	}

	public function getUnreadCount(int $userId): int
	{
		return $this->cache->getOrCompute(
			"user:{$userId}:count",
			fn () => $this->repository->countUnread($userId),
			30,
			["user:{$userId}"],
		);
	}

	public function getLastModified(int $userId): ?int
	{
		return $this->repository->getLastModified($userId);
	}

	public function getNotifications(int $userId, PaginationContext $ctx): PaginatedResult
	{
		/** @var array{items: list<array<string,mixed>>, total: int, page: int, perPage: int}|null $cached */
		$cached = $this->cache->getOrCompute(
			"user:{$userId}:notifications:{$ctx->page}:{$ctx->perPage}",
			function () use ($userId, $ctx) {
				$result = $this->repository->listByUser($userId, $ctx);

				return [
					'items'   => array_map(fn (NotificationDTO $dto) => $dto->toArray(), $result->items),
					'total'   => $result->total,
					'page'    => $result->page,
					'perPage' => $result->perPage,
				];
			},
			30,
			["user:{$userId}"],
		);

		return new PaginatedResult(
			items: array_map(
				fn (array $a) => new NotificationDTO(
					id:        (int) $a['id'],
					type:      (string) $a['type'],
					unread:    (bool) $a['unread'],
					createdAt: (int) $a['createdAt'],
					data:      (array) $a['data'],
				),
				$cached['items'],
			),
			total:   $cached['total'],
			page:    $cached['page'],
			perPage: $cached['perPage'],
		);
	}

	public function markRead(int $notificationId, int $userId): DomainEventCollection
	{
		if (!$this->repository->markRead($notificationId, $userId)) {
			throw new \InvalidArgumentException("Notification {$notificationId} not found for user {$userId}");
		}

		return new DomainEventCollection([new NotificationReadEvent(entityId: $notificationId, actorId: $userId)]);
	}

	public function markAllRead(int $userId): DomainEventCollection
	{
		$this->repository->markAllRead($userId);

		return new DomainEventCollection([new NotificationsReadAllEvent(entityId: $userId, actorId: $userId)]);
	}
}
