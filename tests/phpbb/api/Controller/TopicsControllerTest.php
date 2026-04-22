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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class TopicsControllerTest extends TestCase
{
	private TopicsController $controller;

	protected function setUp(): void
	{
		$this->controller = new TopicsController();
	}

	#[Test]
	public function indexByForumReturnsDataEnvelopeWithMeta(): void
	{
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
		$request  = Request::create('/api/v1/forums/999/topics');
		$response = $this->controller->indexByForum(999, $request);

		self::assertSame(200, $response->getStatusCode());

		$body = json_decode($response->getContent(), true);
		self::assertSame([], $body['data']);
		self::assertSame(0, $body['meta']['total']);
	}

	#[Test]
	public function showReturnsTopicDataForExistingId(): void
	{
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
		$request  = Request::create('/api/v1/topics/999');
		$response = $this->controller->show(999, $request);

		self::assertSame(404, $response->getStatusCode());

		$body = json_decode($response->getContent(), true);
		self::assertSame(404, $body['status']);
	}

	#[Test]
	public function showReturns401ForLoginRequiredTopicWithoutToken(): void
	{
		$request  = Request::create('/api/v1/topics/3');
		$response = $this->controller->show(3, $request);

		self::assertSame(401, $response->getStatusCode());

		$body = json_decode($response->getContent(), true);
		self::assertSame(401, $body['status']);
	}

	#[Test]
	public function showReturns401ForPasswordRequiredTopicWithoutToken(): void
	{
		$request  = Request::create('/api/v1/topics/4');
		$response = $this->controller->show(4, $request);

		self::assertSame(401, $response->getStatusCode());

		$body = json_decode($response->getContent(), true);
		self::assertSame(401, $body['status']);
	}
}
