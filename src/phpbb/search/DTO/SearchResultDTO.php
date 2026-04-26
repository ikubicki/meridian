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

namespace phpbb\search\DTO;

/**
 * Search result DTO (API Response)
 *
 * @TAG domain_dto
 */
final readonly class SearchResultDTO
{
	public function __construct(
		public int    $postId,
		public int    $topicId,
		public int    $forumId,
		public string $subject,
		public string $excerpt,
		public int    $postedAt,
		public string $topicTitle,
		public string $forumName,
	) {
	}

	public static function fromRow(array $row): self
	{
		return new self(
			postId:     (int) $row['post_id'],
			topicId:    (int) $row['topic_id'],
			forumId:    (int) $row['forum_id'],
			subject:    (string) $row['post_subject'],
			excerpt:    mb_substr((string) $row['post_text'], 0, 200),
			postedAt:   (int) $row['post_time'],
			topicTitle: (string) $row['topic_title'],
			forumName:  (string) $row['forum_name'],
		);
	}

	public function toArray(): array
	{
		return [
			'postId'     => $this->postId,
			'topicId'    => $this->topicId,
			'forumId'    => $this->forumId,
			'subject'    => $this->subject,
			'excerpt'    => $this->excerpt,
			'postedAt'   => $this->postedAt,
			'topicTitle' => $this->topicTitle,
			'forumName'  => $this->forumName,
		];
	}
}
