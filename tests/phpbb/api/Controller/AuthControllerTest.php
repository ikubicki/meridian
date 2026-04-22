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

namespace phpbb\Tests\api\Controller;

use phpbb\api\Controller\AuthController;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class AuthControllerTest extends TestCase
{
	private AuthController $controller;

	protected function setUp(): void
	{
		$this->controller = new AuthController();
	}

	#[Test]
	public function loginReturns200WithMockTokens(): void
	{
		$request  = Request::create('/api/v1/auth/login', 'POST', [], [], [], [], json_encode(['username' => 'alice', 'password' => 'secret']));
		$response = $this->controller->login($request);

		self::assertSame(200, $response->getStatusCode());

		$body = json_decode($response->getContent(), true);
		self::assertArrayHasKey('data', $body);
		self::assertSame(900, $body['data']['expiresIn']);
		// accessToken must be a signed JWT (header.payload.signature)
		self::assertSame(3, count(explode('.', $body['data']['accessToken'])));
		self::assertArrayHasKey('refreshToken', $body['data']);
	}

	#[Test]
	public function loginReturns422WhenCredentialsMissing(): void
	{
		$request  = Request::create('/api/v1/auth/login', 'POST', [], [], [], [], json_encode([]));
		$response = $this->controller->login($request);

		self::assertSame(422, $response->getStatusCode());

		$body = json_decode($response->getContent(), true);
		self::assertArrayHasKey('errors', $body);
		self::assertNotEmpty($body['errors']);
	}

	#[Test]
	public function logoutReturns204(): void
	{
		$response = $this->controller->logout();

		self::assertSame(204, $response->getStatusCode());
	}

	#[Test]
	public function refreshReturns200WithNewAccessToken(): void
	{
		$request  = Request::create('/api/v1/auth/refresh', 'POST', [], [], [], [], json_encode(['refreshToken' => 'mock-refresh-token']));
		$response = $this->controller->refresh($request);

		self::assertSame(200, $response->getStatusCode());

		$body = json_decode($response->getContent(), true);
		self::assertArrayHasKey('data', $body);
		self::assertArrayHasKey('accessToken', $body['data']);
	}

	#[Test]
	public function refreshReturns422WhenTokenMissing(): void
	{
		$request  = Request::create('/api/v1/auth/refresh', 'POST', [], [], [], [], json_encode([]));
		$response = $this->controller->refresh($request);

		self::assertSame(422, $response->getStatusCode());
	}
}
