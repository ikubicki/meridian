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

use phpbb\api\Controller\ParticipantsController;
use phpbb\common\Event\DomainEventCollection;
use phpbb\messaging\Contract\MessagingServiceInterface;
use phpbb\messaging\DTO\ParticipantDTO;
use phpbb\messaging\Event\ParticipantAddedEvent;
use phpbb\user\Entity\User;
use phpbb\user\Enum\UserType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;

class ParticipantsControllerTest extends TestCase
{
	private MessagingServiceInterface&MockObject $messagingService;
	private EventDispatcherInterface&MockObject $dispatcher;
	private ParticipantsController $controller;

	protected function setUp(): void
	{
		$this->messagingService = $this->createMock(MessagingServiceInterface::class);
		$this->dispatcher = $this->createMock(EventDispatcherInterface::class);

		$this->controller = new ParticipantsController(
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

	private function makeParticipantDto(int $conversationId = 1, int $userId = 2): ParticipantDTO
	{
		return new ParticipantDTO(
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
	public function testList_returns401_whenNotAuthenticated(): void
	{
		$request = new Request();

		$response = $this->controller->list(1, $request);

		$this->assertSame(401, $response->getStatusCode());
	}

	#[Test]
	public function testList_returnsParticipants(): void
	{
		$user = $this->makeUser();
		$dto = $this->makeParticipantDto();

		$request = new Request();
		$request->attributes->set('_api_user', $user);

		$this->messagingService
			->expects($this->once())
			->method('listParticipants')
			->with(1, $user->id)
			->willReturn([$dto]);

		$response = $this->controller->list(1, $request);
		$body = json_decode($response->getContent(), true);

		$this->assertSame(200, $response->getStatusCode());
		$this->assertIsArray($body['data']);
		$this->assertCount(1, $body['data']);
	}

	#[Test]
	public function testAdd_returns401_whenNotAuthenticated(): void
	{
		$request = new Request(content: '{}');

		$response = $this->controller->add(1, $request);

		$this->assertSame(401, $response->getStatusCode());
	}

	#[Test]
	public function testAdd_returns400_whenUserIdMissing(): void
	{
		$user = $this->makeUser();

		$request = new Request(content: json_encode([]));
		$request->attributes->set('_api_user', $user);

		$response = $this->controller->add(1, $request);

		$this->assertSame(400, $response->getStatusCode());
	}

	#[Test]
	public function testAdd_returns201_onSuccess(): void
	{
		$user = $this->makeUser();
		$event = new ParticipantAddedEvent(entityId: 1, actorId: $user->id);
		$events = new DomainEventCollection([$event]);
		$dto = $this->makeParticipantDto();

		$request = new Request(content: json_encode(['userId' => 5]));
		$request->attributes->set('_api_user', $user);

		$this->messagingService
			->expects($this->once())
			->method('addParticipant')
			->with(1, 5, $user->id)
			->willReturn($events);

		$this->messagingService
			->expects($this->once())
			->method('listParticipants')
			->with(1, $user->id)
			->willReturn([$dto]);

		$this->dispatcher
			->expects($this->once())
			->method('dispatch');

		$response = $this->controller->add(1, $request);
		$body = json_decode($response->getContent(), true);

		$this->assertSame(201, $response->getStatusCode());
		$this->assertIsArray($body['data']);
	}
}
