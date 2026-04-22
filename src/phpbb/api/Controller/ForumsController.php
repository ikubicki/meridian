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

use phpbb\hierarchy\Contract\HierarchyServiceInterface;
use phpbb\hierarchy\DTO\CreateForumRequest;
use phpbb\hierarchy\DTO\ForumDTO;
use phpbb\hierarchy\DTO\UpdateForumRequest;
use phpbb\hierarchy\Entity\ForumType;
use phpbb\user\Entity\User;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class ForumsController
{
	public function __construct(
		private readonly HierarchyServiceInterface $hierarchyService,
		private readonly EventDispatcherInterface $dispatcher,
	) {
	}

	#[Route('/forums', name: 'api_v1_forums_index', methods: ['GET'], defaults: ['_allow_anonymous' => true])]
	public function index(Request $request): JsonResponse
	{
		$parentId = $request->query->has('parent_id')
			? (int) $request->query->get('parent_id')
			: null;

		$forums = $this->hierarchyService->listForums($parentId);

		$data = array_map([$this, 'forumToArray'], $forums);

		return new JsonResponse([
			'data' => $data,
			'meta' => ['total' => count($data)],
		]);
	}

	#[Route('/forums/{forumId}', name: 'api_v1_forums_show', methods: ['GET'], defaults: ['_allow_anonymous' => true])]
	public function show(int $forumId): JsonResponse
	{
		try {
			$forum = $this->hierarchyService->getForum($forumId);
		} catch (\InvalidArgumentException $e) {
			return new JsonResponse(['error' => 'Forum not found', 'status' => 404], 404);
		}

		return new JsonResponse(['data' => $this->forumToArray($forum)]);
	}

	#[Route('/forums', name: 'api_v1_forums_create', methods: ['POST'])]
	public function create(Request $request): JsonResponse
	{
		$authResponse = $this->requireAdmin($request);
		if ($authResponse !== null) {
			return $authResponse;
		}

		$body = json_decode($request->getContent(), true) ?? [];

		$forumRequest = new CreateForumRequest(
			name: (string) ($body['name'] ?? ''),
			type: ForumType::from((int) ($body['type'] ?? ForumType::Forum->value)),
			parentId: (int) ($body['parent_id'] ?? 0),
			actorId: $this->getActorId($request),
			description: (string) ($body['description'] ?? ''),
			link: (string) ($body['link'] ?? ''),
		);

		try {
			$events = $this->hierarchyService->createForum($forumRequest);
			$events->dispatch($this->dispatcher);
		} catch (\InvalidArgumentException $e) {
			return new JsonResponse(['error' => $e->getMessage(), 'status' => 400], 400);
		}

		return new JsonResponse(['status' => 'created'], 201);
	}

	#[Route('/forums/{forumId}', name: 'api_v1_forums_update', methods: ['PUT'])]
	public function update(int $forumId, Request $request): JsonResponse
	{
		$authResponse = $this->requireAdmin($request);
		if ($authResponse !== null) {
			return $authResponse;
		}

		$body = json_decode($request->getContent(), true) ?? [];

		$updateRequest = new UpdateForumRequest(
			forumId: $forumId,
			actorId: $this->getActorId($request),
			name: isset($body['name']) ? (string) $body['name'] : null,
			type: isset($body['type']) ? ForumType::from((int) $body['type']) : null,
			description: isset($body['description']) ? (string) $body['description'] : null,
			link: isset($body['link']) ? (string) $body['link'] : null,
		);

		try {
			$events = $this->hierarchyService->updateForum($updateRequest);
			$events->dispatch($this->dispatcher);
		} catch (\InvalidArgumentException $e) {
			$message = $e->getMessage();
			$status  = str_contains($message, 'not found') ? 404 : 400;

			return new JsonResponse(['error' => $message, 'status' => $status], $status);
		}

		return new JsonResponse(['status' => 'updated']);
	}

	#[Route('/forums/{forumId}', name: 'api_v1_forums_delete', methods: ['DELETE'])]
	public function delete(int $forumId, Request $request): JsonResponse
	{
		$authResponse = $this->requireAdmin($request);
		if ($authResponse !== null) {
			return $authResponse;
		}

		try {
			$events = $this->hierarchyService->deleteForum($forumId, $this->getActorId($request));
			$events->dispatch($this->dispatcher);
		} catch (\InvalidArgumentException $e) {
			$message = $e->getMessage();
			$status  = str_contains($message, 'not found') ? 404 : 400;

			return new JsonResponse(['error' => $message, 'status' => $status], $status);
		}

		return new JsonResponse(['status' => 'deleted']);
	}

	#[Route('/forums/{forumId}/move', name: 'api_v1_forums_move', methods: ['PATCH'])]
	public function move(int $forumId, Request $request): JsonResponse
	{
		$authResponse = $this->requireAdmin($request);
		if ($authResponse !== null) {
			return $authResponse;
		}

		$body        = json_decode($request->getContent(), true) ?? [];
		$newParentId = (int) ($body['new_parent_id'] ?? 0);

		try {
			$events = $this->hierarchyService->moveForum($forumId, $newParentId, $this->getActorId($request));
			$events->dispatch($this->dispatcher);
		} catch (\InvalidArgumentException $e) {
			$message = $e->getMessage();
			$status  = str_contains($message, 'not found') ? 404 : 400;

			return new JsonResponse(['error' => $message, 'status' => $status], $status);
		}

		return new JsonResponse(['status' => 'moved']);
	}

	private function forumToArray(ForumDTO $dto): array
	{
		return [
			'id'             => $dto->id,
			'title'          => $dto->name,
			'description'    => $dto->description,
			'parentId'       => $dto->parentId,
			'type'           => $dto->type,
			'status'         => $dto->status,
			'leftId'         => $dto->leftId,
			'rightId'        => $dto->rightId,
			'displayOnIndex' => $dto->displayOnIndex,
			'topicCount'     => $dto->topicsApproved,
			'postCount'      => $dto->postsApproved,
			'lastPostId'     => $dto->lastPostId,
			'lastPostTime'   => $dto->lastPostTime,
			'lastPosterName' => $dto->lastPosterName,
			'link'           => $dto->link,
		];
	}

	private function requireAdmin(Request $request): ?JsonResponse
	{
		/** @var User|null $user */
		$user = $request->attributes->get('_api_user');

		if ($user === null || $request->attributes->get('_api_elevated') !== true) {
			return new JsonResponse(['error' => 'Elevated token required', 'status' => 401], 401);
		}

		return null;
	}

	private function getActorId(Request $request): int
	{
		/** @var User|null $user */
		$user = $request->attributes->get('_api_user');

		return $user?->id ?? 0;
	}
}
