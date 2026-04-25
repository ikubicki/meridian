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

namespace phpbb\tests\messaging\DTO;

use phpbb\messaging\DTO\ConversationDTO;
use phpbb\messaging\Entity\Conversation;
use PHPUnit\Framework\TestCase;

class ConversationDTOTest extends TestCase
{
	public function testConversationDTOCanBeCreatedFromEntity(): void
	{
		$entity = new Conversation(
			id: 1,
			participantHash: 'abc123',
			title: 'Test',
			createdBy: 2,
			createdAt: 1234567890,
			lastMessageId: 5,
			lastMessageAt: 1234567900,
			messageCount: 10,
			participantCount: 3,
		);

		$dto = ConversationDTO::fromEntity($entity);

		self::assertSame(1, $dto->id);
		self::assertSame('Test', $dto->title);
		self::assertSame(2, $dto->createdBy);
		self::assertSame(10, $dto->messageCount);
		self::assertSame(3, $dto->participantCount);
	}

	public function testConversationDTOCanBeCreatedDirectly(): void
	{
		$dto = new ConversationDTO(
			id: 1,
			title: 'Direct',
			createdBy: 1,
			createdAt: 1234567890,
			lastMessageId: null,
			lastMessageAt: null,
			messageCount: 0,
			participantCount: 2,
		);

		self::assertSame(1, $dto->id);
		self::assertSame('Direct', $dto->title);
	}
}
