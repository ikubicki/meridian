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

use phpbb\hierarchy\Contract\ForumRepositoryInterface;
use phpbb\hierarchy\Contract\TreeServiceInterface;

final class TreeService implements TreeServiceInterface
{
	public function __construct(
		private readonly ForumRepositoryInterface $repository,
	) {
	}

	public function insertNode(int $forumId, int $parentId): array
	{
		if ($parentId === 0) {
			$all = $this->repository->findAll();

			if (empty($all)) {
				$newLeft  = 1;
				$newRight = 2;
			} else {
				$maxRight = 0;
				foreach ($all as $forum) {
					if ($forum->rightId > $maxRight) {
						$maxRight = $forum->rightId;
					}
				}
				$newLeft  = $maxRight + 1;
				$newRight = $maxRight + 2;
			}

			$this->repository->updateTreePosition($forumId, $newLeft, $newRight, 0);

			return [$newLeft, $newRight];
		}

		$parent = $this->repository->findById($parentId);
		if ($parent === null) {
			throw new \InvalidArgumentException("Parent forum {$parentId} not found");
		}

		$insertPoint = $parent->rightId;

		$this->repository->shiftLeftIds($insertPoint, 2);
		$this->repository->shiftRightIds($insertPoint, 2);

		$newLeft  = $insertPoint;
		$newRight = $insertPoint + 1;

		$this->repository->updateTreePosition($forumId, $newLeft, $newRight, $parentId);

		return [$newLeft, $newRight];
	}

	public function removeNode(int $leftId, int $rightId): void
	{
		$width = $rightId - $leftId + 1;
		$this->repository->shiftLeftIds($rightId + 1, -$width);
		$this->repository->shiftRightIds($rightId + 1, -$width);
	}

	public function moveNode(int $forumId, int $newParentId): void
	{
		$forum = $this->repository->findById($forumId);
		if ($forum === null) {
			throw new \InvalidArgumentException("Forum {$forumId} not found");
		}

		$oldLeft  = $forum->leftId;
		$oldRight = $forum->rightId;
		$width    = $oldRight - $oldLeft + 1;

		$all           = $this->repository->findAll();
		$subtreeNodes  = array_filter(
			$all,
			fn ($f) => $f->leftId >= $oldLeft && $f->rightId <= $oldRight,
		);

		// Step A: Move subtree to temporary positions (add large offset)
		$tmpOffset = 1_000_000;
		foreach ($subtreeNodes as $node) {
			$this->repository->updateTreePosition(
				$node->id,
				$node->leftId + $tmpOffset,
				$node->rightId + $tmpOffset,
				$node->parentId,
			);
		}

		// Step B: Close the gap left by removing the subtree
		$this->repository->shiftLeftIds($oldRight + 1, -$width);
		$this->repository->shiftRightIds($oldRight + 1, -$width);

		// Step C: Determine new insertion point
		if ($newParentId === 0) {
			$allAfterClose = $this->repository->findAll();
			$maxRight      = 0;
			foreach ($allAfterClose as $f) {
				if ($f->rightId > $maxRight) {
					$maxRight = $f->rightId;
				}
			}
			$newLeft = $maxRight + 1;
		} else {
			$newParent = $this->repository->findById($newParentId);
			if ($newParent === null) {
				throw new \InvalidArgumentException("Parent forum {$newParentId} not found");
			}
			$newLeft = $newParent->rightId;
		}

		// Step D: Open gap at new position for the subtree
		$this->repository->shiftLeftIds($newLeft, $width);
		$this->repository->shiftRightIds($newLeft, $width);

		// Step E: Place the subtree at new position + update parent_id for root node.
		// $subtreeNodes holds original (pre-Step-A) positions, so the final position
		// is simply original + offsetDiff. The tmpOffset cancels out and is not needed here.
		$offsetDiff = $newLeft - $oldLeft;
		foreach ($subtreeNodes as $node) {
			$newNodeParent = ($node->id === $forumId) ? $newParentId : $node->parentId;
			$this->repository->updateTreePosition(
				$node->id,
				$node->leftId + $offsetDiff,
				$node->rightId + $offsetDiff,
				$newNodeParent,
			);
		}

		// Step F: Clear parents cache for all moved nodes
		foreach ($subtreeNodes as $node) {
			$this->repository->clearParentsCache($node->id);
		}
	}
}
