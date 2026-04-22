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

final readonly class Topic
{
	public function __construct(
		public int $id,
		public int $forumId,
		public string $title,
		public int $posterId,
		public int $time,
		public int $postsApproved,
		public int $lastPostTime,
		public string $lastPosterName,
		public int $lastPosterId,
		public string $lastPosterColour,
		public int $firstPostId,
		public int $lastPostId,
		public int $visibility,
		public string $firstPosterName,
		public string $firstPosterColour,
	) {
	}
}
