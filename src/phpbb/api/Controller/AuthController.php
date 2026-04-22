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

namespace phpbb\api\Controller;

use phpbb\auth\Contract\AuthenticationServiceInterface;
use phpbb\auth\Exception\AuthenticationFailedException;
use phpbb\auth\Exception\InvalidRefreshTokenException;
use phpbb\user\Entity\User;
use phpbb\user\Exception\BannedException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AuthController
{
	public function __construct(
		private readonly AuthenticationServiceInterface $authService,
	) {
	}

	#[Route('/auth/login', name: 'api_v1_auth_login', methods: ['POST'])]
	public function login(Request $request): JsonResponse
	{
		$body   = json_decode($request->getContent(), true) ?? [];
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

		try {
			$tokens = $this->authService->login(
				$body['username'],
				$body['password'],
				$request->getClientIp() ?? '0.0.0.0',
			);
		} catch (AuthenticationFailedException $e) {
			return new JsonResponse(['error' => $e->getMessage(), 'status' => 401], 401);
		} catch (BannedException $e) {
			return new JsonResponse(['error' => $e->getMessage(), 'status' => 403], 403);
		}

		return new JsonResponse(['data' => $tokens], 200);
	}

	#[Route('/auth/logout', name: 'api_v1_auth_logout', methods: ['POST'])]
	public function logout(Request $request): Response
	{
		/** @var User $user */
		$user = $request->attributes->get('_api_user');

		$this->authService->logout($user->id);

		return new Response(null, 204);
	}

	#[Route('/auth/refresh', name: 'api_v1_auth_refresh', methods: ['POST'])]
	public function refresh(Request $request): JsonResponse
	{
		$body = json_decode($request->getContent(), true) ?? [];

		if (empty($body['refreshToken'])) {
			return new JsonResponse(['errors' => [['field' => 'refreshToken', 'message' => 'refreshToken is required']]], 422);
		}

		try {
			$tokens = $this->authService->refresh($body['refreshToken']);
		} catch (InvalidRefreshTokenException $e) {
			return new JsonResponse(['error' => $e->getMessage(), 'status' => 401], 401);
		}

		return new JsonResponse(['data' => $tokens], 200);
	}

	#[Route('/auth/elevate', name: 'api_v1_auth_elevate', methods: ['POST'])]
	public function elevate(Request $request): JsonResponse
	{
		$body = json_decode($request->getContent(), true) ?? [];

		if (empty($body['password'])) {
			return new JsonResponse(['errors' => [['field' => 'password', 'message' => 'Password is required']]], 422);
		}

		/** @var User $user */
		$user = $request->attributes->get('_api_user');

		try {
			$tokens = $this->authService->elevate($user->id, $body['password']);
		} catch (AuthenticationFailedException $e) {
			return new JsonResponse(['error' => $e->getMessage(), 'status' => 401], 401);
		}

		return new JsonResponse(['data' => $tokens], 200);
	}
}
