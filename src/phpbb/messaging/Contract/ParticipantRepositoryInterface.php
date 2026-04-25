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

use phpbb\db\Exception\RepositoryException;
use phpbb\messaging\Entity\Participant;

/**
 * Participant Repository Interface
 *
 * @TAG repository_interface
 */
interface ParticipantRepositoryInterface
{
	/**
	 * Find all participants in a conversation
	 *
	 * @return Participant[]
	 * @throws RepositoryException
	 */
	public function findByConversation(int $conversationId): array;

	/**
	 * Find all conversations a user participates in
	 *
	 * @return Participant[]
	 * @throws RepositoryException
	 */
	public function findByUser(int $userId): array;

	/**
	 * Find specific participant
	 *
	 * @throws RepositoryException
	 */
	public function findByConversationAndUser(int $conversationId, int $userId): ?Participant;

	/**
	 * Insert a participant into a conversation
	 *
	 * @throws RepositoryException
	 */
	public function insert(int $conversationId, int $userId, string $role = 'member'): void;

	/**
	 * Update participant fields
	 *
	 * @param array<string, mixed> $fields Fields to update
	 * @throws RepositoryException
	 */
	public function update(int $conversationId, int $userId, array $fields): void;

	/**
	 * Remove participant from conversation
	 *
	 * @throws RepositoryException
	 */
	public function delete(int $conversationId, int $userId): void;
}
