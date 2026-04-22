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

namespace phpbb\Tests\api\EventSubscriber;

use phpbb\api\EventSubscriber\AuthenticationSubscriber;
use phpbb\auth\Contract\TokenServiceInterface;
use phpbb\auth\Entity\TokenPayload;
use phpbb\user\Contract\UserRepositoryInterface;
use phpbb\user\Entity\User;
use phpbb\user\Enum\UserType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class AuthenticationSubscriberTest extends TestCase
{
	private TokenServiceInterface $tokenService;
	private UserRepositoryInterface $userRepository;
	private AuthenticationSubscriber $subscriber;
	private HttpKernelInterface $kernel;

	protected function setUp(): void
	{
		$this->tokenService   = $this->createMock(TokenServiceInterface::class);
		$this->userRepository = $this->createMock(UserRepositoryInterface::class);
		$this->subscriber     = new AuthenticationSubscriber($this->tokenService, $this->userRepository);
		$this->kernel         = $this->createMock(HttpKernelInterface::class);
	}

	#[Test]
	public function itIgnoresNonApiPaths(): void
	{
		$request = Request::create('/viewtopic.php');
		$event   = $this->makeEvent($request);

		$this->subscriber->onKernelRequest($event);

		self::assertNull($event->getResponse());
	}

	#[Test]
	public function itAllowsHealthWithoutToken(): void
	{
		$request = Request::create('/api/v1/health');
		$event   = $this->makeEvent($request);

		$this->subscriber->onKernelRequest($event);

		self::assertNull($event->getResponse());
	}

	#[Test]
	public function itAllowsLoginWithoutToken(): void
	{
		$request = Request::create('/api/v1/auth/login', 'POST');
		$event   = $this->makeEvent($request);

		$this->subscriber->onKernelRequest($event);

		self::assertNull($event->getResponse());
	}

	#[Test]
	public function itAllowsSignupWithoutToken(): void
	{
		$request = Request::create('/api/v1/auth/signup', 'POST');
		$event   = $this->makeEvent($request);

		$this->subscriber->onKernelRequest($event);

		self::assertNull($event->getResponse());
	}

	#[Test]
	public function itReturns401WhenAuthorizationHeaderMissing(): void
	{
		$request = Request::create('/api/v1/forums');
		$event   = $this->makeEvent($request);

		$this->subscriber->onKernelRequest($event);

		$response = $event->getResponse();
		self::assertNotNull($response);
		self::assertSame(401, $response->getStatusCode());

		$body = json_decode($response->getContent(), true);
		self::assertSame(401, $body['status']);
	}

	#[Test]
	public function itReturns401WhenAuthorizationHeaderIsMalformed(): void
	{
		$request = Request::create('/api/v1/forums');
		$request->headers->set('Authorization', 'Basic dXNlcjpwYXNz');
		$event = $this->makeEvent($request);

		$this->subscriber->onKernelRequest($event);

		$response = $event->getResponse();
		self::assertNotNull($response);
		self::assertSame(401, $response->getStatusCode());
	}

	#[Test]
	public function itSets_api_userAttributeAfterSuccessfulValidation(): void
	{
		$payload = $this->makePayload(sub: 2, gen: 1, pv: 0);
		$user    = $this->makeUser(id: 2, tokenGeneration: 1, permVersion: 0);

		$this->tokenService
			->method('decodeToken')
			->willReturn($payload);

		$this->userRepository
			->method('findById')
			->with(2)
			->willReturn($user);

		$request = Request::create('/api/v1/forums');
		$request->headers->set('Authorization', 'Bearer valid.token.here');
		$event = $this->makeEvent($request);

		$this->subscriber->onKernelRequest($event);

		self::assertNull($event->getResponse());
		self::assertSame($user, $request->attributes->get('_api_user'));
	}

	#[Test]
	public function itReturns401ForExpiredToken(): void
	{
		$this->tokenService
			->method('decodeToken')
			->willThrowException(new \UnexpectedValueException('Token expired'));

		$request = Request::create('/api/v1/forums');
		$request->headers->set('Authorization', 'Bearer expired.token.here');
		$event = $this->makeEvent($request);

		$this->subscriber->onKernelRequest($event);

		$response = $event->getResponse();
		self::assertNotNull($response);
		self::assertSame(401, $response->getStatusCode());

		$body = json_decode($response->getContent(), true);
		self::assertSame('Invalid token', $body['error']);
	}

	#[Test]
	public function itReturns401ForInvalidSignature(): void
	{
		$this->tokenService
			->method('decodeToken')
			->willThrowException(new \UnexpectedValueException('Invalid signature'));

		$request = Request::create('/api/v1/forums');
		$request->headers->set('Authorization', 'Bearer bad.sig.token');
		$event = $this->makeEvent($request);

		$this->subscriber->onKernelRequest($event);

		$response = $event->getResponse();
		self::assertNotNull($response);
		self::assertSame(401, $response->getStatusCode());
	}

	#[Test]
	public function itSets_api_userAttributeFromUserRepository(): void
	{
		$payload = $this->makePayload(sub: 2, gen: 1, pv: 0);
		$user    = $this->makeUser(id: 2, tokenGeneration: 1, permVersion: 0);

		$this->tokenService->method('decodeToken')->willReturn($payload);
		$this->userRepository->method('findById')->with(2)->willReturn($user);

		$request = Request::create('/api/v1/forums');
		$request->headers->set('Authorization', 'Bearer token');
		$event = $this->makeEvent($request);

		$this->subscriber->onKernelRequest($event);

		self::assertNull($event->getResponse());
		self::assertSame($user, $request->attributes->get('_api_user'));
	}

	#[Test]
	public function itReturns401WhenUserNotFound(): void
	{
		$payload = $this->makePayload(sub: 99, gen: 1, pv: 0);

		$this->tokenService->method('decodeToken')->willReturn($payload);
		$this->userRepository->method('findById')->with(99)->willReturn(null);

		$request = Request::create('/api/v1/forums');
		$request->headers->set('Authorization', 'Bearer token');
		$event = $this->makeEvent($request);

		$this->subscriber->onKernelRequest($event);

		$response = $event->getResponse();
		self::assertNotNull($response);
		self::assertSame(401, $response->getStatusCode());

		$body = json_decode($response->getContent(), true);
		self::assertSame('User not found', $body['error']);
	}

	#[Test]
	public function itReturns401WhenTokenGenerationStale(): void
	{
		$payload = $this->makePayload(sub: 2, gen: 0, pv: 0);
		$user    = $this->makeUser(id: 2, tokenGeneration: 1, permVersion: 0);

		$this->tokenService->method('decodeToken')->willReturn($payload);
		$this->userRepository->method('findById')->willReturn($user);

		$request = Request::create('/api/v1/forums');
		$request->headers->set('Authorization', 'Bearer token');
		$event = $this->makeEvent($request);

		$this->subscriber->onKernelRequest($event);

		$response = $event->getResponse();
		self::assertNotNull($response);
		self::assertSame(401, $response->getStatusCode());

		$body = json_decode($response->getContent(), true);
		self::assertSame('Token revoked', $body['error']);
	}

	#[Test]
	public function itSets_api_token_staleWhenPermVersionMismatch(): void
	{
		$payload = $this->makePayload(sub: 2, gen: 1, pv: 0);
		$user    = $this->makeUser(id: 2, tokenGeneration: 1, permVersion: 1);

		$this->tokenService->method('decodeToken')->willReturn($payload);
		$this->userRepository->method('findById')->willReturn($user);

		$request = Request::create('/api/v1/forums');
		$request->headers->set('Authorization', 'Bearer token');
		$event = $this->makeEvent($request);

		$this->subscriber->onKernelRequest($event);

		self::assertNull($event->getResponse());
		self::assertTrue($request->attributes->get('_api_token_stale'));
		self::assertSame($user, $request->attributes->get('_api_user'));
	}

	#[Test]
	public function itDoesNotSet_api_tokenAttribute(): void
	{
		$payload = $this->makePayload(sub: 2, gen: 1, pv: 0);
		$user    = $this->makeUser(id: 2, tokenGeneration: 1, permVersion: 0);

		$this->tokenService->method('decodeToken')->willReturn($payload);
		$this->userRepository->method('findById')->willReturn($user);

		$request = Request::create('/api/v1/forums');
		$request->headers->set('Authorization', 'Bearer token');
		$event = $this->makeEvent($request);

		$this->subscriber->onKernelRequest($event);

		self::assertNull($event->getResponse());
		self::assertFalse($request->attributes->has('_api_token'));
		self::assertNotNull($request->attributes->get('_api_user'));
	}

	private function makeEvent(Request $request): RequestEvent
	{
		return new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);
	}

	private function makePayload(int $sub, int $gen, int $pv): TokenPayload
	{
		return new TokenPayload('phpbb4', $sub, 'phpbb-api', time(), time() + 900, 'jti-1', $gen, $pv, 0, '');
	}

	private function makeUser(int $id, int $tokenGeneration, int $permVersion): User
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
			tokenGeneration: $tokenGeneration,
			permVersion:     $permVersion,
		);
	}
}
