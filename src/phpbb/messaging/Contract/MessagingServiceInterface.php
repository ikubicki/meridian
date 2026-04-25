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

namespace phpbb\messaging\Contract;

use phpbb\api\DTO\PaginationContext;
use phpbb\common\Event\DomainEventCollection;
use phpbb\messaging\DTO\ConversationDTO;
use phpbb\messaging\DTO\MessageDTO;
use phpbb\messaging\DTO\ParticipantDTO;
use phpbb\messaging\DTO\Request\CreateConversationRequest;
use phpbb\messaging\DTO\Request\SendMessageRequest;
use phpbb\user\DTO\PaginatedResult;

/**
 * Messaging Service Interface (Main Facade)
 *
 * @TAG service_interface
 */
interface MessagingServiceInterface
{
	/**
	 * List conversations for a user
	 *
	 * @param string|null $state Filter by state (active, pinned, archived)
	 * @param PaginationContext $ctx Pagination context
	 * @return PaginatedResult<ConversationDTO>
	 */
	public function listConversations(int $userId, ?string $state, PaginationContext $ctx): PaginatedResult;

	/**
	 * Get a conversation
	 *
	 * @throws \UnauthorizedAccessException if users not in conversation
	 * @throws \NotFoundException if conversation not found
	 */
	public function getConversation(int $conversationId, int $userId): ConversationDTO;

	/**
	 * Create a new conversation
	 *
	 * @return DomainEventCollection Events raised
	 */
	public function createConversation(CreateConversationRequest $request, int $userId): DomainEventCollection;

	/**
	 * Archive a conversation for the user
	 */
	public function archiveConversation(int $conversationId, int $userId): DomainEventCollection;

	/**
	 * Pin a conversation for the user
	 */
	public function pinConversation(int $conversationId, int $userId): DomainEventCollection;

	/**
	 * Unpin a conversation for the user
	 */
	public function unpinConversation(int $conversationId, int $userId): DomainEventCollection;

	/**
	 * Delete a conversation (owner only)
	 */
	public function deleteConversation(int $conversationId, int $userId): DomainEventCollection;

	/**
	 * List messages in a conversation
	 *
	 * @return PaginatedResult<MessageDTO>
	 */
	public function listMessages(int $conversationId, int $userId, PaginationContext $ctx): PaginatedResult;

	/**
	 * Get a single message
	 */
	public function getMessage(int $messageId, int $userId): MessageDTO;

	/**
	 * Send a message to a conversation
	 */
	public function sendMessage(int $conversationId, SendMessageRequest $request, int $userId): DomainEventCollection;

	/**
	 * Edit a message
	 */
	public function editMessage(int $messageId, string $newText, int $userId): DomainEventCollection;

	/**
	 * Delete a message (soft delete for user)
	 */
	public function deleteMessage(int $messageId, int $userId): DomainEventCollection;

	/**
	 * Mark a message as read
	 */
	public function markMessageRead(int $messageId, int $conversationId, int $userId): DomainEventCollection;

	/**
	 * Search messages in a conversation
	 *
	 * @return PaginatedResult<MessageDTO>
	 */
	public function searchMessages(int $conversationId, int $userId, string $query, PaginationContext $ctx): PaginatedResult;

	/**
	 * List all participants in a conversation
	 *
	 * @return ParticipantDTO[]
	 */
	public function listParticipants(int $conversationId, int $userId): array;

	/**
	 * Add a participant to a conversation (owner only)
	 */
	public function addParticipant(int $conversationId, int $newUserId, int $userId): DomainEventCollection;

	/**
	 * Remove a participant from a conversation
	 */
	public function removeParticipant(int $conversationId, int $targetUserId, int $userId): DomainEventCollection;

	/**
	 * Update a participant's role or settings
	 */
	public function updateParticipantRole(int $conversationId, int $targetUserId, string $role, int $userId): DomainEventCollection;
}
