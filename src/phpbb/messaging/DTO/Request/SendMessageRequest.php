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

namespace phpbb\messaging\DTO\Request;

/**
 * Send Message Request DTO
 *
 * @TAG domain_request_dto
 */
final readonly class SendMessageRequest
{
	public function __construct(
		public string $messageText,
		public ?string $messageSubject = null,
		public ?string $metadata = null,
	) {
	}
}
