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

namespace phpbb\api\v1\controller;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Auth controller for the Forum REST API.
 *
 * Phase 1: mock implementation — accepts only admin/admin and returns
 * a signed JWT. In Phase 2 this will query phpbb_users and phpbb_api_tokens.
 *
 * JWT is signed with HMAC-SHA256. The secret key should be moved to
 * a config parameter before going to production.
 */
class auth
{
	/** @var string JWT signing secret — replace with a config parameter in Phase 2 */
	private $jwt_secret = 'phpbb-api-secret-change-in-production';

	/** @var int Token lifetime in seconds */
	private $ttl = 3600;

	/**
	 * POST /api/v1/auth/login
	 *
	 * Accepts JSON body: {"login": "...", "password": "..."}
	 * Returns:           {"token": "<jwt>", "expires_in": 3600}
	 *
	 * @param Request $request
	 * @return JsonResponse
	 */
	public function login(Request $request)
	{
		$body = json_decode($request->getContent(), true);

		$login    = isset($body['login'])    ? (string) $body['login']    : '';
		$password = isset($body['password']) ? (string) $body['password'] : '';

		// Phase 1 mock — only admin/admin is accepted
		if ($login !== 'admin' || $password !== 'admin')
		{
			return new JsonResponse([
				'error'  => 'Invalid credentials',
				'status' => 401,
			], 401);
		}

		$now = time();
		$payload = [
			'iss'      => 'phpBB',
			'iat'      => $now,
			'exp'      => $now + $this->ttl,
			'user_id'  => 2,
			'username' => 'admin',
			'admin'    => true,
		];

		$token = JWT::encode($payload, $this->jwt_secret, 'HS256');

		return new JsonResponse([
			'token'      => $token,
			'expires_in' => $this->ttl,
		]);
	}
}

