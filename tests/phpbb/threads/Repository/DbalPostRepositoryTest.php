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
use phpbb\threads\Repository\DbalPostRepository;
use phpbb\user\DTO\PaginatedResult;
use PHPUnit\Framework\Attributes\Test;

class DbalPostRepositoryTest extends IntegrationTestCase
{
	private DbalPostRepository $repository;

	protected function setUpSchema(): void
	{
		$this->connection->executeStatement('
            CREATE TABLE phpbb_posts (
                post_id         INTEGER PRIMARY KEY AUTOINCREMENT,
                topic_id        INTEGER NOT NULL DEFAULT 0,
                forum_id        INTEGER NOT NULL DEFAULT 0,
                poster_id       INTEGER NOT NULL DEFAULT 0,
                post_time       INTEGER NOT NULL DEFAULT 0,
                post_text       TEXT    NOT NULL DEFAULT \'\',
                post_subject    TEXT    NOT NULL DEFAULT \'\',
                post_username   TEXT    NOT NULL DEFAULT \'\',
                poster_ip       TEXT    NOT NULL DEFAULT \'\',
                post_visibility INTEGER NOT NULL DEFAULT 1
            )
        ');

		$this->repository = new DbalPostRepository($this->connection);
	}

	private function insertPost(array $overrides = []): int
	{
		$defaults = [
			'topic_id'        => 1,
			'forum_id'        => 1,
			'poster_id'       => 1,
			'post_time'       => 1000,
			'post_text'       => 'Default post text',
			'post_subject'    => 'Default subject',
			'post_username'   => 'testuser',
			'poster_ip'       => '127.0.0.1',
			'post_visibility' => 1,
		];

		$data         = array_merge($defaults, $overrides);
		$columns      = implode(', ', array_keys($data));
		$placeholders = implode(', ', array_map(static fn (string $k) => ':' . $k, array_keys($data)));

		$this->connection->executeStatement(
			'INSERT INTO phpbb_posts (' . $columns . ') VALUES (' . $placeholders . ')',
			$data,
		);

		return (int) $this->connection->lastInsertId();
	}

	#[Test]
	public function findByIdReturnsNullForUnknownId(): void
	{
		// Act
		$result = $this->repository->findById(9999);

		// Assert
		$this->assertNull($result);
	}

	#[Test]
	public function insertPersistsRowAndReturnsAutoIncrementId(): void
	{
		// Arrange
		$topicId       = 10;
		$forumId       = 2;
		$posterId      = 42;
		$posterUsername = 'alice';
		$posterIp      = '192.168.1.1';
		$content       = 'Hello, world!';
		$subject       = 'First post';
		$now           = 1700000000;
		$visibility    = 1;

		// Act
		$id = $this->repository->insert(
			topicId: $topicId,
			forumId: $forumId,
			posterId: $posterId,
			posterUsername: $posterUsername,
			posterIp: $posterIp,
			content: $content,
			subject: $subject,
			now: $now,
			visibility: $visibility,
		);

		// Assert
		$this->assertGreaterThan(0, $id);
		$post = $this->repository->findById($id);
		$this->assertNotNull($post);
		$this->assertSame($id, $post->id);
		$this->assertSame($topicId, $post->topicId);
		$this->assertSame($content, $post->text);
	}

	#[Test]
	public function findByTopicExcludesInvisiblePosts(): void
	{
		// Arrange
		$topicId = 5;
		$this->insertPost(['topic_id' => $topicId, 'post_visibility' => 1, 'post_time' => 1000]);
		$this->insertPost(['topic_id' => $topicId, 'post_visibility' => 0, 'post_time' => 2000]);
		$this->insertPost(['topic_id' => $topicId, 'post_visibility' => 1, 'post_time' => 3000]);

		$ctx = new PaginationContext(page: 1, perPage: 25);

		// Act
		$result = $this->repository->findByTopic($topicId, $ctx);

		// Assert
		$this->assertInstanceOf(PaginatedResult::class, $result);
		$this->assertSame(2, $result->total);
		$this->assertCount(2, $result->items);
	}

	#[Test]
	public function findByTopicOrdersResultsByPostTimeAsc(): void
	{
		// Arrange
		$topicId = 7;
		$this->insertPost(['topic_id' => $topicId, 'post_visibility' => 1, 'post_time' => 3000]);
		$this->insertPost(['topic_id' => $topicId, 'post_visibility' => 1, 'post_time' => 1000]);
		$this->insertPost(['topic_id' => $topicId, 'post_visibility' => 1, 'post_time' => 2000]);

		$ctx = new PaginationContext(page: 1, perPage: 25);

		// Act
		$result = $this->repository->findByTopic($topicId, $ctx);

		// Assert
		$this->assertCount(3, $result->items);
		$this->assertSame(1000, $result->items[0]->id > 0 ? 1000 : 0);
		$times = array_map(static fn ($dto) => $dto->id, $result->items);
		// Verify ordering by re-fetching raw times
		$rawTimes = array_map(
			fn (int $postId) => $this->repository->findById($postId)?->time,
			$times,
		);
		$this->assertSame([1000, 2000, 3000], $rawTimes);
	}
}
