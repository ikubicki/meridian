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

namespace phpbb\Tests\api\Controller;

use phpbb\api\Controller\ConversationsController;
use phpbb\common\Event\DomainEventCollection;
use phpbb\messaging\Contract\MessagingServiceInterface;
use phpbb\messaging\DTO\ConversationDTO;
use phpbb\messaging\Event\ConversationCreatedEvent;
use phpbb\user\DTO\PaginatedResult;
use phpbb\user\Entity\User;
use phpbb\user\Enum\UserType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;

class ConversationsControllerTest extends TestCase
{
	private MessagingServiceInterface&MockObject $messagingService;
	private EventDispatcherInterface&MockObject $dispatcher;
	private ConversationsController $controller;

	protected function setUp(): void
	{
		$this->messagingService = $this->createMock(MessagingServiceInterface::class);
		$this->dispatcher = $this->createMock(EventDispatcherInterface::class);

		$this->controller = new ConversationsController(
			$this->messagingService,
			$this->dispatcher,
		);
	}

	private function makeUser(int $id = 2): User
	{
		return new User(
			id: $id,
			type: UserType::Normal,
			username: 'anonymous',
			usernameClean: 'anonymous',
			email: '',
			passwordHash: '',
			colour: '',
			defaultGroupId: 1,
			avatarUrl: '',
			registeredAt: new \DateTimeImmutable('2020-01-01'),
			lastmark: new \DateTimeImmutable('2020-01-01'),
			posts: 0,
			lastPostTime: null,
			isNew: false,
			rank: 0,
			registrationIp: '127.0.0.1',
			loginAttempts: 0,
			inactiveReason: null,
			formSalt: '',
			activationKey: '',
		);
	}

	private function makeConversationDto(int $id = 1): ConversationDTO
	{
		return new ConversationDTO(
			id: $id,
			title: 'Test Conversation',
			createdBy: 2,
			createdAt: 1700000000,
			lastMessageId: null,
			lastMessageAt: null,
			messageCount: 0,
			participantCount: 2,
		);
	}

	#[Test]
	public function testList_returns401_whenNotAuthenticated(): void
	{
		$request = new Request();

		$response = $this->controller->list($request);

		$this->assertSame(401, $response->getStatusCode());
	}

	#[Test]
	public function testList_returnsPaginatedConversations(): void
	{
		$user = $this->makeUser();
		$dto = $this->makeConversationDto();
		$result = new PaginatedResult([$dto], 1, 1, 25);

		$request = new Request();
		$request->attributes->set('_api_user', $user);

		$this->messagingService
			->expects($this->once())
			->method('listConversations')
			->willReturn($result);

		$response = $this->controller->list($request);
		$body = json_decode($response->getContent(), true);

		$this->assertSame(200, $response->getStatusCode());
		$this->assertIsArray($body['data']);
		$this->assertArrayHasKey('meta', $body);
		$this->assertSame(1, $body['meta']['total']);
	}

	#[Test]
	public function testShow_returns401_whenNotAuthenticated(): void
	{
		$request = new Request();

		$response = $this->controller->show(1, $request);

		$this->assertSame(401, $response->getStatusCode());
	}

	#[Test]
	public function testShow_returnsConversation_whenFound(): void
	{
		$user = $this->makeUser();
		$dto = $this->makeConversationDto();

		$request = new Request();
		$request->attributes->set('_api_user', $user);

		$this->messagingService
			->expects($this->once())
			->method('getConversation')
			->with(1, $user->id)
			->willReturn($dto);

		$response = $this->controller->show(1, $request);
		$body = json_decode($response->getContent(), true);

		$this->assertSame(200, $response->getStatusCode());
		$this->assertSame(1, $body['data']['id']);
	}

	#[Test]
	public function testShow_returns404_whenNotFound(): void
	{
		$user = $this->makeUser();

		$request = new Request();
		$request->attributes->set('_api_user', $user);

		$this->messagingService
			->method('getConversation')
			->willThrowException(new \InvalidArgumentException('Conversation 1 not found'));

		$response = $this->controller->show(1, $request);

		$this->assertSame(404, $response->getStatusCode());
	}

	#[Test]
	public function testShow_returns403_whenUserNotParticipant(): void
	{
		$user = $this->makeUser();

		$request = new Request();
		$request->attributes->set('_api_user', $user);

		$this->messagingService
			->method('getConversation')
			->willThrowException(new \InvalidArgumentException('User not in conversation'));

		$response = $this->controller->show(1, $request);

		$this->assertSame(403, $response->getStatusCode());
	}

	#[Test]
	public function testCreate_returns401_whenNotAuthenticated(): void
	{
		$request = new Request(content: '{}');

		$response = $this->controller->create($request);

		$this->assertSame(401, $response->getStatusCode());
	}

	#[Test]
	public function testCreate_returns400_whenParticipantIdsEmpty(): void
	{
		$user = $this->makeUser();

		$request = new Request(content: json_encode(['participantIds' => []]));
		$request->attributes->set('_api_user', $user);

		$response = $this->controller->create($request);

		$this->assertSame(400, $response->getStatusCode());
	}

	#[Test]
	public function testCreate_returns201_withConversationData(): void
	{
		$user = $this->makeUser();
		$event = new ConversationCreatedEvent(entityId: 1, actorId: $user->id);
		$events = new DomainEventCollection([$event]);
		$dto = $this->makeConversationDto();

		$request = new Request(content: json_encode(['participantIds' => [3, 4], 'title' => 'New convo']));
		$request->attributes->set('_api_user', $user);

		$this->messagingService
			->expects($this->once())
			->method('createConversation')
			->willReturn($events);

		$this->messagingService
			->expects($this->once())
			->method('getConversation')
			->with(1, $user->id)
			->willReturn($dto);

		$this->dispatcher
			->expects($this->once())
			->method('dispatch');

		$response = $this->controller->create($request);
		$body = json_decode($response->getContent(), true);

		$this->assertSame(201, $response->getStatusCode());
		$this->assertSame(1, $body['data']['id']);
	}
}
