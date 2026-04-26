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
use phpbb\search\Driver\NativeDriver;
use phpbb\search\DTO\SearchQuery;
use phpbb\search\Tokenizer\NativeTokenizer;
use phpbb\Tests\Integration\IntegrationTestCase;
use phpbb\user\DTO\PaginatedResult;
use PHPUnit\Framework\Attributes\Test;

final class NativeDriverTest extends IntegrationTestCase
{
	private NativeDriver $driver;
	private LikeDriver $fallback;

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

		$this->connection->executeStatement('
			CREATE TABLE phpbb_search_wordlist (
				word_id   INTEGER PRIMARY KEY AUTOINCREMENT,
				word_text TEXT    NOT NULL UNIQUE
			)
		');

		$this->connection->executeStatement('
			CREATE TABLE phpbb_search_wordmatch (
				word_id     INTEGER NOT NULL DEFAULT 0,
				post_id     INTEGER NOT NULL DEFAULT 0,
				title_match INTEGER NOT NULL DEFAULT 0
			)
		');

		$this->fallback = new LikeDriver($this->connection);
		$this->driver   = new NativeDriver($this->connection, new NativeTokenizer(), $this->fallback);
	}

	private function insertForum(int $id, string $name = 'General'): void
	{
		$this->connection->executeStatement(
			'INSERT INTO phpbb_forums (forum_id, forum_name, forum_desc) VALUES (:id, :name, :desc)',
			['id' => $id, 'name' => $name, 'desc' => ''],
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

	private function indexWord(string $wordText): int
	{
		$this->connection->executeStatement(
			'INSERT INTO phpbb_search_wordlist (word_text) VALUES (:word)',
			['word' => $wordText],
		);

		return (int) $this->connection->lastInsertId();
	}

	private function indexWordInPost(int $wordId, int $postId, int $titleMatch = 0): void
	{
		$this->connection->executeStatement(
			'INSERT INTO phpbb_search_wordmatch (word_id, post_id, title_match) VALUES (:wordId, :postId, :titleMatch)',
			['wordId' => $wordId, 'postId' => $postId, 'titleMatch' => $titleMatch],
		);
	}

	#[Test]
	public function it_finds_posts_by_indexed_word(): void
	{
		$this->insertForum(1);
		$this->insertTopic(1, 1);
		$postId = $this->insertPost(['post_text' => 'hello world content']);
		$wordId = $this->indexWord('hello');
		$this->indexWordInPost($wordId, $postId);

		$ctx    = new PaginationContext(page: 1, perPage: 25);
		$result = $this->driver->search(new SearchQuery(keywords: 'hello'), $ctx);

		$this->assertInstanceOf(PaginatedResult::class, $result);
		$this->assertSame(1, $result->total);
		$this->assertSame($postId, $result->items[0]->postId);
	}

	#[Test]
	public function it_falls_back_to_like_driver_when_no_tokens_pass_length_filter(): void
	{
		$this->insertForum(1);
		$this->insertTopic(1, 1);
		$this->insertPost(['post_text' => 'hi yo content']);

		$ctx    = new PaginationContext(page: 1, perPage: 25);
		$result = $this->driver->search(new SearchQuery(keywords: 'hi yo'), $ctx);

		$this->assertInstanceOf(PaginatedResult::class, $result);
	}

	#[Test]
	public function it_falls_back_when_word_not_in_index(): void
	{
		$this->insertForum(1);
		$this->insertTopic(1, 1);
		$this->insertPost(['post_text' => 'hello world content']);

		$ctx    = new PaginationContext(page: 1, perPage: 25);
		$result = $this->driver->search(new SearchQuery(keywords: 'hello'), $ctx);

		$this->assertInstanceOf(PaginatedResult::class, $result);
	}

	#[Test]
	public function it_excludes_posts_matching_must_not_word(): void
	{
		$this->insertForum(1);
		$this->insertTopic(1, 1);
		$post1 = $this->insertPost(['post_text' => 'hello world clean']);
		$post2 = $this->insertPost(['post_text' => 'hello world spam']);
		$wHello = $this->indexWord('hello');
		$wSpam  = $this->indexWord('spam');
		$this->indexWordInPost($wHello, $post1);
		$this->indexWordInPost($wHello, $post2);
		$this->indexWordInPost($wSpam, $post2);

		$ctx    = new PaginationContext(page: 1, perPage: 25);
		$result = $this->driver->search(new SearchQuery(keywords: 'hello -spam'), $ctx);

		$this->assertSame(1, $result->total);
		$this->assertSame($post1, $result->items[0]->postId);
	}

	#[Test]
	public function it_returns_empty_when_must_word_matches_no_posts(): void
	{
		$this->insertForum(1);
		$this->insertTopic(1, 1);
		$this->insertPost(['post_text' => 'some content here']);
		$this->indexWord('hello');

		$ctx    = new PaginationContext(page: 1, perPage: 25);
		$result = $this->driver->search(new SearchQuery(keywords: 'hello'), $ctx);

		$this->assertSame(0, $result->total);
	}

	#[Test]
	public function it_applies_forum_id_filter(): void
	{
		$this->insertForum(1, 'Forum One');
		$this->insertForum(2, 'Forum Two');
		$this->insertTopic(1, 1);
		$this->insertTopic(2, 2);
		$post1 = $this->insertPost(['forum_id' => 1, 'topic_id' => 1]);
		$post2 = $this->insertPost(['forum_id' => 2, 'topic_id' => 2]);
		$wHello = $this->indexWord('hello');
		$this->indexWordInPost($wHello, $post1);
		$this->indexWordInPost($wHello, $post2);

		$ctx    = new PaginationContext(page: 1, perPage: 25);
		$result = $this->driver->search(new SearchQuery(keywords: 'hello', forumId: 1), $ctx);

		$this->assertSame(1, $result->total);
		$this->assertSame(1, $result->items[0]->forumId);
	}
}
