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
use phpbb\threads\Entity\Post;
use phpbb\user\DTO\PaginatedResult;

interface PostRepositoryInterface
{
	/**
	 * @throws RepositoryException
	 */
	public function findById(int $id): ?Post;

	/**
	 * @return PaginatedResult<\phpbb\threads\DTO\PostDTO>
	 * @throws RepositoryException
	 */
	public function findByTopic(int $topicId, PaginationContext $ctx): PaginatedResult;

	/**
	 * @throws RepositoryException
	 */
	public function insert(
		int $topicId,
		int $forumId,
		int $posterId,
		string $posterUsername,
		string $posterIp,
		string $content,
		string $subject,
		int $now,
		int $visibility,
	): int;

	/**
	 * @throws RepositoryException
	 */
	public function updateContent(int $postId, string $content): void;

	/**
	 * @throws RepositoryException
	 */
	public function softDelete(int $postId): void;
}
