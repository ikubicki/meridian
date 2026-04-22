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

use phpbb\api\EventSubscriber\AuthorizationSubscriber;
use phpbb\auth\Contract\AuthorizationServiceInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class AuthorizationSubscriberTest extends TestCase
{
	#[Test]
	public function itExitsEarlyWhenNoPermissionRouteAttribute(): void
	{
		$authService = $this->createMock(AuthorizationServiceInterface::class);
		$authService->expects(self::never())->method('isGranted');

		$subscriber = new AuthorizationSubscriber($authService);
		$kernel     = $this->createMock(HttpKernelInterface::class);

		$request = Request::create('/api/v1/forums');
		// No _api_permission attribute set

		$event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
		$subscriber->onKernelRequest($event);

		self::assertNull($event->getResponse()); // No 403 returned
	}

	#[Test]
	public function itReturns403WhenPermissionRequiredButNotGranted(): void
	{
		$authService = $this->createMock(AuthorizationServiceInterface::class);
		$authService->method('isGranted')->willReturn(false);

		$subscriber = new AuthorizationSubscriber($authService);
		$kernel     = $this->createMock(HttpKernelInterface::class);

		$request = Request::create('/api/v1/admin/panel');
		$request->attributes->set('_api_permission', 'admin');
		$request->attributes->set('_api_user', null); // null user means no grant

		$event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
		$subscriber->onKernelRequest($event);

		self::assertNotNull($event->getResponse());
		self::assertSame(403, $event->getResponse()->getStatusCode());
	}
}
