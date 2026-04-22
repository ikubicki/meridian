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

namespace phpbb\hierarchy\Entity;

final readonly class ForumStats
{
	public function __construct(
		public int $postsApproved,
		public int $postsUnapproved,
		public int $postsSoftdeleted,
		public int $topicsApproved,
		public int $topicsUnapproved,
		public int $topicsSoftdeleted,
	) {
	}

	public function totalPosts(): int
	{
		return $this->postsApproved + $this->postsUnapproved + $this->postsSoftdeleted;
	}

	public function totalTopics(): int
	{
		return $this->topicsApproved + $this->topicsUnapproved + $this->topicsSoftdeleted;
	}
}
