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
 * Message Entity
 *
 * @TAG domain_entity
 */
final readonly class Message
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
}
