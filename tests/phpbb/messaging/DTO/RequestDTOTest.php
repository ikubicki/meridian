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

use phpbb\messaging\DTO\Request\CreateConversationRequest;
use phpbb\messaging\DTO\Request\SendMessageRequest;
use PHPUnit\Framework\TestCase;

class RequestDTOTest extends TestCase
{
	public function testCreateConversationRequestCanBeCreated(): void
	{
		$request = new CreateConversationRequest(
			title: 'Test Conversation',
			participantIds: [2, 3],
		);

		self::assertSame('Test Conversation', $request->title);
		self::assertSame([2, 3], $request->participantIds);
	}

	public function testSendMessageRequestCanBeCreated(): void
	{
		$request = new SendMessageRequest(
			messageText: 'Hello, World!',
			messageSubject: 'Greeting',
			metadata: null,
		);

		self::assertSame('Hello, World!', $request->messageText);
		self::assertSame('Greeting', $request->messageSubject);
	}

	public function testSendMessageRequestWithDefaults(): void
	{
		$request = new SendMessageRequest(
			messageText: 'Simple message',
		);

		self::assertSame('Simple message', $request->messageText);
		self::assertNull($request->messageSubject);
		self::assertNull($request->metadata);
	}
}
