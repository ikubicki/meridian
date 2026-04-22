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

namespace phpbb\hierarchy\Service;

use phpbb\common\Event\DomainEventCollection;
use phpbb\hierarchy\Contract\ForumRepositoryInterface;
use phpbb\hierarchy\Contract\HierarchyServiceInterface;
use phpbb\hierarchy\Contract\TreeServiceInterface;
use phpbb\hierarchy\DTO\CreateForumRequest;
use phpbb\hierarchy\DTO\ForumDTO;
use phpbb\hierarchy\DTO\UpdateForumRequest;
use phpbb\hierarchy\Event\ForumCreatedEvent;
use phpbb\hierarchy\Event\ForumDeletedEvent;
use phpbb\hierarchy\Event\ForumMovedEvent;
use phpbb\hierarchy\Event\ForumUpdatedEvent;

final class HierarchyService implements HierarchyServiceInterface
{
	public function __construct(
		private readonly ForumRepositoryInterface $repository,
		private readonly TreeServiceInterface $treeService,
	) {
	}

	public function listForums(?int $parentId): array
	{
		$forums = $this->repository->findChildren($parentId ?? 0);

		$result = [];
		foreach ($forums as $forum) {
			$result[] = ForumDTO::fromEntity($forum);
		}

		return $result;
	}

	public function getForum(int $forumId): ForumDTO
	{
		$forum = $this->repository->findById($forumId);

		if ($forum === null) {
			throw new \InvalidArgumentException("Forum {$forumId} not found");
		}

		return ForumDTO::fromEntity($forum);
	}

	public function createForum(CreateForumRequest $request): DomainEventCollection
	{
		$forumId = $this->repository->insertRaw($request);

		$this->treeService->insertNode($forumId, $request->parentId);

		return new DomainEventCollection([
			new ForumCreatedEvent(
				entityId: $forumId,
				actorId: $request->actorId,
			),
		]);
	}

	public function updateForum(UpdateForumRequest $request): DomainEventCollection
	{
		$this->repository->update($request);

		return new DomainEventCollection([
			new ForumUpdatedEvent(
				entityId: $request->forumId,
				actorId: $request->actorId,
			),
		]);
	}

	public function deleteForum(int $forumId, int $actorId): DomainEventCollection
	{
		$children = $this->repository->findChildren($forumId);
		if (!empty($children)) {
			throw new \InvalidArgumentException("Cannot delete forum {$forumId} because it has children");
		}

		$forum = $this->repository->findById($forumId);
		if ($forum === null) {
			throw new \InvalidArgumentException("Forum {$forumId} not found");
		}

		$this->treeService->removeNode($forum->leftId, $forum->rightId);

		$this->repository->delete($forumId);

		return new DomainEventCollection([
			new ForumDeletedEvent(
				entityId: $forumId,
				actorId: $actorId,
			),
		]);
	}

	public function moveForum(int $forumId, int $newParentId, int $actorId): DomainEventCollection
	{
		$this->treeService->moveNode($forumId, $newParentId);

		return new DomainEventCollection([
			new ForumMovedEvent(
				entityId: $forumId,
				actorId: $actorId,
			),
		]);
	}
}
