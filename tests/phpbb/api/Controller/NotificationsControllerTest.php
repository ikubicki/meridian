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

use phpbb\api\Controller\NotificationsController;
use phpbb\common\Event\DomainEventCollection;
use phpbb\notifications\Contract\NotificationServiceInterface;
use phpbb\user\DTO\PaginatedResult;
use phpbb\user\Entity\User;
use phpbb\user\Enum\UserType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;

class NotificationsControllerTest extends TestCase
{
	private NotificationServiceInterface&MockObject $service;
	private EventDispatcherInterface&MockObject $dispatcher;
	private NotificationsController $controller;

	protected function setUp(): void
	{
		$this->service    = $this->createMock(NotificationServiceInterface::class);
		$this->dispatcher = $this->createMock(EventDispatcherInterface::class);

		$this->controller = new NotificationsController(
			$this->service,
			$this->dispatcher,
		);
	}

	private function makeUser(int $id = 2): User
	{
		return new User(
			id:               $id,
			type:             UserType::Normal,
			username:         'testuser',
			usernameClean:    'testuser',
			email:            '',
			passwordHash:     '',
			colour:           '',
			defaultGroupId:   1,
			avatarUrl:        '',
			registeredAt:     new \DateTimeImmutable('2020-01-01'),
			lastmark:         new \DateTimeImmutable('2020-01-01'),
			posts:            0,
			lastPostTime:     null,
			isNew:            false,
			rank:             0,
			registrationIp:   '127.0.0.1',
			loginAttempts:    0,
			inactiveReason:   null,
			formSalt:         '',
			activationKey:    '',
		);
	}

	#[Test]
	public function countReturns401WhenNoUser(): void
	{
		$request = new Request();

		$response = $this->controller->count($request);

		$this->assertSame(401, $response->getStatusCode());
	}

	#[Test]
	public function countReturns200WithUnreadCount(): void
	{
		$user    = $this->makeUser();
		$request = new Request();
		$request->attributes->set('_api_user', $user);

		$this->service->method('getLastModified')->willReturn(null);
		$this->service->method('getUnreadCount')->willReturn(3);

		$response = $this->controller->count($request);

		$this->assertSame(200, $response->getStatusCode());
		$this->assertSame('30', $response->headers->get('X-Poll-Interval'));

		$body = json_decode($response->getContent(), true);
		$this->assertSame(3, $body['data']['unread']);
	}

	#[Test]
	public function markReadReturns204OnSuccess(): void
	{
		$user    = $this->makeUser();
		$request = new Request();
		$request->attributes->set('_api_user', $user);

		$events = new DomainEventCollection([]);

		$this->service->method('markRead')->willReturn($events);
		$this->dispatcher->expects($this->never())->method('dispatch');

		$response = $this->controller->markRead(1, $request);

		$this->assertSame(204, $response->getStatusCode());
	}

	#[Test]
	public function markReadReturns404WhenNotFound(): void
	{
		$user    = $this->makeUser();
		$request = new Request();
		$request->attributes->set('_api_user', $user);

		$this->service->method('markRead')
			->willThrowException(new \InvalidArgumentException('Notification not found'));

		$response = $this->controller->markRead(999, $request);

		$this->assertSame(404, $response->getStatusCode());
	}

	#[Test]
	public function indexReturns401WhenNoUser(): void
	{
		// Arrange
		$request = new Request();

		// Act
		$response = $this->controller->index($request);

		// Assert
		$this->assertSame(401, $response->getStatusCode());
	}

	#[Test]
	public function indexReturnsEmptyDataAndMeta(): void
	{
		// Arrange
		$user    = $this->makeUser();
		$request = new Request();
		$request->attributes->set('_api_user', $user);

		$paginatedResult = new PaginatedResult(items: [], total: 0, page: 1, perPage: 25);
		$this->service->method('getNotifications')->willReturn($paginatedResult);

		// Act
		$response = $this->controller->index($request);

		// Assert
		$this->assertSame(200, $response->getStatusCode());
		$body = json_decode($response->getContent(), true);
		$this->assertSame([], $body['data']);
		$this->assertSame(0, $body['meta']['total']);
		$this->assertSame(1, $body['meta']['page']);
	}
}
