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

declare(strict_types=1);

namespace phpbb\api\Controller;

use Firebase\JWT\JWT;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AuthController
{
	#[Route('/auth/login', name: 'api_v1_auth_login', methods: ['POST'])]
	public function login(Request $request): JsonResponse
	{
		$body = json_decode($request->getContent(), true) ?? [];
		$errors = [];

		if (empty($body['username'])) {
			$errors[] = ['field' => 'username', 'message' => 'Username is required'];
		}

		if (empty($body['password'])) {
			$errors[] = ['field' => 'password', 'message' => 'Password is required'];
		}

		if (!empty($errors)) {
			return new JsonResponse(['errors' => $errors], 422);
		}

		// TODO: Replace with real AuthenticationService::login() call
		$now    = time();
		$secret = (string) (getenv('PHPBB_JWT_SECRET') ?: $_SERVER['PHPBB_JWT_SECRET'] ?? '');
		$payload = [
			'sub'   => 2,
			'gen'   => 1,
			'pv'    => 0,
			'utype' => 0,
			'flags' => 0,
			'iat'   => $now,
			'exp'   => $now + 900,
			'jti'   => bin2hex(random_bytes(8)),
		];

		return new JsonResponse([
			'data' => [
				'accessToken'  => JWT::encode($payload, $secret, 'HS256'),
				'refreshToken' => 'mock-refresh-token',
				'expiresIn'    => 900,
			],
		], 200);
	}

	#[Route('/auth/logout', name: 'api_v1_auth_logout', methods: ['POST'])]
	public function logout(): Response
	{
		// TODO: Replace with real AuthenticationService::logout() call (JTI deny-list)
		return new Response(null, 204);
	}

	#[Route('/auth/refresh', name: 'api_v1_auth_refresh', methods: ['POST'])]
	public function refresh(Request $request): JsonResponse
	{
		$body = json_decode($request->getContent(), true) ?? [];

		if (empty($body['refreshToken'])) {
			return new JsonResponse(['errors' => [['field' => 'refreshToken', 'message' => 'refreshToken is required']]], 422);
		}

		// TODO: Replace with real TokenService::refresh() call
		return new JsonResponse([
			'data' => [
				'accessToken' => 'mock.access.token.refreshed',
				'expiresIn'   => 900,
			],
		], 200);
	}
}
