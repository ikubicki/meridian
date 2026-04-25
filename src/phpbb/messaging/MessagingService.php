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
use phpbb\api\DTO\PaginationContext;
use phpbb\common\Event\DomainEventCollection;
use phpbb\messaging\Contract\ConversationRepositoryInterface;
use phpbb\messaging\Contract\ConversationServiceInterface;
use phpbb\messaging\Contract\MessageRepositoryInterface;
use phpbb\messaging\Contract\MessageServiceInterface;
use phpbb\messaging\Contract\MessagingServiceInterface;
use phpbb\messaging\Contract\ParticipantRepositoryInterface;
use phpbb\messaging\Contract\ParticipantServiceInterface;
use phpbb\messaging\DTO\ConversationDTO;
use phpbb\messaging\DTO\MessageDTO;
use phpbb\messaging\DTO\Request\CreateConversationRequest;
use phpbb\messaging\DTO\Request\SendMessageRequest;
use phpbb\user\DTO\PaginatedResult;

/**
 * Messaging Service - Main Facade
 *
 * Orchestrates conversation, message, and participant operations.
 * Manages transactions and event collection.
 *
 * @TAG service
 */
final class MessagingService implements MessagingServiceInterface
{
	public function __construct(
		private readonly ConversationRepositoryInterface $conversationRepo,
		private readonly MessageRepositoryInterface $messageRepo,
		private readonly ParticipantRepositoryInterface $participantRepo,
		private readonly ConversationServiceInterface $conversationService,
		private readonly MessageServiceInterface $messageService,
		private readonly ParticipantServiceInterface $participantService,
		private readonly Connection $connection,
	) {
	}

	public function listConversations(int $userId, ?string $state, PaginationContext $ctx): PaginatedResult
	{
		return $this->conversationRepo->listByUser($userId, $state, $ctx);
	}

	public function getConversation(int $conversationId, int $userId): ConversationDTO
	{
		$conversation = $this->conversationRepo->findById($conversationId);
		if ($conversation === null) {
			throw new \InvalidArgumentException("Conversation {$conversationId} not found");
		}

		// Verify user is a participant
		$participant = $this->participantRepo->findByConversationAndUser($conversationId, $userId);
		if ($participant === null) {
			throw new \InvalidArgumentException("User {$userId} not in conversation {$conversationId}");
		}

		return ConversationDTO::fromEntity($conversation);
	}

	public function createConversation(CreateConversationRequest $request, int $userId): DomainEventCollection
	{
		return $this->conversationService->createConversation($request, $userId);
	}

	public function archiveConversation(int $conversationId, int $userId): DomainEventCollection
	{
		return $this->conversationService->archiveConversation($conversationId, $userId);
	}

	public function pinConversation(int $conversationId, int $userId): DomainEventCollection
	{
		return $this->conversationService->pinConversation($conversationId, $userId);
	}

	public function unpinConversation(int $conversationId, int $userId): DomainEventCollection
	{
		return $this->conversationService->unpinConversation($conversationId, $userId);
	}

	public function deleteConversation(int $conversationId, int $userId): DomainEventCollection
	{
		return $this->conversationService->deleteConversation($conversationId, $userId);
	}

	public function listMessages(int $conversationId, int $userId, PaginationContext $ctx): PaginatedResult
	{
		// Verify user is a participant
		$participant = $this->participantRepo->findByConversationAndUser($conversationId, $userId);
		if ($participant === null) {
			throw new \InvalidArgumentException("User {$userId} not in conversation {$conversationId}");
		}

		return $this->messageRepo->listByConversation($conversationId, $ctx);
	}

	public function getMessage(int $messageId, int $userId): MessageDTO
	{
		$message = $this->messageRepo->findById($messageId);
		if ($message === null) {
			throw new \InvalidArgumentException("Message {$messageId} not found");
		}

		// Verify user is a participant
		$participant = $this->participantRepo->findByConversationAndUser($message->conversationId, $userId);
		if ($participant === null) {
			throw new \InvalidArgumentException("User {$userId} not in conversation {$message->conversationId}");
		}

		// Check if message is deleted for this user
		if ($this->messageRepo->isDeletedForUser($messageId, $userId)) {
			throw new \InvalidArgumentException("Message {$messageId} is deleted for user {$userId}");
		}

		return MessageDTO::fromEntity($message);
	}

	public function sendMessage(int $conversationId, SendMessageRequest $request, int $userId): DomainEventCollection
	{
		return $this->messageService->sendMessage($conversationId, $request, $userId);
	}

	public function editMessage(int $messageId, string $newText, int $userId): DomainEventCollection
	{
		return $this->messageService->editMessage($messageId, $newText, $userId);
	}

	public function deleteMessage(int $messageId, int $userId): DomainEventCollection
	{
		return $this->messageService->deleteMessage($messageId, $userId);
	}

	public function markMessageRead(int $messageId, int $conversationId, int $userId): DomainEventCollection
	{
		return $this->messageService->markMessageRead($messageId, $conversationId, $userId);
	}

	public function searchMessages(int $conversationId, int $userId, string $query, PaginationContext $ctx): PaginatedResult
	{
		// Verify user is a participant
		$participant = $this->participantRepo->findByConversationAndUser($conversationId, $userId);
		if ($participant === null) {
			throw new \InvalidArgumentException("User {$userId} not in conversation {$conversationId}");
		}

		return $this->messageRepo->search($conversationId, $query, $ctx);
	}

	public function listParticipants(int $conversationId, int $userId): array
	{
		return $this->participantService->listParticipants($conversationId, $userId);
	}

	public function addParticipant(int $conversationId, int $newUserId, int $userId): DomainEventCollection
	{
		return $this->participantService->addParticipant($conversationId, $newUserId, $userId);
	}

	public function removeParticipant(int $conversationId, int $targetUserId, int $userId): DomainEventCollection
	{
		return $this->participantService->removeParticipant($conversationId, $targetUserId, $userId);
	}

	public function updateParticipantRole(int $conversationId, int $targetUserId, string $role, int $userId): DomainEventCollection
	{
		return $this->participantService->updateParticipantRole($conversationId, $targetUserId, $role, $userId);
	}
}
