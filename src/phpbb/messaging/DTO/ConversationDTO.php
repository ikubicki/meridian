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

namespace phpbb\messaging\DTO;

use phpbb\messaging\Entity\Conversation;

/**
 * Conversation DTO (API Response)
 *
 * @TAG domain_dto
 */
final readonly class ConversationDTO
{
	public function __construct(
		public int $id,
		public ?string $title,
		public int $createdBy,
		public int $createdAt,
		public ?int $lastMessageId,
		public ?int $lastMessageAt,
		public int $messageCount,
		public int $participantCount,
	) {
	}

	public static function fromEntity(Conversation $conversation): self
	{
		return new self(
			id: $conversation->id,
			title: $conversation->title,
			createdBy: $conversation->createdBy,
			createdAt: $conversation->createdAt,
			lastMessageId: $conversation->lastMessageId,
			lastMessageAt: $conversation->lastMessageAt,
			messageCount: $conversation->messageCount,
			participantCount: $conversation->participantCount,
		);
	}
}
