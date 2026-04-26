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

use phpbb\threads\Entity\Post;

final readonly class PostDTO
{
	public function __construct(
		public int $id,
		public int $topicId,
		public int $forumId,
		public int $authorId,
		public string $authorUsername,
		public string $content,
		public int $createdAt,
	) {
	}

	public static function fromEntity(Post $post): self
	{
		return new self(
			id:             $post->id,
			topicId:        $post->topicId,
			forumId:        $post->forumId,
			authorId:       $post->posterId,
			authorUsername: $post->username,
			content:        $post->text,
			createdAt:      $post->time,
		);
	}
}
