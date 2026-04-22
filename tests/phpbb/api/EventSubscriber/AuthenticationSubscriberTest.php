<?php

/**
 *
 * This file is part of the phpBB Forum Software package.
 *
 * @copyright (c) phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 * For full copyright and license information, please see
 * the docs/CREDITS.txt file.
 *
 */

declare(strict_types=1);

namespace phpbb\Tests\api\EventSubscriber;

use Firebase\JWT\JWT;
use phpbb\api\EventSubscriber\AuthenticationSubscriber;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class AuthenticationSubscriberTest extends TestCase
{
	private const JWT_SECRET = 'test-secret-minimum-32-chars-ok!';

	private AuthenticationSubscriber $subscriber;
	private HttpKernelInterface $kernel;

	protected function setUp(): void
	{
		$this->subscriber = new AuthenticationSubscriber(self::JWT_SECRET);
		$this->kernel     = $this->createMock(HttpKernelInterface::class);
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
	public function itSetsApiTokenAttributeForValidJwt(): void
	{
		$payload = ['sub' => 2, 'username' => 'alice', 'gen' => 1, 'pv' => 0, 'utype' => 0, 'iat' => time(), 'exp' => time() + 900];
		$token   = JWT::encode($payload, self::JWT_SECRET, 'HS256');

		$request = Request::create('/api/v1/forums');
		$request->headers->set('Authorization', 'Bearer ' . $token);
		$event = $this->makeEvent($request);

		$this->subscriber->onKernelRequest($event);

		self::assertNull($event->getResponse());
		$claims = $request->attributes->get('_api_token');
		self::assertNotNull($claims);
		self::assertSame(2, $claims->sub);
	}

	#[Test]
	public function itReturns401ForExpiredToken(): void
	{
		$payload = ['sub' => 2, 'iat' => time() - 1000, 'exp' => time() - 100];
		$token   = JWT::encode($payload, self::JWT_SECRET, 'HS256');

		$request = Request::create('/api/v1/forums');
		$request->headers->set('Authorization', 'Bearer ' . $token);
		$event = $this->makeEvent($request);

		$this->subscriber->onKernelRequest($event);

		$response = $event->getResponse();
		self::assertNotNull($response);
		self::assertSame(401, $response->getStatusCode());

		$body = json_decode($response->getContent(), true);
		self::assertSame('Token expired', $body['error']);
	}

	#[Test]
	public function itReturns401ForInvalidSignature(): void
	{
		$payload  = ['sub' => 2, 'iat' => time(), 'exp' => time() + 900];
		$token    = JWT::encode($payload, 'wrong-secret-minimum-32-characters-x', 'HS256');

		$request = Request::create('/api/v1/forums');
		$request->headers->set('Authorization', 'Bearer ' . $token);
		$event = $this->makeEvent($request);

		$this->subscriber->onKernelRequest($event);

		$response = $event->getResponse();
		self::assertNotNull($response);
		self::assertSame(401, $response->getStatusCode());
	}

	private function makeEvent(Request $request): RequestEvent
	{
		return new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);
	}
}
