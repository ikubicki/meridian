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

use phpbb\messaging\Contract\MessagingServiceInterface;
use phpbb\messaging\DTO\ParticipantDTO;
use phpbb\user\Entity\User;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class ParticipantsController
{
	public function __construct(
		private readonly MessagingServiceInterface $messagingService,
		private readonly EventDispatcherInterface $dispatcher,
	) {
	}

	#[Route('/conversations/{conversationId}/participants', name: 'api_v1_participants_list', methods: ['GET'])]
	public function list(int $conversationId, Request $request): JsonResponse
	{
		/** @var User|null $user */
		$user = $request->attributes->get('_api_user');

		if ($user === null) {
			return new JsonResponse(['error' => 'Authentication required'], 401);
		}

		try {
			$participants = $this->messagingService->listParticipants($conversationId, $user->id);

			return new JsonResponse([
				'data' => array_map([$this, 'participantToArray'], $participants),
			]);
		} catch (\InvalidArgumentException) {
			return new JsonResponse(['error' => 'Forbidden'], 403);
		} catch (\Throwable) {
			return new JsonResponse(['error' => 'Internal server error'], 500);
		}
	}

	#[Route('/conversations/{conversationId}/participants', name: 'api_v1_participants_add', methods: ['POST'])]
	public function add(int $conversationId, Request $request): JsonResponse
	{
		/** @var User|null $user */
		$user = $request->attributes->get('_api_user');

		if ($user === null) {
			return new JsonResponse(['error' => 'Authentication required'], 401);
		}

		try {
			$body = json_decode($request->getContent(), true) ?? [];
			$newUserId = (int) ($body['userId'] ?? 0);

			if ($newUserId <= 0) {
				return new JsonResponse(['error' => 'userId is required'], 400);
			}

			$events = $this->messagingService->addParticipant($conversationId, $newUserId, $user->id);
			$events->dispatch($this->dispatcher);

			// Return updated participant list
			$participants = $this->messagingService->listParticipants($conversationId, $user->id);

			return new JsonResponse([
				'data' => array_map([$this, 'participantToArray'], $participants),
			], 201);
		} catch (\InvalidArgumentException $e) {
			if (str_contains($e->getMessage(), 'owner')) {
				return new JsonResponse(['error' => 'Only owner can add participants'], 403);
			}

			return new JsonResponse(['error' => 'Invalid request'], 400);
		} catch (\Throwable) {
			return new JsonResponse(['error' => 'Internal server error'], 500);
		}
	}

	#[Route('/conversations/{conversationId}/participants/{targetUserId}', name: 'api_v1_participants_update', methods: ['PATCH'])]
	public function update(int $conversationId, int $targetUserId, Request $request): JsonResponse
	{
		/** @var User|null $user */
		$user = $request->attributes->get('_api_user');

		if ($user === null) {
			return new JsonResponse(['error' => 'Authentication required'], 401);
		}

		try {
			$body = json_decode($request->getContent(), true) ?? [];
			$role = (string) ($body['role'] ?? '');

			if ($role === '') {
				return new JsonResponse(['error' => 'role is required'], 400);
			}

			$events = $this->messagingService->updateParticipantRole($conversationId, $targetUserId, $role, $user->id);
			$events->dispatch($this->dispatcher);

			return new JsonResponse(status: 204);
		} catch (\InvalidArgumentException $e) {
			if (str_contains($e->getMessage(), 'owner')) {
				return new JsonResponse(['error' => 'Only owner can update roles'], 403);
			}
			if (str_contains($e->getMessage(), 'Invalid role')) {
				return new JsonResponse(['error' => $e->getMessage()], 422);
			}

			return new JsonResponse(['error' => 'Invalid request'], 400);
		} catch (\Throwable) {
			return new JsonResponse(['error' => 'Internal server error'], 500);
		}
	}

	private function participantToArray(ParticipantDTO $dto): array
	{
		return [
			'userId' => $dto->userId,
			'role' => $dto->role,
			'state' => $dto->state,
			'joinedAt' => $dto->joinedAt,
			'lastReadMessageId' => $dto->lastReadMessageId,
			'lastReadAt' => $dto->lastReadAt,
			'isMuted' => $dto->isMuted,
		];
	}
}
