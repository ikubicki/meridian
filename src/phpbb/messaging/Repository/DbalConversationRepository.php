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
			$qb = $this->connection->createQueryBuilder();
			$row = $qb->select(
				'conversation_id',
				'participant_hash',
				'title',
				'created_by',
				'created_at',
				'last_message_id',
				'last_message_at',
				'message_count',
				'participant_count'
			)
				->from(self::TABLE)
				->where($qb->expr()->eq('conversation_id', ':id'))
				->setParameter('id', $conversationId)
				->setMaxResults(1)
				->executeQuery()
				->fetchAssociative();

			return $row !== false ? $this->hydrate($row) : null;
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to find conversation by ID', previous: $e);
		}
	}

	public function findByParticipantHash(string $hash): ?Conversation
	{
		try {
			$qb = $this->connection->createQueryBuilder();
			$row = $qb->select(
				'conversation_id',
				'participant_hash',
				'title',
				'created_by',
				'created_at',
				'last_message_id',
				'last_message_at',
				'message_count',
				'participant_count'
			)
				->from(self::TABLE)
				->where($qb->expr()->eq('participant_hash', ':hash'))
				->setParameter('hash', $hash)
				->setMaxResults(1)
				->executeQuery()
				->fetchAssociative();

			return $row !== false ? $this->hydrate($row) : null;
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to find conversation by participant hash', previous: $e);
		}
	}

	public function listByUser(int $userId, ?string $state, PaginationContext $ctx): PaginatedResult
	{
		try {
			$base = $this->connection->createQueryBuilder()
				->from(self::TABLE, 'c')
				->innerJoin('c', self::PARTICIPANTS_TABLE, 'p', 'c.conversation_id = p.conversation_id')
				->where('p.user_id = :userId')
				->andWhere($this->connection->createQueryBuilder()->expr()->isNull('p.left_at'))
				->setParameter('userId', $userId);

			if ($state !== null) {
				$base->andWhere('p.state = :state')
					->setParameter('state', $state);
			}

			$total = (int) (clone $base)->select('COUNT(*)')
				->executeQuery()
				->fetchOne();

			$offset = ($ctx->page - 1) * $ctx->perPage;

			$rows = (clone $base)
				->select(
					'c.conversation_id',
					'c.participant_hash',
					'c.title',
					'c.created_by',
					'c.created_at',
					'c.last_message_id',
					'c.last_message_at',
					'c.message_count',
					'c.participant_count'
				)
				->orderBy('c.last_message_at', 'DESC')
				->addOrderBy('c.created_at', 'DESC')
				->setMaxResults($ctx->perPage)
				->setFirstResult($offset)
				->executeQuery()
				->fetchAllAssociative();

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

			$qb = $this->connection->createQueryBuilder();
			$qb->insert(self::TABLE)
				->values([
					'participant_hash'  => ':hash',
					'title'             => ':title',
					'created_by'        => ':createdBy',
					'created_at'        => ':now',
					'message_count'     => '0',
					'participant_count' => ':participantCount',
				])
				->setParameter('hash', $hash)
				->setParameter('title', $request->title)
				->setParameter('createdBy', $createdBy)
				->setParameter('now', $now)
				->setParameter('participantCount', count($participantIds))
				->executeStatement();

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

			$allowed = ['title', 'last_message_id', 'last_message_at', 'message_count', 'participant_count', 'participant_hash'];

			$qb = $this->connection->createQueryBuilder();
			$qb->update(self::TABLE)
				->where($qb->expr()->eq('conversation_id', ':conversationId'))
				->setParameter('conversationId', $conversationId);

			foreach ($fields as $field => $value) {
				if (!in_array($field, $allowed, true)) {
					throw new RepositoryException("Invalid field: {$field}");
				}
				$qb->set($field, ':' . $field)
					->setParameter($field, $value);
			}

			$qb->executeStatement();
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to update conversation', previous: $e);
		}
	}

	public function delete(int $conversationId): void
	{
		try {
			$qb = $this->connection->createQueryBuilder();
			$qb->delete(self::TABLE)
				->where($qb->expr()->eq('conversation_id', ':id'))
				->setParameter('id', $conversationId)
				->executeStatement();
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
