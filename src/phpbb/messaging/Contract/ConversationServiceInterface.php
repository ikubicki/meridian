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

use phpbb\common\Event\DomainEventCollection;
use phpbb\messaging\DTO\Request\CreateConversationRequest;

/**
 * Conversation Service Interface
 *
 * @TAG service_interface
 */
interface ConversationServiceInterface
{
	/**
	 * Create a new conversation
	 */
	public function createConversation(CreateConversationRequest $request, int $userId): DomainEventCollection;

	/**
	 * Archive a conversation
	 */
	public function archiveConversation(int $conversationId, int $userId): DomainEventCollection;

	/**
	 * Pin a conversation
	 */
	public function pinConversation(int $conversationId, int $userId): DomainEventCollection;

	/**
	 * Unpin a conversation
	 */
	public function unpinConversation(int $conversationId, int $userId): DomainEventCollection;

	/**
	 * Delete a conversation (owner only)
	 */
	public function deleteConversation(int $conversationId, int $userId): DomainEventCollection;
}
