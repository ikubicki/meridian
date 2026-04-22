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

namespace phpbb\Tests\threads\DTO;

use phpbb\threads\DTO\PostDTO;
use phpbb\threads\Entity\Post;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PostDTOTest extends TestCase
{
	private function makePost(): Post
	{
		return new Post(
			id: 500,
			topicId: 42,
			forumId: 3,
			posterId: 99,
			time: 1700000000,
			text: 'Post body content',
			subject: 'Re: Sample Topic',
			username: 'bob',
			posterIp: '10.0.0.1',
			visibility: 1,
		);
	}

	#[Test]
	public function fromEntityMapsAllFiveFieldsCorrectly(): void
	{
		// Arrange
		$post = $this->makePost();

		// Act
		$dto = PostDTO::fromEntity($post);

		// Assert — verify exact field mappings including renamed fields
		$this->assertSame(500, $dto->id);
		$this->assertSame(42, $dto->topicId);
		$this->assertSame(3, $dto->forumId);
		$this->assertSame(99, $dto->authorId);              // authorId ← posterId
		$this->assertSame('Post body content', $dto->content); // content ← text
	}
}
