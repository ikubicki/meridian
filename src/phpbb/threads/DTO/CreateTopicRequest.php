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

namespace phpbb\threads\DTO;

final readonly class CreateTopicRequest
{
	public function __construct(
		public int $forumId,
		public string $title,
		public string $content,
		public int $actorId,
		public string $actorUsername,
		public string $actorColour,
		public string $posterIp,
	) {
	}
}
