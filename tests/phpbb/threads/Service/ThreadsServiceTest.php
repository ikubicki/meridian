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

namespace phpbb\Tests\threads\Service;

use phpbb\api\DTO\PaginationContext;
use phpbb\search\Service\NullSearchIndexer;
use phpbb\Tests\Integration\IntegrationTestCase;
use phpbb\threads\DTO\CreatePostRequest;
use phpbb\threads\DTO\CreateTopicRequest;
use phpbb\threads\DTO\UpdatePostRequest;
use phpbb\threads\DTO\UpdateTopicRequest;
use phpbb\threads\Repository\DbalPostRepository;
use phpbb\threads\Repository\DbalTopicRepository;
use phpbb\threads\ThreadsService;
use PHPUnit\Framework\Attributes\Test;

class ThreadsServiceTest extends IntegrationTestCase
{
	private ThreadsService $service;
	private DbalTopicRepository $topicRepository;
	private DbalPostRepository $postRepository;

	protected function setUpSchema(): void
	{
		$this->connection->executeStatement(
			'CREATE TABLE phpbb_topics (
				topic_id                  INTEGER PRIMARY KEY AUTOINCREMENT,
				forum_id                  INTEGER NOT NULL DEFAULT 0,
				topic_title               TEXT    NOT NULL DEFAULT "",
				topic_poster              INTEGER NOT NULL DEFAULT 0,
				topic_time                INTEGER NOT NULL DEFAULT 0,
				topic_posts_approved      INTEGER NOT NULL DEFAULT 0,
				topic_last_post_time      INTEGER NOT NULL DEFAULT 0,
				topic_last_poster_name    TEXT    NOT NULL DEFAULT "",
				topic_last_poster_id      INTEGER NOT NULL DEFAULT 0,
				topic_last_poster_colour  TEXT    NOT NULL DEFAULT "",
				topic_first_post_id       INTEGER NOT NULL DEFAULT 0,
				topic_last_post_id        INTEGER NOT NULL DEFAULT 0,
				topic_visibility          INTEGER NOT NULL DEFAULT 1,
				topic_first_poster_name   TEXT    NOT NULL DEFAULT "",
				topic_first_poster_colour TEXT    NOT NULL DEFAULT "",
				topic_last_post_subject   TEXT    NOT NULL DEFAULT ""
			)',
		);

		$this->connection->executeStatement(
			'CREATE TABLE phpbb_posts (
				post_id         INTEGER PRIMARY KEY AUTOINCREMENT,
				topic_id        INTEGER NOT NULL DEFAULT 0,
				forum_id        INTEGER NOT NULL DEFAULT 0,
				poster_id       INTEGER NOT NULL DEFAULT 0,
				post_time       INTEGER NOT NULL DEFAULT 0,
				post_text       TEXT    NOT NULL DEFAULT "",
				post_subject    TEXT    NOT NULL DEFAULT "",
				post_username   TEXT    NOT NULL DEFAULT "",
				poster_ip       TEXT    NOT NULL DEFAULT "",
				post_visibility INTEGER NOT NULL DEFAULT 1
			)',
		);

		$this->topicRepository = new DbalTopicRepository($this->connection);
		$this->postRepository  = new DbalPostRepository($this->connection);
		$this->service         = new ThreadsService(
			topicRepository: $this->topicRepository,
			postRepository: $this->postRepository,
			connection: $this->connection,
			searchIndexer: new NullSearchIndexer(),
		);
	}

	private function insertTopic(array $overrides = []): int
	{
		$defaults = [
			'forum_id'                  => 1,
			'topic_title'               => 'Test Topic',
			'topic_poster'              => 10,
			'topic_time'                => 1000000,
			'topic_posts_approved'      => 1,
			'topic_last_post_time'      => 1000001,
			'topic_last_poster_name'    => 'testuser',
			'topic_last_poster_id'      => 10,
			'topic_last_poster_colour'  => 'ff0000',
			'topic_first_post_id'       => 0,
			'topic_last_post_id'        => 0,
			'topic_visibility'          => 1,
			'topic_first_poster_name'   => 'testuser',
			'topic_first_poster_colour' => 'ff0000',
			'topic_last_post_subject'   => 'Test Topic',
		];

		$row          = array_merge($defaults, $overrides);
		$columns      = implode(', ', array_keys($row));
		$placeholders = implode(', ', array_map(static fn (string $k) => ':' . $k, array_keys($row)));

		$this->connection->executeStatement(
			'INSERT INTO phpbb_topics (' . $columns . ') VALUES (' . $placeholders . ')',
			$row,
		);

		return (int) $this->connection->lastInsertId();
	}

	private function insertPost(array $overrides = []): int
	{
		$defaults = [
			'topic_id'       => 1,
			'forum_id'       => 1,
			'poster_id'      => 10,
			'post_time'      => 1000000,
			'post_text'      => 'Default content',
			'post_subject'   => 'Test Subject',
			'post_username'  => 'testuser',
			'poster_ip'      => '127.0.0.1',
			'post_visibility' => 1,
		];

		$row          = array_merge($defaults, $overrides);
		$columns      = implode(', ', array_keys($row));
		$placeholders = implode(', ', array_map(static fn (string $k) => ':' . $k, array_keys($row)));

		$this->connection->executeStatement(
			'INSERT INTO phpbb_posts (' . $columns . ') VALUES (' . $placeholders . ')',
			$row,
		);

		return (int) $this->connection->lastInsertId();
	}

	#[Test]
	public function getTopicThrowsForUnknownTopicId(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->service->getTopic(99999);
	}

	#[Test]
	public function getTopicThrowsForInvisibleTopic(): void
	{
		$topicId = $this->insertTopic(['topic_visibility' => 0]);

		$this->expectException(\InvalidArgumentException::class);
		$this->service->getTopic($topicId);
	}

	#[Test]
	public function listTopicsReturnsPaginatedResult(): void
	{
		// Arrange
		$this->insertTopic(['forum_id' => 5]);
		$this->insertTopic(['forum_id' => 5]);

		// Act
		$result = $this->service->listTopics(5, new PaginationContext(page: 1, perPage: 25));

		// Assert
		$this->assertSame(2, $result->total);
		$this->assertCount(2, $result->items);
	}

	#[Test]
	public function createTopicInsertsTopicAndFirstPostAndReturnsTopicCreatedEvent(): void
	{
		// Arrange
		$request = new CreateTopicRequest(
			forumId: 7,
			title: 'Welcome',
			content: 'Hello everyone',
			actorId: 42,
			actorUsername: 'alice',
			actorColour: '00ff00',
			posterIp: '127.0.0.1',
		);

		// Act
		$events = $this->service->createTopic($request);
		$event  = $events->first();

		// Assert
		$this->assertNotNull($event);
		$this->assertSame(42, $event->actorId);

		$topicCount = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM phpbb_topics');
		$postCount  = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM phpbb_posts');
		$this->assertSame(1, $topicCount);
		$this->assertSame(1, $postCount);
	}

	#[Test]
	public function createTopicRollsBackTransactionOnFailure(): void
	{
		// Arrange
		$this->connection->executeStatement('DROP TABLE phpbb_posts');

		$request = new CreateTopicRequest(
			forumId: 1,
			title: 'Rollback',
			content: 'Should fail',
			actorId: 1,
			actorUsername: 'admin',
			actorColour: 'ffffff',
			posterIp: '127.0.0.1',
		);

		// Assert
		$this->expectException(\RuntimeException::class);

		// Act
		try {
			$this->service->createTopic($request);
		} finally {
			$topicCount = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM phpbb_topics');
			$this->assertSame(0, $topicCount);
		}
	}

	#[Test]
	public function createPostThrowsForUnknownTopic(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		$this->service->createPost(new CreatePostRequest(
			topicId: 999,
			content: 'Reply',
			actorId: 20,
			actorUsername: 'bob',
			actorColour: 'abc123',
			posterIp: '127.0.0.1',
		));
	}

	#[Test]
	public function createPostInsertsReplySubjectAndReturnsPostCreatedEvent(): void
	{
		// Arrange
		$topicId = $this->insertTopic([
			'forum_id' => 9,
			'topic_title' => 'Original title',
		]);

		// Act
		$events = $this->service->createPost(new CreatePostRequest(
			topicId: $topicId,
			content: 'Answer',
			actorId: 33,
			actorUsername: 'charlie',
			actorColour: 'ff00ff',
			posterIp: '127.0.0.1',
		));
		$event = $events->first();

		// Assert
		$this->assertNotNull($event);
		$this->assertSame(33, $event->actorId);

		$subject = (string) $this->connection->fetchOne('SELECT post_subject FROM phpbb_posts WHERE post_id = ?', [$event->entityId]);
		$this->assertSame('Re: Original title', $subject);
	}

	#[Test]
	public function createPostPropagatesActorColourToTopicDenormalization(): void
	{
		// Arrange
		$topicId = $this->insertTopic([
			'forum_id' => 2,
			'topic_title' => 'Color test',
			'topic_last_poster_colour' => '',
		]);

		// Act
		$this->service->createPost(new CreatePostRequest(
			topicId: $topicId,
			content: 'Reply with color',
			actorId: 55,
			actorUsername: 'delta',
			actorColour: 'abcdef',
			posterIp: '127.0.0.1',
		));

		// Assert
		$topic = $this->topicRepository->findById($topicId);
		$this->assertNotNull($topic);
		$this->assertSame('abcdef', $topic->lastPosterColour);
	}

	#[Test]
	public function listPostsThrowsForUnknownTopic(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->service->listPosts(404, new PaginationContext(page: 1, perPage: 25));
	}

	// -------------------------------------------------------------------------
	// getPost
	// -------------------------------------------------------------------------

	#[Test]
	public function getPostThrowsForUnknownPost(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->service->getPost(99999);
	}

	#[Test]
	public function getPostThrowsForInvisiblePost(): void
	{
		$topicId = $this->insertTopic();
		$postId  = $this->insertPost(['topic_id' => $topicId, 'post_visibility' => 0]);

		$this->expectException(\InvalidArgumentException::class);
		$this->service->getPost($postId);
	}

	#[Test]
	public function getPostReturnsPostDTO(): void
	{
		$topicId = $this->insertTopic();
		$postId  = $this->insertPost(['topic_id' => $topicId, 'poster_id' => 42, 'post_text' => 'Hello']);

		$dto = $this->service->getPost($postId);

		$this->assertSame($postId, $dto->id);
		$this->assertSame($topicId, $dto->topicId);
		$this->assertSame(42, $dto->authorId);
		$this->assertSame('Hello', $dto->content);
	}

	// -------------------------------------------------------------------------
	// updateTopic
	// -------------------------------------------------------------------------

	#[Test]
	public function updateTopicChangesTitle(): void
	{
		$topicId = $this->insertTopic(['topic_title' => 'Old Title', 'topic_poster' => 10]);

		$events = $this->service->updateTopic(new UpdateTopicRequest(
			topicId: $topicId,
			title:   'New Title',
			actorId: 10,
		));

		$this->assertNotNull($events->first());
		$this->assertSame('New Title', $this->topicRepository->findById($topicId)?->title);
	}

	#[Test]
	public function updateTopicThrowsForUnknownTopic(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		$this->service->updateTopic(new UpdateTopicRequest(
			topicId: 99999,
			title:   'Never',
			actorId: 1,
		));
	}

	#[Test]
	public function updateTopicThrowsForWrongActor(): void
	{
		$topicId = $this->insertTopic(['topic_poster' => 10]);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionCode(403);

		$this->service->updateTopic(new UpdateTopicRequest(
			topicId: $topicId,
			title:   'Hijack',
			actorId: 99,
		));
	}

	#[Test]
	public function updateTopicThrowsForEmptyTitle(): void
	{
		$topicId = $this->insertTopic(['topic_poster' => 10]);

		$this->expectException(\InvalidArgumentException::class);

		$this->service->updateTopic(new UpdateTopicRequest(
			topicId: $topicId,
			title:   '   ',
			actorId: 10,
		));
	}

	// -------------------------------------------------------------------------
	// updatePost
	// -------------------------------------------------------------------------

	#[Test]
	public function updatePostChangesContent(): void
	{
		$topicId = $this->insertTopic();
		$postId  = $this->insertPost(['topic_id' => $topicId, 'poster_id' => 20, 'post_text' => 'Original']);

		$events = $this->service->updatePost(new UpdatePostRequest(
			postId:  $postId,
			content: 'Updated content',
			actorId: 20,
		));

		$this->assertNotNull($events->first());
		$this->assertSame('Updated content', $this->postRepository->findById($postId)?->text);
	}

	#[Test]
	public function updatePostThrowsForWrongActor(): void
	{
		$topicId = $this->insertTopic();
		$postId  = $this->insertPost(['topic_id' => $topicId, 'poster_id' => 20]);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionCode(403);

		$this->service->updatePost(new UpdatePostRequest(
			postId:  $postId,
			content: 'Hijack',
			actorId: 99,
		));
	}

	#[Test]
	public function updatePostThrowsForEmptyContent(): void
	{
		$topicId = $this->insertTopic();
		$postId  = $this->insertPost(['topic_id' => $topicId, 'poster_id' => 20]);

		$this->expectException(\InvalidArgumentException::class);

		$this->service->updatePost(new UpdatePostRequest(
			postId:  $postId,
			content: '   ',
			actorId: 20,
		));
	}

	// -------------------------------------------------------------------------
	// deleteTopic
	// -------------------------------------------------------------------------

	#[Test]
	public function deleteTopicSoftDeletesTopic(): void
	{
		$topicId = $this->insertTopic(['topic_poster' => 10, 'topic_visibility' => 1]);

		$events = $this->service->deleteTopic($topicId, 10);

		$this->assertNotNull($events->first());
		$this->assertSame(0, $this->topicRepository->findById($topicId)?->visibility);
	}

	#[Test]
	public function deleteTopicThrowsForWrongActor(): void
	{
		$topicId = $this->insertTopic(['topic_poster' => 10]);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionCode(403);

		$this->service->deleteTopic($topicId, 99);
	}

	#[Test]
	public function deleteTopicThrowsForUnknownTopic(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->service->deleteTopic(99999, 1);
	}

	// -------------------------------------------------------------------------
	// deletePost
	// -------------------------------------------------------------------------

	#[Test]
	public function deletePostSoftDeletesPostAndDecrementsTopicCount(): void
	{
		$topicId = $this->insertTopic(['topic_poster' => 10, 'topic_posts_approved' => 2]);
		$postId  = $this->insertPost(['topic_id' => $topicId, 'poster_id' => 20]);

		$events = $this->service->deletePost($postId, 20);

		$this->assertNotNull($events->first());
		$this->assertSame(0, $this->postRepository->findById($postId)?->visibility);
		$this->assertSame(1, $this->topicRepository->findById($topicId)?->postsApproved);
	}

	#[Test]
	public function deletePostThrowsForWrongActor(): void
	{
		$topicId = $this->insertTopic();
		$postId  = $this->insertPost(['topic_id' => $topicId, 'poster_id' => 20]);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionCode(403);

		$this->service->deletePost($postId, 99);
	}

	#[Test]
	public function deletePostThrowsForUnknownPost(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->service->deletePost(99999, 1);
	}
}
