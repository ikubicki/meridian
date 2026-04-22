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

namespace phpbb\Tests\Integration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;

/**
 * Concrete subclass of IntegrationTestCase used for smoke-testing the base class itself.
 *
 * Creates a trivial SQLite table in setUpSchema() so we can confirm the Connection
 * is live and the hook is invoked during setUp().
 */
class IntegrationTestCaseTest extends IntegrationTestCase
{
	private bool $schemaSetUp = false;

	protected function setUpSchema(): void
	{
		$this->schemaSetUp = true;
		$this->connection->executeStatement(
			'CREATE TABLE smoke_check (id INTEGER PRIMARY KEY)'
		);
	}

	#[Test]
	public function setUpCreatesLiveSqliteConnection(): void
	{
		$this->assertInstanceOf(Connection::class, $this->connection);
	}

	#[Test]
	public function setUpCallsSetUpSchema(): void
	{
		$this->assertTrue($this->schemaSetUp);
	}
}
