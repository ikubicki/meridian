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

namespace phpbb\storage\Contract;

use phpbb\storage\Entity\StorageQuota;

interface StorageQuotaRepositoryInterface
{
	public function findByUserAndForum(int $userId, int $forumId): ?StorageQuota;

	/** Returns true if usage was incremented; false if quota exceeded. */
	public function incrementUsage(int $userId, int $forumId, int $bytes): bool;

	public function decrementUsage(int $userId, int $forumId, int $bytes): void;

	public function reconcile(int $userId, int $forumId, int $actualBytes): void;

	/** @return array<array{user_id: int, forum_id: int}> */
	public function findAllUserForumPairs(): array;

	/** Insert quota row with max_bytes = PHP_INT_MAX; no-op if row already exists. */
	public function initDefault(int $userId, int $forumId): void;
}
