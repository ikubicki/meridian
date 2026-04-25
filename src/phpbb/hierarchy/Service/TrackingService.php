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
use phpbb\hierarchy\Contract\ForumRepositoryInterface;
use phpbb\hierarchy\Contract\TrackingServiceInterface;

final class TrackingService implements TrackingServiceInterface
{
	private const FORUMS_TRACK_TABLE = 'phpbb_forums_track';
	private const MARK_TIME_TABLE = 'phpbb_topics_track';

	public function __construct(
		private readonly Connection $connection,
		private readonly ForumRepositoryInterface $repository,
	) {
	}

	public function markForumRead(int $forumId, int $userId): void
	{
		try {
			$markTime = time();

			$qb = $this->connection->createQueryBuilder();
			$existing = $qb->select('user_id')
				->from(self::FORUMS_TRACK_TABLE)
				->where($qb->expr()->eq('forum_id', ':forumId'))
				->andWhere($qb->expr()->eq('user_id', ':userId'))
				->setParameter('forumId', $forumId)
				->setParameter('userId', $userId)
				->executeQuery()
				->fetchAssociative();

			if ($existing !== false) {
				$qb2 = $this->connection->createQueryBuilder();
				$qb2->update(self::FORUMS_TRACK_TABLE)
					->set('mark_time', ':markTime')
					->where($qb2->expr()->eq('forum_id', ':forumId'))
					->andWhere($qb2->expr()->eq('user_id', ':userId'))
					->setParameter('markTime', $markTime)
					->setParameter('forumId', $forumId)
					->setParameter('userId', $userId)
					->executeStatement();
			} else {
				$qb3 = $this->connection->createQueryBuilder();
				$qb3->insert(self::FORUMS_TRACK_TABLE)
					->values([
						'user_id'   => ':userId',
						'forum_id'  => ':forumId',
						'mark_time' => ':markTime',
					])
					->setParameter('userId', $userId)
					->setParameter('forumId', $forumId)
					->setParameter('markTime', $markTime)
					->executeStatement();
			}
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new \phpbb\db\Exception\RepositoryException('Failed to mark forum read', previous: $e);
		}
	}

	public function hasUnread(int $forumId, int $userId): bool
	{
		try {
			$qb = $this->connection->createQueryBuilder();
			$row = $qb->select('mark_time')
				->from(self::FORUMS_TRACK_TABLE)
				->where($qb->expr()->eq('forum_id', ':forumId'))
				->andWhere($qb->expr()->eq('user_id', ':userId'))
				->setParameter('forumId', $forumId)
				->setParameter('userId', $userId)
				->executeQuery()
				->fetchAssociative();

			if ($row === false) {
				$forum = $this->repository->findById($forumId);
				if ($forum === null) {
					return false;
				}

				return $forum->stats->postsApproved > 0;
			}

			$markTime = (int) $row['mark_time'];

			$qb2 = $this->connection->createQueryBuilder();
			$count = (int) $qb2->select('COUNT(*)')
				->from(self::MARK_TIME_TABLE)
				->where($qb2->expr()->eq('forum_id', ':forumId'))
				->andWhere($qb2->expr()->gt('topic_last_post_time', ':markTime'))
				->setParameter('forumId', $forumId)
				->setParameter('markTime', $markTime)
				->executeQuery()
				->fetchOne();

			return $count > 0;
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new \phpbb\db\Exception\RepositoryException('Failed to check unread', previous: $e);
		}
	}
}
