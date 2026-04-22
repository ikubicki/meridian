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

namespace phpbb\threads;

use Doctrine\DBAL\Connection;
use phpbb\api\DTO\PaginationContext;
use phpbb\common\Event\DomainEventCollection;
use phpbb\threads\Contract\PostRepositoryInterface;
use phpbb\threads\Contract\ThreadsServiceInterface;
use phpbb\threads\Contract\TopicRepositoryInterface;
use phpbb\threads\DTO\CreatePostRequest;
use phpbb\threads\DTO\CreateTopicRequest;
use phpbb\threads\DTO\PostDTO;
use phpbb\threads\DTO\TopicDTO;
use phpbb\threads\Event\PostCreatedEvent;
use phpbb\threads\Event\TopicCreatedEvent;
use phpbb\user\DTO\PaginatedResult;

final class ThreadsService implements ThreadsServiceInterface
{
	public function __construct(
		private readonly TopicRepositoryInterface $topicRepository,
		private readonly PostRepositoryInterface $postRepository,
		private readonly Connection $connection,
	) {
	}

	public function getTopic(int $topicId): TopicDTO
	{
		$topic = $this->topicRepository->findById($topicId);

		if ($topic === null) {
			throw new \InvalidArgumentException("Topic {$topicId} not found");
		}

		if ($topic->visibility !== 1) {
			throw new \InvalidArgumentException("Topic {$topicId} is not visible");
		}

		return TopicDTO::fromEntity($topic);
	}

	/**
	 * @return PaginatedResult<TopicDTO>
	 */
	public function listTopics(int $forumId, PaginationContext $ctx): PaginatedResult
	{
		return $this->topicRepository->findByForum($forumId, $ctx);
	}

	public function createTopic(CreateTopicRequest $request): DomainEventCollection
	{
		$now = time();

		$this->connection->beginTransaction();

		try {
			$topicId = $this->topicRepository->insert($request, $now);

			$postId = $this->postRepository->insert(
				topicId:        $topicId,
				forumId:        $request->forumId,
				posterId:       $request->actorId,
				posterUsername: $request->actorUsername,
				posterIp:       $request->posterIp,
				content:        $request->content,
				subject:        $request->title,
				now:            $now,
				visibility:     1,
			);

			$this->topicRepository->updateFirstLastPost($topicId, $postId);
			$this->connection->commit();
		} catch (\Throwable $e) {
			if ($this->connection->isTransactionActive()) {
				$this->connection->rollBack();
			}

			throw new \RuntimeException('Failed to create topic', previous: $e);
		}

		return new DomainEventCollection([
			new TopicCreatedEvent(entityId: $topicId, actorId: $request->actorId),
		]);
	}

	/**
	 * @return PaginatedResult<PostDTO>
	 */
	public function listPosts(int $topicId, PaginationContext $ctx): PaginatedResult
	{
		$topic = $this->topicRepository->findById($topicId);

		if ($topic === null) {
			throw new \InvalidArgumentException("Topic {$topicId} not found");
		}

		return $this->postRepository->findByTopic($topicId, $ctx);
	}

	public function createPost(CreatePostRequest $request): DomainEventCollection
	{
		$topic = $this->topicRepository->findById($request->topicId);

		if ($topic === null) {
			throw new \InvalidArgumentException("Topic {$request->topicId} not found");
		}

		$now = time();

		$this->connection->beginTransaction();

		try {
			$postId = $this->postRepository->insert(
				topicId:        $request->topicId,
				forumId:        $topic->forumId,
				posterId:       $request->actorId,
				posterUsername: $request->actorUsername,
				posterIp:       $request->posterIp,
				content:        $request->content,
				subject:        'Re: ' . $topic->title,
				now:            $now,
				visibility:     1,
			);

			$this->topicRepository->updateLastPost(
				topicId:      $request->topicId,
				postId:       $postId,
				posterId:     $request->actorId,
				posterName:   $request->actorUsername,
				posterColour: $request->actorColour,
				now:          $now,
			);

			$this->connection->commit();
		} catch (\Throwable $e) {
			if ($this->connection->isTransactionActive()) {
				$this->connection->rollBack();
			}

			throw new \RuntimeException('Failed to create post', previous: $e);
		}

		return new DomainEventCollection([
			new PostCreatedEvent(entityId: $postId, actorId: $request->actorId),
		]);
	}
}
