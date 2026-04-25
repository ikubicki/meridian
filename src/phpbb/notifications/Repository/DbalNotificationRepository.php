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

namespace phpbb\notifications\Repository;

use phpbb\api\DTO\PaginationContext;
use phpbb\db\Exception\RepositoryException;
use phpbb\notifications\Contract\NotificationRepositoryInterface;
use phpbb\notifications\DTO\NotificationDTO;
use phpbb\notifications\Entity\Notification;
use phpbb\user\DTO\PaginatedResult;

/**
 * DBAL Notification Repository Implementation
 *
 * @TAG repository_implementation
 */
final class DbalNotificationRepository implements NotificationRepositoryInterface
{
	private const NOTIFICATIONS_TABLE = 'phpbb_notifications';
	private const TYPES_TABLE         = 'phpbb_notification_types';

	public function __construct(
		private readonly \Doctrine\DBAL\Connection $connection,
	) {
	}

	public function findById(int $notificationId, int $userId): ?Notification
	{
		try {
			$qb  = $this->connection->createQueryBuilder();
			$row = $qb->select(
				'n.notification_id',
				'n.notification_type_id',
				'n.item_id',
				'n.item_parent_id',
				'n.user_id',
				'n.notification_read',
				'n.notification_time',
				'n.notification_data',
				'nt.notification_type_name',
			)
				->from(self::NOTIFICATIONS_TABLE, 'n')
				->leftJoin('n', self::TYPES_TABLE, 'nt', 'n.notification_type_id = nt.notification_type_id')
				->where($qb->expr()->eq('n.notification_id', ':id'))
				->andWhere($qb->expr()->eq('n.user_id', ':userId'))
				->setParameter('id', $notificationId)
				->setParameter('userId', $userId)
				->setMaxResults(1)
				->executeQuery()
				->fetchAssociative();

			return $row !== false ? $this->hydrate($row) : null;
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to find notification by ID', previous: $e);
		}
	}

	public function countUnread(int $userId): int
	{
		try {
			return (int) $this->connection->createQueryBuilder()
				->select('COUNT(*)')
				->from(self::NOTIFICATIONS_TABLE)
				->where('user_id = :userId')
				->andWhere('notification_read = 0')
				->setParameter('userId', $userId)
				->executeQuery()
				->fetchOne();
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to count unread notifications', previous: $e);
		}
	}

	public function getLastModified(int $userId): ?int
	{
		try {
			$result = $this->connection->createQueryBuilder()
				->select('MAX(notification_time)')
				->from(self::NOTIFICATIONS_TABLE)
				->where('user_id = :userId')
				->setParameter('userId', $userId)
				->executeQuery()
				->fetchOne();

			return $result !== false && $result !== null ? (int) $result : null;
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to get last modified time', previous: $e);
		}
	}

	public function listByUser(int $userId, PaginationContext $ctx): PaginatedResult
	{
		try {
			$total = (int) $this->connection->createQueryBuilder()
				->select('COUNT(*)')
				->from(self::NOTIFICATIONS_TABLE)
				->where('user_id = :userId')
				->setParameter('userId', $userId)
				->executeQuery()
				->fetchOne();

			$offset = ($ctx->page - 1) * $ctx->perPage;

			$rows = $this->connection->createQueryBuilder()
				->select(
					'n.notification_id',
					'n.notification_type_id',
					'n.item_id',
					'n.item_parent_id',
					'n.user_id',
					'n.notification_read',
					'n.notification_time',
					'n.notification_data',
					'nt.notification_type_name',
				)
				->from(self::NOTIFICATIONS_TABLE, 'n')
				->leftJoin('n', self::TYPES_TABLE, 'nt', 'n.notification_type_id = nt.notification_type_id')
				->where('n.user_id = :userId')
				->setParameter('userId', $userId)
				->orderBy('n.notification_time', 'DESC')
				->setMaxResults($ctx->perPage)
				->setFirstResult($offset)
				->executeQuery()
				->fetchAllAssociative();

			$items = array_map(
				fn (array $row) => NotificationDTO::fromEntity($this->hydrate($row)),
				$rows,
			);

			return new PaginatedResult(
				items: $items,
				total: $total,
				page: $ctx->page,
				perPage: $ctx->perPage,
			);
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to list notifications by user', previous: $e);
		}
	}

	public function markRead(int $notificationId, int $userId): bool
	{
		try {
			$affected = $this->connection->createQueryBuilder()
				->update(self::NOTIFICATIONS_TABLE)
				->set('notification_read', '1')
				->where('notification_id = :id')
				->andWhere('user_id = :userId')
				->andWhere('notification_read = 0')
				->setParameter('id', $notificationId)
				->setParameter('userId', $userId)
				->executeStatement();

			return $affected > 0;
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to mark notification as read', previous: $e);
		}
	}

	public function markAllRead(int $userId): int
	{
		try {
			return (int) $this->connection->createQueryBuilder()
				->update(self::NOTIFICATIONS_TABLE)
				->set('notification_read', '1')
				->where('user_id = :userId')
				->andWhere('notification_read = 0')
				->setParameter('userId', $userId)
				->executeStatement();
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to mark all notifications as read', previous: $e);
		}
	}

	private function hydrate(array $row): Notification
	{
		return Notification::fromRow($row);
	}
}
