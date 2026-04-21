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

namespace phpbb\api\event;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use UnexpectedValueException;

/**
 * JWT authentication middleware for the REST API.
 *
 * Guards all API paths (/api/, /adm/api/, /install/api/) and verifies the
 * Bearer token on every request except public endpoints (health, auth/login).
 *
 * On success the decoded token claims are stored as a request attribute
 * "_api_token" so controllers can read user_id, username, admin etc.
 */
class auth_subscriber implements EventSubscriberInterface
{
	/** @var string Must match the secret used in auth controller */
	private $jwt_secret = 'phpbb-api-secret-change-in-production';

	/**
	 * @param GetResponseEvent $event
	 * @return void
	 */
	public function on_kernel_request(GetResponseEvent $event)
	{
		if (!$event->isMasterRequest())
		{
			return;
		}

		$request = $event->getRequest();
		$path    = $request->getPathInfo();

		// Only intercept API paths — let standard phpBB routes pass through untouched
		if (strpos($path, '/api/') !== 0 && strpos($path, '/adm/api/') !== 0 && strpos($path, '/install/api/') !== 0)
		{
			return;
		}

		// Public endpoints — no token required
		if (substr($path, -strlen('/health')) === '/health' || substr($path, -strlen('/auth/login')) === '/auth/login' || substr($path, -strlen('/auth/signup')) === '/auth/signup')
		{
			return;
		}

		// Extract Bearer token from Authorization header
		$auth_header = $request->headers->get('Authorization', '');
		if (strpos($auth_header, 'Bearer ') !== 0)
		{
			$event->setResponse(new JsonResponse([
				'error'  => 'Missing or malformed Authorization header',
				'status' => 401,
			], 401));
			return;
		}

		$raw_token = substr($auth_header, strlen('Bearer '));

		try
		{
			$claims = JWT::decode($raw_token, new Key($this->jwt_secret, 'HS256'));
			// Store decoded claims on the request for controllers to consume
			$request->attributes->set('_api_token', $claims);
		}
		catch (ExpiredException $e)
		{
			$event->setResponse(new JsonResponse(['error' => 'Token expired', 'status' => 401], 401));
		}
		catch (SignatureInvalidException $e)
		{
			$event->setResponse(new JsonResponse(['error' => 'Invalid token signature', 'status' => 401], 401));
		}
		catch (UnexpectedValueException $e)
		{
			$event->setResponse(new JsonResponse(['error' => 'Invalid token', 'status' => 401], 401));
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public static function getSubscribedEvents()
	{
		return [
			KernelEvents::REQUEST => 'on_kernel_request',
		];
	}
}
