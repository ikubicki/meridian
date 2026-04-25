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
use phpbb\messaging\Contract\MessageRepositoryInterface;
use phpbb\messaging\DTO\MessageDTO;
use phpbb\messaging\DTO\Request\SendMessageRequest;
use phpbb\messaging\Entity\Message;
use phpbb\user\DTO\PaginatedResult;

/**
 * DBAL Message Repository Implementation
 *
 * @TAG repository_implementation
 */
class DbalMessageRepository implements MessageRepositoryInterface
{
	private const TABLE = 'phpbb_messaging_messages';
	private const DELETES_TABLE = 'phpbb_messaging_message_deletes';

	public function __construct(
		private readonly \Doctrine\DBAL\Connection $connection,
	) {
	}

	public function findById(int $messageId): ?Message
	{
		try {
			$row = $this->connection->executeQuery(
				'SELECT message_id, conversation_id, author_id, message_text, message_subject,
                        created_at, edited_at, edit_count, metadata
                 FROM ' . self::TABLE . '
                 WHERE message_id = :id
                 LIMIT 1',
				['id' => $messageId],
			)->fetchAssociative();

			return $row !== false ? $this->hydrate($row) : null;
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to find message by ID', previous: $e);
		}
	}

	public function listByConversation(int $conversationId, PaginationContext $ctx): PaginatedResult
	{
		try {
			// Count total messages (excluding soft-deleted)
			$total = (int) $this->connection->executeQuery(
				'SELECT COUNT(*) FROM ' . self::TABLE . '
                 WHERE conversation_id = :conversationId',
				['conversationId' => $conversationId],
			)->fetchOne();

			$offset = ($ctx->page - 1) * $ctx->perPage;

			$rows = $this->connection->executeQuery(
				'SELECT message_id, conversation_id, author_id, message_text, message_subject,
                        created_at, edited_at, edit_count, metadata
                 FROM ' . self::TABLE . '
                 WHERE conversation_id = :conversationId
                 ORDER BY created_at ASC
                 LIMIT :limit OFFSET :offset',
				['conversationId' => $conversationId, 'limit' => $ctx->perPage, 'offset' => $offset],
				['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
			)->fetchAllAssociative();

			$items = array_map(
				fn (array $row) => MessageDTO::fromEntity($this->hydrate($row)),
				$rows,
			);

			return new PaginatedResult(
				items: $items,
				total: $total,
				page: $ctx->page,
				perPage: $ctx->perPage,
			);
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to list messages by conversation', previous: $e);
		}
	}

	public function search(int $conversationId, string $query, PaginationContext $ctx): PaginatedResult
	{
		try {
			$searchTerm = '%' . addcslashes($query, '%_') . '%';

			// Count matching messages
			$total = (int) $this->connection->executeQuery(
				'SELECT COUNT(*) FROM ' . self::TABLE . '
                 WHERE conversation_id = :conversationId
                 AND (message_text LIKE :query OR message_subject LIKE :query)',
				['conversationId' => $conversationId, 'query' => $searchTerm],
			)->fetchOne();

			$offset = ($ctx->page - 1) * $ctx->perPage;

			$rows = $this->connection->executeQuery(
				'SELECT message_id, conversation_id, author_id, message_text, message_subject,
                        created_at, edited_at, edit_count, metadata
                 FROM ' . self::TABLE . '
                 WHERE conversation_id = :conversationId
                 AND (message_text LIKE :query OR message_subject LIKE :query)
                 ORDER BY created_at ASC
                 LIMIT :limit OFFSET :offset',
				[
					'conversationId' => $conversationId,
					'query'          => $searchTerm,
					'limit'          => $ctx->perPage,
					'offset'         => $offset,
				],
				['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
			)->fetchAllAssociative();

			$items = array_map(
				fn (array $row) => MessageDTO::fromEntity($this->hydrate($row)),
				$rows,
			);

			return new PaginatedResult(
				items: $items,
				total: $total,
				page: $ctx->page,
				perPage: $ctx->perPage,
			);
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to search messages', previous: $e);
		}
	}

	public function insert(int $conversationId, SendMessageRequest $request, int $authorId): int
	{
		try {
			$now = time();

			$this->connection->executeStatement(
				'INSERT INTO ' . self::TABLE . '
                    (conversation_id, author_id, message_text, message_subject, created_at, edit_count, metadata)
                 VALUES
                    (:conversationId, :authorId, :messageText, :messageSubject, :now, 0, :metadata)',
				[
					'conversationId' => $conversationId,
					'authorId'       => $authorId,
					'messageText'    => $request->messageText,
					'messageSubject' => $request->messageSubject,
					'now'            => $now,
					'metadata'       => $request->metadata,
				],
			);

			return (int) $this->connection->lastInsertId();
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to insert message', previous: $e);
		}
	}

	private const UPDATABLE_FIELDS = ['message_text', 'message_subject', 'edited_at', 'edit_count', 'metadata'];

	public function update(int $messageId, array $fields): void
	{
		try {
			if (empty($fields)) {
				return;
			}

			$set = [];
			$params = ['messageId' => $messageId];

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
                 WHERE message_id = :messageId',
				$params,
			);
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to update message', previous: $e);
		}
	}

	public function deletePerUser(int $messageId, int $userId): void
	{
		try {
			// Get conversation ID for the message
			$conversationId = (int) $this->connection->executeQuery(
				'SELECT conversation_id FROM ' . self::TABLE . ' WHERE message_id = :id',
				['id' => $messageId],
			)->fetchOne();

			if ($conversationId === 0) {
				throw new RepositoryException('Message not found');
			}

			$now = time();

			// Check if soft-delete record already exists
			$exists = $this->connection->executeQuery(
				'SELECT 1 FROM ' . self::DELETES_TABLE . '
                 WHERE conversation_id = :conversationId AND message_id = :messageId AND user_id = :userId
                 LIMIT 1',
				['conversationId' => $conversationId, 'messageId' => $messageId, 'userId' => $userId],
			)->fetchOne();

			if ($exists !== false) {
				// Update existing record
				$this->connection->executeStatement(
					'UPDATE ' . self::DELETES_TABLE . '
                     SET deleted_at = :now
                     WHERE conversation_id = :conversationId AND message_id = :messageId AND user_id = :userId',
					[
						'conversationId' => $conversationId,
						'messageId'      => $messageId,
						'userId'         => $userId,
						'now'            => $now,
					],
				);
			} else {
				// Insert new record
				$this->connection->executeStatement(
					'INSERT INTO ' . self::DELETES_TABLE . '
                        (conversation_id, message_id, user_id, deleted_at)
                     VALUES
                        (:conversationId, :messageId, :userId, :now)',
					[
						'conversationId' => $conversationId,
						'messageId'      => $messageId,
						'userId'         => $userId,
						'now'            => $now,
					],
				);
			}
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to delete message per user', previous: $e);
		}
	}

	public function isDeletedForUser(int $messageId, int $userId): bool
	{
		try {
			$result = $this->connection->executeQuery(
				'SELECT 1 FROM ' . self::DELETES_TABLE . '
                 WHERE message_id = :messageId AND user_id = :userId
                 LIMIT 1',
				['messageId' => $messageId, 'userId' => $userId],
			)->fetchOne();

			return $result !== false;
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to check if message is deleted for user', previous: $e);
		}
	}

	/**
	 * Hydrate a row from the database into a Message entity
	 *
	 * @param array<string, mixed> $row
	 */
	private function hydrate(array $row): Message
	{
		return new Message(
			id: (int) $row['message_id'],
			conversationId: (int) $row['conversation_id'],
			authorId: (int) $row['author_id'],
			messageText: (string) $row['message_text'],
			messageSubject: $row['message_subject'] !== null ? (string) $row['message_subject'] : null,
			createdAt: (int) $row['created_at'],
			editedAt: $row['edited_at'] !== null ? (int) $row['edited_at'] : null,
			editCount: (int) $row['edit_count'],
			metadata: $row['metadata'] !== null ? (string) $row['metadata'] : null,
		);
	}
}
