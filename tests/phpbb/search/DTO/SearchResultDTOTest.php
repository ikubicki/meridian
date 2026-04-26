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

namespace phpbb\Tests\search\DTO;

use phpbb\search\DTO\SearchResultDTO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SearchResultDTOTest extends TestCase
{
	#[Test]
	public function it_maps_all_fields_from_row(): void
	{
		// Arrange
		$row = [
			'post_id'      => 42,
			'topic_id'     => 7,
			'forum_id'     => 3,
			'post_subject' => 'Hello',
			'post_text'    => 'Body text',
			'post_time'    => 1714089600,
			'topic_title'  => 'Hello World Topic',
			'forum_name'   => 'General Discussion',
		];

		// Act
		$dto = SearchResultDTO::fromRow($row);

		// Assert
		$this->assertSame(42, $dto->postId);
		$this->assertSame(7, $dto->topicId);
		$this->assertSame(3, $dto->forumId);
		$this->assertSame('Hello', $dto->subject);
		$this->assertSame('Body text', $dto->excerpt);
		$this->assertSame(1714089600, $dto->postedAt);
		$this->assertSame('Hello World Topic', $dto->topicTitle);
		$this->assertSame('General Discussion', $dto->forumName);

		$this->assertIsInt($dto->postId);
		$this->assertIsInt($dto->topicId);
		$this->assertIsInt($dto->forumId);
		$this->assertIsString($dto->subject);
		$this->assertIsString($dto->excerpt);
		$this->assertIsInt($dto->postedAt);
		$this->assertIsString($dto->topicTitle);
		$this->assertIsString($dto->forumName);
	}

	#[Test]
	public function it_truncates_excerpt_to_200_chars(): void
	{
		// Arrange
		$row = [
			'post_id'      => 1,
			'topic_id'     => 1,
			'forum_id'     => 1,
			'post_subject' => 'Subject',
			'post_text'    => str_repeat('a', 250),
			'post_time'    => 0,
			'topic_title'  => 'Title',
			'forum_name'   => 'Forum',
		];

		// Act
		$dto = SearchResultDTO::fromRow($row);

		// Assert
		$this->assertSame(200, mb_strlen($dto->excerpt));
	}
}
