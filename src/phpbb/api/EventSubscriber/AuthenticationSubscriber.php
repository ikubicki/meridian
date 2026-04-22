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

use phpbb\auth\Contract\TokenServiceInterface;
use phpbb\user\Contract\UserRepositoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * JWT Bearer token authentication middleware for the REST API.
 *
 * Priority 16 — fires before routing resolution so that unauthenticated requests
 * are rejected before any controller is invoked.
 *
 * On successful validation, the resolved User entity is stored as the request
 * attribute "_api_user". Controllers read identity via:
 *     $request->attributes->get('_api_user')
 *
 * Public endpoints (no token required):
 *   - GET  /api/v1/health
 *   - POST /api/v1/auth/login
 *   - POST /api/v1/auth/signup
 *   - POST /api/v1/auth/refresh
 */
class AuthenticationSubscriber implements EventSubscriberInterface
{
	/** @var string[] Public path suffixes that bypass JWT validation */
	private const PUBLIC_SUFFIXES = [
		'/health',
		'/auth/login',
		'/auth/signup',
		'/auth/refresh',
	];



	public function __construct(
		private readonly TokenServiceInterface $tokenService,
		private readonly UserRepositoryInterface $userRepository,
	) {
	}

	public static function getSubscribedEvents(): array
	{
		return [
			KernelEvents::REQUEST => ['onKernelRequest', 16],
		];
	}

	public function onKernelRequest(RequestEvent $event): void
	{
		if (!$event->isMainRequest()) {
			return;
		}

		$request = $event->getRequest();
		$path    = $request->getPathInfo();

		if (!str_starts_with($path, '/api/')) {
			return;
		}

		foreach (self::PUBLIC_SUFFIXES as $suffix) {
			if (str_ends_with($path, $suffix)) {
				return;
			}
		}

		$authHeader = $request->headers->get('Authorization', '');

		// Routes that declare _allow_anonymous => true permit unauthenticated access.
		// A provided token is still validated and sets _api_user; no token = skip.
		if ($request->attributes->get('_allow_anonymous') === true) {
			if (!str_starts_with($authHeader, 'Bearer ')) {
				return;
			}
		}

		if (!str_starts_with($authHeader, 'Bearer ')) {
			$event->setResponse(new JsonResponse(
				['error' => 'Missing or malformed Authorization header', 'status' => 401],
				401,
			));

			return;
		}

		$rawToken = substr($authHeader, strlen('Bearer '));

		try {
			$payload  = $this->tokenService->decodeToken($rawToken, 'phpbb-api');
			$elevated = false;
		} catch (\UnexpectedValueException) {
			// Try elevated token — forum admin operations send phpbb-admin audience
			try {
				$payload  = $this->tokenService->decodeToken($rawToken, 'phpbb-admin');
				$elevated = true;
			} catch (\UnexpectedValueException) {
				$event->setResponse(new JsonResponse(['error' => 'Invalid token', 'status' => 401], 401));

				return;
			}
		}

		$user = $this->userRepository->findById($payload->sub);

		if ($user === null) {
			$event->setResponse(new JsonResponse(['error' => 'User not found', 'status' => 401], 401));

			return;
		}

		if ($payload->gen < $user->tokenGeneration) {
			$event->setResponse(new JsonResponse(['error' => 'Token revoked', 'status' => 401], 401));

			return;
		}

		if ($payload->pv !== $user->permVersion) {
			$request->attributes->set('_api_token_stale', true);
		}

		$request->attributes->set('_api_user', $user);
		$request->attributes->set('_api_elevated', $elevated);
	}
}
