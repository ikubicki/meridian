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
use phpbb\auth\Contract\AuthorizationServiceInterface;
use phpbb\threads\Contract\ThreadsServiceInterface;
use phpbb\threads\DTO\CreatePostRequest;
use phpbb\threads\DTO\PostDTO;
use phpbb\user\Contract\UserRepositoryInterface;
use phpbb\user\Entity\User;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class PostsController
{
	private const ANONYMOUS_USER_ID = 1;

	public function __construct(
		private readonly ThreadsServiceInterface $threadsService,
		private readonly AuthorizationServiceInterface $authorizationService,
		private readonly UserRepositoryInterface $userRepository,
		private readonly EventDispatcherInterface $dispatcher,
	) {
	}

	#[Route('/topics/{topicId}/posts', name: 'api_v1_topics_posts_index', methods: ['GET'], defaults: ['_allow_anonymous' => true])]
	public function index(int $topicId, Request $request): JsonResponse
	{
		try {
			$topic = $this->threadsService->getTopic($topicId);
		} catch (\InvalidArgumentException) {
			return new JsonResponse(['error' => 'Topic not found', 'status' => 404], 404);
		}

		/** @var User|null $user */
		$user    = $request->attributes->get('_api_user');
		$checker = $user ?? $this->userRepository->findById(self::ANONYMOUS_USER_ID);

		if ($checker === null || !$this->authorizationService->isGranted($checker, 'f_read', $topic->forumId)) {
			return new JsonResponse(['error' => 'Forbidden', 'status' => 403], 403);
		}

		$ctx    = PaginationContext::fromQuery($request->query);
		$result = $this->threadsService->listPosts($topicId, $ctx);

		return new JsonResponse([
			'data' => array_map([$this, 'postToArray'], $result->items),
			'meta' => [
				'total'    => $result->total,
				'page'     => $result->page,
				'perPage'  => $result->perPage,
				'lastPage' => max(1, $result->totalPages()),
			],
		]);
	}

	#[Route('/topics/{topicId}/posts', name: 'api_v1_topics_posts_create', methods: ['POST'])]
	public function create(int $topicId, Request $request): JsonResponse
	{
		/** @var User|null $user */
		$user = $request->attributes->get('_api_user');

		if ($user === null) {
			return new JsonResponse(['error' => 'Authentication required', 'status' => 401], 401);
		}

		try {
			$topic = $this->threadsService->getTopic($topicId);
		} catch (\InvalidArgumentException) {
			return new JsonResponse(['error' => 'Topic not found', 'status' => 404], 404);
		}

		if (!$this->authorizationService->isGranted($user, 'f_reply', $topic->forumId)) {
			return new JsonResponse(['error' => 'Forbidden', 'status' => 403], 403);
		}

		$body    = json_decode($request->getContent(), true) ?? [];
		$content = trim((string) ($body['content'] ?? ''));

		if ($content === '') {
			return new JsonResponse(['error' => 'Content is required', 'status' => 400], 400);
		}

		try {
			$events = $this->threadsService->createPost(new CreatePostRequest(
				topicId: $topicId,
				content: $content,
				actorId: $user->id,
				actorUsername: $user->username,
				actorColour: $user->colour,
				posterIp: $request->getClientIp() ?? '127.0.0.1',
			));
			$events->dispatch($this->dispatcher);
			$event = $events->first();

			if ($event === null) {
				throw new \RuntimeException('Missing post-created event');
			}

			$posts = $this->threadsService->listPosts($topicId, new PaginationContext(page: 1, perPage: 1));
			$dto   = $posts->items[0] ?? null;

			if ($dto === null || $dto->id !== $event->entityId) {
				$dto = new PostDTO(
					id: $event->entityId,
					topicId: $topicId,
					forumId: $topic->forumId,
					authorId: $user->id,
					content: $content,
				);
			}
		} catch (\InvalidArgumentException) {
			return new JsonResponse(['error' => 'Topic not found', 'status' => 404], 404);
		} catch (\Throwable) {

			return new JsonResponse(['error' => 'Internal server error', 'status' => 500], 500);
		}

		return new JsonResponse([
			'data' => $this->postToArray($dto),
		], 201);
	}

	private function postToArray(PostDTO $dto): array
	{
		return [
			'id'       => $dto->id,
			'topicId'  => $dto->topicId,
			'forumId'  => $dto->forumId,
			'authorId' => $dto->authorId,
			'content'  => $dto->content,
		];
	}
}
