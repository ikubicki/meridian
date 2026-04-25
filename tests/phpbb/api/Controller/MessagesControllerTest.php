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

use phpbb\api\Controller\MessagesController;
use phpbb\common\Event\DomainEventCollection;
use phpbb\messaging\Contract\MessagingServiceInterface;
use phpbb\messaging\DTO\MessageDTO;
use phpbb\messaging\Event\MessageCreatedEvent;
use phpbb\user\DTO\PaginatedResult;
use phpbb\user\Entity\User;
use phpbb\user\Enum\UserType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;

class MessagesControllerTest extends TestCase
{
	private MessagingServiceInterface&MockObject $messagingService;
	private EventDispatcherInterface&MockObject $dispatcher;
	private MessagesController $controller;

	protected function setUp(): void
	{
		$this->messagingService = $this->createMock(MessagingServiceInterface::class);
		$this->dispatcher = $this->createMock(EventDispatcherInterface::class);

		$this->controller = new MessagesController(
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

	private function makeMessageDto(int $id = 1): MessageDTO
	{
		return new MessageDTO(
			id: $id,
			conversationId: 1,
			authorId: 2,
			messageText: 'Hello world',
			messageSubject: null,
			createdAt: 1700000000,
			editedAt: null,
			editCount: 0,
			metadata: null,
		);
	}

	#[Test]
	public function testList_returns401_whenNotAuthenticated(): void
	{
		$request = new Request();

		$response = $this->controller->list(1, $request);

		$this->assertSame(401, $response->getStatusCode());
	}

	#[Test]
	public function testList_returns403_whenUserNotParticipant(): void
	{
		$user = $this->makeUser();

		$request = new Request();
		$request->attributes->set('_api_user', $user);

		$this->messagingService
			->method('listMessages')
			->willThrowException(new \InvalidArgumentException('User not in conversation'));

		$response = $this->controller->list(1, $request);

		$this->assertSame(403, $response->getStatusCode());
	}

	#[Test]
	public function testList_returnsPaginatedMessages(): void
	{
		$user = $this->makeUser();
		$dto = $this->makeMessageDto();
		$result = new PaginatedResult([$dto], 1, 1, 25);

		$request = new Request();
		$request->attributes->set('_api_user', $user);

		$this->messagingService
			->expects($this->once())
			->method('listMessages')
			->willReturn($result);

		$response = $this->controller->list(1, $request);
		$body = json_decode($response->getContent(), true);

		$this->assertSame(200, $response->getStatusCode());
		$this->assertIsArray($body['data']);
		$this->assertArrayHasKey('meta', $body);
	}

	#[Test]
	public function testShow_returns401_whenNotAuthenticated(): void
	{
		$request = new Request();

		$response = $this->controller->show(1, $request);

		$this->assertSame(401, $response->getStatusCode());
	}

	#[Test]
	public function testShow_returns404_whenNotFound(): void
	{
		$user = $this->makeUser();

		$request = new Request();
		$request->attributes->set('_api_user', $user);

		$this->messagingService
			->method('getMessage')
			->willThrowException(new \InvalidArgumentException('Message 1 not found'));

		$response = $this->controller->show(1, $request);

		$this->assertSame(404, $response->getStatusCode());
	}

	#[Test]
	public function testShow_returnsMessage(): void
	{
		$user = $this->makeUser();
		$dto = $this->makeMessageDto();

		$request = new Request();
		$request->attributes->set('_api_user', $user);

		$this->messagingService
			->expects($this->once())
			->method('getMessage')
			->with(1, $user->id)
			->willReturn($dto);

		$response = $this->controller->show(1, $request);
		$body = json_decode($response->getContent(), true);

		$this->assertSame(200, $response->getStatusCode());
		$this->assertSame(1, $body['data']['id']);
	}

	#[Test]
	public function testSend_returns401_whenNotAuthenticated(): void
	{
		$request = new Request(content: '{}');

		$response = $this->controller->send(1, $request);

		$this->assertSame(401, $response->getStatusCode());
	}

	#[Test]
	public function testSend_returns400_whenTextEmpty(): void
	{
		$user = $this->makeUser();

		$request = new Request(content: json_encode(['text' => '']));
		$request->attributes->set('_api_user', $user);

		$response = $this->controller->send(1, $request);

		$this->assertSame(400, $response->getStatusCode());
	}

	#[Test]
	public function testSend_returns201_withMessageData(): void
	{
		$user = $this->makeUser();
		$event = new MessageCreatedEvent(entityId: 1, actorId: $user->id);
		$events = new DomainEventCollection([$event]);
		$dto = $this->makeMessageDto();

		$request = new Request(content: json_encode(['text' => 'Hello!']));
		$request->attributes->set('_api_user', $user);

		$this->messagingService
			->expects($this->once())
			->method('sendMessage')
			->willReturn($events);

		$this->messagingService
			->expects($this->once())
			->method('getMessage')
			->with(1, $user->id)
			->willReturn($dto);

		$this->dispatcher
			->expects($this->once())
			->method('dispatch');

		$response = $this->controller->send(1, $request);
		$body = json_decode($response->getContent(), true);

		$this->assertSame(201, $response->getStatusCode());
		$this->assertSame(1, $body['data']['id']);
	}
}
