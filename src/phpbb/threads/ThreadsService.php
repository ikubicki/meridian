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
use phpbb\content\Contract\PostContentPipelineInterface;
use phpbb\content\DTO\ContentContext;
use phpbb\search\Contract\SearchIndexerInterface;
use phpbb\threads\Contract\PostRepositoryInterface;
use phpbb\threads\Contract\ThreadsServiceInterface;
use phpbb\threads\Contract\TopicRepositoryInterface;
use phpbb\threads\DTO\CreatePostRequest;
use phpbb\threads\DTO\CreateTopicRequest;
use phpbb\threads\DTO\PostDTO;
use phpbb\threads\DTO\TopicDTO;
use phpbb\threads\DTO\UpdatePostRequest;
use phpbb\threads\DTO\UpdateTopicRequest;
use phpbb\threads\Event\PostCreatedEvent;
use phpbb\threads\Event\PostDeletedEvent;
use phpbb\threads\Event\PostUpdatedEvent;
use phpbb\threads\Event\TopicCreatedEvent;
use phpbb\threads\Event\TopicDeletedEvent;
use phpbb\threads\Event\TopicUpdatedEvent;
use phpbb\user\DTO\PaginatedResult;

final class ThreadsService implements ThreadsServiceInterface
{
	public function __construct(
		private readonly TopicRepositoryInterface $topicRepository,
		private readonly PostRepositoryInterface $postRepository,
		private readonly Connection $connection,
		private readonly SearchIndexerInterface $searchIndexer,
		private readonly PostContentPipelineInterface $contentPipeline,
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

	public function getPost(int $postId): PostDTO
	{
		$post = $this->postRepository->findById($postId);

		if ($post === null || $post->visibility !== 1) {
			throw new \InvalidArgumentException("Post {$postId} not found");
		}

		return PostDTO::fromEntity($post);
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
		$ctx            = new ContentContext(actorId: $request->actorId, forumId: $request->forumId);
		$processedContent = $this->contentPipeline->processForSave($request->content, $ctx);

		$this->connection->beginTransaction();

		try {
			$topicId = $this->topicRepository->insert($request, $now);

			$postId = $this->postRepository->insert(
				topicId:        $topicId,
				forumId:        $request->forumId,
				posterId:       $request->actorId,
				posterUsername: $request->actorUsername,
				posterIp:       $request->posterIp,
				content:        $processedContent,
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

		$this->searchIndexer->indexPost($postId, $processedContent, $request->title, $request->forumId);

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
		$ctx              = new ContentContext(actorId: $request->actorId, forumId: $topic->forumId, topicId: $request->topicId);
		$processedContent = $this->contentPipeline->processForSave($request->content, $ctx);

		$this->connection->beginTransaction();

		try {
			$postId = $this->postRepository->insert(
				topicId:        $request->topicId,
				forumId:        $topic->forumId,
				posterId:       $request->actorId,
				posterUsername: $request->actorUsername,
				posterIp:       $request->posterIp,
				content:        $processedContent,
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

		$this->searchIndexer->indexPost($postId, $processedContent, 'Re: ' . $topic->title, $topic->forumId);

		return new DomainEventCollection([
			new PostCreatedEvent(entityId: $postId, actorId: $request->actorId),
		]);
	}

	public function updateTopic(UpdateTopicRequest $request): DomainEventCollection
	{
		$topic = $this->topicRepository->findById($request->topicId);

		if ($topic === null || $topic->visibility !== 1) {
			throw new \InvalidArgumentException("Topic {$request->topicId} not found");
		}

		if ($topic->posterId !== $request->actorId) {
			throw new \RuntimeException('Forbidden', 403);
		}

		$title = trim($request->title);

		if ($title === '') {
			throw new \InvalidArgumentException('Title must not be empty');
		}

		$this->topicRepository->updateTitle($request->topicId, $title);

		return new DomainEventCollection([
			new TopicUpdatedEvent(entityId: $request->topicId, actorId: $request->actorId),
		]);
	}

	public function updatePost(UpdatePostRequest $request): DomainEventCollection
	{
		$post = $this->postRepository->findById($request->postId);

		if ($post === null || $post->visibility !== 1) {
			throw new \InvalidArgumentException("Post {$request->postId} not found");
		}

		if ($post->posterId !== $request->actorId) {
			throw new \RuntimeException('Forbidden', 403);
		}

		$content = trim($request->content);

		if ($content === '') {
			throw new \InvalidArgumentException('Content must not be empty');
		}

		$ctx              = new ContentContext(actorId: $request->actorId, forumId: $post->forumId, topicId: $post->topicId);
		$processedContent = $this->contentPipeline->processForSave($content, $ctx);

		$this->postRepository->updateContent($request->postId, $processedContent);

		return new DomainEventCollection([
			new PostUpdatedEvent(entityId: $request->postId, actorId: $request->actorId),
		]);
	}

	public function deleteTopic(int $topicId, int $actorId): DomainEventCollection
	{
		$topic = $this->topicRepository->findById($topicId);

		if ($topic === null || $topic->visibility !== 1) {
			throw new \InvalidArgumentException("Topic {$topicId} not found");
		}

		if ($topic->posterId !== $actorId) {
			throw new \RuntimeException('Forbidden', 403);
		}

		$this->topicRepository->softDelete($topicId);

		return new DomainEventCollection([
			new TopicDeletedEvent(entityId: $topicId, actorId: $actorId),
		]);
	}

	public function deletePost(int $postId, int $actorId): DomainEventCollection
	{
		$post = $this->postRepository->findById($postId);

		if ($post === null || $post->visibility !== 1) {
			throw new \InvalidArgumentException("Post {$postId} not found");
		}

		if ($post->posterId !== $actorId) {
			throw new \RuntimeException('Forbidden', 403);
		}

		$this->connection->beginTransaction();

		try {
			$this->postRepository->softDelete($postId);
			$this->topicRepository->decrementPostCount($post->topicId);
			$this->connection->commit();
		} catch (\Throwable $e) {
			if ($this->connection->isTransactionActive()) {
				$this->connection->rollBack();
			}

			throw new \RuntimeException('Failed to delete post', previous: $e);
		}

		return new DomainEventCollection([
			new PostDeletedEvent(entityId: $postId, actorId: $actorId),
		]);
	}
}
