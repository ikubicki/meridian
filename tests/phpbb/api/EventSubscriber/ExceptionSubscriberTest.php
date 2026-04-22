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

use phpbb\api\EventSubscriber\ExceptionSubscriber;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class ExceptionSubscriberTest extends TestCase
{
	private ExceptionSubscriber $subscriber;
	private HttpKernelInterface $kernel;

	protected function setUp(): void
	{
		$this->subscriber = new ExceptionSubscriber();
		$this->kernel     = $this->createMock(HttpKernelInterface::class);
	}

	#[Test]
	public function itReturnsJson404ForHttpNotFoundOnApiPath(): void
	{
		$request = Request::create('/api/v1/forums/999');
		$event   = new ExceptionEvent(
			$this->kernel,
			$request,
			HttpKernelInterface::MAIN_REQUEST,
			new NotFoundHttpException('Forum not found'),
		);

		$this->subscriber->onKernelException($event);

		$response = $event->getResponse();
		self::assertNotNull($response);
		self::assertSame(404, $response->getStatusCode());

		$body = json_decode($response->getContent(), true);
		self::assertSame('Forum not found', $body['error']);
		self::assertSame(404, $body['status']);
	}

	#[Test]
	public function itIgnoresNonApiPaths(): void
	{
		$request = Request::create('/viewtopic.php');
		$event   = new ExceptionEvent(
			$this->kernel,
			$request,
			HttpKernelInterface::MAIN_REQUEST,
			new \RuntimeException('Something broke'),
		);

		$this->subscriber->onKernelException($event);

		self::assertNull($event->getResponse());
	}

	#[Test]
	public function itReturns500WithSafeMessageForGenericException(): void
	{
		$request = Request::create('/api/v1/forums');
		$event   = new ExceptionEvent(
			$this->kernel,
			$request,
			HttpKernelInterface::MAIN_REQUEST,
			new \RuntimeException('PDO connection failed with credentials: root/secret'),
		);

		$this->subscriber->onKernelException($event);

		$response = $event->getResponse();
		self::assertNotNull($response);
		self::assertSame(500, $response->getStatusCode());

		$body = json_decode($response->getContent(), true);
		self::assertSame('An unexpected error occurred.', $body['error']);
		self::assertStringNotContainsString('secret', $body['error']);
	}

	#[Test]
	public function itStopsPropagationAfterHandling(): void
	{
		$request = Request::create('/api/v1/topics/1');
		$event   = new ExceptionEvent(
			$this->kernel,
			$request,
			HttpKernelInterface::MAIN_REQUEST,
			new NotFoundHttpException(),
		);

		$this->subscriber->onKernelException($event);

		self::assertTrue($event->isPropagationStopped());
	}
}
