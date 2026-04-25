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

namespace phpbb\storage\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use phpbb\db\Exception\RepositoryException;
use phpbb\storage\Contract\StorageQuotaRepositoryInterface;
use phpbb\storage\Entity\StorageQuota;

final class DbalStorageQuotaRepository implements StorageQuotaRepositoryInterface
{
	private const TABLE = 'phpbb_storage_quotas';

	public function __construct(
		private readonly Connection $connection,
	) {
	}

	public function findByUserAndForum(int $userId, int $forumId): ?StorageQuota
	{
		try {
			$row = $this->connection->executeQuery(
				'SELECT user_id, forum_id, used_bytes, max_bytes, updated_at
				 FROM ' . self::TABLE . '
				 WHERE user_id = :user_id AND forum_id = :forum_id
				 LIMIT 1',
				['user_id' => $userId, 'forum_id' => $forumId],
			)->fetchAssociative();

			return $row !== false ? $this->hydrate($row) : null;
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to find storage quota', previous: $e);
		}
	}

	public function incrementUsage(int $userId, int $forumId, int $bytes): bool
	{
		try {
			$affected = $this->connection->executeStatement(
				'UPDATE ' . self::TABLE . '
				 SET used_bytes = used_bytes + :bytes, updated_at = :now
				 WHERE user_id = :user_id AND forum_id = :forum_id
				   AND used_bytes + :bytes <= max_bytes',
				[
					'bytes'    => $bytes,
					'now'      => time(),
					'user_id'  => $userId,
					'forum_id' => $forumId,
				],
			);

			return $affected > 0;
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to increment storage usage', previous: $e);
		}
	}

	public function decrementUsage(int $userId, int $forumId, int $bytes): void
	{
		try {
			$this->connection->executeStatement(
				'UPDATE ' . self::TABLE . '
				 SET used_bytes = GREATEST(0, used_bytes - :bytes), updated_at = :now
				 WHERE user_id = :user_id AND forum_id = :forum_id',
				[
					'bytes'    => $bytes,
					'now'      => time(),
					'user_id'  => $userId,
					'forum_id' => $forumId,
				],
			);
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to decrement storage usage', previous: $e);
		}
	}

	public function reconcile(int $userId, int $forumId, int $actualBytes): void
	{
		try {
			$this->connection->executeStatement(
				'UPDATE ' . self::TABLE . '
				 SET used_bytes = :actual_bytes, updated_at = :now
				 WHERE user_id = :user_id AND forum_id = :forum_id',
				[
					'actual_bytes' => $actualBytes,
					'now'          => time(),
					'user_id'      => $userId,
					'forum_id'     => $forumId,
				],
			);
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to reconcile storage quota', previous: $e);
		}
	}

	public function findAllUserForumPairs(): array
	{
		try {
			return $this->connection->executeQuery(
				'SELECT user_id, forum_id FROM ' . self::TABLE,
			)->fetchAllAssociative();
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to find user/forum quota pairs', previous: $e);
		}
	}

	public function initDefault(int $userId, int $forumId): void
	{
		try {
			$this->connection->executeStatement(
				'INSERT INTO ' . self::TABLE . '
				 (user_id, forum_id, used_bytes, max_bytes, updated_at)
				 VALUES (:user_id, :forum_id, 0, :max_bytes, :now)',
				[
					'user_id'   => $userId,
					'forum_id'  => $forumId,
					'max_bytes' => \PHP_INT_MAX,
					'now'       => time(),
				],
			);
		} catch (UniqueConstraintViolationException) {
			// Row already exists — no-op, which is the intended behaviour
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to initialise default quota', previous: $e);
		}
	}

	private function hydrate(array $row): StorageQuota
	{
		return new StorageQuota(
			userId:     (int) $row['user_id'],
			forumId:    (int) $row['forum_id'],
			usedBytes:  (int) $row['used_bytes'],
			maxBytes:   (int) $row['max_bytes'],
			updatedAt:  (int) $row['updated_at'],
		);
	}
}
