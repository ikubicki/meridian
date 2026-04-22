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

namespace phpbb\hierarchy\Contract;

use phpbb\hierarchy\DTO\CreateForumRequest;
use phpbb\hierarchy\DTO\UpdateForumRequest;
use phpbb\hierarchy\Entity\Forum;

interface ForumRepositoryInterface
{
	public function findById(int $id): ?Forum;

	/** @return array<int, Forum> keyed by forum_id, ordered by left_id ASC */
	public function findAll(): array;

	/** @return array<int, Forum> keyed by forum_id, ordered by left_id ASC */
	public function findChildren(int $parentId): array;

	public function insertRaw(CreateForumRequest $request): int;

	public function update(UpdateForumRequest $request): Forum;

	public function delete(int $forumId): void;

	public function updateTreePosition(int $forumId, int $leftId, int $rightId, int $parentId): void;

	public function shiftLeftIds(int $threshold, int $delta): void;

	public function shiftRightIds(int $threshold, int $delta): void;

	public function updateParentId(int $forumId, int $parentId): void;

	public function clearParentsCache(int $forumId): void;
}
