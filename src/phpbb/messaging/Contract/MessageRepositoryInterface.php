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
use phpbb\db\Exception\RepositoryException;
use phpbb\messaging\DTO\Request\SendMessageRequest;
use phpbb\messaging\Entity\Message;
use phpbb\user\DTO\PaginatedResult;

/**
 * Message Repository Interface
 *
 * @TAG repository_interface
 */
interface MessageRepositoryInterface
{
	/**
	 * Find message by ID
	 *
	 * @throws RepositoryException
	 */
	public function findById(int $messageId): ?Message;

	/**
	 * List messages in a conversation with pagination
	 *
	 * @return PaginatedResult<\phpbb\messaging\DTO\MessageDTO>
	 * @throws RepositoryException
	 */
	public function listByConversation(int $conversationId, PaginationContext $ctx): PaginatedResult;

	/**
	 * Search messages in a conversation
	 *
	 * @return PaginatedResult<\phpbb\messaging\DTO\MessageDTO>
	 * @throws RepositoryException
	 */
	public function search(int $conversationId, string $query, PaginationContext $ctx): PaginatedResult;

	/**
	 * Insert a new message
	 *
	 * @return int Message ID
	 * @throws RepositoryException
	 */
	public function insert(int $conversationId, SendMessageRequest $request, int $authorId): int;

	/**
	 * Update message fields
	 *
	 * @param array<string, mixed> $fields Fields to update
	 * @throws RepositoryException
	 */
	public function update(int $messageId, array $fields): void;

	/**
	 * Soft delete a message for a specific user
	 *
	 * @throws RepositoryException
	 */
	public function deletePerUser(int $messageId, int $userId): void;

	/**
	 * Check if message is deleted for a user
	 *
	 * @throws RepositoryException
	 */
	public function isDeletedForUser(int $messageId, int $userId): bool;
}
