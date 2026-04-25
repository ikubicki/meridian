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

namespace phpbb\Tests\notifications\Migration;

use phpbb\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Smoke tests verifying the post-migration schema of phpbb_notifications
 * and phpbb_notification_types against an SQLite in-memory database.
 *
 * These tests set up the expected post-migration schema (using SQLite DDL)
 * and verify the resulting structure via PRAGMA queries — equivalent to
 * SHOW COLUMNS / SHOW INDEX on MySQL/MariaDB.
 */
final class MigrationSchemaTest extends IntegrationTestCase
{
	protected function setUpSchema(): void
	{
		// Post-migration phpbb_notifications schema:
		// notification_data is JSON (MariaDB alias for LONGTEXT; SQLite accepts it as TEXT affinity)
		// composite index user_read_time replaces the old single user index
		$this->connection->executeStatement('
			CREATE TABLE phpbb_notifications (
				notification_id INTEGER PRIMARY KEY AUTOINCREMENT,
				notification_type_id INTEGER NOT NULL DEFAULT 0,
				item_id INTEGER NOT NULL DEFAULT 0,
				item_parent_id INTEGER NOT NULL DEFAULT 0,
				user_id INTEGER NOT NULL DEFAULT 0,
				notification_read INTEGER NOT NULL DEFAULT 0,
				notification_time INTEGER NOT NULL DEFAULT 1,
				notification_data JSON NOT NULL
			)
		');

		$this->connection->executeStatement('
			CREATE INDEX user_read_time ON phpbb_notifications (user_id, notification_read, notification_time)
		');

		$this->connection->executeStatement('
			CREATE TABLE phpbb_notification_types (
				notification_type_id INTEGER PRIMARY KEY AUTOINCREMENT,
				notification_type_name VARCHAR(255) NOT NULL UNIQUE,
				notification_type_enabled INTEGER NOT NULL DEFAULT 1
			)
		');

		// Seed built-in types — mirrors the INSERT IGNORE in the migration SQL
		$this->connection->executeStatement(
			"INSERT OR IGNORE INTO phpbb_notification_types (notification_type_name, notification_type_enabled)
			 VALUES ('notification.type.post', 1), ('notification.type.topic', 1)"
		);
	}

	#[Test]
	public function notificationDataColumnIsJsonType(): void
	{
		// Arrange
		$expected_type = 'JSON';

		// Act
		$columns = $this->connection
			->executeQuery('PRAGMA table_info(phpbb_notifications)')
			->fetchAllAssociative();

		$data_column = array_filter(
			$columns,
			static fn (array $col): bool => $col['name'] === 'notification_data'
		);

		// Assert
		$this->assertNotEmpty($data_column, 'notification_data column must exist in phpbb_notifications after migration');
		$column = array_values($data_column)[0];
		$this->assertSame($expected_type, strtoupper($column['type']), 'notification_data must be JSON type after migration');
	}

	#[Test]
	public function userReadTimeCompositeIndexExists(): void
	{
		// Arrange
		$expected_index = 'user_read_time';

		// Act
		$indexes = $this->connection
			->executeQuery('PRAGMA index_list(phpbb_notifications)')
			->fetchAllAssociative();

		$index_names = array_column($indexes, 'name');

		// Assert
		$this->assertContains(
			$expected_index,
			$index_names,
			'user_read_time composite index must exist on phpbb_notifications after migration'
		);
	}

	#[Test]
	public function builtinNotificationTypeRowsExist(): void
	{
		// Arrange
		$expected_types = ['notification.type.post', 'notification.type.topic'];

		// Act
		$rows = $this->connection
			->executeQuery(
				"SELECT notification_type_name FROM phpbb_notification_types
				 WHERE notification_type_name IN ('notification.type.post', 'notification.type.topic')"
			)
			->fetchAllAssociative();

		$found_types = array_column($rows, 'notification_type_name');

		// Assert
		foreach ($expected_types as $type) {
			$this->assertContains(
				$type,
				$found_types,
				"Notification type '{$type}' must be seeded in phpbb_notification_types after migration"
			);
		}
	}
}
