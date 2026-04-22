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

			$this->connection->executeStatement(
				'INSERT INTO ' . self::WATCH_TABLE . ' (forum_id, user_id, notify_status) VALUES (:forumId, :userId, :notifyStatus)',
				['forumId' => $forumId, 'userId' => $userId, 'notifyStatus' => 0]
			);
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new \phpbb\db\Exception\RepositoryException('Failed to subscribe', previous: $e);
		}
	}

	public function unsubscribe(int $forumId, int $userId): void
	{
		try {
			$this->connection->executeStatement(
				'DELETE FROM ' . self::WATCH_TABLE . ' WHERE forum_id = :forumId AND user_id = :userId',
				['forumId' => $forumId, 'userId' => $userId]
			);
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new \phpbb\db\Exception\RepositoryException('Failed to unsubscribe', previous: $e);
		}
	}

	public function isSubscribed(int $forumId, int $userId): bool
	{
		try {
			$result = $this->connection->executeQuery(
				'SELECT user_id FROM ' . self::WATCH_TABLE . ' WHERE forum_id = :forumId AND user_id = :userId',
				['forumId' => $forumId, 'userId' => $userId]
			)->fetchAssociative();

			return $result !== false;
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new \phpbb\db\Exception\RepositoryException('Failed to check subscription', previous: $e);
		}
	}
}
