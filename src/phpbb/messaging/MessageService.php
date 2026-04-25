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
use phpbb\messaging\Contract\MessageRepositoryInterface;
use phpbb\messaging\Contract\MessageServiceInterface;
use phpbb\messaging\Contract\ParticipantRepositoryInterface;
use phpbb\messaging\DTO\Request\SendMessageRequest;
use phpbb\messaging\Event\MessageCreatedEvent;
use phpbb\messaging\Event\MessageDeletedEvent;
use phpbb\messaging\Event\MessageEditedEvent;

/**
 * Message Service - Helper for Message Operations
 *
 * Handles message lifecycle: sending, editing, deleting.
 * Manages read tracking and edit window validation.
 *
 * @TAG service
 */
final class MessageService implements MessageServiceInterface
{
	// Edit window: 15 minutes after message creation
	private const EDIT_WINDOW_SECONDS = 15 * 60;

	public function __construct(
		private readonly ConversationRepositoryInterface $conversationRepo,
		private readonly MessageRepositoryInterface $messageRepo,
		private readonly ParticipantRepositoryInterface $participantRepo,
		private readonly Connection $connection,
	) {
	}

	public function sendMessage(int $conversationId, SendMessageRequest $request, int $userId): DomainEventCollection
	{
		$this->connection->beginTransaction();

		try {
			// Verify conversation exists
			$conversation = $this->conversationRepo->findById($conversationId);
			if ($conversation === null) {
				throw new \InvalidArgumentException("Conversation {$conversationId} not found");
			}

			// Verify user is a participant
			$participant = $this->participantRepo->findByConversationAndUser($conversationId, $userId);
			if ($participant === null) {
				throw new \InvalidArgumentException("User {$userId} not in conversation {$conversationId}");
			}

			// Insert message
			$now = time();
			$messageId = $this->messageRepo->insert($conversationId, $request, $userId);

			// Update conversation denormalized fields
			$this->conversationRepo->update($conversationId, [
				'last_message_id' => $messageId,
				'last_message_at' => $now,
				'message_count' => ($conversation->messageCount ?? 0) + 1,
			]);

			// Reset read cursors for all other participants
			$allParticipants = $this->participantRepo->findByConversation($conversationId);
			foreach ($allParticipants as $p) {
				if ($p->userId !== $userId) {
					$this->participantRepo->update($conversationId, $p->userId, [
						'last_read_message_id' => null,
						'last_read_at' => null,
					]);
				}
			}

			$this->connection->commit();

			return new DomainEventCollection([
				new MessageCreatedEvent(entityId: $messageId, actorId: $userId),
			]);
		} catch (\Throwable $e) {
			if ($this->connection->isTransactionActive()) {
				$this->connection->rollBack();
			}

			throw new \RuntimeException("Failed to send message: {$e->getMessage()}", 0, $e);
		}
	}

	public function editMessage(int $messageId, string $newText, int $userId): DomainEventCollection
	{
		$this->connection->beginTransaction();

		try {
			// Fetch message
			$message = $this->messageRepo->findById($messageId);
			if ($message === null) {
				throw new \InvalidArgumentException("Message {$messageId} not found");
			}

			// Verify user is the author
			if ($message->authorId !== $userId) {
				throw new \InvalidArgumentException('Only message author can edit');
			}

			// Verify conversation and participant
			$conversation = $this->conversationRepo->findById($message->conversationId);
			if ($conversation === null) {
				throw new \InvalidArgumentException("Conversation {$message->conversationId} not found");
			}

			$participant = $this->participantRepo->findByConversationAndUser($message->conversationId, $userId);
			if ($participant === null) {
				throw new \InvalidArgumentException("User {$userId} not in conversation");
			}

			// Check edit window (15 minutes)
			$now = time();
			if ($now - $message->createdAt > self::EDIT_WINDOW_SECONDS) {
				throw new \InvalidArgumentException('Edit window expired (15 minutes)');
			}

			// Update message
			$this->messageRepo->update($messageId, [
				'message_text' => $newText,
				'edited_at' => $now,
				'edit_count' => ($message->editCount ?? 0) + 1,
			]);

			$this->connection->commit();

			return new DomainEventCollection([
				new MessageEditedEvent(entityId: $messageId, actorId: $userId),
			]);
		} catch (\Throwable $e) {
			if ($this->connection->isTransactionActive()) {
				$this->connection->rollBack();
			}

			throw new \RuntimeException("Failed to edit message: {$e->getMessage()}", 0, $e);
		}
	}

	public function deleteMessage(int $messageId, int $userId): DomainEventCollection
	{
		$this->connection->beginTransaction();

		try {
			// Fetch message
			$message = $this->messageRepo->findById($messageId);
			if ($message === null) {
				throw new \InvalidArgumentException("Message {$messageId} not found");
			}

			// Verify user is the author or conversation owner
			$conversation = $this->conversationRepo->findById($message->conversationId);
			if ($conversation === null) {
				throw new \InvalidArgumentException('Conversation not found');
			}

			$isAuthor = $message->authorId === $userId;
			$isOwner = $conversation->createdBy === $userId;

			if (!$isAuthor && !$isOwner) {
				throw new \InvalidArgumentException('Only author or owner can delete message');
			}

			// Soft-delete for this user
			$this->messageRepo->deletePerUser($messageId, $userId);

			$this->connection->commit();

			return new DomainEventCollection([
				new MessageDeletedEvent(entityId: $messageId, actorId: $userId),
			]);
		} catch (\Throwable $e) {
			if ($this->connection->isTransactionActive()) {
				$this->connection->rollBack();
			}

			throw new \RuntimeException("Failed to delete message: {$e->getMessage()}", 0, $e);
		}
	}

	public function markMessageRead(int $messageId, int $conversationId, int $userId): DomainEventCollection
	{

		// Verify message exists
		$message = $this->messageRepo->findById($messageId);
		if ($message === null) {
			throw new \InvalidArgumentException("Message {$messageId} not found");
		}

		// Verify it's in the correct conversation
		if ($message->conversationId !== $conversationId) {
			throw new \InvalidArgumentException('Message not in conversation');
		}

		// Verify user is a participant
		$participant = $this->participantRepo->findByConversationAndUser($conversationId, $userId);
		if ($participant === null) {
			throw new \InvalidArgumentException('User not in conversation');
		}

		// Update read cursor
		$this->participantRepo->update($conversationId, $userId, [
			'last_read_message_id' => $messageId,
			'last_read_at' => time(),
		]);

		return new DomainEventCollection([]);
	}
}
