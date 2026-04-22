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

use phpbb\threads\Entity\Topic;

final readonly class TopicDTO
{
	public function __construct(
		public int $id,
		public string $title,
		public int $forumId,
		public int $authorId,
		public int $postCount,
		public string $lastPosterName,
		public int|string $lastPostTime,
		public int|string $createdAt,
	) {
	}

	public static function fromEntity(Topic $topic): self
	{
		return new self(
			id: $topic->id,
			title: $topic->title,
			forumId: $topic->forumId,
			authorId: $topic->posterId,
			postCount: $topic->postsApproved,
			lastPosterName: $topic->lastPosterName,
			lastPostTime: $topic->lastPostTime,
			createdAt: $topic->time,
		);
	}
}
