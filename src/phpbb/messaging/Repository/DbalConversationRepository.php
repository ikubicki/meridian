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

use Doctrine\DBAL\ParameterType;
use phpbb\api\DTO\PaginationContext;
use phpbb\db\Exception\RepositoryException;
use phpbb\messaging\Contract\ConversationRepositoryInterface;
use phpbb\messaging\DTO\ConversationDTO;
use phpbb\messaging\DTO\Request\CreateConversationRequest;
use phpbb\messaging\Entity\Conversation;
use phpbb\user\DTO\PaginatedResult;

/**
 * DBAL Conversation Repository Implementation
 *
 * @TAG repository_implementation
 */
class DbalConversationRepository implements ConversationRepositoryInterface
{
	private const TABLE = 'phpbb_messaging_conversations';
	private const PARTICIPANTS_TABLE = 'phpbb_messaging_participants';

	public function __construct(
		private readonly \Doctrine\DBAL\Connection $connection,
	) {
	}

	public function findById(int $conversationId): ?Conversation
	{
		try {
			$row = $this->connection->executeQuery(
				'SELECT conversation_id, participant_hash, title, created_by, created_at,
                        last_message_id, last_message_at, message_count, participant_count
                 FROM ' . self::TABLE . '
                 WHERE conversation_id = :id
                 LIMIT 1',
				['id' => $conversationId],
			)->fetchAssociative();

			return $row !== false ? $this->hydrate($row) : null;
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to find conversation by ID', previous: $e);
		}
	}

	public function findByParticipantHash(string $hash): ?Conversation
	{
		try {
			$row = $this->connection->executeQuery(
				'SELECT conversation_id, participant_hash, title, created_by, created_at,
                        last_message_id, last_message_at, message_count, participant_count
                 FROM ' . self::TABLE . '
                 WHERE participant_hash = :hash
                 LIMIT 1',
				['hash' => $hash],
			)->fetchAssociative();

			return $row !== false ? $this->hydrate($row) : null;
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to find conversation by participant hash', previous: $e);
		}
	}

	public function listByUser(int $userId, ?string $state, PaginationContext $ctx): PaginatedResult
	{
		try {
			// Count total conversations for user
			$countSql = '
				SELECT COUNT(*) FROM ' . self::TABLE . ' c
				INNER JOIN ' . self::PARTICIPANTS_TABLE . ' p ON c.conversation_id = p.conversation_id
				WHERE p.user_id = :userId AND p.left_at IS NULL
			';
			$countParams = ['userId' => $userId];

			if ($state !== null) {
				$countSql .= ' AND p.state = :state';
				$countParams['state'] = $state;
			}

			$total = (int) $this->connection->executeQuery($countSql, $countParams)->fetchOne();

			// Fetch paginated results
			$offset = ($ctx->page - 1) * $ctx->perPage;

			$sql = '
				SELECT c.conversation_id, c.participant_hash, c.title, c.created_by, c.created_at,
					   c.last_message_id, c.last_message_at, c.message_count, c.participant_count
				FROM ' . self::TABLE . ' c
				INNER JOIN ' . self::PARTICIPANTS_TABLE . ' p ON c.conversation_id = p.conversation_id
				WHERE p.user_id = :userId AND p.left_at IS NULL
			';
			$params = ['userId' => $userId];

			if ($state !== null) {
				$sql .= ' AND p.state = :state';
				$params['state'] = $state;
			}

			$sql .= ' ORDER BY c.last_message_at DESC, c.created_at DESC
					 LIMIT :limit OFFSET :offset';
			$params['limit'] = $ctx->perPage;
			$params['offset'] = $offset;

			$rows = $this->connection->executeQuery(
				$sql,
				$params,
				['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
			)->fetchAllAssociative();

			$items = array_map(
				fn (array $row) => ConversationDTO::fromEntity($this->hydrate($row)),
				$rows,
			);

			return new PaginatedResult(
				items: $items,
				total: $total,
				page: $ctx->page,
				perPage: $ctx->perPage,
			);
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to list conversations for user', previous: $e);
		}
	}

	public function insert(CreateConversationRequest $request, int $createdBy): int
	{
		try {
			// Generate participant hash from sorted user IDs
			$participantIds = array_merge([$createdBy], $request->participantIds);
			sort($participantIds);
			$hash = hash('sha256', implode(',', $participantIds));

			$now = time();

			$this->connection->executeStatement(
				'INSERT INTO ' . self::TABLE . '
                    (participant_hash, title, created_by, created_at, message_count, participant_count)
                 VALUES
                    (:hash, :title, :createdBy, :now, 0, :participantCount)',
				[
					'hash'             => $hash,
					'title'            => $request->title,
					'createdBy'        => $createdBy,
					'now'              => $now,
					'participantCount' => count($participantIds),
				],
			);

			return (int) $this->connection->lastInsertId();
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to insert conversation', previous: $e);
		}
	}

	public function update(int $conversationId, array $fields): void
	{
		try {
			if (empty($fields)) {
				return;
			}

			$set = [];
			$params = ['conversationId' => $conversationId];

			$allowed = ['title', 'last_message_id', 'last_message_at', 'message_count', 'participant_count', 'participant_hash'];
			foreach ($fields as $field => $value) {
				if (!in_array($field, $allowed, true)) {
					throw new RepositoryException("Invalid field: {$field}");
				}
				$set[] = $field . ' = :' . $field;
				$params[$field] = $value;
			}

			$this->connection->executeStatement(
				'UPDATE ' . self::TABLE . '
                 SET ' . implode(', ', $set) . '
                 WHERE conversation_id = :conversationId',
				$params,
			);
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to update conversation', previous: $e);
		}
	}

	public function delete(int $conversationId): void
	{
		try {
			$this->connection->executeStatement(
				'DELETE FROM ' . self::TABLE . ' WHERE conversation_id = :id',
				['id' => $conversationId],
			);
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to delete conversation', previous: $e);
		}
	}

	/**
	 * Hydrate a row from the database into a Conversation entity
	 *
	 * @param array<string, mixed> $row
	 */
	private function hydrate(array $row): Conversation
	{
		return new Conversation(
			id: (int) $row['conversation_id'],
			participantHash: (string) $row['participant_hash'],
			title: $row['title'] !== null ? (string) $row['title'] : null,
			createdBy: (int) $row['created_by'],
			createdAt: (int) $row['created_at'],
			lastMessageId: $row['last_message_id'] !== null ? (int) $row['last_message_id'] : null,
			lastMessageAt: $row['last_message_at'] !== null ? (int) $row['last_message_at'] : null,
			messageCount: (int) $row['message_count'],
			participantCount: (int) $row['participant_count'],
		);
	}
}
