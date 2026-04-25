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
 * Conversation Entity
 *
 * @TAG domain_entity
 */
final readonly class Conversation
{
	public function __construct(
		public int $id,
		public string $participantHash,
		public ?string $title,
		public int $createdBy,
		public int $createdAt,
		public ?int $lastMessageId,
		public ?int $lastMessageAt,
		public int $messageCount,
		public int $participantCount,
	) {
	}
}
