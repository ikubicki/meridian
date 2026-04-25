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

namespace phpbb\messaging\Entity;

/**
 * Participant Entity
 *
 * @TAG domain_entity
 */
final readonly class Participant
{
	public function __construct(
		public int $conversationId,
		public int $userId,
		public string $role,
		public string $state,
		public int $joinedAt,
		public ?int $leftAt,
		public ?int $lastReadMessageId,
		public ?int $lastReadAt,
		public bool $isMuted,
		public bool $isBlocked,
	) {
	}
}
