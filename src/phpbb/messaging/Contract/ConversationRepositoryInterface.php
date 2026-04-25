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
use phpbb\messaging\DTO\Request\CreateConversationRequest;
use phpbb\messaging\Entity\Conversation;
use phpbb\user\DTO\PaginatedResult;

/**
 * Conversation Repository Interface
 *
 * @TAG repository_interface
 * @return PaginatedResult<\phpbb\messaging\DTO\ConversationDTO>
 */
interface ConversationRepositoryInterface
{
	/**
	 * Find conversation by ID
	 *
	 * @throws RepositoryException
	 */
	public function findById(int $conversationId): ?Conversation;

	/**
	 * Find conversation by participant hash
	 *
	 * @throws RepositoryException
	 */
	public function findByParticipantHash(string $hash): ?Conversation;

	/**
	 * List conversations for a user with optional state filter
	 *
	 * @param string|null $state Filter by state (active, pinned, archived)
	 * @param PaginationContext $ctx Pagination context
	 * @return PaginatedResult<\phpbb\messaging\DTO\ConversationDTO>
	 * @throws RepositoryException
	 */
	public function listByUser(int $userId, ?string $state, PaginationContext $ctx): PaginatedResult;

	/**
	 * Insert a new conversation
	 *
	 * @return int Conversation ID
	 * @throws RepositoryException
	 */
	public function insert(CreateConversationRequest $request, int $createdBy): int;

	/**
	 * Update conversation fields
	 *
	 * @param array<string, mixed> $fields Fields to update
	 * @throws RepositoryException
	 */
	public function update(int $conversationId, array $fields): void;

	/**
	 * Delete a conversation
	 *
	 * @throws RepositoryException
	 */
	public function delete(int $conversationId): void;
}
