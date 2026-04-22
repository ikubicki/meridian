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

use phpbb\common\Event\DomainEventCollection;
use phpbb\hierarchy\DTO\CreateForumRequest;
use phpbb\hierarchy\DTO\ForumDTO;
use phpbb\hierarchy\DTO\UpdateForumRequest;

interface HierarchyServiceInterface
{
	/**
	 * @return ForumDTO[]
	 */
	public function listForums(?int $parentId): array;

	public function getForum(int $forumId): ForumDTO;

	public function createForum(CreateForumRequest $request): DomainEventCollection;

	public function updateForum(UpdateForumRequest $request): DomainEventCollection;

	public function deleteForum(int $forumId, int $actorId): DomainEventCollection;

	public function moveForum(int $forumId, int $newParentId, int $actorId): DomainEventCollection;
}
