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

namespace phpbb\Tests\search\Service;

use phpbb\cache\TagAwareCacheInterface;
use phpbb\search\Service\SearchIndexerService;
use phpbb\search\Tokenizer\NativeTokenizer;
use phpbb\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;

final class SearchIndexerServiceTest extends IntegrationTestCase
{
	private SearchIndexerService $indexer;

	protected function setUpSchema(): void
	{
		$this->connection->executeStatement('
			CREATE TABLE phpbb_search_wordlist (
				word_id    INTEGER PRIMARY KEY AUTOINCREMENT,
				word_text  TEXT    NOT NULL UNIQUE,
				word_count INTEGER NOT NULL DEFAULT 0
			)
		');

		$this->connection->executeStatement('
			CREATE TABLE phpbb_search_wordmatch (
				post_id     INTEGER NOT NULL DEFAULT 0,
				word_id     INTEGER NOT NULL DEFAULT 0,
				title_match INTEGER NOT NULL DEFAULT 0,
				UNIQUE (post_id, word_id, title_match)
			)
		');

		$this->connection->executeStatement('
			CREATE TABLE phpbb_posts (
				post_id         INTEGER PRIMARY KEY AUTOINCREMENT,
				forum_id        INTEGER NOT NULL DEFAULT 0,
				post_text       TEXT    NOT NULL DEFAULT \'\',
				post_subject    TEXT    NOT NULL DEFAULT \'\',
				post_visibility INTEGER NOT NULL DEFAULT 1
			)
		');

		$this->indexer = new SearchIndexerService(
			$this->connection,
			new NativeTokenizer(),
			new NullLogger(),
			$this->createMock(TagAwareCacheInterface::class),
		);
	}

	private function insertPost(string $text, string $subject, int $forumId = 1, int $visibility = 1): int
	{
		$this->connection->executeStatement(
			'INSERT INTO phpbb_posts (forum_id, post_text, post_subject, post_visibility) VALUES (?, ?, ?, ?)',
			[$forumId, $text, $subject, $visibility],
		);

		return (int) $this->connection->lastInsertId();
	}

	#[Test]
	public function it_indexes_post_creates_wordlist_entries(): void
	{
		$this->indexer->indexPost(1, 'hello world content', 'interesting subject', 1);

		$wordTexts = $this->connection->fetchFirstColumn(
			'SELECT word_text FROM phpbb_search_wordlist ORDER BY word_text',
		);

		$this->assertContains('hello', $wordTexts);
		$this->assertContains('world', $wordTexts);
		$this->assertContains('content', $wordTexts);
		$this->assertContains('interesting', $wordTexts);
		$this->assertContains('subject', $wordTexts);
	}

	#[Test]
	public function it_indexes_post_creates_wordmatch_entries(): void
	{
		$this->indexer->indexPost(42, 'hello world content', 'interesting subject', 1);

		$bodyMatches = $this->connection->fetchFirstColumn(
			'SELECT word_id FROM phpbb_search_wordmatch WHERE post_id = 42 AND title_match = 0',
		);
		$this->assertNotEmpty($bodyMatches);

		$titleMatches = $this->connection->fetchFirstColumn(
			'SELECT word_id FROM phpbb_search_wordmatch WHERE post_id = 42 AND title_match = 1',
		);
		$this->assertNotEmpty($titleMatches);
	}

	#[Test]
	public function it_deindexes_post_removes_wordmatch_entries(): void
	{
		$this->indexer->indexPost(10, 'hello world content', 'test subject', 1);

		$this->assertNotEmpty(
			$this->connection->fetchFirstColumn(
				'SELECT post_id FROM phpbb_search_wordmatch WHERE post_id = 10',
			),
		);

		$this->indexer->deindexPost(10);

		$this->assertEmpty(
			$this->connection->fetchFirstColumn(
				'SELECT post_id FROM phpbb_search_wordmatch WHERE post_id = 10',
			),
		);
	}

	#[Test]
	public function it_deindexes_post_decrements_word_count(): void
	{
		$this->indexer->indexPost(5, 'remarkable discovery found', 'test subject', 1);

		$countBefore = (int) $this->connection->fetchOne(
			"SELECT word_count FROM phpbb_search_wordlist WHERE word_text = 'remarkable'",
		);

		$this->indexer->indexPost(6, 'remarkable event happened', 'other subject', 1);
		$this->indexer->deindexPost(5);

		$countAfter = (int) $this->connection->fetchOne(
			"SELECT word_count FROM phpbb_search_wordlist WHERE word_text = 'remarkable'",
		);

		$this->assertSame($countBefore, $countAfter);
	}

	#[Test]
	public function it_deindexes_post_deletes_words_with_zero_count(): void
	{
		$this->indexer->indexPost(7, 'uniqueword discovery', 'test subject', 1);

		$this->assertNotFalse(
			$this->connection->fetchOne(
				"SELECT word_id FROM phpbb_search_wordlist WHERE word_text = 'uniqueword'",
			),
		);

		$this->indexer->deindexPost(7);

		$this->assertFalse(
			$this->connection->fetchOne(
				"SELECT word_id FROM phpbb_search_wordlist WHERE word_text = 'uniqueword'",
			),
		);
	}

	#[Test]
	public function it_reindex_all_rebuilds_index_from_scratch(): void
	{
		$this->insertPost('hello world content', 'first topic', 1);
		$this->insertPost('another post text', 'second topic', 1);
		$this->insertPost('hidden post text', 'hidden topic', 1, 0);

		$this->indexer->reindexAll();

		$wordCount = (int) $this->connection->fetchOne(
			'SELECT COUNT(*) FROM phpbb_search_wordlist',
		);
		$this->assertGreaterThan(0, $wordCount);

		$matchCount = (int) $this->connection->fetchOne(
			'SELECT COUNT(*) FROM phpbb_search_wordmatch',
		);
		$this->assertGreaterThan(0, $matchCount);

		$hiddenPostMatches = (int) $this->connection->fetchOne(
			'SELECT COUNT(*) FROM phpbb_search_wordmatch WHERE post_id = 3',
		);
		$this->assertSame(0, $hiddenPostMatches);
	}
}
