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

namespace phpbb\Tests\search\Driver;

use phpbb\api\DTO\PaginationContext;
use phpbb\search\Driver\LikeDriver;
use phpbb\search\DTO\SearchQuery;
use phpbb\Tests\Integration\IntegrationTestCase;
use phpbb\user\DTO\PaginatedResult;
use PHPUnit\Framework\Attributes\Test;

final class LikeDriverTest extends IntegrationTestCase
{
	private LikeDriver $driver;

	protected function setUpSchema(): void
	{
		$this->connection->executeStatement('
			CREATE TABLE phpbb_forums (
				forum_id   INTEGER PRIMARY KEY AUTOINCREMENT,
				forum_name TEXT    NOT NULL DEFAULT \'\',
				forum_desc TEXT    NOT NULL DEFAULT \'\'
			)
		');

		$this->connection->executeStatement('
			CREATE TABLE phpbb_topics (
				topic_id    INTEGER PRIMARY KEY AUTOINCREMENT,
				forum_id    INTEGER NOT NULL DEFAULT 0,
				topic_title TEXT    NOT NULL DEFAULT \'\'
			)
		');

		$this->connection->executeStatement('
			CREATE TABLE phpbb_posts (
				post_id         INTEGER PRIMARY KEY AUTOINCREMENT,
				topic_id        INTEGER NOT NULL DEFAULT 0,
				forum_id        INTEGER NOT NULL DEFAULT 0,
				poster_id       INTEGER NOT NULL DEFAULT 0,
				post_text       TEXT    NOT NULL DEFAULT \'\',
				post_subject    TEXT    NOT NULL DEFAULT \'\',
				post_time       INTEGER NOT NULL DEFAULT 0,
				post_visibility INTEGER NOT NULL DEFAULT 1
			)
		');

		$this->driver = new LikeDriver($this->connection);
	}

	private function insertForum(int $id, string $name = 'General', string $desc = ''): void
	{
		$this->connection->executeStatement(
			'INSERT INTO phpbb_forums (forum_id, forum_name, forum_desc) VALUES (:id, :name, :desc)',
			['id' => $id, 'name' => $name, 'desc' => $desc],
		);
	}

	private function insertTopic(int $id, int $forumId, string $title = 'Test Topic'): void
	{
		$this->connection->executeStatement(
			'INSERT INTO phpbb_topics (topic_id, forum_id, topic_title) VALUES (:id, :forumId, :title)',
			['id' => $id, 'forumId' => $forumId, 'title' => $title],
		);
	}

	private function insertPost(array $overrides = []): int
	{
		$defaults = [
			'topic_id'        => 1,
			'forum_id'        => 1,
			'poster_id'       => 1,
			'post_text'       => 'Default post text',
			'post_subject'    => 'Default subject',
			'post_time'       => 1000,
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
	public function it_applies_like_pattern_to_query(): void
	{
		// Arrange
		$this->insertForum(1, 'General');
		$this->insertTopic(1, 1, 'Test Topic');
		$this->insertPost(['post_text' => 'Hello searchable world', 'topic_id' => 1, 'forum_id' => 1]);
		$this->insertPost(['post_text' => 'Something unrelated', 'topic_id' => 1, 'forum_id' => 1]);

		$ctx = new PaginationContext(page: 1, perPage: 25);

		// Act
		$result = $this->driver->search(new SearchQuery(keywords: 'searchable'), $ctx);

		// Assert
		$this->assertInstanceOf(PaginatedResult::class, $result);
		$this->assertSame(1, $result->total);
		$this->assertCount(1, $result->items);
		$this->assertStringContainsString('searchable', $result->items[0]->excerpt);
	}

	#[Test]
	public function it_adds_forum_id_filter_when_provided(): void
	{
		// Arrange
		$this->insertForum(1, 'Forum One');
		$this->insertForum(2, 'Forum Two');
		$this->insertTopic(1, 1, 'Topic One');
		$this->insertTopic(2, 2, 'Topic Two');
		$this->insertPost(['post_text' => 'Hello world', 'forum_id' => 1, 'topic_id' => 1]);
		$this->insertPost(['post_text' => 'Hello world', 'forum_id' => 2, 'topic_id' => 2]);

		$ctx = new PaginationContext(page: 1, perPage: 25);

		// Act
		$result = $this->driver->search(new SearchQuery(keywords: 'Hello', forumId: 1), $ctx);

		// Assert
		$this->assertSame(1, $result->total);
		$this->assertSame(1, $result->items[0]->forumId);
	}

	#[Test]
	public function it_omits_forum_id_filter_when_null(): void
	{
		// Arrange
		$this->insertForum(1, 'Forum One');
		$this->insertForum(2, 'Forum Two');
		$this->insertTopic(1, 1, 'Topic One');
		$this->insertTopic(2, 2, 'Topic Two');
		$this->insertPost(['post_text' => 'Hello world', 'forum_id' => 1, 'topic_id' => 1]);
		$this->insertPost(['post_text' => 'Hello world', 'forum_id' => 2, 'topic_id' => 2]);

		$ctx = new PaginationContext(page: 1, perPage: 25);

		// Act
		$result = $this->driver->search(new SearchQuery(keywords: 'Hello'), $ctx);

		// Assert
		$this->assertSame(2, $result->total);
	}

	#[Test]
	public function it_always_filters_by_post_visibility_1(): void
	{
		// Arrange
		$this->insertForum(1, 'General');
		$this->insertTopic(1, 1, 'Test Topic');
		$this->insertPost(['post_text' => 'Visible post', 'post_visibility' => 1]);
		$this->insertPost(['post_text' => 'Visible but hidden', 'post_visibility' => 0]);

		$ctx = new PaginationContext(page: 1, perPage: 25);

		// Act
		$result = $this->driver->search(new SearchQuery(keywords: 'Visible'), $ctx);

		// Assert
		$this->assertSame(1, $result->total);
		$this->assertSame('Visible post', $result->items[0]->excerpt);
	}
}
