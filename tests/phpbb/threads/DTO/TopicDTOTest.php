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

use phpbb\threads\DTO\TopicDTO;
use phpbb\threads\Entity\Topic;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TopicDTOTest extends TestCase
{
	private function makeTopic(): Topic
	{
		return new Topic(
			id: 42,
			forumId: 3,
			title: 'Sample Topic',
			posterId: 99,
			time: 1700000000,
			postsApproved: 7,
			lastPostTime: 1700005000,
			lastPosterName: 'bob',
			lastPosterId: 99,
			lastPosterColour: '00ff00',
			firstPostId: 300,
			lastPostId: 306,
			visibility: 1,
			firstPosterName: 'bob',
			firstPosterColour: '00ff00',
		);
	}

	#[Test]
	public function fromEntityMapsAllEightFieldsCorrectly(): void
	{
		// Arrange
		$topic = $this->makeTopic();

		// Act
		$dto = TopicDTO::fromEntity($topic);

		// Assert — verify exact field mappings including renamed fields
		$this->assertSame(42, $dto->id);
		$this->assertSame('Sample Topic', $dto->title);
		$this->assertSame(3, $dto->forumId);
		$this->assertSame(99, $dto->authorId);       // authorId ← posterId
		$this->assertSame(7, $dto->postCount);        // postCount ← postsApproved
		$this->assertSame('bob', $dto->lastPosterName);
		$this->assertSame(1700005000, $dto->lastPostTime);
		$this->assertSame(1700000000, $dto->createdAt); // createdAt ← time
	}
}
