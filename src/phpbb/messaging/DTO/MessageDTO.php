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

use phpbb\messaging\Entity\Message;

/**
 * Message DTO (API Response)
 *
 * @TAG domain_dto
 */
final readonly class MessageDTO
{
	public function __construct(
		public int $id,
		public int $conversationId,
		public int $authorId,
		public string $messageText,
		public ?string $messageSubject,
		public int $createdAt,
		public ?int $editedAt,
		public int $editCount,
		public ?string $metadata,
	) {
	}

	public static function fromEntity(Message $message): self
	{
		return new self(
			id: $message->id,
			conversationId: $message->conversationId,
			authorId: $message->authorId,
			messageText: $message->messageText,
			messageSubject: $message->messageSubject,
			createdAt: $message->createdAt,
			editedAt: $message->editedAt,
			editCount: $message->editCount,
			metadata: $message->metadata,
		);
	}
}
