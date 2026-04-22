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
use phpbb\common\Event\DomainEventCollection;
use phpbb\threads\DTO\CreatePostRequest;
use phpbb\threads\DTO\CreateTopicRequest;
use phpbb\threads\DTO\PostDTO;
use phpbb\threads\DTO\TopicDTO;
use phpbb\user\DTO\PaginatedResult;

interface ThreadsServiceInterface
{
	public function getTopic(int $topicId): TopicDTO;

	/**
	 * @return PaginatedResult<TopicDTO>
	 */
	public function listTopics(int $forumId, PaginationContext $ctx): PaginatedResult;

	public function createTopic(CreateTopicRequest $request): DomainEventCollection;

	/**
	 * @return PaginatedResult<PostDTO>
	 */
	public function listPosts(int $topicId, PaginationContext $ctx): PaginatedResult;

	public function createPost(CreatePostRequest $request): DomainEventCollection;
}
