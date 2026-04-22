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
use phpbb\Tests\Integration\IntegrationTestCase;
use phpbb\threads\DTO\CreatePostRequest;
use phpbb\threads\DTO\CreateTopicRequest;
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
}
