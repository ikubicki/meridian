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
			$qb = $this->connection->createQueryBuilder();
			$row = $qb->select(
				'message_id',
				'conversation_id',
				'author_id',
				'message_text',
				'message_subject',
				'created_at',
				'edited_at',
				'edit_count',
				'metadata'
			)
				->from(self::TABLE)
				->where($qb->expr()->eq('message_id', ':id'))
				->setParameter('id', $messageId)
				->setMaxResults(1)
				->executeQuery()
				->fetchAssociative();

			return $row !== false ? $this->hydrate($row) : null;
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to find message by ID', previous: $e);
		}
	}

	public function listByConversation(int $conversationId, PaginationContext $ctx): PaginatedResult
	{
		try {
			$base = $this->connection->createQueryBuilder()
				->from(self::TABLE)
				->where('conversation_id = :conversationId')
				->setParameter('conversationId', $conversationId);

			$total = (int) (clone $base)->select('COUNT(*)')
				->executeQuery()
				->fetchOne();

			$offset = ($ctx->page - 1) * $ctx->perPage;

			$rows = (clone $base)
				->select(
					'message_id',
					'conversation_id',
					'author_id',
					'message_text',
					'message_subject',
					'created_at',
					'edited_at',
					'edit_count',
					'metadata'
				)
				->orderBy('created_at', 'ASC')
				->setMaxResults($ctx->perPage)
				->setFirstResult($offset)
				->executeQuery()
				->fetchAllAssociative();

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

			$base = $this->connection->createQueryBuilder()
				->from(self::TABLE)
				->where('conversation_id = :conversationId')
				->andWhere('(message_text LIKE :query OR message_subject LIKE :query)')
				->setParameter('conversationId', $conversationId)
				->setParameter('query', $searchTerm);

			$total = (int) (clone $base)->select('COUNT(*)')
				->executeQuery()
				->fetchOne();

			$offset = ($ctx->page - 1) * $ctx->perPage;

			$rows = (clone $base)
				->select(
					'message_id',
					'conversation_id',
					'author_id',
					'message_text',
					'message_subject',
					'created_at',
					'edited_at',
					'edit_count',
					'metadata'
				)
				->orderBy('created_at', 'ASC')
				->setMaxResults($ctx->perPage)
				->setFirstResult($offset)
				->executeQuery()
				->fetchAllAssociative();

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

			$qb = $this->connection->createQueryBuilder();
			$qb->insert(self::TABLE)
				->values([
					'conversation_id'  => ':conversationId',
					'author_id'        => ':authorId',
					'message_text'     => ':messageText',
					'message_subject'  => ':messageSubject',
					'created_at'       => ':now',
					'edit_count'       => '0',
					'metadata'         => ':metadata',
				])
				->setParameter('conversationId', $conversationId)
				->setParameter('authorId', $authorId)
				->setParameter('messageText', $request->messageText)
				->setParameter('messageSubject', $request->messageSubject)
				->setParameter('now', $now)
				->setParameter('metadata', $request->metadata)
				->executeStatement();

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

			$qb = $this->connection->createQueryBuilder();
			$qb->update(self::TABLE)
				->where($qb->expr()->eq('message_id', ':messageId'))
				->setParameter('messageId', $messageId);

			foreach ($fields as $field => $value) {
				if (!in_array($field, self::UPDATABLE_FIELDS, true)) {
					throw new \InvalidArgumentException('Unknown field: ' . $field);
				}
				$qb->set($field, ':' . $field)
					->setParameter($field, $value);
			}

			$qb->executeStatement();
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to update message', previous: $e);
		}
	}

	public function deletePerUser(int $messageId, int $userId): void
	{
		try {
			// Get conversation ID for the message
			$qb = $this->connection->createQueryBuilder();
			$conversationId = (int) $qb->select('conversation_id')
				->from(self::TABLE)
				->where($qb->expr()->eq('message_id', ':id'))
				->setParameter('id', $messageId)
				->executeQuery()
				->fetchOne();

			if ($conversationId === 0) {
				throw new RepositoryException('Message not found');
			}

			$now = time();

			// Check if soft-delete record already exists
			$qb2 = $this->connection->createQueryBuilder();
			$exists = $qb2->select('1')
				->from(self::DELETES_TABLE)
				->where($qb2->expr()->eq('conversation_id', ':conversationId'))
				->andWhere($qb2->expr()->eq('message_id', ':messageId'))
				->andWhere($qb2->expr()->eq('user_id', ':userId'))
				->setParameter('conversationId', $conversationId)
				->setParameter('messageId', $messageId)
				->setParameter('userId', $userId)
				->setMaxResults(1)
				->executeQuery()
				->fetchOne();

			if ($exists !== false) {
				// Update existing record
				$qb3 = $this->connection->createQueryBuilder();
				$qb3->update(self::DELETES_TABLE)
					->set('deleted_at', ':now')
					->where($qb3->expr()->eq('conversation_id', ':conversationId'))
					->andWhere($qb3->expr()->eq('message_id', ':messageId'))
					->andWhere($qb3->expr()->eq('user_id', ':userId'))
					->setParameter('now', $now)
					->setParameter('conversationId', $conversationId)
					->setParameter('messageId', $messageId)
					->setParameter('userId', $userId)
					->executeStatement();
			} else {
				// Insert new record
				$qb4 = $this->connection->createQueryBuilder();
				$qb4->insert(self::DELETES_TABLE)
					->values([
						'conversation_id' => ':conversationId',
						'message_id'      => ':messageId',
						'user_id'         => ':userId',
						'deleted_at'      => ':now',
					])
					->setParameter('conversationId', $conversationId)
					->setParameter('messageId', $messageId)
					->setParameter('userId', $userId)
					->setParameter('now', $now)
					->executeStatement();
			}
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to delete message per user', previous: $e);
		}
	}

	public function isDeletedForUser(int $messageId, int $userId): bool
	{
		try {
			$qb = $this->connection->createQueryBuilder();
			$result = $qb->select('1')
				->from(self::DELETES_TABLE)
				->where($qb->expr()->eq('message_id', ':messageId'))
				->andWhere($qb->expr()->eq('user_id', ':userId'))
				->setParameter('messageId', $messageId)
				->setParameter('userId', $userId)
				->setMaxResults(1)
				->executeQuery()
				->fetchOne();

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
