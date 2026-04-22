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

interface TreeServiceInterface
{
	/**
	 * Insert a new node under $parentId.
	 * Returns the (left_id, right_id) pair for the new node as [int, int].
	 * @return array{0: int, 1: int}
	 */
	public function insertNode(int $forumId, int $parentId): array;

	/**
	 * Remove $width positions from the tree after removing a node (width=2 for leaf).
	 */
	public function removeNode(int $leftId, int $rightId): void;

	/**
	 * Move a subtree rooted at $forumId to become a child of $newParentId.
	 */
	public function moveNode(int $forumId, int $newParentId): void;
}
