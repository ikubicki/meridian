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

namespace phpbb\Tests\threads\Entity;

use phpbb\threads\Entity\Topic;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TopicEntityTest extends TestCase
{
	#[Test]
	public function constructorAcceptsAllFifteenProperties(): void
	{
		// Arrange & Act
		$topic = new Topic(
			id: 1,
			forumId: 2,
			title: 'Test Topic',
			posterId: 10,
			time: 1700000000,
			postsApproved: 5,
			lastPostTime: 1700001000,
			lastPosterName: 'alice',
			lastPosterId: 10,
			lastPosterColour: 'ff0000',
			firstPostId: 100,
			lastPostId: 105,
			visibility: 1,
			firstPosterName: 'alice',
			firstPosterColour: 'ff0000',
		);

		// Assert
		$this->assertSame(1, $topic->id);
		$this->assertSame(2, $topic->forumId);
		$this->assertSame('Test Topic', $topic->title);
		$this->assertSame(10, $topic->posterId);
		$this->assertSame(1700000000, $topic->time);
		$this->assertSame(5, $topic->postsApproved);
		$this->assertSame(1700001000, $topic->lastPostTime);
		$this->assertSame('alice', $topic->lastPosterName);
		$this->assertSame(10, $topic->lastPosterId);
		$this->assertSame('ff0000', $topic->lastPosterColour);
		$this->assertSame(100, $topic->firstPostId);
		$this->assertSame(105, $topic->lastPostId);
		$this->assertSame(1, $topic->visibility);
		$this->assertSame('alice', $topic->firstPosterName);
		$this->assertSame('ff0000', $topic->firstPosterColour);
	}
}
