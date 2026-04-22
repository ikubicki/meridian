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

use Doctrine\DBAL\Connection;
use phpbb\api\Controller\TopicsController;
use phpbb\auth\Contract\AuthorizationServiceInterface;
use phpbb\user\Contract\UserRepositoryInterface;
use phpbb\user\Entity\User;
use phpbb\user\Enum\UserType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class TopicsControllerTest extends TestCase
{
	private Connection $connection;
	private AuthorizationServiceInterface $authService;
	private UserRepositoryInterface $userRepository;
	private TopicsController $controller;

	protected function setUp(): void
	{
		$this->connection     = $this->createMock(Connection::class);
		$this->authService    = $this->createMock(AuthorizationServiceInterface::class);
		$this->userRepository = $this->createMock(UserRepositoryInterface::class);

		$this->controller = new TopicsController(
			$this->connection,
			$this->authService,
			$this->userRepository,
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

	#[Test]
	public function indexByForumReturnsDataEnvelopeWithMeta(): void
	{
		$this->userRepository->method('findById')->willReturn($this->makeUser());
		$this->authService->method('isGranted')->willReturn(true);
		$this->connection->method('fetchOne')->willReturn('0');
		$this->connection->method('fetchAllAssociative')->willReturn([]);

		$request  = Request::create('/api/v1/forums/1/topics');
		$response = $this->controller->indexByForum(1, $request);

		self::assertSame(200, $response->getStatusCode());

		$body = json_decode($response->getContent(), true);
		self::assertArrayHasKey('data', $body);
		self::assertArrayHasKey('meta', $body);
		self::assertArrayHasKey('total', $body['meta']);
		self::assertArrayHasKey('page', $body['meta']);
		self::assertArrayHasKey('perPage', $body['meta']);
		self::assertArrayHasKey('lastPage', $body['meta']);
	}

	#[Test]
	public function indexByForumReturnsEmptyDataForUnknownForum(): void
	{
		$this->userRepository->method('findById')->willReturn($this->makeUser());
		$this->authService->method('isGranted')->willReturn(true);
		$this->connection->method('fetchOne')->willReturn('0');
		$this->connection->method('fetchAllAssociative')->willReturn([]);

		$request  = Request::create('/api/v1/forums/999/topics');
		$response = $this->controller->indexByForum(999, $request);

		self::assertSame(200, $response->getStatusCode());

		$body = json_decode($response->getContent(), true);
		self::assertSame([], $body['data']);
		self::assertSame(0, $body['meta']['total']);
	}

	#[Test]
	public function indexByForumReturns403WhenGuestHasNoReadPermission(): void
	{
		$this->userRepository->method('findById')->willReturn($this->makeUser());
		$this->authService->method('isGranted')->willReturn(false);

		$request  = Request::create('/api/v1/forums/99/topics');
		$response = $this->controller->indexByForum(99, $request);

		self::assertSame(403, $response->getStatusCode());
	}

	#[Test]
	public function showReturnsTopicDataForExistingId(): void
	{
		$topicRow = [
			'topic_id'              => 1,
			'forum_id'              => 2,
			'topic_title'           => 'Test topic',
			'topic_poster'          => 200,
			'topic_time'            => 1700000000,
			'topic_posts_approved'  => 3,
			'topic_last_post_time'  => 1700001000,
			'topic_last_poster_name' => 'alice',
			'topic_visibility'      => 1,
		];

		$this->connection->method('fetchAssociative')->willReturn($topicRow);
		$this->userRepository->method('findById')->willReturn($this->makeUser());
		$this->authService->method('isGranted')->willReturn(true);

		$request  = Request::create('/api/v1/topics/1');
		$response = $this->controller->show(1, $request);

		self::assertSame(200, $response->getStatusCode());

		$body = json_decode($response->getContent(), true);
		self::assertArrayHasKey('data', $body);
		self::assertSame(1, $body['data']['id']);
		self::assertArrayHasKey('title', $body['data']);
	}

	#[Test]
	public function showReturns404ForNonExistingTopic(): void
	{
		$this->connection->method('fetchAssociative')->willReturn(false);

		$request  = Request::create('/api/v1/topics/999');
		$response = $this->controller->show(999, $request);

		self::assertSame(404, $response->getStatusCode());

		$body = json_decode($response->getContent(), true);
		self::assertSame(404, $body['status']);
	}

	#[Test]
	public function showReturns403WhenGuestHasNoReadPermission(): void
	{
		$topicRow = [
			'topic_id'              => 5,
			'forum_id'              => 10,
			'topic_title'           => 'Restricted',
			'topic_poster'          => 1,
			'topic_time'            => 1700000000,
			'topic_posts_approved'  => 1,
			'topic_last_post_time'  => 1700000000,
			'topic_last_poster_name' => 'anonymous',
			'topic_visibility'      => 1,
		];

		$this->connection->method('fetchAssociative')->willReturn($topicRow);
		$this->userRepository->method('findById')->willReturn($this->makeUser());
		$this->authService->method('isGranted')->willReturn(false);

		$request  = Request::create('/api/v1/topics/5');
		$response = $this->controller->show(5, $request);

		self::assertSame(403, $response->getStatusCode());
	}
}
