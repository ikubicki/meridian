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

use phpbb\threads\Entity\Post;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PostEntityTest extends TestCase
{
	#[Test]
	public function constructorAcceptsAllTenProperties(): void
	{
		// Arrange & Act
		$post = new Post(
			id: 200,
			topicId: 1,
			forumId: 2,
			posterId: 10,
			time: 1700000000,
			text: 'Hello world',
			subject: 'Re: Test Topic',
			username: 'alice',
			posterIp: '127.0.0.1',
			visibility: 1,
		);

		// Assert
		$this->assertSame(200, $post->id);
		$this->assertSame(1, $post->topicId);
		$this->assertSame(2, $post->forumId);
		$this->assertSame(10, $post->posterId);
		$this->assertSame(1700000000, $post->time);
		$this->assertSame('Hello world', $post->text);
		$this->assertSame('Re: Test Topic', $post->subject);
		$this->assertSame('alice', $post->username);
		$this->assertSame('127.0.0.1', $post->posterIp);
		$this->assertSame(1, $post->visibility);
	}
}
