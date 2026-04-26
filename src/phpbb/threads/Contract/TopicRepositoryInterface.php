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

namespace phpbb\threads\Contract;

use phpbb\api\DTO\PaginationContext;
use phpbb\db\Exception\RepositoryException;
use phpbb\threads\DTO\CreateTopicRequest;
use phpbb\threads\Entity\Topic;
use phpbb\user\DTO\PaginatedResult;

interface TopicRepositoryInterface
{
	/**
	 * @throws RepositoryException
	 */
	public function findById(int $id): ?Topic;

	/**
	 * @return PaginatedResult<\phpbb\threads\DTO\TopicDTO>
	 * @throws RepositoryException
	 */
	public function findByForum(int $forumId, PaginationContext $ctx): PaginatedResult;

	/**
	 * @throws RepositoryException
	 */
	public function insert(CreateTopicRequest $request, int $now): int;

	/**
	 * @throws RepositoryException
	 */
	public function updateFirstLastPost(int $topicId, int $postId): void;

	/**
	 * @throws RepositoryException
	 */
	public function updateLastPost(
		int $topicId,
		int $postId,
		int $posterId,
		string $posterName,
		string $posterColour,
		int $now,
	): void;

	/**
	 * @throws RepositoryException
	 */
	public function updateTitle(int $topicId, string $title): void;

	/**
	 * @throws RepositoryException
	 */
	public function softDelete(int $topicId): void;

	/**
	 * @throws RepositoryException
	 */
	public function decrementPostCount(int $topicId): void;
}
