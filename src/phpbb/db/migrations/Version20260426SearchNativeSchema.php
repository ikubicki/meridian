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

namespace phpbb\db\migrations;

use Doctrine\DBAL\Connection;

/**
 * Migration: Create Native Search Schema (M8)
 *
 * Creates 2 native full-text search tables:
 * - search_wordlist
 * - search_wordmatch
 *
 * @TAG database_migration
 */
class Version20260426SearchNativeSchema
{
	private const TABLE_PREFIX = 'phpbb_';

	public function __construct(
		private readonly Connection $connection,
	) {
	}

	public function up(): void
	{
		$this->createWordlistTable();
		$this->createWordmatchTable();
	}

	public function down(): void
	{
		$this->connection->executeStatement('DROP TABLE IF EXISTS ' . self::TABLE_PREFIX . 'search_wordmatch');
		$this->connection->executeStatement('DROP TABLE IF EXISTS ' . self::TABLE_PREFIX . 'search_wordlist');
	}

	private function createWordlistTable(): void
	{
		$sql = '
			CREATE TABLE IF NOT EXISTS ' . self::TABLE_PREFIX . 'search_wordlist (
				word_id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
				word_text  VARCHAR(255) NOT NULL COLLATE utf8mb4_unicode_ci,
				word_count MEDIUMINT UNSIGNED NOT NULL DEFAULT 0,

				PRIMARY KEY (word_id),
				UNIQUE KEY uidx_word_text (word_text)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		';
		$this->connection->executeStatement($sql);
	}

	private function createWordmatchTable(): void
	{
		$sql = '
			CREATE TABLE IF NOT EXISTS ' . self::TABLE_PREFIX . 'search_wordmatch (
				post_id     INT UNSIGNED NOT NULL,
				word_id     INT UNSIGNED NOT NULL,
				title_match TINYINT(1) NOT NULL DEFAULT 0,

				PRIMARY KEY (post_id, word_id, title_match),
				KEY idx_word_id (word_id),
				KEY idx_post_id (post_id)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		';
		$this->connection->executeStatement($sql);
	}
}
