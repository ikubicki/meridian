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
use phpbb\messaging\DTO\MessageDTO;
use phpbb\messaging\DTO\Request\SendMessageRequest;
use phpbb\user\Entity\User;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class MessagesController
{
	public function __construct(
		private readonly MessagingServiceInterface $messagingService,
		private readonly EventDispatcherInterface $dispatcher,
	) {
	}

	#[Route('/conversations/{conversationId}/messages', name: 'api_v1_messages_list', methods: ['GET'])]
	public function list(int $conversationId, Request $request): JsonResponse
	{
		/** @var User|null $user */
		$user = $request->attributes->get('_api_user');

		if ($user === null) {
			return new JsonResponse(['error' => 'Authentication required'], 401);
		}

		try {
			$ctx = PaginationContext::fromQuery($request->query);
			$result = $this->messagingService->listMessages($conversationId, $user->id, $ctx);

			return new JsonResponse([
				'data' => array_map([$this, 'messageToArray'], $result->items),
				'meta' => [
					'total' => $result->total,
					'page' => $result->page,
					'perPage' => $result->perPage,
					'lastPage' => max(1, $result->totalPages()),
				],
			]);
		} catch (\InvalidArgumentException) {
			return new JsonResponse(['error' => 'Forbidden'], 403);
		} catch (\Throwable) {
			return new JsonResponse(['error' => 'Internal server error'], 500);
		}
	}

	#[Route('/messages/{messageId}', name: 'api_v1_messages_show', methods: ['GET'])]
	public function show(int $messageId, Request $request): JsonResponse
	{
		/** @var User|null $user */
		$user = $request->attributes->get('_api_user');

		if ($user === null) {
			return new JsonResponse(['error' => 'Authentication required'], 401);
		}

		try {
			$dto = $this->messagingService->getMessage($messageId, $user->id);

			return new JsonResponse(['data' => $this->messageToArray($dto)]);
		} catch (\InvalidArgumentException) {
			return new JsonResponse(['error' => 'Message not found'], 404);
		} catch (\Throwable) {
			return new JsonResponse(['error' => 'Internal server error'], 500);
		}
	}

	#[Route('/conversations/{conversationId}/messages', name: 'api_v1_messages_send', methods: ['POST'])]
	public function send(int $conversationId, Request $request): JsonResponse
	{
		/** @var User|null $user */
		$user = $request->attributes->get('_api_user');

		if ($user === null) {
			return new JsonResponse(['error' => 'Authentication required'], 401);
		}

		try {
			$body = json_decode($request->getContent(), true) ?? [];
			$text = trim((string) ($body['text'] ?? ''));
			$subject = isset($body['subject']) ? trim((string) $body['subject']) : null;

			if ($text === '') {
				return new JsonResponse(['error' => 'Message text is required'], 400);
			}

			$request_dto = new SendMessageRequest(
				messageText: $text,
				messageSubject: $subject,
			);

			$events = $this->messagingService->sendMessage($conversationId, $request_dto, $user->id);
			$events->dispatch($this->dispatcher);

			// Get the created message
			$event = $events->first();
			if ($event === null) {
				throw new \RuntimeException('Missing message-created event');
			}

			$dto = $this->messagingService->getMessage($event->entityId, $user->id);

			return new JsonResponse(['data' => $this->messageToArray($dto)], 201);
		} catch (\InvalidArgumentException) {
			return new JsonResponse(['error' => 'Invalid request'], 400);
		} catch (\Throwable) {
			return new JsonResponse(['error' => 'Internal server error'], 500);
		}
	}

	#[Route('/messages/{messageId}', name: 'api_v1_messages_edit', methods: ['PATCH'])]
	public function edit(int $messageId, Request $request): JsonResponse
	{
		/** @var User|null $user */
		$user = $request->attributes->get('_api_user');

		if ($user === null) {
			return new JsonResponse(['error' => 'Authentication required'], 401);
		}

		try {
			$body = json_decode($request->getContent(), true) ?? [];
			$text = trim((string) ($body['text'] ?? ''));

			if ($text === '') {
				return new JsonResponse(['error' => 'Message text is required'], 400);
			}

			$events = $this->messagingService->editMessage($messageId, $text, $user->id);
			$events->dispatch($this->dispatcher);

			$dto = $this->messagingService->getMessage($messageId, $user->id);

			return new JsonResponse(['data' => $this->messageToArray($dto)]);
		} catch (\InvalidArgumentException $e) {
			if (str_contains($e->getMessage(), 'Edit window')) {
				return new JsonResponse(['error' => 'Edit window expired'], 409);
			}
			if (str_contains($e->getMessage(), 'author')) {
				return new JsonResponse(['error' => 'Forbidden'], 403);
			}

			return new JsonResponse(['error' => 'Message not found'], 404);
		} catch (\Throwable) {
			return new JsonResponse(['error' => 'Internal server error'], 500);
		}
	}

	#[Route('/messages/{messageId}', name: 'api_v1_messages_delete', methods: ['DELETE'])]
	public function delete(int $messageId, Request $request): JsonResponse
	{
		/** @var User|null $user */
		$user = $request->attributes->get('_api_user');

		if ($user === null) {
			return new JsonResponse(['error' => 'Authentication required'], 401);
		}

		try {
			$events = $this->messagingService->deleteMessage($messageId, $user->id);
			$events->dispatch($this->dispatcher);

			return new JsonResponse(status: 204);
		} catch (\InvalidArgumentException $e) {
			if (str_contains($e->getMessage(), 'author') || str_contains($e->getMessage(), 'owner')) {
				return new JsonResponse(['error' => 'Forbidden'], 403);
			}

			return new JsonResponse(['error' => 'Message not found'], 404);
		} catch (\Throwable) {
			return new JsonResponse(['error' => 'Internal server error'], 500);
		}
	}

	#[Route('/conversations/{conversationId}/messages/{messageId}/read', name: 'api_v1_messages_mark_read', methods: ['POST'])]
	public function markRead(int $conversationId, int $messageId, Request $request): JsonResponse
	{
		/** @var User|null $user */
		$user = $request->attributes->get('_api_user');

		if ($user === null) {
			return new JsonResponse(['error' => 'Authentication required'], 401);
		}

		try {
			$events = $this->messagingService->markMessageRead($messageId, $conversationId, $user->id);
			$events->dispatch($this->dispatcher);

			return new JsonResponse(status: 204);
		} catch (\InvalidArgumentException) {
			return new JsonResponse(['error' => 'Invalid request'], 400);
		} catch (\Throwable) {
			return new JsonResponse(['error' => 'Internal server error'], 500);
		}
	}

	#[Route('/conversations/{conversationId}/messages/search', name: 'api_v1_messages_search', methods: ['GET'])]
	public function search(int $conversationId, Request $request): JsonResponse
	{
		/** @var User|null $user */
		$user = $request->attributes->get('_api_user');

		if ($user === null) {
			return new JsonResponse(['error' => 'Authentication required'], 401);
		}

		try {
			$query = trim((string) ($request->query->get('q') ?? ''));
			if ($query === '') {
				return new JsonResponse(['error' => 'Search query is required'], 400);
			}

			$ctx = PaginationContext::fromQuery($request->query);
			$result = $this->messagingService->searchMessages($conversationId, $user->id, $query, $ctx);

			return new JsonResponse([
				'data' => array_map([$this, 'messageToArray'], $result->items),
				'meta' => [
					'total' => $result->total,
					'page' => $result->page,
					'perPage' => $result->perPage,
					'lastPage' => max(1, $result->totalPages()),
				],
			]);
		} catch (\InvalidArgumentException) {
			return new JsonResponse(['error' => 'Forbidden'], 403);
		} catch (\Throwable) {
			return new JsonResponse(['error' => 'Internal server error'], 500);
		}
	}

	private function messageToArray(MessageDTO $dto): array
	{
		return [
			'id' => $dto->id,
			'conversationId' => $dto->conversationId,
			'authorId' => $dto->authorId,
			'text' => $dto->messageText,
			'subject' => $dto->messageSubject,
			'createdAt' => $dto->createdAt,
			'editedAt' => $dto->editedAt,
			'editCount' => $dto->editCount,
		];
	}
}
