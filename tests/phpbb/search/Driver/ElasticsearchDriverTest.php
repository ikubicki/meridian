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
use phpbb\search\Driver\ElasticsearchDriver;
use phpbb\search\DTO\SearchQuery;
use phpbb\Tests\Integration\IntegrationTestCase;
use phpbb\user\DTO\PaginatedResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

final class ElasticsearchDriverTest extends IntegrationTestCase
{
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
	}

	#[Test]
	public function it_logs_warning_and_returns_parent_result(): void
	{
		// Arrange
		/** @var LoggerInterface&MockObject $logger */
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with($this->stringContains('Elasticsearch'));

		$driver = new ElasticsearchDriver($this->connection, $logger);
		$ctx    = new PaginationContext(page: 1, perPage: 25);

		// Act
		$result = $driver->search(new SearchQuery(keywords: 'test'), $ctx);

		// Assert
		$this->assertInstanceOf(PaginatedResult::class, $result);
	}
}
