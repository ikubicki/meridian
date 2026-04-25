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

namespace phpbb\tests\messaging\Entity;

use phpbb\messaging\Entity\Conversation;
use PHPUnit\Framework\TestCase;

class ConversationTest extends TestCase
{
	public function testConversationCanBeCreated(): void
	{
		$conversation = new Conversation(
			id: 1,
			participantHash: 'abc123',
			title: 'Test Conversation',
			createdBy: 1,
			createdAt: 1234567890,
			lastMessageId: null,
			lastMessageAt: null,
			messageCount: 0,
			participantCount: 2,
		);

		self::assertSame(1, $conversation->id);
		self::assertSame('abc123', $conversation->participantHash);
		self::assertSame('Test Conversation', $conversation->title);
		self::assertSame(1, $conversation->createdBy);
		self::assertSame(1234567890, $conversation->createdAt);
	}

	public function testConversationPropertiesAreReadonly(): void
	{
		$conversation = new Conversation(
			id: 1,
			participantHash: 'abc123',
			title: null,
			createdBy: 1,
			createdAt: 1234567890,
			lastMessageId: null,
			lastMessageAt: null,
			messageCount: 0,
			participantCount: 2,
		);

		// Verify it's a readonly object by checking reflection
		$reflection = new \ReflectionClass($conversation);
		self::assertTrue($reflection->isReadonly());
	}
}
