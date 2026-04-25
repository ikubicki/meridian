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

use phpbb\api\DTO\PaginationContext;
use phpbb\messaging\Contract\MessagingServiceInterface;
use phpbb\messaging\DTO\ConversationDTO;
use phpbb\messaging\DTO\Request\CreateConversationRequest;
use phpbb\user\Entity\User;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class ConversationsController
{
	public function __construct(
		private readonly MessagingServiceInterface $messagingService,
		private readonly EventDispatcherInterface $dispatcher,
	) {
	}

	#[Route('/conversations', name: 'api_v1_conversations_list', methods: ['GET'])]
	public function list(Request $request): JsonResponse
	{
		/** @var User|null $user */
		$user = $request->attributes->get('_api_user');

		if ($user === null) {
			return new JsonResponse(['error' => 'Authentication required'], 401);
		}

		try {
			$state = $request->query->get('state');
			$ctx = PaginationContext::fromQuery($request->query);
			$result = $this->messagingService->listConversations($user->id, $state, $ctx);

			return new JsonResponse([
				'data' => array_map([$this, 'conversationToArray'], $result->items),
				'meta' => [
					'total' => $result->total,
					'page' => $result->page,
					'perPage' => $result->perPage,
					'lastPage' => max(1, $result->totalPages()),
				],
			]);
		} catch (\Throwable $e) {
			return new JsonResponse(['error' => 'Internal server error'], 500);
		}
	}

	#[Route('/conversations/{conversationId}', name: 'api_v1_conversations_show', methods: ['GET'])]
	public function show(int $conversationId, Request $request): JsonResponse
	{
		/** @var User|null $user */
		$user = $request->attributes->get('_api_user');

		if ($user === null) {
			return new JsonResponse(['error' => 'Authentication required'], 401);
		}

		try {
			$dto = $this->messagingService->getConversation($conversationId, $user->id);

			return new JsonResponse(['data' => $this->conversationToArray($dto)]);
		} catch (\InvalidArgumentException $e) {
			if (str_contains($e->getMessage(), 'not found')) {
				return new JsonResponse(['error' => 'Conversation not found'], 404);
			}

			return new JsonResponse(['error' => 'Forbidden'], 403);
		} catch (\Throwable) {
			return new JsonResponse(['error' => 'Internal server error'], 500);
		}
	}

	#[Route('/conversations', name: 'api_v1_conversations_create', methods: ['POST'])]
	public function create(Request $request): JsonResponse
	{
		/** @var User|null $user */
		$user = $request->attributes->get('_api_user');

		if ($user === null) {
			return new JsonResponse(['error' => 'Authentication required'], 401);
		}

		try {
			$body = json_decode($request->getContent(), true) ?? [];
			$participantIds = (array) ($body['participantIds'] ?? []);
			$title = $body['title'] ?? null;

			if (empty($participantIds)) {
				return new JsonResponse(['error' => 'participantIds is required'], 400);
			}

			$request_dto = new CreateConversationRequest(
				participantIds: $participantIds,
				title: $title ? trim((string) $title) : null,
			);

			$events = $this->messagingService->createConversation($request_dto, $user->id);
			$events->dispatch($this->dispatcher);

			// Get the created conversation
			$event = $events->first();
			if ($event === null) {
				throw new \RuntimeException('Missing conversation-created event');
			}

			$dto = $this->messagingService->getConversation($event->entityId, $user->id);

			return new JsonResponse(['data' => $this->conversationToArray($dto)], 201);
		} catch (\InvalidArgumentException) {
			return new JsonResponse(['error' => 'Invalid request'], 400);
		} catch (\Throwable $e) {
			return new JsonResponse(['error' => 'Internal server error'], 500);
		}
	}

	#[Route('/conversations/{conversationId}/archive', name: 'api_v1_conversations_archive', methods: ['POST'])]
	public function archive(int $conversationId, Request $request): JsonResponse
	{
		/** @var User|null $user */
		$user = $request->attributes->get('_api_user');

		if ($user === null) {
			return new JsonResponse(['error' => 'Authentication required'], 401);
		}

		try {
			$events = $this->messagingService->archiveConversation($conversationId, $user->id);
			$events->dispatch($this->dispatcher);

			return new JsonResponse(status: 204);
		} catch (\InvalidArgumentException) {
			return new JsonResponse(['error' => 'Conversation not found'], 404);
		} catch (\Throwable) {
			return new JsonResponse(['error' => 'Internal server error'], 500);
		}
	}

	#[Route('/conversations/{conversationId}/pin', name: 'api_v1_conversations_pin', methods: ['POST'])]
	public function pin(int $conversationId, Request $request): JsonResponse
	{
		/** @var User|null $user */
		$user = $request->attributes->get('_api_user');

		if ($user === null) {
			return new JsonResponse(['error' => 'Authentication required'], 401);
		}

		try {
			$events = $this->messagingService->pinConversation($conversationId, $user->id);
			$events->dispatch($this->dispatcher);

			return new JsonResponse(status: 204);
		} catch (\InvalidArgumentException) {
			return new JsonResponse(['error' => 'Conversation not found'], 404);
		} catch (\Throwable) {
			return new JsonResponse(['error' => 'Internal server error'], 500);
		}
	}

	#[Route('/conversations/{conversationId}/unpin', name: 'api_v1_conversations_unpin', methods: ['POST'])]
	public function unpin(int $conversationId, Request $request): JsonResponse
	{
		/** @var User|null $user */
		$user = $request->attributes->get('_api_user');

		if ($user === null) {
			return new JsonResponse(['error' => 'Authentication required'], 401);
		}

		try {
			$events = $this->messagingService->unpinConversation($conversationId, $user->id);
			$events->dispatch($this->dispatcher);

			return new JsonResponse(status: 204);
		} catch (\InvalidArgumentException) {
			return new JsonResponse(['error' => 'Conversation not found'], 404);
		} catch (\Throwable) {
			return new JsonResponse(['error' => 'Internal server error'], 500);
		}
	}

	#[Route('/conversations/{conversationId}', name: 'api_v1_conversations_delete', methods: ['DELETE'])]
	public function delete(int $conversationId, Request $request): JsonResponse
	{
		/** @var User|null $user */
		$user = $request->attributes->get('_api_user');

		if ($user === null) {
			return new JsonResponse(['error' => 'Authentication required'], 401);
		}

		try {
			$events = $this->messagingService->deleteConversation($conversationId, $user->id);
			$events->dispatch($this->dispatcher);

			return new JsonResponse(status: 204);
		} catch (\InvalidArgumentException $e) {
			if (str_contains($e->getMessage(), 'owner')) {
				return new JsonResponse(['error' => 'Only owner can delete'], 403);
			}

			return new JsonResponse(['error' => 'Conversation not found'], 404);
		} catch (\Throwable) {
			return new JsonResponse(['error' => 'Internal server error'], 500);
		}
	}

	private function conversationToArray(ConversationDTO $dto): array
	{
		return [
			'id' => $dto->id,
			'title' => $dto->title,
			'createdBy' => $dto->createdBy,
			'createdAt' => $dto->createdAt,
			'lastMessageId' => $dto->lastMessageId,
			'lastMessageAt' => $dto->lastMessageAt,
			'messageCount' => $dto->messageCount,
			'participantCount' => $dto->participantCount,
			'participants' => array_map(
				fn ($p) => [
					'userId' => $p->userId,
					'role' => $p->role,
					'state' => $p->state,
					'joinedAt' => $p->joinedAt,
				],
				$dto->participants ?? [],
			),
		];
	}
}
