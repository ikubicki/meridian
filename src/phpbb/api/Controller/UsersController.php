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

use phpbb\user\DTO\UserSearchCriteria;
use phpbb\user\Service\UserSearchService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class UsersController
{
	public function __construct(
		private readonly UserSearchService $userSearchService,
	) {
	}

	#[Route('/me', name: 'api_v1_me_show', methods: ['GET'])]
	public function me(Request $request): JsonResponse
	{
		$token  = $request->attributes->get('_api_token');
		$userId = (int) ($token?->sub ?? 0);

		if ($userId <= 0)
		{
			return new JsonResponse(['error' => 'Unauthorised', 'status' => 401], 401);
		}

		$user = $this->userSearchService->findById($userId);

		if ($user === null)
		{
			return new JsonResponse(['error' => 'User not found', 'status' => 404], 404);
		}

		return new JsonResponse(['data' => [
			'id'           => $user->id,
			'username'     => $user->username,
			'email'        => $user->email,
			'type'         => $user->type->value,
			'colour'       => $user->colour,
			'avatarUrl'    => $user->avatarUrl,
			'posts'        => $user->posts,
			'registeredAt' => $user->registeredAt->format(\DateTimeInterface::ATOM),
		]]);
	}

	#[Route('/users', name: 'api_v1_users_index', methods: ['GET'])]
	public function index(Request $request): JsonResponse
	{
		$criteria  = UserSearchCriteria::fromQuery($request->query);
		$paginated = $this->userSearchService->search($criteria);

		$items = array_map(static fn ($user) => [
			'id'       => $user->id,
			'username' => $user->username,
			'colour'   => $user->colour,
			'posts'    => $user->posts,
		], $paginated->items);

		return new JsonResponse([
			'data' => $items,
			'meta' => [
				'total'      => $paginated->total,
				'page'       => $paginated->page,
				'perPage'    => $paginated->perPage,
				'totalPages' => $paginated->totalPages(),
			],
		]);
	}

	#[Route('/users/{userId}', name: 'api_v1_users_show', methods: ['GET'])]
	public function show(int $userId): JsonResponse
	{
		$user = $this->userSearchService->findById($userId);

		if ($user === null)
		{
			return new JsonResponse(['error' => 'User not found', 'status' => 404], 404);
		}

		return new JsonResponse(['data' => [
			'id'           => $user->id,
			'username'     => $user->username,
			'type'         => $user->type->value,
			'colour'       => $user->colour,
			'avatarUrl'    => $user->avatarUrl,
			'posts'        => $user->posts,
			'registeredAt' => $user->registeredAt->format(\DateTimeInterface::ATOM),
		]]);
	}
}
