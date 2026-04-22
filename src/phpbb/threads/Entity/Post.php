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

namespace phpbb\threads\Entity;

final readonly class Post
{
	public function __construct(
		public int $id,
		public int $topicId,
		public int $forumId,
		public int $posterId,
		public int $time,
		public string $text,
		public string $subject,
		public string $username,
		public string $posterIp,
		public int $visibility,
	) {
	}
}
