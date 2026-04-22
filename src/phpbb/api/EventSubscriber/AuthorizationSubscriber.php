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

namespace phpbb\api\EventSubscriber;

use phpbb\auth\Contract\AuthorizationServiceInterface;
use phpbb\user\Entity\User;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class AuthorizationSubscriber implements EventSubscriberInterface
{
	public function __construct(
		private readonly AuthorizationServiceInterface $authorizationService,
	) {
	}

	public static function getSubscribedEvents(): array
	{
		return [
			KernelEvents::REQUEST => ['onKernelRequest', 8],
		];
	}

	public function onKernelRequest(RequestEvent $event): void
	{
		if (!$event->isMainRequest()) {
			return;
		}

		$request    = $event->getRequest();
		$permission = $request->attributes->get('_api_permission');

		if ($permission === null) {
			return;
		}

		/** @var User|null $user */
		$user = $request->attributes->get('_api_user');

		if ($user === null || !$this->authorizationService->isGranted($user, $permission)) {
			$event->setResponse(new JsonResponse(['error' => 'Forbidden', 'status' => 403], 403));
		}
	}
}
