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

use phpbb\api\Controller\TopicsController;
use phpbb\auth\Contract\AuthorizationServiceInterface;
use phpbb\common\Event\DomainEventCollection;
use phpbb\threads\Contract\ThreadsServiceInterface;
use phpbb\threads\DTO\TopicDTO;
use phpbb\threads\Event\TopicCreatedEvent;
use phpbb\user\Contract\UserRepositoryInterface;
use phpbb\user\DTO\PaginatedResult;
use phpbb\user\Entity\User;
use phpbb\user\Enum\UserType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;

class TopicsControllerTest extends TestCase
{
	private ThreadsServiceInterface&MockObject $threadsService;
	private AuthorizationServiceInterface&MockObject $authorizationService;
	private UserRepositoryInterface&MockObject $userRepository;
	private EventDispatcherInterface&MockObject $dispatcher;
	private TopicsController $controller;

	protected function setUp(): void
	{
		$this->threadsService = $this->createMock(ThreadsServiceInterface::class);
		$this->authorizationService = $this->createMock(AuthorizationServiceInterface::class);
		$this->userRepository = $this->createMock(UserRepositoryInterface::class);
		$this->dispatcher = $this->createMock(EventDispatcherInterface::class);

		$this->controller = new TopicsController(
			$this->threadsService,
			$this->authorizationService,
			$this->userRepository,
			$this->dispatcher,
		);
	}

	private function makeUser(int $id = 1): User
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

	private function makeTopicDto(int $id = 1): TopicDTO
	{
		return new TopicDTO(
			id: $id,
			title: 'Topic title',
			forumId: 2,
			authorId: 3,
			postCount: 4,
			lastPosterName: 'alice',
			lastPostTime: 1700000000,
			createdAt: 1699999999,
		);
	}

	#[Test]
	public function indexByForumReturns200WithDataAndMetaEnvelope(): void
	{
		$this->userRepository->method('findById')->willReturn($this->makeUser());
		$this->authorizationService->method('isGranted')->willReturn(true);
		$this->threadsService->method('listTopics')->willReturn(new PaginatedResult(
			items: [$this->makeTopicDto(11)],
			total: 1,
			page: 1,
			perPage: 25,
		));

		$response = $this->controller->indexByForum(2, Request::create('/api/v1/forums/2/topics'));

		self::assertSame(200, $response->getStatusCode());
		$body = json_decode((string) $response->getContent(), true);
		self::assertArrayHasKey('data', $body);
		self::assertArrayHasKey('meta', $body);
	}

	#[Test]
	public function indexByForumReturns403WhenAclDenied(): void
	{
		$this->userRepository->method('findById')->willReturn($this->makeUser());
		$this->authorizationService->method('isGranted')->willReturn(false);

		$response = $this->controller->indexByForum(2, Request::create('/api/v1/forums/2/topics'));

		self::assertSame(403, $response->getStatusCode());
	}

	#[Test]
	public function showReturns200WithTopicDtoFieldsInDataEnvelope(): void
	{
		$this->threadsService->method('getTopic')->willReturn($this->makeTopicDto(77));
		$this->userRepository->method('findById')->willReturn($this->makeUser());
		$this->authorizationService->method('isGranted')->willReturn(true);

		$response = $this->controller->show(77, Request::create('/api/v1/topics/77'));

		self::assertSame(200, $response->getStatusCode());
		$body = json_decode((string) $response->getContent(), true);
		self::assertSame(77, $body['data']['id']);
		self::assertArrayHasKey('title', $body['data']);
		self::assertArrayHasKey('forumId', $body['data']);
		self::assertArrayHasKey('authorId', $body['data']);
		self::assertArrayHasKey('postCount', $body['data']);
		self::assertArrayHasKey('lastPosterName', $body['data']);
		self::assertArrayHasKey('lastPostTime', $body['data']);
		self::assertArrayHasKey('createdAt', $body['data']);
	}

	#[Test]
	public function showReturns404WhenTopicDoesNotExist(): void
	{
		$this->threadsService->method('getTopic')->willThrowException(new \InvalidArgumentException('missing'));

		$response = $this->controller->show(999, Request::create('/api/v1/topics/999'));

		self::assertSame(404, $response->getStatusCode());
	}

	#[Test]
	public function showReturns403WhenAclDenied(): void
	{
		$this->threadsService->method('getTopic')->willReturn($this->makeTopicDto(5));
		$this->userRepository->method('findById')->willReturn($this->makeUser());
		$this->authorizationService->method('isGranted')->willReturn(false);

		$response = $this->controller->show(5, Request::create('/api/v1/topics/5'));

		self::assertSame(403, $response->getStatusCode());
	}

	#[Test]
	public function createReturns201WithFullTopicDtoData(): void
	{
		$request = Request::create('/api/v1/forums/3/topics', 'POST', [], [], [], [], json_encode([
			'title' => 'New topic',
			'content' => 'First post',
		]));
		$request->attributes->set('_api_user', $this->makeUser(12));

		$this->authorizationService->method('isGranted')->willReturn(true);
		$this->threadsService->method('createTopic')->willReturn(new DomainEventCollection([
			new TopicCreatedEvent(entityId: 321, actorId: 12),
		]));
		$this->threadsService->method('getTopic')->with(321)->willReturn($this->makeTopicDto(321));

		$response = $this->controller->create(3, $request);

		self::assertSame(201, $response->getStatusCode());
		$body = json_decode((string) $response->getContent(), true);
		self::assertSame(321, $body['data']['id']);
		self::assertArrayHasKey('postCount', $body['data']);
	}

	#[Test]
	public function createReturns401WithoutAuthenticatedUser(): void
	{
		$request = Request::create('/api/v1/forums/3/topics', 'POST', [], [], [], [], json_encode([
			'title' => 'New topic',
			'content' => 'First post',
		]));

		$response = $this->controller->create(3, $request);

		self::assertSame(401, $response->getStatusCode());
	}

	#[Test]
	public function createReturns400WithEmptyTitle(): void
	{
		$request = Request::create('/api/v1/forums/3/topics', 'POST', [], [], [], [], json_encode([
			'title' => '   ',
			'content' => 'First post',
		]));
		$request->attributes->set('_api_user', $this->makeUser(12));
		$this->authorizationService->method('isGranted')->willReturn(true);

		$response = $this->controller->create(3, $request);

		self::assertSame(400, $response->getStatusCode());
	}
}
