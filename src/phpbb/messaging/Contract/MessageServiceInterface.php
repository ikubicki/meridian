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
use phpbb\messaging\DTO\Request\SendMessageRequest;

/**
 * Message Service Interface
 *
 * @TAG service_interface
 */
interface MessageServiceInterface
{
	/**
	 * Send a message
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
}
