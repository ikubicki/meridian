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

use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use UnexpectedValueException;

/**
 * JWT Bearer token authentication middleware for the REST API.
 *
 * Priority 16 — fires before routing resolution so that unauthenticated requests
 * are rejected before any controller is invoked.
 *
 * On successful validation, decoded JWT claims are stored as the request attribute
 * "_api_token" (stdClass). Controllers read identity via:
 *     $request->attributes->get('_api_token')->sub
 *
 * Public endpoints (no token required):
 *   - GET  /api/v1/health
 *   - POST /api/v1/auth/login
 *   - POST /api/v1/auth/signup
 *   - POST /api/v1/auth/refresh
 *
 * Security note: gen/pv counter validation against DB is deferred to the Auth
 * service implementation. The TODO below marks this gap explicitly.
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

	/**
	 * @var string[] Path prefixes where auth is optional: no token = allowed,
	 *               valid token = sets _api_token, invalid token = 401.
	 *               Controllers are responsible for per-resource access checks.
	 */
	private const OPTIONAL_AUTH_PREFIXES = [
		'/api/v1/topics/',
	];

	public function __construct(
		private readonly string $jwtSecret = '',
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

		// Optional-auth paths: no token is fine, but a provided token must be valid
		foreach (self::OPTIONAL_AUTH_PREFIXES as $prefix) {
			if (str_starts_with($path, $prefix)) {
				if (!str_starts_with($authHeader, 'Bearer ')) {
					return; // unauthenticated access allowed — controller decides
				}
				break; // has a token: fall through to normal validation below
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
		$secret   = $this->resolveSecret();

		try {
			$claims = JWT::decode($rawToken, new Key($secret, 'HS256'));

			// TODO: verify $claims->gen against phpbb_users.token_generation (requires DB)
			// TODO: verify $claims->pv against phpbb_users.perm_version (requires DB)

			$request->attributes->set('_api_token', $claims);
		} catch (ExpiredException) {
			$event->setResponse(new JsonResponse(['error' => 'Token expired', 'status' => 401], 401));
		} catch (SignatureInvalidException) {
			$event->setResponse(new JsonResponse(['error' => 'Invalid token signature', 'status' => 401], 401));
		} catch (UnexpectedValueException) {
			$event->setResponse(new JsonResponse(['error' => 'Invalid token', 'status' => 401], 401));
		}
	}

	private function resolveSecret(): string
	{
		$secret = $this->jwtSecret !== '' ? $this->jwtSecret : (string) (getenv('PHPBB_JWT_SECRET') ?: $_SERVER['PHPBB_JWT_SECRET'] ?? '');

		if ($secret === '') {
			throw new \RuntimeException('PHPBB_JWT_SECRET environment variable is not set.');
		}

		return $secret;
	}
}
