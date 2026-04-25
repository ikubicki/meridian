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

namespace phpbb\tests\messaging\Service;

use Doctrine\DBAL\Connection;
use phpbb\api\DTO\PaginationContext;
use phpbb\common\Event\DomainEventCollection;
use phpbb\messaging\Contract\ConversationRepositoryInterface;
use phpbb\messaging\Contract\ConversationServiceInterface;
use phpbb\messaging\Contract\MessageRepositoryInterface;
use phpbb\messaging\Contract\MessageServiceInterface;
use phpbb\messaging\Contract\ParticipantRepositoryInterface;
use phpbb\messaging\Contract\ParticipantServiceInterface;
use phpbb\messaging\DTO\ConversationDTO;
use phpbb\messaging\DTO\MessageDTO;
use phpbb\messaging\DTO\Request\CreateConversationRequest;
use phpbb\messaging\Entity\Conversation;
use phpbb\messaging\Entity\Message;
use phpbb\messaging\Entity\Participant;
use phpbb\messaging\MessagingService;
use phpbb\user\DTO\PaginatedResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class MessagingServiceTest extends TestCase
{
	private ConversationRepositoryInterface&MockObject $conversationRepo;
	private MessageRepositoryInterface&MockObject $messageRepo;
	private ParticipantRepositoryInterface&MockObject $participantRepo;
	private ConversationServiceInterface&MockObject $conversationService;
	private MessageServiceInterface&MockObject $messageService;
	private ParticipantServiceInterface&MockObject $participantService;
	private Connection&MockObject $connection;
	private MessagingService $service;

	protected function setUp(): void
	{
		$this->conversationRepo = $this->createMock(ConversationRepositoryInterface::class);
		$this->messageRepo = $this->createMock(MessageRepositoryInterface::class);
		$this->participantRepo = $this->createMock(ParticipantRepositoryInterface::class);
		$this->conversationService = $this->createMock(ConversationServiceInterface::class);
		$this->messageService = $this->createMock(MessageServiceInterface::class);
		$this->participantService = $this->createMock(ParticipantServiceInterface::class);
		$this->connection = $this->createMock(Connection::class);

		$this->service = new MessagingService(
			$this->conversationRepo,
			$this->messageRepo,
			$this->participantRepo,
			$this->conversationService,
			$this->messageService,
			$this->participantService,
			$this->connection,
		);
	}

	private function makeConversation(int $id = 1): Conversation
	{
		return new Conversation(
			id: $id,
			participantHash: 'abc123',
			title: 'Test Conversation',
			createdBy: 1,
			createdAt: 1700000000,
			lastMessageId: null,
			lastMessageAt: null,
			messageCount: 0,
			participantCount: 2,
		);
	}

	private function makeMessage(int $id = 1, int $conversationId = 1): Message
	{
		return new Message(
			id: $id,
			conversationId: $conversationId,
			authorId: 1,
			messageText: 'Hello world',
			messageSubject: null,
			createdAt: 1700000000,
			editedAt: null,
			editCount: 0,
			metadata: null,
		);
	}

	private function makeParticipant(int $conversationId = 1, int $userId = 1): Participant
	{
		return new Participant(
			conversationId: $conversationId,
			userId: $userId,
			role: 'member',
			state: 'active',
			joinedAt: 1700000000,
			leftAt: null,
			lastReadMessageId: null,
			lastReadAt: null,
			isMuted: false,
			isBlocked: false,
		);
	}

	#[Test]
	public function testListConversations_delegatesToRepo(): void
	{
		$ctx = new PaginationContext();
		$expected = new PaginatedResult([], 0, 1, 25);

		$this->conversationRepo
			->expects($this->once())
			->method('listByUser')
			->with(42, 'active', $ctx)
			->willReturn($expected);

		$result = $this->service->listConversations(42, 'active', $ctx);

		$this->assertSame($expected, $result);
	}

	#[Test]
	public function testGetConversation_returnsDTO_whenUserIsParticipant(): void
	{
		$conversation = $this->makeConversation(1);
		$participant = $this->makeParticipant(1, 5);

		$this->conversationRepo
			->expects($this->once())
			->method('findById')
			->with(1)
			->willReturn($conversation);

		$this->participantRepo
			->expects($this->once())
			->method('findByConversationAndUser')
			->with(1, 5)
			->willReturn($participant);

		$dto = $this->service->getConversation(1, 5);

		$this->assertInstanceOf(ConversationDTO::class, $dto);
		$this->assertSame(1, $dto->id);
	}

	#[Test]
	public function testGetConversation_throwsWhenConversationNotFound(): void
	{
		$this->conversationRepo
			->expects($this->once())
			->method('findById')
			->with(999)
			->willReturn(null);

		$this->expectException(\InvalidArgumentException::class);

		$this->service->getConversation(999, 1);
	}

	#[Test]
	public function testGetConversation_throwsWhenUserNotParticipant(): void
	{
		$conversation = $this->makeConversation(1);

		$this->conversationRepo
			->expects($this->once())
			->method('findById')
			->with(1)
			->willReturn($conversation);

		$this->participantRepo
			->expects($this->once())
			->method('findByConversationAndUser')
			->with(1, 99)
			->willReturn(null);

		$this->expectException(\InvalidArgumentException::class);

		$this->service->getConversation(1, 99);
	}

	#[Test]
	public function testCreateConversation_delegatesToConversationService(): void
	{
		$request = new CreateConversationRequest(participantIds: [2, 3], title: 'Test');
		$events = new DomainEventCollection([]);

		$this->conversationService
			->expects($this->once())
			->method('createConversation')
			->with($request, 1)
			->willReturn($events);

		$result = $this->service->createConversation($request, 1);

		$this->assertSame($events, $result);
	}

	#[Test]
	public function testListMessages_throwsWhenUserNotParticipant(): void
	{
		$this->participantRepo
			->expects($this->once())
			->method('findByConversationAndUser')
			->with(1, 99)
			->willReturn(null);

		$this->expectException(\InvalidArgumentException::class);

		$this->service->listMessages(1, 99, new PaginationContext());
	}

	#[Test]
	public function testListMessages_delegatesToMessageRepo_whenUserIsParticipant(): void
	{
		$participant = $this->makeParticipant(1, 5);
		$ctx = new PaginationContext();
		$expected = new PaginatedResult([], 0, 1, 25);

		$this->participantRepo
			->expects($this->once())
			->method('findByConversationAndUser')
			->with(1, 5)
			->willReturn($participant);

		$this->messageRepo
			->expects($this->once())
			->method('listByConversation')
			->with(1, $ctx)
			->willReturn($expected);

		$result = $this->service->listMessages(1, 5, $ctx);

		$this->assertSame($expected, $result);
	}

	#[Test]
	public function testGetMessage_returnsDTO_whenUserIsParticipant(): void
	{
		$message = $this->makeMessage(10, 1);
		$participant = $this->makeParticipant(1, 5);

		$this->messageRepo
			->expects($this->once())
			->method('findById')
			->with(10)
			->willReturn($message);

		$this->participantRepo
			->expects($this->once())
			->method('findByConversationAndUser')
			->with(1, 5)
			->willReturn($participant);

		$this->messageRepo
			->expects($this->once())
			->method('isDeletedForUser')
			->with(10, 5)
			->willReturn(false);

		$dto = $this->service->getMessage(10, 5);

		$this->assertInstanceOf(MessageDTO::class, $dto);
		$this->assertSame(10, $dto->id);
	}

	#[Test]
	public function testGetMessage_throwsWhenMessageNotFound(): void
	{
		$this->messageRepo
			->expects($this->once())
			->method('findById')
			->with(999)
			->willReturn(null);

		$this->expectException(\InvalidArgumentException::class);

		$this->service->getMessage(999, 1);
	}

	#[Test]
	public function testSearchMessages_throwsWhenUserNotParticipant(): void
	{
		$this->participantRepo
			->expects($this->once())
			->method('findByConversationAndUser')
			->with(1, 99)
			->willReturn(null);

		$this->expectException(\InvalidArgumentException::class);

		$this->service->searchMessages(1, 99, 'hello', new PaginationContext());
	}
}
