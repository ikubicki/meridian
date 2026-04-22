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

use phpbb\api\Controller\UsersController;
use phpbb\user\Entity\User;
use phpbb\user\Enum\UserType;
use phpbb\user\Service\UserSearchService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class UsersControllerTest extends TestCase
{
	private UserSearchService&MockObject $searchService;
	private UsersController $controller;

	protected function setUp(): void
	{
		$this->searchService = $this->createMock(UserSearchService::class);
		$this->controller    = new UsersController($this->searchService);
	}

	private function makeUser(int $id = 1): User
	{
		return new User(
			id: $id,
			type: UserType::Normal,
			username: 'alice',
			usernameClean: 'alice',
			email: 'alice@example.com',
			passwordHash: 'hash',
			colour: '',
			defaultGroupId: 2,
			avatarUrl: '',
			registeredAt: new \DateTimeImmutable('2026-01-01'),
			lastmark: new \DateTimeImmutable('2026-06-01'),
			posts: 0,
			lastPostTime: null,
			isNew: false,
			rank: 0,
			registrationIp: '127.0.0.1',
			loginAttempts: 0,
			inactiveReason: null,
			formSalt: 'salt',
			activationKey: '',
		);
	}

	#[Test]
	public function meReturns401WithoutToken(): void
	{
		$request  = Request::create('/api/v1/me');
		$response = $this->controller->me($request);

		self::assertSame(401, $response->getStatusCode());
	}

	#[Test]
	public function meReturnsCurrentUserDataEnvelope(): void
	{
		$user = $this->makeUser(1);

		$request = Request::create('/api/v1/me');
		$request->attributes->set('_api_user', $user);

		$response = $this->controller->me($request);

		self::assertSame(200, $response->getStatusCode());

		$body = json_decode($response->getContent(), true);
		self::assertArrayHasKey('data', $body);
		self::assertArrayHasKey('id', $body['data']);
		self::assertArrayHasKey('username', $body['data']);
		self::assertArrayHasKey('email', $body['data']);
		self::assertSame(1, $body['data']['id']);
	}

	#[Test]
	public function meReturns401WhenNoApiUser(): void
	{
		$request = Request::create('/api/v1/me');

		$response = $this->controller->me($request);

		self::assertSame(401, $response->getStatusCode());
	}

	#[Test]
	public function showReturnsPublicProfileWithoutEmail(): void
	{
		$user = $this->makeUser(1);
		$this->searchService->method('findById')->with(1)->willReturn($user);

		$response = $this->controller->show(1);

		self::assertSame(200, $response->getStatusCode());

		$body = json_decode($response->getContent(), true);
		self::assertArrayHasKey('data', $body);
		self::assertSame(1, $body['data']['id']);
		self::assertArrayNotHasKey('email', $body['data']);
	}

	#[Test]
	public function showReturns404ForNonExistingUser(): void
	{
		$this->searchService->method('findById')->with(999)->willReturn(null);

		$response = $this->controller->show(999);

		self::assertSame(404, $response->getStatusCode());

		$body = json_decode($response->getContent(), true);
		self::assertSame(404, $body['status']);
	}
}
