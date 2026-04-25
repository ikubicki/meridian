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

namespace phpbb\messaging\Repository;

use phpbb\db\Exception\RepositoryException;
use phpbb\messaging\Contract\ParticipantRepositoryInterface;
use phpbb\messaging\Entity\Participant;

/**
 * DBAL Participant Repository Implementation
 *
 * @TAG repository_implementation
 */
class DbalParticipantRepository implements ParticipantRepositoryInterface
{
	private const TABLE = 'phpbb_messaging_participants';

	public function __construct(
		private readonly \Doctrine\DBAL\Connection $connection,
	) {
	}

	public function findByConversation(int $conversationId): array
	{
		try {
			$qb = $this->connection->createQueryBuilder();
			$rows = $qb->select(
				'conversation_id',
				'user_id',
				'role',
				'state',
				'joined_at',
				'left_at',
				'last_read_message_id',
				'last_read_at',
				'is_muted',
				'is_blocked'
			)
				->from(self::TABLE)
				->where($qb->expr()->eq('conversation_id', ':conversationId'))
				->setParameter('conversationId', $conversationId)
				->executeQuery()
				->fetchAllAssociative();

			return array_map($this->hydrate(...), $rows);
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to find participants by conversation', previous: $e);
		}
	}

	public function findByUser(int $userId): array
	{
		try {
			$qb = $this->connection->createQueryBuilder();
			$rows = $qb->select(
				'conversation_id',
				'user_id',
				'role',
				'state',
				'joined_at',
				'left_at',
				'last_read_message_id',
				'last_read_at',
				'is_muted',
				'is_blocked'
			)
				->from(self::TABLE)
				->where($qb->expr()->eq('user_id', ':userId'))
				->setParameter('userId', $userId)
				->executeQuery()
				->fetchAllAssociative();

			return array_map($this->hydrate(...), $rows);
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to find participants by user', previous: $e);
		}
	}

	public function findByConversationAndUser(int $conversationId, int $userId): ?Participant
	{
		try {
			$qb = $this->connection->createQueryBuilder();
			$row = $qb->select(
				'conversation_id',
				'user_id',
				'role',
				'state',
				'joined_at',
				'left_at',
				'last_read_message_id',
				'last_read_at',
				'is_muted',
				'is_blocked'
			)
				->from(self::TABLE)
				->where($qb->expr()->eq('conversation_id', ':conversationId'))
				->andWhere($qb->expr()->eq('user_id', ':userId'))
				->setParameter('conversationId', $conversationId)
				->setParameter('userId', $userId)
				->setMaxResults(1)
				->executeQuery()
				->fetchAssociative();

			return $row !== false ? $this->hydrate($row) : null;
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to find participant by conversation and user', previous: $e);
		}
	}

	public function insert(int $conversationId, int $userId, string $role = 'member'): void
	{
		try {
			$now = time();

			$qb = $this->connection->createQueryBuilder();
			$qb->insert(self::TABLE)
				->values([
					'conversation_id' => ':conversationId',
					'user_id'         => ':userId',
					'role'            => ':role',
					'state'           => '"active"',
					'joined_at'       => ':now',
					'is_muted'        => '0',
					'is_blocked'      => '0',
				])
				->setParameter('conversationId', $conversationId)
				->setParameter('userId', $userId)
				->setParameter('role', $role)
				->setParameter('now', $now)
				->executeStatement();
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to insert participant', previous: $e);
		}
	}

	private const UPDATABLE_FIELDS = ['state', 'is_muted', 'is_blocked', 'role', 'last_read_at', 'last_read_message_id', 'left_at'];

	public function update(int $conversationId, int $userId, array $fields): void
	{
		try {
			if (empty($fields)) {
				return;
			}

			$qb = $this->connection->createQueryBuilder();
			$qb->update(self::TABLE)
				->where($qb->expr()->eq('conversation_id', ':conversationId'))
				->andWhere($qb->expr()->eq('user_id', ':userId'))
				->setParameter('conversationId', $conversationId)
				->setParameter('userId', $userId);

			foreach ($fields as $field => $value) {
				if (!in_array($field, self::UPDATABLE_FIELDS, true)) {
					throw new \InvalidArgumentException('Unknown field: ' . $field);
				}
				$qb->set($field, ':' . $field)
					->setParameter($field, $value);
			}

			$qb->executeStatement();
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to update participant', previous: $e);
		}
	}

	public function delete(int $conversationId, int $userId): void
	{
		try {
			$qb = $this->connection->createQueryBuilder();
			$qb->delete(self::TABLE)
				->where($qb->expr()->eq('conversation_id', ':conversationId'))
				->andWhere($qb->expr()->eq('user_id', ':userId'))
				->setParameter('conversationId', $conversationId)
				->setParameter('userId', $userId)
				->executeStatement();
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to delete participant', previous: $e);
		}
	}

	/**
	 * Hydrate a row from the database into a Participant entity
	 *
	 * @param array<string, mixed> $row
	 */
	private function hydrate(array $row): Participant
	{
		return new Participant(
			conversationId: (int) $row['conversation_id'],
			userId: (int) $row['user_id'],
			role: (string) $row['role'],
			state: (string) $row['state'],
			joinedAt: (int) $row['joined_at'],
			leftAt: $row['left_at'] !== null ? (int) $row['left_at'] : null,
			lastReadMessageId: $row['last_read_message_id'] !== null ? (int) $row['last_read_message_id'] : null,
			lastReadAt: $row['last_read_at'] !== null ? (int) $row['last_read_at'] : null,
			isMuted: (bool) $row['is_muted'],
			isBlocked: (bool) $row['is_blocked'],
		);
	}
}
