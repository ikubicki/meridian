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

namespace phpbb\Tests\threads\Repository;

use phpbb\api\DTO\PaginationContext;
use phpbb\Tests\Integration\IntegrationTestCase;
use phpbb\threads\DTO\CreateTopicRequest;
use phpbb\threads\DTO\TopicDTO;
use phpbb\threads\Entity\Topic;
use phpbb\threads\Repository\DbalTopicRepository;
use phpbb\user\DTO\PaginatedResult;
use PHPUnit\Framework\Attributes\Test;

class DbalTopicRepositoryTest extends IntegrationTestCase
{
	private DbalTopicRepository $repository;

	protected function setUpSchema(): void
	{
		$this->connection->executeStatement(
			'CREATE TABLE phpbb_topics (
                topic_id                INTEGER PRIMARY KEY AUTOINCREMENT,
                forum_id                INTEGER NOT NULL DEFAULT 0,
                topic_title             TEXT    NOT NULL DEFAULT \'\',
                topic_poster            INTEGER NOT NULL DEFAULT 0,
                topic_time              INTEGER NOT NULL DEFAULT 0,
                topic_posts_approved    INTEGER NOT NULL DEFAULT 0,
                topic_last_post_time    INTEGER NOT NULL DEFAULT 0,
                topic_last_poster_name  TEXT    NOT NULL DEFAULT \'\',
                topic_last_poster_id    INTEGER NOT NULL DEFAULT 0,
                topic_last_poster_colour TEXT   NOT NULL DEFAULT \'\',
                topic_first_post_id     INTEGER NOT NULL DEFAULT 0,
                topic_last_post_id      INTEGER NOT NULL DEFAULT 0,
                topic_visibility        INTEGER NOT NULL DEFAULT 1,
                topic_first_poster_name TEXT    NOT NULL DEFAULT \'\',
                topic_first_poster_colour TEXT  NOT NULL DEFAULT \'\',
                topic_last_post_subject TEXT    NOT NULL DEFAULT \'\'
            )',
		);

		$this->repository = new DbalTopicRepository($this->connection);
	}

	private function insertTopic(array $overrides = []): int
	{
		$defaults = [
			'forum_id'                => 1,
			'topic_title'             => 'Test Topic',
			'topic_poster'            => 10,
			'topic_time'              => 1000000,
			'topic_posts_approved'    => 1,
			'topic_last_post_time'    => 1000001,
			'topic_last_poster_name'  => 'testuser',
			'topic_last_poster_id'    => 10,
			'topic_last_poster_colour' => 'ff0000',
			'topic_first_post_id'     => 0,
			'topic_last_post_id'      => 0,
			'topic_visibility'        => 1,
			'topic_first_poster_name' => 'testuser',
			'topic_first_poster_colour' => 'ff0000',
			'topic_last_post_subject' => 'Test Topic',
		];

		$row          = array_merge($defaults, $overrides);
		$columns      = implode(', ', array_keys($row));
		$placeholders = implode(', ', array_map(fn ($k) => ':' . $k, array_keys($row)));

		$this->connection->executeStatement(
			"INSERT INTO phpbb_topics ($columns) VALUES ($placeholders)",
			$row,
		);

		return (int) $this->connection->lastInsertId();
	}

	#[Test]
	public function findById_unknownId_returnsNull(): void
	{
		// Arrange — empty table

		// Act
		$result = $this->repository->findById(99999);

		// Assert
		$this->assertNull($result);
	}

	#[Test]
	public function findById_existingRow_returnsHydratedTopicWithAllProperties(): void
	{
		// Arrange
		$id = $this->insertTopic([
			'forum_id'                => 5,
			'topic_title'             => 'Hello World',
			'topic_poster'            => 42,
			'topic_time'              => 1700000000,
			'topic_posts_approved'    => 3,
			'topic_last_post_time'    => 1700001000,
			'topic_last_poster_name'  => 'alice',
			'topic_last_poster_id'    => 42,
			'topic_last_poster_colour' => 'abc123',
			'topic_first_post_id'     => 7,
			'topic_last_post_id'      => 9,
			'topic_visibility'        => 1,
			'topic_first_poster_name' => 'alice',
			'topic_first_poster_colour' => 'abc123',
			'topic_last_post_subject' => 'Hello World',
		]);

		// Act
		$topic = $this->repository->findById($id);

		// Assert
		$this->assertInstanceOf(Topic::class, $topic);
		$this->assertSame($id, $topic->id);
		$this->assertSame(5, $topic->forumId);
		$this->assertSame('Hello World', $topic->title);
		$this->assertSame(42, $topic->posterId);
		$this->assertSame(1700000000, $topic->time);
		$this->assertSame(3, $topic->postsApproved);
		$this->assertSame(1700001000, $topic->lastPostTime);
		$this->assertSame('alice', $topic->lastPosterName);
		$this->assertSame(42, $topic->lastPosterId);
		$this->assertSame('abc123', $topic->lastPosterColour);
		$this->assertSame(7, $topic->firstPostId);
		$this->assertSame(9, $topic->lastPostId);
		$this->assertSame(1, $topic->visibility);
		$this->assertSame('alice', $topic->firstPosterName);
		$this->assertSame('abc123', $topic->firstPosterColour);
	}

	#[Test]
	public function insert_persistsRowAndReturnsAutoIncrementId(): void
	{
		// Arrange
		$request = new CreateTopicRequest(
			forumId: 3,
			title: 'New Topic',
			content: 'body text',
			actorId: 15,
			actorUsername: 'bob',
			actorColour: '00ff00',
			posterIp: '127.0.0.1',
		);
		$now = 1700050000;

		// Act
		$id = $this->repository->insert($request, $now);

		// Assert
		$this->assertGreaterThan(0, $id);
		$topic = $this->repository->findById($id);
		$this->assertNotNull($topic);
		$this->assertSame(3, $topic->forumId);
		$this->assertSame('New Topic', $topic->title);
		$this->assertSame(15, $topic->posterId);
	}

	#[Test]
	public function findByForum_returnsCorrectTotalAndItems(): void
	{
		// Arrange
		$this->insertTopic(['forum_id' => 10, 'topic_last_post_time' => 1000]);
		$this->insertTopic(['forum_id' => 10, 'topic_last_post_time' => 2000]);
		$this->insertTopic(['forum_id' => 10, 'topic_last_post_time' => 3000]);
		$this->insertTopic(['forum_id' => 99]);

		$ctx = new PaginationContext(page: 1, perPage: 10);

		// Act
		$result = $this->repository->findByForum(10, $ctx);

		// Assert
		$this->assertInstanceOf(PaginatedResult::class, $result);
		$this->assertSame(3, $result->total);
		$this->assertCount(3, $result->items);
	}

	#[Test]
	public function findByForum_excludesInvisibleTopics(): void
	{
		// Arrange
		$this->insertTopic(['forum_id' => 10, 'topic_visibility' => 1]);
		$this->insertTopic(['forum_id' => 10, 'topic_visibility' => 0]);
		$this->insertTopic(['forum_id' => 10, 'topic_visibility' => 2]);

		$ctx = new PaginationContext(page: 1, perPage: 10);

		// Act
		$result = $this->repository->findByForum(10, $ctx);

		// Assert
		$this->assertSame(1, $result->total);
		$this->assertCount(1, $result->items);
		$this->assertInstanceOf(TopicDTO::class, $result->items[0]);
	}

	#[Test]
	public function findByForumRespectsOffsetForSecondPage(): void
	{
		// Arrange
		$this->insertTopic(['forum_id' => 11, 'topic_last_post_time' => 500]);
		$this->insertTopic(['forum_id' => 11, 'topic_last_post_time' => 400]);
		$this->insertTopic(['forum_id' => 11, 'topic_last_post_time' => 300]);

		$ctx = new PaginationContext(page: 2, perPage: 2);

		// Act
		$result = $this->repository->findByForum(11, $ctx);

		// Assert
		$this->assertSame(3, $result->total);
		$this->assertCount(1, $result->items);
		$this->assertSame(300, $result->items[0]->lastPostTime);
	}

	#[Test]
	public function updateFirstLastPost_writesCorrectColumns(): void
	{
		// Arrange
		$topicId = $this->insertTopic([
			'topic_first_post_id' => 0,
			'topic_last_post_id'  => 0,
		]);

		// Act
		$this->repository->updateFirstLastPost($topicId, 42);

		// Assert
		$topic = $this->repository->findById($topicId);
		$this->assertNotNull($topic);
		$this->assertSame(42, $topic->firstPostId);
		$this->assertSame(42, $topic->lastPostId);
	}

	#[Test]
	public function updateLastPost_writesAllFiveDenormalizationColumns(): void
	{
		// Arrange
		$topicId = $this->insertTopic([
			'topic_last_post_id'      => 0,
			'topic_last_poster_id'    => 0,
			'topic_last_poster_name'  => '',
			'topic_last_poster_colour' => '',
			'topic_last_post_time'    => 0,
		]);

		// Act
		$this->repository->updateLastPost(
			topicId: $topicId,
			postId: 99,
			posterId: 77,
			posterName: 'charlie',
			posterColour: 'ff00ff',
			now: 1700099999,
		);

		// Assert
		$topic = $this->repository->findById($topicId);
		$this->assertNotNull($topic);
		$this->assertSame(99, $topic->lastPostId);
		$this->assertSame(77, $topic->lastPosterId);
		$this->assertSame('charlie', $topic->lastPosterName);
		$this->assertSame('ff00ff', $topic->lastPosterColour);
		$this->assertSame(1700099999, $topic->lastPostTime);
	}
}
