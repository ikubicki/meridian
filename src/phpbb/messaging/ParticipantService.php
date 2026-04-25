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
use phpbb\messaging\Contract\ParticipantRepositoryInterface;
use phpbb\messaging\Contract\ParticipantServiceInterface;
use phpbb\messaging\DTO\ParticipantDTO;
use phpbb\messaging\Event\ParticipantAddedEvent;

/**
 * Participant Service - Helper for Participant Operations
 *
 * Handles participant lifecycle: adding, removing, updating roles.
 * Manages conversation membership and permissions.
 *
 * @TAG service
 */
final class ParticipantService implements ParticipantServiceInterface
{
	public function __construct(
		private readonly ConversationRepositoryInterface $conversationRepo,
		private readonly ParticipantRepositoryInterface $participantRepo,
		private readonly Connection $connection,
	) {
	}

	public function listParticipants(int $conversationId, int $userId): array
	{
		// Verify conversation exists
		$conversation = $this->conversationRepo->findById($conversationId);
		if ($conversation === null) {
			throw new \InvalidArgumentException("Conversation {$conversationId} not found");
		}

		// Verify user is a participant
		$userParticipant = $this->participantRepo->findByConversationAndUser($conversationId, $userId);
		if ($userParticipant === null) {
			throw new \InvalidArgumentException("User {$userId} not in conversation {$conversationId}");
		}

		// Get all participants for the conversation
		$participants = $this->participantRepo->findByConversation($conversationId);

		// Convert to DTOs
		return array_map(fn ($p) => ParticipantDTO::fromEntity($p), $participants);
	}

	public function addParticipant(int $conversationId, int $newUserId, int $userId): DomainEventCollection
	{
		$this->connection->beginTransaction();

		try {
			// Verify conversation exists
			$conversation = $this->conversationRepo->findById($conversationId);
			if ($conversation === null) {
				throw new \InvalidArgumentException("Conversation {$conversationId} not found");
			}

			// Verify requestor is owner
			if ($conversation->createdBy !== $userId) {
				throw new \InvalidArgumentException('Only owner can add participants');
			}

			// Check if user is already a participant
			$existing = $this->participantRepo->findByConversationAndUser($conversationId, $newUserId);
			if ($existing !== null) {
				$this->connection->commit();

				return new DomainEventCollection([]); // Idempotent: already a member
			}

			// Add new participant as member
			$this->participantRepo->insert($conversationId, $newUserId, 'member');

			// Update participant count
			$participants = $this->participantRepo->findByConversation($conversationId);
			$this->conversationRepo->update($conversationId, [
				'participant_count' => count($participants),
			]);

			$this->connection->commit();

			return new DomainEventCollection([
				new ParticipantAddedEvent(entityId: $conversationId, actorId: $userId),
			]);
		} catch (\Throwable $e) {
			if ($this->connection->isTransactionActive()) {
				$this->connection->rollBack();
			}

			throw new \RuntimeException("Failed to add participant: {$e->getMessage()}", 0, $e);
		}
	}

	public function removeParticipant(int $conversationId, int $targetUserId, int $userId): DomainEventCollection
	{
		$this->connection->beginTransaction();

		try {
			// Verify conversation exists
			$conversation = $this->conversationRepo->findById($conversationId);
			if ($conversation === null) {
				throw new \InvalidArgumentException("Conversation {$conversationId} not found");
			}

			// Verify requestor is owner or target is self
			$isSelfRemove = $targetUserId === $userId;
			$isOwner = $conversation->createdBy === $userId;

			if (!$isSelfRemove && !$isOwner) {
				throw new \InvalidArgumentException('Only owner or self can remove participant');
			}

			// Verify target is a participant
			$target = $this->participantRepo->findByConversationAndUser($conversationId, $targetUserId);
			if ($target === null) {
				throw new \InvalidArgumentException("User {$targetUserId} not in conversation");
			}

			// Remove participant
			$this->participantRepo->delete($conversationId, $targetUserId);

			// Update participant count
			$participants = $this->participantRepo->findByConversation($conversationId);
			$this->conversationRepo->update($conversationId, [
				'participant_count' => count($participants),
			]);

			$this->connection->commit();

			return new DomainEventCollection([]);
		} catch (\Throwable $e) {
			if ($this->connection->isTransactionActive()) {
				$this->connection->rollBack();
			}

			throw new \RuntimeException("Failed to remove participant: {$e->getMessage()}", 0, $e);
		}
	}

	public function updateParticipantRole(int $conversationId, int $targetUserId, string $role, int $userId): DomainEventCollection
	{
		// Verify conversation exists
		$conversation = $this->conversationRepo->findById($conversationId);
		if ($conversation === null) {
			throw new \InvalidArgumentException("Conversation {$conversationId} not found");
		}

		// Verify requestor is owner
		if ($conversation->createdBy !== $userId) {
			throw new \InvalidArgumentException('Only owner can update participant role');
		}

		// Verify target is a participant
		$target = $this->participantRepo->findByConversationAndUser($conversationId, $targetUserId);
		if ($target === null) {
			throw new \InvalidArgumentException("User {$targetUserId} not in conversation");
		}

		// Validate role
		$validRoles = ['member', 'owner', 'hidden'];
		if (!in_array($role, $validRoles, true)) {
			throw new \InvalidArgumentException("Invalid role: {$role}");
		}

		// Update role
		$this->participantRepo->update($conversationId, $targetUserId, ['role' => $role]);

		return new DomainEventCollection([]);
	}
}
