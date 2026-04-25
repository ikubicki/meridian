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
			$rows = $this->connection->executeQuery(
				'SELECT conversation_id, user_id, role, state, joined_at, left_at,
                        last_read_message_id, last_read_at, is_muted, is_blocked
                 FROM ' . self::TABLE . '
                 WHERE conversation_id = :conversationId',
				['conversationId' => $conversationId],
			)->fetchAllAssociative();

			return array_map($this->hydrate(...), $rows);
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to find participants by conversation', previous: $e);
		}
	}

	public function findByUser(int $userId): array
	{
		try {
			$rows = $this->connection->executeQuery(
				'SELECT conversation_id, user_id, role, state, joined_at, left_at,
                        last_read_message_id, last_read_at, is_muted, is_blocked
                 FROM ' . self::TABLE . '
                 WHERE user_id = :userId',
				['userId' => $userId],
			)->fetchAllAssociative();

			return array_map($this->hydrate(...), $rows);
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to find participants by user', previous: $e);
		}
	}

	public function findByConversationAndUser(int $conversationId, int $userId): ?Participant
	{
		try {
			$row = $this->connection->executeQuery(
				'SELECT conversation_id, user_id, role, state, joined_at, left_at,
                        last_read_message_id, last_read_at, is_muted, is_blocked
                 FROM ' . self::TABLE . '
                 WHERE conversation_id = :conversationId AND user_id = :userId
                 LIMIT 1',
				['conversationId' => $conversationId, 'userId' => $userId],
			)->fetchAssociative();

			return $row !== false ? $this->hydrate($row) : null;
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to find participant by conversation and user', previous: $e);
		}
	}

	public function insert(int $conversationId, int $userId, string $role = 'member'): void
	{
		try {
			$now = time();

			$this->connection->executeStatement(
				'INSERT INTO ' . self::TABLE . '
                    (conversation_id, user_id, role, state, joined_at, is_muted, is_blocked)
                 VALUES
                    (:conversationId, :userId, :role, "active", :now, 0, 0)',
				[
					'conversationId' => $conversationId,
					'userId'         => $userId,
					'role'           => $role,
					'now'            => $now,
				],
			);
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

			$set = [];
			$params = ['conversationId' => $conversationId, 'userId' => $userId];

			foreach ($fields as $field => $value) {
				if (!in_array($field, self::UPDATABLE_FIELDS, true)) {
					throw new \InvalidArgumentException('Unknown field: ' . $field);
				}
				$set[] = $field . ' = :' . $field;
				$params[$field] = $value;
			}

			$this->connection->executeStatement(
				'UPDATE ' . self::TABLE . '
                 SET ' . implode(', ', $set) . '
                 WHERE conversation_id = :conversationId AND user_id = :userId',
				$params,
			);
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to update participant', previous: $e);
		}
	}

	public function delete(int $conversationId, int $userId): void
	{
		try {
			$this->connection->executeStatement(
				'DELETE FROM ' . self::TABLE . '
                 WHERE conversation_id = :conversationId AND user_id = :userId',
				['conversationId' => $conversationId, 'userId' => $userId],
			);
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
