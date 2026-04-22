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

namespace phpbb\Tests\db;

use Doctrine\DBAL\Connection;
use phpbb\db\DbalConnectionFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class DbalConnectionFactoryTest extends TestCase
{
	#[Test]
	public function createReturnsDbalConnectionInstance(): void
	{
		$factory = new DbalConnectionFactory();

		// DBAL connections are lazy — no actual TCP handshake happens here
		$connection = $factory->create(
			host: '127.0.0.1',
			dbname: 'testdb',
			user: 'root',
			password: '',
		);

		$this->assertInstanceOf(Connection::class, $connection);
	}
}
