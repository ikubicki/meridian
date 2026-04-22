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

namespace phpbb\db;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

final class DbalConnectionFactory
{
	public function create(
		string $host,
		string $dbname,
		string $user,
		string $password,
		string $port = '',
	): Connection {
		$params = [
			'driver'        => 'pdo_mysql',
			'host'          => $host,
			'port'          => (int) $port ?: 3306,
			'dbname'        => $dbname,
			'user'          => $user,
			'password'      => $password,
			'charset'       => 'utf8mb4',
			'serverVersion' => 'mariadb-10.11.0',
		];

		return DriverManager::getConnection($params);
	}
}
