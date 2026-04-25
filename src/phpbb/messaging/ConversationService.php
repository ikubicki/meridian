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

namespace phpbb\messaging;

use Doctrine\DBAL\Connection;
use phpbb\common\Event\DomainEventCollection;
use phpbb\messaging\Contract\ConversationRepositoryInterface;
use phpbb\messaging\Contract\ConversationServiceInterface;
use phpbb\messaging\Contract\ParticipantRepositoryInterface;
use phpbb\messaging\DTO\Request\CreateConversationRequest;
use phpbb\messaging\Event\ConversationArchivedEvent;
use phpbb\messaging\Event\ConversationCreatedEvent;
use phpbb\messaging\Event\ConversationDeletedEvent;

/**
 * Conversation Service - Helper for Conversation Operations
 *
 * Handles conversation lifecycle: creation, state changes, deletion.
 * Manages transactions for multi-step operations.
 *
 * @TAG service
 */
final class ConversationService implements ConversationServiceInterface
{
	public function __construct(
		private readonly ConversationRepositoryInterface $conversationRepo,
		private readonly ParticipantRepositoryInterface $participantRepo,
		private readonly Connection $connection,
	) {
	}

	public function createConversation(CreateConversationRequest $request, int $userId): DomainEventCollection
	{
		$this->connection->beginTransaction();

		try {
			$hash = $this->hashParticipants(array_merge([$userId], $request->participantIds));

			// Check if conversation already exists for this participant set
			$existing = $this->conversationRepo->findByParticipantHash($hash);
			if ($existing !== null) {
				$this->connection->commit();

				return new DomainEventCollection([
					new ConversationCreatedEvent(entityId: $existing->id, actorId: $userId),
				]); // Idempotent: return existing conversation
			}

			// Create new conversation
			$conversationId = $this->conversationRepo->insert($request, $userId);

			// Add creator as owner
			$this->participantRepo->insert($conversationId, $userId, 'owner');

			// Add other participants as members
			foreach ($request->participantIds as $participantId) {
				if ($participantId !== $userId) {
					$this->participantRepo->insert($conversationId, $participantId, 'member');
				}
			}

			$this->connection->commit();

			return new DomainEventCollection([
				new ConversationCreatedEvent(entityId: $conversationId, actorId: $userId),
			]);
		} catch (\Throwable $e) {
			if ($this->connection->isTransactionActive()) {
				$this->connection->rollBack();
			}

			throw new \RuntimeException("Failed to create conversation: {$e->getMessage()}", 0, $e);
		}
	}

	public function archiveConversation(int $conversationId, int $userId): DomainEventCollection
	{
		// Verify conversation exists and user is participant
		$conversation = $this->conversationRepo->findById($conversationId);
		if ($conversation === null) {
			throw new \InvalidArgumentException("Conversation {$conversationId} not found");
		}

		$participant = $this->participantRepo->findByConversationAndUser($conversationId, $userId);
		if ($participant === null) {
			throw new \InvalidArgumentException("User {$userId} not in conversation {$conversationId}");
		}

		// Archive conversation for this user
		$this->participantRepo->update($conversationId, $userId, ['state' => 'archived']);

		return new DomainEventCollection([
			new ConversationArchivedEvent(entityId: $conversationId, actorId: $userId),
		]);
	}

	public function pinConversation(int $conversationId, int $userId): DomainEventCollection
	{
		// Verify conversation exists and user is participant
		$conversation = $this->conversationRepo->findById($conversationId);
		if ($conversation === null) {
			throw new \InvalidArgumentException("Conversation {$conversationId} not found");
		}

		$participant = $this->participantRepo->findByConversationAndUser($conversationId, $userId);
		if ($participant === null) {
			throw new \InvalidArgumentException("User {$userId} not in conversation {$conversationId}");
		}

		// Pin conversation for this user
		$this->participantRepo->update($conversationId, $userId, ['state' => 'pinned']);

		return new DomainEventCollection([]);
	}

	public function unpinConversation(int $conversationId, int $userId): DomainEventCollection
	{
		// Verify conversation exists and user is participant
		$conversation = $this->conversationRepo->findById($conversationId);
		if ($conversation === null) {
			throw new \InvalidArgumentException("Conversation {$conversationId} not found");
		}

		$participant = $this->participantRepo->findByConversationAndUser($conversationId, $userId);
		if ($participant === null) {
			throw new \InvalidArgumentException("User {$userId} not in conversation {$conversationId}");
		}

		// Unpin conversation for this user
		$this->participantRepo->update($conversationId, $userId, ['state' => 'active']);

		return new DomainEventCollection([]);
	}

	public function deleteConversation(int $conversationId, int $userId): DomainEventCollection
	{
		$this->connection->beginTransaction();

		try {
			// Verify conversation exists
			$conversation = $this->conversationRepo->findById($conversationId);
			if ($conversation === null) {
				throw new \InvalidArgumentException("Conversation {$conversationId} not found");
			}

			// Verify user is owner
			if ($conversation->createdBy !== $userId) {
				throw new \InvalidArgumentException('Only owner can delete conversation');
			}

			// Delete conversation (cascade will delete messages and participants)
			$this->conversationRepo->delete($conversationId);

			$this->connection->commit();

			return new DomainEventCollection([
				new ConversationDeletedEvent(entityId: $conversationId, actorId: $userId),
			]);
		} catch (\Throwable $e) {
			if ($this->connection->isTransactionActive()) {
				$this->connection->rollBack();
			}

			throw new \RuntimeException("Failed to delete conversation: {$e->getMessage()}", 0, $e);
		}
	}

	/**
	 * Calculate SHA-256 hash of sorted participant IDs for conversation deduplication
	 *
	 * @param int[] $participantIds
	 */
	private function hashParticipants(array $participantIds): string
	{
		sort($participantIds);

		return hash('sha256', implode(',', $participantIds));
	}
}
