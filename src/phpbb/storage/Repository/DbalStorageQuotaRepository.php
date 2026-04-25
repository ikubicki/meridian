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
			$qb = $this->connection->createQueryBuilder();
			$row = $qb->select('user_id', 'forum_id', 'used_bytes', 'max_bytes', 'updated_at')
				->from(self::TABLE)
				->where($qb->expr()->eq('user_id', ':userId'))
				->andWhere($qb->expr()->eq('forum_id', ':forumId'))
				->setParameter('userId', $userId)
				->setParameter('forumId', $forumId)
				->setMaxResults(1)
				->executeQuery()
				->fetchAssociative();

			return $row !== false ? $this->hydrate($row) : null;
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to find storage quota', previous: $e);
		}
	}

	public function incrementUsage(int $userId, int $forumId, int $bytes): bool
	{
		try {
			$qb = $this->connection->createQueryBuilder();
			$affected = $qb->update(self::TABLE)
				->set('used_bytes', 'used_bytes + :bytes')
				->set('updated_at', ':now')
				->where($qb->expr()->eq('user_id', ':userId'))
				->andWhere($qb->expr()->eq('forum_id', ':forumId'))
				->andWhere('used_bytes + :bytes <= max_bytes')
				->setParameter('bytes', $bytes)
				->setParameter('now', time())
				->setParameter('userId', $userId)
				->setParameter('forumId', $forumId)
				->executeStatement();

			return $affected > 0;
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to increment storage usage', previous: $e);
		}
	}

	public function decrementUsage(int $userId, int $forumId, int $bytes): void
	{
		try {
			$qb = $this->connection->createQueryBuilder();
			// Portable replacement for GREATEST(0, used_bytes - :bytes) — MySQL-only scalar function
			$qb->update(self::TABLE)
				->set('used_bytes', 'CASE WHEN used_bytes >= :bytes THEN used_bytes - :bytes ELSE 0 END')
				->set('updated_at', ':now')
				->where($qb->expr()->eq('user_id', ':userId'))
				->andWhere($qb->expr()->eq('forum_id', ':forumId'))
				->setParameter('bytes', $bytes)
				->setParameter('now', time())
				->setParameter('userId', $userId)
				->setParameter('forumId', $forumId)
				->executeStatement();
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to decrement storage usage', previous: $e);
		}
	}

	public function reconcile(int $userId, int $forumId, int $actualBytes): void
	{
		try {
			$qb = $this->connection->createQueryBuilder();
			$qb->update(self::TABLE)
				->set('used_bytes', ':actualBytes')
				->set('updated_at', ':now')
				->where($qb->expr()->eq('user_id', ':userId'))
				->andWhere($qb->expr()->eq('forum_id', ':forumId'))
				->setParameter('actualBytes', $actualBytes)
				->setParameter('now', time())
				->setParameter('userId', $userId)
				->setParameter('forumId', $forumId)
				->executeStatement();
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to reconcile storage quota', previous: $e);
		}
	}

	public function findAllUserForumPairs(): array
	{
		try {
			$qb = $this->connection->createQueryBuilder();

			return $qb->select('user_id', 'forum_id')
				->from(self::TABLE)
				->executeQuery()
				->fetchAllAssociative();
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to find user/forum quota pairs', previous: $e);
		}
	}

	public function initDefault(int $userId, int $forumId): void
	{
		try {
			$qb = $this->connection->createQueryBuilder();
			$qb->insert(self::TABLE)
				->values([
					'user_id'    => ':userId',
					'forum_id'   => ':forumId',
					'used_bytes' => '0',
					'max_bytes'  => ':maxBytes',
					'updated_at' => ':now',
				])
				->setParameter('userId', $userId)
				->setParameter('forumId', $forumId)
				->setParameter('maxBytes', \PHP_INT_MAX)
				->setParameter('now', time())
				->executeStatement();
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
