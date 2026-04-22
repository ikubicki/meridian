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
use phpbb\threads\DTO\CreateTopicRequest;
use phpbb\threads\DTO\TopicDTO;
use phpbb\user\Contract\UserRepositoryInterface;
use phpbb\user\Entity\User;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class TopicsController
{
	private const ANONYMOUS_USER_ID = 1;

	public function __construct(
		private readonly ThreadsServiceInterface $threadsService,
		private readonly AuthorizationServiceInterface $authorizationService,
		private readonly UserRepositoryInterface $userRepository,
		private readonly EventDispatcherInterface $dispatcher,
	) {
	}

	#[Route('/forums/{forumId}/topics', name: 'api_v1_forums_topics_index', methods: ['GET'], defaults: ['_allow_anonymous' => true])]
	public function indexByForum(int $forumId, Request $request): JsonResponse
	{
		/** @var User|null $user */
		$user    = $request->attributes->get('_api_user');
		$checker = $user ?? $this->userRepository->findById(self::ANONYMOUS_USER_ID);

		if ($checker === null || !$this->authorizationService->isGranted($checker, 'f_read', $forumId)) {
			return new JsonResponse(['error' => 'Forbidden', 'status' => 403], 403);
		}

		$ctx    = PaginationContext::fromQuery($request->query);
		$result = $this->threadsService->listTopics($forumId, $ctx);

		return new JsonResponse([
			'data' => array_map([$this, 'topicToArray'], $result->items),
			'meta' => [
				'total'    => $result->total,
				'page'     => $result->page,
				'perPage'  => $result->perPage,
				'lastPage' => max(1, $result->totalPages()),
			],
		]);
	}

	#[Route('/topics/{topicId}', name: 'api_v1_topics_show', methods: ['GET'], defaults: ['_allow_anonymous' => true])]
	public function show(int $topicId, Request $request): JsonResponse
	{
		try {
			$dto = $this->threadsService->getTopic($topicId);
		} catch (\InvalidArgumentException) {
			return new JsonResponse(['error' => 'Topic not found', 'status' => 404], 404);
		}

		/** @var User|null $user */
		$user    = $request->attributes->get('_api_user');
		$checker = $user ?? $this->userRepository->findById(self::ANONYMOUS_USER_ID);

		if ($checker === null || !$this->authorizationService->isGranted($checker, 'f_read', $dto->forumId)) {
			return new JsonResponse(['error' => 'Forbidden', 'status' => 403], 403);
		}

		return new JsonResponse(['data' => $this->topicToArray($dto)]);
	}

	#[Route('/forums/{forumId}/topics', name: 'api_v1_forums_topics_create', methods: ['POST'])]
	public function create(int $forumId, Request $request): JsonResponse
	{
		/** @var User|null $user */
		$user = $request->attributes->get('_api_user');

		if ($user === null) {
			return new JsonResponse(['error' => 'Authentication required', 'status' => 401], 401);
		}

		if (!$this->authorizationService->isGranted($user, 'f_post', $forumId)) {
			return new JsonResponse(['error' => 'Forbidden', 'status' => 403], 403);
		}

		$body    = json_decode($request->getContent(), true) ?? [];
		$title   = trim((string) ($body['title'] ?? ''));
		$content = trim((string) ($body['content'] ?? ''));

		if ($title === '') {
			return new JsonResponse(['error' => 'Title is required', 'status' => 400], 400);
		}

		try {
			$events = $this->threadsService->createTopic(new CreateTopicRequest(
				forumId:       $forumId,
				title:         $title,
				content:       $content,
				actorId:       $user->id,
				actorUsername: $user->username,
				actorColour:   $user->colour,
				posterIp:      $request->getClientIp() ?? '127.0.0.1',
			));
			$events->dispatch($this->dispatcher);
			$event = $events->first();

			if ($event === null) {
				throw new \RuntimeException('Missing topic-created event');
			}

			$dto = $this->threadsService->getTopic($event->entityId);
		} catch (\InvalidArgumentException) {
			return new JsonResponse(['error' => 'Topic not found', 'status' => 404], 404);
		} catch (\Throwable) {
			return new JsonResponse(['error' => 'Internal server error', 'status' => 500], 500);
		}

		return new JsonResponse(['data' => $this->topicToArray($dto)], 201);
	}

	private function topicToArray(TopicDTO $dto): array
	{
		return [
			'id'             => $dto->id,
			'title'          => $dto->title,
			'forumId'        => $dto->forumId,
			'authorId'       => $dto->authorId,
			'postCount'      => $dto->postCount,
			'lastPosterName' => $dto->lastPosterName,
			'lastPostTime'   => $dto->lastPostTime,
			'createdAt'      => $dto->createdAt,
		];
	}
}
