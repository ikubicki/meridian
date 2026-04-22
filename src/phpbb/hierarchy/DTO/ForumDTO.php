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

namespace phpbb\hierarchy\DTO;

use phpbb\hierarchy\Entity\Forum;

final readonly class ForumDTO
{
	public function __construct(
		public int $id,
		public string $name,
		public string $description,
		public int $parentId,
		public int $type,
		public int $status,
		public int $leftId,
		public int $rightId,
		public bool $displayOnIndex,
		public int $topicsApproved,
		public int $postsApproved,
		public int $lastPostId,
		public int $lastPostTime,
		public string $lastPosterName,
		public string $link,
		public array $parents,
	) {
	}

	public static function fromEntity(Forum $forum): self
	{
		return new self(
			id: $forum->id,
			name: $forum->name,
			description: $forum->description,
			parentId: $forum->parentId,
			type: $forum->type->value,
			status: $forum->status->value,
			leftId: $forum->leftId,
			rightId: $forum->rightId,
			displayOnIndex: $forum->displayOnIndex,
			topicsApproved: $forum->stats->topicsApproved,
			postsApproved: $forum->stats->postsApproved,
			lastPostId: $forum->lastPost->postId,
			lastPostTime: $forum->lastPost->time,
			lastPosterName: $forum->lastPost->posterName,
			link: $forum->link,
			parents: $forum->parents,
		);
	}
}
