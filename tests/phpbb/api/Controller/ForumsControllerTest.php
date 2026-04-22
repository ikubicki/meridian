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

use phpbb\api\Controller\ForumsController;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ForumsControllerTest extends TestCase
{
	private ForumsController $controller;

	protected function setUp(): void
	{
		$this->controller = new ForumsController();
	}

	#[Test]
	public function indexReturnsDataEnvelopeWithMeta(): void
	{
		$response = $this->controller->index();

		self::assertSame(200, $response->getStatusCode());

		$body = json_decode($response->getContent(), true);
		self::assertArrayHasKey('data', $body);
		self::assertArrayHasKey('meta', $body);
		self::assertArrayHasKey('total', $body['meta']);
		self::assertIsArray($body['data']);
		self::assertNotEmpty($body['data']);
	}

	#[Test]
	public function showReturnsForumDataForExistingId(): void
	{
		$response = $this->controller->show(1);

		self::assertSame(200, $response->getStatusCode());

		$body = json_decode($response->getContent(), true);
		self::assertArrayHasKey('data', $body);
		self::assertSame(1, $body['data']['id']);
		self::assertArrayHasKey('title', $body['data']);
	}

	#[Test]
	public function showReturns404ForNonExistingForum(): void
	{
		$response = $this->controller->show(999);

		self::assertSame(404, $response->getStatusCode());

		$body = json_decode($response->getContent(), true);
		self::assertArrayHasKey('error', $body);
		self::assertSame(404, $body['status']);
	}
}
