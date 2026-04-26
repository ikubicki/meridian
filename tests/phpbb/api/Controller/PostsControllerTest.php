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

use phpbb\api\Controller\PostsController;
use phpbb\auth\Contract\AuthorizationServiceInterface;
use phpbb\common\Event\DomainEventCollection;
use phpbb\threads\Contract\ThreadsServiceInterface;
use phpbb\threads\DTO\PostDTO;
use phpbb\threads\DTO\TopicDTO;
use phpbb\threads\Event\PostCreatedEvent;
use phpbb\user\Contract\UserRepositoryInterface;
use phpbb\user\DTO\PaginatedResult;
use phpbb\user\Entity\User;
use phpbb\user\Enum\UserType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;

class PostsControllerTest extends TestCase
{
	private ThreadsServiceInterface&MockObject $threadsService;
	private AuthorizationServiceInterface&MockObject $authorizationService;
	private UserRepositoryInterface&MockObject $userRepository;
	private EventDispatcherInterface&MockObject $dispatcher;
	private PostsController $controller;

	protected function setUp(): void
	{
		$this->threadsService = $this->createMock(ThreadsServiceInterface::class);
		$this->authorizationService = $this->createMock(AuthorizationServiceInterface::class);
		$this->userRepository = $this->createMock(UserRepositoryInterface::class);
		$this->dispatcher = $this->createMock(EventDispatcherInterface::class);

		$this->controller = new PostsController(
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

	private function makeTopicDto(): TopicDTO
	{
		return new TopicDTO(
			id: 10,
			title: 'Topic title',
			forumId: 2,
			authorId: 3,
			postCount: 4,
			lastPosterName: 'alice',
			lastPostTime: 1700000000,
			createdAt: 1699999999,
		);
	}

	private function makePostDto(int $id = 1): PostDTO
	{
		return new PostDTO(
			id:             $id,
			topicId:        10,
			forumId:        2,
			authorId:       3,
			authorUsername: 'alice',
			content:        'Post content',
			createdAt:      1700000000,
		);
	}

	#[Test]
	public function indexReturns200WithDataAndMetaPaginationEnvelope(): void
	{
		$this->threadsService->method('getTopic')->willReturn($this->makeTopicDto());
		$this->userRepository->method('findById')->willReturn($this->makeUser());
		$this->authorizationService->method('isGranted')->willReturn(true);
		$this->threadsService->method('listPosts')->willReturn(new PaginatedResult(
			items: [$this->makePostDto(91)],
			total: 1,
			page: 1,
			perPage: 25,
		));

		$response = $this->controller->index(10, Request::create('/api/v1/topics/10/posts'));

		self::assertSame(200, $response->getStatusCode());
		$body = json_decode((string) $response->getContent(), true);
		self::assertArrayHasKey('data', $body);
		self::assertArrayHasKey('meta', $body);
	}

	#[Test]
	public function indexReturns404WhenTopicDoesNotExist(): void
	{
		$this->threadsService->method('getTopic')->willThrowException(new \InvalidArgumentException('missing'));

		$response = $this->controller->index(999, Request::create('/api/v1/topics/999/posts'));

		self::assertSame(404, $response->getStatusCode());
	}

	#[Test]
	public function createReturns201WithPostDataEnvelope(): void
	{
		$request = Request::create('/api/v1/topics/10/posts', 'POST', [], [], [], [], json_encode([
			'content' => 'Reply message',
		]));
		$request->attributes->set('_api_user', $this->makeUser(15));

		$this->threadsService->method('getTopic')->willReturn($this->makeTopicDto());
		$this->authorizationService->method('isGranted')->willReturn(true);
		$this->threadsService->method('createPost')->willReturn(new DomainEventCollection([
			new PostCreatedEvent(entityId: 501, actorId: 15),
		]));
		$this->threadsService->method('getPost')->willReturn($this->makePostDto(501));

		$response = $this->controller->create(10, $request);

		self::assertSame(201, $response->getStatusCode());
		$body = json_decode((string) $response->getContent(), true);
		self::assertSame(501, $body['data']['id']);
	}

	#[Test]
	public function createReturns401WithoutAuthenticatedUser(): void
	{
		$request = Request::create('/api/v1/topics/10/posts', 'POST', [], [], [], [], json_encode([
			'content' => 'Reply message',
		]));

		$response = $this->controller->create(10, $request);

		self::assertSame(401, $response->getStatusCode());
	}

	#[Test]
	public function createReturns400WithEmptyContent(): void
	{
		$request = Request::create('/api/v1/topics/10/posts', 'POST', [], [], [], [], json_encode([
			'content' => '   ',
		]));
		$request->attributes->set('_api_user', $this->makeUser(15));

		$this->threadsService->method('getTopic')->willReturn($this->makeTopicDto());
		$this->authorizationService->method('isGranted')->willReturn(true);

		$response = $this->controller->create(10, $request);

		self::assertSame(400, $response->getStatusCode());
	}

	#[Test]
	public function createReturns404ForUnknownTopic(): void
	{
		$request = Request::create('/api/v1/topics/999/posts', 'POST', [], [], [], [], json_encode([
			'content' => 'Reply message',
		]));
		$request->attributes->set('_api_user', $this->makeUser(15));

		$this->threadsService->method('getTopic')->willThrowException(new \InvalidArgumentException('missing'));

		$response = $this->controller->create(999, $request);

		self::assertSame(404, $response->getStatusCode());
	}
}
