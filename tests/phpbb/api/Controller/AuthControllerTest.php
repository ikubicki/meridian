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

use phpbb\api\Controller\AuthController;
use phpbb\auth\Contract\AuthenticationServiceInterface;
use phpbb\auth\Exception\AuthenticationFailedException;
use phpbb\user\Entity\User;
use phpbb\user\Enum\UserType;
use phpbb\user\Exception\BannedException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class AuthControllerTest extends TestCase
{
	private AuthenticationServiceInterface $authService;
	private AuthController $controller;

	protected function setUp(): void
	{
		$this->authService = $this->createMock(AuthenticationServiceInterface::class);
		$this->controller  = new AuthController($this->authService);
	}

	#[Test]
	public function itReturns200WithTokensOnSuccessfulLogin(): void
	{
		$this->authService
			->expects(self::once())
			->method('login')
			->willReturn(['accessToken' => 'jwt', 'refreshToken' => 'r', 'expiresIn' => 900]);

		$request  = Request::create('/api/v1/auth/login', 'POST', [], [], [], [], json_encode(['username' => 'alice', 'password' => 'secret']));
		$response = $this->controller->login($request);

		self::assertSame(200, $response->getStatusCode());

		$body = json_decode($response->getContent(), true);
		self::assertArrayHasKey('data', $body);
		self::assertSame('jwt', $body['data']['accessToken']);
		self::assertSame('r', $body['data']['refreshToken']);
		self::assertSame(900, $body['data']['expiresIn']);
	}

	#[Test]
	public function itReturns422WhenUsernameIsMissing(): void
	{
		$request  = Request::create('/api/v1/auth/login', 'POST', [], [], [], [], json_encode(['password' => 'x']));
		$response = $this->controller->login($request);

		self::assertSame(422, $response->getStatusCode());

		$body = json_decode($response->getContent(), true);
		self::assertArrayHasKey('errors', $body);
	}

	#[Test]
	public function itReturns401OnAuthenticationFailedException(): void
	{
		$this->authService
			->method('login')
			->willThrowException(new AuthenticationFailedException('Bad credentials'));

		$request  = Request::create('/api/v1/auth/login', 'POST', [], [], [], [], json_encode(['username' => 'alice', 'password' => 'wrong']));
		$response = $this->controller->login($request);

		self::assertSame(401, $response->getStatusCode());
	}

	#[Test]
	public function itReturns403OnBannedException(): void
	{
		$this->authService
			->method('login')
			->willThrowException(new BannedException('Banned'));

		$request  = Request::create('/api/v1/auth/login', 'POST', [], [], [], [], json_encode(['username' => 'alice', 'password' => 'secret']));
		$response = $this->controller->login($request);

		self::assertSame(403, $response->getStatusCode());
	}

	#[Test]
	public function itReturns204OnLogout(): void
	{
		$user = $this->makeUser(99);

		$this->authService
			->expects(self::once())
			->method('logout')
			->with(99);

		$request = Request::create('/api/v1/auth/logout', 'POST');
		$request->attributes->set('_api_user', $user);

		$response = $this->controller->logout($request);

		self::assertSame(204, $response->getStatusCode());
		self::assertSame('', $response->getContent());
	}

	#[Test]
	public function itReturns200WithElevatedTokenOnElevate(): void
	{
		$user = $this->makeUser(5);

		$this->authService
			->expects(self::once())
			->method('elevate')
			->with(5, 'x')
			->willReturn(['elevatedToken' => 'e', 'expiresIn' => 300]);

		$request = Request::create('/api/v1/auth/elevate', 'POST', [], [], [], [], json_encode(['password' => 'x']));
		$request->attributes->set('_api_user', $user);

		$response = $this->controller->elevate($request);

		self::assertSame(200, $response->getStatusCode());

		$body = json_decode($response->getContent(), true);
		self::assertArrayHasKey('data', $body);
		self::assertSame('e', $body['data']['elevatedToken']);
		self::assertSame(300, $body['data']['expiresIn']);
	}

	private function makeUser(int $id): User
	{
		return new User(
			id:              $id,
			type:            UserType::Normal,
			username:        'alice',
			usernameClean:   'alice',
			email:           'alice@example.com',
			passwordHash:    '$argon2id$v=19$m=65536,t=4,p=1$fake',
			colour:          '',
			defaultGroupId:  2,
			avatarUrl:       '',
			registeredAt:    new \DateTimeImmutable(),
			lastmark:        new \DateTimeImmutable(),
			posts:           0,
			lastPostTime:    null,
			isNew:           false,
			rank:            0,
			registrationIp:  '127.0.0.1',
			loginAttempts:   0,
			inactiveReason:  null,
			formSalt:        '',
			activationKey:   '',
			tokenGeneration: 1,
			permVersion:     0,
		);
	}
}
