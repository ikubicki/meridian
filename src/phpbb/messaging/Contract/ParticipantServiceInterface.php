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
use phpbb\messaging\DTO\ParticipantDTO;

/**
 * Participant Service Interface
 *
 * @TAG service_interface
 */
interface ParticipantServiceInterface
{
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
	 * Update a participant's role
	 */
	public function updateParticipantRole(int $conversationId, int $targetUserId, string $role, int $userId): DomainEventCollection;
}
