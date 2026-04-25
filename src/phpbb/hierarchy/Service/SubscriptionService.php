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

namespace phpbb\hierarchy\Service;

use Doctrine\DBAL\Connection;
use phpbb\hierarchy\Contract\SubscriptionServiceInterface;

final class SubscriptionService implements SubscriptionServiceInterface
{
	private const WATCH_TABLE = 'phpbb_forums_watch';

	public function __construct(
		private readonly Connection $connection,
	) {
	}

	public function subscribe(int $forumId, int $userId): void
	{
		try {
			if ($this->isSubscribed($forumId, $userId)) {
				return;
			}

			$qb = $this->connection->createQueryBuilder();
			$qb->insert(self::WATCH_TABLE)
				->values([
					'forum_id'      => ':forumId',
					'user_id'       => ':userId',
					'notify_status' => '0',
				])
				->setParameter('forumId', $forumId)
				->setParameter('userId', $userId)
				->executeStatement();
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new \phpbb\db\Exception\RepositoryException('Failed to subscribe', previous: $e);
		}
	}

	public function unsubscribe(int $forumId, int $userId): void
	{
		try {
			$qb = $this->connection->createQueryBuilder();
			$qb->delete(self::WATCH_TABLE)
				->where($qb->expr()->eq('forum_id', ':forumId'))
				->andWhere($qb->expr()->eq('user_id', ':userId'))
				->setParameter('forumId', $forumId)
				->setParameter('userId', $userId)
				->executeStatement();
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new \phpbb\db\Exception\RepositoryException('Failed to unsubscribe', previous: $e);
		}
	}

	public function isSubscribed(int $forumId, int $userId): bool
	{
		try {
			$qb = $this->connection->createQueryBuilder();
			$result = $qb->select('user_id')
				->from(self::WATCH_TABLE)
				->where($qb->expr()->eq('forum_id', ':forumId'))
				->andWhere($qb->expr()->eq('user_id', ':userId'))
				->setParameter('forumId', $forumId)
				->setParameter('userId', $userId)
				->executeQuery()
				->fetchAssociative();

			return $result !== false;
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new \phpbb\db\Exception\RepositoryException('Failed to check subscription', previous: $e);
		}
	}
}
