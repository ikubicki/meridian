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
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

abstract class IntegrationTestCase extends TestCase
{
	protected Connection $connection;

	protected function setUp(): void
	{
		parent::setUp();

		$this->connection = DriverManager::getConnection([
			'driver' => 'pdo_sqlite',
			'memory' => true,
		]);

		$this->setUpSchema();
	}

	abstract protected function setUpSchema(): void;
}
