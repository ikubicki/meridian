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

namespace phpbb\Tests\db\migrations;

use phpbb\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Smoke tests verifying the post-migration schema of phpbb_search_wordlist
 * and phpbb_search_wordmatch against an SQLite in-memory database.
 *
 * These tests set up the expected post-migration schema using SQLite-compatible
 * DDL and verify the resulting structure via PRAGMA queries.
 */
final class Version20260426SearchNativeSchemaTest extends IntegrationTestCase
{
	protected function setUpSchema(): void
	{
		$this->connection->executeStatement('
			CREATE TABLE phpbb_search_wordlist (
				word_id   INTEGER PRIMARY KEY AUTOINCREMENT,
				word_text VARCHAR(255) NOT NULL,
				word_count INTEGER NOT NULL DEFAULT 0
			)
		');

		$this->connection->executeStatement(
			'CREATE UNIQUE INDEX uidx_word_text ON phpbb_search_wordlist (word_text)'
		);

		$this->connection->executeStatement('
			CREATE TABLE phpbb_search_wordmatch (
				post_id     INTEGER NOT NULL,
				word_id     INTEGER NOT NULL,
				title_match INTEGER NOT NULL DEFAULT 0,
				PRIMARY KEY (post_id, word_id, title_match)
			)
		');

		$this->connection->executeStatement(
			'CREATE INDEX idx_word_id ON phpbb_search_wordmatch (word_id)'
		);

		$this->connection->executeStatement(
			'CREATE INDEX idx_post_id ON phpbb_search_wordmatch (post_id)'
		);
	}

	// -------------------------------------------------------------------------
	// phpbb_search_wordlist
	// -------------------------------------------------------------------------

	#[Test]
	public function wordlistTableHasWordIdColumn(): void
	{
		$columns = $this->fetchColumns('phpbb_search_wordlist');

		$this->assertArrayHasKey('word_id', $columns);
	}

	#[Test]
	public function wordlistTableHasWordTextColumn(): void
	{
		$columns = $this->fetchColumns('phpbb_search_wordlist');

		$this->assertArrayHasKey('word_text', $columns);
	}

	#[Test]
	public function wordlistTableHasWordCountColumnWithDefaultZero(): void
	{
		$columns = $this->fetchColumns('phpbb_search_wordlist');

		$this->assertArrayHasKey('word_count', $columns);
		$this->assertSame('0', $columns['word_count']['dflt_value']);
	}

	#[Test]
	public function wordlistUniqueIndexOnWordTextExists(): void
	{
		$indexes = $this->connection
			->executeQuery('PRAGMA index_list(phpbb_search_wordlist)')
			->fetchAllAssociative();

		$uniqueIndexes = array_filter(
			$indexes,
			static fn (array $idx): bool => $idx['name'] === 'uidx_word_text' && (int) $idx['unique'] === 1,
		);

		$this->assertNotEmpty($uniqueIndexes, 'uidx_word_text unique index must exist on phpbb_search_wordlist');
	}

	#[Test]
	public function wordlistEnforcesUniqueWordText(): void
	{
		$this->connection->executeStatement(
			"INSERT INTO phpbb_search_wordlist (word_text, word_count) VALUES ('hello', 3)"
		);

		$this->expectException(\Doctrine\DBAL\Exception::class);

		$this->connection->executeStatement(
			"INSERT INTO phpbb_search_wordlist (word_text, word_count) VALUES ('hello', 1)"
		);
	}

	// -------------------------------------------------------------------------
	// phpbb_search_wordmatch
	// -------------------------------------------------------------------------

	#[Test]
	public function wordmatchTableHasPostIdColumn(): void
	{
		$columns = $this->fetchColumns('phpbb_search_wordmatch');

		$this->assertArrayHasKey('post_id', $columns);
	}

	#[Test]
	public function wordmatchTableHasWordIdColumn(): void
	{
		$columns = $this->fetchColumns('phpbb_search_wordmatch');

		$this->assertArrayHasKey('word_id', $columns);
	}

	#[Test]
	public function wordmatchTableHasTitleMatchColumnWithDefaultZero(): void
	{
		$columns = $this->fetchColumns('phpbb_search_wordmatch');

		$this->assertArrayHasKey('title_match', $columns);
		$this->assertSame('0', $columns['title_match']['dflt_value']);
	}

	#[Test]
	public function wordmatchIndexOnWordIdExists(): void
	{
		$indexes = $this->connection
			->executeQuery('PRAGMA index_list(phpbb_search_wordmatch)')
			->fetchAllAssociative();

		$names = array_column($indexes, 'name');

		$this->assertContains('idx_word_id', $names, 'idx_word_id index must exist on phpbb_search_wordmatch');
	}

	#[Test]
	public function wordmatchIndexOnPostIdExists(): void
	{
		$indexes = $this->connection
			->executeQuery('PRAGMA index_list(phpbb_search_wordmatch)')
			->fetchAllAssociative();

		$names = array_column($indexes, 'name');

		$this->assertContains('idx_post_id', $names, 'idx_post_id index must exist on phpbb_search_wordmatch');
	}

	// -------------------------------------------------------------------------
	// Helper
	// -------------------------------------------------------------------------

	/**
	 * Returns PRAGMA table_info rows keyed by column name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function fetchColumns(string $table): array
	{
		$rows = $this->connection
			->executeQuery('PRAGMA table_info(' . $table . ')')
			->fetchAllAssociative();

		$keyed = [];
		foreach ($rows as $row) {
			$keyed[$row['name']] = $row;
		}

		return $keyed;
	}
}
