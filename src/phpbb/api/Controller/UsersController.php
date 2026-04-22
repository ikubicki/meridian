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

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class UsersController
{
	/** @var array<int, array<string, mixed>> */
	private const MOCK_USERS = [
		1 => [
			'id'           => 1,
			'username'     => 'admin',
			'email'        => 'admin@example.com',
			'utype'        => 3,
			'registeredAt' => '2026-01-01T00:00:00Z',
		],
		2 => [
			'id'           => 2,
			'username'     => 'alice',
			'email'        => 'alice@example.com',
			'utype'        => 0,
			'registeredAt' => '2026-02-15T10:00:00Z',
		],
	];

	#[Route('/me', name: 'api_v1_me_show', methods: ['GET'])]
	public function me(Request $request): JsonResponse
	{
		// TODO: Replace with real UserService::getUser() using $token->sub
		$token  = $request->attributes->get('_api_token');
		$userId = $token?->sub ?? 2;

		$user = self::MOCK_USERS[(int) $userId] ?? self::MOCK_USERS[2];

		return new JsonResponse(['data' => $user]);
	}

	#[Route('/users/{userId}', name: 'api_v1_users_show', methods: ['GET'])]
	public function show(int $userId): JsonResponse
	{
		// TODO: Replace with real UserService::getPublicProfile()
		if (!isset(self::MOCK_USERS[$userId])) {
			return new JsonResponse(['error' => 'User not found', 'status' => 404], 404);
		}

		$user = self::MOCK_USERS[$userId];

		// Public profile: omit email for non-admin display
		unset($user['email']);

		return new JsonResponse(['data' => $user]);
	}
}
