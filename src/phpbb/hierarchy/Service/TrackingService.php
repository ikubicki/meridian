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

			$existing = $this->connection->executeQuery(
				'SELECT user_id FROM ' . self::FORUMS_TRACK_TABLE . ' WHERE forum_id = :forumId AND user_id = :userId',
				['forumId' => $forumId, 'userId' => $userId]
			)->fetchAssociative();

			if ($existing !== false) {
				$this->connection->executeStatement(
					'UPDATE ' . self::FORUMS_TRACK_TABLE . ' SET mark_time = :markTime WHERE forum_id = :forumId AND user_id = :userId',
					['markTime' => $markTime, 'forumId' => $forumId, 'userId' => $userId]
				);
			} else {
				$this->connection->executeStatement(
					'INSERT INTO ' . self::FORUMS_TRACK_TABLE . ' (user_id, forum_id, mark_time) VALUES (:userId, :forumId, :markTime)',
					['userId' => $userId, 'forumId' => $forumId, 'markTime' => $markTime]
				);
			}
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new \phpbb\db\Exception\RepositoryException('Failed to mark forum read', previous: $e);
		}
	}

	public function hasUnread(int $forumId, int $userId): bool
	{
		try {
			$row = $this->connection->executeQuery(
				'SELECT mark_time FROM ' . self::FORUMS_TRACK_TABLE . ' WHERE forum_id = :forumId AND user_id = :userId',
				['forumId' => $forumId, 'userId' => $userId]
			)->fetchAssociative();

			if ($row === false) {
				$forum = $this->repository->findById($forumId);
				if ($forum === null) {
					return false;
				}

				return $forum->stats->postsApproved > 0;
			}

			$markTime = (int) $row['mark_time'];
			$result = $this->connection->executeQuery(
				'SELECT COUNT(*) FROM ' . self::MARK_TIME_TABLE . ' WHERE forum_id = :forumId AND topic_last_post_time > :markTime',
				['forumId' => $forumId, 'markTime' => $markTime]
			)->fetchOne();

			return (int) $result > 0;
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new \phpbb\db\Exception\RepositoryException('Failed to check unread', previous: $e);
		}
	}
}
