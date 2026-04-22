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

use phpbb\api\Controller\UsersController;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class UsersControllerTest extends TestCase
{
	private UsersController $controller;

	protected function setUp(): void
	{
		$this->controller = new UsersController();
	}

	#[Test]
	public function meReturnsCurrentUserDataEnvelope(): void
	{
		$request  = Request::create('/api/v1/me');
		$response = $this->controller->me($request);

		self::assertSame(200, $response->getStatusCode());

		$body = json_decode($response->getContent(), true);
		self::assertArrayHasKey('data', $body);
		self::assertArrayHasKey('id', $body['data']);
		self::assertArrayHasKey('username', $body['data']);
		self::assertArrayHasKey('email', $body['data']);
	}

	#[Test]
	public function meUsesUserIdFromJwtTokenAttribute(): void
	{
		$token     = new \stdClass();
		$token->sub = 1;

		$request = Request::create('/api/v1/me');
		$request->attributes->set('_api_token', $token);

		$response = $this->controller->me($request);

		self::assertSame(200, $response->getStatusCode());

		$body = json_decode($response->getContent(), true);
		self::assertSame(1, $body['data']['id']);
	}

	#[Test]
	public function showReturnsPublicProfileWithoutEmail(): void
	{
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
		$response = $this->controller->show(999);

		self::assertSame(404, $response->getStatusCode());

		$body = json_decode($response->getContent(), true);
		self::assertSame(404, $body['status']);
	}
}
